<?php
/**
 * SendToMP_License — license management, tier detection, and feature gating.
 *
 * Free tier works without a key. Plus/Pro require activation via the
 * Bluetorch licensing API (Supabase edge functions).
 *
 * License status is cached in a 24-hour transient. On Supabase downtime,
 * the plugin fail-opens using the cached status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_License {

	const TIER_FREE = 'free';
	const TIER_PLUS = 'plus';
	const TIER_PRO  = 'pro';

	const STATUS_CACHE_KEY = 'sendtomp_license_status';
	const COUNTER_KEY      = 'sendtomp_monthly_counter';

	/**
	 * Feature-to-tier mapping. Features not listed default to 'free' (always available).
	 */
	const FEATURE_TIERS = [
		'lords'           => self::TIER_PLUS,
		'bcc'             => self::TIER_PLUS,
		'wpforms_adapter' => self::TIER_PLUS,
		'cf7_adapter'     => self::TIER_PLUS,
		'local_overrides' => self::TIER_PLUS,
		'full_templates'  => self::TIER_PLUS,
		'webhook_api'     => self::TIER_PRO,
		'remove_branding' => self::TIER_PRO,
		'csv_export'      => self::TIER_PRO,
		'white_label'     => self::TIER_PRO,
	];

	/**
	 * Tier hierarchy for comparison.
	 */
	const TIER_LEVELS = [
		self::TIER_FREE => 0,
		self::TIER_PLUS => 1,
		self::TIER_PRO  => 2,
	];

	/**
	 * Monthly message limit for Free tier.
	 */
	const FREE_MONTHLY_LIMIT = 25;

	/**
	 * Check if the current license tier supports a feature.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function can( string $feature ): bool {
		$required_tier = isset( self::FEATURE_TIERS[ $feature ] ) ? self::FEATURE_TIERS[ $feature ] : self::TIER_FREE;
		$current_tier  = self::get_tier();

		$required_level = isset( self::TIER_LEVELS[ $required_tier ] ) ? self::TIER_LEVELS[ $required_tier ] : 0;
		$current_level  = isset( self::TIER_LEVELS[ $current_tier ] ) ? self::TIER_LEVELS[ $current_tier ] : 0;

		return $current_level >= $required_level;
	}

	/**
	 * Determine whether branding should be displayed.
	 *
	 * Free: always on (setting ignored).
	 * Plus: respects show_branding setting (default on).
	 * Pro: respects show_branding setting (default off / white-label).
	 *
	 * @return bool
	 */
	public static function should_show_branding(): bool {
		$tier = self::get_tier();

		if ( self::TIER_FREE === $tier ) {
			return true;
		}

		return (bool) sendtomp()->get_setting( 'show_branding' );
	}

	/**
	 * Get the current license tier.
	 *
	 * @return string 'free', 'plus', or 'pro'.
	 */
	public static function get_tier(): string {
		$status = self::get_cached_status();

		if ( $status && ! empty( $status['valid'] ) && ! empty( $status['tier'] ) ) {
			return $status['tier'];
		}

		// If a license key is set but cache is empty/expired, refresh on-demand
		// to prevent paid users losing access when cron is unreliable.
		$key = sendtomp()->get_setting( 'license_key' );
		if ( ! empty( $key ) && ! $status ) {
			self::refresh_status();
			$status = self::get_cached_status();
			if ( $status && ! empty( $status['valid'] ) && ! empty( $status['tier'] ) ) {
				return $status['tier'];
			}
		}

		return self::TIER_FREE;
	}

	/**
	 * Get full cached license status.
	 *
	 * @return array|null Cached status or null.
	 */
	public static function get_cached_status(): ?array {
		$cached = get_transient( self::STATUS_CACHE_KEY );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Check whether the Free tier monthly limit has been reached.
	 *
	 * @return bool True if under limit or on a paid tier.
	 */
	public static function check_monthly_limit(): bool {
		if ( self::get_tier() !== self::TIER_FREE ) {
			return true;
		}

		$counter = self::get_monthly_counter();

		return $counter < self::FREE_MONTHLY_LIMIT;
	}

	/**
	 * Increment the monthly message counter (Free tier tracking).
	 *
	 * @return void
	 */
	public static function increment_counter(): void {
		if ( self::get_tier() !== self::TIER_FREE ) {
			return;
		}

		$key     = self::get_counter_transient_key();
		$counter = (int) get_transient( $key );

		// Set TTL to end of current UTC month (consistent with UTC month key).
		$now_utc      = time();
		$end_of_month = gmmktime( 23, 59, 59, (int) gmdate( 'n', $now_utc ), (int) gmdate( 't', $now_utc ), (int) gmdate( 'Y', $now_utc ) );
		$ttl          = max( $end_of_month - $now_utc, HOUR_IN_SECONDS );

		set_transient( $key, $counter + 1, $ttl );
	}

	/**
	 * Get the current monthly message count.
	 *
	 * @return int
	 */
	public static function get_monthly_counter(): int {
		return (int) get_transient( self::get_counter_transient_key() );
	}

	/**
	 * Get the remaining messages for this month (Free tier).
	 *
	 * @return int
	 */
	public static function get_remaining(): int {
		if ( self::get_tier() !== self::TIER_FREE ) {
			return PHP_INT_MAX;
		}

		return max( 0, self::FREE_MONTHLY_LIMIT - self::get_monthly_counter() );
	}

	// -------------------------------------------------------------------------
	// License API interactions
	// -------------------------------------------------------------------------

	/**
	 * Activate a license key for this site.
	 *
	 * @param string $key The license key.
	 * @return array{valid: bool, tier?: string, message: string}
	 */
	public static function activate( string $key ): array {
		$api_url = self::get_api_url();

		if ( empty( $api_url ) ) {
			return [
				'valid'   => false,
				'message' => __( 'API URL is not configured. Go to Settings → General to set it.', 'sendtomp' ),
			];
		}

		$response = wp_remote_post( $api_url . '/license/activate', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key' => $key,
				'site_url'    => home_url(),
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'valid'   => false,
				'message' => __( 'Could not connect to the licensing server. Please try again later.', 'sendtomp' ),
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $body['valid'] ) ) {
			$message = isset( $body['message'] ) ? $body['message'] : __( 'License activation failed.', 'sendtomp' );
			return [
				'valid'   => false,
				'message' => $message,
			];
		}

		// Store key and cache status.
		$settings = get_option( 'sendtomp_settings', [] );
		$settings['license_key'] = sanitize_text_field( $key );
		update_option( 'sendtomp_settings', $settings );
		sendtomp()->flush_settings_cache();

		$status = [
			'valid'      => true,
			'tier'       => isset( $body['tier'] ) ? sanitize_text_field( $body['tier'] ) : self::TIER_PLUS,
			'expires_at' => isset( $body['expires_at'] ) ? sanitize_text_field( $body['expires_at'] ) : '',
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
		];

		set_transient( self::STATUS_CACHE_KEY, $status, DAY_IN_SECONDS );

		return [
			'valid'   => true,
			'tier'    => $status['tier'],
			'message' => sprintf(
				/* translators: %s: tier name */
				__( 'License activated. Your plan: %s.', 'sendtomp' ),
				ucfirst( $status['tier'] )
			),
		];
	}

	/**
	 * Deactivate the current license for this site.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function deactivate(): array {
		$key = sendtomp()->get_setting( 'license_key' );

		if ( empty( $key ) ) {
			return [
				'success' => false,
				'message' => __( 'No license key to deactivate.', 'sendtomp' ),
			];
		}

		$api_url = self::get_api_url();

		if ( ! empty( $api_url ) ) {
			wp_remote_post( $api_url . '/license/deactivate', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key' => $key,
				'site_url'    => home_url(),
			] ),
		] );
		}

		// Clear key and cached status regardless of API response.
		$settings = get_option( 'sendtomp_settings', [] );
		$settings['license_key'] = '';
		update_option( 'sendtomp_settings', $settings );
		sendtomp()->flush_settings_cache();

		delete_transient( self::STATUS_CACHE_KEY );

		return [
			'success' => true,
			'message' => __( 'License deactivated. You are now on the Free plan.', 'sendtomp' ),
		];
	}

	/**
	 * Refresh the cached license status from the API.
	 *
	 * Called by a daily cron or on-demand from admin.
	 *
	 * @return void
	 */
	public static function refresh_status(): void {
		$key = sendtomp()->get_setting( 'license_key' );

		if ( empty( $key ) ) {
			delete_transient( self::STATUS_CACHE_KEY );
			return;
		}

		$api_url = self::get_api_url();

		if ( empty( $api_url ) ) {
			return;
		}

		$response = wp_remote_post( $api_url . '/license/check', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key' => $key,
				'site_url'    => home_url(),
			] ),
		] );

		// On failure, keep the existing cached status (fail-open).
		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
			$status = [
				'valid'      => ! empty( $body['valid'] ),
				'tier'       => isset( $body['tier'] ) ? sanitize_text_field( $body['tier'] ) : self::TIER_FREE,
				'expires_at' => isset( $body['expires_at'] ) ? sanitize_text_field( $body['expires_at'] ) : '',
				'status'     => isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'unknown',
				'checked_at' => gmdate( 'Y-m-d H:i:s' ),
			];

			set_transient( self::STATUS_CACHE_KEY, $status, DAY_IN_SECONDS );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the licensing API base URL.
	 *
	 * Uses the same API URL as the middleware (resolve-member etc.).
	 *
	 * @return string
	 */
	private static function get_api_url(): string {
		return untrailingslashit( (string) sendtomp()->get_setting( 'api_url' ) );
	}

	/**
	 * Get the transient key for the current month's counter.
	 *
	 * @return string
	 */
	private static function get_counter_transient_key(): string {
		return self::COUNTER_KEY . '_' . gmdate( 'Ym' );
	}
}
