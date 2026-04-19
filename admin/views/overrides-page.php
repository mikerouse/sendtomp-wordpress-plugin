<?php
/**
 * Address Overrides admin tab.
 *
 * Displays existing local overrides in a table and provides a form to
 * add/edit overrides using the shared peer search autocomplete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$overrides = SendToMP_Overrides::get_all();
$can_override = sendtomp()->can( 'local_overrides' );

// Enqueue peer search for the member selector.
SendToMP_Form_Adapter_Abstract::enqueue_peer_search();
?>

<h2><?php esc_html_e( 'Address Overrides', 'sendtomp' ); ?></h2>
<p><?php esc_html_e( 'Override the delivery email for specific MPs or Peers. Local overrides take precedence over global overrides and Parliament API data.', 'sendtomp' ); ?></p>

<?php if ( ! $can_override ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'Local address overrides require a Plus+ or Pro licence. Upgrade to unlock this feature.', 'sendtomp' ); ?></p>
	</div>
<?php else : ?>

	<!-- Add/Edit Override Form -->
	<div class="sendtomp-override-form" style="background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
		<h3 style="margin-top: 0;"><?php esc_html_e( 'Add Override', 'sendtomp' ); ?></h3>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="sendtomp-override-member-search"><?php esc_html_e( 'Member', 'sendtomp' ); ?></label>
				</th>
				<td>
					<input type="text" id="sendtomp-override-member-search" class="regular-text sendtomp-peer-search"
						data-house="all"
						placeholder="<?php esc_attr_e( 'Search for an MP or Peer...', 'sendtomp' ); ?>" />
					<input type="hidden" id="sendtomp-override-member-id" name="sendtomp-override-member-id" value="" />
					<input type="hidden" id="sendtomp-override-member-house" value="" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sendtomp-override-email"><?php esc_html_e( 'Override Email', 'sendtomp' ); ?></label>
				</th>
				<td>
					<input type="email" id="sendtomp-override-email" class="regular-text"
						placeholder="<?php esc_attr_e( 'mp@example.com', 'sendtomp' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sendtomp-override-notes"><?php esc_html_e( 'Notes', 'sendtomp' ); ?></label>
				</th>
				<td>
					<textarea id="sendtomp-override-notes" class="large-text" rows="2"
						placeholder="<?php esc_attr_e( 'Why this override exists (optional)', 'sendtomp' ); ?>"></textarea>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" id="sendtomp-save-override" class="button button-primary">
				<?php esc_html_e( 'Save Override', 'sendtomp' ); ?>
			</button>
			<span id="sendtomp-override-result" style="margin-left: 10px;"></span>
		</p>
	</div>

	<!-- Existing Overrides Table -->
	<?php if ( ! empty( $overrides ) ) : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Member', 'sendtomp' ); ?></th>
					<th><?php esc_html_e( 'House', 'sendtomp' ); ?></th>
					<th><?php esc_html_e( 'Override Email', 'sendtomp' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'sendtomp' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'sendtomp' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'sendtomp' ); ?></th>
				</tr>
			</thead>
			<tbody id="sendtomp-overrides-tbody">
				<?php foreach ( $overrides as $member_id => $override ) : ?>
					<tr data-member-id="<?php echo esc_attr( $member_id ); ?>">
						<td>
							<strong><?php echo esc_html( $override['member_name'] ); ?></strong>
							<br><small class="description">ID: <?php echo esc_html( $member_id ); ?></small>
						</td>
						<td><?php echo esc_html( ucfirst( $override['house'] ) ); ?></td>
						<td><code><?php echo esc_html( $override['email'] ); ?></code></td>
						<td><?php echo esc_html( $override['notes'] ); ?></td>
						<td><?php echo esc_html( $override['updated_at'] ); ?></td>
						<td>
							<button type="button" class="button button-small sendtomp-delete-override"
								data-member-id="<?php echo esc_attr( $member_id ); ?>"
								data-member-name="<?php echo esc_attr( $override['member_name'] ); ?>">
								<?php esc_html_e( 'Delete', 'sendtomp' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No local overrides configured.', 'sendtomp' ); ?></p>
	<?php endif; ?>

<?php endif; ?>

<script>
jQuery(function($) {
	'use strict';

	// Capture the selected member's house from the peer search result.
	$(document).on('click', '.sendtomp-peer-result-item', function() {
		var member = $(this).data('member');
		if (member && $('#sendtomp-override-member-id').length) {
			$('#sendtomp-override-member-house').val(member.house || 'commons');
		}
	});

	// Save override.
	$('#sendtomp-save-override').on('click', function() {
		var $btn    = $(this);
		var $result = $('#sendtomp-override-result');
		var memberId   = $('#sendtomp-override-member-id').val();
		var memberName = $('#sendtomp-override-member-search').val();
		var house      = $('#sendtomp-override-member-house').val() || 'commons';
		var email      = $('#sendtomp-override-email').val();
		var notes      = $('#sendtomp-override-notes').val();

		if (!memberId || !email) {
			$result.text('Please select a member and enter an email.').css('color', '#d63638');
			return;
		}

		$btn.prop('disabled', true);
		$result.text('Saving...').css('color', '');

		$.ajax({
			url: sendtomp_admin.ajax_url,
			type: 'POST',
			data: {
				action: 'sendtomp_save_override',
				nonce: sendtomp_admin.nonce,
				member_id: memberId,
				member_name: memberName,
				house: house,
				email: email,
				notes: notes
			},
			success: function(response) {
				if (response.success) {
					$result.text(response.data.message).css('color', '#00a32a');
					// Reload to show updated table.
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					$result.text(response.data.message || 'Failed.').css('color', '#d63638');
				}
			},
			error: function() {
				$result.text('Request failed.').css('color', '#d63638');
			},
			complete: function() {
				$btn.prop('disabled', false);
			}
		});
	});

	// Delete override.
	$(document).on('click', '.sendtomp-delete-override', function() {
		var $btn = $(this);
		var memberId   = $btn.data('member-id');
		var memberName = $btn.data('member-name');

		if (!confirm('Delete the override for ' + memberName + '?')) {
			return;
		}

		$btn.prop('disabled', true).text('Deleting...');

		$.ajax({
			url: sendtomp_admin.ajax_url,
			type: 'POST',
			data: {
				action: 'sendtomp_delete_override',
				nonce: sendtomp_admin.nonce,
				member_id: memberId
			},
			success: function(response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
				} else {
					alert(response.data.message || 'Failed to delete.');
					$btn.prop('disabled', false).text('Delete');
				}
			},
			error: function() {
				alert('Request failed.');
				$btn.prop('disabled', false).text('Delete');
			}
		});
	});
});
</script>
