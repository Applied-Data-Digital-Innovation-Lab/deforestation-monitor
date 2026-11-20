/* theme.js — light or dark, decided before the first paint.
 *
 * Loaded in <head>, ahead of the stylesheet and without `defer`, because it
 * has to set data-theme on <html> before anything is drawn: run any later and
 * a reader who chose dark sees the light page flash first.
 *
 * The operating system decides by default. With no data-theme attribute the
 * stylesheet follows prefers-color-scheme on its own, and this file is not
 * needed for that at all — without JavaScript the site still matches the
 * system. What this adds is the button in the header, for a reader who wants
 * this site the other way from everything else on their screen.
 *
 * The choice is kept only while it differs from the system. Picking the theme
 * the system already gives clears it, and so does the system preference
 * changing: the most recent thing the reader asked for wins, and switching
 * the whole machine to dark is a newer request than a click here last week.
 *
 * Pages that draw their own colours — the charts and the maps, which paint to
 * canvas and tiles the stylesheet cannot reach — subscribe with
 * DMTheme.onChange and repaint from the stylesheet's tokens. */

(function () {
  'use strict';

  var KEY = 'dm-theme';
  var root = document.documentElement;
  var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var listeners = [];
  var button = null;

  /* Storage can throw outright (Safari private windows, blocked site data),
     so every access is guarded and a failure means "no choice stored". */
  function stored() {
    try {
      var value = localStorage.getItem(KEY);
      return value === 'dark' || value === 'light' ? value : null;
    } catch (e) {
      return null;
    }
  }

  function store(value) {
    try {
      if (value === null) localStorage.removeItem(KEY);
      else localStorage.setItem(KEY, value);
    } catch (e) { /* the choice lasts for this page only */ }
  }

  function system() {
    return query && query.matches ? 'dark' : 'light';
  }

  function current() {
    var value = root.getAttribute('data-theme');
    return value === 'dark' || value === 'light' ? value : system();
  }

  function syncButton() {
    if (!button) return;
    var dark = current() === 'dark';
    button.setAttribute('aria-pressed', dark ? 'true' : 'false');
    button.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
  }

  function notify() {
    var theme = current();
    syncButton();
    for (var i = 0; i < listeners.length; i++) listeners[i](theme);
  }

  function set(theme) {
    if (theme === system()) {
      root.removeAttribute('data-theme');
      store(null);
    } else {
      root.setAttribute('data-theme', theme);
      store(theme);
    }
    notify();
  }

  var choice = stored();
  if (choice !== null && choice !== system()) {
    root.setAttribute('data-theme', choice);
  } else if (choice !== null) {
    store(null);   // matches the system now, so there is nothing to override
  }

  if (query) {
    var onSystemChange = function () {
      root.removeAttribute('data-theme');
      store(null);
      notify();
    };
    if (query.addEventListener) query.addEventListener('change', onSystemChange);
    else if (query.addListener) query.addListener(onSystemChange);
  }

  /* The button is in the header, which does not exist yet when this runs. It
     ships hidden, so a reader without JavaScript is not offered a control
     that does nothing. */
  document.addEventListener('DOMContentLoaded', function () {
    button = document.getElementById('theme-toggle');
    if (!button) return;
    button.addEventListener('click', function () {
      set(current() === 'dark' ? 'light' : 'dark');
    });
    syncButton();
    button.hidden = false;
  });

  window.DMTheme = {
    current: current,
    set: set,
    onChange: function (fn) { listeners.push(fn); }
  };
})();
