<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Rate_Limiter {

	public function check( SendToMP_Submission $submission ) {
		if ( ! empty( $submission->metadata['honeypot'] ) ) {
			return new WP_Error( 'submission_rejected', __( 'Your message could not be submitted. Please try again.', 'sendtomp' ) );
		}

		if ( ! $this->check_duplicate( $submission ) ) {
			return new WP_Error( 'duplicate_submission', __( 'It looks like you have already sent this message. Please check your email for a confirmation link.', 'sendtomp' ) );
		}

		$email_limit = $this->get_limit( 'email' );
		if ( ! $this->check_rate( 'email', $submission->constituent_email, $email_limit ) ) {
			return new WP_Error( 'rate_limit_email', __( 'You have reached the maximum number of messages for today. Please try again tomorrow.', 'sendtomp' ) );
		}

		$ip = $this->get_client_ip();
		$ip_limit = $this->get_limit( 'ip' );
		if ( ! $this->check_rate( 'ip', $ip, $ip_limit ) ) {
			return new WP_Error( 'rate_limit_ip', __( 'Too many messages have been sent from your location today. Please try again tomorrow.', 'sendtomp' ) );
		}

		if ( ! empty( $submission->constituent_postcode ) ) {
			$postcode_limit = $this->get_limit( 'postcode' );
			if ( ! $this->check_rate( 'postcode', $submission->constituent_postcode, $postcode_limit ) ) {
				return new WP_Error( 'rate_limit_postcode', __( 'Too many messages have been sent from your postcode area today. Please try again tomorrow.', 'sendtomp' ) );
			}
		}

		// Per-member rate limit for Lords (reuses postcode limit value).
		if ( 'lords' === $submission->target_house && $submission->target_member_id > 0 ) {
			$member_limit = $this->get_limit( 'postcode' );
			if ( ! $this->check_rate( 'member', (string) $submission->target_member_id, $member_limit ) ) {
				return new WP_Error( 'rate_limit_member', __( 'Too many messages have been sent to this Peer today. Please try again tomorrow.', 'sendtomp' ) );
			}
		}

		$global_limit = $this->get_limit( 'global' );
		if ( ! $this->check_rate( 'global', 'site', $global_limit ) ) {
			return new WP_Error( 'rate_limit_global', __( 'This service is temporarily at capacity. Please try again later.', 'sendtomp' ) );
		}

		return true;
	}

	private function check_rate( string $type, string $identifier, int $limit ): bool {
		$key   = 'sendtomp_rl_' . $type . '_' . md5( $identifier );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, DAY_IN_SECONDS );

		return true;
	}

	private function check_duplicate( SendToMP_Submission $submission ): bool {
		$key = 'sendtomp_dup_' . $submission->get_hash();

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, DAY_IN_SECONDS );

		return true;
	}

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

	private function get_limit( string $type ): int {
		$map = [
			'email'    => 'rate_limit_email',
			'ip'       => 'rate_limit_ip',
			'postcode' => 'rate_limit_postcode',
			'global'   => 'rate_limit_global',
		];

		$key = isset( $map[ $type ] ) ? $map[ $type ] : null;

		if ( ! $key ) {
			return 10;
		}

		return (int) sendtomp()->get_setting( $key );
	}
}
