/**
 * Front-end behaviour for the [ai_advisor] widget.
 */
( function () {
	'use strict';

	var config = window.wpAiAdvisor || {};
	var strings = config.strings || {};

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	function addMessage( log, role, text ) {
		var wrapper = el( 'div', 'wp-ai-advisor__message wp-ai-advisor__message--' + role );
		var label = el( 'span', 'wp-ai-advisor__role', 'assistant' === role ? strings.advisor : strings.you );
		var body = el( 'p', 'wp-ai-advisor__text', text );

		wrapper.appendChild( label );
		wrapper.appendChild( body );
		log.appendChild( wrapper );
		log.scrollTop = log.scrollHeight;

		return wrapper;
	}

	function addSources( wrapper, sources ) {
		if ( ! sources || ! sources.length ) {
			return;
		}

		var list = el( 'ul', 'wp-ai-advisor__sources' );
		var heading = el( 'span', 'wp-ai-advisor__sources-title', strings.sources );

		sources.forEach( function ( source ) {
			var item = el( 'li' );
			var link = el( 'a', null, source.title );

			link.href = source.url;
			item.appendChild( link );
			list.appendChild( item );
		} );

		wrapper.appendChild( heading );
		wrapper.appendChild( list );
	}

	function setup( widget ) {
		var form = widget.querySelector( '.wp-ai-advisor__form' );
		var input = widget.querySelector( '.wp-ai-advisor__input' );
		var button = widget.querySelector( '.wp-ai-advisor__submit' );
		var log = widget.querySelector( '.wp-ai-advisor__log' );
		var notice = widget.querySelector( '.wp-ai-advisor__notice' );
		var history = [];
		var busy = false;

		if ( ! form || ! input || ! log ) {
			return;
		}

		function showError( message ) {
			notice.textContent = message;
			notice.hidden = false;
		}

		function clearError() {
			notice.textContent = '';
			notice.hidden = true;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( busy ) {
				return;
			}

			var question = input.value.trim();

			if ( ! question ) {
				return;
			}

			clearError();
			addMessage( log, 'user', question );
			input.value = '';

			busy = true;
			button.disabled = true;

			var pending = addMessage( log, 'assistant', strings.thinking );
			pending.classList.add( 'is-pending' );

			window
				.fetch( config.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce
					},
					body: JSON.stringify( {
						question: question,
						history: history
					} )
				} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					pending.remove();

					if ( ! result.ok ) {
						showError( ( result.data && result.data.message ) || strings.error );

						return;
					}

					var wrapper = addMessage( log, 'assistant', result.data.answer );
					addSources( wrapper, result.data.sources );

					history.push( { role: 'user', content: question } );
					history.push( { role: 'assistant', content: result.data.answer } );
					history = history.slice( -10 );
				} )
				.catch( function () {
					pending.remove();
					showError( strings.error );
				} )
				.finally( function () {
					busy = false;
					button.disabled = false;
					input.focus();
				} );
		} );

		// Enter submits, Shift+Enter adds a newline.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var widgets = document.querySelectorAll( '.wp-ai-advisor' );

		Array.prototype.forEach.call( widgets, setup );
	} );
} )();
