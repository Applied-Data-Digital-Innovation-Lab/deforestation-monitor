/* format.js — helpers shared by the three pages.
 *
 * Two jobs:
 *   1. Never print a bare number. Hectares and "per 1,000 ha" mean nothing
 *      without their unit, and a ratio has to read as "31.8x its usual level".
 *   2. Handle the fetch lifecycle, including the several seconds Neon can take
 *      to wake from idle. Every panel shows a spinner, then says the database
 *      is waking if it is slow, then shows the data or an error with a retry.
 */

window.DM = (function () {
  'use strict';

  var NO_DATA = 'no data';

  /* ---------- numbers ---------- */

  function group(value, digits) {
    return value.toLocaleString('en-US', {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits
    });
  }

  /** Hectares, with the precision the magnitude deserves. */
  function ha(value, withUnit) {
    if (value === null || value === undefined) return NO_DATA;
    var digits = value >= 100 ? 0 : value >= 10 ? 1 : 2;
    if (value === 0) digits = 0;
    return group(value, digits) + (withUnit === false ? '' : ' ha');
  }

  /** Loss normalised by territory size. Values run from about 0.02 to 12. */
  function per1000(value, withUnit) {
    if (value === null || value === undefined) return NO_DATA;
    var digits = value >= 1 ? 2 : value >= 0.1 ? 3 : 4;
    return group(value, digits) + (withUnit === false ? '' : ' ha / 1,000 ha');
  }

  /** The seasonal baseline, in hectares a week.
   *
   *  It is stored in "per 1,000 ha of territory" units, which cannot be set
   *  beside a hectares figure without converting it first — the method section
   *  puts the two side by side, so the division belongs in one place rather
   *  than written out wherever it is needed.
   *
   *  A baseline of 0 or null means none was ever established, which is not the
   *  same as a baseline of zero hectares; both come back null. */
  function baselineHa(perThousand, territoryHa) {
    if (!perThousand || !territoryHa) return null;
    return perThousand * territoryHa / 1000;
  }

  /** "31.8x" — how many times its own usual level.
   *
   *  Anything that is not a finite number comes back null, and every caller
   *  turns null into the words "no baseline". A string, a NaN or a missing
   *  field can therefore never reach the page as a stray character. */
  function ratio(value) {
    if (typeof value !== 'number' || !isFinite(value)) return null;
    return group(value, value >= 100 ? 0 : 1) + '×';
  }

  function count(value) {
    if (value === null || value === undefined) return NO_DATA;
    return group(value, 0);
  }

  /* ---------- dates ---------- */

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /* Timestamps arrive as "2026-08-24T00:00:00.000" with no zone. Parsed by
     hand so the browser cannot shift them a day either way. */
  function parseTs(text) {
    if (!text) return null;
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(text));
    if (!m) return null;
    return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  }

  function date(text) {
    var d = parseTs(text);
    if (!d) return NO_DATA;
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
  }

  function shortDate(text) {
    var d = parseTs(text);
    if (!d) return '';
    return d.getDate() + ' ' + MONTHS[d.getMonth()];
  }

  /** "24-30 Aug 2026", collapsing whatever the two ends share. */
  function window_(startText, endText) {
    var a = parseTs(startText), b = parseTs(endText);
    if (!a || !b) return NO_DATA;
    if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth()) {
      return a.getDate() + '–' + b.getDate() + ' ' +
             MONTHS[b.getMonth()] + ' ' + b.getFullYear();
    }
    if (a.getFullYear() === b.getFullYear()) {
      return shortDate(startText) + ' – ' + shortDate(endText) + ' ' + b.getFullYear();
    }
    return date(startText) + ' – ' + date(endText);
  }

  /* ---------- names ---------- */

  /* 23 territories, all in Guyane Francaise, have no name in the source data.
     Names are never translated; a missing one is labelled, not invented. */
  function territoryName(name, id) {
    return name ? name : 'Unnamed territory (#' + id + ')';
  }

  function isUnnamed(name) {
    return !name;
  }

  /* ---------- DOM ---------- */

  /* Built as nodes rather than HTML strings: territory names come from the
     database and are never interpolated into markup. */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        var value = attrs[key];
        if (value === null || value === undefined || value === false) return;
        if (key === 'text') node.textContent = value;
        else if (key === 'class') node.className = value;
        else if (key === 'style') node.setAttribute('style', value);
        else node.setAttribute(key, value === true ? '' : value);
      });
    }
    append(node, children);
    return node;
  }

  /* Children may be nodes or plain strings. Strings become text nodes, which
     is also what keeps database values out of markup. el() and replace() share
     this so a list of children behaves the same wherever it is passed. */
  function append(node, children) {
    (children || []).forEach(function (child) {
      if (child === null || child === undefined || child === false) return;
      node.appendChild(
        typeof child === 'string' || typeof child === 'number'
          ? document.createTextNode(String(child))
          : child
      );
    });
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function replace(node, children) {
    clear(node);
    append(node, children);
  }

  /* ---------- fetching ---------- */

  /* `init` is passed through to fetch() — the field report is the one caller
     that sends a POST; everything else reads. */
  function fetchJSON(url, init) {
    var options = init || {};
    options.headers = options.headers || { Accept: 'application/json' };
    return fetch(url, options).then(function (res) {
      return res.text().then(function (raw) {
        var body = null;
        try { body = JSON.parse(raw); } catch (e) { /* not JSON */ }
        if (!res.ok) {
          throw new Error(
            body && body.message ? body.message : 'The request failed (' + res.status + ').'
          );
        }
        if (body === null) throw new Error('The server sent a response the page could not read.');
        return body;
      });
    });
  }

  /**
   * Run a load into a container, showing every state along the way.
   *
   * @param {Element}  target   Container, emptied before each state.
   * @param {Function} load     Returns a promise of the data.
   * @param {Function} render   Receives the data; paints into target.
   * @param {string}   label    What is loading, for the spinner text.
   */
  function panel(target, load, render, label) {
    function attempt() {
      var spinner = el('div', { class: 'state' }, [
        el('span', { class: 'spinner' }),
        el('span', { text: 'Loading ' + label + '…' })
      ]);
      replace(target, [spinner]);

      /* Neon suspends idle compute, so the first request after a quiet spell
         can take several seconds. Say so instead of spinning silently. */
      var wakingTimer = setTimeout(function () {
        var note = spinner.lastChild;
        if (note) note.textContent = 'Waking the database… this can take a few seconds.';
      }, 2500);

      load().then(function (data) {
        clearTimeout(wakingTimer);
        clear(target);
        render(data);
      }).catch(function (err) {
        clearTimeout(wakingTimer);
        var retry = el('button', { type: 'button', text: 'Try again' });
        retry.addEventListener('click', attempt);
        replace(target, [
          el('div', { class: 'state state-error' }, [
            el('div', { text: 'Could not load ' + label + '. ' + err.message }),
            retry
          ])
        ]);
      });
    }

    attempt();
  }

  /* ---------- theme ---------- */

  /* A colour token from the stylesheet, as the theme currently resolves it.
     Charts and maps paint to canvas and tiles, which CSS cannot reach, so
     they read their colours through this instead of keeping their own. */
  function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  function theme() {
    return window.DMTheme ? window.DMTheme.current() : 'light';
  }

  /* Called with the new theme whenever it changes — by the header button or
     by the operating system. */
  function onTheme(fn) {
    if (window.DMTheme) window.DMTheme.onChange(fn);
  }

  /* ---------- basemap ----------
   *
   * LIGHT: Esri's topographic map — relief, rivers, roads and place names, in
   * muted beige and green. It replaced the physical map, which stopped at
   * zoom 8 and served a grey "no data" tile past it; probed tile by tile,
   * this one still draws contours, rivers and names at z16, and only goes
   * pale at z18 where the terrain itself is empty. One layer, carrying its
   * own labels and boundaries. CartoDB Voyager was tried and stamps "API KEY
   * REQUIRED" across every tile, on all four subdomains and at @2x.
   *
   * DARK: Esri's Dark Gray Canvas, which is two layers — the ground, and the
   * names and boundaries in a reference layer drawn over it. Not the topo map
   * with a CSS filter: darkening those tiles makes the place names
   * unreadable, and the names are how you tell where in the basin you are.
   * The canvas has no relief or contours, which is the price of a dark map
   * that is drawn dark rather than filtered.
   *
   * Its ground is about #333. The light theme's bordeaux point is 1.55:1 on
   * it — effectively invisible — which is why the stylesheet lightens the
   * alert in dark: the lighter disc holds 4.72:1 and its light ring 11.4:1.
   *
   * The canvas is only drawn to z16; from z17 Esri serves a grey "Map data
   * not yet available" tile. maxNativeZoom has Leaflet stretch the z16 tiles
   * instead, so zooming in past it goes soft rather than blank. */
  var ESRI = 'https://server.arcgisonline.com/ArcGIS/rest/services/';

  function basemapLayers(which) {
    if (which === 'dark') {
      return [
        L.tileLayer(ESRI + 'Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
          maxZoom: 19,
          maxNativeZoom: 16,
          attribution:
            '&copy; <a href="https://www.esri.com">Esri</a> — Esri, HERE, Garmin, ' +
            '&copy; OpenStreetMap contributors, and the GIS user community'
        }),
        L.tileLayer(ESRI + 'Canvas/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}', {
          maxZoom: 19,
          maxNativeZoom: 16
        })
      ];
    }
    return [
      L.tileLayer(ESRI + 'World_Topo_Map/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution:
          '&copy; <a href="https://www.esri.com">Esri</a> — Esri, DeLorme, NAVTEQ, ' +
          'and the GIS user community'
      })
    ];
  }

  /* Put the basemap for the current theme on a map, and swap it when the
     theme changes. Tile layers sit in Leaflet's tile pane, under every
     marker, so the swap never changes what is drawn on top. */
  function basemap(map) {
    var layers = [];
    var gone = false;

    /* There is no unsubscribing from the theme, so a map that has been
       removed — the territory page rebuilds its map on "Try again" — keeps
       its listener. Adding tiles to a removed map throws; this makes the
       listener a no-op instead. */
    map.on('unload', function () { gone = true; });

    function apply(which) {
      if (gone) return;
      layers.forEach(function (layer) { map.removeLayer(layer); });
      layers = basemapLayers(which);
      layers.forEach(function (layer) { layer.addTo(map); });
    }

    apply(theme());
    onTheme(apply);
  }

  /* The summary is wanted by the header on every page and by the home page's
     figures. Memoised so a page asks for it once; a failure clears the cache
     so a retry is a real retry. */
  var summaryPromise = null;

  function summary() {
    if (!summaryPromise) {
      summaryPromise = fetchJSON('api.php?r=summary').catch(function (err) {
        summaryPromise = null;
        throw err;
      });
    }
    return summaryPromise;
  }

  return {
    NO_DATA: NO_DATA,
    summary: summary,
    ha: ha,
    per1000: per1000,
    baselineHa: baselineHa,
    ratio: ratio,
    count: count,
    date: date,
    shortDate: shortDate,
    parseTs: parseTs,
    window: window_,
    territoryName: territoryName,
    isUnnamed: isUnnamed,
    el: el,
    clear: clear,
    replace: replace,
    fetchJSON: fetchJSON,
    panel: panel,
    cssVar: cssVar,
    theme: theme,
    onTheme: onTheme,
    basemap: basemap
  };
})();

/* The window the whole site is showing, in the header. Deliberately quiet: if
   it cannot load, the box stays hidden and every page still works. */
(function () {
  'use strict';

  var DM = window.DM;          // named explicitly: this file does not rely on
                               // its own export having become a global
  var box = document.getElementById('window-box');
  if (!box) return;

  DM.summary().then(function (data) {
    var range = DM.window(data.window_start, data.window_end);
    if (range === DM.NO_DATA) return;
    document.getElementById('window-range').textContent = range;
    box.hidden = false;
  }).catch(function () { /* the header simply goes without it */ });
})();
