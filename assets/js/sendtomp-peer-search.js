/**
 * SendToMP — Peer Search Autocomplete
 *
 * Reusable autocomplete for .sendtomp-peer-search inputs.
 * Used by GF feed settings, WPForms builder, and CF7 editor
 * to search for and select Lords members.
 *
 * Expects sendtomp_admin.ajax_url and sendtomp_admin.nonce to be
 * available (localised by SendToMP_Admin::enqueue_assets).
 */
jQuery( function( $ ) {
	'use strict';

	var debounceTimer;

	function initPeerSearch( $input ) {
		var $container = $input.parent();
		var $results   = $( '<div class="sendtomp-peer-results"></div>' ).appendTo( $container );
		var $hidden    = $container.find( 'input[type="hidden"]' ).first();

		// If no hidden input found, look for sibling by naming convention.
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

			debounceTimer = setTimeout( function() {
				$.ajax( {
					url: sendtomp_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'sendtomp_search_members',
						nonce: sendtomp_admin.nonce,
						query: query,
						house: 'lords'
					},
					success: function( response ) {
						$results.empty();

						if ( ! response.success || ! response.data.results || ! response.data.results.length ) {
							$results.append(
								$( '<div>' ).css( { padding: '8px 12px', color: '#999', fontSize: '0.9em' } )
									.text( 'No results found.' )
							);
							$results.show();
							return;
						}

						var members = response.data.results;

						// Handle both array and {members: []} shapes.
						if ( members.members ) {
							members = members.members;
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

								// Also set any sibling name display field.
								$container.find( '.sendtomp-peer-name-display' ).text( m.name + ' (' + ( m.party || '' ) + ')' );
							} );

							$results.append( $item );
						} );

						$results.show();
					}
				} );
			}, 300 );
		} );

		// Hide results when clicking outside.
		$( document ).on( 'click', function( e ) {
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

	// Initialise all peer search inputs on the page.
	$( '.sendtomp-peer-search' ).each( function() {
		initPeerSearch( $( this ) );
	} );

	// Re-init for dynamically added inputs (e.g., GF feed settings loaded via AJAX).
	$( document ).on( 'focus', '.sendtomp-peer-search:not(.sendtomp-peer-search-init)', function() {
		$( this ).addClass( 'sendtomp-peer-search-init' );
		initPeerSearch( $( this ) );
	} );
} );
