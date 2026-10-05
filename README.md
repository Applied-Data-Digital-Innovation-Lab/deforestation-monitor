# Deforestation Monitor for Indigenous Territories
 
Detecting forest loss inside legally recognised indigenous territories of the Amazon basin, using RAISG territory boundaries and Global Forest Watch satellite alerts.
 
---
 
## Objective
 
Track deforestation across 3,866 indigenous territories and surface the ones losing forest at an unusual rate for themselves, rather than the ones that are simply large.
 
There are alerts somewhere in the Amazon almost every day, so listing every territory with a new alert produces something nobody can act on. Each territory is compared with its own historical and seasonal pattern instead.
 
The result is a public web interface with the current ranking, a map, per-territory history, an archive of previous rankings, and a form for recording what was found on the ground.
 
**Data sources:** [RAISG](https://www.raisg.org/en/maps/) `Tis_TerritoriosIndigenas` (territory boundaries, June 2025) and the [GFW Data API](https://data-api.globalforestwatch.org/) `gfw_integrated_alerts` (daily satellite alerts).
 
---
 
## Scope
 
The RAISG layer contains 7,466 polygons; the project uses 3,866. Three filters: inside the Amazon biome, officially recognised, and at least 1 hectare. The first removes mostly Peruvian community records outside the area the monitor covers, the second excludes claims that are not enforceable boundaries, and the third drops three digitising artefacts smaller than a single alert pixel.
 
The polygons are dissolved by `codigo_tis`, since no ID column is unique in the raw layer and several territories appear as multiple polygons. A duplicated key would multiply rows on every merge without raising an error.
 
Full details, with the counts at each step, are in `notebooks/01_explore_territories.ipynb`.
 
---
 
## How a territory gets flagged
 
The window is the **last seven days** with available data, recalculated on every run.
 
Each territory is compared with its own history around the same time of year: the baseline is the 90th percentile of weekly loss from a four-week window either side of the current week of year. The week being evaluated is excluded from its own baseline, or an extreme week would raise the bar it has to clear.
 
A territory is flagged when **both** conditions are met:
 
- at least **5 ha** of forest loss
- at least **2× its seasonal baseline**
**With one exception, and it matters:** a territory whose baseline rests on too little history is judged on the 5 ha alone. There is nothing meaningful to take a ratio against, so requiring one would mean never flagging it. 398 of the 3,866 fall into that group today, down from well over 500 before the history was extended. Each row records which path it took.
 
The ratio says how unusual the loss is; the absolute floor keeps tiny territories with trivial losses off the list. The score that orders the result multiplies the ratio by the square root of hectares lost.
 
**Why normalise by area.** The median territory is 4,192 ha and the largest is 9,537,676. San Pedro recorded 465 alerts in a year against Yanomami's 414,372, but lost 0.136 per cent of itself against Yanomami's 0.053: nearly three times as much, while ranking past position 3,000 in absolute counts.
 
**Why adjust for season.** Median weekly loss is roughly ten times higher in weeks 34 to 43 than in weeks 1 to 22. Akawini in Guyana has a flat baseline of 0.040 and a seasonal one of 2.616: under a flat baseline it ranked fourth with a ratio of 13.5, adjusted it comes out at 0.21, well under what it normally loses in August. The adjustment moves territories in both directions.
 
**Why confidence is stored, not filtered.** Alerts start as `nominal` and mature over three to four months. Filtering to confirmed alerts captured a shrinking share of the data the more recent it was: 96 per cent of the current week is still `nominal`, against 3 to 16 per cent at five months old. Storing the level keeps the rule identical for recent and historical data.
 
---
 
## Where inside the territory
 
A flagged territory is a polygon, and some of them are enormous. Saying that Alto Rio Negro lost 1,850 hectares across eight million is not something a field team can act on.
 
The alerts behind that figure are individual 10 m pixels with their own coordinates, so they cluster into the places the loss is concentrated in. For each flagged territory the pipeline fetches those coordinates for the current window, groups them with DBSCAN at a two-kilometre radius, and stores the ten largest with their centre, area and share.
 
In most territories the top few hotspots account for nearly all the loss: 92 per cent in one place for Jurubaxi-Téa, 80 per cent for Curripaco. In the largest ones the loss is more dispersed and the leading hotspot covers a fifth or a half, which is still the difference between a point to visit and a polygon the size of a country.
 
Only flagged territories are queried, and only for the current window: around sixty requests rather than 3,866. GFW caps a response at 6 MB, so a territory with hundreds of thousands of alerts in one week is asked for a day at a time.
 
**One caveat, visible on the page.** A hotspot's hectares are its pixel count at 0.01 ha each, while the territory's figure above it is GFW's own `SUM(area__ha)`. The two are close but will not reconcile exactly, and the share shown is a share of alerts rather than of hectares.
 
---
 
## Rising trajectories
 
The weekly criterion finds events, not processes. A territory that deteriorates gradually raises its own baseline as it goes: measured across the whole archive, a baseline rises by a factor of 1.95 after a flagged week and stays flat after a normal one.
 
A second criterion fits a line over the previous seven windows of each territory's anomaly ratio, excluding the current one. Weeks a territory lost nothing are read as zero rather than as gaps, which is what lets it find the shape that matters: a territory that was quiet and then started losing.
 
Around 60 to 80 territories a week qualify, almost none of them on the flagged list.
 
### Why there is no predictive model
 
Across the settled historical windows, only 9.4 per cent of flagged territories are flagged again the following week, against a base rate of 0.5 per cent.
 
Deforestation events are finite: once a stretch is cleared, that forest cannot be lost again. GFW does not re-alert the same pixel once it reaches high confidence. And the baseline adapts, as above. A classifier trained on this would reach 99.5 per cent accuracy by answering "no" every time.
 
Separating fire from clearing was tried and measured too: of 246 hotspots cross-referenced against a month of NASA FIRMS detections, 3 per cent had active fire within two kilometres. What this system detects in these territories is mostly clearing rather than burning.
 
---
 
## Architecture
 
The database is the source of truth. The daily pipeline does not depend on anything in `data/`.
 
```text
GitHub Actions (daily)
      │
      ├── fetch_alerts.py       refreshes the last 30 days of alerts
      ├── detect_anomalies.py   builds the ranking, appends to the archive
      ├── detect_trends.py      fits the rising trajectories
      └── detect_hotspots.py    locates the loss inside flagged territories
                  │
                  ▼
              Neon DB  ──────▶  PHP web app
```
 
| Table | Rows | What it holds |
|---|---|---|
| `territories` | 3,866 | Name, country, area, population, map point |
| `geostores` | 3,866 | The GFW ID for each territory |
| `alerts_daily` | ~1,320,000 | One row per territory, day and confidence level |
| `alerts_weekly` | ~843,000 | The weekly series behind the charts, zeros included |
| `rankings` | 3,866 | The current rolling window |
| `rankings_history` | ~500,000 | One row per territory per calendar week, where it lost something |
| `trends` | ~80 | Territories on a rising trajectory |
| `hotspots` | ~250 | Where inside the flagged territories the loss happened |
| `field_reports` | grows | What people found when they went to look |
 
**Two tables, never one query.** `rankings` holds the rolling seven-day window the site shows. `rankings_history` holds calendar weeks, one row per territory per week, and `window_end` there is always a Sunday. Writing a rolling window into the archive once put six overlapping rows beside every real one, and anything reading a run of windows as a series read the same days over and over. A `UNIQUE` and a `CHECK` in the schema now make that impossible rather than merely unintended.
 
**The archive stores only territories that lost something.** A territory with no loss has no ratio, no score and no place in any ranking; the full series, zeros and all, is in `alerts_weekly`. Archiving all 3,866 every week is what took the database past its storage limit.
 
**Refreshing replaces rather than appends.** Each run re-queries the last 30 days and overwrites those rows, because alerts are revised upward as confidence improves and late detections keep appearing for dates already covered. Only territories that returned successfully are cleared, so a network error cannot silently delete data. The run fails outright if the most recent alert is more than `MAX_LAG_DAYS` old, which is what catches a feed that has stopped advancing rather than one that is merely behind.
 
---
 
## The web interface
 
`web/` is a PHP front end over the Neon tables.
 
| Page | What it shows |
|---|---|
| `index.php` | The week's flagged territories, a map, the method, and everything that lost forest |
| `map.php` | Every territory on one map, with a synchronised list |
| `territory.php` | One territory: current figures, where inside it the loss was, two years of weekly history, and the field report form |
| `history.php` | Any past window, the most frequently flagged, and the rising ones |
 
`data.php` is the only file that knows about the database; everything else calls its functions. `api.php` maps a query string onto them and returns JSON, so the page shell renders immediately while Neon wakes from idle. The site follows the operating system's light or dark setting, with a header button to override it.
 
### Field reports
 
Anyone who checked an alert on the ground can record what they found. This is the only part of the site that writes, and it posts to `report.php` rather than `api.php`, which stays read-only.
 
**Nothing published without review.** A report is stored with `approved = false` and does not reach the page until that is set by hand. The note is free text from an anonymous visitor, shown under the name of a real territory, so it is held back rather than taken down afterwards.
 
```sql
-- what is waiting
SELECT id, territory_id, verdict, note, reporter, submitted_at
FROM field_reports WHERE NOT approved ORDER BY submitted_at DESC;
 
-- publish one
UPDATE field_reports SET approved = TRUE WHERE id = 1;
```
 
Three defences, cheapest first: a trap field the stylesheet moves off screen, which a bot fills and a person never sees; length and value checks server-side, whatever the form said; and five reports an hour per address, counted inside the same transaction as the insert so concurrent sends cannot slip past it.
 
### Credentials and the web role
 
PHP queries Postgres server-side and the client receives names and numbers. The site connects as `web_reader`, which reads what the pipeline publishes and writes nowhere except `field_reports`. The `SELECT` on that table is column-level, so `reporter` never leaves the database and the site cannot publish a name even by mistake.
 
The role is defined in `sql/constraints.sql`. Set a password of your own before running it, and point the site's `NEON_URL` at that role rather than the owner.
 
---
 
## Running it
 
```bash
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```
 
A `.env` in the project root with two values. The GFW key comes from `data-api.globalforestwatch.org`; the Neon URL needs the owner role, since the pipeline writes.
 
```
GFW_API_KEY=...
NEON_URL=postgresql://user:password@host/dbname?sslmode=require
```
 
### Database setup
 
**Run this first, against an empty database.** The pipeline truncates and appends rather than replacing tables, which is what lets the keys and constraints survive each run, but it also means the tables have to exist before anything else runs.
 
```bash
psql "$NEON_URL" -f sql/schema.sql
```
 
`sql/constraints.sql` is the other half of the same structure, for a database whose tables were created before the schema existed. On a fresh database it is not needed, except for the `web_reader` role at the end of it.
 
### First-time load
 
Download the RAISG `Tis_TerritoriosIndigenas` shapefile into `data/raw/`, then:
 
```bash
python src/build_territories.py        # ~1 min
python src/create_geostores.py         # ~80 min
python src/fetch_alerts.py --backfill  # ~35 min
python src/seed_neon.py                # uploads the static tables
python src/backfill_rankings.py        # evaluates every past week
```
 
### Daily
 
What GitHub Actions runs, from `.github/workflows/daily.yml`. Credentials are repository secrets.
 
```bash
python src/fetch_alerts.py
python src/detect_anomalies.py
python src/detect_trends.py
python src/detect_hotspots.py
```
 
### The website
 
PHP 7.4 or later with the `pdo_pgsql` extension, and `NEON_URL` in the environment with the read-only role. PHP does not read `.env` files on its own, hence the variable on the command line:
 
```bash
cd web
NEON_URL="$(grep '^NEON_URL=' .env | cut -d= -f2-)" php -S 127.0.0.1:8000
```
 
Without `NEON_URL` the site renders its shell, every panel fails, and the header says so. There is no fallback data source.
 
---
 
## Working with the GFW Data API
 
Five things that cost time and are not documented upstream.
 
**The dataset version has to be resolved at run time, not pinned.** GFW publishes a new version daily and retires the old ones after a few weeks. A pinned version first stops receiving data, silently, while the pipeline keeps succeeding and the query keeps returning rows that are not new; then it disappears and every request 404s. The backfill and the daily refresh both resolve the latest version.
 
**The SQL dialect rejects `IN`**, returning `Unsupported filter operator: in`. Use chained `OR`.
 
**It ignores the alias on `COUNT(*)`** and always returns the column as `count`, though it honours aliases everywhere else.
 
**`MAX()` over an empty result set** returns null and breaks the query with `object of type 'NoneType' has no len()`. Territories with no alerts hit this.
 
**A response is capped at 6 MB** and fails rather than truncating, with `Response payload size exceeded maximum allowed payload size`. There is no row count to check in advance; the only way to know is to ask and be refused, then split the request.
 
---
 
## Repository layout
 
```
├── src/          the pipeline: territories, geostores, alerts, anomalies,
│                 trends, hotspots, plus seed_neon and backfill_rankings
├── sql/          schema.sql, and constraints.sql for an existing database
├── notebooks/    the analysis behind each decision, 01 to 05
├── web/          the PHP front end
├── data/         not committed
└── .github/workflows/daily.yml
```
 
---
 
## Scope and limitations
 
**Timing.** Alerts are published once a day, and a clearing takes a few more days to appear in the dataset depending on cloud cover. The window ends on the most recent day with data rather than on today.
 
**Detection is uneven day to day.** A cloudy day produces nothing and a clear one produces a backlog: one territory went from 3 alerts to 4,522 three days later. Working in seven-day windows rather than single days smooths that out.
 
**The 2024 fire season is diluted, not removed.** Extending the record from two years to four halved its weight in every August-to-October baseline: the same nine extreme weeks now sit among 36 observations rather than 18, so the 90th percentile falls below them rather than inside them. It still contributes about a quarter of those seasonal windows, which makes August-to-October baselines the least reliable of the year.
 
**The two history thresholds were calibrated against a shorter record.** `MIN_ACTIVE_WEEKS` and `SETTLED_AFTER_WEEKS` are both 20, chosen when the record was 107 weeks and now running against 218. They no longer mean what they meant: far more territories clear 20 active weeks, so the sparse group has shrunk to 398. Worth revisiting as a fraction of available history rather than a constant.
 
**Territories still vary in how much history they have.** Those 398 fall back to the absolute threshold alone, so for roughly one territory in ten the seasonal comparison is not doing the work.
 
**The thresholds are choices.** The 90th percentile, the 5 ha floor and the ratio of 2 were tuned against what a small team could realistically act on. They sit as constants at the top of the script so they are easy to revisit.
 
**Storage.** The database sits at about three quarters of Neon's free tier, and `alerts_daily` grows every day with nothing pruning it. When it fills, writes fail and the daily job stops. The cheapest remedy is dropping the per-confidence detail older than six months, which costs nothing analytically given that alerts mature in three to four.
 
**A territory with no forest left goes quiet.** Alerts fire on loss of cover, so once there is nothing left to lose there is nothing to detect. That holds for any alert-based system.
 
**An alert points somewhere, it does not confirm anything.** This is a screening tool for prioritisation rather than a substitute for ground verification, and it does not identify a cause or attribute responsibility.
 
---
 
## Attribution
 
Territory boundaries: RAISG (Red Amazónica de Información Socioambiental Georreferenciada), <https://www.raisg.org>. Attribution is required by their terms of use.
 
Deforestation alerts: Global Forest Watch, integrated deforestation alerts (UMD/GLAD and WUR). Base map tiles: Esri.