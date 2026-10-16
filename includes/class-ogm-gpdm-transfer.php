<?php
/**
 * GP Dark Mode — settings Export / Import (1.12.0).
 *
 * Moves a configured dark mode from one site to another (staging → live):
 * the behavior settings, module choices, AI settings, the generated palette,
 * the AI stylesheet, the Manual CSS and the review instructions, as one JSON
 * file. API keys stay out unless the exporter asks for them.
 *
 * Deliberately NOT transferred — they describe the site they were made on:
 * cached model lists, review status/proposal/backup, the contrast check
 * measurements, and the "host ends long requests" flag.
 *
 * Import is two-step: preview (what would change, nothing written), then
 * apply, which first stores the current state so one click undoes it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_Transfer {

	/** File format version (bump only on an incompatible change). */
	const FORMAT = 1;

	/** Largest import accepted (bytes) — a few stylesheets, with room to spare. */
	const MAX_BYTES = 2000000;

	/** The state before the last import (unregistered, non-autoloaded). */
	const BACKUP_KEY = 'ogm_gpdm_import_backup';

	/** Per-user transient holding a previewed import until it's applied. */
	const PENDING_PREFIX = 'ogm_gpdm_import_pending_';

	/** API key fields — never exported unless asked, never shown. */
	const KEY_FIELDS = array( 'ai_api_key_anthropic', 'ai_api_key_openai' );

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------- */

	/**
	 * The export document for this site.
	 *
	 * @param bool $include_keys Include the AI provider API keys.
	 * @return array
	 */
	public static function build_export( OGM_GP_Dark_Mode $plugin, $include_keys = false ) {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$stored   = get_option( OGM_GP_Dark_Mode::OPT_KEY, array() );
		$settings = is_array( $stored ) ? array_merge( OGM_GP_Dark_Mode::defaults(), $stored ) : OGM_GP_Dark_Mode::defaults();
		unset( $settings['custom_css'] ); // Exported as manual_css.
		if ( ! $include_keys ) {
			foreach ( self::KEY_FIELDS as $field ) {
				unset( $settings[ $field ] );
			}
		}

		$instructions = get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, '' );

		return array(
			'plugin'            => 'gp-dark-mode',
			'format'            => self::FORMAT,
			'version'           => OGM_GPDM_VERSION,
			'exported_at'       => gmdate( 'Y-m-d H:i:s' ),
			'site'              => home_url( '/' ),
			'includes_api_keys' => (bool) $include_keys,
			'settings'          => $settings,
			'manual_css'        => $plugin->custom_css(),
			'generated_css'     => $plugin->generated_css(),
			'ai_css'            => OGM_GPDM_AI::ai_css(),
			'ai_instructions'   => is_string( $instructions ) ? $instructions : '',
		);
	}

	/** File name for an export: the site's host and the time. */
	public static function export_filename() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = preg_replace( '/[^a-z0-9.-]+/i', '-', '' !== $host ? $host : 'site' );
		return 'gp-dark-mode-settings-' . $host . '-' . gmdate( 'Ymd-His' ) . '.json';
	}

	/* ---------------------------------------------------------------------
	 * Import: read + validate
	 * ------------------------------------------------------------------- */

	/**
	 * Parse and validate an export file. Only known fields survive; every
	 * value is re-checked when applied (sanitize_settings, sanitize_css).
	 *
	 * @return array|WP_Error
	 */
	public static function parse( $json ) {
		$json = (string) $json;
		if ( '' === trim( $json ) ) {
			return new WP_Error( 'ogm_gpdm_import_empty', __( 'Choose an export file first.', 'gp-dark-mode' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'ogm_gpdm_import_size', __( 'That file is too large to be a GP Dark Mode export.', 'gp-dark-mode' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['plugin'] ) || 'gp-dark-mode' !== $data['plugin'] ) {
			return new WP_Error( 'ogm_gpdm_import_kind', __( 'That isn’t a GP Dark Mode settings export.', 'gp-dark-mode' ) );
		}
		if ( ! isset( $data['format'] ) || self::FORMAT !== (int) $data['format'] ) {
			return new WP_Error( 'ogm_gpdm_import_format', __( 'That export was made by a version of GP Dark Mode this one can’t read. Update the plugin on both sites, then export again.', 'gp-dark-mode' ) );
		}
		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'ogm_gpdm_import_shape', __( 'The export file has no settings in it.', 'gp-dark-mode' ) );
		}

		$known    = array_keys( OGM_GP_Dark_Mode::defaults() );
		$settings = array_intersect_key( $data['settings'], array_flip( $known ) );
		if ( isset( $settings['modules'] ) && ! is_array( $settings['modules'] ) ) {
			unset( $settings['modules'] );
		}
		$keys_included = ! empty( $data['includes_api_keys'] );
		if ( ! $keys_included ) {
			foreach ( self::KEY_FIELDS as $field ) {
				unset( $settings[ $field ] );
			}
		}

		$text = function ( $key ) use ( $data ) {
			return ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) ? $data[ $key ] : null;
		};

		return array(
			'version'           => is_string( $text( 'version' ) ) ? sanitize_text_field( $text( 'version' ) ) : '',
			'exported_at'       => is_string( $text( 'exported_at' ) ) ? sanitize_text_field( $text( 'exported_at' ) ) : '',
			'site'              => is_string( $text( 'site' ) ) ? esc_url_raw( $text( 'site' ) ) : '',
			'includes_api_keys' => $keys_included,
			'settings'          => $settings,
			'manual_css'        => $text( 'manual_css' ),
			'generated_css'     => $text( 'generated_css' ),
			'ai_css'            => $text( 'ai_css' ),
			'ai_instructions'   => $text( 'ai_instructions' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Import: preview
	 * ------------------------------------------------------------------- */

	/** Human labels for the settings a preview lists. */
	private static function labels() {
		return array(
			'default_theme'     => __( 'Default theme', 'gp-dark-mode' ),
			'respect_system'    => __( 'Match the visitor’s system setting', 'gp-dark-mode' ),
			'show_toggle'       => __( 'Toggle in the menu bar', 'gp-dark-mode' ),
			'accent_nav'        => __( 'Dark nav accent', 'gp-dark-mode' ),
			'enable_generated'  => __( 'Generated palette on', 'gp-dark-mode' ),
			'enable_ai_css'     => __( 'AI stylesheet on', 'gp-dark-mode' ),
			'enable_custom_css' => __( 'Manual CSS on', 'gp-dark-mode' ),
			'ai_provider'       => __( 'AI provider', 'gp-dark-mode' ),
			'ai_model'          => __( 'AI model', 'gp-dark-mode' ),
			'ai_effort'         => __( 'Effort', 'gp-dark-mode' ),
			'ai_mode'           => __( 'Review mode', 'gp-dark-mode' ),
		);
	}

	/** A setting value as the preview shows it. */
	private static function show_value( $key, $value ) {
		if ( in_array( $key, array( 'respect_system', 'show_toggle', 'accent_nav', 'enable_generated', 'enable_ai_css', 'enable_custom_css' ), true ) ) {
			return empty( $value ) ? __( 'Off', 'gp-dark-mode' ) : __( 'On', 'gp-dark-mode' );
		}
		if ( '' === (string) $value ) {
			$defaults = array(
				'ai_model'  => __( 'Provider default', 'gp-dark-mode' ),
				'ai_effort' => __( 'Default (medium)', 'gp-dark-mode' ),
				'ai_mode'   => __( 'Automatic', 'gp-dark-mode' ),
			);
			return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '—';
		}
		return (string) $value;
	}

	/**
	 * Everything an import would change, as data:
	 * ['settings' => [[label, now, after]], 'modules' => [[label, now, after]],
	 *  'css' => [[label, now_len, after_len, changed]], 'instructions' => bool,
	 *  'notes' => string[]].
	 */
	public static function diff( OGM_GP_Dark_Mode $plugin, array $import ) {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';

		$current = $plugin->settings();
		$out     = array(
			'settings'     => array(),
			'modules'      => array(),
			'css'          => array(),
			'instructions' => false,
			'notes'        => array(),
		);

		foreach ( self::labels() as $key => $label ) {
			if ( ! array_key_exists( $key, $import['settings'] ) ) {
				continue;
			}
			$now   = isset( $current[ $key ] ) ? $current[ $key ] : '';
			$after = $import['settings'][ $key ];
			if ( self::show_value( $key, $now ) !== self::show_value( $key, $after ) ) {
				$out['settings'][] = array( $label, self::show_value( $key, $now ), self::show_value( $key, $after ) );
			}
		}

		if ( isset( $import['settings']['modules'] ) ) {
			foreach ( OGM_GP_Dark_Mode::modules() as $slug => $module ) {
				if ( ! array_key_exists( $slug, $import['settings']['modules'] ) ) {
					continue; // Unknown to the exporting version: this site's choice stays.
				}
				$now   = ! empty( $current['modules'][ $slug ] );
				$after = ! empty( $import['settings']['modules'][ $slug ] );
				if ( $now !== $after ) {
					$out['modules'][] = array( $module['label'], $now ? __( 'On', 'gp-dark-mode' ) : __( 'Off', 'gp-dark-mode' ), $after ? __( 'On', 'gp-dark-mode' ) : __( 'Off', 'gp-dark-mode' ) );
				}
			}
		}

		$layers = array(
			'generated_css' => array( __( 'Generated palette', 'gp-dark-mode' ), $plugin->generated_css() ),
			'ai_css'        => array( __( 'AI stylesheet', 'gp-dark-mode' ), OGM_GPDM_AI::ai_css() ),
			'manual_css'    => array( __( 'Manual CSS', 'gp-dark-mode' ), $plugin->custom_css() ),
		);
		foreach ( $layers as $key => $layer ) {
			if ( null === $import[ $key ] ) {
				continue;
			}
			$out['css'][] = array( $layer[0], strlen( $layer[1] ), strlen( $import[ $key ] ), trim( $layer[1] ) !== trim( $import[ $key ] ) );
		}

		if ( null !== $import['ai_instructions'] ) {
			$now                 = get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, '' );
			$out['instructions'] = trim( (string) $now ) !== trim( $import['ai_instructions'] );
		}

		// Notes worth reading before applying.
		if ( '' !== $import['version'] && version_compare( $import['version'], OGM_GPDM_VERSION, '>' ) ) {
			/* translators: 1: exporting version, 2: this version. */
			$out['notes'][] = sprintf( __( 'This file comes from a newer version of the plugin (%1$s; this site runs %2$s). Settings this version doesn’t know about are skipped — update the plugin here first for an exact copy.', 'gp-dark-mode' ), $import['version'], OGM_GPDM_VERSION );
		}
		if ( $import['includes_api_keys'] ) {
			$out['notes'][] = __( 'The file includes API keys: they will replace this site’s keys (an empty one in the file leaves this site’s key alone).', 'gp-dark-mode' );
		} else {
			$out['notes'][] = __( 'The file has no API keys, so this site’s keys stay as they are.', 'gp-dark-mode' );
		}
		if ( null !== $import['generated_css'] && '' !== trim( $import['generated_css'] ) && class_exists( 'OGM_GPDM_Palette' ) ) {
			$here = OGM_GPDM_Palette::generate();
			if ( '' !== trim( $here ) && self::squash( $here ) !== self::squash( $import['generated_css'] ) ) {
				$out['notes'][] = __( 'This site’s Global Colors differ from the exporting site’s, so its own generated palette would come out differently. The import uses the exporting site’s palette as-is; afterwards, Generate from Global Colors rebuilds it from this site’s colors.', 'gp-dark-mode' );
			}
		}
		return $out;
	}

	/** Whitespace-insensitive comparison form of a stylesheet. */
	private static function squash( $css ) {
		return preg_replace( '/\s+/', '', (string) $css );
	}

	/** The preview as HTML (escaped throughout). */
	public static function preview_html( OGM_GP_Dark_Mode $plugin, array $import ) {
		$d    = self::diff( $plugin, $import );
		$html = '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: site URL, 2: UTC timestamp, 3: plugin version. */
				__( 'From %1$s, exported %2$s UTC (plugin %3$s).', 'gp-dark-mode' ),
				'' !== $import['site'] ? $import['site'] : __( 'an unknown site', 'gp-dark-mode' ),
				'' !== $import['exported_at'] ? $import['exported_at'] : '—',
				'' !== $import['version'] ? $import['version'] : '—'
			)
		) . '</strong></p>';

		$rows = array_merge( $d['settings'], $d['modules'] );
		if ( empty( $rows ) && ! array_filter( array_column( $d['css'], 3 ) ) && ! $d['instructions'] ) {
			$html .= '<p>' . esc_html__( 'Nothing would change: this site already matches the file.', 'gp-dark-mode' ) . '</p>';
		} else {
			$html .= '<table class="widefat striped ogm-gpdm-import-table"><thead><tr><th>' . esc_html__( 'What', 'gp-dark-mode' ) . '</th><th>' . esc_html__( 'This site now', 'gp-dark-mode' ) . '</th><th>' . esc_html__( 'After import', 'gp-dark-mode' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$html .= '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . esc_html( $r[1] ) . '</td><td><strong>' . esc_html( $r[2] ) . '</strong></td></tr>';
			}
			foreach ( $d['css'] as $c ) {
				$size = function ( $n ) {
					/* translators: %s: number of characters. */
					return 0 === $n ? __( 'empty', 'gp-dark-mode' ) : sprintf( _n( '%s character', '%s characters', $n, 'gp-dark-mode' ), number_format_i18n( $n ) );
				};
				$html .= '<tr><td>' . esc_html( $c[0] ) . '</td><td>' . esc_html( $size( $c[1] ) ) . '</td><td>' . ( $c[3] ? '<strong>' . esc_html( $size( $c[2] ) ) . '</strong>' : esc_html__( 'unchanged', 'gp-dark-mode' ) ) . '</td></tr>';
			}
			if ( $d['instructions'] ) {
				$html .= '<tr><td>' . esc_html__( 'AI review instructions', 'gp-dark-mode' ) . '</td><td colspan="2"><strong>' . esc_html__( 'replaced', 'gp-dark-mode' ) . '</strong></td></tr>';
			}
			$html .= '</tbody></table>';
		}
		foreach ( $d['notes'] as $note ) {
			$html .= '<p class="description">' . esc_html( $note ) . '</p>';
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Import: apply + undo
	 * ------------------------------------------------------------------- */

	/** Hold a previewed import for this user until it's applied (15 minutes). */
	public static function stash( array $import ) {
		set_transient( self::PENDING_PREFIX . get_current_user_id(), $import, 15 * MINUTE_IN_SECONDS );
	}

	/** The previewed import waiting for this user, or null. */
	public static function stashed() {
		$import = get_transient( self::PENDING_PREFIX . get_current_user_id() );
		return is_array( $import ) && isset( $import['settings'] ) ? $import : null;
	}

	/** The state an import replaces, saved first so it can be undone. */
	private static function snapshot( OGM_GP_Dark_Mode $plugin, $source ) {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		$stored = get_option( OGM_GP_Dark_Mode::OPT_KEY, array() );
		return array(
			'created'         => gmdate( 'Y-m-d H:i:s' ),
			'source'          => (string) $source,
			'settings'        => is_array( $stored ) ? $stored : array(),
			'generated_css'   => $plugin->generated_css(),
			'ai_css'          => OGM_GPDM_AI::ai_css(),
			'ai_instructions' => (string) get_option( OGM_GPDM_AI::INSTRUCTIONS_KEY, '' ),
		);
	}

	/**
	 * Write a state: settings through the plugin's own sanitizer (so
	 * nothing invalid lands, and a missing key field keeps this site's key),
	 * the stylesheets through sanitize_css.
	 */
	private static function write( OGM_GP_Dark_Mode $plugin, array $settings_input, $generated, $ai_css, $instructions ) {
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-ai.php';
		if ( null !== $instructions ) {
			$settings_input['ai_instructions'] = $instructions; // sanitize_settings() stores it in its own option.
		}
		$clean = $plugin->sanitize_settings( $settings_input );
		update_option( OGM_GP_Dark_Mode::OPT_KEY, $clean );
		if ( null !== $generated ) {
			update_option( OGM_GP_Dark_Mode::GEN_KEY, OGM_GP_Dark_Mode::sanitize_css( $generated ), true );
		}
		if ( null !== $ai_css ) {
			update_option( OGM_GPDM_AI::AI_CSS_KEY, OGM_GP_Dark_Mode::sanitize_css( $ai_css ), true );
		}
		$plugin->flush_settings_cache();
	}

	/**
	 * Apply an import: snapshot this site, then write the file's state over
	 * it. Settings the file doesn't carry (and modules unknown to the
	 * exporting version) keep this site's values.
	 */
	public static function apply( OGM_GP_Dark_Mode $plugin, array $import ) {
		$snapshot = self::snapshot( $plugin, '' !== $import['site'] ? $import['site'] : __( 'an export file', 'gp-dark-mode' ) );
		update_option( self::BACKUP_KEY, $snapshot, false );

		$current = $snapshot['settings'];
		$input   = array_merge( OGM_GP_Dark_Mode::defaults(), $current, $import['settings'] );
		if ( isset( $import['settings']['modules'] ) ) {
			$input['modules'] = array_merge(
				isset( $current['modules'] ) && is_array( $current['modules'] ) ? $current['modules'] : array(),
				$import['settings']['modules']
			);
		}
		// Keys: only a non-empty key from the file replaces this site's.
		foreach ( self::KEY_FIELDS as $field ) {
			$from_file = isset( $import['settings'][ $field ] ) && is_string( $import['settings'][ $field ] ) ? trim( $import['settings'][ $field ] ) : '';
			if ( '' !== $from_file && 'clear' !== strtolower( $from_file ) ) {
				$input[ $field ] = $from_file;
			} else {
				unset( $input[ $field ] ); // Absent → the sanitizer keeps the stored key.
			}
		}
		if ( null !== $import['manual_css'] ) {
			$input['custom_css'] = $import['manual_css'];
		} else {
			unset( $input['custom_css'] );
		}

		self::write( $plugin, $input, $import['generated_css'], $import['ai_css'], $import['ai_instructions'] );
		return true;
	}

	/** The stored pre-import state, or null. */
	public static function backup() {
		$b = get_option( self::BACKUP_KEY, null );
		return ( is_array( $b ) && isset( $b['settings'] ) && is_array( $b['settings'] ) ) ? $b : null;
	}

	/**
	 * Put back the state from before the last import, keys included, and
	 * drop the backup.
	 *
	 * @return true|WP_Error
	 */
	public static function undo( OGM_GP_Dark_Mode $plugin ) {
		$b = self::backup();
		if ( null === $b ) {
			return new WP_Error( 'ogm_gpdm_import_no_backup', __( 'There’s no import to undo.', 'gp-dark-mode' ) );
		}
		$input = array_merge( OGM_GP_Dark_Mode::defaults(), $b['settings'] );
		foreach ( self::KEY_FIELDS as $field ) {
			// An empty stored key must come back empty, not keep the imported one.
			if ( '' === (string) ( isset( $input[ $field ] ) ? $input[ $field ] : '' ) ) {
				$input[ $field ] = 'clear';
			}
		}
		$input['custom_css'] = isset( $b['settings']['custom_css'] ) && is_string( $b['settings']['custom_css'] ) ? $b['settings']['custom_css'] : '';
		self::write( $plugin, $input, $b['generated_css'], $b['ai_css'], $b['ai_instructions'] );
		delete_option( self::BACKUP_KEY );
		return true;
	}
}
