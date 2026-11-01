"""Rebuild rankings_history for the full weekly archive.

This is intended for first-time setup. Rebuilding an existing archive uses
today's data and baselines, not the ones used for the original rankings.
Use --yes to confirm replacing a non-empty archive.
"""

import argparse

import pandas as pd
from sqlalchemy import text

from db import engine
from detect_anomalies import load, to_weekly, score, SETTLED_AFTER_WEEKS

# Early windows have little history for the seasonal baseline, but are kept
# to preserve the 2024 fire season. Their ratios should be treated with care.


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--yes",
        action="store_true",
        help="confirm replacing a non-empty archive",
    )
    args = parser.parse_args()

    # alerts_daily only keeps 180 days. Include the stored weekly series so
    # the rebuild doesn't replace the full archive with just six months.
    alerts, territories, stored = load()
    weekly = to_weekly(alerts, territories, stored)

    weeks = sorted(pd.Series(weekly["week"].unique()))
    print(f"{len(weeks)} weeks to evaluate")

    eng = engine()

    # Check first so we don't spend time computing a rebuild that will be refused.
    with eng.connect() as conn:
        existing = conn.execute(
            text("SELECT COUNT(*) FROM rankings_history")
        ).scalar()
        archived = conn.execute(
            text("SELECT COUNT(DISTINCT window_end) FROM rankings_history")
        ).scalar()

    if existing and not args.yes:
        raise SystemExit(
            f"rankings_history already holds {existing:,} rows across "
            f"{archived} weeks. Rebuilding uses today's baselines rather "
            f"than the ones used originally, and would write {len(weeks)} "
            "weeks in their place. Nothing was written. Add --yes to continue."
        )

    frames = []
    for i, week in enumerate(weeks):
        end = pd.Timestamp(week) + pd.Timedelta(days=6)
        result = score(weekly, alerts, territories, window_end=end)
        result["weeks_of_history"] = i
        result["baseline_settled"] = i >= SETTLED_AFTER_WEEKS
        frames.append(result.reset_index())

        if (i + 1) % 20 == 0:
            print(f"{i + 1}/{len(weeks)}")

    history = pd.concat(frames, ignore_index=True)

    # Keep only territories with recorded loss. Zero-loss weeks remain in
    # alerts_weekly.
    history = history[history["area_ha"] > 0]

    print(f"\n{len(history):,} rows across {len(weeks)} windows")
    print(f"flagged in total: {history['flagged'].sum():,}")

    # Keep the truncate and reload in one transaction so a failed load
    # doesn't leave the archive empty.
    with eng.begin() as conn:
        conn.execute(text("TRUNCATE rankings_history"))
        history.to_sql(
            "rankings_history",
            conn,
            if_exists="append",
            index=False,
            chunksize=5000,
            method="multi",
        )

    print("wrote rankings_history")


if __name__ == "__main__":
    main()