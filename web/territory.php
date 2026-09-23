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
        <span><i class="swatch swatch-current"></i> Current window</span>
        <span><i class="swatch swatch-week"></i> Earlier weeks</span>
        <!-- Shown only when the series runs past the evaluated window. -->
        <span id="legend-pending" hidden><i class="swatch swatch-pending"></i> Not yet evaluated</span>
        <span><i class="swatch swatch-band"></i> Its usual level</span>
        <span><i class="swatch swatch-threshold"></i> Flagging threshold (2&times; usual)</span>
      </div>

      <p class="chart-caveat" id="chart-caveat" hidden></p>
    </div>
  </section>

</div>
<?php dm_footer(['assets/territory.js']); ?>
