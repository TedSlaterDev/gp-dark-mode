/**
 * GP Dark Mode — contrast check engine (1.10.0).
 *
 * Measures pages the way visitors see them, from the settings page:
 *
 * 1. fetch() the page logged out (credentials omitted), strip every script,
 *    noscript and iframe, and render it in a hidden iframe whose sandbox has
 *    NO allow-scripts — so no ad, analytics or tracking code ever runs (no
 *    impressions, no pageviews). allow-same-origin keeps it readable from here.
 *    Async-CSS tricks that need JS (media="print" onload, preload-as-style)
 *    are switched to plain stylesheets so the page is styled as normal.
 * 2. Measure every visible text element in light mode (data-theme="light"),
 *    then dark mode: text color composited over the real background found by
 *    walking up the ancestors. WCAG: 4.5:1, or 3:1 for large text.
 * 3. In dark mode, flag large elements whose own background is still light.
 * 4. Emulate :hover / :focus by copying each same-origin rule that uses them
 *    into a class-based twin placed right after it (same specificity, same
 *    source order), then adding that class to the element and its ancestors.
 *
 * Exposes window.OGM_GPDM_AUDIT.run( pages, onProgress ) → Promise<result>.
 * No dependencies; plain ES5 so it runs anywhere the settings page does.
 */
( function ( window, document ) {
	'use strict';

	var WIDTH        = 1280;
	var HEIGHT       = 900;
	var PAGE_TIMEOUT = 20000;
	var MAX_TEXTS    = 4000;  // Text elements measured per page.
	var MAX_HOVER    = 250;   // Interactive elements given the hover/focus treatment.
	var MAX_ROWS     = 60;    // Failures kept per page.
	var MAX_ISLANDS  = 20;
	var ISLAND_AREA  = 12000; // px² — about 200×60.

	/* ---------- color math ---------- */

	var colorCanvas = null;
	var colorCtx    = null;
	var colorCache  = {};

	/** Any CSS color string → {r, g, b, a} (a 0–1), via a 1×1 canvas — handles rgb(), oklch(), color-mix()… */
	function parseColor( str ) {
		if ( ! str ) {
			return { r: 0, g: 0, b: 0, a: 0 };
		}
		if ( colorCache.hasOwnProperty( str ) ) {
			return colorCache[ str ];
		}
		var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/.exec( str );
		var out;
		if ( m ) {
			var a = m[4] === undefined ? 1 : ( m[4].indexOf( '%' ) > -1 ? parseFloat( m[4] ) / 100 : parseFloat( m[4] ) );
			out   = { r: +m[1], g: +m[2], b: +m[3], a: a };
		} else {
			if ( ! colorCtx ) {
				colorCanvas        = document.createElement( 'canvas' );
				colorCanvas.width  = 1;
				colorCanvas.height = 1;
				colorCtx           = colorCanvas.getContext( '2d', { willReadFrequently: true } );
			}
			colorCtx.clearRect( 0, 0, 1, 1 );
			colorCtx.fillStyle = '#000';
			colorCtx.fillStyle = str;
			colorCtx.fillRect( 0, 0, 1, 1 );
			var d = colorCtx.getImageData( 0, 0, 1, 1 ).data;
			out   = { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
		}
		colorCache[ str ] = out;
		return out;
	}

	/** top over bottom (bottom opaque) → opaque. */
	function over( top, bottom ) {
		var a = top.a;
		return {
			r: top.r * a + bottom.r * ( 1 - a ),
			g: top.g * a + bottom.g * ( 1 - a ),
			b: top.b * a + bottom.b * ( 1 - a ),
			a: 1
		};
	}

	function channel( c ) {
		c = c / 255;
		return c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	}

	function luminance( c ) {
		return 0.2126 * channel( c.r ) + 0.7152 * channel( c.g ) + 0.0722 * channel( c.b );
	}

	function contrast( a, b ) {
		var l1 = luminance( a );
		var l2 = luminance( b );
		return ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
	}

	function hex( c ) {
		function h( v ) {
			v = Math.max( 0, Math.min( 255, Math.round( v ) ) ).toString( 16 );
			return v.length === 1 ? '0' + v : v;
		}
		return '#' + h( c.r ) + h( c.g ) + h( c.b );
	}

	/* ---------- page loading ---------- */

	/** Fetch a page's HTML as a logged-out visitor (falls back to the admin's session behind HTTP auth). */
	function fetchHtml( url ) {
		function get( credentials ) {
			return window.fetch( url, { credentials: credentials, cache: 'no-store', redirect: 'follow' } ).then( function ( res ) {
				return res.text().then( function ( text ) {
					return { status: res.status, text: text, finalUrl: res.url || url };
				} );
			} );
		}
		return get( 'omit' ).then( function ( r ) {
			return ( r.status === 401 || r.status === 403 ) ? get( 'same-origin' ) : r;
		} );
	}

	/** The fetched HTML with scripts, trackers and frames removed and async CSS made synchronous. */
	function sanitizeHtml( html, baseUrl ) {
		var doc = new window.DOMParser().parseFromString( html, 'text/html' );
		var kill = doc.querySelectorAll( 'script, noscript, iframe, frame, object, embed, meta[http-equiv], link[rel="modulepreload"], #wpadminbar' );
		for ( var i = 0; i < kill.length; i++ ) {
			kill[ i ].parentNode.removeChild( kill[ i ] );
		}

		var links = doc.querySelectorAll( 'link' );
		for ( var j = 0; j < links.length; j++ ) {
			var l      = links[ j ];
			var rel    = ( l.getAttribute( 'rel' ) || '' ).toLowerCase();
			var onload = l.getAttribute( 'onload' ) || '';
			if ( rel === 'preload' && ( l.getAttribute( 'as' ) || '' ).toLowerCase() === 'style' && /rel\s*=|stylesheet/.test( onload ) ) {
				l.setAttribute( 'rel', 'stylesheet' );
			}
			if ( rel.indexOf( 'stylesheet' ) > -1 && ( l.getAttribute( 'media' ) || '' ) === 'print' && /media/.test( onload ) ) {
				l.setAttribute( 'media', 'all' );
			}
			l.removeAttribute( 'onload' );
		}

		// Everything with an inline handler is inert anyway (no allow-scripts),
		// but strip the attributes so nothing reads as active.
		var all = doc.querySelectorAll( '[onclick],[onload],[onerror],[onmouseover]' );
		for ( var k = 0; k < all.length; k++ ) {
			all[ k ].removeAttribute( 'onclick' );
			all[ k ].removeAttribute( 'onload' );
			all[ k ].removeAttribute( 'onerror' );
			all[ k ].removeAttribute( 'onmouseover' );
		}

		var head = doc.head || doc.documentElement.insertBefore( doc.createElement( 'head' ), doc.body );
		var base = doc.createElement( 'base' );
		base.setAttribute( 'href', baseUrl );
		head.insertBefore( base, head.firstChild );

		var calm = doc.createElement( 'style' );
		calm.id = 'gpdm-audit-calm';
		calm.textContent = '*,*::before,*::after{transition:none!important;animation:none!important;}';
		head.appendChild( calm );

		return '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
	}

	/** Render sanitized HTML in a hidden, script-less iframe. Resolves with the iframe once loaded (or after a timeout). */
	function render( html ) {
		return new Promise( function ( resolve ) {
			var frame = document.createElement( 'iframe' );
			frame.setAttribute( 'sandbox', 'allow-same-origin' );
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.setAttribute( 'tabindex', '-1' );
			frame.style.cssText = 'position:fixed;left:-20000px;top:0;width:' + WIDTH + 'px;height:' + HEIGHT + 'px;border:0;opacity:0;pointer-events:none;';
			var done = false;
			function finish() {
				if ( ! done ) {
					done = true;
					resolve( frame );
				}
			}
			frame.addEventListener( 'load', finish );
			setTimeout( finish, PAGE_TIMEOUT );
			frame.srcdoc = html;
			document.body.appendChild( frame );
		} );
	}

	/* ---------- measuring ---------- */

	/** The page canvas color: html/body backgrounds aside, white unless the page declares a dark-only color scheme. */
	function canvasColor( win, doc ) {
		var scheme = ( win.getComputedStyle( doc.documentElement ).colorScheme || '' ).toLowerCase();
		if ( scheme.indexOf( 'dark' ) > -1 && scheme.indexOf( 'light' ) === -1 ) {
			return { r: 18, g: 18, b: 18, a: 1 };
		}
		return { r: 255, g: 255, b: 255, a: 1 };
	}

	/** The opaque color behind an element: its own and its ancestors' background colors composited, stopping at an image. */
	function backgroundOf( el, win, doc ) {
		var layers   = [];
		var overImg  = false;
		var node     = el;
		var opaque   = false;
		while ( node && node.nodeType === 1 ) {
			var cs = win.getComputedStyle( node );
			var bg = parseColor( cs.backgroundColor );
			if ( bg.a > 0 ) {
				layers.push( bg );
				if ( bg.a >= 0.999 ) {
					opaque = true;
					break;
				}
			}
			if ( cs.backgroundImage && cs.backgroundImage !== 'none' ) {
				overImg = true; // A photo or gradient: the solid color found so far is the best estimate.
				break;
			}
			node = node.parentElement;
		}
		var result = opaque ? layers.pop() : canvasColor( win, doc );
		while ( layers.length ) {
			result = over( layers.pop(), result );
		}
		return { color: result, overImage: overImg };
	}

	function opacityChain( el, win ) {
		var o    = 1;
		var node = el;
		while ( node && node.nodeType === 1 && o > 0.05 ) {
			o   *= parseFloat( win.getComputedStyle( node ).opacity ) || 0;
			node = node.parentElement;
		}
		return o;
	}

	function isVisible( el, win ) {
		var cs = win.getComputedStyle( el );
		if ( cs.display === 'none' || cs.visibility !== 'visible' ) {
			return false;
		}
		var r = el.getBoundingClientRect();
		if ( r.width <= 1 || r.height <= 1 ) {
			return false; // Screen-reader text and collapsed boxes.
		}
		return opacityChain( el, win ) > 0.1;
	}

	/** A short, readable selector for an element: up to three levels, stopping at an id. */
	function selectorFor( el ) {
		var parts = [];
		var node  = el;
		for ( var depth = 0; node && node.nodeType === 1 && depth < 3; depth++ ) {
			var tag = node.tagName.toLowerCase();
			if ( tag === 'html' || tag === 'body' ) {
				parts.unshift( tag );
				break;
			}
			if ( node.id && /^[A-Za-z][\w-]*$/.test( node.id ) && ! /\d{3,}/.test( node.id ) ) {
				parts.unshift( '#' + node.id );
				break;
			}
			var classes = [];
			for ( var i = 0; i < node.classList.length && classes.length < 2; i++ ) {
				var c = node.classList[ i ];
				if ( c.indexOf( 'gpdm-force-' ) !== 0 && /^[A-Za-z_][\w-]*$/.test( c ) ) {
					classes.push( c );
				}
			}
			parts.unshift( tag + ( classes.length ? '.' + classes.join( '.' ) : '' ) );
			node = node.parentElement;
		}
		return parts.join( ' ' ).slice( 0, 200 );
	}

	function isLarge( cs ) {
		var size   = parseFloat( cs.fontSize ) || 16;
		var weight = parseInt( cs.fontWeight, 10 ) || 400;
		return size >= 24 || ( size >= 18.66 && weight >= 700 );
	}

	/** Elements that carry their own visible text, plus form fields (their text isn't a text node). */
	function textElements( doc ) {
		var seen   = [];
		var walker = doc.createTreeWalker( doc.body || doc.documentElement, 4 /* NodeFilter.SHOW_TEXT */, null, false );
		var n;
		while ( ( n = walker.nextNode() ) && seen.length < MAX_TEXTS ) {
			if ( ! /\S/.test( n.nodeValue ) ) {
				continue;
			}
			var el = n.parentElement;
			// A marker on the element (the frame is thrown away) — indexOf would be O(n²).
			if ( ! el || el.__gpdmSeen || el.closest( 'style, svg, .dm-toggle, #wpadminbar' ) ) {
				continue;
			}
			el.__gpdmSeen = true;
			seen.push( el );
		}
		var fields = doc.querySelectorAll( 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="image"]):not([type="range"]):not([type="color"]), select, textarea' );
		for ( var i = 0; i < fields.length && seen.length < MAX_TEXTS; i++ ) {
			if ( ! fields[ i ].__gpdmSeen ) {
				fields[ i ].__gpdmSeen = true;
				seen.push( fields[ i ] );
			}
		}
		return seen;
	}

	function sampleText( el ) {
		var t = ( el.value !== undefined && el.tagName !== 'BUTTON' && el.tagName !== 'LI' ) ? ( el.value || el.getAttribute( 'placeholder' ) || '' ) : el.textContent;
		return ( t || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 60 );
	}

	/** Measure one element's text: {ratio, need, fg, bg, overImage} or null when invisible. */
	function measure( el, win, doc ) {
		if ( ! isVisible( el, win ) ) {
			return null;
		}
		var cs   = win.getComputedStyle( el );
		var back = backgroundOf( el, win, doc );
		var fg   = parseColor( cs.color );
		if ( fg.a === 0 ) {
			return null;
		}
		var fgEff = over( fg, back.color );
		return {
			ratio: contrast( fgEff, back.color ),
			need: isLarge( cs ) ? 3 : 4.5,
			fg: hex( fgEff ),
			bg: hex( back.color ),
			overImage: back.overImage
		};
	}

	/* ---------- hover / focus emulation ---------- */

	var STATE_RE = /:(hover|focus-visible|focus-within|focus)(?![\w-])/g;

	/**
	 * Add a class-based twin of every same-origin rule that uses :hover or
	 * :focus, right after the original — same specificity (a class counts as
	 * much as a pseudo-class), same source order. Cross-origin sheets can't
	 * be read and are skipped.
	 */
	function installStateTwins( doc ) {
		var count = 0;
		function walk( list, owner ) {
			for ( var i = 0; i < list.length; i++ ) {
				var rule = list[ i ];
				if ( rule.cssRules && ! rule.selectorText ) {
					// @media / @supports / @layer — recurse in place.
					walk( rule.cssRules, rule );
					continue;
				}
				if ( ! rule.selectorText || ! STATE_RE.test( rule.selectorText ) ) {
					STATE_RE.lastIndex = 0;
					continue;
				}
				STATE_RE.lastIndex = 0;
				var twin = rule.selectorText.replace( STATE_RE, function ( m, state ) {
					return '.gpdm-force-' + ( state === 'focus-visible' ? 'focus' : state );
				} );
				try {
					owner.insertRule( twin + '{' + rule.style.cssText + '}', i + 1 );
					i++; // Skip the twin just inserted.
					count++;
				} catch ( e ) {
					// A selector the engine can't re-parse: skip it.
				}
			}
		}
		for ( var s = 0; s < doc.styleSheets.length; s++ ) {
			var sheet = doc.styleSheets[ s ];
			var rules;
			try {
				rules = sheet.cssRules;
			} catch ( e ) {
				continue;
			}
			if ( rules ) {
				walk( rules, sheet );
			}
		}
		return count;
	}

	function interactiveElements( doc ) {
		var list = doc.querySelectorAll( 'a[href], button, [role="button"], input[type="submit"], input[type="button"], .button, .wp-block-button__link, .gb-button' );
		var out  = [];
		for ( var i = 0; i < list.length && out.length < MAX_HOVER; i++ ) {
			if ( ! list[ i ].closest( '.dm-toggle, #wpadminbar' ) ) {
				out.push( list[ i ] );
			}
		}
		return out;
	}

	function setState( el, state, on ) {
		var cls = 'gpdm-force-' + state;
		if ( state === 'hover' || state === 'focus-within' ) {
			// :hover applies to every ancestor of the pointer target.
			for ( var node = el; node && node.nodeType === 1; node = node.parentElement ) {
				node.classList.toggle( cls, on );
			}
		} else {
			el.classList.toggle( cls, on );
		}
	}

	/* ---------- one page ---------- */

	function record( rows, el, m, state, alsoLight ) {
		var sel = selectorFor( el );
		var key = sel + '|' + m.fg + '|' + m.bg + '|' + state;
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].key === key ) {
				rows[ i ].count++;
				return;
			}
		}
		rows.push( {
			key: key,
			selector: sel,
			sample: sampleText( el ),
			fg: m.fg,
			bg: m.bg,
			ratio: Math.round( m.ratio * 100 ) / 100,
			need: m.need,
			count: 1,
			state: state,
			also_light: !! alsoLight,
			over_image: !! m.overImage
		} );
	}

	function checkDocument( frame, page ) {
		var win  = frame.contentWindow;
		var doc  = frame.contentDocument;
		var root = doc.documentElement;
		var texts = textElements( doc );

		// Light mode first: which elements already fail there (the site's own design)?
		root.setAttribute( 'data-theme', 'light' );
		var lightTotal = 0;
		for ( var i = 0; i < texts.length; i++ ) {
			var lm = measure( texts[ i ], win, doc );
			if ( lm && lm.ratio + 0.005 < lm.need ) {
				texts[ i ].__gpdmLightFail = true;
				lightTotal++;
			}
		}

		root.setAttribute( 'data-theme', 'dark' );
		var rows      = [];
		var darkTotal = 0;
		var measured  = 0;
		for ( var j = 0; j < texts.length; j++ ) {
			var dm = measure( texts[ j ], win, doc );
			if ( ! dm ) {
				continue;
			}
			measured++;
			if ( dm.ratio + 0.005 < dm.need ) {
				darkTotal++;
				record( rows, texts[ j ], dm, 'normal', texts[ j ].__gpdmLightFail );
			}
		}

		// Light panels: large boxes whose own background is still light in dark mode.
		var islands = [];
		var islandTotal = 0;
		var boxes = doc.querySelectorAll( 'body, body *' );
		for ( var b = 0; b < boxes.length; b++ ) {
			var box = boxes[ b ];
			if ( /^(IMG|PICTURE|VIDEO|CANVAS|SVG|IFRAME|OBJECT|EMBED|SOURCE|BR|HR)$/i.test( box.tagName ) || box.closest( 'svg, .dm-toggle' ) ) {
				continue;
			}
			var cs = win.getComputedStyle( box );
			var bg = parseColor( cs.backgroundColor );
			if ( bg.a < 0.5 || ( cs.backgroundImage && cs.backgroundImage !== 'none' ) || luminance( bg ) < 0.45 ) {
				continue;
			}
			var r = box.getBoundingClientRect();
			if ( r.width * r.height < ISLAND_AREA || ! isVisible( box, win ) ) {
				continue;
			}
			// Report the outermost light box only.
			var inside = false;
			for ( var k = 0; k < islands.length; k++ ) {
				if ( islands[ k ].el.contains( box ) ) {
					inside = true;
					break;
				}
			}
			if ( inside ) {
				continue;
			}
			islandTotal++;
			if ( islands.length < MAX_ISLANDS ) {
				islands.push( { el: box, selector: selectorFor( box ), bg: hex( over( bg, { r: 0, g: 0, b: 0, a: 1 } ) ), w: Math.round( r.width ), h: Math.round( r.height ) } );
			}
		}

		// Hover and focus, in dark mode.
		var twins = installStateTwins( doc );
		var interactive = twins ? interactiveElements( doc ) : [];
		var states = [ 'hover', 'focus' ];
		for ( var s = 0; s < states.length; s++ ) {
			for ( var t = 0; t < interactive.length; t++ ) {
				var el = interactive[ t ];
				if ( ! isVisible( el, win ) ) {
					continue;
				}
				setState( el, states[ s ], true );
				var sm = measure( el, win, doc );
				setState( el, states[ s ], false );
				if ( sm && sm.ratio + 0.005 < sm.need ) {
					darkTotal++;
					record( rows, el, sm, states[ s ], false );
				}
			}
		}

		rows.sort( function ( a, c ) {
			return a.ratio - c.ratio;
		} );
		return {
			url: page.url,
			label: page.label,
			ok: true,
			texts: measured,
			interactive: interactive.length,
			dark_total: darkTotal,
			light_total: lightTotal,
			island_total: islandTotal,
			failures: rows.slice( 0, MAX_ROWS ).map( function ( row ) {
				delete row.key;
				return row;
			} ),
			islands: islands.map( function ( isl ) {
				return { selector: isl.selector, bg: isl.bg, w: isl.w, h: isl.h };
			} )
		};
	}

	function checkPage( page ) {
		var frame = null;
		return fetchHtml( page.url ).then( function ( r ) {
			return render( sanitizeHtml( r.text, r.finalUrl ) );
		} ).then( function ( f ) {
			frame = f;
			if ( ! frame.contentDocument || ! frame.contentDocument.body ) {
				throw new Error( 'the page could not be rendered' );
			}
			return checkDocument( frame, page );
		} ).catch( function ( e ) {
			return { url: page.url, label: page.label, ok: false, error: String( ( e && e.message ) || e ).slice( 0, 150 ) };
		} ).then( function ( result ) {
			if ( frame && frame.parentNode ) {
				frame.parentNode.removeChild( frame );
			}
			return result;
		} );
	}

	/**
	 * Check every page in turn (one iframe at a time keeps memory flat).
	 * onProgress( done, total, label ) is called before each page.
	 */
	function run( pages, onProgress ) {
		var results = [];
		var chain   = Promise.resolve();
		pages.forEach( function ( page, i ) {
			chain = chain.then( function () {
				if ( onProgress ) {
					onProgress( i, pages.length, page.label );
				}
				return checkPage( page ).then( function ( res ) {
					results.push( res );
				} );
			} );
		} );
		return chain.then( function () {
			return { width: WIDTH, pages: results };
		} );
	}

	window.OGM_GPDM_AUDIT = {
		run: run,
		// Exposed for tests.
		_contrast: function ( a, b ) {
			return contrast( parseColor( a ), parseColor( b ) );
		}
	};
}( window, document ) );
