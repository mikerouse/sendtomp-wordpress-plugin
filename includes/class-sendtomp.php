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

		// Initialise custom updater (checks for plugin updates via licensing API).
		SendToMP_Updater::init();

		// Schedule daily license status refresh.
		add_action( 'sendtomp_license_check', [ 'SendToMP_License', 'refresh_status' ] );
		if ( ! wp_next_scheduled( 'sendtomp_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'sendtomp_license_check' );
		}

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
		} else {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
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
		if ( ! empty( $this->adapters ) ) {
			return;
		}

		// Gravity Forms adapter — uses GF's addon registration system.
		if ( class_exists( 'GFForms' ) ) {
			require_once SENDTOMP_PLUGIN_DIR . 'adapters/gravity-forms/class-sendtomp-gf-adapter.php';
			GFAddOn::register( 'SendToMP_GF_Adapter' );
			$this->adapters['gravity-forms'] = SendToMP_GF_Adapter::get_instance();
		}

		// WPForms adapter (Plus+ tier).
		if ( function_exists( 'wpforms' ) && $this->can( 'wpforms_adapter' ) ) {
			require_once SENDTOMP_PLUGIN_DIR . 'adapters/wpforms/class-sendtomp-wpforms-adapter.php';
			$adapter = new SendToMP_WPForms_Adapter();
			$adapter->register_hooks();
			$this->adapters['wpforms'] = $adapter;
		}

		// Contact Form 7 adapter (Plus+ tier).
		if ( defined( 'WPCF7_VERSION' ) && $this->can( 'cf7_adapter' ) ) {
			require_once SENDTOMP_PLUGIN_DIR . 'adapters/contact-form-7/class-sendtomp-cf7-adapter.php';
			$adapter = new SendToMP_CF7_Adapter();
			$adapter->register_hooks();
			$this->adapters['cf7'] = $adapter;
		}

		// Webhook adapter (Pro tier).
		if ( $this->can( 'webhook_api' ) ) {
			require_once SENDTOMP_PLUGIN_DIR . 'adapters/webhook/class-sendtomp-webhook-adapter.php';
			$adapter = new SendToMP_Webhook_Adapter();
			$adapter->register_hooks();
			$this->adapters['webhook'] = $adapter;
		}
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
			'license_key'                     => '',
			'webhook_api_key_hash'            => '',
			'webhook_api_key_privileged_hash' => '',
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
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public function can( $feature ) {
		return SendToMP_License::can( $feature );
	}

	/**
	 * Enqueue frontend assets for postcode lookup.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		wp_enqueue_script(
			'sendtomp-postcode-lookup',
			SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-postcode-lookup.js',
			[ 'jquery' ],
			SENDTOMP_VERSION,
			true
		);

		wp_localize_script( 'sendtomp-postcode-lookup', 'sendtomp_frontend', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sendtomp_postcode_lookup' ),
		] );
	}

	public static function activate() {
		SendToMP_Confirmation::create_table();
		SendToMP_Logger::create_table();
		( new SendToMP_Confirmation() )->schedule_cleanup();

		// Schedule daily log purge.
		if ( ! wp_next_scheduled( 'sendtomp_purge_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'sendtomp_purge_old_logs' );
		}

		// Schedule daily license status refresh.
		if ( ! wp_next_scheduled( 'sendtomp_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'sendtomp_license_check' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'sendtomp_cleanup_pending' );
		wp_clear_scheduled_hook( 'sendtomp_purge_old_logs' );
		wp_clear_scheduled_hook( 'sendtomp_license_check' );
	}
}
