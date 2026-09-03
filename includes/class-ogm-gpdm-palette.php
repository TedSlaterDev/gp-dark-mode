<?php
/**
 * GP Dark Mode — Layer 2: generated dark palette.
 *
 * Reads GeneratePress's Global Colors from the database and derives dark
 * counterparts deterministically (no AI): luminance inverted, hue preserved,
 * with a WCAG contrast floor for text colors. The output redefines GP's own
 * CSS variables under html[data-theme="dark"], so everything GP (and
 * GenerateBlocks) paints with those variables flips wholesale.
 *
 * Opt-in by design: enabling it changes rendering wherever GP variables are
 * used, so the admin generates, reviews, then enables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_Palette {

	/** Reference dark surface used for contrast checks (framework --bg). */
	const DARK_REF = '#0f0f12';

	/* ---------------------------------------------------------------------
	 * Generation
	 * ------------------------------------------------------------------- */

	/**
	 * Build the generated-palette CSS from the site's GP Global Colors.
	 * Returns '' when there is nothing usable to generate from.
	 */
	public static function generate() {
		$colors = self::global_colors();
		if ( empty( $colors ) ) {
			return '';
		}

		$palette = self::derive_palette( $colors, self::usage_roles( $colors ) );

		if ( empty( $palette['map'] ) ) {
			return '';
		}

		$lines = array();
		foreach ( $palette['map'] as $slug => $entry ) {
			$lines[] = "\t--" . $slug . ': ' . $entry['dark'] . '; /* from ' . $entry['from'] . ' (' . $entry['class'] . ') */';
		}

		$css  = "/* Generated dark palette (Layer 2) — derived from the GeneratePress Global\n";
		$css .= "   Colors. Regenerate from Settings → GP Dark Mode after changing them. */\n";
		$css .= "html[data-theme=\"dark\"] {\n" . implode( "\n", $lines ) . "\n}\n";

		if ( ! empty( $palette['kept'] ) ) {
			$css .= '/* Left unchanged (already dark-mode-appropriate as-is): ' . implode( ', ', $palette['kept'] ) . " */\n";
		}
		if ( ! empty( $palette['unclassified'] ) ) {
			$css .= '/* Left unchanged (could not classify — review these manually in dark mode): ' . implode( ', ', $palette['unclassified'] ) . " */\n";
		}
		if ( ! empty( $palette['unparseable'] ) ) {
			$css .= '/* Skipped (unsupported color format): ' . implode( ', ', $palette['unparseable'] ) . " */\n";
		}

		return $css;
	}

	/**
	 * Derive dark counterparts for a set of Global Colors AS A SET.
	 *
	 * Naive per-color lightness inversion collapses GP's near-white surface
	 * ramp (base #f0f0f0 / base-2 #f7f8f9 / base-3 #ffffff) into one
	 * indistinguishable near-black, and does the same to the text tiers — so
	 * surfaces and texts are RANKED and assigned spaced dark lightness values
	 * that preserve each ramp's (inverted) ordering.
	 *
	 * @param array $colors GP global colors: [{slug, color}, …].
	 * @param array $usage  Optional usage_roles() result: slug => roles. How a
	 *                      color is actually used overrides its slug name.
	 * @return array {map: slug => {dark, from, class}, kept: [], unclassified: [], unparseable: []}
	 */
	public static function derive_palette( array $colors, array $usage = array() ) {
		$out = array(
			'map'          => array(),
			'kept'         => array(),
			'unclassified' => array(),
			'unparseable'  => array(),
		);

		$surfaces = array();
		$texts    = array();

		foreach ( $colors as $color ) {
			$slug  = isset( $color['slug'] ) ? (string) $color['slug'] : '';
			$value = isset( $color['color'] ) ? (string) $color['color'] : '';
			if ( '' === $slug ) {
				continue;
			}

			$rgb = self::parse_color( $value );
			if ( null === $rgb ) {
				if ( '' !== trim( $value ) ) {
					$out['unparseable'][] = '--' . $slug . ' (' . trim( $value ) . ')';
				}
				continue;
			}

			$hex   = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
			$class = self::classify( $hex, $slug );
			$hsl   = self::hex_to_hsl( $hex );

			// How the site actually USES a color beats what its slug is named
			// (live case: --contrast, a "text" slug, painting the site header's
			// background — inverting it as text turned the header light in dark
			// mode). Utility and brand classes are exempt: white/black anchors
			// must not flip, and graying a saturated accent into the surface
			// ramp would destroy it.
			if ( 'utility' !== $class && 'brand' !== $class ) {
				$used_surface = in_array( 'surface', isset( $usage[ strtolower( $slug ) ] ) ? $usage[ strtolower( $slug ) ] : array(), true );
				$used_text    = in_array( 'text', isset( $usage[ strtolower( $slug ) ] ) ? $usage[ strtolower( $slug ) ] : array(), true );
				if ( $used_surface && $used_text ) {
					$out['kept'][] = '--' . $slug . ' (' . $hex . ', used as both background and text - left unchanged; the framework\'s own tokens keep dark-mode text readable)';
					continue;
				} elseif ( $used_surface ) {
					$class = 'surface';
				} elseif ( $used_text ) {
					$class = 'text';
				}
			}

			if ( 'utility' === $class ) {
				$out['kept'][] = '--' . $slug . ' (' . $hex . ', utility color)';
			} elseif ( 'surface' === $class ) {
				// The mirror of the already-light-text rule below: a surface
				// that is ALREADY dark in light mode (dark headers/footers are
				// a common light-mode design) is dark-mode-appropriate as it
				// stands — the inversion ramp would LIGHTEN it.
				if ( $hsl[2] <= 0.32 ) {
					$out['kept'][] = '--' . $slug . ' (' . $hex . ', already dark)';
				} else {
					$surfaces[] = array( 'slug' => $slug, 'hex' => $hex, 'hsl' => $hsl );
				}
			} elseif ( 'text' === $class ) {
				// A text color that is ALREADY light is dark-mode-appropriate
				// as it stands (e.g. white button text, GP's tertiary gray) —
				// darkening it would break exactly the places it's used.
				if ( $hsl[2] >= 0.65 && self::contrast_ratio( $hex, self::DARK_REF ) >= 4.5 ) {
					$out['kept'][] = '--' . $slug . ' (' . $hex . ', already readable on dark)';
				} else {
					$texts[] = array( 'slug' => $slug, 'hex' => $hex, 'hsl' => $hsl );
				}
			} elseif ( 'brand' === $class ) {
				if ( self::contrast_ratio( $hex, self::DARK_REF ) >= 3 ) {
					$out['kept'][] = '--' . $slug . ' (' . $hex . ')';
				} else {
					$dark = self::derive_dark( $hex, $slug );
					if ( null !== $dark ) {
						$out['map'][ $slug ] = array( 'dark' => $dark, 'from' => $hex, 'class' => 'brand, lightened to >= 3:1' );
					} else {
						$out['kept'][] = '--' . $slug . ' (' . $hex . ')';
					}
				}
			} else {
				$out['unclassified'][] = '--' . $slug . ' (' . $hex . ')';
			}
		}

		// Surfaces: lightest light-mode surface becomes the darkest dark
		// surface; each rank is spaced so the ramp stays visually distinct.
		usort( $surfaces, function ( $a, $b ) {
			return $b['hsl'][2] <=> $a['hsl'][2];
		} );
		foreach ( $surfaces as $i => $surface ) {
			$l = min( 0.35, 0.07 + 0.045 * $i );
			$out['map'][ $surface['slug'] ] = array(
				'dark'  => self::hsl_to_hex( $surface['hsl'][0], min( $surface['hsl'][1], 0.30 ), $l ),
				'from'  => $surface['hex'],
				'class' => 'surface, rank ' . ( $i + 1 ),
			);
		}

		// Texts: darkest light-mode text (the primary color) becomes the
		// lightest dark text; ranks step down but keep WCAG floors
		// (4.5:1 for the primary, 3:1 for secondary/muted tiers).
		usort( $texts, function ( $a, $b ) {
			return $a['hsl'][2] <=> $b['hsl'][2];
		} );
		$prev_l = null;
		foreach ( $texts as $i => $text ) {
			$l = max( 0.55, 0.92 - 0.10 * $i );
			if ( null !== $prev_l ) {
				$l = min( $l, $prev_l - 0.04 ); // Never collide with the tier above.
			}
			// Neutral text tiers stay muted, but a DELIBERATELY colored text
			// slot (e.g. a brand red living in contrast-2) keeps its hue
			// strength — desaturating it to 0.20 turns red into muddy pink.
			$s     = min( $text['hsl'][1], $text['hsl'][1] >= 0.40 ? 0.55 : 0.20 );
			$floor = ( 0 === $i ) ? 4.5 : 3.0;
			$dark  = self::hsl_to_hex( $text['hsl'][0], $s, $l );
			$guard = 0;
			while ( self::contrast_ratio( $dark, self::DARK_REF ) < $floor && $l < 0.98 && $guard < 25 ) {
				$l    += 0.02;
				$dark  = self::hsl_to_hex( $text['hsl'][0], $s, $l );
				$guard++;
			}
			$prev_l = $l;

			$out['map'][ $text['slug'] ] = array(
				'dark'  => $dark,
				'from'  => $text['hex'],
				'class' => 'text, rank ' . ( $i + 1 ),
			);
		}

		return $out;
	}

	/**
	 * Where each global color is actually USED on this site — slug names lie
	 * (live-verified: a site painted its header background with var(--contrast),
	 * a "text"-named color; inverting it as text turned the dark header light
	 * in dark mode). Sources, strongest first:
	 *
	 * 1. GP settings values: a background-ish key holding var(--slug) or the
	 *    color's literal hex marks a SURFACE role; text/title/link keys mark
	 *    a TEXT role.
	 * 2. Hand-written CSS (Customizer Additional CSS + child style.css):
	 *    background*: var(--slug) → surface; color: var(--slug) → text.
	 *
	 * @param array $colors GP global colors.
	 * @return array slug => string[] roles ('surface'/'text').
	 */
	public static function usage_roles( array $colors ) {
		$roles = array();
		$note  = function ( $slug, $role ) use ( &$roles ) {
			$slug = strtolower( (string) $slug );
			if ( ! isset( $roles[ $slug ] ) ) {
				$roles[ $slug ] = array();
			}
			if ( ! in_array( $role, $roles[ $slug ], true ) ) {
				$roles[ $slug ][] = $role;
			}
		};

		$hex_to_slug = array();
		foreach ( $colors as $color ) {
			if ( ! empty( $color['slug'] ) && ! empty( $color['color'] ) && null !== self::parse_color( $color['color'] ) ) {
				$rgb = self::parse_color( $color['color'] );
				$hex_to_slug[ sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] ) ] = strtolower( (string) $color['slug'] );
			}
		}

		// 1) GP settings.
		foreach ( array( get_option( 'generate_settings', array() ), get_option( 'generate_background_settings', array() ) ) as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}
			foreach ( $set as $key => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				if ( false !== stripos( (string) $key, 'background' ) ) {
					$role = 'surface';
				} elseif ( preg_match( '~text|title|link~i', (string) $key ) ) {
					$role = 'text';
				} else {
					continue;
				}
				if ( preg_match( '~var\(\s*--([a-z0-9_-]+)~i', $value, $m ) ) {
					$note( $m[1], $role );
				} else {
					$rgb = self::parse_color( $value );
					if ( null !== $rgb ) {
						$hex = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
						if ( isset( $hex_to_slug[ $hex ] ) ) {
							$note( $hex_to_slug[ $hex ], $role );
						}
					}
				}
			}
		}

		// 2) Hand-written CSS.
		$css = function_exists( 'wp_get_custom_css' ) ? (string) wp_get_custom_css() : '';
		$file = get_stylesheet_directory() . '/style.css';
		if ( is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			$css .= "\n" . file_get_contents( $file );
		}
		if ( '' !== trim( $css ) ) {
			foreach ( $colors as $color ) {
				if ( empty( $color['slug'] ) ) {
					continue;
				}
				$slug = preg_quote( strtolower( (string) $color['slug'] ), '~' );
				if ( preg_match( '~background[a-z-]*\s*:\s*[^;{}]*var\(\s*--' . $slug . '\s*[),]~i', $css ) ) {
					$note( $color['slug'], 'surface' );
				}
				if ( preg_match( '~(?<![a-z-])color\s*:\s*[^;{}]*var\(\s*--' . $slug . '\s*[),]~i', $css ) ) {
					$note( $color['slug'], 'text' );
				}
			}
		}

		return $roles;
	}

	/** GP Global Colors as array of {name, slug, color}. */
	private static function global_colors() {
		if ( function_exists( 'generate_get_global_colors' ) ) {
			$colors = generate_get_global_colors();
			if ( is_array( $colors ) && ! empty( $colors ) ) {
				return $colors;
			}
		}
		$settings = get_option( 'generate_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['global_colors'] ) && is_array( $settings['global_colors'] ) ) {
			return $settings['global_colors'];
		}
		return array();
	}

	/* ---------------------------------------------------------------------
	 * Derivation
	 * ------------------------------------------------------------------- */

	/**
	 * Classify a global color by its slug (GP's semantic naming), falling
	 * back to lightness for custom slugs.
	 */
	public static function classify( $hex, $slug ) {
		$slug = strtolower( (string) $slug );

		// Utility colors: intent is "this exact color", not a themed role —
		// inverting a color literally named "white" breaks its usages.
		if ( preg_match( '~^(?:white|black)$~', $slug ) ) {
			return 'utility';
		}

		if ( preg_match( '~contrast|text~', $slug ) ) {
			return 'text';
		}
		if ( preg_match( '~base|background|bg|surface~', $slug ) ) {
			return 'surface';
		}
		if ( preg_match( '~accent|primary|brand|link|button~', $slug ) ) {
			return 'brand';
		}

		$hsl = self::hex_to_hsl( $hex );
		if ( null === $hsl ) {
			return 'unknown';
		}
		// Clearly chromatic colors are brand colors regardless of lightness —
		// a dark saturated red named "dominant" must NOT be flattened into a
		// desaturated text gray by the lightness fallback below.
		if ( $hsl[1] >= 0.40 ) {
			return 'brand';
		}
		if ( $hsl[2] >= 0.80 ) {
			return 'surface';
		}
		if ( $hsl[2] <= 0.30 ) {
			return 'text';
		}
		return 'unknown';
	}

	/**
	 * Derive the dark-mode counterpart of ONE color in isolation.
	 * Returns a hex string, or null when the color should be left unchanged.
	 *
	 * NOTE: for a full palette, use derive_palette() — deriving each color
	 * independently collapses GP's surface/text ramps into indistinguishable
	 * values; the palette-level method spaces the ranks.
	 */
	public static function derive_dark( $hex, $slug ) {
		$hsl = self::hex_to_hsl( $hex );
		if ( null === $hsl ) {
			return null;
		}

		list( $h, $s, $l ) = $hsl;
		$class = self::classify( $hex, $slug );

		if ( 'surface' === $class ) {
			// Already-dark surfaces are dark-mode-appropriate as-is.
			if ( $l <= 0.32 ) {
				return null;
			}
			// Invert lightness so the surface ramp keeps its ordering
			// (lightest surface becomes darkest), clamped away from pure black.
			$l = max( 0.05, min( 0.90, 1 - $l ) );
			// Near-white surfaces carry meaningless hue; keep them neutral-ish.
			$s = min( $s, 0.30 );
			return self::hsl_to_hex( $h, $s, $l );
		}

		if ( 'text' === $class ) {
			// Already-light text is dark-mode-appropriate as-is.
			if ( $l >= 0.65 && self::contrast_ratio( $hex, self::DARK_REF ) >= 4.5 ) {
				return null;
			}
			$l   = max( 0.70, min( 0.95, 1 - $l ) );
			$s   = min( $s, $s >= 0.40 ? 0.55 : 0.20 ); // Colored text keeps its hue strength.
			$out = self::hsl_to_hex( $h, $s, $l );
			// WCAG floor: body text must reach 4.5:1 against the dark surface.
			$guard = 0;
			while ( self::contrast_ratio( $out, self::DARK_REF ) < 4.5 && $l < 0.98 && $guard < 20 ) {
				$l  += 0.02;
				$out = self::hsl_to_hex( $h, $s, $l );
				$guard++;
			}
			return $out;
		}

		if ( 'brand' === $class ) {
			// Brand colors keep their identity. Only lighten ones too dark to
			// register on a dark page (3:1 is the large-text/UI floor).
			if ( self::contrast_ratio( $hex, self::DARK_REF ) >= 3 ) {
				return null; // Readable as-is — leave the brand color alone.
			}
			$out   = $hex;
			$guard = 0;
			while ( self::contrast_ratio( $out, self::DARK_REF ) < 3 && $l < 0.75 && $guard < 30 ) {
				$l  += 0.02;
				$out = self::hsl_to_hex( $h, $s, $l );
				$guard++;
			}
			return strtolower( $out ) === strtolower( self::normalize_hex( $hex ) ) ? null : $out;
		}

		return null; // Unknown mid-range color: safer untouched.
	}

	/* ---------------------------------------------------------------------
	 * Color math
	 * ------------------------------------------------------------------- */

	/**
	 * Parse a CSS color value into [r,g,b]: hex, rgb(), or rgba() — GP allows
	 * rgb()/rgba() in Global Colors, and silently skipping one can leave a
	 * light surface un-darkened under flipped light text. Alpha is dropped
	 * (composited output would depend on what's behind); anything else
	 * (hsl(), gradients, keywords) returns null and is reported as skipped.
	 */
	public static function parse_color( $value ) {
		$value = trim( (string) $value );

		$rgb = self::hex_to_rgb( $value );
		if ( null !== $rgb ) {
			return $rgb;
		}

		if ( preg_match( '~^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})~i', $value, $m ) ) {
			$r = (int) $m[1];
			$g = (int) $m[2];
			$b = (int) $m[3];
			if ( $r <= 255 && $g <= 255 && $b <= 255 ) {
				return array( $r, $g, $b );
			}
		}

		return null;
	}

	/** '#abc' / '#aabbcc' → [r,g,b] 0-255, or null. */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( preg_match( '~^[0-9a-f]{3}$~i', $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '~^[0-9a-f]{6}$~i', $hex ) ) {
			return null;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Lowercase 6-digit '#rrggbb' form of a hex color (input assumed valid). */
	public static function normalize_hex( $hex ) {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return strtolower( (string) $hex );
		}
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}

	/** Hex → [h 0-1, s 0-1, l 0-1], or null. */
	public static function hex_to_hsl( $hex ) {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return null;
		}

		$r = $rgb[0] / 255;
		$g = $rgb[1] / 255;
		$b = $rgb[2] / 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;

		if ( $max === $min ) {
			return array( 0.0, 0.0, $l );
		}

		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return array( $h / 6, $s, $l );
	}

	/** [h,s,l] 0-1 → '#rrggbb'. */
	public static function hsl_to_hex( $h, $s, $l ) {
		if ( 0.0 === (float) $s ) {
			$v = (int) round( $l * 255 );
			return sprintf( '#%02x%02x%02x', $v, $v, $v );
		}

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		$to_rgb = function ( $t ) use ( $p, $q ) {
			if ( $t < 0 ) {
				$t += 1;
			}
			if ( $t > 1 ) {
				$t -= 1;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};

		return sprintf(
			'#%02x%02x%02x',
			(int) round( $to_rgb( $h + 1 / 3 ) * 255 ),
			(int) round( $to_rgb( $h ) * 255 ),
			(int) round( $to_rgb( $h - 1 / 3 ) * 255 )
		);
	}

	/** WCAG relative luminance of a hex color. */
	public static function relative_luminance( $hex ) {
		$rgb = self::hex_to_rgb( $hex );
		if ( null === $rgb ) {
			return 0.0;
		}
		$chan = array();
		foreach ( $rgb as $c ) {
			$c      = $c / 255;
			$chan[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
	}

	/** WCAG contrast ratio between two hex colors (1–21). */
	public static function contrast_ratio( $hex_a, $hex_b ) {
		$la = self::relative_luminance( $hex_a );
		$lb = self::relative_luminance( $hex_b );
		$lighter = max( $la, $lb );
		$darker  = min( $la, $lb );
		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}
}
