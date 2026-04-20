/**
 * SendToMP — CF7 editor panel (Lords toggle).
 *
 * @package SendToMP
 */

jQuery(function($) {
	'use strict';

	$('#sendtomp-target_house').on('change', function() {
		if ($(this).val() === 'lords') {
			$('.sendtomp-lords-only').show();
		} else {
			$('.sendtomp-lords-only').hide();
		}
	});
});
