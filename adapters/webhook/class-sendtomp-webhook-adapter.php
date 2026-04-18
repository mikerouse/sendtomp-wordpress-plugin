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
	 * Request attribute key for storing the auth level.
	 */
	const AUTH_LEVEL_ATTR = '_sendtomp_auth_level';

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
			'args'                => [
				'constituent_name' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Full name of the constituent.', 'sendtomp' ),
				],
				'constituent_email' => [
					'type'              => 'string',
					'required'          => true,
					'format'            => 'email',
					'sanitize_callback' => 'sanitize_email',
					'description'       => __( 'Email address of the constituent.', 'sendtomp' ),
				],
				'constituent_postcode' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'UK postcode of the constituent.', 'sendtomp' ),
				],
				'constituent_address' => [
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Full address of the constituent.', 'sendtomp' ),
				],
				'message_subject' => [
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Subject line for the message.', 'sendtomp' ),
				],
				'message_body' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => __( 'The message body to send to the MP.', 'sendtomp' ),
				],
				'target_house' => [
					'type'              => 'string',
					'required'          => false,
					'default'           => 'commons',
					'enum'              => [ 'commons', 'lords' ],
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Target house: commons or lords.', 'sendtomp' ),
				],
				'skip_confirmation' => [
					'type'              => 'boolean',
					'required'          => false,
					'default'           => false,
					'description'       => __( 'Skip double opt-in (privileged key only).', 'sendtomp' ),
				],
			],
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

		$token = trim( substr( $header, 7 ) );

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
			$request->set_param( self::AUTH_LEVEL_ATTR, 'privileged' );
			return true;
		}

		// Check standard key.
		$standard_hash = isset( $settings['webhook_api_key_hash'] ) ? $settings['webhook_api_key_hash'] : '';
		if ( ! empty( $standard_hash ) && wp_check_password( $token, $standard_hash ) ) {
			$request->set_param( self::AUTH_LEVEL_ATTR, 'standard' );
			return true;
		}

		// Track failed attempts by IP to prevent brute-force.
		$ip            = SendToMP_Pipeline::get_client_ip();
		$transient_key = 'sendtomp_auth_fail_' . md5( $ip );
		$failures      = (int) get_transient( $transient_key );

		if ( $failures >= 10 ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Too many failed authentication attempts. Try again later.', 'sendtomp' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $transient_key, $failures + 1, MINUTE_IN_SECONDS );

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

		if ( ! is_array( $body ) ) {
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

		$target_house = sanitize_text_field( isset( $body['target_house'] ) ? $body['target_house'] : 'commons' );
		if ( ! in_array( $target_house, [ 'commons', 'lords' ], true ) ) {
			$target_house = 'commons';
		}

		$auth_level        = $request->get_param( self::AUTH_LEVEL_ATTR ) ?: 'standard';
		$skip_confirmation = ! empty( $body['skip_confirmation'] ) && 'privileged' === $auth_level;

		$submission = $this->create_submission( $mapped_data );
		$submission->source_form_id = 'api';
		$submission->target_house   = $target_house;
		$submission->raw_data       = map_deep( $body, 'sanitize_text_field' );

		// Run through the shared pipeline (with or without confirmation).
		$result = SendToMP_Pipeline::process( $submission, [
			'skip_confirmation' => $skip_confirmation,
		] );

		if ( is_wp_error( $result ) ) {
			$status = $this->error_to_status( $result );

			return new WP_REST_Response( [
				'success' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			], $status );
		}

		if ( $skip_confirmation ) {
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

		return new WP_REST_Response( [
			'success'              => true,
			'skipped_confirmation' => false,
			'message'              => __( 'Confirmation email sent. The constituent must confirm before the message is delivered.', 'sendtomp' ),
		], 202 );
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
