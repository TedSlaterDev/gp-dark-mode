=== Dark Mode for GeneratePress ===
Contributors: tedslater
Tags: dark mode, generatepress, dark theme, toggle, color scheme
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Robust, no-flash, accessible dark mode for the GeneratePress theme, with a menu-bar toggle and a behavior settings page.

== Description ==

GP Dark Mode adds a polished light/dark experience to GeneratePress:

* **No flash of the wrong theme.** An inline head script sets the theme before first paint.
* **Accessible toggle.** A `role="switch"` button in the GeneratePress menu bar (keyboard + screen-reader friendly), or place it anywhere with the `[gp_dark_mode_toggle]` shortcode.
* **Remembers the visitor's choice** via localStorage, with a cookie fallback.
* **Optional system-preference matching** (`prefers-color-scheme`) for first-time visitors, with live updates when the OS theme changes.
* **Clean CSS-variable framework** (`assets/css/gp-dark-mode.css`) you can recolor in one place.
* **Layered styling**: framework mechanics → an optional **generated dark palette** derived from your GeneratePress Global Colors (usage-aware, WCAG contrast enforced, never alters typography — redefines GP's own CSS variables in dark mode) → **ten integration modules** (checkbox-gated dark CSS for GeneratePress chrome, RevContent/sbn native ads, Rumble ad iframes, Ad Inserter, AdSense, GiveWP, Forminator, Contact Form 7, WordPress Popular Posts, and core content; detectable ones pre-enabled on fresh installs) → the **AI stylesheet** (machine-owned, filled only by proposals you approve) → your **Manual CSS**, which loads last, always wins, and is never touched by anything else. One settings tab per layer.
* **Additional CSS editor** built into the settings page (the native WordPress CodeMirror editor with syntax highlighting) for site-specific widget tweaks — no file editing required. Starts empty: the plugin ships no site-specific CSS, and this field belongs to the site owner alone.
* **AI Context Bundle** — one-click Markdown export of everything an AI assistant needs to review this site's dark mode: GeneratePress Global Colors and color settings, the plugin's framework + current Additional CSS, the child theme's `style.css` and `functions.php` (likely secrets redacted), and a rendered capture of the CSS the front end actually prints, reduced to color-relevant rules. Paste it into a Claude chat and ask for a review.
* **AI Assist (bring your own key)** — the plugin can run that review itself: it sends the bundle to your own Anthropic or OpenAI account and returns a **proposal** that replaces the AI stylesheet layer — shown as a diff with Apply / Discard, plus a one-click swap back to the previous AI stylesheet. Your Manual CSS is never touched, nothing is ever applied automatically, and no keys or content go anywhere except the provider you configure.

= Settings (Settings → GP Dark Mode) =

* Default theme (Light / Dark)
* Respect the visitor's system preference on first visit
* Show the menu-bar toggle
* Recolor the navigation bar with the accent color in dark mode (off by default)
* Generated dark palette (Layer 2): generate from the Global Colors, review, enable — with a Regenerate button
* Integration modules (Layer 3): per-vendor checkboxes with auto-detection badges
* AI stylesheet (Layer 4a): the reviewer's layer — enable/disable, diff, apply, swap back
* Manual CSS editor (Layer 4b: enable/disable + edit your styles in the dashboard — loads last, always wins)
* AI Assist: provider (Anthropic/OpenAI), model, API key — plus the Run AI Review flow with diff, Apply, Discard, Restore
* AI Context Bundle download

== Installation ==

1. Upload the `gp-dark-mode` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Enable the GeneratePress menu bar (Customize → Layout → Primary Navigation) so the toggle has a home, or add `[gp_dark_mode_toggle]` where you like.
4. Adjust behavior under **Settings → GP Dark Mode**.

== Frequently Asked Questions ==

= Does it require GeneratePress? =
The styling and toggle behavior work on any theme, but the automatic menu-bar placement uses a GeneratePress hook. On other themes, use the `[gp_dark_mode_toggle]` shortcode.

= How do I change the colors? =
Edit the CSS variables at the top of `assets/css/gp-dark-mode.css`.

= How do I edit the site-specific tweaks? =
Use the **Additional CSS** editor on the settings page (Settings → GP Dark Mode). It starts empty and only you write to it — the home for per-placement ad selectors and one-off page chrome. Toggle it off there to run without your overrides.

== Screenshots ==

1. The accessible toggle in the GeneratePress menu bar, with dark mode active — the theme is applied before first paint, so there is never a flash.
2. The tabbed settings page: one tab per stylesheet layer (Shipped, Generated, AI, Manual, Settings).
3. The generated dark palette, derived from your GeneratePress Global Colors with WCAG contrast enforced.
4. The AI Review flow: choose a provider and model, add instructions, and review the proposal as a color-marked diff before applying.
5. The Manual CSS tab — your hand-written layer in the native WordPress code editor; it loads last and always wins.

== External services ==

The optional AI Assist feature connects to an AI provider you configure.
It is off by default, requires your own API key, and contacts a provider
only when you click "Refresh models" or start a review yourself.

= Anthropic API (api.anthropic.com) =
Used when Anthropic is your chosen provider. The plugin sends your API
key (request header), your written instructions, and the AI Context
Bundle: GeneratePress color settings, this plugin's stylesheets and your
Additional CSS, your child theme's style.css and functions.php (likely
secrets redacted), and a color-reduced capture of your site's rendered
CSS. No visitor data is ever sent.
Terms: https://www.anthropic.com/legal/commercial-terms
Privacy: https://www.anthropic.com/legal/privacy

= OpenAI API (api.openai.com) =
The same data is sent when OpenAI is your chosen provider.
Terms: https://openai.com/policies/terms-of-use
Privacy: https://openai.com/policies/privacy-policy

Building the AI Context Bundle also fetches pages and stylesheets from
your own site (loopback requests); that step sends nothing to any third
party.

== Changelog ==

= 1.6.5 =
* Changed: display name is now **Dark Mode for GeneratePress** (the folder, slug, and settings all stay `gp-dark-mode`) in line with WordPress.org naming guidelines.
* Added: readme sections for the plugin directory — external-services disclosure for the BYOK AI providers, and screenshot captions. Tested up to WordPress 7.1.

= 1.6.4 =
* Improved: the Rumble iframe backing plate now has softly rounded corners (5px), so the white panel sits more naturally on the dark page.

= 1.6.3 =
* Fixed: the 1.6.2 Rumble backing-plate rule never reached the browser — the module's own header comment contained a literal comment-terminator sequence, ending the comment early; the parser's error recovery then silently swallowed the module's rule AND the next module in the concatenated stylesheet. The comment is rewritten, and a new test guards every shipped CSS file against stray comment delimiters.
* Improved: the backing plate now also targets Rumble's `div[id^="rac-ad-"]` wrappers (thanks to the reference site's owner for spotting the pattern) — sturdier than the iframe src match alone, and covers friendly iframes.

= 1.6.2 =
* New: **Rumble ad iframes** integration module. Rumble's display units are cross-origin iframes (ads.rmbl.ws) that no page CSS can restyle, and many creatives draw dark caption text on a transparent background — dark-on-dark on the dark page. The module gives the iframe element a light backing plate: transparent creative regions render on white, and creatives that paint their own background cover it harmlessly. Default off (script-injected vendors can't be auto-detected) — enable it on the Shipped tab.

= 1.6.1 =
* Fixed: Revcontent/sbn **widget chrome** was unreadable in dark mode — the section heading ("Around the Web") and the per-card attribution/brand lines (`.rc-provider`, `.sbn-item-brand`, `.rc-branding`) stayed vendor-dark on the dark page. Headings are now white and attribution lines muted gray; inside adhesion units (light panel) the same elements go dark/muted-dark instead. Live-verified against units served via Rumble's network, which carry the same dual `sbn-*`/`rc-*` markup — no separate module needed.

= 1.6.0 =
* Changed: **the plugin ships no site-specific CSS.** The reference site's residue file (per-placement `.code-block-NN`/`.rc-uid-*` selectors, GenerateBlocks instance hashes, one-off chrome) is no longer bundled or served as a Manual-CSS default — the Manual tab now starts genuinely empty on every install, and only the site owner writes to it. The residue lives on outside the ZIP as a test fixture (`tests/fixtures/theme-manual-css.css`), where the coverage suite still proves the original decomposition lossless and the reference site can copy it into its own Manual tab at deploy.
* Note: installs that saved their Manual CSS are unaffected. A hypothetical never-saved pre-1.2 install would lose only the residue slice, whose selectors match nothing outside the reference site.

= 1.5.10 =
* Fixed: Revcontent headlines outside the historical scopes — e.g. below-post "Around the Web" units, which sit outside the article content — stayed vendor-gray on the dark page. The module's white-headline rule is now global: every Revcontent headline is white in dark mode, except units carrying their own light panel (the adhesion counter-rules from 1.5.7 deliberately out-specify it). Per-placement `rc-uid-*` rules in Manual CSS are no longer needed for headline color.

= 1.5.9 =
* Fixed: on fresh installs, plugin activation silently retired the bundled Manual-CSS defaults. WordPress re-runs the settings sanitizer on every programmatic option write (activation seeding, migrations), and a write without the Manual field materialized it as saved-empty — killing the bundled-defaults fallback before the site owner ever touched the field. The sanitizer now preserves the stored state unless the field was actually submitted; an explicit empty save still retires the defaults, as intended. Already-affected installs can restore the defaults by pasting `assets/css/site-overrides.css` into the Manual tab.

= 1.5.8 =
* Fixed: dark mode no longer alters ad-headline typography. The Revcontent module's homepage rule carried `font-size: inherit` and `font-weight: 600` over from the original theme CSS, so headlines visibly resized when toggling modes; the rule now changes color only. Sites preferring the normalized sizing can add it mode-independently via the Manual tab: `body.home .rc-headline { font-size: inherit !important; font-weight: 600; }`.

= 1.5.7 =
* Fixed: Revcontent **adhesion** ads (the floating bars Ad Inserter pins with inline `position: fixed`, e.g. bottom-corner units) were getting the module's white-headline treatment — white text on the adhesion template's own white panel. Adhesion units now keep dark headlines and their light panel in dark mode; in-page Revcontent units on the dark page still get white headlines. Detected generically via Ad Inserter's inline fixed positioning and its `ai-close` class — no per-site block numbers.

= 1.5.6 =
* Fixed: clicking the front-end toggle could leave a browser "selected" frame around it (the user-agent focus ring, which adapts to a bright double ring on dark headers). Plain-focus outlines and box-shadow rings are now suppressed on the toggle button; keyboard users still get the plugin's own soft blue focus ring around the track via `:focus-visible`.

= 1.5.5 =
* Improved: the AI Review "running" status is now a little night-sky progress bar — the dark sky sweeps across a daylight track with twinkling stars and a crescent moon riding the leading edge, plus rotating status messages ("Convincing white backgrounds to sleep…"). Honest by design: with no real progress signal, the sweep eases toward full but only lands on 100% when the review actually finishes. Elapsed time stays visible in small print; screen readers are spared the whimsy and only hear the final state; `prefers-reduced-motion` is respected.

= 1.5.4 =
* Improved: when an AI review finishes, the status area now shows a green "Review complete." note with a proper **Load the proposal** button instead of a bare text link.
* Improved: the CSS editors' default height is now 600px (still resizable by dragging the corner).

= 1.5.3 =
* Fixed: the generated palette could LIGHTEN an already-dark design element in dark mode — e.g. a dark site header painted with a "text"-named Global Color (`var(--contrast)`) was inverted to near-white. Two structural fixes: the generator now reads how each color is actually USED on the site (GeneratePress settings, the Customizer's Additional CSS, and the child theme's style.css) and lets real usage override the slug-name guess; and surfaces that are already dark in light mode are left unchanged — the mirror of the existing already-light-text rule. A color used as both background and text is left untouched with an honest comment (the plugin's own framework keeps dark-mode text readable). Regenerate the palette from Settings → GP Dark Mode to pick up the fix.

= 1.5.2 =
* New: **Danger zone** on the Settings tab — a confirmed, nonce-protected factory reset that deletes every plugin option (settings, API keys, modules, generated palette, AI stylesheet, proposals/backups, instructions, cached model lists), clears any pending review, and re-seeds fresh-install defaults with module auto-detection. The Manual CSS returns to the bundled defaults.
* Improved: proper breathing room and a larger title at the top of each tab panel (the spacing is applied to the panel itself — admin-wide heading rules were swallowing the tab strip's margin).

= 1.5.1 =
* New: **four more integration modules** — WordPress core content (tables, code blocks, quotes, separators), Google AdSense wrappers (the iframes themselves can't be styled by anyone's CSS; this keeps the containers from leaving light islands), WordPress Popular Posts widgets, and Contact Form 7. All default OFF on existing installs (enabling one is a deliberate choice); fresh installs pre-enable the detectable ones. The pre-1.2 migration stays pixel-identical: it enables exactly the five modules the old seed decomposed into, never the newer ones.
* Improved: the settings tabs now melt into the card — the active tab shares the panel's background and connects seamlessly instead of WordPress's boxed "selected" look.
* Improved: every CSS textarea in the tabs is now 700px tall and drag-resizable (including the read-only ones), matching the Manual editor.
* Clarified: the per-layer checkbox now reads "Enable this stylesheet — include it in the CSS the plugin outputs. Unchecked, it stays saved here but visitors never receive it." — it is purely an on/off switch, not a delivery mode. The Manual tab also states explicitly that nothing is ever added to it automatically.

= 1.5.0 =
* Changed: **the settings page is now tabbed — one tab per stylesheet layer, in cascade order, plus Settings.** Shipped (read-only framework + enabled modules) → Generated (the Global Colors palette) → AI (the review loop and the AI stylesheet) → Manual (your CodeMirror editor) → Settings (behavior, modules, provider/API keys, the Context Bundle export). Everything still lives in ONE form: a single Save button covers every tab, and tab state rides in the URL hash so links land on the right tab.
* Changed: **the AI output is now its own stylesheet layer (4a), separate from your Manual CSS (4b).** Each accepted proposal replaces the machine-owned AI stylesheet wholesale; your Manual CSS loads after it and always wins — a review can never clobber a hand edit, and the diff/backup/swap now operate on the AI layer only. The reviewer's prompt knows the split: the Manual layer is read-only context it must work around, never duplicate. New `enable_ai_css` toggle on the AI tab; the AI stylesheet is stored in `ogm_gpdm_ai_css` (cleaned up on uninstall).
* Upgrade note: your existing Additional CSS becomes the Manual tab's content, untouched (it may include previously applied AI output — after your next accepted proposal you can prune it). The AI stylesheet starts empty, and stale pre-1.5 proposals/backups are cleared because they carried the old single-layer semantics.

= 1.4.2 =
* New: **review instructions.** A textarea above the Run button lets you tell the reviewer exactly what to fix ("the footer menu links should be white; the background behind the featured posts should be dark; …"). The instructions ride into the prompt with top priority, and the field prefills with the last run's text so reviews iterate naturally.
* Security fix: **API keys are now stored per provider** (`ai_api_key_anthropic` / `ai_api_key_openai`). Previously one shared key field meant a saved Anthropic key could be transmitted to OpenAI (and vice versa) just by flipping the provider dropdown and clicking Refresh models or Run — a cross-provider secret disclosure. A one-time migration assigns your existing key to the provider it was saved for; the settings page shows the selected provider's key field only.
* Fix: the review completion no longer force-reloads the page (which silently destroyed unsaved form edits — a pasted key, CodeMirror CSS changes); it now shows a "Load the proposal" link.
* Fix: deactivating the plugin clears any pending review event and its "running" status, so reactivation can't show a phantom 15-minute "review is running" lockout.
* Improved: a missing API key now fails the Run button instantly instead of taking a cron round-trip to report.

= 1.4.1 =
* Fix: the toggle's sun and moon are now **inline SVG icons** instead of Unicode glyphs. The text codepoints (`☀`/`☾`) render with whatever font each site's stack resolves them to — a solid white crescent on one site, a hollow outline on another. The SVGs (filled with `currentColor`, forced white on the track) look identical everywhere. The `ogm_gpdm_toggle_markup` filter still lets a site swap in its own markup.

= 1.4.0 =
* Changed: **the AI review now runs in the background.** The first real deployment showed the synchronous run dying two ways: proxies 502 the 1–3 minute request, and mod_security-style WAFs 403 the redirect when a provider error message rides in the query string (the admin saw "Forbidden" instead of the real error). "Run AI Review" now starts the run via AJAX and executes it on a WP-Cron event; the page polls every few seconds, shows progress, surfaces the real provider error inline when a run fails (stored server-side in `ogm_gpdm_ai_status`, never in a URL), and loads the proposal automatically when it's ready. A stale run is treated as failed after 15 minutes with a hint to check WP-Cron. Apply/Discard/Swap redirects now carry fixed status codes only.
* Changed: the **AI Review section moved into the settings form, directly under the AI Assist configuration** — provider, key, model, and the run button now live together.
* Changed: when an API key is saved, the field shows **asterisks** instead of an empty box with placeholder text; submit it unchanged (or blank) to keep the key, type a new one to replace it, or type "clear" to remove it.

= 1.3.2 =
* Fix: **the framework is now inert in light mode.** Its "global application" rules (`body`/`blockquote`/`p` colors with `!important`, link colors, content surfaces, form fields) were carried over un-scoped from the reference child theme and applied in BOTH modes — in light mode the `p { color: var(--text) !important }` rule overrode other sites' own styling (live-verified: GenerateBlocks byline colors on a homepage featured-post panel forced to #222). All of these rules are now scoped to `html[data-theme="dark"]`; dark-mode output is unchanged.
* Note for the reference-site deployment: its light mode previously relied on these un-scoped rules being active (they matched its theme colors, so no visible change is expected — the GP customizer sets the same values). Verify light mode on deploy; if anything shifts, re-add the needed un-scoped rules to that site's Additional CSS, where site opinions belong.

= 1.3.1 =
* Improved: the generated palette keeps the hue strength of DELIBERATELY colored text slots — a brand red living in `contrast-2` stays red in dark mode instead of being desaturated to muddy pink (neutral gray tiers stay muted as before). Found by running the generator against a real site's Global Colors.
* New: **live model dropdowns** for AI Assist (the Editorial QA pattern). The free-text model field is now a dropdown seeded with a sensible default list; "Refresh models" pulls the provider's current lineup from its `/v1/models` endpoint (filtered to chat-capable families, ranked most-capable first, dated/preview snapshots collapsed, capped at 12), cached per provider in the non-autoloaded `ogm_gpdm_ai_models` option with a "refreshed X ago" note. Switching providers swaps to that provider's list instantly; the saved model always stays selectable even if a refresh no longer returns it; the first "Provider default" entry keeps the empty-means-default semantics.

= 1.3.0 =
* New: **AI Assist (BYOK)** — the plugin can now run the dark-mode review itself. Configure a provider (Anthropic or OpenAI), model, and your own API key under AI Assist; "Run AI Review" sends the AI Context Bundle to your account and stores the reply as a **proposal**: prose notes plus a complete replacement for the Additional CSS layer, rendered as a color-marked diff against the current copy. Apply (with an automatic backup of what was effective before, including the bundled seed on never-saved installs) or Discard — nothing is ever applied without a click. **Restore is a swap**: the current Additional CSS trades places with the backup, so restoring is itself reversible and a stale backup can never destroy later hand edits.
* Defaults: Anthropic `claude-opus-5` (with server-side refusal fallbacks enabled and reasoning effort `medium`, filterable via `ogm_gpdm_ai_effort` — raise it if your host allows long requests) or OpenAI `gpt-5` (reasoning effort low, matching Editorial QA's fast-scan choice). A truncated reply (token-limit hit) is rejected rather than stored — raise the budget via `ogm_gpdm_ai_max_tokens` if that happens. A run — bundle assembly plus the model call, in one request — typically takes 1–3 minutes and costs a few tens of cents; if your host cuts long requests, reload the settings page after a couple of minutes, the proposal may still have been stored.
* Privacy: the review sends the bundle — including the child theme's `functions.php` with best-effort secret redaction — to the provider you configured, and nowhere else; the API key itself is redacted out of the bundle. On multisite, running a review is network-admin-only, like the bundle download. The key is stored in the plugin's settings option and removed on uninstall (on multisite, uninstall cleans every site's rows); type "clear" in the key field to remove it sooner.
* Proposal and backup live in their own non-autoloaded options (`ogm_gpdm_ai_proposal`, `ogm_gpdm_ai_backup`), cleaned up on uninstall.

= 1.2.0 =
* New: **layered dark-mode styling.** Output order is now framework (Layer 1) → generated palette (Layer 2, opt-in) → integration modules (Layer 3) → Additional CSS (Layer 4, always last and always wins), all in the same cascade slot as before.
* New: **generated dark palette (Layer 2).** One click derives dark values from the site's GeneratePress Global Colors (lightness inverted, hue preserved, WCAG contrast floor: 4.5:1 for text, 3:1 for brand colors) and redefines GP's own CSS variables under `html[data-theme="dark"]` — everything GP and GenerateBlocks paint with those variables flips wholesale. Opt-in by design: generate, review the CSS on the settings page, then enable. Brand colors already readable on dark are left untouched and listed as such.
* New: **integration modules (Layer 3).** The old monolithic Additional CSS seed is decomposed into checkbox-gated modules written against each vendor's stable generic selectors: GeneratePress chrome (nav/sidebar/embeds — on by default), RevContent/sbn (manual — script-injected vendors can't be detected), Ad Inserter wrappers, GiveWP, Forminator (auto-detected and pre-enabled on fresh installs). Modules are plugin-versioned files, so fixes reach every site on update — unlike the frozen editable field.
* Changed: the bundled Additional CSS default is now the true site residue only (per-placement `.code-block-NN`/`.rc-uid-*` selectors, GenerateBlocks instance classes, one-off chrome). The union of framework + modules + new seed equals the old framework + seed rule-for-rule (verified by `tests/coverage.php`).
* Upgrade note: a one-time migration keeps upgraded sites rendering **identically**. Installs that had saved their Additional CSS get ALL modules switched off (their saved copy already contains the complete old rules, including any edits or deletions — no module may fight or resurrect them); installs still running the bundled defaults get ALL modules switched on (all modules + the new slim seed equal the old seed rule-for-rule). Enabling or disabling modules afterwards is a deliberate opt-in change. Fresh installs get the slim seed + detected modules.
* The AI Context Bundle now reports the generated palette and enabled modules, and tells the reviewer not to duplicate module-covered selectors.

= 1.1.0 =
* New: **AI Context Bundle** (settings page, below the form) — downloads one Markdown file assembling the full dark-mode picture for AI (or human) review: GeneratePress Global Colors + color settings read from the database, the plugin's settings/framework/Additional CSS, the child theme's `style.css` (reduced to color-relevant rules) and `functions.php` (verbatim, best-effort secret redaction), the Customizer's Additional CSS, and a rendered capture of the homepage + latest post (inline `<style>` blocks and same-origin `wp-content` stylesheets, deduplicated and reduced to color-relevant rules; cross-origin and core stylesheets listed by URL). Includes a "how to use" contract so an assistant knows the plugin's conventions and that the deliverable targets the Additional CSS layer only. Deterministic PHP — no API calls, nothing leaves the site until you share the file. The capture URLs are filterable via `ogm_gpdm_bundle_urls`.

= 1.0.3 =
* Parity fix (dark nav background): restored the original's verbatim `background-color: var(--accent)` on the dark-mode navigation (the 1.0.0 conversion dropped it as a supposed no-op). Combined with the cascade-slot fix below it now resolves exactly as on the reference site: GeneratePress's customizer dynamic CSS defines `--accent` after the framework's self-referential declaration, so the nav is painted the theme's accent color (live-verified `#1d428a` on the reference site); on a theme that never defines `--accent` it computes to transparent and the nav blends into the page. The "Dark nav accent" setting still recolors it with the plugin's own `--dm-accent` when enabled.
* Parity fix (cascade slot): on GeneratePress the framework + Additional CSS now print inline on the `generate-style` handle — the same position as the original child-theme code — so they land BEFORE GP's customizer dynamic CSS and the child theme's `style.css`, and the site's own styles keep winning specificity ties (plain link colors, `.inside-article` backgrounds, form fields all rendered differently in the old late-stylesheet slot). Non-GeneratePress themes keep the standalone-stylesheet fallback.
* Parity fix (toggle animation): restored `transition: box-shadow 0.3s ease-in-out` on the toggle track's hover and dark-mode states. The reduced-motion media query overrides the base rule's transition, so without the per-state declarations the track's fill/glow snapped instead of easing.
* Fix (dark-mode pagination): the broad `body.home .entry-content a` dark rule was turning the homepage's unselected page-number chips white while their `--base-2` background stayed light — white on white. Unselected chips now keep their intended dark-on-light colors at rest; hover keeps the existing accent-blue + white styling. (Added to the Additional CSS defaults.)
* Note: the dark-nav and pagination rules live in the Additional CSS defaults, so they apply automatically only to sites that haven't saved their own Additional CSS yet. Sites with saved styles should copy the updated `Navigation (dark)` and `Pagination (dark)` blocks from `assets/css/site-overrides.css`.

= 1.0.2 =
* The Additional CSS editor can now be dragged taller with a vertical resize handle (and opens at a taller default height). Works for both the syntax-highlighted editor and the plain-textarea fallback.

= 1.0.1 =
* Fix: the toggle now binds to every instance of the button. GeneratePress renders the menu-bar toggle in more than one navigation (e.g. a hidden mobile/secondary nav), and the previous code wired only the first by id — so clicks on the visible button did nothing. Multiple toggles (menu bar + `[gp_dark_mode_toggle]` shortcode) now stay in sync.
* Hardening: narrowed the Additional CSS sanitizer so it no longer strips `<…>` text inside CSS comments/strings; it also sanitizes on output now.

= 1.0.0 =
* Initial release. Converted from a theme `functions.php` implementation into a standalone plugin: reusable core CSS-variable framework, an in-dashboard Additional CSS editor (CodeMirror, seeded from the bundled defaults) for site-specific tweaks, behavior settings page (default theme, system-preference matching, menu-bar toggle, opt-in dark-nav accent), `[gp_dark_mode_toggle]` shortcode fallback, and a head-owned `theme-init` lifecycle so transitions are never left disabled.
