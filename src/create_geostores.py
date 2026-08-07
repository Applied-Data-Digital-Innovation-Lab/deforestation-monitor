"""Register each territory with the GFW geostore endpoint."""

import os
import time
from pathlib import Path
import geopandas as gpd
import pandas as pd
import requests
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parents[1]
TERRITORIES = ROOT / "data/processed/territories.parquet"
OUT_PATH = ROOT / "data/processed/geostores.csv"

GEOSTORE_URL = "https://data-api.globalforestwatch.org/geostore"
TIMEOUT = 180
MAX_RETRIES = 3
PAUSE = 0.2

load_dotenv(ROOT / ".env")
API_KEY = os.getenv("GFW_API_KEY")


def register(geometry: dict) -> str:
    """Send one polygon and return its geostore id, retrying on failure."""
    for attempt in range(MAX_RETRIES):
        try:
            r = requests.post(
                GEOSTORE_URL,
                headers={"x-api-key": API_KEY, "Content-Type": "application/json"},
                json={"geometry": geometry},
                timeout=TIMEOUT,
            )
            if r.status_code in (200, 201):
                return r.json()["data"]["gfw_geostore_id"]
            if r.status_code < 500 and r.status_code != 429:
                raise RuntimeError(f"HTTP {r.status_code}: {r.text[:200]}")
        except requests.RequestException as exc:
            if attempt == MAX_RETRIES - 1:
                raise RuntimeError(str(exc)[:200]) from exc
        time.sleep(2 ** attempt)
    raise RuntimeError("exhausted retries")


def already_done() -> set:
    """Territory ids already written, so an interrupted run can resume."""
    if not OUT_PATH.exists():
        return set()
    return set(pd.read_csv(OUT_PATH)["territory_id"])


def main() -> None:
    if not API_KEY:
        raise SystemExit("GFW_API_KEY not found in .env")

    territories = gpd.read_parquet(TERRITORIES)
    done = already_done()
    pending = territories[~territories["territory_id"].isin(done)]

    print(f"{len(done)} already registered, {len(pending)} pending")
    if pending.empty:
        return

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    write_header = not OUT_PATH.exists()

    failures = []
    started = time.time()

    with OUT_PATH.open("a", encoding="utf-8") as fh:
        if write_header:
            fh.write("territory_id,geostore_id\n")

        for n, (_, row) in enumerate(pending.iterrows(), start=1):
            try:
                geostore_id = register(row.geometry.__geo_interface__)
            except RuntimeError as exc:
                failures.append((row["territory_id"], row["name"], str(exc)))
                continue

            # Flushed on every row so an interrupted run loses nothing.
            fh.write(f"{row['territory_id']},{geostore_id}\n")
            fh.flush()

            if n % 50 == 0:
                rate = (time.time() - started) / n
                left = (len(pending) - n) * rate / 60
                print(f"{n}/{len(pending)}  {rate:.2f}s/req  ~{left:.0f} min left")

            time.sleep(PAUSE)

    ok = len(pending) - len(failures)
    print(f"\ndone. {ok} registered, {len(failures)} failed")
    for f in failures:
        print(" ", f)


if __name__ == "__main__":
    main()