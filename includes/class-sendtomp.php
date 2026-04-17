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

		$this->load_dependencies();
		$this->detect_adapters();

		if ( is_admin() ) {
			new SendToMP_Admin();
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
			'bcc_emails'          => '',
			'subject_template'    => 'Message from {constituent_name} in {mp_constituency}',
			'email_template'      => '',
			'default_house'       => 'commons',
			'campaign_type'       => 'general',
			'rate_limit_email'    => 3,
			'rate_limit_ip'       => 10,
			'rate_limit_postcode' => 20,
			'rate_limit_global'   => 100,
			'confirmation_expiry' => 24,
			'log_retention'       => 90,
			'show_branding'       => true,
			'directory_optin'     => false,
		);

		$saved = get_option( 'sendtomp_settings', array() );
		$this->settings = wp_parse_args( $saved, $defaults );

		return $this->settings;
	}

	public function get_setting( $key ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Check whether the current license tier supports a given feature.
	 * Phase 6 will implement proper tier checking. For now, all features are enabled.
	 */
	public function sendtomp_can( $feature ) {
		// Features: 'lords', 'bcc', 'webhook_api', 'remove_branding',
		// 'local_overrides', 'wpforms_adapter', 'cf7_adapter', 'csv_export'
		return true;
	}

	public static function activate() {
		SendToMP_Confirmation::create_table();
		SendToMP_Logger::create_table();
	}

	public static function deactivate() {
		// Cleanup tasks on deactivation.
	}
}
