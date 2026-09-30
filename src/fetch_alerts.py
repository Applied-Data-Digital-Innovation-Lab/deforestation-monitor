"""Fetch daily deforestation alerts per territory from the GFW Data API."""

import os
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
import pandas as pd
import requests
from dotenv import load_dotenv
import argparse
from datetime import date, timedelta
from sqlalchemy import bindparam, text
from db import engine

ROOT = Path(__file__).resolve().parents[1]
GEOSTORES = ROOT / "data/processed/geostores.csv"
ALERTS_PATH = ROOT / "data/processed/alerts_daily.csv"
DONE_PATH = ROOT / "data/processed/alerts_done.csv"

DATASET = "gfw_integrated_alerts"
DATASET_URL = f"https://data-api.globalforestwatch.org/dataset/{DATASET}"
BACKFILL_VERSION = "v20260801" # The version used for the two-year backfill, kept fixed so the history comes from one coherent snapshot.
BACKFILL_START = "2024-08-01"
WORKERS = 8
TIMEOUT = 180
MAX_RETRIES = 3
REFRESH_DAYS = 30
MAX_LAG_DAYS = 10

def query_url(version: str) -> str:
    return f"{DATASET_URL}/{version}/query/json"


def latest_version() -> str:
    """Resolve the newest published version at run time.

    GFW publishes a new version daily. A pinned version stops receiving data
    once the next one appears, so the daily refresh keeps succeeding while
    quietly fetching nothing new.
    """
    r = requests.get(DATASET_URL, timeout=TIMEOUT)
    r.raise_for_status()
    return r.json()["data"]["versions"][-1]

def build_sql(start_date: str) -> str:
    # Confidence is stored, not filtered. Alerts take three to four months
    # to mature from nominal to high, so filtering would capture a shrinking
    # share of alerts the more recent the data, making periods incomparable.
    return (
        "SELECT gfw_integrated_alerts__date AS date, "
        "gfw_integrated_alerts__confidence AS confidence, "
        "SUM(area__ha) AS area_ha, "
        "COUNT(*) "
        "FROM results "
        f"WHERE gfw_integrated_alerts__date >= '{start_date}' "
        "GROUP BY gfw_integrated_alerts__date, gfw_integrated_alerts__confidence "
        "ORDER BY gfw_integrated_alerts__date"
    )

load_dotenv(ROOT / ".env")
API_KEY = os.getenv("GFW_API_KEY")

write_lock = threading.Lock()


def fetch(geostore_id: str, sql: str, url: str) -> pd.DataFrame:
    """Query one territory, retrying on server errors."""
    for attempt in range(MAX_RETRIES):
        try:
            r = requests.get(
                url,
                headers={"x-api-key": API_KEY},
                params={
                    "sql": sql,
                    "geostore_id": geostore_id,
                    "geostore_origin": "rw",
                },
                timeout=TIMEOUT,
            )
            if r.status_code == 200:
                return pd.DataFrame(r.json()["data"])
            if r.status_code < 500 and r.status_code != 429:
                raise RuntimeError(f"HTTP {r.status_code}: {r.text[:200]}")
        except requests.RequestException as exc:
            if attempt == MAX_RETRIES - 1:
                raise RuntimeError(str(exc)[:200]) from exc
        time.sleep(2 ** attempt)
    raise RuntimeError("exhausted retries")


def already_done() -> set:
    """Territories already processed, including those with no alerts."""
    if not DONE_PATH.exists():
        return set()
    return set(pd.read_csv(DONE_PATH)["territory_id"])

def refresh_recent(geostores: pd.DataFrame, start_date: str) -> None:
    """Re-query the most recent weeks and replace those rows in Neon.

    Alerts are revised as confidence improves and late detections appear for
    past dates, so the recent window has to be overwritten rather than
    appended to.
    """
    version = latest_version()
    url = query_url(version)
    print(f"using dataset version {version}")

    sql = build_sql(start_date)
    rows, failures = [], []

    def work(territory_id, geostore_id):
        return territory_id, fetch(geostore_id, sql, url)

    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        futures = {
            pool.submit(work, r["territory_id"], r["geostore_id"]): r["territory_id"]
            for _, r in geostores.iterrows()
        }
        for n, future in enumerate(as_completed(futures), start=1):
            territory_id = futures[future]
            try:
                territory_id, df = future.result()
            except RuntimeError as exc:
                failures.append((territory_id, str(exc)))
                continue
            for _, r in df.iterrows():
                rows.append({
                    "territory_id": territory_id,
                    "date": r["date"],
                    "confidence": r["confidence"],
                    "area_ha": r["area_ha"],
                    "alerts": r["count"],
                })
            if n % 500 == 0:
                print(f"{n}/{len(geostores)}")

    if not rows:
        print("nothing fetched, leaving the database untouched")
        return

    fresh = pd.DataFrame(rows)
    fresh["date"] = pd.to_datetime(fresh["date"])

    eng = engine()
    # Only the territories that came back are cleared. A failed fetch has to
    # leave its existing rows alone, otherwise a network error deletes data.
    fetched = fresh["territory_id"].unique()

    stmt = text(
        "DELETE FROM alerts_daily "
        "WHERE date >= :start AND territory_id IN :ids"
    ).bindparams(bindparam("ids", expanding=True))

    with eng.begin() as conn:
        deleted = conn.execute(
            stmt, {"start": start_date, "ids": [int(t) for t in fetched]}
        ).rowcount

    fresh.to_sql("alerts_daily", eng, if_exists="append", index=False,
                 chunksize=5000, method="multi")

    with eng.connect() as conn:
        total = conn.execute(text("SELECT COUNT(*) FROM alerts_daily")).scalar()
        newest = conn.execute(text("SELECT MAX(date) FROM alerts_daily")).scalar()

    print(f"\nreplaced {deleted:,} rows with {len(fresh):,}")
    print(f"{total:,} rows total, {len(failures)} failures")
    for f in failures[:10]:
        print(" ", f)

    lag = (date.today() - newest.date()).days
    print(f"most recent alert: {newest.date()} ({lag} days behind)")

    # The feed can lag by a few days, but it shouldn't stop advancing.
    if lag > MAX_LAG_DAYS:
        raise SystemExit(
            f"no alerts newer than {newest.date()}, {lag} days behind. "
            "The feed has stalled or the dataset version is not advancing."
        )

def backfill(geostores: pd.DataFrame) -> None:
    """Fetch the full history for every territory, resuming if interrupted."""

    done = already_done()
    pending = geostores[~geostores["territory_id"].isin(done)]

    print(f"{len(done)} already fetched, {len(pending)} pending")
    if pending.empty:
        return

    ALERTS_PATH.parent.mkdir(parents=True, exist_ok=True)
    write_alerts_header = not ALERTS_PATH.exists()
    write_done_header = not DONE_PATH.exists()

    failures = []
    completed = 0
    started = time.time()

    alerts_fh = ALERTS_PATH.open("a", encoding="utf-8")
    done_fh = DONE_PATH.open("a", encoding="utf-8")

    if write_alerts_header:
        alerts_fh.write("territory_id,date,confidence,area_ha,alerts\n")
    if write_done_header:
        done_fh.write("territory_id\n")

    sql = build_sql(BACKFILL_START)
    url = query_url(BACKFILL_VERSION)

    def work(territory_id: int, geostore_id: str):
        return territory_id, fetch(geostore_id, sql, url)

    try:
        with ThreadPoolExecutor(max_workers=WORKERS) as pool:
            futures = {
                pool.submit(work, row["territory_id"], row["geostore_id"]): row["territory_id"]
                for _, row in pending.iterrows()
            }

            for future in as_completed(futures):
                territory_id = futures[future]
                try:
                    territory_id, df = future.result()
                except RuntimeError as exc:
                    failures.append((territory_id, str(exc)))
                    continue

                with write_lock:
                    for _, r in df.iterrows():
                        alerts_fh.write(
                            f"{territory_id},{r['date']},{r['confidence']},"
                            f"{r['area_ha']},{r['count']}\n"
                        )
                    # Recorded even when empty, so a territory with no alerts is not retried on the next run.
                    done_fh.write(f"{territory_id}\n")
                    alerts_fh.flush()
                    done_fh.flush()

                    if completed <= 3:
                        print(f"OK {territory_id}: {len(df)} rows")

                    completed += 1
                    if completed % 50 == 0:
                        rate = (time.time() - started) / completed
                        left = (len(pending) - completed) * rate / 60
                        print(
                            f"{completed}/{len(pending)}  "
                            f"{rate:.2f}s/territory  ~{left:.0f} min left"
                        )
    finally:
        alerts_fh.close()
        done_fh.close()

    print(f"\ndone. {completed} fetched, {len(failures)} failed")
    for f in failures[:20]:
        print(" ", f)

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--backfill", action="store_true",
        help="fetch the full history from scratch instead of the recent window",
    )
    args = parser.parse_args()

    if not API_KEY:
        raise SystemExit("GFW_API_KEY not found in .env")

    geostores = pd.read_sql("SELECT territory_id, geostore_id FROM geostores", engine())

    if args.backfill:
        backfill(geostores)
    else:
        start = (date.today() - timedelta(days=REFRESH_DAYS)).isoformat()
        print(f"refreshing from {start} ({len(geostores)} territories)")
        refresh_recent(geostores, start)

if __name__ == "__main__":
    main()