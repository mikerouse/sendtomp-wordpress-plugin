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
						$keyInput.on('click', function () {
							this.select();
						}).trigger('click');
					} else {
						alert(response.data.message || 'Failed to generate key.');
					}
				},
				error: function () {
					alert('Request failed. Please try again.');
				},
				complete: function () {
					$button.prop('disabled', false);
				}
			});
		});

	});
})(jQuery);
