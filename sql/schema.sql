```sql
-- Database schema for the deforestation monitor.
--
-- The tables used to be created by pandas.to_sql, which meant no keys or
-- constraints were enforced. This schema keeps those rules in the database.
--
-- Run once against a fresh database, before seed_neon.py. The pipeline
-- truncates and appends without replacing the tables.

-- Territory set. Updated when RAISG publishes a new release.
CREATE TABLE IF NOT EXISTS territories (
    territory_id  INTEGER PRIMARY KEY,
    name          TEXT,
    country       TEXT,
    area_ha       DOUBLE PRECISION NOT NULL CHECK (area_ha > 0),
    population    DOUBLE PRECISION,
    communities   DOUBLE PRECISION,
    lat           DOUBLE PRECISION,
    lon           DOUBLE PRECISION
);

-- One GFW geostore per territory.
CREATE TABLE IF NOT EXISTS geostores (
    territory_id  INTEGER PRIMARY KEY REFERENCES territories,
    geostore_id   TEXT NOT NULL
);

-- One row per territory, day and confidence tier.
-- The combination must be unique to avoid counting the same reading twice.
CREATE TABLE IF NOT EXISTS alerts_daily (
    territory_id  INTEGER NOT NULL REFERENCES territories,
    date          TIMESTAMP NOT NULL,
    confidence    TEXT NOT NULL,
    area_ha       DOUBLE PRECISION NOT NULL,
    alerts        INTEGER NOT NULL,
    UNIQUE (territory_id, date, confidence)
);

CREATE INDEX IF NOT EXISTS idx_alerts_daily_date ON alerts_daily (date);
CREATE INDEX IF NOT EXISTS idx_alerts_daily_territory ON alerts_daily (territory_id);

-- Weekly series used by the history charts.
-- The unique constraint keeps one row per territory and week.
CREATE TABLE IF NOT EXISTS alerts_weekly (
    territory_id     INTEGER NOT NULL REFERENCES territories,
    week             TIMESTAMP NOT NULL,
    lost_ha          DOUBLE PRECISION NOT NULL,
    lost_per_1000ha  DOUBLE PRECISION NOT NULL,
    UNIQUE (territory_id, week)
);

CREATE INDEX IF NOT EXISTS idx_alerts_weekly_territory ON alerts_weekly (territory_id);

-- Current rolling window, rewritten daily.
CREATE TABLE IF NOT EXISTS rankings (
    territory_id       INTEGER PRIMARY KEY REFERENCES territories,
    area_ha            DOUBLE PRECISION NOT NULL,
    lost_per_1000ha    DOUBLE PRECISION NOT NULL,
    territory_ha       DOUBLE PRECISION NOT NULL,
    seasonal_baseline  DOUBLE PRECISION,
    window_obs         INTEGER,
    active_weeks       INTEGER,
    ratio              DOUBLE PRECISION,
    method             TEXT NOT NULL CHECK (method IN ('seasonal_baseline', 'sparse_history')),
    flagged             BOOLEAN NOT NULL,
    score               DOUBLE PRECISION,
    name                TEXT,
    country             TEXT,
    population          DOUBLE PRECISION,
    window_start        TIMESTAMP NOT NULL,
    window_end          TIMESTAMP NOT NULL
);

-- Historical weekly rankings. window_end is always a Sunday.
-- The unique key prevents the same calendar week from being stored twice.
CREATE TABLE IF NOT EXISTS rankings_history (
    territory_id       INTEGER NOT NULL REFERENCES territories,
    area_ha            DOUBLE PRECISION NOT NULL,
    lost_per_1000ha    DOUBLE PRECISION NOT NULL,
    territory_ha       DOUBLE PRECISION NOT NULL,
    seasonal_baseline  DOUBLE PRECISION,
    window_obs         INTEGER,
    active_weeks       INTEGER,
    ratio              DOUBLE PRECISION,
    method             TEXT NOT NULL,
    flagged             BOOLEAN NOT NULL,
    score               DOUBLE PRECISION,
    name                TEXT,
    country             TEXT,
    population          DOUBLE PRECISION,
    window_start        TIMESTAMP NOT NULL,
    window_end          TIMESTAMP NOT NULL,
    weeks_of_history   INTEGER NOT NULL,
    baseline_settled   BOOLEAN NOT NULL,
    UNIQUE (window_end, territory_id),
    CHECK (EXTRACT(DOW FROM window_end) = 0)
);

CREATE INDEX IF NOT EXISTS idx_rh_window ON rankings_history (window_end);
CREATE INDEX IF NOT EXISTS idx_rh_territory ON rankings_history (territory_id);

-- Territories with a rising trend in the current window.
CREATE TABLE IF NOT EXISTS trends (
    territory_id   INTEGER PRIMARY KEY REFERENCES territories,
    slope           DOUBLE PRECISION NOT NULL,
    r2              DOUBLE PRECISION,
    name            TEXT,
    country         TEXT,
    flagged_now     BOOLEAN NOT NULL,
    window_end      TIMESTAMP NOT NULL,
    weeks_fitted    INTEGER NOT NULL
);

-- Where inside a territory the loss happened, for the flagged ones.
CREATE TABLE IF NOT EXISTS hotspots (
    territory_id  INTEGER NOT NULL REFERENCES territories,
    window_end    TIMESTAMP NOT NULL,
    rank          INTEGER NOT NULL,
    lat           DOUBLE PRECISION NOT NULL,
    lon           DOUBLE PRECISION NOT NULL,
    alerts        INTEGER NOT NULL,
    lost_ha       DOUBLE PRECISION NOT NULL,
    share_pct     DOUBLE PRECISION NOT NULL,
    UNIQUE (territory_id, window_end, rank)
);
```
