<?php
declare(strict_types=1);

/**
 * index.php — the home page, built around the tool rather than around a hero.
 *
 * The order is the band, the week's figures, the map, the ranking, then the
 * explanation. The band fills the viewport less the header, and the week's
 * three figures sit on its floor — so the page opens on a photograph and a
 * finding rather than on a control panel, and the cue under the figures says
 * where the tool is. It was 320px once, deliberately too short to fill a
 * screen; that is no longer what the stylesheet does.
 *
 * The shell is served immediately and the figures arrive from api.php, so the
 * page is never blank while Neon wakes from idle.
 */

require __DIR__ . '/partials.php';

dm_head('Ranking', 'ranking', [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
]);
?>

<!-- The band. Full width, the photograph behind a flat veil, the text on top.
     No boundaries are drawn over it: only points are published, and drawing
     territory outlines by hand would be illustrating data that does not exist. -->
<section class="hero">
  <div class="wrap hero-inner">
    <p class="hero-eyebrow" id="hero-eyebrow">Indigenous territories · Amazon basin</p>
    <h1 class="hero-title">Watching indigenous land change</h1>
    <p class="hero-lead" id="hero-lead"><span class="skeleton">&nbsp;&nbsp;</span>
      territories are showing forest loss outside their historical pattern.</p>
  </div>

  <!-- An opaque cream strip on the floor of the band. The figures and the cue
       sit on it, so no text in this zone is set over the photograph at all. -->
  <div class="hero-foot">
    <div class="wrap">
      <div class="figures" id="hero-stats" aria-live="polite">
        <div class="figure"><span class="figure-value skeleton">&nbsp;&nbsp;&nbsp;</span></div>
        <div class="figure"><span class="figure-value skeleton">&nbsp;&nbsp;&nbsp;</span></div>
        <div class="figure"><span class="figure-value skeleton">&nbsp;&nbsp;&nbsp;</span></div>
      </div>

      <a class="hero-scroll" href="#tool">
        <span class="hero-scroll-label">Explore this week</span>
        <span class="hero-scroll-arrow" aria-hidden="true">&darr;</span>
      </a>
    </div>
  </div>
</section>

<!-- The tool: map and ranking as one thing ------------------------------- -->
<section class="band band-tool" id="tool">
  <div class="wrap">

    <p class="transition">
      Not all loss is unusual. Each territory is compared against its own
      historical pattern.
    </p>

    <p class="list-note">
      <strong>Ranked by how unusual the loss is, weighted by how much forest
      it covers.</strong>
    </p>

    <div class="tool-head">
      <h2>Flagged indigenous territories</h2>
      <span class="tool-count" id="tool-count">&nbsp;</span>
    </div>

    <div class="panel">
      <div class="panel-map">
        <div class="map-shell map-shell--home"
             data-territory-map
             data-only="flagged"
             data-caption="#map-caption"></div>
      </div>

      <div class="panel-list">
        <div class="panel-rows" id="panel-rows" aria-live="polite"></div>
        <a class="panel-more" href="#all-loss">Every territory that lost forest &darr;</a>
      </div>
    </div>

    <p class="panel-foot">
      <span id="map-caption">&nbsp;</span>
      <a href="map.php">Full map, all <?= dm_territories() ?> &rarr;</a>
    </p>

  </div>
</section>

<!-- Everything that lost forest, not a second printing of the flagged ---- -->
<section class="band band-white" id="all-loss">
  <div class="wrap">
    <h2>Every territory that lost forest this week</h2>
    <p class="band-note">
      The flagged ones are the priority, but the rest are not irrelevant.
      Ordered by hectares lost; the flagged are marked.
    </p>
    <div id="country-filter" class="filter-bar" hidden></div>
    <div id="losing" aria-live="polite"></div>
  </div>
</section>

<!-- The separator between the table and the method. Outside any .wrap and
     outside any <section>, because it is neither: it is a rule drawn in a
     photograph, and it carries no text of its own.

     alt is empty on purpose. A decorative image with a description is an
     interruption for anyone listening to the page — the band says nothing
     the sections on either side of it do not already say in words. -->
<div class="photo-band">
  <img src="<?= dm_asset('assets/indigenous_community.jpg') ?>"
       alt="" width="1400" height="990" loading="lazy" decoding="async">
</div>

<!-- How it works, worked through one real territory ---------------------- -->
<!-- The four values are filled from the top row of the ranking, so the method
     is demonstrated on this week's data rather than on an invented example.
     Nothing here draws the territory itself: only points are published, and a
     shape in the result box would be an illustration of data we do not have. -->
<section class="band band-method">
  <div class="wrap">
    <h2>How a territory gets flagged</h2>
    <p class="band-note">
      Worked through the territory at the top of this week's ranking. Every
      number below is its own.
    </p>

    <ol class="flow" id="flow" aria-live="polite">
      <li class="flow-step">
        <span class="flow-num">01</span>
        <span class="flow-label"><span>7 days</span> <span>of loss</span></span>
        <span class="flow-value" id="flow-lost"><span class="skeleton">&nbsp;&nbsp;&nbsp;</span></span>
        <span class="flow-caption">detected</span>
      </li>
      <li class="flow-step">
        <span class="flow-num">02</span>
        <span class="flow-label"><span>Seasonal</span> <span>baseline</span></span>
        <span class="flow-value" id="flow-baseline"><span class="skeleton">&nbsp;&nbsp;&nbsp;</span></span>
        <span class="flow-caption">expected</span>
      </li>
      <li class="flow-step">
        <span class="flow-num">03</span>
        <span class="flow-label"><span>Anomaly</span> <span>score</span></span>
        <span class="flow-value is-ratio" id="flow-ratio"><span class="skeleton">&nbsp;&nbsp;</span></span>
        <span class="flow-caption" id="flow-ratio-note">above usual</span>
      </li>
      <li class="flow-step is-result">
        <span class="flow-num">04</span>
        <span class="flow-label"><span>Flagged</span> <span>territory</span></span>
        <span class="flow-value" id="flow-name"><span class="skeleton">&nbsp;&nbsp;&nbsp;&nbsp;</span></span>
        <span class="flow-caption" id="flow-country">&nbsp;</span>
      </li>
    </ol>

    <div class="conditions">
      <p class="conditions-intro">A territory is flagged only when both conditions are met:</p>
      <ol class="conditions-list">
        <li><span class="cond-num">01</span>at least 5 ha lost in the last 7 days</li>
        <li><span class="cond-num">02</span>at least 2&times; its seasonal baseline</li>
      </ol>
      <p class="conditions-tail">
        The second condition is what keeps large territories with constant loss
        from dominating the ranking.
      </p>
    </div>
  </div>
</section>

<!-- Built by --------------------------------------------------------------
     White rather than the cream of the method section above it. Cream on
     cream is what made the old About block read as glued to the bottom of
     the method, and the page already alternates: white table, cream method,
     white here, forest below. -->
<section class="band band-credit">
  <div class="wrap">
    <h2 class="credit-head">Built by</h2>
    <p class="credit-name">Ignacio Morales</p>
    <p class="credit-role">Data scientist</p>
    <p class="credit-org">
      Developed for the Applied Data and Digital Innovation Lab at
      Living Stones Foundation.
    </p>
  </div>
</section>

<!-- The closing statement. The page ends on what an alert is not ---------- -->
<!-- The same words the other pages carry in their footer, from the one copy in
     partials.php. Given a section here rather than being repeated underneath
     it: the home page is where a reader arrives with no idea what "flagged"
     means, so this is the last thing they read rather than fine print. -->
<section class="band band-close">
  <div class="wrap">
    <?php dm_disclaimer_lead(); ?>
    <?php dm_disclaimer_data(); ?>
  </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<?php
/* No footer: it would print the section above a second time, immediately
   below it. */
dm_footer(['assets/map.js', 'assets/ranking.js'], false);
?>
