<?php
/**
 * SendToMP_Provider_WP_Mail — passthrough via wp_mail().
 *
 * Covers two user-facing provider selections:
 *   - "wp_mail"     : PHP's default mailer (not recommended)
 *   - "smtp_plugin" : a detected third-party SMTP plugin handles delivery
 *
 * From our side both are the same code path — we call wp_mail() and let
 * WordPress (plus any hooked SMTP plugin) do the rest. The UI distinguishes
 * the two so users understand the difference; functionally this class
 * handles both.
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SendToMP_Provider_WP_Mail
 */
class SendToMP_Provider_WP_Mail implements SendToMP_Provider_Interface {

	/**
	 * The provider id we identify as. Injected by the factory so a single
	 * instance can stand in for either "wp_mail" or "smtp_plugin".
	 *
	 * @var string
	 */
	private $id;

	/**
	 * @param string $id Either 'wp_mail' or 'smtp_plugin'.
	 */
	public function __construct( string $id = 'wp_mail' ) {
		$this->id = $id;
	}

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured(): bool {
		// wp_mail() is always "configured" — whether it delivers reliably
		// is a different question, surfaced in the UI as a warning.
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function boot(): void {
		// No hooks needed.
	}

	/**
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
			'wp_mail_failed',
			__( 'wp_mail() send failed. Check your mail server or configure an SMTP provider.', 'sendtomp' )
		);
	}

	/**
	 * Translate the canonical message array into wp_mail() header lines.
	 *
	 * @param array $m        Message.
	 * @param bool  $has_html Whether body is HTML.
	 * @return array
	 */
	private function build_headers( array $m, bool $has_html ): array {
		$headers = [];

		if ( ! empty( $m['from']['email'] ) ) {
			$from      = $m['from'];
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
