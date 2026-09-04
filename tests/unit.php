<?php
/**
 * GP Dark Mode — bundle assembler unit tests (pure functions, no WordPress).
 * Run: php tests/unit.php
 */
define( 'ABSPATH', '/tmp/' );
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-bundle.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-palette.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-ai.php';

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "PASS: $label\n";
	} else {
		$fail++;
		echo "FAIL: $label" . ( $detail ? " — $detail" : '' ) . "\n";
	}
}

// ---- extract_color_css ----

$tricky = <<<CSS
@import url("foo.css");
@charset "utf-8";

/* comment with { brace } inside */
.plain-layout { display: flex; margin: 0 auto; width: 100%; }

.kept { color: #fff; padding: 10px; background: var(--bg); }

@media (max-width: 768px) {
  .m-layout { display: none; }
  .m-color { border-bottom: 1px solid rgb(0 0 0 / 40%); float: left; }
}

@media print {
  .p-layout { width: 100%; }
}

@keyframes spin { from { transform: rotate(0); color: red; } to { transform: rotate(360deg); } }

@font-face { font-family: X; src: url(x.woff2); }

.data-uri { background: url(data:image/png;base64,AAA;BBB==) no-repeat; width: 4px; }

.content-brace::after { content: "}"; color: #123456; }

:root { --accent: #1d428a; --spacing: 10px; }

.rounded { border-radius: 8px; transition: color .2s ease; }

.shadowed { box-shadow: 0 1px 2px rgba(0,0,0,.5); border-width: 2px; }

.shorthand-color { outline: 2px solid currentColor; }
CSS;

$out = OGM_GPDM_Bundle::extract_color_css( $tricky );
echo "----- tricky extract -----\n$out\n--------------------------\n";

check( 'kept rule survives', false !== strpos( $out, '.kept' ) && false !== strpos( $out, 'color: #fff' ) );
check( 'layout-only rule dropped', false === strpos( $out, '.plain-layout' ) );
check( 'padding dropped from kept rule', false === strpos( $out, 'padding' ) );
check( 'media wrapper kept when inner color rule exists', false !== strpos( $out, '@media (max-width: 768px)' ) && false !== strpos( $out, '.m-color' ) );
check( 'media inner layout rule dropped', false === strpos( $out, '.m-layout' ) );
check( 'print media with no color rules dropped entirely', false === strpos( $out, '@media print' ) );
check( 'keyframes dropped', false === strpos( $out, '@keyframes' ) && false === strpos( $out, 'spin' ) );
check( 'font-face dropped', false === strpos( $out, '@font-face' ) );
check( 'data-uri background kept intact', false !== strpos( $out, 'base64,AAA;BBB==' ) );
check( 'content-brace rule intact with color', false !== strpos( $out, '.content-brace::after' ) && false !== strpos( $out, '#123456' ) );
check( 'custom props kept', false !== strpos( $out, '--accent: #1d428a' ) && false !== strpos( $out, '--spacing: 10px' ) );
check( 'border-radius dropped', false === strpos( $out, 'border-radius' ) );
check( 'transition dropped', false === strpos( $out, 'transition' ) );
check( 'box-shadow kept', false !== strpos( $out, '.shadowed' ) && false !== strpos( $out, 'box-shadow' ) );
check( 'border-width dropped', false === strpos( $out, 'border-width' ) );
check( 'currentColor shorthand kept', false !== strpos( $out, '.shorthand-color' ) );
check( '@import statement not misparsed as selector', false === strpos( $out, '@import' ) );

// Real file: the reference site's Manual-CSS residue (a fixture since 1.6.0 —
// the plugin no longer ships site-specific CSS).
$real    = file_get_contents( __DIR__ . '/fixtures/theme-manual-css.css' );
$realOut = OGM_GPDM_Bundle::extract_color_css( $real );
check( 'site-overrides: pagination rule kept', false !== strpos( $realOut, ':not(:hover)' ) );
check( 'site-overrides: reduced substantially', strlen( $realOut ) < strlen( $real ) );

// The nav rule moved to the gp-chrome module in the 1.2.0 layer split.
$chrome    = file_get_contents( dirname( __DIR__ ) . '/assets/css/modules/gp-chrome.css' );
$chromeOut = OGM_GPDM_Bundle::extract_color_css( $chrome );
check( 'gp-chrome module: nav rule kept', false !== strpos( $chromeOut, 'html[data-theme="dark"] .main-navigation' ) && false !== strpos( $chromeOut, 'background-color: var(--accent)' ) );
echo 'site-overrides: ' . strlen( $real ) . ' bytes -> ' . strlen( $realOut ) . " bytes\n";

// Adhesion units (Ad Inserter inline position:fixed / ai-close) render on
// Revcontent's own white panel: the counter-rules must exist and must
// out-specify the body.home white-headline rule (three classes vs two).
$rc = file_get_contents( dirname( __DIR__ ) . '/assets/css/modules/revcontent.css' );
check( 'revcontent module: adhesion counter-rule present', false !== strpos( $rc, '.code-block.ai-close .rc-headline' ) && false !== strpos( $rc, '.code-block[style*="position: fixed"] .rc-headline' ) );
check( 'revcontent module: global white-headline rule present', false !== strpos( $rc, "html[data-theme=\"dark\"] .rc-headline,\nhtml[data-theme=\"dark\"] .rc-headline a" ) );
check( 'revcontent module: widget chrome covered (sbn/provider)', false !== strpos( $rc, '.sbn-header-headline' ) && false !== strpos( $rc, '.rc-provider' ) && false !== strpos( $rc, '.sbn-item-brand' ) );
check( 'revcontent module: adhesion counters cover widget chrome', false !== strpos( $rc, '.code-block.ai-close .rc-provider' ) && false !== strpos( $rc, '.code-block.ai-close .sbn-header-headline' ) );

// Rumble module: cross-origin iframe creatives can't be restyled — the
// backing plate targets the rac-ad wrapper AND the iframe, dark-scoped.
$rmbl = file_get_contents( dirname( __DIR__ ) . '/assets/css/modules/rumble.css' );
check( 'rumble module: rac-ad wrapper backing plate', false !== strpos( $rmbl, 'html[data-theme="dark"] div[id^="rac-ad-"]' ) );
check( 'rumble module: dark-scoped iframe backing plate', false !== strpos( $rmbl, 'html[data-theme="dark"] iframe[src*="ads.rmbl.ws"]' ) && false !== strpos( $rmbl, 'background: #fff' ) );

// Comment-terminator landmine guard (found live: a docblock containing the
// text "sbn-*/rc-*" ended the comment at the embedded terminator, and the
// browser's error recovery then swallowed the module's rules AND the whole
// next module in the concatenated blob). After stripping well-formed
// comments, no shipped CSS file may contain a stray comment delimiter.
foreach ( array_merge(
	array( dirname( __DIR__ ) . '/assets/css/gp-dark-mode.css' ),
	glob( dirname( __DIR__ ) . '/assets/css/modules/*.css' )
) as $css_file ) {
	$stripped = preg_replace( '~/\*.*?\*/~s', '', file_get_contents( $css_file ) );
	check( 'comment balance: ' . basename( $css_file ), false === strpos( $stripped, '*/' ) && false === strpos( $stripped, '/*' ) );
}
check( 'revcontent module: adhesion headlines forced dark', false !== strpos( $rc, '#1d1d1f !important' ) );
check( 'revcontent module: adhesion keeps light panel', false !== strpos( $rc, '.code-block.ai-close .rc-feed-container' ) );

// Dark mode must never alter typography (1.5.8): the module carries no
// font-size/font-weight declarations — color only. (Comments may still
// mention the properties by name, so strip them before checking.)
$rc_code = preg_replace( '~/\*.*?\*/~s', '', $rc );
check( 'revcontent module: no typography in dark rules', false === strpos( $rc_code, 'font-size' ) && false === strpos( $rc_code, 'font-weight' ) );

// The framework must be inert in light mode: no un-scoped global rules.
$fw = file_get_contents( dirname( __DIR__ ) . '/assets/css/gp-dark-mode.css' );
check( 'framework: global text rule is dark-scoped', false !== strpos( $fw, 'html[data-theme="dark"] blockquote' ) );
check( 'framework: no unscoped body/p/blockquote globals', ! preg_match( '~^(?:body|blockquote|p|a)\s*[,{]~m', $fw ) );
check( 'framework: surfaces + forms dark-scoped', false !== strpos( $fw, 'html[data-theme="dark"] .site-content input' ) && ! preg_match( '~^\.site-content~m', $fw ) );

// Optional fixture: a real child-theme functions.php with a CSS heredoc.
$fixture = '/Users/ted/Desktop/dark-mode/functions.php';
if ( is_readable( $fixture ) ) {
	$fn = file_get_contents( $fixture );
	if ( preg_match( '~<<<CSS(.*?)\nCSS;~s', $fn, $m ) ) {
		$manualOut = OGM_GPDM_Bundle::extract_color_css( $m[1] );
		check( 'Manual CSS heredoc: parses without fatal, non-empty', strlen( $manualOut ) > 1000 );
		check( 'Manual CSS heredoc: dark vars kept', false !== strpos( $manualOut, '--bg: #0f0f12' ) );
		check( 'Manual CSS heredoc: dm-track box-shadow kept', false !== strpos( $manualOut, '.dm-track' ) );
		echo 'Manual CSS heredoc: ' . strlen( $m[1] ) . ' bytes -> ' . strlen( $manualOut ) . " bytes\n";
	}
} else {
	echo "(child-theme functions.php fixture not present — heredoc checks skipped)\n";
}

// Nested CSS (modern nesting) — best-effort wrapper retention.
$nested = '.card { color: red; .inner { background: #000; margin: 0; } }';
$nOut   = OGM_GPDM_Bundle::extract_color_css( $nested );
check( 'nested: wrapper + inner color kept', false !== strpos( $nOut, '.card' ) && false !== strpos( $nOut, '.inner' ) );

// ---- regression: review findings ----

// F1a: quoted brace in a SELECTOR must not kill the rest of the sheet.
$o = OGM_GPDM_Bundle::extract_color_css( 'a[href*="{"] { color: red; } .later { color: green; } .even-later { background: #000; }' );
check( 'F1a: quoted-brace selector — later rules survive', false !== strpos( $o, '.later' ) && false !== strpos( $o, '.even-later' ) && false !== strpos( $o, 'color: red' ) );

// F1b: unbalanced brace — resync keeps later rules and flags the extract.
$o = OGM_GPDM_Bundle::extract_color_css( ".broken { color: red \n.fine { color: green; }\n.also-fine { background: #000; }" );
check( 'F1b: unbalanced brace — later rules recovered', false !== strpos( $o, 'color: green' ) && false !== strpos( $o, '.also-fine' ) );
check( 'F1b: truncation note present', false !== strpos( $o, 'extraction hit malformed' ) );

// F3: a >1MB comment must not wipe the extract (manual comment strip).
$huge = '.before { color: red; } /*' . str_repeat( 'x', 1500000 ) . '*/ .after { color: blue; }';
$o = OGM_GPDM_Bundle::extract_color_css( $huge );
check( 'F3: huge comment — both rules survive', false !== strpos( $o, '.before' ) && false !== strpos( $o, '.after' ) );

// F4a: nesting — parent declarations kept alongside nested rule.
$o = OGM_GPDM_Bundle::extract_color_css( '.card { color: red; background: #111; &:hover { color: blue; } }' );
check( 'F4a: nesting keeps parent decls', false !== strpos( $o, 'color: red' ) && false !== strpos( $o, 'background: #111' ) && false !== strpos( $o, '&:hover' ) );

// F4b: nested @media with bare declarations inside a rule.
$o = OGM_GPDM_Bundle::extract_color_css( '.btn { color:#fff; @media (max-width:600px){ background:#333 } }' );
check( 'F4b: nested @media bare decls kept', false !== strpos( $o, 'color: #fff' ) && false !== strpos( $o, 'background: #333' ) );

// F5: vendor-prefixed color props + named colors (the autofill fix).
$o = OGM_GPDM_Bundle::extract_color_css( 'input:-webkit-autofill { -webkit-box-shadow: 0 0 0 30px black inset; -webkit-text-fill-color: white; }' );
check( 'F5: -webkit autofill rule kept in full', false !== strpos( $o, '-webkit-box-shadow' ) && false !== strpos( $o, '-webkit-text-fill-color' ) );

// ---- redact_secrets ----

$php_src = <<<'PHP'
<?php
define( 'GA4_API_KEY', 'AIzaSyD-1234567890abcdef' );
define( 'MY_COLOR', '#1d428a' );
$api_key = "sk-ant-abc123456789012345";
$options['password'] = 'hunter2hunter2';
$secret_token = 'shhh-very-secret-value';
$css = '.token-list { color: #fff; }';
echo 'client_secret: "abcdefgh12345678"';
PHP;

$red = OGM_GPDM_Bundle::redact_secrets( $php_src );
echo "----- redacted -----\n$red\n--------------------\n";
check( 'define KEY redacted', false === strpos( $red, 'AIzaSyD' ) );
check( 'color define NOT redacted', false !== strpos( $red, '#1d428a' ) );
check( 'api_key var redacted', false === strpos( $red, 'sk-ant-abc' ) );
check( 'password redacted', false === strpos( $red, 'hunter2hunter2' ) );
check( 'secret token redacted', false === strpos( $red, 'shhh-very-secret-value' ) );
check( 'CSS class named token untouched', false !== strpos( $red, '.token-list { color: #fff; }' ) );
check( 'client_secret string redacted', false === strpos( $red, 'abcdefgh12345678' ) );

// F2: redaction must never eat real code.
$src2 = "error_log( \"GA4 token: \" . \$token );\n\tdo_something_important();\n\t\$opt = get_option( 'my_theme_setting' );";
$red2 = OGM_GPDM_Bundle::redact_secrets( $src2 );
check( 'F2a: log-string with token: untouched', $red2 === $src2 );

$red3 = OGM_GPDM_Bundle::redact_secrets( "\$options['secret_page'] = 'welcome-friends';" );
check( 'F2b: secret_page array key untouched', false !== strpos( $red3, 'welcome-friends' ) );

$red4 = OGM_GPDM_Bundle::redact_secrets( '<input type="password" name="user_pass" />' );
check( 'F2c: password form markup untouched', false !== strpos( $red4, 'user_pass' ) && false === strpos( $red4, 'REDACTED' ) );

$red5 = OGM_GPDM_Bundle::redact_secrets( "\$acct['password'] = 'hunter2hunter2';" );
check( 'F2d: array-key password value still redacted', false === strpos( $red5, 'hunter2hunter2' ) );

// ---- OGM_GPDM_Palette (Layer 2 color math) ----

$hsl = OGM_GPDM_Palette::hex_to_hsl( '#1d428a' );
$rt  = OGM_GPDM_Palette::hsl_to_hex( $hsl[0], $hsl[1], $hsl[2] );
check( 'palette: hex->hsl->hex roundtrip close', 1 >= abs( hexdec( substr( $rt, 1, 2 ) ) - 0x1d ) );

check( 'palette: invalid hex rejected', null === OGM_GPDM_Palette::hex_to_rgb( 'red' ) && null === OGM_GPDM_Palette::hex_to_rgb( '#12345' ) );
check( 'palette: 3-digit hex accepted', array( 255, 255, 255 ) === OGM_GPDM_Palette::hex_to_rgb( '#fff' ) );

$surface = OGM_GPDM_Palette::derive_dark( '#f7f8f9', 'base-2' );
$s_hsl   = OGM_GPDM_Palette::hex_to_hsl( $surface );
check( 'palette: light surface goes dark', null !== $surface && $s_hsl[2] < 0.25 );

$text = OGM_GPDM_Palette::derive_dark( '#222222', 'contrast' );
check( 'palette: dark text goes light + meets 4.5:1', null !== $text && OGM_GPDM_Palette::contrast_ratio( $text, OGM_GPDM_Palette::DARK_REF ) >= 4.5 );

// A representative brand accent (#1d428a) sits below 3:1 on the dark ref — must be lightened.
$brand = OGM_GPDM_Palette::derive_dark( '#1d428a', 'accent' );
check( 'palette: too-dark brand color lightened to >= 3:1', null !== $brand && OGM_GPDM_Palette::contrast_ratio( $brand, OGM_GPDM_Palette::DARK_REF ) >= 3 );

// OGM green (#5b8c3e) is already readable on dark — must be left untouched.
check( 'palette: readable brand color untouched (null)', null === OGM_GPDM_Palette::derive_dark( '#5b8c3e', 'accent' ) );

check( 'palette: surface ordering preserved inverted', OGM_GPDM_Palette::hex_to_hsl( OGM_GPDM_Palette::derive_dark( '#ffffff', 'base' ) )[2] <= OGM_GPDM_Palette::hex_to_hsl( OGM_GPDM_Palette::derive_dark( '#e7edf7', 'base-3' ) )[2] );

// ---- review-round regressions: ramp-aware derivation ----

check( 'palette: rgba() parsed', array( 255, 255, 255 ) === OGM_GPDM_Palette::parse_color( 'rgba(255, 255, 255, 0.9)' ) );
check( 'palette: rgb() parsed', array( 29, 66, 138 ) === OGM_GPDM_Palette::parse_color( 'rgb(29,66,138)' ) );
check( 'palette: gradients rejected', null === OGM_GPDM_Palette::parse_color( 'linear-gradient(#fff,#000)' ) );

check( 'palette: dark saturated custom slug is brand, not text', 'brand' === OGM_GPDM_Palette::classify( '#8b0000', 'dominant' ) );
check( 'palette: slug "white" is utility', 'utility' === OGM_GPDM_Palette::classify( '#ffffff', 'white' ) );
check( 'palette: already-light text left unchanged', null === OGM_GPDM_Palette::derive_dark( '#ffffff', 'button-text' ) );

// The GP DEFAULT palette must produce distinct, ordered ramps.
$gp_defaults = array(
	array( 'slug' => 'contrast', 'color' => '#222222' ),
	array( 'slug' => 'contrast-2', 'color' => '#575760' ),
	array( 'slug' => 'contrast-3', 'color' => '#b2b2be' ),
	array( 'slug' => 'base', 'color' => '#f0f0f0' ),
	array( 'slug' => 'base-2', 'color' => '#f7f8f9' ),
	array( 'slug' => 'base-3', 'color' => '#ffffff' ),
	array( 'slug' => 'accent', 'color' => '#1e73be' ),
);
$p = OGM_GPDM_Palette::derive_palette( $gp_defaults );

$l = function ( $slug ) use ( $p ) { return OGM_GPDM_Palette::hex_to_hsl( $p['map'][ $slug ]['dark'] )[2]; };

check( 'ramp: all three surfaces derived', isset( $p['map']['base'], $p['map']['base-2'], $p['map']['base-3'] ) );
check( 'ramp: surfaces pairwise distinct', abs( $l('base') - $l('base-2') ) >= 0.03 && abs( $l('base-2') - $l('base-3') ) >= 0.03 );
check( 'ramp: surface ordering inverted (lightest -> darkest)', $l('base-3') < $l('base-2') && $l('base-2') < $l('base') );

check( 'ramp: primary text >= 4.5:1', OGM_GPDM_Palette::contrast_ratio( $p['map']['contrast']['dark'], OGM_GPDM_Palette::DARK_REF ) >= 4.5 );
check( 'ramp: contrast tiers distinct', isset( $p['map']['contrast-2'] ) && abs( $l('contrast') - $l('contrast-2') ) >= 0.03 );
check( 'ramp: light tertiary gray kept as-is', ! isset( $p['map']['contrast-3'] ) && false !== strpos( implode( '|', $p['kept'] ), 'contrast-3' ) );
check( 'ramp: GP default accent (3.87:1) kept', ! isset( $p['map']['accent'] ) && false !== strpos( implode( '|', $p['kept'] ), 'accent' ) );

// A too-dark brand color still gets lightened at the palette level.
$p2 = OGM_GPDM_Palette::derive_palette( array( array( 'slug' => 'accent', 'color' => '#1d428a' ) ) );
check( 'ramp: too-dark brand lightened', isset( $p2['map']['accent'] ) && OGM_GPDM_Palette::contrast_ratio( $p2['map']['accent']['dark'], OGM_GPDM_Palette::DARK_REF ) >= 3 );

// A saturated color in a text slot (WTT: brand red in contrast-2) must keep
// its hue strength instead of turning muddy pink.
$p3 = OGM_GPDM_Palette::derive_palette( array(
	array( 'slug' => 'contrast', 'color' => '#1f2024' ),
	array( 'slug' => 'contrast-2', 'color' => '#de2426' ),
) );
$red_s = OGM_GPDM_Palette::hex_to_hsl( $p3['map']['contrast-2']['dark'] )[1];
check( 'ramp: saturated text slot keeps hue strength', $red_s >= 0.35 );
$gray_s = OGM_GPDM_Palette::hex_to_hsl( $p3['map']['contrast']['dark'] )[1];
check( 'ramp: neutral text slot stays muted', $gray_s <= 0.20 );

// ---- usage-aware classification + already-dark surface guard ----
// (live WTT case: the site header's background is painted with
// var(--contrast), a "text"-named color; the generator flipped it light and
// the dark header turned light gray in dark mode.)

// Usage says surface, color is already dark: kept unchanged.
$p4 = OGM_GPDM_Palette::derive_palette(
	array( array( 'slug' => 'contrast', 'color' => '#1f2024' ) ),
	array( 'contrast' => array( 'surface' ) )
);
check( 'usage: dark color used as surface kept unchanged', ! isset( $p4['map']['contrast'] ) && false !== strpos( implode( '|', $p4['kept'] ), 'already dark' ) );

// Usage says both surface and text: unchanged, with an honest note.
$p5 = OGM_GPDM_Palette::derive_palette(
	array( array( 'slug' => 'contrast', 'color' => '#1f2024' ) ),
	array( 'contrast' => array( 'surface', 'text' ) )
);
check( 'usage: conflicting roles kept unchanged', ! isset( $p5['map']['contrast'] ) && false !== strpos( implode( '|', $p5['kept'] ), 'both background and text' ) );

// Usage overrides the slug the other way too: a base-named color used as
// text goes through the text ramp instead of the surface ramp.
$p6 = OGM_GPDM_Palette::derive_palette(
	array( array( 'slug' => 'base-2', 'color' => '#333333' ) ),
	array( 'base-2' => array( 'text' ) )
);
check( 'usage: text-used base slug derived as text', isset( $p6['map']['base-2'] ) && 0 === strpos( $p6['map']['base-2']['class'], 'text' ) );

// Slug-classified surfaces that are ALREADY dark are kept even without
// usage data (the mirror of the already-light-text rule).
$p7 = OGM_GPDM_Palette::derive_palette( array( array( 'slug' => 'base', 'color' => '#101010' ) ) );
check( 'ramp: already-dark surface kept unchanged', ! isset( $p7['map']['base'] ) && false !== strpos( implode( '|', $p7['kept'] ), 'already dark' ) );
check( 'derive_dark: already-dark surface null', null === OGM_GPDM_Palette::derive_dark( '#101010', 'base' ) );

// Brand colors are exempt from usage overrides: a saturated accent used as
// a background keeps its identity instead of being grayed into the ramp.
$p8 = OGM_GPDM_Palette::derive_palette(
	array( array( 'slug' => 'accent', 'color' => '#5b8c3e' ) ),
	array( 'accent' => array( 'surface' ) )
);
check( 'usage: brand exempt from usage override', ! isset( $p8['map']['accent'] ) && false !== strpos( implode( '|', $p8['kept'] ), 'accent' ) );

// ---- OGM_GPDM_AI::extract_css (pure response parsing) ----

$r = OGM_GPDM_AI::extract_css( "- darkened the widget\n- pruned duplicates\n\n```css\nhtml[data-theme=\"dark\"] .x { color: #fff; }\n```\nDone." );
check( 'ai: fenced css extracted', null !== $r && false !== strpos( $r['css'], '.x { color: #fff; }' ) );
check( 'ai: notes captured', false !== strpos( $r['notes'], 'darkened the widget' ) );

$r = OGM_GPDM_AI::extract_css( "```css\n.small { color: #000; }\n```\ntext\n```css\n.big { color: #111; }\n.big2 { color: #222; }\n.big3 { color: #333; }\n```" );
check( 'ai: largest fenced block wins', null !== $r && false !== strpos( $r['css'], '.big3' ) && false === strpos( $r['css'], '.small' ) );

$r = OGM_GPDM_AI::extract_css( "```\n.untyped { color: red; }\n```" );
check( 'ai: untagged fence accepted', null !== $r && false !== strpos( $r['css'], '.untyped' ) );

$r = OGM_GPDM_AI::extract_css( 'html[data-theme="dark"] .raw { color: #fff; }' );
check( 'ai: unfenced raw css accepted', null !== $r && false !== strpos( $r['css'], '.raw' ) );

// ---- model ranking (Editorial QA pattern) ----

$ranked = OGM_GPDM_AI::rank_anthropic_models( array( 'claude-haiku-4-5', 'claude-opus-4-6', 'claude-opus-5', 'claude-sonnet-5', 'claude-fable-5', 'claude-opus-4-6' ) );
check( 'rank anthropic: fable first, haiku last', 'claude-fable-5' === $ranked[0] && 'claude-haiku-4-5' === end( $ranked ) );
check( 'rank anthropic: deduped', 5 === count( $ranked ) );
check( 'rank anthropic: opus-5 before opus-4-6', array_search( 'claude-opus-5', $ranked, true ) < array_search( 'claude-opus-4-6', $ranked, true ) );

$ranked = OGM_GPDM_AI::rank_openai_models( array( 'gpt-4o', 'gpt-4o-2024-05-13', 'gpt-5', 'chatgpt-4o-latest', 'gpt-3.5-turbo' ) );
check( 'rank openai: gpt-5 first', 'gpt-5' === $ranked[0] );
check( 'rank openai: dated snapshot collapsed into family head', in_array( 'gpt-4o', $ranked, true ) && ! in_array( 'gpt-4o-2024-05-13', $ranked, true ) );

check( 'seed models: defaults lead each list', 'claude-opus-5' === OGM_GPDM_AI::seed_models( 'anthropic' )[0] && 'gpt-5' === OGM_GPDM_AI::seed_models( 'openai' )[0] );

check( 'ai: prose-only response rejected', null === OGM_GPDM_AI::extract_css( 'I could not find any problems worth fixing.' ) );
check( 'ai: empty response rejected', null === OGM_GPDM_AI::extract_css( '' ) );

// Truncation shape: an UNCLOSED fence must never fall through to the
// unfenced-CSS branch (that would store prose + half a stylesheet).
$r = OGM_GPDM_AI::extract_css( "- notes about the fix\n\n```css\nhtml[data-theme=\"dark\"] .a { color: #fff; }\nhtml[data-theme=\"dark\"] .b { background" );
check( 'ai: unclosed fence (truncated reply) rejected', null === $r );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
