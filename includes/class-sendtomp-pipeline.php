<?php
/**
 * SendToMP_Pipeline — shared submission processing pipeline.
 *
 * Extracted as a standalone class so that any adapter (including those that
 * cannot extend SendToMP_Form_Adapter_Abstract due to single-inheritance
 * constraints) can delegate to the same pipeline via composition.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Pipeline {

	/**
	 * Run the full submission processing pipeline.
	 *
	 * Expects the submission to already have source_adapter, source_form_id,
	 * target_house, metadata, and raw_data set by the calling adapter.
	 *
	 * @param SendToMP_Submission $submission        The submission to process.
	 * @param array               $options           Pipeline options.
	 *     @type bool $skip_confirmation When true, sends directly to the MP
	 *                                   without the double opt-in confirmation
	 *                                   step. Use only with verified callers.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function process( SendToMP_Submission $submission, array $options = [] ) {
		$skip_confirmation = isset( $options['skip_confirmation'] ) && true === $options['skip_confirmation'];

		// 1. Normalise postcode (Commons only — Lords don't use postcodes).
		if ( 'commons' === $submission->target_house ) {
			$submission->normalise_postcode();
		}

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
		$api_client = new SendToMP_API_Client();

		if ( 'lords' === $submission->target_house && $submission->target_member_id > 0 ) {
			$api_response = $api_client->resolve_member_by_id(
				$submission->target_member_id,
				'lords'
			);
		} else {
			$api_response = $api_client->resolve_member(
				$submission->constituent_postcode,
				$submission->target_house
			);
		}
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
			'house'            => isset( $member['house'] ) ? $member['house'] : $submission->target_house,
			'delivery_email'   => isset( $delivery['email'] ) ? $delivery['email'] : '',
			'override_applied' => isset( $delivery['override_applied'] ) ? $delivery['override_applied'] : false,
			'contact_quality'  => isset( $delivery['contact_quality'] ) ? $delivery['contact_quality'] : 'direct',
		];

		$submission->target_member_id = $submission->resolved_member['id'];

		// 6. Apply local address overrides (local > global > API).
		$submission->resolved_member = SendToMP_Overrides::apply( $submission->resolved_member );

		// 7. Direct send path — skip confirmation, send directly to MP.
		if ( $skip_confirmation ) {
			$mail_result = ( new SendToMP_Mailer() )->send_to_mp( $submission );
			if ( is_wp_error( $mail_result ) ) {
				return $mail_result;
			}

			SendToMP_Logger::log( $submission, 'confirmed_and_sent' );

			return true;
		}

		// 8. Store pending submission and get confirmation token.
		$token = ( new SendToMP_Confirmation() )->store_pending( $submission, $submission->resolved_member );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// 9. Send confirmation email.
		$mail_result = ( new SendToMP_Mailer() )->send_confirmation(
			$submission,
			$token,
			$submission->resolved_member['name'],
			$submission->resolved_member['constituency']
		);
		if ( is_wp_error( $mail_result ) ) {
			return $mail_result;
		}

		// 10. Log as pending_confirmation.
		SendToMP_Logger::log( $submission, 'pending_confirmation' );

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			$ip = '0.0.0.0';
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		return $validated ? $validated : '0.0.0.0';
	}
}
