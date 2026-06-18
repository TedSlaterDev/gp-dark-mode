=== GP Dark Mode ===
Contributors: orchardgrovemedia
Tags: dark mode, generatepress, dark theme, toggle, color scheme
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
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
* **Additional CSS editor** built into the settings page (the native WordPress CodeMirror editor with syntax highlighting) for site-specific widget tweaks — no file editing required. Pre-filled from the bundled defaults.

= Settings (Settings → GP Dark Mode) =

* Default theme (Light / Dark)
* Respect the visitor's system preference on first visit
* Show the menu-bar toggle
* Recolor the navigation bar with the accent color in dark mode (off by default)
* Additional CSS editor (enable/disable + edit your styles in the dashboard)

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
Use the **Additional CSS** editor on the settings page (Settings → GP Dark Mode). It's pre-filled from the bundled defaults; a reference copy ships in `assets/css/site-overrides.css` if you want to start over. Toggle it off there to run the clean framework only.

== Changelog ==

= 1.0.2 =
* The Additional CSS editor can now be dragged taller with a vertical resize handle (and opens at a taller default height). Works for both the syntax-highlighted editor and the plain-textarea fallback.

= 1.0.1 =
* Fix: the toggle now binds to every instance of the button. GeneratePress renders the menu-bar toggle in more than one navigation (e.g. a hidden mobile/secondary nav), and the previous code wired only the first by id — so clicks on the visible button did nothing. Multiple toggles (menu bar + `[gp_dark_mode_toggle]` shortcode) now stay in sync.
* Hardening: narrowed the Additional CSS sanitizer so it no longer strips `<…>` text inside CSS comments/strings; it also sanitizes on output now.

= 1.0.0 =
* Initial release. Converted from a theme `functions.php` implementation into a standalone plugin: reusable core CSS-variable framework, an in-dashboard Additional CSS editor (CodeMirror, seeded from the bundled defaults) for site-specific tweaks, behavior settings page (default theme, system-preference matching, menu-bar toggle, opt-in dark-nav accent), `[gp_dark_mode_toggle]` shortcode fallback, and a head-owned `theme-init` lifecycle so transitions are never left disabled.
