<?php
/**
 * Email Delivery admin tab.
 *
 * Tile-based provider picker + per-provider config panels. Preserves
 * the existing Brevo Partner Programme managed-service offering as
 * a section below the picker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped variables used only in this included view.

$delivery_mailer = new SendToMP_Mailer();
$smtp_plugin     = $delivery_mailer->detect_smtp_plugin();
$tier            = SendToMP_License::get_tier();
$is_pro          = SendToMP_License::TIER_PRO === $tier;

$settings      = sendtomp()->get_settings();
$default_prov  = $smtp_plugin ? 'smtp_plugin' : 'wp_mail';
$current_prov  = isset( $settings['smtp_provider'] ) ? (string) $settings['smtp_provider'] : $default_prov;

$is_delivery_ok = $delivery_mailer->is_delivery_configured();
$delivery_label = $delivery_mailer->get_delivery_label();
$active_prov_id = $delivery_mailer->get_provider()->get_id();

// If the user picked smtp_plugin but none is detected, the tile for it
// won't render, so the selection falls back to wp_mail visually.
if ( 'smtp_plugin' === $current_prov && ! $smtp_plugin ) {
	$current_prov = 'wp_mail';
}

$has_brevo_key  = ! empty( $settings['brevo_api_key'] );
$has_smtp_pass  = ! empty( $settings['smtp_password'] );

$provider_tile_img_url = SENDTOMP_PLUGIN_URL . 'assets/images/providers/';

/**
 * Render one tile in the provider grid.
 *
 * @param array $tile {
 *   @type string $slug        Provider id value ("brevo", "smtp_custom", etc.).
 *   @type string $name        Display name.
 *   @type string $subtitle    One-line description.
 *   @type string $badge       Optional corner badge ("Recommended", "Pro", "Detected").
 *   @type string $badge_kind  "success" | "info" | "pro" | "" — styles the badge.
 *   @type string $logo        Path or filename under assets/images/providers/, or empty for a text fallback.
 *   @type bool   $disabled    When true, tile cannot be selected (Pro-only in v1.6).
 * }
 */
$render_tile = function ( array $tile ) use ( $current_prov, $provider_tile_img_url, $is_delivery_ok, $active_prov_id, $smtp_plugin ) {
	$is_checked = ( $current_prov === $tile['slug'] && empty( $tile['disabled'] ) );
	$classes    = [ 'sendtomp-provider-tile' ];
	if ( $is_checked ) {
		$classes[] = 'is-selected';
	}
	if ( ! empty( $tile['disabled'] ) ) {
		$classes[] = 'is-disabled';
	}

	// Success state: this tile is the one actually doing the delivery.
	// For smtp_plugin, is-active is true when the tile is selected AND a
	// plugin was detected (the tile only renders in that case anyway).
	$is_active = $is_checked && $is_delivery_ok && ( $active_prov_id === $tile['slug'] || ( 'smtp_plugin' === $tile['slug'] && $smtp_plugin ) );
	if ( $is_active ) {
		$classes[] = 'is-active';
	}

	$logo_url = ! empty( $tile['logo'] )
		? $provider_tile_img_url . $tile['logo']
		: '';

	?>
	<label class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<input
			type="radio"
			name="sendtomp_settings[smtp_provider]"
			value="<?php echo esc_attr( $tile['slug'] ); ?>"
			<?php checked( $is_checked ); ?>
			<?php disabled( ! empty( $tile['disabled'] ) ); ?>
			class="sendtomp-provider-radio"
		/>

		<?php if ( ! empty( $tile['badge'] ) ) : ?>
			<span class="sendtomp-provider-badge sendtomp-provider-badge--<?php echo esc_attr( $tile['badge_kind'] ?: 'info' ); ?>">
				<?php echo esc_html( $tile['badge'] ); ?>
			</span>
		<?php endif; ?>

		<div class="sendtomp-provider-tile-logo">
			<?php if ( $logo_url ) : ?>
				<img
					src="<?php echo esc_url( $logo_url ); ?>"
					alt="<?php echo esc_attr( $tile['name'] ); ?>"
					onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
				/>
				<span class="sendtomp-provider-tile-logo-fallback" aria-hidden="true"><?php echo esc_html( $tile['name'] ); ?></span>
			<?php else : ?>
				<span class="sendtomp-provider-tile-logo-fallback"><?php echo esc_html( $tile['name'] ); ?></span>
			<?php endif; ?>
		</div>

		<div class="sendtomp-provider-tile-meta">
			<span class="sendtomp-provider-tile-name"><?php echo esc_html( $tile['name'] ); ?></span>
			<?php if ( ! empty( $tile['subtitle'] ) ) : ?>
				<span class="sendtomp-provider-tile-subtitle"><?php echo esc_html( $tile['subtitle'] ); ?></span>
			<?php endif; ?>
		</div>
	</label>
	<?php
};

// Build the tile list. Detected-plugin tile is dynamic based on what's active.
$tiles = [];

if ( $smtp_plugin ) {
	$detected_slug_map = [
		'WP Mail SMTP' => 'wp-mail-smtp',
		'FluentSMTP'   => 'fluent-smtp',
		'Post SMTP'    => 'post-smtp',
		'Easy WP SMTP' => 'easy-wp-smtp',
	];
	$tile_slug = $detected_slug_map[ $smtp_plugin ] ?? strtolower( str_replace( ' ', '-', $smtp_plugin ) );
	$tiles[]   = [
		'slug'       => 'smtp_plugin',
		'name'       => $smtp_plugin,
		'subtitle'   => __( 'Active SMTP plugin handles delivery.', 'sendtomp' ),
		'badge'      => __( 'Detected', 'sendtomp' ),
		'badge_kind' => 'success',
		'logo'       => $tile_slug . '.svg',
		'disabled'   => false,
	];
}

$tiles[] = [
	'slug'       => 'brevo',
	'name'       => __( 'Brevo', 'sendtomp' ),
	'subtitle'   => __( 'Direct API — parliamentary-grade deliverability.', 'sendtomp' ),
	'badge'      => __( 'Recommended', 'sendtomp' ),
	'badge_kind' => 'info',
	'logo'       => 'brevo.svg',
	'disabled'   => false,
];

$tiles[] = [
	'slug'       => 'smtp_custom',
	'name'       => __( 'Custom SMTP', 'sendtomp' ),
	'subtitle'   => __( 'Bring your own host, port, and credentials.', 'sendtomp' ),
	'badge'      => '',
	'logo'       => 'custom-smtp.svg',
	'disabled'   => false,
];

$tiles[] = [
	'slug'       => 'google_workspace',
	'name'       => __( 'Google Workspace', 'sendtomp' ),
	'subtitle'   => __( 'Send via Gmail — coming in v1.7.', 'sendtomp' ),
	'badge'      => __( 'Pro', 'sendtomp' ),
	'badge_kind' => 'pro',
	'logo'       => 'google-workspace.svg',
	'disabled'   => true,
];

$tiles[] = [
	'slug'       => 'office365',
	'name'       => __( 'Office 365 / Outlook', 'sendtomp' ),
	'subtitle'   => __( 'Send via Microsoft 365 — coming in v1.8.', 'sendtomp' ),
	'badge'      => __( 'Pro', 'sendtomp' ),
	'badge_kind' => 'pro',
	'logo'       => 'office365.svg',
	'disabled'   => true,
];

$tiles[] = [
	'slug'       => 'wp_mail',
	'name'       => __( 'Default (wp_mail)', 'sendtomp' ),
	'subtitle'   => __( 'Not recommended — unreliable for MP inboxes.', 'sendtomp' ),
	'badge'      => '',
	'logo'       => 'wp-mail.svg',
	'disabled'   => false,
];
?>

<h2 class="sendtomp-page-subtitle"><?php esc_html_e( 'Email Delivery', 'sendtomp' ); ?></h2>
<p><?php esc_html_e( 'Choose how SendToMP sends transactional emails — confirmation links to visitors and the verified message to the MP.', 'sendtomp' ); ?></p>

<?php if ( $is_delivery_ok ) : ?>
	<div class="sendtomp-delivery-status sendtomp-delivery-status--ok">
		<span class="sendtomp-delivery-status-icon" aria-hidden="true">&#10003;</span>
		<strong><?php esc_html_e( 'Email delivery is configured.', 'sendtomp' ); ?></strong>
		<?php
		/* translators: %s: active provider label (e.g. "Brevo", "WP Mail SMTP"). */
		echo ' ' . esc_html( sprintf( __( 'Sending via %s.', 'sendtomp' ), $delivery_label ) );
		?>
	</div>
<?php endif; ?>

<form method="post" action="options.php" class="sendtomp-delivery-form">
	<?php settings_fields( 'sendtomp_settings_group' ); ?>

	<div class="sendtomp-provider-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Email delivery provider', 'sendtomp' ); ?>">
		<?php foreach ( $tiles as $tile ) { $render_tile( $tile ); } ?>
	</div>

	<!-- Per-provider config panels. Visibility is toggled by JS based on the selected tile. -->

	<?php if ( $smtp_plugin ) : ?>
		<div class="sendtomp-provider-panel" data-provider="smtp_plugin" <?php echo 'smtp_plugin' === $current_prov ? '' : 'hidden'; ?>>
			<h3><?php
			/* translators: %s: name of the detected SMTP plugin. */
			echo esc_html( sprintf( __( '%s is handling delivery', 'sendtomp' ), $smtp_plugin ) );
			?></h3>
			<p><?php
			/* translators: %s: name of the detected SMTP plugin. */
			echo esc_html( sprintf( __( '%s is active on this site and SendToMP will defer to it. No additional configuration is needed here — manage your SMTP credentials inside the plugin itself.', 'sendtomp' ), $smtp_plugin ) );
			?></p>
		</div>
	<?php endif; ?>

	<div class="sendtomp-provider-panel" data-provider="brevo" <?php echo 'brevo' === $current_prov ? '' : 'hidden'; ?>>
		<h3><?php esc_html_e( 'Send via Brevo', 'sendtomp' ); ?></h3>

		<div class="sendtomp-why-brevo">
			<h4><?php esc_html_e( 'Why we recommend Brevo', 'sendtomp' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Parliament email gateways filter aggressively — Brevo has the transactional reputation to get through.', 'sendtomp' ); ?></li>
				<li><?php esc_html_e( 'Built-in bounce handling, suppression lists, and deliverability dashboards — things SMTP alone can\'t give you.', 'sendtomp' ); ?></li>
				<li><?php esc_html_e( 'Direct API integration is faster and more observable than SMTP, and every send shows up in your Brevo dashboard.', 'sendtomp' ); ?></li>
				<li><?php esc_html_e( 'Free tier covers small campaigns; paid tiers are cheaper and more reliable than most alternatives we\'ve tested for parliamentary correspondence.', 'sendtomp' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Don\'t have a Brevo account yet? Bluetorch offers a managed setup below — or sign up at brevo.com and paste your API key here.', 'sendtomp' ); ?></p>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sendtomp-brevo-api-key"><?php esc_html_e( 'Brevo API key', 'sendtomp' ); ?></label></th>
				<td>
					<input
						type="password"
						id="sendtomp-brevo-api-key"
						name="sendtomp_settings[brevo_api_key]"
						value=""
						class="regular-text"
						autocomplete="off"
						placeholder="<?php echo $has_brevo_key ? esc_attr( str_repeat( '•', 20 ) ) : esc_attr__( 'xkeysib-…', 'sendtomp' ); ?>"
					/>
					<?php if ( $has_brevo_key ) : ?>
						<p class="description"><?php esc_html_e( 'An API key is stored (encrypted). Leave this field blank to keep using it, or paste a new key to replace it.', 'sendtomp' ); ?></p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Create a key at Brevo → SMTP & API → API Keys. Only v3 keys starting with "xkeysib-" work.', 'sendtomp' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</div>

	<div class="sendtomp-provider-panel" data-provider="smtp_custom" <?php echo 'smtp_custom' === $current_prov ? '' : 'hidden'; ?>>
		<h3><?php esc_html_e( 'Custom SMTP', 'sendtomp' ); ?></h3>
		<p><?php esc_html_e( 'Use your own SMTP server. SendToMP reconfigures WordPress mail delivery with these credentials.', 'sendtomp' ); ?></p>

		<?php if ( $smtp_plugin ) : ?>
			<div class="notice notice-info inline">
				<p>
				<?php
				/* translators: %s: name of the detected SMTP plugin. */
				echo esc_html( sprintf( __( 'Note: %s is also active. When both are configured, we defer to the dedicated SMTP plugin to avoid conflicts.', 'sendtomp' ), $smtp_plugin ) );
				?>
				</p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sendtomp-smtp-host"><?php esc_html_e( 'Host', 'sendtomp' ); ?></label></th>
				<td>
					<input
						type="text"
						id="sendtomp-smtp-host"
						name="sendtomp_settings[smtp_host]"
						value="<?php echo esc_attr( $settings['smtp_host'] ?? '' ); ?>"
						class="regular-text"
						placeholder="smtp.example.com"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-smtp-port"><?php esc_html_e( 'Port', 'sendtomp' ); ?></label></th>
				<td>
					<input
						type="number"
						id="sendtomp-smtp-port"
						name="sendtomp_settings[smtp_port]"
						value="<?php echo esc_attr( $settings['smtp_port'] ?? 587 ); ?>"
						min="1"
						max="65535"
						class="small-text"
					/>
					<span class="description"><?php esc_html_e( 'Common: 587 (TLS), 465 (SSL), 25 (unencrypted).', 'sendtomp' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Encryption', 'sendtomp' ); ?></th>
				<td>
					<?php $enc = $settings['smtp_encryption'] ?? 'tls'; ?>
					<label><input type="radio" name="sendtomp_settings[smtp_encryption]" value="tls" <?php checked( $enc, 'tls' ); ?>/> TLS</label>
					&nbsp;
					<label><input type="radio" name="sendtomp_settings[smtp_encryption]" value="ssl" <?php checked( $enc, 'ssl' ); ?>/> SSL</label>
					&nbsp;
					<label><input type="radio" name="sendtomp_settings[smtp_encryption]" value="none" <?php checked( $enc, 'none' ); ?>/> <?php esc_html_e( 'None', 'sendtomp' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'sendtomp' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="sendtomp_settings[smtp_auth]"
							value="1"
							<?php checked( ! empty( $settings['smtp_auth'] ) ); ?>
						/>
						<?php esc_html_e( 'This server requires authentication', 'sendtomp' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-smtp-username"><?php esc_html_e( 'Username', 'sendtomp' ); ?></label></th>
				<td>
					<input
						type="text"
						id="sendtomp-smtp-username"
						name="sendtomp_settings[smtp_username]"
						value="<?php echo esc_attr( $settings['smtp_username'] ?? '' ); ?>"
						class="regular-text"
						autocomplete="off"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-smtp-password"><?php esc_html_e( 'Password', 'sendtomp' ); ?></label></th>
				<td>
					<input
						type="password"
						id="sendtomp-smtp-password"
						name="sendtomp_settings[smtp_password]"
						value=""
						class="regular-text"
						autocomplete="new-password"
						placeholder="<?php echo $has_smtp_pass ? esc_attr( str_repeat( '•', 20 ) ) : ''; ?>"
					/>
					<?php if ( $has_smtp_pass ) : ?>
						<p class="description"><?php esc_html_e( 'A password is stored (encrypted). Leave blank to keep it, or type a new one to replace.', 'sendtomp' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</div>

	<div class="sendtomp-provider-panel" data-provider="wp_mail" <?php echo 'wp_mail' === $current_prov ? '' : 'hidden'; ?>>
		<h3><?php esc_html_e( 'WordPress default (wp_mail)', 'sendtomp' ); ?></h3>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Not recommended.', 'sendtomp' ); ?></strong>
				<?php esc_html_e( 'WordPress\'s built-in mailer uses PHP\'s sendmail, which is unreliable for parliamentary inboxes. Choose Brevo or Custom SMTP instead.', 'sendtomp' ); ?>
			</p>
		</div>
	</div>

	<div class="sendtomp-provider-panel" data-provider="google_workspace" hidden>
		<h3><?php esc_html_e( 'Google Workspace', 'sendtomp' ); ?></h3>
		<p><?php esc_html_e( 'OAuth-based Google Workspace sending lands in the v1.7 release — a Pro-tier feature. One-click Connect to Google; no App passwords or credentials to paste.', 'sendtomp' ); ?></p>
	</div>

	<div class="sendtomp-provider-panel" data-provider="office365" hidden>
		<h3><?php esc_html_e( 'Office 365 / Outlook', 'sendtomp' ); ?></h3>
		<p><?php esc_html_e( 'OAuth-based Office 365 sending lands in the v1.8 release — a Pro-tier feature. Works with Microsoft 365 tenants via Microsoft Graph.', 'sendtomp' ); ?></p>
	</div>

	<?php submit_button( __( 'Save Email Delivery', 'sendtomp' ) ); ?>
</form>

<hr style="margin: 32px 0;" />

<!-- Brevo Partner Programme — preserved from pre-1.6 delivery tab -->
<div class="sendtomp-brevo-partner">
	<div style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
		<div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
			<div>
				<h3 style="margin-top: 0; margin-bottom: 8px;"><?php esc_html_e( 'Brevo Email Setup Service', 'sendtomp' ); ?></h3>
				<p style="font-size: 1.05em; color: #1d2327; margin: 0;">
					<?php esc_html_e( 'Let Bluetorch set up and configure Brevo for your campaigns — ensuring your messages reach MPs reliably.', 'sendtomp' ); ?>
				</p>
			</div>
		</div>

		<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
			<div style="background: #f6f7f7; border-radius: 6px; padding: 16px;">
				<h4 style="margin: 0 0 8px; color: #1d2327;"><?php esc_html_e( 'New to Brevo?', 'sendtomp' ); ?></h4>
				<p style="font-size: 0.9em; color: #646970; margin: 0;">
					<?php esc_html_e( 'We\'ll create your Brevo account, configure SPF/DKIM/DMARC for your domain, wire up your WordPress SMTP, and verify that emails reach parliamentary addresses.', 'sendtomp' ); ?>
				</p>
			</div>
			<div style="background: #f6f7f7; border-radius: 6px; padding: 16px;">
				<h4 style="margin: 0 0 8px; color: #1d2327;"><?php esc_html_e( 'Already using Brevo?', 'sendtomp' ); ?></h4>
				<p style="font-size: 0.9em; color: #646970; margin: 0;">
					<?php esc_html_e( 'We\'ll review your setup, optimise deliverability for parliamentary email gateways, and ensure your domain authentication is correctly configured for MP correspondence.', 'sendtomp' ); ?>
				</p>
			</div>
		</div>

		<div style="background: <?php echo $is_pro ? '#f0faf0' : '#f0f6fc'; ?>; border: 1px solid <?php echo $is_pro ? '#00a32a' : '#72aee6'; ?>; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
			<?php if ( $is_pro ) : ?>
				<p style="margin: 0; font-size: 1em;">
					<strong style="color: #00a32a;">&#10003; <?php esc_html_e( 'Included with your Pro plan', 'sendtomp' ); ?></strong>
					&mdash; <?php esc_html_e( 'Brevo setup and configuration is included at no extra cost for Pro subscribers.', 'sendtomp' ); ?>
				</p>
			<?php else : ?>
				<p style="margin: 0; font-size: 1em;">
					<strong><?php esc_html_e( 'One-off setup fee: £150', 'sendtomp' ); ?></strong>
					&mdash; <?php esc_html_e( 'Covers account configuration, domain authentication, SMTP integration, and delivery verification. No VAT. Pro subscribers get this service free.', 'sendtomp' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<h4 style="margin-bottom: 12px;"><?php esc_html_e( 'Request Brevo Setup', 'sendtomp' ); ?></h4>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sendtomp-brevo-first-name"><?php esc_html_e( 'First Name', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
				<td><input type="text" id="sendtomp-brevo-first-name" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-brevo-last-name"><?php esc_html_e( 'Last Name', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
				<td><input type="text" id="sendtomp-brevo-last-name" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-brevo-email"><?php esc_html_e( 'Email Address', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
				<td><input type="email" id="sendtomp-brevo-email" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-brevo-company"><?php esc_html_e( 'Company / Organisation', 'sendtomp' ); ?></label></th>
				<td><input type="text" id="sendtomp-brevo-company" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sendtomp-brevo-website"><?php esc_html_e( 'Website Address', 'sendtomp' ); ?></label></th>
				<td><input type="url" id="sendtomp-brevo-website" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></th>
				<td>
					<label>
						<input type="checkbox" id="sendtomp-brevo-consent" />
						<?php
						if ( $is_pro ) {
							esc_html_e( 'I agree that Bluetorch Consulting Ltd may contact me to set up and configure Brevo for my SendToMP campaigns. My details will be shared with Brevo to establish a partner relationship.', 'sendtomp' );
						} else {
							esc_html_e( 'I agree that Bluetorch Consulting Ltd may contact me to set up and configure Brevo for my SendToMP campaigns. I understand the one-off setup fee of £150 will be invoiced separately. My details will be shared with Brevo to establish a partner relationship.', 'sendtomp' );
						}
						?>
					</label>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" id="sendtomp-brevo-submit" class="button button-primary">
				<?php esc_html_e( 'Submit Enquiry', 'sendtomp' ); ?>
			</button>
			<span id="sendtomp-brevo-result" style="margin-left: 10px;"></span>
		</p>

		<p class="description">
			<?php esc_html_e( 'The Brevo setup service is scoped to email delivery for your SendToMP campaigns (messages to MPs and/or Lords). Bluetorch Consulting Ltd will contact you to arrange setup.', 'sendtomp' ); ?>
		</p>
	</div>
</div>
