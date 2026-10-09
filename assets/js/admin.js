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
					field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
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

	/**
	 * Fills {placeholders} the way LCFT_Format::render() does: known ones replaced, unknown ones
	 * removed.
	 *
	 * @param {string} template The text.
	 * @param {Object} tokens   Name => value.
	 * @return {string} The filled text.
	 */
	function fillPlaceholders( template, tokens ) {
		return String( template || '' )
			.replace( /\{([a-z0-9_]+)\}/gi, function ( match, name ) {
				return Object.prototype.hasOwnProperty.call( tokens, name ) ? tokens[ name ] : '';
			} )
			.trim();
	}

	/**
	 * Keeps the preview beside the widget and hours tabs in step with the fields being edited.
	 *
	 * Each field is read when present on the page and falls back to the saved value otherwise,
	 * since only one tab's fields exist at a time.
	 */
	function initPreview() {
		var root = document.querySelector( '[data-lcft-preview]' );
		var data = window.lcftAdmin.preview;

		if ( ! root || ! data ) {
			return;
		}

		var widget = root.querySelector( '[data-lcft-preview-widget]' );
		var invite = root.querySelector( '[data-lcft-preview-invite]' );
		var panel = root.querySelector( '[data-lcft-preview-panel]' );
		var notice = root.querySelector( '[data-lcft-preview-notice]' );
		var welcome = root.querySelector( '[data-lcft-preview-welcome]' );
		var name = root.querySelector( '[data-lcft-preview-name]' );
		var avatar = root.querySelector( '[data-lcft-preview-avatar]' );
		var hint = root.querySelector( '[data-lcft-preview-hint]' );
		var sample = root.querySelector( '[data-lcft-preview-sample]' );
		var buttons = root.querySelectorAll( '[data-lcft-preview-state]' );
		var state = root.getAttribute( 'data-state' ) || 'present';

		function field( id ) {
			return document.getElementById( id );
		}

		function value( id, fallback ) {
			var input = field( id );

			if ( ! input ) {
				return fallback;
			}

			return input.type === 'checkbox' ? input.checked : input.value;
		}

		// Message times read like the real widget's: the member's own clock, a few minutes ago.
		Array.prototype.forEach.call( root.querySelectorAll( '[data-lcft-preview-time]' ), function ( meta ) {
			var date = new Date( Date.now() + parseInt( meta.getAttribute( 'data-lcft-preview-time' ), 10 ) * 60000 );

			try {
				meta.textContent = new Intl.DateTimeFormat( document.documentElement.lang || undefined, {
					hour: '2-digit',
					minute: '2-digit'
				} ).format( date );
			} catch ( error ) {
				meta.textContent = date.toTimeString().slice( 0, 5 );
			}
		} );

		function update() {
			var open = state === 'present' || state === 'absent';
			var position = value( 'widget_position', data.position ) === 'left' ? 'lcft--left' : 'lcft--right';
			var accent = value( 'widget_accent', data.accent ) || '#1c3f94';
			var avatarUrl = value( 'agent_avatar_url', data.avatar );
			var welcomeText = fillPlaceholders( value( 'welcome_message', data.welcome ), data.tokens );
			var closedText = fillPlaceholders( value( 'closed_message', data.closedMessage ), data.tokens );
			var withSample = sample && sample.checked;
			var hints = [];

			[ widget, invite ].forEach( function ( element ) {
				element.classList.remove( 'lcft--left', 'lcft--right' );
				element.classList.add( position );
				element.style.setProperty( '--lcft-accent', accent );
			} );

			widget.hidden = state === 'invite';
			invite.hidden = state !== 'invite';
			widget.classList.toggle( 'is-open', open );
			panel.hidden = ! open;

			name.textContent = value( 'agent_name', data.agentName );
			avatar.hidden = ! avatarUrl;

			if ( avatarUrl && avatar.getAttribute( 'src' ) !== avatarUrl ) {
				avatar.src = avatarUrl;
			}

			notice.textContent = closedText || data.autoNotice;
			notice.hidden = state !== 'absent';

			// As in the widget: the welcome only stands in for an empty conversation.
			welcome.textContent = welcomeText;
			welcome.hidden = withSample || ! welcomeText;

			if ( sample ) {
				sample.closest( 'label' ).hidden = ! open;
			}

			Array.prototype.forEach.call( root.querySelectorAll( '[data-lcft-preview-sample-item]' ), function ( item ) {
				item.hidden = ! withSample;
			} );

			Array.prototype.forEach.call( buttons, function ( button ) {
				var active = button.getAttribute( 'data-lcft-preview-state' ) === state;

				button.classList.toggle( 'is-active', active );
				button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );

			if ( value( 'widget_theme_accent', data.themeAccent ) ) {
				hints.push( data.i18n.themeAccent );
			}

			if ( state === 'absent' && ! closedText ) {
				hints.push( data.i18n.autoNotice );
			}

			if ( state === 'invite' && value( 'logged_out_mode', data.inviteMode ) !== 'invite' ) {
				hints.push( data.i18n.inviteOff );
			}

			if ( open && welcomeText && withSample ) {
				hints.push( data.i18n.welcomeOnly );
			}

			hint.textContent = hints.join( ' ' );
			hint.hidden = ! hints.length;
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				state = button.getAttribute( 'data-lcft-preview-state' );
				update();
			} );
		} );

		if ( sample ) {
			sample.addEventListener( 'change', update );
		}

		// Delegated, so placeholder insertions and the media picker, which fire input events, are
		// caught along with typing.
		var form = root.closest( 'form' );

		if ( form ) {
			form.addEventListener( 'input', update );
			form.addEventListener( 'change', update );
		}

		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		defendTabs();
		initMediaPicker();
		initPlaceholderPickers();
		initPreview();

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
