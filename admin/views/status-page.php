<?php
/**
 * Status dashboard — environment overview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scoped variables used only in this included view.

$form_plugins        = SendToMP_Status::get_form_plugins();
$has_form            = SendToMP_Status::has_active_form_plugin();
$mailer              = new SendToMP_Mailer();
$smtp_plugin         = $mailer->detect_smtp_plugin();
$license_tier        = SendToMP_License::get_tier();
$license_remaining   = SendToMP_License::TIER_FREE === $license_tier ? SendToMP_License::get_remaining() : null;
$license_used        = SendToMP_License::TIER_FREE === $license_tier ? SendToMP_License::get_monthly_counter() : null;
$license_limit       = SendToMP_License::FREE_MONTHLY_LIMIT;
$log_stats           = SendToMP_Logger::get_stats();

$tier_labels = [
	SendToMP_License::TIER_FREE => __( 'Free plan', 'sendtomp' ),
	SendToMP_License::TIER_PLUS => __( 'Plus plan', 'sendtomp' ),
	SendToMP_License::TIER_PRO  => __( 'Pro plan', 'sendtomp' ),
];
$tier_label = $tier_labels[ $license_tier ] ?? __( 'Free plan', 'sendtomp' );
?>

<div class="sendtomp-status">

	<?php if ( ! $has_form ) : ?>
		<div class="sendtomp-status-banner sendtomp-banner-warning">
			<div class="sendtomp-banner-icon" aria-hidden="true">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="sendtomp-banner-body">
				<h2><?php esc_html_e( 'A form plugin is needed to start sending messages', 'sendtomp' ); ?></h2>
				<p><?php esc_html_e( 'SendToMP turns a standard WordPress form into a verified channel for contacting MPs. To begin, you need one of the supported form plugins installed and active. The status below shows what is present and what is missing.', 'sendtomp' ); ?></p>
			</div>
		</div>
	<?php endif; ?>

	<div class="sendtomp-status-grid">

		<section class="sendtomp-status-card">
			<header class="sendtomp-card-header">
				<h2><?php esc_html_e( 'Form plugins', 'sendtomp' ); ?></h2>
				<p class="sendtomp-card-subtitle"><?php esc_html_e( 'A supported form plugin is required. Gravity Forms works on the free plan; the others unlock with a paid plan.', 'sendtomp' ); ?></p>
			</header>

			<ul class="sendtomp-status-list">
				<?php foreach ( $form_plugins as $plugin ) : ?>
					<?php
					$state_class = 'state-' . $plugin['state'];
					$state_label = '';
					switch ( $plugin['state'] ) {
						case SendToMP_Status::STATE_ACTIVE:
							$state_label = __( 'Active', 'sendtomp' );
							break;
						case SendToMP_Status::STATE_INSTALLED:
							$state_label = __( 'Installed — not active', 'sendtomp' );
							break;
						case SendToMP_Status::STATE_NOT_INSTALLED:
							$state_label = __( 'Not installed', 'sendtomp' );
							break;
					}
					?>
					<li class="sendtomp-status-item <?php echo esc_attr( $state_class ); ?>">
						<div class="sendtomp-item-icon" aria-hidden="true">
							<?php if ( SendToMP_Status::STATE_ACTIVE === $plugin['state'] ) : ?>
								<span class="dashicons dashicons-yes-alt"></span>
							<?php elseif ( SendToMP_Status::STATE_INSTALLED === $plugin['state'] ) : ?>
								<span class="dashicons dashicons-clock"></span>
							<?php else : ?>
								<span class="dashicons dashicons-marker"></span>
							<?php endif; ?>
						</div>

						<div class="sendtomp-item-body">
							<div class="sendtomp-item-title">
								<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
								<span class="sendtomp-tier-badge sendtomp-tier-<?php echo esc_attr( $plugin['tier'] ); ?>">
									<?php echo esc_html( $plugin['tier_label'] ); ?>
								</span>
							</div>
							<div class="sendtomp-item-meta"><?php echo esc_html( $state_label ); ?></div>
							<p class="sendtomp-item-description"><?php echo esc_html( $plugin['description'] ); ?></p>
							<?php if ( ! empty( $plugin['pro_note'] ) ) : ?>
								<p class="sendtomp-item-footnote"><?php echo esc_html( $plugin['pro_note'] ); ?></p>
							<?php endif; ?>
						</div>

						<div class="sendtomp-item-actions">
							<?php if ( SendToMP_Status::STATE_ACTIVE === $plugin['state'] ) : ?>
								<span class="sendtomp-state-chip sendtomp-chip-ok"><?php esc_html_e( 'Ready', 'sendtomp' ); ?></span>
							<?php elseif ( SendToMP_Status::STATE_INSTALLED === $plugin['state'] && ! empty( $plugin['wp_org_slug'] ) ) : ?>
								<?php
								$file = SendToMP_Status::get_installed_plugin_file( $plugin['wp_org_slug'] );
								if ( $file ) :
									?>
									<a class="button button-primary" href="<?php echo esc_url( SendToMP_Status::get_activate_url( $file ) ); ?>">
										<?php esc_html_e( 'Activate', 'sendtomp' ); ?>
									</a>
								<?php endif; ?>
							<?php elseif ( ! empty( $plugin['wp_org'] ) && ! empty( $plugin['wp_org_slug'] ) ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( SendToMP_Status::get_install_url( $plugin['wp_org_slug'] ) ); ?>">
									<?php esc_html_e( 'Install', 'sendtomp' ); ?>
								</a>
							<?php elseif ( ! empty( $plugin['is_built_in'] ) ) : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sendtomp&tab=webhook' ) ); ?>">
									<?php esc_html_e( 'Details', 'sendtomp' ); ?>
								</a>
							<?php elseif ( ! empty( $plugin['purchase_url'] ) ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $plugin['purchase_url'] ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'Get it', 'sendtomp' ); ?> &rarr;
								</a>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $plugin['is_affiliate'] ) ) : ?>
							<div class="sendtomp-item-disclosure">
								<?php esc_html_e( 'Bluetorch earns a small commission if you buy through this link, which helps us keep SendToMP going. You pay the same price either way.', 'sendtomp' ); ?>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="sendtomp-status-card">
			<header class="sendtomp-card-header">
				<h2><?php esc_html_e( 'Email delivery', 'sendtomp' ); ?></h2>
				<p class="sendtomp-card-subtitle"><?php esc_html_e( 'MPs read their mail. Reliable delivery matters — an SMTP plugin backed by a transactional email service is strongly recommended.', 'sendtomp' ); ?></p>
			</header>

			<ul class="sendtomp-status-list">
				<li class="sendtomp-status-item <?php echo $smtp_plugin ? 'state-active' : 'state-not_installed'; ?>">
					<div class="sendtomp-item-icon" aria-hidden="true">
						<?php if ( $smtp_plugin ) : ?>
							<span class="dashicons dashicons-yes-alt"></span>
						<?php else : ?>
							<span class="dashicons dashicons-warning"></span>
						<?php endif; ?>
					</div>

					<div class="sendtomp-item-body">
						<div class="sendtomp-item-title">
							<strong><?php esc_html_e( 'SMTP plugin', 'sendtomp' ); ?></strong>
						</div>
						<div class="sendtomp-item-meta">
							<?php
							if ( $smtp_plugin ) {
								/* translators: %s: detected SMTP plugin name */
								echo esc_html( sprintf( __( 'Detected: %s', 'sendtomp' ), $smtp_plugin ) );
							} else {
								esc_html_e( 'Not detected — WordPress default mail will be used, which is unreliable for MP correspondence.', 'sendtomp' );
							}
							?>
						</div>
						<p class="sendtomp-item-description">
							<?php esc_html_e( 'An SMTP plugin such as WP Mail SMTP or FluentSMTP routes mail through a transactional service (Brevo, SendGrid, Postmark, Amazon SES) for far better deliverability.', 'sendtomp' ); ?>
						</p>
					</div>

					<div class="sendtomp-item-actions">
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sendtomp&tab=delivery' ) ); ?>">
							<?php esc_html_e( 'Delivery settings', 'sendtomp' ); ?>
						</a>
					</div>
				</li>
			</ul>
		</section>

		<section class="sendtomp-status-card">
			<header class="sendtomp-card-header">
				<h2><?php esc_html_e( 'Plan &amp; usage', 'sendtomp' ); ?></h2>
				<p class="sendtomp-card-subtitle"><?php esc_html_e( 'Your current plan and how many confirmed messages have been sent this month.', 'sendtomp' ); ?></p>
			</header>

			<div class="sendtomp-plan-summary">
				<div class="sendtomp-plan-row">
					<span class="sendtomp-plan-label"><?php esc_html_e( 'Current plan', 'sendtomp' ); ?></span>
					<span class="sendtomp-plan-value sendtomp-tier-badge sendtomp-tier-<?php echo esc_attr( $license_tier ); ?>">
						<?php echo esc_html( $tier_label ); ?>
					</span>
				</div>

				<?php if ( SendToMP_License::TIER_FREE === $license_tier ) : ?>
					<div class="sendtomp-plan-row">
						<span class="sendtomp-plan-label"><?php esc_html_e( 'This month', 'sendtomp' ); ?></span>
						<span class="sendtomp-plan-value">
							<?php
							echo esc_html( sprintf(
								/* translators: 1: messages used, 2: monthly limit */
								__( '%1$d of %2$d messages', 'sendtomp' ),
								(int) $license_used,
								(int) $license_limit
							) );
							?>
						</span>
					</div>
					<?php
					$percent = $license_limit > 0 ? min( 100, ( (int) $license_used / (int) $license_limit ) * 100 ) : 0;
					?>
					<div class="sendtomp-progress">
						<div class="sendtomp-progress-bar" style="width: <?php echo esc_attr( (int) $percent ); ?>%;"></div>
					</div>
					<p class="sendtomp-plan-footnote">
						<?php
						echo esc_html( sprintf(
							/* translators: %d: remaining messages */
							__( '%d remaining this month. The counter resets on the 1st.', 'sendtomp' ),
							(int) $license_remaining
						) );
						?>
					</p>
				<?php endif; ?>

				<div class="sendtomp-plan-actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sendtomp&tab=license' ) ); ?>">
						<?php esc_html_e( 'Manage licence', 'sendtomp' ); ?>
					</a>
					<?php if ( SendToMP_License::TIER_PRO !== $license_tier ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( 'https://bluetorch.co.uk/sendtomp#pricing' ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Upgrade', 'sendtomp' ); ?> &rarr;
						</a>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<section class="sendtomp-status-card">
			<header class="sendtomp-card-header">
				<h2><?php esc_html_e( 'Delivery log', 'sendtomp' ); ?></h2>
				<p class="sendtomp-card-subtitle"><?php esc_html_e( 'A running total of submissions handled by SendToMP across all time.', 'sendtomp' ); ?></p>
			</header>

			<?php
			$total_all = (int) ( $log_stats['total_sent'] ?? 0 )
				+ (int) ( $log_stats['total_confirmed'] ?? 0 )
				+ (int) ( $log_stats['total_failed'] ?? 0 )
				+ (int) ( $log_stats['total_pending'] ?? 0 );
			?>
			<div class="sendtomp-log-stats">
				<div class="sendtomp-log-stat">
					<div class="sendtomp-log-value"><?php echo esc_html( number_format_i18n( $total_all ) ); ?></div>
					<div class="sendtomp-log-label"><?php esc_html_e( 'Total submissions', 'sendtomp' ); ?></div>
				</div>
				<div class="sendtomp-log-stat">
					<div class="sendtomp-log-value"><?php echo esc_html( number_format_i18n( (int) ( $log_stats['total_confirmed'] ?? 0 ) ) ); ?></div>
					<div class="sendtomp-log-label"><?php esc_html_e( 'Confirmed &amp; sent', 'sendtomp' ); ?></div>
				</div>
				<div class="sendtomp-log-stat">
					<div class="sendtomp-log-value"><?php echo esc_html( number_format_i18n( (int) ( $log_stats['total_pending'] ?? 0 ) ) ); ?></div>
					<div class="sendtomp-log-label"><?php esc_html_e( 'Pending confirmation', 'sendtomp' ); ?></div>
				</div>
				<div class="sendtomp-log-stat">
					<div class="sendtomp-log-value"><?php echo esc_html( number_format_i18n( (int) ( $log_stats['total_failed'] ?? 0 ) ) ); ?></div>
					<div class="sendtomp-log-label"><?php esc_html_e( 'Failed', 'sendtomp' ); ?></div>
				</div>
			</div>

			<div class="sendtomp-log-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sendtomp-log' ) ); ?>">
					<?php esc_html_e( 'Open submission log', 'sendtomp' ); ?>
				</a>
			</div>
		</section>

	</div>
</div>
