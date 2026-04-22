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
		add_filter( 'gform_admin_messages', [ $this, 'maybe_inject_gf_confirmation_handoff_message' ] );
		add_action( 'gform_editor_pre_render', [ $this, 'maybe_render_form_editor_feed_missing_notice' ] );
		add_action( 'admin_print_footer_scripts', [ $this, 'maybe_print_handoff_copy_script' ] );
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
			$this->asset_version( 'assets/css/sendtomp-admin.css' )
		);

		wp_enqueue_script(
			'sendtomp-admin',
			SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-admin.js',
			[ 'jquery' ],
			$this->asset_version( 'assets/js/sendtomp-admin.js' ),
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
				$this->asset_version( 'assets/js/sendtomp-delivery.js' ),
				true
			);
		}

		if ( 'overrides' === $current_tab ) {
			wp_enqueue_script(
				'sendtomp-overrides',
				SENDTOMP_PLUGIN_URL . 'assets/js/sendtomp-overrides.js',
				[ 'jquery', 'sendtomp-admin' ],
				$this->asset_version( 'assets/js/sendtomp-overrides.js' ),
				true
			);
		}
	}

	/**
	 * Build a cache-busting version string for a bundled asset.
	 *
	 * Combines the plugin version with the file's mtime so that edits
	 * to CSS/JS during a release cycle bust browser caches without
	 * having to bump SENDTOMP_VERSION.
	 *
	 * @param string $relative_path Path under the plugin dir, e.g.
	 *                              "assets/css/sendtomp-admin.css".
	 * @return string Version string for wp_enqueue_*().
	 */
	private function asset_version( string $relative_path ): string {
		$full = SENDTOMP_PLUGIN_DIR . $relative_path;
		if ( ! file_exists( $full ) ) {
			return SENDTOMP_VERSION;
		}
		return SENDTOMP_VERSION . '.' . filemtime( $full );
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

		// Nudge the user if nothing is set up for email delivery yet.
		// The check covers both a detected SMTP plugin AND the built-in
		// Email Delivery tab (Brevo / Custom SMTP), so it goes quiet as
		// soon as either is configured.
		$mailer = new SendToMP_Mailer();
		if ( ! $mailer->is_delivery_configured() ) {
			$delivery_tab_url = admin_url( 'admin.php?page=sendtomp&tab=delivery' );
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'SendToMP:', 'sendtomp' ) . '</strong> ';
			echo esc_html__( 'Email delivery is falling back to WordPress default mail, which is not reliable for MP inboxes. Configure a provider on the Email Delivery tab, or install an SMTP plugin.', 'sendtomp' );
			echo ' <a href="' . esc_url( $delivery_tab_url ) . '">' . esc_html__( 'Open Email Delivery', 'sendtomp' ) . ' &rarr;</a>';
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
	 * Inject a handoff reminder into Gravity Forms' admin-messages stream
	 * on the form's Confirmations page. Reminds the site owner that the
	 * default GF confirmation ("Thanks, we'll be in touch shortly") is
	 * misleading for SendToMP forms, since the message only reaches the
	 * MP after the visitor clicks a link in their confirmation email.
	 *
	 * Why this hook and not `admin_notices`: Gravity Forms renders its
	 * own layout on its admin pages and the standard WP `admin_notices`
	 * output area isn't surfaced there. GF runs its own notices through
	 * `gform_admin_messages` (info) and `gform_admin_error_messages`
	 * (error) filters — these are the only reliable way to surface a
	 * notice on GF admin subviews.
	 *
	 * TODO (v2): suppress when the active confirmation already contains
	 * wording that signals the handoff (e.g. "check your email", "confirm").
	 * Currently shown unconditionally on the Confirmations tab when a
	 * SendToMP feed exists for the form.
	 *
	 * @param array $messages Existing GF admin messages.
	 * @return array
	 */
	public function maybe_inject_gf_confirmation_handoff_message( $messages ) {
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			return $messages;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only URL parameter for screen detection.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';
		$form_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'gf_edit_forms' !== $page || 'settings' !== $view || 'confirmation' !== $subview || ! $form_id ) {
			return $messages;
		}

		// Feeds are stored with addon_slug = 'gravity-forms' rather than
		// 'sendtomp' because the adapter's get_slug() is overridden to
		// satisfy SendToMP_Form_Adapter_Interface (routing between
		// adapters), and GF happens to call that same method when saving
		// feeds. Naming collision noted in
		// docs/TODO or in the adapter file — for now, query using what's
		// actually on disk. Changing $_slug would orphan existing feeds.
		$feeds = GFAPI::get_feeds( null, $form_id, 'gravity-forms' );
		if ( is_wp_error( $feeds ) || empty( $feeds ) ) {
			return $messages;
		}

		$has_active_feed = false;
		foreach ( $feeds as $feed ) {
			if ( ! empty( $feed['is_active'] ) ) {
				$has_active_feed = true;
				break;
			}
		}
		if ( ! $has_active_feed ) {
			return $messages;
		}

		// Reuse the copy written on the adapter so feed editor and admin
		// message stay in sync if one is edited. GF wraps each entry in
		// its own notice markup, so we pass body HTML only (no outer div).
		$body = '';
		if ( class_exists( 'SendToMP_GF_Adapter' ) && method_exists( 'SendToMP_GF_Adapter', 'render_handoff_notice_html' ) ) {
			$body = SendToMP_GF_Adapter::get_instance()->render_handoff_notice_html();
		}

		$messages[] = '<strong>' . esc_html__( 'SendToMP is active on this form.', 'sendtomp' ) . '</strong> '
			. wp_kses_post( $body );

		// Second message: the ready-to-paste confirmation HTML with a Copy
		// button. GF renders multi-message payloads as a <ul><li>…</li></ul>,
		// so block content (pre, div, etc.) is valid HTML inside each <li>.
		if ( class_exists( 'SendToMP_GF_Adapter' ) && method_exists( 'SendToMP_GF_Adapter', 'render_handoff_snippet_ui' ) ) {
			$messages[] = wp_kses_post(
				SendToMP_GF_Adapter::get_instance()->render_handoff_snippet_ui()
			);
		}

		return $messages;
	}

	/**
	 * Render a warning on the Gravity Forms form editor when the form
	 * contains a postcode-looking field but has no active SendToMP feed.
	 * Hooked to `gform_editor_pre_render` rather than admin_notices
	 * because GF strips standard admin_notices that aren't tagged with
	 * the `gf-notice` class, and the form editor page doesn't call
	 * `display_admin_message()` (so gform_admin_messages doesn't reach
	 * it either).
	 *
	 * TODO (v2): replace this warning with a first-class "MP Lookup
	 * Field" custom GF field type. Drag-and-drop discovery is more
	 * usable than an admin warning, but the field type still needs
	 * feed-level coupling for send-time behaviour, so this notice
	 * stays useful as a companion even after the custom field lands.
	 *
	 * @param array $form The form being edited.
	 * @return void
	 */
	public function maybe_render_form_editor_feed_missing_notice( $form ): void {
		if ( empty( $form['id'] ) || empty( $form['fields'] ) ) {
			return;
		}
		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}

		// Heuristic: look for any field whose label or admin label
		// mentions "post code" / "postcode" / "postal code" / "zip".
		$has_postcode_field = false;
		foreach ( $form['fields'] as $field ) {
			$label = strtolower(
				(string) ( $field->label ?? '' ) . ' ' . (string) ( $field->adminLabel ?? '' )
			);
			if ( preg_match( '/\b(post\s*code|postal\s*code|zip)\b/', $label ) ) {
				$has_postcode_field = true;
				break;
			}
		}
		if ( ! $has_postcode_field ) {
			return;
		}

		// Feeds are stored with addon_slug='gravity-forms' (see
		// SendToMP_GF_Adapter::get_slug() for the reason).
		$feeds = GFAPI::get_feeds( null, (int) $form['id'], 'gravity-forms' );
		if ( ! is_wp_error( $feeds ) && ! empty( $feeds ) ) {
			foreach ( $feeds as $feed ) {
				if ( ! empty( $feed['is_active'] ) ) {
					return; // Active feed exists — nothing to warn about.
				}
			}
		}

		$feed_url = admin_url(
			'admin.php?page=gf_edit_forms&view=settings&subview=gravity-forms&id=' . absint( $form['id'] ) . '&fid=0'
		);

		?>
		<div class="notice notice-warning gf-notice" style="margin:10px 0;padding:10px 14px;">
			<p><strong><?php esc_html_e( 'SendToMP is not set up for this form yet.', 'sendtomp' ); ?></strong></p>
			<p><?php esc_html_e( 'This form has a postcode field, but no active SendToMP feed. The "Find my MP" button and MP lookup won\'t appear on the published form until you create one.', 'sendtomp' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $feed_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create SendToMP feed', 'sendtomp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Print the tiny clipboard helper for the handoff copy link in the
	 * admin footer, but only on the GF Confirmations page where the
	 * handoff notice actually renders.
	 *
	 * Kept inline rather than enqueued — it's ~10 lines and only runs
	 * on one specific admin subview.
	 *
	 * @return void
	 */
	public function maybe_print_handoff_copy_script(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'gf_edit_forms' !== $page || 'settings' !== $view || 'confirmation' !== $subview ) {
			return;
		}

		$copied_label = esc_js( __( 'Copied to clipboard', 'sendtomp' ) );
		$failed_label = esc_js( __( 'Copy failed — please select and copy manually', 'sendtomp' ) );
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( evt ) {
				var link = evt.target.closest( 'a.sendtomp-copy-link' );
				if ( ! link ) { return; }
				evt.preventDefault();

				var href   = link.getAttribute( 'href' ) || '';
				var target = href.charAt( 0 ) === '#'
					? document.getElementById( href.slice( 1 ) )
					: null;
				if ( ! target ) { return; }

				var status = link.parentNode.querySelector( '.sendtomp-copy-status' );
				var done   = function ( msg ) { if ( status ) { status.textContent = msg; } };

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( target.textContent ).then(
						function () { done( '<?php echo $copied_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_js above. ?>' ); },
						function () { done( '<?php echo $failed_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_js above. ?>' ); }
					);
				} else {
					var range = document.createRange();
					range.selectNodeContents( target );
					var sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange( range );
					try {
						document.execCommand( 'copy' );
						done( '<?php echo $copied_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_js above. ?>' );
					} catch ( e ) {
						done( '<?php echo $failed_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_js above. ?>' );
					}
					sel.removeAllRanges();
				}
			} );
		} )();
		</script>
		<?php
	}
}
