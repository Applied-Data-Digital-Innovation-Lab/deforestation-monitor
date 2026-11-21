# Functionality

Every feature the system has, what it takes in, what it gives back, and the
rule it applies. Phase 1 of the delivery guide asks for this inventory; it is
also the checklist the migration to PHP and MySQL was tested against.

**Status, and what it means.** Every read feature below was checked by asking
the PostgreSQL version and the MySQL version the same questions and comparing
the answers: 65 results, identical except for six floating point sums that
differ in the last bit because the two engines add the same numbers in a
different order. `tests/dump_api.php` and `tests/compare_backends.py` are that
check, and it can be run again at any time.

Unless a row says otherwise, every feature reads the MySQL database on the
hosting, which `bin/import.php` loads from what the pipeline publishes.

---

## Ranking — `index.php`

| Feature | What it gives back | Status |
|---|---|---|
| Headline figures | Territories flagged, hectares lost across all territories, and the flagged territory furthest above its own baseline | Ported, output verified |
| Flagged list | The flagged territories, worst first, with hectares lost, ratio and method | Ported, output verified |
| Map of the flagged | The flagged territories as points | Ported, output verified |
| Everything that lost forest | Every territory with any loss in the window, most hectares first. Around 2,000 rows | Ported, output verified |
| Method | How a territory gets flagged. Static text | Unchanged |

**Input:** none. The page always shows the window being evaluated now.

**Rule:** the ordering of the flagged list is the score, `ratio × √hectares
lost`. The long list orders by hectares lost, with the territory id as a
tiebreaker: two thousand rows contain many ties, and without a second key
their order depends on the query plan.

---

## Map — `map.php`

| Feature | What it gives back | Status |
|---|---|---|
| All territories | 3,866 points, flagged ones marked, with hectares lost and ratio | Ported, output verified |
| Synchronised list | The same payload as a list beside the map | Ported, output verified |

**Input:** optionally a past window, through the API.

**Rule:** a territory with no ranking row for the window is shown with zero
loss rather than dropped, so the map always carries every territory. The rows
are ordered by territory id; the query had no ordering at all before the
migration, which left it to the engine.

---

## Territory — `territory.php`

| Feature | What it gives back | Status |
|---|---|---|
| Current figures | Hectares lost, loss per 1,000 ha, seasonal baseline, ratio, method, area, population, communities | Ported, output verified |
| Hotspots | Up to ten clusters inside the territory for the current window, with centre, alerts, hectares and share | Ported, output verified |
| Weekly history | The full weekly series, each week with the seasonal baseline and ratio it was judged against | Ported, rewritten, output verified |
| Published field reports | Up to fifty approved reports, newest first | Ported, output verified |
| Field report form | Records what somebody found on the ground | Ported, behaviour changed, see below |

**Input:** a numeric territory id, and optionally a past window.

**Rules:**

- A week with no recorded loss is a real zero and is kept, not skipped.
- A week matches its archived baseline through the archive row whose
  `window_end` falls on its seventh day: `alerts_weekly` keys weeks by their
  Monday and the archive keys them by their Sunday. A week the archive does
  not hold comes back marked unscreened, with no baseline, rather than guessed
  at.
- A hotspot's hectares are its pixel count at 0.01 ha each, while the
  territory's figure above it is Global Forest Watch's own area sum. The two
  are close and will not reconcile exactly, and the share shown is a share of
  alerts rather than of hectares. This is stated on the page.
- Hotspots are joined to the current window, so if the ranking has moved on to
  a new week and the hotspots have not been recomputed, none are shown rather
  than last week's under this week's heading.

**The weekly history query was rewritten, not translated.** PostgreSQL's
`DISTINCT ON` has no MySQL equivalent. It is now a join against the latest
timestamp per calendar day, which cannot duplicate rows because the unique key
on (territory, week) makes that maximum unique within a territory.

---

## History — `history.php`

| Feature | What it gives back | Status |
|---|---|---|
| Window selector | Every archived window, newest first, each with its flagged count and whether its baselines had settled | Ported, output verified |
| A past window | The same headline figures, flagged list and loss list, for the window chosen | Ported, output verified |
| Most frequently flagged | The twenty territories flagged most often across the settled weeks, with total hectares lost | Ported, output verified |
| Rising trajectories | The twenty steepest upward trends among territories not flagged this week | Ported, output verified |

**Input:** a window end date, as `YYYY-MM-DD`.

**Rules:**

- A window counts as settled only when no territory in it is unsettled, which
  is the conservative reading. The raw counts travel with it, so a window whose
  rows disagree can be seen rather than guessed at.
- The frequency count uses only settled weeks. In the early windows the
  baselines rested on a handful of observations and flagged hundreds of
  territories at a time; counting those would mostly be counting that.
- Total hectares lost sums every settled week, flagged or not: it is what the
  territory lost over the period, not only in the weeks it was flagged.

---

## Field reports — `report.php`

| Feature | What it does | Status |
|---|---|---|
| Submit | Records a verdict, an optional note and an optional name against the current window | Ported, behaviour changed |
| Publication | Nothing appears until approved by hand | Unchanged |

**Input:** territory id, a verdict from `confirmed`, `not_found`,
`other_cause` or `unsure`, a note of up to 2,000 characters, and a name of up
to 200.

**Result:** `ok`, `rate_limited`, or `not_found` for an unknown territory.

**Rules:** five reports an hour per address. A trap field the stylesheet moves
off screen. Lengths and values checked on the server whatever the form said.
`reporter` and `source_ip` are stored and never published.

**What changed.** The counting and the insert are still one step per address,
but PostgreSQL released that lock when the transaction ended and MySQL's does
not: it belongs to the connection and is released explicitly on every way out
of the function. The protection is the same; the mechanism holding it is
weaker, and a connection that dies mid-request holds the lock until it closes.

**And what the database no longer enforces.** In PostgreSQL the site's role
could not read `reporter` or `source_ip` at all. On the hosting those columns
are protected by the application never selecting them. See
`PROJECT_OVERVIEW.md`.

---

## API — `api.php`

One JSON resource per feature above, read-only, selected by a query string.
Every page builds itself from these, so the page shell renders immediately
instead of waiting on the database.

**Status:** ported, output verified. Archived windows are reachable here even
where no page offers a control for them.

---

## Data pipeline — `src/`, on GitHub Actions

Not installed on the hosting and not required for the site to run. It writes
to PostgreSQL and publishes files; `bin/import.php` loads them.

| Stage | What it does | Rule |
|---|---|---|
| `fetch_alerts.py` | Refreshes the last 30 days of alerts from Global Forest Watch | Replaces rather than appends, because alerts are revised upward as confidence matures. Only territories that answered are cleared, so a network error cannot delete data. Fails outright if the newest alert is more than 10 days old |
| `detect_anomalies.py` | Builds the ranking and appends to the archive | Below |
| `detect_trends.py` | Fits the rising trajectories | A line over 8 weekly ratios excluding the current one, at least 6 points, keeping only upward slopes. Weeks with no loss are read as zero, not as gaps |
| `detect_hotspots.py` | Locates the loss inside flagged territories | DBSCAN at a 2 km radius, at least 20 alerts to a cluster, the 10 largest kept, 0.01 ha per alert |
| `prune_alerts.py` | Bounds the daily table | Drops daily rows older than 180 days |
| `publish.py` | Publishes what the site needs | Four tables whole and the last 4 periods of the two large ones, plus a manifest with row counts, columns and sums |

**How a territory gets flagged.** The window is the last seven days with data,
recalculated every run. The baseline is the 90th percentile of weekly loss
across a four-week window either side of the same week of year, excluding the
week being judged. A territory is flagged when it lost at least 5 hectares
**and** at least twice its baseline.

A territory with fewer than 20 active weeks of history has no usable
percentile and is judged on the 5 hectares alone. 398 of the 3,866 are in that
group. Each row records which path it took.

---

## Loading the published data — `bin/import.php`

| Feature | What it does | Status |
|---|---|---|
| Daily import | Downloads the published files and replaces the periods they cover | New |
| Verification | Row counts and sums checked against the manifest before committing | New |
| First load | A full publication, with `--seed` | New |
| Log | Every run recorded in `import_log`, success or failure | New |

**Rules:**

- Command line only. Over HTTP it is a write endpoint anyone could fire.
- `territories` is updated in place, never replaced: it is the parent of every
  foreign key.
- `field_reports` is never touched. Those rows exist on the hosting and
  nowhere else.
- A publication already loaded is skipped, so running the job twice is safe.
- A file whose size or row count disagrees with the manifest is rejected and
  nothing is written.

---

## Not implemented, and why

**No predictive model.** Across the settled windows, 9.4 per cent of flagged
territories are flagged again the next week against a base rate of 0.5 per
cent. Deforestation events are finite, Global Forest Watch stops alerting on a
pixel once it is confirmed, and the baseline adapts upward after a flagged
week. A classifier would reach 99.5 per cent accuracy by answering no every
time.

**No fire and clearing separation.** Of 246 hotspots cross-referenced against
a month of NASA FIRMS detections, 3 per cent had active fire within two
kilometres. What this detects in these territories is mostly clearing.

**No alerting when the data stops.** `import_log` records every run and its
outcome, and the newest successful row is the number to watch, but nothing
sends a message when it stops moving.
