-- MySQL schema for the deforestation monitor's serving database.
--
-- This is the database the PHP site reads, and nothing else writes to it
-- except database/import.php. It is not the pipeline's database: the pipeline
-- goes on working in PostgreSQL, computes the baselines there, and publishes
-- into this one. Two of the pipeline's tables are absent on purpose,
-- alerts_daily and geostores, because the site never reads either.
--
-- There is no CREATE DATABASE and no USE here. On cPanel the database and its
-- user are created from the MySQL Databases page, and phpMyAdmin imports into
-- whichever database is selected there.
--
-- Import order matters: territories first, since every other table references
-- it. Run this file before database/sample_data.sql.
--
-- WHAT DIFFERS FROM THE POSTGRESQL SCHEMA, AND WHY
--
--   TIMESTAMP -> DATETIME. In MySQL, TIMESTAMP converts to and from the
--   session's time zone on every read and write, and its range ends in 2038.
--   DATETIME stores the value as written, which is what PostgreSQL's TIMESTAMP
--   (without time zone) already did. Using MySQL's TIMESTAMP here would let the
--   displayed week shift by a day depending on who was connected.
--
--   TEXT -> VARCHAR wherever the column is indexed or constrained, because
--   MySQL cannot index a TEXT column without a prefix length. `note` stays
--   TEXT: it is neither indexed nor constrained.
--
--   SERIAL -> INT AUTO_INCREMENT, and now() -> CURRENT_TIMESTAMP.
--
--   EXTRACT(DOW FROM window_end) = 0 -> DAYOFWEEK(window_end) = 1. Both mean
--   Sunday. PostgreSQL numbers the week from Sunday = 0 and MySQL from
--   Sunday = 1, so the literal translation would have validated the wrong day.
--
--   `rank` is backquoted. RANK became a reserved word in MySQL 8 when the
--   window functions arrived, so the bare name is a syntax error there.
--
--   Every REFERENCES names its column. PostgreSQL defaults to the primary key
--   of the parent table; MySQL requires the column.
--
--   Two indexes from the PostgreSQL schema are gone, noted at their tables.
--   Both duplicated the leftmost column of a UNIQUE key, which InnoDB can
--   already use on its own, so they cost disk and bought nothing.
--
--   utf8mb4 and utf8mb4_unicode_ci are stated on every table rather than left
--   to the server default, which is latin1 on some MariaDB builds and would
--   corrupt the accented territory names on import without raising an error.
--   utf8mb4_unicode_ci exists on both MySQL and MariaDB.


-- The territory set, from RAISG. Every other table points here.
CREATE TABLE IF NOT EXISTS territories (
    territory_id  INT PRIMARY KEY,
    name          VARCHAR(255),
    country       VARCHAR(100),
    area_ha       DOUBLE NOT NULL CHECK (area_ha > 0),
    population    DOUBLE,
    communities   DOUBLE,
    lat           DOUBLE,
    lon           DOUBLE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Weekly series behind the history charts. One row per territory and week.
--
-- idx_alerts_weekly_territory is not here: territory_id is the leftmost column
-- of uq_alerts_weekly, so InnoDB uses that key for a lookup by territory and
-- for the foreign key below.
CREATE TABLE IF NOT EXISTS alerts_weekly (
    territory_id     INT NOT NULL,
    week             DATETIME NOT NULL,
    lost_ha          DOUBLE NOT NULL,
    lost_per_1000ha  DOUBLE NOT NULL,
    UNIQUE KEY uq_alerts_weekly (territory_id, week),
    CONSTRAINT fk_alerts_weekly_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The window being evaluated now, replaced on every publish.
CREATE TABLE IF NOT EXISTS rankings (
    territory_id       INT PRIMARY KEY,
    area_ha            DOUBLE NOT NULL,
    lost_per_1000ha    DOUBLE NOT NULL,
    territory_ha       DOUBLE NOT NULL,
    seasonal_baseline  DOUBLE,
    window_obs         INT,
    active_weeks       INT,
    ratio              DOUBLE,
    method             VARCHAR(32) NOT NULL
                       CHECK (method IN ('seasonal_baseline', 'sparse_history')),
    flagged            BOOLEAN NOT NULL,
    score              DOUBLE,
    name               VARCHAR(255),
    country            VARCHAR(100),
    population         DOUBLE,
    window_start       DATETIME NOT NULL,
    window_end         DATETIME NOT NULL,
    CONSTRAINT fk_rankings_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The same ranking recalculated for every window in the series. window_end is
-- always a Sunday, which the CHECK states.
--
-- idx_rh_window is not here: window_end is the leftmost column of
-- uq_rankings_history. idx_rh_territory stays, because territory_id is that
-- key's second column and a lookup by territory alone cannot use it.
CREATE TABLE IF NOT EXISTS rankings_history (
    territory_id       INT NOT NULL,
    area_ha            DOUBLE NOT NULL,
    lost_per_1000ha    DOUBLE NOT NULL,
    territory_ha       DOUBLE NOT NULL,
    seasonal_baseline  DOUBLE,
    window_obs         INT,
    active_weeks       INT,
    ratio              DOUBLE,
    method             VARCHAR(32) NOT NULL,
    flagged            BOOLEAN NOT NULL,
    score              DOUBLE,
    name               VARCHAR(255),
    country            VARCHAR(100),
    population         DOUBLE,
    window_start       DATETIME NOT NULL,
    window_end         DATETIME NOT NULL,
    weeks_of_history   INT NOT NULL,
    baseline_settled   BOOLEAN NOT NULL,
    UNIQUE KEY uq_rankings_history (window_end, territory_id),
    KEY idx_rh_territory (territory_id),
    CONSTRAINT fk_rankings_history_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id),
    CONSTRAINT chk_rankings_history_sunday
        CHECK (DAYOFWEEK(window_end) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Territories whose anomaly ratio is climbing without having spiked yet.
CREATE TABLE IF NOT EXISTS trends (
    territory_id   INT PRIMARY KEY,
    slope          DOUBLE NOT NULL,
    r2             DOUBLE,
    name           VARCHAR(255),
    country        VARCHAR(100),
    flagged_now    BOOLEAN NOT NULL,
    window_end     DATETIME NOT NULL,
    weeks_fitted   INT NOT NULL,
    CONSTRAINT fk_trends_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Where inside a flagged territory the loss happened, current window only.
CREATE TABLE IF NOT EXISTS hotspots (
    territory_id  INT NOT NULL,
    window_end    DATETIME NOT NULL,
    `rank`        INT NOT NULL,
    lat           DOUBLE NOT NULL,
    lon           DOUBLE NOT NULL,
    alerts        INT NOT NULL,
    lost_ha       DOUBLE NOT NULL,
    share_pct     DOUBLE NOT NULL,
    UNIQUE KEY uq_hotspots (territory_id, window_end, `rank`),
    CONSTRAINT fk_hotspots_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Reports filed from the site by people who went to look. Hidden until
-- approved. The only table the site writes to, and the only one the importer
-- must never touch: these rows exist here and nowhere else.
--
-- reporter and source_ip are kept for follow-up and for the rate limit, and
-- neither is ever published. They are also why this table ships empty: the
-- repository is public, and the guide's section 7 rules out real personal data
-- in it.
CREATE TABLE IF NOT EXISTS field_reports (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    territory_id  INT NOT NULL,
    window_end    DATETIME,
    verdict       VARCHAR(16) NOT NULL
                  CHECK (verdict IN ('confirmed', 'not_found', 'other_cause', 'unsure')),
    note          TEXT,
    reporter      VARCHAR(200),
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_ip     VARCHAR(45),
    approved      BOOLEAN NOT NULL DEFAULT FALSE,
    KEY idx_field_reports_territory (territory_id, submitted_at DESC),
    KEY idx_field_reports_ip (source_ip, submitted_at),
    CONSTRAINT fk_field_reports_territory
        FOREIGN KEY (territory_id) REFERENCES territories (territory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
