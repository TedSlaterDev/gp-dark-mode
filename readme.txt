=== GP Dark Mode ===
Contributors: orchardgrovemedia
Tags: dark mode, generatepress, dark theme, toggle, color scheme
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Robust, no-flash, accessible dark mode for the GeneratePress theme, with a menu-bar toggle and a behavior settings page.

== Description ==

GP Dark Mode adds a polished light/dark experience to GeneratePress:

* **No flash of the wrong theme.** An inline head script sets the theme before first paint.
* **Accessible toggle.** A `role="switch"` button in the GeneratePress menu bar (keyboard + screen-reader friendly), or place it anywhere with the `[gp_dark_mode_toggle]` shortcode.
* **Remembers the visitor's choice** via localStorage, with a cookie fallback.
* **Optional system-preference matching** (`prefers-color-scheme`) for first-time visitors, with live updates when the OS theme changes.
* **Clean CSS-variable framework** (`assets/css/gp-dark-mode.css`) you can recolor in one place, kept separate from site-specific widget tweaks (`assets/css/site-overrides.css`).

= Settings (Settings → GP Dark Mode) =

* Default theme (Light / Dark)
* Respect the visitor's system preference on first visit
* Show the menu-bar toggle
* Recolor the navigation bar with the accent color in dark mode (off by default)
* Load the site-specific overrides stylesheet

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

= How do I disable the site-specific tweaks? =
Turn off "Site overrides" on the settings page, or edit `assets/css/site-overrides.css`.

== Changelog ==

= 1.0.0 =
* Initial release. Converted from a theme `functions.php` implementation into a standalone plugin: split core framework / site-overrides stylesheets, behavior settings page, shortcode fallback, and a head-owned `theme-init` lifecycle so transitions are never left disabled.
