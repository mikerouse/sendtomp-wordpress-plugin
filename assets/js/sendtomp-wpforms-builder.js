/**
 * SendToMP — WPForms Builder Integration
 *
 * Keeps field mapping dropdowns in sync when form fields are added,
 * removed, or renamed inside the WPForms drag-and-drop builder.
 */
/**
 * SendToMP — WPForms Builder Integration
 *
 * Keeps field mapping dropdowns in sync when form fields are added,
 * removed, or renamed inside the WPForms drag-and-drop builder.
 *
 * Note: The field label selector (.label-title .text) matches WPForms
 * 1.8.x–1.9.x internal DOM structure. If WPForms changes these class
 * names, the fallback "Field {id}" label is used instead.
 */
jQuery( function( $ ) {
	'use strict';

	var SENDTOMP_SELECTS = [
		'#wpforms-panel-field-settings-sendtomp-field_constituent_name',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_email',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_postcode',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_address',
		'#wpforms-panel-field-settings-sendtomp-field_message_subject',
		'#wpforms-panel-field-settings-sendtomp-field_message_body'
	];

	function getFieldLabel( $el, id ) {
		// Try multiple selectors for resilience across WPForms versions.
		var label = $el.find( '.label-title .text' ).text()
			|| $el.find( '.wpforms-field-label' ).text()
			|| $el.find( 'label' ).first().text();

		return ( label && label.trim() ) ? label.trim() : 'Field ' + id;
	}

	function rebuildFieldOptions() {
		var fields = [];

		$( '#wpforms-panel-fields .wpforms-field' ).each( function() {
			var id    = $( this ).data( 'field-id' );
			var label = getFieldLabel( $( this ), id );

			fields.push( { id: id, label: label } );
		} );

		$( SENDTOMP_SELECTS.join( ',' ) ).each( function() {
			var $select = $( this );
			var current = $select.val();

			$select.find( 'option:not(:first)' ).remove();

			$.each( fields, function( _, field ) {
				$select.append(
					$( '<option>' ).val( field.id ).text( field.label )
				);
			} );

			$select.val( current );
		} );
	}

	$( document ).on( 'wpformsFieldAdd wpformsFieldDelete wpformsFieldUpdate', rebuildFieldOptions );
} );
