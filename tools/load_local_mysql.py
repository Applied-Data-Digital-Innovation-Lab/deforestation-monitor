#!/usr/bin/env python3
"""
Copy the serving tables from Neon into the local MySQL, and verify the copy.

This is scaffolding for testing the migration on a laptop. It is NOT how the
production database gets filled: that is database/import.php, which reads a
published file and has no access to PostgreSQL. The point of this script is to
get a known-good copy into MySQL so that the importer written later can be
checked against it.

Counts and sums are read from PostgreSQL in the same run that copies the rows,
because GFW revises its own history: between two measurements six weeks apart
alerts_weekly gained 11,598 rows and LOST 40.36 hectares. A checksum taken
yesterday would not match today's data, and the hour spent looking for the
difference would find nothing wrong.

alerts_daily and geostores are not copied: the site never reads either.
field_reports is not copied either, and that is deliberate. Those rows carry a
reporter and a source IP, they exist only where they were filed, and no tool in
this migration is allowed to move or overwrite them.

Needs pymysql:  pip install pymysql
Usage:          python load_local_mysql.py
"""

import sys

import pymysql
import pymysql.converters
from sqlalchemy import create_engine, text

MYSQL = {"host": "127.0.0.1", "port": 3306, "user": "root",
         "password": "", "database": "dm_test"}

# Parents before children: every table below references territories.
TABLES = ["territories", "alerts_weekly", "rankings",
          "rankings_history", "trends", "hotspots"]

# Checked after the copy. Counts must agree exactly. The sums are the two
# figures the whole migration is judged on.
CHECKSUMS = {
    "alerts_weekly": "SUM(lost_ha)",
    "rankings_history": "SUM(area_ha)",
    "territories": "SUM(area_ha)",
}

BATCH = 2000


def neon_url() -> str:
    for line in open(".env"):
        if line.startswith("NEON_URL="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    raise SystemExit("NEON_URL no esta en .env")


def mysql_connect() -> pymysql.connections.Connection:
    # pymysql writes floats with %.15g, which is one digit short of what a
    # double needs to survive the trip. repr() gives the shortest string that
    # reads back as the same bits, so the checksums below compare the data and
    # not the formatting.
    conv = pymysql.converters.conversions.copy()
    conv[float] = lambda value, mapping=None: repr(float(value))
    return pymysql.connect(**MYSQL, conv=conv, autocommit=False)


def main() -> int:
    pg = create_engine(neon_url())
    my = mysql_connect()

    source = {}
    print("== leyendo y copiando ==")

    with pg.connect() as pgc:
        for table in TABLES:
            source[table] = {
                "rows": pgc.execute(text(f"SELECT COUNT(*) FROM {table}")).scalar()
            }
            if table in CHECKSUMS:
                source[table]["sum"] = pgc.execute(
                    text(f"SELECT {CHECKSUMS[table]} FROM {table}")
                ).scalar()

            result = (
                pgc.execution_options(stream_results=True)
                .execute(text(f"SELECT * FROM {table}"))
            )
            cols = list(result.keys())
            # Every name backquoted, which is also what keeps `rank` working.
            collist = ", ".join(f"`{c}`" for c in cols)
            marks = ", ".join(["%s"] * len(cols))
            insert = f"INSERT INTO `{table}` ({collist}) VALUES ({marks})"

            written = 0
            with my.cursor() as cur:
                while True:
                    rows = result.fetchmany(BATCH)
                    if not rows:
                        break
                    cur.executemany(insert, [tuple(r) for r in rows])
                    my.commit()
                    written += len(rows)
                    if written % 100000 == 0:
                        print(f"   {table}: {written:,}")

            print(f"  {table:18} {written:>10,} filas")

    print("\n== verificacion ==")
    ok = True
    with my.cursor() as cur:
        for table in TABLES:
            cur.execute(f"SELECT COUNT(*) FROM `{table}`")
            got_rows = cur.fetchone()[0]
            want_rows = source[table]["rows"]
            same = got_rows == want_rows
            ok &= same
            print(f"  {table:18} filas  {want_rows:>10,} -> {got_rows:>10,}  "
                  f"{'OK' if same else 'DISTINTO'}")

            if table in CHECKSUMS:
                cur.execute(f"SELECT {CHECKSUMS[table]} FROM `{table}`")
                got_sum = float(cur.fetchone()[0])
                want_sum = float(source[table]["sum"])
                diff = abs(got_sum - want_sum)
                # Exact is what repr() above is for. The tolerance is stated
                # rather than decided after seeing the number: a hundredth of a
                # hectare over four million is 2e-9 relative, which is noise
                # from the engines and not a lost row.
                same = diff < 0.01
                ok &= same
                print(f"  {table:18} suma   {want_sum:>16.6f} -> "
                      f"{got_sum:>16.6f}  dif {diff:.9f}  "
                      f"{'OK' if same else 'DISTINTO'}")

    print()
    if ok:
        print("La copia coincide con el origen.")
        return 0
    print("La copia NO coincide. No sigas al paso siguiente.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
