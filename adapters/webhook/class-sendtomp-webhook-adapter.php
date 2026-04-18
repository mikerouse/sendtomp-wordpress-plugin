<?php
/**
 * SendToMP_Webhook_Adapter — REST API endpoint adapter for SendToMP.
 *
 * Provides a POST /wp-json/sendtomp/v1/submit endpoint that external
 * systems (Zapier, n8n, Make, custom integrations) can use to submit
 * messages through the SendToMP pipeline.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Webhook_Adapter extends SendToMP_Form_Adapter_Abstract {

	/**
	 * REST API namespace.
	 */
	const REST_NAMESPACE = 'sendtomp/v1';

	/**
	 * The authentication level for the current request.
	 *
	 * @var string 'standard'|'privileged'
	 */
	private $auth_level = 'standard';

	/**
	 * Return the adapter slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'webhook';
	}

	/**
	 * Return the human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Webhook API';
	}

	/**
	 * Always available — gated by can('webhook_api') at detect_adapters level.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {
		return true;
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Not form-based — returns empty array.
	 *
	 * @return array
	 */
	public function get_available_forms(): array {
		return [];
	}

	/**
	 * Not form-based — returns empty array.
	 *
	 * @param int|string $form_id Unused.
	 * @return array
	 */
	public function get_form_fields( $form_id ): array {
		return [];
	}

	// -------------------------------------------------------------------------
	// REST API
	// -------------------------------------------------------------------------

	/**
	 * Register the submit endpoint.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/submit', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_submit' ],
			'permission_callback' => [ $this, 'authenticate' ],
		] );
	}

	/**
	 * Authenticate the request using Bearer token.
	 *
	 * Checks against both standard and privileged API key hashes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );

		if ( ! $header || stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing API key. Include an Authorization: Bearer <key> header.', 'sendtomp' ),
				[ 'status' => 401 ]
			);
		}

		$token = substr( $header, 7 );

		if ( empty( $token ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Empty API key.', 'sendtomp' ),
				[ 'status' => 401 ]
			);
		}

		$settings = sendtomp()->get_settings();

		// Check privileged key first (more specific).
		$privileged_hash = isset( $settings['webhook_api_key_privileged_hash'] ) ? $settings['webhook_api_key_privileged_hash'] : '';
		if ( ! empty( $privileged_hash ) && wp_check_password( $token, $privileged_hash ) ) {
			$this->auth_level = 'privileged';
			return true;
		}

		// Check standard key.
		$standard_hash = isset( $settings['webhook_api_key_hash'] ) ? $settings['webhook_api_key_hash'] : '';
		if ( ! empty( $standard_hash ) && wp_check_password( $token, $standard_hash ) ) {
			$this->auth_level = 'standard';
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid API key.', 'sendtomp' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Handle a submission via the REST API.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => __( 'Request body must be valid JSON.', 'sendtomp' ),
			], 422 );
		}

		$mapped_data = [
			'constituent_name'     => sanitize_text_field( isset( $body['constituent_name'] ) ? $body['constituent_name'] : '' ),
			'constituent_email'    => sanitize_email( isset( $body['constituent_email'] ) ? $body['constituent_email'] : '' ),
			'constituent_postcode' => sanitize_text_field( isset( $body['constituent_postcode'] ) ? $body['constituent_postcode'] : '' ),
			'constituent_address'  => sanitize_text_field( isset( $body['constituent_address'] ) ? $body['constituent_address'] : '' ),
			'message_subject'      => sanitize_text_field( isset( $body['message_subject'] ) ? $body['message_subject'] : '' ),
			'message_body'         => sanitize_textarea_field( isset( $body['message_body'] ) ? $body['message_body'] : '' ),
		];

		$target_house      = sanitize_text_field( isset( $body['target_house'] ) ? $body['target_house'] : 'commons' );
		$skip_confirmation = ! empty( $body['skip_confirmation'] ) && 'privileged' === $this->auth_level;

		$submission = $this->create_submission( $mapped_data );
		$submission->source_form_id = 'api';
		$submission->target_house   = $target_house;
		$submission->raw_data       = $body;

		if ( $skip_confirmation ) {
			return $this->process_direct_send( $submission );
		}

		// Normal double opt-in flow.
		$result = $this->process_submission( $submission );

		if ( is_wp_error( $result ) ) {
			$status = $this->error_to_status( $result );

			return new WP_REST_Response( [
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			], $status );
		}

		return new WP_REST_Response( [
			'success'              => true,
			'skipped_confirmation' => false,
			'message'              => __( 'Confirmation email sent. The constituent must confirm before the message is delivered.', 'sendtomp' ),
		], 202 );
	}

	// -------------------------------------------------------------------------
	// Direct send (skip_confirmation)
	// -------------------------------------------------------------------------

	/**
	 * Process a submission without the double opt-in confirmation step.
	 *
	 * Runs validation, rate-limiting, and member resolution, then sends
	 * directly to the MP. Only available with a privileged API key.
	 *
	 * @param SendToMP_Submission $submission The submission to process.
	 * @return WP_REST_Response
	 */
	private function process_direct_send( SendToMP_Submission $submission ): WP_REST_Response {
		// 1. Normalise postcode.
		$submission->normalise_postcode();

		// 2. Validate.
		$valid = $submission->validate();
		if ( is_wp_error( $valid ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $valid->get_error_message(),
				'code'    => $valid->get_error_code(),
			], 422 );
		}

		// 3. Rate-limit check.
		$rate_check = ( new SendToMP_Rate_Limiter() )->check( $submission );
		if ( is_wp_error( $rate_check ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $rate_check->get_error_message(),
				'code'    => $rate_check->get_error_code(),
			], 429 );
		}

		// 4. Resolve member.
		$api_response = ( new SendToMP_API_Client() )->resolve_member(
			$submission->constituent_postcode,
			$submission->target_house
		);
		if ( is_wp_error( $api_response ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $api_response->get_error_message(),
				'code'    => $api_response->get_error_code(),
			], 502 );
		}

		// 5. Build resolved_member.
		$member   = isset( $api_response['member'] ) ? $api_response['member'] : [];
		$delivery = isset( $api_response['delivery'] ) ? $api_response['delivery'] : [];

		$submission->resolved_member = [
			'id'               => isset( $member['id'] ) ? (int) $member['id'] : 0,
			'name'             => isset( $member['name'] ) ? $member['name'] : '',
			'party'            => isset( $member['party'] ) ? $member['party'] : '',
			'constituency'     => isset( $member['constituency'] ) ? $member['constituency'] : '',
			'house'            => isset( $member['house'] ) ? $member['house'] : $submission->target_house,
			'delivery_email'   => isset( $delivery['email'] ) ? $delivery['email'] : '',
			'override_applied' => isset( $delivery['override_applied'] ) ? $delivery['override_applied'] : false,
		];

		$submission->target_member_id = $submission->resolved_member['id'];

		// 6. Send directly to MP (no confirmation).
		$mail_result = ( new SendToMP_Mailer() )->send_to_mp( $submission );
		if ( is_wp_error( $mail_result ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $mail_result->get_error_message(),
				'code'    => $mail_result->get_error_code(),
			], 502 );
		}

		// 7. Log as directly sent.
		SendToMP_Logger::log( $submission, 'confirmed_and_sent' );

		return new WP_REST_Response( [
			'success'              => true,
			'skipped_confirmation' => true,
			'member'               => [
				'name'         => $submission->resolved_member['name'],
				'party'        => $submission->resolved_member['party'],
				'constituency' => $submission->resolved_member['constituency'],
			],
			'message' => __( 'Message sent directly to MP.', 'sendtomp' ),
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Map a WP_Error code to an HTTP status code.
	 *
	 * @param WP_Error $error The error to map.
	 * @return int
	 */
	private function error_to_status( WP_Error $error ): int {
		$code = $error->get_error_code();

		$map = [
			// Validation errors → 422.
			'missing_name'       => 422,
			'invalid_email'      => 422,
			'missing_email'      => 422,
			'missing_postcode'   => 422,
			'invalid_postcode'   => 422,
			'missing_message'    => 422,
			'invalid_house'      => 422,

			// Rate limits → 429.
			'rate_limit_email'    => 429,
			'rate_limit_ip'       => 429,
			'rate_limit_postcode' => 429,
			'rate_limit_global'   => 429,
			'submission_rejected' => 429,

			// Duplicates → 409.
			'duplicate_submission' => 409,

			// Upstream failures → 502.
			'sendtomp_api_error'       => 502,
			'sendtomp_invalid_response' => 502,
			'no_delivery_email'        => 502,
			'send_failed'              => 502,
		];

		return isset( $map[ $code ] ) ? $map[ $code ] : 500;
	}
}
