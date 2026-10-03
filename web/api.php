<?php
declare(strict_types=1);

/**
 * api.php — HTTP in front of data.php. Contains no SQL and no knowledge of
 * where the numbers come from; it maps a query string to one of the read
 * functions in data.php and returns JSON. It only reads: the one write in the
 * site, a field report, goes through report.php.
 *
 *   api.php?r=windows                 every window that can be asked for
 *   api.php?r=summary
 *   api.php?r=flagged
 *   api.php?r=losing
 *   api.php?r=points                  all 3,866 map points in one response
 *   api.php?r=points&only=flagged     just the flagged ones
 *   api.php?r=territory&id=3841
 *   api.php?r=history&id=3841
 *   api.php?r=hotspots&id=3841        where inside it the loss happened
 *   api.php?r=reports&id=3841         what people found when they went to look
 *   api.php?r=frequent                the territories flagged most often
 *   api.php?r=rising                  climbing, but not flagged this week
 *
 * Every resource except `windows`, `history`, `hotspots`, `reports`,
 * `frequent` and `rising` accepts &w=YYYY-MM-DD, the window_end to answer for. Without it
 * the answer comes from `rankings`, the window being evaluated now. With it,
 * from `rankings_history`.
 *
 * `history` takes no window: a territory's weekly loss series is the same
 * series whichever window you are looking at from. `frequent` takes none
 * either: it is a count across every settled window there is. `rising` is
 * fitted by the pipeline for the current window only, and so is `hotspots`.
 */

require __DIR__ . '/data.php';

header('Content-Type: application/json; charset=utf-8');

// The pipeline rewrites `rankings` daily and rows do not persist between
// refreshes, so nothing here may be cached across a rewrite.
header('Cache-Control: no-store');

$resource = isset($_GET['r']) ? (string) $_GET['r'] : '';

/**
 * Read the requested window, or fail with 400.
 *
 * Validated here rather than trusted downstream. It is bound as a parameter
 * and never interpolated, so this is not what stops injection — it is what
 * stops a typo being answered with the wrong week's numbers under the date
 * that was asked for.
 */
function dm_window_param(): ?string
{
    $raw = $_GET['w'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        dm_fail(400, 'bad_request', 'w must be a window_end date, as YYYY-MM-DD.');
    }
    return $raw;
}

/** Read a positive integer id, or fail with 400. */
function dm_require_id(): int
{
    $raw = $_GET['id'] ?? '';
    if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) {
        dm_fail(400, 'bad_request', 'A numeric territory id is required.');
    }
    return (int) $raw;
}

function dm_fail(int $status, string $code, string $message)
{
    http_response_code($status);
    echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $window = dm_window_param();

    switch ($resource) {
        case 'windows':
            $payload = dm_windows();
            break;

        case 'summary':
            $payload = dm_summary($window);
            break;

        case 'flagged':
            $payload = dm_flagged($window);
            break;

        case 'losing':
            $payload = dm_losing($window);
            break;

        case 'points':
            // ?only=flagged keeps the home page from downloading 3,866 rows
            // in order to draw 35 of them.
            $payload = dm_map_points(($_GET['only'] ?? 'all') === 'flagged', $window);
            break;

        case 'territory':
            $payload = dm_territory(dm_require_id(), $window);
            if ($payload === null) {
                dm_fail(404, 'not_found', 'No territory with that id.');
            }
            break;

        case 'history':
            $payload = dm_history(dm_require_id());
            break;

        case 'hotspots':
            $payload = dm_hotspots(dm_require_id());
            break;

        case 'reports':
            $payload = dm_reports(dm_require_id());
            break;

        case 'frequent':
            $payload = dm_frequent();
            break;

        case 'rising':
            $payload = dm_rising();
            break;

        default:
            dm_fail(400, 'bad_request', 'Unknown resource.');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (DmNotConfigured $e) {
    // Not an outage: nothing was ever configured to respond. Said separately
    // from the case below, and quoted rather than paraphrased, because
    // "the database did not respond" sent whoever read it to check a database
    // that was never contacted. This message names no credentials — only the
    // variable that is missing — so it is safe to show.
    error_log('[deforestation-monitor] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'error'   => 'not_configured',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // The exception text can contain the connection string, so it goes to the
    // server log and never to the browser.
    error_log('[deforestation-monitor] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'error'   => 'data_unavailable',
        'message' => 'The database did not respond. It may still be waking up — try again in a moment.',
    ], JSON_UNESCAPED_UNICODE);
}
