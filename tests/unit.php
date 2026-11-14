<?php
/**
 * GP Dark Mode — bundle assembler unit tests (pure functions, no WordPress).
 * Run: php tests/unit.php
 */
define( 'ABSPATH', '/tmp/' );
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-bundle.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-palette.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-ai.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-css.php';

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
check( 'gp-chrome module: nav rule kept', false !== strpos( $chromeOut, 'html[data-theme="dark"] .main-navigation' ) );
// 1.11.0: the simplified menu — no rules above/below the bar, no default
// accent fill; the accent look is the opt-in "Dark nav accent" setting.
check( 'gp-chrome module: menu has no borders and no default accent fill', false === strpos( $chrome, 'border-top' ) && false === strpos( $chrome, 'border-bottom' ) && false === strpos( $chrome, 'var(--accent)' ) );
check( 'gp-chrome module: accent menu still one setting away', (bool) preg_match( '~html\.gpdm-accent-nav\[data-theme="dark"\] \.main-navigation,.*?background-color: var\(--dm-accent\)~s', $chrome ) );
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
// The Forms block (1.12.2: the base rule + the field-border rule; 1.13.1: the
// field-border rule also reaches fields outside .site-content): every
// selector in it is dark-scoped, and it has both rules.
$fw_forms = preg_match( '~Forms \(dark only\)\s*=+\s*\*/(.*?)/\*\s*=+\s*Toggle component~s', $fw, $m ) ? preg_replace( '~/\*.*?\*/~s', '', $m[1] ) : '';
preg_match_all( '~([^{}]+)\{~', $fw_forms, $m );
$fw_form_sels = array();
foreach ( $m[1] as $list ) {
	foreach ( preg_split( '~,(?![^(]*\))~', $list ) as $sel ) {
		$fw_form_sels[] = trim( $sel );
	}
}
check( 'framework: surfaces + forms dark-scoped', false !== strpos( $fw, 'html[data-theme="dark"] .site-content input' ) && ! preg_match( '~^\.site-content~m', $fw ) && 9 === count( $fw_form_sels ) && count( $fw_form_sels ) === count( preg_grep( '~^html\[data-theme="dark"\] (?:\.site-content )?(?:input|textarea|select)\b~', $fw_form_sels ) ), implode( ' | ', $fw_form_sels ) );

// ---- No self-referencing custom properties (1.12.1) ----
// `--x: var(--x, fallback)` is a cycle: the property becomes invalid and the
// fallback never applies. The framework carried the original site's
// `--accent: var(--accent, #1d428a)` until 1.12.1. Wherever it printed AFTER
// the theme's own --accent (non-GP themes, where the framework is a late
// standalone sheet), it wiped the theme's accent out in both modes.
function gpdm_self_refs( $css ) {
	$css = preg_replace( '~/\*.*?\*/~s', '', (string) $css );
	preg_match_all( '~(?<![\w-])(--[\w-]+)\s*:([^;{}]*)~', $css, $decls, PREG_SET_ORDER );
	$found = array();
	foreach ( $decls as $d ) {
		if ( preg_match( '~var\(\s*' . preg_quote( $d[1], '~' ) . '\s*[,)]~', $d[2] ) ) {
			$found[] = $d[1];
		}
	}
	return array_values( array_unique( $found ) );
}
check( 'self-ref: catches the pre-1.12.1 framework line', array( '--accent' ) === gpdm_self_refs( ":root {\n\t--dm-accent: #1d428a;\n\t--accent: var(--accent, #1d428a);\n}" ) );
check( 'self-ref: catches it inside a nested fallback and minified', array( '--link' ) === gpdm_self_refs( 'html[data-theme="dark"]{--bg:#000;--link:var(--dm-link,var(--link))}' ) );
check( 'self-ref: other variables, longer names and comments are fine', array() === gpdm_self_refs( ":root { --accent: var(--accent-2); --a: var(--b, #fff); /* --c: var(--c); */ }\n.x { color: var(--accent); }" ) );
$self_ref_files = array_merge( array( dirname( __DIR__ ) . '/assets/css/gp-dark-mode.css' ), glob( dirname( __DIR__ ) . '/assets/css/modules/*.css' ) );
check( 'self-ref: scanning the framework + every module (not a vacuous pass)', count( $self_ref_files ) > 5 );
foreach ( $self_ref_files as $css_file ) {
	$refs = gpdm_self_refs( file_get_contents( $css_file ) );
	check( 'self-ref: none in ' . basename( $css_file ), array() === $refs, implode( ', ', $refs ) );
}
check( 'framework: never defines --accent (the theme owns it)', ! preg_match( '~(?<![\w-])--accent\s*:~', preg_replace( '~/\*.*?\*/~s', '', $fw ) ) );

// ---- Form-field borders (1.12.2, WCAG 2.2 SC 1.4.11 Non-text Contrast) ----
// A text field's border is what identifies the control, so it needs 3:1
// against the field AND the page. Found in a WCAG 2.2 AA audit: the forms
// rule painted fields with --border (#333340, a divider color), 1.38:1 on a
// #1b1b20 field and 1.16:1 on the framework's own --surface-2.

// A token's value in the framework's :root or dark variables block.
function gpdm_fw_token( $fw, $scope, $name ) {
	$code = preg_replace( '~/\*.*?\*/~s', '', $fw );
	$re   = 'root' === $scope ? '~^:root\s*\{([^}]*)\}~m' : '~^html\[data-theme="dark"\]\s*\{([^}]*)\}~m';
	if ( ! preg_match( $re, $code, $b ) || ! preg_match( '~(?<![\w-])' . preg_quote( $name, '~' ) . '\s*:\s*([^;]+);~', $b[1], $v ) ) {
		return null;
	}
	return OGM_GPDM_Palette::normalize_hex( trim( $v[1] ) );
}

// Selector specificity [a, b, c] — enough for the shipped form rules:
// :where() counts 0; :not()/:is() count their argument (one compound here).
function gpdm_specificity( $sel ) {
	$spec = array( 0, 0, 0 );
	while ( preg_match( '~:(where|not|is)\(~', $sel, $mm, PREG_OFFSET_CAPTURE ) ) {
		$start = $mm[0][1];
		$open  = $start + strlen( $mm[0][0] ) - 1;
		for ( $i = $open, $depth = 0; $i < strlen( $sel ); $i++ ) {
			if ( '(' === $sel[ $i ] ) {
				$depth++;
			} elseif ( ')' === $sel[ $i ] && 0 === --$depth ) {
				break;
			}
		}
		if ( 'where' !== $mm[1][0] ) {
			$inner = gpdm_specificity( substr( $sel, $open + 1, $i - $open - 1 ) );
			$spec  = array( $spec[0] + $inner[0], $spec[1] + $inner[1], $spec[2] + $inner[2] );
		}
		$sel = substr( $sel, 0, $start ) . substr( $sel, $i + 1 );
	}
	$spec[1] += preg_match_all( '~\[[^\]]*\]~', $sel );
	$sel      = preg_replace( '~\[[^\]]*\]~', '', $sel );
	$spec[0] += preg_match_all( '~#[\w-]+~', $sel );
	$spec[1] += preg_match_all( '~\.[\w-]+~', $sel ) + preg_match_all( '~(?<!:):[\w-]+~', $sel );
	$spec[2] += preg_match_all( '~::[\w-]+~', $sel ) + preg_match_all( '~(?:^|[\s>+\~])[a-z][\w-]*~i', $sel );
	return $spec;
}
check( 'specificity helper: known values', array( 0, 2, 2 ) === gpdm_specificity( 'html[data-theme="dark"] .site-content input' ) && array( 0, 6, 2 ) === gpdm_specificity( 'html[data-theme="dark"] .site-content input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"])' ) && array( 0, 2, 2 ) === gpdm_specificity( 'html[data-theme="dark"] .site-content input:where(:not([type="submit"]):not(.x))' ) && array( 1, 1, 1 ) === gpdm_specificity( '#a .b c' ) );

// Rules in a CSS string whose selector list includes $needle: [selectors[], body].
function gpdm_rules_with( $css, $needle ) {
	$css = preg_replace( '~/\*.*?\*/~s', '', $css );
	preg_match_all( '~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $r ) {
		$sels = array_map( 'trim', preg_split( '~,(?![^(]*\))~', $r[1] ) );
		if ( in_array( $needle, $sels, true ) ) {
			$out[] = array( $sels, trim( $r[2] ) );
		}
	}
	return $out;
}

$fw_field = gpdm_rules_with( $fw, 'html[data-theme="dark"] .site-content textarea' );
check( 'field border: framework has the base forms rule and a field-border rule after it', 2 === count( $fw_field ) && false !== strpos( $fw_field[0][1], 'background-color: var(--surface-2)' ) && 'border-color: var(--field-border);' === $fw_field[1][1], isset( $fw_field[1] ) ? $fw_field[1][1] : '' );
$fw_field_sels = isset( $fw_field[1] ) ? $fw_field[1][0] : array();
$fw_field_in   = (string) current( preg_grep( '~ \.site-content input~', $fw_field_sels ) );
$fw_field_site = (string) current( preg_grep( '~^html\[data-theme="dark"\] input~', $fw_field_sels ) );
check( 'field border: covers text inputs, textareas and selects', 6 === count( $fw_field_sels ) && '' !== $fw_field_in && in_array( 'html[data-theme="dark"] .site-content select', $fw_field_sels, true ) && in_array( 'html[data-theme="dark"] .site-content textarea', $fw_field_sels, true ), implode( ' | ', $fw_field_sels ) );
check( 'field border (1.13.1): also outside the content area (header, footer, widget areas, hooks)', '' !== $fw_field_site && in_array( 'html[data-theme="dark"] textarea', $fw_field_sels, true ) && in_array( 'html[data-theme="dark"] select', $fw_field_sels, true ) );
$excluded = array( 'submit', 'button', 'reset', 'image', 'checkbox', 'radio' );
$all_out  = true;
foreach ( $excluded as $type ) {
	$all_out = $all_out && false !== strpos( $fw_field_in, ':not([type="' . $type . '"])' ) && false !== strpos( $fw_field_site, ':not([type="' . $type . '"])' );
}
check( 'field border: buttons (submit/button/reset/image), checkboxes and radios excluded', $all_out, $fw_field_in . ' / ' . $fw_field_site );
check( 'field border: never the divider color on a field', false === strpos( $fw_field[1][1], 'var(--border)' ) );
// Equal specificity: the field rule wins over the base rule by order, and a
// Manual CSS rule with the base selector can still override it.
check( 'field border: :where() holds the field rule at the base rule\'s specificity (0,2,2)', array( 0, 2, 2 ) === gpdm_specificity( $fw_field_in ) && array( 0, 2, 2 ) === gpdm_specificity( 'html[data-theme="dark"] .site-content input' ), implode( ',', gpdm_specificity( $fw_field_in ) ) );
// Outside the content area the rule competes with GeneratePress's own field
// rules (inc/css-output.php): it must beat the resting one, whose border is
// var(--base). GP's input[type=...]:focus (0,2,1) still wins, so text inputs
// keep GP's focus color; GP's textarea:focus and select:focus are only
// (0,1,1), so those keep --field-border on focus (documented in the CSS).
$spec_n = function ( $sel ) {
	$s = gpdm_specificity( $sel );
	return $s[0] * 10000 + $s[1] * 100 + $s[2];
};
check( 'field border (1.13.1): site-wide selectors beat GP\'s field rule (0,1,1), lose to its input :focus rule (0,2,1)', $spec_n( $fw_field_site ) > $spec_n( 'input[type="text"]' ) && $spec_n( 'html[data-theme="dark"] textarea' ) > $spec_n( 'textarea' ) && $spec_n( 'html[data-theme="dark"] select' ) > $spec_n( 'select' ) && $spec_n( $fw_field_site ) < $spec_n( 'input[type="text"]:focus' ) && array( 0, 1, 2 ) === gpdm_specificity( $fw_field_site ), implode( ',', gpdm_specificity( $fw_field_site ) ) );
// ...and never outranks the content-area base rule, which sets the field
// background there.
check( 'field border (1.13.1): site-wide selectors stay below the content-area rules (0,2,2)', $spec_n( $fw_field_site ) < $spec_n( 'html[data-theme="dark"] .site-content input' ) );
// Focus styles stay the theme's: the forms block sets no outline, no
// box-shadow and no :focus rule — only colors.
check( 'field border: focus styles intact (no outline, box-shadow or :focus in the forms block)', '' !== $fw_forms && ! preg_match( '~outline|box-shadow|:focus~', $fw_forms ) );

$fb_dark  = gpdm_fw_token( $fw, 'dark', '--field-border' );
$fb_light = gpdm_fw_token( $fw, 'root', '--field-border' );
check( 'field border: token defined light (:root) and dark', null !== $fb_dark && null !== $fb_light );
check( 'field border: palette constants match the framework (FIELD_BORDER, DARK_SURFACE_2, DARK_REF)', OGM_GPDM_Palette::FIELD_BORDER === $fb_dark && OGM_GPDM_Palette::DARK_SURFACE_2 === gpdm_fw_token( $fw, 'dark', '--surface-2' ) && OGM_GPDM_Palette::DARK_REF === gpdm_fw_token( $fw, 'dark', '--bg' ) );
foreach ( array( 'dark' => $fb_dark, 'root' => $fb_light ) as $scope => $fb ) {
	$ratios = array();
	foreach ( array( '--bg', '--bg-2', '--surface', '--surface-2' ) as $surface ) {
		$ratios[ $surface ] = round( OGM_GPDM_Palette::contrast_ratio( (string) $fb, (string) gpdm_fw_token( $fw, $scope, $surface ) ), 2 );
	}
	check( "field border: $scope --field-border >= 3:1 against --bg, --bg-2, --surface, --surface-2", null !== $fb && min( $ratios ) >= 3, json_encode( $ratios ) );
}
check( 'field border: the audited failure reproduces with --border (#333340 on #1b1b20 < 3:1)', OGM_GPDM_Palette::contrast_ratio( (string) gpdm_fw_token( $fw, 'dark', '--border' ), '#1b1b20' ) < 3 && OGM_GPDM_Palette::contrast_ratio( (string) $fb_dark, '#1b1b20' ) >= 3 );

// Upgrades: palettes generated before 1.12.2 are stored, not recomputed. The
// default must clear 3:1 against the brightest surfaces that generator could
// emit — the accent-tinted --bg-2 (s <= .55, L .20) and ramp ranks 1-4
// (s <= .30) — at every hue.
$worst_old = 99;
for ( $deg = 0; $deg < 360; $deg++ ) {
	$worst_old = min( $worst_old, OGM_GPDM_Palette::contrast_ratio( (string) $fb_dark, OGM_GPDM_Palette::hsl_to_hex( $deg / 360, 0.55, 0.20 ) ) );
	for ( $rank = 0; $rank < 4; $rank++ ) {
		$worst_old = min( $worst_old, OGM_GPDM_Palette::contrast_ratio( (string) $fb_dark, OGM_GPDM_Palette::hsl_to_hex( $deg / 360, 0.30, 0.07 + 0.045 * $rank ) ) );
	}
}
check( 'field border: default >= 3:1 on every surface a pre-1.12.2 generated palette can hold', $worst_old >= 3, round( $worst_old, 2 ) . ':1' );

// The minifier keeps the :where()/:not() selector intact.
check( 'field border: selector survives the print-time minifier', false !== strpos( OGM_GPDM_CSS::minify( $fw ), 'html[data-theme="dark"] .site-content input:where(:not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="checkbox"]):not([type="radio"])),' ) && false !== strpos( OGM_GPDM_CSS::minify( $fw ), 'html[data-theme="dark"] input:where(:not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]):not([type="checkbox"]):not([type="radio"])),html[data-theme="dark"] textarea,' ) );

// Contact Form 7's module paints its own fields (more specific than the
// framework's rule), so it carries the same fix.
$cf7       = file_get_contents( dirname( __DIR__ ) . '/assets/css/modules/contact-form-7.css' );
$cf7_field = gpdm_rules_with( $cf7, 'html[data-theme="dark"] .wpcf7 textarea' );
$cf7_in    = isset( $cf7_field[1] ) ? (string) current( preg_grep( '~ input~', $cf7_field[1][0] ) ) : '';
$cf7_base  = isset( $cf7_field[0] ) ? (string) current( preg_grep( '~ input~', $cf7_field[0][0] ) ) : '';
$cf7_out   = true;
foreach ( $excluded as $type ) {
	$cf7_out = $cf7_out && false !== strpos( $cf7_in, ':not([type="' . $type . '"])' );
}
check( 'cf7 module: field-border rule after the base rule, buttons/checkboxes/radios excluded', 2 === count( $cf7_field ) && 'border-color: var(--field-border);' === $cf7_field[1][1] && $cf7_out );
check( 'cf7 module: field rule at the base rule\'s specificity (wins by order)', '' !== $cf7_in && gpdm_specificity( $cf7_in ) === gpdm_specificity( $cf7_base ), implode( ',', gpdm_specificity( $cf7_in ) ) . ' vs ' . implode( ',', gpdm_specificity( $cf7_base ) ) );

// No shipped stylesheet gives a text field the divider color without a
// --field-border rule after it for the same selector.
$field_gaps = array();
foreach ( $self_ref_files as $css_file ) {
	$code = preg_replace( '~/\*.*?\*/~s', '', file_get_contents( $css_file ) );
	preg_match_all( '~([^{}]+)\{([^{}]*)\}~', $code, $rules, PREG_SET_ORDER );
	foreach ( $rules as $i => $r ) {
		if ( false === strpos( $r[2], 'border-color: var(--border)' ) ) {
			continue;
		}
		foreach ( array_map( 'trim', preg_split( '~,(?![^(]*\))~', $r[1] ) ) as $sel ) {
			if ( ! preg_match( '~\b(?:textarea|select)$~', $sel ) ) {
				continue;
			}
			$fixed = false;
			foreach ( array_slice( $rules, $i + 1 ) as $later ) {
				$fixed = $fixed || ( false !== strpos( $later[2], 'border-color: var(--field-border)' ) && in_array( $sel, array_map( 'trim', preg_split( '~,(?![^(]*\))~', $later[1] ) ), true ) );
			}
			if ( ! $fixed ) {
				$field_gaps[] = basename( $css_file ) . ': ' . $sel;
			}
		}
	}
}
check( 'field border: every shipped field rule with --border is followed by a --field-border rule', array() === $field_gaps, implode( '; ', $field_gaps ) );

check( 'field border: the AI bundle contract and the Chrome prompt name --field-border', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-ogm-gpdm-bundle.php' ), '`--field-border`' ) && false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-ogm-gpdm-audit.php' ), '--field-border' ) );

// ---- OGM_GPDM_CSS::minify (print-time; comments + whitespace only) ----
$m = OGM_GPDM_CSS::minify( "/* gone */\nhtml[data-theme=\"dark\"] .code-block[style*=\"position: fixed\"] .rc-headline ,\n .x > .y {\n\tcolor: #fff !important;\n\tbackground: rgba(1, 2, 3, .5);\n}\n" );
check( 'minify: comments removed', false === strpos( $m, '/*' ) );
check( 'minify: quoted attribute string preserved verbatim', false !== strpos( $m, '[style*="position: fixed"]' ) );
check( 'minify: structural whitespace removed', false !== strpos( $m, '.rc-headline,.x>.y{color:#fff !important;background:rgba(1,2,3,.5)}' ) );
check( 'minify: descendant-vs-pseudo space kept', 'a :hover{color:red}' === OGM_GPDM_CSS::minify( "a :hover { color: red; }" ) );
check( 'minify: pseudo-class untouched', 'a:hover{color:red}' === OGM_GPDM_CSS::minify( "a:hover { color: red; }" ) );
check( 'minify: calc operators keep their spaces', '.a{width:calc(100% - 10px)}' === OGM_GPDM_CSS::minify( ".a { width: calc(100% - 10px); }" ) );
check( 'minify: media query', '@media (max-width:768px){.a{display:none}}' === OGM_GPDM_CSS::minify( "@media (max-width: 768px) {\n  .a { display: none; }\n}" ) );
check( 'minify: content string with spaces and braces survives', ".a::before{content:\"a ; } b\"}" === OGM_GPDM_CSS::minify( ".a::before { content: \"a ; } b\"; }" ) );
check( 'minify: unterminated comment left alone (no crash)', is_string( OGM_GPDM_CSS::minify( '.a{color:red} /* open' ) ) );
check( 'minify: empty in, empty out', '' === OGM_GPDM_CSS::minify( "  \n " ) );
$fw_min = OGM_GPDM_CSS::minify( $fw );
check( 'minify: framework shrinks by >40%', strlen( $fw_min ) < strlen( $fw ) * 0.6 );
echo 'minify: framework ' . strlen( $fw ) . ' bytes -> ' . strlen( $fw_min ) . " bytes\n";

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

// 1.13.1: outside .site-content, GeneratePress paints a field's border
// var(--base) on a var(--base-2) fill, over var(--base-3) areas. The generated
// palette turns all three into dark surfaces, which is the gap the site-wide
// field-border selectors close.
$gp_field = array( $p['map']['base']['dark'], $p['map']['base-2']['dark'], $p['map']['base-3']['dark'] );
check( 'field border (1.13.1): GP\'s own field border under the generated palette reproduces the gap (< 3:1)', OGM_GPDM_Palette::contrast_ratio( $gp_field[0], $gp_field[1] ) < 3 && OGM_GPDM_Palette::contrast_ratio( $gp_field[0], $gp_field[2] ) < 3, round( OGM_GPDM_Palette::contrast_ratio( $gp_field[0], $gp_field[1] ), 2 ) . ':1' );
$gp_fixed = array();
foreach ( $gp_field as $surface ) {
	$gp_fixed[] = round( OGM_GPDM_Palette::contrast_ratio( OGM_GPDM_Palette::FIELD_BORDER, $surface ), 2 );
}
check( 'field border (1.13.1): --field-border clears 3:1 on GP\'s generated field, page and area colors', min( $gp_fixed ) >= 3, implode( ' / ', $gp_fixed ) );

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

// ---- the plugin's own tokens, derived from the site palette (1.8.0) ----
// Live AFS case: the toggle, the current menu item and dark links carried the
// framework's built-in navy (#1d428a / #17284f / #9ad) on a teal site.

check( 'surfaces: rank order recorded', array( 'base-3', 'base-2', 'base' ) === $p['surfaces'] );

$afs = array(
	array( 'slug' => 'afs-ink', 'color' => '#07313D' ),
	array( 'slug' => 'afs-accent', 'color' => '#0E6E8E' ),
	array( 'slug' => 'afs-accent-deep', 'color' => '#0A4254' ),
	array( 'slug' => 'afs-surface', 'color' => '#FFFFFF' ),
	array( 'slug' => 'afs-surface-alt', 'color' => '#EFF5F4' ),
	array( 'slug' => 'afs-ink-soft', 'color' => '#3A4F51' ),
);
$acc = OGM_GPDM_Palette::find_accent( $afs, array( 'link_color' => 'var(--afs-accent)' ) );
check( 'accent: link color var() resolves to its Global Color', null !== $acc && '#0e6e8e' === $acc['hex'] && false !== strpos( $acc['from'], '--afs-accent' ) );
check( 'accent: literal link color accepted', '#0e6e8e' === OGM_GPDM_Palette::find_accent( array(), array( 'link_color' => '#0E6E8E' ) )['hex'] );
check( 'accent: neutral link color skipped for a named accent slug', '#1e73be' === OGM_GPDM_Palette::find_accent( $gp_defaults, array( 'link_color' => 'var(--contrast)' ) )['hex'] );
check( 'accent: none found -> null', null === OGM_GPDM_Palette::find_accent( array( array( 'slug' => 'contrast', 'color' => '#222222' ) ), array( 'link_color' => '#000000' ) ) );
check( 'accent: unknown var() slug skipped', '#0e6e8e' === OGM_GPDM_Palette::find_accent( $afs, array( 'link_color' => 'var(--nope)' ) )['hex'] );

$afs_p = OGM_GPDM_Palette::derive_palette( $afs );
$t     = OGM_GPDM_Palette::derive_tokens( $afs_p, $acc );
$rank2 = $afs_p['map'][ $afs_p['surfaces'][1] ]['dark'];
check( 'tokens: --dm-accent is the site accent', '#0e6e8e' === $t['root']['--dm-accent'][0] );
check( 'tokens: dark --bg-2/--surface-2 = rank-2 surface', $rank2 === $t['dark']['--bg-2'][0] && $rank2 === $t['dark']['--surface-2'][0] && 'afs-surface-alt' === $afs_p['surfaces'][1] );
check( 'tokens: framework dark --text readable on --bg-2', OGM_GPDM_Palette::contrast_ratio( '#f0f0f0', $t['dark']['--bg-2'][0] ) >= 4.5 );
$link = $t['dark']['--link'][0];
check( 'tokens: dark --link >= 4.5:1 on every dark surface', OGM_GPDM_Palette::contrast_ratio( $link, OGM_GPDM_Palette::DARK_REF ) >= 4.5 && OGM_GPDM_Palette::contrast_ratio( $link, $rank2 ) >= 4.5 && OGM_GPDM_Palette::contrast_ratio( $link, $afs_p['map']['afs-surface']['dark'] ) >= 4.5 );
check( 'tokens: dark --link keeps the accent hue', abs( OGM_GPDM_Palette::hex_to_hsl( $link )[0] - OGM_GPDM_Palette::hex_to_hsl( '#0e6e8e' )[0] ) < 0.02 );
$glow = $t['root']['--dm-glow'][0];
check( 'tokens: light glow keeps white icons >= 3:1', OGM_GPDM_Palette::contrast_ratio( '#ffffff', $glow ) >= 3 );
check( 'tokens: light glow differs from the track', '#0e6e8e' !== $glow && OGM_GPDM_Palette::hex_to_hsl( $glow )[2] > OGM_GPDM_Palette::hex_to_hsl( '#0e6e8e' )[2] );
check( 'tokens: dark glow is deep', OGM_GPDM_Palette::hex_to_hsl( $t['dark']['--dm-glow'][0] )[2] < 0.3 );

// Derived from the built-in accent itself, the rules land next to the
// framework's hand-tuned values — evidence they encode the same design.
$near = OGM_GPDM_Palette::derive_tokens( array( 'map' => array(), 'surfaces' => array() ), array( 'hex' => '#1d428b', 'from' => 'test' ) );
$dist = function ( $a, $b ) { $x = OGM_GPDM_Palette::hex_to_rgb( $a ); $y = OGM_GPDM_Palette::hex_to_rgb( $b ); return max( abs( $x[0] - $y[0] ), abs( $x[1] - $y[1] ), abs( $x[2] - $y[2] ) ); };
check( 'tokens: rules ~reproduce the tuned set (glow #0056ff, link #99aadd)', $dist( $near['root']['--dm-glow'][0], '#0056ff' ) <= 4 && $dist( $near['dark']['--link'][0], '#99aadd' ) <= 12 );

// The reference site's palette (accent = the framework's built-in accent):
// nothing emitted, so a regenerate there leaves its toggle, links and
// alternate surface exactly as they are today.
$ref_colors = array(
	array( 'slug' => 'contrast', 'color' => '#222222' ),
	array( 'slug' => 'contrast-2', 'color' => '#575760' ),
	array( 'slug' => 'contrast-3', 'color' => '#b2b2be' ),
	array( 'slug' => 'base', 'color' => '#f0f0f0' ),
	array( 'slug' => 'base-2', 'color' => '#f7f8f9' ),
	array( 'slug' => 'base-3', 'color' => '#ffffff' ),
	array( 'slug' => 'accent', 'color' => '#1d428a' ),
	array( 'slug' => 'global-color-8', 'color' => '#e3a700' ),
	array( 'slug' => 'global-color-9', 'color' => '#0346cc' ),
	array( 'slug' => 'global-color-10', 'color' => '#ffbb00' ),
);
foreach ( array( 'var(--accent)', '#1d428a', '' ) as $ref_link ) {
	$ref_t = OGM_GPDM_Palette::derive_tokens(
		OGM_GPDM_Palette::derive_palette( $ref_colors ),
		OGM_GPDM_Palette::find_accent( $ref_colors, array( 'link_color' => $ref_link ) )
	);
	check( "tokens: built-in accent keeps the framework set (link_color '$ref_link')", array() === $ref_t['root'] && array() === $ref_t['dark'] && false !== strpos( implode( '|', $ref_t['notes'] ), 'built-in accent' ) );
}

// One ranked surface + an accent: --bg-2 falls back to a dark accent tint.
$one = OGM_GPDM_Palette::derive_tokens( OGM_GPDM_Palette::derive_palette( array( array( 'slug' => 'base', 'color' => '#ffffff' ) ) ), array( 'hex' => '#0e6e8e', 'from' => 'test' ) );
check( 'tokens: no rank-2 surface -> accent-tinted --bg-2', isset( $one['dark']['--bg-2'] ) && abs( OGM_GPDM_Palette::hex_to_hsl( $one['dark']['--bg-2'][0] )[0] - OGM_GPDM_Palette::hex_to_hsl( '#0e6e8e' )[0] ) < 0.02 && OGM_GPDM_Palette::contrast_ratio( '#f0f0f0', $one['dark']['--bg-2'][0] ) >= 4.5 );

// No accent: the toggle and link keep the framework's colors; a rank-2
// surface still feeds --bg-2.
$none = OGM_GPDM_Palette::derive_tokens( $afs_p, null );
check( 'tokens: no accent -> no toggle/link tokens', array() === $none['root'] && ! isset( $none['dark']['--link'] ) && ! isset( $none['dark']['--dm-glow'] ) && false !== strpos( implode( '|', $none['notes'] ), 'no accent found' ) );
check( 'tokens: no accent -> --bg-2 still from rank 2', $rank2 === $none['dark']['--bg-2'][0] );

// ---- --field-border from the generator (1.12.2) ----
// Emitted only when the framework's value is under 3:1 against a palette's
// dark surfaces or the field background; otherwise a note with the lowest ratio.
check( 'field_border: framework surfaces -> the framework value, unchanged', array( OGM_GPDM_Palette::FIELD_BORDER ) === array_slice( OGM_GPDM_Palette::field_border( array( OGM_GPDM_Palette::DARK_REF, OGM_GPDM_Palette::DARK_SURFACE_2 ) ), 0, 1 ) );
$gp_t = OGM_GPDM_Palette::derive_tokens( $p, OGM_GPDM_Palette::find_accent( $gp_defaults, array() ) );
check( 'field_border: GP default palette keeps the framework value (note, no token)', ! isset( $gp_t['dark']['--field-border'] ) && (bool) preg_grep( '~^Form-field borders: .*' . OGM_GPDM_Palette::FIELD_BORDER . '.* reaches [0-9.]+:1~', $gp_t['notes'] ) );
check( 'field_border: AFS, one-surface and no-accent palettes keep it too', ! isset( $t['dark']['--field-border'] ) && ! isset( $one['dark']['--field-border'] ) && ! isset( $none['dark']['--field-border'] ) && (bool) preg_grep( '~^Form-field borders~', $none['notes'] ) );
// Built-in accent: still nothing emitted (that test above), but checked.
check( 'field_border: built-in accent palette checked as well (note)', (bool) preg_grep( '~^Form-field borders~', $ref_t['notes'] ) );
// Stress case: six light surfaces with a yellow cast ramp up to L 0.295.
$six = array();
foreach ( array( '#fffff0', '#fbfbe8', '#f7f7e0', '#f3f3d8', '#efefd0', '#ebebc8' ) as $i => $hex ) {
	$six[] = array( 'slug' => 'base-' . ( $i + 1 ), 'color' => $hex );
}
$six[]  = array( 'slug' => 'accent', 'color' => '#1e73be' );
$six_p  = OGM_GPDM_Palette::derive_palette( $six );
$six_t  = OGM_GPDM_Palette::derive_tokens( $six_p, OGM_GPDM_Palette::find_accent( $six, array() ) );
$six_fb = isset( $six_t['dark']['--field-border'] ) ? $six_t['dark']['--field-border'][0] : null;
$six_lo = 99;
foreach ( array_merge( array( OGM_GPDM_Palette::DARK_REF, $six_t['dark']['--surface-2'][0] ), array_map( function ( $slug ) use ( $six_p ) { return $six_p['map'][ $slug ]['dark']; }, $six_p['surfaces'] ) ) as $ref ) {
	$six_lo = min( $six_lo, OGM_GPDM_Palette::contrast_ratio( (string) $six_fb, $ref ) );
}
check( 'field_border: bright ramp -> --field-border emitted, >= 3:1 on every dark surface and the field', 6 === count( $six_p['surfaces'] ) && null !== $six_fb && $six_lo >= 3, $six_fb . ' lowest ' . round( $six_lo, 2 ) );
check( 'field_border: lightened in the framework value\'s own hue', null !== $six_fb && abs( OGM_GPDM_Palette::hex_to_hsl( $six_fb )[0] - OGM_GPDM_Palette::hex_to_hsl( OGM_GPDM_Palette::FIELD_BORDER )[0] ) < 0.02 && OGM_GPDM_Palette::hex_to_hsl( $six_fb )[2] > OGM_GPDM_Palette::hex_to_hsl( OGM_GPDM_Palette::FIELD_BORDER )[2] );
check( 'field_border: emitted note says lowest ratio without overclaiming', null !== $six_fb && preg_match( '~lowest ([0-9.]+):1~', $six_t['dark']['--field-border'][1], $lo_m ) && (float) $lo_m[1] <= $six_lo && (float) $lo_m[1] >= 3 );

// The framework's hover glows are now one variable; its built-in values
// must stay exactly the old literals (upgrades render pixel-identically).
check( 'framework: hover glows use --dm-glow', 2 === substr_count( $fw, 'inset 0 0 0 20px var(--dm-glow)' ) && false === strpos( $fw, '20px #0056ff' ) && false === strpos( $fw, '20px #122666' ) );
check( 'framework: --dm-glow defaults are the old literals', (bool) preg_match( '~:root\s*\{[^}]*--dm-glow:\s*#0056ff;~', $fw ) && (bool) preg_match( '~html\[data-theme="dark"\]\s*\{[^}]*--dm-glow:\s*#122666;~', $fw ) );

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

// Families the code has never seen must rank by version, not fall to a floor.
$ranked = OGM_GPDM_AI::rank_openai_models( array( 'gpt-3.5-turbo', 'o3', 'gpt-4o', 'gpt-6-luna', 'gpt-5.1', 'gpt-6-astra', 'gpt-6.1-sol', 'gpt-5-mini', 'o4-mini', 'gpt-4.5-preview' ) );
check( 'rank openai: unseen newer major ranks first', 'gpt-6.1-sol' === $ranked[0] && 'gpt-6-astra' === $ranked[1] );
check( 'rank openai: gpt-6 family > gpt-5.1 > o-series', array_search( 'gpt-6-luna', $ranked, true ) < array_search( 'gpt-5.1', $ranked, true ) && array_search( 'gpt-5.1', $ranked, true ) < array_search( 'o3', $ranked, true ) );
check( 'rank openai: mini variants below their flagship', array_search( 'gpt-5.1', $ranked, true ) < array_search( 'gpt-5-mini', $ranked, true ) );

// ---- effort resolution (settings value -> per-provider API value) ----
check( 'effort: default is medium on both providers', 'medium' === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-opus-5', '' ) && 'medium' === OGM_GPDM_AI::effort_for( 'openai', 'gpt-5.1', '' ) );
check( 'effort: max passes through on Claude 5', 'max' === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-fable-5', 'max' ) );
check( 'effort: max capped to high on gpt-5.x and o-series', 'high' === OGM_GPDM_AI::effort_for( 'openai', 'gpt-5.1', 'max' ) && 'high' === OGM_GPDM_AI::effort_for( 'openai', 'o3', 'max' ) );
check( 'effort: max passes through on gpt-6+', 'max' === OGM_GPDM_AI::effort_for( 'openai', 'gpt-6-astra', 'max' ) );
check( 'effort: omitted for models that take none', null === OGM_GPDM_AI::effort_for( 'openai', 'gpt-4o', 'high' ) && null === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-haiku-4-5', 'high' ) );
check( 'effort: Claude Haiku 5.5 and Opus 5.5 take it (1.7.1)', 'high' === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-haiku-5-5', 'high' ) && 'max' === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-opus-5-5', 'max' ) );
check( 'effort: bogus setting falls back to medium', 'medium' === OGM_GPDM_AI::effort_for( 'anthropic', 'claude-opus-5', 'turbo' ) );
check( 'effort: timeouts scale and stay inside the run window', 240 === OGM_GPDM_AI::effort_timeout( 'low' ) && 420 === OGM_GPDM_AI::effort_timeout( 'medium' ) && 660 === OGM_GPDM_AI::effort_timeout( 'high' ) && 840 === OGM_GPDM_AI::effort_timeout( 'max' ) && 240 === OGM_GPDM_AI::effort_timeout( null ) && OGM_GPDM_AI::effort_timeout( 'max' ) < OGM_GPDM_AI::RUN_TIMEOUT );

// v1.7.1: the reply allowance scales with effort — thinking counts against it
// (Opus 5.5 at High ran out of a fixed 16,000 before writing any CSS).
check( 'max_tokens: allowance scales with effort', 16000 === OGM_GPDM_AI::max_tokens( 'low' ) && 32000 === OGM_GPDM_AI::max_tokens( 'medium' ) && 48000 === OGM_GPDM_AI::max_tokens( 'high' ) && 64000 === OGM_GPDM_AI::max_tokens( 'max' ) && 64000 === OGM_GPDM_AI::max_tokens( 'xhigh' ) );
check( 'max_tokens: models without effort keep 16,000 (gpt-4o ceiling 16,384)', 16000 === OGM_GPDM_AI::max_tokens( null ) && OGM_GPDM_AI::max_tokens( null ) <= 16384 );
$fits = true;
foreach ( array( null, 'low', 'medium', 'high', 'xhigh', 'max' ) as $e ) {
	// The whole allowance at ~100 output tokens/s (measured) must fit the HTTP timeout.
	$fits = $fits && ( OGM_GPDM_AI::max_tokens( $e ) / 100 ) < OGM_GPDM_AI::effort_timeout( $e );
}
check( 'max_tokens: every allowance can be spent inside its timeout', $fits );

$prov = OGM_GPDM_AI::providers();
// ---- 1.11.0: GeneratePress's own light surfaces, darkened per site ----
$gp_colors = array(
	array( 'slug' => 'contrast', 'color' => '#222222' ), array( 'slug' => 'contrast-2', 'color' => '#575760' ),
	array( 'slug' => 'contrast-3', 'color' => '#b2b2be' ), array( 'slug' => 'base', 'color' => '#f0f0f0' ),
	array( 'slug' => 'base-2', 'color' => '#f7f8f9' ), array( 'slug' => 'base-3', 'color' => '#ffffff' ),
	array( 'slug' => 'accent', 'color' => '#1e73be' ),
);
$gp_defaults = array(
	'top_bar_background_color' => '#636363', 'header_background_color' => 'var(--base-3)', 'navigation_background_color' => 'var(--base-3)',
	'subnavigation_background_color' => 'var(--base)', 'content_background_color' => 'var(--base-3)', 'sidebar_widget_background_color' => 'var(--base-3)',
	'footer_widget_background_color' => 'var(--base-3)', 'footer_background_color' => 'var(--base-3)', 'search_modal_bg_color' => 'var(--base-3)',
);
$vanilla = OGM_GPDM_Palette::gp_surfaces_css( $gp_defaults, $gp_colors );
check( 'gp surfaces: vanilla GP darkens header, menu, content, comments, footer widgets, footer bar', false !== strpos( $vanilla, 'html[data-theme="dark"] .site-header' ) && false !== strpos( $vanilla, 'html[data-theme="dark"] .main-navigation,' ) && false !== strpos( $vanilla, 'html[data-theme="dark"] .separate-containers .comments-area' ) && false !== strpos( $vanilla, 'html[data-theme="dark"] .footer-widgets' ) && false !== strpos( $vanilla, 'html[data-theme="dark"] .site-info' ) );
check( 'gp surfaces: dropdowns get the alternate surface', (bool) preg_match( '~\.main-navigation ul ul \{\s*background-color: var\(--surface-2\)~', $vanilla ) );
check( 'gp surfaces: text set light wherever a surface is darkened', substr_count( $vanilla, 'color: var(--text);' ) >= 6 );
check( 'gp surfaces: the dark default top bar (#636363) is left alone', false === strpos( $vanilla, '.top-bar' ) );
check( 'gp surfaces: search modal variables darkened', false !== strpos( $vanilla, '--gp-search-modal-bg-color: var(--surface)' ) && false !== strpos( $vanilla, '--gp-search-modal-text-color: var(--text)' ) );
check( 'gp surfaces: every selector scoped to dark mode', 0 === preg_match( '~(^|,)\s*\.[a-z]~m', preg_replace( '~/\*.*?\*/~s', '', $vanilla ) ) );
$brand = OGM_GPDM_Palette::gp_surfaces_css( array_merge( $gp_defaults, array( 'header_background_color' => '#0b2545', 'navigation_background_color' => 'var(--accent)', 'footer_background_color' => '#fafafa' ) ), $gp_colors );
check( 'gp surfaces: a dark brand header and an accent menu are left alone', false === strpos( $brand, '.site-header' ) && false === strpos( $brand, 'html[data-theme="dark"] .main-navigation,' ) );
check( 'gp surfaces: a literal light hex is still darkened', false !== strpos( $brand, '.site-info' ) );
$pal = OGM_GPDM_Palette::gp_surfaces_css( $gp_defaults, $gp_colors, array( 'base-3', 'BASE' ) );
check( 'gp surfaces: slugs the generated palette remaps are left to it', '' === $pal );
$odd = OGM_GPDM_Palette::gp_surfaces_css( array( 'header_background_color' => 'linear-gradient(#fff,#eee)', 'footer_background_color' => 'var(--nope)', 'footer_widget_background_color' => 'var(--base-3, #fff)', 'content_background_color' => '' ), $gp_colors );
check( 'gp surfaces: gradients, unknown slugs and empty values skipped; var() fallbacks resolved', false === strpos( $odd, '.site-header' ) && false === strpos( $odd, '.site-info' ) && false !== strpos( $odd, '.footer-widgets' ) && false === strpos( $odd, 'inside-article' ) );
check( 'gp surfaces: no settings → nothing', '' === OGM_GPDM_Palette::gp_surfaces_css( array(), $gp_colors ) );

// Generated CSS (tokens + GP surfaces) must not self-reference either.
$gen_css = $vanilla . $brand . $odd;
foreach ( array( $t, $near, $one, $none ) as $tok ) {
	foreach ( array( 'root', 'dark' ) as $scope ) {
		foreach ( $tok[ $scope ] as $name => $val ) {
			$gen_css .= "\n:root{" . $name . ':' . $val[0] . '}';
		}
	}
}
check( 'self-ref: none in generated tokens + GP surface CSS', '' !== trim( $gen_css ) && array() === gpdm_self_refs( $gen_css ), implode( ', ', gpdm_self_refs( $gen_css ) ) );
check( 'palette slugs: parsed from generated CSS', array( 'base-3', 'contrast', 'bg-2' ) === OGM_GPDM_Palette::palette_slugs( "html[data-theme=\"dark\"] {\n --base-3: #121212;\n --contrast: #eee;\n --bg-2:#111; color: var(--base);\n}" ) );

check( 'seed models: current lineups, defaults lead each list', 'claude-opus-5-5' === OGM_GPDM_AI::seed_models( 'anthropic' )[0] && 'gpt-6.1-sol' === OGM_GPDM_AI::seed_models( 'openai' )[0] && $prov['anthropic']['default_model'] === OGM_GPDM_AI::seed_models( 'anthropic' )[0] && $prov['openai']['default_model'] === OGM_GPDM_AI::seed_models( 'openai' )[0] );
check( 'seed models: OpenAI seed is already in ranked order (refresh keeps the default on top)', OGM_GPDM_AI::seed_models( 'openai' ) === OGM_GPDM_AI::rank_openai_models( OGM_GPDM_AI::seed_models( 'openai' ) ) );
check( 'seed models: every Claude seed takes effort', null !== OGM_GPDM_AI::effort_for( 'anthropic', 'claude-fable-5-1', '' ) && null !== OGM_GPDM_AI::effort_for( 'anthropic', 'claude-sonnet-5-5', '' ) && null !== OGM_GPDM_AI::effort_for( 'anthropic', 'claude-haiku-5-5', '' ) );

check( 'ai: prose-only response rejected', null === OGM_GPDM_AI::extract_css( 'I could not find any problems worth fixing.' ) );
check( 'ai: empty response rejected', null === OGM_GPDM_AI::extract_css( '' ) );

// Truncation shape: an UNCLOSED fence must never fall through to the
// unfenced-CSS branch (that would store prose + half a stylesheet).
$r = OGM_GPDM_AI::extract_css( "- notes about the fix\n\n```css\nhtml[data-theme=\"dark\"] .a { color: #fff; }\nhtml[data-theme=\"dark\"] .b { background" );
check( 'ai: unclosed fence (truncated reply) rejected', null === $r );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
