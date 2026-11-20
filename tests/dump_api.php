<?php
declare(strict_types=1);

/**
 * Dump everything the data layer can answer, as JSON on stdout.
 *
 * Pointed at one data layer at a time, so the same questions can be put to
 * the PostgreSQL version and to the MySQL one and the answers compared. That
 * is a stronger check than a smoke test: it does not ask whether the ported
 * SQL runs, it asks whether it returns the same figures.
 *
 *   NEON_URL=... php tests/dump_api.php web/data.php.bak > /tmp/pg.json
 *                php tests/dump_api.php web/data.php     > /tmp/my.json
 *
 * The territories and windows it asks about are taken from the data itself,
 * in a fixed order, so both runs ask about the same ones without either being
 * hardcoded.
 *
 * dm_reports and dm_submit_report are left out on purpose. Field reports are
 * not copied between the databases, by design, so the two sides hold
 * different rows there and a difference would mean nothing.
 */

$layer = $argv[1] ?? null;
if ($layer === null || !is_file($layer)) {
    fwrite(STDERR, "uso: php tests/dump_api.php <ruta a data.php>\n");
    exit(1);
}

require $layer;

$out = [];

$windows = dm_windows();
$out['windows'] = $windows;

// Three windows rather than one: the newest, the one after it, and one from
// the middle of the series, where the baselines had not settled and the
// flagged count behaves differently.
$picked = [];
foreach ([0, 1, intdiv(count($windows), 2), count($windows) - 1] as $i) {
    if (isset($windows[$i]['window_end'])) {
        $picked[$windows[$i]['window_end']] = true;
    }
}
$picked = array_keys($picked);

$out['summary_current']  = dm_summary();
$out['flagged_current']  = dm_flagged();
$out['losing_current']   = dm_losing();
$out['map_all']          = dm_map_points(false);
$out['map_flagged']      = dm_map_points(true);
$out['frequent']         = dm_frequent();
$out['rising']           = dm_rising();

foreach ($picked as $w) {
    $out["summary@$w"] = dm_summary($w);
    $out["flagged@$w"] = dm_flagged($w);
    $out["losing@$w"]  = dm_losing($w);
    $out["map@$w"]     = dm_map_points(false, $w);
}

// The five worst flagged territories, which are the ones with hotspots, plus
// three quiet ones by lowest id, which exercise the empty and null paths.
$ids = array_slice(array_column($out['flagged_current'], 'territory_id'), 0, 5);
$quiet = array_column($out['map_all'], 'territory_id');
sort($quiet);
$ids = array_values(array_unique(array_merge($ids, array_slice($quiet, 0, 3))));

$out['territorios_consultados'] = $ids;

foreach ($ids as $id) {
    $id = (int) $id;
    $out["territory#$id"] = dm_territory($id);
    $out["history#$id"]   = dm_history($id);
    $out["hotspots#$id"]  = dm_hotspots($id);
    foreach (array_slice($picked, 0, 2) as $w) {
        $out["territory#$id@$w"] = dm_territory($id, $w);
    }
}

print json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
print "\n";
