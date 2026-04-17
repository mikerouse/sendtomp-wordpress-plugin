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

	});
})(jQuery);
