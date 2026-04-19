<?php
/**
 * SendToMP_Updater — custom plugin updater.
 *
 * Hooks into WordPress's plugin update system to check for new versions
 * via the Bluetorch licensing API. Updates require a valid Plus/Pro license.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Updater {

	/**
	 * Initialise the updater hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_for_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
	}

	/**
	 * Check for plugin updates via the licensing API.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object Modified transient.
	 */
	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$key = sendtomp()->get_setting( 'license_key' );

		// Free tier doesn't receive updates via this mechanism.
		if ( empty( $key ) ) {
			return $transient;
		}

		$api_url = untrailingslashit( (string) sendtomp()->get_setting( 'api_url' ) );

		if ( empty( $api_url ) ) {
			return $transient;
		}

		$response = wp_remote_post( $api_url . '/license/check-update', [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key'     => $key,
				'site_url'        => home_url(),
				'current_version' => SENDTOMP_VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $transient;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['update_available'] ) || empty( $body['version'] ) ) {
			return $transient;
		}

		// Only offer update if the remote version is newer.
		if ( version_compare( SENDTOMP_VERSION, $body['version'], '>=' ) ) {
			return $transient;
		}

		$update = (object) [
			'slug'        => 'sendtomp',
			'plugin'      => SENDTOMP_PLUGIN_BASENAME,
			'new_version' => sanitize_text_field( $body['version'] ),
			'url'         => 'https://www.bluetorch.co.uk/sendtomp',
			'package'     => isset( $body['download_url'] ) ? esc_url_raw( $body['download_url'] ) : '',
			'tested'      => isset( $body['tested_wp'] ) ? sanitize_text_field( $body['tested_wp'] ) : '',
			'requires'    => isset( $body['requires_wp'] ) ? sanitize_text_field( $body['requires_wp'] ) : '6.0',
		];

		$transient->response[ SENDTOMP_PLUGIN_BASENAME ] = $update;

		return $transient;
	}

	/**
	 * Provide plugin information for the "View Details" modal.
	 *
	 * @param false|object|array $result The result object/array.
	 * @param string             $action The API action.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'sendtomp' !== $args->slug ) {
			return $result;
		}

		$key     = sendtomp()->get_setting( 'license_key' );
		$api_url = untrailingslashit( (string) sendtomp()->get_setting( 'api_url' ) );

		if ( empty( $key ) || empty( $api_url ) ) {
			return $result;
		}

		$response = wp_remote_post( $api_url . '/license/check-update', [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'license_key'     => $key,
				'site_url'        => home_url(),
				'current_version' => SENDTOMP_VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['version'] ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'SendToMP',
			'slug'          => 'sendtomp',
			'version'       => sanitize_text_field( $body['version'] ),
			'author'        => '<a href="https://www.bluetorch.co.uk">Bluetorch Consulting Ltd</a>',
			'homepage'      => 'https://www.bluetorch.co.uk/sendtomp',
			'download_link' => isset( $body['download_url'] ) ? esc_url_raw( $body['download_url'] ) : '',
			'requires'      => isset( $body['requires_wp'] ) ? sanitize_text_field( $body['requires_wp'] ) : '6.0',
			'tested'        => isset( $body['tested_wp'] ) ? sanitize_text_field( $body['tested_wp'] ) : '',
			'sections'      => [
				'description' => __( 'Send verified constituent messages to UK Members of Parliament and Peers.', 'sendtomp' ),
				'changelog'   => isset( $body['changelog'] ) ? wp_kses_post( $body['changelog'] ) : '',
			],
		];
	}
}
