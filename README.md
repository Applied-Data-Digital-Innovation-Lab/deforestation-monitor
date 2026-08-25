# Deforestation Monitor for Indigenous Territories
 
Detecting forest loss inside legally recognised indigenous territories of the Amazon basin, by crossing RAISG territory boundaries with Global Forest Watch satellite alerts.
 
---
 
## Objective
 
Track deforestation across 3,866 indigenous territories and surface the ones that are losing forest at an unusual rate for themselves, rather than the ones that are simply large.
 
That distinction is the whole point. Alerts appear somewhere in the Amazon almost every day, so a system that flags every territory with a new alert flags nearly all of them, nearly always. The useful signal is a territory departing from its own historical and seasonal pattern.
 
Target output: a ranked table plus a map, showing which territories deserve attention this week and why.
 
---
 
## Approach
 
| Stage | What it does | Status |
|---|---|---|
| 1. Territories | Download RAISG polygons, filter, clean, assign a primary key | Done |
| 2. Geostores | Register each polygon with GFW once, store the returned ID | Done |
| 3. Alerts | Query GFW for daily alerts per territory, all confidence levels | Done |
| 4. History | Accumulate and validate the per-territory time series | Done |
| 5. Anomalies | Normalise by area, detect departures from each territory's seasonal baseline | In progress |
| 6. Dashboard | Ranked table, time series, map | Not started |
 
---
 
## Data sources
 
| Source | What it provides | Format |
|---|---|---|
| [RAISG](https://www.raisg.org/en/maps/), `Tis_TerritoriosIndigenas` | Indigenous territory boundaries, June 2025 release | Shapefile |
| [GFW Data API](https://data-api.globalforestwatch.org/), `gfw_integrated_alerts` | Satellite deforestation alerts | JSON over HTTP |
 
---
 
## Scope
 
Of the 7,466 polygons in the RAISG layer, 3,866 are used. Three filters narrow it down.
 
**Inside the Amazon biome** (`amzbiog == "s"`). The excluded polygons are mostly Andean peasant communities from the Peruvian registry, where there is little forest cover and forest-loss alerts carry no signal. Peru alone accounts for 5,792 of the 7,466 records, largely because its registry lists communities at a much finer granularity than the other countries.
 
**Officially recognised** (`leyenda == "TI con reconocimiento oficial"`). Where recognition is pending or absent, the polygon represents a claim rather than an enforceable boundary, and attributing forest loss to it would be unreliable.
 
**At least 1 hectare.** This one excludes territories that pass both filters above, so it needs its own justification. An integrated alert pixel covers 0.01 ha at 10 m resolution. Nine polygons in the biome fall below one hectare; three survive the other filters, measuring 0 ha, 0.000011 ha (about 0.11 square metres) and 0.87 ha. They are digitising artefacts in the source data, not places. The script prints them by name and country when it drops them, so the exclusion stays auditable.
 
```
raw polygons: 7466
after scope filters: 3876
after dissolve: 3869
dropping 3 polygons below 1.0 ha:
 codigo_tis                 nombre   pais  area_sig_h
      13062        Massara Tract B Guyana    0.000011
      45435 Ampi Sacha Mishquiyacu   Perú    0.865650
      45596  Santa Rosa de Firmeza   Perú    0.000000
after area filter: 3866
```
 
### Primary key
 
None of the three candidate ID columns is unique in the raw layer: `codigo_tis` has 7,457 distinct values out of 7,466, `featureid` 7,188, `id_tiunico` 7,041.
 
Inspecting the duplicates showed that most are single territories split across several polygons. Cuyabeno-Imuya in Ecuador appears seven times: two rows with real area and five slivers. The two genuine conflicts, both in Venezuela, fall outside the legal-recognition filter on their own.
 
So the polygons are dissolved by `codigo_tis` with areas summed, after which the key is unique and asserted as such. This matters more than it looks: later stages merge alert counts against a stored history, and a duplicated key would multiply rows on every merge and inflate the counts without raising an error.
 
### Geostores
 
The query endpoint takes a geometry, but territory polygons are too large to resend on every request. GFW provides a geostore endpoint: upload a geometry once, get back an ID, then query by ID from then on.
 
The concern was whether the largest polygons would be accepted. Yanomami serialises to 2.4 MB of GeoJSON and was accepted as-is, so no simplification is needed anywhere and territory boundaries stay exact. That matters: simplifying moves the boundary, which would mean counting alerts that fall outside it.
 
Registration runs sequentially, roughly 80 minutes for all 3,866 territories. Parallelising was considered and rejected: this is a one-off run and the extra complexity is not worth 80 minutes. Each row is written as it goes, so an interrupted run resumes where it stopped.
 
Alert fetching is a different case and does run in parallel. A two-year query averages 4.87 s per territory, which is 5.2 hours sequentially against 39 minutes with eight workers. Shrinking the date range would not help: a territory with no alerts at all still takes 4 s, so most of the cost is per-request overhead rather than data volume.
 
### Confidence levels are stored, not filtered
 
Alerts come in three tiers: `nominal`, `high` and `highest`. An alert starts at the lowest tier and is upgraded as further satellite passes confirm it, reaching `highest` only when several detection systems agree on the same pixel.
 
The first version filtered to `high` and `highest`, on the reasoning that a false positive costs an organisation a wasted field trip. That was reverted after July 2026 came out five times lower than July 2025. Measured across the five largest territories, the share of alerts still at `nominal` runs 96 per cent for the current week and 3 to 16 per cent by five months old: full maturation takes three to four months.
 
So the filter captured a shrinking share of alerts the more recent the data. Comparing the current week against the same week a year earlier was not a comparison at all, because the present would always look calm. For a temporal comparison, consistency matters more than precision: `nominal` false positives exist in both present and past and cancel out, whereas filtering introduces a bias that grows with recency. Confidence became a stored column instead.
 
### Why alert counts get normalised by area
 
Areas are heavily right-skewed. Inside the biome the median territory covers 4,192 ha and the mean covers 53,147 ha, a factor of thirteen, with the largest at 9,537,676 ha. Ranking by raw alert count would return the same handful of giants every time.
 
This is not hypothetical. San Pedro is the median-sized territory at 4,193 ha and recorded 465 alerts in a year against Yanomami's 414,372, but normalised by area it lost 0.136 per cent of itself against Yanomami's 0.053: nearly three times as much, while ranking somewhere past position 3,000 in absolute counts.
 
---
 
## Working with the GFW Data API
 
Three quirks that cost time and are not documented upstream.
 
**The SQL dialect rejects `IN`**, returning `Unsupported filter operator: in`. Multi-value filters have to be written as chained `OR` conditions.
 
**It ignores the alias on `COUNT(*)`** and always returns the column as `count`, although it honours aliases everywhere else.
 
**The dataset version has to be pinned.** GFW publishes a new version every day, so `latest` would make results change between runs without warning. Pinning also avoids a redirect that silently converts a `POST` into a `GET` and discards the request body.
 
---
 
## Repository layout
 
```
.
├── README.md
├── src/
│   ├── build_territories.py     # stage 1: raw shapefile to working dataset
│   ├── create_geostores.py      # stage 2: register geometries with GFW
│   └── fetch_alerts.py          # stage 3: pull daily alerts per territory
├── notebooks/
│   ├── 01_explore_territories.ipynb   # scope decisions
│   ├── 02_geostores.ipynb             # geometry size and timing checks
│   ├── 03_alerts.ipynb                # query design and confidence levels
│   ├── 04_history.ipynb               # validation of the fetched history
│   └── 05_anomalies.ipynb             # baseline, seasonality, ranking
├── data/                        # not committed
│   ├── raw/
│   └── processed/
├── requirements.txt
└── .gitignore
```
 
---
 
## Known limitations
 
**Not real time.** Integrated alerts are published once a day, and there is a further lag between clearing happening on the ground and a satellite detecting it, depending on cloud cover.
 
**The 2024 fire season contaminates the baseline.** Seventy per cent of all forest loss in the two-year window falls in three months of 2024, with Brazil and Bolivia accounting for 96.5 per cent of that peak. Single-day figures reach 20.9 per cent of a territory's own area, which no clearing operation produces: these are burn scars, which satellites register as tree cover loss. A baseline built on these two years treats a catastrophic fire season as normal October behaviour. Resolving it means either more years of history or using GFW's alert-driver classification to separate cause.
 
**Territory density varies enormously.** Yanomami records alerts in every week of the two years; the median territory has 73 active weeks out of 107, and 548 territories have too little history for a percentile to mean anything. Those are handled by an absolute loss threshold rather than a baseline comparison.
 
**Seasonality is real and had to be modelled.** Median weekly loss runs between 0.000 and 0.0061 in weeks 1 to 22 and between 0.014 and 0.0535 in weeks 34 to 43, roughly a tenfold difference between wet and dry season. It shows up in the median and not only the mean, so it is a genuine pattern rather than an artefact of 2024. A flat baseline overstates anomalies in August.
 
**Two years is thin for a seasonal model.** Each week of the year has only two observations, widened to about twenty by a four-week window either side. Enough to work with, not enough to be confident about any single week.
 
**Alerts get revised.** GFW reclassifies alerts as confidence improves, so each refresh has to re-query a trailing window and overwrite rather than simply append.
 
**Geometry detail is high.** 58 MB for 3,866 polygons is good for spatial queries and too heavy for any interactive map. A simplified copy, or territory centroids, will be needed for the map.
 
**An alert is not a verified event.** This is a screening tool for prioritisation, not a substitute for ground verification.
 
---
 
## Attribution
 
Territory boundaries: RAISG (Red Amazónica de Información Socioambiental Georreferenciada), <https://www.raisg.org>. Attribution is required by their terms of use.
 
Deforestation alerts: Global Forest Watch / World Resources Institute.