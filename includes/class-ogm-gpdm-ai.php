<?php
/**
 * GP Dark Mode — Layer 4 AI assist (BYOK).
 *
 * Sends the AI Context Bundle to the admin's own Anthropic or OpenAI account
 * and turns the response into a PROPOSAL for the Additional CSS layer: shown
 * as a diff on the settings page with Apply / Discard / Restore — never
 * applied automatically. Dual-provider raw wp_remote_post, mirroring the
 * Editorial QA plugin's pattern (no SDK dependency in a WP plugin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_AI {

	/**
	 * Proposal + backup live in separate, UNREGISTERED options — see
	 * OGM_GP_Dark_Mode::GEN_KEY for why programmatic state must never be
	 * written through the registered settings option.
	 */
	const PROPOSAL_KEY = 'ogm_gpdm_ai_proposal';
	const BACKUP_KEY   = 'ogm_gpdm_ai_backup';

	/** API endpoints. */
	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
	const OPENAI_URL    = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Background-mode endpoints (1.9.0): Anthropic's Message Batches API and
	 * OpenAI's Responses API with background:true. Both accept the review and
	 * return an id at once, so no PHP request ever waits on the model — hosts
	 * such as WP Engine end any request after 60 seconds, and a High-effort
	 * review takes several minutes.
	 */
	const ANTHROPIC_BATCH_URL = 'https://api.anthropic.com/v1/messages/batches';
	const OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';

	/** The one request inside each Anthropic batch. */
	const BATCH_CUSTOM_ID = 'gpdm-review';

	/** Cron hook that checks a background job while nobody has the page open. */
	const CHECK_HOOK = 'ogm_gpdm_check_ai_job';

	/** Minimum seconds between two provider checks of a background job. */
	const CHECK_INTERVAL = 8;

	/**
	 * A background job with no outcome after this long is treated as failed.
	 * Anthropic expires a batch after 24 hours, so a live provider always
	 * answers first; this only catches a job the provider lost track of.
	 */
	const BACKGROUND_TIMEOUT = 93600; // 26 hours.

	/**
	 * Set (to a timestamp) when a direct review died mid-call — the host
	 * ended the request. Automatic mode then uses background reviews on this
	 * site. Unregistered, non-autoloaded; cleared by a successful review in
	 * Direct mode.
	 */
	const CAPPED_KEY = 'ogm_gpdm_ai_host_capped';

	/** Cached per-provider model lists (unregistered, non-autoloaded). */
	const MODELS_KEY = 'ogm_gpdm_ai_models';

	/**
	 * Background-run status (unregistered, non-autoloaded):
	 * {state: running|done|error, message, started, finished}.
	 * The review runs via WP-Cron because a synchronous admin request dies
	 * two ways in the wild (live-verified on the first deployment): proxies
	 * 502 the 1-3 minute wait, and mod_security 403s redirects that carry
	 * provider error text in the query string.
	 */
	const STATUS_KEY = 'ogm_gpdm_ai_status';

	/** A 'running' state older than this is treated as failed (seconds). */
	const RUN_TIMEOUT = 900;

	/**
	 * The site owner's review instructions (unregistered, non-autoloaded).
	 * Written by the AJAX start handler, read by the cron run, and used to
	 * prefill the textarea for the next iteration.
	 */
	const INSTRUCTIONS_KEY = 'ogm_gpdm_ai_instructions';

	/**
	 * The AI stylesheet (Layer 4a) — machine-owned CSS that each accepted
	 * proposal replaces wholesale. Since 1.5.0 this is its OWN layer,
	 * separate from the owner's Manual CSS (Layer 4b), which loads after it
	 * and always wins — so a review can never clobber a hand edit.
	 * Unregistered; autoload stays on (front-end state, like GEN_KEY).
	 */
	const AI_CSS_KEY = 'ogm_gpdm_ai_css';

	/** The current AI stylesheet ('' when none applied yet). */
	public static function ai_css() {
		$css = get_option( self::AI_CSS_KEY, '' );
		return is_string( $css ) ? $css : '';
	}

	/* ---------------------------------------------------------------------
	 * Background-run status
	 * ------------------------------------------------------------------- */

	/** Current status array, or null. */
	public static function status() {
		$status = get_option( self::STATUS_KEY, null );
		return ( is_array( $status ) && isset( $status['state'] ) ) ? $status : null;
	}

	/**
	 * Write a status. 'running' starts a new run (fresh clock, phase
	 * 'queued'); any other state closes the current one and drops its
	 * in-flight fields. Since 1.9.0 a running status also carries:
	 * mode (direct|background), phase (queued|bundle|calling|submitted) +
	 * phase_at, the provider/model/effort of the call, and for background
	 * runs the provider job id and the time of the last check.
	 */
	public static function set_status( $state, $message = '' ) {
		$current = self::status();
		$status  = array(
			'state'    => $state,
			'message'  => mb_substr( (string) $message, 0, 600 ),
			'started'  => ( 'running' === $state || null === $current ) ? time() : ( isset( $current['started'] ) ? (int) $current['started'] : time() ),
			'finished' => ( 'running' === $state ) ? 0 : time(),
		);
		if ( 'running' === $state ) {
			$status['phase']    = 'queued';
			$status['phase_at'] = time();
		} elseif ( null !== $current && isset( $current['mode'] ) ) {
			$status['mode'] = $current['mode']; // Shown with the outcome.
		}
		update_option( self::STATUS_KEY, $status, false );
	}

	/** Merge fields into the current status (creating a running one if none). */
	public static function patch_status( array $fields ) {
		$status = self::status();
		if ( null === $status ) {
			$status = array(
				'state'    => 'running',
				'message'  => '',
				'started'  => time(),
				'finished' => 0,
			);
		}
		if ( isset( $fields['message'] ) ) {
			$fields['message'] = mb_substr( (string) $fields['message'], 0, 600 );
		}
		update_option( self::STATUS_KEY, array_merge( $status, $fields ), false );
	}

	/** Record how far a running review got — the diagnosis when a host kills it. */
	public static function mark_phase( $phase, array $fields = array() ) {
		$status = self::status();
		if ( null === $status || 'running' !== $status['state'] ) {
			return;
		}
		self::patch_status( array_merge( $fields, array( 'phase' => $phase, 'phase_at' => time() ) ) );
	}

	/** True when the running status belongs to a background job. */
	private static function is_background_status( $status ) {
		return is_array( $status ) && isset( $status['mode'] ) && 'background' === $status['mode'];
	}

	/** True while a fresh run is in flight. */
	public static function is_running() {
		$status = self::status();
		return null !== $status
			&& 'running' === $status['state']
			&& null === self::stale_reason( $status );
	}

	/**
	 * Why a 'running' status can no longer finish, or null while it still
	 * can. A direct run is bounded by its own HTTP timeout once the call has
	 * started, and by RUN_TIMEOUT before that; a background job by
	 * BACKGROUND_TIMEOUT. The phase it stopped in says what went wrong.
	 */
	private static function stale_reason( array $status ) {
		$now     = time();
		$started = isset( $status['started'] ) ? (int) $status['started'] : $now;
		$phase   = isset( $status['phase'] ) ? (string) $status['phase'] : 'queued';

		if ( self::is_background_status( $status ) && 'submitted' === $phase ) {
			if ( $now - $started < self::BACKGROUND_TIMEOUT ) {
				return null;
			}
			return __( 'The provider never returned a result for the background review. Try again.', 'gp-dark-mode' );
		}

		if ( 'calling' === $phase ) {
			$effort   = isset( $status['effort'] ) ? $status['effort'] : null;
			$at       = isset( $status['phase_at'] ) ? (int) $status['phase_at'] : $started;
			$deadline = $at + self::effort_timeout( $effort ) + 60;
			if ( $now < $deadline && $now - $started < self::RUN_TIMEOUT ) {
				return null;
			}
			return 'host_stopped';
		}

		if ( $now - $started < self::RUN_TIMEOUT ) {
			return null;
		}
		if ( 'queued' === $phase ) {
			return __( 'The review never started: the site’s WP-Cron didn’t run it. Check that WP-Cron is working, then try again.', 'gp-dark-mode' );
		}
		return __( 'The review stopped while it was gathering the site’s CSS, before anything was sent to the model — the host may have ended the request. Try again; if it keeps happening, set Review mode to Background.', 'gp-dark-mode' );
	}

	/**
	 * Status for the polling endpoint. Checks a background job with its
	 * provider (throttled), and converts a stale 'running' into an error so
	 * the UI never spins forever. A direct run that died mid-call means the
	 * host ends long requests: in Automatic mode the next review on this
	 * site goes to the background instead.
	 */
	public static function polled_status( $plugin = null ) {
		$status = self::status();
		if ( null === $status ) {
			return array( 'state' => 'idle', 'message' => '' );
		}
		if ( 'running' === $status['state'] && self::is_background_status( $status ) && $plugin instanceof OGM_GP_Dark_Mode ) {
			self::check_job( $plugin );
			$status = self::status();
		}
		if ( 'running' === $status['state'] ) {
			$why = self::stale_reason( $status );
			if ( 'host_stopped' === $why ) {
				$seconds = max( 0, time() - ( isset( $status['phase_at'] ) ? (int) $status['phase_at'] : (int) $status['started'] ) );
				$auto    = ! ( $plugin instanceof OGM_GP_Dark_Mode ) || '' === self::mode_setting( $plugin->settings() );
				if ( $auto ) {
					update_option( self::CAPPED_KEY, time(), false );
				}
				self::set_status(
					'error',
					$auto
						? __( 'The review was sent to the model, but the site’s host ended the request before the reply came back — some hosts, such as WP Engine, stop any request after about a minute. Run it again: from now on this site sends reviews in the background, so no request has to wait.', 'gp-dark-mode' )
						: __( 'The review was sent to the model, but the site’s host ended the request before the reply came back — some hosts, such as WP Engine, stop any request after about a minute. Set Review mode to Background (or Automatic) and run it again.', 'gp-dark-mode' )
				);
				return self::status();
			}
			if ( null !== $why ) {
				self::set_status( 'error', $why );
				return self::status();
			}
		}
		return $status;
	}

	/* ---------------------------------------------------------------------
	 * Review mode: direct call vs background job
	 * ------------------------------------------------------------------- */

	/** The saved Review mode setting: '' (Automatic), 'direct' or 'background'. */
	public static function mode_setting( array $settings ) {
		$mode = isset( $settings['ai_mode'] ) ? (string) $settings['ai_mode'] : '';
		return in_array( $mode, array( 'direct', 'background' ), true ) ? $mode : '';
	}

	/**
	 * Hosts known to end every request after about a minute, which a direct
	 * review (minutes at High effort) can't survive. WP Engine documents a
	 * 60-second limit that can't be raised. Filterable via
	 * ogm_gpdm_ai_host_caps_requests.
	 */
	public static function host_caps_requests() {
		$capped = function_exists( 'is_wpe' ) || defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon', false );
		if ( function_exists( 'apply_filters' ) ) {
			$capped = (bool) apply_filters( 'ogm_gpdm_ai_host_caps_requests', $capped );
		}
		return $capped;
	}

	/**
	 * Why Automatic mode picks background on this site — 'host' (a known
	 * capped host), 'learned' (a direct review here was cut off) — or ''
	 * when it calls the model directly.
	 */
	public static function auto_background_reason() {
		if ( self::host_caps_requests() ) {
			return 'host';
		}
		return get_option( self::CAPPED_KEY ) ? 'learned' : '';
	}

	/** Whether the next review runs as a background job. */
	public static function uses_background( array $settings ) {
		$mode = self::mode_setting( $settings );
		if ( '' !== $mode ) {
			return 'background' === $mode;
		}
		return '' !== self::auto_background_reason();
	}

	/** Queue a background check unless one is already queued. */
	public static function schedule_check( $delay = 60 ) {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CHECK_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CHECK_HOOK );
	}

	/** Providers and their default models. */
	public static function providers() {
		return array(
			'anthropic' => array( 'label' => 'Anthropic (Claude)', 'default_model' => 'claude-opus-5-5' ),
			'openai'    => array( 'label' => 'OpenAI', 'default_model' => 'gpt-6.1-sol' ),
		);
	}

	/**
	 * Default model for a provider. The hardcoded ids cover a fresh install;
	 * once a refresh has run, the default tracks the provider's lineup so it
	 * doesn't go stale. OpenAI: the top of the ranked cache (the newest
	 * major.minor family). Anthropic: the newest Opus in the cache — Opus is
	 * the tier Anthropic recommends for most work, while the ranked top
	 * (Fable, or a restricted tier) costs more.
	 */
	public static function default_model( $provider ) {
		$providers = self::providers();
		$fallback  = isset( $providers[ $provider ] ) ? $providers[ $provider ]['default_model'] : 'claude-opus-5-5';
		$ids       = self::models_cache( 'openai' === $provider ? 'openai' : 'anthropic' )['ids'];
		if ( 'openai' === $provider ) {
			if ( ! empty( $ids ) ) {
				return (string) $ids[0];
			}
		} else {
			foreach ( $ids as $id ) {
				if ( preg_match( '/^claude-opus-\d/', $id ) ) {
					return (string) $id; // Ranked newest-first within the family.
				}
			}
		}
		return $fallback;
	}

	/**
	 * Claude models that take the effort parameter and the server-side
	 * refusal fallback: the 5 family plus Opus 4.6–4.8 and Sonnet 4.6. Haiku
	 * 5.5 has no fallback target of its own, but the API accepts
	 * fallbacks:"default" there and simply keeps the refusal.
	 */
	private static function is_effort_claude( $model ) {
		return (bool) preg_match( '/^claude-(fable|opus-5|opus-4-[6-8]|sonnet-5|sonnet-4-6|haiku-5)/', strtolower( (string) $model ) );
	}

	/* ---------------------------------------------------------------------
	 * Model lists (dropdown + refresh — Editorial QA pattern)
	 * ------------------------------------------------------------------- */

	/**
	 * Seed model lists, shown until the first "Refresh models" — each
	 * provider's current lineup (checked 2026-10-14), default first. gpt-5
	 * stays for keys without GPT-6 access.
	 */
	public static function seed_models( $provider ) {
		return 'openai' === $provider
			? array( 'gpt-6.1-sol', 'gpt-6-astra', 'gpt-6-luna', 'gpt-5' )
			: array( 'claude-opus-5-5', 'claude-fable-5-1', 'claude-sonnet-5-5', 'claude-haiku-5-5' );
	}

	/** Cached model entry for a provider: ['ids' => string[], 'updated' => int]. */
	public static function models_cache( $provider ) {
		$all   = get_option( self::MODELS_KEY, array() );
		$entry = ( is_array( $all ) && isset( $all[ $provider ] ) && is_array( $all[ $provider ] ) ) ? $all[ $provider ] : array();
		return array(
			'ids'     => ( isset( $entry['ids'] ) && is_array( $entry['ids'] ) ) ? array_values( array_filter( array_map( 'strval', $entry['ids'] ) ) ) : array(),
			'updated' => isset( $entry['updated'] ) ? (int) $entry['updated'] : 0,
		);
	}

	/**
	 * Options for the model dropdown: the cached list (or the seed list before
	 * any refresh), always including the currently-saved model so a custom or
	 * since-retired id stays selectable.
	 *
	 * @return string[]
	 */
	public static function model_choices( $provider, $current = '' ) {
		$ids = self::models_cache( $provider )['ids'];
		if ( empty( $ids ) ) {
			$ids = self::seed_models( $provider );
		}
		$current = trim( (string) $current );
		if ( '' !== $current && ! in_array( $current, $ids, true ) ) {
			array_unshift( $ids, $current );
		}
		return $ids;
	}

	/**
	 * Fetch, rank, and cache a provider's current model list.
	 *
	 * @return string[]|WP_Error
	 */
	public static function refresh_models( $provider, $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return new WP_Error( 'ogm_gpdm_ai_no_key', __( 'API key not configured. Save the key first, then refresh.', 'gp-dark-mode' ) );
		}

		$ids = ( 'openai' === $provider )
			? self::fetch_openai_models( $key )
			: self::fetch_anthropic_models( $key );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$ids = ( 'openai' === $provider ) ? self::rank_openai_models( $ids ) : self::rank_anthropic_models( $ids );

		if ( empty( $ids ) ) {
			return new WP_Error( 'ogm_gpdm_ai_no_models', __( 'No compatible models were returned by the provider.', 'gp-dark-mode' ) );
		}

		$all = get_option( self::MODELS_KEY, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $provider ] = array(
			'ids'     => $ids,
			'updated' => time(),
		);
		update_option( self::MODELS_KEY, $all, false );

		return $ids;
	}

	/** GET https://api.anthropic.com/v1/models — claude-* ids or WP_Error. */
	private static function fetch_anthropic_models( $key ) {
		$res = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
			)
		);

		$data = self::models_response_data( $res, 'Anthropic' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ids = array();
		foreach ( $data as $m ) {
			if ( ! empty( $m['id'] ) && 0 === strpos( (string) $m['id'], 'claude-' ) ) {
				$ids[] = (string) $m['id'];
			}
		}
		return $ids;
	}

	/** GET https://api.openai.com/v1/models — chat-capable ids or WP_Error. */
	private static function fetch_openai_models( $key ) {
		$res = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'authorization' => 'Bearer ' . $key ),
			)
		);

		$data = self::models_response_data( $res, 'OpenAI' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Allowlist chat families; blocklist non-text and deprecated lines.
		static $blocklist = array(
			'embedding', 'audio', 'tts', 'whisper', 'image', 'moderation',
			'realtime', 'transcribe', 'instruct', 'davinci', 'curie',
			'babbage', 'ada', 'search', 'similarity', 'edit', '-ft-', ':ft-',
		);

		$ids = array();
		foreach ( $data as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$id = (string) $m['id'];

			$is_chat = preg_match( '/^o\d/i', $id )
				|| 0 === strpos( $id, 'gpt-' )
				|| 0 === strpos( $id, 'chatgpt-' );
			if ( ! $is_chat ) {
				continue;
			}

			$skip = false;
			foreach ( $blocklist as $needle ) {
				if ( false !== stripos( $id, $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Shared /v1/models response validation → data[] or WP_Error. */
	private static function models_response_data( $res, $label ) {
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ogm_gpdm_ai_transport', $label . ': ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			$json = json_decode( $body, true );
			$msg  = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ogm_gpdm_ai_http', $label . ': ' . mb_substr( $msg, 0, 200 ) );
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return new WP_Error( 'ogm_gpdm_ai_shape', $label . ': ' . __( 'unexpected models-list response shape.', 'gp-dark-mode' ) );
		}
		return $json['data'];
	}

	/**
	 * Sort Anthropic ids most-capable → least (fable > opus > sonnet > haiku),
	 * newer dated snapshots first within a family, dedupe, cap.
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function rank_anthropic_models( array $ids ) {
		$family_score = array(
			'fable'  => 40,
			'opus'   => 30,
			'sonnet' => 20,
			'haiku'  => 10,
		);

		$scored = array();
		foreach ( $ids as $id ) {
			$score = 0;
			foreach ( $family_score as $fam => $pts ) {
				if ( false !== stripos( $id, $fam ) ) {
					$score += $pts;
					break;
				}
			}
			$scored[] = array( 'id' => $id, 'score' => $score );
		}
		usort( $scored, function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) {
				return $b['score'] - $a['score'];
			}
			return strcmp( $b['id'], $a['id'] ); // Newer dated snapshots first.
		} );

		$out = array();
		foreach ( $scored as $row ) {
			if ( ! in_array( $row['id'], $out, true ) ) {
				$out[] = $row['id'];
			}
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sort OpenAI chat ids most-capable → least, collapse dated/preview
	 * snapshots to one entry per family head, cap.
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function rank_openai_models( array $ids ) {
		// Version-aware: newer GPT major.minor families rank first, so a lineup
		// this code has never heard of (gpt-6-*, gpt-7…) lands at the top —
		// the old fixed table gave anything unrecognized a floor score below
		// gpt-3.5, which buried new families and pushed them off the cap.
		$score_for = function ( $id ) {
			$id    = strtolower( $id );
			$score = 1000;
			if ( preg_match( '/^gpt-(\d+)(?:\.(\d+))?/', $id, $m ) ) {
				$score = 1000 + 100 * (int) $m[1] + 10 * (int) ( isset( $m[2] ) ? $m[2] : 0 );
			} elseif ( preg_match( '/^o(\d+)/', $id, $m ) ) {
				$score = 1470 + 5 * (int) $m[1]; // o1…o4 sit below gpt-5, above gpt-4.x.
			} elseif ( 0 === strpos( $id, 'chatgpt-' ) ) {
				$score = 1300;
			}
			if ( false !== strpos( $id, '-mini' ) ) {
				$score -= 50;
			}
			if ( false !== strpos( $id, '-nano' ) ) {
				$score -= 60;
			}
			return $score;
		};

		$variant_penalty = function ( $id ) {
			$p = 0;
			if ( preg_match( '/-\d{4}-\d{2}-\d{2}/', $id ) ) {
				$p += 3;
			}
			if ( false !== stripos( $id, 'preview' ) ) {
				$p += 5;
			}
			return $p;
		};

		$scored = array();
		foreach ( $ids as $id ) {
			$scored[] = array( 'id' => $id, 'score' => $score_for( $id ) - $variant_penalty( $id ) );
		}
		usort( $scored, function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) {
				return $b['score'] - $a['score'];
			}
			return strcmp( $a['id'], $b['id'] );
		} );

		$seen = array();
		$out  = array();
		foreach ( $scored as $row ) {
			$fam = preg_replace( '/-\d{4}.*$/', '', $row['id'] );
			$fam = preg_replace( '/-preview.*$/i', '', $fam );
			if ( isset( $seen[ $fam ] ) ) {
				continue;
			}
			$seen[ $fam ] = true;
			$out[]        = $row['id'];
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Prompts + response parsing
	 * ------------------------------------------------------------------- */

	/** System prompt: role + output contract (the bundle carries the details). */
	public static function build_system_prompt() {
		return 'You are reviewing the dark mode of a WordPress GeneratePress site for the GP Dark Mode plugin. '
			. 'The user message is the plugin\'s AI Context Bundle — follow the contract in its "How to use this bundle" section exactly. '
			. 'Deliverable: a COMPLETE replacement for the "AI stylesheet" (Layer 4a) section, and nothing else. '
			. 'The "Manual CSS" (Layer 4b) is the site owner\'s hand-written layer: it loads AFTER your stylesheet and always wins — treat it as read-only context, never duplicate its selectors, and work around it rather than fighting it. '
			. 'Preserve your previous stylesheet\'s working rules unless they are wrong; change only what needs changing; never duplicate selectors the enabled modules already cover; never propose edits to theme files or other layers. '
			. 'Output format: first a short plain-text summary of what you changed and why (a few bullet lines), then the full replacement CSS inside ONE ```css fenced block. '
			. 'Mark any rule whose effect depends on cascade order or specificity with a /* verify visually */ comment.';
	}

	/** User prompt: the task + the site owner's instructions + the bundle. */
	public static function build_user_prompt( $bundle_md, $instructions = '' ) {
		$prompt = "Review this site's dark mode using the context bundle below. Fix genuine problems (unreadable combinations, "
			. "light islands in dark mode, missing dark overrides for widgets that appear in the rendered capture), prune rules "
			. "made redundant by the enabled modules, and keep everything else stable.\n\n";

		// 1.10.0: the measured contrast check, when the bundle carries one.
		if ( false !== strpos( (string) $bundle_md, '## 6. Measured contrast in dark mode' ) ) {
			$prompt .= "MEASURED PROBLEMS — section 6 of the bundle lists contrast failures and light panels measured in a browser "
				. "with the current dark mode applied (including the current AI stylesheet). Fix every dark-mode failure listed there: "
				. "text must reach 4.5:1 against its actual background (3:1 for large text). When many failures share a cause, fix the "
				. "cause (a Global Color or palette variable) rather than each selector. Failures marked 'also fails in light mode' are the "
				. "site's own design — fix them in dark mode only if that's simple. Light panels: darken them unless the selector shows "
				. "they're intentional (a logo plate, an ad slot's backing).\n\n";
		}

		$instructions = trim( (string) $instructions );
		if ( '' !== $instructions ) {
			$prompt .= "SITE OWNER'S INSTRUCTIONS FOR THIS REVIEW — these are specific fixes the owner has already identified; "
				. "prioritize them above everything else and address every one:\n"
				. $instructions . "\n\n";
		}

		return $prompt . $bundle_md;
	}

	/**
	 * Pull the proposed CSS (and the prose notes before it) out of a model
	 * response. Returns array{css, notes} or null when no CSS was found.
	 */
	public static function extract_css( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return null;
		}

		$css = '';
		if ( preg_match_all( '~```(?:css)?[ \t]*\n(.*?)```~s', $text, $m ) ) {
			// The largest fenced block containing rules is the deliverable.
			foreach ( $m[1] as $block ) {
				$block = trim( $block );
				if ( false !== strpos( $block, '{' ) && strlen( $block ) > strlen( $css ) ) {
					$css = $block;
				}
			}
		}

		$notes = '';
		if ( '' !== $css ) {
			$fence_pos = strpos( $text, '```' );
			$notes     = trim( (string) substr( $text, 0, $fence_pos ) );
		} elseif ( false === strpos( $text, '```' ) && false !== strpos( $text, '{' ) && false !== strpos( $text, '}' ) && false !== strpos( $text, 'data-theme' ) ) {
			// Unfenced response that is clearly CSS. An UNCLOSED fence never
			// takes this path: that is a truncated/malformed reply, and
			// treating it as CSS would store prose + half a stylesheet.
			$css = trim( $text );
		}

		if ( '' === $css ) {
			return null;
		}

		return array(
			'css'   => $css,
			'notes' => mb_substr( $notes, 0, 4000 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * The review pipeline
	 * ------------------------------------------------------------------- */

	/**
	 * Assemble the bundle, then either call the configured provider and store
	 * the proposal (direct mode) or hand the review to the provider as a
	 * background job (see uses_background()).
	 *
	 * @return true|string|WP_Error true = proposal stored; 'submitted' = a
	 *                              background job is running; WP_Error.
	 */
	public static function run_review( OGM_GP_Dark_Mode $plugin ) {
		$req = self::prepare( $plugin );
		if ( is_wp_error( $req ) ) {
			return $req;
		}

		if ( self::uses_background( $plugin->settings() ) ) {
			return self::submit_background( $req );
		}

		self::mark_phase(
			'calling',
			array(
				'mode'     => 'direct',
				'provider' => $req['provider'],
				'model'    => $req['model'],
				'effort'   => $req['effort'],
			)
		);
		$text = ( 'openai' === $req['provider'] )
			? self::call_openai( $req['key'], $req['model'], $req['system'], $req['user'], $req['effort'] )
			: self::call_anthropic( $req['key'], $req['model'], $req['system'], $req['user'], $req['effort'] );

		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return self::store_proposal( $text, $req['provider'], $req['model'] );
	}

	/**
	 * Everything a provider call needs: the provider-matched key, model,
	 * effort and both prompts (the bundle is assembled here).
	 *
	 * @return array|WP_Error
	 */
	private static function prepare( OGM_GP_Dark_Mode $plugin ) {
		$settings = $plugin->settings();
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : 'anthropic';
		$key      = $plugin->ai_key_for( $provider ); // Provider-matched — a key must never go to the other provider.
		$model    = isset( $settings['ai_model'] ) && '' !== trim( (string) $settings['ai_model'] )
			? trim( (string) $settings['ai_model'] )
			: self::default_model( $provider );

		if ( '' === $key ) {
			return new WP_Error( 'ogm_gpdm_ai_no_key', __( 'No API key saved for this provider — add one under AI Assist and save the settings first.', 'gp-dark-mode' ) );
		}

		self::mark_phase( 'bundle' );
		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-bundle.php';
		$bundle = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );

		$instructions = get_option( self::INSTRUCTIONS_KEY, '' );
		$instructions = is_string( $instructions ) ? $instructions : '';

		return array(
			'provider' => $provider,
			'key'      => $key,
			'model'    => $model,
			'effort'   => self::effort_for( $provider, $model, isset( $settings['ai_effort'] ) ? (string) $settings['ai_effort'] : '' ),
			'system'   => self::build_system_prompt(),
			'user'     => self::build_user_prompt( $bundle, $instructions ),
		);
	}

	/**
	 * Store CSS the site owner pasted — typically a reply from Claude using
	 * Claude in Chrome — as a proposal, so it gets the same diff, Apply,
	 * backup and Restore as a review. Accepts the whole reply (prose plus a
	 * ```css block) or bare CSS.
	 *
	 * @return true|WP_Error
	 */
	public static function store_pasted_proposal( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'ogm_gpdm_ai_paste_empty', __( 'Paste the reply (or the CSS) first.', 'gp-dark-mode' ) );
		}
		if ( false === strpos( $text, '```' ) && false !== strpos( $text, '{' ) && false === strpos( $text, 'data-theme' ) ) {
			// Bare CSS without a fence: extract_css() wants a data-theme selector
			// as proof it's dark-mode CSS, so wrap it rather than reject it.
			$text = "```css\n" . $text . "\n```";
		}
		$stored = self::store_proposal( $text, __( 'Pasted', 'gp-dark-mode' ), __( 'Claude in Chrome', 'gp-dark-mode' ) );
		if ( is_wp_error( $stored ) && 'ogm_gpdm_ai_no_css' === $stored->get_error_code() ) {
			return new WP_Error( 'ogm_gpdm_ai_no_css', __( 'No CSS found in what you pasted — include the ```css block from the reply.', 'gp-dark-mode' ) );
		}
		return $stored;
	}

	/**
	 * Turn the model's reply into the stored proposal.
	 *
	 * @return true|WP_Error
	 */
	private static function store_proposal( $text, $provider, $model ) {
		$parsed = self::extract_css( $text );
		if ( null === $parsed ) {
			return new WP_Error(
				'ogm_gpdm_ai_no_css',
				__( 'The model responded but no CSS block could be found in the reply.', 'gp-dark-mode' ) . ' ' . mb_substr( $text, 0, 200 )
			);
		}

		// autoload=false: the proposal is admin-screen-only state, potentially
		// tens of KB — it must not ride along on every front-end page load.
		update_option(
			self::PROPOSAL_KEY,
			array(
				'css'      => OGM_GP_Dark_Mode::sanitize_css( $parsed['css'] ),
				'notes'    => $parsed['notes'],
				'provider' => $provider,
				'model'    => $model,
				'created'  => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		return true;
	}

	/**
	 * Resolve the effort value to send for this provider + model, or null when
	 * the model takes none. '' (the setting's default) means medium on both
	 * providers. Claude 5-family models accept low…max as-is; OpenAI reasoning
	 * models accept the same names from gpt-6 up, while gpt-5.x and the
	 * o-series top out at high — so Max is capped there instead of sent and
	 * rejected. The ogm_gpdm_ai_effort filter still gets the last word.
	 */
	public static function effort_for( $provider, $model, $setting ) {
		$setting = in_array( $setting, array( 'low', 'medium', 'high', 'max' ), true ) ? $setting : 'medium';
		$model   = strtolower( (string) $model );
		if ( 'openai' === $provider ) {
			if ( preg_match( '/^gpt-(\d+)/', $model, $m ) && (int) $m[1] >= 6 ) {
				$effort = $setting;
			} elseif ( preg_match( '/^(o\d|gpt-5)/', $model ) ) {
				$effort = ( 'max' === $setting ) ? 'high' : $setting;
			} else {
				return null;
			}
		} else {
			if ( ! self::is_effort_claude( $model ) ) {
				return null;
			}
			$effort = $setting;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'ogm_gpdm_ai_effort', $effort, $provider, $model );
			if ( in_array( $filtered, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ) {
				$effort = $filtered;
			}
		}
		return $effort;
	}

	/**
	 * HTTP timeout (seconds) for one provider call, scaled with effort; always
	 * inside RUN_TIMEOUT. Each step leaves room to spend the whole
	 * max_tokens() allowance at ~100 output tokens/s (measured 2026-10-14:
	 * Claude Opus 5.5 at High wrote 16,000 tokens in 157 s behind a
	 * ~30k-token bundle).
	 */
	public static function effort_timeout( $effort ) {
		switch ( $effort ) {
			case 'low':
				return 240;
			case 'medium':
				return 420;
			case 'high':
				return 660;
			case 'xhigh':
			case 'max':
				return 840;
			default:
				return 240;
		}
	}

	/**
	 * The output-token allowance for one call, scaled with effort. On
	 * reasoning models it covers the thinking AND the stylesheet — Claude
	 * Opus 5.5 thinks on every request and can't be told not to — so the old
	 * fixed 16,000 ran out at High before any CSS was written. Models that
	 * take no effort keep 16,000 (gpt-4o's ceiling is 16,384). Filterable via
	 * ogm_gpdm_ai_max_tokens( $tokens, $provider, $model, $effort ); clamped
	 * to 4,000–128,000 (the largest synchronous output any listed model takes).
	 */
	public static function max_tokens( $effort = null, $provider = '', $model = '' ) {
		switch ( $effort ) {
			case 'medium':
				$tokens = 32000;
				break;
			case 'high':
				$tokens = 48000;
				break;
			case 'xhigh':
			case 'max':
				$tokens = 64000;
				break;
			default: // low, or a model that takes no effort.
				$tokens = 16000;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$tokens = (int) apply_filters( 'ogm_gpdm_ai_max_tokens', $tokens, $provider, $model, $effort );
		}
		return min( 128000, max( 4000, $tokens ) );
	}

	/**
	 * The error for a reply cut off at the token allowance. Says what ran out
	 * (thinking counts against it) and what to change in the UI; the filter
	 * is for developers.
	 */
	private static function truncated_error( $budget, $effort ) {
		$amount = function_exists( 'number_format_i18n' ) ? number_format_i18n( $budget ) : number_format( $budget );
		$advice = in_array( $effort, array( 'medium', 'high', 'xhigh', 'max' ), true )
			? __( 'Run it again at a lower Effort setting, or pick a faster model.', 'gp-dark-mode' )
			: __( 'Run it again with a different model.', 'gp-dark-mode' );
		return new WP_Error(
			'ogm_gpdm_ai_truncated',
			sprintf(
				/* translators: 1: number of tokens, 2: what to try next. */
				__( 'The model used its whole reply allowance (%1$s tokens, which covers its thinking as well as the stylesheet) before it finished, so nothing was stored. %2$s Developers can raise the allowance with the ogm_gpdm_ai_max_tokens filter.', 'gp-dark-mode' ),
				$amount,
				$advice
			)
		);
	}

	/**
	 * A transport error from wp_remote_post(), with a timeout explained in
	 * the same terms as the Effort setting instead of a raw cURL message.
	 */
	private static function transport_error( WP_Error $err, $effort ) {
		if ( false === stripos( $err->get_error_message(), 'timed out' ) ) {
			return $err;
		}
		$minutes = (int) round( self::effort_timeout( $effort ) / 60 );
		return new WP_Error(
			'ogm_gpdm_ai_timeout',
			sprintf(
				/* translators: %d: number of minutes. */
				__( 'The model was still working when the %d-minute limit for this Effort setting ran out, so nothing was stored. Run it again at a lower Effort setting, or pick a faster model.', 'gp-dark-mode' ),
				$minutes
			)
		);
	}

	/** Call the Anthropic Messages API. Returns response text or WP_Error. */
	private static function call_anthropic( $key, $model, $system, $user, $effort = null ) {
		$budget  = self::max_tokens( $effort, 'anthropic', $model );
		$body    = self::anthropic_params( $model, $system, $user, $effort, $budget );
		$headers = self::anthropic_headers( $key );

		if ( self::is_effort_claude( $model ) ) {
			// Server-side refusal fallbacks: if a safety classifier declines,
			// the API retries a category-appropriate fallback model itself.
			// Direct calls only — the Batches API rejects the parameter.
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}

		$res = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout' => self::effort_timeout( $effort ),
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return self::transport_error( $res, $effort );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ogm_gpdm_ai_http', 'Anthropic: ' . $msg );
		}
		return self::anthropic_message_text( $json, $budget, $effort );
	}

	/** Headers for every Anthropic API request. */
	private static function anthropic_headers( $key ) {
		return array(
			'content-type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);
	}

	/** Messages API parameters, shared by the direct call and the batch request. */
	private static function anthropic_params( $model, $system, $user, $effort, $budget ) {
		$params = array(
			'model'      => $model,
			'max_tokens' => $budget,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);
		// Reasoning effort, resolved by effort_for() from the Effort setting.
		if ( self::is_effort_claude( $model ) && null !== $effort ) {
			$params['output_config'] = array( 'effort' => $effort );
		}
		return $params;
	}

	/**
	 * The reply text of a Messages API message — from a direct call or a
	 * batch result — or WP_Error for a refusal, a truncated reply or an
	 * unexpected shape.
	 */
	private static function anthropic_message_text( $json, $budget, $effort ) {
		// Refusal stop reason first — a refusal can carry empty content, so it
		// must not be misreported as a malformed response.
		if ( is_array( $json ) && isset( $json['stop_reason'] ) && 'refusal' === $json['stop_reason'] ) {
			$why = isset( $json['stop_details']['explanation'] ) ? $json['stop_details']['explanation'] : '';
			return new WP_Error( 'ogm_gpdm_ai_refusal', trim( 'Anthropic declined the request. ' . $why ) );
		}

		// A truncated reply must NEVER become a proposal: half a stylesheet
		// applied as a complete replacement silently deletes the tail rules.
		if ( is_array( $json ) && isset( $json['stop_reason'] ) && 'max_tokens' === $json['stop_reason'] ) {
			return self::truncated_error( $budget, $effort );
		}

		if ( ! is_array( $json ) || empty( $json['content'] ) || ! is_array( $json['content'] ) ) {
			return new WP_Error( 'ogm_gpdm_ai_shape', __( 'Unexpected Anthropic response shape.', 'gp-dark-mode' ) );
		}

		$text = '';
		foreach ( $json['content'] as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	/** Call the OpenAI Chat Completions API. Returns response text or WP_Error. */
	private static function call_openai( $key, $model, $system, $user, $effort = null ) {
		$budget = self::max_tokens( $effort, 'openai', $model );
		$body   = array(
			'model'                 => $model,
			'max_completion_tokens' => $budget,
			'messages'              => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
		);

		// Reasoning effort, resolved by effort_for() (medium by default; omitted
		// for models that take none, such as gpt-4o).
		if ( null !== $effort ) {
			$body['reasoning_effort'] = $effort;
		}

		$res = wp_remote_post(
			self::OPENAI_URL,
			array(
				'timeout' => self::effort_timeout( $effort ),
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return self::transport_error( $res, $effort );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ogm_gpdm_ai_http', 'OpenAI: ' . $msg );
		}
		if ( ! is_array( $json ) || ! isset( $json['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'ogm_gpdm_ai_shape', __( 'Unexpected OpenAI response shape.', 'gp-dark-mode' ) );
		}

		// Truncated reply — see the Anthropic max_tokens check above.
		if ( isset( $json['choices'][0]['finish_reason'] ) && 'length' === $json['choices'][0]['finish_reason'] ) {
			return self::truncated_error( $budget, $effort );
		}

		return (string) $json['choices'][0]['message']['content'];
	}

	/* ---------------------------------------------------------------------
	 * Background jobs (1.9.0)
	 * ------------------------------------------------------------------- */

	/**
	 * Hand the review to the provider as a background job and record it in
	 * the status: an Anthropic message batch of one request, or an OpenAI
	 * background response. Returns 'submitted' or WP_Error.
	 */
	private static function submit_background( array $req ) {
		$budget = self::max_tokens( $req['effort'], $req['provider'], $req['model'] );

		if ( 'openai' === $req['provider'] ) {
			$body = array(
				'model'             => $req['model'],
				'instructions'      => $req['system'],
				'input'             => array(
					array(
						'role'    => 'user',
						'content' => $req['user'],
					),
				),
				'background'        => true,
				// Kept until the plugin fetches the result, then deleted
				// (forget_job()); without store a background response can be
				// purged ~10 minutes in, before a long review finishes.
				'store'             => true,
				'max_output_tokens' => $budget,
			);
			if ( null !== $req['effort'] ) {
				$body['reasoning'] = array( 'effort' => $req['effort'] );
			}
			$url     = self::OPENAI_RESPONSES_URL;
			$headers = self::openai_headers( $req['key'] );
			$label   = 'OpenAI';
		} else {
			$body    = array(
				'requests' => array(
					array(
						'custom_id' => self::BATCH_CUSTOM_ID,
						'params'    => self::anthropic_params( $req['model'], $req['system'], $req['user'], $req['effort'], $budget ),
					),
				),
			);
			$url     = self::ANTHROPIC_BATCH_URL;
			$headers = self::anthropic_headers( $req['key'] );
			$label   = 'Anthropic';
		}

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ogm_gpdm_ai_transport', $label . ': ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ogm_gpdm_ai_http', $label . ': ' . $msg );
		}
		if ( ! is_array( $json ) || empty( $json['id'] ) || ! is_string( $json['id'] ) ) {
			/* translators: %s: provider name. */
			return new WP_Error( 'ogm_gpdm_ai_shape', sprintf( __( '%s accepted the review but returned no job id.', 'gp-dark-mode' ), $label ) );
		}

		self::patch_status(
			array(
				'mode'     => 'background',
				'phase'    => 'submitted',
				'phase_at' => time(),
				'provider' => $req['provider'],
				'model'    => $req['model'],
				'effort'   => $req['effort'],
				'checked'  => time(),
				'job'      => array(
					'id'     => $json['id'],
					'budget' => $budget,
				),
				'message'  => self::background_note( $req['provider'] ),
			)
		);
		return 'submitted';
	}

	/** What the page says while a background job runs. */
	public static function background_note( $provider ) {
		return 'openai' === $provider
			? __( 'Running in OpenAI’s background mode — usually a few minutes. You can leave this page; the proposal will be waiting here.', 'gp-dark-mode' )
			: __( 'Queued in Anthropic’s batch service at half the usual price — usually a few minutes, occasionally up to an hour. You can leave this page; the proposal will be waiting here.', 'gp-dark-mode' );
	}

	/** Headers for every OpenAI API request. */
	private static function openai_headers( $key ) {
		return array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $key,
		);
	}

	/**
	 * Ask the provider about the running background job, and finish the run
	 * when it has an outcome: store the proposal (or the error), then delete
	 * the job's copy at the provider. Called by the page's status poll and by
	 * the CHECK_HOOK cron event; throttled to one provider call per
	 * CHECK_INTERVAL unless $force. A transient failure (network, 429, 5xx)
	 * leaves the job running for the next check.
	 */
	public static function check_job( OGM_GP_Dark_Mode $plugin, $force = false ) {
		$status = self::status();
		if ( null === $status || 'running' !== $status['state'] || ! self::is_background_status( $status ) || empty( $status['job']['id'] ) ) {
			return;
		}
		$now = time();
		if ( ! $force && isset( $status['checked'] ) && $now - (int) $status['checked'] < self::CHECK_INTERVAL ) {
			return;
		}
		self::patch_status( array( 'checked' => $now ) );

		$provider = isset( $status['provider'] ) && 'openai' === $status['provider'] ? 'openai' : 'anthropic';
		$key      = $plugin->ai_key_for( $provider );
		if ( '' === $key ) {
			self::set_status( 'error', __( 'The API key was removed while the review was running, so its result can’t be collected. Save the key and run the review again.', 'gp-dark-mode' ) );
			return;
		}

		$job    = $status['job'];
		$effort = isset( $status['effort'] ) ? $status['effort'] : null;
		$budget = isset( $job['budget'] ) ? (int) $job['budget'] : self::max_tokens( $effort );
		$poll   = ( 'openai' === $provider )
			? self::poll_openai( $key, (string) $job['id'], $budget, $effort )
			: self::poll_anthropic( $key, (string) $job['id'], $budget, $effort );

		if ( 'running' === $poll['state'] ) {
			return;
		}
		if ( 'retry' === $poll['state'] ) {
			self::patch_status( array( 'message' => self::background_note( $provider ) . ' ' . $poll['note'] ) );
			return;
		}

		$outcome = ( 'done' === $poll['state'] )
			? self::store_proposal( $poll['text'], $provider, isset( $status['model'] ) ? (string) $status['model'] : '' )
			: $poll['error'];
		if ( is_wp_error( $outcome ) ) {
			self::set_status( 'error', $outcome->get_error_message() );
		} else {
			self::set_status( 'done', __( 'Review complete — a proposal is ready.', 'gp-dark-mode' ) );
		}
		self::forget_job( $provider, $key, (string) $job['id'] );
	}

	/** A 'retry' poll result: the check failed in a way the next one may not. */
	private static function poll_retry( $label, $detail ) {
		return array(
			'state' => 'retry',
			/* translators: 1: provider name, 2: error detail. */
			'note'  => sprintf( __( '(The last check with %1$s failed — %2$s — the next one retries.)', 'gp-dark-mode' ), $label, $detail ),
		);
	}

	/**
	 * Poll an Anthropic message batch.
	 *
	 * @return array{state:string,text?:string,error?:WP_Error,note?:string}
	 */
	private static function poll_anthropic( $key, $id, $budget, $effort ) {
		$res = wp_remote_get( self::ANTHROPIC_BATCH_URL . '/' . rawurlencode( $id ), array( 'timeout' => 20, 'headers' => self::anthropic_headers( $key ) ) );
		$got = self::poll_response( $res, 'Anthropic' );
		if ( isset( $got['state'] ) ) {
			return $got;
		}
		$batch = $got['json'];
		if ( ! isset( $batch['processing_status'] ) || 'ended' !== $batch['processing_status'] ) {
			return array( 'state' => 'running' );
		}
		if ( empty( $batch['results_url'] ) || ! is_string( $batch['results_url'] ) ) {
			return self::poll_retry( 'Anthropic', __( 'the batch ended but its results aren’t available yet', 'gp-dark-mode' ) );
		}

		$res = wp_remote_get( $batch['results_url'], array( 'timeout' => 30, 'headers' => self::anthropic_headers( $key ) ) );
		if ( is_wp_error( $res ) ) {
			return self::poll_retry( 'Anthropic', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return self::poll_retry( 'Anthropic', 'HTTP ' . $code );
		}

		$result = null;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_remote_retrieve_body( $res ) ) as $line ) {
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) && isset( $row['custom_id'], $row['result'] ) && self::BATCH_CUSTOM_ID === $row['custom_id'] ) {
				$result = $row['result'];
				break;
			}
		}
		$type = is_array( $result ) && isset( $result['type'] ) ? $result['type'] : '';

		if ( 'succeeded' === $type ) {
			$text = self::anthropic_message_text( isset( $result['message'] ) ? $result['message'] : null, $budget, $effort );
			return is_wp_error( $text ) ? array( 'state' => 'error', 'error' => $text ) : array( 'state' => 'done', 'text' => $text );
		}
		if ( 'errored' === $type ) {
			$msg = isset( $result['error']['error']['message'] ) ? $result['error']['error']['message'] : ( isset( $result['error']['message'] ) ? $result['error']['message'] : __( 'the request failed', 'gp-dark-mode' ) );
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_http', 'Anthropic: ' . $msg ) );
		}
		if ( 'expired' === $type ) {
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_expired', __( 'Anthropic’s batch service didn’t get to the review within 24 hours, so it expired unbilled. Run it again.', 'gp-dark-mode' ) ) );
		}
		if ( 'canceled' === $type ) {
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_canceled', __( 'Review canceled.', 'gp-dark-mode' ) ) );
		}
		return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_shape', __( 'Anthropic’s batch ended without a result for the review.', 'gp-dark-mode' ) ) );
	}

	/**
	 * Poll an OpenAI background response.
	 *
	 * @return array{state:string,text?:string,error?:WP_Error,note?:string}
	 */
	private static function poll_openai( $key, $id, $budget, $effort ) {
		$res = wp_remote_get( self::OPENAI_RESPONSES_URL . '/' . rawurlencode( $id ), array( 'timeout' => 20, 'headers' => self::openai_headers( $key ) ) );
		$got = self::poll_response( $res, 'OpenAI' );
		if ( isset( $got['state'] ) ) {
			return $got;
		}
		$json   = $got['json'];
		$status = isset( $json['status'] ) ? (string) $json['status'] : '';

		if ( in_array( $status, array( 'queued', 'in_progress', '' ), true ) ) {
			return array( 'state' => 'running' );
		}
		if ( 'incomplete' === $status ) {
			$reason = isset( $json['incomplete_details']['reason'] ) ? (string) $json['incomplete_details']['reason'] : '';
			if ( 'max_output_tokens' === $reason ) {
				return array( 'state' => 'error', 'error' => self::truncated_error( $budget, $effort ) );
			}
			/* translators: %s: the reason OpenAI gave. */
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_incomplete', sprintf( __( 'OpenAI stopped the review before it finished (%s), so nothing was stored.', 'gp-dark-mode' ), '' !== $reason ? $reason : 'incomplete' ) ) );
		}
		if ( 'failed' === $status ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'the review failed', 'gp-dark-mode' );
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_http', 'OpenAI: ' . $msg ) );
		}
		if ( 'cancelled' === $status || 'canceled' === $status ) {
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_canceled', __( 'Review canceled.', 'gp-dark-mode' ) ) );
		}
		if ( 'completed' !== $status ) {
			return array( 'state' => 'running' ); // A status this code doesn't know yet.
		}

		$text    = '';
		$refusal = '';
		foreach ( isset( $json['output'] ) && is_array( $json['output'] ) ? $json['output'] : array() as $item ) {
			if ( ! isset( $item['type'] ) || 'message' !== $item['type'] || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $part ) {
				if ( isset( $part['type'], $part['text'] ) && 'output_text' === $part['type'] ) {
					$text .= $part['text'];
				} elseif ( isset( $part['type'] ) && 'refusal' === $part['type'] ) {
					$refusal .= isset( $part['refusal'] ) ? $part['refusal'] : '';
				}
			}
		}
		if ( '' === $text && '' !== $refusal ) {
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_refusal', trim( 'OpenAI declined the request. ' . $refusal ) ) );
		}
		if ( '' === $text ) {
			return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_shape', __( 'Unexpected OpenAI response shape.', 'gp-dark-mode' ) ) );
		}
		return array( 'state' => 'done', 'text' => $text );
	}

	/**
	 * Shared handling of a job-status GET: ['json' => …] on 200, a retry for
	 * transient failures (network, 408/409/429, 5xx), an error otherwise
	 * (the job is gone, or the key no longer works).
	 */
	private static function poll_response( $res, $label ) {
		if ( is_wp_error( $res ) ) {
			return self::poll_retry( $label, $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 === $code && is_array( $json ) ) {
			return array( 'json' => $json );
		}
		if ( in_array( $code, array( 0, 200, 408, 409, 429 ), true ) || $code >= 500 ) {
			return self::poll_retry( $label, 'HTTP ' . $code );
		}
		$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
		return array( 'state' => 'error', 'error' => new WP_Error( 'ogm_gpdm_ai_http', $label . ': ' . $msg ) );
	}

	/**
	 * Delete the job's copy at the provider once its result is in (or it
	 * failed): the request held the whole AI Context Bundle. Best effort —
	 * a failed delete leaves it to the provider's own retention.
	 */
	private static function forget_job( $provider, $key, $id ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return;
		}
		$url = ( 'openai' === $provider ? self::OPENAI_RESPONSES_URL : self::ANTHROPIC_BATCH_URL ) . '/' . rawurlencode( $id );
		wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => 'openai' === $provider ? self::openai_headers( $key ) : self::anthropic_headers( $key ),
			)
		);
	}

	/**
	 * Cancel the running background job (best effort at the provider) and
	 * close the run. A direct call can't be stopped once it's in flight.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel_job( OGM_GP_Dark_Mode $plugin ) {
		$status = self::status();
		if ( null === $status || 'running' !== $status['state'] ) {
			return new WP_Error( 'ogm_gpdm_ai_not_running', __( 'No review is running.', 'gp-dark-mode' ) );
		}
		if ( ! self::is_background_status( $status ) || empty( $status['job']['id'] ) ) {
			return new WP_Error( 'ogm_gpdm_ai_not_cancelable', __( 'This review is a direct call to the model and can’t be stopped — it ends on its own within the time limit for its Effort setting.', 'gp-dark-mode' ) );
		}
		$provider = isset( $status['provider'] ) && 'openai' === $status['provider'] ? 'openai' : 'anthropic';
		$key      = $plugin->ai_key_for( $provider );
		if ( '' !== $key ) {
			$url = ( 'openai' === $provider ? self::OPENAI_RESPONSES_URL : self::ANTHROPIC_BATCH_URL ) . '/' . rawurlencode( (string) $status['job']['id'] ) . '/cancel';
			wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => 'openai' === $provider ? self::openai_headers( $key ) : self::anthropic_headers( $key ),
					'body'    => '{}',
				)
			);
		}
		self::set_status( 'error', __( 'Review canceled.', 'gp-dark-mode' ) );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Proposal lifecycle
	 * ------------------------------------------------------------------- */

	/** The stored proposal (array) or null. */
	public static function proposal() {
		$prop = get_option( self::PROPOSAL_KEY, null );
		return ( is_array( $prop ) && isset( $prop['css'] ) && is_string( $prop['css'] ) && '' !== $prop['css'] ) ? $prop : null;
	}

	/** The stored pre-apply backup (array) or null. */
	public static function backup() {
		$backup = get_option( self::BACKUP_KEY, null );
		return ( is_array( $backup ) && isset( $backup['css'] ) && is_string( $backup['css'] ) ) ? $backup : null;
	}

	/** Drop the stored proposal. */
	public static function discard() {
		delete_option( self::PROPOSAL_KEY );
	}

	/**
	 * Apply the stored proposal as the new AI stylesheet (Layer 4a), backing
	 * up the previous AI stylesheet first. The owner's Manual CSS is never
	 * touched — it is a separate layer that loads after this one.
	 *
	 * @return true|WP_Error
	 */
	public static function apply_proposal( OGM_GP_Dark_Mode $plugin ) {
		$prop = self::proposal();
		if ( null === $prop ) {
			return new WP_Error( 'ogm_gpdm_ai_no_proposal', __( 'No proposal to apply.', 'gp-dark-mode' ) );
		}

		update_option(
			self::BACKUP_KEY,
			array(
				'css'     => self::ai_css(),
				'created' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		// AI_CSS_KEY is unregistered (no sanitizer re-entry) and autoloaded
		// (front-end state). Sanitize the CSS itself on write.
		update_option( self::AI_CSS_KEY, OGM_GP_Dark_Mode::sanitize_css( $prop['css'] ), true );
		delete_option( self::PROPOSAL_KEY );

		return true;
	}

	/**
	 * Restore the backup — as a SWAP of the AI stylesheet: the current AI
	 * CSS goes into the backup slot before being overwritten, so Restore is
	 * always reversible (click twice and you're back where you started).
	 *
	 * @return true|WP_Error
	 */
	public static function restore_backup( OGM_GP_Dark_Mode $plugin ) {
		$backup = self::backup();
		if ( null === $backup ) {
			return new WP_Error( 'ogm_gpdm_ai_no_backup', __( 'No backup to restore.', 'gp-dark-mode' ) );
		}

		update_option(
			self::BACKUP_KEY,
			array(
				'css'     => self::ai_css(),
				'created' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		update_option( self::AI_CSS_KEY, OGM_GP_Dark_Mode::sanitize_css( $backup['css'] ), true );

		return true;
	}
}
