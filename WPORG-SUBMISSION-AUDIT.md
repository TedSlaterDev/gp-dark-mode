# WordPress.org pre-submission audit — GP Dark Mode v1.6.4

Audited 2026-08-29 against the wordpress.org plugin guidelines ahead of a
directory submission. **Verdict: strong candidate — code passes everything a
reviewer greps for; the blockers are readme/asset items, about a half-day of
work.** Slug `gp-dark-mode` is AVAILABLE (wordpress.org/plugins/gp-dark-mode/
redirects to search — no existing plugin owns it).

## Already compliant (verified, not assumed)

- **License** GPL-2.0-or-later in the header AND readme; License URI present.
- **Auth on every endpoint**: all 6 `admin_post_*` + 3 `wp_ajax_*` handlers
  check `current_user_can('manage_options')` FIRST, then
  `check_admin_referer`/`check_ajax_referer` (verified each one individually;
  multisite additionally restricts bundle/AI to network admins).
- **Input sanitization**: every `$_POST`/`$_GET` read goes through
  `wp_unslash` + `sanitize_key`/`sanitize_textarea_field`; the two
  nonce-exempt `$_GET` reads are display-only and carry phpcs annotations.
- **Output escaping**: only two unescaped `echo`s, both justified with phpcs
  ignores (toggle markup built from escaped parts; plain-text file download).
  CSS layers are printed through `sanitize_css()` which strips
  `</style`/`<script` breakouts.
- **Settings API**: `register_setting` with a `sanitize_settings` callback;
  the generated palette deliberately lives in a separate unregistered option
  (the WP sanitize-re-entry gotcha, already handled).
- **API keys**: stored per-provider (never cross-sent), rendered as
  `type="password"` with a MASK value — the real key is never echoed back;
  "type 'clear' to remove" pattern. Legacy single-key migration in place.
- **Prefixes**: constants `OGM_GPDM_`, class `OGM_GP_Dark_Mode`, options/
  transients/hooks all `ogm_gpdm_*` (the only unprefixed option reads are
  GeneratePress's own `generate_settings`, which is correct). Text domain
  `gp-dark-mode` — 158 i18n calls, 100% consistent.
- **No phoning home / no self-update / no CDN assets**: the ONLY external
  hosts are `api.anthropic.com` and `api.openai.com`, both BYOK, off by
  default, user-initiated; no `pre_set_site_transient_update_plugins`
  filter; all enqueues are local files or core's `wp_enqueue_code_editor`.
  Bundle page/stylesheet fetches are same-origin loopbacks with time budgets
  and `limit_response_size`.
- **ABSPATH guards** in the main file and all three includes;
  **uninstall.php** removes every option, multisite-aware.
- **No bundled third-party libraries** (nothing to license-check).

## Required before submitting

1. **`Tested up to: 6.8` → `7.1`** (current core as of 2026-08-29 per
   api.wordpress.org). Smoke-test on a 7.1 site first (any current Local
   site will do), then bump.
2. **Add an `== External services ==` section to readme.txt** — reviewers
   now require this for any remote call. Draft to paste (adjust if wording
   feels off):

   ```
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
   ```
3. **Directory assets** (these live in the SVN `assets/` dir, NOT the plugin
   ZIP): `icon-128x128.png` + `icon-256x256.png` (derive from the
   gpdarkmode-site crescent mark), `banner-772x250.png` +
   `banner-1544x500.png` (the site hero translates well), and
   `screenshot-1.png` … `screenshot-N.png` with matching `== Screenshots ==`
   captions in readme.txt (suggest: toggle in the GP menu bar; light vs dark;
   the tabbed settings; the AI diff/Apply screen).
4. **Run the official Plugin Check (PCP)** before submitting — reviewers
   effectively expect a clean pass. In any Local WP site:
   install/activate "Plugin Check", copy this plugin in, then
   `wp plugin check gp-dark-mode` (or use its admin screen). Expect near-zero
   findings given the above; fix or phpcs-annotate anything it flags.
5. **Decide the display name.** "GP Dark Mode" will *probably* pass, but
   naming guidelines prefer "for X" forms and a reviewer may ask; "Dark Mode
   for GeneratePress" as Plugin Name (keeping the `gp-dark-mode` slug and
   folder) is the friction-free form. GeneratePress itself is GPL and its
   name appears throughout legitimately ("for GeneratePress" phrasing is
   fine); just don't imply endorsement.
6. **What NOT to upload to SVN trunk**: SPEC.md, tests/, .git, .gitignore,
   .DS_Store, WPORG-SUBMISSION-AUDIT.md (this file). Ship: gp-dark-mode.php,
   readme.txt, uninstall.php, includes/, assets/ (the plugin's own css/js).

## Worth knowing (not blockers)

- **One pending submission per author** — GP Dark Mode first, then queue the
  next plugin only after this one is approved.
- Queue reality (Aug 2026): first human response typically days-to-two-weeks;
  the giant "backlog" is mostly authors sitting on feedback — answer their
  email same-day and the whole thing lands in 1–3 weeks.
- After approval you get SVN, not git: `svn co https://plugins.svn.wordpress.org/gp-dark-mode`
  → commit to `trunk/`, tag releases as `tags/1.6.4/`, directory art in
  `assets/`. Releases = commit + `Stable tag` bump (readme.txt is already
  the source of truth, so the release habit is unchanged).
- New June-2026 policy: wp.org delays auto-update rollout up to 24h after a
  release (supply-chain protection). Doesn't affect submission.
- The gpdarkmode-site ZIP + build.php flow can continue unchanged during
  review; once approved, consider pointing the site's Download button at the
  wp.org page (or keep both — folder name matches the slug, so installs
  migrate cleanly, unlike OpenPoll's `post-poll` folder).
- The reviewer may ask about `functions.php` contents being sent to an AI
  provider — the readme's "likely secrets redacted" line plus the External
  services section covers it; be ready to describe the redaction briefly.
