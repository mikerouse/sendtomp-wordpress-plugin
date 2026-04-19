/**
 * SendToMP — Frontend Postcode Lookup
 *
 * Shows the constituent's MP name and constituency before form
 * submission. Attaches to any input with class .sendtomp-postcode
 * or data-sendtomp-postcode attribute.
 */
jQuery( function( $ ) {
	'use strict';

	function initPostcodeLookup( $input ) {
		var $preview = $( '<div class="sendtomp-mp-preview" style="display:none; margin-top:6px; padding:8px 12px; background:#f0f6fc; border:1px solid #72aee6; border-radius:4px; font-size:0.9em;"></div>' );
		$input.after( $preview );

		$input.on( 'input blur', function() {
			var postcode = $.trim( $input.val() );

			clearTimeout( $input.data( 'debounceTimer' ) );

			if ( postcode.length < 5 ) {
				$preview.hide().empty();
				return;
			}

			$input.data( 'debounceTimer', setTimeout( function() {
				$.ajax( {
					url: sendtomp_frontend.ajax_url,
					type: 'POST',
					data: {
						action: 'sendtomp_lookup_postcode',
						nonce: sendtomp_frontend.nonce,
						postcode: postcode
					},
					success: function( response ) {
						if ( response.success && response.data.name ) {
							$preview.html(
								'<strong>Your MP:</strong> ' +
								$( '<span>' ).text( response.data.name ).html() +
								' (' + $( '<span>' ).text( response.data.constituency ).html() + ')' +
								' &mdash; ' + $( '<span>' ).text( response.data.party ).html()
							).show();
						} else {
							$preview.hide().empty();
						}
					},
					error: function() {
						$preview.hide().empty();
					}
				} );
			}, 500 ) );
		} );
	}

	// Initialise on any matching inputs.
	$( '.sendtomp-postcode, [data-sendtomp-postcode]' ).each( function() {
		initPostcodeLookup( $( this ) );
	} );
} );
