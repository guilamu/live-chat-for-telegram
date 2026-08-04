/**
 * Settings screen: connection checks and webhook controls.
 *
 * @package Live_Chat_For_Telegram
 */

( function () {
	'use strict';

	var panel = null;

	/**
	 * Calls one of the plugin's admin-ajax actions.
	 *
	 * @param {string} action The action name, without the lcft_ prefix.
	 * @return {Promise<Object>} The decoded response.
	 */
	function request( action ) {
		var body = new FormData();

		body.append( 'action', 'lcft_' + action );
		body.append( 'nonce', window.lcftAdmin.nonce );

		return fetch( window.lcftAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Replaces the panel with a single line of feedback.
	 *
	 * @param {string} message The text to show.
	 * @param {string} status  ok, warning or error.
	 */
	function notify( message, status ) {
		if ( ! panel ) {
			return;
		}

		panel.innerHTML = '';
		panel.appendChild( buildRow( { label: '', status: status, message: message } ) );
	}

	/**
	 * Builds one result row.
	 *
	 * @param {Object} check label, status and message.
	 * @return {HTMLElement} The row.
	 */
	function buildRow( check ) {
		var row = document.createElement( 'div' );
		var icons = { ok: '✔', warning: '⚠', error: '✖' };

		row.className = 'lcft-check is-' + ( check.status || 'warning' );

		var icon = document.createElement( 'span' );
		icon.className = 'lcft-check__icon';
		icon.textContent = icons[ check.status ] || icons.warning;
		row.appendChild( icon );

		var text = document.createElement( 'span' );
		text.className = 'lcft-check__text';

		if ( check.label ) {
			var label = document.createElement( 'strong' );
			label.textContent = check.label + ' — ';
			text.appendChild( label );
		}

		// Messages can carry a URL from Telegram, so they are added as text and never as markup.
		text.appendChild( document.createTextNode( check.message || '' ) );
		row.appendChild( text );

		return row;
	}

	/**
	 * Draws a full set of checks.
	 *
	 * @param {Array} checks The results.
	 */
	function render( checks ) {
		if ( ! panel ) {
			return;
		}

		panel.innerHTML = '';

		checks.forEach( function ( check ) {
			panel.appendChild( buildRow( check ) );
		} );
	}

	/**
	 * Keeps the settings tabs working when another plugin hijacks them.
	 *
	 * `.nav-tab` is a core WordPress class, and plugins routinely bind a click handler to every
	 * instance of it in wp-admin — then call preventDefault() and rebuild the URL from a data
	 * attribute only their own tabs carry. The result on someone else's screen is a cancelled
	 * navigation and a URL ending in "tab=undefined".
	 *
	 * Listening on the document in the capture phase puts this ahead of any handler bound to the
	 * links themselves, so the navigation happens before anything gets the chance to cancel it.
	 */
	function defendTabs() {
		document.addEventListener(
			'click',
			function ( event ) {
				if ( ! event.target.closest ) {
					return;
				}

				var tab = event.target.closest( '.lcft-settings .nav-tab' );

				// Leave modified clicks alone: they are the visitor asking for a new tab or window.
				if ( ! tab || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
					return;
				}

				event.stopImmediatePropagation();
				event.preventDefault();
				window.location.href = tab.href;
			},
			true
		);
	}

	/**
	 * Wires up the "Choose from Media Library" button next to the avatar URL field.
	 *
	 * Opens the standard WordPress media frame restricted to images. Selecting one writes its URL
	 * into the target text field and updates the preview thumbnail.
	 */
	function initMediaPicker() {
		var button = document.querySelector( '[data-lcft-media-picker]' );

		if ( ! button || ! window.wp || ! window.wp.media ) {
			return;
		}

		var targetId = button.getAttribute( 'data-lcft-media-picker' );
		var field = document.getElementById( targetId );
		var preview = document.getElementById( targetId + '_preview' );
		var frame = null;

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = window.wp.media( {
				title: window.lcftAdmin.i18n.chooseImage,
				button: { text: window.lcftAdmin.i18n.useImage },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				if ( ! attachment || ! attachment.url ) {
					return;
				}

				if ( field ) {
					field.value = attachment.url;
				}

				if ( preview ) {
					preview.src = attachment.url;
					preview.hidden = false;
				}
			} );

			frame.open();
		} );
	}

	/**
	 * Wires up every merge-tag style picker: the small icon button sitting in a field's own corner,
	 * which opens a searchable list to insert from. Used both for {placeholder} tokens (inserted at
	 * the caret) and for the field map's Gravity Forms field browser (appended as a whole line).
	 *
	 * Modelled on Gravity Forms' "Insert Merge Tag" control — one icon per field, rather than one
	 * shared button below it.
	 */
	function initPlaceholderPickers() {
		var toggles = document.querySelectorAll( '[data-lcft-mt-toggle]' );

		if ( ! toggles.length ) {
			return;
		}

		function closeAll() {
			document.querySelectorAll( '.lcft-mt__panel' ).forEach( function ( panel ) {
				panel.hidden = true;
			} );
			document.querySelectorAll( '[data-lcft-mt-toggle]' ).forEach( function ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			} );
		}

		Array.prototype.forEach.call( toggles, function ( toggle ) {
			var targetId = toggle.getAttribute( 'data-lcft-mt-toggle' );
			var panel = document.querySelector( '[data-lcft-mt-panel="' + targetId + '"]' );
			var target = document.getElementById( targetId );

			if ( ! panel || ! target ) {
				return;
			}

			var search = panel.querySelector( '[data-lcft-mt-search]' );
			var items = panel.querySelectorAll( '[data-lcft-placeholder], [data-lcft-fieldmap-line]' );

			toggle.addEventListener( 'click', function () {
				var willOpen = panel.hidden;

				closeAll();

				panel.hidden = ! willOpen;
				toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );

				if ( willOpen && search ) {
					search.value = '';
					filterItems( items, '' );
					search.focus();
				}
			} );

			if ( search ) {
				search.addEventListener( 'input', function () {
					filterItems( items, search.value );
				} );

				search.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
				} );
			}

			Array.prototype.forEach.call( items, function ( item ) {
				item.addEventListener( 'click', function () {
					if ( item.hasAttribute( 'data-lcft-fieldmap-line' ) ) {
						appendLine( target, item.getAttribute( 'data-lcft-fieldmap-line' ) );
					} else {
						insertAtCaret( target, '{' + item.getAttribute( 'data-lcft-placeholder' ) + '}' );
					}

					closeAll();
				} );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest || ! event.target.closest( '.lcft-mt' ) ) {
				closeAll();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeAll();
			}
		} );
	}

	/**
	 * Shows only the items whose searchable text contains the search text.
	 *
	 * @param {NodeList} items The candidate buttons.
	 * @param {string}   query The search text.
	 */
	function filterItems( items, query ) {
		var needle = query.toLowerCase();

		Array.prototype.forEach.call( items, function ( item ) {
			var haystack = ( item.getAttribute( 'data-lcft-search' ) || item.getAttribute( 'data-lcft-placeholder' ) || '' ).toLowerCase();
			item.hidden = -1 === haystack.indexOf( needle );
		} );
	}

	/**
	 * Inserts text at the current caret position of a field, replacing any selection, and dispatches
	 * an input event so anything listening for changes notices.
	 *
	 * @param {HTMLElement} field The text input or textarea.
	 * @param {string}      text  The text to insert.
	 */
	function insertAtCaret( field, text ) {
		var start = field.selectionStart;
		var end = field.selectionEnd;

		if ( typeof start !== 'number' || typeof end !== 'number' ) {
			field.value += text;
		} else {
			field.value = field.value.slice( 0, start ) + text + field.value.slice( end );
			field.selectionStart = field.selectionEnd = start + text.length;
		}

		field.focus();
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	/**
	 * Appends a whole line to a textarea, such as a field map's "name = id" entries, rather than
	 * inserting at the caret — the map is one entry per line, so appending is the useful behaviour
	 * regardless of where the cursor happens to be.
	 *
	 * @param {HTMLElement} field The textarea.
	 * @param {string}      line  The line to append.
	 */
	function appendLine( field, line ) {
		var value = field.value;

		field.value = ( '' === value || /\n$/.test( value ) ) ? value + line + '\n' : value + '\n' + line + '\n';
		field.focus();
		field.selectionStart = field.selectionEnd = field.value.length;
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		defendTabs();
		initMediaPicker();
		initPlaceholderPickers();

		panel = document.getElementById( 'lcft-diagnostics' );

		var buttons = document.querySelectorAll( '[data-lcft-action]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var action = button.getAttribute( 'data-lcft-action' );

				button.disabled = true;
				notify( window.lcftAdmin.i18n.working, 'warning' );

				request( action )
					.then( function ( response ) {
						if ( response && response.success && response.data && response.data.checks ) {
							render( response.data.checks );
							return;
						}

						if ( response && response.success ) {
							notify( response.data.message, 'ok' );
							return;
						}

						notify(
							response && response.data && response.data.message
								? response.data.message
								: window.lcftAdmin.i18n.failed,
							'error'
						);
					} )
					.catch( function () {
						notify( window.lcftAdmin.i18n.failed, 'error' );
					} )
					.then( function () {
						button.disabled = false;
					} );
			} );
		} );
	} );
}() );
