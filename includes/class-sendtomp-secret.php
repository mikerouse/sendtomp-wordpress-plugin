<?php
/**
 * SendToMP_Secret — encrypt/decrypt helper for sensitive settings.
 *
 * Used for the Custom SMTP password, Brevo API key, and (later) OAuth
 * tokens — anything we persist in wp_options that would be bad to
 * leak via a database dump or an unauthenticated options-export.
 *
 * Encryption: AES-256-GCM via OpenSSL. The stored value is a versioned
 * base64 bundle ("stmpv1:<base64(iv|tag|ciphertext)>") so we can
 * rotate the format without breaking legacy records.
 *
 * Key derivation:
 *   sha256( "sendtomp-secret:" . wp_salt('auth') [ . SENDTOMP_SECRET_KEY ] )
 *
 * Setting a `SENDTOMP_SECRET_KEY` constant in wp-config.php keeps the
 * encryption key material outside the database, so a DB-only dump
 * cannot decrypt stored secrets. Without it, we still get encryption
 * at rest — just sharing the fate of wp_salt().
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SendToMP_Secret
 */
class SendToMP_Secret {

	/**
	 * Prefix marking values we encrypted ourselves. Lets callers tell
	 * an already-encrypted value from a plaintext one without having
	 * to attempt a decrypt.
	 *
	 * @var string
	 */
	const FORMAT_PREFIX = 'stmpv1:';

	/**
	 * Encrypt a plaintext value for safe storage.
	 *
	 * Returns an empty string when OpenSSL is unavailable or the
	 * operation fails — callers should either treat that as "don't
	 * persist this" or fall back to a clear warning.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Encrypted value in "stmpv1:..." form, or '' on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$key = self::get_key();
		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext ) {
			return '';
		}

		return self::FORMAT_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a previously-encrypted value.
	 *
	 * Returns null when the input isn't a value we produced, when
	 * OpenSSL is unavailable, or when the auth tag fails verification
	 * (e.g. key rotation, tampering). Callers should treat null as
	 * "this secret is lost — prompt the user to re-enter".
	 *
	 * @param string $stored The "stmpv1:..." value from storage.
	 * @return string|null Plaintext, or null on any failure.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return null;
		}
		if ( strpos( $stored, self::FORMAT_PREFIX ) !== 0 ) {
			return null;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::FORMAT_PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return null;
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Return true if a stored value looks like something we encrypted.
	 * Useful for migration paths that want to encrypt-on-save only
	 * when the user has actually changed the field.
	 *
	 * @param string $stored Value from storage.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return '' !== $stored && 0 === strpos( $stored, self::FORMAT_PREFIX );
	}

	/**
	 * Derive the 32-byte symmetric key used for AES-256-GCM.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function get_key(): string {
		$material = 'sendtomp-secret:' . wp_salt( 'auth' );

		if ( defined( 'SENDTOMP_SECRET_KEY' ) && is_string( SENDTOMP_SECRET_KEY ) && '' !== SENDTOMP_SECRET_KEY ) {
			$material .= '|' . SENDTOMP_SECRET_KEY;
		}

		return hash( 'sha256', $material, true );
	}
}
