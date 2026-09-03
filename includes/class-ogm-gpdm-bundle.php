<?php
/**
 * GP Dark Mode — AI context bundle assembler.
 *
 * Gathers everything an AI (or a human reviewer) needs to review and tweak
 * this site's dark-mode styling: GeneratePress color configuration, the
 * plugin's own framework + Additional CSS, the child theme's stylesheet and
 * functions.php, and a rendered capture of the CSS the front end actually
 * prints. Deterministic PHP only — no API calls. Output is one Markdown
 * document, downloadable from the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_Bundle {

	/** Skip raw sources larger than this (bytes). */
	const CAP_FILE_RAW = 524288;

	/** functions.php verbatim cap (bytes). */
	const CAP_FUNCTIONS = 262144;

	/** Linked stylesheets fetched per bundle. */
	const MAX_LINKED = 20;

	/** Recursion guard for the CSS parser. */
	const MAX_DEPTH = 8;

	/* ---------------------------------------------------------------------
	 * Assembly
	 * ------------------------------------------------------------------- */

	/**
	 * Build the structured bundle.
	 *
	 * @param OGM_GP_Dark_Mode $plugin Plugin instance (settings + CSS access).
	 * @return array
	 */
	public static function assemble( OGM_GP_Dark_Mode $plugin ) {
		return array(
			'meta'         => self::section_meta(),
			'gp_config'    => self::section_gp_config(),
			'plugin_state' => self::section_plugin_state( $plugin ),
			'child_theme'  => self::section_child_theme(),
			'rendered'     => self::section_rendered(),
		);
	}

	/** Site / theme / version facts. */
	private static function section_meta() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'site'           => get_bloginfo( 'name' ),
			'home_url'       => home_url( '/' ),
			'wordpress'      => get_bloginfo( 'version' ),
			'active_theme'   => $theme->get_stylesheet() . ' v' . $theme->get( 'Version' ),
			'parent_theme'   => $parent ? $parent->get_template() . ' v' . $parent->get( 'Version' ) : '(none — not a child theme)',
			'generatepress'  => ( function_exists( 'generate_get_option' ) || 'generatepress' === strtolower( (string) get_template() ) ) ? 'active' : 'NOT detected',
			'plugin_version' => OGM_GPDM_VERSION,
			'generated_utc'  => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** GeneratePress color configuration, read straight from the database. */
	private static function section_gp_config() {
		$out = array(
			'global_colors'  => array(),
			'color_settings' => array(),
		);

		if ( function_exists( 'generate_get_global_colors' ) ) {
			$out['global_colors'] = (array) generate_get_global_colors();
		}

		$settings = get_option( 'generate_settings', array() );
		if ( is_array( $settings ) ) {
			if ( empty( $out['global_colors'] ) && ! empty( $settings['global_colors'] ) && is_array( $settings['global_colors'] ) ) {
				$out['global_colors'] = $settings['global_colors'];
			}
			foreach ( $settings as $key => $value ) {
				if ( is_scalar( $value ) && false !== stripos( (string) $key, 'color' ) ) {
					$out['color_settings'][ $key ] = $value;
				}
			}
		}

		$background = get_option( 'generate_background_settings', array() );
		if ( is_array( $background ) && ! empty( $background ) ) {
			foreach ( $background as $key => $value ) {
				if ( is_scalar( $value ) && false !== stripos( (string) $key, 'color' ) ) {
					$out['color_settings'][ 'background/' . $key ] = $value;
				}
			}
		}

		return $out;
	}

	/** The plugin's own settings + the CSS it currently outputs. */
	private static function section_plugin_state( OGM_GP_Dark_Mode $plugin ) {
		$settings = $plugin->settings();
		unset( $settings['custom_css'] );

		// NEVER let API keys into the bundle — the bundle is downloaded,
		// shared, and sent to AI providers inside the review prompt.
		foreach ( array( 'ai_api_key', 'ai_api_key_anthropic', 'ai_api_key_openai' ) as $key_field ) {
			if ( array_key_exists( $key_field, $settings ) ) {
				$settings[ $key_field ] = ( '' !== (string) $settings[ $key_field ] ) ? '(configured, redacted)' : '';
			}
		}

		// Mirror custom_css()'s exact condition — it also requires is_string()
		// before it serves the DB value instead of '' (no bundled default
		// exists since 1.6.0).
		$opts  = get_option( OGM_GP_Dark_Mode::OPT_KEY, array() );
		$saved = is_array( $opts ) && array_key_exists( 'custom_css', $opts ) && is_string( $opts['custom_css'] );

		$modules_active = array();
		if ( isset( $settings['modules'] ) && is_array( $settings['modules'] ) ) {
			$modules_active = array_keys( array_filter( $settings['modules'] ) );
		}

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		return array(
			'settings'              => $settings,
			'additional_css_source' => $saved ? 'saved in database (edited on the settings page)' : 'empty (never saved - the plugin ships no default Manual CSS)',
			'framework_css'         => OGM_GP_Dark_Mode::framework_css(),
			'modules_active'        => $modules_active,
			'modules_css'           => $plugin->modules_css(),
			'generated_css'         => $plugin->generated_css(),
			'ai_css'                => OGM_GPDM_AI::ai_css(),
			'additional_css'        => $plugin->custom_css(),
		);
	}

	/** Child theme sources: style.css, functions.php, Customizer CSS. */
	private static function section_child_theme() {
		$dir = get_stylesheet_directory();
		$out = array(
			'style_css'      => self::read_css_file( $dir . '/style.css' ),
			'functions_php'  => self::read_functions_file( $dir . '/functions.php' ),
			'customizer_css' => '',
		);

		$custom = function_exists( 'wp_get_custom_css' ) ? (string) wp_get_custom_css() : '';
		if ( '' !== trim( $custom ) ) {
			$out['customizer_css'] = ( strlen( $custom ) > 32768 )
				? self::extract_color_css( $custom )
				: trim( $custom );
		}

		return $out;
	}

	/** Read + preprocess one CSS file. */
	private static function read_css_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return array( 'error' => 'not readable: ' . basename( $path ) );
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		if ( strlen( $raw ) > self::CAP_FILE_RAW ) {
			return array(
				'error'     => basename( $path ) . ' exceeds ' . size_format( self::CAP_FILE_RAW ) . ' — skipped',
				'raw_bytes' => strlen( $raw ),
			);
		}
		return array(
			'raw_bytes' => strlen( $raw ),
			'css'       => self::extract_color_css( $raw ),
		);
	}

	/** Read functions.php verbatim, with best-effort secret redaction. */
	private static function read_functions_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return array( 'error' => 'not readable: functions.php' );
		}
		$raw       = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		$truncated = false;
		if ( strlen( $raw ) > self::CAP_FUNCTIONS ) {
			$raw       = substr( $raw, 0, self::CAP_FUNCTIONS );
			$truncated = true;
		}
		return array(
			'raw_bytes' => strlen( $raw ),
			'truncated' => $truncated,
			'php'       => self::redact_secrets( $raw ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendered front-end capture
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch representative front-end pages and collect the CSS they print.
	 * Inline <style> blocks are preprocessed; same-origin linked stylesheets
	 * under wp-content are fetched once and preprocessed; everything else is
	 * listed by URL so a reviewer knows it exists.
	 */
	private static function section_rendered() {
		$urls = array( home_url( '/' ) );

		$recent = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => true,
			)
		);
		if ( ! empty( $recent ) ) {
			$permalink = get_permalink( $recent[0] );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}

		/**
		 * Filter which front-end URLs the bundle captures.
		 *
		 * @param string[] $urls Absolute URLs (default: homepage + latest post).
		 */
		$urls = apply_filters( 'ogm_gpdm_bundle_urls', array_values( array_unique( array_filter( $urls ) ) ) );

		$fetched_sheets = array(); // url => bool (fetch succeeded) — shared across pages for dedupe.
		$seen_inline    = array(); // md5 => first page url.
		$pages          = array();

		// Overall network budget: without one, stacked per-request timeouts on
		// a broken-loopback host can outlast proxy/FPM limits and 504 the
		// download before the graceful per-page error path ever helps.
		$deadline = microtime( true ) + 25;

		foreach ( $urls as $url ) {
			$pages[] = self::capture_page( $url, $fetched_sheets, $seen_inline, $deadline );
		}

		return array( 'pages' => $pages );
	}

	/** Capture one page's printed CSS. */
	private static function capture_page( $url, array &$fetched_sheets, array &$seen_inline, $deadline ) {
		$page = array(
			'url'           => $url,
			'inline'        => array(),
			'inline_empty'  => 0,
			'inline_dupes'  => 0,
			'sheets'        => array(),
			'sheets_listed' => array(),
		);

		$remaining = $deadline - microtime( true );
		if ( $remaining < 2 ) {
			$page['error'] = 'skipped — capture time budget exhausted';
			return $page;
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout'             => min( 12, max( 2, (int) $remaining ) ),
				'redirection'         => 3,
				'limit_response_size' => 4194304,
				'user-agent'          => 'Mozilla/5.0 (compatible; GP-Dark-Mode-Bundle/' . OGM_GPDM_VERSION . '; +' . home_url( '/' ) . ')',
			)
		);

		if ( is_wp_error( $res ) ) {
			$page['error'] = $res->get_error_message();
			return $page;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$html = (string) wp_remote_retrieve_body( $res );

		if ( 200 !== $code || '' === $html ) {
			$page['error'] = 'HTTP ' . $code . ( '' === $html ? ' (empty body)' : '' );
			return $page;
		}

		// Inline <style> blocks.
		if ( preg_match_all( '~<style\b([^>]*)>(.*?)</style>~is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$attrs = $m[1];
				$css   = trim( $m[2] );
				if ( '' === $css ) {
					continue;
				}

				$hash = md5( $css );
				if ( isset( $seen_inline[ $hash ] ) ) {
					$page['inline_dupes']++;
					continue;
				}
				$seen_inline[ $hash ] = $url;

				$id = '';
				if ( preg_match( '~id=["\']([^"\']+)["\']~i', $attrs, $idm ) ) {
					$id = $idm[1];
				}

				if ( strlen( $css ) > self::CAP_FILE_RAW ) {
					$page['inline'][] = array(
						'id'        => $id ? $id : '(no id)',
						'raw_bytes' => strlen( $css ),
						'css'       => '/* skipped: inline block exceeds ' . size_format( self::CAP_FILE_RAW ) . ' */',
					);
					continue;
				}

				$extract = self::extract_color_css( $css );
				if ( '' === $extract ) {
					$page['inline_empty']++;
					continue;
				}

				$page['inline'][] = array(
					'id'        => $id ? $id : '(no id)',
					'raw_bytes' => strlen( $css ),
					'css'       => $extract,
				);
			}
		}

		// Linked stylesheets.
		$home_host = self::bare_host( home_url() );

		if ( preg_match_all( '~<link\b[^>]+>~i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( ! preg_match( '~rel=["\']?[^"\']*stylesheet~i', $tag ) ) {
					continue;
				}
				if ( ! preg_match( '~href=["\']([^"\']+)["\']~i', $tag, $hm ) ) {
					continue;
				}

				$href = self::resolve_url( html_entity_decode( $hm[1] ), $url );
				if ( '' === $href ) {
					continue; // data:/blob:/unresolvable — nothing to capture.
				}

				$host = self::bare_host( $href );

				if ( $host !== $home_host ) {
					$page['sheets_listed'][] = array( 'url' => $href, 'reason' => 'cross-origin — not fetched' );
					continue;
				}
				if ( false !== strpos( $href, '/wp-includes/' ) ) {
					$page['sheets_listed'][] = array( 'url' => $href, 'reason' => 'WordPress core — skipped' );
					continue;
				}

				if ( isset( $fetched_sheets[ $href ] ) ) {
					$page['sheets_listed'][] = array(
						'url'    => $href,
						'reason' => $fetched_sheets[ $href ] ? 'already captured above' : 'fetch failed earlier',
					);
					continue;
				}

				if ( count( $fetched_sheets ) >= self::MAX_LINKED ) {
					$page['sheets_listed'][] = array( 'url' => $href, 'reason' => 'fetch cap reached' );
					continue;
				}

				if ( $deadline - microtime( true ) < 2 ) {
					$page['sheets_listed'][] = array( 'url' => $href, 'reason' => 'capture time budget exhausted' );
					continue;
				}

				$sheet                   = self::fetch_stylesheet( $href, $deadline );
				$fetched_sheets[ $href ] = ( null !== $sheet );
				if ( null !== $sheet ) {
					$page['sheets'][] = $sheet;
				} else {
					$page['sheets_listed'][] = array( 'url' => $href, 'reason' => 'fetch failed or too large' );
				}
			}
		}

		return $page;
	}

	/** Fetch one same-origin stylesheet and preprocess it. */
	private static function fetch_stylesheet( $url, $deadline ) {
		$remaining = $deadline - microtime( true );
		if ( $remaining < 2 ) {
			return null;
		}
		$res = wp_remote_get(
			$url,
			array(
				'timeout'             => min( 8, max( 2, (int) $remaining ) ),
				'redirection'         => 2,
				'limit_response_size' => self::CAP_FILE_RAW + 1024,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$raw = (string) wp_remote_retrieve_body( $res );
		if ( '' === $raw || strlen( $raw ) > self::CAP_FILE_RAW ) {
			return null;
		}
		return array(
			'url'       => $url,
			'raw_bytes' => strlen( $raw ),
			'css'       => self::extract_color_css( $raw ),
		);
	}

	/**
	 * Resolve a stylesheet href to an absolute http(s) URL against its page.
	 * Root-relative and path-relative hrefs are same-origin by definition —
	 * comparing their (empty) host against the home host would misclassify
	 * them as cross-origin. Returns '' for non-http schemes.
	 */
	private static function resolve_url( $href, $page_url ) {
		$href = trim( $href );
		if ( '' === $href || preg_match( '~^(?:data|blob|javascript|about):~i', $href ) ) {
			return '';
		}
		if ( preg_match( '~^https?://~i', $href ) ) {
			return $href;
		}

		$scheme = wp_parse_url( $page_url, PHP_URL_SCHEME );
		$scheme = $scheme ? $scheme : 'https';

		if ( 0 === strpos( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}

		$host = wp_parse_url( $page_url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		$origin = $scheme . '://' . $host;
		$port   = wp_parse_url( $page_url, PHP_URL_PORT );
		if ( $port ) {
			$origin .= ':' . $port;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}

		// Path-relative: resolve against the page's directory (dot segments
		// are left for the server to normalize).
		$path = (string) wp_parse_url( $page_url, PHP_URL_PATH );
		$slash = strrpos( $path, '/' );
		$dir   = ( false === $slash ) ? '/' : substr( $path, 0, $slash + 1 );
		return $origin . $dir . $href;
	}

	/** Hostname without a leading www. */
	private static function bare_host( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return preg_replace( '~^www\.~i', '', strtolower( $host ) );
	}

	/* ---------------------------------------------------------------------
	 * CSS preprocessing
	 * ------------------------------------------------------------------- */

	/** Set when the last extract hit malformed or unbalanced input. */
	private static $extract_truncated = false;

	/**
	 * Reduce a stylesheet to its color-relevant rules.
	 *
	 * Best-effort, lossy by design: keeps custom-property definitions and any
	 * declaration whose property or value is color-related; preserves @media/
	 * @supports wrappers (and rules' own declarations alongside nested rules);
	 * drops @keyframes, @font-face, and everything else. Malformed input
	 * (unbalanced braces) is skipped past rather than aborting, and the output
	 * carries a note when that happened.
	 */
	public static function extract_color_css( $css ) {
		$css = (string) $css;
		if ( '' === trim( $css ) ) {
			return '';
		}

		self::$extract_truncated = false;

		$css = self::strip_comments( $css );
		$out = trim( self::extract_from_block( $css, 0 ) );

		if ( self::$extract_truncated ) {
			$out = trim( $out . "\n/* [bundle note: extraction hit malformed or unbalanced braces — some rules near that point may be missing] */" );
		}

		return $out;
	}

	/**
	 * Remove CSS comments without a regex — a single multi-megabyte comment
	 * (e.g. an inline base64 sourcemap) blows pcre.backtrack_limit and makes
	 * preg_replace return null, which would wipe the whole extract.
	 */
	private static function strip_comments( $css ) {
		$out = '';
		$pos = 0;
		$len = strlen( $css );

		while ( $pos < $len ) {
			$open = strpos( $css, '/*', $pos );
			if ( false === $open ) {
				$out .= substr( $css, $pos );
				break;
			}
			$out  .= substr( $css, $pos, $open - $pos );
			$close = strpos( $css, '*/', $open + 2 );
			if ( false === $close ) {
				break; // Unterminated comment: the rest is dead, as in a browser.
			}
			$pos = $close + 2;
		}

		return $out;
	}

	/** Recursive worker over one block of rules. */
	private static function extract_from_block( $css, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return '';
		}

		$out = '';
		$len = strlen( $css );
		$pos = 0;

		while ( $pos < $len ) {
			$brace = self::next_open_brace( $css, $pos );
			if ( false === $brace ) {
				break;
			}

			// Selector = text before the brace, after any statement (@import…;).
			$pre  = substr( $css, $pos, $brace - $pos );
			$semi = strrpos( $pre, ';' );
			$sel  = trim( false !== $semi ? substr( $pre, $semi + 1 ) : $pre );
			$sel  = preg_replace( '~\s+~', ' ', $sel );

			$end = self::find_matching_brace( $css, $brace );
			if ( false === $end ) {
				// Unbalanced input: note it and keep scanning past this brace so
				// later valid rules are not silently lost.
				self::$extract_truncated = true;
				$pos = $brace + 1;
				continue;
			}

			$body = substr( $css, $brace + 1, $end - $brace - 1 );
			$pos  = $end + 1;

			if ( '' === $sel ) {
				continue;
			}

			if ( '@' === $sel[0] && ! preg_match( '~^@(media|supports|container|layer|scope)\b~i', $sel ) ) {
				continue; // @keyframes, @font-face, @page, @property…: dropped.
			}

			$out .= self::render_rule( $sel, $body, $depth );
		}

		return $out;
	}

	/**
	 * Render one rule or conditional at-rule: keep its DIRECT color-relevant
	 * declarations and recurse into any nested blocks — native CSS nesting
	 * mixes both in one body, and dropping either loses palette information.
	 */
	private static function render_rule( $sel, $body, $depth ) {
		$has_blocks = ( false !== self::next_open_brace( $body, 0 ) );

		$direct = $has_blocks ? self::direct_declaration_text( $body ) : $body;
		$kept   = self::filter_declarations( $direct );
		$inner  = $has_blocks ? self::extract_from_block( $body, $depth + 1 ) : '';

		if ( empty( $kept ) && '' === $inner ) {
			return '';
		}

		if ( '' === $inner ) {
			return $sel . ' { ' . implode( '; ', $kept ) . '; }' . "\n";
		}

		$decls = empty( $kept ) ? '' : implode( '; ', $kept ) . ";\n";
		return $sel . " {\n" . $decls . $inner . "}\n";
	}

	/** Position of the next '{' outside quoted strings, or false. */
	private static function next_open_brace( $css, $pos ) {
		$len   = strlen( $css );
		$quote = '';

		for ( $i = $pos; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $ch ) {
					$i++;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
			} elseif ( '{' === $ch ) {
				return $i;
			}
		}

		return false;
	}

	/** The parts of a rule body that sit OUTSIDE its nested blocks. */
	private static function direct_declaration_text( $body ) {
		$out = '';
		$pos = 0;
		$len = strlen( $body );

		while ( $pos < $len ) {
			$brace = self::next_open_brace( $body, $pos );
			if ( false === $brace ) {
				$out .= substr( $body, $pos );
				break;
			}

			// Keep the pre-block text only up to its last ';' so the nested
			// block's selector doesn't leak into the declaration list.
			$pre  = substr( $body, $pos, $brace - $pos );
			$semi = strrpos( $pre, ';' );
			if ( false !== $semi ) {
				$out .= substr( $pre, 0, $semi + 1 );
			}

			$end = self::find_matching_brace( $body, $brace );
			if ( false === $end ) {
				self::$extract_truncated = true;
				break;
			}
			$pos = $end + 1;
		}

		return $out;
	}

	/** Find the '}' matching the '{' at $open, quote-aware. */
	private static function find_matching_brace( $css, $open ) {
		$len   = strlen( $css );
		$depth = 0;
		$quote = '';

		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $ch ) {
					$i++;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
			} elseif ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return false;
	}

	/** Split a declaration body on ';' (paren/quote-aware), keep color-relevant ones. */
	private static function filter_declarations( $body ) {
		$decls = array();
		$buf   = '';
		$paren = 0;
		$quote = '';
		$len   = strlen( $body );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $body[ $i ];

			if ( '' !== $quote ) {
				$buf .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buf .= $body[ ++$i ];
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
			} elseif ( '(' === $ch ) {
				$paren++;
			} elseif ( ')' === $ch ) {
				$paren--;
			} elseif ( ';' === $ch && 0 === $paren ) {
				$decls[] = $buf;
				$buf     = '';
				continue;
			}

			$buf .= $ch;
		}
		if ( '' !== trim( $buf ) ) {
			$decls[] = $buf;
		}

		$kept = array();
		foreach ( $decls as $decl ) {
			$colon = strpos( $decl, ':' );
			if ( false === $colon ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $decl, 0, $colon ) ) );
			$value = trim( substr( $decl, $colon + 1 ) );

			if ( '' === $prop || '' === $value ) {
				continue;
			}
			if ( self::keep_declaration( $prop, $value ) ) {
				$kept[] = $prop . ': ' . preg_replace( '~\s+~', ' ', $value );
			}
		}

		return $kept;
	}

	/** Is one declaration color-relevant? */
	private static function keep_declaration( $prop, $value ) {
		// Custom properties: always relevant (they carry the palette).
		if ( 0 === strpos( $prop, '--' ) ) {
			return true;
		}

		// A vendor prefix hides the real property name (-webkit-box-shadow,
		// -webkit-text-fill-color — the canonical dark-mode autofill fix).
		$bare = preg_replace( '~^-(?:webkit|moz|ms|o)-~', '', $prop );

		static $deny = array(
			'border-radius',
			'border-spacing',
			'border-collapse',
			'background-size',
			'background-position',
			'background-repeat',
			'background-attachment',
			'background-origin',
			'border-width',
			'border-style',
		);
		if ( in_array( $bare, $deny, true ) ) {
			return false;
		}

		static $keep_prefixes = array(
			'color',
			'background',
			'border',
			'outline',
			'box-shadow',
			'text-shadow',
			'text-decoration',
			'text-fill-color',
			'text-stroke',
			'fill',
			'stroke',
			'caret-color',
			'accent-color',
			'column-rule',
			'scrollbar-color',
			'opacity',
			'filter',
		);
		foreach ( $keep_prefixes as $p ) {
			if ( $bare === $p || 0 === strpos( $bare, $p . '-' ) ) {
				return true;
			}
		}

		// Any other property whose VALUE carries a color (e.g. a shorthand or
		// a vendor property outside the list) — hex/functions/named colors.
		return (bool) preg_match(
			'~#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(|\bhwb\(|\blab\(|\blch\(|\boklab\(|\boklch\(|\bcolor-mix\(|\blight-dark\(|\bvar\(--|currentColor'
			. '|\b(?:white|black|red|blue|green|yellow|orange|purple|pink|gr[ae]y|silver|gold|navy|teal|maroon|olive|aqua|fuchsia|lime|cyan|magenta|brown|beige|ivory|coral|crimson|indigo|violet|khaki|salmon|tan|turquoise|plum|orchid|lavender|azure|snow|linen|wheat)\b'
			. '|\btransparent\b~i',
			$value
		);
	}

	/* ---------------------------------------------------------------------
	 * Secret redaction (best-effort)
	 * ------------------------------------------------------------------- */

	/**
	 * Redact likely secrets from PHP source before it leaves the site.
	 * Best-effort only — the settings page tells the user to review the file
	 * before sharing it externally.
	 */
	public static function redact_secrets( $php ) {
		$php = (string) $php;

		// key/secret/token/password assignments with a quoted, token-shaped
		// value on the SAME line. Three guards keep this from eating real code:
		// the in-between class excludes quotes (a keyword inside a string
		// literal or array key can't reach across its own closing quote — an
		// optional ['"]\s*\] still allows the $a['password'] = '…' form), the
		// value class excludes spaces and newlines (concatenations like
		// "token: " . $x are never consumed), and \b anchors the keyword.
		$redacted = preg_replace(
			'~(\b(?:api[_-]?key|apikey|secret|passw(?:or)?d|token|auth[_-]?key|bearer|client[_-]?secret|private[_-]?key)(?:[\'"]\s*\])?[^\n=:(\'"]{0,40}[=:]>?\s*[\'"])([A-Za-z0-9+/=_.\-:!@#$%^&*]{8,})([\'"])~i',
			'$1[REDACTED]$3',
			$php
		);
		if ( null !== $redacted ) {
			$php = $redacted; // Bounded classes can't hit backtrack limits; guard anyway.
		}

		// define( 'SOMETHING_KEY', 'value' ) style constants.
		$redacted = preg_replace(
			'~(define\s*\(\s*[\'"][^\'"]*(?:KEY|SECRET|TOKEN|PASS)[^\'"]*[\'"]\s*,\s*[\'"])([^\'"\n\r]{8,})([\'"])~i',
			'$1[REDACTED]$3',
			$php
		);
		if ( null !== $redacted ) {
			$php = $redacted;
		}

		return $php;
	}

	/* ---------------------------------------------------------------------
	 * Markdown rendering
	 * ------------------------------------------------------------------- */

	/** Render the assembled bundle as one Markdown document. */
	public static function to_markdown( array $bundle ) {
		$md = '';

		$md .= self::md_intro( $bundle['meta'] );
		$md .= self::md_gp_config( $bundle['gp_config'] );
		$md .= self::md_plugin_state( $bundle['plugin_state'] );
		$md .= self::md_child_theme( $bundle['child_theme'] );
		$md .= self::md_rendered( $bundle['rendered'] );

		$md .= "\n---\nEnd of bundle. Approximate size: " . size_format( strlen( $md ) )
			. ' (~' . number_format( strlen( $md ) / 4 ) . " tokens).\n";

		return $md;
	}

	private static function md_intro( array $meta ) {
		$md  = "# GP Dark Mode — AI Context Bundle\n\n";
		$md .= 'Generated ' . $meta['generated_utc'] . " UTC by GP Dark Mode v" . $meta['plugin_version']
			. ' for ' . $meta['site'] . ' (' . $meta['home_url'] . ")\n\n";

		$md .= "## How to use this bundle\n\n";
		$md .= "Give this file to an AI assistant and ask it to review, extend, or repair this site's dark mode. The contract:\n\n";
		$md .= "- Dark mode is toggled by `data-theme=\"dark\"` on `<html>`; every dark override is scoped `html[data-theme=\"dark\"] …`.\n";
		if ( 'active' === $meta['generatepress'] ) {
			$md .= "- The plugin prints its framework + Additional CSS inline on the `generate-style` handle — BEFORE GeneratePress's dynamic CSS (which defines the Global Colors as CSS variables, e.g. `--accent`, `--base-2`, `--contrast`) and before the child theme's `style.css`. The site's own styles therefore win specificity ties.\n";
		} else {
			$md .= "- **GeneratePress was NOT detected on this site.** The framework + Additional CSS load as a LATE standalone stylesheet instead, so the plugin's rules win specificity ties against the theme's — the reverse of the GeneratePress cascade described in the plugin's docs.\n";
		}
		$md .= "- The plugin's palette tokens are `--bg`, `--bg-2`, `--surface`, `--surface-2`, `--text`, `--text-muted`, `--border`, `--link`, `--dm-accent` (light values on `:root`, dark values on `html[data-theme=\"dark\"]`).\n";
		$md .= "- GeneratePress's own Global Color variables may also be redefined under `html[data-theme=\"dark\"]` — high leverage, since GP paints most chrome with them.\n";
		$md .= "- Deliverable: a COMPLETE replacement for the **Current AI stylesheet** (Layer 4a) section only. The **Manual CSS** (Layer 4b) is the owner's layer — it loads after yours and always wins; treat it as read-only context. Never propose edits to theme files.\n";
		$md .= "- The static CSS below shows what is *written*, not what *wins* the cascade. Mark any proposal that depends on specificity or cascade order as needing visual verification.\n";
		$md .= "- Stylesheets in this bundle are reduced to color-relevant declarations (best-effort); `@keyframes`/`@font-face` and layout properties are omitted on purpose.\n\n";

		$md .= "## 1. Site\n\n" . self::fence( self::json( $meta ), 'json' );
		return $md;
	}

	private static function md_gp_config( array $gp ) {
		$md  = "## 2. GeneratePress color configuration (from the database)\n\n";
		$md .= "### Global Colors\n\n" . self::fence( self::json( $gp['global_colors'] ), 'json' );
		$md .= "### Other color settings\n\n" . self::fence( self::json( $gp['color_settings'] ), 'json' );
		return $md;
	}

	private static function md_plugin_state( array $state ) {
		$md  = "## 3. GP Dark Mode plugin state\n\n";
		$md .= "Settings:\n\n" . self::fence( self::json( $state['settings'] ), 'json' );
		$md .= 'Additional CSS source: ' . $state['additional_css_source'] . "\n\n";
		if ( empty( $state['settings']['enable_ai_css'] ) ) {
			$md .= "**WARNING: the AI stylesheet output toggle is currently OFF** — the deliverable layer is stored but NOT printed on the front end. Enable it (GP Dark Mode → AI tab) before applying any proposal, or the changes will silently do nothing.\n\n";
		}
		if ( empty( $state['settings']['enable_custom_css'] ) ) {
			$md .= "Note: the Manual CSS output toggle is OFF — the Manual layer below is stored but not printed on the front end.\n\n";
		}
		$md .= "### Core framework CSS (Layer 1 — shipped, loads first)\n\n" . self::fence( $state['framework_css'], 'css' );

		if ( '' !== trim( $state['generated_css'] ) ) {
			$enabled = empty( $state['settings']['enable_generated'] ) ? 'currently DISABLED — not printed on the front end' : 'enabled';
			$md     .= "### Generated dark palette (Layer 2 — {$enabled})\n\n" . self::fence( $state['generated_css'], 'css' );
		}

		if ( ! empty( $state['modules_active'] ) ) {
			$md .= '### Enabled integration modules (Layer 3): ' . implode( ', ', $state['modules_active'] ) . "\n\n";
			$md .= "These modules already cover their vendors' generic selectors — do NOT duplicate them in the deliverable.\n\n";
			$md .= self::fence( $state['modules_css'], 'css' );
		}

		$md .= "### Current AI stylesheet (Layer 4a — machine-owned; THE DELIVERABLE REPLACES THIS)\n\n" . self::fence( $state['ai_css'], 'css' );

		$md .= "### Manual CSS (Layer 4b — the site owner's hand-written layer; loads LAST and always wins. READ-ONLY CONTEXT: never duplicate it, work around it)\n\n" . self::fence( $state['additional_css'], 'css' );
		return $md;
	}

	private static function md_child_theme( array $child ) {
		$md = "## 4. Child theme\n\n";

		$md .= "### style.css (color-relevant extract)\n\n";
		$md .= self::md_css_entry( $child['style_css'] );

		$md .= "### functions.php (verbatim, likely secrets redacted — REVIEW BEFORE SHARING)\n\n";
		$fn = $child['functions_php'];
		if ( isset( $fn['error'] ) ) {
			$md .= '_' . $fn['error'] . "_\n\n";
		} else {
			if ( ! empty( $fn['truncated'] ) ) {
				$md .= '_Truncated to ' . size_format( self::CAP_FUNCTIONS ) . "._\n\n";
			}
			$md .= self::fence( $fn['php'], 'php' );
		}

		if ( '' !== $child['customizer_css'] ) {
			$md .= "### Customizer Additional CSS\n\n" . self::fence( $child['customizer_css'], 'css' );
		}

		$md .= "_The GeneratePress parent stylesheet is intentionally omitted — it is generic framework CSS; its effective colors come from the Global Colors above and appear in the rendered capture below._\n\n";

		return $md;
	}

	private static function md_rendered( array $rendered ) {
		$md = "## 5. Rendered front-end CSS capture\n\n";

		foreach ( $rendered['pages'] as $page ) {
			$md .= '### Page: ' . $page['url'] . "\n\n";

			if ( isset( $page['error'] ) ) {
				$md .= '_Fetch failed: ' . $page['error'] . ". The bundle is still usable; this page's printed CSS is simply not captured._\n\n";
				continue;
			}

			if ( ! empty( $page['inline'] ) ) {
				$md .= "#### Inline `<style>` blocks (color-relevant extracts)\n\n";
				foreach ( $page['inline'] as $inline ) {
					$md .= '**' . $inline['id'] . '** (' . size_format( $inline['raw_bytes'] ) . " raw)\n\n";
					$md .= self::fence( $inline['css'], 'css' );
				}
			}
			if ( $page['inline_empty'] || $page['inline_dupes'] ) {
				$md .= '_' . (int) $page['inline_empty'] . ' inline block(s) had no color-relevant rules; '
					. (int) $page['inline_dupes'] . " duplicated earlier blocks._\n\n";
			}

			if ( ! empty( $page['sheets'] ) ) {
				$md .= "#### Linked stylesheets (fetched, color-relevant extracts)\n\n";
				foreach ( $page['sheets'] as $sheet ) {
					$md .= '**' . $sheet['url'] . '** (' . size_format( $sheet['raw_bytes'] ) . " raw)\n\n";
					$md .= self::fence( $sheet['css'], 'css' );
				}
			}

			if ( ! empty( $page['sheets_listed'] ) ) {
				$md .= "#### Other stylesheets on this page (not fetched)\n\n";
				foreach ( $page['sheets_listed'] as $listed ) {
					$md .= '- ' . $listed['url'] . ' — ' . $listed['reason'] . "\n";
				}
				$md .= "\n";
			}
		}

		return $md;
	}

	/** Render one read_css_file() result. */
	private static function md_css_entry( array $entry ) {
		if ( isset( $entry['error'] ) ) {
			return '_' . $entry['error'] . "_\n\n";
		}
		if ( '' === $entry['css'] ) {
			return "_No color-relevant rules found (" . size_format( $entry['raw_bytes'] ) . " raw)._\n\n";
		}
		return '(' . size_format( $entry['raw_bytes'] ) . " raw)\n\n" . self::fence( $entry['css'], 'css' );
	}

	/**
	 * Fenced code block whose fence is longer than any backtick run in the
	 * content — a verbatim functions.php (or round-tripped Additional CSS)
	 * containing a ``` line must not close the fence early and garble the
	 * rest of the document.
	 */
	private static function fence( $content, $lang = '' ) {
		$content = trim( (string) $content );
		$longest = 0;
		if ( preg_match_all( '~`+~', $content, $m ) ) {
			foreach ( $m[0] as $run ) {
				$longest = max( $longest, strlen( $run ) );
			}
		}
		$f = str_repeat( '`', max( 3, $longest + 1 ) );
		return $f . $lang . "\n" . $content . "\n" . $f . "\n\n";
	}

	/** Pretty JSON without escaped slashes. */
	private static function json( $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return false === $json ? '{}' : $json;
	}
}
