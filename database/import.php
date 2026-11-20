<?php
declare(strict_types=1);

/**
 * Load the published data into this server's MySQL database.
 *
 * The pipeline runs on GitHub Actions and writes to PostgreSQL. It publishes
 * what the site needs as release assets; this reads them. Nothing here talks
 * to PostgreSQL and nothing here needs Python.
 *
 * Run from cron, once a day, a couple of hours after the workflow:
 *
 *   /usr/local/bin/php /home/ACCOUNT/public_html/PROJECT/database/import.php
 *
 *   --force     import even if the publication is one already loaded
 *   --dry-run   check everything and write nothing
 *   --quiet     only print on failure, which is what cron wants
 *
 * CLI only, on purpose: reachable over HTTP this would be a write endpoint
 * anyone could fire.
 *
 * field_reports is never touched. Those rows are written by the site and
 * exist here and nowhere else.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const DEFAULT_DATA_URL =
    'https://github.com/Applied-Data-Digital-Innovation-Lab/deforestation-monitor'
    . '/releases/download/data-latest';

const BATCH = 500;

// A file far from the published size is a bad download, not new data.
const SIZE_TOLERANCE = 0.02;

// Floating-point sums differ in the last bits between engines, so compare
// with a tolerance rather than for equality. A lost row moves these totals by
// millions of times more than this.
const SUM_TOLERANCE = 1e-9;

$argvFlags = array_slice($argv, 1);
$force   = in_array('--force', $argvFlags, true);
$dryRun  = in_array('--dry-run', $argvFlags, true);
$quiet   = in_array('--quiet', $argvFlags, true);

exit(run($force, $dryRun, $quiet));


function say(bool $quiet, string $line): void
{
    if (!$quiet) {
        print $line . "\n";
    }
}

function fail(string $line): int
{
    fwrite(STDERR, "import: $line\n");
    return 1;
}

function config(): array
{
    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_readable($path)) {
        throw new RuntimeException("no encuentro $path");
    }
    $config = require $path;
    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException("config/config.php no tiene '$key'");
        }
    }
    return $config;
}

function connect(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) ($config['port'] ?? 3306),
        $config['name']
    );
    $pdo = new PDO($dsn, (string) $config['user'], (string) $config['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec(
        'SET SESSION sql_mode = '
        . '\'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION\''
    );
    return $pdo;
}

/** Download one asset to a temporary file and return its path. */
function download(string $url, string $into): string
{
    $path = $into . '/' . basename(parse_url($url, PHP_URL_PATH) ?: 'asset');

    if (function_exists('curl_init')) {
        $fh = fopen($path, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        $ok    = curl_exec($ch);
        $error = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fh);
        if ($ok === false) {
            throw new RuntimeException("no pude bajar $url: $error (HTTP $code)");
        }
        return $path;
    }

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException(
            'el servidor no tiene curl ni allow_url_fopen, '
            . 'asi que no puede bajar los datos'
        );
    }
    $body = @file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException("no pude bajar $url");
    }
    file_put_contents($path, $body);
    return $path;
}

/**
 * The columns a table really has, in order.
 *
 * Aliased, because information_schema labels its results in uppercase on
 * MySQL 8 and later whatever case the query used.
 */
function columnsOf(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('
        SELECT column_name AS col
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = :t
        ORDER BY ordinal_position
    ');
    $stmt->execute([':t' => $table]);
    return array_map('strval', array_column($stmt->fetchAll(), 'col'));
}

function quoted(array $columns): string
{
    return implode(', ', array_map(fn($c) => "`$c`", $columns));
}

/**
 * Replace one table's published scope, inside a transaction.
 *
 * Counts and sums are checked against the manifest before the COMMIT, so a
 * short or altered file is rolled back rather than written.
 */
function importTable(
    PDO $pdo,
    string $table,
    array $entry,
    string $file,
    bool $dryRun,
    bool $quiet
): int {
    $columns = $entry['columns'];
    $actual  = columnsOf($pdo, $table);

    if (!$actual) {
        throw new RuntimeException(
            "$table: la tabla no existe en esta base. Importar database/schema.sql primero."
        );
    }

    $missing = array_diff($columns, $actual);
    if ($missing) {
        throw new RuntimeException(
            "$table: el archivo trae columnas que la tabla no tiene ("
            . implode(', ', $missing) . '). El schema cambio.'
        );
    }

    $sumColumn = $entry['sum'] ? array_key_first($entry['sum']) : null;
    $sumIndex  = $sumColumn !== null ? array_search($sumColumn, $columns, true) : false;

    $marks = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

    // territories is the parent of every foreign key, so its rows are updated
    // in place rather than replaced: deleting them would fail against the
    // children, and the territory set only changes when RAISG publishes.
    $upsert = $table === 'territories';
    if ($upsert) {
        $assign = [];
        foreach (array_slice($columns, 1) as $c) {
            $assign[] = "`$c` = ?";
        }
        // Values bound twice instead of VALUES() or a row alias: those are
        // deprecated or unavailable depending on the server, this is not.
        $insert = $pdo->prepare(
            "INSERT INTO `$table` (" . quoted($columns) . ") VALUES $marks"
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assign)
        );
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO `$table` (" . quoted($columns) . ") VALUES $marks"
        );
    }

    $pdo->beginTransaction();
    try {
        if (!$upsert) {
            if ($entry['scope']) {
                $key   = $entry['key'];
                $holes = implode(', ', array_fill(0, count($entry['scope']), '?'));
                $pdo->prepare("DELETE FROM `$table` WHERE `$key` IN ($holes)")
                    ->execute($entry['scope']);
            } else {
                $pdo->exec("DELETE FROM `$table`");
            }
        }

        $fh = gzopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException("no pude abrir $file");
        }

        $rows = 0;
        $sum  = 0.0;
        $batch = [];

        while (($line = gzgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            // PDO binds as strings by default, and false would become '',
            // which a TINYINT column refuses under STRICT_ALL_TABLES.
            if (is_array($row)) {
                $row = array_map(fn($v) => is_bool($v) ? (int) $v : $v, $row);
            }
            if (!is_array($row) || count($row) !== count($columns)) {
                throw new RuntimeException(
                    "$table: fila " . ($rows + 1) . " tiene "
                    . (is_array($row) ? count($row) : 0) . ' valores y la tabla '
                    . count($columns)
                );
            }
            if ($sumIndex !== false && $row[$sumIndex] !== null) {
                $sum += (float) $row[$sumIndex];
            }
            $batch[] = $row;
            $rows++;

            if (count($batch) >= BATCH) {
                flushBatch($insert, $batch, $upsert);
                $batch = [];
            }
        }
        gzclose($fh);
        if ($batch) {
            flushBatch($insert, $batch, $upsert);
        }

        if ($rows !== $entry['rows']) {
            throw new RuntimeException(
                "$table: el archivo trae $rows filas y el manifest dice "
                . $entry['rows']
            );
        }

        if ($sumColumn !== null) {
            $want = (float) $entry['sum'][$sumColumn];
            $diff = abs($sum - $want);
            $rel  = $want != 0.0 ? $diff / abs($want) : $diff;
            if ($diff > SUM_TOLERANCE && $rel > 1e-12) {
                throw new RuntimeException(sprintf(
                    '%s: la suma de %s da %.6f y el manifest dice %.6f',
                    $table, $sumColumn, $sum, $want
                ));
            }
        }

        if ($dryRun) {
            $pdo->rollBack();
            say($quiet, sprintf('  %-18s %7d filas  (dry-run, no escribi)', $table, $rows));
            return $rows;
        }

        $pdo->commit();
        say($quiet, sprintf('  %-18s %7d filas', $table, $rows));
        return $rows;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function flushBatch(PDOStatement $stmt, array $batch, bool $upsert): void
{
    foreach ($batch as $row) {
        $stmt->execute($upsert ? array_merge($row, array_slice($row, 1)) : $row);
    }
}

function run(bool $force, bool $dryRun, bool $quiet): int
{
    $tmp = null;
    try {
        $config = config();
        $pdo    = connect($config);
        $base   = rtrim((string) ($config['data_url'] ?? DEFAULT_DATA_URL), '/');

        $tmp = sys_get_temp_dir() . '/dm-import-' . bin2hex(random_bytes(6));
        if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
            return fail("no pude crear $tmp");
        }

        $manifest = json_decode(
            file_get_contents(download("$base/manifest.json", $tmp)),
            true, 512, JSON_THROW_ON_ERROR
        );

        $published = (string) $manifest['published_at'];
        // Stored and compared in the same shape, or the comparison below never
        // matches and every run reimports.
        $publishedSql = str_replace(['T', 'Z'], [' ', ''], $published);
        say($quiet, "publicacion {$published} ({$manifest['mode']})");

        $last = $pdo->query(
            'SELECT published_at FROM import_log WHERE status = \'ok\' '
            . 'ORDER BY id DESC LIMIT 1'
        )->fetchColumn();

        if ($last !== false && (string) $last === $publishedSql && !$force) {
            say($quiet, 'ya estaba importada, no hago nada. --force para repetir.');
            return 0;
        }

        $total = 0;
        foreach ($manifest['tables'] as $table => $entry) {
            if ($table === 'field_reports') {
                throw new RuntimeException(
                    'el manifest trae field_reports y no deberia: esas filas '
                    . 'solo existen en esta base'
                );
            }
            $entry += ['scope' => null, 'key' => null, 'sum' => null];

            $file = download("$base/{$entry['file']}", $tmp);
            $size = filesize($file);
            $want = (int) $entry['bytes'];
            if ($want > 0 && abs($size - $want) / $want > SIZE_TOLERANCE) {
                throw new RuntimeException(
                    "{$entry['file']}: baje $size bytes y el manifest dice $want"
                );
            }

            $total += importTable($pdo, $table, $entry, $file, $dryRun, $quiet);
        }

        if (!$dryRun) {
            // UTC_TIMESTAMP and not NOW: published_at comes from the workflow
            // in UTC, and a local imported_at beside it would make the two
            // columns incomparable on a server in any other zone.
            $pdo->prepare('
                INSERT INTO import_log (published_at, imported_at, mode, row_count, status)
                VALUES (:p, UTC_TIMESTAMP(), :m, :r, \'ok\')
            ')->execute([
                ':p' => $publishedSql,
                ':m' => (string) $manifest['mode'],
                ':r' => $total,
            ]);
        }

        say($quiet, sprintf('%d filas en total%s', $total, $dryRun ? ' (dry-run)' : ''));
        return 0;
    } catch (Throwable $e) {
        // Logged as well as printed, so a cron run that nobody reads still
        // leaves a trace the site can show.
        if (isset($pdo)) {
            try {
                $pdo->prepare('
                    INSERT INTO import_log (published_at, imported_at, mode, row_count, status, note)
                    VALUES (NULL, UTC_TIMESTAMP(), NULL, 0, \'error\', :n)
                ')->execute([':n' => substr($e->getMessage(), 0, 1000)]);
            } catch (Throwable $ignored) {
            }
        }
        return fail($e->getMessage());
    } finally {
        if ($tmp && is_dir($tmp)) {
            foreach (glob("$tmp/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }
}
