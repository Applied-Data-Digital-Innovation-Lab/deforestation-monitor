-- Keys and constraints for the deforestation monitor.
--
-- Applied once against an existing database. schema.sql is used for fresh
-- databases. Both define the same structure.

ALTER TABLE territories
    ADD PRIMARY KEY (territory_id),
    ALTER COLUMN area_ha SET NOT NULL,
    ADD CHECK (area_ha > 0);

ALTER TABLE geostores
    ADD PRIMARY KEY (territory_id),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ALTER COLUMN geostore_id SET NOT NULL;

-- One reading per territory, day and confidence tier.
ALTER TABLE alerts_daily
    ADD UNIQUE (territory_id, date, confidence),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ALTER COLUMN date SET NOT NULL,
    ALTER COLUMN confidence SET NOT NULL,
    ALTER COLUMN area_ha SET NOT NULL,
    ALTER COLUMN alerts SET NOT NULL;

-- One row per territory and week.
ALTER TABLE alerts_weekly
    ADD UNIQUE (territory_id, week),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ALTER COLUMN week SET NOT NULL,
    ALTER COLUMN lost_ha SET NOT NULL,
    ALTER COLUMN lost_per_1000ha SET NOT NULL;

ALTER TABLE rankings
    ADD PRIMARY KEY (territory_id),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ADD CHECK (method IN ('seasonal_baseline', 'sparse_history')),
    ALTER COLUMN flagged SET NOT NULL,
    ALTER COLUMN window_start SET NOT NULL,
    ALTER COLUMN window_end SET NOT NULL;

-- Historical rankings are stored by calendar week.
ALTER TABLE rankings_history
    ADD UNIQUE (window_end, territory_id),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ADD CHECK (EXTRACT(DOW FROM window_end) = 0),
    ALTER COLUMN flagged SET NOT NULL,
    ALTER COLUMN window_end SET NOT NULL,
    ALTER COLUMN weeks_of_history SET NOT NULL,
    ALTER COLUMN baseline_settled SET NOT NULL;

ALTER TABLE trends
    ADD PRIMARY KEY (territory_id),
    ADD FOREIGN KEY (territory_id) REFERENCES territories,
    ALTER COLUMN slope SET NOT NULL,
    ALTER COLUMN flagged_now SET NOT NULL,
    ALTER COLUMN window_end SET NOT NULL;

-- hotspots and field_reports are not here: both were created after this file,
-- with their keys from the start, so there is nothing to alter. They are in
-- schema.sql like everything else.

-- Database role used by the site. Read-only except for field_reports.
-- reporter stays in the database and is never exposed by the site.

CREATE ROLE web_reader WITH LOGIN PASSWORD 'set-this-yourself';
GRANT CONNECT ON DATABASE neondb TO web_reader;
GRANT USAGE ON SCHEMA public TO web_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO web_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO web_reader;

GRANT INSERT ON field_reports TO web_reader;
GRANT USAGE, SELECT ON SEQUENCE field_reports_id_seq TO web_reader;