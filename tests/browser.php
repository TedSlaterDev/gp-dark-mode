<?php
/**
 * GP Dark Mode — the contrast-check engine in a real browser (1.13.0).
 *
 * Serves the plugin folder with PHP's built-in server, opens
 * tests/fixtures/audit/harness.html in headless Chrome, and checks what
 * assets/admin/gp-dark-mode-audit.js measured on tests/fixtures/audit/fields.html
 * and (1.13.1) gp-outside.html, which loads the real framework stylesheet.
 *
 * Optional: prints "(skipped: …)" and exits 0 when no Chrome/Chromium is
 * found. Set CHROME=/path/to/chrome to choose one.
 */

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = "" ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; } else { $fail++; echo "FAIL: $label" . ( "" !== $detail ? " — $detail" : "" ) . "\n"; }
}

function find_chrome() {
	$env = getenv( 'CHROME' );
	if ( $env && is_executable( $env ) ) {
		return $env;
	}
	foreach ( array( '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium' ) as $mac ) {
		if ( is_executable( $mac ) ) {
			return $mac;
		}
	}
	foreach ( array( 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser' ) as $bin ) {
		$path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) );
		if ( '' !== $path ) {
			return $path;
		}
	}
	return '';
}

$chrome = find_chrome();
if ( '' === $chrome ) {
	echo "(skipped: no Chrome or Chromium found — set CHROME=/path/to/chrome to run the browser check)\n";
	exit( 0 );
}

// A free port, then the built-in server on it.
$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( ! $probe ) {
	echo "(skipped: no free local port: $errstr)\n";
	exit( 0 );
}
$port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
fclose( $probe );

$root   = dirname( __DIR__ );
$server = proc_open(
	array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$pipes
);
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) {
	usleep( 100000 );
}

$profile = sys_get_temp_dir() . '/gpdm-browser-test-' . getmypid();
$cmd     = escapeshellarg( $chrome ) . ' --headless=new --disable-gpu --no-first-run --no-default-browser-check'
	. ' --user-data-dir=' . escapeshellarg( $profile )
	. ' --virtual-time-budget=15000 --dump-dom '
	. escapeshellarg( 'http://127.0.0.1:' . $port . '/tests/fixtures/audit/harness.html' ) . ' 2>/dev/null';
$dom = (string) shell_exec( $cmd );

proc_terminate( $server );
proc_close( $server );
exec( 'rm -rf ' . escapeshellarg( $profile ) );

$out = preg_match( '~<pre id="out">(.*?)</pre>~s', $dom, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
check( 'harness: the check finished', 0 === strpos( $out, 'RESULT ' ) );
$result = json_decode( (string) substr( $out, 7 ), true );
$page   = is_array( $result ) && isset( $result['pages'][0] ) ? $result['pages'][0] : array();
check( 'harness: the fixture page rendered', ! empty( $page['ok'] ) );

$rows = array();
foreach ( isset( $page['fields'] ) ? $page['fields'] : array() as $f ) {
	$rows[ $f['selector'] . ( 'focus' === $f['state'] ? ':focus' : '' ) ] = $f;
}
$near = function ( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 0.02;
};

check( 'fields: counts (10 bordered fields; submit, checkbox, hidden and borderless skipped)', isset( $page['fields_checked'] ) && 10 === $page['fields_checked'] );
check( 'fields: 5 dark-mode failures, 1 in light mode', isset( $page['field_total'] ) && 5 === $page['field_total'] && 1 === $page['field_light_total'] );
check( 'fields: exactly the failing fields are listed', array( '#both-modes', '#divider-field', '#focus-bad:focus', '#translucent', '#value-field' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $rows ) ) );

$d = isset( $rows['#divider-field'] ) ? $rows['#divider-field'] : array();
check( 'divider-colored border: 1.38:1 on the field, 1.54:1 on the page', $d && '#333340' === $d['border'] && '#1b1b20' === $d['field'] && '#0f0f12' === $d['bg'] && $near( $d['ratio_field'], 1.38 ) && $near( $d['ratio_bg'], 1.54 ) && $near( $d['ratio'], 1.38 ) );
check( 'divider-colored border: dark-mode only, sample from the placeholder', $d && false === $d['also_light'] && 'Your email' === $d['sample'] && 'normal' === $d['state'] );

$fo = isset( $rows['#focus-bad:focus'] ) ? $rows['#focus-bad:focus'] : array();
check( 'focus: a field that passes at rest but fails on focus is listed as focus only', $fo && '#2a2a30' === $fo['border'] && 'Search' === $fo['sample'] && ! isset( $rows['#focus-bad'] ) );

$b = isset( $rows['#both-modes'] ) ? $rows['#both-modes'] : array();
check( 'both modes: marked as failing in light mode too', $b && true === $b['also_light'] && 'message' === $b['sample'] );

$t = isset( $rows['#translucent'] ) ? $rows['#translucent'] : array();
check( 'translucent border: composited over the field, not reported as white', $t && '#ffffff' !== $t['border'] && $near( $t['ratio_field'], 1.43 ) );

$v = isset( $rows['#value-field'] ) ? $rows['#value-field'] : array();
check( 'privacy: the sample is the field name, never what is typed in it', $v && 'token' === $v['sample'] && false === strpos( $out, 'private-value-xyz' ) && false === strpos( $out, 'hidden-value' ) );

check( 'passes: strong border, strong bottom border only, one strong side, fill that stands out', ! isset( $rows['#ok-text'] ) && ! isset( $rows['#underline'] ) && ! isset( $rows['#mixed-sides'] ) && ! isset( $rows['#light-field'] ) && ! isset( $rows['#sel'] ) );
check( 'no text or light-panel noise from the fixture', isset( $page['dark_total'] ) && 0 === $page['dark_total'] && 0 === $page['island_total'] );

// 1.13.1: GeneratePress's own fields outside .site-content, with the real
// framework stylesheet, GP's field rules and the generated palette for GP's
// default colors. Without the site-wide field-border selectors the header
// search and the footer fields measure 1.16:1 (border #292929 on #191d22).
$gp = isset( $result['pages'][1] ) ? $result['pages'][1] : array();
check( 'GP fixture: rendered with the framework stylesheet', ! empty( $gp['ok'] ) );
check( 'GP fixture: header, content and footer fields measured (borderless navigation search skipped)', isset( $gp['fields_checked'] ) && 5 === $gp['fields_checked'] );
check( 'GP fixture: no field fails in dark mode, inside or outside the content area', isset( $gp['field_total'] ) && 0 === $gp['field_total'] && array() === $gp['fields'], isset( $gp['fields'] ) ? json_encode( $gp['fields'] ) : '' );
check( 'GP fixture: light mode untouched (GP\'s default field border fails there on its own)', isset( $gp['field_light_total'] ) && 5 === $gp['field_light_total'] );

echo "\nbrowser: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
