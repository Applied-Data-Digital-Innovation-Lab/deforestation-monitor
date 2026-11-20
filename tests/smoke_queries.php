<?php
declare(strict_types=1);

/**
 * Smoke test for every query in the data layer.
 *
 * Calls each public function of data.php against whichever database
 * config/config.php points at, and reports what came back. It does not check
 * the figures: that is what the validation case is for. It checks that every
 * statement is valid for the server it is aimed at, which is the thing that
 * breaks when the database underneath changes.
 *
 * It is worth running against an empty database as well as a full one. Empty,
 * it still proves every statement parses and executes, which is most of what
 * a port can get wrong.
 *
 *   php tests/smoke_queries.php           read-only
 *   php tests/smoke_queries.php --write   also files one field report
 *
 * The write is opt-in because this test is meant to be safe to run against
 * the live site, and filing a report there is not.
 *
 * Exits 1 if any query failed, so it can be used unattended.
 */

require dirname(__DIR__) . '/app/includes/data.php';

// Taken from the data rather than hardcoded, so the archived-window queries
// are exercised on a database that has windows and skipped on one that does
// not. A failure here is reported by the dm_windows check below like any
// other.
$window = null;
try {
    $windows = dm_windows();
    if ($windows !== []) {
        $window = $windows[0]['window_end'];
    }
} catch (Throwable $e) {
    $window = null;
}

// Likewise the territory: any id that exists, so dm_history and dm_hotspots
// are asked something answerable instead of about a territory that is absent.
$territory = 1;
try {
    $points = dm_map_points(false);
    if ($points !== []) {
        $territory = (int) $points[0]['territory_id'];
    }
} catch (Throwable $e) {
    $territory = 1;
}

$checks = [
    'dm_windows'              => static fn() => dm_windows(),
    'dm_summary (actual)'     => static fn() => [dm_summary()],
    'dm_flagged (actual)'     => static fn() => dm_flagged(),
    'dm_losing (actual)'      => static fn() => dm_losing(),
    'dm_map_points (todos)'   => static fn() => dm_map_points(false),
    'dm_map_points (flagged)' => static fn() => dm_map_points(true),
    'dm_territory'            => static fn() => array_filter([dm_territory($territory)]),
    'dm_history'              => static fn() => dm_history($territory),
    'dm_hotspots'             => static fn() => dm_hotspots($territory),
    'dm_reports'              => static fn() => dm_reports($territory),
    'dm_frequent'             => static fn() => dm_frequent(),
    'dm_rising'               => static fn() => dm_rising(),
];

if ($window !== null) {
    $checks['dm_summary (archivo)']     = static fn() => [dm_summary($window)];
    $checks['dm_flagged (archivo)']     = static fn() => dm_flagged($window);
    $checks['dm_losing (archivo)']      = static fn() => dm_losing($window);
    $checks['dm_map_points (archivo)']  = static fn() => dm_map_points(false, $window);
    $checks['dm_territory (archivo)']   = static fn() => array_filter([dm_territory($territory, $window)]);
}

if (in_array('--write', $argv, true)) {
    // The one write in the site, and the only query whose MySQL version lost a
    // guarantee the PostgreSQL one had: the advisory lock is no longer
    // released by the transaction. Worth exercising rather than assuming.
    $checks['dm_submit_report'] = static function () use ($territory) {
        $result = dm_submit_report(
            $territory,
            'unsure',
            'smoke test, borrable',
            null,
            '203.0.113.9'
        );
        if (!in_array($result, ['ok', 'rate_limited', 'not_found'], true)) {
            throw new RuntimeException("devolvio un estado inesperado: '$result'");
        }
        return [$result];
    };
}

printf("territorio %d, ventana %s\n\n", $territory, $window ?? 'ninguna');

$failed = 0;
foreach ($checks as $name => $run) {
    try {
        $rows = $run();
        printf("  %-26s OK     %d\n", $name, count($rows));
    } catch (Throwable $e) {
        $failed++;
        printf("  %-26s FALLO  %s\n", $name, $e->getMessage());
    }
}

printf("\n%d de %d consultas fallaron\n", $failed, count($checks));

if (!in_array('--write', $argv, true)) {
    print "dm_submit_report no se probo: agregar --write\n";
}

exit($failed === 0 ? 0 : 1);
