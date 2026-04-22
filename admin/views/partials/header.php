<?php
/**
 * Shared admin page header — logo, version chip, tagline.
 *
 * Included at the top of every SendToMP admin view, inside the
 * `.wrap` div. The `<h1>` element is preserved (with the logo as its
 * content) so WordPress still knows where to place admin notices,
 * which WP injects immediately after the first `h1` in `.wrap`.
 *
 * Optional variables the caller can set before including:
 *   $sendtomp_header_tagline (string) — replaces the default tagline
 *   $sendtomp_header_hide_tagline (bool) — when true, suppresses the tagline
 *
 * @package SendToMP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sendtomp_header_tagline = isset( $sendtomp_header_tagline )
	? (string) $sendtomp_header_tagline
	: __( 'Send verified constituent messages to UK Members of Parliament. Built by a former parliamentary assistant.', 'sendtomp' );

$sendtomp_header_hide_tagline = isset( $sendtomp_header_hide_tagline )
	? (bool) $sendtomp_header_hide_tagline
	: false;
?>
<h1 class="sendtomp-admin-header">
	<img
		src="<?php echo esc_url( SENDTOMP_PLUGIN_URL . 'assets/icon-2000x1000.png' ); ?>"
		alt="<?php esc_attr_e( 'SendToMP', 'sendtomp' ); ?>"
		class="sendtomp-admin-logo"
		width="128"
		height="64"
	/>
	<span class="sendtomp-admin-header-version">v<?php echo esc_html( SENDTOMP_VERSION ); ?></span>
</h1>

<?php if ( ! $sendtomp_header_hide_tagline ) : ?>
	<p class="sendtomp-admin-header-tagline"><?php echo esc_html( $sendtomp_header_tagline ); ?></p>
<?php endif; ?>
