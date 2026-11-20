#!/usr/bin/env python3
"""
Write database/sample_data.sql: enough data for the site to work after a
plain phpMyAdmin import.

The guide's acceptance test is that a second volunteer can install the project
in a clean cPanel account by following the documentation. That needs a
database with something in it, and the full history is 1.4 million rows, far
past what phpMyAdmin will accept as an upload. So this carries the four small
tables whole and the last few periods of the two large ones. The complete
history arrives afterwards through database/import.php --seed.

field_reports is not included. Those rows carry a reporter and a source IP,
and the guide rules out real personal data in a public repository.

Both a plain .sql and a .sql.gz are written. phpMyAdmin's upload limit is
2 MB on some accounts and 50 MB on others, and it accepts either file, so
DEPLOYMENT.md can name one and fall back to the other without anyone having
to find out mid-install.

    python tools/make_sample_data.py            8 periods
    python tools/make_sample_data.py --weeks 4  smaller
"""

import argparse
import datetime as dt
import gzip
import os
import pathlib
import shutil
import sys

from sqlalchemy import create_engine, text

OUT = pathlib.Path("database/sample_data.sql")

FULL = ["territories", "rankings", "trends", "hotspots"]
WINDOWED = {"alerts_weekly": "week", "rankings_history": "window_end"}

# One statement per chunk: a single INSERT per row would make the file much
# larger and the import much slower.
CHUNK = 500


def neon_url() -> str:
    url = os.environ.get("NEON_URL")
    if url:
        return url
    try:
        for line in open(".env"):
            if line.startswith("NEON_URL="):
                return line.split("=", 1)[1].strip().strip('"').strip("'")
    except FileNotFoundError:
        pass
    raise SystemExit("NEON_URL no esta ni en el entorno ni en .env")


def literal(value) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (dt.datetime, dt.date)):
        return "'" + value.strftime("%Y-%m-%d %H:%M:%S") + "'"
    if isinstance(value, float):
        # repr, so the value reads back as the same double.
        return repr(value)
    if isinstance(value, int):
        return str(value)
    # Backslash first, or the quote escaping would be escaped in turn.
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def dump(conn, table: str, where: str, params: dict, out) -> int:
    result = (
        conn.execution_options(stream_results=True)
        .execute(text(f"SELECT * FROM {table}{where}"), params)
    )
    columns = ", ".join(f"`{c}`" for c in result.keys())
    rows = 0

    while True:
        chunk = result.fetchmany(CHUNK)
        if not chunk:
            break
        out.write(f"INSERT INTO `{table}` ({columns}) VALUES\n")
        out.write(",\n".join(
            "(" + ", ".join(literal(v) for v in row) + ")" for row in chunk
        ))
        out.write(";\n")
        rows += len(chunk)

    return rows


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--weeks", type=int, default=8)
    args = ap.parse_args()

    OUT.parent.mkdir(parents=True, exist_ok=True)
    eng = create_engine(neon_url())

    with eng.connect() as conn, OUT.open("w", encoding="utf-8") as out:
        out.write(
            "-- Sample data for the deforestation monitor.\n"
            "--\n"
            "-- Import after database/schema.sql, into the database created\n"
            "-- from the cPanel MySQL Databases page.\n"
            "--\n"
            f"-- The four small tables whole, and the last {args.weeks} periods of\n"
            "-- alerts_weekly and rankings_history. The site works with this;\n"
            "-- the full history comes from database/import.php --seed.\n"
            "--\n"
            "-- field_reports is empty on purpose: those rows carry a reporter\n"
            "-- and a source IP and do not belong in a public repository.\n"
            f"--\n-- Generated {dt.datetime.now(dt.timezone.utc):%Y-%m-%d %H:%M} UTC "
            "by tools/make_sample_data.py\n\n"
            "SET NAMES utf8mb4;\n"
            "START TRANSACTION;\n\n"
        )

        total = 0
        for table in FULL:
            n = dump(conn, table, "", {}, out)
            out.write("\n")
            total += n
            print(f"  {table:18} {n:>8,}")

        for table, column in WINDOWED.items():
            scope = [r[0] for r in conn.execute(text(
                f"SELECT DISTINCT {column} FROM {table} "
                f"ORDER BY {column} DESC LIMIT :n"
            ), {"n": args.weeks})]
            names = {f"v{i}": v for i, v in enumerate(scope)}
            marks = ", ".join(f":{k}" for k in names)
            n = dump(conn, table, f" WHERE {column} IN ({marks})", names, out)
            out.write("\n")
            total += n
            print(f"  {table:18} {n:>8,}  {len(scope)} periodos")

        out.write("COMMIT;\n")

    gz = OUT.with_suffix(".sql.gz")
    with OUT.open("rb") as plain, gzip.open(gz, "wb", compresslevel=9) as packed:
        shutil.copyfileobj(plain, packed)

    size = OUT.stat().st_size
    print(f"\n  {OUT}     {total:,} filas  {size/1024/1024:.2f} MB")
    print(f"  {gz}  {gz.stat().st_size/1024/1024:.2f} MB")
    return 0


if __name__ == "__main__":
    sys.exit(main())
