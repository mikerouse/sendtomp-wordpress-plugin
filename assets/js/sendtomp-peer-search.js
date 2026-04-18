/**
 * SendToMP — Peer Search Autocomplete
 *
 * Reusable autocomplete for .sendtomp-peer-search inputs.
 * Used by GF feed settings, WPForms builder, and CF7 editor
 * to search for and select Lords members.
 *
 * Uses sendtomp_admin.ajax_url/nonce when available, falls back
 * to the WordPress global ajaxurl for non-SendToMP admin pages.
 */
jQuery( function( $ ) {
	'use strict';

	var debounceTimer;

	function getAjaxConfig() {
		if ( typeof sendtomp_admin !== 'undefined' ) {
			return { url: sendtomp_admin.ajax_url, nonce: sendtomp_admin.nonce };
		}
		if ( typeof sendtomp_peer_search !== 'undefined' ) {
			return { url: sendtomp_peer_search.ajax_url, nonce: sendtomp_peer_search.nonce };
		}
		return { url: ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' ), nonce: '' };
	}

	function initPeerSearch( $input ) {
		// Mark as initialised immediately to prevent double-init.
		$input.addClass( 'sendtomp-peer-search-init' );

		var $container = $input.parent();
		var $results   = $( '<div class="sendtomp-peer-results"></div>' ).appendTo( $container );

		// Find the hidden member ID input — look for input with name containing target_member_id.
		var $hidden = $container.find( 'input[type="hidden"][name*="target_member_id"]' ).first();
		if ( ! $hidden.length ) {
			$hidden = $container.find( 'input[type="hidden"]' ).first();
		}
		if ( ! $hidden.length ) {
			var hiddenId = $input.attr( 'id' );
			if ( hiddenId ) {
				$hidden = $( '#' + hiddenId.replace( '-search', '' ).replace( 'peer-search', 'target_member_id' ) );
			}
		}

		// Basic styling for the dropdown.
		$results.css( {
			position: 'absolute',
			zIndex: 99999,
			background: '#fff',
			border: '1px solid #c3c4c7',
			borderRadius: '4px',
			maxHeight: '240px',
			overflowY: 'auto',
			display: 'none',
			width: $input.outerWidth() + 'px',
			boxShadow: '0 2px 8px rgba(0,0,0,0.12)'
		} );
		$container.css( 'position', 'relative' );

		$input.on( 'input', function() {
			var query = $.trim( $input.val() );

			clearTimeout( debounceTimer );

			if ( query.length < 2 ) {
				$results.hide().empty();
				return;
			}

			var config = getAjaxConfig();

			debounceTimer = setTimeout( function() {
				$.ajax( {
					url: config.url,
					type: 'POST',
					data: {
						action: 'sendtomp_search_members',
						nonce: config.nonce,
						query: query,
						house: 'lords'
					},
					success: function( response ) {
						$results.empty();

						if ( ! response.success || ! response.data.results ) {
							$results.append(
								$( '<div>' ).css( { padding: '8px 12px', color: '#999', fontSize: '0.9em' } )
									.text( 'No results found.' )
							);
							$results.show();
							return;
						}

						var members = response.data.results;

						// Normalise: handle both array and {members: []} shapes.
						if ( ! Array.isArray( members ) && members.members ) {
							members = members.members;
						}

						if ( ! members.length ) {
							$results.append(
								$( '<div>' ).css( { padding: '8px 12px', color: '#999', fontSize: '0.9em' } )
									.text( 'No results found.' )
							);
							$results.show();
							return;
						}

						$.each( members, function( _, member ) {
							var qualityBadge = '';
							if ( member.contact_quality === 'shared' ) {
								qualityBadge = ' <span style="color:#b32d2e;font-size:0.8em;">[shared inbox]</span>';
							} else if ( member.contact_quality === 'override' ) {
								qualityBadge = ' <span style="color:#00a32a;font-size:0.8em;">[override]</span>';
							}

							var $item = $( '<div>' )
								.css( { padding: '8px 12px', cursor: 'pointer', borderBottom: '1px solid #f0f0f1' } )
								.html( '<strong>' + $( '<span>' ).text( member.name ).html() + '</strong>'
									+ ' <span style="color:#646970;">(' + $( '<span>' ).text( member.party || '' ).html() + ')</span>'
									+ qualityBadge )
								.data( 'member', member );

							$item.on( 'mouseenter', function() {
								$( this ).css( 'background', '#f0f6fc' );
							} ).on( 'mouseleave', function() {
								$( this ).css( 'background', '' );
							} );

							$item.on( 'click', function() {
								var m = $( this ).data( 'member' );
								$input.val( m.name );
								if ( $hidden.length ) {
									$hidden.val( m.id ).trigger( 'change' );
								}
								$results.hide().empty();
							} );

							$results.append( $item );
						} );

						$results.show();
					}
				} );
			}, 300 );
		} );

		// Hide results when clicking outside.
		$( document ).on( 'click.sendtompPeerSearch', function( e ) {
			if ( ! $( e.target ).closest( $container ).length ) {
				$results.hide();
			}
		} );

		// Clear selection on manual edit.
		$input.on( 'keydown', function() {
			if ( $hidden.length && $hidden.val() ) {
				$hidden.val( '' );
			}
		} );
	}

	// Initialise all peer search inputs on the page (mark to prevent re-init).
	$( '.sendtomp-peer-search' ).each( function() {
		$( this ).addClass( 'sendtomp-peer-search-init' );
		initPeerSearch( $( this ) );
	} );

	// Re-init for dynamically added inputs (e.g., GF feed settings loaded via AJAX).
	$( document ).on( 'focus', '.sendtomp-peer-search:not(.sendtomp-peer-search-init)', function() {
		initPeerSearch( $( this ) );
	} );
} );
