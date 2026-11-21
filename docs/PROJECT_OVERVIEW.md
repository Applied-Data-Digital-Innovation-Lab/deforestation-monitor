# Project overview

What the deforestation monitor is, how it is put together, and why it is put
together that way. For a technical lead deciding whether to accept it, and for
whoever maintains it afterwards.

For installing it, see `DEPLOYMENT.md`. For what each feature does, see
`FUNCTIONALITY.md`.

## What it does

It screens satellite-detected forest loss across 3,866 indigenous territories
in the Amazon basin, once a day, and flags the territories where this week's
loss is unusual **compared with that territory's own history** rather than
compared with each other. A large territory that always loses forest does not
flag; a quiet one that suddenly does, flags.

The output is a public website: a ranking, a map, a weekly history, and a page
per territory showing where inside it the loss happened.

An alert is a screening signal. It says where to look, not what happened.

## How it is put together

Three parts, in two places.

```
GitHub Actions, once a day          GitHub release        cPanel hosting
┌──────────────────────────┐        ┌────────────┐        ┌──────────────┐
│ fetch from GFW           │        │            │        │              │
│ rank against baselines   │ ──────▶│  6 files   │──────▶ │  MySQL       │
│ fit trends               │        │  + manifest│        │  PHP site    │
│ locate hotspots          │        │            │        │              │
│   (PostgreSQL / Neon)    │        └────────────┘        └──────────────┘
└──────────────────────────┘         published            bin/import.php
                                                          pulls once a day
```

The analysis runs on GitHub Actions and keeps its working data in PostgreSQL.
It publishes the finished figures as files. The hosting downloads those files
once a day and loads them into its own MySQL database, which the PHP site
reads.

**No Python runs on the production server and none needs to.** The site is
PHP, HTML, CSS and JavaScript reading a MySQL database, which is the
architecture the delivery guide requires. The pipeline is an external
dependency, declared below and in the README, not a dependency of the
installation.

## The decisions, and why

**The pipeline did not move to PHP.** It computes a seasonal baseline from
four years of weekly history per territory and clusters alert pixels
geographically. Reimplementing that in PHP would take weeks and produce a
less accurate system on a shared host that cannot be relied on for long
running work. The guide allows an external architecture; this is one.

**MySQL is a copy, not the source of truth.** The pipeline needs to read four
years of history every day to compute baselines. Doing that over the internet
against a shared hosting database would be slow and fragile, and reaching it
at all would mean opening the database to connections from outside. Publishing
files instead means the hosting database never accepts a connection from
anywhere but itself.

A wrong figure on the site is therefore a publishing fault, not a calculation.
The two can be told apart, which is worth more than it sounds when something
looks wrong at a distance.

**The publication is a rolling four-week window, not a daily delta.** A delta
is smaller, but a day the importer does not run is a day lost for good and
nobody finds out. With four weeks of overlap, the next successful run repairs
up to a month of silence, and loading the same week twice changes nothing
because the import replaces whole periods rather than adding to them.

**It is published as a GitHub release asset, not committed to the
repository.** About 1 MB a day committed would be hundreds of megabytes a year
in the history, which cannot be removed without rewriting it.

**The files are JSON Lines, not CSV.** CSV has no way of writing NULL that
cannot also be a string. JSON gives real nulls, keeps floating point values
exact, and can be read a line at a time, so the importer never holds a whole
file in memory.

**The manifest is uploaded last.** An upload that dies halfway leaves the
previous manifest in place; the importer reads the manifest first, sees
nothing new and does nothing, rather than loading half of a publication.

**`DATETIME` and not `TIMESTAMP` in MySQL.** MySQL's `TIMESTAMP` converts to
and from the session's time zone on every read, so the same stored value reads
differently depending on who is connected. Every date in this project is a
calendar week or a window boundary; a value that shifts by a day is a wrong
answer.

**Pages stay at the root of `app/`.** The guide draws `pages/` and `api/`
subdirectories. Moving the four pages into `pages/` would change every public
URL and the path the JavaScript uses to reach the API, for no functional gain,
and the guide states the structure may be adapted. `includes/`, `config/` and
`assets/` are as the guide draws them.

**`assets/` is not split into `css/`, `js/` and `images/`** for the same
reason: eight more path changes, each one a chance to ship a 404, to match a
subdivision the guide shows but does not require.

## What this costs, honestly

**Column-level privileges are gone.** In PostgreSQL the web role could not
read `reporter` or `source_ip` at all; the database refused, whatever the
application did. cPanel grants privileges per database, so on the hosting
those columns are protected only by the application never selecting them.
That is a weaker guarantee and it should not be described as the same one.

**The system depends on a free Neon account that is not the Foundation's.**
The repository belongs to the organisation, but the PostgreSQL database and
the Global Forest Watch API key are personal. If the account lapses, the site
keeps serving its last loaded figures and stops updating. Transferring the
Neon project needs a claim link that its owner generates and that expires in
24 hours.

**Scheduled GitHub workflows are disabled after 60 days of inactivity in the
repository.** Nothing announces it. The daily run simply stops.

**The published data stops at whatever the pipeline last wrote.** There is no
alarm. `import_log` on the hosting records every run and its outcome, and the
freshness of the newest `ok` row is the one number worth watching.

## If the Foundation wants to own all of it

The dependency on Neon can be removed, at a cost worth stating before anyone
starts.

The pipeline keeps its history in PostgreSQL because that is where it computes
the baselines. It already exports both irreplaceable tables to compressed
files (`src/export_archive.py`), so the history itself is not trapped: it
could be read from those files instead and the database dropped. That means
rewriting the part of the pipeline that two external reviews examined and that
the validation case in `docs/` checks, so it is a project rather than a
change, and it should not be done at the same time as anything else.

Until then, the claim link is the whole handover, and it is worth doing early
rather than when something has already broken.

## Technologies

| | |
|---|---|
| Site | PHP 8, PDO with prepared statements, HTML, CSS, vanilla JavaScript |
| Charts and maps | Chart.js, Leaflet |
| Hosting database | MySQL 5.7+ or MariaDB 10.2+ |
| Pipeline | Python 3.13, pandas, scikit-learn, SQLAlchemy |
| Pipeline database | PostgreSQL (Neon) |
| Scheduling | GitHub Actions for the analysis, cPanel cron for the import |
| Sources | RAISG territory boundaries, Global Forest Watch integrated alerts |
