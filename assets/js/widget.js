/**
 * The chat bubble.
 *
 * Everything the server sends is inserted as text nodes, never as markup. Message bodies are
 * written by people on both ends, and one innerHTML here would turn a support reply into a script
 * running in the member's session.
 *
 * @package Live_Chat_For_Telegram
 */

( function () {
	'use strict';

	var config = window.lcftWidget;

	if ( ! config ) {
		return;
	}

	var el = {};
	var state = {
		open: false,
		cursor: 0,
		loaded: false,
		unread: 0,
		timer: null,
		typingSentAt: 0,
		sending: false,
		pendingFile: null,
		lastGroup: null,
		replaying: false
	};

	// # HTTP ---------------------------------------------------------------------------------------

	/**
	 * Appends query parameters to a route, accounting for config.root already carrying its own
	 * query string.
	 *
	 * Sites without pretty permalinks expose the REST API as index.php?rest_route=/lcft/v1, so
	 * config.root already contains a "?". Naively appending "?since=2" after the route would add a
	 * second "?", which is not a parameter separator — everything from the first "?" onward,
	 * including that literal character, becomes part of the rest_route value, and WordPress reports
	 * 404 for a route that does not exist. Whether to join with "?" or "&" is decided from root, not
	 * from path, since path itself never carries a query string.
	 *
	 * @param {string} path   The route, relative to the namespace.
	 * @param {Object} params Query parameters to append.
	 * @return {string} The route with its query string.
	 */
	function withQuery( path, params ) {
		var query = [];

		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] !== undefined && params[ key ] !== null ) {
				query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
			}
		} );

		if ( ! query.length ) {
			return path;
		}

		var separator = config.root.indexOf( '?' ) === -1 ? '?' : '&';

		return path + separator + query.join( '&' );
	}

	/**
	 * Calls the plugin's REST API.
	 *
	 * @param {string} path   The route, relative to the namespace.
	 * @param {Object} [init] Fetch options.
	 * @return {Promise<Object>} The decoded body.
	 */
	function api( path, init ) {
		var options = init || {};

		options.credentials = 'same-origin';
		options.headers = options.headers || {};
		options.headers[ 'X-WP-Nonce' ] = config.nonce;

		return fetch( config.root + path, options ).then( function ( response ) {
			if ( response.status === 401 || response.status === 403 ) {
				throw new Error( 'expired' );
			}

			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( ( body && body.message ) || 'failed' );
				}

				return body;
			} );
		} );
	}

	// # RENDERING ----------------------------------------------------------------------------------

	/**
	 * Turns bare URLs into links, without ever parsing the text as markup.
	 *
	 * @param {string} text The message body.
	 * @return {DocumentFragment} The rendered body.
	 */
	function linkify( text ) {
		var fragment = document.createDocumentFragment();
		var pattern = /https?:\/\/[^\s<]+/g;
		var lastIndex = 0;
		var match;

		while ( ( match = pattern.exec( text ) ) !== null ) {
			if ( match.index > lastIndex ) {
				fragment.appendChild( document.createTextNode( text.slice( lastIndex, match.index ) ) );
			}

			var link = document.createElement( 'a' );
			link.href = match[ 0 ];
			link.target = '_blank';
			link.rel = 'noopener nofollow ugc';
			link.textContent = match[ 0 ];
			fragment.appendChild( link );

			lastIndex = pattern.lastIndex;
		}

		if ( lastIndex < text.length ) {
			fragment.appendChild( document.createTextNode( text.slice( lastIndex ) ) );
		}

		return fragment;
	}

	/**
	 * Formats a timestamp the way the page's language expects.
	 *
	 * @param {string} iso An ISO 8601 date.
	 * @return {string} The time of day.
	 */
	function formatTime( iso ) {
		var date = new Date( iso );

		if ( isNaN( date.getTime() ) ) {
			return '';
		}

		try {
			return new Intl.DateTimeFormat( document.documentElement.lang || undefined, {
				hour: '2-digit',
				minute: '2-digit'
			} ).format( date );
		} catch ( error ) {
			return date.toTimeString().slice( 0, 5 );
		}
	}

	/**
	 * Builds the media element for an attachment.
	 *
	 * @param {Object} attachment The attachment payload.
	 * @return {HTMLElement} The element to insert.
	 */
	function renderAttachment( attachment ) {
		if ( attachment.kind === 'photo' ) {
			var figure = document.createElement( 'button' );
			figure.type = 'button';
			figure.className = 'lcft-media lcft-media--image';

			var image = document.createElement( 'img' );
			image.src = attachment.url;
			image.alt = attachment.name || '';
			image.loading = 'lazy';
			figure.appendChild( image );

			figure.addEventListener( 'click', function () {
				openLightbox( attachment );
			} );

			return figure;
		}

		if ( attachment.kind === 'voice' || attachment.kind === 'audio' ) {
			var audio = document.createElement( 'audio' );
			audio.className = 'lcft-media lcft-media--audio';
			audio.controls = true;
			audio.preload = 'none';
			audio.src = attachment.url;

			// Telegram records voice notes as Ogg/Opus, which some versions of Safari refuse. The
			// download link is the fallback rather than a dead player.
			if ( audio.canPlayType && ! audio.canPlayType( attachment.mime ) ) {
				return renderFileCard( attachment, config.i18n.audioFallback );
			}

			return audio;
		}

		if ( attachment.kind === 'video' || attachment.kind === 'video_note' ) {
			var video = document.createElement( 'video' );
			video.className = 'lcft-media lcft-media--video';
			video.controls = true;
			video.preload = 'none';
			video.src = attachment.url;

			return video;
		}

		return renderFileCard( attachment, '' );
	}

	/**
	 * Builds a download card for a file the browser will not display.
	 *
	 * @param {Object} attachment The attachment payload.
	 * @param {string} note       An optional explanation.
	 * @return {HTMLElement} The card.
	 */
	function renderFileCard( attachment, note ) {
		var card = document.createElement( 'a' );

		card.className = 'lcft-file';
		card.href = attachment.url;
		card.rel = 'noopener';
		card.setAttribute( 'download', attachment.name || '' );

		var icon = document.createElement( 'span' );
		icon.className = 'lcft-file__icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '📄';
		card.appendChild( icon );

		var body = document.createElement( 'span' );
		body.className = 'lcft-file__body';

		var name = document.createElement( 'span' );
		name.className = 'lcft-file__name';
		name.textContent = attachment.name || config.i18n.download;
		body.appendChild( name );

		var meta = document.createElement( 'span' );
		meta.className = 'lcft-file__meta';
		meta.textContent = note ? note + ' · ' + attachment.sizeText : attachment.sizeText;
		body.appendChild( meta );

		card.appendChild( body );

		return card;
	}

	/**
	 * Shows an image full size.
	 *
	 * @param {Object} attachment The attachment payload.
	 */
	function openLightbox( attachment ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'lcft-lightbox';
		overlay.setAttribute( 'role', 'dialog' );

		var image = document.createElement( 'img' );
		image.src = attachment.url;
		image.alt = attachment.name || '';
		overlay.appendChild( image );

		function close() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKey );
		}

		function onKey( event ) {
			if ( event.key === 'Escape' ) {
				close();
			}
		}

		overlay.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( overlay );
	}

	/**
	 * Appends a message to the log.
	 *
	 * @param {Object} message The message payload.
	 * @return {HTMLElement} The bubble.
	 */
	function renderMessage( message ) {
		// Telegram splits a multi-image send into one update per image, tied together by a group
		// ID. Without this they would arrive as several bubbles a second apart.
		if ( message.attachment && message.mediaGroupId && message.mediaGroupId === state.lastGroup ) {
			var previous = el.log.lastElementChild;

			if ( previous ) {
				previous.querySelector( '.lcft-msg__media' ).appendChild( renderAttachment( message.attachment ) );

				return previous;
			}
		}

		state.lastGroup = message.mediaGroupId || null;

		var bubble = document.createElement( 'div' );
		bubble.className = 'lcft-msg lcft-msg--' + ( message.direction === 'in' ? 'me' : 'them' );
		bubble.dataset.id = message.id;

		var media = document.createElement( 'div' );
		media.className = 'lcft-msg__media';

		if ( message.attachment ) {
			media.appendChild( renderAttachment( message.attachment ) );
		}

		bubble.appendChild( media );

		if ( message.body ) {
			var body = document.createElement( 'div' );
			body.className = 'lcft-msg__body';
			body.appendChild( linkify( message.body ) );
			bubble.appendChild( body );
		}

		var meta = document.createElement( 'div' );
		meta.className = 'lcft-msg__meta';
		meta.textContent = formatTime( message.created );

		if ( message.edited ) {
			meta.textContent += ' · ' + config.i18n.edited;
		}

		if ( message.delivered === false ) {
			bubble.classList.add( 'is-undelivered' );
			meta.textContent += ' · ' + config.i18n.undelivered;
		}

		bubble.appendChild( meta );
		el.log.appendChild( bubble );

		return bubble;
	}

	/**
	 * Draws a batch of messages and moves the cursor forward.
	 *
	 * @param {Array} messages The payloads, oldest first.
	 */
	function appendMessages( messages ) {
		if ( ! messages || ! messages.length ) {
			return;
		}

		var incoming = 0;

		messages.forEach( function ( message ) {
			renderMessage( message );

			if ( message.id > state.cursor ) {
				state.cursor = message.id;
			}

			if ( message.direction === 'out' ) {
				incoming++;
			}
		} );

		scrollToEnd();

		// The first draw replays history the member has already seen. Counting it would badge the
		// bubble with the whole conversation and play a chime for messages weeks old; the server
		// has already told us how many are genuinely unread.
		if ( ! incoming || state.replaying ) {
			return;
		}

		if ( state.open && document.visibilityState === 'visible' ) {
			acknowledge();

			return;
		}

		state.unread += incoming;
		updateBadge();
		chime();
	}

	/**
	 * Scrolls the log to the newest message.
	 */
	function scrollToEnd() {
		el.log.scrollTop = el.log.scrollHeight;
	}

	/**
	 * Updates the unread counter on the bubble.
	 */
	function updateBadge() {
		el.badge.textContent = state.unread;
		el.badge.hidden = state.unread === 0;
	}

	/**
	 * Plays a short tone for an incoming reply, when the browser allows it.
	 */
	function chime() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;

			if ( ! Ctx ) {
				return;
			}

			var ctx = new Ctx();
			var oscillator = ctx.createOscillator();
			var gain = ctx.createGain();

			oscillator.frequency.value = 660;
			gain.gain.setValueAtTime( 0.05, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.0001, ctx.currentTime + 0.3 );

			oscillator.connect( gain ).connect( ctx.destination );
			oscillator.start();
			oscillator.stop( ctx.currentTime + 0.3 );
		} catch ( error ) {
			// A browser that blocks audio without a gesture is not a problem worth reporting.
		}
	}

	/**
	 * Shows or hides the closing-hours banner.
	 *
	 * @param {string} notice The message, or an empty string when open.
	 */
	function setNotice( notice ) {
		el.notice.textContent = notice || '';
		el.notice.hidden = ! notice;
	}

	// # POLLING ------------------------------------------------------------------------------------

	/**
	 * Returns how long to wait before the next check.
	 *
	 * An open bubble checks at the configured interval regardless of tab visibility: a member
	 * switching to Telegram to read a reply, or to type one, is the single most common moment for
	 * this to matter, and slowing down exactly then reads as the chat being broken. A closed bubble
	 * only needs to notice a reply for the unread badge, so it checks far less often, and not at all
	 * while both closed and hidden — a member with twenty tabs open is not twenty pollers.
	 *
	 * @return {number} Milliseconds.
	 */
	function currentInterval() {

		if ( ! state.open ) {
			return document.visibilityState === 'visible' ? 30000 : 0;
		}

		return config.pollInterval;
	}

	/**
	 * Schedules the next poll.
	 */
	function scheduleNext() {
		window.clearTimeout( state.timer );

		var delay = currentInterval();

		if ( ! delay ) {
			return;
		}

		state.timer = window.setTimeout( poll, delay );
	}

	/**
	 * Fetches anything new.
	 */
	function poll() {
		api( withQuery( '/messages', { since: state.cursor } ) )
			.then( function ( data ) {
				appendMessages( data.messages );
				setNotice( data.open ? '' : el.notice.dataset.closed || '' );
			} )
			.catch( handleError )
			.then( scheduleNext );
	}

	/**
	 * Tells the server which messages have been seen.
	 */
	function acknowledge() {
		if ( ! state.cursor ) {
			return;
		}

		state.unread = 0;
		updateBadge();

		api( '/read', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { up_to: state.cursor } )
		} ).catch( function () {
			// A read receipt that does not arrive is not worth interrupting the member for.
		} );
	}

	/**
	 * Reports a failure the member can act on, and stays quiet about the rest.
	 *
	 * @param {Error} error The failure.
	 */
	function handleError( error ) {
		if ( error && error.message === 'expired' ) {
			setNotice( config.i18n.expired );
			window.clearTimeout( state.timer );
			state.timer = null;
		}
	}

	// # SENDING ------------------------------------------------------------------------------------

	/**
	 * Sends whatever is in the composer.
	 */
	function send() {
		var text = el.input.value.trim();

		if ( state.sending || ( ! text && ! state.pendingFile ) ) {
			return;
		}

		state.sending = true;

		var body = new FormData();
		body.append( 'body', text );

		if ( state.pendingFile ) {
			body.append( 'file', state.pendingFile );
		}

		el.input.value = '';
		resizeInput();
		clearPendingFile();

		var placeholder = renderMessage( {
			id: 0,
			direction: 'in',
			body: text,
			created: new Date().toISOString(),
			delivered: true
		} );
		placeholder.classList.add( 'is-pending' );
		scrollToEnd();

		api( '/message', { method: 'POST', body: body } )
			.then( function ( data ) {
				placeholder.remove();

				if ( data.message ) {
					appendMessages( [ data.message ] );
				}

				if ( ! data.delivered ) {
					setNotice( data.error || config.i18n.failed );
				}
			} )
			.catch( function ( error ) {
				placeholder.classList.remove( 'is-pending' );
				placeholder.classList.add( 'is-undelivered' );
				handleError( error );

				if ( ! error || error.message !== 'expired' ) {
					setNotice( error.message || config.i18n.failed );
				}
			} )
			.then( function () {
				state.sending = false;
			} );
	}

	/**
	 * Tells the group the member is writing, at most once every four seconds.
	 */
	function relayTyping() {
		var now = Date.now();

		if ( now - state.typingSentAt < 4000 ) {
			return;
		}

		state.typingSentAt = now;

		api( '/typing', { method: 'POST' } ).catch( function () {
			// Purely cosmetic on the operator's side.
		} );
	}

	/**
	 * Grows the composer with its contents, up to a point.
	 */
	function resizeInput() {
		el.input.style.height = 'auto';
		el.input.style.height = Math.min( 120, el.input.scrollHeight ) + 'px';
	}

	/**
	 * Inserts text at the current caret position of the composer, replacing any selection.
	 *
	 * @param {string} text The text to insert.
	 */
	function insertAtCaret( text ) {
		var start = el.input.selectionStart;
		var end = el.input.selectionEnd;

		if ( typeof start !== 'number' || typeof end !== 'number' ) {
			el.input.value += text;
		} else {
			el.input.value = el.input.value.slice( 0, start ) + text + el.input.value.slice( end );
			el.input.selectionStart = el.input.selectionEnd = start + text.length;
		}

		el.input.focus();
		resizeInput();
	}

	/**
	 * Wires up the emoji button and its panel, when the picker is enabled.
	 */
	function initEmojiPicker() {
		var toggle = el.root.querySelector( '[data-lcft-emoji-toggle]' );
		var panel = el.root.querySelector( '[data-lcft-emoji-panel]' );

		if ( ! toggle || ! panel ) {
			return;
		}

		function close() {
			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		toggle.addEventListener( 'click', function () {
			var willOpen = panel.hidden;

			panel.hidden = ! willOpen;
			toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
		} );

		panel.querySelectorAll( '[data-lcft-emoji]' ).forEach( function ( item ) {
			item.addEventListener( 'click', function () {
				insertAtCaret( item.getAttribute( 'data-lcft-emoji' ) );
				close();
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! panel.hidden && ( ! event.target.closest || ! event.target.closest( '.lcft__emoji-wrap' ) ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				close();
			}
		} );
	}

	/**
	 * Forgets the staged file.
	 */
	function clearPendingFile() {
		state.pendingFile = null;

		if ( el.file ) {
			el.file.value = '';
		}

		el.root.classList.remove( 'has-file' );
	}

	// # OPENING ------------------------------------------------------------------------------------

	/**
	 * Loads the conversation the first time the bubble is opened.
	 *
	 * @return {Promise} Resolved once the log is drawn.
	 */
	function load() {
		if ( state.loaded ) {
			return Promise.resolve();
		}

		state.loaded = true;

		return api( '/session' )
			.then( function ( data ) {
				el.notice.dataset.closed = data.notice || '';

				if ( ! data.messages.length && data.welcome ) {
					var welcome = document.createElement( 'div' );
					welcome.className = 'lcft-welcome';
					welcome.textContent = data.welcome;
					el.log.appendChild( welcome );
				}

				state.replaying = true;
				appendMessages( data.messages );
				state.replaying = false;

				state.unread = data.unread || 0;
				updateBadge();

				setNotice( data.open ? '' : data.notice );
			} )
			.catch( handleError );
	}

	/**
	 * Opens or closes the panel.
	 *
	 * @param {boolean} open Whether the panel should be open.
	 */
	function toggle( open ) {
		state.open = open;
		el.panel.hidden = ! open;
		el.root.classList.toggle( 'is-open', open );

		if ( ! open ) {
			scheduleNext();

			return;
		}

		load().then( function () {
			scrollToEnd();
			acknowledge();
			el.input.focus();
			scheduleNext();
		} );
	}

	// # BOOT ---------------------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		el.root = document.querySelector( '[data-lcft]' );

		if ( ! el.root ) {
			return;
		}

		el.panel = el.root.querySelector( '[data-lcft-panel]' );
		el.log = el.root.querySelector( '[data-lcft-log]' );
		el.notice = el.root.querySelector( '[data-lcft-notice]' );
		el.form = el.root.querySelector( '[data-lcft-form]' );
		el.input = el.root.querySelector( '[data-lcft-input]' );
		el.file = el.root.querySelector( '[data-lcft-file]' );
		el.badge = el.root.querySelector( '[data-lcft-badge]' );

		el.root.hidden = false;

		el.root.querySelector( '[data-lcft-toggle]' ).addEventListener( 'click', function () {
			toggle( ! state.open );
		} );

		el.root.querySelector( '[data-lcft-close]' ).addEventListener( 'click', function () {
			toggle( false );
		} );

		el.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			send();
		} );

		el.input.addEventListener( 'input', function () {
			resizeInput();
			relayTyping();
		} );

		// Enter sends, Shift+Enter breaks the line — the convention every chat uses.
		el.input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				send();
			}
		} );

		initEmojiPicker();

		if ( el.file ) {
			el.file.addEventListener( 'change', function () {
				var file = el.file.files[ 0 ];

				if ( ! file ) {
					return;
				}

				if ( file.size > config.maxUpload ) {
					setNotice( config.i18n.tooLarge );
					clearPendingFile();

					return;
				}

				state.pendingFile = file;
				el.root.classList.add( 'has-file' );
			} );
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' ) {
				poll();
			} else {
				scheduleNext();
			}
		} );

		if ( config.autoOpen > 0 ) {
			window.setTimeout( function () {
				if ( ! state.open ) {
					toggle( true );
				}
			}, config.autoOpen * 1000 );
		}

		// One quiet check on load, so a reply left overnight shows a badge without the member
		// having to open anything.
		load().then( scheduleNext );
	} );
}() );
