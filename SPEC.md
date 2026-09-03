# GP Dark Mode — Specification

Robust, no-flash, accessible dark mode for GeneratePress, with a layered
stylesheet architecture, a generated palette, vendor modules, and a BYOK AI
review loop. By Orchard Grove Media. GPL-2.0-or-later. Requires WP 6.0+,
PHP 7.4+.

This document is the authority on architecture and invariants. The readme.txt
changelog is the authority on release history. `tests/run.sh` is the executable
proof of the behaviors described here.

---

## 1. Front-end contract (stable since 1.0.0 — do not break)

- Dark mode is `data-theme="dark"` on `<html>`; light is `data-theme="light"`.
- The inline head script (priority 0 on `wp_head`) sets the theme BEFORE first
  paint (no flash) and owns the `theme-init` lifecycle (a class suppressing
  transitions, removed via rAF after first paint — head-owned so it clears even
  when the toggle is hidden).
- Visitor choice persists in `localStorage['theme']` with a cookie (`theme`,
  365d, SameSite=Lax) fallback. Optional `respect_system` follows
  `prefers-color-scheme` for visitors with no saved choice, with live updates.
- Toggle markup: `button#dark-mode-toggle.dm-toggle[role=switch]` containing
  `.dm-track > .dm-icon.dm-sun + .dm-icon.dm-moon + .dm-knob`. Icons are inline
  SVGs (1.4.1) — text glyphs render per-site fonts and looked different on
  every site. The toggle JS binds EVERY `.dm-toggle` (GeneratePress renders the
  menu bar in multiple navs). Placement: `generate_menu_bar_items` @20 or the
  `[gp_dark_mode_toggle]` shortcode. Filter: `ogm_gpdm_toggle_markup`.
- Palette tokens: `--bg --bg-2 --surface --surface-2 --text --text-muted
  --border --link --dm-accent` (light values on `:root`, dark under
  `html[data-theme="dark"]`).

## 2. The five stylesheet layers (cascade order)

Output is assembled in `enqueue_front_assets()` and printed inline on the
`generate-style` handle at `wp_enqueue_scripts` @20 — the SAME cascade slot as
the original child-theme implementation: BEFORE GeneratePress's customizer
dynamic CSS (same handle @50 / `generatepress-dynamic-css`) and before the
child `style.css`, so the site's own styles win specificity ties. Non-GP
themes fall back to a late standalone stylesheet.

| # | Layer | Source | Toggle | Notes |
|---|-------|--------|--------|-------|
| 1 | Framework | `assets/css/gp-dark-mode.css` | always | Mechanics + tokens + toggle component. **INERT in light mode** (1.3.2): every global rule is dark-scoped. The self-referential `--accent: var(--accent, #1d428a)` is kept verbatim — GP's dynamic CSS redefines it later so it stays valid (brand color on the reference site); on themes without `--accent` it computes to transparent. |
| 2 | Generated palette | option `ogm_gpdm_generated_css` (GEN_KEY, autoloaded) | `enable_generated` (default 0 — opt-in, review first) | `OGM_GPDM_Palette::generate()`: GP Global Colors → RANK-BASED dark ramps (never naive inversion — it collapses GP's near-white surface ramp). Surfaces: lightest→darkest, L = 0.07 + 0.045·rank. Text tiers: 0.92 − 0.10·rank with WCAG floors (4.5:1 primary, 3:1 others). Classification is USAGE-AWARE: `usage_roles()` reads where each color is actually used (GP settings values holding `var(--slug)` or the literal hex, keyed background→surface / text·title·link→text; plus `background*: var(--slug)` / `color: var(--slug)` in Customizer CSS and child style.css) and real usage overrides the slug-name guess — slug names lie (live case: a dark site header painted with `var(--contrast)`). Conflicting usage (both roles) → left unchanged with a note; utility and brand classes are exempt from overrides. Already-light text, ALREADY-DARK surfaces (L ≤ 0.32 — mirror rule; inverting them would lighten a deliberately dark header/footer), `white`/`black` utility slugs, and readable brand colors (≥3:1) stay untouched; too-dark brands are lightened to 3:1. Saturated colors in text slots keep hue strength (s cap 0.55 when s ≥ 0.40). `rgb()/rgba()` parsed; unparseable/unclassified colors are listed in output comments, never silently dropped. |
| 3 | Integration modules | `assets/css/modules/*.css` | `modules[slug]` checkboxes | Registry in `OGM_GP_Dark_Mode::modules()`: gp-chrome (default on), revcontent (manual — script-injected, undetectable), ad-inserter / givewp / forminator (auto-detected ON at fresh activation only). Written against vendors' STABLE generic selectors; per-placement selectors belong in Layer 4b. Plugin-versioned: fixes ship to every site. |
| 4a | AI stylesheet | option `ogm_gpdm_ai_css` (autoloaded) | `enable_ai_css` (default 1) | Machine-owned. Each accepted proposal replaces it wholesale. Backup/swap (`ogm_gpdm_ai_backup`) operates on THIS layer only. |
| 4b | Manual CSS | setting `custom_css` | `enable_custom_css` (default 1) | The owner's layer. Loads LAST, always wins, never touched by the AI. Starts EMPTY — since 1.6.0 the plugin ships no site-specific CSS; the reference site's old residue is a test fixture (`tests/fixtures/tgp-manual-css.css`), pasted into that site's own Manual tab at deploy. |

`tests/coverage.php` proves the 1.2.0 decomposition was lossless: all 341
selector+declaration pairs of the pre-split seed exist in the union of
framework + modules + the reference site's residue (a fixture since 1.6.0,
not shipped). Deliberate later divergences are allowed
only via the harness's documented `$intentionally_dropped` list (each entry
names the release and reason); anything unlisted still fails. First entries
(1.5.8): the seed's `font-size`/`font-weight` on homepage ad headlines —
**dark mode must never alter typography**, only color.

## 3. Options inventory

Registered (Settings API, sanitized by `sanitize_settings`):

- `ogm_gpdm_settings` — default_theme, respect_system, show_toggle,
  enable_custom_css, enable_ai_css, accent_nav, enable_generated,
  modules{slug:0|1}, ai_provider, ai_api_key_anthropic, ai_api_key_openai,
  ai_model ('' = provider default), custom_css.

Unregistered (programmatic state — see invariant #1):

- `ogm_gpdm_generated_css` (autoload yes — front-end), `ogm_gpdm_ai_css`
  (autoload yes — front-end), `ogm_gpdm_ai_proposal`, `ogm_gpdm_ai_backup`,
  `ogm_gpdm_ai_status`, `ogm_gpdm_ai_instructions`, `ogm_gpdm_ai_models`
  (all autoload no — admin-only).

`uninstall.php` deletes every option above, looping all sites on multisite.

## 4. Invariants (hard-won — each one broke in production or review)

1. **Never write a registered option programmatically with a partial array.**
   `register_setting`'s sanitize callback runs on EVERY `update_option` of
   that key and treats the value as raw form input — it discarded a generated
   palette, wiped the seed fallback, and zeroed the modules map before we
   learned this. Programmatic state lives in separate UNREGISTERED options.
   (Migrations run on `plugins_loaded`, before `admin_init` registers the
   sanitizer, so they may write the settings option raw.)
2. **No free text in redirect query strings.** mod_security-class WAFs 403
   URLs carrying quoted provider error text (live-verified: the admin saw
   "Forbidden" instead of the real error). Redirects carry fixed codes only;
   error text travels via AJAX JSON or stored status.
3. **Long work never runs in a web request.** Proxies 502 requests over
   ~60–100s (live-verified). The AI review runs on a WP-Cron event; the page
   polls. A `running` status older than 15 min is treated as failed.
4. **Layer 1 is a no-op in light mode.** Both-modes opinions are
   reference-site residue and belong in Layer 4b (live-verified: an un-scoped
   `p { color: var(--text) !important }` broke another site's byline colors).
5. **API keys are per-provider** and must never be sent to the other
   provider's endpoint; keys are redacted from the bundle (which is
   downloaded, shared, AND sent inside review prompts); the key field renders
   a mask (`KEY_MASK`), and "clear" removes a key.
6. **Truncated model replies are rejected, never stored** (`max_tokens` /
   `length` stop reasons; an unclosed ``` fence in extract_css is treated as
   truncation). A truncated "complete replacement" silently deletes tail
   rules and the diff can't reveal it.
7. **Nothing the AI produces is applied without a human click**, and applying
   can only ever replace the machine-owned Layer 4a.
8. **Upgrades render pixel-identically** (see migrations).
9. **Icons and widget chrome never depend on site fonts** (inline SVG).

## 5. AI review flow

Run (AJAX `ogm_gpdm_ai_start`: cap + nonce + multisite super-admin gate + key
pre-flight) → stores owner instructions (`ogm_gpdm_ai_instructions`, ≤4000
chars, prefills next run) → status=running → `wp_schedule_single_event` +
`spawn_cron` → cron hook `ogm_gpdm_run_ai_review` executes
`OGM_GPDM_AI::run_review()`:

1. Assemble the AI Context Bundle (`OGM_GPDM_Bundle`, section 6).
2. Prompt: system = role + output contract (deliverable replaces Layer 4a;
   Layer 4b is read-only context that always wins); user = task + owner
   instructions (top priority) + bundle.
3. Provider call: Anthropic Messages API (default `claude-opus-5`,
   server-side refusal fallbacks beta, effort `medium` via
   `ogm_gpdm_ai_effort` filter) or OpenAI chat completions (default `gpt-5`,
   reasoning_effort low). `ogm_gpdm_ai_max_tokens` filter (default 16000,
   min 4000). Refusals and truncation are surfaced as errors.
4. `extract_css`: largest fenced CSS block wins; prose before it = notes;
   unfenced pure CSS accepted only when NO fence appears anywhere.
5. Proposal stored (`ogm_gpdm_ai_proposal`) → page polls
   (`ogm_gpdm_ai_status` AJAX) → diff vs current AI stylesheet →
   Apply / Discard / Swap-restore (admin-post, fixed-code redirects).

Model dropdowns: per-provider seed lists; "Refresh models" AJAX pulls
`/v1/models`, filters chat-capable families, ranks most-capable-first
(fable > opus > sonnet > haiku; o-series/gpt scoring), collapses dated
snapshots, caps at 12, caches in `ogm_gpdm_ai_models`.

## 6. AI Context Bundle

One Markdown export (settings-page download + review-prompt payload):
site meta; GP Global Colors + color settings from the DB; plugin settings
(API keys REDACTED) + all five layers labeled with their roles; child
`style.css` (reduced to color-relevant rules by a quote-aware, resync-on-
malformed CSS parser); `functions.php` verbatim with best-effort secret
redaction; Customizer CSS; a rendered capture of the homepage + latest post
(inline styles + same-origin wp-content sheets, deduped, 25s network budget,
URLs filterable via `ogm_gpdm_bundle_urls`). Fences auto-lengthen past any
backtick run in content. The "How to use" preamble is the machine-readable
contract; it must stay truthful to the code (e.g. it flags when Additional
CSS output is disabled and when GP is not the active theme).

## 7. Migrations (`maybe_upgrade`, plugins_loaded, one-time each)

- pre-1.2 (no `modules` key): never-saved installs → ALL modules on (output ≡
  old seed, coverage-proven); saved installs → ALL modules off (their saved
  CSS is the complete old world). Pixel-identical either way.
- pre-1.4.2 (`ai_api_key` present): key moves to the provider it was saved
  for; legacy field removed.
- pre-1.5 (no `enable_ai_css`): flag added; `custom_css` stays as Manual
  (4b) untouched; stale old-semantics proposal/backup options deleted.

## 8. Settings UI (1.5.0)

Tabbed, one tab per layer in cascade order + Settings:
**Shipped | Generated | AI | Manual | Settings** — all panels inside ONE form
(hidden tabs still submit; single Save button), hash-driven JS tabs. The
review-instructions textarea deliberately has NO name attribute (it travels
only with the AJAX start, never through the settings form). Completion never
auto-reloads (it would destroy unsaved form edits) — it offers a link.

## 9. Testing

`tests/run.sh` — plain PHP CLI, no WordPress install:

- `unit.php` — pure functions: CSS extractor (incl. malformed-input resync),
  secret redaction, palette color math + ramp derivation, model ranking,
  AI response parsing, framework light-inertness guards.
- `coverage.php` — the 341-pair decomposition proof.
- `smoke.php` — end-to-end under a stubbed WP: bundle assembly, five-layer
  cascade order, migrations, AI pipeline (both providers, refusal,
  truncation, missing key), apply/restore on the AI layer with Manual
  untouched, status lifecycle, key semantics.

Every confirmed finding from an adversarial review round gets a regression
test named for it. The optional TGP reference fixture at
`~/Desktop/dark-mode/` deepens two suites when present.

## 10. File map

```
gp-dark-mode.php                     Main class (settings, layers, tabs UI, handlers)
includes/class-ogm-gpdm-bundle.php   AI Context Bundle assembler
includes/class-ogm-gpdm-palette.php  Layer-2 generated palette (color math)
includes/class-ogm-gpdm-ai.php       BYOK AI review (providers, models, proposal lifecycle)
assets/css/gp-dark-mode.css          Layer 1 framework
assets/css/modules/*.css             Layer 3 integration modules
tests/fixtures/tgp-manual-css.css    reference-site residue (fixture, not shipped)
assets/js/dark-mode-toggle.js        Front-end toggle behavior
assets/admin/gp-dark-mode-admin.*    Settings-page CSS/JS (tabs, models, review polling)
tests/                               run.sh + unit/coverage/smoke + fixtures
uninstall.php                        Full cleanup, multisite-aware
```
