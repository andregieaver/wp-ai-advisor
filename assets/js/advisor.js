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

	/**
	 * Renders a small, fixed subset of Markdown into a container.
	 *
	 * Answers arrive as Markdown, so raw ** and - have to stop showing up on the
	 * page. Every node is built with createElement and textContent rather than
	 * innerHTML: the text comes from a model reading site content and visitor
	 * input, so it is never treated as markup.
	 *
	 * Supported: paragraphs, bullet and numbered lists, headings, bold, italic,
	 * inline code and links. Anything else stays as literal text.
	 */
	var INLINE = /(\*\*[^*]+\*\*|__[^_]+__|\*[^*\n]+\*|_[^_\n]+_|`[^`]+`|\[[^\]]+\]\([^)\s]+\))/;

	/**
	 * Endings treated as domains when the text carries no scheme.
	 *
	 * Mirrors WP_AI_Advisor_Text::known_tlds(): without a scheme, a missing
	 * space after a full stop looks exactly like a domain, so an unknown ending
	 * is left as prose. Every two-letter ending is accepted as a country code.
	 */
	var TLDS = [
		'com', 'net', 'org', 'info', 'biz', 'edu', 'gov', 'int',
		'shop', 'store', 'app', 'dev', 'ai', 'cloud', 'online',
		'site', 'tech', 'email', 'blog', 'news', 'agency', 'studio',
		'design', 'digital', 'group', 'media', 'company', 'solutions',
		'coffee', 'cafe', 'bar', 'restaurant', 'services'
	];

	var FILE_ENDINGS = [
		'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
		'zip', 'rar', 'mp3', 'mp4', 'mov', 'js', 'css', 'html', 'htm',
		'php', 'json', 'xml', 'exe', 'dmg'
	];

	// Addresses written in running text: a full URL, a bare domain, or an email.
	var AUTOLINK = /(https?:\/\/[^\s<>"')\]]+|[^\s<>()[\]]+@[a-z0-9.-]+\.[a-z]{2,24}|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?:\/[^\s<>"')\]]*)?)/i;

	/**
	 * Whether a schemeless string reads as a domain rather than as prose.
	 */
	function looksLikeDomain( candidate ) {
		var host = candidate.split( '/' )[0];

		if ( ! /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/i.test( host ) ) {
			return false;
		}

		var labels = host.split( '.' );
		var tld = labels.pop().toLowerCase();

		// "f.eks" and "bl.a" are abbreviations, not hosts.
		if ( labels[0].length < 2 ) {
			return false;
		}

		if ( FILE_ENDINGS.indexOf( tld ) !== -1 ) {
			return false;
		}

		return 2 === tld.length || TLDS.indexOf( tld ) !== -1;
	}

	/**
	 * Turns an address found in running text into an href, or returns null.
	 */
	function autoHref( token ) {
		var trimmed = token.replace( /[.,;:!?)\]}'"]+$/, '' );

		if ( ! trimmed ) {
			return null;
		}

		if ( /^https?:\/\//i.test( trimmed ) ) {
			return { href: trimmed, label: trimmed, tail: token.slice( trimmed.length ) };
		}

		if ( /^[^\s@]+@[a-z0-9.-]+\.[a-z]{2,24}$/i.test( trimmed ) ) {
			return { href: 'mailto:' + trimmed, label: trimmed, tail: token.slice( trimmed.length ) };
		}

		if ( looksLikeDomain( trimmed ) ) {
			return { href: 'https://' + trimmed, label: trimmed, tail: token.slice( trimmed.length ) };
		}

		return null;
	}

	/**
	 * Appends text, turning any address inside it into a link.
	 */
	function appendAutolinked( text, parent ) {
		String( text ).split( AUTOLINK ).forEach( function ( part ) {
			if ( ! part ) {
				return;
			}

			var found = AUTOLINK.test( part ) ? autoHref( part ) : null;

			if ( ! found ) {
				parent.appendChild( document.createTextNode( part ) );

				return;
			}

			var anchor = el( 'a', null, found.label );

			anchor.href = found.href;
			anchor.rel = 'noopener';
			parent.appendChild( anchor );

			if ( found.tail ) {
				parent.appendChild( document.createTextNode( found.tail ) );
			}
		} );
	}

	function safeHref( url ) {
		var trimmed = String( url ).trim();

		// Relative paths and http(s) only: no javascript:, no data:.
		if ( /^\//.test( trimmed ) || /^https?:\/\//i.test( trimmed ) ) {
			return trimmed;
		}

		return null;
	}

	function renderInline( text, parent ) {
		var parts = String( text ).split( INLINE );

		parts.forEach( function ( part ) {
			if ( ! part ) {
				return;
			}

			var link = part.match( /^\[([^\]]+)\]\(([^)\s]+)\)$/ );

			if ( link ) {
				var href = safeHref( link[2] );

				if ( href ) {
					var anchor = el( 'a', null, link[1] );

					anchor.href = href;
					anchor.rel = 'noopener';
					parent.appendChild( anchor );
				} else {
					parent.appendChild( document.createTextNode( link[1] ) );
				}

				return;
			}

			if ( /^\*\*[^*]+\*\*$/.test( part ) || /^__[^_]+__$/.test( part ) ) {
				parent.appendChild( el( 'strong', null, part.slice( 2, -2 ) ) );

				return;
			}

			if ( /^\*[^*\n]+\*$/.test( part ) || /^_[^_\n]+_$/.test( part ) ) {
				parent.appendChild( el( 'em', null, part.slice( 1, -1 ) ) );

				return;
			}

			if ( /^`[^`]+`$/.test( part ) ) {
				parent.appendChild( el( 'code', null, part.slice( 1, -1 ) ) );

				return;
			}

			appendAutolinked( part, parent );
		} );
	}

	function listKind( line ) {
		if ( /^\s*[-*+]\s+/.test( line ) ) {
			return 'ul';
		}

		if ( /^\s*\d+[.)]\s+/.test( line ) ) {
			return 'ol';
		}

		return null;
	}

	function renderMarkdown( text, container ) {
		var blocks = String( text ).replace( /\r\n?/g, '\n' ).split( /\n{2,}/ );
		var rich = false;

		blocks.forEach( function ( block ) {
			var lines = block.split( '\n' ).filter( function ( line ) {
				return line.trim().length;
			} );

			if ( ! lines.length ) {
				return;
			}

			var kind = listKind( lines[0] );

			if ( kind ) {
				var list = el( kind, 'aiadv__list' );

				lines.forEach( function ( line ) {
					if ( ! listKind( line ) ) {
						// A wrapped continuation line belongs to the item above.
						var previous = list.lastChild;

						if ( previous ) {
							previous.appendChild( document.createTextNode( ' ' ) );
							renderInline( line.trim(), previous );
						}

						return;
					}

					var item = el( 'li' );

					renderInline( line.replace( /^\s*(?:[-*+]|\d+[.)])\s+/, '' ), item );
					list.appendChild( item );
				} );

				container.appendChild( list );
				rich = true;

				return;
			}

			var heading = lines[0].match( /^\s*#{1,6}\s+(.*)$/ );

			if ( heading && 1 === lines.length ) {
				var head = el( 'p', 'aiadv__subhead' );

				renderInline( heading[1], head );
				container.appendChild( head );
				rich = true;

				return;
			}

			var paragraph = el( 'p', 'aiadv__text' );

			lines.forEach( function ( line, index ) {
				if ( index ) {
					paragraph.appendChild( document.createElement( 'br' ) );
				}

				renderInline( line.trim(), paragraph );
			} );

			container.appendChild( paragraph );
		} );

		if ( rich || blocks.length > 1 ) {
			container.classList.add( 'is-rich' );
		}
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
		this.postId = parseInt( root.getAttribute( 'data-post-id' ), 10 ) || 0;
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

	Widget.prototype.showDetail = function ( message ) {
		var detail = el( 'span', 'aiadv__notice-detail', message );

		this.notice.appendChild( document.createElement( 'br' ) );
		this.notice.appendChild( detail );
	};

	Widget.prototype.addMessage = function ( role, text, markdown ) {
		var wrapper = el( 'div', 'aiadv__message aiadv__message--' + role );

		wrapper.appendChild( el( 'span', 'screen-reader-text', 'assistant' === role ? strings.advisor : strings.you ) );

		if ( markdown ) {
			renderMarkdown( text, wrapper );
		} else {
			wrapper.appendChild( el( 'p', 'aiadv__text', text ) );
		}

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
					language: this.language,
					post_id: this.postId
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
					var data = result.data || {};
					var notice = data.data && data.data.admin_notice;

					self.showError( data.message || strings.error );

					// Administrators also get the underlying reason, which is
					// the only place it is visible while testing.
					if ( notice ) {
						self.showDetail( notice );
					}

					return;
				}

				var wrapper = self.addMessage( 'assistant', result.data.answer, true );
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
