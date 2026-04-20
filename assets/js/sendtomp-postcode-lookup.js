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
		var $preview = $( '<div class="sendtomp-mp-preview" style="display:none; align-items:center; gap:10px; margin-top:6px; padding:8px 12px; background:#f0f6fc; border:1px solid #72aee6; border-radius:4px; font-size:0.9em;"></div>' );
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
							renderPreview( $preview, response.data );
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

	// Build the preview box. Uses jQuery .text() on every API-sourced string
	// to escape any HTML. The thumbnail URL is validated to be https on the
	// Parliament domain before use; anything else is dropped silently.
	function renderPreview( $preview, data ) {
		var $row  = $( '<div style="display:flex; align-items:center; gap:10px;"></div>' );
		var thumb = safeThumbnailUrl( data.thumbnail_url );

		if ( thumb ) {
			$row.append(
				$( '<img>' ).attr( {
					src: thumb,
					alt: data.name || '',
					loading: 'lazy'
				} ).css( {
					width: '40px',
					height: '40px',
					'border-radius': '50%',
					'object-fit': 'cover',
					'flex-shrink': 0
				} )
			);
		}

		var $text = $( '<div></div>' );
		$text.append( $( '<strong>' ).text( 'Your MP: ' ) );
		$text.append( $( '<span>' ).text( data.name ) );
		if ( data.constituency ) {
			$text.append( document.createTextNode( ' (' ) );
			$text.append( $( '<span>' ).text( data.constituency ) );
			$text.append( document.createTextNode( ')' ) );
		}
		if ( data.party ) {
			$text.append( document.createTextNode( ' — ' ) );
			$text.append( $( '<span>' ).text( data.party ) );
		}
		$row.append( $text );

		$preview.empty().append( $row ).css( 'display', 'flex' );
	}

	function safeThumbnailUrl( url ) {
		if ( typeof url !== 'string' || ! url ) {
			return '';
		}
		// Only accept https URLs on the Parliament Members API host.
		if ( /^https:\/\/members-api\.parliament\.uk\//i.test( url ) ) {
			return url;
		}
		return '';
	}

	// Initialise on any matching inputs.
	// The .sendtomp-postcode class may be applied directly to an <input>
	// or (by Gravity Forms) to the wrapping field container — in the latter
	// case we find the first text/generic input inside it.
	$( '.sendtomp-postcode, [data-sendtomp-postcode]' ).each( function() {
		var $el    = $( this );
		var $input = $el.is( 'input' )
			? $el
			: $el.find( 'input[type="text"], input:not([type])' ).first();

		if ( $input.length ) {
			// Give browsers a hint so autofill recognises this as a postcode.
			if ( ! $input.attr( 'autocomplete' ) ) {
				$input.attr( 'autocomplete', 'postal-code' );
			}
			initPostcodeLookup( $input );
		}
	} );
} );
