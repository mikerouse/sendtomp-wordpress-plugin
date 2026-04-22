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
		$expiry_hours     = sendtomp()->get_setting( 'confirmation_expiry' );

		$location_label = ! empty( $mp_constituency ) ? " ({$mp_constituency})" : '';
		// Confirmation email is always plain text — strip any HTML that
		// may be present in the message body (from a WYSIWYG template)
		// so the preview reads cleanly.
		$preview_body = wp_strip_all_tags( (string) $submission->message_body, true );

		$body  = "You recently submitted a message to {$mp_name}{$location_label} via " . get_bloginfo( 'name' ) . ".\n\n";
		$body .= "Please confirm you want to send this message by clicking the link below:\n\n";
		$body .= $confirmation_url . "\n\n";
		$body .= "--- Your message preview ---\n\n";
		$body .= "Subject: {$submission->message_subject}\n\n";
		$body .= $preview_body . "\n\n";
		$body .= "---\n\n";
		$body .= "This link will expire in {$expiry_hours} hours.\n\n";
		$body .= "If you did not submit this message, you can safely ignore this email.\n";

		if ( SendToMP_License::should_show_branding() ) {
			$body .= "\n---\nPowered by Bluetorch's SendToMP — verified constituent correspondence.\n";
		}

		$settings  = sendtomp()->get_settings();
		$from_name  = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_name'] ?? '' ) );
		$from_email = str_replace( [ "\r", "\n" ], '', (string) ( $settings['from_email'] ?? '' ) );

		$result = $this->dispatch( [
			'to'      => [ 'email' => $to ],
			'from'    => [ 'email' => $from_email, 'name' => $from_name ],
			'subject' => $subject,
			'text'    => $body,
		] );

		if ( true !== $result ) {
			return is_wp_error( $result )
				? $result
				: new WP_Error( 'confirmation_failed', __( 'We could not send a confirmation email. Please check your email address and try again.', 'sendtomp' ) );
		}

		return true;
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
