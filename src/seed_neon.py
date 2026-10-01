"""Upload the tables that only change when the source data changes."""

from pathlib import Path

import geopandas as gpd
import pandas as pd
from sqlalchemy import text

from db import engine

ROOT = Path(__file__).resolve().parents[1]
TERRITORIES = ROOT / "data/processed/territories.parquet"
GEOSTORES = ROOT / "data/processed/geostores.csv"
ALERTS = ROOT / "data/processed/alerts_daily.csv"

KEEP = [
    "territory_id", "name", "country", "area_ha",
    "population", "communities", "lat", "lon",
]


def main() -> None:
    eng = engine()

    gdf = gpd.read_parquet(TERRITORIES)

    # representative_point stays inside the boundary, unlike a centroid,
    # which can fall outside crescent-shaped or multi-part territories.
    # Computing it once here means the daily pipeline does not need the
    # geometries at all.
    points = gdf.geometry.representative_point()
    dim = gdf.drop(columns="geometry").copy()
    dim["lat"] = points.y
    dim["lon"] = points.x
    dim = dim[[c for c in KEEP if c in dim.columns]]

    geostores = pd.read_csv(GEOSTORES)
    alerts = pd.read_csv(ALERTS, parse_dates=["date"])

    for name, df in [
        ("territories", dim),
        ("geostores", geostores),
        ("alerts_daily", alerts),
    ]:
        print(f"writing {name}: {len(df):,} rows")
        # CASCADE, because every other table references territories: this
        # empties the whole database, and the pipeline rebuilds it.
        with eng.begin() as conn:
            conn.execute(text(f"TRUNCATE {name} CASCADE"))
        df.to_sql(name, eng, if_exists="append", index=False,
                  chunksize=5000, method="multi")

    with eng.connect() as conn:
        for name in ("territories", "geostores", "alerts_daily"):
            n = conn.execute(text(f"SELECT COUNT(*) FROM {name}")).scalar()
            print(f"{name}: {n:,} rows in Neon")


if __name__ == "__main__":
    main()