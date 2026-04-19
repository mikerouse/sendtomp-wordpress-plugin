<?php if (!defined('ABSPATH')) exit; ?>

<?php
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
$tabs = array(
	'general'      => 'General',
	'email'        => 'Email',
	'confirmation' => 'Confirmation',
	'rate-limits'  => 'Rate Limits',
	'overrides'    => 'Address Overrides',
	'webhook'      => 'Webhook API',
	'license'      => 'License',
	'log'          => 'Log',
);
?>

<div class="wrap">
	<h1>SendToMP <span class="sendtomp-version">v<?php echo esc_html( SENDTOMP_VERSION ); ?></span></h1>
	<p>Send verified constituent messages to UK Members of Parliament. Built by a former parliamentary assistant.</p>

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
						<div class="stat-label">Total Sent</div>
					</div>
					<div class="sendtomp-stat-card">
						<div class="stat-value"><?php echo esc_html($total_confirmed); ?></div>
						<div class="stat-label">Confirmed</div>
					</div>
					<div class="sendtomp-stat-card">
						<div class="stat-value"><?php echo esc_html(number_format($confirmation_rate, 1)); ?>%</div>
						<div class="stat-label">Confirmation Rate</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($current_tab === 'overrides') : ?>
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
				<h3><?php esc_html_e( 'SMTP Required', 'sendtomp' ); ?></h3>
				<p><?php esc_html_e( 'SendToMP requires a transactional email service (Brevo, SendGrid, Postmark, etc.) configured via an SMTP plugin.', 'sendtomp' ); ?></p>
				<?php
				$smtp_plugin = ( new SendToMP_Mailer() )->detect_smtp_plugin();
				if ( $smtp_plugin ) {
					echo '<p style="color: #00a32a;">&#10003; ' . esc_html( sprintf( __( 'Detected: %s', 'sendtomp' ), $smtp_plugin ) ) . '</p>';
				} else {
					echo '<p style="color: #d63638;">&#10007; ' . esc_html__( 'No SMTP plugin detected. Email delivery may be unreliable.', 'sendtomp' ) . '</p>';
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
