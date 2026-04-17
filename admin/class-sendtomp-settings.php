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
				echo '<p>' . esc_html__( 'Configure the core settings for SendToMP.', 'sendtomp' ) . '</p>';
			},
			'sendtomp'
		);

		add_settings_field(
			'api_url',
			__( 'API URL', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'api_url',
				'type'        => 'url',
				'description' => __( 'The URL for the SendToMP middleware API.', 'sendtomp' ),
			]
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'sendtomp' ),
			[ $this, 'render_text_field' ],
			'sendtomp',
			$section,
			[
				'key'         => 'api_key',
				'type'        => 'password',
				'description' => __( 'Your API key for authenticating with the middleware.', 'sendtomp' ),
			]
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
				'description' => __( 'The default parliamentary house to target.', 'sendtomp' ),
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
					'general'     => __( 'General', 'sendtomp' ),
					'advocacy'    => __( 'Advocacy', 'sendtomp' ),
					'petition'    => __( 'Petition', 'sendtomp' ),
					'consultation' => __( 'Consultation', 'sendtomp' ),
				],
				'description' => __( 'The type of campaign this site is running.', 'sendtomp' ),
			]
		);
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
				echo '<p>' . esc_html__( 'Configure how emails are sent to MPs.', 'sendtomp' ) . '</p>';
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
				'description' => __( 'The email address that MP emails are sent from.', 'sendtomp' ),
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
				'description' => __( 'The sender name for MP emails.', 'sendtomp' ),
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
					'constituent' => __( 'Constituent email address', 'sendtomp' ),
					'fixed'       => __( 'Fixed address (From Email)', 'sendtomp' ),
				],
				'description' => __( 'Who the MP can reply to.', 'sendtomp' ),
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
				'description' => __( 'Comma-separated list of email addresses to BCC on every message.', 'sendtomp' ),
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
				'description' => __( 'Email subject template. Use placeholders: {constituent_name}, {mp_name}, {mp_constituency}.', 'sendtomp' ),
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
				'description' => __( 'Custom email body template. Leave blank for default. Use placeholders: {constituent_name}, {message_body}, {mp_name}, etc.', 'sendtomp' ),
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
				echo '<p>' . esc_html__( 'Configure the email confirmation process for constituents.', 'sendtomp' ) . '</p>';
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
				'description' => __( 'Subject line for the confirmation email sent to constituents.', 'sendtomp' ),
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
				'description' => __( 'How many hours before a confirmation link expires.', 'sendtomp' ),
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
				'description' => __( 'Consent / privacy text shown to the constituent before submission.', 'sendtomp' ),
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
				'description' => __( 'Message shown to the constituent after successful confirmation.', 'sendtomp' ),
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
				echo '<p>' . esc_html__( 'Configure rate limits to prevent abuse. Values are per 24-hour period.', 'sendtomp' ) . '</p>';
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
				'description' => __( 'Maximum submissions per email address per day.', 'sendtomp' ),
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
				'description' => __( 'Maximum submissions per IP address per day.', 'sendtomp' ),
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
				'description' => __( 'Maximum submissions per postcode per day.', 'sendtomp' ),
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
				'description' => __( 'Maximum total submissions across all users per day.', 'sendtomp' ),
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
		echo '<p class="description">' . esc_html__( 'License activation will be available in a future release.', 'sendtomp' ) . '</p>';
		echo '<button type="button" class="button button-secondary" disabled>';
		echo esc_html__( 'Activate License', 'sendtomp' );
		echo '</button> ';
		echo '<button type="button" class="button button-secondary" disabled>';
		echo esc_html__( 'Deactivate License', 'sendtomp' );
		echo '</button>';
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
	 * Sanitize all settings inputs.
	 *
	 * @param array $input Raw input array.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$existing  = get_option( self::OPTION_NAME, [] );
		$sanitized = $existing;

		// General.
		if ( isset( $input['api_url'] ) ) {
			$sanitized['api_url'] = esc_url_raw( $input['api_url'] );
		}

		if ( isset( $input['api_key'] ) ) {
			$sanitized['api_key'] = sanitize_text_field( $input['api_key'] );
		}

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
	 * Get the current active settings tab.
	 *
	 * @return string The current tab slug.
	 */
	public function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$valid_tabs = [ 'general', 'email', 'confirmation', 'rate-limits', 'license', 'log' ];

		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'general';
		}

		return $tab;
	}
}
