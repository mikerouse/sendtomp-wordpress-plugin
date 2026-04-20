/**
 * SendToMP — Overrides page (save/delete handlers).
 *
 * @package SendToMP
 */

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
