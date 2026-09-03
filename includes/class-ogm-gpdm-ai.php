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

	/** Write a status. */
	public static function set_status( $state, $message = '' ) {
		$current = self::status();
		update_option(
			self::STATUS_KEY,
			array(
				'state'    => $state,
				'message'  => mb_substr( (string) $message, 0, 600 ),
				'started'  => ( 'running' === $state || null === $current ) ? time() : ( isset( $current['started'] ) ? (int) $current['started'] : time() ),
				'finished' => ( 'running' === $state ) ? 0 : time(),
			),
			false
		);
	}

	/** True while a fresh run is in flight. */
	public static function is_running() {
		$status = self::status();
		return null !== $status
			&& 'running' === $status['state']
			&& ( time() - (int) $status['started'] ) < self::RUN_TIMEOUT;
	}

	/**
	 * Status for the polling endpoint — converts a stale 'running' into an
	 * error so the UI never spins forever when cron stalls.
	 */
	public static function polled_status() {
		$status = self::status();
		if ( null === $status ) {
			return array( 'state' => 'idle', 'message' => '' );
		}
		if ( 'running' === $status['state'] && ( time() - (int) $status['started'] ) >= self::RUN_TIMEOUT ) {
			self::set_status( 'error', __( 'The review timed out or the site’s cron never ran it. Check that WP-Cron is working, then try again.', 'gp-dark-mode' ) );
			return self::status();
		}
		return $status;
	}

	/** Providers and their default models. */
	public static function providers() {
		return array(
			'anthropic' => array( 'label' => 'Anthropic (Claude)', 'default_model' => 'claude-opus-5' ),
			'openai'    => array( 'label' => 'OpenAI', 'default_model' => 'gpt-5' ),
		);
	}

	/** Default model for a provider. */
	public static function default_model( $provider ) {
		$providers = self::providers();
		return isset( $providers[ $provider ] ) ? $providers[ $provider ]['default_model'] : 'claude-opus-5';
	}

	/* ---------------------------------------------------------------------
	 * Model lists (dropdown + refresh — Editorial QA pattern)
	 * ------------------------------------------------------------------- */

	/** Seed model lists, shown until the first "Refresh models". */
	public static function seed_models( $provider ) {
		return 'openai' === $provider
			? array( 'gpt-5', 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1' )
			: array( 'claude-opus-5', 'claude-sonnet-5', 'claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5' );
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
		$score_for = function ( $id ) {
			$id = strtolower( $id );
			if ( preg_match( '/^o4(?!-mini)/', $id ) ) {
				return 105;
			}
			if ( preg_match( '/^o3(?!-mini)/', $id ) ) {
				return 100;
			}
			if ( preg_match( '/^o1(?!-mini)/', $id ) ) {
				return 90;
			}
			if ( preg_match( '/^o4-mini/', $id ) ) {
				return 72;
			}
			if ( preg_match( '/^o3-mini/', $id ) ) {
				return 70;
			}
			if ( preg_match( '/^o1-mini/', $id ) ) {
				return 65;
			}
			if ( 0 === strpos( $id, 'gpt-5' ) ) {
				return 95;
			}
			if ( 0 === strpos( $id, 'gpt-4.5' ) ) {
				return 85;
			}
			if ( 0 === strpos( $id, 'gpt-4.1-mini' ) ) {
				return 58;
			}
			if ( 0 === strpos( $id, 'gpt-4.1' ) ) {
				return 82;
			}
			if ( 0 === strpos( $id, 'gpt-4o-mini' ) ) {
				return 55;
			}
			if ( 0 === strpos( $id, 'gpt-4o' ) ) {
				return 80;
			}
			if ( 0 === strpos( $id, 'gpt-4-turbo' ) ) {
				return 75;
			}
			if ( 0 === strpos( $id, 'gpt-4' ) ) {
				return 60;
			}
			if ( 0 === strpos( $id, 'gpt-3.5' ) ) {
				return 40;
			}
			if ( 0 === strpos( $id, 'chatgpt-' ) ) {
				return 50;
			}
			return 30;
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
	 * Assemble the bundle, call the configured provider, store the proposal.
	 *
	 * @return true|WP_Error
	 */
	public static function run_review( OGM_GP_Dark_Mode $plugin ) {
		$settings = $plugin->settings();
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : 'anthropic';
		$key      = $plugin->ai_key_for( $provider ); // Provider-matched — a key must never go to the other provider.
		$model    = isset( $settings['ai_model'] ) && '' !== trim( (string) $settings['ai_model'] )
			? trim( (string) $settings['ai_model'] )
			: self::default_model( $provider );

		if ( '' === $key ) {
			return new WP_Error( 'ogm_gpdm_ai_no_key', __( 'No API key saved for this provider — add one under AI Assist and save the settings first.', 'gp-dark-mode' ) );
		}

		require_once OGM_GPDM_DIR . 'includes/class-ogm-gpdm-bundle.php';
		$bundle = OGM_GPDM_Bundle::to_markdown( OGM_GPDM_Bundle::assemble( $plugin ) );

		$instructions = get_option( self::INSTRUCTIONS_KEY, '' );
		$instructions = is_string( $instructions ) ? $instructions : '';

		$system = self::build_system_prompt();
		$user   = self::build_user_prompt( $bundle, $instructions );

		$text = ( 'openai' === $provider )
			? self::call_openai( $key, $model, $system, $user )
			: self::call_anthropic( $key, $model, $system, $user );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

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

	/** The response token budget (filterable — see the truncation errors). */
	private static function max_tokens() {
		return max( 4000, (int) apply_filters( 'ogm_gpdm_ai_max_tokens', 16000 ) );
	}

	/** Call the Anthropic Messages API. Returns response text or WP_Error. */
	private static function call_anthropic( $key, $model, $system, $user ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => self::max_tokens(),
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);

		if ( preg_match( '~^claude-(fable|opus-5|opus-4-[6-8]|sonnet-5|sonnet-4-6)~', $model ) ) {
			// Server-side refusal fallbacks: if a safety classifier declines,
			// the API retries a category-appropriate fallback model itself.
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';

			/**
			 * Reasoning effort for the review. 'medium' keeps a single web
			 * request comfortably inside shared-hosting time limits; raise to
			 * 'high' if your host allows long requests.
			 */
			$effort = apply_filters( 'ogm_gpdm_ai_effort', 'medium' );
			if ( in_array( $effort, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ) {
				$body['output_config'] = array( 'effort' => $effort );
			}
		}

		$res = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout' => 180,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ogm_gpdm_ai_http', 'Anthropic: ' . $msg );
		}
		// Refusal stop reason first — a refusal can carry empty content, so it
		// must not be misreported as a malformed response.
		if ( is_array( $json ) && isset( $json['stop_reason'] ) && 'refusal' === $json['stop_reason'] ) {
			$why = isset( $json['stop_details']['explanation'] ) ? $json['stop_details']['explanation'] : '';
			return new WP_Error( 'ogm_gpdm_ai_refusal', trim( 'Anthropic declined the request. ' . $why ) );
		}

		// A truncated reply must NEVER become a proposal: half a stylesheet
		// applied as a complete replacement silently deletes the tail rules.
		if ( is_array( $json ) && isset( $json['stop_reason'] ) && 'max_tokens' === $json['stop_reason'] ) {
			return new WP_Error( 'ogm_gpdm_ai_truncated', __( 'The response hit the token limit and would be incomplete — nothing was stored. Raise the limit via the ogm_gpdm_ai_max_tokens filter, or trim the Additional CSS before reviewing.', 'gp-dark-mode' ) );
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
	private static function call_openai( $key, $model, $system, $user ) {
		$body = array(
			'model'                 => $model,
			'max_completion_tokens' => self::max_tokens(),
			'messages'              => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
		);

		// Reasoning models: low effort keeps the request fast (same choice
		// Editorial QA ships for o*/gpt-5).
		if ( preg_match( '~^(o[0-9]|gpt-5)~', $model ) ) {
			$body['reasoning_effort'] = 'low';
		}

		$res = wp_remote_post(
			self::OPENAI_URL,
			array(
				'timeout' => 180,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
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
			return new WP_Error( 'ogm_gpdm_ai_truncated', __( 'The response hit the token limit and would be incomplete — nothing was stored. Raise the limit via the ogm_gpdm_ai_max_tokens filter, or trim the Additional CSS before reviewing.', 'gp-dark-mode' ) );
		}

		return (string) $json['choices'][0]['message']['content'];
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
