<?php
declare(strict_types=1);

/**
 * report.php — the one endpoint that writes. A person who went to check an
 * alert on the ground records what they found; until now the system had no
 * way to learn whether it is right.
 *
 *   POST report.php
 *     territory_id   required, numeric
 *     verdict        required, one of DM_VERDICTS
 *     note           optional, up to DM_REPORT_NOTE_MAX characters
 *     reporter       optional, up to DM_REPORT_REPORTER_MAX characters
 *     website        the trap; see below
 *
 * -> 200 {"ok": true}, or an error in api.php's shape: {"error", "message"}.
 *
 * Three defences, cheapest first:
 *
 *   1. The trap. `website` is a real text field that the stylesheet moves off
 *      screen. A person never sees it; a bot filling every field does. If it
 *      arrives filled, the answer is the same 200 a real report gets and
 *      nothing is stored — an error would tell the bot what to stop doing.
 *   2. Length and value checks, here, whatever the form's maxlength said:
 *      the form is only a suggestion to whoever posts to this URL.
 *   3. Five reports an hour per IP, counted in the table itself before the
 *      insert (dm_pg_submit_report). No session, no cookie, nothing else to
 *      keep.
 *
 * The IP is REMOTE_ADDR and only that. X-Forwarded-For is whatever the client
 * chose to send, so trusting it would let anyone pick a fresh IP per request
 * and walk straight past the limit. Behind a proxy every visitor shares the
 * proxy's address and the limit becomes site-wide; if the site is ever put
 * behind one, this is the line to change, to read the header that proxy sets.
 */

require __DIR__ . '/data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dm_report_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function dm_report_fail(int $status, string $code, string $message): void
{
    dm_report_reply($status, ['error' => $code, 'message' => $message]);
}

/**
 * One text field from the POST body, trimmed, with empty meaning absent.
 *
 * Anything that is not a single string — `note[]=…` arrives as an array — or
 * is not valid UTF-8 is refused rather than coerced, and the length is counted
 * in characters, so 2,000 means 2,000 whatever the alphabet.
 */
function dm_report_text(string $name, int $max, string $label): ?string
{
    $raw = $_POST[$name] ?? null;
    if ($raw === null) {
        return null;
    }
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
        dm_report_fail(400, 'bad_request', $label . ' could not be read.');
    }

    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $max) {
        dm_report_fail(
            400,
            'too_long',
            $label . ' can be at most ' . number_format($max) . ' characters.'
        );
    }
    return $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    dm_report_fail(405, 'method_not_allowed', 'Field reports are sent by POST.');
}

// 1. The trap, before anything else is looked at. Anything at all in it —
//    including a non-string — is a bot.
$trap = $_POST['website'] ?? '';
if (!is_string($trap) || trim($trap) !== '') {
    dm_report_reply(200, ['ok' => true]);
}

// 2. The fields.
$raw_id = $_POST['territory_id'] ?? '';
if (!is_string($raw_id) || !preg_match('/^\d{1,9}$/', $raw_id)) {
    dm_report_fail(400, 'bad_request', 'A numeric territory id is required.');
}
$territory_id = (int) $raw_id;

$verdict = $_POST['verdict'] ?? '';
if (!is_string($verdict) || !in_array($verdict, DM_VERDICTS, true)) {
    dm_report_fail(400, 'bad_request', 'Choose what you found.');
}

$note     = dm_report_text('note', DM_REPORT_NOTE_MAX, 'The note');
$reporter = dm_report_text('reporter', DM_REPORT_REPORTER_MAX, 'The name or organisation');

$source_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if ($source_ip === '') {
    // No address means no way to apply the limit, so no way to accept it.
    dm_report_fail(400, 'bad_request', 'The report could not be accepted.');
}

// 3. The limit and the insert, together.
try {
    $result = dm_submit_report($territory_id, $verdict, $note, $reporter, $source_ip);
} catch (DmNotConfigured $e) {
    error_log('[deforestation-monitor] ' . $e->getMessage());
    dm_report_fail(503, 'not_configured', $e->getMessage());
} catch (Throwable $e) {
    // As in api.php: the exception can carry the connection string, so it
    // goes to the log and the browser gets a sentence.
    error_log('[deforestation-monitor] report: ' . $e->getMessage());
    dm_report_fail(503, 'data_unavailable', 'The report could not be saved. Please try again in a moment.');
}

if ($result === 'ok') {
    dm_report_reply(200, ['ok' => true]);
}
if ($result === 'not_found') {
    dm_report_fail(404, 'not_found', 'No territory with that id.');
}
if ($result === 'rate_limited') {
    dm_report_fail(
        429,
        'rate_limited',
        'This connection has already sent ' . DM_REPORTS_PER_HOUR
            . ' reports in the last hour. Please try again later.'
    );
}
dm_report_fail(503, 'data_unavailable', 'The report could not be saved.');
