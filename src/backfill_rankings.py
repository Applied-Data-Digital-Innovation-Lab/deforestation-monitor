"""Evaluate every past window, so the site can show any week of the history."""

import pandas as pd
from sqlalchemy import text

from db import engine
from detect_anomalies import load, to_weekly, score, MIN_ACTIVE_WEEKS, SETTLED_AFTER_WEEKS

# A window this early has almost no history behind it: the seasonal baseline
# rests on a handful of weeks, or on none at all. Those rows are published
# anyway, because they cover the 2024 fire season, which is the most
# significant event in the record. The flag says not to trust the ratio.

def main() -> None:
    alerts, territories = load()
    weekly = to_weekly(alerts, territories)

    weeks = sorted(pd.Series(weekly["week"].unique()))
    print(f"{len(weeks)} weeks to evaluate")

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

    # Only archive territories with recorded loss. The full series, zeros included,
    # is kept in alerts_weekly.
    history = history[history["area_ha"] > 0]

    print(f"\n{len(history):,} rows across {len(weeks)} windows")
    print(f"flagged in total: {history['flagged'].sum():,}")

    eng = engine()
    with eng.begin() as conn:
        conn.execute(text("TRUNCATE rankings_history"))

    history.to_sql("rankings_history", eng, if_exists="append", index=False,
                   chunksize=5000, method="multi")

    print("wrote rankings_history")


if __name__ == "__main__":
    main()