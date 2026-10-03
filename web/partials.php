<?php
declare(strict_types=1);

/**
 * partials.php — the chrome shared by the three pages: document head,
 * navigation, and the footer that states what an alert is and is not.
 */

require_once __DIR__ . '/data.php';

function dm_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * An asset URL stamped with the file's own modification time.
 *
 * Without this a browser keeps serving the copy it cached, and a change to the
 * stylesheet or to a script simply does not arrive — which looks exactly like
 * the change never having been made.
 */
function dm_asset(string $path): string
{
    $full = __DIR__ . '/' . $path;
    $stamp = is_readable($full) ? (string) filemtime($full) : '0';
    return dm_e($path . '?v=' . $stamp);
}

/**
 * How many territories are watched.
 *
 * RAISG's set is fixed for a release rather than being counted per request, so
 * it is a constant — but it is printed in the meta description of every page
 * and in the link to the full map, and two hand-typed copies of a number are
 * two chances for them to drift apart.
 */
const DM_TERRITORIES = 3866;

function dm_territories(): string
{
    return number_format(DM_TERRITORIES);
}

/**
 * @param string       $title       Browser title, without the site name.
 * @param string       $active      Nav item to mark current: ranking|map|history,
 *                                  or '' on a page that is not in the nav at
 *                                  all, which is territory.php.
 * @param string[]     $head_extra  Raw <head> lines (stylesheets for Leaflet, etc).
 * @param string       $body_class
 */
function dm_head(string $title, string $active = '', array $head_extra = [], string $body_class = ''): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= dm_e($title) ?> · Deforestation Monitor</title>
<meta name="description" content="Weekly screening of forest loss across <?= dm_territories() ?> indigenous territories in the Amazon basin.">
<meta name="color-scheme" content="light dark">
<!-- Before the stylesheet and not deferred: it sets the reader's theme on
     <html> before the first paint, or a dark choice opens on a light flash. -->
<script src="<?= dm_asset('assets/theme.js') ?>"></script>
<link rel="stylesheet" href="<?= dm_asset('assets/app.css') ?>">
<?php foreach ($head_extra as $line) { echo $line, "\n"; } ?>
</head>
<!-- data-territories carries DM_TERRITORIES into the scripts. They cannot read
     a PHP constant, and the map's loading line names the number, so without
     this there were two copies of it: one here and one typed into map.js. -->
<body data-territories="<?= DM_TERRITORIES ?>"<?= $body_class !== '' ? ' class="' . dm_e($body_class) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="wrap header-inner">
    <!-- The name and, under it, what is being monitored. The tagline is a
         sibling of the link rather than a third span inside it: inside, it
         became part of the link's accessible name, and every page announced
         the logo as "Deforestation Monitor Indigenous territories Amazon
         basin". -->
    <div class="brand-block">
      <a class="brand" href="index.php">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="brand-text">Deforestation<span class="brand-thin"> Monitor</span></span>
      </a>
      <p class="brand-tagline">Indigenous territories &middot; Amazon basin</p>
    </div>
    <nav class="site-nav" aria-label="Main">
      <a href="index.php"<?= $active === 'ranking' ? ' aria-current="page"' : '' ?>>Ranking</a>
      <a href="map.php"<?= $active === 'map' ? ' aria-current="page"' : '' ?>>Map</a>
      <a href="history.php"<?= $active === 'history' ? ' aria-current="page"' : '' ?>>History</a>
    </nav>

    <!-- Always the window being evaluated now, on every page including
         history.php. The week a reader picks there is chosen and shown inside
         that page; the header is the site's clock and does not move with it,
         or "Seven-day window" would name two different things depending on
         where you looked. -->
    <span class="window-box" id="window-box" hidden>
      <span class="window-label">Seven-day window</span>
      <span class="window-range" id="window-range"></span>
    </span>
<?php if (!dm_is_configured()): ?>
    <!-- The badge used to read DEMO and mean "these are sample figures". There
         are no sample figures any more: without NEON_URL the pages render
         their shell and every panel in them fails, so what the badge marks now
         is a deployment that was never finished. -->
    <span class="source-badge" title="NEON_URL is not set, so this site has no data source. Every figure on the page will fail to load until it is set in the environment.">NOT CONFIGURED</span>
<?php endif; ?>

    <!-- The theme follows the operating system; this overrides it for this
         site only. A toggle with a fixed name and aria-pressed, rather than a
         label that flips between "dark" and "light": a name that changes on
         every press leaves a screen reader user unsure which state it is
         reporting. Hidden until theme.js wires it up. -->
    <button type="button" class="theme-toggle" id="theme-toggle"
            aria-label="Dark mode" aria-pressed="false" hidden>
      <svg class="icon-moon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M13.5 9.6A5.6 5.6 0 0 1 6.4 2.5a5.6 5.6 0 1 0 7.1 7.1z" fill="currentColor"/>
      </svg>
      <svg class="icon-sun" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <circle cx="8" cy="8" r="3.1" fill="currentColor"/>
        <path d="M8 1v1.8M8 13.2V15M1 8h1.8M13.2 8H15M3.05 3.05l1.27 1.27M11.68 11.68l1.27 1.27M3.05 12.95l1.27-1.27M11.68 4.32l1.27-1.27"
              stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
</header>
<main id="main">
<?php
}

/**
 * @param string[] $scripts     Page scripts, loaded after the shared helpers.
 * @param bool     $with_footer False on a page that fills the viewport and
 *                              carries the screening notice itself.
 */
/**
 * What an alert is. The first thing in the closing block, above everything
 * else and across its full width: it is the claim the rest qualifies.
 *
 * One copy of the words. The home page closes on them as a section of its
 * own, and every other page carries them in the footer — said twice on the
 * same page they would read as an oversight, and kept in two places in the
 * source they would drift apart.
 *
 * Written for a dark ground, which is what both callers give it.
 */
function dm_disclaimer_lead(): void
{
    ?>
    <p class="note-lead">
      <strong>An alert is a screening signal, not a verified event.</strong>
      A flagged territory is one where satellite-detected forest loss is
      unusual compared with its own recent history. It is a prompt to look
      closer &mdash; not confirmation that a particular event occurred, nor a
      statement about who is responsible.
    </p>
    <?php
}

/**
 * The attribution itself, as bare text so each caller can set it in its own
 * element.
 *
 * Global Forest Watch's licence requires the credit, and requires it to name
 * the product rather than the site, so this is one string used everywhere
 * rather than a sentence retyped per page. A page that shows loss derived
 * from these alerts and does not print this line is out of compliance — which
 * is what map.php was, having no footer of its own.
 */
function dm_source_line(): void
{
    echo 'Indigenous territory boundaries from RAISG; forest-loss alerts from '
       . 'Global Forest Watch (integrated deforestation alerts, UMD/GLAD and WUR).';
}

/**
 * Where the data comes from. Full width under the statement — it is the only
 * block left in the dark band now that the credit has a strip of its own.
 */
function dm_disclaimer_data(): void
{
    ?>
    <div class="note-block">
      <h2 class="note-head">Data</h2>
      <!-- Global Forest Watch is named, and named with the specific product,
           because its licence requires the attribution. It is not a courtesy
           line that can be shortened away when this block is redesigned. -->
      <p class="note-body"><?php dm_source_line(); ?></p>
      <p class="note-body">
        The seven-day window ends on the last day with published data. Global
        Forest Watch releases alerts a few days behind, so the days since are
        not covered yet.
      </p>
      <p class="note-body">
        Territory and country names are shown as recorded in the source data
        and are not translated.
      </p>
    </div>
    <?php
}

function dm_footer(array $scripts = [], bool $with_footer = true): void
{
    ?>
</main>
<?php if ($with_footer): ?>
<footer class="site-footer">
  <div class="wrap">
    <?php dm_disclaimer_lead(); ?>
    <?php dm_disclaimer_data(); ?>
  </div>
</footer>
<?php endif; ?>
<script src="<?= dm_asset('assets/format.js') ?>"></script>
<?php foreach ($scripts as $src) { echo '<script src="', dm_asset($src), '"></script>', "\n"; } ?>
</body>
</html>
<?php
}
