/**
 * SendToMP — Delivery page (Brevo enquiry form).
 *
 * @package SendToMP
 */

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
