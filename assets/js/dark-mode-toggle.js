/**
 * GP Dark Mode — toggle behavior (loaded in the footer).
 *
 * The initial theme is already applied by the inline head script, which also
 * exposes its config on window.ogmGpdm. This script wires up the toggle button
 * and, when configured, keeps unsaved visitors in sync with their OS theme.
 */
(function () {
  var html = document.documentElement;
  // Bind every toggle on the page (menu bar + any [gp_dark_mode_toggle]
  // shortcodes), not just the first one by id.
  var toggles = document.querySelectorAll('.dm-toggle');

  var cfg = window.ogmGpdm || {};
  var respectSystem = !!cfg.respectSystem;
  var defaultTheme = cfg.defaultTheme === 'dark' ? 'dark' : 'light';

  // ---------- Storage helpers (localStorage + cookie fallback) ----------

  function storageAvailable() {
    try {
      var k = '__theme_test__';
      localStorage.setItem(k, '1');
      localStorage.removeItem(k);
      return true;
    } catch (e) {
      return false;
    }
  }

  var hasLocalStorage = storageAvailable();

  function getCookie(name) {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : null;
    } catch (e) {
      return null;
    }
  }

  function setCookie(name, value, days) {
    try {
      var expires = '';
      if (typeof days === 'number') {
        var d = new Date();
        d.setTime(d.getTime() + days * 864e5);
        expires = '; expires=' + d.toUTCString();
      }
      document.cookie =
        name + '=' + encodeURIComponent(value) +
        expires +
        '; path=/' +
        '; samesite=lax';
    } catch (e) {}
  }

  function deleteCookie(name) {
    setCookie(name, '', -1);
  }

  // The visitor's saved override ('dark' | 'light' | null).
  function getPref() {
    try {
      if (hasLocalStorage) return localStorage.getItem('theme');
    } catch (e) {}
    return getCookie('theme');
  }

  function setPref(valueOrNull) {
    try {
      if (hasLocalStorage) {
        if (valueOrNull === null) localStorage.removeItem('theme');
        else localStorage.setItem('theme', valueOrNull);
        return;
      }
    } catch (e) {}

    if (valueOrNull === null) deleteCookie('theme');
    else setCookie('theme', valueOrNull, 365);
  }

  function hasSavedPref() {
    var p = getPref();
    return p === 'dark' || p === 'light';
  }

  // ---------- Theme logic ----------

  function isDarkNow() {
    return html.getAttribute('data-theme') === 'dark';
  }

  function refreshDisqusIfPresent() {
    if (!document.getElementById('disqus_thread')) return;
    if (window.DISQUS && typeof window.DISQUS.reset === 'function') {
      window.DISQUS.reset({ reload: true });
    }
  }

  function syncToggles(isDark) {
    for (var i = 0; i < toggles.length; i++) {
      toggles[i].setAttribute('aria-checked', isDark ? 'true' : 'false');
    }
  }

  function apply(theme, persist) {
    var isDark = theme === 'dark';

    html.setAttribute('data-theme', isDark ? 'dark' : 'light');
    syncToggles(isDark);

    if (persist) setPref(isDark ? 'dark' : 'light');

    refreshDisqusIfPresent();
  }

  function toggleTheme() {
    apply(isDarkNow() ? 'light' : 'dark', true);
  }

  // Wire each toggle once (the data flag guards against double-binding).
  for (var t = 0; t < toggles.length; t++) {
    (function (btn) {
      if (btn.getAttribute('data-gpdm-bound') === '1') {
        return;
      }
      btn.setAttribute('data-gpdm-bound', '1');
      btn.setAttribute('aria-checked', isDarkNow() ? 'true' : 'false');

      btn.addEventListener('click', toggleTheme);

      btn.addEventListener('keydown', function (e) {
        var key = e.key || e.code;
        if (key === ' ' || key === 'Spacebar' || key === 'Enter') {
          e.preventDefault();
          toggleTheme();
        }
      });
    })(toggles[t]);
  }

  // Follow live OS theme changes — but only for visitors who haven't chosen,
  // and only when the site is configured to respect the system preference.
  if (respectSystem && window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (hasSavedPref()) return;
      // Mirror the head script's three-way resolution: a runtime drop to
      // "no OS preference" falls back to the configured default, not light.
      var theme;
      if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        theme = 'dark';
      } else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
        theme = 'light';
      } else {
        theme = defaultTheme;
      }
      apply(theme, false);
    };
    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', onChange);
    } else if (typeof mq.addListener === 'function') {
      mq.addListener(onChange); // Older Safari.
    }
  }
})();
