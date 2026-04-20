<?php
/**
 * SendToMP_Settings — registers settings and handles saving.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Settings {

	/**
	 * Option name for all plugin settings.
	 */
	const OPTION_NAME = 'sendtomp_settings';

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_sendtomp_test_email', [ $this, 'handle_test_email' ] );
		add_action( 'wp_ajax_sendtomp_purge_logs', [ $this, 'handle_purge_logs' ] );
		add_action( 'wp_ajax_sendtomp_generate_webhook_key', [ $this, 'handle_generate_webhook_key' ] );
		add_action( 'wp_ajax_sendtomp_search_members', [ $this, 'handle_search_members' ] );
		add_action( 'wp_ajax_sendtomp_activate_license', [ $this, 'handle_activate_license' ] );
		add_action( 'wp_ajax_sendtomp_deactivate_license', [ $this, 'handle_deactivate_license' ] );
		add_action( 'wp_ajax_sendtomp_export_logs_csv', [ $this, 'handle_export_logs_csv' ] );
		add_action( 'wp_ajax_sendtomp_lookup_postcode', [ $this, 'handle_lookup_postcode' ] );
		add_action( 'wp_ajax_nopriv_sendtomp_lookup_postcode', [ $this, 'handle_lookup_postcode' ] );
		add_action( 'wp_ajax_sendtomp_erase_data', [ $this, 'handle_erase_data' ] );
		add_action( 'wp_ajax_sendtomp_brevo_enquiry', [ $this, 'handle_brevo_enquiry' ] );
		add_action( 'wp_ajax_sendtomp_save_override', [ $this, 'handle_save_override' ] );
		add_action( 'wp_ajax_sendtomp_delete_override', [ $this, 'handle_delete_override' ] );
	}

	/**
	 * Register the settings, sections, and fields using the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting( 'sendtomp_settings_group', self::OPTION_NAME, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
			'default'           => [],
		] );

		$tab = $this->get_current_tab();

		switch ( $tab ) {
			case 'general':
				$this->register_general_fields();
				break;

			case 'email':
				$this->register_email_fields();
				break;

			case 'confirmation':
				$this->register_confirmation_fields();
				break;

			case 'rate-limits':
				$this->register_rate_limit_fields();
				break;

			case 'webhook':
				$this->register_webhook_fields();
				break;

			case 'license':
				$this->register_license_fields();
				break;

			case 'log':
				$this->register_log_fields();
				break;
		}
	}

	/**
	 * Register General tab fields.
	 *
	 * @return void
	 */
	private function register_general_fields(): void {
		$section = 'sendtomp_general_section';

		add_settings_section(
			$section,
			__( 'General Settings', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'These settings connect SendToMP to the Bluetorch API (which resolves postcodes to MPs) and set defaults for your campaigns.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'default_house',
			__( 'Default House', 'sendtomp' ),
			[ $this, 'render_select_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'default_house',
				'options'     => [
					'commons' => __( 'House of Commons', 'sendtomp' ),
					'lords'   => __( 'House of Lords', 'sendtomp' ),
				],
				'description' => __( 'The default target for new forms and feeds. Most campaigns target the House of Commons (MPs elected by constituency). You can override this per form — this just sets the starting default.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'campaign_type',
			__( 'Campaign Type', 'sendtomp' ),
			[ $this, 'render_select_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'campaign_type',
				'options'     => [
					'general'      => __( 'General — open-ended correspondence', 'sendtomp' ),
					'advocacy'     => __( 'Advocacy — urging action on an issue', 'sendtomp' ),
					'petition'     => __( 'Petition — supporting a specific ask', 'sendtomp' ),
					'consultation' => __( 'Consultation — sharing views on legislation', 'sendtomp' ),
				],
				'description' => __( 'Determines the default email template used when your constituents write to their MP. Each type provides a professionally framed opening and closing around the constituent\'s own message. You can always customise the template further on the Email tab.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'show_branding',
			__( 'Show Branding', 'sendtomp' ),
			[ $this, 'render_branding_field' ],
			'sendtomp',
			$section
		);

		add_settings_field(
			'directory_optin',
			__( 'Campaign Directory', 'sendtomp' ),
			[ $this, 'render_directory_optin_field' ],
			'sendtomp',
			$section
		);
	}

	/**
	 * Render the branding field with tier-appropriate messaging.
	 *
	 * @return void
	 */
	public function render_branding_field( array $args = [] ): void {
		$tier = SendToMP_License::get_tier();

		$this->render_checkbox_field( [
			'key'         => 'show_branding',
			'label'       => __( 'Show "Powered by Bluetorch\'s SendToMP" in email footers and confirmation pages', 'sendtomp' ),
			'description' => SendToMP_License::TIER_PRO === $tier
				? __( 'Pro plan: branding is off by default (white-label). Enable this if you want to show "Powered by Bluetorch\'s SendToMP" — it links to bluetorch.co.uk/sendtomp and appears as a small footer line.', 'sendtomp' )
				: __( 'When checked, a "Powered by Bluetorch\'s SendToMP" line appears in the footer of MP emails, confirmation emails, and the confirmation page. Uncheck to remove it.', 'sendtomp' ),
		] );
	}

	/**
	 * Render the campaign directory opt-in field.
	 *
	 * @return void
	 */
	public function render_directory_optin_field( array $args = [] ): void {
		if ( ! sendtomp()->can( 'lords' ) ) {
			echo '<p class="description">';
			echo esc_html__( 'The Bluetorch Campaign Directory is a public listing of active campaigns using SendToMP, helping constituents discover causes they can support. Available on Plus and Pro plans.', 'sendtomp' );
			echo '</p>';
			return;
		}

		$this->render_checkbox_field( [
			'key'         => 'directory_optin',
			'label'       => __( 'List this campaign in the Bluetorch Campaign Directory', 'sendtomp' ),
			'description' => __( 'When enabled, your site name and campaign URL will appear in the public directory at bluetorch.co.uk/campaigns. This helps constituents discover your campaign. No personal data or message content is shared — only your site name and URL.', 'sendtomp' ),
		] );
	}

	/**
	 * Register Email tab fields.
	 *
	 * @return void
	 */
	private function register_email_fields(): void {
		$section = 'sendtomp_email_section';

		add_settings_section(
			$section,
			__( 'Email Settings', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'These settings control how emails appear when they arrive in an MP\'s inbox. Getting these right is critical for deliverability and response rates.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'from_email',
			__( 'From Email', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'from_email',
				'type'        => 'email',
				'description' => __( 'The email address MPs will see as the sender. Use your main site/organisation email — not a subdomain or throwaway address. MP offices group and categorise incoming email by sender domain, so using a consistent, recognisable address helps your messages get read and acted upon. This also needs to match the domain configured in your SMTP service for SPF/DKIM authentication.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'from_name',
			__( 'From Name', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'from_name',
				'type'        => 'text',
				'description' => __( 'The name shown alongside the From Email in the MP\'s inbox. Use your organisation or campaign name — this is the first thing caseworkers see.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'reply_to',
			__( 'Reply-To Behaviour', 'sendtomp' ),
			[ $this, 'render_select_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'reply_to',
				'options'     => [
					'constituent' => __( 'Constituent\'s email address (recommended)', 'sendtomp' ),
					'fixed'       => __( 'Fixed address', 'sendtomp' ),
				],
				'description' => __( 'When set to "Constituent\'s email", the MP can reply directly to the person who wrote. This is the recommended setting — it enables genuine two-way correspondence. Use "Fixed address" only if you need all replies to go to a central inbox.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'reply_to_email',
			__( 'Fixed Reply-To Email', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'reply_to_email',
				'type'        => 'email',
				'description' => __( 'Only used when Reply-To is set to "Fixed address". All MP replies will go to this address instead of the constituent. Leave blank to use the From Email.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'bcc_emails',
			__( 'BCC Emails', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'bcc_emails',
				'type'        => 'text',
				'description' => __( 'Receive a blind copy of every message sent to an MP. Useful for campaign monitoring and record-keeping. Comma-separate multiple addresses. The MP and constituent will not see these addresses.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'subject_template',
			__( 'Subject Template', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'subject_template',
				'type'        => 'text',
				'description' => __( 'The subject line of emails sent to MPs. Placeholders: {constituent_name}, {mp_name}, {mp_constituency}, {mp_party}. A good subject helps caseworkers prioritise and categorise your messages.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'email_template',
			__( 'Email Body Template', 'sendtomp' ),
			[ $this, 'render_textarea_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'email_template',
				'description' => __( 'Custom email body template. Leave blank to use the default template for your campaign type (set on the General tab). Placeholders: {constituent_name}, {constituent_email}, {constituent_postcode}, {message_body}, {mp_name}, {mp_constituency}, {mp_party}, {site_name}. The constituent\'s message is inserted at {message_body}.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'test_email',
			__( 'Test Email', 'sendtomp' ),
			[ $this, 'render_test_email_button' ],
			'sendtomp',
			$section
		);
	}

	/**
	 * Register Confirmation tab fields.
	 *
	 * @return void
	 */
	private function register_confirmation_fields(): void {
		$section = 'sendtomp_confirmation_section';

		add_settings_section(
			$section,
			__( 'Confirmation Settings', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'SendToMP uses a double opt-in process: after a constituent submits a form, they receive an email asking them to confirm before the message is sent to their MP. This ensures GDPR compliance, prevents spam, and means every message that reaches an MP is from a verified, real person.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'confirmation_subject',
			__( 'Confirmation Email Subject', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'confirmation_subject',
				'type'        => 'text',
				'description' => __( 'The subject line of the confirmation email sent to the constituent. Use {mp_name} to include the MP\'s name. Keep it clear and action-oriented — this email needs to be opened for the message to be sent.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'confirmation_expiry',
			__( 'Confirmation Expiry (hours)', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'confirmation_expiry',
				'min'         => 1,
				'max'         => 168,
				'description' => __( 'How long the confirmation link remains valid. After this period, unconfirmed submissions are automatically deleted. 24 hours is recommended — long enough for the constituent to check their email, short enough to keep your data clean.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'consent_text',
			__( 'Consent Text', 'sendtomp' ),
			[ $this, 'render_textarea_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'consent_text',
				'description' => __( 'Shown on the confirmation page before the constituent clicks "Confirm & Send". Use this to explain what data will be shared with their MP and link to your privacy policy. Leave blank for the default text.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'thankyou_message',
			__( 'Thank-you Message', 'sendtomp' ),
			[ $this, 'render_textarea_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'thankyou_message',
				'description' => __( 'Shown after the constituent confirms and their message is sent to the MP. Use this to thank them, explain what to expect next, and encourage social sharing. Leave blank for the default message.', 'sendtomp' ),
			]
		);
	}

	/**
	 * Register Rate Limits tab fields.
	 *
	 * @return void
	 */
	private function register_rate_limit_fields(): void {
		$section = 'sendtomp_rate_limits_section';

		add_settings_section(
			$section,
			__( 'Rate Limit Settings', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'Rate limits prevent abuse by capping how many messages can be sent in a 24-hour period. These protect both your site and the MPs who receive your messages. The defaults work well for most campaigns — only adjust if you have a specific reason.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'rate_limit_email',
			__( 'Per-Email Limit', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'rate_limit_email',
				'min'         => 1,
				'max'         => 1000,
				'description' => __( 'Maximum messages one email address can send per day. Prevents a single person flooding MPs. Default: 3.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'rate_limit_ip',
			__( 'Per-IP Limit', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'rate_limit_ip',
				'min'         => 1,
				'max'         => 1000,
				'description' => __( 'Maximum messages from one IP address per day. Catches automated abuse from a single source. Set higher than per-email to allow shared networks (offices, universities). Default: 10.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'rate_limit_postcode',
			__( 'Per-Postcode Limit', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'rate_limit_postcode',
				'min'         => 1,
				'max'         => 1000,
				'description' => __( 'Maximum messages from one postcode per day. Prevents overwhelming a single MP. Set higher than per-email to allow multiple genuine constituents from the same area. Default: 20.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'rate_limit_global',
			__( 'Global Limit', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'rate_limit_global',
				'min'         => 1,
				'max'         => 100000,
				'description' => __( 'Maximum total messages from your entire site per day. A safety net to prevent runaway abuse. If you hit this limit, all submissions are paused until the next day. Default: 100.', 'sendtomp' ),
			]
		);
	}

	/**
	 * Register License tab fields.
	 *
	 * @return void
	 */
	private function register_license_fields(): void {
		$section = 'sendtomp_license_section';

		add_settings_section(
			$section,
			__( 'License', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'Enter your license key to activate premium features.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'license_key',
			__( 'License Key', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'license_key',
				'type'        => 'text',
				'description' => __( 'Your SendToMP license key.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'license_actions',
			__( 'License Status', 'sendtomp' ),
			[ $this, 'render_license_status' ],
			'sendtomp',
			$section
		);
	}

	/**
	 * Register Log tab fields.
	 *
	 * @return void
	 */
	private function register_log_fields(): void {
		$section = 'sendtomp_log_section';

		add_settings_section(
			$section,
			__( 'Log Settings', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'Configure log retention and data management.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'log_retention',
			__( 'Log Retention (days)', 'sendtomp' ),
			[ $this, 'render_number_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'log_retention',
				'min'         => 1,
				'max'         => 365,
				'description' => __( 'Number of days to retain submission logs before automatic purge.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'purge_logs',
			__( 'Purge Old Logs', 'sendtomp' ),
			[ $this, 'render_purge_button' ],
			'sendtomp',
			$section
		);

		add_settings_field(
			'erase_data',
			__( 'GDPR Data Erasure', 'sendtomp' ),
			[ $this, 'render_erase_data_field' ],
			'sendtomp',
			$section
		);

		add_settings_field(
			'privacy_notice',
			__( 'Privacy Notice Template', 'sendtomp' ),
			[ $this, 'render_privacy_notice_template' ],
			'sendtomp',
			$section
		);

		if ( sendtomp()->can( 'csv_export' ) ) {
			add_settings_field(
				'export_logs',
				__( 'Export Logs', 'sendtomp' ),
				[ $this, 'render_export_button' ],
				'sendtomp',
				$section
			);
		}
	}

	/**
	 * Register Webhook API tab fields.
	 *
	 * @return void
	 */
	private function register_webhook_fields(): void {
		$section = 'sendtomp_webhook_section';

		add_settings_section(
			$section,
			__( 'Webhook API', 'sendtomp' ),
			function () {
				echo '<p>' . esc_html__( 'Allow external systems to submit messages via the REST API.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'webhook_endpoint',
			__( 'Endpoint URL', 'sendtomp' ),
			[ $this, 'render_webhook_endpoint' ],
			'sendtomp',
			$section
		);

		add_settings_field(
			'webhook_api_key',
			__( 'Standard API Key', 'sendtomp' ),
			[ $this, 'render_api_key_field' ],
			'sendtomp',
			$section,
			[
				'key_type'    => 'standard',
				'hash_key'    => 'webhook_api_key_hash',
				'description' => __( 'Used for normal submissions (double opt-in confirmation required).', 'sendtomp' ),
			]
		);

		add_settings_field(
			'webhook_api_key_privileged',
			__( 'Privileged API Key', 'sendtomp' ),
			[ $this, 'render_api_key_field' ],
			'sendtomp',
			$section,
			[
				'key_type'    => 'privileged',
				'hash_key'    => 'webhook_api_key_privileged_hash',
				'description' => __( 'Allows skip_confirmation to send directly without double opt-in. Use with caution — ensure GDPR compliance.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'webhook_docs',
			__( 'Usage', 'sendtomp' ),
			[ $this, 'render_webhook_docs' ],
			'sendtomp',
			$section
		);
	}

	/**
	 * Render the webhook endpoint URL.
	 *
	 * @return void
	 */
	public function render_webhook_endpoint(): void {
		$url = rest_url( 'sendtomp/v1/submit' );
		echo '<code>' . esc_html( $url ) . '</code>';
		echo '<p class="description">' . esc_html__( 'POST JSON to this endpoint with an Authorization header.', 'sendtomp' ) . '</p>';
	}

	/**
	 * Render an API key field with masked display and regenerate button.
	 *
	 * @param array $args Field arguments including key_type and hash_key.
	 * @return void
	 */
	public function render_api_key_field( array $args ): void {
		$hash_key = $args['hash_key'];
		$key_type = $args['key_type'];
		$has_key  = ! empty( sendtomp()->get_setting( $hash_key ) );

		$status = $has_key
			? '<span style="color: green;">&#10003; ' . esc_html__( 'Key is set', 'sendtomp' ) . '</span>'
			: '<span style="color: #999;">' . esc_html__( 'No key generated yet', 'sendtomp' ) . '</span>';

		echo '<div id="sendtomp-webhook-key-' . esc_attr( $key_type ) . '">';
		echo '<p>' . $status . '</p>';
		echo '<button type="button" class="button button-secondary sendtomp-generate-key" data-key-type="' . esc_attr( $key_type ) . '">';
		echo esc_html( $has_key ? __( 'Regenerate Key', 'sendtomp' ) : __( 'Generate Key', 'sendtomp' ) );
		echo '</button>';
		echo '<div class="sendtomp-key-result" style="display:none; margin-top: 10px;">';
		echo '<p><strong>' . esc_html__( 'Copy this key now — it will not be shown again:', 'sendtomp' ) . '</strong></p>';
		echo '<input type="text" class="regular-text sendtomp-key-display" readonly style="font-family: monospace;" />';
		echo '</div>';
		echo '</div>';

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render inline webhook API documentation.
	 *
	 * @return void
	 */
	public function render_webhook_docs(): void {
		$url = rest_url( 'sendtomp/v1/submit' );

		echo '<details>';
		echo '<summary style="cursor: pointer; font-weight: 600;">' . esc_html__( 'Example request (click to expand)', 'sendtomp' ) . '</summary>';
		echo '<pre style="background: #f0f0f0; padding: 12px; margin-top: 8px; overflow-x: auto;">';
		echo esc_html( 'curl -X POST ' . $url . " \\\n" );
		echo esc_html( "  -H \"Content-Type: application/json\" \\\n" );
		echo esc_html( "  -H \"Authorization: Bearer YOUR_API_KEY\" \\\n" );
		echo esc_html( "  -d '{\n" );
		echo esc_html( "    \"constituent_name\": \"Jane Smith\",\n" );
		echo esc_html( "    \"constituent_email\": \"jane@example.com\",\n" );
		echo esc_html( "    \"constituent_postcode\": \"SW1A 1AA\",\n" );
		echo esc_html( "    \"message_body\": \"Dear MP, ...\",\n" );
		echo esc_html( "    \"target_house\": \"commons\"\n" );
		echo esc_html( "  }'" );
		echo '</pre>';

		echo '<p><strong>' . esc_html__( 'Required fields:', 'sendtomp' ) . '</strong> ';
		echo esc_html( 'constituent_name, constituent_email, constituent_postcode, message_body' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Optional fields:', 'sendtomp' ) . '</strong> ';
		echo esc_html( 'constituent_address, message_subject, target_house (default: commons), skip_confirmation (privileged key only)' ) . '</p>';
		echo '</details>';
	}

	/**
	 * AJAX handler — generate a webhook API key.
	 *
	 * @return void
	 */
	public function handle_generate_webhook_key(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'sendtomp' ) ] );
		}

		$key_type = isset( $_POST['key_type'] ) ? sanitize_text_field( wp_unslash( $_POST['key_type'] ) ) : '';

		if ( ! in_array( $key_type, [ 'standard', 'privileged' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid key type.', 'sendtomp' ) ] );
		}

		$raw_key = wp_generate_password( 40, false, false );
		$hash    = wp_hash_password( $raw_key );

		$option_key = 'standard' === $key_type ? 'webhook_api_key_hash' : 'webhook_api_key_privileged_hash';

		$settings = get_option( 'sendtomp_settings', [] );
		$settings[ $option_key ] = $hash;
		update_option( 'sendtomp_settings', $settings );

		// Flush settings cache so the UI reflects the change.
		sendtomp()->flush_settings_cache();

		wp_send_json_success( [
			'key'     => $raw_key,
			'message' => __( 'API key generated. Copy it now — it will not be shown again.', 'sendtomp' ),
		] );
	}

	/**
	 * AJAX handler — search for members (Peers/MPs) via the middleware API.
	 *
	 * @return void
	 */
	public function handle_search_members(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$house = isset( $_POST['house'] ) ? sanitize_text_field( wp_unslash( $_POST['house'] ) ) : 'lords';
		$party = isset( $_POST['party'] ) ? sanitize_text_field( wp_unslash( $_POST['party'] ) ) : '';

		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( [ 'results' => [] ] );
		}

		$api_client  = new SendToMP_API_Client();
		$search_house = ( 'all' === $house ) ? '' : $house;
		$results     = $api_client->search_members( $query, $search_house, $party );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( [ 'message' => $results->get_error_message() ] );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * AJAX handler — activate a license key.
	 *
	 * @return void
	 */
	public function handle_activate_license(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( empty( $key ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a license key.', 'sendtomp' ) ] );
		}

		$result = SendToMP_License::activate( $key );

		if ( $result['valid'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler — deactivate the current license.
	 *
	 * @return void
	 */
	public function handle_deactivate_license(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		$result = SendToMP_License::deactivate();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX handler — save a local address override.
	 *
	 * @return void
	 */
	public function handle_save_override(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		if ( ! sendtomp()->can( 'local_overrides' ) ) {
			wp_send_json_error( [ 'message' => __( 'Local overrides are not available on your current plan.', 'sendtomp' ) ] );
		}

		$member_id   = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$member_name = isset( $_POST['member_name'] ) ? sanitize_text_field( wp_unslash( $_POST['member_name'] ) ) : '';
		$house       = isset( $_POST['house'] ) ? sanitize_text_field( wp_unslash( $_POST['house'] ) ) : 'commons';
		$email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$notes       = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( $member_id < 1 ) {
			wp_send_json_error( [ 'message' => __( 'Please select a member.', 'sendtomp' ) ] );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'A valid email address is required.', 'sendtomp' ) ] );
		}

		if ( ! in_array( $house, [ 'commons', 'lords' ], true ) ) {
			$house = 'commons';
		}

		$saved = SendToMP_Overrides::save( $member_id, $member_name, $house, $email, $notes );

		if ( ! $saved ) {
			wp_send_json_error( [ 'message' => __( 'Failed to save override.', 'sendtomp' ) ] );
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s: member name */
				__( 'Override saved for %s.', 'sendtomp' ),
				$member_name
			),
		] );
	}

	/**
	 * AJAX handler — delete a local address override.
	 *
	 * @return void
	 */
	public function handle_delete_override(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		if ( ! sendtomp()->can( 'local_overrides' ) ) {
			wp_send_json_error( [ 'message' => __( 'Local overrides are not available on your current plan.', 'sendtomp' ) ] );
		}

		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

		if ( $member_id < 1 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid member ID.', 'sendtomp' ) ] );
		}

		$deleted = SendToMP_Overrides::delete( $member_id );

		if ( ! $deleted ) {
			wp_send_json_error( [ 'message' => __( 'Override not found.', 'sendtomp' ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Override deleted.', 'sendtomp' ) ] );
	}

	/**
	 * AJAX handler — export submission logs as CSV (Pro tier only).
	 *
	 * @return void
	 */
	public function handle_export_logs_csv(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied.', 'sendtomp' ) );
		}

		if ( ! sendtomp()->can( 'csv_export' ) ) {
			wp_die( __( 'CSV export requires a Pro plan.', 'sendtomp' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=sendtomp-logs-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, [
			'Date', 'Name', 'Email', 'Postcode', 'Subject', 'MP', 'House',
			'Status', 'Override', 'Contact Quality', 'Adapter', 'Error',
		] );

		// Stream all pages to avoid memory issues on large datasets.
		$page = 1;
		do {
			$logs = SendToMP_Logger::get_logs( [
				'per_page' => 1000,
				'page'     => $page,
			] );

			foreach ( $logs['items'] as $log ) {
				fputcsv( $output, array_map( [ $this, 'csv_safe' ], [
					$log->created_at,
					$log->constituent_name,
					$log->constituent_email,
					$log->constituent_postcode,
					$log->message_subject,
					$log->target_member_name,
					$log->house,
					$log->delivery_status,
					$log->override_applied ?? '',
					$log->contact_quality ?? '',
					$log->source_adapter,
					$log->error_message ?? '',
				] ) );
			}

			$page++;
		} while ( count( $logs['items'] ) >= 1000 );

		fclose( $output );
		exit;
	}

	/**
	 * AJAX handler — frontend postcode lookup (returns MP info).
	 *
	 * Available to both logged-in and logged-out users for frontend forms.
	 *
	 * @return void
	 */
	public function handle_lookup_postcode(): void {
		check_ajax_referer( 'sendtomp_postcode_lookup', 'nonce' );

		$postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';

		if ( empty( $postcode ) || strlen( $postcode ) < 5 ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid UK postcode.', 'sendtomp' ) ] );
		}

		// Rate limit postcode lookups by IP (30 per minute).
		$ip            = SendToMP_Pipeline::get_client_ip();
		$throttle_key  = 'sendtomp_lookup_' . md5( $ip );
		$lookup_count  = (int) get_transient( $throttle_key );

		if ( $lookup_count >= 30 ) {
			wp_send_json_error( [ 'message' => __( 'Too many lookups. Please try again shortly.', 'sendtomp' ) ] );
		}

		set_transient( $throttle_key, $lookup_count + 1, MINUTE_IN_SECONDS );

		// Cache lookups by postcode (1 hour TTL).
		$cache_key = 'sendtomp_mp_' . md5( strtoupper( str_replace( ' ', '', $postcode ) ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$api_client  = new SendToMP_API_Client();
		$api_result  = $api_client->resolve_member( $postcode, 'commons' );

		if ( is_wp_error( $api_result ) ) {
			wp_send_json_error( [ 'message' => $api_result->get_error_message() ] );
		}

		$member = isset( $api_result['member'] ) ? $api_result['member'] : [];

		$result = [
			'name'         => isset( $member['name'] ) ? $member['name'] : '',
			'party'        => isset( $member['party'] ) ? $member['party'] : '',
			'constituency' => isset( $member['constituency'] ) ? $member['constituency'] : '',
		];

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		wp_send_json_success( $result );
	}

	/**
	 * Sanitize a CSV cell value to prevent formula injection.
	 *
	 * @param string $value Raw cell value.
	 * @return string Sanitized value.
	 */
	private function csv_safe( string $value ): string {
		if ( isset( $value[0] ) && in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * AJAX handler — erase all data for a given email (GDPR).
	 *
	 * @return void
	 */
	public function handle_erase_data(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'A valid email address is required.', 'sendtomp' ) ] );
		}

		$logs_deleted   = SendToMP_Logger::purge_by_email( $email );
		$pending_deleted = SendToMP_Confirmation::purge_by_email( $email );

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: logs deleted, 2: pending deleted */
				__( 'Erased %1$d log entries and %2$d pending submissions for that email address.', 'sendtomp' ),
				$logs_deleted,
				$pending_deleted
			),
		] );
	}

	/**
	 * AJAX handler — submit Brevo partner enquiry to Bluetorch.
	 *
	 * @return void
	 */
	public function handle_brevo_enquiry(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sendtomp' ) ] );
		}

		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$website      = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$consent      = ! empty( $_POST['consent'] );

		if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'First name, last name, and email are required.', 'sendtomp' ) ] );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'A valid email address is required.', 'sendtomp' ) ] );
		}

		if ( ! $consent ) {
			wp_send_json_error( [ 'message' => __( 'You must agree to the terms to proceed.', 'sendtomp' ) ] );
		}

		$tier             = SendToMP_License::get_tier();
		$requires_payment = SendToMP_License::TIER_PRO !== $tier;

		// Submit enquiry to Bluetorch API.
		$response = wp_remote_post( untrailingslashit( SENDTOMP_API_BASE ) . '/api/brevo/enquiry', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'first_name'       => $first_name,
				'last_name'        => $last_name,
				'company_name'     => $company_name,
				'website'          => $website,
				'email'            => $email,
				'site_url'         => home_url(),
				'tier'             => $tier,
				'requires_payment' => $requires_payment,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not submit your enquiry. Please try again later.', 'sendtomp' ) ] );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$error_message = __( 'Enquiry submission failed. Please try again later.', 'sendtomp' );
			$body          = wp_remote_retrieve_body( $response );
			$decoded_body  = json_decode( $body, true );

			if ( is_array( $decoded_body ) && ! empty( $decoded_body['message'] ) && is_string( $decoded_body['message'] ) ) {
				$error_message = sanitize_text_field( $decoded_body['message'] );
			}

			wp_send_json_error( [ 'message' => $error_message ] );
		}

		wp_send_json_success( [
			'message'          => $requires_payment
				? __( 'Thanks! We\'ll be in touch to set up your Brevo account. The one-off setup fee of £150 will be invoiced separately.', 'sendtomp' )
				: __( 'Thanks! As a Pro subscriber, this service is included at no extra cost. We\'ll be in touch to set up your Brevo account.', 'sendtomp' ),
			'requires_payment' => $requires_payment,
		] );
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$key   = $args['key'];
		$type  = isset( $args['type'] ) ? $args['type'] : 'text';
		$value = sendtomp()->get_setting( $key );
		$name  = self::OPTION_NAME . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( $name ),
			esc_attr( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$key   = $args['key'];
		$value = sendtomp()->get_setting( $key );
		$min   = isset( $args['min'] ) ? $args['min'] : 0;
		$max   = isset( $args['max'] ) ? $args['max'] : 99999;
		$name  = self::OPTION_NAME . '[' . esc_attr( $key ) . ']';

		printf(
			'<input type="number" id="%s" name="%s" value="%s" min="%d" max="%d" class="small-text" />',
			esc_attr( $key ),
			esc_attr( $name ),
			esc_attr( $value ),
			$min,
			$max
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a select dropdown field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_select_field( array $args ): void {
		$key     = $args['key'];
		$options = $args['options'];
		$value   = sendtomp()->get_setting( $key );
		$name    = self::OPTION_NAME . '[' . esc_attr( $key ) . ']';

		printf( '<select id="%s" name="%s">', esc_attr( $key ), esc_attr( $name ) );

		foreach ( $options as $option_value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_textarea_field( array $args ): void {
		$key   = $args['key'];
		$value = sendtomp()->get_setting( $key );
		$name  = self::OPTION_NAME . '[' . esc_attr( $key ) . ']';

		printf(
			'<textarea id="%s" name="%s" rows="6" cols="50" class="large-text">%s</textarea>',
			esc_attr( $key ),
			esc_attr( $name ),
			esc_textarea( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$key     = $args['key'];
		$value   = sendtomp()->get_setting( $key );
		$checked = ! empty( $value ) ? 'checked' : '';
		$label   = isset( $args['label'] ) ? $args['label'] : '';

		echo '<label>';
		echo '<input type="checkbox" name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '" value="1" ' . $checked . ' />';
		echo ' ' . esc_html( $label );
		echo '</label>';

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render the test email button.
	 *
	 * @return void
	 */
	public function render_test_email_button(): void {
		echo '<button type="button" id="sendtomp-test-email" class="button button-secondary">';
		echo esc_html__( 'Send Test Email', 'sendtomp' );
		echo '</button>';
		echo '<span id="sendtomp-test-email-result" style="margin-left: 10px;"></span>';
		echo '<p class="description">' . esc_html__( 'Send a test email to the admin email address to verify your mail configuration.', 'sendtomp' ) . '</p>';
	}

	/**
	 * Render the license status display and activate/deactivate buttons.
	 *
	 * @return void
	 */
	public function render_license_status(): void {
		$status = SendToMP_License::get_cached_status();
		$tier   = SendToMP_License::get_tier();
		$key    = sendtomp()->get_setting( 'license_key' );

		if ( ! empty( $key ) && $status && ! empty( $status['valid'] ) ) {
			$tier_label = ucfirst( $tier );
			$expires    = ! empty( $status['expires_at'] ) ? $status['expires_at'] : __( 'Never', 'sendtomp' );
			$checked    = ! empty( $status['checked_at'] ) ? $status['checked_at'] : '—';

			echo '<div style="background: #f0faf0; border: 1px solid #00a32a; border-radius: 4px; padding: 12px 16px; margin-bottom: 12px;">';
			echo '<strong style="color: #00a32a;">&#10003; ' . esc_html( sprintf( __( 'Active — %s Plan', 'sendtomp' ), $tier_label ) ) . '</strong>';
			echo '<br><small>' . esc_html__( 'Expires:', 'sendtomp' ) . ' ' . esc_html( $expires ) . '</small>';
			echo '<br><small>' . esc_html__( 'Last checked:', 'sendtomp' ) . ' ' . esc_html( $checked ) . '</small>';
			echo '</div>';

			echo '<button type="button" id="sendtomp-deactivate-license" class="button button-secondary">';
			echo esc_html__( 'Deactivate License', 'sendtomp' );
			echo '</button>';
			echo '<span id="sendtomp-license-result" style="margin-left: 10px;"></span>';
		} else {
			$remaining     = SendToMP_License::get_remaining();
			$monthly_limit = SendToMP_License::FREE_MONTHLY_LIMIT;

			echo '<div style="background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; margin-bottom: 12px;">';
			echo '<strong>' . esc_html__( 'Free Plan', 'sendtomp' ) . '</strong>';
			echo '<br><small>' . esc_html( sprintf(
				/* translators: 1: remaining messages, 2: total monthly limit */
				__( '%1$d of %2$d messages remaining this month', 'sendtomp' ),
				$remaining,
				$monthly_limit
			) ) . '</small>';
			echo '</div>';

			echo '<button type="button" id="sendtomp-activate-license" class="button button-primary">';
			echo esc_html__( 'Activate License', 'sendtomp' );
			echo '</button>';
			echo '<span id="sendtomp-license-result" style="margin-left: 10px;"></span>';
			echo '<p class="description">' . esc_html__( 'Enter your license key above and click Activate to unlock Plus or Pro features.', 'sendtomp' ) . '</p>';
		}
	}

	/**
	 * Render the purge logs button.
	 *
	 * @return void
	 */
	public function render_purge_button(): void {
		echo '<button type="button" id="sendtomp-purge-logs" class="button button-secondary">';
		echo esc_html__( 'Purge Old Logs Now', 'sendtomp' );
		echo '</button>';
		echo '<span id="sendtomp-purge-result" style="margin-left: 10px;"></span>';
		echo '<p class="description">' . esc_html__( 'Immediately delete logs older than the retention period above.', 'sendtomp' ) . '</p>';
	}

	/**
	 * Render the GDPR data erasure field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_erase_data_field( array $args = [] ): void {
		echo '<div style="display: flex; gap: 8px; align-items: center;">';
		echo '<input type="email" id="sendtomp-erase-email" class="regular-text" placeholder="' . esc_attr__( 'constituent@example.com', 'sendtomp' ) . '" />';
		echo '<button type="button" id="sendtomp-erase-data" class="button button-secondary">';
		echo esc_html__( 'Erase Data', 'sendtomp' );
		echo '</button>';
		echo '</div>';
		echo '<span id="sendtomp-erase-result" style="display: block; margin-top: 6px;"></span>';
		echo '<p class="description">' . esc_html__( 'Delete all submission logs and pending submissions for a given email address. This action cannot be undone.', 'sendtomp' ) . '</p>';
	}

	/**
	 * Render the privacy notice template for site owners.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_privacy_notice_template( array $args = [] ): void {
		// Intentionally English-only: this is a legal template for UK parliamentary
		// correspondence. Site owners should translate/adapt for their privacy policy.
		$template = <<<'TEXT'
This website uses the SendToMP plugin to allow you to send messages to your Member of Parliament. When you submit a message:

- Your name, email address, postcode, and message are processed on this website.
- Your postcode is used to identify your MP via the UK Parliament API. No other personal data is sent to external services.
- You will receive a confirmation email before your message is sent. Your data is not shared with your MP until you explicitly confirm.
- If you do not confirm within 24 hours, your data is automatically deleted.
- Submission logs are retained for the period specified in our settings (default: 90 days) and then automatically deleted.
- You may request erasure of your data at any time by contacting us.
TEXT;

		echo '<details>';
		echo '<summary style="cursor: pointer; font-weight: 600;">' . esc_html__( 'Suggested privacy policy paragraph (click to expand)', 'sendtomp' ) . '</summary>';
		echo '<textarea readonly class="large-text" rows="10" style="margin-top: 8px; background: #f6f7f7; font-size: 0.9em;">' . esc_textarea( $template ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Copy this text and add it to your site\'s privacy policy page.', 'sendtomp' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Render the CSV export button (Pro tier only).
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_export_button( array $args = [] ): void {
		$export_url = add_query_arg( [
			'action' => 'sendtomp_export_logs_csv',
			'nonce'  => wp_create_nonce( 'sendtomp_admin' ),
		], admin_url( 'admin-ajax.php' ) );

		echo '<a href="' . esc_url( $export_url ) . '" class="button button-secondary">';
		echo esc_html__( 'Export to CSV', 'sendtomp' );
		echo '</a>';
		echo '<p class="description">' . esc_html__( 'Download all submission logs as a CSV file.', 'sendtomp' ) . '</p>';
	}

	/**
	 * Sanitize all settings inputs.
	 *
	 * @param array $input Raw input array.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$existing  = get_option( self::OPTION_NAME, [] );
		$sanitized = $existing;

		// General.
		if ( isset( $input['default_house'] ) ) {
			$sanitized['default_house'] = in_array( $input['default_house'], [ 'commons', 'lords' ], true )
				? $input['default_house']
				: 'commons';
		}

		if ( isset( $input['campaign_type'] ) ) {
			$allowed_types = [ 'general', 'advocacy', 'petition', 'consultation' ];
			$sanitized['campaign_type'] = in_array( $input['campaign_type'], $allowed_types, true )
				? $input['campaign_type']
				: 'general';
		}

		// Email.
		if ( isset( $input['from_email'] ) ) {
			$sanitized['from_email'] = sanitize_email( $input['from_email'] );
		}

		if ( isset( $input['from_name'] ) ) {
			$sanitized['from_name'] = sanitize_text_field( $input['from_name'] );
		}

		if ( isset( $input['reply_to'] ) ) {
			$sanitized['reply_to'] = in_array( $input['reply_to'], [ 'constituent', 'fixed' ], true )
				? $input['reply_to']
				: 'constituent';
		}

		if ( isset( $input['reply_to_email'] ) ) {
			$sanitized['reply_to_email'] = sanitize_email( $input['reply_to_email'] );
		}

		if ( isset( $input['bcc_emails'] ) ) {
			$sanitized['bcc_emails'] = sanitize_text_field( $input['bcc_emails'] );
		}

		if ( isset( $input['subject_template'] ) ) {
			$sanitized['subject_template'] = sanitize_text_field( $input['subject_template'] );
		}

		if ( isset( $input['email_template'] ) ) {
			$sanitized['email_template'] = wp_kses_post( $input['email_template'] );
		}

		// Confirmation.
		if ( isset( $input['confirmation_subject'] ) ) {
			$sanitized['confirmation_subject'] = sanitize_text_field( $input['confirmation_subject'] );
		}

		if ( isset( $input['confirmation_expiry'] ) ) {
			$sanitized['confirmation_expiry'] = absint( $input['confirmation_expiry'] );
			if ( $sanitized['confirmation_expiry'] < 1 ) {
				$sanitized['confirmation_expiry'] = 24;
			}
		}

		if ( isset( $input['consent_text'] ) ) {
			$sanitized['consent_text'] = wp_kses_post( $input['consent_text'] );
		}

		if ( isset( $input['thankyou_message'] ) ) {
			$sanitized['thankyou_message'] = wp_kses_post( $input['thankyou_message'] );
		}

		// Rate limits.
		if ( isset( $input['rate_limit_email'] ) ) {
			$sanitized['rate_limit_email'] = absint( $input['rate_limit_email'] );
		}

		if ( isset( $input['rate_limit_ip'] ) ) {
			$sanitized['rate_limit_ip'] = absint( $input['rate_limit_ip'] );
		}

		if ( isset( $input['rate_limit_postcode'] ) ) {
			$sanitized['rate_limit_postcode'] = absint( $input['rate_limit_postcode'] );
		}

		if ( isset( $input['rate_limit_global'] ) ) {
			$sanitized['rate_limit_global'] = absint( $input['rate_limit_global'] );
		}

		// Branding — only update if a branding-related field was in the submitted form.
		// This prevents checkbox values resetting when saving from tabs that
		// don't include these inputs (e.g., the Webhook API tab).
		if ( isset( $input['show_branding'] ) || isset( $input['campaign_type'] ) ) {
			$sanitized['show_branding']  = ! empty( $input['show_branding'] );
			$sanitized['directory_optin'] = ! empty( $input['directory_optin'] );
		}

		// License.
		if ( isset( $input['license_key'] ) ) {
			$sanitized['license_key'] = sanitize_text_field( $input['license_key'] );
		}

		// Log.
		if ( isset( $input['log_retention'] ) ) {
			$sanitized['log_retention'] = absint( $input['log_retention'] );
			if ( $sanitized['log_retention'] < 1 ) {
				$sanitized['log_retention'] = 90;
			}
		}

		return $sanitized;
	}

	/**
	 * AJAX handler — send a test email to the admin.
	 *
	 * @return void
	 */
	public function handle_test_email(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'sendtomp' ) ] );
		}

		$to     = get_option( 'admin_email' );
		$mailer = new SendToMP_Mailer();
		$result = $mailer->send_test_email( $to );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => sprintf( __( 'Test email sent to %s.', 'sendtomp' ), $to ) ] );
	}

	/**
	 * AJAX handler — purge old logs.
	 *
	 * @return void
	 */
	public function handle_purge_logs(): void {
		check_ajax_referer( 'sendtomp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'sendtomp' ) ] );
		}

		$days    = (int) sendtomp()->get_setting( 'log_retention' );
		$deleted = SendToMP_Logger::purge_old( $days );

		wp_send_json_success( [
			'message' => sprintf( __( 'Purged %d log entries older than %d days.', 'sendtomp' ), $deleted, $days ),
		] );
	}

	/**
	 * Get the current active settings tab.
	 *
	 * @return string The current tab slug.
	 */
	public function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$valid_tabs = [ 'general', 'email', 'delivery', 'confirmation', 'rate-limits', 'overrides', 'webhook', 'license', 'log' ];

		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'general';
		}

		return $tab;
	}
}
