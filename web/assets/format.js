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

  function fetchJSON(url) {
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
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
    panel: panel
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
