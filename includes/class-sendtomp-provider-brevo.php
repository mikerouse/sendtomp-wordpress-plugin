<?php
/**
 * SendToMP_Provider_Brevo — delivery via Brevo's transactional HTTP API.
 *
 * POSTs to https://api.brevo.com/v3/smtp/email rather than going
 * through SMTP: better deliverability metrics, no PHPMailer overhead,
 * and Brevo's dashboard picks up every send with rich diagnostics.
 *
 * The API key is stored encrypted via SendToMP_Secret — we never
 * hold it plaintext in options.
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SendToMP_Provider_Brevo
 */
class SendToMP_Provider_Brevo implements SendToMP_Provider_Interface {

	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'brevo';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured(): bool {
		return '' !== $this->get_api_key();
	}

	/**
	 * @inheritDoc
	 */
	public function boot(): void {
		// No hooks needed — provider is invoked directly by the dispatcher.
	}

	/**
	 * @inheritDoc
	 */
	public function send( array $message ) {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error(
				'brevo_not_configured',
				__( 'Brevo API key is missing or could not be decrypted. Re-enter it on the Email Delivery tab.', 'sendtomp' )
			);
		}

		$payload = $this->build_payload( $message );

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 15,
			'headers' => [
				'accept'       => 'application/json',
				'api-key'      => $api_key,
				'content-type' => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		$body = wp_remote_retrieve_body( $response );

		return new WP_Error(
			'brevo_send_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: Brevo response body */
				__( 'Brevo send failed (HTTP %1$d): %2$s', 'sendtomp' ),
				$status,
				wp_strip_all_tags( (string) $body )
			)
		);
	}

	/**
	 * Fetch and decrypt the Brevo API key from settings.
	 *
	 * @return string Empty string when missing or un-decryptable.
	 */
	private function get_api_key(): string {
		$stored = (string) sendtomp()->get_setting( 'brevo_api_key' );
		if ( '' === $stored ) {
			return '';
		}

		$plaintext = SendToMP_Secret::decrypt( $stored );
		return null === $plaintext ? '' : $plaintext;
	}

	/**
	 * Build the Brevo API request payload from the normalised message.
	 *
	 * @param array $message Canonical SendToMP message array.
	 * @return array
	 */
	private function build_payload( array $message ): array {
		$payload = [
			'sender'  => $this->format_addr( $message['from'] ?? [] ),
			'to'      => [ $this->format_addr( $message['to'] ?? [] ) ],
			'subject' => (string) ( $message['subject'] ?? '' ),
		];

		if ( ! empty( $message['html'] ) ) {
			$payload['htmlContent'] = (string) $message['html'];
		}
		if ( ! empty( $message['text'] ) ) {
			$payload['textContent'] = (string) $message['text'];
		}
		if ( ! empty( $message['reply_to'] ) ) {
			$payload['replyTo'] = $this->format_addr( $message['reply_to'] );
		}
		if ( ! empty( $message['cc'] ) && is_array( $message['cc'] ) ) {
			$payload['cc'] = array_values( array_map( [ $this, 'format_addr' ], $message['cc'] ) );
		}
		if ( ! empty( $message['bcc'] ) && is_array( $message['bcc'] ) ) {
			$payload['bcc'] = array_values( array_map( [ $this, 'format_addr' ], $message['bcc'] ) );
		}

		return $payload;
	}

	/**
	 * Normalise an address into Brevo's {email,name?} shape.
	 *
	 * Accepts either a plain email string or an ['email' => ..., 'name' => ...] array.
	 *
	 * @param mixed $addr Raw address value.
	 * @return array
	 */
	private function format_addr( $addr ): array {
		if ( is_string( $addr ) ) {
			return [ 'email' => $addr ];
		}
		if ( ! is_array( $addr ) ) {
			return [ 'email' => '' ];
		}

		$out = [ 'email' => (string) ( $addr['email'] ?? '' ) ];
		if ( ! empty( $addr['name'] ) ) {
			$out['name'] = (string) $addr['name'];
		}
		return $out;
	}
}
