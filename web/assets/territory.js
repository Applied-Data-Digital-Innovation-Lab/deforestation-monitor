/* territory.js — one territory: who it is, where it stands this week, and the
   weekly history that shows whether "this week" is actually unusual. */

(function () {
  'use strict';

  var el = DM.el;

  var page = document.getElementById('page');
  if (!page) return;

  var territoryId = page.getAttribute('data-territory-id');

  var headerTarget = document.getElementById('t-header');
  var statusTarget = document.getElementById('t-status');
  var noticeTarget = document.getElementById('t-notice');
  var chartState   = document.getElementById('chart-state');
  var chartBox     = document.getElementById('chart-box');
  var chartLegend  = document.getElementById('chart-legend');
  var chartCaveat  = document.getElementById('chart-caveat');
  var chartSub     = document.getElementById('chart-sub');
  var scaleToggle  = document.getElementById('scale-toggle');

  /* Terracotta marks what to look at — the current week and the threshold it
     crossed. Green is the context underneath it: what this place usually
     loses. Earlier weeks are sand-grey, neither. */
  var COLOR = {
    alert:   '#8C2F39',                    /* current window, threshold line */
    week:    '#B5B0A6',                    /* every earlier week            */
    pending: '#DEDBD4',                    /* a week begun but not evaluated */
    forest:  '#193B32',                    /* the band's edge               */
    sage:    'rgba(217, 228, 220, .85)',   /* the band's fill               */
    grid:    '#E8E5DD',
    muted:   '#646360',   /* axis ticks, 6.01:1 on white */
    ink:     '#3F3E3A',
    tooltip: '#0F241A'   /* the page's darkest green, not a neutral black */
  };

  var chart = null;

  /* Chart.js paints to a canvas, so the stylesheet cannot reach its labels.
     Pointed at the page's own sans stack so the axis does not arrive in
     Chart.js's default Helvetica. */
  var SANS = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, ' +
             '"Helvetica Neue", Arial, sans-serif';

  /* ---------- header ---------- */

  function metaItem(label, value, isMissing) {
    return el('div', {}, [
      el('dt', { text: label }),
      el('dd', { class: isMissing ? 'no-data' : null, text: value })
    ]);
  }

  /* How long this territory's record is, counted from the weekly series the
     chart below draws rather than written down.
   *
   * It was the literal 107 in two sentences on this page. The series gains a
   * row every week, so that number was wrong within days of being typed and
   * wrong by a growing margin after that — and it is the denominator of
   * "recorded loss in 42 of 107 weeks", so being wrong made the fraction
   * understate how sparse the record is.
   *
   * This assumes the pipeline counts active_weeks over the same span that
   * alerts_weekly stores, which is what the two numbers described when they
   * agreed. If the pipeline ever fixes its own window — the last 104 weeks,
   * say — while the series keeps growing, this is the line to revisit. */
  function weeksOfRecord(history) {
    return oneRowPerWeek(history).length;
  }

  function renderHeader(t, weeks) {
    var name = DM.territoryName(t.name, t.territory_id);
    document.title = name + ' · Deforestation Monitor';

    var badge = null;
    if (t.current && t.current.flagged) {
      badge = el('span', { class: 'badge badge-flagged', text: 'Flagged this week' });
    } else if (t.current) {
      badge = el('span', { class: 'badge badge-quiet', text: 'Within its normal range' });
    }

    /* The same mark that stands for this territory on the map, and that runs
       through the logo and the section headings. */
    var dot = t.current && t.current.flagged
      ? el('span', { class: 'anomaly-dot', 'aria-hidden': 'true' })
      : null;

    var meta = [
      metaItem('Country', t.country || DM.NO_DATA, !t.country),
      metaItem('Territory size', DM.ha(t.territory_ha), t.territory_ha === null),
      metaItem('Population', DM.count(t.population), t.population === null),
      metaItem('Communities', DM.count(t.communities), t.communities === null)
    ];

    /* Without a series behind it the count has no denominator, so it is shown
       on its own rather than as a fraction of nothing. */
    if (t.current && t.current.active_weeks !== null) {
      meta.push(metaItem(
        'Weeks with recorded loss',
        weeks > 0
          ? DM.count(t.current.active_weeks) + ' of ' + DM.count(weeks)
          : DM.count(t.current.active_weeks),
        false
      ));
    }

    DM.replace(headerTarget, [
      el('div', { class: 't-title-row' }, [
        dot,
        el('h1', { class: DM.isUnnamed(t.name) ? 't-name is-unnamed' : null, text: name }),
        badge
      ]),
      el('dl', { class: 't-meta' }, meta)
    ]);
  }

  /* ---------- current status ---------- */

  function statCard(label, value, unit, sub, isAlert) {
    return el('div', { class: 'stat' + (isAlert ? ' is-alert' : '') }, [
      el('p', { class: 'stat-label', text: label }),
      el('div', { class: 'stat-value' }, [
        value,
        unit ? el('span', { class: 'unit', text: unit }) : null
      ]),
      sub ? el('p', { class: 'stat-sub', text: sub }) : null
    ]);
  }

  function renderStatus(t) {
    var c = t.current;

    if (!c) {
      DM.clear(statusTarget);
      return;
    }

    var ratioText = DM.ratio(c.ratio);

    /* A stored baseline of 0 means no normal level was ever established, the
       same thing a null ratio says. Treated as absent rather than as "zero
       hectares is normal here". */
    var baseline = c.seasonal_baseline ? c.seasonal_baseline : null;

    DM.replace(statusTarget, [
      /* No alert colour on a quantity: the ratio below is what says whether
         this is unusual, and that is where the colour belongs. */
      statCard(
        'Forest lost this window',
        DM.ha(c.lost_ha, false),
        'hectares',
        'in the seven days to ' + DM.date(c.window_end)
      ),
      statCard(
        'Loss per 1,000 ha',
        DM.per1000(c.lost_per_1000ha, false),
        'ha / 1,000 ha',
        'the same loss, normalised by territory size'
      ),
      statCard(
        'Against its usual level',
        ratioText === null ? el('span', { style: 'font-size:24px', text: 'no baseline' }) : ratioText,
        ratioText === null ? null : 'its usual',
        baseline !== null
          ? 'usual for this time of year: ' + DM.per1000(baseline)
          : 'no seasonal baseline exists for this territory',
        c.ratio !== null && c.ratio >= 2
      )
    ]);
  }

  /* ---------- notices ---------- */

  function renderNotices(t, weeks) {
    var notices = [];
    var c = t.current;

    if (!c) {
      notices.push(el('div', { class: 'notice' }, [
        el('strong', { text: 'No entry in the current window. ' }),
        'The pipeline rewrites the rankings table daily and has no row for this ' +
        'territory in the seven days being evaluated. The history below is unaffected.'
      ]));
    } else if (c.method === 'sparse_history') {
      notices.push(el('div', { class: 'notice notice-method' }, [
        el('strong', { text: 'Judged on absolute loss. ' }),
        'This territory has recorded loss in only ' + DM.count(c.active_weeks) +
        (weeks > 0 ? ' of the last ' + DM.count(weeks) : '') +
        ' weeks, which is too little history for a dependable seasonal baseline. ' +
        'It is screened on how much forest it lost rather than on the seasonal ' +
        'comparison alone, and the multiple shown above rests on few observations.'
      ]));
    } else if (c.ratio === null) {
      notices.push(el('div', { class: 'notice' }, [
        el('strong', { text: 'No seasonal baseline. ' }),
        'There is no established normal level for this territory at this time of ' +
        'year, so no multiple can be calculated.'
      ]));
    }

    DM.replace(noticeTarget, notices);
  }

  /* ---------- chart ---------- */

  function chartMessage(text) {
    DM.replace(chartState, [el('div', { class: 'state', text: text })]);
  }

  /* ---------- the y axis ----------
   *
   * DM.ha() varies its precision with the magnitude, which is right for a
   * single figure on a card and wrong for a column of them: the axis came out
   * reading 20.0, 10.0, 8.00, 6.00, 4.00, 0.10, 0.08, 0.03 — four conventions
   * in one column. This gives every tick the shortest exact form of itself, so
   * the column shares one: 20, 10, 8, 6, 4, 2, 1, 0.1, 0.08.
   *
   * minimumFractionDigits: 0 is what trims the padding; the maximum is only
   * ever a ceiling, set high enough that a small tick keeps its significant
   * digits and a large one is not written out to a fictitious precision. */
  function axisTick(value) {
    if (typeof value !== 'number' || !isFinite(value)) return '';
    if (value === 0) return '0';

    var abs = Math.abs(value);
    var digits;
    if (abs >= 100) {
      digits = 0;
    } else if (abs >= 1) {
      digits = 2;                                        // 1.5 stays 1.5, 8 stays 8
    } else {
      // 0.08 needs two places, 0.008 needs three: one past the leading zeros.
      digits = Math.min(6, Math.ceil(-Math.log(abs) / Math.LN10) + 1);
    }

    return value.toLocaleString('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: digits
    });
  }

  /* Ticks a log axis can be read at: 1, 2 and 5 times a power of ten.
   *
   * Chart.js fills a narrow range with every integer in the decade, which put
   * 6, 8, 10 and 20 within a fifth of the axis height of each other — four
   * labels almost touching at the top. The 1-2-5 set is evenly spread in log
   * space instead (0.30, 0.40 and 0.30 of a decade apart), so the gaps are
   * even wherever you look.
   *
   * Over a wide enough range even that is too many, so it falls back to 1-5
   * and then to bare decades. Filtering rather than generating means a range
   * Chart.js has already reduced to decades survives the first pass
   * untouched. */
  var MANTISSAS = [[1, 2, 5], [1, 5], [1]];
  var MAX_LOG_TICKS = 9;

  function mantissaOf(value) {
    var exponent = Math.floor(Math.log(value) / Math.LN10);
    var m = value / Math.pow(10, exponent);

    /* log10 of an exact power of ten can land a hair under the integer —
       Math.log(1000) / Math.LN10 is 2.9999999999999996 — which floors the
       exponent one too low and makes the mantissa 10 rather than 1. Left
       alone that silently discarded the decade ticks, the ones most worth
       keeping. Corrected here rather than by widening the rounding, which
       would start admitting values that are genuinely 9.9 or 1.05. */
    if (m >= 9.9999) {
      m /= 10;
    } else if (m < 0.9999) {
      m *= 10;
    }

    return Math.round(m * 1000) / 1000;
  }

  function thinLogTicks(ticks) {
    var kept = null;

    for (var i = 0; i < MANTISSAS.length; i++) {
      var set = MANTISSAS[i];
      var pass = ticks.filter(function (tick) {
        return tick.value > 0 && set.indexOf(mantissaOf(tick.value)) !== -1;
      });
      if (!pass.length) continue;
      kept = pass;
      if (pass.length <= MAX_LOG_TICKS) break;
    }

    /* An axis with no labels at all is worse than a crowded one. */
    return kept && kept.length ? kept : ticks;
  }

  /* A week is stamped with the Monday it begins on, so a series covers six
     days more than its last row's own date says. Printing that date as the end
     of the range claimed the history stopped on a Monday. */
  function weekEnd(week) {
    var d = DM.parseTs(week);
    if (!d) return null;
    d.setDate(d.getDate() + 6);
    return d.getFullYear() + '-' +
      ('0' + (d.getMonth() + 1)).slice(-2) + '-' +
      ('0' + d.getDate()).slice(-2);
  }

  /* One bar per week, whatever arrives.
   *
   * alerts_weekly has no key that guarantees one row per week: `week` is a
   * timestamp, and the pipeline rewrites the table daily, so a territory can
   * carry two rows for the same Monday. Sorted by week they land in adjacent
   * categories with the same label and the same height — one week drawn
   * twice — and the current-window match then coloured the second of the pair
   * and left its twin sitting grey beside it.
   *
   * The query now collapses them too. This stays because the chart is where
   * the duplicate is visible, and it must not be able to draw a week twice
   * whatever the table does next.
   *
   * The later row wins rather than the sum: lost_ha is the week's total, not a
   * daily increment, so adding two writes of it would double-count. Rows
   * arrive in ascending order, so the last one seen for a date is the newest. */
  function oneRowPerWeek(rows) {
    var byDay = {};
    var order = [];
    rows.forEach(function (row) {
      var day = String(row.week).slice(0, 10);
      if (byDay[day] === undefined) order.push(day);
      byDay[day] = row;
    });
    return order.map(function (day) { return byDay[day]; });
  }

  function buildChart(t, raw) {
    var history = oneRowPerWeek(raw);

    /* Not shown on the page — the chart is correct now — but a territory
       carrying duplicate weeks is worth knowing about. */
    if (history.length !== raw.length) {
      var dropped = raw.length - history.length;
      if (window.console && console.warn) {
        console.warn('[deforestation-monitor] alerts_weekly returned ' + dropped +
                     ' duplicate week row(s) for territory ' + t.territory_id +
                     '; kept the most recent write of each week.');
      }
    }

    if (!history.length) {
      chartMessage('No weekly history has been recorded for this territory.');
      return;
    }

    var c = t.current;

    /* Converted to plain hectares so the band, the threshold and the bars
       share one axis. A baseline of 0 or null means none was established: no
       band is drawn, rather than one flattened onto the axis that would read
       as a threshold of zero hectares. */
    var baselineHa = c ? DM.baselineHa(c.seasonal_baseline, t.territory_ha) : null;

    /* Which bar is the evaluated window.
     *
     * By containment rather than by an exact date match. Equality held only
     * while every window began exactly on a week's Monday; one character of
     * drift — a timezone offset on the timestamp, a window realigned by the
     * pipeline — and no bar was marked at all, silently. The evaluated week is
     * the last one that begins on or before the window. ISO dates sort
     * chronologically as strings, so this is a plain comparison. */
    var currentIndex = -1;
    if (c && c.window_start) {
      var wanted = String(c.window_start).slice(0, 10);
      history.forEach(function (row, i) {
        if (String(row.week).slice(0, 10) <= wanted) currentIndex = i;
      });
    }

    /* The series can run past the evaluated window: the loss table gains a row
       for a week as soon as it starts, while the rankings table evaluates the
       last window that has finished. Those trailing weeks are real data but
       they have not been screened, and drawn in the ordinary grey they sat
       against the bordeaux bar looking like the same week drawn twice. */
    var pendingCount = currentIndex === -1 ? 0 : history.length - 1 - currentIndex;

    /* Full spans, so nothing anywhere shows a single date for seven days. */
    var spans = history.map(function (row) {
      return DM.window(row.week, weekEnd(row.week));
    });

    var labels = history.map(function (row) { return DM.date(row.week); });
    var tickLabels = history.map(function (row) {
      var d = DM.parseTs(row.week);
      return d ? DM.shortDate(row.week).split(' ')[1] + ' ' + d.getFullYear() : '';
    });
    var values = history.map(function (row) { return row.lost_ha; });

    var barColors = history.map(function (row, i) {
      if (i === currentIndex) return COLOR.alert;
      return i > currentIndex ? COLOR.pending : COLOR.week;
    });

    var datasets = [];

    if (baselineHa !== null) {
      datasets.push({
        type: 'line',
        label: 'Its usual level',
        data: values.map(function () { return baselineHa; }),
        borderColor: COLOR.forest,
        borderWidth: 1,
        borderDash: [4, 3],
        backgroundColor: COLOR.sage,
        fill: 'origin',
        pointRadius: 0,
        pointHitRadius: 0,
        order: 0
      });
      datasets.push({
        type: 'line',
        label: 'Flagging threshold',
        data: values.map(function () { return baselineHa * 2; }),
        borderColor: COLOR.alert,
        borderWidth: 1.5,
        borderDash: [5, 4],
        fill: false,
        pointRadius: 0,
        pointHitRadius: 0,
        order: 1
      });
    }

    var barsIndex = datasets.length;
    datasets.push({
      type: 'bar',
      label: 'Hectares lost',
      data: values,
      backgroundColor: barColors,
      borderWidth: 0,
      barPercentage: 1,
      categoryPercentage: 0.86,
      order: 2
    });

    chartBox.hidden = false;
    DM.clear(chartState);

    Chart.defaults.font.family = SANS;

    chart = new Chart(document.getElementById('history-chart').getContext('2d'), {
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 8,
              color: COLOR.muted,
              callback: function (value, index) { return tickLabels[index]; }
            }
          },
          y: {
            type: 'linear',
            beginAtZero: true,
            grid: { color: COLOR.grid },
            border: { display: false },
            title: { display: true, text: 'Hectares lost per week', color: COLOR.ink },
            /* Runs again on every update, so it applies the moment the toggle
               switches the scale and undoes itself the moment it switches
               back. */
            afterBuildTicks: function (axis) {
              if (axis.type === 'logarithmic') {
                axis.ticks = thinLogTicks(axis.ticks);
              }
            },
            ticks: {
              color: COLOR.muted,
              callback: function (value) { return axisTick(value); }
            }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: COLOR.tooltip,
            padding: 10,
            displayColors: false,
            filter: function (item) { return item.datasetIndex === barsIndex; },
            callbacks: {
              title: function (items) { return spans[items[0].dataIndex]; },
              label: function (item) { return DM.ha(item.parsed.y); },
              afterLabel: function (item) {
                var lines = [];
                var pending = item.dataIndex > currentIndex;

                /* No multiple for a week that has not finished. Its hectares
                   are still accumulating, so a low ratio would read as loss
                   having dropped rather than as the week being half-counted. */
                if (baselineHa !== null && baselineHa > 0 && !pending) {
                  lines.push(DM.ratio(item.parsed.y / baselineHa) + ' its usual level');
                }
                if (item.dataIndex === currentIndex) {
                  lines.push('The evaluated window');
                } else if (pending) {
                  lines.push('Not part of the evaluated window');
                }
                return lines.length ? lines : null;
              }
            }
          }
        }
      }
    });

    /* The range now runs to the end of the last week rather than to the Monday
       it started on, and says which convention it is using either way. */
    var last = history[history.length - 1];
    chartSub.textContent =
      history.length + ' weeks, ' + DM.window(history[0].week, weekEnd(last.week)) +
      '. Weeks run Monday to Sunday.';

    var pendingSwatch = document.getElementById('legend-pending');
    if (pendingSwatch) pendingSwatch.hidden = pendingCount === 0;

    chartLegend.hidden = baselineHa === null && pendingCount === 0;
    scaleToggle.hidden = false;
    setCaveat('linear', baselineHa, pendingCount);
    wireScaleToggle(baselineHa, pendingCount);
  }

  /* The band is drawn flat, and that is a property of this view rather than of
     the method — spelled out on the page so nobody reads it as the latter. */
  function setCaveat(scale, baselineHa, pendingCount) {
    var parts = [];

    /* First, because it is about a bar the reader can see at the right-hand
       edge, next to the bordeaux one. */
    if (pendingCount > 0) {
      parts.push(
        pendingCount === 1
          ? 'The pale bar at the end is a week that has begun but was not the ' +
            'window screened this run, so it is not compared against the baseline.'
          : 'The ' + pendingCount + ' pale bars at the end are weeks that have ' +
            'begun but were not the window screened this run, so they are not ' +
            'compared against the baseline.'
      );
    }

    if (baselineHa !== null) {
      parts.push(
        'The shaded band is drawn flat across the whole history. The method ' +
        'recalculates the seasonal baseline for every week, from a window of ' +
        'four weeks on either side of it; the rankings table only stores the ' +
        'value for the week being evaluated, so that single value is what is ' +
        'drawn here. The flat band is a simplification of this view, not a ' +
        'property of the method.'
      );
    } else {
      parts.push('No seasonal baseline is stored for this territory, so no band is drawn.');
    }

    if (scale === 'logarithmic') {
      parts.push(
        'On the logarithmic scale a week with no loss cannot be plotted: a gap ' +
        'in the bars means zero hectares, not missing data.'
      );
    }

    chartCaveat.textContent = parts.join(' ');
    chartCaveat.hidden = false;
  }

  /* A single week can be 30 times the size of every other bar, which is the
     point — but it flattens the history. The log view restores it. */
  function wireScaleToggle(baselineHa, pendingCount) {
    var buttons = scaleToggle.querySelectorAll('button');

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        var scale = button.getAttribute('data-scale');

        Array.prototype.forEach.call(buttons, function (other) {
          other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
        });

        chart.options.scales.y.type = scale;
        chart.options.scales.y.beginAtZero = scale === 'linear';
        chart.update();

        setCaveat(scale, baselineHa, pendingCount);
      });
    });
  }

  /* ---------- loading ---------- */

  function showLoading() {
    DM.replace(headerTarget, [
      el('div', { class: 'state' }, [
        el('span', { class: 'spinner' }),
        el('span', { id: 't-status-text', text: 'Loading this territory…' })
      ])
    ]);
    chartMessage('Loading the weekly history…');
  }

  function showError(message) {
    var retry = el('button', { type: 'button', text: 'Try again' });
    retry.addEventListener('click', load);

    DM.replace(headerTarget, [
      el('div', { class: 'state state-error' }, [
        el('div', { text: 'Could not load this territory. ' + message }),
        retry
      ])
    ]);
    DM.clear(statusTarget);
    DM.clear(chartState);
  }

  /* Deferred scripts run after the parser finishes, so Chart may not exist yet
     when this file executes at the end of the body. */
  function whenChartReady(fn) {
    if (window.Chart) { fn(); return; }
    document.addEventListener('DOMContentLoaded', fn);
  }

  function load() {
    showLoading();

    var waking = setTimeout(function () {
      var status = document.getElementById('t-status-text');
      if (status) status.textContent = 'Waking the database… this can take a few seconds.';
    }, 2500);

    Promise.all([
      DM.fetchJSON('api.php?r=territory&id=' + encodeURIComponent(territoryId)),
      DM.fetchJSON('api.php?r=history&id=' + encodeURIComponent(territoryId))
    ]).then(function (results) {
      clearTimeout(waking);
      var t = results[0];
      var history = results[1];
      var weeks = weeksOfRecord(history);

      renderHeader(t, weeks);
      renderStatus(t);
      renderNotices(t, weeks);
      whenChartReady(function () { buildChart(t, history); });
    }).catch(function (err) {
      clearTimeout(waking);
      showError(err.message);
    });
  }

  load();
})();
