"""Rank territories by how unusual their recent forest loss is."""

from pathlib import Path

from db import engine
import pandas as pd

from sqlalchemy import text

ROOT = Path(__file__).resolve().parents[1]
OUT_PATH = ROOT / "data/processed/rankings.csv"

# Each territory is compared with its own history for this time of year,
# rather than with a fixed threshold or with other territories.
BASELINE_PCT = 0.90        # lower than 95: with ~20 observations, 95 can depend on just one or two weeks
SEASON_WINDOW = 4          # weeks either side of the target week of year
MIN_ACTIVE_WEEKS = 20      # below this, there is not enough history for a useful percentile
MIN_LOST_HA = 5.0          # a spike of two hectares is probably not worth a field trip
MIN_RATIO = 2.0            # how far above its own baseline a territory must be to be flagged
WINDOW_DAYS = 7
SETTLED_AFTER_WEEKS = 20   # below this, the baseline rests on too little history


def load() -> tuple[pd.DataFrame, pd.DataFrame]:
    """Read the daily alerts and the territory dimension from Neon."""
    eng = engine()
    alerts = pd.read_sql(
        "SELECT territory_id, date, confidence, area_ha, alerts FROM alerts_daily",
        eng, parse_dates=["date"],
    )
    territories = pd.read_sql(
        "SELECT territory_id, name, country, area_ha, population, lat, lon "
        "FROM territories",
        eng,
    )
    return alerts, territories


def to_weekly(alerts: pd.DataFrame, territories: pd.DataFrame) -> pd.DataFrame:
    """One row per territory per week, zeros included."""
    alerts = alerts.copy()
    alerts["week"] = alerts["date"].dt.to_period("W").dt.start_time

    weekly = (
        alerts.groupby(["territory_id", "week"])["area_ha"].sum().reset_index()
    )

    # A week with no alerts is still a real zero. Otherwise, each territory's
    # history would only include weeks when it lost forest, making the baseline
    # higher than it should be.
    grid = pd.MultiIndex.from_product(
        [
            territories["territory_id"].unique(),
            pd.date_range(weekly["week"].min(), weekly["week"].max(), freq="W-MON"),
        ],
        names=["territory_id", "week"],
    )
    weekly = (
        weekly.set_index(["territory_id", "week"])
        .reindex(grid, fill_value=0)
        .reset_index()
    )

    weekly = weekly.merge(
        territories[["territory_id", "area_ha"]].rename(
            columns={"area_ha": "territory_ha"}
        ),
        on="territory_id",
    )
    weekly["lost_per_1000ha"] = weekly["area_ha"] / weekly["territory_ha"] * 1000
    weekly["woy"] = weekly["week"].dt.isocalendar().week.astype(int)
    return weekly


def seasonal_baselines(history: pd.DataFrame, target_woy: int) -> pd.DataFrame:
    """Calculate the baseline from weeks around the same time of year."""
    distance = (history["woy"] - target_woy).abs()

    # Weeks 1 and 52 are neighbours, not fifty weeks apart.
    distance = distance.where(distance <= 26, 52 - distance)
    window = history[distance <= SEASON_WINDOW]

    baseline = window.groupby("territory_id")["lost_per_1000ha"].agg(
        seasonal_baseline=lambda s: s.quantile(BASELINE_PCT),
        window_obs="size",
    )

    # This is based on the full history, not just the seasonal window.
    # The window only has around twenty observations, so requiring twenty
    # active weeks there would leave almost every territory without a baseline.
    active = history.groupby("territory_id")["lost_per_1000ha"].agg(
        active_weeks=lambda s: (s > 0).sum()
    )

    return baseline.join(active)


def current_window(alerts: pd.DataFrame, territories: pd.DataFrame, window_end=None):
    """Use the seven days ending on the given date, or on the latest data."""
    last_date = pd.Timestamp(window_end) if window_end is not None else alerts["date"].max()
    start = last_date - pd.Timedelta(days=WINDOW_DAYS - 1)

    lost = (
        alerts[(alerts["date"] >= start) & (alerts["date"] <= last_date)]
        .groupby("territory_id")["area_ha"].sum()
    )

    out = (
        territories[["territory_id", "area_ha"]]
        .rename(columns={"area_ha": "territory_ha"})
        .set_index("territory_id")
        .join(lost.rename("area_ha"))
        .fillna({"area_ha": 0.0})
    )
    out["lost_per_1000ha"] = out["area_ha"] / out["territory_ha"] * 1000
    return out, last_date, start


def score(
    weekly: pd.DataFrame,
    alerts: pd.DataFrame,
    territories: pd.DataFrame,
    window_end=None,
) -> pd.DataFrame:
    current, last_date, start = current_window(alerts, territories, window_end)
    target_woy = int(pd.Timestamp(last_date).isocalendar().week)

    # Leave the current window out of the history. Otherwise, an unusually
    # bad week could raise its own baseline and make the spike less obvious.
    history = weekly[weekly["week"] < start]

    result = current[["area_ha", "lost_per_1000ha", "territory_ha"]].join(
        seasonal_baselines(history, target_woy)
    )

    result["ratio"] = (
        result["lost_per_1000ha"] / result["seasonal_baseline"]
    ).replace([float("inf"), -float("inf")], pd.NA).astype("Float64")

    sparse = (
        result["seasonal_baseline"].isna()
        | (result["seasonal_baseline"] == 0)
        | (result["active_weeks"] < MIN_ACTIVE_WEEKS)
    )
    result["method"] = "seasonal_baseline"
    result.loc[sparse, "method"] = "sparse_history"

    result["flagged"] = (result["area_ha"] >= MIN_LOST_HA) & (
        (result["ratio"] >= MIN_RATIO) | sparse
    )

    # The ratio shows how unusual the event is, while area shows how much
    # forest was actually lost. The square root keeps huge events from
    # completely dominating the ranking.
    result["score"] = result["ratio"].fillna(MIN_RATIO) * (
        result["area_ha"] ** 0.5
    )

    result = result.join(
        territories.set_index("territory_id")[["name", "country", "population"]]
    )
    result["window_start"] = start
    result["window_end"] = last_date
    return result.sort_values("score", ascending=False)


def main() -> None:
    alerts, territories = load()
    weekly = to_weekly(alerts, territories)
    result = score(weekly, alerts, territories)

    flagged = result[result["flagged"]]
    print(
        f"window: {result['window_start'].iloc[0].date()} "
        f"to {result['window_end'].iloc[0].date()}"
    )
    print(f"territories: {len(result)}")
    print(f"flagged: {len(flagged)}")
    print(flagged["method"].value_counts().to_string())

    eng = engine()

    result.reset_index().to_sql(
        "rankings", eng, if_exists="replace", index=False,
        chunksize=5000, method="multi",
    )
    print(f"\nwrote {len(result):,} rows to rankings")

    weekly[["territory_id", "week", "area_ha", "lost_per_1000ha"]].rename(
        columns={"area_ha": "lost_ha"}
    ).to_sql(
        "alerts_weekly", eng, if_exists="replace", index=False,
        chunksize=5000, method="multi",
    )
    print(f"wrote {len(weekly):,} rows to alerts_weekly")

    # rankings uses a rolling seven-day window for the site. The archive stores
    # calendar weeks to avoid overlapping observations in trend analysis.

    last_date = alerts["date"].max()
    week_end = last_date - pd.Timedelta(days=(last_date.weekday() + 1) % 7)
    archived = score(weekly, alerts, territories, window_end=week_end)

    archive = archived.reset_index().copy()
    start = archived["window_start"].iloc[0]
    archive["weeks_of_history"] = int(
        weekly.loc[weekly["week"] < start, "week"].nunique()
    )
    archive["baseline_settled"] = (
        archive["weeks_of_history"] >= SETTLED_AFTER_WEEKS
    )

    with eng.begin() as conn:
        # Rewritten every day until the week is behind us, so late alerts land
        # in the archive too.
        conn.execute(
            text("DELETE FROM rankings_history WHERE window_end = :w"),
            {"w": week_end},
        )

    archive.to_sql(
        "rankings_history",
        eng,
        if_exists="append",
        index=False,
        chunksize=5000,
        method="multi",
    )
    print(
        f"archived {len(archive):,} rows "
        f"for the week ending {week_end.date()}"
    )

    with eng.begin() as conn:
        conn.execute(text(
            "CREATE INDEX IF NOT EXISTS idx_alerts_weekly_territory "
            "ON alerts_weekly (territory_id)"
        ))
        conn.execute(text(
            "CREATE INDEX IF NOT EXISTS idx_rankings_territory "
            "ON rankings (territory_id)"
        ))

    cols = [
        "name",
        "country",
        "area_ha",
        "lost_per_1000ha",
        "seasonal_baseline",
        "ratio",
        "score",
    ]
    print()
    print(flagged.head(10)[cols].round(2).to_string())


if __name__ == "__main__":
    main()