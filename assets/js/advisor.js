/**
 * Front-end behaviour for the [ai_advisor] conversation container.
 */
( function () {
	'use strict';

	var config = window.wpAiAdvisor || {};
	var strings = config.strings || {};

	var ICONS = {
		clock: 'M12 7v5l3 2M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18z',
		arrow: 'M5 12h14M12 5l7 7-7 7'
	};

	function svg( path ) {
		var node = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		var shape = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );

		node.setAttribute( 'viewBox', '0 0 24 24' );
		node.setAttribute( 'aria-hidden', 'true' );
		node.setAttribute( 'focusable', 'false' );
		shape.setAttribute( 'd', path );
		node.appendChild( shape );

		return node;
	}

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

	function Widget( root ) {
		this.root = root;
		this.panel = root.querySelector( '.aiadv__panel' );
		this.log = root.querySelector( '.aiadv__log' );
		this.form = root.querySelector( '.aiadv__form' );
		this.input = root.querySelector( '.aiadv__input' );
		this.send = root.querySelector( '.aiadv__send' );
		this.notice = root.querySelector( '.aiadv__notice' );
		this.launch = root.querySelector( '.aiadv__launch' );
		this.close = root.querySelector( '.aiadv__close' );
		this.history = [];
		this.busy = false;
		this.language = root.getAttribute( 'data-language' ) || '';
	}

	Widget.prototype.init = function () {
		var self = this;

		if ( ! this.panel || ! this.form || ! this.input ) {
			return;
		}

		if ( this.launch ) {
			this.launch.addEventListener( 'click', function () {
				self.open();
			} );
		}

		if ( this.close ) {
			this.close.addEventListener( 'click', function () {
				self.collapse();
			} );
		}

		Array.prototype.forEach.call(
			this.root.querySelectorAll( '.aiadv__suggestion' ),
			function ( button ) {
				button.addEventListener( 'click', function () {
					self.open();
					self.ask( button.getAttribute( 'data-question' ) || button.textContent.trim() );
				} );
			}
		);

		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.ask( self.input.value );
		} );

		// Enter sends, Shift+Enter makes a new line.
		this.input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				self.ask( self.input.value );
			}
		} );

		// Grow the composer with its content, up to the CSS max-height.
		this.input.addEventListener( 'input', function () {
			self.input.style.height = 'auto';
			self.input.style.height = self.input.scrollHeight + 'px';
		} );

		this.root.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && self.root.classList.contains( 'is-open' ) ) {
				self.collapse();
			}
		} );
	};

	Widget.prototype.open = function () {
		this.root.classList.add( 'is-open' );
		this.panel.hidden = false;

		if ( this.launch ) {
			this.launch.setAttribute( 'aria-expanded', 'true' );
		}

		this.input.focus();
	};

	Widget.prototype.collapse = function () {
		this.root.classList.remove( 'is-open' );
		this.panel.hidden = true;

		if ( this.launch ) {
			this.launch.setAttribute( 'aria-expanded', 'false' );
			this.launch.focus();
		}
	};

	Widget.prototype.showError = function ( message ) {
		this.notice.textContent = message;
		this.notice.hidden = false;
	};

	Widget.prototype.clearError = function () {
		this.notice.textContent = '';
		this.notice.hidden = true;
	};

	Widget.prototype.addMessage = function ( role, text ) {
		var wrapper = el( 'div', 'aiadv__message aiadv__message--' + role );

		wrapper.appendChild( el( 'span', 'screen-reader-text', 'assistant' === role ? strings.advisor : strings.you ) );
		wrapper.appendChild( el( 'p', 'aiadv__text', text ) );

		this.log.appendChild( wrapper );
		this.log.scrollTop = this.log.scrollHeight;

		return wrapper;
	};

	/**
	 * Renders the chips under an answer: follow-up questions, site links, CTA.
	 */
	Widget.prototype.addActions = function ( wrapper, data ) {
		var self = this;
		var actions = el( 'div', 'aiadv__actions' );
		var used = false;

		( data.followups || [] ).forEach( function ( question ) {
			var chip = el( 'button', 'aiadv__chip' );

			chip.type = 'button';
			chip.appendChild( el( 'span', null, question ) );
			chip.addEventListener( 'click', function () {
				self.ask( question );
			} );

			actions.appendChild( chip );
			used = true;
		} );

		( data.links || [] ).forEach( function ( link ) {
			var chip = el( 'a', 'aiadv__chip aiadv__chip--link' );

			chip.href = link.url;
			chip.appendChild( el( 'span', null, link.label ) );
			chip.appendChild( svg( ICONS.arrow ) );

			actions.appendChild( chip );
			used = true;
		} );

		if ( data.cta && data.cta.url && data.cta.label ) {
			var cta = el( 'a', 'aiadv__chip aiadv__chip--cta' );

			cta.href = data.cta.url;
			cta.appendChild( svg( ICONS.clock ) );
			cta.appendChild( el( 'span', null, data.cta.label ) );

			actions.appendChild( cta );
			used = true;
		}

		if ( used ) {
			wrapper.appendChild( actions );
			this.log.scrollTop = this.log.scrollHeight;
		}
	};

	Widget.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		this.send.disabled = busy;
	};

	Widget.prototype.ask = function ( rawQuestion ) {
		var self = this;
		var question = ( rawQuestion || '' ).trim();

		if ( this.busy || ! question ) {
			return;
		}

		this.clearError();
		this.addMessage( 'user', question );
		this.input.value = '';
		this.input.style.height = 'auto';
		this.setBusy( true );

		var pending = this.addMessage( 'assistant', strings.thinking );
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
					history: this.history,
					language: this.language
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
					self.showError( ( result.data && result.data.message ) || strings.error );

					return;
				}

				var wrapper = self.addMessage( 'assistant', result.data.answer );
				self.addActions( wrapper, result.data );

				self.history.push( { role: 'user', content: question } );
				self.history.push( { role: 'assistant', content: result.data.answer } );
				self.history = self.history.slice( -8 );
			} )
			.catch( function () {
				pending.remove();
				self.showError( strings.error );
			} )
			.then( function () {
				self.setBusy( false );
				self.input.focus();
			} );
	};

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.aiadv' ), function ( root ) {
			new Widget( root ).init();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
