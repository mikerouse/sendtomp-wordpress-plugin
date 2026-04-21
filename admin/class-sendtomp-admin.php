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
		add_action( 'admin_notices', [ $this, 'maybe_render_gf_confirmation_handoff_notice' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_post_sendtomp_dismiss_form_notice', [ $this, 'handle_dismiss_form_notice' ] );

		$this->settings = new SendToMP_Settings();
	}

	/**
	 * Redirect to the Status tab after plugin activation so users see
	 * what's set up and what they need to do next.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'sendtomp_activation_redirect' ) ) {
			return;
		}

		// Don't redirect during bulk activation or AJAX.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL parameter from WP core plugin list.
			delete_transient( 'sendtomp_activation_redirect' );
			return;
		}

		delete_transient( 'sendtomp_activation_redirect' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sendtomp&tab=status' ) );
		exit;
	}

	/**
	 * Handle the "Don't remind me again" dismissal for the form-missing notice.
	 *
	 * @return void
	 */
	public function handle_dismiss_form_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sendtomp' ) );
		}

		check_admin_referer( 'sendtomp_dismiss_form_notice' );

		update_user_meta( get_current_user_id(), 'sendtomp_form_notice_dismissed', SENDTOMP_VERSION );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=sendtomp&tab=status' );
		wp_safe_redirect( $redirect );
		exit;
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

		// Tab-specific scripts.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		if ( 'delivery' === $current_tab ) {
			wp_enqueue_script(
				'sendtomp-delivery',
				SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-delivery.js',
				[ 'jquery', 'sendtomp-admin' ],
				SENDTOMP_VERSION,
				true
			);
		}

		if ( 'overrides' === $current_tab ) {
			wp_enqueue_script(
				'sendtomp-overrides',
				SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-overrides.js',
				[ 'jquery', 'sendtomp-admin' ],
				SENDTOMP_VERSION,
				true
			);
		}
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

		// Missing form plugin — the most important notice; shown first.
		$this->maybe_render_form_missing_notice();

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

		// (continues below — existing licence warnings)

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

	/**
	 * Render a prominent notice when no supported form plugin is active.
	 *
	 * Suppressed once the user chooses "Don't remind me again" for the
	 * current plugin version. A major version bump re-enables the notice.
	 *
	 * @return void
	 */
	private function maybe_render_form_missing_notice(): void {
		if ( SendToMP_Status::has_active_form_plugin() ) {
			return;
		}

		$dismissed_version = get_user_meta( get_current_user_id(), 'sendtomp_form_notice_dismissed', true );
		if ( $dismissed_version && version_compare( (string) $dismissed_version, SENDTOMP_VERSION, '>=' ) ) {
			return;
		}

		$status_url        = admin_url( 'admin.php?page=sendtomp&tab=status' );
		$dismiss_permanent = wp_nonce_url(
			admin_url( 'admin-post.php?action=sendtomp_dismiss_form_notice' ),
			'sendtomp_dismiss_form_notice'
		);

		echo '<div class="notice notice-warning is-dismissible sendtomp-notice-form-missing">';
		echo '<p><strong>' . esc_html__( 'SendToMP needs a form plugin to send messages.', 'sendtomp' ) . '</strong> ';
		echo esc_html__( 'Install and activate Gravity Forms (works on the Free plan), WPForms, or Contact Form 7 to get started.', 'sendtomp' );
		echo '</p>';
		echo '<p class="sendtomp-notice-actions">';
		echo '<a class="button button-primary" href="' . esc_url( $status_url ) . '">' . esc_html__( 'View status', 'sendtomp' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss_permanent ) . '">' . esc_html__( "Don't remind me again", 'sendtomp' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Admin notice on the Gravity Forms Confirmations page reminding the
	 * site owner that SendToMP uses a double opt-in flow, so the default
	 * GF confirmation ("Thanks, we'll be in touch shortly") is misleading
	 * and should be updated to tell visitors to check their email.
	 *
	 * Hooked from admin_notices (registered on the main plugin's admin
	 * class, not the GF adapter, because GFFeedAddOn::init() doesn't
	 * reliably run on every GF admin page and the notice kept failing
	 * to render on the Confirmations subview).
	 *
	 * TODO (v2): suppress when the active confirmation already contains
	 * wording that signals the handoff (e.g. "check your email", "confirm").
	 * Currently shown unconditionally on the Confirmations tab.
	 *
	 * @return void
	 */
	public function maybe_render_gf_confirmation_handoff_notice(): void {
		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only URL parameter for screen detection.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';
		$form_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'gf_edit_forms' !== $page || 'settings' !== $view || 'confirmation' !== $subview || ! $form_id ) {
			return;
		}

		$feeds = GFAPI::get_feeds( null, $form_id, 'sendtomp' );
		if ( is_wp_error( $feeds ) || empty( $feeds ) ) {
			return;
		}

		$has_active_feed = false;
		foreach ( $feeds as $feed ) {
			if ( ! empty( $feed['is_active'] ) ) {
				$has_active_feed = true;
				break;
			}
		}
		if ( ! $has_active_feed ) {
			return;
		}

		// Reuse the copy written on the adapter so feed editor and admin
		// notice stay in sync if one is edited.
		$body = '';
		if ( class_exists( 'SendToMP_GF_Adapter' ) && method_exists( 'SendToMP_GF_Adapter', 'render_handoff_notice_html' ) ) {
			$body = SendToMP_GF_Adapter::get_instance()->render_handoff_notice_html();
		}

		echo '<div class="notice notice-info"><h3 style="margin-top:0.75em;">'
			. esc_html__( 'SendToMP is active on this form', 'sendtomp' )
			. '</h3>'
			. wp_kses_post( $body )
			. '</div>';
	}
}
