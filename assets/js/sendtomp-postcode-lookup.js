/**
 * SendToMP — Frontend Postcode Lookup
 *
 * Shows the constituent's MP name, portrait, constituency, and party
 * before form submission. Two entry points:
 *   - debounced auto-lookup on `input` / `blur` (unchanged, so power
 *     users who just type can see the preview without a click)
 *   - a "Find my MP" button next to the postcode input for users who
 *     prefer an explicit action (better for screen readers, keyboard
 *     users, and anyone who doesn't realise a blur triggers the lookup)
 *
 * Attaches to any input with class .sendtomp-postcode or
 * data-sendtomp-postcode attribute. When the class is on a wrapper
 * (Gravity Forms applies cssClass to the field wrapper, not the
 * input), the JS descends to the first text input inside.
 */
jQuery( function( $ ) {
	'use strict';

	function initPostcodeLookup( $input ) {
		var $button = $( '<button>', {
			type: 'button',
			text: ( window.sendtomp_frontend && sendtomp_frontend.find_mp_label ) || 'Find my MP',
			'class': 'sendtomp-find-mp-button',
			'aria-controls': 'sendtomp-mp-preview-' + getUid()
		} ).css( {
			'margin-top': '6px',
			'padding': '6px 14px',
			'background': '#0073aa',
			'color': '#fff',
			'border': '1px solid #0073aa',
			'border-radius': '4px',
			'cursor': 'pointer',
			'font-size': '0.9em'
		} );

		var previewId = $button.attr( 'aria-controls' );
		var $preview  = $( '<div>', {
			id: previewId,
			'class': 'sendtomp-mp-preview',
			'aria-live': 'polite',
			'role': 'status'
		} ).css( {
			'display': 'none',
			'align-items': 'center',
			'gap': '10px',
			'margin-top': '6px',
			'padding': '8px 12px',
			'background': '#f0f6fc',
			'border': '1px solid #72aee6',
			'border-radius': '4px',
			'font-size': '0.9em'
		} );

		$input.after( $button );
		$button.after( $preview );

		function runLookup( showLoadingState ) {
			var postcode = $.trim( $input.val() );

			if ( postcode.length < 5 ) {
				$preview.hide().empty();
				if ( showLoadingState ) {
					// Explicit button click with too-short postcode: tell
					// them why nothing happened.
					$preview.text(
						( window.sendtomp_frontend && sendtomp_frontend.short_postcode ) ||
						'Please enter a full UK postcode.'
					).css( 'display', 'block' );
				}
				return;
			}

			if ( showLoadingState ) {
				$preview.empty().text(
					( window.sendtomp_frontend && sendtomp_frontend.finding_label ) ||
					'Finding your MP...'
				).css( 'display', 'block' );
				$button.prop( 'disabled', true );
			}

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
				},
				complete: function() {
					$button.prop( 'disabled', false );
				}
			} );
		}

		// Auto-lookup on typing/blur stays for power users.
		$input.on( 'input blur', function() {
			clearTimeout( $input.data( 'debounceTimer' ) );
			$input.data( 'debounceTimer', setTimeout( function() {
				runLookup( false );
			}, 500 ) );
		} );

		// Explicit button press cancels any pending debounce and runs
		// the lookup immediately with visible feedback.
		$button.on( 'click', function() {
			clearTimeout( $input.data( 'debounceTimer' ) );
			runLookup( true );
		} );
	}

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

	var _uid = 0;
	function getUid() {
		_uid += 1;
		return _uid;
	}

	// Initialise on any matching inputs. The .sendtomp-postcode class may
	// be applied directly to an <input> or (by Gravity Forms) to the
	// wrapping field container — in the latter case we find the first
	// text/generic input inside it.
	$( '.sendtomp-postcode, [data-sendtomp-postcode]' ).each( function() {
		var $el    = $( this );
		var $input = $el.is( 'input' )
			? $el
			: $el.find( 'input[type="text"], input:not([type])' ).first();

		if ( $input.length ) {
			if ( ! $input.attr( 'autocomplete' ) ) {
				$input.attr( 'autocomplete', 'postal-code' );
			}
			initPostcodeLookup( $input );
		}
	} );
} );
