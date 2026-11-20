/* ranking.js — the home page: the headline claim and its three figures, the
   full flagged list with country filters, and the longer list underneath. */

(function () {
  'use strict';

  var el = DM.el;

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  /* ---------- 1. the line of context and the three figures ---------- */

  /* Two lines: the number with what it counts, and under them which
     territories it covers. The third figure belongs to one place, and a name
     set loose beside the label read as an orphan — under it, it reads as part
     of the figure. Giving all three the second line keeps them a set, and
     puts back the universe each number covers, which is what keeps the two
     hectare figures from being read as the same one. */
  function figure(value, unit, label, scope, isRatio) {
    return el('div', { class: 'figure' }, [
      el('span', { class: 'figure-head' }, [
        el('span', { class: 'figure-value' + (isRatio ? ' is-ratio' : '') }, [
          value,
          unit ? el('span', { class: 'unit', text: unit }) : null
        ]),
        el('span', { class: 'figure-label', text: label })
      ]),
      el('span', { class: 'figure-scope', text: scope || '' })
    ]);
  }

  function renderHero(data) {
    var stats = document.getElementById('hero-stats');

    /* The band's own two lines: where and when, then what was found. */
    var eyebrow = document.getElementById('hero-eyebrow');
    if (eyebrow) {
      eyebrow.textContent = 'Indigenous territories · Amazon basin · ' +
        DM.window(data.window_start, data.window_end);
    }

    var lead = document.getElementById('hero-lead');
    if (lead) {
      DM.replace(lead, [
        el('span', { class: 'hero-count', text: DM.count(data.flagged_count) }),
        data.flagged_count === 1
          ? ' territory is showing forest loss outside its historical pattern.'
          : ' territories are showing forest loss outside their historical pattern.'
      ]);
    }

    var figures = [
      figure(DM.count(data.flagged_count), null, 'flagged',
             'of ' + DM.count(data.territories_with_loss) + ' with loss'),
      figure(DM.ha(data.total_lost_ha, false), 'ha', 'lost',
             'across all territories')
    ];

    /* The third figure is the ratio of the territory that lost the most, not
       its hectares. Repeating hectares said nothing the second figure had not
       already said; the multiple is what this site is for. */
    if (data.worst_flagged_ratio !== null) {
      figures.push(figure(
        DM.ratio(data.worst_flagged_ratio),
        null,
        'above usual',
        DM.territoryName(data.worst_flagged_name, data.worst_flagged_id),
        true
      ));
    } else {
      figures.push(figure('None', null, 'flagged', 'nothing met both conditions this week'));
    }

    DM.replace(stats, figures);

    var toolCount = document.getElementById('tool-count');
    if (toolCount) toolCount.textContent = DM.count(data.flagged_count) + ' total';
  }

  /* ---------- 2. the list beside the map ----------
     The same rows as the full list below, cut to what fits next to the map.
     How many fit is decided in CSS by screen height, so every row is rendered
     and the ones past the fold are hidden rather than never built. */

  /* The map publishes a handle as soon as it is created; this list is built
     from a different query, so it drives that handle rather than being built
     by map.js. Rows are marked by toggling a class on exactly two of them —
     the one leaving and the one arriving — never by re-rendering. */
  function linkToMap(target) {
    var shell = document.querySelector('[data-territory-map]');
    if (!shell || !shell.dmMap) return;

    var marked = null;

    shell.dmMap.onHighlight(function (id) {
      if (marked) marked.classList.remove('is-active');
      marked = id === null ? null : target.querySelector('[data-id="' + id + '"]');
      if (marked) marked.classList.add('is-active');
    });

    function idFrom(ev) {
      var node = ev.target && ev.target.closest ? ev.target.closest('.panel-row') : null;
      return node ? Number(node.getAttribute('data-id')) : null;
    }

    target.addEventListener('mouseover', function (ev) { shell.dmMap.highlight(idFrom(ev)); });
    target.addEventListener('mouseleave', function () { shell.dmMap.highlight(null); });
    target.addEventListener('focusin', function (ev) {
      var id = idFrom(ev);
      if (id !== null) shell.dmMap.highlight(id);
    });
  }

  function renderPanelList(rows) {
    var target = document.getElementById('panel-rows');
    var name;

    DM.replace(target, rows.map(function (row, index) {
      name = DM.territoryName(row.name, row.territory_id);

      /* Stacked, with the ratio as the largest thing in the row. That number
         is what separates this from any other deforestation map: Vale do
         Javari lost five times more than Rio Biá and is far less anomalous,
         and that only shows if the multiple outweighs the hectares. */
      var element = el('a', {
        class: 'panel-row',
        href: 'territory.php?id=' + row.territory_id,
      }, [
        el('span', { class: 'pl-rank', text: pad2(index + 1) }),
        el('span', { class: 'pl-name', text: name }),
        el('span', { class: 'pl-meta' }, [
          (row.country || DM.NO_DATA),
          ' · ',
          DM.ha(row.lost_ha)
        ]),
        /* A territory with too little history has no baseline, so no multiple
           can exist. An em-dash reads as a failed load; the words say which
           it is, the same way the territory page does. */
        DM.ratio(row.ratio) === null
          ? el('span', { class: 'pl-ratio-line' }, [
              el('span', { class: 'pl-ratio is-none', text: 'No baseline' }),
              el('span', { class: 'pl-note', text: 'too little history' })
            ])
          : el('span', { class: 'pl-ratio-line' }, [
              el('span', { class: 'pl-ratio', text: DM.ratio(row.ratio) }),
              el('span', { class: 'pl-note', text: 'above usual' })
            ])
      ]);

      element.setAttribute('data-id', String(row.territory_id));
      return element;
    }));

    linkToMap(target);
  }

  /* ---------- 3. the method, worked through the top of the ranking ----------
     The four steps carry one territory's own numbers, taken from the row the
     panel already fetched — no second request, and no example invented for the
     page that could drift away from what the ranking actually says.

     The baseline is stored per 1,000 ha of territory and the loss in plain
     hectares, so the two cannot be set beside each other until the baseline is
     converted. Once it is, the arithmetic is visible: 733 divided by 23.1 is
     the 31.8x in the third step. */
  function renderMethod(row) {
    var lost = document.getElementById('flow-lost');
    if (!lost) return;                    // the flow lives on the home page only

    var baseline = DM.baselineHa(row.seasonal_baseline, row.territory_ha);
    var ratioText = DM.ratio(row.ratio);

    function none() {
      return el('span', { class: 'is-none', text: 'no baseline' });
    }

    DM.replace(lost, [DM.ha(row.lost_ha)]);
    DM.replace(document.getElementById('flow-baseline'),
               baseline === null ? [none()] : [DM.ha(baseline)]);
    DM.replace(document.getElementById('flow-ratio'),
               ratioText === null ? [none()] : [ratioText]);

    /* Without a baseline there is no multiple, so the caption below it cannot
       keep saying "above usual" — the same wording the panel rows use. */
    if (ratioText === null) {
      document.getElementById('flow-ratio-note').textContent = 'too little history';
    }

    DM.replace(document.getElementById('flow-name'), [
      el('span', { class: 'anomaly-dot', 'aria-hidden': 'true' }),
      el('a', {
        href: 'territory.php?id=' + row.territory_id,
        text: DM.territoryName(row.name, row.territory_id)
      })
    ]);

    document.getElementById('flow-country').textContent = row.country || DM.NO_DATA;
  }

  /* A week with nothing flagged is a legitimate result, and a failed request
     is not — but in both cases the steps have to stop blinking and say which
     of the two it was, rather than leaving four skeletons on the page. */
  function methodWithoutSubject(message) {
    var lost = document.getElementById('flow-lost');
    if (!lost) return;
    ['flow-lost', 'flow-baseline', 'flow-ratio', 'flow-name'].forEach(function (id) {
      DM.replace(document.getElementById(id), [
        el('span', { class: 'is-none', text: message })
      ]);
    });
    document.getElementById('flow-country').textContent = '';
  }

  /* ---------- shared cells ---------- */

  function nameCell(row, withCountry) {
    var unnamed = DM.isUnnamed(row.name);
    return el('td', { class: 'name-cell' }, [
      el('a', {
        class: 't-name' + (unnamed ? ' is-unnamed' : ''),
        href: 'territory.php?id=' + row.territory_id,
        text: DM.territoryName(row.name, row.territory_id)
      }),
      withCountry
        ? el('span', { class: 't-country', text: row.country || DM.NO_DATA })
        : null
    ]);
  }

  /* Hectares lost, with a bar behind them so the spread reads at a glance. */
  function lossCell(row, maxLoss) {
    var width = maxLoss > 0 ? Math.max((row.lost_ha / maxLoss) * 100, 1.5) : 0;
    return el('td', { class: 'num bar-cell' }, [
      el('span', { class: 'bar', style: 'width:' + width.toFixed(1) + '%' }),
      el('span', { text: DM.ha(row.lost_ha, false) })
    ]);
  }

  function ratioCell(row, big) {
    var text = DM.ratio(row.ratio);
    if (text === null) {
      return el('td', { class: 'num' }, [
        el('span', { class: 'ratio is-none', text: 'no baseline' })
      ]);
    }
    return el('td', { class: 'num' }, [
      el('span', {
        class: 'ratio' + (big ? ' is-big' : '') + (row.ratio < 10 ? ' is-mild' : ''),
        text: text
      }),
      big ? el('span', { class: 'ratio-caption', text: 'its usual level' }) : null
    ]);
  }

  function emptyState(message) {
    return el('div', { class: 'state', text: message });
  }

    /* Country buttons are derived from the rows that arrived. A territory in a
     country that has never been flagged before produces its button on its own;
     nothing here is written down in advance. */
  function buildCountryFilter(rows, body) {
    var bar = document.getElementById('country-filter');
    var counts = {};
    var order = [];

    rows.forEach(function (row) {
      var country = row.country || DM.NO_DATA;
      if (counts[country] === undefined) {
        counts[country] = 0;
        order.push(country);
      }
      counts[country]++;
    });

    order.sort(function (a, b) {
      return counts[b] - counts[a] || a.localeCompare(b);
    });

    if (order.length < 2) return;               // one country: nothing to filter

    var buttons = [];

    function select(value, button) {
      buttons.forEach(function (other) {
        other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
      });
      Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
        tr.hidden = value !== 'all' && tr.getAttribute('data-country') !== value;
      });
    }

    function chip(value, label, count) {
      var button = el('button', {
        type: 'button',
        class: 'chip',
        'aria-pressed': value === 'all' ? 'true' : 'false'
      }, [
        label,
        el('span', { class: 'chip-count', text: String(count) })
      ]);
      button.addEventListener('click', function () { select(value, button); });
      buttons.push(button);
      return button;
    }

    var chips = [chip('all', 'All', rows.length)];
    order.forEach(function (country) {
      chips.push(chip(country, country, counts[country]));
    });

    DM.replace(bar, [
      el('span', { class: 'filter-label', text: 'Filter by country' }),
      el('div', { class: 'chips' }, chips)
    ]);
    bar.hidden = false;
  }

  /* ---------- 4. everything that lost forest ---------- */

  function losingTable(rows) {
    var maxLoss = rows.reduce(function (max, row) {
      return Math.max(max, row.lost_ha || 0);
    }, 0);

    var body = el('tbody', {}, rows.map(function (row, index) {
      var tr = el('tr', {}, [
        el('td', { class: 'rank', text: pad2(index + 1) }),
        nameCell(row, false),
        el('td', { class: 't-country', text: row.country || DM.NO_DATA }),
        lossCell(row, maxLoss),
        el('td', { class: 'num', text: DM.per1000(row.lost_per_1000ha, false) }),
        ratioCell(row, false),
        el('td', {}, [
          row.flagged
            ? el('span', { class: 'badge badge-flagged', text: 'Flagged' })
            : el('span', { class: 't-country', text: 'within its normal range' })
        ])
      ]);
      tr.setAttribute('data-country', row.country || '');
      return tr;
    }));

    var node = el('div', { class: 'table-scroll table-scroll--tall' }, [
      el('table', { class: 'data' }, [
        el('caption', { class: 'visually-hidden', text: 'All territories with recorded loss, ordered by hectares lost' }),
        el('thead', {}, [
          el('tr', {}, [
            el('th', { scope: 'col', class: 'rank', text: '#' }),
            el('th', { scope: 'col', text: 'Territory' }),
            el('th', { scope: 'col', text: 'Country' }),
            el('th', { scope: 'col', class: 'num', text: 'Forest lost (ha)' }),
            el('th', { scope: 'col', class: 'num', text: 'Loss per 1,000 ha' }),
            el('th', { scope: 'col', class: 'num', text: 'Against its usual level' }),
            el('th', { scope: 'col', text: 'Status' })
          ])
        ]),
        body
      ])
    ]);

    return { node: node, body: body };
  }

  /* ---------- wiring ---------- */

  DM.panel(
    document.getElementById('hero-stats'),
    function () { return DM.summary(); },
    renderHero,
    'this week’s figures'
  );

  /* The panel beside the map: the ten most anomalous, by score. */
  var panelRows = document.getElementById('panel-rows');
  DM.replace(panelRows, [el('div', { class: 'state', text: 'Loading…' })]);

  DM.fetchJSON('api.php?r=flagged').then(function (rows) {
    if (!rows.length) {
      DM.replace(panelRows, [emptyState('Nothing flagged in this window.')]);
      methodWithoutSubject('none this week');
      return;
    }
    renderPanelList(rows.slice(0, 10));
    /* The same first row the panel puts at 01: the method is demonstrated on
       the territory the reader is looking at, not on a different one. */
    renderMethod(rows[0]);
  }).catch(function (err) {
    DM.replace(panelRows, [emptyState('Could not load the ranking. ' + err.message)]);
    methodWithoutSubject('not loaded');
  });

  /* The section below is every territory that lost forest — around two
     thousand rows, ordered by hectares, with the flagged ones marked. It is
     not a second printing of the ten above.
     Its payload is 350 KB, so it is fetched when the reader approaches it
     rather than on every page load. */
  var losingTarget = document.getElementById('losing');
  var losingLoaded = false;

  function loadLosing() {
    if (losingLoaded) return;
    losingLoaded = true;

    DM.panel(
      losingTarget,
      function () { return DM.fetchJSON('api.php?r=losing'); },
      function (rows) {
        if (!rows.length) {
          losingTarget.appendChild(emptyState('No recorded loss in this window.'));
          return;
        }
        var built = losingTable(rows);
        losingTarget.appendChild(built.node);
        buildCountryFilter(rows, built.body);
      },
      'every territory that lost forest'
    );
  }

  if (typeof IntersectionObserver === 'function') {
    var watcher = new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) {
        watcher.disconnect();
        loadLosing();
      }
    }, { rootMargin: '600px' });
    watcher.observe(losingTarget);
  } else {
    loadLosing();
  }
})();
