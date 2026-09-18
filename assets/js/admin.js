/**
 * Knowledge-base controls on the AI Advisor settings screen.
 *
 * Crawling and embedding run one source per request so a large site cannot
 * exhaust PHP's time limit; this script drives the loop and reports progress.
 */
( function () {
	'use strict';

	var config = window.wpAiAdvisorAdmin || {};
	var strings = config.strings || {};
	var stopped = false;
	var running = false;

	function byId( id ) {
		return document.getElementById( id );
	}

	function log( message ) {
		var node = byId( 'aiadv-log' );

		if ( node ) {
			node.textContent = message;
		}
	}

	function setRunning( state ) {
		running = state;
		stopped = false;

		[ 'aiadv-crawl', 'aiadv-index', 'aiadv-clear', 'aiadv-test', 'aiadv-upload' ].forEach( function ( id ) {
			var button = byId( id );

			if ( button ) {
				button.disabled = state;
			}
		} );

		var stop = byId( 'aiadv-stop' );

		if ( stop ) {
			stop.disabled = ! state;
		}
	}

	function renderStats( stats ) {
		if ( ! stats ) {
			return;
		}

		var container = byId( 'aiadv-stats' );

		if ( ! container ) {
			return;
		}

		var values = {
			total: stats.total,
			indexed: stats.indexed,
			pending: ( stats.pending || 0 ) + ( stats.fetched || 0 ),
			error: stats.error,
			chunks: stats.chunks
		};

		Object.keys( values ).forEach( function ( key ) {
			var node = container.querySelector( '[data-stat="' + key + '"]' );

			if ( node ) {
				node.textContent = values[ key ];
			}
		} );
	}

	function call( path, body, isForm ) {
		var options = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		};

		if ( isForm ) {
			options.body = body;
		} else {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( body || {} );
		}

		return window.fetch( config.root + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || strings.failed );
				}

				return data;
			} );
		} );
	}

	/**
	 * Repeats a stepping endpoint until it reports done, the user stops, or it errors.
	 */
	function loop( path, label ) {
		if ( stopped ) {
			log( strings.stopped );
			setRunning( false );

			return Promise.resolve();
		}

		return call( path, {} ).then( function ( data ) {
			renderStats( data.stats );

			if ( data.done ) {
				return null;
			}

			var item = data.item || {};
			var name = item.title || item.url || '';

			log( label.replace( '%s', name ) + ( item.error ? ' — ' + item.error : '' ) );

			return loop( path, label );
		} );
	}

	function crawl() {
		setRunning( true );
		log( strings.crawling.replace( '%s', '…' ) );

		call( '/crawl/start', {} )
			.then( function ( data ) {
				renderStats( data.stats );

				return loop( '/crawl/step', strings.crawling );
			} )
			.then( function () {
				if ( stopped ) {
					return null;
				}

				return loop( '/index/step', strings.indexing );
			} )
			.then( function () {
				if ( ! stopped ) {
					log( strings.done );
					setRunning( false );
					window.location.reload();
				}
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	function indexOnly() {
		setRunning( true );

		loop( '/index/step', strings.indexing )
			.then( function () {
				if ( ! stopped ) {
					log( strings.done );
					setRunning( false );
					window.location.reload();
				}
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	function upload() {
		var input = byId( 'aiadv-file' );

		if ( ! input || ! input.files || ! input.files.length ) {
			return;
		}

		var data = new window.FormData();

		data.append( 'file', input.files[0] );

		setRunning( true );
		log( strings.uploading );

		call( '/documents', data, true )
			.then( function ( result ) {
				renderStats( result.stats );

				// A new document lands as "fetched" and still needs embedding.
				return loop( '/index/step', strings.indexing );
			} )
			.then( function () {
				log( strings.done );
				setRunning( false );
				window.location.reload();
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	function bind( id, handler ) {
		var button = byId( id );

		if ( button ) {
			button.addEventListener( 'click', handler );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bind( 'aiadv-crawl', crawl );
		bind( 'aiadv-index', indexOnly );
		bind( 'aiadv-upload', upload );

		bind( 'aiadv-stop', function () {
			stopped = true;
			log( strings.stopped );
		} );

		bind( 'aiadv-test', function () {
			log( strings.testing );

			call( '/test', {} )
				.then( function ( data ) {
					log( data.message );
				} )
				.catch( function ( error ) {
					log( error.message || strings.failed );
				} );
		} );

		bind( 'aiadv-clear', function () {
			if ( ! window.confirm( strings.confirm ) ) {
				return;
			}

			call( '/clear', {} )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( error ) {
					log( error.message || strings.failed );
				} );
		} );

		Array.prototype.forEach.call(
			document.querySelectorAll( '.aiadv-admin__delete' ),
			function ( button ) {
				button.addEventListener( 'click', function () {
					call( '/sources/delete', { id: button.getAttribute( 'data-id' ) } )
						.then( function () {
							window.location.reload();
						} )
						.catch( function ( error ) {
							log( error.message || strings.failed );
						} );
				} );
			}
		);

		// Warn before navigating away mid-crawl.
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( running ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );
} )();
