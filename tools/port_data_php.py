#!/usr/bin/env python3
"""
Port web/data.php from PostgreSQL to MySQL.

Run from the repository root. Nothing is written unless every replacement
matches exactly once, so a failed run leaves the file untouched and names the
patch that did not apply. The original is kept as web/data.php.bak.

Also writes config/config.example.php and adds the real config file to
.gitignore, which has to happen before the credentials exist.

The patches are deliberately narrow. Existing comments are edited only where
the port made them false; the rest of the prose in the file is left alone.
"""

import pathlib
import re
import sys

ROOT = pathlib.Path.cwd()
TARGET = ROOT / "web" / "data.php"
GITIGNORE = ROOT / ".gitignore"
CONFIG_DIR = ROOT / "config"


def block(start: str, end: str, tail: str = r"[^\n]*") -> str:
    """Anchored on a first and last line, whatever sits between them.

    Only the anchors have to be reproduced exactly, and the tail absorbs any
    trailing spacing on the closing line, which cannot be seen when reading a
    file.
    """
    return re.escape(start) + r".*?" + re.escape(end) + tail


def line(text: str) -> str:
    return re.escape(text)


PATCHES = []


def patch(name, pattern, new):
    PATCHES.append((name, pattern, new))


# --- header: where the data comes from ------------------------------------
# His sentences in between are not touched, so this is three narrow patches
# rather than one block.

patch(
    "header: backend",
    line(" * PostgreSQL over PDO, and nothing else. NEON_URL must be set:\n"
         " *\n"
         " *   export NEON_URL='postgresql://user:password@host.neon.tech/dbname?sslmode=require'"),
    r""" * MySQL over PDO, and nothing else. config/config.php must exist:
 *
 *   <?php return ['host' => 'localhost', 'name' => 'account_dbname',
 *                 'user' => 'account_dbuser', 'pass' => 'secret'];""",
)

patch(
    "header: nombre de la funcion",
    line(" * it is not configured and stops, which is dm_require_neon_url() below. There"),
    " * it is not configured and stops, which is dm_require_config() below. There",
)

patch(
    "header: donde viven las credenciales",
    line(" * The credentials are never written down here, and this file does not read\n"
         " * .env: NEON_URL has to be in the process environment."),
    r""" * The credentials are never written down here. config/config.php is created
 * on the server from config.example.php and is not in Git.
 *
 * This database is a copy. The pipeline computes in PostgreSQL and publishes
 * into it through database/import.php, so a wrong figure here is a publishing
 * fault and not a calculation. field_reports is the exception: written by the
 * site, held nowhere else, and never touched by the importer.""",
)


# --- the exception's docblock ---------------------------------------------

patch(
    "DmNotConfigured: primera frase",
    line(" * Thrown when NEON_URL is absent. Its own class, not a bare RuntimeException,"),
    " * Thrown when config/config.php is missing or incomplete. Its own class,\n"
    " * not a bare RuntimeException,",
)


# --- configuration: a file on disk instead of a variable -----------------

patch(
    "config: funciones",
    block("function dm_neon_url(): ?string", "    return $url;\n}", tail=""),
    r"""/**
 * config/config.php, or null when it is absent or incomplete. Read once.
 *
 * dirname(__DIR__) so the path survives this file moving to app/includes/ and
 * the config to app/config/, which is the layout the delivery guide asks for.
 */
function dm_config(): ?array
{
    static $config = null;
    static $read   = false;

    if ($read) {
        return $config;
    }
    $read = true;

    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_readable($path)) {
        return null;
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        return null;
    }

    // Incomplete counts as absent: a config missing the password would
    // otherwise reach PDO and come back as a connection failure, which reads
    // like an outage rather than like an unfinished deployment.
    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if (!array_key_exists($key, $loaded)) {
            return null;
        }
    }

    $config = $loaded;
    return $config;
}

function dm_is_configured(): bool
{
    return dm_config() !== null;
}

/** The connection settings, or stop with a message that names what is missing. */
function dm_require_config(): array
{
    $config = dm_config();
    if ($config === null) {
        throw new DmNotConfigured(
            'config/config.php is missing or incomplete. This site reads its '
            . 'figures from MySQL and has no other source; copy '
            . 'config/config.example.php to config/config.php, fill in the '
            . 'database host, name, user and password, and reload.'
        );
    }
    return $config;
}""",
)


# --- section heading ------------------------------------------------------

patch(
    "titulo de seccion",
    line("   POSTGRES BACKEND (Neon, via PDO)"),
    "   MYSQL BACKEND (via PDO)",
)


# --- the connection -------------------------------------------------------

patch(
    "dm_pdo",
    block("function dm_pdo(): PDO", "\n    return $pdo;\n}", tail=""),
    r"""function dm_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = dm_require_config();

    // charset in the DSN, not a SET NAMES afterwards: the connection has to be
    // utf8mb4 from its first statement or the accented names come back mangled
    // with no error raised.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) ($config['port'] ?? 3306),
        $config['name']
    );

    $pdo = new PDO(
        $dsn,
        (string) $config['user'],
        (string) $config['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // The strictness this file assumes, whatever the account's sql_mode is.
    // Without STRICT_ALL_TABLES an oversized value is truncated and stored
    // rather than refused.
    $pdo->exec(
        'SET SESSION sql_mode = '
        . '\'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION\''
    );

    return $pdo;
}""",
)


# --- dm_pg_scope ----------------------------------------------------------

patch(
    "dm_pg_scope: comentario",
    line("    // ::date because window_end is a timestamp: comparing it to '2026-08-31'\n"
         "    // as a timestamp would match only midnight exactly."),
    "    // DATE() because window_end is a DATETIME: comparing it to '2026-08-31'\n"
    "    // as a datetime would match only midnight exactly.",
)

patch(
    "dm_pg_scope: clausula",
    line("        ' AND ' . $alias . '.window_end::date = :window',"),
    "        ' AND DATE(' . $alias . '.window_end) = :window',",
)


# --- dm_pg_windows --------------------------------------------------------

patch(
    "dm_pg_windows: SQL",
    block("            window_end::date", "        ORDER BY window_end::date DESC"),
    r"""            DATE(window_end)                                  AS window_end,
            DATE(MIN(window_start))                           AS window_start,
            COUNT(CASE WHEN flagged THEN 1 END)               AS flagged_count,
            COUNT(CASE WHEN NOT baseline_settled THEN 1 END)  AS unsettled_count,
            COUNT(*)                                          AS territory_count,
            MIN(weeks_of_history)                             AS weeks_of_history
        FROM rankings_history
        GROUP BY DATE(window_end)
        ORDER BY DATE(window_end) DESC""",
)


# --- dm_pg_summary --------------------------------------------------------

patch(
    "dm_pg_summary: SQL",
    block("            COUNT(*) FILTER (WHERE r.flagged)", "            MAX(r.window_end)"),
    r"""            COUNT(CASE WHEN r.flagged THEN 1 END)      AS flagged_count,
            COUNT(CASE WHEN r.area_ha > 0 THEN 1 END)  AS territories_with_loss,
            COALESCE(SUM(r.area_ha), 0)                AS total_lost_ha,
            MIN(r.window_start)                        AS window_start,
            MAX(r.window_end)                          AS window_end""",
)


# --- dm_pg_flagged --------------------------------------------------------

patch(
    "dm_pg_flagged: orden",
    line("        ORDER BY r.score DESC NULLS LAST"),
    "        -- MySQL sorts NULL below every value, so DESC already leaves the\n"
    "        -- unscored rows last and needs no clause for it.\n"
    "        ORDER BY r.score DESC",
)


# --- dm_pg_losing ---------------------------------------------------------

patch(
    "dm_pg_losing: desempate",
    line("        WHERE r.area_ha > 0' . $clause . '\n        ORDER BY r.area_ha DESC"),
    "        WHERE r.area_ha > 0' . $clause . '\n"
    "        -- territory_id as a tiebreaker: hectares lost repeat often across\n"
    "        -- two thousand rows, and with one key the tied rows land wherever\n"
    "        -- the plan puts them.\n"
    "        ORDER BY r.area_ha DESC, r.territory_id ASC",
)


# --- dm_pg_map_points -----------------------------------------------------

patch(
    "dm_pg_map_points: orden",
    line("          ' . ($flagged_only ? 'AND r.flagged' : '') . '\n    ', $params);"),
    "          ' . ($flagged_only ? 'AND r.flagged' : '') . '\n"
    "        -- Had no ORDER BY at all, which left the row order to the plan. The\n"
    "        -- map does not care, but an unstable payload cannot be diffed.\n"
    "        ORDER BY t.territory_id ASC\n"
    "    ', $params);",
)


# --- dm_pg_history --------------------------------------------------------
# Two sentences of his became false. The rest of the comment stands.

patch(
    "dm_pg_history: mecanismo",
    line("    //o DISTINCT ON because one row per calendar week is not guaranteed: the"),
    "    // The `pick` subquery below, because one row per calendar week is not\n"
    "    // guaranteed: the",
)

patch(
    "dm_pg_history: rango",
    line("    // window_end falls on its seventh day. A range rather than `::date =`, so"),
    "    // window_end falls on its seventh day. A range rather than an equality, so",
)

patch(
    "dm_pg_history: SQL",
    line("        SELECT DISTINCT ON (w.week::date)") + r".*?"
    + line("ORDER BY w.week::date ASC, w.week DESC") + r".*?\]\);",
    r"""        SELECT w.week, w.lost_ha, w.lost_per_1000ha,
               h.seasonal_baseline,
               h.ratio,
               (h.territory_id IS NOT NULL) AS screened
        FROM alerts_weekly w
        -- MySQL has no distinct-on. MAX(week) per calendar day picks the later
        -- stamp, and cannot duplicate a row: inside one territory the UNIQUE
        -- makes that maximum unique. :pick_id is :id again under a second name,
        -- because reusing one placeholder twice is not portable with
        -- ATTR_EMULATE_PREPARES off.
        JOIN (
                 SELECT MAX(week) AS latest
                 FROM alerts_weekly
                 WHERE territory_id = :pick_id
                 GROUP BY DATE(week)
             ) pick
               ON pick.latest = w.week
        LEFT JOIN rankings_history h
               ON h.territory_id = w.territory_id
              AND h.window_end >= w.week + INTERVAL 6 DAY
              AND h.window_end <  w.week + INTERVAL 7 DAY
        WHERE w.territory_id = :id
        ORDER BY w.week ASC
    ', [':id' => $territory_id, ':pick_id' => $territory_id]);""",
)


# --- dm_pg_hotspots ------------------------------------------------------

patch(
    "dm_pg_hotspots: comentario",
    line("    // ::date on both sides because both are timestamps written by separate"),
    "    // DATE() on both sides because both are datetimes written by separate",
)

patch(
    "dm_pg_hotspots: SQL",
    block("        SELECT h.rank, h.lat", "        ORDER BY h.rank ASC"),
    r"""        SELECT h.`rank`, h.lat, h.lon, h.alerts, h.lost_ha, h.share_pct,
               h.window_end
        FROM hotspots h
        JOIN rankings r
          ON r.territory_id = h.territory_id
         AND DATE(r.window_end) = DATE(h.window_end)
        WHERE h.territory_id = :id
        ORDER BY h.`rank` ASC""",
)


# --- dm_pg_submit_report -------------------------------------------------
# The lock moves out of the transaction and its last sentence becomes false.
# His first two sentences, and every other comment in the function, stay.

patch(
    "dm_pg_submit_report: lock",
    line("    $pdo = dm_pdo();\n"
         "    $pdo->beginTransaction();\n"
         "\n"
         "    try {\n"
         "        // The count and the insert as one step per IP. Without the lock, six\n"
         "        // requests arriving together would all count four and all insert.\n"
         "        // Transaction-scoped, so it is released by the COMMIT or ROLLBACK\n"
         "        // below and cannot be left held.\n"
         "        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:ip))')\n"
         "            ->execute([':ip' => $source_ip]);"),
    r"""    $pdo = dm_pdo();

    // The count and the insert as one step per IP. Without the lock, six
    // requests arriving together would all count four and all insert.
    // GET_LOCK belongs to the session and not to the transaction, so every way
    // out of this function goes through the finally. Taken before the
    // transaction opens, so releasing it cannot fail inside one. The name is
    // hashed in PHP because the cap is 64 characters and MySQL is retiring its
    // own hash functions.
    $lock_name = 'dm_ip_' . sha1($source_ip);
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock, 10)');
    $lock->execute([':lock' => $lock_name]);
    if ((int) $lock->fetchColumn() !== 1) {
        // Ten seconds means stuck, not busy. Refusing is safer than inserting
        // without having counted.
        return 'rate_limited';
    }

    $pdo->beginTransaction();

    try {""",
)

patch(
    "dm_pg_submit_report: intervalo",
    line("              AND submitted_at > NOW() - INTERVAL \\'1 hour\\'"),
    "              AND submitted_at > NOW() - INTERVAL 1 HOUR",
)

patch(
    "dm_pg_submit_report: finally",
    line("        throw $e;\n    }\n}"),
    r"""        throw $e;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(:lock)')
            ->execute([':lock' => $lock_name]);
    }
}""",
)


# --- dm_pg_frequent ------------------------------------------------------

patch(
    "dm_pg_frequent: SQL",
    block("            COUNT(*) FILTER (WHERE flagged) AS times_flagged,",
          "        HAVING COUNT(*) FILTER (WHERE flagged) > 0"),
    r"""            COUNT(CASE WHEN flagged THEN 1 END) AS times_flagged,
            SUM(area_ha)                        AS total_lost_ha
        FROM rankings_history
        WHERE baseline_settled
        GROUP BY territory_id, name, country
        HAVING times_flagged > 0""",
)


# --- shaping -------------------------------------------------------------

patch(
    "shaping: primera frase",
    line("   PDO hands back strings for numeric columns, so every value is cast here."),
    "   What PDO returns for a numeric column depends on the driver and the PHP\n"
    "   version, so every value is cast here.",
)


LEFTOVERS = {
    "::date": "cast de Postgres",
    "FILTER (WHERE": "COUNT(*) FILTER de Postgres",
    "NULLS LAST": "ordenamiento de Postgres",
    "pg_advisory": "lock de Postgres",
    "hashtext": "funcion de Postgres",
    "pgsql:": "DSN de Postgres",
    "NEON_URL": "variable de entorno vieja",
    r"INTERVAL \'": "intervalo con sintaxis de Postgres",
    "DISTINCT ON": "distinct-on de Postgres",
    "dm_neon_url": "funcion vieja de configuracion",
    "SHA1(": "hash hecho por el servidor en vez de por PHP",
}


CONFIG_EXAMPLE = """<?php
/**
 * Template for config/config.php, created from this file on the server and
 * excluded from Git. Every value below is a placeholder.
 *
 * cPanel prefixes database and user names with the account, so they usually
 * look like account_dbname and account_dbuser.
 *
 * The site only reads, except for one INSERT into field_reports. The user
 * needs SELECT on every table and INSERT on field_reports, and nothing else.
 */

return [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'account_dbname',
    'user' => 'account_dbuser',
    'pass' => 'put-the-real-password-here',
];
"""


GITIGNORE_ADDITION = """
# The real database credentials. Section 7 of the delivery guide is explicit
# that removing a published credential from the latest commit is not enough.
**/config/config.php
"""


def main() -> int:
    if not TARGET.is_file():
        print(f"No encontre {TARGET}. Corre el script desde la raiz del repo.")
        return 1

    original = TARGET.read_text(encoding="utf-8")
    text = original
    failures = []

    for name, pattern, new in PATCHES:
        found = re.findall(pattern, text, flags=re.DOTALL)
        if len(found) != 1:
            failures.append(f"  {name}: {len(found)} coincidencias, esperaba 1")
            continue
        text = re.sub(pattern, lambda m, n=new: n, text, count=1, flags=re.DOTALL)

    if failures:
        print("NO SE ESCRIBIO NADA. Estos patches no aplicaron:\n")
        print("\n".join(failures))
        return 1

    stale = []
    for p in (ROOT / "web").rglob("*.php"):
        if p.resolve() == TARGET.resolve():
            continue
        body = p.read_text(encoding="utf-8")
        for gone in ("dm_neon_url", "dm_require_neon_url"):
            if gone in body:
                stale.append(f"  {p.relative_to(ROOT)} llama a {gone}()")
    if stale:
        print("NO SE ESCRIBIO NADA. Hay llamadas a funciones que este patch elimina:\n")
        print("\n".join(stale))
        return 1

    # Renaming across every file at once is safe whether an occurrence is a
    # call or a mention: a call renamed with its definition goes on working.
    text = text.replace("dm_pg_", "dm_db_")
    others = {}
    for p in (ROOT / "web").rglob("*.php"):
        if p.resolve() == TARGET.resolve():
            continue
        body = p.read_text(encoding="utf-8")
        if "dm_pg_" in body:
            others[p] = body.replace("dm_pg_", "dm_db_")

    remaining = [f"  {t}  ({why})" for t, why in LEFTOVERS.items() if t in text]
    if "dm_pg_" in text:
        remaining.append("  dm_pg_  (prefijo viejo)")
    if remaining:
        print("NO SE ESCRIBIO NADA. Quedaron restos de Postgres:\n")
        print("\n".join(remaining))
        return 1

    TARGET.with_suffix(".php.bak").write_text(original, encoding="utf-8")
    TARGET.write_text(text, encoding="utf-8")
    print("web/data.php portado. Original en web/data.php.bak")
    print(f"  {len(PATCHES)} patches aplicados, 0 restos de Postgres")

    for p, body in others.items():
        p.write_text(body, encoding="utf-8")
        print(f"  {p.relative_to(ROOT)}: dm_pg_ renombrado a dm_db_")

    CONFIG_DIR.mkdir(exist_ok=True)
    (CONFIG_DIR / "config.example.php").write_text(CONFIG_EXAMPLE, encoding="utf-8")
    print("  config/config.example.php escrito")

    current = GITIGNORE.read_text(encoding="utf-8") if GITIGNORE.is_file() else ""
    if "config/config.php" in current:
        print("  .gitignore ya ignoraba el config, no lo toque")
    else:
        GITIGNORE.write_text(current.rstrip("\n") + "\n" + GITIGNORE_ADDITION,
                             encoding="utf-8")
        print("  .gitignore actualizado")

    return 0


if __name__ == "__main__":
    sys.exit(main())
