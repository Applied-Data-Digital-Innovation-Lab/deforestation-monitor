"""Find territories with a consistent upward trend that the weekly criterion misses."""

import numpy as np
import pandas as pd
from sqlalchemy import text

from db import engine

WINDOW_WEEKS = 8      # weeks to use, including the current one
MIN_POINTS = 6        # minimum points needed to fit a trend
MIN_R2 = 0.5          # filters out noisy trends and single spikes
MIN_SLOPE = 0.0       # only keep upward trends


def fit(group: pd.DataFrame) -> pd.Series:
    """Fit a straight line to one territory's recent ratios."""
    if len(group) < MIN_POINTS:
        return pd.Series({"slope": np.nan, "r2": np.nan, "weeks": len(group)})

    x = group["t"].to_numpy()
    y = group["ratio"].astype(float).to_numpy()

    slope, intercept = np.polyfit(x, y, 1)
    residual = ((y - (slope * x + intercept)) ** 2).sum()
    total = ((y - y.mean()) ** 2).sum()

    r2 = 1 - residual / total if total > 0 else 0.0

    return pd.Series({"slope": slope, "r2": r2, "weeks": len(group)})


def main() -> None:
    eng = engine()

    history = pd.read_sql(
        """
        SELECT territory_id, name, country, window_end, ratio, flagged
        FROM rankings_history
        WHERE baseline_settled AND ratio IS NOT NULL
        ORDER BY territory_id, window_end
        """,
        eng, parse_dates=["window_end"],
    )

    weeks = sorted(history["window_end"].unique())
    current = weeks[-1]

    # Leave the current week out so a single recent event does not create
    # an artificial trend.
    earlier = weeks[-WINDOW_WEEKS:-1]

    series = history[history["window_end"].isin(earlier)].copy()
    series["t"] = series.groupby("territory_id").cumcount()

    fitted = series.groupby("territory_id").apply(
        fit, include_groups=False
    ).dropna()

    rising = fitted[
        (fitted["slope"] > MIN_SLOPE) &
        (fitted["r2"] >= MIN_R2)
    ].copy()

    names = history.drop_duplicates("territory_id").set_index("territory_id")
    rising = rising.join(names[["name", "country"]])

    flagged_now = set(history.loc[
        (history["window_end"] == current) & history["flagged"],
        "territory_id"
    ])

    rising["flagged_now"] = rising.index.isin(flagged_now)
    rising["window_end"] = current
    rising["weeks_fitted"] = rising["weeks"].astype(int)

    rising = rising.drop(columns="weeks").sort_values(
        "slope", ascending=False
    )

    print(f"{len(fitted):,} territories with enough series to fit")
    print(f"{len(rising)} rising consistently")
    print(f"of those, already flagged this week: {rising['flagged_now'].sum()}")
    print()
    print(rising.head(10)[
        ["name", "country", "slope", "r2", "flagged_now"]
    ].round(3).to_string())

    rising.reset_index().to_sql(
        "trends",
        eng,
        if_exists="replace",
        index=False,
        chunksize=5000,
        method="multi",
    )

    with eng.begin() as conn:
        conn.execute(text(
            "CREATE INDEX IF NOT EXISTS idx_trends_territory "
            "ON trends (territory_id)"
        ))

    print(f"\nwrote {len(rising)} rows to trends")


if __name__ == "__main__":
    main()