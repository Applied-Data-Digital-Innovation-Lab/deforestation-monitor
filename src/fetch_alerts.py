"""Fetch daily deforestation alerts per territory from the GFW Data API."""

import os
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
import pandas as pd
import requests
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parents[1]
GEOSTORES = ROOT / "data/processed/geostores.csv"
ALERTS_PATH = ROOT / "data/processed/alerts_daily.csv"
DONE_PATH = ROOT / "data/processed/alerts_done.csv"

DATASET = "gfw_integrated_alerts"
VERSION = "v20260801"
BASE = f"https://data-api.globalforestwatch.org/dataset/{DATASET}/{VERSION}/query/json"

START_DATE = "2024-08-01"
WORKERS = 8
TIMEOUT = 180
MAX_RETRIES = 3

# Confidence is stored, not filtered. Alerts take three to four months
# to mature from nominal to high, so filtering would capture a shrinking
# share of alerts the more recent the data, making periods incomparable.

SQL = (
    "SELECT gfw_integrated_alerts__date AS date, "
    "gfw_integrated_alerts__confidence AS confidence, "
    "SUM(area__ha) AS area_ha, "
    "COUNT(*) "
    "FROM results "
    f"WHERE gfw_integrated_alerts__date >= '{START_DATE}' "
    "GROUP BY gfw_integrated_alerts__date, gfw_integrated_alerts__confidence "
    "ORDER BY gfw_integrated_alerts__date"
)

load_dotenv(ROOT / ".env")
API_KEY = os.getenv("GFW_API_KEY")

write_lock = threading.Lock()


def fetch(geostore_id: str) -> pd.DataFrame:
    """Query one territory, retrying on server errors."""
    for attempt in range(MAX_RETRIES):
        try:
            r = requests.get(
                BASE,
                headers={"x-api-key": API_KEY},
                params={
                    "sql": SQL,
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


def main() -> None:
    if not API_KEY:
        raise SystemExit("GFW_API_KEY not found in .env")

    geostores = pd.read_csv(GEOSTORES)
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

    def work(territory_id: int, geostore_id: str):
        return territory_id, fetch(geostore_id)

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


if __name__ == "__main__":
    main()