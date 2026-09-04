<?php
/**
 * Plugin Name:       Dark Mode for GeneratePress
 * Plugin URI:        https://gpdarkmode.com/
 * Description:       Robust, no-flash, accessible dark mode for the GeneratePress theme — with a menu-bar toggle, a behavior settings page, and a clean light/dark CSS-variable framework.
 * Version:           1.6.5
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Author:            Orchard Grove Media, LLC
 * Author URI:        https://orchardgrove.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gp-dark-mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OGM_GPDM_VERSION', '1.6.5' );
define( 'OGM_GPDM_FILE', __FILE__ );
define( 'OGM_GPDM_DIR', plugin_dir_path( __FILE__ ) );
define( 'OGM_GPDM_URL', plugin_dir_url( __FILE__ ) );
define( 'OGM_GPDM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * GP Dark Mode.
 *
 * Outputs an early (no-flash) theme-setting script in <head>, an accessible
 * toggle in the GeneratePress menu bar (or via shortcode), the light/dark CSS
 * framework, and an OGM-styled behavior settings page.
 */
final class OGM_GP_Dark_Mode {

	/** Option key holding the settings array. */
	const OPT_KEY = 'ogm_gpdm_settings';

	/**
	 * Option key holding the Layer-2 generated palette CSS. Deliberately a
	 * SEPARATE, unregistered option: writing it through OPT_KEY would route
	 * update_option() through the register_setting() sanitize callback, which
	 * treats the value as raw form input — discarding the new CSS and
	 * corrupting keys absent from "the form" (custom_css, modules).
	 */
	const GEN_KEY = 'ogm_gpdm_generated_css';

	/**
	 * Rendered in the API-key field when a key is saved (shows as asterisks
	 * in the password input). Submitting it unchanged keeps the stored key.
	 */
	const KEY_MASK = '************';

	/** Admin page slug (Settings → GP Dark Mode). */
	const PAGE_SLUG = 'ogm-gp-dark-mode';

	/** @var OGM_GP_Dark_Mode|null */
	private static $instance = null;

	/** @var array|null Cached, parsed settings. */
	private $settings = null;

	/** Singleton accessor. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/** Wire up hooks. */
	public function boot() {
		// Boot runs on plugins_loaded — migrate pre-1.2.0 options here, before
		// admin_init can register the settings sanitizer.
		$this->maybe_upgrade();

		// Front end.
		add_action( 'wp_head', array( $this, 'print_head_script' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ), 20 );
		add_action( 'generate_menu_bar_items', array( $this, 'render_menu_bar_toggle' ), 20 );
		add_shortcode( 'gp_dark_mode_toggle', array( $this, 'shortcode_toggle' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_generatepress_notice' ) );
		add_action( 'admin_post_ogm_gpdm_bundle', array( $this, 'handle_bundle_download' ) );
		add_action( 'admin_post_ogm_gpdm_generate', array( $this, 'handle_palette_generate' ) );
		add_action( 'admin_post_ogm_gpdm_ai_apply', array( $this, 'handle_ai_apply' ) );
		add_action( 'admin_post_ogm_gpdm_ai_discard', array( $this, 'handle_ai_discard' ) );
		add_action( 'admin_post_ogm_gpdm_ai_restore', array( $this, 'handle_ai_restore' ) );
		add_action( 'admin_post_ogm_gpdm_reset', array( $this, 'handle_reset' ) );
		add_action( 'wp_ajax_ogm_gpdm_refresh_models', array( $this, 'handle_refresh_models' ) );
		add_action( 'wp_ajax_ogm_gpdm_ai_start', array( $this, 'handle_ai_start' ) );
		add_action( 'wp_ajax_ogm_gpdm_ai_status', array( $this, 'handle_ai_status' ) );
		add_action( 'ogm_gpdm_run_ai_review', array( $this, 'run_ai_review_cron' ) );
		add_filter( 'plugin_action_links_' . OGM_GPDM_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Deactivation cleanup: drop the pending review event and its 'running'
	 * status — otherwise reactivating within 15 minutes shows a phantom
	 * "review is running" lockout that nothing can complete.
	 */
	public static function deactivate() {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'ogm_gpdm_run_ai_review' );
		}
		delete_option( 'ogm_gpdm_ai_status' );
	}

	/**
	 * Seed default options on activation. Fresh installs get auto-detectable
	 * integration modules switched on; upgrades keep whatever is saved.
	 */
	public static function activate() {
		if ( false === get_option( self::OPT_KEY, false ) ) {
			$defaults = self::defaults();
			foreach ( self::modules() as $slug => $module ) {
				if ( null !== $module['detect'] && self::module_detected( $slug ) ) {
					$defaults['modules'][ $slug ] = 1;
				}
			}
			add_option( self::OPT_KEY, $defaults );
		}
	}

	/* ---------------------------------------------------------------------
	 * Layer 3: integration modules
	 * ------------------------------------------------------------------- */

	/**
	 * Module registry. Each module is one CSS file of dark-mode rules for a
	 * vendor/integration's STABLE generic selectors, gated by a checkbox.
	 * 'detect' names an auto-detection (used to pre-check on fresh installs
	 * and to show a "Detected" badge); null = manual only (script-injected
	 * vendors are invisible server-side).
	 */
	public static function modules() {
		return array(
			'gp-chrome'   => array(
				'label'  => __( 'GeneratePress chrome (navigation, sidebar, embeds)', 'gp-dark-mode' ),
				'file'   => 'gp-chrome.css',
				'detect' => 'gp',
			),
			'revcontent'  => array(
				'label'  => __( 'RevContent / sbn ad widgets', 'gp-dark-mode' ),
				'file'   => 'revcontent.css',
				'detect' => null,
			),
			'ad-inserter' => array(
				'label'  => __( 'Ad Inserter wrappers', 'gp-dark-mode' ),
				'file'   => 'ad-inserter.css',
				'detect' => 'ad-inserter',
			),
			'givewp'      => array(
				'label'  => __( 'GiveWP donations', 'gp-dark-mode' ),
				'file'   => 'givewp.css',
				'detect' => 'givewp',
			),
			'forminator'  => array(
				'label'  => __( 'Forminator forms', 'gp-dark-mode' ),
				'file'   => 'forminator.css',
				'detect' => 'forminator',
			),
			'core-blocks' => array(
				'label'  => __( 'WordPress core content (tables, code, quotes, separators)', 'gp-dark-mode' ),
				'file'   => 'core-blocks.css',
				'detect' => 'core',
			),
			'adsense'     => array(
				'label'  => __( 'Google AdSense wrappers', 'gp-dark-mode' ),
				'file'   => 'adsense.css',
				'detect' => null,
			),
			'wp-popular-posts' => array(
				'label'  => __( 'WordPress Popular Posts widgets', 'gp-dark-mode' ),
				'file'   => 'wp-popular-posts.css',
				'detect' => 'wpp',
			),
			'rumble'      => array(
				'label'  => __( 'Rumble ad iframes (light backing plate)', 'gp-dark-mode' ),
				'file'   => 'rumble.css',
				'detect' => null,
			),
			'contact-form-7' => array(
				'label'  => __( 'Contact Form 7', 'gp-dark-mode' ),
				'file'   => 'contact-form-7.css',
				'detect' => 'cf7',
			),
		);
	}

	/** Is a module's integration present on this site? */
	public static function module_detected( $slug ) {
		switch ( $slug ) {
			case 'gp-chrome':
			case 'gp':
				return function_exists( 'generate_get_option' ) || 'generatepress' === strtolower( (string) get_template() );
			case 'ad-inserter':
				return defined( 'AD_INSERTER_VERSION' ) || function_exists( 'ai_content_hook' );
			case 'givewp':
				return class_exists( 'Give' ) || defined( 'GIVE_VERSION' );
			case 'forminator':
				return class_exists( 'Forminator' ) || defined( 'FORMINATOR_VERSION' );
			case 'core-blocks':
				return true; // WordPress core — always present.
			case 'wp-popular-posts':
				return function_exists( 'wpp_get_mostpopular' ) || class_exists( 'WordPressPopularPosts\\Plugin' );
			case 'contact-form-7':
				return class_exists( 'WPCF7' ) || defined( 'WPCF7_VERSION' );
		}
		return false;
	}

	/** Concatenated CSS of all enabled modules. */
	public function modules_css() {
		$enabled = $this->get( 'modules' );
		if ( ! is_array( $enabled ) ) {
			return '';
		}

		$css = '';
		foreach ( self::modules() as $slug => $module ) {
			if ( empty( $enabled[ $slug ] ) ) {
				continue;
			}
			$file = OGM_GPDM_DIR . 'assets/css/modules/' . $module['file'];
			if ( is_readable( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin asset.
				$css .= "\n/* ===== GP Dark Mode module: " . $slug . " ===== */\n" . file_get_contents( $file );
			}
		}
		return $css;
	}

	/** The saved API key for ONE provider ('' when none). */
	public function ai_key_for( $provider ) {
		$field = 'ai_api_key_' . ( 'openai' === $provider ? 'openai' : 'anthropic' );
		$s     = $this->settings();
		return isset( $s[ $field ] ) && is_string( $s[ $field ] ) ? trim( $s[ $field ] ) : '';
	}

	/** The stored Layer-2 generated palette CSS ('' when never generated). */
	public function generated_css() {
		$css = get_option( self::GEN_KEY, '' );
		return is_string( $css ) ? $css : '';
	}

	/**
	 * One-time migration for options written before 1.2.0 (no 'modules' key).
	 * Runs on plugins_loaded — BEFORE admin_init registers the settings
	 * sanitizer, so update_option() here stores the array untouched.
	 *
	 * The invariant: upgrading must render pixel-identically.
	 * - Never-saved installs (no 'custom_css' key) were outputting the full
	 *   old bundled seed; the five decomposition modules go on. (Since 1.6.0
	 *   the plugin ships no Manual-CSS default, so such installs lose the
	 *   slim residue slice — its rules were reference-site-specific and no
	 *   such install exists outside the reference site, whose own deploy
	 *   pastes the residue into Manual. tests/coverage.php still proves the
	 *   decomposition against tests/fixtures/theme-manual-css.css.)
	 * - Saved installs already carry the complete old rules (with any edits
	 *   or deletions) in their Additional CSS, so ALL modules stay off —
	 *   turning any on (even gp-chrome) could resurrect rules the admin
	 *   deliberately removed, or fight era-specific edits.
	 */
	public function maybe_upgrade() {
		$opts = get_option( self::OPT_KEY, false );
		if ( ! is_array( $opts ) ) {
			return;
		}
		$changed = false;

		if ( ! array_key_exists( 'modules', $opts ) ) {
			// Pixel-identity invariant: never-saved installs get exactly the
			// FIVE modules the old seed decomposed into (their union equals
			// the old seed — tests/coverage.php). Modules added in later
			// versions stay off: they carry rules the old seed never had.
			$seed_decomposition = array( 'gp-chrome', 'revcontent', 'ad-inserter', 'givewp', 'forminator' );

			$all_on  = ! array_key_exists( 'custom_css', $opts );
			$modules = array();
			foreach ( self::modules() as $slug => $module ) {
				$modules[ $slug ] = ( $all_on && in_array( $slug, $seed_decomposition, true ) ) ? 1 : 0;
			}
			$opts['modules'] = $modules;
			$changed         = true;
		}

		// 1.5.0: the AI stylesheet became its own layer (4a). Existing
		// custom_css stays as the owner's Manual layer (4b) untouched; stale
		// 1.4-era proposal/backup carried old-semantics content (they
		// targeted custom_css), so they are cleared for a clean start.
		if ( ! array_key_exists( 'enable_ai_css', $opts ) ) {
			$opts['enable_ai_css'] = 1;
			delete_option( 'ogm_gpdm_ai_proposal' );
			delete_option( 'ogm_gpdm_ai_backup' );
			$changed = true;
		}

		// 1.4.2: the single ai_api_key became per-provider keys (a saved key
		// must never be sent to the OTHER provider). Assign the legacy key to
		// the provider it was saved for.
		if ( array_key_exists( 'ai_api_key', $opts ) ) {
			$legacy = is_string( $opts['ai_api_key'] ) ? $opts['ai_api_key'] : '';
			if ( '' !== $legacy ) {
				$provider = ( isset( $opts['ai_provider'] ) && 'openai' === $opts['ai_provider'] ) ? 'openai' : 'anthropic';
				$field    = 'ai_api_key_' . $provider;
				if ( empty( $opts[ $field ] ) ) {
					$opts[ $field ] = $legacy;
				}
			}
			unset( $opts['ai_api_key'] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPT_KEY, $opts );
			$this->settings = null; // Drop any cached parse.
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/** Default settings. */
	public static function defaults() {
		return array(
			'default_theme'       => 'light', // 'light' | 'dark'.
			'respect_system'      => 0,        // Follow prefers-color-scheme for first-time visitors.
			'show_toggle'         => 1,        // Render the menu-bar toggle.
			'enable_custom_css'   => 1,        // Output the Manual CSS (Layer 4b) on the front end.
			'enable_ai_css'       => 1,        // Output the AI stylesheet (Layer 4a) on the front end.
			'accent_nav'          => 0,        // Recolor the dark-mode nav with the accent.
			'enable_generated'    => 0,        // Output the Layer-2 generated palette (opt-in: review first).
			'modules'             => array(    // Layer-3 modules. Vendor modules default off; fresh
				'gp-chrome'        => 1,       // installs flip detected integrations on in activate().
				'revcontent'       => 0,       // Modules added in later versions default 0 so an
				'ad-inserter'      => 0,       // upgrade never changes rendering — enabling one is
				'givewp'           => 0,       // always a deliberate choice.
				'forminator'       => 0,
				'core-blocks'      => 0,
				'adsense'          => 0,
				'wp-popular-posts' => 0,
				'contact-form-7'   => 0,
			),
			'ai_provider'         => 'anthropic', // BYOK AI assist (Layer 4 review).
			'ai_api_key_anthropic' => '',      // Keys are stored PER PROVIDER so a
			'ai_api_key_openai'    => '',      // saved key is never sent to the other one.
			'ai_model'            => '',       // Empty = the provider's default model.
		);
	}

	/** Parsed settings (option merged over defaults), cached per request. */
	public function settings() {
		if ( null === $this->settings ) {
			$opts           = get_option( self::OPT_KEY, array() );
			$defaults       = self::defaults();
			$this->settings = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

			// wp_parse_args is shallow — merge the modules map so options saved
			// before a module existed still pick up its default.
			$saved_modules             = is_array( $this->settings['modules'] ) ? $this->settings['modules'] : array();
			$this->settings['modules'] = wp_parse_args( $saved_modules, $defaults['modules'] );
		}
		return $this->settings;
	}

	/** Single setting value. */
	public function get( $key ) {
		$s = $this->settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/** Validated default theme ('light' | 'dark'). */
	public function default_theme() {
		return ( 'dark' === $this->get( 'default_theme' ) ) ? 'dark' : 'light';
	}

	/**
	 * The current Additional CSS.
	 *
	 * Returns the saved value, or '' when nothing was ever saved. Since 1.6.0
	 * the plugin ships NO site-specific CSS: Manual starts empty and only the
	 * site owner writes to it. (The reference site's old residue lives on in
	 * tests/fixtures/theme-manual-css.css, outside the ZIP.)
	 */
	public function custom_css() {
		$opts = get_option( self::OPT_KEY, array() );
		if ( is_array( $opts ) && array_key_exists( 'custom_css', $opts ) && is_string( $opts['custom_css'] ) ) {
			return $opts['custom_css'];
		}
		return '';
	}

	/** Contents of the core framework stylesheet (inlined onto generate-style on GP). */
	public static function framework_css() {
		static $css = null;
		if ( null === $css ) {
			$file = OGM_GPDM_DIR . 'assets/css/gp-dark-mode.css';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset.
			$css = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		}
		return $css;
	}

	public function register_settings() {
		register_setting(
			self::OPT_KEY,
			self::OPT_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/** Sanitize the settings array on save. */
	public function sanitize_settings( $input ) {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$theme                = isset( $in['default_theme'] ) ? sanitize_key( (string) $in['default_theme'] ) : $d['default_theme'];
		$out['default_theme'] = in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : $d['default_theme'];

		$out['respect_system']    = empty( $in['respect_system'] ) ? 0 : 1;
		$out['show_toggle']       = empty( $in['show_toggle'] ) ? 0 : 1;
		$out['enable_custom_css'] = empty( $in['enable_custom_css'] ) ? 0 : 1;
		$out['enable_ai_css']     = empty( $in['enable_ai_css'] ) ? 0 : 1;
		$out['accent_nav']        = empty( $in['accent_nav'] ) ? 0 : 1;
		$out['enable_generated']  = empty( $in['enable_generated'] ) ? 0 : 1;

		$out['modules'] = array();
		foreach ( self::modules() as $slug => $module ) {
			$out['modules'][ $slug ] = empty( $in['modules'][ $slug ] ) ? 0 : 1;
		}

		$provider           = isset( $in['ai_provider'] ) ? sanitize_key( (string) $in['ai_provider'] ) : $d['ai_provider'];
		$out['ai_provider'] = in_array( $provider, array( 'anthropic', 'openai' ), true ) ? $provider : $d['ai_provider'];
		$out['ai_model']    = isset( $in['ai_model'] ) ? sanitize_text_field( (string) $in['ai_model'] ) : '';

		// Per-provider key fields. Each renders the mask when a key is saved;
		// submitting the mask unchanged (or an empty field) keeps the stored
		// key. The literal "clear" removes it.
		$current = get_option( self::OPT_KEY, array() );
		foreach ( array( 'ai_api_key_anthropic', 'ai_api_key_openai' ) as $key_field ) {
			$key_in = isset( $in[ $key_field ] ) ? trim( sanitize_text_field( (string) $in[ $key_field ] ) ) : '';
			if ( 'clear' === strtolower( $key_in ) ) {
				$out[ $key_field ] = '';
			} elseif ( '' !== $key_in && self::KEY_MASK !== $key_in ) {
				$out[ $key_field ] = $key_in;
			} else {
				$out[ $key_field ] = ( is_array( $current ) && isset( $current[ $key_field ] ) && is_string( $current[ $key_field ] ) )
					? $current[ $key_field ]
					: '';
			}
		}

		// Absent from input means "not submitted" — a programmatic
		// update_option/add_option re-entering this sanitizer (activation
		// seeding, migrations). PRESERVE the stored state instead of
		// materializing '': an empty save is meaningful (it retires the
		// bundled seed fallback), so it may only happen when the field was
		// actually posted. When neither input nor store has the key, leave
		// it ABSENT so the fallback stays alive on fresh installs.
		if ( isset( $in['custom_css'] ) ) {
			$out['custom_css'] = self::sanitize_css( $in['custom_css'] );
		} elseif ( is_array( $current ) && array_key_exists( 'custom_css', $current ) && is_string( $current['custom_css'] ) ) {
			$out['custom_css'] = $current['custom_css'];
		}

		return $out;
	}

	/**
	 * Sanitize the Additional CSS.
	 *
	 * Strips anything that could break out of the <style> element it's printed
	 * into (HTML tags, stray </style>/<script>), while leaving real CSS — child
	 * combinators (>), attribute selectors, etc. — untouched. Only admins
	 * (manage_options) can reach this field.
	 */
	public static function sanitize_css( $css ) {
		$css = (string) $css;
		// The value is only ever printed inside a <style> element (and re-shown
		// in the admin via esc_textarea). A <style>/<script> raw-text region can
		// only be closed by a contiguous "</style"/"</script", so stripping those
		// sequences (case-insensitive) blocks any breakout while leaving real CSS
		// — comments, content strings, child combinators (>), [attr] — intact.
		$css = str_ireplace( array( '</style', '<style', '</script', '<script' ), '', $css );
		return trim( $css );
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	/**
	 * Early theme set — prevents a flash of the wrong theme.
	 *
	 * Printed inline at the very top of <head> (priority 0) so the correct
	 * data-theme is on <html> before first paint. Config values are a fixed
	 * enum / boolean, JSON-encoded with tag/amp hex escaping for safety.
	 */
	public function print_head_script() {
		$cfg = wp_json_encode(
			array(
				'defaultTheme'  => $this->default_theme(),
				'respectSystem' => (bool) $this->get( 'respect_system' ),
				'accentNav'     => (bool) $this->get( 'accent_nav' ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP
		);
		if ( false === $cfg ) {
			$cfg = '{}';
		}
		?>
<script id="ogm-gpdm-head">
(function () {
  var d = document.documentElement;
  d.classList.add('theme-init');

  var cfg = <?php echo $cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode of a fixed enum/bool. ?> || {};
  window.ogmGpdm = cfg;

  var defaultTheme  = cfg.defaultTheme === 'dark' ? 'dark' : 'light';
  var respectSystem = !!cfg.respectSystem;

  function getCookie(name) {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : null;
    } catch (e) { return null; }
  }

  var saved = null;
  try { saved = localStorage.getItem('theme'); } catch (e) {}
  if (saved !== 'dark' && saved !== 'light') { saved = getCookie('theme'); }

  var theme;
  if (saved === 'dark' || saved === 'light') {
    theme = saved;                                   // Explicit visitor choice always wins.
  } else if (respectSystem && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    theme = 'dark';
  } else if (respectSystem && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
    theme = 'light';
  } else {
    theme = defaultTheme;                            // No-preference systems fall back to the configured default.
  }

  d.setAttribute('data-theme', theme);

  if (cfg.accentNav) { d.classList.add('gpdm-accent-nav'); }

  // Lift the transition-suppressing class once after first paint. Owned here
  // (not in the toggle script) so it still clears when the toggle is hidden.
  function clearInit() {
    if (window.requestAnimationFrame) {
      requestAnimationFrame(function () { d.classList.remove('theme-init'); });
    } else {
      d.classList.remove('theme-init');
    }
  }
  if (document.readyState !== 'loading') { clearInit(); }
  else { document.addEventListener('DOMContentLoaded', clearInit); }
})();
</script>
		<?php
	}

	/**
	 * Enqueue the front-end CSS (all four layers, in cascade order) and the
	 * toggle script. Layer order: 1 framework (mechanics + tokens) →
	 * 2 generated palette (opt-in) → 3 enabled modules → 4 Additional CSS
	 * (site residue, editable) — later layers override earlier ones.
	 */
	public function enqueue_front_assets() {
		$extra = '';

		// Layer 2: generated palette (opt-in).
		if ( $this->get( 'enable_generated' ) ) {
			$generated = self::sanitize_css( $this->generated_css() );
			if ( '' !== $generated ) {
				$extra .= "\n" . $generated;
			}
		}

		// Layer 3: enabled integration modules.
		$modules = $this->modules_css();
		if ( '' !== $modules ) {
			$extra .= "\n" . $modules;
		}

		// Layer 4a: the AI stylesheet (machine-owned).
		if ( $this->get( 'enable_ai_css' ) ) {
			require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
			$ai_css = self::sanitize_css( OGM_GPDM_AI::ai_css() );
			if ( '' !== $ai_css ) {
				$extra .= "\n" . $ai_css;
			}
		}

		// Layer 4b: Manual CSS (the owner's editable field) prints last, so
		// it overrides everything. Sanitize on output too, so a stale stored
		// value can never emit a stray </style.
		if ( $this->get( 'enable_custom_css' ) ) {
			$additional = self::sanitize_css( $this->custom_css() );
			if ( '' !== $additional ) {
				$extra .= "\n" . $additional;
			}
		}

		$css = self::framework_css() . $extra;

		if ( wp_style_is( 'generate-style', 'enqueued' ) ) {
			// Same cascade slot as the original child-theme implementation:
			// inline on GeneratePress's parent stylesheet, attached at
			// wp_enqueue_scripts priority 20 — which prints BEFORE GP's
			// customizer dynamic CSS (attached to the same handle at priority
			// 50) and before the child theme's style.css. The site's own
			// styles keep winning specificity ties, exactly as on the
			// reference site. Enqueueing this as a late standalone sheet
			// instead flips those ties (link colors, .inside-article
			// backgrounds, form fields).
			if ( '' !== $css ) {
				wp_add_inline_style( 'generate-style', $css );
			}
		} else {
			// Non-GP fallback (a plugin extension — the original printed
			// nothing off GeneratePress): load the framework as its own
			// stylesheet, the remaining layers inline after it.
			wp_enqueue_style(
				'gp-dark-mode-core',
				OGM_GPDM_URL . 'assets/css/gp-dark-mode.css',
				array(),
				OGM_GPDM_VERSION
			);
			if ( '' !== trim( $extra ) ) {
				wp_add_inline_style( 'gp-dark-mode-core', trim( $extra ) );
			}
		}

		// Register always so the shortcode can enqueue it by handle; enqueue here
		// for the menu-bar toggle when it's enabled.
		wp_register_script(
			'gp-dark-mode-toggle',
			OGM_GPDM_URL . 'assets/js/dark-mode-toggle.js',
			array(),
			OGM_GPDM_VERSION,
			true
		);
		if ( $this->get( 'show_toggle' ) ) {
			wp_enqueue_script( 'gp-dark-mode-toggle' );
		}
	}

	/**
	 * The accessible toggle button markup.
	 *
	 * Initial aria-checked reflects the server-side default; the toggle script
	 * corrects it on load to match the visitor's saved/system preference.
	 */
	public function toggle_markup() {
		$checked = ( 'dark' === $this->default_theme() ) ? 'true' : 'false';
		$label   = esc_attr__( 'Toggle dark mode', 'gp-dark-mode' );

		// Inline SVG icons, NOT text glyphs: the Unicode sun/moon codepoints
		// render with whatever font each site's stack resolves them to — a
		// solid crescent on one site, a hollow outline on another. SVG with
		// currentColor is identical everywhere.
		$sun_svg  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false">'
			. '<circle cx="12" cy="12" r="4.5" fill="currentColor" stroke="none"/>'
			. '<path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>'
			. '</svg>';
		$moon_svg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" focusable="false">'
			. '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>'
			. '</svg>';

		$html  = '<button id="dark-mode-toggle" class="dm-toggle" type="button" role="switch"';
		$html .= ' aria-label="' . $label . '" aria-checked="' . esc_attr( $checked ) . '">';
		$html .= '<span class="dm-track" aria-hidden="true">';
		$html .= '<span class="dm-icon dm-sun" aria-hidden="true">' . $sun_svg . '</span>';
		$html .= '<span class="dm-icon dm-moon" aria-hidden="true">' . $moon_svg . '</span>';
		$html .= '<span class="dm-knob" aria-hidden="true"></span>';
		$html .= '</span></button>';

		/**
		 * Filter the toggle button markup.
		 *
		 * @param string             $html   Button HTML.
		 * @param OGM_GP_Dark_Mode   $plugin Plugin instance.
		 */
		return apply_filters( 'ogm_gpdm_toggle_markup', $html, $this );
	}

	/** Render the toggle in the GeneratePress menu bar. */
	public function render_menu_bar_toggle() {
		if ( ! $this->get( 'show_toggle' ) ) {
			return;
		}
		echo $this->toggle_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/** [gp_dark_mode_toggle] — a placement fallback when the menu bar isn't used. */
	public function shortcode_toggle( $atts = array() ) {
		// The button needs its behavior script. It's registered in
		// enqueue_front_assets(); register defensively in case that didn't run.
		if ( ! wp_script_is( 'gp-dark-mode-toggle', 'registered' ) && ! wp_script_is( 'gp-dark-mode-toggle', 'enqueued' ) ) {
			wp_register_script(
				'gp-dark-mode-toggle',
				OGM_GPDM_URL . 'assets/js/dark-mode-toggle.js',
				array(),
				OGM_GPDM_VERSION,
				true
			);
		}
		wp_enqueue_script( 'gp-dark-mode-toggle' );

		// If the footer scripts already printed (shortcode rendered very late),
		// enqueueing is too late — emit it inline now so the button works.
		if ( did_action( 'wp_print_footer_scripts' ) && ! wp_script_is( 'gp-dark-mode-toggle', 'done' ) ) {
			wp_print_scripts( 'gp-dark-mode-toggle' );
		}

		return $this->toggle_markup();
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	public function admin_menu() {
		add_options_page(
			__( 'GP Dark Mode', 'gp-dark-mode' ),
			__( 'GP Dark Mode', 'gp-dark-mode' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function action_links( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'gp-dark-mode' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'ogm-gpdm-admin',
			OGM_GPDM_URL . 'assets/admin/gp-dark-mode-admin.css',
			array( 'dashicons' ),
			OGM_GPDM_VERSION
		);

		// Settings-page behavior (model dropdown refresh + provider switch).
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		$s = $this->settings();

		wp_enqueue_script(
			'ogm-gpdm-admin',
			OGM_GPDM_URL . 'assets/admin/gp-dark-mode-admin.js',
			array( 'jquery' ),
			OGM_GPDM_VERSION,
			true
		);
		wp_localize_script(
			'ogm-gpdm-admin',
			'OGM_GPDM',
			array(
				'refresh_nonce' => wp_create_nonce( 'ogm_gpdm_refresh_models' ),
				'ai_nonce'      => wp_create_nonce( 'ogm_gpdm_ai_start' ),
				'ai_state'      => OGM_GPDM_AI::polled_status()['state'],
				'choices'       => array(
					'anthropic' => OGM_GPDM_AI::model_choices( 'anthropic', 'anthropic' === $s['ai_provider'] ? $s['ai_model'] : '' ),
					'openai'    => OGM_GPDM_AI::model_choices( 'openai', 'openai' === $s['ai_provider'] ? $s['ai_model'] : '' ),
				),
				'defaults'      => array(
					'anthropic' => OGM_GPDM_AI::default_model( 'anthropic' ),
					'openai'    => OGM_GPDM_AI::default_model( 'openai' ),
				),
				'i18n'          => array(
					'providerDefault' => __( 'Provider default', 'gp-dark-mode' ),
					'refreshing'      => __( 'Refreshing…', 'gp-dark-mode' ),
				),
			)
		);

		// CodeMirror CSS editor for the Additional CSS field. Returns false if
		// the user disabled syntax highlighting in their profile — in which case
		// the field gracefully stays a plain textarea.
		$editor = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
		if ( false !== $editor ) {
			// Initialize the editor and, when the user drags it taller (CSS
			// resize handle), refresh CodeMirror so its text area fills the new
			// height.
			wp_add_inline_script(
				'code-editor',
				'jQuery(function(){'
					. 'if(!(window.wp&&wp.codeEditor)){return;}'
					. 'var ed=wp.codeEditor.initialize("ogm-gpdm-custom-css",' . wp_json_encode( $editor ) . ');'
					. 'var cm=ed&&ed.codemirror;'
					. 'if(cm&&window.ResizeObserver){new ResizeObserver(function(){cm.refresh();}).observe(cm.getWrapperElement());}'
				. '});'
			);
		}
	}

	/** True if GeneratePress (or a GeneratePress child theme) is active. */
	public function is_generatepress() {
		return function_exists( 'generate_get_option' ) || 'generatepress' === strtolower( (string) get_template() );
	}

	/** Nudge on our settings page (and the Plugins screen) if GP isn't active. */
	public function maybe_generatepress_notice() {
		if ( $this->is_generatepress() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$on_our_page = ( 'settings_page_' . self::PAGE_SLUG === $screen->id );
		$on_plugins  = ( 'plugins' === $screen->id );
		if ( ! $on_our_page && ! $on_plugins ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'GP Dark Mode is built for the GeneratePress theme. Dark styling still applies, but the menu-bar toggle relies on GeneratePress — use the [gp_dark_mode_toggle] shortcode to place the toggle on other themes.', 'gp-dark-mode' );
		echo '</p></div>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}

		$s = $this->settings();

		echo '<div class="wrap ogm-gpdm-screen">';
		$this->render_header();
		settings_errors();

		if ( isset( $_GET['gpdm-generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice after the nonce-checked redirect.
			if ( 'empty' === $_GET['gpdm-generated'] ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No usable GeneratePress Global Colors found — nothing was generated.', 'gp-dark-mode' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Dark palette generated. Review it below, then tick “Output the generated palette” and save.', 'gp-dark-mode' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['gpdm-ai'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice after the nonce-checked redirect.
			// Fixed codes only — free text in the query string trips WAFs.
			$ai_notices = array(
				'applied'     => array( 'notice-success', __( 'Proposal applied as the AI stylesheet. The previous AI stylesheet is backed up — use the swap button to undo. Your Manual CSS was not touched.', 'gp-dark-mode' ) ),
				'discarded'   => array( 'notice-success', __( 'Proposal discarded.', 'gp-dark-mode' ) ),
				'restored'    => array( 'notice-success', __( 'Backup swapped in as the AI stylesheet.', 'gp-dark-mode' ) ),
				'no-proposal' => array( 'notice-error', __( 'No proposal to apply.', 'gp-dark-mode' ) ),
				'no-backup'   => array( 'notice-error', __( 'No backup to swap in.', 'gp-dark-mode' ) ),
				'reset'       => array( 'notice-success', __( 'All settings were reset to factory defaults. Auto-detected modules were re-enabled, and the Manual CSS is back on the bundled defaults.', 'gp-dark-mode' ) ),
			);
			$ai_code    = sanitize_key( (string) wp_unslash( $_GET['gpdm-ai'] ) );
			if ( isset( $ai_notices[ $ai_code ] ) ) {
				echo '<div class="notice ' . esc_attr( $ai_notices[ $ai_code ][0] ) . ' is-dismissible"><p>' . esc_html( $ai_notices[ $ai_code ][1] ) . '</p></div>';
			}
		}

		echo '<div class="ogm-gpdm-layout">';

		// Main column: one tab per stylesheet layer (cascade order) plus
		// Settings. All panels live inside ONE form — hidden tabs still
		// submit, so the single Save button covers every tab.
		echo '<div class="ogm-gpdm-main"><div class="ogm-gpdm-card">';
		echo '<p class="ogm-gpdm-intro">' . esc_html__( 'One tab per dark-mode stylesheet, in the order they load — later tabs override earlier ones. Behavior, modules, and AI configuration live under Settings.', 'gp-dark-mode' ) . '</p>';

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$tabs = array(
			'shipped'   => __( 'Shipped', 'gp-dark-mode' ),
			'generated' => __( 'Generated', 'gp-dark-mode' ),
			'ai'        => __( 'AI', 'gp-dark-mode' ),
			'manual'    => __( 'Manual', 'gp-dark-mode' ),
			'settings'  => __( 'Settings', 'gp-dark-mode' ),
		);
		echo '<h2 class="nav-tab-wrapper ogm-gpdm-tabs">';
		$first = true;
		foreach ( $tabs as $tab_slug => $tab_label ) {
			echo '<a href="#' . esc_attr( $tab_slug ) . '" class="nav-tab' . ( $first ? ' nav-tab-active' : '' ) . '" data-tab="' . esc_attr( $tab_slug ) . '">' . esc_html( $tab_label ) . '</a>';
			$first = false;
		}
		echo '</h2>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPT_KEY );

		echo '<div class="ogm-gpdm-tab is-active" data-tab="shipped">';
		$this->render_tab_shipped( $s );
		echo '</div>';

		echo '<div class="ogm-gpdm-tab" data-tab="generated">';
		$this->render_tab_generated( $s );
		echo '</div>';

		echo '<div class="ogm-gpdm-tab" data-tab="ai">';
		$this->render_tab_ai( $s );
		echo '</div>';

		echo '<div class="ogm-gpdm-tab" data-tab="manual">';
		$this->render_tab_manual( $s );
		echo '</div>';

		echo '<div class="ogm-gpdm-tab" data-tab="settings">';
		$this->render_tab_settings( $s );
		echo '</div>';

		submit_button( __( 'Save Settings', 'gp-dark-mode' ) );
		echo '</form>';
		echo '</div>'; // .ogm-gpdm-card.
		echo '</div>'; // .ogm-gpdm-main.

		// Sidebar.
		echo '<aside class="ogm-gpdm-sidebar">';
		$this->render_sidebar( $s );
		echo '</aside>';

		echo '</div>'; // .ogm-gpdm-layout.
		echo '</div>'; // .wrap.
	}

	/** Tab: read-only view of the plugin-shipped CSS (framework + modules). */
	private function render_tab_shipped( $s ) {
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Shipped styles', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'What the plugin itself provides: the core framework (toggle, tokens, dark-mode base — inert in light mode) plus the integration modules enabled under Settings. These are plugin-versioned files — updates reach every site automatically, and they are not editable here.', 'gp-dark-mode' ) . '</p>';

		$shipped = trim( self::framework_css() . "\n" . $this->modules_css() );
		echo '<textarea readonly class="large-text code" rows="18" spellcheck="false">' . esc_textarea( $shipped ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Toggle individual modules under Settings → Integration modules.', 'gp-dark-mode' ) . '</p>';
	}

	/** Tab: the Layer-2 generated palette. */
	private function render_tab_generated( $s ) {

		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Generated dark palette', 'gp-dark-mode' ) . '</h2>';

		echo '<p class="description">' . esc_html__( 'Dark values derived automatically from this site’s GeneratePress Global Colors (lightness inverted, hue kept, WCAG contrast enforced), redefining GP’s own CSS variables in dark mode — everything GP and GenerateBlocks paint with them flips wholesale. Generate, review the CSS below, then enable. Regenerate after changing the Global Colors.', 'gp-dark-mode' ) . '</p>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[enable_generated]" value="1" ' . checked( 1, (int) $s['enable_generated'], false ) . ' /> ';
		echo esc_html__( 'Enable this stylesheet — include the generated palette in the CSS the plugin outputs. Unchecked, it stays saved here but visitors never receive it.', 'gp-dark-mode' ) . '</label>';
		$generated = $this->generated_css();
		echo '<textarea readonly class="large-text code" rows="8" spellcheck="false">';
		echo esc_textarea( '' !== trim( $generated ) ? $generated : __( '— not generated yet — click “Generate from Global Colors” below —', 'gp-dark-mode' ) );
		echo '</textarea>';
		$gen_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_generate' ), 'ogm_gpdm_generate' );
		echo '<p><a class="button button-secondary" href="' . esc_url( $gen_url ) . '">' . esc_html__( 'Generate from Global Colors', 'gp-dark-mode' ) . '</a></p>';
	}

	/** Tab: the AI stylesheet (Layer 4a) — review loop + current CSS. */
	private function render_tab_ai( $s ) {
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'AI stylesheet', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Machine-owned: each accepted proposal replaces this stylesheet wholesale. Your Manual CSS is a separate layer that loads after this one and always wins — a review can never clobber a hand edit. Provider and API keys live under Settings.', 'gp-dark-mode' ) . '</p>';

		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[enable_ai_css]" value="1" ' . checked( 1, (int) $s['enable_ai_css'], false ) . ' /> ';
		echo esc_html__( 'Enable this stylesheet — include the AI stylesheet in the CSS the plugin outputs. Unchecked, it stays saved here but visitors never receive it.', 'gp-dark-mode' ) . '</label>';

		$ai_css = OGM_GPDM_AI::ai_css();
		echo '<textarea readonly class="large-text code" rows="10" spellcheck="false">';
		echo esc_textarea( '' !== trim( $ai_css ) ? $ai_css : __( '— empty — run a review and apply its proposal to fill this layer —', 'gp-dark-mode' ) );
		echo '</textarea>';

		$this->render_ai_review_section();
	}

	/** Tab: the owner's Manual CSS (Layer 4b) — loads last, always wins. */
	private function render_tab_manual( $s ) {
		// The .ogm-gpdm-css-field wrapper is load-bearing: the admin CSS
		// scopes the CodeMirror height/resize/border rules to it.
		echo '<div class="ogm-gpdm-css-field">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Manual CSS', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Your hand-written layer — it loads after every other stylesheet, so it always wins. Nothing is ever added here automatically: the AI writes only to its own tab, the generator to its own — this field belongs to you alone. It starts empty; the plugin ships no site-specific CSS. The home for per-placement ad selectors, GenerateBlocks instance classes, and one-off page chrome.', 'gp-dark-mode' ) . '</p>';

		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[enable_custom_css]" value="1" ' . checked( 1, (int) $s['enable_custom_css'], false ) . ' /> ';
		echo esc_html__( 'Enable this stylesheet — include your Manual CSS in the CSS the plugin outputs. Unchecked, it stays saved here but visitors never receive it.', 'gp-dark-mode' ) . '</label>';

		echo '<textarea id="ogm-gpdm-custom-css" class="large-text code" name="' . esc_attr( self::OPT_KEY ) . '[custom_css]" rows="20" spellcheck="false">' . esc_textarea( $this->custom_css() ) . '</textarea>';
		echo '</div>';
	}

	/** Tab: behavior settings, integration modules, AI configuration, bundle export. */
	private function render_tab_settings( $s ) {
		echo '<table class="form-table" role="presentation">';

		// Default theme.
		echo '<tr>';
		echo '<th scope="row"><label for="ogm-gpdm-default-theme">' . esc_html__( 'Default theme', 'gp-dark-mode' ) . '</label></th>';
		echo '<td>';
		echo '<select id="ogm-gpdm-default-theme" name="' . esc_attr( self::OPT_KEY ) . '[default_theme]">';
		echo '<option value="light"' . selected( $s['default_theme'], 'light', false ) . '>' . esc_html__( 'Light', 'gp-dark-mode' ) . '</option>';
		echo '<option value="dark"' . selected( $s['default_theme'], 'dark', false ) . '>' . esc_html__( 'Dark', 'gp-dark-mode' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'What a first-time visitor sees before they pick a theme. Returning visitors keep their own choice.', 'gp-dark-mode' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Respect system preference.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'System preference', 'gp-dark-mode' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[respect_system]" value="1" ' . checked( 1, (int) $s['respect_system'], false ) . ' /> ';
		echo esc_html__( 'Match the visitor’s operating-system dark-mode setting on first visit', 'gp-dark-mode' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When the OS explicitly requests dark or light, that wins over the default above. Systems with no preference fall back to the default theme.', 'gp-dark-mode' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Show toggle.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Toggle button', 'gp-dark-mode' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[show_toggle]" value="1" ' . checked( 1, (int) $s['show_toggle'], false ) . ' /> ';
		echo esc_html__( 'Show the toggle in the GeneratePress menu bar', 'gp-dark-mode' ) . '</label>';
		echo '<p class="description">';
		printf(
			/* translators: %s: shortcode. */
			esc_html__( 'You can also place the toggle anywhere with the %s shortcode.', 'gp-dark-mode' ),
			'<code>[gp_dark_mode_toggle]</code>'
		);
		echo '</p>';
		echo '</td>';
		echo '</tr>';

		// Accent nav (dark-mode nav background recolor).
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Dark nav accent', 'gp-dark-mode' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[accent_nav]" value="1" ' . checked( 1, (int) $s['accent_nav'], false ) . ' /> ';
		echo esc_html__( 'Paint the navigation bar with the accent color in dark mode', 'gp-dark-mode' ) . '</label>';
		echo '<p class="description">';
		printf(
			/* translators: %s: CSS variable name. */
			esc_html__( 'Off by default, matching the original behavior: in dark mode the nav follows the theme’s own --accent color when the theme defines one, and otherwise blends into the page. When on, the nav uses %s instead — editable at the top of the core stylesheet.', 'gp-dark-mode' ),
			'<code>--dm-accent</code>'
		);
		echo '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// Integration modules.
		echo '<div class="ogm-gpdm-css-field">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Integration modules', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ready-made dark styling for common vendors, written against their stable generic selectors. Detected integrations are pre-enabled on fresh installs; script-injected ad vendors (RevContent) can’t be detected server-side — enable those manually. Per-placement selectors belong on the Manual tab.', 'gp-dark-mode' ) . '</p>';
		foreach ( self::modules() as $slug => $module ) {
			$checked  = ! empty( $s['modules'][ $slug ] );
			$detected = ( null !== $module['detect'] ) && self::module_detected( $slug );
			echo '<label style="display:block;margin:4px 0;">';
			echo '<input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[modules][' . esc_attr( $slug ) . ']" value="1" ' . checked( true, $checked, false ) . ' /> ';
			echo esc_html( $module['label'] );
			if ( $detected ) {
				echo ' <span class="ogm-gpdm-detected">' . esc_html__( '(detected)', 'gp-dark-mode' ) . '</span>';
			}
			echo '</label>';
		}
		echo '</div>';

		// BYOK AI assist configuration.
		echo '<div class="ogm-gpdm-css-field">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'AI Assist', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Bring your own API key. The AI review sends this site’s Context Bundle (including functions.php, with likely secrets redacted) to the provider configured here, and returns a proposal you approve on the AI tab — nothing is ever applied automatically.', 'gp-dark-mode' ) . '</p>';

		echo '<p><label>' . esc_html__( 'Provider', 'gp-dark-mode' ) . ' ';
		echo '<select id="ogm-gpdm-ai-provider" name="' . esc_attr( self::OPT_KEY ) . '[ai_provider]">';
		foreach ( OGM_GPDM_AI::providers() as $pkey => $pdata ) {
			echo '<option value="' . esc_attr( $pkey ) . '"' . selected( $s['ai_provider'], $pkey, false ) . '>' . esc_html( $pdata['label'] ) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( 'Model', 'gp-dark-mode' ) . ' ';
		echo '<select id="ogm-gpdm-ai-model" name="' . esc_attr( self::OPT_KEY ) . '[ai_model]">';
		echo '<option value="">' . esc_html(
			sprintf(
				/* translators: %s: the provider's default model id. */
				__( 'Provider default (%s)', 'gp-dark-mode' ),
				OGM_GPDM_AI::default_model( $s['ai_provider'] )
			)
		) . '</option>';
		foreach ( OGM_GPDM_AI::model_choices( $s['ai_provider'], $s['ai_model'] ) as $model_id ) {
			echo '<option value="' . esc_attr( $model_id ) . '"' . selected( $s['ai_model'], $model_id, false ) . '>' . esc_html( $model_id ) . '</option>';
		}
		echo '</select></label> ';

		// type=button so pressing Enter in a field saves settings instead of refreshing.
		echo '<button type="button" class="button" id="ogm-gpdm-refresh-models">' . esc_html__( 'Refresh models', 'gp-dark-mode' ) . '</button> ';
		echo '<span id="ogm-gpdm-refresh-status" aria-live="polite"></span></p>';

		$models_updated = OGM_GPDM_AI::models_cache( $s['ai_provider'] )['updated'];
		if ( $models_updated ) {
			/* translators: %s: human-readable time difference. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Model list refreshed %s ago, live from the provider.', 'gp-dark-mode' ), human_time_diff( $models_updated, time() ) ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Showing a default list. Save the API key, then click Refresh models to pull the provider’s current lineup.', 'gp-dark-mode' ) . '</p>';
		}

		// One key row per provider — only the selected provider's row shows
		// (JS toggles on switch; server-side style covers no-JS). Keys are
		// stored per provider so one is never sent to the other's API.
		foreach ( OGM_GPDM_AI::providers() as $pkey => $pdata ) {
			$key_field = 'ai_api_key_' . $pkey;
			$has_key   = isset( $s[ $key_field ] ) && '' !== $s[ $key_field ];
			echo '<p class="ogm-gpdm-key-row" data-provider="' . esc_attr( $pkey ) . '"' . ( $pkey !== $s['ai_provider'] ? ' style="display:none"' : '' ) . '>';
			echo '<label>' . esc_html( sprintf( /* translators: %s: provider label. */ __( '%s API key', 'gp-dark-mode' ), $pdata['label'] ) ) . ' ';
			echo '<input type="password" name="' . esc_attr( self::OPT_KEY ) . '[' . esc_attr( $key_field ) . ']" value="' . esc_attr( $has_key ? self::KEY_MASK : '' ) . '" autocomplete="new-password" class="regular-text" placeholder="' . esc_attr__( 'paste your API key', 'gp-dark-mode' ) . '" /></label>';
			if ( $has_key ) {
				echo ' <span class="description">' . esc_html__( 'A key is saved — replace it by typing a new one, or type “clear” to remove it.', 'gp-dark-mode' ) . '</span>';
			}
			echo '</p>';
		}

		echo '</div>';

		$this->render_bundle_card();
		$this->render_danger_zone();
	}

	/** The Danger Zone (Settings tab): factory reset. */
	private function render_danger_zone() {
		echo '<div class="ogm-gpdm-css-field ogm-gpdm-danger">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Danger zone', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Reset every GP Dark Mode setting to factory defaults: behavior settings, module choices, API keys, the generated palette, the AI stylesheet, any pending proposal and backup, saved review instructions, and cached model lists. The Manual CSS returns to the bundled defaults. Auto-detected modules are re-enabled, as on a fresh install. This cannot be undone.', 'gp-dark-mode' ) . '</p>';
		$reset_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_reset' ), 'ogm_gpdm_reset' );
		echo '<p><a class="button ogm-gpdm-danger-btn" id="ogm-gpdm-reset" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset all settings', 'gp-dark-mode' ) . '</a></p>';
		echo '</div>';
	}

	/** The AI Context Bundle block (Settings tab). */
	private function render_bundle_card() {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_bundle' ), 'ogm_gpdm_bundle' );

		echo '<div class="ogm-gpdm-css-field ogm-gpdm-bundle-card">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'AI Context Bundle', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One Markdown file containing everything an AI assistant (or a human) needs to review and tweak this site’s dark mode: the GeneratePress Global Colors and color settings, this plugin’s framework and current Additional CSS, the child theme’s style.css and functions.php, and a capture of the CSS the front end actually prints (reduced to color-relevant rules). Paste it into a Claude chat and ask for a dark-mode review.', 'gp-dark-mode' ) . '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Download bundle (.md)', 'gp-dark-mode' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Generation fetches the homepage and the latest post, so it can take ~10–30 seconds. functions.php is included verbatim with likely secrets redacted (best-effort) — skim it before sharing the file outside your team.', 'gp-dark-mode' ) . '</p>';
		echo '</div>';
	}

	/** The AI Review section (inside the settings form, below AI Assist config). */
	private function render_ai_review_section() {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$proposal = OGM_GPDM_AI::proposal();
		$backup   = OGM_GPDM_AI::backup();
		$status   = OGM_GPDM_AI::polled_status();

		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'AI Review', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Sends the AI Context Bundle to the provider configured under Settings and proposes a replacement for the AI stylesheet above. The review runs in the background (via WP-Cron) and typically takes 1–3 minutes — this page follows along and shows the proposal when it’s ready. Costs are billed to your own API account (roughly a few tens of cents per run).', 'gp-dark-mode' ) . '</p>';

		// The owner's instructions: specific fixes for this run, carried into
		// the prompt with top priority. Prefilled with the last run's text so
		// reviews can be iterated ("good, but also fix X and Y…").
		$instructions = get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, '' );
		echo '<p><label for="ogm-gpdm-ai-instructions">' . esc_html__( 'Instructions for this review (optional — the reviewer prioritizes these):', 'gp-dark-mode' ) . '</label></p>';
		echo '<textarea id="ogm-gpdm-ai-instructions" class="large-text" rows="5" spellcheck="false" placeholder="' . esc_attr__( 'e.g. The homepage pagination needs work; the footer menu links should be white; the background behind the featured posts should be dark…', 'gp-dark-mode' ) . '">' . esc_textarea( is_string( $instructions ) ? $instructions : '' ) . '</textarea>';

		echo '<p><button type="button" class="button button-primary" id="ogm-gpdm-run-review"' . ( OGM_GPDM_AI::is_running() ? ' disabled' : '' ) . '>' . esc_html__( 'Run AI Review', 'gp-dark-mode' ) . '</button> ';
		echo '<span id="ogm-gpdm-review-status" aria-live="polite">';
		if ( 'running' === $status['state'] ) {
			echo esc_html__( 'A review is running…', 'gp-dark-mode' );
		} elseif ( 'error' === $status['state'] && null === $proposal ) {
			echo '<span class="ogm-gpdm-refresh-error">' . esc_html( sprintf( /* translators: %s: error message. */ __( 'Last run failed: %s', 'gp-dark-mode' ), $status['message'] ) ) . '</span>';
		}
		echo '</span></p>';

		if ( null !== $proposal ) {
			echo '<h3>' . esc_html(
				sprintf(
					/* translators: 1: provider, 2: model, 3: UTC timestamp. */
					__( 'Pending proposal — %1$s / %2$s, %3$s UTC', 'gp-dark-mode' ),
					$proposal['provider'],
					$proposal['model'],
					$proposal['created']
				)
			) . '</h3>';

			if ( '' !== trim( (string) $proposal['notes'] ) ) {
				echo '<p class="description">' . nl2br( esc_html( $proposal['notes'] ) ) . '</p>';
			}

			$diff = wp_text_diff(
				OGM_GPDM_AI::ai_css(),
				$proposal['css'],
				array(
					'title_left'  => __( 'Current AI stylesheet', 'gp-dark-mode' ),
					'title_right' => __( 'Proposed', 'gp-dark-mode' ),
				)
			);
			if ( $diff ) {
				echo '<div class="ogm-gpdm-ai-diff">' . $diff . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff() returns escaped table markup.
			} else {
				echo '<p class="description">' . esc_html__( 'The proposal is identical to the current AI stylesheet.', 'gp-dark-mode' ) . '</p>';
			}

			echo '<textarea readonly class="large-text code" rows="10" spellcheck="false">' . esc_textarea( $proposal['css'] ) . '</textarea>';

			$apply_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_ai_apply' ), 'ogm_gpdm_ai_apply' );
			$discard_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_ai_discard' ), 'ogm_gpdm_ai_discard' );
			echo '<p>';
			echo '<a class="button button-primary" href="' . esc_url( $apply_url ) . '">' . esc_html__( 'Apply as the AI stylesheet', 'gp-dark-mode' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $discard_url ) . '">' . esc_html__( 'Discard', 'gp-dark-mode' ) . '</a>';
			echo '</p>';
		}

		if ( null !== $backup ) {
			$restore_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogm_gpdm_ai_restore' ), 'ogm_gpdm_ai_restore' );
			echo '<p class="description">';
			printf(
				/* translators: %s: UTC timestamp. */
				esc_html__( 'A backup from %s UTC exists. Restoring SWAPS it with the current AI stylesheet — click twice to get back to where you started. Your Manual CSS is never involved.', 'gp-dark-mode' ),
				esc_html( $backup['created'] )
			);
			echo ' <a class="button" href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Swap in the backup', 'gp-dark-mode' ) . '</a>';
			echo '</p>';
		}
	}

	/** Serve the AI context bundle as a Markdown download. */
	public function handle_bundle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		// The bundle embeds theme file contents (functions.php). On multisite,
		// reading theme source is deliberately a super-admin capability —
		// per-site admins must not be able to export network-shared code.
		if ( is_multisite() && ! is_super_admin() ) {
			wp_die( esc_html__( 'On a multisite network, the AI Context Bundle (which includes theme file contents) is available to network administrators only.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_bundle' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-bundle.php';

		$markdown = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $this ) );

		$host = self::bundle_host_slug();
		$name = 'gp-dark-mode-bundle-' . $host . '-' . gmdate( 'Ymd-His' ) . '.md';

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $markdown ) );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text file download.
		exit;
	}

	/** Generate/regenerate the Layer-2 dark palette from GP's Global Colors. */
	public function handle_palette_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_generate' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-palette.php';

		$css = OGM_GPDM_Palette::generate();

		// GEN_KEY is a separate, UNREGISTERED option on purpose — writing
		// through OPT_KEY would re-enter the settings sanitizer, which would
		// discard this value and corrupt keys absent from the form. Autoload
		// deliberately stays ON: unlike the AI proposal/backup blobs, this is
		// front-end state (read on every page when the palette is enabled)
		// and only a couple of KB.
		update_option( self::GEN_KEY, self::sanitize_css( $css ), true );

		$url = add_query_arg(
			'gpdm-generated',
			'' === $css ? 'empty' : '1',
			admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
		);
		// Land on the tab the action belongs to — a hashless redirect dumps
		// the admin on the first tab with the notice pointing at hidden UI.
		wp_safe_redirect( $url . '#generated' );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AI assist handlers (Layer 4 review)
	 * ------------------------------------------------------------------- */

	/**
	 * AJAX: start a background AI review. Returns immediately — the actual
	 * run happens on the ogm_gpdm_run_ai_review cron event, so no proxy
	 * timeout can 502 it and no error text ever rides in a URL (both failure
	 * modes were live-verified on the first deployment).
	 */
	public function handle_ai_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'gp-dark-mode' ) ), 403 );
		}
		// The review sends the bundle (which embeds theme file contents) to an
		// external API — same trust boundary as the bundle download.
		if ( is_multisite() && ! is_super_admin() ) {
			wp_send_json_error( array( 'message' => __( 'On a multisite network, the AI review is available to network administrators only.', 'gp-dark-mode' ) ), 403 );
		}
		check_ajax_referer( 'ogm_gpdm_ai_start', 'nonce' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		if ( OGM_GPDM_AI::is_running() ) {
			wp_send_json_error( array( 'message' => __( 'A review is already running — give it a couple of minutes.', 'gp-dark-mode' ) ) );
		}

		// Pre-flight: fail instantly on a missing key instead of taking a
		// cron round-trip to find out.
		$settings = $this->settings();
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : 'anthropic';
		if ( '' === $this->ai_key_for( $provider ) ) {
			wp_send_json_error( array( 'message' => __( 'No API key saved for this provider — add one under AI Assist and save the settings first.', 'gp-dark-mode' ) ) );
		}

		// The owner's instructions for this run (also prefills the next one).
		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
		update_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, mb_substr( $instructions, 0, 4000 ), false );

		OGM_GPDM_AI::set_status( 'running' );
		wp_schedule_single_event( time() - 1, 'ogm_gpdm_run_ai_review' );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		wp_send_json_success( array( 'message' => __( 'Review started…', 'gp-dark-mode' ) ) );
	}

	/** AJAX: poll the background run's status. */
	public function handle_ai_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'gp-dark-mode' ) ), 403 );
		}
		check_ajax_referer( 'ogm_gpdm_ai_start', 'nonce' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		wp_send_json_success( OGM_GPDM_AI::polled_status() );
	}

	/** Cron callback: execute the review and record the outcome. */
	public function run_ai_review_cron() {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$result = OGM_GPDM_AI::run_review( $this );

		if ( is_wp_error( $result ) ) {
			OGM_GPDM_AI::set_status( 'error', $result->get_error_message() );
		} else {
			OGM_GPDM_AI::set_status( 'done', __( 'Review complete — a proposal is ready.', 'gp-dark-mode' ) );
		}
	}

	/** Apply the stored proposal (with automatic backup). */
	public function handle_ai_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_ai_apply' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		$result = OGM_GPDM_AI::apply_proposal( $this );

		$this->redirect_with_ai_code( is_wp_error( $result ) ? 'no-proposal' : 'applied' );
	}

	/** Discard the stored proposal. */
	public function handle_ai_discard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_ai_discard' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		OGM_GPDM_AI::discard();

		$this->redirect_with_ai_code( 'discarded' );
	}

	/** Restore the pre-apply backup. */
	public function handle_ai_restore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_ai_restore' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		$result = OGM_GPDM_AI::restore_backup( $this );

		$this->redirect_with_ai_code( is_wp_error( $result ) ? 'no-backup' : 'restored' );
	}

	/**
	 * AJAX: refresh one provider's model list from its /v1/models endpoint
	 * (Editorial QA pattern — the dropdown updates in place).
	 */
	public function handle_refresh_models() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'gp-dark-mode' ) ), 403 );
		}
		check_ajax_referer( 'ogm_gpdm_refresh_models', 'nonce' );

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! array_key_exists( $provider, OGM_GPDM_AI::providers() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'gp-dark-mode' ) ), 400 );
		}

		// Provider-matched key: a saved key must never be sent to the OTHER
		// provider (a dropdown flip + refresh used to do exactly that).
		$result = OGM_GPDM_AI::refresh_models( $provider, $this->ai_key_for( $provider ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => mb_substr( $result->get_error_message(), 0, 200 ) ) );
		}

		wp_send_json_success(
			array(
				'models'  => array_values( $result ),
				'count'   => count( $result ),
				/* translators: %d: number of models. */
				'message' => sprintf( __( 'Refreshed — %d models.', 'gp-dark-mode' ), count( $result ) ),
			)
		);
	}

	/**
	 * Factory reset: delete every plugin option and re-seed activation
	 * defaults (with module auto-detection, like a fresh install).
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gp-dark-mode' ) );
		}
		check_admin_referer( 'ogm_gpdm_reset' );

		// The registered sanitizer treats every write to OPT_KEY as raw form
		// input (see GEN_KEY) — detach it around the re-seed so the seeded
		// defaults land verbatim (and custom_css stays ABSENT, restoring the
		// bundled-seed fallback).
		remove_filter( 'sanitize_option_' . self::OPT_KEY, array( $this, 'sanitize_settings' ) );
		self::reset_all();
		add_filter( 'sanitize_option_' . self::OPT_KEY, array( $this, 'sanitize_settings' ) );

		$this->settings = null;
		$this->redirect_with_ai_code( 'reset', 'settings' );
	}

	/** Delete every plugin option, clear pending cron, re-seed like activation. */
	public static function reset_all() {
		$options = array(
			self::OPT_KEY,
			self::GEN_KEY,
			'ogm_gpdm_ai_proposal',
			'ogm_gpdm_ai_backup',
			'ogm_gpdm_ai_status',
			'ogm_gpdm_ai_instructions',
			'ogm_gpdm_ai_models',
			'ogm_gpdm_ai_css',
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'ogm_gpdm_run_ai_review' );
		}
		self::activate();
	}

	/**
	 * Redirect back to the settings page with a FIXED status code. Free text
	 * must never ride in the query string: mod_security-style WAFs 403 URLs
	 * carrying quoted provider error messages (live-verified on the first
	 * deployment — the admin saw "Forbidden" instead of the real error).
	 */
	private function redirect_with_ai_code( $code, $tab = 'ai' ) {
		// The fragment lands the admin on the tab whose state changed.
		wp_safe_redirect( add_query_arg( 'gpdm-ai', rawurlencode( $code ), admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '#' . sanitize_key( $tab ) );
		exit;
	}

	/** Filename-safe host slug for the bundle download. */
	private static function bundle_host_slug() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = sanitize_file_name( $host );
		return '' !== $host ? $host : 'site';
	}

	/** Branded OGM header. */
	private function render_header() {
		echo '<div class="ogm-gpdm-header">';
		echo '<div class="ogm-gpdm-brand">';
		echo '<span class="ogm-gpdm-mark" aria-hidden="true"><span class="dashicons dashicons-lightbulb"></span></span>';
		echo '<div class="ogm-gpdm-brand-text">';
		echo '<h1>' . esc_html__( 'GP Dark Mode', 'gp-dark-mode' ) . '</h1>';
		echo '<p class="ogm-gpdm-tagline">' . esc_html__( 'No-flash dark mode for GeneratePress — by Orchard Grove Media', 'gp-dark-mode' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '<span class="ogm-gpdm-version">v' . esc_html( OGM_GPDM_VERSION ) . '</span>';
		echo '</div>';
		echo '<hr class="wp-header-end" />';
	}

	/** "At a glance" sidebar. */
	private function render_sidebar( $s ) {
		$gp        = $this->is_generatepress();
		$theme_lbl = ( 'dark' === $this->default_theme() ) ? __( 'Dark', 'gp-dark-mode' ) : __( 'Light', 'gp-dark-mode' );

		echo '<div class="ogm-gpdm-card ogm-gpdm-aside-card">';
		echo '<h2>' . esc_html__( 'At a glance', 'gp-dark-mode' ) . '</h2>';
		echo '<ul class="ogm-gpdm-glance">';

		echo '<li><span class="ogm-gpdm-dot ' . ( $gp ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'GeneratePress', 'gp-dark-mode' ) . '</span><span class="value">' . ( $gp ? esc_html__( 'Detected', 'gp-dark-mode' ) : esc_html__( 'Not found', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="label">' . esc_html__( 'Default theme', 'gp-dark-mode' ) . '</span><span class="value">' . esc_html( $theme_lbl ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['respect_system'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'System preference', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['respect_system'] ? esc_html__( 'Respected', 'gp-dark-mode' ) : esc_html__( 'Ignored', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['show_toggle'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Menu-bar toggle', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['show_toggle'] ? esc_html__( 'Shown', 'gp-dark-mode' ) : esc_html__( 'Hidden', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['enable_ai_css'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'AI stylesheet', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['enable_ai_css'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['enable_custom_css'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Manual CSS', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['enable_custom_css'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['accent_nav'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Dark nav accent', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['accent_nav'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['enable_generated'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Generated palette', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['enable_generated'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

		$module_count = is_array( $s['modules'] ) ? count( array_filter( $s['modules'] ) ) : 0;
		echo '<li><span class="ogm-gpdm-dot ' . ( $module_count ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Modules', 'gp-dark-mode' ) . '</span><span class="value">' . esc_html( sprintf( /* translators: %d: number of enabled modules. */ __( '%d enabled', 'gp-dark-mode' ), $module_count ) ) . '</span></li>';

		echo '</ul>';
		echo '</div>';

		echo '<div class="ogm-gpdm-card ogm-gpdm-aside-card">';
		echo '<h2>' . esc_html__( 'Placement & colors', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="ogm-gpdm-aside-foot">';
		echo esc_html__( 'The toggle sits in the GeneratePress menu bar (enable it under Customize → Layout → Primary Navigation). Anywhere else, drop in:', 'gp-dark-mode' );
		echo '</p>';
		echo '<p><code>[gp_dark_mode_toggle]</code></p>';
		echo '<p class="ogm-gpdm-aside-foot">';
		printf(
			/* translators: %s: stylesheet filename. */
			esc_html__( 'Adjust the palette by editing the CSS variables at the top of %s.', 'gp-dark-mode' ),
			'<code>assets/css/gp-dark-mode.css</code>'
		);
		echo '</p>';
		echo '</div>';
	}
}

register_activation_hook( __FILE__, array( 'OGM_GP_Dark_Mode', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OGM_GP_Dark_Mode', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		OGM_GP_Dark_Mode::instance()->boot();
	}
);
