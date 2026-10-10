<?php
declare(strict_types=1);

/**
 * data.php — the only file in this site that knows where the data comes from.
 *
 * Everything else (api.php and the four pages) calls the functions at the top
 * of this file and never touches SQL or the shape of the database.
 *
 * ONE BACKEND
 * -----------
 * PostgreSQL over PDO, and nothing else. NEON_URL must be set:
 *
 *   export NEON_URL='postgresql://user:password@host.neon.tech/dbname?sslmode=require'
 *
 * Without it there is no fallback and no second set of numbers — the site says
 * it is not configured and stops, which is dm_require_neon_url() below. There
 * was once a JSON sample backend here for running the pages without a
 * database; it is gone, and with it the risk of a page that looks live and is
 * not.
 *
 * The credentials are never written down here, and this file does not read
 * .env: NEON_URL has to be in the process environment.
 *
 * A NOTE ON `area_ha`
 * -------------------
 * The pipeline uses `area_ha` for two different things: hectares LOST in the
 * window (in `rankings`) and the SIZE of the territory (in `territories`).
 * That collision stops at this file. Everything above it sees `lost_ha` and
 * `territory_ha`, which cannot be confused with one another.
 *
 * MISSING VALUES
 * --------------
 * RAISG did not record population or community counts for many territories,
 * and those gaps arrive as either NULL or 0. Both become NULL here, so the
 * pages can say "no data" instead of claiming a territory has no inhabitants.
 *
 * This file is read-only by design, with one exception. The only write in the
 * site is dm_submit_report(), which INSERTs a field report into
 * `field_reports` — the one table the web role may write to. There is no
 * UPDATE or DELETE anywhere, nothing writes to a table the pipeline owns, and
 * nothing here recalculates a published number.
 */


/* ==========================================================================
   PUBLIC API — what the site can ask for, and the one thing it can write

   TWO RANKING TABLES, NEVER ONE QUERY
   -----------------------------------
   `rankings` holds the window being evaluated now, and the daily pipeline
   rewrites it in place. `rankings_history` holds the ranking recalculated for
   every window in the series and is filled separately. They carry the same
   columns and they are not the same data: one is current and volatile, the
   other is settled and historical.

   Every function below that takes $window answers from ONE of them — null
   picks `rankings`, a date picks `rankings_history` — chosen in dm_pg_scope()
   and never combined in a single statement. There is no UNION and no join
   between the two anywhere in this file.

   $window is always a 'YYYY-MM-DD' window_end, and it reaches SQL only as a
   bound parameter; the table name is chosen by a branch, never interpolated.
   ========================================================================== */

/**
 * The windows that can be asked for, newest first.
 * -> [['window_end' => string, 'flagged_count' => int,
 *      'baseline_settled' => bool, 'weeks_of_history' => int|null], ...]
 */
function dm_windows(): array
{
    return dm_pg_windows();
}

/**
 * Headline figures for a seven-day window.
 * -> ['flagged_count' => int, 'total_lost_ha' => float,
 *     'window_start' => string|null, 'window_end' => string|null,
 *     'territories_with_loss' => int]
 *
 * @param string|null $window window_end as 'YYYY-MM-DD'; null for the current
 *                            window, which is the only one `rankings` holds.
 */
function dm_summary(?string $window = null): array
{
    return dm_pg_summary($window);
}

/**
 * The flagged territories, highest `score` first. Typically 10 to 20 rows —
 * but in a window early enough that the baselines had not settled it can be
 * several hundred, which is what `baseline_settled` exists to warn about.
 */
function dm_flagged(?string $window = null): array
{
    return dm_pg_flagged($window);
}

/**
 * Every territory that lost any forest in the window, most hectares first.
 * Over two thousand rows in a typical week.
 *
 * Returns a narrower row than dm_flagged(): only what the long list actually
 * prints. At this row count the columns nobody reads — the window timestamps
 * repeated on every row above all — are most of the payload.
 */
function dm_losing(?string $window = null): array
{
    return dm_pg_losing($window);
}

/**
 * Territories as map points, in one payload — never one request per territory.
 * Territories with no loss this week carry lost_ha = 0.
 *
 * Carries `ratio` as well as the loss, so the map page can build both the map
 * and its list from this alone.
 *
 * @param bool $flagged_only True for just the flagged ones. The home page draws
 *                           only those, and has no reason to download 3,866
 *                           rows to show 35 of them.
 */
function dm_map_points(bool $flagged_only = false, ?string $window = null): array
{
    return dm_pg_map_points($flagged_only, $window);
}

/**
 * One territory, with its ranking row for the window attached (or null if the
 * pipeline has no row for it in that window).
 */
function dm_territory(int $territory_id, ?string $window = null): ?array
{
    return dm_pg_territory($territory_id, $window);
}

/**
 * Weekly history for one territory, oldest week first.
 * Zeros in the data are real zeros — weeks with no loss — and are kept.
 *
 * Each week also carries the seasonal baseline and ratio it was screened
 * with, from rankings_history; weeks the archive does not hold (no loss, or
 * not archived yet) carry null and `screened` false.
 */
function dm_history(int $territory_id): array
{
    return dm_pg_history($territory_id);
}

/**
 * Where inside one territory the current window's loss happened: the
 * pipeline's clusters of alert pixels, largest first. Up to ten rows, and
 * none at all for a territory that is not flagged this window.
 *
 * `share_pct` is the hotspot's share of the territory's alert pixels in the
 * window, and `lost_ha` is its pixel count at 0.01 ha each — so the hotspots
 * need not add up to the window's `lost_ha`, which GFW measures by area.
 *
 * -> [['rank' => int, 'lat' => float, 'lon' => float, 'alerts' => int,
 *      'lost_ha' => float, 'share_pct' => float, 'window_end' => string], ...]
 */
function dm_hotspots(int $territory_id): array
{
    return dm_pg_hotspots($territory_id);
}

/* What someone who went to look can say they found. The database accepts
   exactly these four, so the list lives here once and report.php validates
   against it rather than against a copy. */
const DM_VERDICTS = ['confirmed', 'not_found', 'other_cause', 'unsure'];

const DM_REPORT_NOTE_MAX     = 2000;  // characters, not bytes
const DM_REPORT_REPORTER_MAX = 200;
const DM_REPORTS_PER_HOUR    = 5;     // per source IP

/**
 * Field reports filed for one territory, newest first. Fifty at most.
 *
 * Never the reporter's name or the IP: those are stored for follow-up and for
 * the rate limit, and neither is published.
 *
 * -> [['verdict' => string, 'note' => string|null,
 *      'submitted_at' => string], ...]
 */
function dm_reports(int $territory_id): array
{   
    return dm_pg_reports($territory_id);
}

/**
 * Record one field report, against the window being evaluated now.
 *
 * Arguments arrive already validated by report.php — verdict in DM_VERDICTS,
 * lengths within the limits, empty strings turned into null.
 *
 * @return string 'ok', 'rate_limited' (this IP has filed DM_REPORTS_PER_HOUR
 *                in the last hour) or 'not_found' (no such territory).
 */
function dm_submit_report(
    int $territory_id,
    string $verdict,
    ?string $note,
    ?string $reporter,
    string $source_ip
): string {
    return dm_pg_submit_report($territory_id, $verdict, $note, $reporter, $source_ip);
}

/**
 * The territories flagged most often across the whole series, most weeks
 * first. Twenty rows at most.
 *
 * Only settled weeks are counted: in the early windows the baselines rested
 * on a handful of weeks and flagged hundreds of territories at a time, so a
 * count that included them would mostly be counting that.
 *
 * -> [['territory_id' => int, 'name' => string|null, 'country' => string|null,
 *      'times_flagged' => int, 'total_lost_ha' => float], ...]
 */
function dm_frequent(): array
{
    return dm_pg_frequent();
}

/**
 * Territories whose anomaly ratio has been climbing week on week without
 * yet reaching the spike that flags one, steepest first. Twenty rows at most.
 *
 * The pipeline fits the line and decides who qualifies (a positive slope and
 * a fit good enough that one spike does not pass as a trend); this only
 * reads the result. `slope` is in the ratio's own units per week: 0.6 means
 * the multiple rose by about 0.6× each week over the weeks fitted.
 *
 * -> [['territory_id' => int, 'name' => string|null, 'country' => string|null,
 *      'slope' => float, 'r2' => float|null], ...]
 */
function dm_rising(): array
{
    return dm_pg_rising();
}


/* ==========================================================================
   CONFIGURATION
   ========================================================================== */

/**
 * Thrown when NEON_URL is absent. Its own class, not a bare RuntimeException,
 * because api.php has to tell this apart from a database that was reachable
 * and failed: one is a deployment that was never finished and the other is an
 * outage, and answering "the database did not respond" to the first sends
 * whoever reads it to look at the wrong thing.
 */
class DmNotConfigured extends RuntimeException
{
}

function dm_neon_url(): ?string
{
    $url = getenv('NEON_URL');
    if ($url === false || $url === '') {
        $url = $_ENV['NEON_URL'] ?? $_SERVER['NEON_URL'] ?? null;
    }
    return ($url === null || $url === '') ? null : (string) $url;
}

function dm_is_configured(): bool
{
    return dm_neon_url() !== null;
}

/** The connection URL, or stop with a message that names what is missing. */
function dm_require_neon_url(): string
{
    $url = dm_neon_url();
    if ($url === null) {
        throw new DmNotConfigured(
            'NEON_URL is not set. This site reads its figures from Postgres and '
            . 'has no other source; set NEON_URL in the environment and reload.'
        );
    }
    return $url;
}


/* ==========================================================================
   POSTGRES BACKEND (Neon, via PDO)
   ========================================================================== */

function dm_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $url = dm_require_neon_url();

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'])) {
        throw new RuntimeException('NEON_URL is not a valid connection URL.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
        $parts['host'],
        $parts['port'] ?? 5432,
        ltrim($parts['path'] ?? '', '/')
    );

    // Neon requires SSL; sslmode is pinned above rather than read from the
    // query string so a copy-pasted URL cannot silently downgrade it.
    $pdo = new PDO(
        $dsn,
        isset($parts['user']) ? rawurldecode($parts['user']) : '',
        isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    return $pdo;
}

function dm_pg_query(string $sql, array $params = []): array
{
    $stmt = dm_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Columns of `rankings` that the site uses, with the area_ha collision renamed away. */
const DM_RANKING_COLUMNS = '
    r.territory_id,
    r.name,
    r.country,
    r.area_ha        AS lost_ha,
    r.territory_ha,
    r.lost_per_1000ha,
    r.seasonal_baseline,
    r.ratio,
    r.score,
    r.flagged,
    r.method,
    r.active_weeks,
    r.population,
    r.window_start,
    r.window_end
';

/**
 * Which ranking table answers this request, and what pins it to one window.
 *
 * The one place the choice is made. `rankings` carries a single window and no
 * window column worth filtering on; `rankings_history` carries all of them and
 * has to be narrowed to one. A caller gets a table name and a clause and joins
 * them into its own statement — so every query reads exactly one of the two
 * tables, which is the property that must hold.
 *
 * @return array{0:string,1:string,2:array} table, extra AND-clause, params
 */
function dm_pg_scope(?string $window, string $alias = 'r'): array
{
    if ($window === null) {
        return ['rankings', '', []];
    }

    // ::date because window_end is a timestamp: comparing it to '2026-08-31'
    // as a timestamp would match only midnight exactly.
    return [
        'rankings_history',
        ' AND ' . $alias . '.window_end::date = :window',
        [':window' => $window],
    ];
}

/**
 * Every window in rankings_history, newest first.
 *
 * baseline_settled is stored per row. A window counts as settled only when no
 * territory in it is unsettled — the conservative reading, and the one that
 * matches what the warning is for. If it turns out to vary territory by
 * territory in a settled window, this is the line to revisit; the raw counts
 * come back too, so the disagreement would be visible rather than silent.
 */
function dm_pg_windows(): array
{
    $rows = dm_pg_query('
        SELECT
            window_end::date                              AS window_end,
            MIN(window_start)::date                       AS window_start,
            COUNT(*) FILTER (WHERE flagged)               AS flagged_count,
            COUNT(*) FILTER (WHERE NOT baseline_settled)  AS unsettled_count,
            COUNT(*)                                      AS territory_count,
            MIN(weeks_of_history)                         AS weeks_of_history
        FROM rankings_history
        GROUP BY window_end::date
        ORDER BY window_end::date DESC
    ');

    return array_map('dm_shape_window', $rows);
}

function dm_pg_summary(?string $window = null): array
{
    [$table, $clause, $params] = dm_pg_scope($window, 'r');

    $rows = dm_pg_query('
        SELECT
            COUNT(*) FILTER (WHERE r.flagged)      AS flagged_count,
            COUNT(*) FILTER (WHERE r.area_ha > 0)  AS territories_with_loss,
            COALESCE(SUM(r.area_ha), 0)            AS total_lost_ha,
            MIN(r.window_start)                    AS window_start,
            MAX(r.window_end)                      AS window_end
        FROM ' . $table . ' r
        WHERE TRUE' . $clause . '
    ', $params);

    // The single worst-hit flagged territory, for the third headline figure.
    // Computed in SQL rather than in the browser: the home page never
    // downloads the whole ranking, so it could not find this row itself.
    $worst = dm_pg_query('
        SELECT r.territory_id, r.name, r.area_ha, r.ratio
        FROM ' . $table . ' r
        WHERE r.flagged' . $clause . '
        ORDER BY r.area_ha DESC
        LIMIT 1
    ', $params);

    $summary = $rows[0] ?? [];
    if ($worst !== []) {
        $summary['worst_flagged_id']   = $worst[0]['territory_id'];
        $summary['worst_flagged_name'] = $worst[0]['name'];
        $summary['worst_flagged_ha']    = $worst[0]['area_ha'];
        $summary['worst_flagged_ratio'] = $worst[0]['ratio'];
    }

    return dm_shape_summary($summary);
}

function dm_pg_flagged(?string $window = null): array
{
    [$table, $clause, $params] = dm_pg_scope($window, 'r');

    $rows = dm_pg_query('
        SELECT ' . DM_RANKING_COLUMNS . '
        FROM ' . $table . ' r
        WHERE r.flagged' . $clause . '
        ORDER BY r.score DESC NULLS LAST
    ', $params);

    return array_map('dm_shape_ranking', $rows);
}

function dm_pg_losing(?string $window = null): array
{
    [$table, $clause, $params] = dm_pg_scope($window, 'r');

    $rows = dm_pg_query('
        SELECT
            r.territory_id,
            r.name,
            r.country,
            r.area_ha AS lost_ha,
            r.lost_per_1000ha,
            r.ratio,
            r.flagged
        FROM ' . $table . ' r
        WHERE r.area_ha > 0' . $clause . '
        ORDER BY r.area_ha DESC
    ', $params);

    return array_map('dm_shape_ranking_brief', $rows);
}

function dm_pg_map_points(bool $flagged_only = false, ?string $window = null): array
{
    [$table, $clause, $params] = dm_pg_scope($window, 'r');

    // One query for every point. LEFT JOIN because a territory may legitimately
    // have no ranking row in a window — `rankings` is rewritten daily, and a
    // historical window only holds the territories evaluated in it.
    //
    // The window clause belongs in the JOIN and not in the WHERE: in the WHERE
    // it would discard the rows the LEFT JOIN just filled with NULL, and the
    // map would lose every quiet territory.
    $rows = dm_pg_query('
        SELECT
            t.territory_id,
            t.name,
            t.country,
            t.lat,
            t.lon,
            COALESCE(r.area_ha, 0)     AS lost_ha,
            COALESCE(r.flagged, false) AS flagged,
            r.ratio
        FROM territories t
        LEFT JOIN ' . $table . ' r
               ON r.territory_id = t.territory_id' . $clause . '
        WHERE t.lat IS NOT NULL
          AND t.lon IS NOT NULL
          ' . ($flagged_only ? 'AND r.flagged' : '') . '
    ', $params);

    return array_map('dm_shape_point', $rows);
}

function dm_pg_territory(int $territory_id, ?string $window = null): ?array
{
    [$table, $clause, $params] = dm_pg_scope($window, 'r');
    $params[':id'] = $territory_id;

    // Same reason as the map: the window clause goes in the JOIN, or a
    // territory absent from that window stops being found at all.
    $rows = dm_pg_query('
        SELECT
            t.territory_id,
            t.name,
            t.country,
            t.area_ha AS territory_ha,
            t.population,
            t.communities,
            t.lat,
            t.lon,
            r.area_ha AS lost_ha,
            r.lost_per_1000ha,
            r.seasonal_baseline,
            r.ratio,
            r.score,
            r.flagged,
            r.method,
            r.active_weeks,
            r.window_start,
            r.window_end
        FROM territories t
        LEFT JOIN ' . $table . ' r
               ON r.territory_id = t.territory_id' . $clause . '
        WHERE t.territory_id = :id
    ', $params);

    if ($rows === []) {
        return null;
    }

    return dm_shape_territory($rows[0]);
}

function dm_pg_history(int $territory_id): array
{
    // Always filtered by territory_id — alerts_weekly holds about 843,000 rows
    // (October 2026), gains a row per territory every week, and is never
    // queried unfiltered.
    //
    // DISTINCT ON because nothing in the table guarantees one row per week.
    // `week` is a timestamp rather than a date, and the pipeline rewrites this
    // table daily, so a territory can end up carrying two rows for the same
    // Monday — a second write of the same week stamped at a different time of
    // day. Ordered by week they arrive as two neighbouring rows the chart drew
    // as two bars of the same height under the same label: one week, twice.
    //
    // The later stamp wins rather than the sum: lost_ha is that week's total,
    // not a daily increment, so adding two writes of it would double-count,
    // and the newest write is the most complete.
    //
    // Each week carries its own seasonal baseline from rankings_history, so
    // the chart can draw the band each week was actually judged against
    // instead of this week's level drawn flat across all of them. The archive
    // stores calendar weeks keyed by their Sunday, and alerts_weekly keys the
    // same weeks by their Monday, so a week matches the archive row whose
    // window_end falls on its seventh day. A range rather than `::date =`, so
    // a time of day on either stamp cannot break the match and the
    // (window_end, territory_id) key can still be used to find the row.
    //
    // LEFT JOIN, because the archive keeps only weeks with recorded loss: a
    // week with none, or one not archived yet, comes back with `screened`
    // false and no baseline, which the chart handles rather than guesses at.
    $rows = dm_pg_query('
        SELECT DISTINCT ON (w.week::date)
               w.week, w.lost_ha, w.lost_per_1000ha,
               h.seasonal_baseline,
               h.ratio,
               (h.territory_id IS NOT NULL) AS screened
        FROM alerts_weekly w
        LEFT JOIN rankings_history h
               ON h.territory_id = w.territory_id
              AND h.window_end >= w.week + INTERVAL \'6 days\'
              AND h.window_end <  w.week + INTERVAL \'7 days\'
        WHERE w.territory_id = :id
        ORDER BY w.week::date ASC, w.week DESC
    ', [':id' => $territory_id]);

    return array_map('dm_shape_week', $rows);
}

function dm_pg_hotspots(int $territory_id): array
{
    // Joined to `rankings` on the window so the hotspots can only ever be the
    // current window's. The pipeline truncates and refills `hotspots` on its
    // own schedule; if `rankings` has moved on to a new week since, the rows
    // left over describe a week this page no longer shows, and none come back
    // rather than last week's under this week's figures.
    //
    // ::date on both sides because both are timestamps written by separate
    // runs, and a time-of-day difference must not decide the match.
    $rows = dm_pg_query('
        SELECT h.rank, h.lat, h.lon, h.alerts, h.lost_ha, h.share_pct,
               h.window_end
        FROM hotspots h
        JOIN rankings r
          ON r.territory_id = h.territory_id
         AND r.window_end::date = h.window_end::date
        WHERE h.territory_id = :id
        ORDER BY h.rank ASC
    ', [':id' => $territory_id]);

    return array_map('dm_shape_hotspot', $rows);
}

function dm_pg_reports(int $territory_id): array
{
    // The columns that are published and nothing else: `reporter` and
    // `source_ip` stay in the table.
    $rows = dm_pg_query('
        SELECT verdict, note, submitted_at
        FROM field_reports
        WHERE territory_id = :id AND approved
        ORDER BY submitted_at DESC
        LIMIT 50
    ', [':id' => $territory_id]);

    return array_map('dm_shape_report', $rows);
}

function dm_pg_submit_report(
    int $territory_id,
    string $verdict,
    ?string $note,
    ?string $reporter,
    string $source_ip
): string {
    $pdo = dm_pdo();
    $pdo->beginTransaction();

    try {
        // The count and the insert as one step per IP. Without the lock, six
        // requests arriving together would all count four and all insert.
        // Transaction-scoped, so it is released by the COMMIT or ROLLBACK
        // below and cannot be left held.
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:ip))')
            ->execute([':ip' => $source_ip]);

        // Checked here rather than left to the foreign key, so an unknown id
        // is a 404 the page can explain rather than a constraint error that
        // reads like an outage.
        $exists = $pdo->prepare('SELECT 1 FROM territories WHERE territory_id = :id');
        $exists->execute([':id' => $territory_id]);
        if ($exists->fetchColumn() === false) {
            $pdo->rollBack();
            return 'not_found';
        }

        $recent = $pdo->prepare('
            SELECT COUNT(*)
            FROM field_reports
            WHERE source_ip = :ip
              AND submitted_at > NOW() - INTERVAL \'1 hour\'
        ');
        $recent->execute([':ip' => $source_ip]);
        if ((int) $recent->fetchColumn() >= DM_REPORTS_PER_HOUR) {
            $pdo->rollBack();
            return 'rate_limited';
        }

        // window_end is the window being evaluated now — the one the page
        // was showing — taken from `rankings` rather than from the form, so a
        // report cannot be filed against a week the page never showed. MAX
        // because every row of `rankings` carries the same window.
        $pdo->prepare('
            INSERT INTO field_reports
                (territory_id, window_end, verdict, note, reporter, submitted_at, source_ip)
            VALUES
                (:id, (SELECT MAX(window_end) FROM rankings), :verdict, :note, :reporter, NOW(), :ip)
        ')->execute([
            ':id'       => $territory_id,
            ':verdict'  => $verdict,
            ':note'     => $note,
            ':reporter' => $reporter,
            ':ip'       => $source_ip,
        ]);

        $pdo->commit();
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function dm_pg_frequent(): array
{
    // rankings_history only, and every window of it at once — the one query
    // in this file that reads the series across windows rather than one.
    //
    // baseline_settled is a property of the window, not of the territory:
    // the pipeline computes one value per window and writes it to every row
    // in it. So this drops whole early windows, the same ones for every
    // territory, rather than each territory's own first weeks. total_lost_ha
    // sums every settled week, flagged or not — it is what the territory lost
    // over the period, not only in the weeks it was flagged.
    $rows = dm_pg_query('
        SELECT
            territory_id,
            name,
            country,
            COUNT(*) FILTER (WHERE flagged) AS times_flagged,
            SUM(area_ha)                    AS total_lost_ha
        FROM rankings_history
        WHERE baseline_settled
        GROUP BY territory_id, name, country
        HAVING COUNT(*) FILTER (WHERE flagged) > 0
        ORDER BY times_flagged DESC, total_lost_ha DESC
        LIMIT 20
    ');

    return array_map('dm_shape_frequent', $rows);
}

function dm_pg_rising(): array
{
    // `trends` is the pipeline's own table: one row per territory that
    // qualified, already fitted and already filtered. It holds the current
    // window only, so there is nothing to scope here — and it is read on its
    // own, never joined to either ranking table.
    //
    // NOT flagged_now rather than a join to `rankings`: a territory flagged
    // this week is on the ranking already, and this list is the ones that
    // are not there yet.
    $rows = dm_pg_query('
        SELECT territory_id, name, country, slope, r2
        FROM trends
        WHERE NOT flagged_now
        ORDER BY slope DESC
        LIMIT 20
    ');

    return array_map('dm_shape_rising', $rows);
}


/* ==========================================================================
   SHAPING — one canonical row shape

   PDO hands back strings for numeric columns, so every value is cast here.
   Without this the JSON payload would carry "0.6144" as text and the number
   formatting in the browser would quietly fall apart.
   ========================================================================== */

function dm_float($v): ?float
{
    return ($v === null || $v === '') ? null : (float) $v;
}

function dm_int($v): ?int
{
    return ($v === null || $v === '') ? null : (int) $v;
}

function dm_bool($v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    return in_array($v, ['t', 'true', '1', 1, 1.0], true);
}

function dm_text($v): ?string
{
    return ($v === null || $v === '') ? null : (string) $v;
}

/**
 * RAISG gaps arrive as NULL or as 0. Both mean "not recorded", so both become
 * NULL and the pages render "no data" rather than a misleading zero.
 */
function dm_unrecorded($v): ?float
{
    $n = dm_float($v);
    return ($n === null || $n == 0.0) ? null : $n;
}

function dm_shape_summary(array $r): array
{
    return [
        'flagged_count'         => dm_int($r['flagged_count'] ?? 0) ?? 0,
        'territories_with_loss' => dm_int($r['territories_with_loss'] ?? 0) ?? 0,
        'total_lost_ha'         => dm_float($r['total_lost_ha'] ?? 0) ?? 0.0,
        'window_start'          => dm_text($r['window_start'] ?? null),
        'window_end'            => dm_text($r['window_end'] ?? null),

        // The flagged territory that lost the most hectares — and, more to the
        // point of this site, how far above its own baseline that was. Null in
        // the (real) case where nothing was flagged this week.
        'worst_flagged_id'      => dm_int($r['worst_flagged_id'] ?? null),
        'worst_flagged_name'    => dm_text($r['worst_flagged_name'] ?? null),
        'worst_flagged_ha'      => dm_float($r['worst_flagged_ha'] ?? null),
        'worst_flagged_ratio'   => dm_float($r['worst_flagged_ratio'] ?? null),
    ];
}

/**
 * One selectable window.
 *
 * `baseline_settled` is a conclusion, not a column: a window is settled when
 * no territory in it is unsettled. The counts it was drawn from travel with
 * it, so a window whose rows disagree can be seen rather than guessed at.
 */
function dm_shape_window(array $r): array
{
    $unsettled = dm_int($r['unsettled_count'] ?? 0) ?? 0;

    return [
        'window_end'       => dm_text($r['window_end'] ?? null),
        'window_start'     => dm_text($r['window_start'] ?? null),
        'flagged_count'    => dm_int($r['flagged_count'] ?? 0) ?? 0,
        'baseline_settled' => $unsettled === 0,
        'unsettled_count'  => $unsettled,
        'territory_count'  => dm_int($r['territory_count'] ?? 0) ?? 0,
        'weeks_of_history' => dm_int($r['weeks_of_history'] ?? null),
    ];
}

/** A row of `rankings` as the site sees it. */
function dm_shape_ranking(array $r): array
{
    return [
        'territory_id'      => dm_int($r['territory_id']),
        'name'              => dm_text($r['name'] ?? null),
        'country'           => dm_text($r['country'] ?? null),
        'lost_ha'           => dm_float($r['lost_ha'] ?? null),
        'territory_ha'      => dm_float($r['territory_ha'] ?? null),
        'lost_per_1000ha'   => dm_float($r['lost_per_1000ha'] ?? null),
        'seasonal_baseline' => dm_float($r['seasonal_baseline'] ?? null),
        'ratio'             => dm_float($r['ratio'] ?? null),
        'score'             => dm_float($r['score'] ?? null),
        'flagged'           => dm_bool($r['flagged'] ?? false),
        'method'            => dm_text($r['method'] ?? null),
        'active_weeks'      => dm_int($r['active_weeks'] ?? null),
        'population'        => dm_unrecorded($r['population'] ?? null),
        'window_start'      => dm_text($r['window_start'] ?? null),
        'window_end'        => dm_text($r['window_end'] ?? null),
    ];
}

/**
 * The long list's row: only the columns it prints. Two thousand rows of the
 * full shape is most of a megabyte, and the bulk of it is the same window
 * timestamps repeated on every row.
 */
function dm_shape_ranking_brief(array $r): array
{
    return [
        'territory_id'    => dm_int($r['territory_id']),
        'name'            => dm_text($r['name'] ?? null),
        'country'         => dm_text($r['country'] ?? null),
        'lost_ha'         => dm_float($r['lost_ha'] ?? null),
        'lost_per_1000ha' => dm_float($r['lost_per_1000ha'] ?? null),
        'ratio'           => dm_float($r['ratio'] ?? null),
        'flagged'         => dm_bool($r['flagged'] ?? false),
    ];
}

function dm_shape_point(array $r): array
{
    return [
        'territory_id' => dm_int($r['territory_id']),
        'name'         => dm_text($r['name'] ?? null),
        'country'      => dm_text($r['country'] ?? null),
        'lat'          => dm_float($r['lat'] ?? null),
        'lon'          => dm_float($r['lon'] ?? null),
        'lost_ha'      => dm_float($r['lost_ha'] ?? 0) ?? 0.0,
        'flagged'      => dm_bool($r['flagged'] ?? false),

        // Carried so the map page can drive its list from this one payload
        // instead of fetching the rankings a second time for the ratio alone.
        'ratio'        => dm_float($r['ratio'] ?? null),
    ];
}

function dm_shape_territory(array $r): array
{
    $hasRanking = ($r['lost_ha'] ?? null) !== null;

    return [
        'territory_id' => dm_int($r['territory_id']),
        'name'         => dm_text($r['name'] ?? null),
        'country'      => dm_text($r['country'] ?? null),
        'territory_ha' => dm_float($r['territory_ha'] ?? null),
        'population'   => dm_unrecorded($r['population'] ?? null),
        'communities'  => dm_unrecorded($r['communities'] ?? null),
        'lat'          => dm_float($r['lat'] ?? null),
        'lon'          => dm_float($r['lon'] ?? null),

        // Null when the pipeline has no row for this territory in the current
        // window. The page must handle that rather than assume it is present.
        'current'      => $hasRanking ? [
            'lost_ha'           => dm_float($r['lost_ha']),
            'lost_per_1000ha'   => dm_float($r['lost_per_1000ha'] ?? null),
            'seasonal_baseline' => dm_float($r['seasonal_baseline'] ?? null),
            'ratio'             => dm_float($r['ratio'] ?? null),
            'score'             => dm_float($r['score'] ?? null),
            'flagged'           => dm_bool($r['flagged'] ?? false),
            'method'            => dm_text($r['method'] ?? null),
            'active_weeks'      => dm_int($r['active_weeks'] ?? null),
            'window_start'      => dm_text($r['window_start'] ?? null),
            'window_end'        => dm_text($r['window_end'] ?? null),
        ] : null,
    ];
}

/**
 * One week of a territory's series. `seasonal_baseline` (per 1,000 ha, as
 * stored) and `ratio` are that week's own, from rankings_history, and null
 * when `screened` is false — the archive has no row for the week.
 */
function dm_shape_week(array $r): array
{
    return [
        'week'              => dm_text($r['week'] ?? null),
        'lost_ha'           => dm_float($r['lost_ha'] ?? 0) ?? 0.0,
        'lost_per_1000ha'   => dm_float($r['lost_per_1000ha'] ?? 0) ?? 0.0,
        'seasonal_baseline' => dm_float($r['seasonal_baseline'] ?? null),
        'ratio'             => dm_float($r['ratio'] ?? null),
        'screened'          => dm_bool($r['screened'] ?? false),
    ];
}

function dm_shape_hotspot(array $r): array
{
    return [
        'rank'       => dm_int($r['rank']),
        'lat'        => dm_float($r['lat']),
        'lon'        => dm_float($r['lon']),
        'alerts'     => dm_int($r['alerts'] ?? 0) ?? 0,
        'lost_ha'    => dm_float($r['lost_ha'] ?? 0) ?? 0.0,
        'share_pct'  => dm_float($r['share_pct'] ?? null),
        'window_end' => dm_text($r['window_end'] ?? null),
    ];
}

function dm_shape_report(array $r): array
{
    return [
        'verdict'      => dm_text($r['verdict'] ?? null),
        'note'         => dm_text($r['note'] ?? null),
        'submitted_at' => dm_text($r['submitted_at'] ?? null),
    ];
}

/** One territory's tally across the settled weeks. `total_lost_ha` is hectares lost, summed. */
function dm_shape_frequent(array $r): array
{
    return [
        'territory_id'  => dm_int($r['territory_id']),
        'name'          => dm_text($r['name'] ?? null),
        'country'       => dm_text($r['country'] ?? null),
        'times_flagged' => dm_int($r['times_flagged'] ?? 0) ?? 0,
        'total_lost_ha' => dm_float($r['total_lost_ha'] ?? 0) ?? 0.0,
    ];
}

/** One territory's fitted trend. `slope` is anomaly-ratio units per week. */
function dm_shape_rising(array $r): array
{
    return [
        'territory_id' => dm_int($r['territory_id']),
        'name'         => dm_text($r['name'] ?? null),
        'country'      => dm_text($r['country'] ?? null),
        'slope'        => dm_float($r['slope'] ?? 0) ?? 0.0,
        'r2'           => dm_float($r['r2'] ?? null),
    ];
}
