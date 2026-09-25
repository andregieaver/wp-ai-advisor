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

		// Only a fresh start clears the stop flag; clearing it on the way down
		// would let a later phase ignore a Stop the user already pressed.
		if ( state ) {
			stopped = false;
		}

		[ 'aiadv-build', 'aiadv-crawl', 'aiadv-local', 'aiadv-resume', 'aiadv-retry', 'aiadv-clear', 'aiadv-test', 'aiadv-upload', 'aiadv-bulk-apply', 'aiadv-dedupe' ].forEach( function ( id ) {
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
			pending: stats.pending,
			fetched: stats.fetched,
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

	var MAX_ATTEMPTS = 3;

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * Repeats a stepping endpoint until it reports done or the user stops.
	 *
	 * A single failed request used to abandon the entire run and leave the queue
	 * half-drained with no way back in, so each step is retried a few times
	 * before giving up, and giving up says how to resume.
	 */
	function loop( path, label, attempt ) {
		attempt = attempt || 0;

		if ( stopped ) {
			log( strings.stopped );
			setRunning( false );

			return Promise.resolve();
		}

		return call( path, {} )
			.then( function ( data ) {
				renderStats( data.stats );

				if ( data.done ) {
					return null;
				}

				var item = data.item || {};
				var name = item.title || item.url || '';

				log( label.replace( '%s', name ) + ( item.error ? ' — ' + item.error : '' ) );

				return loop( path, label );
			} )
			.catch( function ( error ) {
				if ( attempt + 1 >= MAX_ATTEMPTS ) {
					throw new Error( ( error.message || strings.failed ) + ' ' + strings.resumeHint );
				}

				log( strings.retrying.replace( '%s', error.message || strings.failed ) );

				// Back off a little before trying the same step again.
				return wait( 1000 * ( attempt + 1 ) ).then( function () {
					return loop( path, label, attempt + 1 );
				} );
			} );
	}

	var PHASES = {
		crawl: { step: '/crawl/step', label: 'crawling' },
		local: { step: '/local/step', label: 'importing' }
	};

	/**
	 * Runs the named collection phases in order, then embeds whatever they queued.
	 */
	function runPhases( phases ) {
		var chain = Promise.resolve();
		var problems = [];

		// Each phase absorbs its own failure: a crawl that gives up must not stop
		// local content from importing, or the queue is left half-drained.
		function step( path, label ) {
			return function () {
				if ( stopped ) {
					return null;
				}

				return loop( path, label ).catch( function ( error ) {
					problems.push( error.message || strings.failed );
					log( error.message || strings.failed );
				} );
			};
		}

		phases.forEach( function ( name ) {
			var phase = PHASES[ name ];

			if ( phase ) {
				chain = chain.then( step( phase.step, strings[ phase.label ] ) );
			}
		} );

		return chain
			.then( step( '/index/step', strings.indexing ) )
			.then( function () {
				setRunning( false );

				if ( stopped ) {
					return;
				}

				if ( problems.length ) {
					log( problems[ problems.length - 1 ] );

					return;
				}

				log( strings.done );
				window.location.reload();
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	/**
	 * Seeds every phase the configured source mode asks for, then runs them.
	 */
	function build() {
		setRunning( true );
		log( strings.preparing );

		call( '/build/start', {} )
			.then( function ( data ) {
				renderStats( data.stats );

				return runPhases( data.phases || [] );
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	/**
	 * Seeds and runs a single collection phase.
	 */
	function runOne( name, startPath ) {
		setRunning( true );
		log( strings.preparing );

		call( startPath, {} )
			.then( function ( data ) {
				renderStats( data.stats );

				return runPhases( [ name ] );
			} )
			.catch( function ( error ) {
				log( error.message || strings.failed );
				setRunning( false );
			} );
	}

	/**
	 * Picks up wherever the last run stopped: fetches whatever is still pending,
	 * then embeds whatever is waiting. Clears nothing.
	 */
	function resume() {
		setRunning( true );
		log( strings.preparing );

		runPhases( [ 'crawl', 'local' ] );
	}

	/**
	 * Requeues failed sources, then resumes.
	 */
	function retryFailed() {
		setRunning( true );
		log( strings.preparing );

		call( '/sources/retry', {} )
			.then( function ( data ) {
				renderStats( data.stats );

				return runPhases( [ 'crawl', 'local' ] );
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
		var language = byId( 'aiadv-file-language' );

		data.append( 'file', input.files[0] );

		if ( language ) {
			data.append( 'language', language.value );
		}

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

	function selectedIds() {
		return Array.prototype.slice
			.call( document.querySelectorAll( '.aiadv-admin__select:checked' ) )
			.map( function ( box ) {
				return box.value;
			} );
	}

	function updateSelectedCount() {
		var node = byId( 'aiadv-selected' );

		if ( node ) {
			var count = selectedIds().length;

			node.textContent = count ? strings.selected.replace( '%d', count ) : '';
		}
	}

	function applyBulk( action, ids, confirmMessage ) {
		if ( ! ids.length ) {
			log( strings.nothingSelected );

			return;
		}

		if ( confirmMessage && ! window.confirm( confirmMessage.replace( '%d', ids.length ) ) ) {
			return;
		}

		setRunning( true );

		call( '/sources/bulk', { action: action, ids: ids } )
			.then( function () {
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

	/**
	 * Repeatable category question sets.
	 *
	 * Rows are indexed by position in the field name, so a removed row would
	 * leave a gap; the indexes are rewritten after every change rather than
	 * relying on PHP to tolerate a sparse array.
	 */
	function reindexSets() {
		var container = byId( 'aiadv-sets' );

		if ( ! container ) {
			return;
		}

		Array.prototype.forEach.call(
			container.querySelectorAll( '.aiadv-admin__set' ),
			function ( row, index ) {
				Array.prototype.forEach.call(
					row.querySelectorAll( '[name]' ),
					function ( field ) {
						field.name = field.name.replace( /\[suggestion_sets\]\[[^\]]*\]/, '[suggestion_sets][' + index + ']' );
					}
				);
			}
		);
	}

	function bindSetRemoval( row ) {
		var remove = row.querySelector( '.aiadv-admin__remove-set' );

		if ( remove ) {
			remove.addEventListener( 'click', function () {
				row.parentNode.removeChild( row );
				reindexSets();
			} );
		}
	}

	function setupSets() {
		var container = byId( 'aiadv-sets' );
		var template = byId( 'aiadv-set-template' );
		var add = byId( 'aiadv-add-set' );

		if ( ! container ) {
			return;
		}

		Array.prototype.forEach.call( container.querySelectorAll( '.aiadv-admin__set' ), bindSetRemoval );

		if ( ! add || ! template ) {
			return;
		}

		add.addEventListener( 'click', function () {
			var holder = document.createElement( 'div' );

			holder.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( Date.now() ) );

			var row = holder.querySelector( '.aiadv-admin__set' );

			if ( ! row ) {
				return;
			}

			container.appendChild( row );
			bindSetRemoval( row );
			reindexSets();
			row.querySelector( 'input' ).focus();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupSets();

		bind( 'aiadv-build', build );

		bind( 'aiadv-crawl', function () {
			runOne( 'crawl', '/crawl/start' );
		} );

		bind( 'aiadv-local', function () {
			runOne( 'local', '/local/start' );
		} );

		bind( 'aiadv-resume', resume );
		bind( 'aiadv-retry', retryFailed );

		bind( 'aiadv-bulk-apply', function () {
			var action = byId( 'aiadv-bulk-action' );

			if ( ! action || ! action.value ) {
				log( strings.nothingSelected );

				return;
			}

			applyBulk(
				action.value,
				selectedIds(),
				'delete' === action.value ? strings.confirmDelete : null
			);
		} );

		bind( 'aiadv-dedupe', function () {
			applyBulk( 'duplicates', [ 'all' ], strings.confirmDuplicates );
		} );

		var selectAll = byId( 'aiadv-select-all' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				Array.prototype.forEach.call(
					document.querySelectorAll( '.aiadv-admin__select' ),
					function ( box ) {
						box.checked = selectAll.checked;
					}
				);

				updateSelectedCount();
			} );
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '.aiadv-admin__select' ),
			function ( box ) {
				box.addEventListener( 'change', updateSelectedCount );
			}
		);
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
