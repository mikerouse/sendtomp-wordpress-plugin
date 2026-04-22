<?php
/**
 * SendToMP_Confirmation — handles the double opt-in confirmation flow.
 *
 * Manages the pending submissions table, confirmation page rendering,
 * and the confirm-and-send workflow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SendToMP_Confirmation {

	/**
	 * Create the custom pending submissions table.
	 *
	 * Called on plugin activation via register_activation_hook.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'sendtomp_pending';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			submission_data longtext NOT NULL,
			resolved_member text NOT NULL,
			constituent_email varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			consent_given_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY constituent_email (constituent_email),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Constructor — register WordPress hooks.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'handle_confirmation_request' ] );
		add_action( 'sendtomp_cleanup_pending', [ $this, 'cleanup_expired' ] );
	}

	/**
	 * Schedule the hourly cleanup cron event if not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'sendtomp_cleanup_pending' ) ) {
			wp_schedule_event( time(), 'hourly', 'sendtomp_cleanup_pending' );
		}
	}

	/**
	 * Store a pending submission and return the confirmation token.
	 *
	 * @param SendToMP_Submission $submission     The normalised submission.
	 * @param array               $resolved_member The resolved MP/peer data.
	 * @return string|WP_Error Token string on success, WP_Error on failure.
	 */
	public function store_pending( SendToMP_Submission $submission, array $resolved_member ) {
		global $wpdb;

		$token = wp_generate_password( 64, false, false );

		$encryption_key = wp_salt( 'auth' );
		$iv             = openssl_random_pseudo_bytes( 16 );
		$encrypted_data = openssl_encrypt(
			wp_json_encode( $submission->to_array() ),
			'aes-256-cbc',
			$encryption_key,
			0,
			$iv
		);

		if ( false === $encrypted_data ) {
			return new WP_Error( 'encryption_failed', __( 'Failed to encrypt submission data.', 'sendtomp' ) );
		}

		// Prepend IV to ciphertext so we can extract it on decryption.
		$encrypted_data = base64_encode( $iv ) . ':' . $encrypted_data;

		$expiry_hours = (int) sendtomp()->get_setting( 'confirmation_expiry' );
		if ( $expiry_hours < 1 ) {
			$expiry_hours = 24;
		}

		$now        = gmdate( 'Y-m-d H:i:s' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expiry_hours * HOUR_IN_SECONDS ) );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'sendtomp_pending',
			[
				'token'             => $token,
				'submission_data'   => $encrypted_data,
				'resolved_member'   => wp_json_encode( $resolved_member ),
				'constituent_email' => sanitize_email( $submission->constituent_email ),
				'status'            => 'pending',
				'created_at'        => $now,
				'expires_at'        => $expires_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to store pending submission.', 'sendtomp' ) );
		}

		return $token;
	}

	/**
	 * Retrieve a pending submission by token.
	 *
	 * @param string $token The confirmation token.
	 * @return array|WP_Error Array with 'submission', 'resolved_member', 'pending_id' on success.
	 */
	/**
	 * Return the most recent non-expired pending record for a given
	 * constituent email, decrypted and ready to reuse.
	 *
	 * Used by the submission-log "Resend confirmation email" action:
	 * the log doesn't store the token (by design — tokens must not
	 * leak into the log), so we look up the pending record by email
	 * and re-send using the existing token + data.
	 *
	 * @param string $email Constituent email address.
	 * @return array|WP_Error Same shape as get_pending(), or WP_Error
	 *                       when none exists / all have expired.
	 */
	public function get_latest_pending_by_email( string $email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sendtomp_pending';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table, prepared params; direct query required.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE constituent_email = %s AND status = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
				$email,
				'pending',
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'sendtomp_no_pending', __( 'No active pending confirmation found for this email. The link may have expired, or the message was already confirmed.', 'sendtomp' ) );
		}

		return $this->get_pending( (string) $row['token'] );
	}

	public function get_pending( string $token ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sendtomp_pending';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'invalid_token', __( 'This confirmation link is not valid.', 'sendtomp' ) );
		}

		if ( 'confirmed' === $row['status'] ) {
			return new WP_Error( 'already_confirmed', __( 'This message has already been confirmed and sent.', 'sendtomp' ) );
		}

		if ( 'expired' === $row['status'] || $row['expires_at'] < gmdate( 'Y-m-d H:i:s' ) ) {
			// Mark as expired if it wasn't already.
			if ( 'expired' !== $row['status'] ) {
				$wpdb->update(
					$table,
					[ 'status' => 'expired' ],
					[ 'id' => $row['id'] ],
					[ '%s' ],
					[ '%d' ]
				);
			}
			return new WP_Error( 'token_expired', __( 'This confirmation link has expired. Please submit the form again.', 'sendtomp' ) );
		}

		$decrypted = $this->decrypt_row( $row );
		if ( is_wp_error( $decrypted ) ) {
			return $decrypted;
		}

		return [
			'submission'      => $decrypted['submission_data'],
			'resolved_member' => $decrypted['resolved_member'],
			'pending_id'      => (int) $row['id'],
		];
	}

	/**
	 * Mark a pending submission as confirmed and send the message.
	 *
	 * @param int $pending_id The row ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function confirm( int $pending_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sendtomp_pending';

		// Atomic update — only confirm if still pending (prevents double-send race condition).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'confirmed', consent_given_at = %s WHERE id = %d AND status = 'pending'",
			gmdate( 'Y-m-d H:i:s' ),
			$pending_id
		) );

		if ( 0 === (int) $updated ) {
			return new WP_Error( 'already_confirmed', __( 'This message has already been confirmed and sent.', 'sendtomp' ) );
		}

		// Retrieve the row to get submission data.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $pending_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'record_not_found', __( 'Pending record not found after update.', 'sendtomp' ) );
		}

		$decrypted = $this->decrypt_row( $row );
		if ( is_wp_error( $decrypted ) ) {
			return $decrypted;
		}

		$submission_data = $decrypted['submission_data'];
		$resolved_member = $decrypted['resolved_member'];

		// Reconstruct the submission object.
		$submission                  = new SendToMP_Submission( $submission_data );
		$submission->resolved_member = $resolved_member;

		// Send the email to the MP.
		$mailer = new SendToMP_Mailer();
		$result = $mailer->send_to_mp( $submission );

		if ( is_wp_error( $result ) ) {
			// Revert status and clear consent so the user can try again.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', consent_given_at = NULL WHERE id = %d",
				$pending_id
			) );
			return $result;
		}

		// Use resolved member ID (target_member_id may be 0 for Commons postcode lookups).
		$member_id = ! empty( $resolved_member['id'] ) ? (int) $resolved_member['id'] : $submission->target_member_id;

		// Log delivery locally.
		if ( class_exists( 'SendToMP_Logger' ) ) {
			$submission->target_member_id = $member_id;
			SendToMP_Logger::log( $submission, 'confirmed' );
		}

		// Log to middleware (non-PII data only).
		$api_client = new SendToMP_API_Client();
		$api_client->log_delivery( [
			'postcode'  => $submission->constituent_postcode,
			'member_id' => $member_id,
			'house'     => $submission->target_house,
			'status'    => 'confirmed',
			'site_url'  => home_url(),
		] );

		return true;
	}

	/**
	 * Handle GET and POST requests for the confirmation page.
	 *
	 * Decrypt and deserialise a pending row's submission and member data.
	 *
	 * @param array $row The database row.
	 * @return array|WP_Error Array with 'submission_data' and 'resolved_member', or WP_Error.
	 */
	private function decrypt_row( array $row ) {
		$encryption_key = wp_salt( 'auth' );
		$parts          = explode( ':', $row['submission_data'], 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_data', __( 'Submission data format is invalid.', 'sendtomp' ) );
		}
		$iv        = base64_decode( $parts[0] );
		$decrypted = openssl_decrypt( $parts[1], 'aes-256-cbc', $encryption_key, 0, $iv );

		if ( false === $decrypted ) {
			return new WP_Error( 'decryption_failed', __( 'Failed to decrypt submission data.', 'sendtomp' ) );
		}

		$submission_data = json_decode( $decrypted, true );
		if ( null === $submission_data ) {
			return new WP_Error( 'invalid_data', __( 'Submission data is corrupt.', 'sendtomp' ) );
		}

		$resolved_member = json_decode( $row['resolved_member'], true );
		if ( null === $resolved_member ) {
			return new WP_Error( 'invalid_member', __( 'Resolved member data is corrupt.', 'sendtomp' ) );
		}

		return [
			'submission_data' => $submission_data,
			'resolved_member' => $resolved_member,
		];
	}

	/**
	 * Handle GET and POST requests for the confirmation page.
	 *
	 * GET  ?sendtomp_confirm=TOKEN  — render the confirmation page.
	 * POST ?sendtomp_confirm=TOKEN  — process the confirmation.
	 *
	 * @return void
	 */
	public function handle_confirmation_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token itself is a signed random value acting as capability; full nonce is verified on POST via wp_verify_nonce() below.
		if ( ! isset( $_GET['sendtomp_confirm'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above; token is validated against DB record.
		$token = sanitize_text_field( wp_unslash( $_GET['sendtomp_confirm'] ) );

		if ( empty( $token ) ) {
			return;
		}

		// POST request — process confirmation.
		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			$this->process_confirmation_post( $token );
			return;
		}

		// GET request — render confirmation page.
		$pending = $this->get_pending( $token );

		if ( is_wp_error( $pending ) ) {
			$code = 'token_expired' === $pending->get_error_code() ? 410 : 400;
			$this->render_error_page( $pending->get_error_message(), $code );
			exit;
		}

		$this->render_confirmation_page( $pending );
		exit;
	}

	/**
	 * Process the POST confirmation form submission.
	 *
	 * @param string $token The confirmation token.
	 * @return void
	 */
	private function process_confirmation_post( string $token ): void {
		// Verify the nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sendtomp_confirm_' . $token ) ) {
			$this->render_error_page( __( 'Security check failed. Please go back and try again.', 'sendtomp' ) );
			exit;
		}

		// Validate the token from POST matches the URL.
		$post_token = isset( $_POST['sendtomp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sendtomp_token'] ) ) : '';
		if ( $post_token !== $token ) {
			$this->render_error_page( __( 'Token mismatch. Please go back and try again.', 'sendtomp' ) );
			exit;
		}

		// Validate pending submission.
		$pending = $this->get_pending( $token );
		if ( is_wp_error( $pending ) ) {
			$code = 'token_expired' === $pending->get_error_code() ? 410 : 400;
			$this->render_error_page( $pending->get_error_message(), $code );
			exit;
		}

		// Confirm and send.
		$result = $this->confirm( $pending['pending_id'] );
		if ( is_wp_error( $result ) ) {
			$this->render_error_page( $result->get_error_message() );
			exit;
		}

		// Render thank-you page.
		$this->render_thankyou_page( $pending['resolved_member'] );
		exit;
	}

	/**
	 * Delete expired pending submissions.
	 *
	 * @return void
	 */
	public function cleanup_expired(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'sendtomp_pending';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND expires_at < %s",
				'pending',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Purge all pending submissions for a given email address (GDPR erasure).
	 *
	 * @param string $email The email address to purge.
	 * @return int Number of rows deleted.
	 */
	public static function purge_by_email( string $email ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'sendtomp_pending';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is from trusted internal source; direct query required for plugin tables.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE constituent_email = %s",
				$email
			)
		);
	}

	/**
	 * Render the confirmation page.
	 *
	 * Shows the message preview, MP details, consent text, and a Confirm & Send form.
	 *
	 * @param array $data Array with 'submission', 'resolved_member', 'pending_id'.
	 * @return void
	 */
	public function render_confirmation_page( array $data ): void {
		$submission      = $data['submission'];
		$resolved_member = $data['resolved_member'];

		$mp_name         = isset( $resolved_member['name'] ) ? esc_html( $resolved_member['name'] ) : 'your MP';
		$mp_constituency = isset( $resolved_member['constituency'] ) ? esc_html( $resolved_member['constituency'] ) : '';
		$message_subject = isset( $submission['message_subject'] ) ? esc_html( $submission['message_subject'] ) : '';
		$message_body    = isset( $submission['message_body'] ) ? esc_html( $submission['message_body'] ) : '';
		$site_name       = esc_html( get_bloginfo( 'name' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- token presence already validated by caller; render-only context uses token to build nonce action.
		$token      = sanitize_text_field( wp_unslash( $_GET['sendtomp_confirm'] ) );
		$form_action = esc_url( add_query_arg( [ 'sendtomp_confirm' => $token ], home_url( '/' ) ) );
		$nonce_action = 'sendtomp_confirm_' . $token;

		$show_branding = SendToMP_License::should_show_branding();

		status_header( 200 );
		nocache_headers();

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( __( 'Confirm your message to %s', 'sendtomp' ), $mp_name ) ); ?> &mdash; <?php echo esc_html( $site_name ); ?></title>
	<?php wp_head(); ?>
	<style>
		body.sendtomp-confirmation {
			margin: 0;
			padding: 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			line-height: 1.6;
		}
		.sendtomp-wrap {
			max-width: 640px;
			margin: 40px auto;
			padding: 0 20px;
		}
		.sendtomp-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 32px;
			margin-bottom: 20px;
		}
		.sendtomp-card h1 {
			font-size: 1.5em;
			margin: 0 0 8px;
			color: #1d2327;
		}
		.sendtomp-card .subtitle {
			color: #646970;
			margin: 0 0 24px;
			font-size: 0.95em;
		}
		.sendtomp-preview {
			background: #f6f7f7;
			border-left: 4px solid #2271b1;
			padding: 16px 20px;
			margin: 20px 0;
			border-radius: 0 4px 4px 0;
		}
		.sendtomp-preview h3 {
			margin: 0 0 8px;
			font-size: 0.95em;
			color: #2271b1;
		}
		.sendtomp-preview .subject {
			font-weight: 600;
			margin-bottom: 8px;
		}
		.sendtomp-preview .body {
			white-space: pre-wrap;
			word-wrap: break-word;
			font-size: 0.9em;
		}
		.sendtomp-consent {
			background: #fcf9e8;
			border: 1px solid #dba617;
			border-radius: 4px;
			padding: 16px;
			margin: 20px 0;
			font-size: 0.9em;
			color: #6e4e00;
		}
		.sendtomp-actions {
			margin-top: 24px;
			text-align: center;
		}
		.sendtomp-actions button {
			background: #2271b1;
			color: #fff;
			border: none;
			padding: 12px 32px;
			font-size: 1em;
			font-weight: 600;
			border-radius: 4px;
			cursor: pointer;
			line-height: 1.4;
		}
		.sendtomp-actions button:hover,
		.sendtomp-actions button:focus {
			background: #135e96;
			outline: 2px solid #2271b1;
			outline-offset: 2px;
		}
		.sendtomp-footer {
			text-align: center;
			font-size: 0.8em;
			color: #a7aaad;
			padding: 10px 0 30px;
		}
		.sendtomp-footer a {
			color: #a7aaad;
			text-decoration: none;
		}
		.sendtomp-footer a:hover {
			color: #646970;
		}
	</style>
</head>
<body class="sendtomp-confirmation">
	<div class="sendtomp-wrap">
		<div class="sendtomp-card">
			<h1><?php esc_html_e( 'Confirm your message', 'sendtomp' ); ?></h1>
			<p class="subtitle">
				to <?php echo esc_html( $mp_name ); ?><?php echo $mp_constituency ? ', ' . esc_html( $mp_constituency ) : ''; ?>
			</p>

			<div class="sendtomp-preview">
				<h3><?php esc_html_e( 'Your message preview', 'sendtomp' ); ?></h3>
				<?php if ( $message_subject ) : ?>
					<div class="subject"><?php echo esc_html( $message_subject ); ?></div>
				<?php endif; ?>
				<div class="body"><?php echo esc_html( $message_body ); ?></div>
			</div>

			<?php
			$is_lords  = isset( $resolved_member['house'] ) && 'lords' === $resolved_member['house'];
			$is_shared = isset( $resolved_member['contact_quality'] ) && 'shared' === $resolved_member['contact_quality'];
			?>

			<?php if ( $is_lords && $is_shared ) : ?>
				<div style="background: #f0f6fc; border: 1px solid #72aee6; border-radius: 4px; padding: 12px 16px; margin: 16px 0; font-size: 0.9em; color: #1d2327;">
					<?php echo esc_html( sprintf( __( 'This message will be sent to the House of Lords general contact address, marked for the attention of %s.', 'sendtomp' ), $mp_name ) ); ?>
				</div>
			<?php endif; ?>

			<div class="sendtomp-consent">
				By confirming, you consent to your name, email address<?php echo $is_lords ? '' : ', postcode,'; ?> and message
				being sent to <?php echo esc_html( $mp_name ); ?><?php echo $mp_constituency ? ', ' . esc_html( $mp_constituency ) : ''; ?>.
				<?php echo esc_html( $mp_name ); ?> may reply to you directly.
			</div>

			<form method="post" action="<?php echo esc_url( $form_action ); ?>" class="sendtomp-actions">
				<input type="hidden" name="sendtomp_token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( $nonce_action ); ?>
				<button type="submit"><?php esc_html_e( 'Confirm & Send', 'sendtomp' ); ?></button>
			</form>
		</div>

		<?php if ( $show_branding ) : ?>
			<div class="sendtomp-footer">
				<?php
				printf(
					esc_html__( 'Powered by %s', 'sendtomp' ),
					'<a href="https://www.bluetorch.co.uk/sendtomp" target="_blank" rel="noopener">Bluetorch\'s SendToMP</a>'
				);
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Render the thank-you page after successful confirmation.
	 *
	 * @param array $resolved_member The resolved MP/peer data.
	 * @return void
	 */
	public function render_thankyou_page( array $resolved_member ): void {
		$mp_name         = isset( $resolved_member['name'] ) ? esc_html( $resolved_member['name'] ) : 'your MP';
		$mp_constituency = isset( $resolved_member['constituency'] ) ? esc_html( $resolved_member['constituency'] ) : '';
		$site_name       = esc_html( get_bloginfo( 'name' ) );
		$site_url        = esc_url( home_url( '/' ) );

		$show_branding = SendToMP_License::should_show_branding();

		// Social sharing URLs.
		$is_lords     = isset( $resolved_member['house'] ) && 'lords' === $resolved_member['house'];
		$share_label  = $is_lords
			? $mp_name . ' in the House of Lords'
			: 'my MP, ' . $mp_name;
		$share_text   = rawurlencode( sprintf( 'I just wrote to %s about an issue I care about.', $share_label ) );
		$share_url    = rawurlencode( home_url( '/' ) );
		$twitter_url  = 'https://twitter.com/intent/tweet?text=' . $share_text . '&url=' . $share_url;
		$facebook_url = 'https://www.facebook.com/sharer/sharer.php?u=' . $share_url;
		$email_subject = rawurlencode( sprintf( 'I wrote to %s', $share_label ) );
		$email_body_parts = $is_lords
			? sprintf( "I just wrote to %s about an issue I care about. You can too: %s", $share_label, home_url( '/' ) )
			: sprintf( "I just wrote to my MP, %s (%s), about an issue I care about. You can write to yours too: %s", $mp_name, $mp_constituency, home_url( '/' ) );
		$email_body    = rawurlencode( $email_body_parts );
		$email_url     = 'mailto:?subject=' . $email_subject . '&body=' . $email_body;

		status_header( 200 );
		nocache_headers();

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'Message sent', 'sendtomp' ); ?> &mdash; <?php echo esc_html( $site_name ); ?></title>
	<?php wp_head(); ?>
	<style>
		body.sendtomp-thankyou {
			margin: 0;
			padding: 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			line-height: 1.6;
		}
		.sendtomp-wrap {
			max-width: 640px;
			margin: 40px auto;
			padding: 0 20px;
		}
		.sendtomp-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 32px;
			margin-bottom: 20px;
			text-align: center;
		}
		.sendtomp-card .checkmark {
			font-size: 3em;
			color: #00a32a;
			margin-bottom: 12px;
		}
		.sendtomp-card h1 {
			font-size: 1.5em;
			margin: 0 0 12px;
			color: #1d2327;
		}
		.sendtomp-card p {
			color: #646970;
			margin: 0 0 24px;
			font-size: 1em;
		}
		.sendtomp-share {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #e0e0e0;
		}
		.sendtomp-share h3 {
			font-size: 0.95em;
			color: #646970;
			margin: 0 0 12px;
		}
		.sendtomp-share-links {
			display: flex;
			justify-content: center;
			gap: 12px;
			flex-wrap: wrap;
		}
		.sendtomp-share-links a {
			display: inline-block;
			padding: 8px 20px;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			text-decoration: none;
			color: #2271b1;
			font-size: 0.9em;
			font-weight: 500;
		}
		.sendtomp-share-links a:hover {
			background: #f6f7f7;
			border-color: #2271b1;
		}
		.sendtomp-back {
			margin-top: 20px;
		}
		.sendtomp-back a {
			color: #2271b1;
			text-decoration: none;
			font-size: 0.9em;
		}
		.sendtomp-back a:hover {
			text-decoration: underline;
		}
		.sendtomp-footer {
			text-align: center;
			font-size: 0.8em;
			color: #a7aaad;
			padding: 10px 0 30px;
		}
		.sendtomp-footer a {
			color: #a7aaad;
			text-decoration: none;
		}
		.sendtomp-footer a:hover {
			color: #646970;
		}
	</style>
</head>
<body class="sendtomp-thankyou">
	<div class="sendtomp-wrap">
		<div class="sendtomp-card">
			<div class="checkmark">&#10003;</div>
			<h1><?php esc_html_e( 'Your message has been sent', 'sendtomp' ); ?></h1>
			<p>
				Your message has been sent to <?php echo esc_html( $mp_name ); ?><?php echo $mp_constituency ? ' (' . esc_html( $mp_constituency ) . ')' : ''; ?>.
			</p>

			<div class="sendtomp-share">
				<h3><?php esc_html_e( 'Spread the word', 'sendtomp' ); ?></h3>
				<div class="sendtomp-share-links">
					<a href="<?php echo esc_url( $twitter_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Share on X', 'sendtomp' ); ?></a>
					<a href="<?php echo esc_url( $facebook_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Share on Facebook', 'sendtomp' ); ?></a>
					<a href="<?php echo esc_url( $email_url ); ?>"><?php esc_html_e( 'Share via Email', 'sendtomp' ); ?></a>
				</div>
			</div>

			<div class="sendtomp-back">
				<a href="<?php echo esc_url( $site_url ); ?>">&larr; <?php echo esc_html( sprintf( __( 'Back to %s', 'sendtomp' ), $site_name ) ); ?></a>
			</div>
		</div>

		<?php if ( $show_branding ) : ?>
			<div class="sendtomp-footer">
				<?php
				printf(
					/* translators: %s: link to SendToMP website */
					esc_html__( 'Powered by %s', 'sendtomp' ),
					'<a href="https://www.bluetorch.co.uk/sendtomp" target="_blank" rel="noopener">Bluetorch\'s SendToMP</a>'
				);
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Render a simple error page.
	 *
	 * @param string $message The error message to display.
	 * @return void
	 */
	public function render_error_page( string $message, int $status_code = 400 ): void {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( home_url( '/' ) );

		status_header( $status_code );
		nocache_headers();

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'Confirmation', 'sendtomp' ); ?> &mdash; <?php echo esc_html( $site_name ); ?></title>
	<?php wp_head(); ?>
	<style>
		body.sendtomp-error {
			margin: 0;
			padding: 0;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			line-height: 1.6;
		}
		.sendtomp-wrap {
			max-width: 640px;
			margin: 40px auto;
			padding: 0 20px;
		}
		.sendtomp-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 32px;
			text-align: center;
		}
		.sendtomp-card .icon {
			font-size: 3em;
			color: #d63638;
			margin-bottom: 12px;
		}
		.sendtomp-card h1 {
			font-size: 1.3em;
			margin: 0 0 12px;
			color: #1d2327;
		}
		.sendtomp-card p {
			color: #646970;
			margin: 0 0 24px;
		}
		.sendtomp-card a {
			color: #2271b1;
			text-decoration: none;
		}
		.sendtomp-card a:hover {
			text-decoration: underline;
		}
	</style>
</head>
<body class="sendtomp-error">
	<div class="sendtomp-wrap">
		<div class="sendtomp-card">
			<div class="icon">&#10007;</div>
			<h1><?php esc_html_e( 'Unable to confirm', 'sendtomp' ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
			<p><a href="<?php echo esc_url( $site_url ); ?>">&larr; <?php echo esc_html( sprintf( __( 'Back to %s', 'sendtomp' ), $site_name ) ); ?></a></p>
		</div>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
