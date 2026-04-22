<?php
/**
 * SendToMP_Provider_Interface — contract for email-delivery providers.
 *
 * A provider is anything that can send a single transactional email on
 * behalf of SendToMP: Brevo (HTTP API), custom SMTP (PHPMailer), the
 * default `wp_mail()` passthrough, and later the Pro-tier Google
 * Workspace / Office 365 OAuth providers.
 *
 * Providers are instantiated by the mailer dispatcher based on the
 * `smtp_provider` setting. `boot()` runs early in the plugin lifecycle
 * so providers that need to hook WordPress actions (phpmailer_init,
 * for Custom SMTP) have a chance to do so before wp_mail() runs.
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SendToMP_Provider_Interface
 *
 * Message array shape passed to send():
 *   [
 *     'to'       => string|array      // "x@y" or ['email' => ..., 'name' => ...]
 *     'subject'  => string,
 *     'text'     => string,           // required plain-text body
 *     'html'     => string|null,      // optional HTML body
 *     'from'     => ['email' => ..., 'name' => ...],
 *     'reply_to' => ['email' => ..., 'name' => ...] | null,
 *     'cc'       => array,            // list of address arrays
 *     'bcc'      => array,            // list of address arrays
 *   ]
 */
interface SendToMP_Provider_Interface {

	/**
	 * Short stable identifier for this provider (e.g. "brevo",
	 * "smtp_custom", "wp_mail"). Matches the value stored in the
	 * `smtp_provider` setting.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Return true when this provider has enough configuration to
	 * attempt a send. Used by the Email Delivery UI to badge tiles
	 * as "ready" vs "needs setup", and by the dispatcher to fall
	 * back when the chosen provider is not ready.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Register any WordPress hooks this provider needs. Called once
	 * during plugin init, only for the provider currently selected
	 * in settings. Providers that don't need hooks (Brevo, wp_mail
	 * passthrough) can leave this empty.
	 *
	 * @return void
	 */
	public function boot(): void;

	/**
	 * Send a single email.
	 *
	 * @param array $message See interface docblock for shape.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function send( array $message );
}
