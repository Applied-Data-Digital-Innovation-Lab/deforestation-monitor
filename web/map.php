<?php
declare(strict_types=1);

/**
 * map.php — the exploration view: map and list side by side, filling the
 * viewport, kept in sync with each other.
 *
 * The home page carries a smaller, read-only version of this pairing. Both are
 * driven by assets/map.js; the difference is entirely in the data attributes on
 * the container below.
 *
 * The page does not scroll: the list scrolls inside its own column and the map
 * stays put. Which is why this is the one page that opts out of the site
 * footer — the screening notice it carries is pinned under the list instead,
 * outside that scroll, so the rows cannot bury it. The list holds every
 * territory that lost forest in the window — several hundred in a typical
 * week — and no count is written down here: it moves with the week, and the
 * 2,318 that used to be quoted matched neither the 3,866 territories nor the
 * few hundred that lose something in any one of them.
 *
 * Territory boundaries are deliberately not drawn: the polygons are 58 MB and
 * are not exposed.
 */

require __DIR__ . '/partials.php';

dm_head('Map', 'map', [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" '
        . 'integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
], 'map-page');
?>
<div class="explorer">

  <div class="map-shell explorer-map"
       data-territory-map
       data-only="all"
       data-scroll-zoom
       data-list="#explorer-rows"
       data-filters="#explorer-filters"
       data-count="#explorer-count"></div>

  <aside class="explorer-side" aria-label="Territory list">

    <div class="explorer-head">
      <h1 class="explorer-title">Explore indigenous territories</h1>
      <p class="explorer-count" id="explorer-count">&nbsp;</p>
    </div>

    <div class="explorer-filters" id="explorer-filters"></div>

    <!-- This list is ordered by area, unlike the home page's, so it says so
         rather than borrowing that page's line. -->
    <p class="list-note">
      Ordered by hectares lost. Territories flagged as unusual for themselves
      are marked in bordeaux.
    </p>

    <div class="explorer-rows" id="explorer-rows" aria-live="polite"></div>

    <!-- Pinned outside the scroll: after two thousand rows, a notice at the
         end of the list is a notice nobody reads.

         Condensed rather than dm_disclaimer(): this sits in a narrow column
         beside the map, where the full text would take a third of the height
         the list needs. Same claim, same wording, fewer clauses. -->
    <p class="explorer-note">
      <strong>An alert is a screening signal, not a verified event.</strong>
      A flagged territory is one where satellite-detected forest loss is
      unusual compared with its own recent history &mdash; a prompt to look
      closer, not confirmation that a particular event occurred, nor a
      statement about who is responsible.
    </p>

    <!-- This page carries no footer, so without this line it showed loss
         derived from Global Forest Watch alerts and credited no one. Pinned
         with the notice above rather than placed under the list, for the same
         reason: at the foot of several hundred rows nobody reaches it. -->
    <p class="explorer-source"><?php dm_source_line(); ?></p>

  </aside>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<?php dm_footer(['assets/map.js'], false); ?>
