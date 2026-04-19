<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Mailer {

	public function send_to_mp( SendToMP_Submission $submission ) {
		$settings = sendtomp()->get_settings();

		// Strip newlines to prevent email header injection.
		$from_name  = str_replace( [ "\r", "\n" ], '', $settings['from_name'] );
		$from_email = str_replace( [ "\r", "\n" ], '', $settings['from_email'] );

		$headers = [];
		$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';

		if ( 'constituent' === $settings['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $submission->constituent_email;
		} else {
			// 'fixed' mode — use the configured reply_to_email, falling back to from_email.
			$reply_email = ! empty( $settings['reply_to_email'] ) ? $settings['reply_to_email'] : $settings['from_email'];
			$headers[] = 'Reply-To: ' . $reply_email;
		}

		if ( ! empty( $settings['bcc_emails'] ) && sendtomp()->can( 'bcc' ) ) {
			$bcc_list = array_map( 'trim', explode( ',', $settings['bcc_emails'] ) );
			foreach ( $bcc_list as $bcc ) {
				if ( is_email( $bcc ) ) {
					$headers[] = 'BCC: ' . $bcc;
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
			return new WP_Error( 'no_delivery_email', 'No delivery email address found for this MP.' );
		}

		$to = $submission->resolved_member['delivery_email'];

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'Your message could not be sent. Please try again later.' );
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
		$body  = "You recently submitted a message to {$mp_name}{$location_label} via " . get_bloginfo( 'name' ) . ".\n\n";
		$body .= "Please confirm you want to send this message by clicking the link below:\n\n";
		$body .= $confirmation_url . "\n\n";
		$body .= "--- Your message preview ---\n\n";
		$body .= "Subject: {$submission->message_subject}\n\n";
		$body .= $submission->message_body . "\n\n";
		$body .= "---\n\n";
		$body .= "This link will expire in {$expiry_hours} hours.\n\n";
		$body .= "If you did not submit this message, you can safely ignore this email.\n";

		if ( sendtomp()->get_setting( 'show_branding' ) ) {
			$body .= "\n---\nPowered by Bluetorch's SendToMP — verified constituent correspondence.\n";
		}

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent ) {
			return new WP_Error( 'confirmation_failed', 'We could not send a confirmation email. Please check your email address and try again.' );
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

		if ( sendtomp()->get_setting( 'show_branding' ) ) {
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
		$subject = 'SendToMP Test Email';
		$body    = 'This is a test email from SendToMP to verify your email configuration is working correctly.';

		$sent = wp_mail( $to, $subject, $body );

		if ( ! $sent ) {
			return new WP_Error( 'test_email_failed', 'The test email could not be sent. Please check your email configuration.' );
		}

		return true;
	}
}
