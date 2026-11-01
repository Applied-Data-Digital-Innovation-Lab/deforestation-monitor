"""Back up and restore the two tables that cannot be rebuilt exactly.

GFW revises historical figures and retires dataset versions, so fetching old
data cannot reproduce the original series or the baselines used at the time.

field_reports is excluded because it contains reporter and source_ip. Since
this repository is public, those fields must stay out of Actions artifacts.
The owner must back up that table separately and keep it private.

Snapshots use gzipped CSV to avoid extra dependencies and remain easy to read.
"""

import argparse
from pathlib import Path

import pandas as pd
from sqlalchemy import text

from db import engine

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "data/snapshots"

# Only these two tables are allowed. restore() refuses any other name so a
# renamed snapshot cannot be loaded into the wrong table.
TABLES = {
    "alerts_weekly": ["week"],
    "rankings_history": ["window_start", "window_end"],
}


def export() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    eng = engine()
    stamp = pd.Timestamp.now(tz="UTC").strftime("%Y%m%d")

    for name in TABLES:
        df = pd.read_sql(f"SELECT * FROM {name}", eng)
        path = OUT / f"{name}-{stamp}.csv.gz"
        df.to_csv(path, index=False, compression="gzip")
        print(
            f"{name}: {len(df):,} rows, "
            f"{path.stat().st_size / 1e6:.1f} MB -> {path.name}"
        )


def restore(path: Path, confirmed: bool) -> None:
    """Replace a table with a snapshot after showing the row counts."""
    name = path.name.split("-")[0]

    if name not in TABLES:
        raise SystemExit(
            f"{path.name} does not name one of {', '.join(TABLES)}. "
            "The table is taken from the filename, so renamed snapshots "
            "are refused to avoid loading data into the wrong table."
        )

    df = pd.read_csv(path, parse_dates=TABLES[name])
    eng = engine()

    with eng.connect() as conn:
        current = conn.execute(
            text(f"SELECT COUNT(*) FROM {name}")
        ).scalar()

    print(f"{name}: {current:,} rows now, {len(df):,} rows in the snapshot")

    if not confirmed:
        raise SystemExit(
            "Nothing was written. This replaces the table with the snapshot "
            "and cannot be undone. Run again with --yes once you've checked "
            "the row counts."
        )

    # Keep the truncate and reload in one transaction. If the load fails,
    # the original table is left untouched.
    with eng.begin() as conn:
        conn.execute(text(f"TRUNCATE {name}"))
        df.to_sql(
            name,
            conn,
            if_exists="append",
            index=False,
            chunksize=5000,
            method="multi",
        )

    with eng.connect() as conn:
        after = conn.execute(
            text(f"SELECT COUNT(*) FROM {name}")
        ).scalar()

    print(f"restored {after:,} rows to {name}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--restore",
        metavar="FILE",
        help="replace one table with this snapshot",
    )
    parser.add_argument(
        "--yes",
        action="store_true",
        help="confirm the truncate and reload",
    )
    args = parser.parse_args()

    if args.restore:
        restore(Path(args.restore), args.yes)
    else:
        export()


if __name__ == "__main__":
    main()