/**
 * SendToMP — Gravity Forms markdown editor.
 *
 * Enhances textareas with class `sendtomp-rich-editor` by adding:
 *   - A formatting toolbar (bold, italic, link, lists, quote, heading)
 *   - A merge-tag picker dropdown
 *   - A cheatsheet below the editor
 *
 * The textarea stays a textarea — Gravity Forms handles save, load,
 * validation, and sanitisation natively. The saved content is
 * markdown text, converted to HTML server-side by the mailer.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var data = window.sendtompRichEditor || {
			formFields:     [],
			sendtompTokens: [],
			i18n: {}
		};

		var T = $.extend(
			{
				insertLabel:   'Insert merge tag:',
				choose:        '— choose —',
				formFields:    'Form fields',
				sendtomp:      'SendToMP tokens',
				bold:          'Bold',
				italic:        'Italic',
				link:          'Link',
				bulletList:    'Bulleted list',
				numberedList:  'Numbered list',
				blockquote:    'Quote',
				heading:       'Heading',
				linkPrompt:    'Enter the URL',
				linkText:      'link text',
				cheatsheetTitle: 'Formatting guide',
				cheatsheet: [
					'**bold** for <strong>bold</strong>',
					'*italic* for <em>italic</em>',
					'[link text](https://example.com) for a link',
					'- item  (one per line) for a bulleted list',
					'1. item  (one per line) for a numbered list',
					'> at the start of a line for a blockquote',
					'## at the start of a line for a heading',
					'Blank line between paragraphs'
				]
			},
			data.i18n || {}
		);

		$( 'textarea.sendtomp-rich-editor' ).each( function () {
			var textarea = this;
			if ( ! textarea.id ) { return; }
			if ( textarea.dataset.sendtompInit === '1' ) { return; }
			textarea.dataset.sendtompInit = '1';

			buildToolbar( textarea );
			buildCheatsheet( textarea );
		} );

		/**
		 * Build the toolbar (format buttons + merge tag picker) above the textarea.
		 */
		function buildToolbar( textarea ) {
			var $bar = $( '<div class="sendtomp-md-toolbar"></div>' ).css( {
				display:       'flex',
				flexWrap:      'wrap',
				alignItems:    'center',
				gap:           '4px',
				padding:       '6px 8px',
				border:        '1px solid #c3c4c7',
				borderBottom:  '0',
				background:    '#f6f7f7',
				borderRadius:  '3px 3px 0 0'
			} );

			var buttons = [
				{ label: 'B',  title: T.bold,         style: 'font-weight:700;', action: wrapAction( '**', '**' ) },
				{ label: 'I',  title: T.italic,       style: 'font-style:italic;', action: wrapAction( '*', '*' ) },
				{ label: '🔗', title: T.link,         action: linkAction },
				{ label: '•',  title: T.bulletList,   action: linePrefix( '- ' ) },
				{ label: '1.', title: T.numberedList, action: linePrefix( '1. ' ) },
				{ label: '❝',  title: T.blockquote,   action: linePrefix( '> ' ) },
				{ label: 'H',  title: T.heading,      action: linePrefix( '## ' ) }
			];

			$.each( buttons, function ( _, btn ) {
				var $b = $( '<button type="button" class="button button-small"></button>' )
					.attr( 'title', btn.title )
					.text( btn.label );
				if ( btn.style ) { $b.attr( 'style', btn.style ); }
				$b.on( 'click', function ( e ) {
					e.preventDefault();
					btn.action( textarea );
					textarea.focus();
				} );
				$bar.append( $b );
			} );

			// Spacer.
			$bar.append( $( '<span></span>' ).css( { flex: '1 1 auto' } ) );

			// Merge tag picker.
			var $select = buildMergeTagPicker( textarea );
			$bar.append( $select );

			$( textarea ).before( $bar );

			// Style the textarea to connect with the toolbar.
			$( textarea ).css( {
				borderTopLeftRadius:  '0',
				borderTopRightRadius: '0',
				fontFamily:           'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
				fontSize:             '13px',
				lineHeight:           '1.5',
				minHeight:            '220px'
			} );
		}

		function buildMergeTagPicker( textarea ) {
			var $wrap   = $( '<span class="sendtomp-merge-tag-picker"></span>' ).css( { marginLeft: 'auto' } );
			var $select = $( '<select class="sendtomp-insert-merge-tag"></select>' )
				.attr( 'data-editor-id', textarea.id )
				.css( { minWidth: '220px' } );

			$select.append( $( '<option></option>' ).val( '' ).text( T.insertLabel ) );

			if ( data.formFields && data.formFields.length ) {
				var $formGroup = $( '<optgroup></optgroup>' ).attr( 'label', T.formFields );
				$.each( data.formFields, function ( _, opt ) {
					$formGroup.append( $( '<option></option>' ).val( opt.tag ).text( opt.label ) );
				} );
				$select.append( $formGroup );
			}

			if ( data.sendtompTokens && data.sendtompTokens.length ) {
				var $stGroup = $( '<optgroup></optgroup>' ).attr( 'label', T.sendtomp );
				$.each( data.sendtompTokens, function ( _, opt ) {
					$stGroup.append( $( '<option></option>' ).val( opt.tag ).text( opt.label ) );
				} );
				$select.append( $stGroup );
			}

			$wrap.append( $select );
			return $wrap;
		}

		function buildCheatsheet( textarea ) {
			var $details = $( '<details class="sendtomp-md-cheatsheet"></details>' ).css( { marginTop: '8px' } );
			var $summary = $( '<summary></summary>' )
				.css( { cursor: 'pointer', color: '#2271b1', fontSize: '13px' } )
				.text( T.cheatsheetTitle );
			var $body    = $( '<div></div>' ).css( {
				marginTop: '6px',
				padding:   '10px 12px',
				background: '#f6f7f7',
				border:    '1px solid #dcdcde',
				borderRadius: '3px',
				fontSize:  '12px',
				lineHeight: '1.7'
			} );

			var $ul = $( '<ul></ul>' ).css( { margin: '0', paddingLeft: '18px' } );
			$.each( T.cheatsheet, function ( _, line ) {
				var $li = $( '<li></li>' );
				$li.html( line );
				$ul.append( $li );
			} );
			$body.append( $ul );

			$details.append( $summary ).append( $body );
			$( textarea ).after( $details );
		}

		/**
		 * Wrap selected text with before/after markers, or insert markers
		 * around the cursor if nothing is selected.
		 */
		function wrapAction( before, after ) {
			return function ( ta ) {
				var start   = ta.selectionStart || 0;
				var end     = ta.selectionEnd   || 0;
				var value   = ta.value;
				var sel     = value.substring( start, end );
				var next    = before + ( sel || '' ) + after;
				ta.value    = value.substring( 0, start ) + next + value.substring( end );
				ta.selectionStart = start + before.length;
				ta.selectionEnd   = start + before.length + sel.length;
				$( ta ).trigger( 'change' );
			};
		}

		/**
		 * Prefix each line of the selection (or the current line) with a string.
		 */
		function linePrefix( prefix ) {
			return function ( ta ) {
				var start = ta.selectionStart || 0;
				var end   = ta.selectionEnd   || 0;
				var value = ta.value;

				// Expand selection to full lines.
				var lineStart = value.lastIndexOf( '\n', start - 1 ) + 1;
				var lineEnd   = value.indexOf( '\n', end );
				if ( lineEnd === -1 ) { lineEnd = value.length; }

				var chunk    = value.substring( lineStart, lineEnd );
				var prefixed = chunk.split( '\n' ).map( function ( line ) {
					return prefix + line;
				} ).join( '\n' );

				ta.value = value.substring( 0, lineStart ) + prefixed + value.substring( lineEnd );
				ta.selectionStart = lineStart;
				ta.selectionEnd   = lineStart + prefixed.length;
				$( ta ).trigger( 'change' );
			};
		}

		function linkAction( ta ) {
			var url = window.prompt( T.linkPrompt, 'https://' );
			if ( ! url ) { return; }

			var start = ta.selectionStart || 0;
			var end   = ta.selectionEnd   || 0;
			var value = ta.value;
			var sel   = value.substring( start, end ) || T.linkText;

			var insert = '[' + sel + '](' + url + ')';
			ta.value   = value.substring( 0, start ) + insert + value.substring( end );
			ta.selectionStart = start + 1;
			ta.selectionEnd   = start + 1 + sel.length;
			$( ta ).trigger( 'change' );
		}

		// Merge-tag picker handler: insert selected tag at cursor.
		$( document ).on( 'change', '.sendtomp-insert-merge-tag', function () {
			var $select  = $( this );
			var tag      = $select.val();
			var editorId = $select.data( 'editor-id' );
			if ( ! tag || ! editorId ) { return; }

			var ta = document.getElementById( editorId );
			if ( ta ) {
				var start = ta.selectionStart || 0;
				var end   = ta.selectionEnd   || 0;
				ta.value  = ta.value.substring( 0, start ) + tag + ta.value.substring( end );
				ta.selectionStart = ta.selectionEnd = start + tag.length;
				ta.focus();
				$( ta ).trigger( 'change' );
			}

			$select.val( '' );
		} );
	} );
} )( jQuery );
