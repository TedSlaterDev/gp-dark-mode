<?php
/**
 * GP Dark Mode — bundle assembler end-to-end smoke test against a stubbed
 * WordPress (no install needed). Run: php tests/smoke.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );

$FIXTURES = __DIR__ . '/theme';

// ---- WP stubs ----
function __( $text, $domain = null ) { return $text; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function sanitize_key( $key ) { return preg_replace( '~[^a-z0-9_-]~', '', strtolower( (string) $key ) ); }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function wp_schedule_single_event( ...$a ) { return true; }
function spawn_cron( ...$a ) { return true; }
function wp_unschedule_hook( ...$a ) { return 0; }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return $v; }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/gp-dark-mode/'; }
function plugin_basename( $f ) { return 'gp-dark-mode/gp-dark-mode.php'; }
$GLOBALS['wp_options'] = array(
	'generate_settings'            => array(
		'global_colors'     => array(
			array( 'name' => 'Accent', 'slug' => 'accent', 'color' => '#1d428a' ),
			array( 'name' => 'Contrast', 'slug' => 'contrast', 'color' => '#222222' ),
			array( 'name' => 'Base 2', 'slug' => 'base-2', 'color' => '#f7f8f9' ),
		),
		'background_color'  => '#ffffff',
		'text_color'        => '#222222',
		'link_color'        => '#1d428a',
		'container_width'   => 1200,
		'nav_dropdown_type' => 'hover',
	),
	'generate_background_settings' => array( 'nav_image' => '', 'body_color' => '#fafafa' ),
	// 'ogm_gpdm_settings' intentionally absent: never-installed/never-saved state.
);
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $k ] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['wp_options'][ $k ] = $v;
	return true;
}
function add_option( $k, $v ) {
	if ( array_key_exists( $k, $GLOBALS['wp_options'] ) ) {
		return false;
	}
	$GLOBALS['wp_options'][ $k ] = $v;
	return true;
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function get_bloginfo( $k ) { return 'name' === $k ? 'Example Site' : '6.8'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_template() { return 'generatepress'; }
function wp_get_theme() {
	return new class() {
		public function get_stylesheet() { return 'generatepress_child'; }
		public function get( $k ) { return '1.0'; }
		public function get_template() { return 'generatepress'; }
		public function parent() {
			return new class() {
				public function get_template() { return 'generatepress'; }
				public function get( $k ) { return '3.6.0'; }
			};
		}
	};
}
function generate_get_option( $k ) { return null; }
function generate_get_global_colors() {
	$s = get_option( 'generate_settings' );
	return $s['global_colors'];
}
function get_stylesheet_directory() { global $FIXTURES; return $FIXTURES; }
function wp_get_custom_css() { return ".customizer-added { background: #eee; }\n/* pasted from AI: ``` */\n.customizer-layout { width: 50%; }"; }
function get_posts( $args ) { return array( (object) array( 'ID' => 42 ) ); }
function get_permalink( $p ) { return 'https://example.test/latest-post/'; }
function apply_filters( $tag, $value ) { return $value; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function size_format( $b ) { return $b >= 1024 ? round( $b / 1024, 1 ) . ' KB' : $b . ' B'; }
class WP_Error {
	private $code;
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_text_field( $s ) { return trim( preg_replace( '~[\r\n\t]+~', ' ', (string) $s ) ); }
function delete_option( $k ) { unset( $GLOBALS['wp_options'][ $k ] ); return true; }
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['ai_requests'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['ai_response'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sanitize_file_name( $s ) { return preg_replace( '~[^A-Za-z0-9._-]~', '-', $s ); }

$HOME_HTML = <<<HTML
<!doctype html><html><head>
<style id="generate-style-inline-css">:root{--accent:#1d428a}.dark-thing{color:#fff;padding:4px}</style>
<style id="layout-only-css">.grid{display:grid;gap:10px}</style>
<style id="generate-style-inline-css-dup">:root{--accent:#1d428a}.dark-thing{color:#fff;padding:4px}</style>
<link rel="stylesheet" id="child-css" href="https://example.test/wp-content/themes/generatepress_child/style.css?ver=1" />
<link rel="stylesheet" href="/wp-content/plugins/foo/foo.css" />
<link rel="stylesheet" href="https://example.test/wp-content/uploads/broken.css" />
<link rel="stylesheet" href="https://cdn.example.org/foreign.css" />
<link rel="stylesheet" href="https://example.test/wp-includes/css/dist/block-library/style.min.css" />
</head><body>hello</body></html>
HTML;

function wp_remote_get( $url, $args = array() ) {
	global $HOME_HTML;
	if ( false !== strpos( $url, 'api.anthropic.com/v1/models' ) ) {
		return array( 'code' => 200, 'body' => json_encode( array( 'data' => array(
			array( 'id' => 'claude-haiku-4-5' ),
			array( 'id' => 'claude-opus-5' ),
			array( 'id' => 'claude-fable-5' ),
			array( 'id' => 'not-a-claude' ),
		) ) ) );
	}
	if ( false !== strpos( $url, 'api.openai.com/v1/models' ) ) {
		return array( 'code' => 200, 'body' => json_encode( array( 'data' => array(
			array( 'id' => 'gpt-5' ),
			array( 'id' => 'gpt-4o' ),
			array( 'id' => 'gpt-4o-2024-05-13' ),
			array( 'id' => 'whisper-1' ),
			array( 'id' => 'text-embedding-3-small' ),
		) ) ) );
	}
	if ( 'https://example.test/' === $url ) {
		return array( 'code' => 200, 'body' => $HOME_HTML );
	}
	if ( 'https://example.test/latest-post/' === $url ) {
		// Same styles as home — should dedupe.
		return array( 'code' => 200, 'body' => $HOME_HTML );
	}
	if ( false !== strpos( $url, 'generatepress_child/style.css' ) ) {
		return array( 'code' => 200, 'body' => '.sheet-color { background: #123; } .sheet-layout { float: left; }' );
	}
	if ( 'https://example.test/wp-content/plugins/foo/foo.css' === $url ) {
		return array( 'code' => 200, 'body' => '.foo-color { color: #abc; }' );
	}
	return array( 'code' => 404, 'body' => '' );
}

// ---- fixtures: a child theme (real TGP functions.php when available) ----
@mkdir( $FIXTURES, 0777, true );
$real_fn = '/Users/ted/Desktop/dark-mode/functions.php';
if ( is_readable( $real_fn ) ) {
	copy( $real_fn, $FIXTURES . '/functions.php' );
} else {
	file_put_contents( $FIXTURES . '/functions.php', "<?php\nfunction ogm_add_footer_code() {}\nadd_action( 'wp_footer', 'ogm_add_footer_code' );\n" );
}
file_put_contents( $FIXTURES . '/style.css', "/* Theme Name: GP Child */\n.child-rule { color: #333; margin: 0; }\n.child-layout { display: flex; }" );

// Style/script registration stubs, capturing inline CSS for the layer test.
$GLOBALS['inline_css'] = array();
function wp_style_is( $handle, $state ) { return 'generate-style' === $handle; }
function wp_enqueue_style( ...$a ) {}
function wp_add_inline_style( $handle, $css ) { $GLOBALS['inline_css'][] = array( $handle, $css ); }
function wp_register_script( ...$a ) {}
function wp_enqueue_script( ...$a ) {}

// ---- load plugin (hook registrations hit the stubs harmlessly) ----
require dirname( __DIR__ ) . '/gp-dark-mode.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-bundle.php';
require dirname( __DIR__ ) . '/includes/class-ogm-gpdm-palette.php';

$plugin = OGM_GP_Dark_Mode::instance();
$bundle = OGM_GPDM_Bundle::assemble( $plugin );
$md     = OGM_GPDM_Bundle::to_markdown( $bundle );

file_put_contents( __DIR__ . '/sample-bundle.md', $md );

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; } else { $fail++; echo "FAIL: $label\n"; }
}

check( 'markdown non-trivial', strlen( $md ) > 5000 );
check( 'global colors present', false !== strpos( $md, '#1d428a' ) && false !== strpos( $md, '"slug": "accent"' ) );
check( 'color settings filtered in', false !== strpos( $md, 'background_color' ) );
check( 'non-color GP settings excluded', false === strpos( $md, 'container_width' ) && false === strpos( $md, 'nav_dropdown_type' ) );
check( 'framework css embedded', false !== strpos( $md, '--dm-accent' ) );
check( 'additional css marked as never-saved/empty', false !== strpos( $md, 'the plugin ships no default Manual CSS' ) );
check( 'child style.css color rule kept', false !== strpos( $md, '.child-rule { color: #333; }' ) );
check( 'child style.css layout rule dropped', false === strpos( $md, '.child-layout' ) );
check( 'functions.php embedded', false !== strpos( $md, 'ogm_add_footer_code' ) );
check( 'customizer css present', false !== strpos( $md, '.customizer-added' ) );
check( 'inline style captured + reduced', false !== strpos( $md, 'generate-style-inline-css' ) && false !== strpos( $md, '.dark-thing { color: #fff; }' ) );
check( 'layout-only inline block counted as empty', false !== strpos( $md, '1 inline block(s) had no color-relevant rules' ) );
check( 'duplicate inline block deduped on same page', false !== strpos( $md, '1 duplicated earlier blocks' ) );
check( 'same-origin sheet fetched + reduced', false !== strpos( $md, '.sheet-color { background: #123; }' ) && false === strpos( $md, '.sheet-layout' ) );
check( 'cross-origin sheet listed not fetched', false !== strpos( $md, 'cdn.example.org/foreign.css' ) && false !== strpos( $md, 'cross-origin' ) );
check( 'core sheet skipped', false !== strpos( $md, 'WordPress core' ) );
check( 'second page dedupes sheet', false !== strpos( $md, 'already captured above' ) );
check( 'both pages sectioned', false !== strpos( $md, 'Page: https://example.test/latest-post/' ) );
check( 'how-to contract present', false !== strpos( $md, 'How to use this bundle' ) );
check( 'F7: root-relative sheet resolved + fetched', false !== strpos( $md, '.foo-color { color: #abc; }' ) );
check( 'F7: root-relative sheet NOT labeled cross-origin', false === strpos( $md, '/wp-content/plugins/foo/foo.css — cross-origin' ) );
check( 'F10: failed fetch labeled honestly on page 2', false !== strpos( $md, 'broken.css — fetch failed earlier' ) );
check( 'F13: backtick content wrapped in longer fence', false !== strpos( $md, "````css\n.customizer-added" ) );
check( 'GP-active cascade bullet present', false !== strpos( $md, 'BEFORE GeneratePress' ) );

// ---- v1.2.0: layers ----

// Bundle reports the default-on gp-chrome module + its CSS.
check( 'bundle: modules in settings json', false !== strpos( $md, '"gp-chrome": 1' ) );
check( 'bundle: enabled modules listed', false !== strpos( $md, 'Enabled integration modules (Layer 3): gp-chrome' ) );
check( 'bundle: module CSS embedded', false !== strpos( $md, 'GP Dark Mode module: gp-chrome' ) );

// Layer 2 generator against the stubbed Global Colors.
$palette = OGM_GPDM_Palette::generate();
check( 'palette: generates dark-scope block', false !== strpos( $palette, 'html[data-theme="dark"]' ) );
check( 'palette: base-2 surface derived', false !== strpos( $palette, '--base-2:' ) );
check( 'palette: contrast text derived', false !== strpos( $palette, '--contrast:' ) );

// Usage-aware classification (live WTT case): a background-ish GP setting
// referencing var(--contrast) marks the "text"-named color as a SURFACE —
// combined with the literal text_color hex match, that's conflicting usage,
// so the color must be left unchanged instead of flipped light.
$GLOBALS['wp_options']['generate_settings']['header_background_color'] = 'var(--contrast)';
$roles = OGM_GPDM_Palette::usage_roles( generate_get_global_colors() );
check( 'usage: var() background ref marks surface role', isset( $roles['contrast'] ) && in_array( 'surface', $roles['contrast'], true ) );
check( 'usage: literal text_color hex marks text role', isset( $roles['contrast'] ) && in_array( 'text', $roles['contrast'], true ) );
$conflicted = OGM_GPDM_Palette::generate();
check( 'usage: conflicted color not derived', false === strpos( $conflicted, '--contrast:' ) );
check( 'usage: conflicted color kept with honest note', false !== strpos( $conflicted, 'used as both background and text' ) );
unset( $GLOBALS['wp_options']['generate_settings']['header_background_color'] );

// Front-end assembly order: framework -> module -> additional (last wins).
// Never-saved installs ship NO Manual CSS since 1.6.0 — assemble once to
// prove Layer 4b is silent, then again with an owner-saved marker to prove
// the order.
$GLOBALS['inline_css'] = array();
$plugin->enqueue_front_assets();
$captured = '';
foreach ( $GLOBALS['inline_css'] as $entry ) {
	if ( 'generate-style' === $entry[0] ) { $captured = $entry[1]; break; }
}
check( 'layers: never-saved installs emit no Manual CSS', false === strpos( $captured, '.dark-mode-contact' ) && '' === $plugin->custom_css() );

$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'custom_css' => '.owner-marker { color: #fff; }' );
reset_settings_cache( $plugin );
$GLOBALS['inline_css'] = array();
$plugin->enqueue_front_assets();
$captured = '';
foreach ( $GLOBALS['inline_css'] as $entry ) {
	if ( 'generate-style' === $entry[0] ) { $captured = $entry[1]; break; }
}
$p_framework  = strpos( $captured, 'GP Dark Mode — core framework' );
$p_module     = strpos( $captured, 'GP Dark Mode module: gp-chrome' );
$p_additional = strpos( $captured, '.owner-marker' );
check( 'layers: framework present in inline CSS', false !== $p_framework );
check( 'layers: gp-chrome module present', false !== $p_module );
check( 'layers: additional css present', false !== $p_additional );
check( 'layers: order framework < module < additional', $p_framework < $p_module && $p_module < $p_additional );
check( 'layers: generated palette absent when disabled', false === strpos( $captured, 'Generated dark palette (Layer 2)' ) );
unset( $GLOBALS['wp_options']['ogm_gpdm_settings'] );
reset_settings_cache( $plugin );

// ---- v1.2.0 review fixes ----

function reset_settings_cache( $plugin ) {
	$reset = function () { $this->settings = null; };
	Closure::bind( $reset, $plugin, OGM_GP_Dark_Mode::class )();
}

// Migration A: pre-1.2.0 NEVER-SAVED option (no custom_css, no modules)
// must get ALL modules on — output equals the old full seed.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'default_theme' => 'light' );
$plugin->maybe_upgrade();
$migrated = $GLOBALS['wp_options']['ogm_gpdm_settings']['modules'];
// Exactly the five seed-decomposition modules — newer modules must stay off
// so the upgrade renders identically to the old seed.
check( 'migration: never-saved gets the five decomposition modules on', 5 === count( array_filter( $migrated ) ) && 1 === $migrated['gp-chrome'] && 1 === $migrated['revcontent'] && 0 === $migrated['core-blocks'] && 0 === $migrated['adsense'] );

// Migration B: pre-1.2.0 SAVED option (has custom_css) must get ALL modules
// off — output equals exactly the saved CSS, nothing resurrected.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'default_theme' => 'light', 'custom_css' => '.saved {}' );
reset_settings_cache( $plugin );
$plugin->maybe_upgrade();
$migrated = $GLOBALS['wp_options']['ogm_gpdm_settings']['modules'];
check( 'migration: saved install gets ALL modules off', 0 === count( array_filter( $migrated ) ) );

// Migration is one-time: an option WITH a modules key is left alone.
$GLOBALS['wp_options']['ogm_gpdm_settings']['modules']['gp-chrome'] = 1;
$plugin->maybe_upgrade();
check( 'migration: idempotent once modules key exists', 1 === $GLOBALS['wp_options']['ogm_gpdm_settings']['modules']['gp-chrome'] );

// Generated palette lives in its own UNREGISTERED option (the settings
// sanitizer must never see it), and prints between framework and modules.
update_option( OGM_GP_Dark_Mode::GEN_KEY, OGM_GPDM_Palette::generate() );
check( 'generated: accessor reads GEN_KEY', false !== strpos( $plugin->generated_css(), 'html[data-theme="dark"]' ) );

$GLOBALS['wp_options']['ogm_gpdm_settings'] = array(
	'enable_generated' => 1,
	'modules'          => array( 'gp-chrome' => 1 ),
	'custom_css'       => '.residue-marker { color: #fff; }',
);
reset_settings_cache( $plugin );
$GLOBALS['inline_css'] = array();
$plugin->enqueue_front_assets();
$captured = '';
foreach ( $GLOBALS['inline_css'] as $entry ) {
	if ( 'generate-style' === $entry[0] ) { $captured = $entry[1]; break; }
}
$p_framework = strpos( $captured, 'GP Dark Mode — core framework' );
$p_generated = strpos( $captured, 'Generated dark palette (Layer 2)' );
$p_module    = strpos( $captured, 'GP Dark Mode module: gp-chrome' );
$p_residue   = strpos( $captured, '.residue-marker' );
check( 'generated: printed when enabled', false !== $p_generated );
check( 'layers: full order framework < generated < module < additional', false !== $p_framework && $p_framework < $p_generated && $p_generated < $p_module && $p_module < $p_residue );

// sanitize_settings no longer smuggles generated_css into the settings array
// (it lives in GEN_KEY), and unchecked module boxes sanitize to 0.
$sanitized = $plugin->sanitize_settings( array( 'default_theme' => 'dark', 'modules' => array( 'givewp' => '1' ) ) );
check( 'sanitize: no generated_css key in settings', ! array_key_exists( 'generated_css', $sanitized ) );

// The seed-fallback landmine (found live on TGP staging, 1.5.9): real WP
// re-enters this sanitizer on EVERY update_option/add_option of OPT_KEY —
// activation seeding and migrations pass arrays with no custom_css field.
// That must never materialize custom_css as '' (which silently retires the
// bundled seed); only an actually-submitted field may write it.
$__saved_opts = $GLOBALS['wp_options'][ OGM_GP_Dark_Mode::OPT_KEY ];
$GLOBALS['wp_options'][ OGM_GP_Dark_Mode::OPT_KEY ] = array( 'enable' => 1 );
$re = $plugin->sanitize_settings( array( 'enable' => 1 ) );
check( 're-entry: absent custom_css stays absent (never materialized)', ! array_key_exists( 'custom_css', $re ) );
$GLOBALS['wp_options'][ OGM_GP_Dark_Mode::OPT_KEY ]['custom_css'] = '.mine { color: red; }';
$re = $plugin->sanitize_settings( array( 'enable' => 1 ) );
check( 're-entry: stored custom_css preserved on programmatic write', isset( $re['custom_css'] ) && '.mine { color: red; }' === $re['custom_css'] );
$re = $plugin->sanitize_settings( array( 'enable' => 1, 'custom_css' => '' ) );
check( 're-entry: explicit empty save still retires the seed', array_key_exists( 'custom_css', $re ) && '' === $re['custom_css'] );
$GLOBALS['wp_options'][ OGM_GP_Dark_Mode::OPT_KEY ] = $__saved_opts;
check( 'sanitize: modules map normalized', 1 === $sanitized['modules']['givewp'] && 0 === $sanitized['modules']['gp-chrome'] );

// ---- v1.3.0: AI assist ----

require_once dirname( __DIR__ ) . '/includes/class-ogm-gpdm-ai.php';

// API-key sanitize semantics: empty keeps stored, "clear" removes, new replaces.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_api_key_anthropic' => 'sk-stored' );
$sanitized = $plugin->sanitize_settings( array( 'ai_api_key_anthropic' => '' ) );
check( 'ai sanitize: empty key input keeps stored key', 'sk-stored' === $sanitized['ai_api_key_anthropic'] );
$sanitized = $plugin->sanitize_settings( array( 'ai_api_key_anthropic' => 'clear' ) );
check( 'ai sanitize: literal clear removes key', '' === $sanitized['ai_api_key_anthropic'] );
$sanitized = $plugin->sanitize_settings( array( 'ai_api_key_openai' => 'sk-new', 'ai_provider' => 'openai', 'ai_model' => 'gpt-5-mini' ) );
check( 'ai sanitize: new key + provider + model accepted', 'sk-new' === $sanitized['ai_api_key_openai'] && 'openai' === $sanitized['ai_provider'] && 'gpt-5-mini' === $sanitized['ai_model'] );
check( 'ai sanitize: per-provider keys independent', 'sk-stored' === $sanitized['ai_api_key_anthropic'] );
$sanitized = $plugin->sanitize_settings( array( 'ai_provider' => 'evil' ) );
check( 'ai sanitize: unknown provider falls back', 'anthropic' === $sanitized['ai_provider'] );

// Missing key → clean error.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => '' );
reset_settings_cache( $plugin );
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai: missing key errors', is_wp_error( $result ) && 'ogm_gpdm_ai_no_key' === $result->get_error_code() );

// Anthropic success path.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-test', 'ai_model' => '' );
reset_settings_cache( $plugin );
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array(
		'stop_reason' => 'end_turn',
		'content'     => array(
			array( 'type' => 'thinking', 'thinking' => 'internal' ),
			array( 'type' => 'text', 'text' => "- fixed the widget contrast\n\n```css\nhtml[data-theme=\"dark\"] .prop-test { color: #fff; }\n```" ),
		),
	) ),
);
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: review succeeds', true === $result );
$prop = OGM_GPDM_AI::proposal();
check( 'ai anthropic: proposal stored with css', null !== $prop && false !== strpos( $prop['css'], '.prop-test' ) );
check( 'ai anthropic: notes stored', false !== strpos( $prop['notes'], 'fixed the widget' ) );
$req  = $GLOBALS['ai_requests'][0];
$body = json_decode( $req['args']['body'], true );
check( 'ai anthropic: endpoint + default model', false !== strpos( $req['url'], 'api.anthropic.com' ) && 'claude-opus-5' === $body['model'] );
check( 'ai anthropic: refusal fallbacks + effort set', 'default' === $body['fallbacks'] && 'medium' === $body['output_config']['effort'] );
check( 'ai anthropic: beta header + key header', isset( $req['args']['headers']['anthropic-beta'], $req['args']['headers']['x-api-key'] ) );
check( 'ai anthropic: bundle included in prompt', false !== strpos( $body['messages'][0]['content'], 'AI Context Bundle' ) );

// Refusal path.
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array( 'stop_reason' => 'refusal', 'stop_details' => array( 'explanation' => 'declined' ), 'content' => array() ) ),
);
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: refusal surfaces as error', is_wp_error( $result ) && 'ogm_gpdm_ai_refusal' === $result->get_error_code() );

// HTTP error path.
$GLOBALS['ai_response'] = array( 'code' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'invalid x-api-key' ) ) ) );
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: http error surfaces message', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'invalid x-api-key' ) );

// Truncation: max_tokens stop must be rejected, never stored.
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => '```css\n.partial {' ) ) ) ),
);
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: truncated reply rejected', is_wp_error( $result ) && 'ogm_gpdm_ai_truncated' === $result->get_error_code() );

// OpenAI success path.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa', 'ai_model' => '' );
reset_settings_cache( $plugin );
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => "```css\n.oa-test { color: #000; }\n```" ) ) ) ) ),
);
$result = OGM_GPDM_AI::run_review( $plugin );
$prop   = OGM_GPDM_AI::proposal();
check( 'ai openai: review succeeds + proposal stored', true === $result && null !== $prop && false !== strpos( $prop['css'], '.oa-test' ) );
$req  = $GLOBALS['ai_requests'][0];
$body = json_decode( $req['args']['body'], true );
check( 'ai openai: endpoint + default model + low effort', false !== strpos( $req['url'], 'api.openai.com' ) && 'gpt-5' === $body['model'] && 'low' === $body['reasoning_effort'] );
check( 'ai openai: bearer auth', 'Bearer sk-oa' === $req['args']['headers']['authorization'] );

// Apply / restore lifecycle — v1.5.0: the AI layer (4a), Manual untouched.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa', 'custom_css' => '.manual-css {}' );
update_option( OGM_GPDM_AI::AI_CSS_KEY, '.old-ai-css {}', true );
reset_settings_cache( $plugin );
$result = OGM_GPDM_AI::apply_proposal( $plugin );
check( 'ai apply: succeeds', true === $result );
check( 'ai apply: AI stylesheet replaced', false !== strpos( OGM_GPDM_AI::ai_css(), '.oa-test' ) );
check( 'ai apply: Manual CSS untouched', '.manual-css {}' === $GLOBALS['wp_options']['ogm_gpdm_settings']['custom_css'] );
check( 'ai apply: backup holds previous AI css', false !== strpos( OGM_GPDM_AI::backup()['css'], '.old-ai-css' ) );
check( 'ai apply: proposal consumed', null === OGM_GPDM_AI::proposal() );

// Restore is a SWAP of the AI layer: old AI css comes back AND the backup
// now holds what was current — a second restore round-trips.
$result = OGM_GPDM_AI::restore_backup( $plugin );
check( 'ai restore: swaps AI css back', true === $result && false !== strpos( OGM_GPDM_AI::ai_css(), '.old-ai-css' ) );
check( 'ai restore: backup now holds the replaced AI css', false !== strpos( OGM_GPDM_AI::backup()['css'], '.oa-test' ) );
OGM_GPDM_AI::restore_backup( $plugin );
check( 'ai restore: double restore round-trips', false !== strpos( OGM_GPDM_AI::ai_css(), '.oa-test' ) );
check( 'ai restore: Manual CSS still untouched', '.manual-css {}' === $GLOBALS['wp_options']['ogm_gpdm_settings']['custom_css'] );
delete_option( OGM_GPDM_AI::BACKUP_KEY );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );

// ---- v1.3.1: live model lists ----

// Before any refresh: seed list, with the saved custom model kept selectable.
$choices = OGM_GPDM_AI::model_choices( 'anthropic', 'my-custom-model' );
check( 'models: seed list before refresh + current first', 'my-custom-model' === $choices[0] && in_array( 'claude-opus-5', $choices, true ) );

check( 'models: refresh without key errors', is_wp_error( OGM_GPDM_AI::refresh_models( 'anthropic', '' ) ) );

$refreshed = OGM_GPDM_AI::refresh_models( 'anthropic', 'sk-x' );
check( 'models anthropic: refreshed + ranked (fable first)', is_array( $refreshed ) && 'claude-fable-5' === $refreshed[0] );
check( 'models anthropic: non-claude ids filtered', ! in_array( 'not-a-claude', $refreshed, true ) );
check( 'models: cache stored per provider', isset( $GLOBALS['wp_options']['ogm_gpdm_ai_models']['anthropic']['ids'] ) );
check( 'models: choices now serve the cache', in_array( 'claude-fable-5', OGM_GPDM_AI::model_choices( 'anthropic' ), true ) );

$refreshed = OGM_GPDM_AI::refresh_models( 'openai', 'sk-x' );
check( 'models openai: gpt-5 first, snapshot collapsed, non-chat filtered', is_array( $refreshed ) && 'gpt-5' === $refreshed[0] && ! in_array( 'gpt-4o-2024-05-13', $refreshed, true ) && ! in_array( 'whisper-1', $refreshed, true ) );

// The bundle must NEVER contain the API key (it gets downloaded, shared, and
// sent to providers inside the review prompt).
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_api_key_anthropic' => 'sk-super-secret-value' );
reset_settings_cache( $plugin );
$md2 = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );
check( 'bundle: api key redacted', false === strpos( $md2, 'sk-super-secret-value' ) && false !== strpos( $md2, '(configured, redacted)' ) );

// ---- v1.4.1: font-independent toggle icons ----
$markup = $plugin->toggle_markup();
check( 'toggle: svg icons replace font glyphs', false !== strpos( $markup, '<svg' ) && false === strpos( $markup, '&#9790;' ) && false === strpos( $markup, '&#9728;' ) );
check( 'toggle: moon filled via currentColor', false !== strpos( $markup, 'fill="currentColor"' ) );
check( 'toggle: dm-sun/dm-moon classes preserved for CSS', false !== strpos( $markup, 'dm-sun' ) && false !== strpos( $markup, 'dm-moon' ) );

// ---- v1.4.2: per-provider keys + instructions ----

// Legacy single-key migration: the old ai_api_key moves to the provider it
// was saved for and the legacy field disappears.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'openai', 'ai_api_key' => 'sk-legacy', 'modules' => array( 'gp-chrome' => 1 ) );
reset_settings_cache( $plugin );
$plugin->maybe_upgrade();
$migrated = $GLOBALS['wp_options']['ogm_gpdm_settings'];
check( 'v1.4.2: legacy key migrated to its provider', 'sk-legacy' === $migrated['ai_api_key_openai'] && ! array_key_exists( 'ai_api_key', $migrated ) );

// Cross-provider guard: refresh for openai must use the OPENAI key (empty
// here), never the saved anthropic key.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-ant-secret', 'ai_api_key_openai' => '', 'modules' => array() );
reset_settings_cache( $plugin );
check( 'v1.4.2: key accessor is provider-matched', 'sk-ant-secret' === $plugin->ai_key_for( 'anthropic' ) && '' === $plugin->ai_key_for( 'openai' ) );
$result = OGM_GPDM_AI::refresh_models( 'openai', $plugin->ai_key_for( 'openai' ) );
check( 'v1.4.2: refresh with no key for that provider errors (key never crosses)', is_wp_error( $result ) );

// Instructions ride into the prompt with priority framing.
$prompt = OGM_GPDM_AI::build_user_prompt( 'BUNDLE-BODY', "The footer menu links should be white.\nThe featured-post background should be dark." );
check( 'v1.4.2: instructions in prompt before bundle', false !== strpos( $prompt, "SITE OWNER'S INSTRUCTIONS" ) && strpos( $prompt, 'footer menu links' ) < strpos( $prompt, 'BUNDLE-BODY' ) );
check( 'v1.4.2: no instructions -> no instructions block', false === strpos( OGM_GPDM_AI::build_user_prompt( 'BUNDLE-BODY', '' ), "SITE OWNER'S INSTRUCTIONS" ) );

// run_review reads the stored instructions option.
update_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, 'Make the byline white.', false );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-test', 'modules' => array() );
reset_settings_cache( $plugin );
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => "```css\n.instr-test { color: #fff; }\n```" ) ) ) ),
);
OGM_GPDM_AI::run_review( $plugin );
$req_body = json_decode( $GLOBALS['ai_requests'][0]['args']['body'], true );
check( 'v1.4.2: stored instructions reach the provider', false !== strpos( $req_body['messages'][0]['content'], 'Make the byline white.' ) );
check( 'v1.4.2: provider-matched key on the wire', 'sk-test' === $GLOBALS['ai_requests'][0]['args']['headers']['x-api-key'] );
delete_option( OGM_GPDM_AI::INSTRUCTIONS_KEY );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );

// ---- v1.4.0: background run status lifecycle ----

// Key mask sentinel: submitting the mask unchanged keeps the stored key.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_api_key_anthropic' => 'sk-keepme' );
$sanitized = $plugin->sanitize_settings( array( 'ai_api_key_anthropic' => OGM_GP_Dark_Mode::KEY_MASK ) );
check( 'v1.4: key mask keeps stored key', 'sk-keepme' === $sanitized['ai_api_key_anthropic'] );

// Status lifecycle.
OGM_GPDM_AI::set_status( 'running' );
check( 'v1.4: running state fresh', OGM_GPDM_AI::is_running() && 'running' === OGM_GPDM_AI::polled_status()['state'] );

// Cron callback success: proposal stored + status done.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-test' );
reset_settings_cache( $plugin );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
$GLOBALS['ai_response'] = array(
	'code' => 200,
	'body' => json_encode( array(
		'stop_reason' => 'end_turn',
		'content'     => array( array( 'type' => 'text', 'text' => "```css\nhtml[data-theme=\"dark\"] .cron-test { color: #fff; }\n```" ) ),
	) ),
);
$plugin->run_ai_review_cron();
check( 'v1.4: cron run stores proposal', null !== OGM_GPDM_AI::proposal() && false !== strpos( OGM_GPDM_AI::proposal()['css'], '.cron-test' ) );
check( 'v1.4: cron run sets done', 'done' === OGM_GPDM_AI::polled_status()['state'] );
check( 'v1.4: done is not running', ! OGM_GPDM_AI::is_running() );

// Cron callback failure: real provider error stored server-side, never in a URL.
$GLOBALS['ai_response'] = array( 'code' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'invalid x-api-key' ) ) ) );
$plugin->run_ai_review_cron();
$st = OGM_GPDM_AI::polled_status();
check( 'v1.4: cron failure sets error + message', 'error' === $st['state'] && false !== strpos( $st['message'], 'invalid x-api-key' ) );

// Stale running flips to error on poll (cron never ran).
update_option( OGM_GPDM_AI::STATUS_KEY, array( 'state' => 'running', 'message' => '', 'started' => time() - 1000, 'finished' => 0 ), false );
$st = OGM_GPDM_AI::polled_status();
check( 'v1.4: stale running becomes error', 'error' === $st['state'] && false !== strpos( $st['message'], 'cron' ) );
check( 'v1.4: stale running unblocks new runs', ! OGM_GPDM_AI::is_running() );
delete_option( OGM_GPDM_AI::STATUS_KEY );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );

// First-ever apply: the AI layer starts empty; backup records that empty
// state and the Manual layer is never involved.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa' );
reset_settings_cache( $plugin );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );
update_option( OGM_GPDM_AI::PROPOSAL_KEY, array( 'css' => '.fresh {}', 'notes' => '', 'provider' => 'openai', 'model' => 'gpt-5', 'created' => '2026-08-25 00:00:00' ) );
OGM_GPDM_AI::apply_proposal( $plugin );
check( 'ai apply (first ever): AI layer filled, backup empty', false !== strpos( OGM_GPDM_AI::ai_css(), '.fresh' ) && '' === OGM_GPDM_AI::backup()['css'] );
check( 'ai apply (first ever): Manual layer untouched (stays empty)', '' === $plugin->custom_css() );

// ---- v1.5.0: layer split + tabs ----

// Migration: a pre-1.5 option gains enable_ai_css and stale old-semantics
// proposal/backup are cleared.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'custom_css' => '.mine {}', 'modules' => array( 'gp-chrome' => 1 ) );
update_option( OGM_GPDM_AI::PROPOSAL_KEY, array( 'css' => '.stale {}' ) );
update_option( OGM_GPDM_AI::BACKUP_KEY, array( 'css' => '.stale-backup {}' ) );
reset_settings_cache( $plugin );
$plugin->maybe_upgrade();
$migrated = $GLOBALS['wp_options']['ogm_gpdm_settings'];
check( 'v1.5 migration: enable_ai_css added', 1 === $migrated['enable_ai_css'] );
check( 'v1.5 migration: custom_css stays as Manual', '.mine {}' === $migrated['custom_css'] );
check( 'v1.5 migration: stale proposal/backup cleared', ! array_key_exists( 'ogm_gpdm_ai_proposal', $GLOBALS['wp_options'] ) && ! array_key_exists( 'ogm_gpdm_ai_backup', $GLOBALS['wp_options'] ) );

// Full five-layer front-end order: framework < generated < modules < AI < Manual.
update_option( OGM_GP_Dark_Mode::GEN_KEY, '/* GEN-MARKER */ html[data-theme="dark"]{--base:#111}', true );
update_option( OGM_GPDM_AI::AI_CSS_KEY, '/* AI-MARKER */ .ai-layer{color:#fff}', true );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array(
	'enable_generated' => 1,
	'enable_ai_css'    => 1,
	'enable_custom_css' => 1,
	'modules'          => array( 'gp-chrome' => 1 ),
	'custom_css'       => '/* MANUAL-MARKER */ .manual-layer{color:#000}',
);
reset_settings_cache( $plugin );
$GLOBALS['inline_css'] = array();
$plugin->enqueue_front_assets();
$captured = '';
foreach ( $GLOBALS['inline_css'] as $entry ) {
	if ( 'generate-style' === $entry[0] ) { $captured = $entry[1]; break; }
}
$p1 = strpos( $captured, 'GP Dark Mode — core framework' );
$p2 = strpos( $captured, 'GEN-MARKER' );
$p3 = strpos( $captured, 'GP Dark Mode module: gp-chrome' );
$p4 = strpos( $captured, 'AI-MARKER' );
$p5 = strpos( $captured, 'MANUAL-MARKER' );
check( 'v1.5 layers: all five present', false !== $p1 && false !== $p2 && false !== $p3 && false !== $p4 && false !== $p5 );
check( 'v1.5 layers: cascade order 1<2<3<4a<4b', $p1 < $p2 && $p2 < $p3 && $p3 < $p4 && $p4 < $p5 );

// AI layer respects its enable toggle.
$GLOBALS['wp_options']['ogm_gpdm_settings']['enable_ai_css'] = 0;
reset_settings_cache( $plugin );
$GLOBALS['inline_css'] = array();
$plugin->enqueue_front_assets();
$captured = $GLOBALS['inline_css'][0][1];
check( 'v1.5 layers: AI layer disabled by toggle', false === strpos( $captured, 'AI-MARKER' ) && false !== strpos( $captured, 'MANUAL-MARKER' ) );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );
delete_option( OGM_GP_Dark_Mode::GEN_KEY );

// Bundle reports both Layer-4 halves with the right roles.
update_option( OGM_GPDM_AI::AI_CSS_KEY, '.ai-in-bundle {color:#fff}', true );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'custom_css' => '.manual-in-bundle {color:#000}', 'modules' => array( 'gp-chrome' => 1 ) );
reset_settings_cache( $plugin );
$md3 = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );
check( 'v1.5 bundle: AI layer marked as the deliverable', false !== strpos( $md3, 'Layer 4a — machine-owned; THE DELIVERABLE REPLACES THIS' ) && false !== strpos( $md3, '.ai-in-bundle' ) );
check( 'v1.5 bundle: Manual layer marked read-only', false !== strpos( $md3, 'Layer 4b' ) && false !== strpos( $md3, '.manual-in-bundle' ) );

// ---- v1.5.2: factory reset ----
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'custom_css' => '.saved {}', 'ai_api_key_anthropic' => 'sk-x', 'modules' => array( 'gp-chrome' => 0 ) );
update_option( OGM_GPDM_AI::AI_CSS_KEY, '.ai {}', true );
update_option( OGM_GP_Dark_Mode::GEN_KEY, '.gen {}', true );
update_option( OGM_GPDM_AI::PROPOSAL_KEY, array( 'css' => '.p {}' ) );
OGM_GP_Dark_Mode::reset_all();
$after = $GLOBALS['wp_options']['ogm_gpdm_settings'];
check( 'reset: option re-seeded with detection (gp-chrome on)', 1 === $after['modules']['gp-chrome'] );
check( 'reset: custom_css absent again (Manual back to empty)', ! array_key_exists( 'custom_css', $after ) );
check( 'reset: API key gone', '' === $after['ai_api_key_anthropic'] );
check( 'reset: AI/generated/proposal options gone', ! isset( $GLOBALS['wp_options'][ OGM_GPDM_AI::AI_CSS_KEY ] ) && ! isset( $GLOBALS['wp_options'][ OGM_GP_Dark_Mode::GEN_KEY ] ) && ! isset( $GLOBALS['wp_options'][ OGM_GPDM_AI::PROPOSAL_KEY ] ) );
reset_settings_cache( $plugin );

// The output-toggle warning must target the DELIVERABLE's toggle (enable_ai_css).
$GLOBALS['wp_options']['ogm_gpdm_settings']['enable_ai_css'] = 0;
reset_settings_cache( $plugin );
$md4 = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );
check( 'v1.5 bundle: warns when the AI layer is disabled', false !== strpos( $md4, 'AI stylesheet output toggle is currently OFF' ) );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );

echo "\n$pass passed, $fail failed\n";
echo 'Sample bundle: ' . strlen( $md ) . " bytes -> sample-bundle.md\n";
exit( $fail > 0 ? 1 : 0 );
