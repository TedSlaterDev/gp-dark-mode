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

if ( empty( $missing ) ) {
	echo 'PASS: all ' . $checked . ' selector+declaration pairs from the v1.0.3 seed are present in the framework + modules + new seed union (' . count( $union_files ) . ' files)' . ( $waived ? ', ' . $waived . ' documented intentional divergence(s) waived' : '' ) . ".\n";
	exit( 0 );
}

echo 'FAIL: ' . count( $missing ) . " pair(s) lost in the decomposition:\n";
foreach ( $missing as $miss ) {
	echo "  - $miss\n";
}
exit( 1 );
