/**
 * SendToMP — WPForms Builder Integration
 *
 * Keeps field mapping dropdowns in sync when form fields are added,
 * removed, or renamed inside the WPForms drag-and-drop builder.
 */
( function() {
	'use strict';

	var SENDTOMP_SELECTS = [
		'#wpforms-panel-field-settings-sendtomp-field_constituent_name',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_email',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_postcode',
		'#wpforms-panel-field-settings-sendtomp-field_constituent_address',
		'#wpforms-panel-field-settings-sendtomp-field_message_subject',
		'#wpforms-panel-field-settings-sendtomp-field_message_body'
	];

	function rebuildFieldOptions() {
		var fields = [];

		jQuery( '#wpforms-panel-fields .wpforms-field' ).each( function() {
			var id    = jQuery( this ).data( 'field-id' );
			var label = jQuery( this ).find( '.label-title .text' ).text() || 'Field ' + id;

			fields.push( { id: id, label: label } );
		} );

		jQuery( SENDTOMP_SELECTS.join( ',' ) ).each( function() {
			var $select = jQuery( this );
			var current = $select.val();

			$select.find( 'option:not(:first)' ).remove();

			jQuery.each( fields, function( _, field ) {
				$select.append(
					jQuery( '<option>' ).val( field.id ).text( field.label )
				);
			} );

			$select.val( current );
		} );
	}

	jQuery( document ).on( 'wpformsFieldAdd wpformsFieldDelete wpformsFieldUpdate', rebuildFieldOptions );
} )();
