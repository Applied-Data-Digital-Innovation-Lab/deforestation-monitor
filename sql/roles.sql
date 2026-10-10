-- The site can read pipeline data and submit field reports, but can't
-- change anything else.
--
-- Run separately from constraints.sql, which needs the tables to exist.
-- Pass the password at runtime instead of storing it in the repo:
--
--   psql "$NEON_URL" -v web_reader_password=... -f sql/roles.sql

CREATE ROLE web_reader WITH LOGIN PASSWORD :'web_reader_password';
GRANT CONNECT ON DATABASE neondb TO web_reader;
GRANT USAGE ON SCHEMA public TO web_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO web_reader;

-- Tables are recreated by the daily pipeline, so grant access to new ones too.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO web_reader;

GRANT INSERT ON field_reports TO web_reader;
GRANT USAGE, SELECT ON SEQUENCE field_reports_id_seq TO web_reader;

-- Keep reporter private. The site only needs these columns, and source_ip
-- is used for rate limiting.
REVOKE SELECT ON field_reports FROM web_reader;
GRANT SELECT (territory_id, window_end, verdict, note,
              submitted_at, source_ip, approved)
    ON field_reports TO web_reader;