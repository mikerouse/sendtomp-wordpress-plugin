<?php
/**
 * SendToMP_Status — environment detection for the Status dashboard.
 *
 * Reports the state of form plugins, email delivery, and licence so the
 * Status tab can guide users to a working setup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Status {

	const STATE_ACTIVE       = 'active';
	const STATE_INSTALLED    = 'installed';
	const STATE_NOT_INSTALLED = 'not_installed';

	/**
	 * Return the status of all supported form plugins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_form_plugins(): array {
		return [
			self::get_gravity_forms_status(),
			self::get_wpforms_status(),
			self::get_cf7_status(),
			self::get_webhook_status(),
		];
	}

	/**
	 * Gravity Forms — free tier, commercial product (not on wp.org).
	 *
	 * @return array<string, mixed>
	 */
	private static function get_gravity_forms_status(): array {
		$state = class_exists( 'GFForms' ) ? self::STATE_ACTIVE : self::STATE_NOT_INSTALLED;

		return [
			'slug'        => 'gravity-forms',
			'name'        => __( 'Gravity Forms', 'sendtomp' ),
			'description' => __( 'The recommended form plugin for SendToMP — reliable, well-supported, and built for serious correspondence workflows.', 'sendtomp' ),
			'tier'        => 'free',
			'tier_label'  => __( 'Free plan', 'sendtomp' ),
			'state'       => $state,
			'wp_org'      => false,
			// TODO: replace with real Gravity Forms affiliate URL once approved.
			'purchase_url' => 'https://www.gravityforms.com/',
			'is_affiliate' => true,
			'installed_version' => class_exists( 'GFForms' ) && defined( 'GFForms::$version' ) ? GFForms::$version : null,
		];
	}

	/**
	 * WPForms — Plus tier. Free version is on wp.org, but Pro may be needed
	 * for some features; we detect whichever is active.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_wpforms_status(): array {
		$active    = function_exists( 'wpforms' ) || class_exists( 'WPForms\\WPForms' );
		$installed = self::is_plugin_installed( 'wpforms-lite/wpforms.php' ) || self::is_plugin_installed( 'wpforms/wpforms.php' );

		if ( $active ) {
			$state = self::STATE_ACTIVE;
		} elseif ( $installed ) {
			$state = self::STATE_INSTALLED;
		} else {
			$state = self::STATE_NOT_INSTALLED;
		}

		return [
			'slug'         => 'wpforms',
			'name'         => __( 'WPForms', 'sendtomp' ),
			'description'  => __( 'Drag-and-drop form builder. The free version (WPForms Lite) works for basic needs; Pro unlocks advanced fields.', 'sendtomp' ),
			'tier'         => 'plus',
			'tier_label'   => __( 'Requires Plus plan', 'sendtomp' ),
			'state'        => $state,
			'wp_org'       => true,
			'wp_org_slug'  => 'wpforms-lite',
			'purchase_url' => 'https://wpforms.com/pricing/',
			'pro_note'     => __( 'Pro version recommended for conditional logic and payment fields.', 'sendtomp' ),
			'is_affiliate' => false,
		];
	}

	/**
	 * Contact Form 7 — Plus tier. Free plugin on wp.org.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_cf7_status(): array {
		$active    = defined( 'WPCF7_VERSION' );
		$installed = self::is_plugin_installed( 'contact-form-7/wp-contact-form-7.php' );

		if ( $active ) {
			$state = self::STATE_ACTIVE;
		} elseif ( $installed ) {
			$state = self::STATE_INSTALLED;
		} else {
			$state = self::STATE_NOT_INSTALLED;
		}

		return [
			'slug'         => 'contact-form-7',
			'name'         => __( 'Contact Form 7', 'sendtomp' ),
			'description'  => __( 'The classic WordPress form plugin. Free, flexible, and widely supported.', 'sendtomp' ),
			'tier'         => 'plus',
			'tier_label'   => __( 'Requires Plus plan', 'sendtomp' ),
			'state'        => $state,
			'wp_org'       => true,
			'wp_org_slug'  => 'contact-form-7',
			'purchase_url' => 'https://wordpress.org/plugins/contact-form-7/',
			'is_affiliate' => false,
		];
	}

	/**
	 * Webhook / REST API — Pro tier. Always "installed" since it's built in.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_webhook_status(): array {
		$can_use = function_exists( 'sendtomp' ) && sendtomp()->can( 'webhook_api' );

		return [
			'slug'         => 'webhook',
			'name'         => __( 'Webhook / REST API', 'sendtomp' ),
			'description'  => __( 'Accept submissions from Zapier, n8n, Make, or your own systems via a signed REST endpoint.', 'sendtomp' ),
			'tier'         => 'pro',
			'tier_label'   => __( 'Requires Pro plan', 'sendtomp' ),
			'state'        => $can_use ? self::STATE_ACTIVE : self::STATE_NOT_INSTALLED,
			'wp_org'       => false,
			'purchase_url' => 'https://bluetorch.co.uk/sendtomp#pricing',
			'is_affiliate' => false,
			'is_built_in'  => true,
		];
	}

	/**
	 * Is any supported form plugin active?
	 *
	 * @return bool
	 */
	public static function has_active_form_plugin(): bool {
		foreach ( self::get_form_plugins() as $plugin ) {
			if ( self::STATE_ACTIVE === $plugin['state'] && ! ( $plugin['is_built_in'] ?? false ) ) {
				return true;
			}
		}

		// Webhook counts if Pro.
		$webhook = self::get_webhook_status();
		return self::STATE_ACTIVE === $webhook['state'];
	}

	/**
	 * Check whether a plugin file is installed (active or inactive).
	 *
	 * @param string $plugin_file Relative plugin file path like "wpforms-lite/wpforms.php".
	 * @return bool
	 */
	private static function is_plugin_installed( string $plugin_file ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ] );
	}

	/**
	 * Build an "Activate" URL for a plugin file.
	 *
	 * @param string $plugin_file Relative plugin file path.
	 * @return string
	 */
	public static function get_activate_url( string $plugin_file ): string {
		return wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) ),
			'activate-plugin_' . $plugin_file
		);
	}

	/**
	 * Build an "Install" URL for a wp.org plugin slug.
	 *
	 * @param string $slug Plugin slug on wordpress.org.
	 * @return string
	 */
	public static function get_install_url( string $slug ): string {
		return wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ),
			'install-plugin_' . $slug
		);
	}

	/**
	 * Resolve the installed plugin file for a wp.org slug (so we can build
	 * an Activate URL for a plugin that's installed but not active).
	 *
	 * @param string $slug Plugin slug on wordpress.org (e.g. "wpforms-lite").
	 * @return string|null Plugin file path, or null if not installed.
	 */
	public static function get_installed_plugin_file( string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( 0 === strpos( $plugin_file, $slug . '/' ) ) {
				return $plugin_file;
			}
		}

		return null;
	}
}
