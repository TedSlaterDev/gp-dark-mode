<?php
/**
 * GP Dark Mode — bundle assembler end-to-end smoke test against a stubbed
 * WordPress (no install needed). Run: php tests/smoke.php
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
// The layer-order checks below locate layers by their header comments, so
// run the front-end assembly unminified (the minifier has its own tests).
define( 'SCRIPT_DEBUG', true );

$FIXTURES = __DIR__ . '/theme';

// ---- WP stubs ----
function __( $text, $domain = null ) { return $text; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function sanitize_key( $key ) { return preg_replace( '~[^a-z0-9_-]~', '', strtolower( (string) $key ) ); }
function add_action( ...$a ) { $GLOBALS['actions'][] = $a; }
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
	return ( is_array( $s ) && isset( $s['global_colors'] ) ) ? $s['global_colors'] : array();
}
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return esc_html( $s ); } }
function get_stylesheet_directory() { global $FIXTURES; return $FIXTURES; }
function wp_get_custom_css() { return ".customizer-added { background: #eee; }\n/* pasted from AI: ``` */\n.customizer-layout { width: 50%; }"; }
function get_posts( $args ) { $GLOBALS['get_posts_args'][] = $args; return array( (object) array( 'ID' => 42 ) ); }
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
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['ai_requests'][] = array( 'url' => $url, 'args' => $args, 'method' => isset( $args['method'] ) ? $args['method'] : 'GET' );
	return array( 'code' => 200, 'body' => '{}' );
}
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['scheduled'][ $hook ] ) ? $GLOBALS['scheduled'][ $hook ] : false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url_raw( $u ) { return filter_var( (string) $u, FILTER_SANITIZE_URL ); }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_current_user_id() { return 7; }
function _n( $single, $plural, $n, $domain = null ) { return 1 === (int) $n ? $single : $plural; }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); } }
function add_query_arg( $k, $v, $u ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v ); }
function get_categories( $a = array() ) { return array( (object) array( 'term_id' => 7, 'name' => 'News' ) ); }
function get_category_link( $c ) { return 'https://example.test/category/news/'; }
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
	// v1.9.0 background jobs: tests route provider status/result GETs here.
	if ( isset( $GLOBALS['ai_get'] ) ) {
		foreach ( $GLOBALS['ai_get'] as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				$GLOBALS['ai_requests'][] = array( 'url' => $url, 'args' => $args, 'method' => 'GET' );
				return $response;
			}
		}
	}
	if ( false !== strpos( $url, 'api.anthropic.com/v1/models' ) ) {
		return array( 'code' => 200, 'body' => json_encode( array( 'data' => array(
			array( 'id' => 'claude-haiku-4-5' ),
			array( 'id' => 'claude-opus-5' ),
			array( 'id' => 'claude-opus-6' ), // Hypothetical future Opus: the default must follow the list, not the hardcoded id.
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

// ---- fixtures: a child theme (a real child theme's functions.php when available) ----
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
function wp_register_script( ...$a ) { $GLOBALS['scripts_registered'][] = $a[0]; }
function wp_enqueue_script( ...$a ) { $GLOBALS['scripts_enqueued'][] = $a[0]; }
function wp_script_is( $handle, $state = 'enqueued' ) { return false; }
function wp_print_scripts( ...$a ) {}
function did_action( $hook ) { return 0; }
function register_block_type( $path, $args = array() ) { $GLOBALS['blocks_registered'][] = array( $path, $args ); return true; }
function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-gp-dark-mode-toggle"'; }
function post_type_exists( $type ) { return ! empty( $GLOBALS['post_types'][ $type ] ); }

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

// The plugin's own tokens: the fixture's accent IS the framework's built-in
// accent, so its tuned toggle/link/alternate-surface set is kept (nothing
// emitted); any other accent derives them.
check( 'tokens: built-in accent emits no plugin tokens', false === strpos( $palette, '--dm-accent:' ) && false === strpos( $palette, '--bg-2:' ) && false !== strpos( $palette, 'built-in accent' ) );
$GLOBALS['wp_options']['generate_settings']['link_color'] = '#0e6e8e';
$teal = OGM_GPDM_Palette::generate();
check( 'tokens: other accent -> :root track + glow', (bool) preg_match( '~:root \{\n\t--dm-accent: #0e6e8e;.*\n\t--dm-glow: #[0-9a-f]{6};~', $teal ) );
check( 'tokens: other accent -> dark link + glow + alternate surface', (bool) preg_match( '~html\[data-theme="dark"\] \{\n\t--bg-2: #[0-9a-f]{6};[^}]*--surface-2: #[0-9a-f]{6};[^}]*--dm-glow: #[0-9a-f]{6};[^}]*--link: #[0-9a-f]{6};~', $teal ) );
check( 'tokens: printed after the Global Color block', strpos( $teal, '--contrast:' ) < strpos( $teal, '--dm-accent:' ) );
check( 'tokens: Global Color block unchanged by the accent', substr( $palette, 0, strpos( $palette, "}\n" ) ) === substr( $teal, 0, strpos( $teal, "}\n" ) ) );
$GLOBALS['wp_options']['generate_settings']['link_color'] = '#1d428a';

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
check( 'sanitize: ai_effort allowlisted', 'max' === $plugin->sanitize_settings( array( 'ai_effort' => 'max' ) )['ai_effort'] && '' === $plugin->sanitize_settings( array( 'ai_effort' => 'turbo' ) )['ai_effort'] );

// The seed-fallback landmine (found live on a staging site, 1.5.9): real WP
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
check( 'ai anthropic: endpoint + default model', false !== strpos( $req['url'], 'api.anthropic.com' ) && 'claude-opus-5-5' === $body['model'] );
check( 'ai anthropic: refusal fallbacks + effort set', 'default' === $body['fallbacks'] && 'medium' === $body['output_config']['effort'] );
check( 'ai anthropic: medium allowance + timeout', 32000 === $body['max_tokens'] && 420 === $req['args']['timeout'] );

// Claude Haiku 5.5 thinks by default too: it gets the effort, the scaled
// allowance and fallbacks:"default" (accepted; the refusal just stands).
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_model'] = 'claude-haiku-5-5';
reset_settings_cache( $plugin );
$GLOBALS['ai_requests'] = array();
OGM_GPDM_AI::run_review( $plugin );
$hbody = json_decode( $GLOBALS['ai_requests'][0]['args']['body'], true );
check( 'ai anthropic: Haiku 5.5 gets effort + allowance + fallbacks', 'claude-haiku-5-5' === $hbody['model'] && 'medium' === $hbody['output_config']['effort'] && 32000 === $hbody['max_tokens'] && 'default' === $hbody['fallbacks'] );
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_model'] = '';
reset_settings_cache( $plugin );
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
check( 'ai anthropic: truncation names the allowance + Effort advice', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '32,000 tokens' ) && false !== strpos( $result->get_error_message(), 'lower Effort' ) && false === strpos( $result->get_error_message(), 'Additional CSS' ) );

// A transport timeout is explained in Effort terms, not as a raw cURL error.
$GLOBALS['ai_response'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 420001 milliseconds with 0 bytes received' );
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: timeout explained with the minute limit', is_wp_error( $result ) && 'ogm_gpdm_ai_timeout' === $result->get_error_code() && false !== strpos( $result->get_error_message(), '7-minute' ) );
$GLOBALS['ai_response'] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
$result = OGM_GPDM_AI::run_review( $plugin );
check( 'ai anthropic: other transport errors pass through', is_wp_error( $result ) && 'http_request_failed' === $result->get_error_code() );

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
check( 'ai openai: endpoint + default model + medium effort default', false !== strpos( $req['url'], 'api.openai.com' ) && 'gpt-6.1-sol' === $body['model'] && 'medium' === $body['reasoning_effort'] && 420 === $req['args']['timeout'] && 32000 === $body['max_completion_tokens'] );
check( 'ai openai: bearer auth', 'Bearer sk-oa' === $req['args']['headers']['authorization'] );

// The Effort setting flows through: Max is capped to high on gpt-5 (the
// o-series/gpt-5.x ceiling) and the HTTP timeout scales with it.
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_effort'] = 'max';
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_model']  = 'gpt-5';
reset_settings_cache( $plugin );
$GLOBALS['ai_requests'] = array();
OGM_GPDM_AI::run_review( $plugin );
$req2  = $GLOBALS['ai_requests'][0];
$body2 = json_decode( $req2['args']['body'], true );
check( 'ai openai: Max effort capped to high on gpt-5 + scaled timeout + allowance', 'high' === $body2['reasoning_effort'] && 660 === $req2['args']['timeout'] && 48000 === $body2['max_completion_tokens'] );
unset( $GLOBALS['wp_options']['ogm_gpdm_settings']['ai_effort'] );
reset_settings_cache( $plugin );

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
check( 'models: seed list before refresh + current first', 'my-custom-model' === $choices[0] && in_array( 'claude-opus-5-5', $choices, true ) );

check( 'models: refresh without key errors', is_wp_error( OGM_GPDM_AI::refresh_models( 'anthropic', '' ) ) );

$refreshed = OGM_GPDM_AI::refresh_models( 'anthropic', 'sk-x' );
check( 'models anthropic: refreshed + ranked (fable first)', is_array( $refreshed ) && 'claude-fable-5' === $refreshed[0] );
check( 'models anthropic: non-claude ids filtered', ! in_array( 'not-a-claude', $refreshed, true ) );
check( 'models: cache stored per provider', isset( $GLOBALS['wp_options']['ogm_gpdm_ai_models']['anthropic']['ids'] ) );
check( 'models: choices now serve the cache', in_array( 'claude-fable-5', OGM_GPDM_AI::model_choices( 'anthropic' ), true ) );
check( 'models anthropic: default follows the newest Opus in the refreshed list, not the ranked top', 'claude-opus-6' === OGM_GPDM_AI::default_model( 'anthropic' ) );

$refreshed = OGM_GPDM_AI::refresh_models( 'openai', 'sk-x' );
check( 'models openai: gpt-5 first, snapshot collapsed, non-chat filtered', is_array( $refreshed ) && 'gpt-5' === $refreshed[0] && ! in_array( 'gpt-4o-2024-05-13', $refreshed, true ) && ! in_array( 'whisper-1', $refreshed, true ) );
check( 'models openai: default follows the refreshed top', 'gpt-5' === OGM_GPDM_AI::default_model( 'openai' ) );

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

// ---- v1.7.0: Dark Mode Toggle block ----
// Headers built with a Site Header Block Element / GenerateBlocks Navigation
// have no GeneratePress menu bar, so the automatic toggle never renders there.
$options_before     = $GLOBALS['wp_options'];
$GLOBALS['actions'] = array();
$plugin->boot(); // The harness never fires plugins_loaded; boot() registers the hooks.
$GLOBALS['wp_options'] = $options_before; // boot() also runs maybe_upgrade().
reset_settings_cache( $plugin );
$init_hooked = false;
foreach ( $GLOBALS['actions'] as $hooked ) {
	if ( 'init' === $hooked[0] && is_array( $hooked[1] ) && 'register_blocks' === $hooked[1][1] ) {
		$init_hooked = true;
	}
}
check( 'block: registration hooked on init', $init_hooked );

$GLOBALS['blocks_registered'] = array();
$plugin->register_blocks();
$reg = $GLOBALS['blocks_registered'][0] ?? null;
check( 'block: registered from blocks/toggle with a render callback', null !== $reg && str_ends_with( rtrim( $reg[0], '/' ), 'blocks/toggle' ) && array( $plugin, 'render_toggle_block' ) === $reg[1]['render_callback'] );

$block_dir  = dirname( __DIR__ ) . '/blocks/toggle';
$block_json = json_decode( (string) file_get_contents( $block_dir . '/block.json' ), true );
check( 'block: block.json parses with the expected name + apiVersion 3', is_array( $block_json ) && 'gp-dark-mode/toggle' === $block_json['name'] && 3 === $block_json['apiVersion'] && 'gp-dark-mode' === $block_json['textdomain'] );
check( 'block: server-rendered (no saved markup to go stale)', ! isset( $block_json['attributes'] ) && false !== strpos( (string) file_get_contents( $block_dir . '/index.js' ), 'return null;' ) );
check( 'block: editor files referenced by block.json exist', is_file( $block_dir . '/' . substr( $block_json['editorScript'], 7 ) ) && is_file( $block_dir . '/' . substr( $block_json['editorStyle'], 7 ) ) );
$asset = require $block_dir . '/index.asset.php';
check( 'block: asset file lists the editor globals the script uses', array() === array_diff( array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), $asset['dependencies'] ) );
check( 'block: client registers the same name as block.json', false !== strpos( (string) file_get_contents( $block_dir . '/index.js' ), "registerBlockType( 'gp-dark-mode/toggle'" ) );

$GLOBALS['scripts_enqueued'] = array();
$GLOBALS['wp_options']['ogm_gpdm_settings']['show_toggle'] = 0;
reset_settings_cache( $plugin );
$block_html = $plugin->render_toggle_block( array() );
check( 'block: renders the toggle button inside the block wrapper', 0 === strpos( $block_html, '<div class="wp-block-gp-dark-mode-toggle">' ) && false !== strpos( $block_html, 'class="dm-toggle"' ) && false !== strpos( $block_html, 'role="switch"' ) );
check( 'block: enqueues the toggle script even with the menu-bar toggle off', in_array( 'gp-dark-mode-toggle', $GLOBALS['scripts_enqueued'], true ) );
unset( $GLOBALS['wp_options']['ogm_gpdm_settings']['show_toggle'] );
reset_settings_cache( $plugin );

$fw = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/gp-dark-mode.css' );
check( 'block: front-end CSS drops the menu-bar margin inside the wrapper', false !== strpos( $fw, '.wp-block-gp-dark-mode-toggle .dm-toggle' ) );

// Settings-page hint: a published Site Header Block Element.
$has_header = new ReflectionMethod( $plugin, 'has_site_header_element' );
$GLOBALS['post_types']     = array();
$GLOBALS['get_posts_args'] = array();
check( 'block hint: no GP Premium Elements → no hint, no query', false === $has_header->invoke( $plugin ) && array() === $GLOBALS['get_posts_args'] );
$GLOBALS['post_types'] = array( 'gp_elements' => true );
check( 'block hint: a site-header Block Element is detected', true === $has_header->invoke( $plugin ) );
$q = $GLOBALS['get_posts_args'][0] ?? array();
check( 'block hint: asks for one published site-header element', 'gp_elements' === $q['post_type'] && 'publish' === $q['post_status'] && '_generate_block_type' === $q['meta_key'] && 'site-header' === $q['meta_value'] && 1 === $q['numberposts'] );
$GLOBALS['post_types'] = array();

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
check( 'v1.4: stale running becomes error', 'error' === $st['state'] && false !== stripos( $st['message'], 'cron' ) );
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


// ---- v1.9.0: background reviews (Anthropic batches, OpenAI background responses) ----
$json_ok = function ( $data ) { return array( 'code' => 200, 'body' => json_encode( $data ) ); };
$find_req = function ( $needle, $method = null ) {
	foreach ( $GLOBALS['ai_requests'] as $r ) {
		$m = isset( $r['method'] ) ? $r['method'] : 'POST';
		if ( false !== strpos( $r['url'], $needle ) && ( null === $method || $method === $m ) ) {
			return $r;
		}
	}
	return null;
};
$css_reply = "- darkened the cards\n\n```css\nhtml[data-theme=\"dark\"] .bg-test { color: #eee; }\n```";
$start_bg  = function ( $settings ) use ( $plugin ) {
	$GLOBALS['wp_options']['ogm_gpdm_settings'] = $settings;
	reset_settings_cache( $plugin );
	delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
	OGM_GPDM_AI::set_status( 'running' );
	$GLOBALS['ai_requests'] = array();
	$GLOBALS['ai_get']      = array();
};
$force_check = function () {
	OGM_GPDM_AI::patch_status( array( 'checked' => time() - 60 ) );
};

// Mode selection.
delete_option( OGM_GPDM_AI::CAPPED_KEY );
check( 'mode: Automatic calls directly on an ordinary host', false === OGM_GPDM_AI::uses_background( array( 'ai_mode' => '' ) ) && '' === OGM_GPDM_AI::auto_background_reason() );
check( 'mode: Background / Direct settings win', true === OGM_GPDM_AI::uses_background( array( 'ai_mode' => 'background' ) ) && false === OGM_GPDM_AI::uses_background( array( 'ai_mode' => 'direct' ) ) );
update_option( OGM_GPDM_AI::CAPPED_KEY, time() );
check( 'mode: Automatic switches to background once a host cut a review off', true === OGM_GPDM_AI::uses_background( array( 'ai_mode' => '' ) ) && 'learned' === OGM_GPDM_AI::auto_background_reason() );
check( 'mode: Direct still means direct on such a site', false === OGM_GPDM_AI::uses_background( array( 'ai_mode' => 'direct' ) ) );
delete_option( OGM_GPDM_AI::CAPPED_KEY );
check( 'mode: bogus setting reads as Automatic', '' === OGM_GPDM_AI::mode_setting( array( 'ai_mode' => 'turbo' ) ) );

// Anthropic: submit a one-request batch.
$start_bg( array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-bg', 'ai_model' => 'claude-opus-5-5', 'ai_effort' => 'high', 'ai_mode' => 'background' ) );
$GLOBALS['ai_response'] = $json_ok( array( 'id' => 'msgbatch_123', 'processing_status' => 'in_progress' ) );
$result = OGM_GPDM_AI::run_review( $plugin );
$sub    = $GLOBALS['ai_requests'][0];
$sb     = json_decode( $sub['args']['body'], true );
$st     = OGM_GPDM_AI::status();
check( 'bg anthropic: run_review returns submitted', 'submitted' === $result );
check( 'bg anthropic: posts one request to the Batches API', false !== strpos( $sub['url'], '/v1/messages/batches' ) && 1 === count( $sb['requests'] ) && 'gpdm-review' === $sb['requests'][0]['custom_id'] );
check( 'bg anthropic: params carry model, effort and the High allowance', 'claude-opus-5-5' === $sb['requests'][0]['params']['model'] && 'high' === $sb['requests'][0]['params']['output_config']['effort'] && 48000 === $sb['requests'][0]['params']['max_tokens'] );
check( 'bg anthropic: no fallbacks param or beta header (batches reject them)', ! isset( $sb['requests'][0]['params']['fallbacks'] ) && ! isset( $sub['args']['headers']['anthropic-beta'] ) );
check( 'bg anthropic: status records the job', 'running' === $st['state'] && 'background' === $st['mode'] && 'submitted' === $st['phase'] && 'msgbatch_123' === $st['job']['id'] && 48000 === $st['job']['budget'] );
check( 'bg anthropic: page note says batch + can leave', false !== strpos( $st['message'], 'batch' ) && false !== strpos( $st['message'], 'leave this page' ) );
check( 'bg anthropic: no proposal yet, no direct call made', null === OGM_GPDM_AI::proposal() && null === $find_req( 'api.anthropic.com/v1/messages', 'POST' ) || false !== strpos( $find_req( 'api.anthropic.com/v1/messages', 'POST' )['url'], 'batches' ) );

// Still processing.
$force_check();
$GLOBALS['ai_get'] = array( 'batches/msgbatch_123' => $json_ok( array( 'id' => 'msgbatch_123', 'processing_status' => 'in_progress' ) ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'bg anthropic: in_progress stays running', 'running' === $polled['state'] && null !== $find_req( 'batches/msgbatch_123', 'GET' ) );
check( 'bg: an old background job is not stale (2 h in)', ( function () { OGM_GPDM_AI::patch_status( array( 'started' => time() - 7200 ) ); return OGM_GPDM_AI::is_running(); } )() );

// Throttle: a check moments ago means no provider call.
OGM_GPDM_AI::patch_status( array( 'checked' => time() ) );
$GLOBALS['ai_requests'] = array();
OGM_GPDM_AI::polled_status( $plugin );
check( 'bg: checks are throttled', null === $find_req( 'batches/msgbatch_123', 'GET' ) );

// A transient failure keeps it running with a note.
$force_check();
$GLOBALS['ai_get'] = array( 'batches/msgbatch_123' => array( 'code' => 529, 'body' => '{"error":{"message":"overloaded"}}' ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'bg: a 5xx check retries instead of failing', 'running' === $polled['state'] && false !== strpos( $polled['message'], 'retries' ) );

// Ended + succeeded: proposal stored, run done, batch deleted.
$force_check();
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_get'] = array(
	'results/msgbatch_123' => array( 'code' => 200, 'body' => json_encode( array( 'custom_id' => 'gpdm-review', 'result' => array( 'type' => 'succeeded', 'message' => array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'thinking', 'thinking' => 'x' ), array( 'type' => 'text', 'text' => $css_reply ) ) ) ) ) ) . "\n" ),
	'batches/msgbatch_123' => $json_ok( array( 'id' => 'msgbatch_123', 'processing_status' => 'ended', 'results_url' => 'https://api.anthropic.com/v1/messages/batches/results/msgbatch_123' ) ),
);
$polled = OGM_GPDM_AI::polled_status( $plugin );
$prop   = OGM_GPDM_AI::proposal();
check( 'bg anthropic: ended batch → proposal stored + done', 'done' === $polled['state'] && null !== $prop && false !== strpos( $prop['css'], '.bg-test' ) && 'claude-opus-5-5' === $prop['model'] );
check( 'bg anthropic: results fetched with the API key', null !== ( $rr = $find_req( 'results/msgbatch_123', 'GET' ) ) && 'sk-bg' === $rr['args']['headers']['x-api-key'] );
check( 'bg anthropic: batch deleted at Anthropic afterwards', null !== $find_req( 'batches/msgbatch_123', 'DELETE' ) );

// Ended with a truncated reply → the allowance error, nothing stored.
$jsonl = function ( $result ) { return array( 'code' => 200, 'body' => json_encode( array( 'custom_id' => 'gpdm-review', 'result' => $result ) ) . "\n" ); };
$ended = $json_ok( array( 'id' => 'msgbatch_9', 'processing_status' => 'ended', 'results_url' => 'https://api.anthropic.com/v1/messages/batches/results/msgbatch_9' ) );
$run_ended = function ( $result ) use ( $start_bg, $json_ok, $jsonl, $ended, $plugin ) {
	$start_bg( array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-bg', 'ai_mode' => 'background' ) );
	OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'checked' => time() - 60, 'job' => array( 'id' => 'msgbatch_9', 'budget' => 32000 ) ) );
	$GLOBALS['ai_get'] = array( 'results/msgbatch_9' => $jsonl( $result ), 'batches/msgbatch_9' => $ended );
	return OGM_GPDM_AI::polled_status( $plugin );
};
$polled = $run_ended( array( 'type' => 'succeeded', 'message' => array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => '```css' . "\n.partial {" ) ) ) ) );
check( 'bg anthropic: truncated batch reply rejected with the allowance', 'error' === $polled['state'] && false !== strpos( $polled['message'], '32,000 tokens' ) && null === OGM_GPDM_AI::proposal() );
$polled = $run_ended( array( 'type' => 'errored', 'error' => array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'bad model' ) ) ) );
check( 'bg anthropic: errored request surfaces its message', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'bad model' ) );
$polled = $run_ended( array( 'type' => 'expired' ) );
check( 'bg anthropic: expired batch explained', 'error' === $polled['state'] && false !== strpos( $polled['message'], '24 hours' ) );
$polled = $run_ended( array( 'type' => 'succeeded', 'message' => array( 'stop_reason' => 'refusal', 'stop_details' => array( 'explanation' => 'nope' ), 'content' => array() ) ) );
check( 'bg anthropic: refusal in a batch result surfaces', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'declined' ) );

// A 404 (job gone) or 401 (key revoked) fails instead of retrying forever.
$start_bg( array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-bg', 'ai_mode' => 'background' ) );
OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'anthropic', 'checked' => time() - 60, 'job' => array( 'id' => 'msgbatch_gone', 'budget' => 32000 ) ) );
$GLOBALS['ai_get'] = array( 'batches/msgbatch_gone' => array( 'code' => 404, 'body' => '{"error":{"message":"not found"}}' ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'bg: a 404 check fails the run', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'not found' ) );

// The key removed mid-run.
$start_bg( array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => '', 'ai_mode' => 'background' ) );
OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'anthropic', 'checked' => time() - 60, 'job' => array( 'id' => 'msgbatch_k', 'budget' => 32000 ) ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'bg: a removed key ends the run with an explanation', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'key was removed' ) );

// Cancel.
$start_bg( array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-bg', 'ai_mode' => 'background' ) );
OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'anthropic', 'job' => array( 'id' => 'msgbatch_c', 'budget' => 32000 ) ) );
$GLOBALS['ai_response'] = $json_ok( array( 'id' => 'msgbatch_c', 'processing_status' => 'canceling' ) );
$cancel = OGM_GPDM_AI::cancel_job( $plugin );
check( 'bg: cancel asks Anthropic to cancel and closes the run', true === $cancel && null !== $find_req( 'batches/msgbatch_c/cancel' ) && 'error' === OGM_GPDM_AI::status()['state'] && 'Review canceled.' === OGM_GPDM_AI::status()['message'] );
OGM_GPDM_AI::set_status( 'running' );
OGM_GPDM_AI::patch_status( array( 'mode' => 'direct', 'phase' => 'calling' ) );
check( 'bg: a direct call can’t be canceled', is_wp_error( OGM_GPDM_AI::cancel_job( $plugin ) ) );

// OpenAI: background response.
$start_bg( array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa', 'ai_model' => 'gpt-6.1-sol', 'ai_mode' => 'background' ) );
$GLOBALS['ai_response'] = $json_ok( array( 'id' => 'resp_42', 'status' => 'queued' ) );
$result = OGM_GPDM_AI::run_review( $plugin );
$sub    = $GLOBALS['ai_requests'][0];
$ob     = json_decode( $sub['args']['body'], true );
check( 'bg openai: posts a background response', 'submitted' === $result && false !== strpos( $sub['url'], '/v1/responses' ) && true === $ob['background'] && true === $ob['store'] );
check( 'bg openai: model, effort, allowance, system + user prompts', 'gpt-6.1-sol' === $ob['model'] && 'medium' === $ob['reasoning']['effort'] && 32000 === $ob['max_output_tokens'] && false !== strpos( $ob['instructions'], 'dark mode' ) && 'user' === $ob['input'][0]['role'] && false !== strpos( $ob['input'][0]['content'], 'AI Context Bundle' ) );
check( 'bg openai: bearer auth', 'Bearer sk-oa' === $sub['args']['headers']['authorization'] );
check( 'bg openai: page note says background mode', false !== strpos( OGM_GPDM_AI::status()['message'], 'OpenAI' ) );

$force_check();
$GLOBALS['ai_get'] = array( 'responses/resp_42' => $json_ok( array( 'id' => 'resp_42', 'status' => 'in_progress' ) ) );
check( 'bg openai: in_progress stays running', 'running' === OGM_GPDM_AI::polled_status( $plugin )['state'] );

$force_check();
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_get'] = array( 'responses/resp_42' => $json_ok( array(
	'id'     => 'resp_42',
	'status' => 'completed',
	'output' => array(
		array( 'type' => 'reasoning', 'summary' => array() ),
		array( 'type' => 'message', 'role' => 'assistant', 'content' => array( array( 'type' => 'output_text', 'text' => $css_reply ) ) ),
	),
) ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
$prop   = OGM_GPDM_AI::proposal();
check( 'bg openai: completed → proposal stored + done', 'done' === $polled['state'] && null !== $prop && false !== strpos( $prop['css'], '.bg-test' ) && 'openai' === $prop['provider'] );
check( 'bg openai: response deleted at OpenAI afterwards', null !== $find_req( 'responses/resp_42', 'DELETE' ) );

$run_oa = function ( $response ) use ( $start_bg, $json_ok, $plugin ) {
	$start_bg( array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa', 'ai_mode' => 'background' ) );
	OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'openai', 'model' => 'gpt-6.1-sol', 'effort' => 'high', 'checked' => time() - 60, 'job' => array( 'id' => 'resp_7', 'budget' => 48000 ) ) );
	$GLOBALS['ai_get'] = array( 'responses/resp_7' => $json_ok( $response ) );
	return OGM_GPDM_AI::polled_status( $plugin );
};
$polled = $run_oa( array( 'id' => 'resp_7', 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'output' => array() ) );
check( 'bg openai: incomplete at max_output_tokens → allowance error', 'error' === $polled['state'] && false !== strpos( $polled['message'], '48,000 tokens' ) );
$polled = $run_oa( array( 'id' => 'resp_7', 'status' => 'failed', 'error' => array( 'message' => 'server exploded' ) ) );
check( 'bg openai: failed surfaces the error', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'server exploded' ) );
$polled = $run_oa( array( 'id' => 'resp_7', 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'refusal', 'refusal' => 'cannot help' ) ) ) ) ) );
check( 'bg openai: a refusal surfaces', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'declined' ) );

// The cron check re-queues itself while the job runs.
$start_bg( array( 'ai_provider' => 'openai', 'ai_api_key_openai' => 'sk-oa', 'ai_mode' => 'background' ) );
OGM_GPDM_AI::patch_status( array( 'mode' => 'background', 'phase' => 'submitted', 'provider' => 'openai', 'checked' => time(), 'job' => array( 'id' => 'resp_8', 'budget' => 32000 ) ) );
$GLOBALS['ai_get'] = array( 'responses/resp_8' => $json_ok( array( 'id' => 'resp_8', 'status' => 'in_progress' ) ) );
$plugin->run_ai_check_cron();
check( 'bg: the cron check forces a provider call (ignores the throttle)', null !== $find_req( 'responses/resp_8', 'GET' ) );

// Dead direct runs: the phase says what happened, Automatic learns.
delete_option( OGM_GPDM_AI::CAPPED_KEY );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-x', 'ai_mode' => '' );
reset_settings_cache( $plugin );
OGM_GPDM_AI::set_status( 'running' );
OGM_GPDM_AI::patch_status( array( 'mode' => 'direct', 'phase' => 'calling', 'phase_at' => time() - 30, 'effort' => 'high' ) );
check( 'dead run: a direct call inside its time limit is still running', 'running' === OGM_GPDM_AI::polled_status( $plugin )['state'] );
OGM_GPDM_AI::patch_status( array( 'phase_at' => time() - 800, 'started' => time() - 810 ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'dead run: a call past its time limit = the host ended it', 'error' === $polled['state'] && false !== strpos( $polled['message'], 'host ended the request' ) );
check( 'dead run: Automatic learns → next review goes to the background', (bool) get_option( OGM_GPDM_AI::CAPPED_KEY ) && true === OGM_GPDM_AI::uses_background( $plugin->settings() ) );

delete_option( OGM_GPDM_AI::CAPPED_KEY );
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_mode'] = 'direct';
reset_settings_cache( $plugin );
OGM_GPDM_AI::set_status( 'running' );
OGM_GPDM_AI::patch_status( array( 'mode' => 'direct', 'phase' => 'calling', 'phase_at' => time() - 1000, 'started' => time() - 1000, 'effort' => 'low' ) );
$polled = OGM_GPDM_AI::polled_status( $plugin );
check( 'dead run: explicit Direct → advice to switch, no silent learning', false !== strpos( $polled['message'], 'Set Review mode to Background' ) && ! get_option( OGM_GPDM_AI::CAPPED_KEY ) );

OGM_GPDM_AI::set_status( 'running' );
OGM_GPDM_AI::patch_status( array( 'started' => time() - 1000, 'phase' => 'queued' ) );
check( 'dead run: never picked up = cron', false !== strpos( OGM_GPDM_AI::polled_status( $plugin )['message'], 'WP-Cron' ) );
OGM_GPDM_AI::set_status( 'running' );
OGM_GPDM_AI::patch_status( array( 'started' => time() - 1000, 'phase' => 'bundle' ) );
check( 'dead run: died while gathering CSS says so', false !== strpos( OGM_GPDM_AI::polled_status( $plugin )['message'], 'gathering the site' ) );

// A direct review that succeeds in Direct mode clears the learned flag.
update_option( OGM_GPDM_AI::CAPPED_KEY, time() );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-x', 'ai_mode' => 'direct' );
reset_settings_cache( $plugin );
OGM_GPDM_AI::set_status( 'running' );
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_response'] = $json_ok( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => $css_reply ) ) ) );
$plugin->run_ai_review_cron();
check( 'direct: success in Direct mode clears the learned flag', 'done' === OGM_GPDM_AI::status()['state'] && ! get_option( OGM_GPDM_AI::CAPPED_KEY ) );
check( 'direct: the call phase was recorded with its effort', false !== strpos( $GLOBALS['ai_requests'][0]['url'], 'api.anthropic.com/v1/messages' ) );

// The worker hands a background job to the cron check.
$GLOBALS['wp_options']['ogm_gpdm_settings']['ai_mode'] = 'background';
reset_settings_cache( $plugin );
OGM_GPDM_AI::set_status( 'running' );
$GLOBALS['ai_response'] = $json_ok( array( 'id' => 'msgbatch_w', 'processing_status' => 'in_progress' ) );
$plugin->run_ai_review_cron();
check( 'worker: a submitted job stays running (no premature done)', 'running' === OGM_GPDM_AI::status()['state'] && 'msgbatch_w' === OGM_GPDM_AI::status()['job']['id'] );

$GLOBALS['ai_get'] = array();
delete_option( OGM_GPDM_AI::STATUS_KEY );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
delete_option( OGM_GPDM_AI::CAPPED_KEY );


// ---- v1.10.0: measured contrast check + Claude in Chrome ----
require_once dirname( __DIR__ ) . '/includes/class-ogm-gpdm-audit.php';
delete_option( OGM_GPDM_Audit::OPTION );

$pages = OGM_GPDM_Audit::pages();
$labels = array_map( function ( $p ) { return $p['label']; }, $pages );
check( 'audit pages: home, post, page, category, search, 404', array( 'Homepage', 'Latest post', 'A page', 'Category archive', 'Search results', 'Not found (404)' ) === $labels );
check( 'audit pages: all on this site', count( array_filter( $pages, function ( $p ) { return 0 === strpos( $p['url'], 'https://example.test/' ); } ) ) === count( $pages ) );
check( 'audit: own-host check rejects other hosts', OGM_GPDM_Audit::is_own_url( 'https://example.test/x' ) && ! OGM_GPDM_Audit::is_own_url( 'https://evil.test/x' ) && ! OGM_GPDM_Audit::is_own_url( 'javascript:alert(1)' ) );

$browser = array(
	'width' => 1280,
	'pages' => array(
		array(
			'url' => 'https://example.test/', 'label' => 'Homepage', 'ok' => true, 'texts' => 310, 'interactive' => 44,
			'dark_total' => 3, 'light_total' => 1, 'island_total' => 1,
			'failures' => array(
				array( 'selector' => '#footer .widget a', 'sample' => 'Privacy', 'fg' => '#555555', 'bg' => '#222222', 'ratio' => 2.1, 'need' => 4.5, 'count' => 6, 'state' => 'normal', 'also_light' => false ),
				array( 'selector' => '.main-navigation a', 'sample' => 'Home', 'fg' => '#1D428A', 'bg' => '#101010', 'ratio' => 1.9, 'need' => 4.5, 'count' => 1, 'state' => 'hover' ),
				array( 'selector' => '<script>alert(1)</script>', 'sample' => "x\ny", 'fg' => 'red', 'bg' => '#000000', 'ratio' => 3 ), // bad color: dropped
				array( 'selector' => '.big h1', 'fg' => '#777777', 'bg' => '#333333', 'ratio' => 99, 'need' => 3, 'state' => 'sideways', 'also_light' => 1 ),
			),
			'islands' => array( array( 'selector' => '.card.white', 'bg' => '#ffffff', 'w' => 600, 'h' => 300 ), array( 'selector' => '.bad', 'bg' => 'white' ) ),
		),
		array( 'url' => 'https://evil.test/', 'label' => 'Elsewhere', 'ok' => true ), // foreign host: dropped
		array( 'url' => 'https://example.test/gpdm-contrast-check-not-found/', 'label' => 'Not found (404)', 'ok' => false, 'error' => 'the page could not be rendered' ),
	),
);
$saved = OGM_GPDM_Audit::save_from_json( json_encode( $browser ) );
check( 'audit save: stored', is_array( $saved ) && null !== OGM_GPDM_Audit::get() );
check( 'audit save: foreign-host page dropped', 2 === count( $saved['pages'] ) );
$f = $saved['pages'][0]['failures'];
check( 'audit save: rows with invalid colors dropped, hex lowercased', 3 === count( $f ) && '#1d428a' === $f[1]['fg'] );
check( 'audit save: ratio clamped to 21, need 3 kept, bad state → normal', 21.0 === $f[2]['ratio'] && 3.0 === $f[2]['need'] && 'normal' === $f[2]['state'] && true === $f[2]['also_light'] );
check( 'audit save: hover state kept', 'hover' === $f[1]['state'] );
check( 'audit save: islands validated', 1 === count( $saved['pages'][0]['islands'] ) && '#ffffff' === $saved['pages'][0]['islands'][0]['bg'] );
check( 'audit save: garbage rejected', is_wp_error( OGM_GPDM_Audit::save_from_json( '{"pages":[]}' ) ) && is_wp_error( OGM_GPDM_Audit::save_from_json( 'not json' ) ) );
$t = OGM_GPDM_Audit::totals( $saved );
check( 'audit totals: failed pages excluded', 1 === $t['ok'] && 3 === $t['dark'] && 1 === $t['light'] && 1 === $t['islands'] );

$amd = OGM_GPDM_Audit::to_markdown( $saved );
check( 'audit md: section heading + method', false !== strpos( $amd, '## 6. Measured contrast in dark mode' ) && false !== strpos( $amd, 'scripts off' ) );
check( 'audit md: worst first, hover marked', strpos( $amd, '.main-navigation a` [hover]' ) < strpos( $amd, '#footer .widget a' ) );
check( 'audit md: dark-only before light-too', strpos( $amd, '#footer .widget a' ) < strpos( $amd, '.big h1' ) && false !== strpos( $amd, 'also fails in light mode' ) );
check( 'audit md: counts, page labels, failed page reason', false !== strpos( $amd, '×6' ) && false !== strpos( $amd, '· Homepage' ) && false !== strpos( $amd, 'not checked: the page could not be rendered' ) );
check( 'audit md: light panels listed', false !== strpos( $amd, '`.card.white` — background #ffffff, 600×300px' ) );
check( 'audit md: the script selector is inert text, not markup', false === strpos( $amd, '<script>' ) );

// The bundle carries it, the prompt points at it.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'ai_provider' => 'anthropic', 'ai_api_key_anthropic' => 'sk-a', 'ai_mode' => 'direct' );
reset_settings_cache( $plugin );
$bmd = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );
check( 'bundle: section 6 present with the measured rows', false !== strpos( $bmd, '## 6. Measured contrast in dark mode' ) && false !== strpos( $bmd, '#footer .widget a' ) );
check( 'bundle: contract says section 6 is measured', false !== strpos( $bmd, 'Section 6, when present' ) );
check( 'prompt: MEASURED PROBLEMS instruction when the bundle has a check', false !== strpos( OGM_GPDM_AI::build_user_prompt( $bmd ), 'MEASURED PROBLEMS' ) );
check( 'prompt: no measured instruction without a check', false === strpos( OGM_GPDM_AI::build_user_prompt( 'bundle without section six' ), 'MEASURED PROBLEMS' ) );
$GLOBALS['ai_requests'] = array();
$GLOBALS['ai_response'] = array( 'code' => 200, 'body' => json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => "```css\nhtml[data-theme=\"dark\"] .m { color: #fff; }\n```" ) ) ) ) );
OGM_GPDM_AI::run_review( $plugin );
$sent = json_decode( $GLOBALS['ai_requests'][0]['args']['body'], true );
check( 'review: the request carries the measured section', false !== strpos( $sent['messages'][0]['content'], '## 6. Measured contrast in dark mode' ) && false !== strpos( $sent['messages'][0]['content'], 'MEASURED PROBLEMS' ) );

// Claude in Chrome prompt.
update_option( OGM_GPDM_AI::AI_CSS_KEY, 'html[data-theme="dark"] .ai-now { color: #eee; }', true );
$GLOBALS['wp_options']['ogm_gpdm_settings']['custom_css'] = '.manual-now { color: #000; }';
reset_settings_cache( $plugin );
$cp = OGM_GPDM_Audit::chrome_prompt( $plugin );
check( 'chrome prompt: site, pages, how dark mode works', false !== strpos( $cp, 'Example Site' ) && false !== strpos( $cp, 'https://example.test/category/news/' ) && false !== strpos( $cp, 'data-theme="dark"' ) );
check( 'chrome prompt: measured problems, current AI + Manual CSS, deliverable', false !== strpos( $cp, '#footer .widget a' ) && false !== strpos( $cp, '.ai-now' ) && false !== strpos( $cp, '.manual-now' ) && false !== strpos( $cp, 'ONE ```css code block' ) );
check( 'chrome prompt: no API key, no functions.php', false === strpos( $cp, 'sk-a' ) && false === strpos( $cp, 'functions.php' ) );
delete_option( OGM_GPDM_Audit::OPTION );
check( 'chrome prompt: says when nothing was measured', false !== strpos( OGM_GPDM_Audit::chrome_prompt( $plugin ), 'contrast check hasn\'t been run' ) );

// Pasting Claude's reply as a proposal.
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
$r1 = OGM_GPDM_AI::store_pasted_proposal( "- Fixed the footer links.\n\n```css\nhtml[data-theme=\"dark\"] #footer a { color: #ddd; }\n```" );
$pp = OGM_GPDM_AI::proposal();
check( 'paste: a full reply becomes a proposal (notes + css)', true === $r1 && false !== strpos( $pp['css'], '#footer a' ) && false !== strpos( $pp['notes'], 'footer links' ) && 'Pasted' === $pp['provider'] && 'Claude in Chrome' === $pp['model'] );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
check( 'paste: bare CSS without data-theme still accepted', true === OGM_GPDM_AI::store_pasted_proposal( ':root { --x: #000; }' ) && false !== strpos( OGM_GPDM_AI::proposal()['css'], '--x' ) );
check( 'paste: empty or CSS-less input rejected', is_wp_error( OGM_GPDM_AI::store_pasted_proposal( '   ' ) ) && is_wp_error( OGM_GPDM_AI::store_pasted_proposal( 'Thanks, all done!' ) ) );
delete_option( OGM_GPDM_AI::PROPOSAL_KEY );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );


// ---- v1.11.0: GP's light surfaces darkened in the gp-chrome module output ----
$GLOBALS['wp_options']['generate_settings'] = array(
	'global_colors'           => array( array( 'slug' => 'base-3', 'color' => '#ffffff' ), array( 'slug' => 'brand', 'color' => '#0b2545' ) ),
	'header_background_color' => 'var(--base-3)',
	'footer_background_color' => 'var(--brand)',
);
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'modules' => array( 'gp-chrome' => 1 ) );
reset_settings_cache( $plugin );
$mods = $plugin->modules_css();
check( 'gp surfaces: the light header is darkened in the module output', false !== strpos( $mods, 'html[data-theme="dark"] .site-header' ) );
check( 'gp surfaces: the brand-colored footer is left alone', false === strpos( $mods, '.site-info {' ) );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'modules' => array( 'gp-chrome' => 0 ) );
reset_settings_cache( $plugin );
check( 'gp surfaces: none when the GeneratePress module is off', false === strpos( $plugin->modules_css(), '.site-header' ) );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'modules' => array( 'gp-chrome' => 1 ), 'enable_generated' => 1 );
update_option( OGM_GP_Dark_Mode::GEN_KEY, "html[data-theme=\"dark\"] {\n\t--base-3: #121212;\n}", true );
reset_settings_cache( $plugin );
check( 'gp surfaces: the enabled palette remaps base-3, so no override', false === strpos( $plugin->modules_css(), '.site-header' ) );
$GLOBALS['wp_options']['ogm_gpdm_settings']['enable_generated'] = 0;
reset_settings_cache( $plugin );
check( 'gp surfaces: palette stored but disabled → the override is back', false !== strpos( $plugin->modules_css(), '.site-header' ) );
delete_option( OGM_GP_Dark_Mode::GEN_KEY );
unset( $GLOBALS['wp_options']['generate_settings'] );


// ---- v1.11.1: review instructions ride Save Settings ----
update_option( 'ogm_gpdm_ai_instructions', 'Old instructions from the last review', false );
$san = $plugin->sanitize_settings( array( 'default_theme' => 'light', 'ai_instructions' => '' ) );
check( 'instructions: an emptied box clears them on Save Settings', '' === get_option( 'ogm_gpdm_ai_instructions' ) );
check( 'instructions: never stored in the settings array', ! array_key_exists( 'ai_instructions', $san ) );
$plugin->sanitize_settings( array( 'ai_instructions' => "  Fix the footer links.\nAnd the sidebar.  " ) );
check( 'instructions: new text saved (sanitized, multi-line kept)', "Fix the footer links.\nAnd the sidebar." === get_option( 'ogm_gpdm_ai_instructions' ) );
$plugin->sanitize_settings( array( 'default_theme' => 'dark' ) );
check( 'instructions: an update without the field leaves them alone', "Fix the footer links.\nAnd the sidebar." === get_option( 'ogm_gpdm_ai_instructions' ) );
$plugin->sanitize_settings( array( 'ai_instructions' => str_repeat( 'x', 5000 ) ) );
check( 'instructions: capped at 4,000 characters', 4000 === strlen( get_option( 'ogm_gpdm_ai_instructions' ) ) );
delete_option( 'ogm_gpdm_ai_instructions' );


// ---- v1.12.0: settings Export / Import ----
require_once dirname( __DIR__ ) . '/includes/class-ogm-gpdm-transfer.php';
$site_a = array(
	'default_theme' => 'dark', 'respect_system' => 1, 'show_toggle' => 1, 'accent_nav' => 1, 'enable_generated' => 1, 'enable_ai_css' => 1, 'enable_custom_css' => 1,
	'modules' => array( 'gp-chrome' => 1, 'revcontent' => 1, 'forminator' => 0 ),
	'ai_provider' => 'anthropic', 'ai_model' => 'claude-opus-5-5', 'ai_effort' => 'high', 'ai_mode' => 'background',
	'ai_api_key_anthropic' => 'sk-ant-SITE-A', 'ai_api_key_openai' => '', 'custom_css' => 'html[data-theme="dark"] .a-manual { color: #fff; }',
);
$GLOBALS['wp_options']['ogm_gpdm_settings'] = $site_a;
update_option( OGM_GP_Dark_Mode::GEN_KEY, "html[data-theme=\"dark\"] {\n\t--base-3: #121212;\n}", true );
update_option( OGM_GPDM_AI::AI_CSS_KEY, 'html[data-theme="dark"] .a-ai { color: #eee; }', true );
update_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, 'Site A instructions', false );
update_option( 'ogm_gpdm_ai_models', array( 'anthropic' => array( 'ids' => array( 'x' ) ) ) );
reset_settings_cache( $plugin );

$doc = OGM_GPDM_Transfer::build_export( $plugin );
check( 'export: identifies itself (plugin, format, version, site)', 'gp-dark-mode' === $doc['plugin'] && 1 === $doc['format'] && OGM_GPDM_VERSION === $doc['version'] && 'https://example.test/' === $doc['site'] );
check( 'export: no API keys by default', ! isset( $doc['settings']['ai_api_key_anthropic'] ) && ! isset( $doc['settings']['ai_api_key_openai'] ) && false === $doc['includes_api_keys'] && false === strpos( json_encode( $doc ), 'SITE-A' ) );
check( 'export: settings, modules and every layer included', 'dark' === $doc['settings']['default_theme'] && 1 === $doc['settings']['modules']['revcontent'] && false !== strpos( $doc['manual_css'], '.a-manual' ) && false !== strpos( $doc['generated_css'], '--base-3' ) && false !== strpos( $doc['ai_css'], '.a-ai' ) && 'Site A instructions' === $doc['ai_instructions'] );
check( 'export: Manual CSS travels as manual_css, not inside settings', ! isset( $doc['settings']['custom_css'] ) );
check( 'export: site-specific state left out', ! isset( $doc['ai_models'] ) && ! isset( $doc['audit'] ) && false === strpos( json_encode( $doc ), 'ai_models' ) );
$doc_keys = OGM_GPDM_Transfer::build_export( $plugin, true );
check( 'export: keys included only when asked', 'sk-ant-SITE-A' === $doc_keys['settings']['ai_api_key_anthropic'] && true === $doc_keys['includes_api_keys'] );
check( 'export: filename names the host', 0 === strpos( OGM_GPDM_Transfer::export_filename(), 'gp-dark-mode-settings-example.test-' ) );

// Parsing guards.
check( 'import: empty / not JSON / other plugin rejected', is_wp_error( OGM_GPDM_Transfer::parse( '' ) ) && is_wp_error( OGM_GPDM_Transfer::parse( 'nope' ) ) && is_wp_error( OGM_GPDM_Transfer::parse( '{"plugin":"other","format":1,"settings":{}}' ) ) );
check( 'import: unknown format and missing settings rejected', is_wp_error( OGM_GPDM_Transfer::parse( '{"plugin":"gp-dark-mode","format":9,"settings":{}}' ) ) && is_wp_error( OGM_GPDM_Transfer::parse( '{"plugin":"gp-dark-mode","format":1}' ) ) );
check( 'import: oversized file rejected', is_wp_error( OGM_GPDM_Transfer::parse( str_repeat( ' ', OGM_GPDM_Transfer::MAX_BYTES + 1 ) ) ) );
$sneaky = json_decode( json_encode( $doc ), true );
$sneaky['settings']['evil_key'] = 'x';
$sneaky['settings']['ai_api_key_anthropic'] = 'sk-smuggled';
$parsed = OGM_GPDM_Transfer::parse( json_encode( $sneaky ) );
check( 'import: unknown settings dropped; keys ignored unless the file says it includes them', ! isset( $parsed['settings']['evil_key'] ) && ! isset( $parsed['settings']['ai_api_key_anthropic'] ) );

// Site B: different everything, its own key.
$site_b = array( 'default_theme' => 'light', 'respect_system' => 0, 'accent_nav' => 0, 'enable_generated' => 0, 'modules' => array( 'gp-chrome' => 1, 'revcontent' => 0, 'forminator' => 1, 'contact-form-7' => 1 ), 'ai_provider' => 'openai', 'ai_effort' => '', 'ai_mode' => '', 'ai_api_key_anthropic' => 'sk-ant-SITE-B', 'ai_api_key_openai' => 'sk-oa-SITE-B', 'custom_css' => '.b-manual { color: red; }' );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = $site_b;
update_option( OGM_GP_Dark_Mode::GEN_KEY, '', true );
update_option( OGM_GPDM_AI::AI_CSS_KEY, '.b-ai {}', true );
update_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, 'Site B instructions', false );
delete_option( OGM_GPDM_Transfer::BACKUP_KEY );
reset_settings_cache( $plugin );
$b_before = $GLOBALS['wp_options']['ogm_gpdm_settings'];

$older = json_decode( json_encode( $doc ), true );
unset( $older['settings']['modules']['contact-form-7'] ); // As if exported by a version without that module.
$import = OGM_GPDM_Transfer::parse( json_encode( $older ) );
$diff   = OGM_GPDM_Transfer::diff( $plugin, $import );
$labels = array_map( function ( $r ) { return $r[0]; }, $diff['settings'] );
check( 'preview: lists the setting changes', in_array( 'Default theme', $labels, true ) && in_array( 'Review mode', $labels, true ) && in_array( 'AI provider', $labels, true ) );
check( 'preview: lists module changes, not unknown ones', 2 === count( $diff['modules'] ) );
check( 'preview: stylesheets compared, instructions flagged', 3 === count( $diff['css'] ) && true === $diff['instructions'] );
check( 'preview: says this site keeps its keys', (bool) array_filter( $diff['notes'], function ( $n ) { return false !== strpos( $n, 'keys stay as they are' ); } ) );
$html = OGM_GPDM_Transfer::preview_html( $plugin, $import );
check( 'preview: HTML names the source and escapes values', false !== strpos( $html, 'https://example.test/' ) && false !== strpos( $html, '<table' ) );
check( 'preview: writes nothing', $b_before === $GLOBALS['wp_options']['ogm_gpdm_settings'] && null === OGM_GPDM_Transfer::backup() );
$newer = $older; $newer['version'] = '99.0.0';
check( 'preview: warns about a newer exporting version', (bool) array_filter( OGM_GPDM_Transfer::diff( $plugin, OGM_GPDM_Transfer::parse( json_encode( $newer ) ) )['notes'], function ( $n ) { return false !== strpos( $n, 'newer version' ); } ) );

// Apply.
OGM_GPDM_Transfer::stash( $import );
check( 'stash: held for this user', is_array( OGM_GPDM_Transfer::stashed() ) );
OGM_GPDM_Transfer::apply( $plugin, $import );
$after = $plugin->settings();
check( 'apply: settings copied', 'dark' === $after['default_theme'] && 1 === $after['respect_system'] && 'background' === $after['ai_mode'] && 'claude-opus-5-5' === $after['ai_model'] && 'anthropic' === $after['ai_provider'] );
check( 'apply: this site\'s API keys kept', 'sk-ant-SITE-B' === $after['ai_api_key_anthropic'] && 'sk-oa-SITE-B' === $after['ai_api_key_openai'] );
check( 'apply: modules copied; one unknown to the file keeps this site\'s choice', 1 === $after['modules']['revcontent'] && 0 === $after['modules']['forminator'] && 1 === $after['modules']['contact-form-7'] );
check( 'apply: every layer copied', false !== strpos( $plugin->custom_css(), '.a-manual' ) && false !== strpos( $plugin->generated_css(), '--base-3' ) && false !== strpos( OGM_GPDM_AI::ai_css(), '.a-ai' ) && 'Site A instructions' === get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY ) );
check( 'apply: backup of the previous state kept', null !== OGM_GPDM_Transfer::backup() && 'sk-ant-SITE-B' === OGM_GPDM_Transfer::backup()['settings']['ai_api_key_anthropic'] && '.b-ai {}' === OGM_GPDM_Transfer::backup()['ai_css'] );

// Undo puts every bit back.
check( 'undo: succeeds', true === OGM_GPDM_Transfer::undo( $plugin ) );
$undone = $plugin->settings();
check( 'undo: settings, keys and modules restored', 'light' === $undone['default_theme'] && 'openai' === $undone['ai_provider'] && 'sk-ant-SITE-B' === $undone['ai_api_key_anthropic'] && 0 === $undone['modules']['revcontent'] && 1 === $undone['modules']['forminator'] );
check( 'undo: layers and instructions restored', '.b-manual { color: red; }' === $plugin->custom_css() && '' === $plugin->generated_css() && '.b-ai {}' === OGM_GPDM_AI::ai_css() && 'Site B instructions' === get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY ) );
check( 'undo: backup consumed; a second undo errors', null === OGM_GPDM_Transfer::backup() && is_wp_error( OGM_GPDM_Transfer::undo( $plugin ) ) );

// Keys included: they replace this site's; an empty one leaves this site's alone.
$with_keys = OGM_GPDM_Transfer::parse( json_encode( $doc_keys ) );
OGM_GPDM_Transfer::apply( $plugin, $with_keys );
check( 'apply with keys: the file\'s key replaces; an empty one doesn\'t erase', 'sk-ant-SITE-A' === $plugin->settings()['ai_api_key_anthropic'] && 'sk-oa-SITE-B' === $plugin->settings()['ai_api_key_openai'] );
OGM_GPDM_Transfer::undo( $plugin );
check( 'undo after a keyed import: this site\'s key back', 'sk-ant-SITE-B' === $plugin->settings()['ai_api_key_anthropic'] );

// Imported CSS is sanitized like any other layer.
$hostile = json_decode( json_encode( $doc ), true );
$hostile['ai_css'] = '.x{color:red}</style><script>alert(1)</script>';
OGM_GPDM_Transfer::apply( $plugin, OGM_GPDM_Transfer::parse( json_encode( $hostile ) ) );
check( 'apply: CSS sanitized (no </style breakout)', false === stripos( OGM_GPDM_AI::ai_css(), '</style' ) );
OGM_GPDM_Transfer::undo( $plugin );

// A site whose Manual CSS was never saved: undo must leave it empty, not keep the import's.
$GLOBALS['wp_options']['ogm_gpdm_settings'] = array( 'default_theme' => 'light', 'modules' => array( 'gp-chrome' => 1 ) );
reset_settings_cache( $plugin );
OGM_GPDM_Transfer::apply( $plugin, $import );
check( 'never-saved Manual CSS: the import fills it', false !== strpos( $plugin->custom_css(), '.a-manual' ) );
OGM_GPDM_Transfer::undo( $plugin );
check( 'never-saved Manual CSS: undo empties it again', '' === $plugin->custom_css() );
$GLOBALS['wp_options']['ogm_gpdm_settings'] = $b_before;
reset_settings_cache( $plugin );

// Round trip: export B, import into B → nothing changes.
$self = OGM_GPDM_Transfer::parse( json_encode( OGM_GPDM_Transfer::build_export( $plugin ) ) );
$d2   = OGM_GPDM_Transfer::diff( $plugin, $self );
check( 'round trip: importing a site\'s own export changes nothing', empty( $d2['settings'] ) && empty( $d2['modules'] ) && ! array_filter( array_column( $d2['css'], 3 ) ) && false === $d2['instructions'] );

delete_option( 'ogm_gpdm_ai_models' );
delete_option( OGM_GPDM_Transfer::BACKUP_KEY );
delete_option( OGM_GPDM_AI::INSTRUCTIONS_KEY );
delete_option( OGM_GPDM_AI::AI_CSS_KEY );
delete_option( OGM_GP_Dark_Mode::GEN_KEY );

echo "\n$pass passed, $fail failed\n";
echo 'Sample bundle: ' . strlen( $md ) . " bytes -> sample-bundle.md\n";
exit( $fail > 0 ? 1 : 0 );
