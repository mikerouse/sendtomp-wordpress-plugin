<?php if (!defined('ABSPATH')) exit; ?>

<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped variables used only in this included view.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter parameter, no state change.
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'status';
$tabs = array(
	'status'       => __( 'Status', 'sendtomp' ),
	'general'      => __( 'General', 'sendtomp' ),
	'email'        => __( 'Email', 'sendtomp' ),
	'delivery'     => __( 'Email Delivery', 'sendtomp' ),
	'confirmation' => __( 'Confirmation', 'sendtomp' ),
	'rate-limits'  => __( 'Rate Limits', 'sendtomp' ),
	'overrides'    => __( 'Address Overrides', 'sendtomp' ),
	'webhook'      => __( 'Webhook API', 'sendtomp' ),
	'license'      => __( 'License', 'sendtomp' ),
	'log'          => __( 'Log', 'sendtomp' ),
);
?>

<div class="wrap">
	<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/partials/header.php'; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ($tabs as $slug => $label) : ?>
			<a href="<?php echo esc_url(admin_url('admin.php?page=sendtomp&tab=' . $slug)); ?>"
			   class="nav-tab <?php echo ($current_tab === $slug) ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html($label); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="sendtomp-settings-wrap">
		<div class="sendtomp-settings-main">

			<?php if ($current_tab === 'general') : ?>
				<?php
				$stats = SendToMP_Logger::get_stats();
				$total_sent       = isset($stats['total_sent']) ? intval($stats['total_sent']) : 0;
				$total_confirmed  = isset($stats['total_confirmed']) ? intval($stats['total_confirmed']) : 0;
				$confirmation_rate = isset($stats['confirmation_rate']) ? floatval($stats['confirmation_rate']) : 0;
				?>
				<div class="sendtomp-stats">
					<div class="sendtomp-stat-card">
						<div class="stat-value"><?php echo esc_html($total_sent); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Sent', 'sendtomp' ); ?></div>
					</div>
					<div class="sendtomp-stat-card">
						<div class="stat-value"><?php echo esc_html($total_confirmed); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Confirmed', 'sendtomp' ); ?></div>
					</div>
					<div class="sendtomp-stat-card">
						<div class="stat-value"><?php echo esc_html(number_format($confirmation_rate, 1)); ?>%</div>
						<div class="stat-label"><?php esc_html_e( 'Confirmation Rate', 'sendtomp' ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($current_tab === 'status') : ?>
				<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/status-page.php'; ?>
			<?php elseif ($current_tab === 'delivery') : ?>
				<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/delivery-page.php'; ?>
			<?php elseif ($current_tab === 'overrides') : ?>
				<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/overrides-page.php'; ?>
			<?php elseif ($current_tab === 'log') : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields('sendtomp_settings_group');
					do_settings_sections('sendtomp');
					submit_button();
					?>
				</form>
				<hr />
				<?php include SENDTOMP_PLUGIN_DIR . 'admin/views/logs-page.php'; ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields('sendtomp_settings_group');
					do_settings_sections('sendtomp');
					submit_button();
					?>
				</form>
			<?php endif; ?>

		</div>

		<div class="sendtomp-settings-aside">
			<div class="sendtomp-sidebar">
				<h3><?php esc_html_e( 'Need Help?', 'sendtomp' ); ?></h3>
				<p><?php esc_html_e( 'Check the documentation and FAQs for setup guides and troubleshooting.', 'sendtomp' ); ?></p>
				<p><a href="https://bluetorch.co.uk/sendtomp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit bluetorch.co.uk/sendtomp', 'sendtomp' ); ?> &rarr;</a></p>
			</div>

			<?php if ( ! sendtomp()->can( 'lords' ) ) : ?>
				<div class="sendtomp-sidebar" style="background: linear-gradient(135deg, #f0f6fc, #e8f0fe); border: 1px solid #72aee6;">
					<h3 style="color: #2271b1;"><?php esc_html_e( 'Upgrade to Plus', 'sendtomp' ); ?></h3>
					<p><?php esc_html_e( 'Unlock House of Lords, WPForms, CF7, BCC support, local overrides, and unlimited messages.', 'sendtomp' ); ?></p>
					<p><a href="https://bluetorch.co.uk/sendtomp#pricing" target="_blank" rel="noopener noreferrer" style="font-weight: 600; color: #2271b1;"><?php esc_html_e( 'View pricing', 'sendtomp' ); ?> &rarr;</a></p>
				</div>
			<?php elseif ( ! sendtomp()->can( 'webhook_api' ) ) : ?>
				<div class="sendtomp-sidebar" style="background: linear-gradient(135deg, #f0f6fc, #e8f0fe); border: 1px solid #72aee6;">
					<h3 style="color: #2271b1;"><?php esc_html_e( 'Upgrade to Pro', 'sendtomp' ); ?></h3>
					<p><?php esc_html_e( 'Get the REST API, white-label branding, CSV export, and up to 5 site activations.', 'sendtomp' ); ?></p>
					<p><a href="https://bluetorch.co.uk/sendtomp#pricing" target="_blank" rel="noopener noreferrer" style="font-weight: 600; color: #2271b1;"><?php esc_html_e( 'View pricing', 'sendtomp' ); ?> &rarr;</a></p>
				</div>
			<?php endif; ?>

			<div class="sendtomp-sidebar">
				<h3><?php esc_html_e( 'Email Delivery', 'sendtomp' ); ?></h3>
				<?php
				$sidebar_mailer    = new SendToMP_Mailer();
				$sidebar_delivery  = admin_url( 'admin.php?page=sendtomp&tab=delivery' );
				if ( $sidebar_mailer->is_delivery_configured() ) {
					$label = $sidebar_mailer->get_delivery_label();
					/* translators: %s: active provider label (e.g. "Brevo", "WP Mail SMTP"). */
					echo '<p style="color: #00a32a;">&#10003; ' . esc_html( sprintf( __( 'Configured via %s.', 'sendtomp' ), $label ) ) . '</p>';
					echo '<p><a href="' . esc_url( $sidebar_delivery ) . '">' . esc_html__( 'Manage Email Delivery', 'sendtomp' ) . ' &rarr;</a></p>';
				} else {
					echo '<p>' . esc_html__( 'Messages to MPs need a reliable transactional email service. SendToMP works with either a built-in provider (Brevo, Custom SMTP) configured on the Email Delivery tab, or a third-party SMTP plugin.', 'sendtomp' ) . '</p>';
					echo '<p style="color: #d63638;">&#10007; ' . esc_html__( 'Not configured yet.', 'sendtomp' ) . '</p>';
					echo '<p><a href="' . esc_url( $sidebar_delivery ) . '">' . esc_html__( 'Set up Email Delivery', 'sendtomp' ) . ' &rarr;</a></p>';
				}
				?>
			</div>

			<div class="sendtomp-sidebar">
				<h3><?php esc_html_e( 'Campaign Tips', 'sendtomp' ); ?></h3>
				<p><?php esc_html_e( 'Keep your message concise and personal. MPs respond better to genuine constituent correspondence that references local issues rather than generic template letters.', 'sendtomp' ); ?></p>
			</div>

			<div class="sendtomp-sidebar">
				<h3><?php esc_html_e( 'Templates', 'sendtomp' ); ?></h3>
				<p><?php esc_html_e( 'Browse starter templates for common campaign types.', 'sendtomp' ); ?></p>
				<p><a href="https://bluetorch.co.uk/sendtomp/templates" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View templates', 'sendtomp' ); ?> &rarr;</a></p>
			</div>
		</div>
	</div>
</div>
