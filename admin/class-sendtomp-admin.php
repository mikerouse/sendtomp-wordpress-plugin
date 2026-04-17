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
			plugin_dir_url( __FILE__ ) . '../assets/css/sendtomp-admin.css',
			[],
			SENDTOMP_VERSION
		);

		wp_enqueue_script(
			'sendtomp-admin',
			plugin_dir_url( __FILE__ ) . '../assets/js/sendtomp-admin.js',
			[ 'jquery' ],
			SENDTOMP_VERSION,
			true
		);

		wp_localize_script( 'sendtomp-admin', 'sendtompAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sendtomp_admin' ),
		] );
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

		// Check for API URL configuration.
		$api_url = sendtomp()->get_setting( 'api_url' );
		if ( empty( $api_url ) ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'SendToMP:', 'sendtomp' ) . '</strong> ';
			echo esc_html__( 'The middleware API URL is not configured. Please set it in the General settings tab to enable MP lookups.', 'sendtomp' );
			echo '</p></div>';
		}
	}
}
