<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP {

	private static $instance = null;
	private $adapters = array();
	private $settings = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		load_plugin_textdomain( 'sendtomp', false, dirname( SENDTOMP_PLUGIN_BASENAME ) . '/languages' );

		$this->maybe_upgrade_db();
		$this->load_dependencies();
		$this->detect_adapters();

		// Confirmation flow must be loaded on both frontend and admin.
		new SendToMP_Confirmation();

		// Log purge cron callback.
		add_action( 'sendtomp_purge_old_logs', function () {
			$days = (int) sendtomp()->get_setting( 'log_retention' );
			SendToMP_Logger::purge_old( $days > 0 ? $days : 90 );
		} );

		if ( is_admin() ) {
			new SendToMP_Admin();
		}
	}

	/**
	 * Check DB version and re-run table creation if needed (handles plugin updates
	 * where activation hook doesn't re-fire).
	 */
	private function maybe_upgrade_db() {
		$installed_version = get_option( 'sendtomp_db_version', '0' );
		if ( version_compare( $installed_version, SENDTOMP_VERSION, '<' ) ) {
			SendToMP_Confirmation::create_table();
			SendToMP_Logger::create_table();
			update_option( 'sendtomp_db_version', SENDTOMP_VERSION );
		}
	}

	private function load_dependencies() {
		// Classes are autoloaded. Instantiate core singletons here if needed later.
	}

	private function detect_adapters() {
		// Gravity Forms adapter
		if ( class_exists( 'GFForms' ) ) {
			// $this->adapters['gravityforms'] = new SendToMP_Adapter_GravityForms();
		}

		// WPForms adapter
		if ( function_exists( 'wpforms' ) ) {
			// $this->adapters['wpforms'] = new SendToMP_Adapter_WPForms();
		}

		// Contact Form 7 adapter
		if ( defined( 'WPCF7_VERSION' ) ) {
			// $this->adapters['cf7'] = new SendToMP_Adapter_CF7();
		}

		// Webhook adapter (always available)
		// $this->adapters['webhook'] = new SendToMP_Adapter_Webhook();
	}

	public function get_adapters() {
		return $this->adapters;
	}

	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$defaults = array(
			'api_url'             => '',
			'api_key'             => '',
			'from_email'          => get_option( 'admin_email' ),
			'from_name'           => get_bloginfo( 'name' ),
			'reply_to'            => 'constituent',
			'reply_to_email'      => '',
			'bcc_emails'          => '',
			'subject_template'    => 'Message from {constituent_name} in {mp_constituency}',
			'confirmation_subject' => 'Please confirm your message to {mp_name}',
			'email_template'      => '',
			'default_house'       => 'commons',
			'campaign_type'       => 'general',
			'rate_limit_email'    => 3,
			'rate_limit_ip'       => 10,
			'rate_limit_postcode' => 20,
			'rate_limit_global'   => 100,
			'confirmation_expiry' => 24,
			'consent_text'        => '',
			'thankyou_message'    => '',
			'log_retention'       => 90,
			'show_branding'       => true,
			'directory_optin'     => false,
			'license_key'         => '',
		);

		$saved = get_option( 'sendtomp_settings', array() );
		$this->settings = wp_parse_args( $saved, $defaults );

		return $this->settings;
	}

	public function get_setting( $key ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function flush_settings_cache() {
		$this->settings = null;
	}

	/**
	 * Check whether the current license tier supports a given feature.
	 * Phase 6 will implement proper tier checking. For now, all features are enabled.
	 */
	public function can( $feature ) {
		// Features: 'lords', 'bcc', 'webhook_api', 'remove_branding',
		// 'local_overrides', 'wpforms_adapter', 'cf7_adapter', 'csv_export'
		return true;
	}

	public static function activate() {
		SendToMP_Confirmation::create_table();
		SendToMP_Logger::create_table();
		( new SendToMP_Confirmation() )->schedule_cleanup();

		// Schedule daily log purge.
		if ( ! wp_next_scheduled( 'sendtomp_purge_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'sendtomp_purge_old_logs' );
		}
	}

	public static function deactivate() {
		// Cleanup tasks on deactivation.
	}
}
