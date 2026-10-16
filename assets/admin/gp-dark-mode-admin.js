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

	/* ---- Export / Import (1.12.0) ---- */

	// The export link carries &keys=1 only while the box is ticked.
	$( document ).on( 'change', '#ogm-gpdm-export-keys', function () {
		var $a   = $( '#ogm-gpdm-export' );
		var href = ( $a.attr( 'href' ) || '' ).replace( /&keys=1/, '' );
		$a.attr( 'href', this.checked ? href + '&keys=1' : href );
	} );

	function importReset() {
		$( '#ogm-gpdm-import-preview' ).empty();
		$( '#ogm-gpdm-import-file' ).val( '' );
	}

	// Read the file here, preview on the server (nothing is written yet).
	$( document ).on( 'change', '#ogm-gpdm-import-file', function () {
		var file     = this.files && this.files[ 0 ];
		var $status  = $( '#ogm-gpdm-import-status' ).removeClass( 'ogm-gpdm-refresh-error' );
		var $preview = $( '#ogm-gpdm-import-preview' ).empty();
		if ( ! file ) {
			$status.text( '' );
			return;
		}
		if ( file.size > 2000000 ) {
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'That file is too large to be a GP Dark Mode export.' );
			return;
		}
		$status.text( 'Reading…' );
		var reader = new FileReader();
		reader.onload = function () {
			$.post( ajaxurl, { action: 'ogm_gpdm_import_preview', nonce: cfg.import_nonce, t: Date.now(), file: reader.result } ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$status.text( '' );
					// type="button" — this lives inside the settings form.
					$preview.html( resp.data.html ).append(
						$( '<p/>' )
							.append( $( '<button/>' ).attr( { type: 'button', id: 'ogm-gpdm-import-apply' } ).addClass( 'button button-primary' ).text( 'Apply import' ) )
							.append( ' ' )
							.append( $( '<button/>' ).attr( { type: 'button', id: 'ogm-gpdm-import-cancel' } ).addClass( 'button' ).text( 'Cancel' ) )
					);
				} else {
					$status.addClass( 'ogm-gpdm-refresh-error' ).text( ( resp && resp.data && resp.data.message ) || 'Could not read that file.' );
				}
			} ).fail( function () {
				$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'Could not read that file.' );
			} );
		};
		reader.onerror = function () {
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'Could not read that file.' );
		};
		reader.readAsText( file );
	} );

	$( document ).on( 'click', '#ogm-gpdm-import-cancel', function ( e ) {
		e.preventDefault();
		importReset();
		$( '#ogm-gpdm-import-status' ).text( '' );
	} );

	// Applying replaces what this page's form shows, so reload at once — a
	// Save Settings from the stale form would quietly undo the import.
	$( document ).on( 'click', '#ogm-gpdm-import-apply', function ( e ) {
		e.preventDefault();
		var $btn    = $( this ).prop( 'disabled', true );
		var $status = $( '#ogm-gpdm-import-status' ).removeClass( 'ogm-gpdm-refresh-error' ).text( 'Importing…' );
		$.post( ajaxurl, { action: 'ogm_gpdm_import_apply', nonce: cfg.import_nonce, t: Date.now() } ).done( function ( resp ) {
			if ( resp && resp.success ) {
				$status.text( 'Imported — reloading…' );
				window.location.reload();
			} else {
				$btn.prop( 'disabled', false );
				$status.addClass( 'ogm-gpdm-refresh-error' ).text( ( resp && resp.data && resp.data.message ) || 'The import failed.' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'The import failed.' );
		} );
	} );

	$( document ).on( 'click', '#ogm-gpdm-import-undo', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( 'Put back the settings from before the import? Everything the import changed goes back to how it was.' ) ) {
			return;
		}
		var $btn    = $( this ).prop( 'disabled', true );
		var $status = $( '#ogm-gpdm-undo-status' ).removeClass( 'ogm-gpdm-refresh-error' ).text( 'Undoing…' );
		$.post( ajaxurl, { action: 'ogm_gpdm_import_undo', nonce: cfg.import_nonce, t: Date.now() } ).done( function ( resp ) {
			if ( resp && resp.success ) {
				$status.text( 'Undone — reloading…' );
				window.location.reload();
			} else {
				$btn.prop( 'disabled', false );
				$status.addClass( 'ogm-gpdm-refresh-error' ).text( ( resp && resp.data && resp.data.message ) || 'Could not undo the import.' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'Could not undo the import.' );
		} );
	} );

	/* ---- Danger zone: confirm before the factory reset ---- */
	$( document ).on( 'click', '#ogm-gpdm-reset', function ( e ) {
		if ( ! window.confirm( ( cfg.i18n && cfg.i18n.resetConfirm ) || 'Really reset ALL GP Dark Mode settings? API keys, module choices, the generated palette, the AI stylesheet, and your saved Manual CSS will all be removed. This cannot be undone.' ) ) {
			e.preventDefault();
		}
	} );

	/* ---- AI Review: start in the background, poll until done ---- */

	var aiPollCount = 0;
	var aiBackground = false;

	function aiStatusEl() {
		return $( '#ogm-gpdm-review-status' );
	}

	/* Night-sky progress bar: the dark fill sweeps across a daylight track,
	   stars twinkle in its wake, a crescent moon rides the leading edge.
	   There is no real progress signal (the server only says "running"), so
	   the sweep eases toward — but never reaches — full; arriving at 100%
	   is reserved for the actual "done". */

	var aiTicker  = null;
	var aiMsgOverride = '';
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
		if ( aiMsgOverride ) {
			// A real step is in progress (the contrast check): say that instead.
			$msg.text( aiMsgOverride ).css( 'opacity', 1 );
		} else if ( $msg.length && $msg.text() !== AI_MESSAGES[ idx ] ) {
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

	/* Background reviews (1.9.0): the server's note under the bar ("queued in
	   Anthropic's batch service…") and a Cancel button. Direct calls can't
	   be stopped, so they get neither. */
	function aiShowDetail( data ) {
		var $el = aiStatusEl();
		var $detail = $el.find( '.ogm-gpdm-progress-detail' );
		if ( ! $detail.length ) {
			$detail = $( '<span/>' ).addClass( 'ogm-gpdm-progress-detail' );
			$el.append( $detail );
		}
		$detail.text( data.mode === 'background' ? ( data.message || '' ) : '' );

		if ( data.mode === 'background' && ! $el.find( '.ogm-gpdm-ai-cancel' ).length ) {
			// type="button" — this lives inside the settings form.
			$el.append(
				$( '<button/>' )
					.attr( 'type', 'button' )
					.addClass( 'button-link ogm-gpdm-ai-cancel' )
					.text( 'Cancel review' )
			);
		}
	}

	$( document ).on( 'click', '.ogm-gpdm-ai-cancel', function ( e ) {
		e.preventDefault();
		var $btn = $( this ).prop( 'disabled', true );
		$.post( ajaxurl, { action: 'ogm_gpdm_ai_cancel', nonce: cfg.ai_nonce, t: Date.now() } ).always( function ( resp ) {
			aiCanceled = true;
			aiProgressStop();
			aiStatusEl().removeClass( 'ogm-gpdm-refresh-error' ).text(
				resp && resp.success ? 'Review canceled.' : ( ( resp && resp.data && resp.data.message ) || 'Could not cancel the review.' )
			);
			$btn.remove();
			$( '#ogm-gpdm-run-review' ).prop( 'disabled', false );
		} );
	} );

	var aiCanceled = false;

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
		if ( aiCanceled ) {
			return;
		}
		aiPollCount++;
		if ( aiPollCount > 180 && ! aiBackground ) { // ~15 minutes — matches the server-side run window for a direct review.
			aiProgressStop();
			aiStatusEl().text( 'Still running — reload this page in a minute to check for the proposal.' );
			return;
		}
		if ( aiPollCount > 600 ) { // A background job past ~2 hours: stop polling, the server keeps checking.
			aiProgressStop();
			aiStatusEl().text( 'Still running in the background — the proposal will be here when it’s ready. Reload this page later.' );
			return;
		}
		// t: a cache-buster — some hosts cache identical admin-ajax POSTs.
		$.post( ajaxurl, { action: 'ogm_gpdm_ai_status', nonce: cfg.ai_nonce, t: Date.now() } ).done( function ( resp ) {
			if ( aiCanceled ) {
				return;
			}
			var data = resp && resp.success ? resp.data : null;
			if ( ! data ) {
				aiProgressStop();
				aiStatusEl().addClass( 'ogm-gpdm-refresh-error' ).text( 'Status check failed.' );
				return;
			}
			if ( data.state === 'running' ) {
				aiBackground = data.mode === 'background';
				aiProgressStart();
				aiShowDetail( data );
				// Background jobs can take a while: check every 5 s for the
				// first two minutes, then every 15 s.
				setTimeout( aiPoll, aiBackground && aiPollCount > 24 ? 15000 : 5000 );
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

	/* ---- Contrast check (1.10.0): measure in hidden frames, store, show ---- */

	var AUDIT_FRESH = 5 * 60; // Seconds: a review reuses a check this recent.

	/**
	 * Run the check (assets/admin/gp-dark-mode-audit.js) and store it.
	 * onProgress( text ) narrates; resolves true when stored, false otherwise.
	 */
	function runAudit( onProgress ) {
		if ( ! window.OGM_GPDM_AUDIT || ! window.fetch || ! window.Promise || ! cfg.audit_pages || ! cfg.audit_pages.length ) {
			return Promise.resolve( false );
		}
		return window.OGM_GPDM_AUDIT.run( cfg.audit_pages, function ( done, total, label ) {
			onProgress( 'Checking contrast: ' + label + ' (' + ( done + 1 ) + ' of ' + total + ')…' );
		} ).then( function ( result ) {
			onProgress( 'Saving the results…' );
			return $.post( ajaxurl, { action: 'ogm_gpdm_audit_save', nonce: cfg.ai_nonce, t: Date.now(), audit: JSON.stringify( result ) } );
		} ).then( function ( resp ) {
			if ( resp && resp.success ) {
				cfg.audit_time = resp.data.time;
				$( '#ogm-gpdm-audit-result' ).html( resp.data.html );
				$( '#ogm-gpdm-chrome-prompt' ).val( resp.data.prompt );
				return true;
			}
			onProgress( ( resp && resp.data && resp.data.message ) || 'The contrast check could not be saved.' );
			return false;
		}, function () {
			onProgress( 'The contrast check failed.' );
			return false;
		} );
	}

	$( document ).on( 'click', '#ogm-gpdm-run-audit', function ( e ) {
		e.preventDefault();
		var $btn    = $( this ).prop( 'disabled', true );
		var $status = $( '#ogm-gpdm-audit-status' ).removeClass( 'ogm-gpdm-refresh-error' );
		runAudit( function ( text ) {
			$status.text( text );
		} ).then( function ( ok ) {
			$btn.prop( 'disabled', false );
			if ( ok ) {
				$status.text( 'Done.' );
				$( '#ogm-gpdm-audit-result details' ).prop( 'open', true );
			} else {
				$status.addClass( 'ogm-gpdm-refresh-error' );
			}
		} );
	} );

	/* ---- Review with Claude in Chrome (1.10.0): copy the prompt, paste the reply ---- */

	$( document ).on( 'click', '#ogm-gpdm-copy-prompt', function ( e ) {
		e.preventDefault();
		var text   = $( '#ogm-gpdm-chrome-prompt' ).val() || '';
		var $state = $( '#ogm-gpdm-copy-status' );
		function fallback() {
			var ta = document.getElementById( 'ogm-gpdm-chrome-prompt' );
			ta.focus();
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( err ) {}
			$state.text( ok ? 'Copied.' : 'Select the text and copy it.' );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				$state.text( 'Copied — paste it into Claude.' );
			}, fallback );
		} else {
			fallback();
		}
	} );

	$( document ).on( 'click', '#ogm-gpdm-load-paste', function ( e ) {
		e.preventDefault();
		var $btn    = $( this ).prop( 'disabled', true );
		var $status = $( '#ogm-gpdm-paste-status' ).removeClass( 'ogm-gpdm-refresh-error' ).text( 'Loading…' );
		$.post( ajaxurl, { action: 'ogm_gpdm_ai_paste', nonce: cfg.ai_nonce, t: Date.now(), reply: $( '#ogm-gpdm-paste' ).val() || '' } ).done( function ( resp ) {
			$btn.prop( 'disabled', false );
			if ( resp && resp.success ) {
				// Same hand-off as a finished review: reload only on request, so
				// unsaved edits elsewhere on the page are never lost.
				$status.empty()
					.append( $( '<span/>' ).text( 'Loaded as a proposal. ' ) )
					.append( $( '<button/>' ).attr( 'type', 'button' ).addClass( 'button button-primary' ).text( 'Show the proposal' ).on( 'click', function () {
						window.location.reload();
					} ) );
			} else {
				$status.addClass( 'ogm-gpdm-refresh-error' ).text( ( resp && resp.data && resp.data.message ) || 'Could not load it.' );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$status.addClass( 'ogm-gpdm-refresh-error' ).text( 'Could not load it.' );
		} );
	} );

	$( document ).on( 'click', '#ogm-gpdm-run-review', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		aiProgressStart();
		aiCanceled = false;

		// Measure first (unless a check just ran), so the review gets the
		// measured failures; a failed check never blocks the review.
		var fresh = cfg.audit_time && ( Date.now() / 1000 - cfg.audit_time ) < AUDIT_FRESH;
		var check = fresh ? Promise.resolve( true ) : runAudit( function ( text ) {
			aiMsgOverride = text;
			aiStatusEl().find( '.ogm-gpdm-progress-msg' ).text( text );
		} );
		check.then( function () {
			aiMsgOverride = '';
			startReview( $btn );
		} );
	} );

	function startReview( $btn ) {
		$.post( ajaxurl, {
			action: 'ogm_gpdm_ai_start',
			t: Date.now(),
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
	}

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
