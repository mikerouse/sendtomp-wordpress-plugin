<?php
/**
 * SendToMP_Overrides — local address override management.
 *
 * Stores site-level overrides in wp_options as a serialised array keyed
 * by parliament_member_id. These take precedence over global (Supabase)
 * overrides returned by the middleware API.
 *
 * Precedence: Local override > Global override > Parliament API data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Overrides {

	/**
	 * Option name for storing local overrides.
	 */
	const OPTION_KEY = 'sendtomp_local_overrides';

	/**
	 * Get all local overrides.
	 *
	 * @return array Associative array keyed by member ID.
	 */
	public static function get_all(): array {
		$overrides = get_option( self::OPTION_KEY, [] );

		return is_array( $overrides ) ? $overrides : [];
	}

	/**
	 * Get a local override for a specific member.
	 *
	 * @param int $member_id Parliament member ID.
	 * @return array|null Override data or null if not found.
	 */
	public static function get( int $member_id ): ?array {
		$overrides = self::get_all();

		return isset( $overrides[ $member_id ] ) ? $overrides[ $member_id ] : null;
	}

	/**
	 * Save a local override for a member.
	 *
	 * @param int    $member_id   Parliament member ID.
	 * @param string $member_name Display name for admin convenience.
	 * @param string $house       'commons' or 'lords'.
	 * @param string $email       Override email address.
	 * @param string $notes       Admin notes (why this override exists).
	 * @return bool True on success.
	 */
	public static function save( int $member_id, string $member_name, string $house, string $email, string $notes = '' ): bool {
		$overrides = self::get_all();

		$overrides[ $member_id ] = [
			'member_name' => $member_name,
			'house'       => $house,
			'email'       => $email,
			'notes'       => $notes,
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
		];

		// Use add_option on first save to set autoload=false (avoids loading
		// potentially large override sets on every request).
		if ( false === get_option( self::OPTION_KEY ) ) {
			return add_option( self::OPTION_KEY, $overrides, '', false );
		}

		return update_option( self::OPTION_KEY, $overrides );
	}

	/**
	 * Delete a local override for a member.
	 *
	 * @param int $member_id Parliament member ID.
	 * @return bool True on success.
	 */
	public static function delete( int $member_id ): bool {
		$overrides = self::get_all();

		if ( ! isset( $overrides[ $member_id ] ) ) {
			return false;
		}

		unset( $overrides[ $member_id ] );

		return update_option( self::OPTION_KEY, $overrides );
	}

	/**
	 * Apply local override to a resolved_member array if one exists.
	 *
	 * Checks whether a local override exists for the resolved member ID.
	 * If so, replaces the delivery_email and marks override_applied as 'local'.
	 * If the middleware already applied a global override, the local one wins.
	 *
	 * @param array $resolved_member The resolved member data from the pipeline.
	 * @return array Modified resolved_member with local override applied (if any).
	 */
	public static function apply( array $resolved_member ): array {
		$member_id = isset( $resolved_member['id'] ) ? (int) $resolved_member['id'] : 0;

		if ( $member_id < 1 ) {
			return $resolved_member;
		}

		// Normalise override_applied from boolean to string regardless of tier.
		// This ensures consistent logging even when local_overrides is disabled.
		if ( ! empty( $resolved_member['override_applied'] ) && true === $resolved_member['override_applied'] ) {
			$resolved_member['override_applied'] = 'global';
		}

		if ( ! sendtomp()->can( 'local_overrides' ) ) {
			return $resolved_member;
		}

		$override = self::get( $member_id );

		if ( ! $override || empty( $override['email'] ) ) {
			return $resolved_member;
		}

		// Local override wins.
		$resolved_member['delivery_email']   = $override['email'];
		$resolved_member['override_applied'] = 'local';
		$resolved_member['contact_quality']  = 'override';

		return $resolved_member;
	}
}
