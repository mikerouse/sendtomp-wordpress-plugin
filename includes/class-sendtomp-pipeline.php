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

		// 2b. Already-pending short circuit (skipped on direct-send path).
		//
		// A re-submission from a constituent who hasn't yet clicked the
		// link in their first confirmation email is almost always a
		// "I didn't get your email" attempt, not a fresh intent. Instead
		// of rejecting with an opaque "duplicate" error, re-send the
		// existing pending record's confirmation email (same token, no
		// new DB row in sendtomp_pending) so the constituent gets a
		// fresh copy in their inbox and can continue.
		if ( ! $skip_confirmation ) {
			$existing = ( new SendToMP_Confirmation() )->get_latest_pending_by_email( $submission->constituent_email );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['token'] ) ) {
				$existing_submission = is_array( $existing['submission'] )
					? new SendToMP_Submission( $existing['submission'] )
					: $existing['submission'];

				// The decrypted submission payload doesn't include the resolved
				// member — that's stored in its own column on the pending row —
				// so carry it across before sending so the preview and log
				// name the right MP.
				if ( isset( $existing['resolved_member'] ) && is_array( $existing['resolved_member'] ) ) {
					$existing_submission->resolved_member = $existing['resolved_member'];
				}

				$resent = ( new SendToMP_Mailer() )->send_confirmation(
					$existing_submission,
					(string) $existing['token'],
					(string) ( $existing['resolved_member']['name'] ?? '' ),
					(string) ( $existing['resolved_member']['constituency'] ?? '' )
				);

				if ( ! is_wp_error( $resent ) ) {
					// Re-use the already-resolved member on the new submission so the log row
					// names the right MP instead of showing a blank "MP / Peer" column.
					$submission->resolved_member = $existing['resolved_member'];
					if ( isset( $existing['resolved_member']['id'] ) ) {
						$submission->target_member_id = (int) $existing['resolved_member']['id'];
					}

					// Informational row — keep distinct from pending_confirmation so
					// it doesn't inflate the "email sent" denominator when we
					// compute conversion metrics later. Linked to the same
					// pending_id as the original row for traceability.
					SendToMP_Logger::log(
						$submission,
						'pending_resent',
						__( 'Re-sent the existing confirmation email after the constituent resubmitted the form before confirming the first one.', 'sendtomp' ),
						isset( $existing['pending_id'] ) ? (int) $existing['pending_id'] : 0
					);

					return new WP_Error(
						'pending_resent',
						__( 'We already have your message waiting — we\'ve just re-sent the confirmation email. Please check your inbox (and spam folder) and click the confirmation link to send your message to your MP.', 'sendtomp' )
					);
				}
			}
		}

		// 3. Rate-limit check.
		$rate_check = ( new SendToMP_Rate_Limiter() )->check( $submission );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// 4. Free tier monthly limit check.
		if ( ! SendToMP_License::check_monthly_limit() ) {
			return new WP_Error(
				'monthly_limit_reached',
				sprintf(
					/* translators: %d: message limit */
					__( 'You have reached the monthly limit of %d messages on the Free plan. Upgrade to Plus for unlimited messages.', 'sendtomp' ),
					SendToMP_License::FREE_MONTHLY_LIMIT
				)
			);
		}

		// 5. Resolve the member via the middleware API.
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

		// 6. Build resolved_member by merging member + delivery into a flat array.
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

		// 7. Apply local address overrides (local > global > API).
		$submission->resolved_member = SendToMP_Overrides::apply( $submission->resolved_member );

		// 8. Direct send path — skip confirmation, send directly to MP.
		if ( $skip_confirmation ) {
			$mail_result = ( new SendToMP_Mailer() )->send_to_mp( $submission );
			if ( is_wp_error( $mail_result ) ) {
				return $mail_result;
			}

			SendToMP_Logger::log( $submission, 'confirmed_and_sent' );
			SendToMP_License::increment_counter();

			return true;
		}

		// 9. Store pending submission and get confirmation token.
		$stored = ( new SendToMP_Confirmation() )->store_pending( $submission, $submission->resolved_member );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$token      = (string) $stored['token'];
		$pending_id = (int) $stored['pending_id'];

		// 10. Send confirmation email.
		$mail_result = ( new SendToMP_Mailer() )->send_confirmation(
			$submission,
			$token,
			$submission->resolved_member['name'],
			$submission->resolved_member['constituency']
		);
		if ( is_wp_error( $mail_result ) ) {
			return $mail_result;
		}

		// 11. Log as pending_confirmation (linked to the pending row so the
		// row can transition to 'confirmed' on click instead of spawning a
		// second log row — gives us conversion metrics for free).
		SendToMP_Logger::log( $submission, 'pending_confirmation', '', $pending_id );
		SendToMP_License::increment_counter();

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
