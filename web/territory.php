<?php
declare(strict_types=1);

/**
 * territory.php — one territory, and the argument for why it was flagged.
 *
 * The chart is the point of this page: what is normal for this place, and
 * whether now is different.
 */

require __DIR__ . '/partials.php';

$raw = isset($_GET['id']) ? (string) $_GET['id'] : '';
$territory_id = preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;

dm_head('Territory', '', [
    '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js" '
        . 'integrity="sha256-vOFUCAlZxXS+C7axqST/MvCOvG/0YMFZFx9RxTgCyEQ=" '
        . 'crossorigin="anonymous" defer></script>',
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
]);

if ($territory_id === null):
?>
<div class="wrap">
  <div class="page-head">
    <h1>No territory selected</h1>
    <p class="lede">This page needs a territory id, for example
      <code>territory.php?id=3841</code>. Pick one from the
      <a href="index.php">ranking</a> or the <a href="map.php">map</a>.</p>
  </div>
</div>
<?php
    dm_footer();
    return;
endif;
?>
<div class="wrap" data-territory-id="<?= (int) $territory_id ?>" id="page">

  <a class="back-link" href="index.php">&larr; Back to the ranking</a>

  <div id="t-header" class="t-header" aria-live="polite">
    <div class="t-title-row"><h1 class="skeleton">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</h1></div>
  </div>

  <div id="t-status" class="stat-grid" aria-live="polite"></div>

  <div id="t-notice"></div>

  <!-- Hidden until there is something to put in it. Only territories flagged
       in the current window have hotspots, so for most of them this section
       never appears at all. -->
  <section class="section" id="hotspots" hidden>
    <h2>Where the loss happened</h2>
    <p class="section-note">
      Each alert is a ten-metre pixel with its own coordinates, so alerts less
      than two kilometres apart can be grouped into a hotspot. The rest of this
      site works with the territory as a whole; this shows which part of it the
      loss was in.
    </p>

    <div class="panel panel--60 hotspots">
      <div class="panel-map">
        <div class="map-shell map-shell--hotspots" id="hotspot-map"
             role="img" aria-label="Map of the hotspots inside this territory, each circle sized by hectares lost."></div>
      </div>
      <div class="hotspot-side">
        <div class="hotspot-head" aria-hidden="true">
          <span>#</span><span>Location</span><span class="hs-num">Lost</span><span class="hs-num">% of alerts</span>
        </div>
        <ol class="hotspot-list" id="hotspot-list"></ol>
        <p class="list-note" id="hotspot-note"></p>
      </div>
    </div>
  </section>

  <section class="section">
    <h2>Weekly history</h2>
    <p class="section-note">
      Every week since detection began, including the weeks with no loss at all
      &mdash; those are real zeros, not gaps in the record.
    </p>

    <div class="chart-card">
      <div class="chart-head">
        <div>
          <strong>Hectares lost per week</strong>
          <p class="section-note" id="chart-sub" style="margin:2px 0 0"></p>
        </div>
        <div class="scale-toggle" role="group" aria-label="Vertical scale" id="scale-toggle" hidden>
          <button type="button" data-scale="linear" aria-pressed="true">Linear</button>
          <button type="button" data-scale="logarithmic" aria-pressed="false">Log</button>
        </div>
      </div>

      <div class="chart-box" id="chart-box" hidden>
        <canvas id="history-chart" role="img"
                aria-label="Weekly hectares of forest lost, with the territory's usual level shaded."></canvas>
      </div>

      <div id="chart-state"></div>

      <div class="chart-legend" id="chart-legend" hidden>
        <!-- The same words as the bar's tooltip. Not "current window": the
             figures at the top are the last seven days with data, and this
             bar is a calendar week. -->
        <span><i class="swatch swatch-current"></i> Most recent full week</span>
        <span><i class="swatch swatch-week"></i> Earlier weeks</span>
        <!-- Shown only when the series runs past the evaluated window. -->
        <span id="legend-pending" hidden><i class="swatch swatch-pending"></i> Not yet evaluated</span>
        <span><i class="swatch swatch-band"></i> Its usual level</span>
        <span><i class="swatch swatch-threshold"></i> Flagging threshold (2&times; usual)</span>
      </div>

      <p class="chart-caveat" id="chart-caveat" hidden></p>
    </div>
  </section>

  <!-- The only form on the site, and the only thing on it that writes. It
       is rendered here rather than built by territory.js so that the labels,
       limits and the trap field are in the page whatever the script does. -->
  <section class="section section-divided" id="reports">
    <h2>Did you check this on the ground?</h2>
    <p class="section-note">
      An alert is a screening signal, not a verified event. What is recorded
      here is what will show, over time, how often the system is right.
    </p>

    <form class="report-form" id="report-form" action="report.php" method="post" novalidate>
      <input type="hidden" name="territory_id" value="<?= (int) $territory_id ?>">

      <div class="field">
        <label for="report-verdict">What did you find?</label>
        <select id="report-verdict" name="verdict" required>
          <option value="" selected disabled>Choose one</option>
          <option value="confirmed">Confirmed: the forest loss was there</option>
          <option value="not_found">Not found: no forest loss where the alert was</option>
          <option value="other_cause">Loss was there, but from another cause</option>
          <option value="unsure">Unsure</option>
        </select>
      </div>

      <div class="field">
        <label for="report-note">What you saw <span class="field-hint">optional, up to 2,000 characters</span></label>
        <textarea id="report-note" name="note" rows="5" maxlength="<?= DM_REPORT_NOTE_MAX ?>"></textarea>
      </div>

      <div class="field">
        <label for="report-reporter">Your name or organisation <span class="field-hint">optional, not published</span></label>
        <input type="text" id="report-reporter" name="reporter" maxlength="<?= DM_REPORT_REPORTER_MAX ?>" autocomplete="organization">
      </div>

      <!-- The trap. Moved off screen by the stylesheet, not type="hidden":
           a bot fills a text field it can see in the markup, and a person
           never reaches this one — it is out of the tab order and hidden
           from screen readers. report.php discards anything that fills it. -->
      <div class="report-trap" aria-hidden="true">
        <label for="report-website">Website</label>
        <input type="text" id="report-website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="report-actions">
        <button type="submit" class="report-submit" id="report-submit">Send report</button>
        <p class="report-status" id="report-status" role="status" aria-live="polite"></p>
      </div>
    </form>

    <div class="report-done" id="report-done" hidden tabindex="-1">
      <strong>Thank you. Your report has been recorded.</strong>
      It is stored against this territory and the week being evaluated now.
    </div>

    <div class="report-list" id="report-list" hidden>
      <h3 class="report-list-head">Reports so far</h3>
      <ol class="report-items" id="report-items"></ol>
    </div>
  </section>

</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<?php dm_footer(['assets/territory.js']); ?>
