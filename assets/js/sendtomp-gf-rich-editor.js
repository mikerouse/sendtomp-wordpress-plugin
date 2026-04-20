/**
 * SendToMP — Gravity Forms rich editor + merge tag picker.
 *
 * Converts textareas with class `sendtomp-rich-editor` into a TinyMCE
 * WYSIWYG, and renders a merge-tag picker dropdown above each one that
 * inserts GF form-field tokens or SendToMP runtime tokens at the
 * cursor position.
 *
 * Using wp.editor.initialize() (instead of PHP wp_editor()) means the
 * underlying field stays a plain textarea as far as Gravity Forms is
 * concerned, so GF's own save / load / validation all work correctly.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		if ( typeof wp === 'undefined' || ! wp.editor || ! wp.editor.initialize ) {
			return;
		}

		var data = window.sendtompRichEditor || {
			formFields:     [],
			sendtompTokens: [],
			i18n: {
				insertLabel: 'Insert merge tag:',
				choose:      '— choose —',
				formFields:  'Form fields',
				sendtomp:    'SendToMP tokens (resolved at send time)'
			}
		};

		$( 'textarea.sendtomp-rich-editor' ).each( function () {
			var textarea = this;
			var id       = textarea.id;
			if ( ! id ) { return; }

			// Skip if TinyMCE already initialised on this textarea.
			if ( textarea.dataset.sendtompInit === '1' ) { return; }
			textarea.dataset.sendtompInit = '1';

			buildPicker( textarea );
			initTinyMCE( id );
		} );

		/**
		 * Build the merge-tag picker <select> and inject it above the textarea.
		 */
		function buildPicker( textarea ) {
			var $wrap   = $( '<div class="sendtomp-merge-tag-picker" style="margin-bottom:8px;"></div>' );
			var $label  = $( '<label style="display:inline-block;margin-right:6px;font-weight:600;"></label>' )
				.text( data.i18n.insertLabel );
			var $select = $( '<select class="sendtomp-insert-merge-tag" style="min-width:260px;"></select>' )
				.attr( 'data-editor-id', textarea.id );

			$select.append( $( '<option></option>' ).val( '' ).text( data.i18n.choose ) );

			if ( data.formFields && data.formFields.length ) {
				var $formGroup = $( '<optgroup></optgroup>' ).attr( 'label', data.i18n.formFields );
				$.each( data.formFields, function ( _, opt ) {
					$formGroup.append( $( '<option></option>' ).val( opt.tag ).text( opt.label ) );
				} );
				$select.append( $formGroup );
			}

			if ( data.sendtompTokens && data.sendtompTokens.length ) {
				var $stGroup = $( '<optgroup></optgroup>' ).attr( 'label', data.i18n.sendtomp );
				$.each( data.sendtompTokens, function ( _, opt ) {
					$stGroup.append( $( '<option></option>' ).val( opt.tag ).text( opt.label ) );
				} );
				$select.append( $stGroup );
			}

			$wrap.append( $label ).append( $select );
			$wrap.insertBefore( textarea );
		}

		/**
		 * Initialise TinyMCE on a textarea via wp.editor.initialize.
		 * Uses the "teeny" toolbar profile — enough for email formatting.
		 */
		function initTinyMCE( id ) {
			wp.editor.initialize( id, {
				tinymce: {
					wpautop:  true,
					menubar:  false,
					toolbar1: 'bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,removeformat',
					toolbar2: ''
				},
				quicktags:    true,
				mediaButtons: false
			} );
		}

		/**
		 * Handle merge-tag picker selection — insert into TinyMCE (if
		 * active) or into the raw textarea at the cursor position.
		 */
		$( document ).on( 'change', '.sendtomp-insert-merge-tag', function () {
			var $select  = $( this );
			var tag      = $select.val();
			var editorId = $select.data( 'editor-id' );
			if ( ! tag || ! editorId ) { return; }

			var editor = ( window.tinymce && tinymce.get ) ? tinymce.get( editorId ) : null;

			if ( editor && ! editor.isHidden() ) {
				editor.execCommand( 'mceInsertContent', false, tag );
			} else {
				var ta = document.getElementById( editorId );
				if ( ta ) {
					var start = ta.selectionStart || 0;
					var end   = ta.selectionEnd || 0;
					ta.value  = ta.value.substring( 0, start ) + tag + ta.value.substring( end );
					ta.selectionStart = ta.selectionEnd = start + tag.length;
					ta.focus();
				}
			}

			$select.val( '' );
		} );
	} );
} )( jQuery );
