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
  menu bar in multiple navs). Placement: `generate_menu_bar_items` @20, the
  `[gp_dark_mode_toggle]` shortcode, or the **Dark Mode Toggle block** (1.7.0,
  `gp-dark-mode/toggle`, `blocks/toggle/`): apiVersion 3, server-rendered
  (`save` returns null; `render_toggle_block()` wraps the shortcode output in
  `get_block_wrapper_attributes()` so margin/className supports apply; renders
  regardless of `show_toggle`). Editor script is plain ES5 on WP globals with a
  hand-written `index.asset.php` (no build step); `editor.css` styles a static
  preview. Why: GP Premium Site Header Block Elements (incl. GB Pro Site Header
  + Navigation headers) replace GP's nav, so `generate_menu_bar_items` never
  fires there; the Settings tab detects a published `gp_elements` post with
  `_generate_block_type = site-header` and points to the block. Filter:
  `ogm_gpdm_toggle_markup`.
- Palette tokens: `--bg --bg-2 --surface --surface-2 --text --text-muted
  --border --link --dm-accent --dm-glow` (light values on `:root`, dark under
  `html[data-theme="dark"]`). `--dm-glow` (1.8.0) is the toggle's hover fill
  (#0056ff light / #122666 dark built in — the old literals, so the toggle
  renders pixel-identically).

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
| 2 | Generated palette | option `ogm_gpdm_generated_css` (GEN_KEY, autoloaded) | `enable_generated` (default 0 — opt-in, review first) | `OGM_GPDM_Palette::generate()`: GP Global Colors → RANK-BASED dark ramps (never naive inversion — it collapses GP's near-white surface ramp). Surfaces: lightest→darkest, L = 0.07 + 0.045·rank. Text tiers: 0.92 − 0.10·rank with WCAG floors (4.5:1 primary, 3:1 others). Classification is USAGE-AWARE: `usage_roles()` reads where each color is actually used (GP settings values holding `var(--slug)` or the literal hex, keyed background→surface / text·title·link→text; plus `background*: var(--slug)` / `color: var(--slug)` in Customizer CSS and child style.css) and real usage overrides the slug-name guess — slug names lie (live case: a dark site header painted with `var(--contrast)`). Conflicting usage (both roles) → left unchanged with a note; utility and brand classes are exempt from overrides. Already-light text, ALREADY-DARK surfaces (L ≤ 0.32 — mirror rule; inverting them would lighten a deliberately dark header/footer), `white`/`black` utility slugs, and readable brand colors (≥3:1) stay untouched; too-dark brands are lightened to 3:1. Saturated colors in text slots keep hue strength (s cap 0.55 when s ≥ 0.40). `rgb()/rgba()` parsed; unparseable/unclassified colors are listed in output comments, never silently dropped. **Plugin tokens (1.8.0)** — a second block (`derive_tokens()`) recolors the plugin's own pieces from the site: `find_accent()` = the color `link_color` resolves to, else `form_button_background_color`, else a Global Color whose slug says accent/primary/brand (neutral candidates — s < 0.25, L < 0.12 or > 0.88 — skipped). `:root` gets `--dm-accent` = accent and `--dm-glow` = accent hue, s + 0.35, L ≥ 0.50, lowered until white icons reach 3:1; dark gets `--dm-glow` = accent hue at L 0.235, `--link` = accent hue (s ≤ 0.55) from L 0.72 up to 4.5:1 against DARK_REF, the rank-1 surface and `--bg-2`, and `--bg-2`/`--surface-2` = the rank-2 surface (else an accent tint at L 0.20). A site whose accent IS the framework's built-in `#1d428a` (`BUILTIN_ACCENT`) emits none of these — its companions were hand-tuned for it (the reference site regenerates to an identical look); no accent → toggle/link tokens stay built-in, with a note. |
| 3 | Integration modules | `assets/css/modules/*.css` | `modules[slug]` checkboxes | Registry in `OGM_GP_Dark_Mode::modules()`: gp-chrome (default on), revcontent (manual — script-injected, undetectable), ad-inserter / givewp / forminator (auto-detected ON at fresh activation only). Written against vendors' STABLE generic selectors; per-placement selectors belong in Layer 4b. Plugin-versioned: fixes ship to every site. |
| 4a | AI stylesheet | option `ogm_gpdm_ai_css` (autoloaded) | `enable_ai_css` (default 1) | Machine-owned. Each accepted proposal replaces it wholesale. Backup/swap (`ogm_gpdm_ai_backup`) operates on THIS layer only. |
| 4b | Manual CSS | setting `custom_css` | `enable_custom_css` (default 1) | The owner's layer. Loads LAST, always wins, never touched by the AI. Starts EMPTY — since 1.6.0 the plugin ships no site-specific CSS; the reference site's old residue is a test fixture (`tests/fixtures/theme-manual-css.css`), pasted into that site's own Manual tab at deploy. |

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

## 4b. GeneratePress surfaces + menu (1.11.0)

`OGM_GPDM_Palette::gp_surfaces_css()` is appended to the gp-chrome module's
CSS (`modules_css()`), so it rides Layer 3 and the bundle's module section.
`GP_SURFACES` maps GP color settings → GP's own selectors (inc/css-output.php)
→ token: top bar / header / menu bar / content boxes (inside-article,
comments-area, page-header, one-container .container, paging-navigation,
inside-page-header) / footer widgets / site-info → `var(--surface)`; dropdowns
(`.main-navigation ul ul`) → `var(--surface-2)`; plus the search modal's
`--gp-search-modal-bg/text-color`. A rule is printed only when the setting
(merged over `generate_get_color_defaults()`; `var(--slug)` resolved through
the Global Colors, var() fallbacks allowed) is LIGHT (luminance ≥ 0.5) and,
with the generated palette enabled, not a slug the palette redefines
(`palette_slugs()`). Gradients/unknown slugs/empty → untouched. Each printed
rule also sets `color: var(--text)`. Why: plugin defaults on a vanilla GP site
left header/comments/footer white under light text (found by the contrast
check: 80 failures + 16 panels → 0 + 0). Caveat seen in E2E: once a site saves
`generate_settings` without GP's db-version marker GP switches to its LEGACY
2.x defaults (#222 footer, #3f3f3f dropdowns) — already dark, so left alone.
Menu (gp-chrome.css): no accent fill or borders by default (20 v1.0.3 pairs
waived in coverage.php); `.main-navigation`/`.menu-toggle` text `var(--text)`;
hover/focus/sfHover/current → `var(--text)` on `rgb(255 255 255 / 8%)`;
`html.gpdm-accent-nav` still paints `var(--dm-accent)`. GP link hovers that
outrank the framework's `a { color: var(--link) }` (.main-title, .entry-title,
.entry-meta, .site-info, .footer-widgets `a:hover/:focus`) → `var(--text)`.

## 4c. Export / Import (1.12.0)

`includes/class-ogm-gpdm-transfer.php` (`OGM_GPDM_Transfer`). Export =
admin-post `ogm_gpdm_export` (manage_options + nonce; `&keys=1` honored only
on single-site or for super admins) → JSON {plugin, format 1, version,
exported_at, site, includes_api_keys, settings (merged over defaults, minus
custom_css and — unless asked — the two key fields), manual_css,
generated_css, ai_css, ai_instructions}. NOT exported: model lists, AI
status/proposal/backup, the contrast check, CAPPED_KEY. Import = JS reads the
file → AJAX `ogm_gpdm_import_preview` → `parse()` (≤2 MB, plugin +
format check, known settings keys only, key fields dropped unless
includes_api_keys) → `diff()`/`preview_html()` (settings + modules now →
after, per-layer char counts, instructions, notes: keys kept/replaced, newer
exporting version, this site's Global Colors would generate a different
palette) → stashed in a per-user 15-min transient. `ogm_gpdm_import_apply`
applies the STASH (never a re-posted file): `snapshot()` → BACKUP_KEY
`ogm_gpdm_import_backup` (raw settings incl. keys + 3 layers + instructions),
then `write()`: input = defaults ∪ current ∪ file; modules merged (slugs the
file lacks keep this site's); key fields absent unless the file has a
non-empty key (absent → sanitize_settings keeps the stored key); manual_css →
custom_css (empty allowed); instructions via `ai_instructions`;
`sanitize_settings()` explicitly, then update_option; layers via sanitize_css;
`flush_settings_cache()`. JS reloads right after apply (a stale form + Save
Settings would revert it). `ogm_gpdm_import_undo` restores the snapshot (an
empty stored key → 'clear'; absent custom_css → '') and deletes the backup.

## 5. AI review flow

Run (AJAX `ogm_gpdm_ai_start`: cap + nonce + multisite super-admin gate + key
pre-flight) → stores owner instructions (`ogm_gpdm_ai_instructions`, ≤4000
chars, prefills next run) → status=running → `wp_schedule_single_event` +
`spawn_cron` → cron hook `ogm_gpdm_run_ai_review` executes
`OGM_GPDM_AI::run_review()`:

**Review mode (1.9.0)** — `ai_mode` setting: '' Automatic | direct | background.
`uses_background()`: Automatic = background when `host_caps_requests()` (WP
Engine: `is_wpe()` / `WPE_APIKEY` / `WpeCommon`; filter
`ogm_gpdm_ai_host_caps_requests`) or the learned flag `ogm_gpdm_ai_host_capped`
(set by `polled_status()` when a direct run died in phase `calling`; cleared by
a successful run in explicit Direct mode). Why: WPE ends requests at 60 s and
a High review takes ~4 min — the cron worker started, was killed mid-call, and
the old stale check blamed cron (greport, 2026-10-14).
Status carries `mode`, `phase` (queued → bundle → calling | submitted) +
`phase_at`, provider/model/effort, and for background `job {id, budget}` +
`checked`. `stale_reason()`: direct `calling` dies at phase_at +
effort_timeout + 60 → "host ended the request" (+ learn in Automatic);
queued/bundle at RUN_TIMEOUT; background `submitted` at BACKGROUND_TIMEOUT
(26 h). Background path: `submit_background()` POSTs an Anthropic batch of one
(`custom_id` gpdm-review, same params as direct minus `fallbacks` + beta
header — batches reject them) or an OpenAI Responses `background:true,
store:true` request; `check_job()` (page poll, throttled CHECK_INTERVAL 8 s;
cron `ogm_gpdm_check_ai_job` every 60 s, forced) polls → results JSONL /
response output → `store_proposal()` → `forget_job()` DELETEs the provider
copy. 408/409/429/5xx/network = retry with a note; other 4xx = error.
`cancel_job()` (AJAX `ogm_gpdm_ai_cancel`) cancels background jobs only.
Status/start/cancel AJAX send `X-Accel-Expires: 0` + JS cache-buster `t`
(WordKeeper caches admin-ajax POSTs by URL+body).

**Contrast check (1.10.0)** — `assets/admin/gp-dark-mode-audit.js` (engine,
`window.OGM_GPDM_AUDIT.run(pages)`) + `includes/class-ogm-gpdm-audit.php`
(`OGM_GPDM_Audit`). Pages: `pages()` = home, latest post, a page (not front /
posts page), top category, `?s=the`, a 404 URL; filter `ogm_gpdm_audit_urls`;
own host only. Per page: `fetch(credentials:'omit')` (retry same-origin on
401/403) → DOMParser strips script/noscript/iframe/object/embed/meta
http-equiv/#wpadminbar, converts `media=print onload` and `preload as=style`
to plain stylesheets, adds `<base>` + a no-transitions style → `srcdoc` in an
iframe with `sandbox="allow-same-origin"` (NO allow-scripts: no ads, analytics,
pixels; parent can still read it). Measure: text nodes' parents + form fields;
fg composited over the background walked up the ancestors (stops at a
background-image → `over_image`); canvas white unless color-scheme is dark-only;
WCAG 4.5 / 3 (≥24px or ≥18.66px bold). Light pass first (`also_light` marks
elements failing there too), then dark. Light panels: own bg alpha ≥ .5,
luminance ≥ .45, area ≥ 12,000px², outermost only. Hover/focus: every
same-origin rule using :hover/:focus(-visible|-within) gets a twin with
`.gpdm-force-<state>` inserted right after it (same specificity + source
order); the class goes on the element (+ ancestors for hover). AJAX
`ogm_gpdm_audit_save` → `save_from_json()` rebuilds every field (own-host URLs,
#rrggbb, clamped numbers, ≤60 failures / 20 panels per page) → option
`ogm_gpdm_audit` (no autoload). Bundle section 6 = `to_markdown()`; the user
prompt adds "MEASURED PROBLEMS" when section 6 is present. The Run AI Review
click re-measures unless the stored check is < 5 min old. Live-verified on a
fixture site: e.g. #444 on #0f0f12 = 1.96:1, a hover-only #222 failure, a
both-modes #777 (also_light), async CSS applied, script-injected text and
noscript pixel never loaded.
**Claude in Chrome panel** — `OGM_GPDM_Audit::chrome_prompt()` (site, stack,
Global Colors, layer contract, pages at 1280 + 390px, method incl. live
`<style id="gpdm-trial">` testing, the measured problems, current AI + Manual
CSS, deliverable = one ```css block) + paste box → AJAX `ogm_gpdm_ai_paste` →
`OGM_GPDM_AI::store_pasted_proposal()` (bare CSS gets fenced) → the normal
proposal diff/Apply/backup flow (provider "Pasted", model "Claude in Chrome").

1. Assemble the AI Context Bundle (`OGM_GPDM_Bundle`, section 6).
2. Prompt: system = role + output contract (deliverable replaces Layer 4a;
   Layer 4b is read-only context that always wins); user = task + owner
   instructions (top priority) + bundle.
3. Provider call: Anthropic Messages API (default `claude-opus-5-5`, after a
   refresh the newest cached `claude-opus-*`; effort + fallbacks only for
   `is_effort_claude()` ids — 5 family incl. Haiku 5.5, Opus 4.6–4.8, Sonnet 4.6;
   server-side refusal fallbacks beta, effort from the Effort setting via
   `effort_for()`, `ogm_gpdm_ai_effort` filter) or OpenAI chat completions
   (default `gpt-6.1-sol`, after a refresh the ranked cache's top;
   reasoning_effort likewise). Seed lists (`seed_models()`, pre-refresh) =
   each provider's current lineup, default first; OpenAI's seed must stay in
   `rank_openai_models()` order (unit-tested). **Reply allowance scales with
   effort** (`max_tokens()`, 1.7.1): low/none 16000, medium 32000, high 48000,
   xhigh/max 64000; filter `ogm_gpdm_ai_max_tokens( $tokens, $provider,
   $model, $effort )`, clamped 4000–128000. Why: reasoning models count
   thinking against it and Opus 5.5 always thinks — a fixed 16000 ran out at
   High before any CSS (AFS, 2026-10-14: 16k in 157 s). `effort_timeout()`
   (240/420/660/840 s) keeps every allowance spendable at ~100 tok/s inside
   RUN_TIMEOUT 900. Refusals, truncation (`truncated_error()`: names the
   allowance, suggests a lower Effort) and transport timeouts
   (`transport_error()`: "N-minute limit") are surfaced as errors.
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
test named for it. The optional child-theme reference fixture at
`~/Desktop/dark-mode/` deepens two suites when present.

## 10. File map

```
gp-dark-mode.php                     Main class (settings, layers, tabs UI, handlers)
includes/class-ogm-gpdm-bundle.php   AI Context Bundle assembler
includes/class-ogm-gpdm-palette.php  Layer-2 generated palette (color math)
includes/class-ogm-gpdm-ai.php       BYOK AI review (providers, models, proposal lifecycle)
assets/css/gp-dark-mode.css          Layer 1 framework
assets/css/modules/*.css             Layer 3 integration modules
tests/fixtures/theme-manual-css.css    reference-site residue (fixture, not shipped)
assets/js/dark-mode-toggle.js        Front-end toggle behavior
blocks/toggle/                       Dark Mode Toggle block (block.json, index.js, index.asset.php, editor.css)
assets/admin/gp-dark-mode-admin.*    Settings-page CSS/JS (tabs, models, review polling)
tests/                               run.sh + unit/coverage/smoke + fixtures
uninstall.php                        Full cleanup, multisite-aware
```
