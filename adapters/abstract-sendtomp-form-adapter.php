<?php
/**
 * SendToMP_Form_Adapter_Abstract — shared processing logic for all form adapters.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SendToMP_Form_Adapter_Abstract implements SendToMP_Form_Adapter_Interface {

	/**
	 * Create a SendToMP_Submission from mapped form data.
	 *
	 * @param array $mapped_data Keys matching submission properties.
	 * @return SendToMP_Submission
	 */
	public function create_submission( array $mapped_data ): SendToMP_Submission {
		$mapped_data['source_adapter'] = $this->get_slug();

		$mapped_data['metadata'] = array_merge(
			isset( $mapped_data['metadata'] ) && is_array( $mapped_data['metadata'] ) ? $mapped_data['metadata'] : [],
			[
				'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
				'client_ip'    => $this->get_client_ip(),
			]
		);

		return new SendToMP_Submission( $mapped_data );
	}

	/**
	 * Main processing pipeline for a submission.
	 *
	 * @param SendToMP_Submission $submission The submission to process.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function process_submission( SendToMP_Submission $submission ) {
		// 1. Normalise postcode.
		$submission->normalise_postcode();

		// 2. Validate submission data.
		$valid = $submission->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// 3. Rate-limit check.
		$rate_check = ( new SendToMP_Rate_Limiter() )->check( $submission );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// 4. Resolve the member via the middleware API.
		$api_response = ( new SendToMP_API_Client() )->resolve_member(
			$submission->constituent_postcode,
			$submission->target_house
		);
		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// 5. Build resolved_member by merging member + delivery into a flat array.
		$member   = isset( $api_response['member'] ) ? $api_response['member'] : [];
		$delivery = isset( $api_response['delivery'] ) ? $api_response['delivery'] : [];

		$submission->resolved_member = [
			'id'               => isset( $member['id'] ) ? (int) $member['id'] : 0,
			'name'             => isset( $member['name'] ) ? $member['name'] : '',
			'party'            => isset( $member['party'] ) ? $member['party'] : '',
			'constituency'     => isset( $member['constituency'] ) ? $member['constituency'] : '',
			'house'            => isset( $member['house'] ) ? $member['house'] : '',
			'delivery_email'   => isset( $delivery['email'] ) ? $delivery['email'] : '',
			'override_applied' => isset( $delivery['override_applied'] ) ? $delivery['override_applied'] : false,
		];

		// 6. Store pending submission and get confirmation token.
		$token = ( new SendToMP_Confirmation() )->store_pending( $submission, $submission->resolved_member );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// 7. Send confirmation email.
		$member_name         = $submission->resolved_member['name'];
		$member_constituency = $submission->resolved_member['constituency'];

		$mail_result = ( new SendToMP_Mailer() )->send_confirmation(
			$submission,
			$token,
			$member_name,
			$member_constituency
		);
		if ( is_wp_error( $mail_result ) ) {
			return $mail_result;
		}

		// 8. Log as pending_confirmation.
		SendToMP_Logger::log( $submission, 'pending_confirmation' );

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		return $validated ? $validated : '0.0.0.0';
	}

	/**
	 * @inheritDoc
	 */
	abstract public function get_slug(): string;

	/**
	 * @inheritDoc
	 */
	abstract public function get_label(): string;

	/**
	 * @inheritDoc
	 */
	abstract public function is_plugin_active(): bool;

	/**
	 * @inheritDoc
	 */
	abstract public function register_hooks(): void;

	/**
	 * @inheritDoc
	 */
	abstract public function get_available_forms(): array;

	/**
	 * @inheritDoc
	 */
	abstract public function get_form_fields( $form_id ): array;
}
