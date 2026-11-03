/**
 * VitalLens admin behaviour.
 *
 * No framework and no build step: a few buttons, a checkbox column and a
 * clipboard call do not justify a toolchain, and a plugin that ships
 * un-minified source is one a host can debug without asking us for anything.
 */
( function () {
	'use strict';

	var cfg = window.vitallensAdmin || {};
	var i18n = cfg.i18n || {};

	/**
	 * Write a message into one of the aria-live result lines.
	 *
	 * @param {HTMLElement} el    Target.
	 * @param {string}      text  Message.
	 * @param {string}      state 'ok' | 'bad' | 'busy'.
	 */
	function say( el, text, state ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		el.className = 'vl-result' + ( state ? ' is-' + state : '' );
	}

	/**
	 * POST to admin-ajax with the plugin's nonce.
	 *
	 * @param {string} action Action name.
	 * @param {Object} data   Extra fields.
	 * @return {Promise<Object>} Parsed response.
	 */
	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Wire a button that calls an action and reports into a result line.
	 *
	 * @param {string}   buttonId Button element id.
	 * @param {string}   resultId Result element id.
	 * @param {string}   action   Ajax action.
	 * @param {string}   busyText Text while in flight.
	 * @param {Function} payload  Returns the fields to send.
	 */
	function wireAction( buttonId, resultId, action, busyText, busyDetail, payload ) {
		var button = document.getElementById( buttonId );
		var result = document.getElementById( resultId );
		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var original = button.textContent;
			button.disabled = true;
			button.textContent = busyText;
			// Not the button's own label again: the button already says
			// "Running…", and repeating it underneath told nobody anything
			// about a wait that can run to a minute.
			say( result, busyDetail || busyText, 'busy' );

			post( action, payload ? payload() : {} )
				.then( function ( res ) {
					var message = ( res && res.data && res.data.message ) || i18n.failed;
					if ( res && res.success ) {
						// A partial run is still worth reading in full.
						if ( res.data.errors && res.data.errors.length ) {
							message += ' — ' + res.data.errors.join( '; ' );
						}
						// A run can succeed as a request and still have
						// measured nothing, so the server says which it was.
						say( result, message, res.data.tone === 'bad' ? 'bad' : 'ok' );
						// A re-check rewrites the numbers this screen is built
						// from, so the server asks for a reload rather than
						// leaving stale figures under a success message.
						if ( res.data.reload ) {
							window.setTimeout( function () {
								window.location.reload();
							}, 900 );
							return;
						}
					} else {
						say( result, message, 'bad' );
					}
				} )
				.catch( function () {
					say( result, i18n.failed, 'bad' );
				} )
				.then( function () {
					button.disabled = false;
					button.textContent = original;
				} );
		} );
	}

	/**
	 * Copy buttons for the cron commands.
	 */
	function wireCopy() {
		document.querySelectorAll( '[data-vl-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var text = button.getAttribute( 'data-vl-copy' ) || '';
				var done = function () {
					button.textContent = i18n.copied || 'Copied';
					window.setTimeout( function () {
						button.textContent = i18n.copy || 'Copy';
					}, 1600 );
				};

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done, done );
					return;
				}

				// execCommand is deprecated but it is the only thing that works
				// on a site served over plain HTTP, which plenty of staging
				// installs still are.
				var scratch = document.createElement( 'textarea' );
				scratch.value = text;
				scratch.setAttribute( 'readonly', '' );
				scratch.style.position = 'absolute';
				scratch.style.left = '-9999px';
				document.body.appendChild( scratch );
				scratch.select();
				try {
					document.execCommand( 'copy' );
				} catch ( e ) {
					// Nothing useful to do; the text is on screen to select.
				}
				document.body.removeChild( scratch );
				done();
			} );
		} );
	}

	/**
	 * Keep the radio and checkbox cards' selected styling in step.
	 */
	function wireCards() {
		document.querySelectorAll( '.vl-radio-card input, .vl-alert input' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var name = input.getAttribute( 'name' );

				if ( 'radio' === input.type && name ) {
					// Every radio in the group, so the one losing selection
					// drops its styling at the same moment.
					document.querySelectorAll( 'input[name="' + name + '"]' ).forEach( function ( sibling ) {
						var card = sibling.closest( '.vl-radio-card' );
						if ( card ) {
							card.classList.toggle( 'is-on', sibling.checked );
						}
					} );
					return;
				}

				var own = input.closest( '.vl-alert' );
				if ( own ) {
					own.classList.toggle( 'is-on', input.checked );
				}
			} );
		} );
	}

	/**
	 * The tracked-pages picker: select-all, shift-ranges, and a cost figure
	 * that answers for the selection in front of you rather than the one
	 * saved an hour ago.
	 */
	function wirePicker() {
		var all = document.getElementById( 'vl-check-all' );
		var boxes = Array.prototype.slice.call(
			document.querySelectorAll( 'input[name="vl_track[]"]' )
		);

		if ( ! boxes.length ) {
			return;
		}

		var cost = document.getElementById( 'vl-cost' );
		var anchor = null;

		/**
		 * How many pages would be tracked if this screen were saved now.
		 *
		 * The baseline is everything tracked that this screen is not showing:
		 * a filtered or paged view only ever adds to or removes from it, so
		 * the total has to start from what is off screen.
		 *
		 * @return {number} Checked rows on this screen.
		 */
		function checkedCount() {
			return boxes.filter( function ( box ) {
				return box.checked;
			} ).length;
		}

		function format( value, decimals ) {
			try {
				return value.toLocaleString( undefined, {
					minimumFractionDigits: decimals || 0,
					maximumFractionDigits: decimals || 0
				} );
			} catch ( e ) {
				return String( decimals ? value.toFixed( decimals ) : Math.round( value ) );
			}
		}

		function updateCost() {
			if ( ! cost ) {
				return;
			}

			var devices = parseInt( cost.getAttribute( 'data-devices' ), 10 ) || 1;
			var interval = parseInt( cost.getAttribute( 'data-interval' ), 10 ) || 24;
			var allowance = parseInt( cost.getAttribute( 'data-allowance' ), 10 ) || 25000;
			var baseline = parseInt( cost.getAttribute( 'data-baseline' ), 10 ) || 0;

			var pages = baseline + checkedCount();
			var perRun = pages * devices;
			var perDay = perRun * ( 24 / interval );

			var values = {
				pages: format( pages ),
				per_run: format( perRun ),
				per_month: format( Math.round( perDay * 30 ) ),
				share: format( allowance > 0 ? ( perDay / allowance ) * 100 : 0, 1 ) + '%'
			};

			Object.keys( values ).forEach( function ( key ) {
				var cell = cost.querySelector( '[data-vl-cost="' + key + '"]' );
				if ( ! cell ) {
					return;
				}
				cell.textContent = values[ key ];
				// Flagged while it differs from what is actually stored, so a
				// figure nobody has saved yet never passes for one that is.
				cell.classList.toggle( 'is-live', values[ key ] !== cell.getAttribute( 'data-saved' ) );
			} );
		}

		/**
		 * Row styling follows the tick immediately: the marker down the left
		 * of a tracked row is the only thing on this screen that says what is
		 * being watched, so it cannot wait for a save.
		 *
		 * @param {HTMLInputElement} box One row checkbox.
		 */
		function markRow( box ) {
			var row = box.closest( 'tr' );
			if ( row ) {
				row.classList.toggle( 'is-tracked', box.checked );
			}
		}

		function sync() {
			var on = checkedCount();
			if ( all ) {
				all.checked = ( on === boxes.length );
				// Partly ticked is its own state. Without it the header box
				// says "none selected" while eight rows are.
				all.indeterminate = ( on > 0 && on < boxes.length );
			}
			boxes.forEach( markRow );
			updateCost();
		}

		if ( all ) {
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = all.checked;
				} );
				anchor = null;
				sync();
			} );
		}

		boxes.forEach( function ( box, index ) {
			box.addEventListener( 'click', function ( event ) {
				// Shift-click fills the range, the way every other list table
				// in wp-admin behaves.
				if ( event.shiftKey && null !== anchor && anchor !== index ) {
					var from = Math.min( anchor, index );
					var to = Math.max( anchor, index );
					for ( var i = from; i <= to; i++ ) {
						boxes[ i ].checked = box.checked;
					}
				}
				anchor = index;
				sync();
			} );

			// Keyboard ticks fire change without click.
			box.addEventListener( 'change', sync );
		} );

		sync();
	}

	/**
	 * A <details> used as a menu should close the way a menu closes.
	 */
	function wireMenus() {
		var menus = Array.prototype.slice.call( document.querySelectorAll( '.vl-menu' ) );
		if ( ! menus.length ) {
			return;
		}

		document.addEventListener( 'click', function ( event ) {
			menus.forEach( function ( menu ) {
				if ( menu.open && ! menu.contains( event.target ) ) {
					menu.open = false;
				}
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key ) {
				return;
			}
			menus.forEach( function ( menu ) {
				if ( menu.open ) {
					menu.open = false;
					var summary = menu.querySelector( 'summary' );
					if ( summary ) {
						summary.focus();
					}
				}
			} );
		} );
	}

	/**
	 * The printable report, which is how a PDF gets made here.
	 */
	function wirePrint() {
		var button = document.getElementById( 'vl-print' );
		if ( button ) {
			button.addEventListener( 'click', function () {
				window.print();
			} );
		}

		// Arrived from "Save as PDF", so open the dialog without a second
		// click. After paint, or Safari prints a half-laid-out page.
		if ( document.getElementById( 'vl-autoprint' ) ) {
			window.requestAnimationFrame( function () {
				window.setTimeout( function () {
					window.print();
				}, 180 );
			} );
		}
	}

	/**
	 * Hover and keyboard readout for the history chart.
	 *
	 * The chart's viewBox is stretched to the container, so everything that
	 * must stay round or stay one pixel wide lives in HTML on top of it and
	 * is positioned from the point data the server already rendered.
	 */
	function wireCharts() {
		document.querySelectorAll( '[data-vl-chart]' ).forEach( function ( wrap ) {
			var points;
			try {
				points = JSON.parse( wrap.getAttribute( 'data-vl-chart' ) );
			} catch ( e ) {
				return;
			}
			if ( ! points || points.length < 2 ) {
				return;
			}

			var vbW = parseFloat( wrap.getAttribute( 'data-vl-chart-w' ) ) || 900;
			var vbH = parseFloat( wrap.getAttribute( 'data-vl-chart-h' ) ) || 200;
			var guide = wrap.querySelector( '.vl-chart-guide' );
			var dot = wrap.querySelector( '.vl-chart-dot' );
			var tip = wrap.querySelector( '.vl-chart-tip' );
			var current = -1;

			function show( index ) {
				index = Math.max( 0, Math.min( points.length - 1, index ) );
				if ( index === current ) {
					return;
				}
				current = index;

				var point = points[ index ];
				var box = wrap.getBoundingClientRect();
				var left = ( point.x / vbW ) * box.width;
				var top = ( point.y / vbH ) * box.height;

				guide.style.left = left + 'px';
				dot.style.left = left + 'px';
				dot.style.top = top + 'px';
				dot.style.borderColor = point.b;
				tip.innerHTML = '';

				var value = document.createElement( 'strong' );
				value.textContent = point.v;
				var when = document.createElement( 'span' );
				when.textContent = point.d;
				tip.appendChild( value );
				tip.appendChild( when );

				// Keep the card inside the chart rather than hanging off it.
				var half = tip.offsetWidth / 2;
				tip.style.left = Math.max( half, Math.min( box.width - half, left ) ) + 'px';
				wrap.classList.add( 'is-hover' );
			}

			function hide() {
				current = -1;
				wrap.classList.remove( 'is-hover' );
			}

			wrap.addEventListener( 'pointermove', function ( event ) {
				var box = wrap.getBoundingClientRect();
				if ( box.width <= 0 ) {
					return;
				}
				var ratio = ( event.clientX - box.left ) / box.width;
				show( Math.round( ratio * ( points.length - 1 ) ) );
			} );

			wrap.addEventListener( 'pointerleave', hide );
			wrap.addEventListener( 'blur', hide );

			wrap.addEventListener( 'focus', function () {
				show( points.length - 1 );
			} );

			// A chart nobody can reach with a keyboard is a chart half the
			// people who need it cannot read.
			wrap.addEventListener( 'keydown', function ( event ) {
				var step = 0;
				if ( 'ArrowRight' === event.key ) {
					step = 1;
				} else if ( 'ArrowLeft' === event.key ) {
					step = -1;
				} else if ( 'Home' === event.key ) {
					step = -points.length;
				} else if ( 'End' === event.key ) {
					step = points.length;
				} else {
					return;
				}
				event.preventDefault();
				show( ( current < 0 ? points.length - 1 : current ) + step );
			} );
		} );
	}

	/**
	 * A link that changes something asks first.
	 */
	function wireConfirms() {
		document.querySelectorAll( '[data-vl-confirm]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( link.getAttribute( 'data-vl-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		wireAction( 'vl-test-key', 'vl-key-result', 'vitallens_test_key', i18n.testing, i18n.testingDetail, function () {
			var field = document.getElementById( 'vl-api-key' );
			return { api_key: field ? field.value : '' };
		} );

		wireAction( 'vl-run-now', 'vl-run-result', 'vitallens_run_now', i18n.running, i18n.runningDetail, null );

		wireAction( 'vl-recheck', 'vl-recheck-result', 'vitallens_check_one', i18n.checking, i18n.checkingDetail, function () {
			var button = document.getElementById( 'vl-recheck' );
			return { tracked: button ? button.getAttribute( 'data-vl-tracked' ) : 0 };
		} );

		wireCopy();
		wireCards();
		wirePicker();
		wireMenus();
		wirePrint();
		wireConfirms();
		wireCharts();
	} );
}() );
