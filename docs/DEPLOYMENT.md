# Deployment

How to install the deforestation monitor in a cPanel account, from nothing to
a site that updates itself once a day.

Written to be followed by someone who has not worked on the project. Where a
step can go wrong, it says what the failure looks like.

## What the server needs

| | |
|---|---|
| PHP | 8.0 or later, with `pdo_mysql` |
| MySQL | 5.7 or later, or MariaDB 10.2 or later |
| PHP extensions | `curl` (or `allow_url_fopen`) and `zlib`, for the daily update |
| Database space | about 350 MB once the full history is loaded |
| Cron | one job a day |
| Outbound HTTPS | the daily update downloads from github.com |

Older MySQL and MariaDB will run the site but will not enforce the `CHECK`
constraints in the schema. Nothing depends on them being enforced; they
document rules the published data already satisfies.

## 1. Get the files

Download the ZIP of the release being deployed from GitHub, not a branch.
A branch changes under you, and section 8 of the delivery guide asks for an
identifiable version.

## 2. Upload

Upload **the contents of `app/`** into the assigned directory, for example
`public_html/deforestation-monitor/`, so the server ends up with:

```
public_html/deforestation-monitor/
├── index.php          ranking, the home page
├── map.php
├── history.php
├── territory.php
├── report.php         receives a field report
├── api.php            the JSON the pages read
├── assets/
├── bin/
│   └── import.php     the daily update, run from cron
├── config/
│   └── config.example.php
└── includes/
    ├── data.php       every query in the site
    └── partials.php
```

Nothing else from the repository belongs on the server. `src/`, `tools/`,
`tests/` and `docs/` stay in GitHub; `database/` is imported through
phpMyAdmin and its files are not uploaded.

## 3. Create the database

In **MySQL Databases**, create a database and a user, and attach the user to
the database. cPanel prefixes both names with the account, so they will look
like `account_dforest` and `account_dfuser`.

The site reads, with one exception: it inserts into `field_reports` when
somebody files a report. `SELECT` on every table and `INSERT` on
`field_reports` is all it needs. If the account only offers ALL PRIVILEGES,
see Limitations at the end.

## 4. Import the SQL

In **phpMyAdmin**, select the database and use Import, in this order:

1. `database/schema.sql` — the tables. Must go first: every other table points
   at `territories`.
2. `database/sample_data.sql` — enough data for the site to work, about
   3.5 MB. If phpMyAdmin refuses it for size, import
   `database/sample_data.sql.gz` instead, which is under 1 MB and which it
   accepts without unpacking.

`field_reports` is empty by design and stays that way: those rows carry a
reporter and a source IP and are not in the repository.

## 5. Configure

Copy `config/config.example.php` to `config/config.php` and fill in the
database host, name, user and password. On cPanel the host is `localhost`.

`config/config.php` is excluded from Git and must never be committed. If it
ever is, the password has to be changed, not just deleted from the file:
removing it from the latest commit leaves it in the history.

Until this file exists the site renders its shell, every panel fails to load,
and the header shows a NOT CONFIGURED badge.

## 6. Check the site

Open the URL. All four pages should load with figures. If they do not, the
Troubleshooting table below starts with the likely causes.

## 7. Load the full history

The sample data is four weeks. The full series is 224 weekly windows and
about 870,000 weekly rows, too large to upload through phpMyAdmin.

**Once, from the repository**, somebody with access to the pipeline runs:

```
python src/publish.py --full
```

and uploads everything in `data/published/` to a GitHub release tagged
`data-full`. That is about 45 MB and only has to be done once.

**Then, once, on the server:**

```
/usr/local/bin/php ~/public_html/deforestation-monitor/bin/import.php \
    --seed \
    --url https://github.com/Applied-Data-Digital-Innovation-Lab/deforestation-monitor/releases/download/data-full
```

It takes about half a minute on a laptop and a few minutes on a shared
account, where the database is further away and busier. Seed mode loads
whole tables without wrapping them
in a transaction, because 870,000 rows in one is more than a shared account
will hold, so a failure can leave a table half full. Running it again fixes
that: it deletes before it inserts. That is also why it has to be asked for
with `--seed`, and why it is never what the cron job does.

After it finishes the history page should list 224 windows.

## 8. The daily update

The pipeline runs on GitHub Actions at 09:00 UTC and finishes around 09:25,
replacing the published files one at a time. The server pulls them afterwards.

In **Cron Jobs**, add one job a day, at least two hours after that:

```
0 12 * * * /usr/local/bin/php /home/ACCOUNT/public_html/deforestation-monitor/bin/import.php --quiet
```

Three things to get right:

- **The time is the server's, not UTC.** Check what the server thinks the time
  is before choosing the hour, or the job can land in the middle of the
  publication. Two hours of margin absorbs an ordinary delay; it does not
  absorb a twelve-hour timezone difference.
- **The path to PHP varies.** The Cron Jobs page usually shows it, or
  `which php` in Terminal.
- **`--quiet` prints only on failure**, which is what keeps cron from emailing
  a report every single day and training everyone to ignore it.

The job is safe to run more often, and safe to run twice: it reads the
publication's timestamp and does nothing if it has already loaded that one.

## After deploying

- [ ] The four pages load, with figures
- [ ] The history selector lists 224 windows
- [ ] A territory page draws its chart and, if it is flagged, its hotspots
- [ ] A field report can be filed
- [ ] The browser console is clean
- [ ] The pages work on a phone
- [ ] `bin/` and `includes/` are not browsable over HTTP
- [ ] The daily job has run at least once:

```sql
SELECT id, published_at, imported_at, row_count, status
FROM import_log ORDER BY id DESC LIMIT 5;
```

`status` is `ok` and `published_at` is within the last day or two. A row with
`error` carries the reason in `note`. This table is the only place a cron run
that failed at three in the morning leaves a trace.

## Troubleshooting

| What you see | Why |
|---|---|
| NOT CONFIGURED in the header, every panel failing | `config/config.php` is missing or incomplete |
| "could not find driver" | `pdo_mysql` is not enabled for this PHP version |
| Pages load unstyled | the contents of `app/` were not uploaded, or `assets/` is missing |
| 404 on every page but the first | the files went into a subdirectory one level too deep |
| `import.php` prints the help and exits | it was opened over HTTP; it only runs from the command line |
| "el servidor no tiene curl ni allow_url_fopen" | neither is available, so the server cannot fetch the data. The data can still be loaded by hand through phpMyAdmin, but it will not update itself |
| import says "la tabla no existe" | `schema.sql` was not imported, or into a different database |
| import says "el archivo trae N filas y el manifest dice M" | a download was cut short. It wrote nothing; run it again |
| The site works but the figures never change | the cron job is not running, or is running before the publication. Check `import_log` |

## Known limitations

**Column-level privileges are not reproduced.** The project's PostgreSQL
database restricted the web user to named columns, so `reporter` and
`source_ip` were unreadable even through a flaw in the site. cPanel's
interface grants privileges per database, not per column, and the account
usually cannot issue `GRANT` itself. On this server those two columns are
protected by the application alone: `includes/data.php` never selects them.
This is a real reduction in defence and is written here rather than left to be
discovered.

**The daily update depends on things outside this server.** A GitHub Actions
workflow, a GitHub release, and a Neon PostgreSQL account that is not owned by
the Foundation. If any of them stops, the site keeps working and keeps showing
its last loaded figures, with `import_log` the only sign that it has stopped
moving. `docs/PROJECT_OVERVIEW.md` sets out what that means and what to do
about it.

**Scheduled GitHub workflows are disabled after 60 days without activity in
the repository.** If the daily run stops for no apparent reason, this is the
first thing to check.
