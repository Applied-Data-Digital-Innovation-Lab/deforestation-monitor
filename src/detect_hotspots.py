"""Locate where inside a territory the loss actually happened.

Flagged territories can be large, so the total loss alone is not enough to
show where it is concentrated. GFW alerts have coordinates, which lets us
cluster them into hotspots.

Only flagged territories and the current window are queried.
"""

import numpy as np
import pandas as pd
import requests
from sklearn.cluster import DBSCAN
from sqlalchemy import text

from db import engine
from fetch_alerts import API_KEY, TIMEOUT, latest_version, query_url

# Two kilometres worked across the territory sizes tested.
CLUSTER_KM = 2.0
EARTH_KM = 6371.0

# Below this, treat the alerts as noise.
MIN_ALERTS = 20

# Keep the largest hotspots.
TOP_N = 10

# Hotspot area is pixel count × 0.01 ha (10 m x 10 m pixels).
# Territory totals use GFW's measured SUM(area__ha), so the two won't
# match exactly. The API doesn't expose measured area for each cluster.
HA_PER_ALERT = 0.01


class ResponseTooLarge(RuntimeError):
    """GFW rejects responses over 6 MB."""


def query_points(geostore_id: str, start, end, url: str) -> pd.DataFrame:
    """Alert coordinates for one territory over a date range."""
    sql = (
        "SELECT latitude, longitude FROM results "
        f"WHERE gfw_integrated_alerts__date >= '{pd.Timestamp(start).date()}' "
        f"AND gfw_integrated_alerts__date <= '{pd.Timestamp(end).date()}'"
    )
    r = requests.get(
        url,
        headers={"x-api-key": API_KEY},
        params={"sql": sql, "geostore_id": geostore_id, "geostore_origin": "rw"},
        timeout=TIMEOUT,
    )

    if r.status_code == 500 and "ResponseSizeTooLarge" in r.text:
        raise ResponseTooLarge(f"{geostore_id} over 6 MB")
    if r.status_code != 200:
        raise RuntimeError(f"HTTP {r.status_code}: {r.text[:200]}")

    return pd.DataFrame(r.json().get("data", []))


def fetch_points(geostore_id: str, start, end, url: str) -> pd.DataFrame:
    """Fetch the whole window, falling back to one day at a time."""
    try:
        return query_points(geostore_id, start, end, url)
    except ResponseTooLarge:
        frames = [
            query_points(geostore_id, day, day, url)
            for day in pd.date_range(start, end, freq="D")
        ]
        return pd.concat(frames, ignore_index=True)


def cluster(points: pd.DataFrame) -> pd.DataFrame:
    """Group alerts into hotspots, largest first."""
    if len(points) < MIN_ALERTS:
        return pd.DataFrame()

    # Use haversine distance so no projection is needed.
    coords = np.radians(points[["latitude", "longitude"]].to_numpy())
    labels = DBSCAN(
        eps=CLUSTER_KM / EARTH_KM,
        min_samples=MIN_ALERTS,
        metric="haversine",
        algorithm="ball_tree",
    ).fit(coords).labels_

    points = points.assign(cluster=labels)
    found = points[points["cluster"] >= 0]
    if found.empty:
        return pd.DataFrame()

    out = (
        found.groupby("cluster")
        .agg(alerts=("latitude", "size"),
             lat=("latitude", "mean"),
             lon=("longitude", "mean"))
        .sort_values("alerts", ascending=False)
        .head(TOP_N)
        .reset_index(drop=True)
    )
    out["lost_ha"] = (out["alerts"] * HA_PER_ALERT).round(2)
    out["rank"] = range(1, len(out) + 1)

    # Share of the territory's total alerts.
    out["share_pct"] = (out["alerts"] / len(points) * 100).round(1)
    return out


def main() -> None:
    eng = engine()
    version = latest_version()
    url = query_url(version)
    print(f"using dataset version {version}")

    flagged = pd.read_sql(
        """
        SELECT r.territory_id, r.name, r.window_start, r.window_end,
               g.geostore_id
        FROM rankings r
        JOIN geostores g ON g.territory_id = r.territory_id
        WHERE r.flagged
        ORDER BY r.score DESC
        """,
        eng, parse_dates=["window_start", "window_end"],
    )
    print(f"{len(flagged)} flagged territories to locate")

    rows, dispersed, failures = [], [], []

    for n, t in enumerate(flagged.itertuples(), start=1):
        try:
            points = fetch_points(
                t.geostore_id, t.window_start, t.window_end, url
            )
        except RuntimeError as exc:
            failures.append((t.name, str(exc)[:120]))
            continue

        found = cluster(points)
        if found.empty:
            dispersed.append(t.name)
            continue

        found["territory_id"] = t.territory_id
        found["window_end"] = t.window_end
        rows.append(found)

        if n % 20 == 0:
            print(f"{n}/{len(flagged)}")

    if not rows:
        print("no hotspots found, leaving the table untouched")
        return

    hotspots = pd.concat(rows, ignore_index=True)[
        ["territory_id", "window_end", "rank", "lat", "lon",
         "alerts", "lost_ha", "share_pct"]
    ]

    with eng.begin() as conn:
        conn.execute(text("TRUNCATE hotspots"))

    hotspots.to_sql("hotspots", eng, if_exists="append", index=False,
                    chunksize=5000, method="multi")

    print(f"\n{len(hotspots)} hotspots across "
          f"{hotspots['territory_id'].nunique()} territories")
    print(f"{len(dispersed)} territories too dispersed to locate")
    if failures:
        print(f"{len(failures)} failed")
        for f in failures[:5]:
            print(" ", f)

    top = hotspots.merge(
        flagged[["territory_id", "name"]], on="territory_id"
    ).nlargest(10, "lost_ha")
    print()
    print(top[["name", "rank", "lat", "lon", "lost_ha", "share_pct"]]
          .round(4).to_string(index=False))


if __name__ == "__main__":
    main()
