<?php
/**
 * SendToMP_Provider_SMTP — custom SMTP credentials.
 *
 * Reconfigures WordPress's PHPMailer to send via the site owner's
 * own SMTP server. Runs only when `smtp_provider` is "smtp_custom"
 * and no dedicated SMTP plugin (WP Mail SMTP / FluentSMTP / Post SMTP)
 * is active — if one is, we defer to it rather than racing its
 * `phpmailer_init` configuration.
 *
 * The SMTP password is stored encrypted via SendToMP_Secret.
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SendToMP_Provider_SMTP
 */
class SendToMP_Provider_SMTP implements SendToMP_Provider_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'smtp_custom';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured(): bool {
		$host = (string) sendtomp()->get_setting( 'smtp_host' );
		$port = (int) sendtomp()->get_setting( 'smtp_port' );
		return '' !== $host && $port > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function boot(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		// Defer to a dedicated SMTP plugin if one is already handling email.
		$mailer = new SendToMP_Mailer();
		if ( $mailer->detect_smtp_plugin() ) {
			return;
		}

		// Priority 5 — earlier than WP Mail SMTP's default (10) so that
		// if both end up active the dedicated plugin still wins. Our
		// early bail above is the primary guard; this is belt-and-braces.
		add_action( 'phpmailer_init', [ $this, 'configure_phpmailer' ], 5 );
	}

	/**
	 * Configure the PHPMailer instance WordPress is about to send with.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer|\PHPMailer $phpmailer Passed by reference by WP.
	 * @return void
	 */
	public function configure_phpmailer( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host = (string) sendtomp()->get_setting( 'smtp_host' );
		$phpmailer->Port = (int) sendtomp()->get_setting( 'smtp_port' );

		$encryption = (string) sendtomp()->get_setting( 'smtp_encryption' );
		if ( 'tls' === $encryption || 'ssl' === $encryption ) {
			$phpmailer->SMTPSecure = $encryption;
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		$phpmailer->SMTPAuth = (bool) sendtomp()->get_setting( 'smtp_auth' );

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = (string) sendtomp()->get_setting( 'smtp_username' );
			$stored              = (string) sendtomp()->get_setting( 'smtp_password' );
			if ( '' !== $stored ) {
				$decrypted = SendToMP_Secret::decrypt( $stored );
				if ( null !== $decrypted ) {
					$phpmailer->Password = $decrypted;
				}
			}
		}
	}

	/**
	 * Send via wp_mail(). The `phpmailer_init` hook registered in
	 * boot() reconfigures PHPMailer with our SMTP credentials before
	 * the send actually runs.
	 *
	 * @inheritDoc
	 */
	public function send( array $message ) {
		$to      = $message['to'] ?? '';
		$to_str  = is_array( $to ) ? (string) ( $to['email'] ?? '' ) : (string) $to;
		$subject = (string) ( $message['subject'] ?? '' );

		$has_html = ! empty( $message['html'] );
		$body     = $has_html ? (string) $message['html'] : (string) ( $message['text'] ?? '' );
		$headers  = $this->build_headers( $message, $has_html );

		$sent = wp_mail( $to_str, $subject, $body, $headers );

		return $sent ? true : new WP_Error(
			'smtp_send_failed',
			__( 'Custom SMTP send failed. Check host/port/credentials and retry.', 'sendtomp' )
		);
	}

	/**
	 * Build the raw header lines wp_mail() accepts.
	 *
	 * @param array $m        Message array.
	 * @param bool  $has_html Whether the body is HTML.
	 * @return array
	 */
	private function build_headers( array $m, bool $has_html ): array {
		$headers = [];

		if ( ! empty( $m['from']['email'] ) ) {
			$from    = $m['from'];
			$headers[] = 'From: ' . ( empty( $from['name'] )
				? $from['email']
				: $from['name'] . ' <' . $from['email'] . '>' );
		}

		if ( ! empty( $m['reply_to']['email'] ) ) {
			$headers[] = 'Reply-To: ' . $m['reply_to']['email'];
		}

		if ( $has_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		if ( ! empty( $m['cc'] ) && is_array( $m['cc'] ) ) {
			foreach ( $m['cc'] as $addr ) {
				if ( ! empty( $addr['email'] ) ) {
					$headers[] = 'Cc: ' . $addr['email'];
				}
			}
		}

		if ( ! empty( $m['bcc'] ) && is_array( $m['bcc'] ) ) {
			foreach ( $m['bcc'] as $addr ) {
				if ( ! empty( $addr['email'] ) ) {
					$headers[] = 'Bcc: ' . $addr['email'];
				}
			}
		}

		return $headers;
	}
}
