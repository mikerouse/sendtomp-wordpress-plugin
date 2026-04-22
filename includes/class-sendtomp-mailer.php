<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Mailer {

	/**
	 * Instantiate (and cache) the provider selected in settings.
	 *
	 * Falls back to wp_mail passthrough when the configured provider
	 * isn't ready (e.g. Brevo selected but API key missing) so the
	 * plugin never goes silent on a mis-configuration.
	 *
	 * @return SendToMP_Provider_Interface
	 */
	public function get_provider(): SendToMP_Provider_Interface {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$selected = (string) sendtomp()->get_setting( 'smtp_provider' );

		switch ( $selected ) {
			case 'brevo':
				$candidate = new SendToMP_Provider_Brevo();
				break;
			case 'smtp_custom':
				$candidate = new SendToMP_Provider_SMTP();
				break;
			case 'smtp_plugin':
				$candidate = new SendToMP_Provider_WP_Mail( 'smtp_plugin' );
				break;
			case 'wp_mail':
			default:
				$candidate = new SendToMP_Provider_WP_Mail( 'wp_mail' );
				break;
		}

		if ( ! $candidate->is_configured() ) {
			$candidate = new SendToMP_Provider_WP_Mail( 'wp_mail' );
		}

		$cached = $candidate;
		return $cached;
	}

	/**
	 * Boot the selected provider so its hooks (e.g. phpmailer_init for
	 * Custom SMTP) register before any send happens. Called from
	 * SendToMP::init() at plugins_loaded:20.
	 *
	 * @return void
	 */
	public function boot_selected_provider(): void {
		$this->get_provider()->boot();
	}

	/**
	 * True when SendToMP has a usable email-delivery configuration.
	 *
	 * Either:
	 *   - a third-party SMTP plugin (WP Mail SMTP etc.) is active and
	 *     handling wp_mail(), OR
	 *   - SendToMP's own provider setting resolves to something other
	 *     than the bare wp_mail() passthrough (Brevo API / Custom SMTP).
	 *
	 * Callers use this to decide whether to display the "install an
	 * SMTP plugin" warning.
	 *
	 * @return bool
	 */
	public function is_delivery_configured(): bool {
		if ( $this->detect_smtp_plugin() ) {
			return true;
		}
		return 'wp_mail' !== $this->get_provider()->get_id();
	}

	/**
	 * Human-readable label for the active email-delivery setup, e.g.
	 * "Brevo", "Custom SMTP", or the detected plugin's name. Empty
	 * string when delivery isn't configured.
	 *
	 * @return string
	 */
	public function get_delivery_label(): string {
		$smtp_plugin = $this->detect_smtp_plugin();
		if ( $smtp_plugin ) {
			return (string) $smtp_plugin;
		}

		$id = $this->get_provider()->get_id();
		switch ( $id ) {
			case 'brevo':
				return __( 'Brevo', 'sendtomp' );
			case 'smtp_custom':
				$host = (string) sendtomp()->get_setting( 'smtp_host' );
				return '' === $host
					? __( 'Custom SMTP', 'sendtomp' )
					: sprintf(
						/* translators: %s: SMTP server hostname. */
						__( 'Custom SMTP (%s)', 'sendtomp' ),
						$host
					);
			default:
				return '';
		}
	}

	/**
	 * Send a canonical message via the configured provider.
	 *
	 * @param array $message See SendToMP_Provider_Interface for shape.
	 * @return bool|WP_Error
	 */
	public function dispatch( array $message ) {
		return $this->get_provider()->send( $message );
	}

	public function send_to_mp( SendToMP_Submission $submission ) {
		$settings = sendtomp()->get_settings();

		// Strip newlines to prevent email header injection.
		$from_name  = str_replace( [ "\r", "\n" ], '', $settings['from_name'] );
		$from_email = str_replace( [ "\r", "\n" ], '', $settings['from_email'] );

		if ( 'constituent' === $settings['reply_to'] ) {
			$reply_email = $submission->constituent_email;
		} else {
			// 'fixed' mode — use the configured reply_to_email, falling back to from_email.
			$reply_email = ! empty( $settings['reply_to_email'] ) ? $settings['reply_to_email'] : $settings['from_email'];
		}

		$bcc_addrs = [];
		if ( ! empty( $settings['bcc_emails'] ) && sendtomp()->can( 'bcc' ) ) {
			$bcc_list = array_map( 'trim', explode( ',', $settings['bcc_emails'] ) );
			foreach ( $bcc_list as $bcc ) {
				if ( is_email( $bcc ) ) {
					$bcc_addrs[] = [ 'email' => $bcc ];
				}
			}
		}

		$subject = $this->replace_placeholders( $settings['subject_template'], $submission );

		// For Lords with shared inbox, prepend FAO to subject.
		if ( $submission->is_shared_inbox() ) {
			$subject = 'FAO: ' . $submission->resolved_member['name'] . ' - ' . $subject;
		}

		$template = ! empty( $settings['email_template'] )
			? $settings['email_template']
			: $this->get_default_mp_email_template();

		$body = $this->replace_placeholders( $template, $submission );
		$body .= "\n\n" . $this->get_mp_email_footer( $submission );

		if ( empty( $submission->resolved_member['delivery_email'] ) ) {
			return new WP_Error( 'no_delivery_email', __( 'No delivery email address found for this MP.', 'sendtomp' ) );
		}

		// Decide text vs HTML rendering based on the body content.
		$has_markdown = $this->body_has_markdown_formatting( $body );
		$has_html     = $this->body_contains_html( $body );

		$text_body = $body;
		$html_body = null;

		if ( $has_markdown || $has_html ) {
			$html_body = $has_markdown ? $this->markdown_to_html( $body ) : wpautop( $body );
			// Keep a plain-text fallback for providers that accept both
			// (Brevo does; wp_mail ignores the text variant when HTML is
			// set via Content-Type). Strip markdown/html for readability.
			$text_body = wp_strip_all_tags( $has_markdown ? $body : $html_body, true );
		}

		$message = [
			'to'       => [ 'email' => $submission->resolved_member['delivery_email'] ],
			'from'     => [ 'email' => $from_email, 'name' => $from_name ],
			'reply_to' => [ 'email' => $reply_email ],
			'bcc'      => $bcc_addrs,
			'subject'  => $subject,
			'text'     => $text_body,
			'html'     => $html_body,
		];

		$result = $this->dispatch( $message );

		if ( true !== $result ) {
			return is_wp_error( $result )
				? $result
				: new WP_Error( 'send_failed', __( 'Your message could not be sent. Please try again later.', 'sendtomp' ) );
		}

		return true;
	}

	public function send_confirmation( SendToMP_Submission $submission, string $token, string $mp_name, string $mp_constituency ) {
		$to = $submission->constituent_email;

		$subject_template = sendtomp()->get_setting( 'confirmation_subject' );
		if ( empty( $subject_template ) ) {
			$subject_template = 'Please confirm your message to {mp_name}';
		}
		$subject = str_replace( '{mp_name}', $mp_name, $subject_template );
		$subject = apply_filters( 'sendtomp_confirmation_subject', $subject, $submission, $mp_name );

		$confirmation_url = $this->get_confirmation_url( $token );
		$expiry_hours     = (int) sendtomp()->get_setting( 'confirmation_expiry' );

		// The feed resolves GF merge tags at submission time, but the
		// message body may still contain SendToMP tokens ({mp_name},
		// {mp_constituency}, etc.) that only fill in once the postcode
		// is resolved. Resolve them now so the preview the constituent
		// sees matches what will actually reach the MP.
		$preview_body_resolved = $this->replace_placeholders( (string) $submission->message_body, $submission );
		$preview_body_resolved = $this->strip_unresolved_merge_tags( $preview_body_resolved );

		$settings   = sendtomp()->get_settings();
		$from_name  = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_name'] ?? '' ) );
		$from_email = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_email'] ?? '' ) );

		$context = [
			'mp_name'          => $mp_name,
			'mp_constituency'  => $mp_constituency,
			'location_label'   => '' !== $mp_constituency ? " ({$mp_constituency})" : '',
			'site_name'        => get_bloginfo( 'name' ),
			'confirmation_url' => $confirmation_url,
			'expiry_hours'     => $expiry_hours,
			'message_subject'  => (string) $submission->message_subject,
			'message_body'     => $preview_body_resolved,
			'logo_url'         => (string) ( $settings['confirmation_logo_url'] ?? '' ),
			'intro_message'    => (string) ( $settings['confirmation_intro_message'] ?? '' ),
			'show_branding'    => SendToMP_License::should_show_branding(),
		];

		$html = $this->render_confirmation_html( $context );
		$text = $this->render_confirmation_text( $context );

		$result = $this->dispatch( [
			'to'      => [ 'email' => $to ],
			'from'    => [ 'email' => $from_email, 'name' => $from_name ],
			'subject' => $subject,
			'text'    => $text,
			'html'    => $html,
		] );

		if ( true !== $result ) {
			return is_wp_error( $result )
				? $result
				: new WP_Error( 'confirmation_failed', __( 'We could not send a confirmation email. Please check your email address and try again.', 'sendtomp' ) );
		}

		return true;
	}

	/**
	 * Remove any GF-style merge tags (e.g. "{Post Code:4}") that survived
	 * feed processing because they referenced fields that no longer exist.
	 * Leaving them in the preview is confusing and embarrassing.
	 *
	 * @param string $body Resolved message body.
	 * @return string
	 */
	private function strip_unresolved_merge_tags( string $body ): string {
		// Match "{Label:id}" or "{Label:id:modifier}" — the GF merge-tag shape.
		return (string) preg_replace( '/\{[^{}\n]+:\d+(?::[^{}\n]*)?\}/', '', $body );
	}

	/**
	 * Build the plain-text fallback version of the confirmation email.
	 *
	 * Kept as multipart/alternative so providers that send both parts
	 * (Brevo) give subscribers the accessible option, and clients that
	 * prefer plain text over HTML get a clean read.
	 *
	 * @param array $c Context built in send_confirmation().
	 * @return string
	 */
	private function render_confirmation_text( array $c ): string {
		$body  = sprintf(
			/* translators: 1: MP name + constituency, 2: site name. */
			__( "You recently submitted a message to %1\$s via %2\$s.", 'sendtomp' ),
			$c['mp_name'] . $c['location_label'],
			$c['site_name']
		) . "\n\n";

		if ( '' !== $c['intro_message'] ) {
			$body .= wp_strip_all_tags( $c['intro_message'], true ) . "\n\n";
		}

		$body .= __( 'Please confirm you want to send this message by clicking the link below:', 'sendtomp' ) . "\n\n";
		$body .= $c['confirmation_url'] . "\n\n";
		$body .= __( '--- Your message preview ---', 'sendtomp' ) . "\n\n";
		$body .= sprintf( __( 'Subject: %s', 'sendtomp' ), $c['message_subject'] ) . "\n\n";
		$body .= wp_strip_all_tags( $c['message_body'], true ) . "\n\n";
		$body .= "---\n\n";
		$body .= sprintf(
			/* translators: %d: number of hours. */
			_n( 'This link expires in %d hour.', 'This link expires in %d hours.', $c['expiry_hours'], 'sendtomp' ),
			$c['expiry_hours']
		) . "\n\n";
		$body .= __( "If you did not submit this message, you can safely ignore this email.", 'sendtomp' ) . "\n";

		if ( $c['show_branding'] ) {
			$body .= "\n---\n" . __( "Powered by Bluetorch's SendToMP — verified constituent correspondence.", 'sendtomp' ) . "\n";
		}

		return $body;
	}

	/**
	 * Build the rich HTML confirmation email.
	 *
	 * Uses inline styles everywhere — Gmail, Yahoo, and most clients
	 * strip or ignore <style> blocks in the head. Table-based layout
	 * for Outlook. 600px max width.
	 *
	 * @param array $c Context built in send_confirmation().
	 * @return string
	 */
	private function render_confirmation_html( array $c ): string {
		$confirmation_url = esc_url( $c['confirmation_url'] );
		$logo_url         = esc_url( $c['logo_url'] );
		$site_name        = esc_html( $c['site_name'] );
		$mp_name          = esc_html( $c['mp_name'] );
		$mp_constituency  = esc_html( $c['mp_constituency'] );
		$location_label   = '' !== $c['mp_constituency'] ? ' <span style="color:#6b7280;">(' . $mp_constituency . ')</span>' : '';
		$message_subject  = esc_html( $c['message_subject'] );
		$message_body     = nl2br( esc_html( $c['message_body'] ) );
		$intro_message    = wp_kses_post( $c['intro_message'] );
		$bluetorch_logo   = esc_url( SENDTOMP_PLUGIN_URL . 'assets/images/providers/bluetorch.svg' );
		$expiry_hours     = (int) $c['expiry_hours'];
		$expiry_line      = esc_html( sprintf(
			_n( 'This link expires in %d hour.', 'This link expires in %d hours.', $expiry_hours, 'sendtomp' ),
			$expiry_hours
		) );

		ob_start();
		?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<title><?php echo esc_html( sprintf( __( 'Please confirm your message to %s', 'sendtomp' ), $c['mp_name'] ) ); ?></title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#1f2937; -webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6; padding:24px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
				<?php if ( '' !== $logo_url ) : ?>
				<tr>
					<td style="padding:28px 32px 20px; text-align:center; border-bottom:1px solid #e5e7eb;">
						<img src="<?php echo $logo_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_url'd. ?>" alt="<?php echo esc_attr( $c['site_name'] ); ?>" style="max-height:56px; max-width:320px; width:auto; height:auto; display:inline-block; border:0;" />
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<td style="padding:32px 32px 8px;">
						<?php if ( '' !== trim( wp_strip_all_tags( $intro_message ) ) ) : ?>
						<div style="margin:0 0 24px; padding:16px 20px; background:#eff6ff; border-left:4px solid #3b82f6; border-radius:4px; font-size:15px; line-height:1.5;">
							<?php echo $intro_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post'd. ?>
						</div>
						<?php endif; ?>

						<h1 style="margin:0 0 12px; font-size:22px; line-height:1.3; font-weight:600; color:#111827;">
							<?php echo esc_html__( 'Confirm your message to', 'sendtomp' ); ?> <?php echo $mp_name; // phpcs:ignore ?><?php echo $location_label; // phpcs:ignore ?>
						</h1>
						<p style="margin:0 0 20px; font-size:15px; line-height:1.55; color:#374151;">
							<?php
							/* translators: %s: site name. */
							echo esc_html( sprintf( __( 'You recently submitted this message via %s. Your message won\'t reach your MP until you confirm below.', 'sendtomp' ), $c['site_name'] ) );
							?>
						</p>

						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 28px;">
							<tr>
								<td style="background:#0073aa; border-radius:6px;">
									<a href="<?php echo $confirmation_url; // phpcs:ignore ?>" style="display:inline-block; padding:14px 28px; color:#ffffff; text-decoration:none; font-weight:600; font-size:16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
										<?php esc_html_e( 'Confirm and send my message', 'sendtomp' ); ?>
									</a>
								</td>
							</tr>
						</table>

						<p style="margin:0 0 4px; font-size:13px; line-height:1.5; color:#6b7280;">
							<?php echo $expiry_line; ?>
						</p>
						<p style="margin:0 0 8px; font-size:13px; line-height:1.5; color:#6b7280;">
							<?php esc_html_e( "If you didn't submit this message, you can safely ignore this email.", 'sendtomp' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<td style="padding:8px 32px 32px;">
						<div style="background:#f9fafb; border:1px solid #e5e7eb; border-left:4px solid #0073aa; border-radius:4px; padding:20px 24px;">
							<p style="margin:0 0 12px; font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#6b7280;">
								<?php esc_html_e( 'Preview of your message', 'sendtomp' ); ?>
							</p>
							<p style="margin:0 0 4px; font-size:13px; color:#6b7280;">
								<?php esc_html_e( 'Subject', 'sendtomp' ); ?>
							</p>
							<p style="margin:0 0 16px; font-size:15px; font-weight:600; color:#111827;">
								<?php echo $message_subject; ?>
							</p>
							<p style="margin:0 0 4px; font-size:13px; color:#6b7280;">
								<?php esc_html_e( 'Body', 'sendtomp' ); ?>
							</p>
							<div style="font-size:14px; line-height:1.6; color:#1f2937; white-space:normal;">
								<?php echo $message_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nl2br + esc_html. ?>
							</div>
						</div>
					</td>
				</tr>

				<?php if ( $c['show_branding'] ) : ?>
				<tr>
					<td style="padding:20px 32px; background:#f9fafb; border-top:1px solid #e5e7eb; text-align:center;">
						<a href="https://bluetorch.co.uk/sendtomp" style="text-decoration:none; color:inherit; display:inline-block;">
							<img src="<?php echo $bluetorch_logo; // phpcs:ignore ?>" alt="Bluetorch" height="24" style="max-height:24px; width:auto; display:inline-block; vertical-align:middle; border:0;" />
							<span style="color:#6b7280; font-size:12px; line-height:24px; vertical-align:middle; margin-left:8px;">
								<?php esc_html_e( 'Powered by SendToMP — verified constituent correspondence', 'sendtomp' ); ?>
							</span>
						</a>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	private function replace_placeholders( string $template, SendToMP_Submission $submission ): string {
		$member = $submission->resolved_member;

		$replacements = [
			'{constituent_name}'     => $submission->constituent_name,
			'{constituent_email}'    => $submission->constituent_email,
			'{constituent_postcode}' => $submission->constituent_postcode,
			'{constituent_address}'  => $submission->constituent_address,
			'{message_subject}'      => $submission->message_subject,
			'{message_body}'         => $submission->message_body,
			'{mp_name}'              => isset( $member['name'] ) ? $member['name'] : '',
			'{mp_constituency}'      => isset( $member['constituency'] ) ? $member['constituency'] : '',
			'{mp_party}'             => isset( $member['party'] ) ? $member['party'] : '',
			'{mp_house}'             => isset( $member['house'] ) ? $member['house'] : '',
			'{contact_quality}'      => isset( $member['contact_quality'] ) ? $member['contact_quality'] : 'direct',
			'{site_name}'            => get_bloginfo( 'name' ),
			'{site_url}'             => home_url(),
		];

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	private function get_default_mp_email_template(): string {
		$campaign_type = sendtomp()->get_setting( 'campaign_type' );

		$templates = [
			'petition'     => "Dear {mp_name},\n\nI am writing as your constituent to ask you to support the following petition.\n\n{message_body}\n\nI would be grateful if you could confirm your position on this matter.\n\nYours sincerely,\n{constituent_name}\n{constituent_postcode}",
			'advocacy'     => "Dear {mp_name},\n\nAs your constituent, I am writing to urge you to take action on the following issue.\n\n{message_body}\n\nI look forward to hearing your response.\n\nYours sincerely,\n{constituent_name}\n{constituent_postcode}",
			'consultation' => "Dear {mp_name},\n\nI am writing as your constituent to share my views on an issue currently before Parliament.\n\n{message_body}\n\nThank you for considering my perspective.\n\nYours sincerely,\n{constituent_name}\n{constituent_postcode}",
		];

		if ( isset( $templates[ $campaign_type ] ) ) {
			return $templates[ $campaign_type ];
		}

		return "Dear {mp_name},\n\n{message_body}\n\nYours sincerely,\n{constituent_name}\n{constituent_postcode}\n{constituent_email}";
	}

	private function get_mp_email_footer( SendToMP_Submission $submission ): string {
		$footer  = "---\n";

		// Shared inbox transparency for Lords.
		if ( $submission->is_shared_inbox() ) {
			$footer .= 'Note: This message is intended for the attention of ' . $submission->resolved_member['name'] . ".\n\n";
		}

		$footer_template = ! empty( $submission->constituent_postcode )
			? 'This message was sent by {constituent_name} ({constituent_postcode}) via {site_name}. The sender verified their email address before this message was sent. Reply directly to {constituent_email}.'
			: 'This message was sent by {constituent_name} via {site_name}. The sender verified their email address before this message was sent. Reply directly to {constituent_email}.';

		$footer .= $this->replace_placeholders( $footer_template, $submission );

		if ( SendToMP_License::should_show_branding() ) {
			$footer .= "\n\nPowered by Bluetorch's SendToMP — verified constituent correspondence. Built by a former parliamentary assistant.";
		}

		return $footer;
	}

	private function get_confirmation_url( string $token ): string {
		return add_query_arg( [ 'sendtomp_confirm' => $token ], home_url( '/' ) );
	}

	public function detect_smtp_plugin() {
		if ( class_exists( 'WPMailSMTP\\WPMailSMTP' ) ) {
			return 'WP Mail SMTP';
		}

		if ( class_exists( 'FluentSmtpDb' ) ) {
			return 'FluentSMTP';
		}

		if ( function_exists( 'postman_start' ) ) {
			return 'Post SMTP';
		}

		if ( class_exists( 'Easy_WP_SMTP' ) ) {
			return 'Easy WP SMTP';
		}

		return false;
	}

	public function send_test_email( string $to ) {
		$provider_id = $this->get_provider()->get_id();

		$settings   = sendtomp()->get_settings();
		$from_name  = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_name'] ?? '' ) );
		$from_email = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_email'] ?? '' ) );

		$subject = sprintf(
			/* translators: %s: provider id (e.g. "brevo", "smtp_custom"). */
			__( 'SendToMP test email (via %s)', 'sendtomp' ),
			$provider_id
		);
		$body = sprintf(
			/* translators: %s: provider id. */
			__( "This is a test email from SendToMP to verify your email configuration is working correctly.\n\nDelivered via: %s", 'sendtomp' ),
			$provider_id
		);

		$result = $this->dispatch( [
			'to'      => [ 'email' => $to ],
			'from'    => [ 'email' => $from_email, 'name' => $from_name ],
			'subject' => $subject,
			'text'    => $body,
		] );

		if ( true !== $result ) {
			return is_wp_error( $result )
				? $result
				: new WP_Error( 'test_email_failed', __( 'The test email could not be sent. Please check your email configuration.', 'sendtomp' ) );
		}

		return true;
	}

	/**
	 * Detect whether a string contains HTML tags that should trigger
	 * HTML email rendering.
	 *
	 * Uses a conservative check — only tags commonly produced by the
	 * TinyMCE editor flip the result to true. Freeform `<` in a body
	 * (e.g. "5 < 10") is left as plain text.
	 *
	 * @param string $content The email body to inspect.
	 * @return bool
	 */
	private function body_contains_html( string $content ): bool {
		return (bool) preg_match( '/<(p|br|div|span|strong|em|b|i|u|ul|ol|li|a|h[1-6]|blockquote)\b[^>]*>/i', $content );
	}

	/**
	 * Detect whether a string contains Markdown formatting we convert.
	 *
	 * Looks for the syntax the feed toolbar produces: bold (**text**),
	 * italic (*text*), links ([text](url)), list markers at line start
	 * ("- ", "1. "), blockquote ("> "), or headings ("## ").
	 *
	 * @param string $content Candidate body.
	 * @return bool
	 */
	private function body_has_markdown_formatting( string $content ): bool {
		$patterns = [
			'/\*\*[^*\n]+\*\*/',       // **bold**
			'/(?<!\*)\*[^*\n]+\*(?!\*)/', // *italic* (not part of **)
			'/\[[^\]]+\]\([^)\s]+\)/', // [link](url)
			'/(^|\n)\s*-\s+\S/',       // - bullet list
			'/(^|\n)\s*\d+\.\s+\S/',   // 1. numbered list
			'/(^|\n)\s*>\s+\S/',       // > blockquote
			'/(^|\n)#{1,6}\s+\S/',     // # heading
		];
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert a small Markdown subset to HTML.
	 *
	 * Supports the syntax produced by the Gravity Forms feed editor
	 * toolbar: bold, italic, links, bulleted/numbered lists,
	 * blockquotes, and ATX headings. Non-Markdown text is preserved
	 * and wrapped in paragraphs via wpautop().
	 *
	 * This is deliberately a small hand-rolled converter rather than
	 * a third-party library — keeps the plugin lean and avoids adding
	 * dependencies for what is a narrow email-template use case.
	 *
	 * @param string $markdown Markdown source text.
	 * @return string HTML output.
	 */
	private function markdown_to_html( string $markdown ): string {
		$lines  = preg_split( '/\r\n|\r|\n/', $markdown );
		$output = [];
		$in_ul  = false;
		$in_ol  = false;

		$close_lists = function () use ( &$in_ul, &$in_ol, &$output ) {
			if ( $in_ul ) {
				$output[] = '</ul>';
				$in_ul    = false;
			}
			if ( $in_ol ) {
				$output[] = '</ol>';
				$in_ol    = false;
			}
		};

		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );

			// Bulleted list item.
			if ( preg_match( '/^-\s+(.+)$/', $trimmed, $m ) ) {
				if ( $in_ol ) { $output[] = '</ol>'; $in_ol = false; }
				if ( ! $in_ul ) { $output[] = '<ul>'; $in_ul = true; }
				$output[] = '<li>' . $this->render_markdown_inline( $m[1] ) . '</li>';
				continue;
			}

			// Numbered list item.
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $m ) ) {
				if ( $in_ul ) { $output[] = '</ul>'; $in_ul = false; }
				if ( ! $in_ol ) { $output[] = '<ol>'; $in_ol = true; }
				$output[] = '<li>' . $this->render_markdown_inline( $m[1] ) . '</li>';
				continue;
			}

			$close_lists();

			// Heading (## up to ######).
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $m ) ) {
				$level    = strlen( $m[1] );
				$output[] = '<h' . $level . '>' . $this->render_markdown_inline( $m[2] ) . '</h' . $level . '>';
				continue;
			}

			// Blockquote (single line).
			if ( preg_match( '/^>\s*(.*)$/', $trimmed, $m ) ) {
				$output[] = '<blockquote>' . $this->render_markdown_inline( $m[1] ) . '</blockquote>';
				continue;
			}

			// Regular line — inline format and keep as is; wpautop() later
			// handles paragraph wrapping.
			$output[] = $this->render_markdown_inline( $line );
		}

		$close_lists();

		return wpautop( implode( "\n", $output ) );
	}

	/**
	 * Process inline Markdown (bold, italic, link) on a single line.
	 *
	 * @param string $text Line of text.
	 * @return string HTML-encoded line.
	 */
	private function render_markdown_inline( string $text ): string {
		// Escape any HTML first so raw `<` etc don't leak through.
		$text = esc_html( $text );

		// Links: [text](url)
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>';
			},
			$text
		);

		// Bold: **text** — run before italic so *...* inside doesn't match first.
		$text = preg_replace( '/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text );

		// Italic: *text*
		$text = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text );

		return $text;
	}
}
