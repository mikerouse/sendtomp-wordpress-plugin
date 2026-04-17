<?php if (!defined('ABSPATH')) exit; ?>

<?php
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
$tabs = array(
	'general'      => 'General',
	'email'        => 'Email',
	'confirmation' => 'Confirmation',
	'rate-limits'  => 'Rate Limits',
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

			<?php if ($current_tab === 'log') : ?>
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
				<h3>Need Help?</h3>
				<p>Check the documentation and FAQs for setup guides and troubleshooting.</p>
				<p><a href="https://bluetorch.co.uk/sendtomp" target="_blank" rel="noopener noreferrer">Visit bluetorch.co.uk/sendtomp &rarr;</a></p>
			</div>

			<div class="sendtomp-sidebar">
				<h3>Recommended: Brevo</h3>
				<p>For reliable email delivery, we recommend using Brevo (formerly Sendinblue) as your SMTP provider.</p>
				<p><a href="#" target="_blank" rel="noopener noreferrer">Learn more about Brevo &rarr;</a></p>
			</div>

			<div class="sendtomp-sidebar">
				<h3>Campaign Tips</h3>
				<p>Keep your message concise and personal. MPs respond better to genuine constituent correspondence that references local issues rather than generic template letters.</p>
			</div>
		</div>
	</div>
</div>
