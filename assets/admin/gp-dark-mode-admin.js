/**
 * GP Dark Mode — settings-page behavior.
 *
 * Model dropdown: "Refresh models" pulls the current list from the selected
 * provider's /v1/models via AJAX and rebuilds the <select> in place;
 * switching providers swaps to that provider's cached/seed list client-side.
 * Config arrives via wp_localize_script as window.OGM_GPDM.
 */
jQuery( function ( $ ) {
	var cfg = window.OGM_GPDM || {};

	/* ---- Settings-page tabs (one per stylesheet layer + Settings) ---- */

	function activateTab( slug ) {
		if ( ! $( '.ogm-gpdm-tab[data-tab="' + slug + '"]' ).length ) {
			return;
		}
		$( '.ogm-gpdm-tabs .nav-tab' ).removeClass( 'nav-tab-active' );
		$( '.ogm-gpdm-tabs .nav-tab[data-tab="' + slug + '"]' ).addClass( 'nav-tab-active' );
		$( '.ogm-gpdm-tab' ).removeClass( 'is-active' );
		$( '.ogm-gpdm-tab[data-tab="' + slug + '"]' ).addClass( 'is-active' );
	}

	// Keep the Settings-API referer in sync with the hash, so saving the form
	// from any tab redirects back to that SAME tab (fragments never reach the
	// server on their own).
	function syncReferer() {
		var field = document.querySelector( 'input[name="_wp_http_referer"]' );
		if ( field ) {
			field.value = field.value.split( '#' )[ 0 ] + ( window.location.hash || '' );
		}
	}

	function tabFromHash() {
		// Sanitize: the raw fragment goes into a jQuery selector; a hostile or
		// mistyped hash must degrade to the first tab, never throw (a thrown
		// error here would kill every handler registered after this call).
		var slug = ( window.location.hash || '' ).replace( '#', '' ).replace( /[^a-z-]/g, '' );
		activateTab( '' === slug ? 'shipped' : slug );
		syncReferer();
	}
	$( window ).on( 'hashchange', tabFromHash );
	tabFromHash();

	function currentProvider() {
		return $( '#ogm-gpdm-ai-provider' ).val();
	}

	function rebuild( models ) {
		var $sel = $( '#ogm-gpdm-ai-model' );
		if ( ! $sel.length ) {
			return;
		}
		var current  = $sel.val();
		var provider = currentProvider();
		var list     = ( models || ( cfg.choices && cfg.choices[ provider ] ) || [] ).slice();

		// Keep the currently-saved model selectable even if the refreshed
		// list doesn't include it (mirrors the server-side model_choices()).
		if ( current && list.indexOf( current ) === -1 ) {
			list.unshift( current );
		}

		$sel.empty();
		$sel.append(
			$( '<option/>' ).val( '' ).text(
				( cfg.i18n && cfg.i18n.providerDefault ? cfg.i18n.providerDefault : 'Provider default' ) +
				' (' + ( ( cfg.defaults || {} )[ provider ] || '' ) + ')'
			)
		);
		$.each( list, function ( i, id ) {
			$sel.append( $( '<option/>' ).val( id ).text( id ) );
		} );
		$sel.val( current && list.indexOf( current ) !== -1 ? current : '' );
	}

	function syncKeyRows() {
		var provider = currentProvider();
		$( '.ogm-gpdm-key-row' ).each( function () {
			$( this ).css( 'display', $( this ).attr( 'data-provider' ) === provider ? '' : 'none' );
		} );
	}

	$( document ).on( 'change', '#ogm-gpdm-ai-provider', function () {
		rebuild( null );
		syncKeyRows();
	} );

	/* ---- Danger zone: confirm before the factory reset ---- */
	$( document ).on( 'click', '#ogm-gpdm-reset', function ( e ) {
		if ( ! window.confirm( ( cfg.i18n && cfg.i18n.resetConfirm ) || 'Really reset ALL GP Dark Mode settings? API keys, module choices, the generated palette, the AI stylesheet, and your saved Manual CSS will all be removed. This cannot be undone.' ) ) {
			e.preventDefault();
		}
	} );

	/* ---- AI Review: start in the background, poll until done ---- */

	var aiPollCount = 0;

	function aiStatusEl() {
		return $( '#ogm-gpdm-review-status' );
	}

	/* Night-sky progress bar: the dark fill sweeps across a daylight track,
	   stars twinkle in its wake, a crescent moon rides the leading edge.
	   There is no real progress signal (the server only says "running"), so
	   the sweep eases toward — but never reaches — full; arriving at 100%
	   is reserved for the actual "done". */

	var aiTicker  = null;
	var aiStarted = 0;

	var AI_MESSAGES = [
		'Dimming the lights…',
		'Teaching the header about nightfall…',
		'Convincing white backgrounds to sleep…',
		'Hunting down light leaks…',
		'Sprinkling stars across the footer…',
		'Tucking in the sidebar widgets…',
		'Negotiating with stubborn banner ads…',
		'Auditing every hover state after dark…',
		'Counting contrast ratios like sheep…',
		'Polishing the moon…'
	];

	function aiProgressStart() {
		var $el = aiStatusEl();
		if ( $el.find( '.ogm-gpdm-progress' ).length ) {
			return; // Already on screen — keep its clock running.
		}
		aiStarted = Date.now();
		// aria-live="off" opts the whimsy out of the surrounding polite live
		// region — otherwise screen readers would narrate every message swap
		// and every elapsed-time tick. Only the final state gets announced.
		$el.removeClass( 'ogm-gpdm-refresh-error' ).empty().append(
			$( '<span/>' ).addClass( 'ogm-gpdm-progress' )
				.attr( { 'aria-live': 'off', role: 'progressbar', 'aria-label': 'AI review running' } )
				.append(
					$( '<span/>' ).addClass( 'ogm-gpdm-progress-track' )
						.append( $( '<span/>' ).addClass( 'ogm-gpdm-progress-fill' ) )
						.append( $( '<span/>' ).addClass( 'ogm-gpdm-progress-moon' ) )
				)
				.append( $( '<span/>' ).addClass( 'ogm-gpdm-progress-msg' ).text( AI_MESSAGES[ 0 ] ) )
				.append( $( '<span/>' ).addClass( 'ogm-gpdm-progress-elapsed' ) )
		);
		aiProgressTick();
		aiTicker = window.setInterval( aiProgressTick, 1000 );
	}

	function aiProgressTick() {
		var $el     = aiStatusEl();
		var elapsed = ( Date.now() - aiStarted ) / 1000;
		var pct     = Math.min( 94, 100 * ( 1 - Math.exp( -elapsed / 75 ) ) );

		$el.find( '.ogm-gpdm-progress-fill' ).css( 'width', pct + '%' );
		// Keep the moon inside the track at both extremes.
		$el.find( '.ogm-gpdm-progress-moon' ).css( 'left', 'calc(' + Math.max( 7, pct ) + '% - 14px)' );

		var idx  = Math.floor( elapsed / 7 ) % AI_MESSAGES.length;
		var $msg = $el.find( '.ogm-gpdm-progress-msg' );
		if ( $msg.length && $msg.text() !== AI_MESSAGES[ idx ] ) {
			$msg.css( 'opacity', 0 );
			setTimeout( function () {
				$msg.text( AI_MESSAGES[ idx ] ).css( 'opacity', 1 );
			}, 250 );
		}

		$el.find( '.ogm-gpdm-progress-elapsed' ).text(
			elapsed >= 60 ? ( Math.round( elapsed / 6 ) / 10 + ' min' ) : ( Math.round( elapsed ) + 's' )
		);
	}

	function aiProgressStop() {
		if ( aiTicker ) {
			window.clearInterval( aiTicker );
			aiTicker = null;
		}
	}

	function aiShowDone() {
		// Never auto-reload: the review section lives inside the
		// settings form, and a forced reload would silently destroy
		// unsaved edits (a pasted key, CodeMirror CSS changes).
		// type="button" is load-bearing — a bare <button> inside the
		// settings form defaults to type="submit".
		aiStatusEl()
			.empty()
			.append( $( '<span/>' ).addClass( 'ogm-gpdm-ai-done' ).text( 'Review complete.' ) )
			.append(
				$( '<button/>' )
					.attr( 'type', 'button' )
					.addClass( 'button button-primary' )
					.text( 'Load the proposal' )
					.on( 'click', function () {
						window.location.reload();
					} )
			);
	}

	function aiPoll() {
		aiPollCount++;
		if ( aiPollCount > 60 ) { // ~5 minutes of polling, then give up quietly.
			aiProgressStop();
			aiStatusEl().text( 'Still running — reload this page in a minute to check for the proposal.' );
			return;
		}
		$.post( ajaxurl, { action: 'ogm_gpdm_ai_status', nonce: cfg.ai_nonce } ).done( function ( resp ) {
			var data = resp && resp.success ? resp.data : null;
			if ( ! data ) {
				aiProgressStop();
				aiStatusEl().addClass( 'ogm-gpdm-refresh-error' ).text( 'Status check failed.' );
				return;
			}
			if ( data.state === 'running' ) {
				aiProgressStart();
				setTimeout( aiPoll, 5000 );
			} else if ( data.state === 'done' ) {
				aiProgressStop();
				var $el = aiStatusEl();
				if ( $el.find( '.ogm-gpdm-progress' ).length ) {
					// Let the sky finish: sweep to full, then hand over the button.
					$el.find( '.ogm-gpdm-progress-fill' ).css( 'width', '100%' );
					$el.find( '.ogm-gpdm-progress-moon' ).css( 'left', 'calc(100% - 16px)' );
					$el.find( '.ogm-gpdm-progress-msg' ).text( 'Night has fallen.' ).css( 'opacity', 1 );
					setTimeout( aiShowDone, 1100 );
				} else {
					aiShowDone();
				}
			} else if ( data.state === 'error' ) {
				aiProgressStop();
				aiStatusEl().addClass( 'ogm-gpdm-refresh-error' ).text( 'Failed: ' + ( data.message || 'unknown error' ) );
				$( '#ogm-gpdm-run-review' ).prop( 'disabled', false );
			} else {
				aiProgressStop();
				$( '#ogm-gpdm-run-review' ).prop( 'disabled', false );
			}
		} ).fail( function () {
			setTimeout( aiPoll, 8000 ); // Transient AJAX hiccup — keep polling.
		} );
	}

	$( document ).on( 'click', '#ogm-gpdm-run-review', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		aiProgressStart();

		$.post( ajaxurl, {
			action: 'ogm_gpdm_ai_start',
			nonce: cfg.ai_nonce,
			instructions: $( '#ogm-gpdm-ai-instructions' ).val() || ''
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				aiPollCount = 0;
				setTimeout( aiPoll, 5000 );
			} else {
				aiProgressStop();
				aiStatusEl().addClass( 'ogm-gpdm-refresh-error' ).text( ( resp && resp.data && resp.data.message ) || 'Could not start the review.' );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			aiProgressStop();
			aiStatusEl().addClass( 'ogm-gpdm-refresh-error' ).text( 'Could not start the review.' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// A run was already in flight when the page loaded — resume polling.
	if ( cfg.ai_state === 'running' ) {
		aiPollCount = 0;
		aiProgressStart();
		setTimeout( aiPoll, 3000 );
	}

	$( document ).on( 'click', '#ogm-gpdm-refresh-models', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var $status = $( '#ogm-gpdm-refresh-status' );

		$btn.prop( 'disabled', true );
		$status.removeClass( 'ogm-gpdm-refresh-error' ).text( cfg.i18n && cfg.i18n.refreshing ? cfg.i18n.refreshing : 'Refreshing…' );

		$.post( ajaxurl, {
			action: 'ogm_gpdm_refresh_models',
			nonce: cfg.refresh_nonce,
			provider: currentProvider()
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && resp.data.models ) {
				cfg.choices = cfg.choices || {};
				cfg.choices[ currentProvider() ] = resp.data.models;
				rebuild( resp.data.models );
				$status.text( resp.data.message || 'Refreshed.' );
			} else {
				var m = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Refresh failed.';
				$status.addClass( 'ogm-gpdm-refresh-error' ).text( m );
			}
		} ).fail( function ( xhr ) {
			var msg = 'Refresh failed.';
			if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				msg = xhr.responseJSON.data.message;
			}
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( msg );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );
} );
