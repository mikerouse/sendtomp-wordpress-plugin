/**
 * SendToMP Admin Scripts
 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// Test Email button handler
		$('#sendtomp-test-email').on('click', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $result = $('#sendtomp-test-email-result');

			$button.prop('disabled', true);
			$result.text('Sending...').removeClass('notice-success notice-error').css('color', '');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_test_email',
					nonce: sendtomp_admin.nonce
				},
				success: function (response) {
					if (response.success) {
						$result.text(response.data.message || 'Test email sent successfully.').css('color', '#00a32a');
					} else {
						$result.text(response.data.message || 'Failed to send test email.').css('color', '#d63638');
					}
				},
				error: function () {
					$result.text('Request failed. Please try again.').css('color', '#d63638');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

		// Purge Logs button handler
		$('#sendtomp-purge-logs').on('click', function (e) {
			e.preventDefault();

			if (!confirm('Are you sure you want to purge old logs? This cannot be undone.')) {
				return;
			}

			var $button = $(this);
			var $result = $('#sendtomp-purge-result');

			$button.prop('disabled', true);
			$result.text('Purging...').css('color', '');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_purge_logs',
					nonce: sendtomp_admin.nonce
				},
				success: function (response) {
					if (response.success) {
						$result.text(response.data.message || 'Logs purged.').css('color', '#00a32a');
					} else {
						$result.text(response.data.message || 'Failed to purge logs.').css('color', '#d63638');
					}
				},
				error: function () {
					$result.text('Request failed. Please try again.').css('color', '#d63638');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

		// Webhook API key generation handler
		$(document).on('click', '.sendtomp-generate-key', function (e) {
			e.preventDefault();

			var $button = $(this);
			var keyType = $button.data('key-type');
			var $container = $button.closest('[id^="sendtomp-webhook-key-"]');
			var $resultDiv = $container.find('.sendtomp-key-result');
			var $keyInput = $container.find('.sendtomp-key-display');

			if (!confirm('Generate a new API key? Any existing key of this type will stop working immediately.')) {
				return;
			}

			var previousLabel = $button.text();
			$button.prop('disabled', true).text('Generating...');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_generate_webhook_key',
					nonce: sendtomp_admin.nonce,
					key_type: keyType
				},
				success: function (response) {
					if (response.success) {
						$keyInput.val(response.data.key);
						$resultDiv.show();
						$button.text('Regenerate Key');

						// Select the key for easy copying.
						$keyInput.off('click').on('click', function () {
							this.select();
						}).trigger('click');
					} else {
						$button.text(previousLabel);
						alert(response.data.message || 'Failed to generate key.');
					}
				},
				error: function () {
					$button.text(previousLabel);
					alert('Request failed. Please try again.');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

		// License activation handler.
		$('#sendtomp-activate-license').on('click', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $result = $('#sendtomp-license-result');
			var key = $('#license_key').val();

			if (!key) {
				$result.text('Please enter a license key.').css('color', '#d63638');
				return;
			}

			$button.prop('disabled', true);
			$result.text('Activating...').css('color', '');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_activate_license',
					nonce: sendtomp_admin.nonce,
					license_key: key
				},
				success: function (response) {
					if (response.success) {
						$result.text(response.data.message).css('color', '#00a32a');
						setTimeout(function () { location.reload(); }, 1500);
					} else {
						$result.text(response.data.message || 'Activation failed.').css('color', '#d63638');
					}
				},
				error: function () {
					$result.text('Request failed. Please try again.').css('color', '#d63638');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

		// License deactivation handler.
		$('#sendtomp-deactivate-license').on('click', function (e) {
			e.preventDefault();

			if (!confirm('Deactivate your license? You will revert to the Free plan.')) {
				return;
			}

			var $button = $(this);
			var $result = $('#sendtomp-license-result');

			$button.prop('disabled', true);
			$result.text('Deactivating...').css('color', '');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_deactivate_license',
					nonce: sendtomp_admin.nonce
				},
				success: function (response) {
					if (response.success) {
						$result.text(response.data.message).css('color', '#00a32a');
						setTimeout(function () { location.reload(); }, 1500);
					} else {
						$result.text(response.data.message || 'Deactivation failed.').css('color', '#d63638');
					}
				},
				error: function () {
					$result.text('Request failed. Please try again.').css('color', '#d63638');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

		// Log entry detail view — Resend + Delete buttons.
		function logActionResult(msg, ok) {
			var $r = $('#sendtomp-log-action-result');
			$r.text(msg).css('color', ok ? '#00a32a' : '#d63638');
		}

		$(document).on('click', '.sendtomp-log-resend', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var logId   = parseInt($btn.data('log-id'), 10);
			if (!logId) return;

			$btn.prop('disabled', true);
			logActionResult('Resending…', true);

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_resend_confirmation',
					nonce: sendtomp_admin.nonce,
					log_id: logId
				},
				success: function (response) {
					if (response.success) {
						logActionResult(response.data.message || 'Confirmation email re-sent.', true);
					} else {
						logActionResult(response.data.message || 'Resend failed.', false);
					}
				},
				error: function () {
					logActionResult('Request failed. Please try again.', false);
				},
				complete: function () {
					$btn.prop('disabled', false);
				}
			});
		});

		$(document).on('click', '.sendtomp-log-delete', function (e) {
			e.preventDefault();
			if (!confirm('Delete this submission log entry? This cannot be undone.')) {
				return;
			}

			var $btn  = $(this);
			var logId = parseInt($btn.data('log-id'), 10);
			if (!logId) return;

			$btn.prop('disabled', true);
			logActionResult('Deleting…', true);

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_delete_log',
					nonce: sendtomp_admin.nonce,
					log_id: logId
				},
				success: function (response) {
					if (response.success) {
						logActionResult(response.data.message || 'Deleted.', true);
						// Redirect back to the list after a short pause so the
						// user registers the success message.
						setTimeout(function () {
							var backUrl = (window.location.href.split('&view=')[0]).replace(/&?view=\d+/, '');
							window.location.href = backUrl;
						}, 900);
					} else {
						$btn.prop('disabled', false);
						logActionResult(response.data.message || 'Delete failed.', false);
					}
				},
				error: function () {
					$btn.prop('disabled', false);
					logActionResult('Request failed. Please try again.', false);
				}
			});
		});

		// Media Library picker — wires up any .sendtomp-media-select
		// button to open wp.media(), and writes the chosen image's URL
		// back into the paired input. Each button carries a
		// data-sendtomp-media-target pointing at the input id.
		var mediaFrames = {};
		$(document).on('click', '.sendtomp-media-select', function (e) {
			e.preventDefault();
			var targetId = $(this).data('sendtomp-media-target');
			var $input   = $('#' + targetId);
			var $preview = $('#' + targetId + '-preview');

			if (typeof wp === 'undefined' || !wp.media) {
				alert('Media Library is not available on this page.');
				return;
			}

			if (!mediaFrames[targetId]) {
				mediaFrames[targetId] = wp.media({
					title: 'Select confirmation email logo',
					button: { text: 'Use this image' },
					multiple: false,
					library: { type: 'image' }
				});

				mediaFrames[targetId].on('select', function () {
					var attachment = mediaFrames[targetId].state().get('selection').first().toJSON();
					$input.val(attachment.url).trigger('change');
					$preview.find('img').attr('src', attachment.url);
					$preview.show();
				});
			}

			mediaFrames[targetId].open();
		});

		$(document).on('click', '.sendtomp-media-clear', function (e) {
			e.preventDefault();
			var targetId = $(this).data('sendtomp-media-target');
			$('#' + targetId).val('').trigger('change');
			$('#' + targetId + '-preview').hide().find('img').attr('src', '');
		});

		// GDPR data erasure handler.
		$('#sendtomp-erase-data').on('click', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $result = $('#sendtomp-erase-result');
			var email = $('#sendtomp-erase-email').val();

			if (!email) {
				$result.text('Please enter an email address.').css('color', '#d63638');
				return;
			}

			if (!confirm('Permanently delete all data for ' + email + '? This cannot be undone.')) {
				return;
			}

			$button.prop('disabled', true);
			$result.text('Erasing...').css('color', '');

			$.ajax({
				url: sendtomp_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'sendtomp_erase_data',
					nonce: sendtomp_admin.nonce,
					email: email
				},
				success: function (response) {
					if (response.success) {
						$result.text(response.data.message).css('color', '#00a32a');
						$('#sendtomp-erase-email').val('');
					} else {
						$result.text(response.data.message || 'Failed.').css('color', '#d63638');
					}
				},
				error: function () {
					$result.text('Request failed.').css('color', '#d63638');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

	});
})(jQuery);
