"""Delete alert rows older than the retention window.

Older weeks are kept in alerts_weekly, so alerts_daily only needs recent
data. The old daily rows can be fetched again with fetch_alerts.py --backfill.
"""

from sqlalchemy import text

from db import engine

RETAIN_DAYS = 180


def main() -> None:
    eng = engine()
    with eng.begin() as conn:
        deleted = conn.execute(text(
            "DELETE FROM alerts_daily "
            "WHERE date < now() - make_interval(days => :d)"
        ), {"d": RETAIN_DAYS}).rowcount
        remaining = conn.execute(
            text("SELECT COUNT(*) FROM alerts_daily")
        ).scalar()
        oldest = conn.execute(
            text("SELECT MIN(date) FROM alerts_daily")
        ).scalar()

    print(f"pruned {deleted:,} rows, {remaining:,} remain")
    if oldest is not None:
        print(f"oldest alert now: {oldest.date()}")


if __name__ == "__main__":
    main()