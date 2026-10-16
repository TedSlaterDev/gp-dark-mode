<?php
/**
 * GP Dark Mode — measured contrast check (1.10.0).
 *
 * The check itself runs in the site owner's browser on the AI tab
 * (assets/admin/gp-dark-mode-audit.js): each page is fetched as a logged-out
 * visitor, rendered in a hidden, script-less iframe, and measured in light and
 * dark mode — text contrast against its real background, large light panels
 * left in dark mode, and hover/focus states emulated from the site's own CSS.
 * This class picks the pages, validates and stores the result, and turns it
 * into Markdown for the AI Context Bundle and the Claude in Chrome prompt.
 *
 * Why in the browser: a WordPress host can't render pages; the admin's
 * browser can, and an iframe without allow-scripts runs no ad or analytics
 * code, so the check never creates an ad impression or a pageview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_Audit {

	/** The latest check (unregistered, non-autoloaded — admin-only state). */
	const OPTION = 'ogm_gpdm_audit';

	/** Caps on what's stored: enough for a model, bounded for the option. */
	const MAX_PAGES       = 10;
	const MAX_FAILURES    = 60; // Per page.
	const MAX_ISLANDS     = 20; // Per page.
	const MAX_PROMPT_ROWS = 40; // Failures listed in a prompt, worst first.

	/**
	 * The pages to check: one of each template a GeneratePress site usually
	 * styles differently. Filterable via ogm_gpdm_audit_urls.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public static function pages() {
		$pages = array(
			array(
				'label' => __( 'Homepage', 'gp-dark-mode' ),
				'url'   => home_url( '/' ),
			),
		);

		$post = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			)
		);
		if ( ! empty( $post ) && get_permalink( $post[0] ) ) {
			$pages[] = array(
				'label' => __( 'Latest post', 'gp-dark-mode' ),
				'url'   => get_permalink( $post[0] ),
			);
		}

		$skip = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$page = get_posts(
			array(
				'post_type'        => 'page',
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'exclude'          => $skip,
				'suppress_filters' => false,
			)
		);
		if ( ! empty( $page ) && get_permalink( $page[0] ) ) {
			$pages[] = array(
				'label' => __( 'A page', 'gp-dark-mode' ),
				'url'   => get_permalink( $page[0] ),
			);
		}

		if ( function_exists( 'get_categories' ) ) {
			$cats = get_categories(
				array(
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 1,
					'hide_empty' => true,
				)
			);
			if ( ! empty( $cats ) && function_exists( 'get_category_link' ) ) {
				$link = get_category_link( $cats[0] );
				if ( $link && ! is_wp_error( $link ) ) {
					$pages[] = array(
						'label' => __( 'Category archive', 'gp-dark-mode' ),
						'url'   => $link,
					);
				}
			}
		}

		$pages[] = array(
			'label' => __( 'Search results', 'gp-dark-mode' ),
			'url'   => add_query_arg( 's', 'the', home_url( '/' ) ),
		);
		$pages[] = array(
			'label' => __( 'Not found (404)', 'gp-dark-mode' ),
			'url'   => home_url( '/gpdm-contrast-check-not-found/' ),
		);

		/**
		 * Filter the pages the contrast check measures.
		 *
		 * @param array $pages List of ['label' => string, 'url' => string].
		 */
		$pages = apply_filters( 'ogm_gpdm_audit_urls', $pages );

		$out = array();
		foreach ( (array) $pages as $p ) {
			if ( is_array( $p ) && ! empty( $p['url'] ) && self::is_own_url( $p['url'] ) ) {
				$out[] = array(
					'label' => sanitize_text_field( isset( $p['label'] ) ? (string) $p['label'] : (string) $p['url'] ),
					'url'   => esc_url_raw( (string) $p['url'] ),
				);
			}
			if ( count( $out ) >= self::MAX_PAGES ) {
				break;
			}
		}
		return $out;
	}

	/** True for a URL on this site's own host (the check fetches it from the admin page). */
	public static function is_own_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) && is_string( $home ) && strtolower( $host ) === strtolower( $home );
	}

	/** The stored check, or null. */
	public static function get() {
		$audit = get_option( self::OPTION, null );
		return ( is_array( $audit ) && ! empty( $audit['pages'] ) && is_array( $audit['pages'] ) ) ? $audit : null;
	}

	/**
	 * Validate a check posted by the browser and store it. Everything is
	 * rebuilt field by field from known keys — the payload comes from the
	 * admin's browser, but it ends up inside AI prompts and admin HTML.
	 *
	 * @param string $json The browser's result.
	 * @return array|WP_Error The stored check.
	 */
	public static function save_from_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return new WP_Error( 'ogm_gpdm_audit_shape', __( 'The contrast check returned no pages.', 'gp-dark-mode' ) );
		}

		$pages = array();
		foreach ( array_slice( $data['pages'], 0, self::MAX_PAGES ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['url'] ) || ! self::is_own_url( $p['url'] ) ) {
				continue;
			}
			$page = array(
				'url'          => esc_url_raw( (string) $p['url'] ),
				'label'        => self::text( isset( $p['label'] ) ? $p['label'] : '', 60 ),
				'ok'           => ! empty( $p['ok'] ),
				'error'        => self::text( isset( $p['error'] ) ? $p['error'] : '', 160 ),
				'texts'        => self::count( $p, 'texts' ),
				'interactive'  => self::count( $p, 'interactive' ),
				'dark_total'   => self::count( $p, 'dark_total' ),
				'light_total'  => self::count( $p, 'light_total' ),
				'island_total' => self::count( $p, 'island_total' ),
				'failures'     => array(),
				'islands'      => array(),
			);

			foreach ( isset( $p['failures'] ) && is_array( $p['failures'] ) ? array_slice( $p['failures'], 0, self::MAX_FAILURES ) : array() as $f ) {
				$fg = self::hex( isset( $f['fg'] ) ? $f['fg'] : '' );
				$bg = self::hex( isset( $f['bg'] ) ? $f['bg'] : '' );
				if ( ! is_array( $f ) || '' === $fg || '' === $bg || empty( $f['selector'] ) ) {
					continue;
				}
				$state             = isset( $f['state'] ) && in_array( $f['state'], array( 'hover', 'focus' ), true ) ? $f['state'] : 'normal';
				$page['failures'][] = array(
					'selector'   => self::text( $f['selector'], 200 ),
					'sample'     => self::text( isset( $f['sample'] ) ? $f['sample'] : '', 80 ),
					'fg'         => $fg,
					'bg'         => $bg,
					'ratio'      => round( max( 1, min( 21, (float) ( isset( $f['ratio'] ) ? $f['ratio'] : 1 ) ) ), 2 ),
					'need'       => ( isset( $f['need'] ) && 3.0 === (float) $f['need'] ) ? 3.0 : 4.5,
					'count'      => max( 1, min( 999, (int) ( isset( $f['count'] ) ? $f['count'] : 1 ) ) ),
					'state'      => $state,
					'also_light' => ! empty( $f['also_light'] ),
					'over_image' => ! empty( $f['over_image'] ),
				);
			}

			foreach ( isset( $p['islands'] ) && is_array( $p['islands'] ) ? array_slice( $p['islands'], 0, self::MAX_ISLANDS ) : array() as $i ) {
				$bg = self::hex( isset( $i['bg'] ) ? $i['bg'] : '' );
				if ( ! is_array( $i ) || '' === $bg || empty( $i['selector'] ) ) {
					continue;
				}
				$page['islands'][] = array(
					'selector' => self::text( $i['selector'], 200 ),
					'bg'       => $bg,
					'w'        => max( 0, min( 10000, (int) ( isset( $i['w'] ) ? $i['w'] : 0 ) ) ),
					'h'        => max( 0, min( 100000, (int) ( isset( $i['h'] ) ? $i['h'] : 0 ) ) ),
				);
			}
			$pages[] = $page;
		}

		if ( empty( $pages ) ) {
			return new WP_Error( 'ogm_gpdm_audit_shape', __( 'The contrast check returned no pages from this site.', 'gp-dark-mode' ) );
		}

		$audit = array(
			'created' => gmdate( 'Y-m-d H:i:s' ),
			'time'    => time(),
			'width'   => max( 320, min( 2560, (int) ( isset( $data['width'] ) ? $data['width'] : 1280 ) ) ),
			'pages'   => $pages,
		);
		update_option( self::OPTION, $audit, false );
		return $audit;
	}

	/** Totals across pages: ['pages', 'ok', 'dark', 'light', 'islands']. */
	public static function totals( array $audit ) {
		$t = array(
			'pages'   => 0,
			'ok'      => 0,
			'dark'    => 0,
			'light'   => 0,
			'islands' => 0,
		);
		foreach ( $audit['pages'] as $p ) {
			++$t['pages'];
			if ( ! empty( $p['ok'] ) ) {
				++$t['ok'];
				$t['dark']    += (int) $p['dark_total'];
				$t['light']   += (int) $p['light_total'];
				$t['islands'] += (int) $p['island_total'];
			}
		}
		return $t;
	}

	/** One Markdown line per failure: what, where, how bad. */
	private static function failure_line( array $f, $page_label ) {
		$state = 'normal' === $f['state'] ? '' : ' [' . $f['state'] . ']';
		$line  = '- `' . $f['selector'] . '`' . $state;
		if ( '' !== $f['sample'] ) {
			$line .= ' — "' . $f['sample'] . '"';
		}
		$line .= ' — text ' . $f['fg'] . ' on ' . $f['bg'] . ' = ' . number_format( $f['ratio'], 2 ) . ':1 (needs ' . ( 3.0 === $f['need'] ? '3' : '4.5' ) . ':1)';
		if ( $f['count'] > 1 ) {
			$line .= ' ×' . $f['count'];
		}
		$line .= ' · ' . $page_label;
		if ( $f['over_image'] ) {
			$line .= ' · background is an image/gradient — the color shown is the nearest solid one';
		}
		if ( $f['also_light'] ) {
			$line .= ' · also fails in light mode (the site\'s own design)';
		}
		return $line . "\n";
	}

	/** Failures across all pages, worst first, de-duplicated by selector + colors + state (each row lists its pages). */
	public static function ranked_failures( array $audit ) {
		$rows = array();
		foreach ( $audit['pages'] as $p ) {
			foreach ( $p['failures'] as $f ) {
				$key = $f['selector'] . '|' . $f['fg'] . '|' . $f['bg'] . '|' . $f['state'];
				if ( isset( $rows[ $key ] ) ) {
					$rows[ $key ]['count'] += $f['count'];
					$rows[ $key ]['pages'][] = $p['label'];
					continue;
				}
				$rows[ $key ]          = $f;
				$rows[ $key ]['pages'] = array( $p['label'] );
			}
		}
		$rows = array_values( $rows );
		usort(
			$rows,
			function ( $a, $b ) {
				// Dark-mode-only problems before the site's own, then worst ratio.
				if ( $a['also_light'] !== $b['also_light'] ) {
					return $a['also_light'] ? 1 : -1;
				}
				return ( $a['ratio'] < $b['ratio'] ) ? -1 : ( ( $a['ratio'] > $b['ratio'] ) ? 1 : 0 );
			}
		);
		return $rows;
	}

	/**
	 * The Markdown section for the AI Context Bundle (and the Claude in
	 * Chrome prompt): how it was measured, per-page totals, failures worst
	 * first, light panels.
	 */
	public static function to_markdown( array $audit, $max_rows = 120, $heading = '## 6. Measured contrast in dark mode' ) {
		$t   = self::totals( $audit );
		$md  = $heading . ' (browser check, ' . $audit['created'] . " UTC)\n\n";
		$md .= 'Measured by the plugin in the site owner\'s browser: each page rendered as a logged-out visitor at ' . (int) $audit['width'] . "px wide with scripts off (so ads and script-built widgets are NOT measured), in light and in dark mode, with the CURRENT dark mode — framework, palette, modules, AI stylesheet and Manual CSS — applied. Hover and focus states are emulated from the site's own same-origin CSS. Text needs 4.5:1 contrast against its actual background (3:1 for large text: 24px+, or 18.7px+ bold). A \"light panel\" is a large element whose own background is still light in dark mode.\n\n";
		$md .= sprintf( "Totals: %d page(s) checked, %d dark-mode text failure(s), %d light panel(s); for comparison, %d text failure(s) in light mode.\n\n", $t['ok'], $t['dark'], $t['islands'], $t['light'] );

		$md .= "| Page | Text elements | Dark-mode failures | Light panels | Light-mode failures |\n|---|---|---|---|---|\n";
		foreach ( $audit['pages'] as $p ) {
			if ( empty( $p['ok'] ) ) {
				$md .= '| ' . $p['label'] . ' (' . $p['url'] . ') | not checked: ' . ( '' !== $p['error'] ? $p['error'] : 'failed to load' ) . " | | | |\n";
				continue;
			}
			$md .= '| ' . $p['label'] . ' (' . $p['url'] . ') | ' . (int) $p['texts'] . ' | ' . (int) $p['dark_total'] . ' | ' . (int) $p['island_total'] . ' | ' . (int) $p['light_total'] . " |\n";
		}
		$md .= "\n";

		$rows = self::ranked_failures( $audit );
		if ( ! empty( $rows ) ) {
			$md .= "### Dark-mode text failures, worst first\n\n";
			foreach ( array_slice( $rows, 0, $max_rows ) as $f ) {
				$md .= self::failure_line( $f, implode( ', ', array_unique( $f['pages'] ) ) );
			}
			if ( count( $rows ) > $max_rows ) {
				$md .= '- …and ' . ( count( $rows ) - $max_rows ) . " more of the same kinds.\n";
			}
			$md .= "\n";
		}

		$islands = array();
		foreach ( $audit['pages'] as $p ) {
			foreach ( $p['islands'] as $i ) {
				$islands[] = '- `' . $i['selector'] . '` — background ' . $i['bg'] . ', ' . $i['w'] . '×' . $i['h'] . 'px · ' . $p['label'] . "\n";
			}
		}
		if ( ! empty( $islands ) ) {
			$md .= "### Light panels still light in dark mode\n\n" . implode( '', array_slice( array_unique( $islands ), 0, 40 ) ) . "\nSome may be intentional (a logo plate, an ad slot's white backing) — judge from the selector.\n\n";
		}

		if ( empty( $rows ) && empty( $islands ) ) {
			$md .= "No dark-mode contrast failures or light panels were measured on these pages.\n\n";
		}
		return $md;
	}

	/**
	 * The prompt for a hands-on review with Claude in Chrome: the site, how
	 * its dark mode works, what to check and how, the measured problems, the
	 * current AI and Manual CSS, and the deliverable's shape (pasted back
	 * into the AI tab as a proposal).
	 */
	public static function chrome_prompt( OGM_GP_Dark_Mode $plugin ) {
		$name  = get_bloginfo( 'name' );
		$home  = home_url( '/' );
		$stack = 'GeneratePress' . ( defined( 'GENERATEBLOCKS_VERSION' ) ? ' + GenerateBlocks' : '' ) . ( defined( 'GP_PREMIUM_VERSION' ) ? ' + GP Premium' : '' );

		$colors = '';
		if ( class_exists( 'OGM_GPDM_Bundle' ) ) {
			$gp = OGM_GPDM_Bundle::section_gp_config();
			foreach ( isset( $gp['global_colors'] ) && is_array( $gp['global_colors'] ) ? $gp['global_colors'] : array() as $c ) {
				if ( is_array( $c ) && isset( $c['slug'], $c['color'] ) ) {
					$colors .= ( '' === $colors ? '' : ', ' ) . '--' . $c['slug'] . ': ' . $c['color'];
				}
			}
		}

		$p  = "I'd like you to review and improve the dark mode of my site, {$name} ({$home}), using Claude in Chrome. It runs WordPress on {$stack}, with the plugin \"Dark Mode for GeneratePress\". I'm logged in to the site in this browser.\n\n";
		$p .= "## How dark mode works on this site\n";
		$p .= "- Dark mode is `data-theme=\"dark\"` on `<html>`; light is `data-theme=\"light\"`. Switch with the toggle in the menu bar, or run `document.documentElement.setAttribute('data-theme','dark')` in the page.\n";
		$p .= "- Every dark rule must be scoped `html[data-theme=\"dark\"] …`. Light mode must not change.\n";
		$p .= "- The plugin's palette variables: --bg, --bg-2, --surface, --surface-2, --text, --text-muted, --border, --link, --dm-accent, --dm-glow.\n";
		if ( '' !== $colors ) {
			$p .= "- GeneratePress Global Colors (light values): {$colors}. Redefining them under `html[data-theme=\"dark\"]` is the highest-leverage fix, because GeneratePress paints most of the site with them.\n";
		}
		$p .= "- The plugin's stylesheets load in this order, later ones winning: framework → generated palette → modules → **AI stylesheet** → **Manual CSS** (mine; always wins). Your deliverable REPLACES the AI stylesheet. Don't duplicate or fight the Manual CSS.\n\n";

		$p .= "## Pages to check, at 1280px and at 390px wide\n";
		foreach ( self::pages() as $page ) {
			$p .= '- ' . $page['label'] . ': ' . $page['url'] . "\n";
		}
		$p .= "Also open anything else on the site that looks different: a contact or form page, a page built with blocks, a sidebar, the mobile menu.\n\n";

		$p .= "## What to do\n";
		$p .= "1. On each page, compare light and dark mode by eye and with screenshots. Look for unreadable text, panels still light in dark mode, invisible borders and icons, and form fields, buttons, tables, code, blockquotes, menus (dropdowns and the mobile menu), widgets, comments and the footer.\n";
		$p .= "2. Check states: hover and focus on links, buttons, menu items and form fields; dropdown menus open.\n";
		$p .= "3. Measure: text needs 4.5:1 contrast against its actual background (3:1 for large text). Use getComputedStyle on the element and the background behind it.\n";
		$p .= "4. Write the CSS and test it live: inject it as `<style id=\"gpdm-trial\">` in each page, re-check, and iterate until the problems are gone in both widths. Prefer redefining variables over per-selector overrides; use !important only to beat inline styles or plugin CSS. Leave images, video and ads alone (a backing color behind a transparent ad is fine).\n";
		$p .= "5. Keep the rules in the current AI stylesheet that still work.\n\n";

		$audit = self::get();
		$p    .= "## Problems the plugin already measured\n";
		if ( null !== $audit ) {
			$p .= self::to_markdown( $audit, self::MAX_PROMPT_ROWS, '###' ) . "Verify these yourself — the measurement skips ads and script-built widgets, which you can see.\n\n";
		} else {
			$p .= "None yet — the plugin's contrast check hasn't been run. (Running it on the AI tab before copying this prompt adds a measured list here.)\n\n";
		}

		$ai = OGM_GPDM_AI::ai_css();
		$p .= "## Current AI stylesheet (your output replaces this — keep the rules that still work)\n```css\n" . ( '' !== trim( $ai ) ? $ai : '/* empty */' ) . "\n```\n\n";
		$manual = $plugin->custom_css();
		$p     .= "## My Manual CSS (loads after yours and always wins — read-only)\n```css\n" . ( '' !== trim( $manual ) ? $manual : '/* empty */' ) . "\n```\n\n";

		$p .= "## What to send back\nA short list of what you changed and why, then ONE ```css code block with the complete replacement AI stylesheet. I'll paste your whole reply into the plugin, which shows me a diff before anything goes live.\n";
		return $p;
	}

	/** Plain text, one line, capped. */
	private static function text( $value, $max ) {
		$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/** A #rrggbb color or ''. */
	private static function hex( $value ) {
		$value = strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/** A bounded non-negative count. */
	private static function count( array $p, $key ) {
		return isset( $p[ $key ] ) ? max( 0, min( 100000, (int) $p[ $key ] ) ) : 0;
	}
}
