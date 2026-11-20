#!/usr/bin/env python3
"""
Publish the serving tables for the MySQL copy to read.

The pipeline computes in PostgreSQL and stays there. This writes what the
website needs into files that database/import.php downloads and loads, which
is what lets the site run on a cPanel account that cannot reach PostgreSQL and
cannot run Python.

WHAT IS PUBLISHED

Four tables whole, because they are small and are rewritten on every run
anyway, and two by their last few periods:

    territories       3,866 rows, changes when RAISG publishes a release
    rankings          3,866 rows, rewritten daily
    trends               ~33 rows
    hotspots            ~430 rows
    alerts_weekly     the last N weeks        (~3,900 rows per week)
    rankings_history  the last N windows      (~2,300 rows per window)

A ROLLING WINDOW RATHER THAN A DELTA

Four weeks of overlap, not one day of changes. A delta is smaller, but a day
the importer does not run is a day lost for good, and nobody finds out. With
an overlap the next successful run repairs up to four weeks of silence, and
loading the same week twice changes nothing: the import replaces whole
periods rather than adding to them.

field_reports is not published and must never be. Those rows are written by
the site, they exist in the MySQL database and nowhere else, and this file
would overwrite them with nothing. alerts_daily and geostores are not
published either: the site does not read them.

JSON LINES AND NOT CSV

One JSON array per line, with the column order declared in the manifest. CSV
has no way of saying NULL that cannot also be a string, and its quoting rules
are one more thing to get wrong. JSON gives real nulls, keeps floats exact
(Python writes the shortest text that reads back as the same double) and can
be read a line at a time, so the importer never holds the whole file in
memory. That matters on a shared host with a small memory limit.

THE MANIFEST

Written last, after every data file. An interrupted upload therefore leaves
the previous manifest in place and the importer does nothing, rather than
reading half of a new publication. It carries, per table, the row count, the
column order and the sum of the figures that matter, so the importer can
refuse a short file instead of writing it. For the two windowed tables it also
lists exactly which periods the file covers, which is what the importer
deletes before inserting.

    python src/publish.py                 the rolling window, for the cron
    python src/publish.py --full          every row, for the first load
    python src/publish.py --weeks 8       a wider overlap
"""

import argparse
import datetime as dt
import gzip
import json
import pathlib
import sys

from sqlalchemy import create_engine, text

OUT = pathlib.Path("data/published")

# Published whole.
FULL = ["territories", "rankings", "trends", "hotspots"]

# Published by their last N distinct values of the given column.
WINDOWED = {
    "alerts_weekly": "week",
    "rankings_history": "window_end",
}

# Summed into the manifest so the importer can tell a short file from a
# complete one. Only columns whose total means something.
SUMS = {
    "territories": "area_ha",
    "rankings": "area_ha",
    "alerts_weekly": "lost_ha",
    "rankings_history": "area_ha",
    "hotspots": "lost_ha",
}

BATCH = 5000


def neon_url() -> str:
    for line in open(".env"):
        if line.startswith("NEON_URL="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    raise SystemExit("NEON_URL no esta en .env")


def as_json(value):
    """Anything SQLAlchemy hands back, in a form json can write."""
    if isinstance(value, (dt.datetime, dt.date)):
        # Seconds, never a timezone: these are PostgreSQL timestamps without
        # one, and the MySQL side stores them in DATETIME columns, which also
        # have none. Adding an offset here would invent information.
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return value


def scope_of(conn, table: str, column: str, weeks: int) -> list:
    """The last `weeks` distinct values of `column`, newest first."""
    rows = conn.execute(text(
        f"SELECT DISTINCT {column} FROM {table} "
        f"ORDER BY {column} DESC LIMIT :n"
    ), {"n": weeks}).fetchall()
    return [r[0] for r in rows]


def dump(conn, table: str, where: str, params: dict) -> dict:
    """Write one table to data/published/<table>.jsonl.gz, return its entry."""
    path = OUT / f"{table}.jsonl.gz"
    result = (
        conn.execution_options(stream_results=True)
        .execute(text(f"SELECT * FROM {table}{where}"), params)
    )
    columns = list(result.keys())

    rows = 0
    with gzip.open(path, "wt", encoding="utf-8", compresslevel=6) as fh:
        while True:
            batch = result.fetchmany(BATCH)
            if not batch:
                break
            for row in batch:
                fh.write(json.dumps([as_json(v) for v in row],
                                    ensure_ascii=False))
                fh.write("\n")
            rows += len(batch)

    entry = {"rows": rows, "columns": columns,
             "bytes": path.stat().st_size, "file": path.name}

    if table in SUMS:
        column = SUMS[table]
        total = conn.execute(text(
            f"SELECT COALESCE(SUM({column}), 0) FROM {table}{where}"
        ), params).scalar()
        entry["sum"] = {column: float(total)}

    return entry


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--weeks", type=int, default=4,
                    help="cuantos periodos publicar de las tablas con ventana")
    ap.add_argument("--full", action="store_true",
                    help="todas las filas, para la carga inicial")
    args = ap.parse_args()

    if args.weeks < 1:
        raise SystemExit("--weeks tiene que ser 1 o mas")

    OUT.mkdir(parents=True, exist_ok=True)
    eng = create_engine(neon_url())

    manifest = {
        # UTC with an explicit Z: the importer compares this against what it
        # loaded last, and a local time without an offset would compare wrong
        # on a server in another zone.
        "published_at": dt.datetime.now(dt.timezone.utc)
                          .strftime("%Y-%m-%dT%H:%M:%SZ"),
        "mode": "full" if args.full else "window",
        "weeks": None if args.full else args.weeks,
        "tables": {},
    }

    with eng.connect() as conn:
        for table in FULL:
            manifest["tables"][table] = dump(conn, table, "", {})
            print(f"  {table:18} {manifest['tables'][table]['rows']:>9,} filas"
                  f"  {manifest['tables'][table]['bytes']/1024:>8.0f} KB")

        for table, column in WINDOWED.items():
            if args.full:
                entry = dump(conn, table, "", {})
                entry["key"] = column
                entry["scope"] = None
            else:
                scope = scope_of(conn, table, column, args.weeks)
                if not scope:
                    raise SystemExit(f"{table} esta vacia, no publico nada")
                # IN over the exact values rather than a date subtraction, so
                # the file and the manifest cannot disagree about which
                # periods are covered: the importer deletes this same list.
                names = {f"v{i}": v for i, v in enumerate(scope)}
                marks = ", ".join(f":{k}" for k in names)
                entry = dump(conn, table, f" WHERE {column} IN ({marks})", names)
                entry["key"] = column
                entry["scope"] = [as_json(v) for v in scope]

            manifest["tables"][table] = entry
            print(f"  {table:18} {entry['rows']:>9,} filas"
                  f"  {entry['bytes']/1024:>8.0f} KB"
                  f"  {len(entry['scope']) if entry['scope'] else 'todos'} periodos")

    # Last, and only once every data file above closed cleanly.
    (OUT / "manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    total = sum(t["bytes"] for t in manifest["tables"].values())
    print(f"\n  manifest.json escrito, {total/1024/1024:.2f} MB en total")
    print(f"  published_at {manifest['published_at']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
