<?php
declare(strict_types=1);

/**
 * history.php — the same screening, run against every week on record.
 *
 * Everything here reads `rankings_history`, which is filled separately and does
 * not move. `rankings` — the window being evaluated now, rewritten daily — is
 * what index.php and map.php read, and the two are never queried together. The
 * header's date stays on the current window on this page as on every other: the
 * week chosen here is chosen and shown inside the page.
 *
 * The shell is served immediately; the windows, the ranking and the figures
 * arrive from api.php, so the page is never blank while Neon wakes.
 */

require __DIR__ . '/partials.php';

/* The same Chart.js build and the same integrity hash as territory.php, and
   the same Leaflet as index.php and map.php — one version of each library
   across the site, and hashes that were verified rather than written from
   memory. */
dm_head('History', 'history', [
    '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js" '
        . 'integrity="sha256-vOFUCAlZxXS+C7axqST/MvCOvG/0YMFZFx9RxTgCyEQ=" '
        . 'crossorigin="anonymous" defer></script>',
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
]);
?>

<div class="wrap">

  <div class="page-head">
    <h1>Every week, re-ranked</h1>
    <p class="page-note">
      The screening rerun for every seven-day window on record, not just the
      current one. Pick a week to see what it flagged.
    </p>
  </div>

  <!-- The whole series at a glance. It is here rather than lower down because
       the shape of it — the early spike, then the settling — is the context
       every individual week below has to be read against. -->
  <section class="series">
    <div class="series-head">
      <h2>Indigenous territories flagged each week</h2>
      <span class="series-note" id="series-note">&nbsp;</span>
    </div>
    <div class="series-box" id="series-box" hidden>
      <canvas id="series-chart" aria-label="Territories flagged in each seven-day window"></canvas>
    </div>
    <div id="series-state"></div>
  </section>

  <!-- The chosen week ---------------------------------------------------- -->
  <section class="window-pick">
    <label class="window-pick-label" for="window-select">Week</label>
    <select class="window-pick-select" id="window-select" disabled>
      <option>Loading…</option>
    </select>
    <span class="window-pick-note" id="window-pick-note"></span>
  </section>

  <!-- Before the ranking, never after it and never beside it. In a window
       early enough that the baselines had not settled, the method flags
       hundreds of territories; a reader who meets that number before this
       warning has already drawn the wrong conclusion from it. -->
  <div id="window-warning" hidden></div>

  <div class="figures figures-plain" id="window-stats" aria-live="polite">
    <div class="figure"><span class="figure-value skeleton">&nbsp;&nbsp;&nbsp;</span></div>
    <div class="figure"><span class="figure-value skeleton">&nbsp;&nbsp;&nbsp;</span></div>
  </div>

  <div class="tool-head">
    <h2>Flagged in this window</h2>
    <span class="tool-count" id="window-count">&nbsp;</span>
  </div>

  <!-- The same component the home page uses, driven by the same file. The
       only thing that differs is data-window: with it, map.js asks api.php for
       the points of that week, which come from rankings_history. Without it —
       every other page — they come from rankings, the current window.

       data-list is what makes map.js build and synchronise the list itself,
       rather than this page rebuilding that logic beside it. -->
  <div class="panel panel--60" id="window-ranking" aria-live="polite">
    <div class="panel-map">
      <div class="map-shell map-shell--home"
           id="history-map"
           data-territory-map
           data-only="flagged"
           data-window=""
           data-caption="#history-map-caption"
           data-list="#history-map-rows"
           data-count="#history-map-count"></div>
    </div>

    <!-- The column headings sit outside the scrolling box rather than inside
         it with position:sticky. Outside, nothing can pass behind them at all
         — which is the property being asked for, and it does not depend on
         winning a paint-order argument with the rows underneath. -->
    <div class="panel-list history-list">
      <div class="list-head" aria-hidden="true">
        <span class="lh-rank">#</span>
        <span class="lh-name">Territory</span>
        <span class="lh-ha">Forest lost (ha)</span>
        <span class="lh-ratio">Against its usual level</span>
      </div>
      <div class="explorer-rows" id="history-map-rows"></div>
    </div>
  </div>

  <p class="panel-foot">
    <span id="history-map-caption">&nbsp;</span>
    <span class="panel-foot-count" id="history-map-count"></span>
  </p>

  <!-- Across the weeks, not within one ---------------------------------- -->
  <!-- Every list above answers "what stood out this week". This one answers
       the question that only the series can: which territories keep coming
       back. It is one request against the whole of rankings_history, made
       once, and it does not move with the week picker. -->
  <section class="frequent" id="frequent">
    <div class="tool-head">
      <h2>Most frequently flagged</h2>
      <span class="tool-count" id="frequent-count">&nbsp;</span>
    </div>
    <p class="band-note">
      The weekly ranking is a photograph of one week. This is which
      territories keep appearing in it, week after week, across the full record.
    </p>

    <!-- The same list component as the week's ranking above, with the
         heading outside the rows for the same reason. The last column is the
         count, and it is the point of the row. -->
    <div class="frequent-list">
      <div class="list-head" aria-hidden="true">
        <span class="lh-rank">#</span>
        <span class="lh-name">Territory</span>
        <span class="lh-ha">Forest lost (ha)</span>
        <span class="lh-times">Weeks flagged</span>
      </div>
      <div class="explorer-rows" id="frequent-rows" aria-live="polite"></div>
    </div>

    <!-- Kept with the list, not in the footer: without it the count at the
         top of this table reads as a tally of the full record, when the first weeks of
         the series are deliberately not in it. -->
    <p class="panel-foot">
      <span>
        Counts only the weeks whose baseline had settled. The first
        <span id="frequent-note-weeks">20</span> weeks of the series are left
        out: their baselines rested on too little history, and the numbers
        here would be inflated by them. Hectares are the total lost across
        those weeks, flagged or not.
      </span>
    </p>
  </section>

  <!-- Climbing, not yet flagged --------------------------------------- -->
  <!-- The weekly rule fires on a spike. A territory whose loss has been
       creeping up for weeks without one never trips it, and the two lists
       above — one week, or a count of weeks — cannot show that either. The
       pipeline fits the line; this only reads its result. -->
  <section class="frequent">
    <div class="tool-head">
      <h2>Rising, not yet flagged</h2>
      <span class="tool-count" id="rising-count">&nbsp;</span>
    </div>
    <p class="band-note">
      The weekly rule catches events, not processes. These territories have
      been climbing steadily without yet reaching the spike that flags one.
    </p>

    <!-- The same rows as the list above. The last column is the slope of
         the fitted line, in the ratio's own units: how much the multiple
         rose each week. -->
    <div class="frequent-list rising-list">
      <div class="list-head" aria-hidden="true">
        <span class="lh-rank">#</span>
        <span class="lh-name">Territory</span>
        <span class="lh-times">Climbing by</span>
      </div>
      <div class="explorer-rows" id="rising-rows" aria-live="polite"></div>
    </div>

    <p class="panel-foot">
      <span>
        A straight line is fitted to the last seven weeks of each
        territory&rsquo;s anomaly ratio, leaving out the current week so this
        does not repeat what the ranking above already shows. Only territories
        whose line rises, and fits reasonably well, are listed &mdash; so a
        single spike does not count as a trend.
      </span>
    </p>
  </section>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<?php dm_footer(['assets/map.js', 'assets/history.js']); ?>
