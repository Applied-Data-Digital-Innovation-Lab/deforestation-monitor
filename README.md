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
| 2. Geostores | Register each polygon with GFW once, store the returned ID | Not started |
| 3. Alerts | Query GFW for high-confidence alerts, aggregated by territory and date | Not started |
| 4. History | Accumulate the per-territory time series | Not started |
| 5. Anomalies | Normalise by area, detect departures from each territory's baseline | Not started |
| 6. Dashboard | Ranked table, time series, map, review state | Not started |


---

## Data sources

| Source | What it provides | Format |
|---|---|---|---|
| [RAISG](https://www.raisg.org/en/maps/), `Tis_TerritoriosIndigenas` | Indigenous territory boundaries, June 2025 release | Shapefile |
| [GFW Data API](https://data-api.globalforestwatch.org/), `gfw_integrated_alerts` | Satellite deforestation alerts | JSON over HTTP |

---

## Scope

Of the 7,466 polygons in the RAISG layer, 3,866 are used. Three filters narrow it down.

**Inside the Amazon biome** (`amzbiog == "s"`). The excluded polygons are mostly Andean peasant communities from the Peruvian registry, where there is little forest cover and forest-loss alerts carry no signal. Peru alone accounts for 5,792 of the 7,466 records, largely because its registry lists communities at a much finer granularity than the other countries.

**Officially recognised** (`leyenda == "TI con reconocimiento oficial"`). Where recognition is pending or absent, the polygon represents a claim rather than an enforceable boundary, and attributing forest loss to it would be unreliable.

**At least 1 hectare.** This one excludes territories that pass both filters above, so it needs its own justification. An integrated alert pixel covers between 0.01 ha and 0.09 ha depending on the product. Nine polygons in the biome fall below one hectare; three survive the other filters, measuring 0 ha, 0.000011 ha (about 0.11 square metres) and 0.87 ha. They are digitising artefacts in the source data, not places. The script prints them by name and country when it drops them, so the exclusion stays auditable.

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

So the polygons are dissolved by `codigo_tis` with areas summed, after which the key is umique and asserted as such. This matters more than it looks: stage 4 merges daily alert counts against a stored history, and a duplicated key would multiply rows on every merge and inflate the counts without raising an error.

### Why alert counts get normalised by area

Areas are heavily right-skewed. Inside the biome the median territory covers 4,192 ha and the mean covers 53,147 ha, a factor of thirteen, with the largest at 9,537,676 ha. Ranking by raw alert count would return the same handful of
giants every time and would never surface a 1,300 ha territory losing a significant share of its forest in a week.

---

## Repository layout

```
.
├── src/
│   └── build_territories.py     # stage 1: raw shapefile to working dataset
├── notebooks/
│   └── 01_explore_territories.ipynb   # exploration behind the scope decisions
├── data/                        # not committed
│   ├── raw/
│   └── processed/
└── .gitignore
```

---

## Known limitations

**Not real time.** GLAD alerts are published once a day, and there is a further lag between clearing happening on the ground and a satellite detecting it, depending on cloud cover. Daily batch is the closest achievable approximation.

**Small territories will be hard to model.** 730 territories, roughly 19% of the working set, are under 1,000 ha. Their daily alert series will be mostly zeros, and a percentile computed over a series of zeros means nothing. Stage 5 will need to treat them differently.

**Seasonality is not noise to be removed carelessly.** Deforestation in the Amazon rises every year during the dry season. A territory going up in August is not news. A territory going up more than it usually does in August is.

**Alerts get revised.** GFW reclassifies alerts as confidence improves, so each refresh has to re-query a trailing window and overwrite rather than simply append.

**Geometry detail is high.** 58 MB for 3,866 polygons is good for spatial queries and too heavy for a browser map. A simplified copy will be needed for the dashboard only.

**An alert is not a verified event.** This is a screening tool for prioritisation, not a substitute for ground verification.

---

## Attribution

Territory boundaries: RAISG (Red Amazónica de Información Socioambiental Georreferenciada), <https://www.raisg.org>. Attribution is required by their terms of use.

Deforestation alerts: Global Forest Watch / World Resources Institute, using GLAD (University of Maryland) and RADD (Wageningen University) products.