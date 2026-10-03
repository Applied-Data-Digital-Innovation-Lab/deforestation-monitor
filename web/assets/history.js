/* history.js — the week picker, the series behind it, and the ranking of
   whichever window is chosen.
 *
 * Everything on this page comes from `rankings_history` by way of api.php's
 * `w` parameter. Nothing here reads the current-window table, and nothing on
 * any other page reads this one's. */

(function () {
  'use strict';

  var el = DM.el;

  var selectNode  = document.getElementById('window-select');
  if (!selectNode) return;

  var pickNote    = document.getElementById('window-pick-note');
  var warningNode = document.getElementById('window-warning');
  var statsNode   = document.getElementById('window-stats');
  var countNode   = document.getElementById('window-count');
  var seriesBox   = document.getElementById('series-box');
  var seriesState = document.getElementById('series-state');
  var seriesNote  = document.getElementById('series-note');

  /* From the stylesheet's tokens, so the series follows the theme. Chart.js
     paints to a canvas, which CSS cannot reach. */
  function themeColors() {
    return {
      alert:       DM.cssVar('--alert'),   /* the chosen week */
      line:        DM.cssVar('--muted'),
      grid:        DM.cssVar('--chart-grid'),
      muted:       DM.cssVar('--muted'),
      ink:         DM.cssVar('--ink-2'),
      tooltip:     DM.cssVar('--tooltip-bg'),
      tooltipEdge: DM.cssVar('--tooltip-edge')
    };
  }

  var COLOR = themeColors();

  var SANS = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, ' +
             '"Helvetica Neue", Arial, sans-serif';

  var windows = [];
  var chart = null;
  var chosenSeriesDay = null;   // the week marked on the series; read by paintSeries

  DM.onTheme(function () {
    COLOR = themeColors();
    if (chart) paintSeries();
  });

  /* The wording is fixed. A window with a handful of weeks behind it flags
     hundreds of territories — 547 in August 2024 against the 40 to 60 of a
     settled week — and read without this, that number says catastrophe when
     what it describes is a baseline resting on too little history. */
  var UNSETTLED_WARNING =
    'This window has little history behind it. Each territory’s seasonal ' +
    'baseline rests on a handful of weeks, so the multiples are inflated and ' +
    'far more territories appear flagged than the method would flag today.';

  function day(value) { return String(value).slice(0, 10); }

  function labelFor(entry) {
    var text = DM.window(entry.window_start, entry.window_end);
    return entry.baseline_settled ? text : text + '  ·  baseline not settled';
  }

  /* ---------- the URL ----------
     The chosen week goes into the address bar without reloading, so the page
     can be linked to and reloaded on the same week — but the ranking is
     replaced in place, because reloading to change one select would throw away
     the series chart above it and fetch it again for nothing. */

  function chosenFromUrl() {
    var m = /[?&]w=(\d{4}-\d{2}-\d{2})(?:&|$)/.exec(String(location.search));
    return m ? m[1] : null;
  }

  function rememberInUrl(value, isNewest) {
    if (!window.history || !history.replaceState) return;
    /* The newest window is dropped from the URL rather than pinned: it is the
       page's default, and a bare history.php should always open on it. */
    history.replaceState(null, '', isNewest ? location.pathname
                                            : location.pathname + '?w=' + value);
  }

  /* ---------- the warning ---------- */

  function renderWarning(entry) {
    if (!entry || entry.baseline_settled) {
      DM.clear(warningNode);
      warningNode.hidden = true;
      return;
    }

    DM.replace(warningNode, [
      el('div', { class: 'window-warning' }, [
        el('strong', { text: 'Early window. ' }),
        UNSETTLED_WARNING,
        entry.weeks_of_history === null
          ? null
          : el('span', {
              class: 'window-warning-weeks',
              text: entry.weeks_of_history + ' weeks of history preceded it.'
            })
      ])
    ]);
    warningNode.hidden = false;
  }

  /* ---------- the two figures ---------- */

  function figure(value, unit, label, scope) {
    return el('div', { class: 'figure' }, [
      el('span', { class: 'figure-head' }, [
        el('span', { class: 'figure-value' }, [
          value,
          unit ? el('span', { class: 'unit', text: unit }) : null
        ]),
        el('span', { class: 'figure-label', text: label })
      ]),
      el('span', { class: 'figure-scope', text: scope || '' })
    ]);
  }

  function renderStats(summary, entry) {
    DM.replace(statsNode, [
      figure(DM.count(summary.flagged_count), null, 'flagged',
             'of ' + DM.count(summary.territories_with_loss) + ' with loss'),
      figure(DM.ha(summary.total_lost_ha, false), 'ha', 'lost',
             'across all territories')
    ]);

    countNode.textContent = DM.count(summary.flagged_count) + ' total';

    pickNote.textContent = entry && entry.weeks_of_history !== null
      ? entry.weeks_of_history + ' weeks of history behind it'
      : '';
  }

  /* ---------- the series ----------
     One point per window, drawn from the window list itself — the counts are
     already in that payload, so the chart costs no extra request. */

  function buildSeries(chosenDay) {
    if (!window.Chart) return;

    /* Oldest first here: a series that reads right to left would invert the
       one thing this chart is for, which is that the early weeks are the
       exceptional ones. */
    var series = windows.slice().reverse();

    var labels = series.map(function (entry) { return DM.date(entry.window_end); });
    var values = series.map(function (entry) { return entry.flagged_count; });

    var pointSizes = series.map(function (entry) {
      return day(entry.window_end) === chosenDay ? 4.5 : 0;
    });

    chosenSeriesDay = chosenDay;

    /* The colours, the chosen week's marker among them, are set by
       paintSeries() — here on every pick, and on its own when the theme
       changes. */
    if (chart) {
      chart.data.datasets[0].pointRadius = pointSizes;
      paintSeries();
      return;
    }

    seriesBox.hidden = false;
    DM.clear(seriesState);
    Chart.defaults.font.family = SANS;

    chart = new Chart(document.getElementById('series-chart').getContext('2d'), {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          borderWidth: 1.5,
          fill: false,
          tension: 0,
          pointRadius: pointSizes,
          pointHitRadius: 12
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              maxRotation: 0, autoSkip: true, maxTicksLimit: 7,
              callback: function (value, index) {
                var d = DM.parseTs(series[index].window_end);
                return d ? DM.shortDate(series[index].window_end).split(' ')[1] + ' ' +
                           d.getFullYear() : '';
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: {},
            border: { display: false },
            title: { display: true, text: 'Territories flagged' },
            ticks: { callback: function (v) { return DM.count(v); } }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            padding: 10,
            displayColors: false,
            callbacks: {
              title: function (items) {
                var entry = series[items[0].dataIndex];
                return DM.window(entry.window_start, entry.window_end);
              },
              label: function (item) {
                return DM.count(item.parsed.y) + ' flagged';
              },
              afterLabel: function (item) {
                return series[item.dataIndex].baseline_settled
                  ? null : 'Baseline not settled';
              }
            }
          }
        }
      }
    });

    paintSeries();

    var unsettled = windows.filter(function (e) { return !e.baseline_settled; }).length;
    seriesNote.textContent = unsettled
      ? DM.count(windows.length) + ' windows · ' + DM.count(unsettled) + ' with an unsettled baseline'
      : DM.count(windows.length) + ' windows';
  }

  /* Every colour the series has, in one place, so creating it, picking a
     week and switching theme cannot paint it three different ways. */
  function paintSeries() {
    var series = windows.slice().reverse();
    var pointColors = series.map(function (entry) {
      return day(entry.window_end) === chosenSeriesDay ? COLOR.alert : 'rgba(0,0,0,0)';
    });

    var ds = chart.data.datasets[0];
    ds.borderColor = COLOR.line;
    ds.pointBackgroundColor = pointColors;
    ds.pointBorderColor = pointColors;

    var scales = chart.options.scales;
    scales.x.ticks.color = COLOR.muted;
    scales.y.ticks.color = COLOR.muted;
    scales.y.grid.color = COLOR.grid;
    scales.y.title.color = COLOR.ink;

    var tooltip = chart.options.plugins.tooltip;
    tooltip.backgroundColor = COLOR.tooltip;
    tooltip.borderColor = COLOR.tooltipEdge;
    tooltip.borderWidth = 1;

    chart.update();
  }

  /* ---------- loading one window ---------- */

  var token = 0;

  /* map.js owns the map, the list beside it and the sync between them; this
     page only tells it which week to be showing. It is loaded before this file
     and publishes its handle synchronously, so the handle is there — but it is
     looked up per call rather than cached, so a page without a map simply does
     nothing here. */
  function moveMap(value) {
    var shell = document.getElementById('history-map');
    if (shell && shell.dmMap && shell.dmMap.setWindow) shell.dmMap.setWindow(value);
  }

  function show(entry) {
    var value = day(entry.window_end);
    var mine = ++token;                       // a slower earlier request loses

    rememberInUrl(value, entry === windows[0]);
    renderWarning(entry);
    buildSeries(value);
    moveMap(value);

    DM.replace(statsNode, [
      el('div', { class: 'figure' }, [el('span', { class: 'figure-value skeleton', text: '   ' })]),
      el('div', { class: 'figure' }, [el('span', { class: 'figure-value skeleton', text: '   ' })])
    ]);
    countNode.textContent = ' ';

    /* Only the two figures are fetched here. The ranking itself is the list
       beside the map, which map.js builds from the points it already has — a
       table underneath repeating those same territories was a second printing
       of one week, and a second request to fetch it. */
    DM.fetchJSON('api.php?r=summary&w=' + value).then(function (summary) {
      if (mine !== token) return;             // a newer week is already showing
      renderStats(summary, entry);
    }).catch(function (err) {
      if (mine !== token) return;
      DM.replace(statsNode, [
        el('div', { class: 'state state-error', text: 'Could not load this week. ' + err.message })
      ]);
      countNode.textContent = '';
    });
  }

  /* ---------- the frequently flagged ----------
     One request, made once, for the whole series. Unlike everything above
     it, this does not depend on the chosen week, so it does not wait for the
     windows and is not rebuilt when the select changes. */

  var frequentRows  = document.getElementById('frequent-rows');
  var frequentCount = document.getElementById('frequent-count');
  var frequentWeeks = document.getElementById('frequent-note-weeks');

  function renderFrequent(rows) {
    if (!rows.length) {
      DM.replace(frequentRows, [
        el('div', { class: 'state', text: 'No settled weeks have been recorded yet.' })
      ]);
      return;
    }

    /* The same row as the ranking beside the map, so the two lists on this
       page read as one component; only the last column differs. The count
       is set as the ratio is set there — the dominant thing in the row. */
    DM.replace(frequentRows, rows.map(function (row, index) {
      var name = DM.territoryName(row.name, row.territory_id);

      return el('a', {
        class: 'explorer-row',
        href: 'territory.php?id=' + row.territory_id
      }, [
        el('span', { class: 'ex-rank', text: String(index + 1) }),
        el('span', { class: 'ex-name' }, [
          name,
          ' ',
          el('span', { class: 'ex-country', text: row.country || DM.NO_DATA })
        ]),
        el('span', { class: 'ex-ha', text: DM.ha(row.total_lost_ha, false) }),
        el('span', { class: 'ex-times', text: DM.count(row.times_flagged) })
      ]);
    }));
  }

  /* The counts in the heading and the footnote come from the window list,
     which the page fetches anyway; the list itself does not carry them. */
  function noteFrequentWindows() {
    var settled = windows.filter(function (e) { return e.baseline_settled; }).length;
    var unsettled = windows.length - settled;

    if (frequentCount) {
      frequentCount.textContent = settled
        ? 'over ' + DM.count(settled) + ' settled weeks' : '';
    }
    if (frequentWeeks && unsettled) frequentWeeks.textContent = DM.count(unsettled);
  }

  if (frequentRows) {
    DM.panel(
      frequentRows,
      function () { return DM.fetchJSON('api.php?r=frequent'); },
      renderFrequent,
      'the most frequently flagged'
    );
  }

  /* ---------- the rising ----------
     Same shape as the list above, one request, made once. The pipeline has
     already fitted the lines and kept only the ones that qualify; nothing is
     recalculated here. */

  var risingRows  = document.getElementById('rising-rows');
  var risingCount = document.getElementById('rising-count');

  /* "+0.62×" — how much the anomaly ratio climbed each week.

     The slope is in the ratio's own units, so it is written the way the
     ratio is written everywhere else, with the sign to say it is a change
     and not a level. Two decimals rather than DM.ratio's one: the slopes run
     from 0.01 to 0.6, and at one decimal half the list would read 0.2. */
  function slopeText(value) {
    if (typeof value !== 'number' || !isFinite(value)) return DM.NO_DATA;
    return '+' + value.toLocaleString('en-US', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    }) + '×';
  }

  function renderRising(rows) {
    if (!rows.length) {
      DM.replace(risingRows, [
        el('div', { class: 'state', text: 'No territory is climbing steadily this week.' })
      ]);
      risingCount.textContent = '';
      return;
    }

    DM.replace(risingRows, rows.map(function (row, index) {
      var name = DM.territoryName(row.name, row.territory_id);

      return el('a', {
        class: 'explorer-row',
        href: 'territory.php?id=' + row.territory_id
      }, [
        el('span', { class: 'ex-rank', text: String(index + 1) }),
        el('span', { class: 'ex-name' }, [
          name,
          ' ',
          el('span', { class: 'ex-country', text: row.country || DM.NO_DATA })
        ]),
        el('span', { class: 'ex-times ex-slope' }, [
          slopeText(row.slope),
          el('span', { class: 'ex-slope-caption', text: 'per week' })
        ])
      ]);
    }));

    risingCount.textContent = DM.count(rows.length) + ' territories';
  }

  if (risingRows) {
    DM.panel(
      risingRows,
      function () { return DM.fetchJSON('api.php?r=rising'); },
      renderRising,
      'the rising territories'
    );
  }

  /* ---------- wiring ---------- */

  DM.replace(seriesState, [
    el('div', { class: 'state' }, [
      el('span', { class: 'spinner' }),
      el('span', { text: 'Loading the two years…' })
    ])
  ]);

  DM.fetchJSON('api.php?r=windows').then(function (rows) {
    windows = rows;

    if (!windows.length) {
      DM.replace(seriesState, [
        el('div', { class: 'state', text: 'No past windows have been recorded yet.' })
      ]);
      DM.clear(statsNode);
      countNode.textContent = '';
      return;
    }

    noteFrequentWindows();

    var chosenDay = chosenFromUrl();
    var chosen = windows[0];
    windows.forEach(function (entry) {
      if (day(entry.window_end) === chosenDay) chosen = entry;
    });

    DM.replace(selectNode, windows.map(function (entry) {
      var option = el('option', { value: day(entry.window_end), text: labelFor(entry) });
      if (entry === chosen) option.setAttribute('selected', 'selected');
      return option;
    }));
    selectNode.disabled = false;

    selectNode.addEventListener('change', function () {
      var value = selectNode.value;
      for (var i = 0; i < windows.length; i++) {
        if (day(windows[i].window_end) === value) { show(windows[i]); return; }
      }
    });

    /* Chart.js is deferred, so it may not have run when this resolves. */
    if (window.Chart) {
      show(chosen);
    } else {
      document.addEventListener('DOMContentLoaded', function () { show(chosen); });
    }
  }).catch(function (err) {
    DM.replace(seriesState, [
      el('div', { class: 'state state-error', text: 'Could not load the windows. ' + err.message })
    ]);
    DM.clear(statsNode);
  });
})();
