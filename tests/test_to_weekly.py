"""Keep the archived value for weeks only partly covered by daily data.

The daily table keeps 180 days, so its oldest week is incomplete. Rebuilding
that week overwrites the full archived value with a partial one. The row
count stays the same, so the loss goes unnoticed.
"""

import pandas as pd

from detect_anomalies import to_weekly

TERRITORIES = pd.DataFrame([{"territory_id": 1, "area_ha": 1000.0}])

# Daily data starts on Wednesday 22 April. The week of the 27th is the
# first full week available.
DAILY = pd.DataFrame([
    {"territory_id": 1, "date": "2026-04-22", "area_ha": 10.0},
    {"territory_id": 1, "date": "2026-04-23", "area_ha": 5.0},
    {"territory_id": 1, "date": "2026-04-27", "area_ha": 7.0},
    {"territory_id": 1, "date": "2026-04-30", "area_ha": 3.0},
    {"territory_id": 1, "date": "2026-05-04", "area_ha": 20.0},
])
DAILY["date"] = pd.to_datetime(DAILY["date"])

ARCHIVE = pd.DataFrame([
    {"territory_id": 1, "week": "2026-04-06", "lost_ha": 100.0},
    {"territory_id": 1, "week": "2026-04-13", "lost_ha": 200.0},
    {"territory_id": 1, "week": "2026-04-20", "lost_ha": 300.0},
])
ARCHIVE["week"] = pd.to_datetime(ARCHIVE["week"])


def series(weekly: pd.DataFrame) -> dict:
    """Weekly loss keyed by week, for readable assertions."""
    return {
        week.strftime("%Y-%m-%d"): lost
        for week, lost in zip(weekly["week"], weekly["area_ha"])
    }


def test_boundary_week_keeps_its_archived_value():
    """A partial rebuild must not overwrite the archived value."""
    assert series(to_weekly(DAILY, TERRITORIES, ARCHIVE))["2026-04-20"] == 300.0


def test_weeks_inside_the_window_reconcile_to_the_daily_table():
    got = series(to_weekly(DAILY, TERRITORIES, ARCHIVE))
    assert got["2026-04-27"] == 10.0
    assert got["2026-05-04"] == 20.0


def test_weeks_before_the_window_keep_their_archived_values():
    got = series(to_weekly(DAILY, TERRITORIES, ARCHIVE))
    assert got["2026-04-06"] == 100.0
    assert got["2026-04-13"] == 200.0


def test_an_empty_archive_keeps_the_first_partial_week():
    """Without an archive, keep the partial first week for backfills."""
    assert series(to_weekly(DAILY, TERRITORIES, pd.DataFrame()))["2026-04-20"] == 15.0