<?php
/**
 * Email Delivery admin tab.
 *
 * SMTP setup guidance and Brevo partner referral programme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$smtp_plugin = ( new SendToMP_Mailer() )->detect_smtp_plugin();
$tier        = SendToMP_License::get_tier();
$is_pro      = SendToMP_License::TIER_PRO === $tier;
?>

<h2><?php esc_html_e( 'Email Delivery', 'sendtomp' ); ?></h2>

<!-- SMTP Status -->
<div style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
	<h3 style="margin-top: 0;"><?php esc_html_e( 'SMTP Status', 'sendtomp' ); ?></h3>

	<?php if ( $smtp_plugin ) : ?>
		<p style="color: #00a32a; font-size: 1.1em;">
			&#10003; <strong><?php echo esc_html( sprintf( __( 'SMTP plugin detected: %s', 'sendtomp' ), $smtp_plugin ) ); ?></strong>
		</p>
		<p class="description"><?php esc_html_e( 'Your site is configured to send emails via a dedicated SMTP service. This is the recommended setup for reliable delivery to parliamentary email addresses.', 'sendtomp' ); ?></p>
	<?php else : ?>
		<p style="color: #d63638; font-size: 1.1em;">
			&#10007; <strong><?php esc_html_e( 'No SMTP plugin detected', 'sendtomp' ); ?></strong>
		</p>
		<p><?php esc_html_e( 'Without a dedicated SMTP service, emails sent via WordPress default mail are likely to be rejected or filtered by parliamentary email gateways.', 'sendtomp' ); ?></p>
		<p><strong><?php esc_html_e( 'We strongly recommend:', 'sendtomp' ); ?></strong></p>
		<ol>
			<li><?php esc_html_e( 'Sign up for a transactional email service (Brevo, SendGrid, Postmark, or Amazon SES)', 'sendtomp' ); ?></li>
			<li><?php esc_html_e( 'Install an SMTP plugin (WP Mail SMTP, FluentSMTP, or Post SMTP)', 'sendtomp' ); ?></li>
			<li><?php esc_html_e( 'Configure it with your service credentials', 'sendtomp' ); ?></li>
			<li><?php esc_html_e( 'Use the Test Email button on the Email tab to verify delivery', 'sendtomp' ); ?></li>
		</ol>
	<?php endif; ?>
</div>

<!-- Brevo Partner Programme -->
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
				<?php esc_html_e( 'We\'ll create your Brevo account, configure SPF/DKIM/DMARC for your domain, set up your WordPress SMTP plugin, and verify that emails reach parliamentary addresses.', 'sendtomp' ); ?>
			</p>
		</div>
		<div style="background: #f6f7f7; border-radius: 6px; padding: 16px;">
			<h4 style="margin: 0 0 8px; color: #1d2327;"><?php esc_html_e( 'Already using Brevo?', 'sendtomp' ); ?></h4>
			<p style="font-size: 0.9em; color: #646970; margin: 0;">
				<?php esc_html_e( 'We\'ll review your setup, optimise deliverability for parliamentary email gateways, and ensure your domain authentication is correctly configured for MP correspondence.', 'sendtomp' ); ?>
			</p>
		</div>
	</div>

	<!-- Pricing -->
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

	<!-- Enquiry Form -->
	<h4 style="margin-bottom: 12px;"><?php esc_html_e( 'Request Brevo Setup', 'sendtomp' ); ?></h4>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sendtomp-brevo-first-name"><?php esc_html_e( 'First Name', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
			<td><input type="text" id="sendtomp-brevo-first-name" class="regular-text" required /></td>
		</tr>
		<tr>
			<th scope="row"><label for="sendtomp-brevo-last-name"><?php esc_html_e( 'Last Name', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
			<td><input type="text" id="sendtomp-brevo-last-name" class="regular-text" required /></td>
		</tr>
		<tr>
			<th scope="row"><label for="sendtomp-brevo-email"><?php esc_html_e( 'Email Address', 'sendtomp' ); ?> <span style="color: #d63638;">*</span></label></th>
			<td><input type="email" id="sendtomp-brevo-email" class="regular-text" required /></td>
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

<script>
jQuery(function($) {
	'use strict';

	$('#sendtomp-brevo-submit').on('click', function(e) {
		e.preventDefault();

		var $btn    = $(this);
		var $result = $('#sendtomp-brevo-result');

		var firstName   = $('#sendtomp-brevo-first-name').val();
		var lastName    = $('#sendtomp-brevo-last-name').val();
		var email       = $('#sendtomp-brevo-email').val();
		var companyName = $('#sendtomp-brevo-company').val();
		var website     = $('#sendtomp-brevo-website').val();
		var consent     = $('#sendtomp-brevo-consent').is(':checked');

		if (!firstName || !lastName || !email) {
			$result.text('Please fill in all required fields.').css('color', '#d63638');
			return;
		}

		if (!consent) {
			$result.text('Please agree to the terms to proceed.').css('color', '#d63638');
			return;
		}

		$btn.prop('disabled', true);
		$result.text('Submitting...').css('color', '');

		$.ajax({
			url: sendtomp_admin.ajax_url,
			type: 'POST',
			data: {
				action: 'sendtomp_brevo_enquiry',
				nonce: sendtomp_admin.nonce,
				first_name: firstName,
				last_name: lastName,
				email: email,
				company_name: companyName,
				website: website,
				consent: consent ? '1' : ''
			},
			success: function(response) {
				if (response.success) {
					$result.text(response.data.message).css('color', '#00a32a');
					$btn.text('Submitted').prop('disabled', true);
				} else {
					$result.text(response.data.message || 'Failed.').css('color', '#d63638');
					$btn.prop('disabled', false);
				}
			},
			error: function() {
				$result.text('Request failed. Please try again.').css('color', '#d63638');
				$btn.prop('disabled', false);
			}
		});
	});
});
</script>
