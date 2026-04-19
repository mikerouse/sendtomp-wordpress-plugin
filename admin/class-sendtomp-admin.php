<?php
/**
 * SendToMP_Admin — admin page registration and menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Admin {

	/**
	 * @var SendToMP_Settings
	 */
	private $settings;

	/**
	 * Constructor — register admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );

		$this->settings = new SendToMP_Settings();
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function add_menu_pages(): void {
		add_menu_page(
			__( 'SendToMP', 'sendtomp' ),
			__( 'SendToMP', 'sendtomp' ),
			'manage_options',
			'sendtomp',
			[ $this, 'render_settings_page' ],
			'dashicons-email-alt',
			80
		);

		add_submenu_page(
			'sendtomp',
			__( 'Submission Log', 'sendtomp' ),
			__( 'Submission Log', 'sendtomp' ),
			'manage_options',
			'sendtomp-log',
			[ $this, 'render_log_page' ]
		);
	}

	/**
	 * Render the settings page by loading the view template.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include plugin_dir_path( __FILE__ ) . 'views/settings-page.php';
	}

	/**
	 * Render the submission log page by loading the view template.
	 *
	 * @return void
	 */
	public function render_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include plugin_dir_path( __FILE__ ) . 'views/logs-page.php';
	}

	/**
	 * Enqueue admin CSS and JS assets on SendToMP pages only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'sendtomp' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'sendtomp-admin',
			SENDTOMP_PLUGIN_URL . 'assets/css/sendtomp-admin.css',
			[],
			SENDTOMP_VERSION
		);

		wp_enqueue_script(
			'sendtomp-admin',
			SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-admin.js',
			[ 'jquery' ],
			SENDTOMP_VERSION,
			true
		);

		wp_localize_script( 'sendtomp-admin', 'sendtomp_admin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sendtomp_admin' ),
		] );

		SendToMP_Form_Adapter_Abstract::enqueue_peer_search();
	}

	/**
	 * Display admin notices on SendToMP pages.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || strpos( $screen->id, 'sendtomp' ) === false ) {
			return;
		}

		// Check for SMTP plugin.
		$mailer = new SendToMP_Mailer();
		if ( ! $mailer->detect_smtp_plugin() ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'SendToMP:', 'sendtomp' ) . '</strong> ';
			echo esc_html__( 'No SMTP plugin detected. WordPress default mail may not be reliable for sending emails to MPs. We recommend installing an SMTP plugin such as WP Mail SMTP or FluentSMTP.', 'sendtomp' );
			echo '</p></div>';
		}

		// API URL is now hardcoded — no configuration notice needed.

		// License status notices.
		$license_key = sendtomp()->get_setting( 'license_key' );
		$license_status = SendToMP_License::get_cached_status();

		if ( ! empty( $license_key ) && $license_status && ! empty( $license_status['status'] ) && 'expired' === $license_status['status'] ) {
			echo '<div class="notice notice-error is-dismissible">';
			echo '<p><strong>' . esc_html__( 'SendToMP:', 'sendtomp' ) . '</strong> ';
			echo esc_html__( 'Your license has expired. Please renew to continue receiving updates and using premium features.', 'sendtomp' );
			echo ' <a href="' . esc_url( 'https://bluetorch.co.uk/sendtomp/portal' ) . '">' . esc_html__( 'Renew now', 'sendtomp' ) . ' &rarr;</a>';
			echo '</p></div>';
		}

		// Free tier remaining messages warning.
		if ( SendToMP_License::TIER_FREE === SendToMP_License::get_tier() ) {
			$remaining = SendToMP_License::get_remaining();
			if ( $remaining <= 5 && $remaining > 0 ) {
				echo '<div class="notice notice-warning is-dismissible">';
				echo '<p><strong>' . esc_html__( 'SendToMP:', 'sendtomp' ) . '</strong> ';
				echo esc_html( sprintf(
					/* translators: %d: remaining messages */
					__( 'You have %d messages remaining this month on the Free plan.', 'sendtomp' ),
					$remaining
				) );
				echo ' <a href="' . esc_url( 'https://bluetorch.co.uk/sendtomp#pricing' ) . '">' . esc_html__( 'Upgrade to Plus', 'sendtomp' ) . ' &rarr;</a>';
				echo '</p></div>';
			}
		}
	}
}
