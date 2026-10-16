<?php
/**
 * GP Dark Mode — decomposition coverage test (no WordPress).
 *
 * Proves the 1.2.0 layer split is lossless: every selector+declaration pair
 * in the pre-split Additional CSS seed (fixtures/seed-v1.0.3.css) must still
 * exist somewhere in the union of the module files + the new slim seed +
 * the framework. Run: php tests/coverage.php
 */

$root = dirname( __DIR__ );

$fixture_file = __DIR__ . '/fixtures/seed-v1.0.3.css';
$union_files  = array_merge(
	array(
		$root . '/assets/css/gp-dark-mode.css',
		$root . '/tests/fixtures/theme-manual-css.css',
	),
	glob( $root . '/assets/css/modules/*.css' )
);

/** Strip comments, then map "selector" => [declarations...] across a stylesheet. */
function rules_map( $css ) {
	$css = preg_replace( '~/\*.*?\*/~s', '', $css );
	$map = array();
	if ( ! preg_match_all( '~([^{}]+)\{([^{}]*)\}~s', $css, $m, PREG_SET_ORDER ) ) {
		return $map;
	}
	foreach ( $m as $rule ) {
		$sel_list = $rule[1];
		// Drop any @media/@supports prelude that leaks into the selector text.
		$sel_list = preg_replace( '~@[a-z-]+[^,{]*~i', '', $sel_list );
		$decls    = array();
		foreach ( explode( ';', $rule[2] ) as $decl ) {
			$decl = trim( preg_replace( '~\s+~', ' ', $decl ) );
			if ( '' !== $decl ) {
				$decls[] = strtolower( $decl );
			}
		}
		foreach ( explode( ',', $sel_list ) as $sel ) {
			$sel = trim( preg_replace( '~\s+~', ' ', $sel ) );
			if ( '' === $sel ) {
				continue;
			}
			if ( ! isset( $map[ $sel ] ) ) {
				$map[ $sel ] = array();
			}
			$map[ $sel ] = array_merge( $map[ $sel ], $decls );
		}
	}
	return $map;
}

if ( ! is_readable( $fixture_file ) ) {
	echo "FAIL: fixture missing: $fixture_file\n";
	exit( 1 );
}

$fixture = rules_map( file_get_contents( $fixture_file ) );

$union = array();
foreach ( $union_files as $file ) {
	foreach ( rules_map( file_get_contents( $file ) ) as $sel => $decls ) {
		if ( ! isset( $union[ $sel ] ) ) {
			$union[ $sel ] = array();
		}
		$union[ $sel ] = array_merge( $union[ $sel ], $decls );
	}
}

// Deliberate divergences from the v1.0.3 seed. Each entry documents a pair
// we CHOSE to drop, with the release and reason — anything not listed here
// still fails the run, so accidental losses stay caught.
$intentionally_dropped = array(
	// 1.5.8: dark mode must never alter typography; these made homepage ad
	// headlines resize/re-weight between modes. Sites wanting the normalized
	// look add it mode-independently via the Manual tab.
	'html[data-theme="dark"] body.home .rc-headline  =>  font-size: inherit !important',
	'html[data-theme="dark"] body.home .rc-headline  =>  font-weight: 600',
	'html[data-theme="dark"] body.home .rc-headline a  =>  font-size: inherit !important',
	'html[data-theme="dark"] body.home .rc-headline a  =>  font-weight: 600',
	// 1.11.0: the dark-mode menu was simplified at the site owner's request —
	// no accent fill, no white rules above and below the bar and dropdowns, no
	// fixed alternate hover color. A light menu bar is darkened by the computed
	// GP-surfaces rules instead (an already-dark or brand bar keeps its color);
	// text is var(--text); hover/current get a translucent wash that reads on
	// any bar. The accent look stays one setting away ("Dark nav accent").
	'html[data-theme="dark"] .main-navigation  =>  background-color: var(--accent)',
	'html[data-theme="dark"] .main-navigation  =>  border-bottom: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation  =>  border-top: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation  =>  color: #fff',
	'html[data-theme="dark"] .main-navigation ul ul  =>  background-color: var(--accent)',
	'html[data-theme="dark"] .main-navigation ul ul  =>  border-bottom: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation ul ul  =>  border-top: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation ul ul  =>  color: #fff',
	'html[data-theme="dark"] .main-navigation .menu-toggle  =>  background-color: var(--accent)',
	'html[data-theme="dark"] .main-navigation .menu-toggle  =>  border-bottom: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation .menu-toggle  =>  border-top: solid 1px rgb(255 255 255 / 40%)',
	'html[data-theme="dark"] .main-navigation .menu-toggle  =>  color: #fff',
	'html[data-theme="dark"] .main-navigation .main-nav ul li:not([class*="current-menu-"]):hover > a  =>  background-color: var(--bg-2)',
	'html[data-theme="dark"] .main-navigation .main-nav ul li:not([class*="current-menu-"]):focus > a  =>  background-color: var(--bg-2)',
	'html[data-theme="dark"] .main-navigation .main-nav ul li.sfHover:not([class*="current-menu-"]) > a  =>  background-color: var(--bg-2)',
	'html[data-theme="dark"] .main-navigation .menu-bar-item:hover > a  =>  color: var(--text)',
	'html[data-theme="dark"] .main-navigation .menu-bar-item:hover > a  =>  background-color: var(--bg-2)',
	'html[data-theme="dark"] .main-navigation .menu-bar-item.sfHover > a  =>  color: var(--text)',
	'html[data-theme="dark"] .main-navigation .menu-bar-item.sfHover > a  =>  background-color: var(--bg-2)',
	'html[data-theme="dark"] .main-navigation .main-nav ul li[class*="current-menu-"] > a  =>  background-color: var(--bg-2)',
);

$missing = array();
$checked = 0;
$waived  = 0;

foreach ( $fixture as $sel => $decls ) {
	foreach ( $decls as $decl ) {
		$checked++;
		if ( ! isset( $union[ $sel ] ) || ! in_array( $decl, $union[ $sel ], true ) ) {
			if ( in_array( "$sel  =>  $decl", $intentionally_dropped, true ) ) {
				$waived++;
				continue;
			}
			$missing[] = "$sel  =>  $decl";
		}
	}
}

// Print-time minification must be lossless: for every shipped CSS file the
// rule map is identical after OGM_GPDM_CSS::minify() (compared with all
// whitespace removed, since the minifier's only job is whitespace), and every
// quoted string survives byte-for-byte (which the whitespace-blind compare
// alone could not prove).
// The include has WordPress's ABSPATH guard, which calls exit() — silently,
// with status 0 — when the constant is missing. Define it first or this whole
// script dies here looking like a pass.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
require_once $root . '/includes/class-ogm-gpdm-css.php';

/** Whitespace-blind copy of a rules_map(), for raw-vs-minified comparison. */
function canon_map( array $map ) {
	$out = array();
	foreach ( $map as $sel => $decls ) {
		$out[ preg_replace( '~\s+~', '', $sel ) ] = array_map(
			function ( $d ) {
				return preg_replace( '~\s+~', '', $d );
			},
			$decls
		);
	}
	return $out;
}

$string_re = '~"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'~';
$min_fail  = array();
foreach ( $union_files as $file ) {
	$raw = file_get_contents( $file );
	$min = OGM_GPDM_CSS::minify( $raw );
	if ( canon_map( rules_map( $raw ) ) !== canon_map( rules_map( $min ) ) ) {
		$min_fail[] = basename( $file ) . ' (rule map differs after minify)';
	}
	preg_match_all( $string_re, preg_replace( '~/\*.*?\*/~s', '', $raw ), $raw_strings );
	preg_match_all( $string_re, $min, $min_strings );
	if ( $raw_strings[0] !== $min_strings[0] ) {
		$min_fail[] = basename( $file ) . ' (a quoted string changed)';
	}
}
if ( ! empty( $min_fail ) ) {
	echo "FAIL: minification is NOT lossless:\n";
	foreach ( $min_fail as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}

if ( empty( $missing ) ) {
	echo 'PASS: all ' . $checked . ' selector+declaration pairs from the v1.0.3 seed are present in the framework + modules + new seed union (' . count( $union_files ) . ' files)' . ( $waived ? ', ' . $waived . ' documented intentional divergence(s) waived' : '' ) . '; minify lossless across all ' . count( $union_files ) . " files.\n";
	exit( 0 );
}

echo 'FAIL: ' . count( $missing ) . " pair(s) lost in the decomposition:\n";
foreach ( $missing as $miss ) {
	echo "  - $miss\n";
}
exit( 1 );
