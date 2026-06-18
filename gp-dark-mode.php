<?php
/**
 * Plugin Name:       GP Dark Mode
 * Plugin URI:        https://orchardgrove.com/
 * Description:       Robust, no-flash, accessible dark mode for the GeneratePress theme — with a menu-bar toggle, a behavior settings page, and a clean light/dark CSS-variable framework.
 * Version:           1.0.2
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

define( 'OGM_GPDM_VERSION', '1.0.2' );
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
		add_filter( 'plugin_action_links_' . OGM_GPDM_BASENAME, array( $this, 'action_links' ) );
	}

	/** Seed default options on activation. */
	public static function activate() {
		if ( false === get_option( self::OPT_KEY, false ) ) {
			add_option( self::OPT_KEY, self::defaults() );
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
			'enable_custom_css'   => 1,        // Output the Additional CSS field on the front end.
			'accent_nav'          => 0,        // Recolor the dark-mode nav with the accent.
		);
	}

	/** Parsed settings (option merged over defaults), cached per request. */
	public function settings() {
		if ( null === $this->settings ) {
			$opts           = get_option( self::OPT_KEY, array() );
			$this->settings = wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
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
	 * Returns the saved value once the user has saved the form (even if they
	 * cleared it); until then it falls back to the bundled default overrides so
	 * the field is pre-filled and the site keeps its existing styling.
	 */
	public function custom_css() {
		$opts = get_option( self::OPT_KEY, array() );
		if ( is_array( $opts ) && array_key_exists( 'custom_css', $opts ) && is_string( $opts['custom_css'] ) ) {
			return $opts['custom_css'];
		}
		return self::default_custom_css();
	}

	/** Bundled default overrides — the seed for the Additional CSS field. */
	public static function default_custom_css() {
		static $css = null;
		if ( null === $css ) {
			$file = OGM_GPDM_DIR . 'assets/css/site-overrides.css';
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
		$out['accent_nav']        = empty( $in['accent_nav'] ) ? 0 : 1;

		$out['custom_css'] = isset( $in['custom_css'] ) ? self::sanitize_css( $in['custom_css'] ) : '';

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

	/** Enqueue the front-end CSS framework (+ optional inline Additional CSS) and the toggle script. */
	public function enqueue_front_assets() {
		// Core framework depends on GeneratePress's stylesheet so our rules win
		// where specificity ties. (A missing dependency is simply ignored.)
		wp_enqueue_style(
			'gp-dark-mode-core',
			OGM_GPDM_URL . 'assets/css/gp-dark-mode.css',
			array( 'generate-style' ),
			OGM_GPDM_VERSION
		);

		// Additional CSS (the editable field) prints inline right after the core
		// framework, so it overrides it and lands in the right cascade slot.
		if ( $this->get( 'enable_custom_css' ) ) {
			// Sanitize on output too, so even the pre-first-save bundled default
			// can never emit a stray </style.
			$css = self::sanitize_css( $this->custom_css() );
			if ( '' !== $css ) {
				wp_add_inline_style( 'gp-dark-mode-core', $css );
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

		$html  = '<button id="dark-mode-toggle" class="dm-toggle" type="button" role="switch"';
		$html .= ' aria-label="' . $label . '" aria-checked="' . esc_attr( $checked ) . '">';
		$html .= '<span class="dm-track" aria-hidden="true">';
		$html .= '<span class="dm-icon dm-sun" aria-hidden="true">&#9728;</span>';
		$html .= '<span class="dm-icon dm-moon" aria-hidden="true">&#9790;</span>';
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

		echo '<div class="ogm-gpdm-layout">';

		// Main column.
		echo '<div class="ogm-gpdm-main"><div class="ogm-gpdm-card">';
		echo '<p class="ogm-gpdm-intro">' . esc_html__( 'Control how dark mode behaves for visitors, and edit this site’s additional dark-mode styles right here — no file editing required.', 'gp-dark-mode' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPT_KEY );
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
			esc_html__( 'Off by default, matching the original behavior (the nav keeps its theme background). When on, the GeneratePress nav uses %s in dark mode — editable at the top of the core stylesheet.', 'gp-dark-mode' ),
			'<code>--dm-accent</code>'
		);
		echo '</p>';
		echo '</td>';
		echo '</tr>';

		// Additional CSS — enable toggle (the editor itself sits below the table).
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Additional CSS', 'gp-dark-mode' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_KEY ) . '[enable_custom_css]" value="1" ' . checked( 1, (int) $s['enable_custom_css'], false ) . ' /> ';
		echo esc_html__( 'Output the Additional CSS below on the front end', 'gp-dark-mode' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Turn off to run the clean framework only, without clearing your styles.', 'gp-dark-mode' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// Additional CSS editor — full width, below the behavior table.
		echo '<div class="ogm-gpdm-css-field">';
		echo '<h2 class="ogm-gpdm-subhead">' . esc_html__( 'Additional CSS', 'gp-dark-mode' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These styles load after the core framework, in both light and dark mode — the home for this site’s third-party widget tweaks (ads, RevContent, donations, etc.). Pre-filled from the plugin’s bundled defaults until you save your own.', 'gp-dark-mode' ) . '</p>';
		echo '<textarea id="ogm-gpdm-custom-css" class="large-text code" name="' . esc_attr( self::OPT_KEY ) . '[custom_css]" rows="20" spellcheck="false">' . esc_textarea( $this->custom_css() ) . '</textarea>';
		echo '<p class="description">';
		printf(
			/* translators: %s: filename of the bundled defaults. */
			esc_html__( 'A copy of the defaults ships in %s if you ever want to start over.', 'gp-dark-mode' ),
			'<code>assets/css/site-overrides.css</code>'
		);
		echo '</p>';
		echo '</div>';

		submit_button( __( 'Save Settings', 'gp-dark-mode' ) );
		echo '</form>';
		echo '</div></div>'; // .ogm-gpdm-card, .ogm-gpdm-main.

		// Sidebar.
		echo '<aside class="ogm-gpdm-sidebar">';
		$this->render_sidebar( $s );
		echo '</aside>';

		echo '</div>'; // .ogm-gpdm-layout.
		echo '</div>'; // .wrap.
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

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['enable_custom_css'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Additional CSS', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['enable_custom_css'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

		echo '<li><span class="ogm-gpdm-dot ' . ( $s['accent_nav'] ? 'is-on' : '' ) . '"></span><span class="label">' . esc_html__( 'Dark nav accent', 'gp-dark-mode' ) . '</span><span class="value">' . ( $s['accent_nav'] ? esc_html__( 'On', 'gp-dark-mode' ) : esc_html__( 'Off', 'gp-dark-mode' ) ) . '</span></li>';

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

add_action(
	'plugins_loaded',
	static function () {
		OGM_GP_Dark_Mode::instance()->boot();
	}
);
