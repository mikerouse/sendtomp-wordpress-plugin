<?php
/**
 * SendToMP Uninstall
 *
 * Fired when the plugin is uninstalled. Cleans up all plugin data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Deactivate license BEFORE deleting settings (needs the API URL and key).
$settings = get_option( 'sendtomp_settings', [] );

// Delete plugin options.
delete_option( 'sendtomp_settings' );
delete_option( 'sendtomp_db_version' );
delete_option( 'sendtomp_local_overrides' );

// Delete all transients
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_sendtomp_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_sendtomp_' ) . '%'
) );

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sendtomp_pending" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sendtomp_log" );

// Clear scheduled events
wp_clear_scheduled_hook( 'sendtomp_cleanup_pending' );
wp_clear_scheduled_hook( 'sendtomp_purge_old_logs' );
wp_clear_scheduled_hook( 'sendtomp_license_check' );
if ( ! empty( $settings['api_url'] ) && ! empty( $settings['license_key'] ) && ! empty( $settings['api_key'] ) ) {
	wp_remote_post(
		rtrim( $settings['api_url'], '/' ) . '/license/deactivate',
		[
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $settings['api_key'],
			],
			'body'     => wp_json_encode( [
				'license_key' => $settings['license_key'],
				'site_url'    => home_url(),
			] ),
			'blocking' => false,
			'timeout'  => 5,
		]
	);
}
