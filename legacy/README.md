# football-data.org: match records

> Legacy reference implementation. Run commands from this `legacy/` directory
> (e.g. `php bin/import.php`, `docker compose up -d` for the viewer on :9988).
> Its SQLite database lives in `legacy/var/` (not in git). To share the Top-5 dataset with the
> Symfony app, link it: `mkdir -p var && ln -s ../../var/sources var/sources`.
> **Not needed to run the app**: nothing in the Symfony app uses this code; it needs its own
> `.env` with a football-data.org API key.

Plain PHP (8.1+) project on the free football-data.org v4 API. Two parts:

1. **Importer**: builds one record per match for the assessment period
   (1 July 2025 – 19 July 2026) in a SQLite database, and exports it to CSV.
2. **Viewer**: a small web page for matches, standings and top scorers.

Scope (`config/scope.php`): Premier League, La Liga, Serie A, Bundesliga, Ligue 1,
Eredivisie and Champions League (season 2025/26), plus World Cup 2026.

## Setup

```bash
cp .env.example .env    # put your API key in FOOTBALL_DATA_API_KEY
```

## Import and export

```bash
docker compose run --rm app php bin/import.php            # all 8 competitions, ~3 minutes
docker compose run --rm app php bin/import.php --only=CL,WC
docker compose run --rm app php bin/export.php            # CSVs in var/export/
```

The importer waits 6.5 s between real API calls (free plan: 10 per minute) and caches
responses for a day, so re-runs are fast. Use `--fresh` to skip the cache.
Re-running is safe: records are upserted, standings and scorers are replaced.

Exit code 2 means some requests failed (e.g. 403 when a season isn't in your plan).
Everything else is still imported; details are in the `import_log` table/CSV.

## What is in a match record (`match_records` view)

| Field | Source |
|---|---|
| Date, competition, competition type, season | `/competitions/{code}/matches` |
| Match type (`league`, `league_phase`, `group`, `knockout`, `qualification`), stage, matchday, group | idem |
| Home/away team, score (regular + extra time), half-time score, penalties, winner | idem |
| Opponent strength: final table position and `strength` (0–1, 1 = top) | `/standings` (final table) |
| Missing values: `missing_fields` (JSON list of expected fields that were empty) | computed on import |
| Provenance: `source`, `source_updated` (API lastUpdated), `imported_at` | importer |

Player data in the free tier only comes from `/scorers`: season totals for the top
scorers per competition (goals, assists, penalties, matches played), in
`player_competition_stats`. There are no per-match line-ups, minutes or positions.

Known limits of `strength`: it uses the final table (hindsight, not the form at the
time of the match), it is relative within a competition only, and knockout-only
opponents without a table row get no value.

## MVP dataset: Top-5 leagues (2025/26)

Player-season statistics come from the
[Top-5 Football Dataset](https://github.com/m-mahadi/top5-football-dataset)
(FBref + Understat + SofaScore, merged by its author).

```bash
git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
php bin/import-top5.php      # -> table ext_player_seasons, one row per player
php bin/coverage.php         # -> table field_coverage + var/export/field_coverage.csv
```

- `config/fields.php` is the field registry: for every statistic the category,
  priority, source, source column, competitions and level. Add or swap sources there.
- Players who changed clubs have one row per club in the dataset. Per-stint fields
  (FBref: minutes, goals, shots, ...) are summed; season fields (Understat, SofaScore)
  are already season totals and are taken once, so nothing is counted twice.
- Missing values stay NULL. A sum over stints with an unknown stint is NULL, not a
  partial total. Goalkeeper fields are only counted over goalkeepers.
- The coverage report shows, per field and source: competitions with data, missing
  records overall, and missing records among players with 900+ minutes.

## Viewer

```bash
docker compose up -d    # http://localhost:9988
```

- Competition pages: matches, standings and top scorers per competition.
- **Players** (`players.php`): one table with every statistic from the Top-5 dataset plus
  linked Champions League / World Cup top-scorer numbers. Filter by league, position,
  minimum minutes and name; sort on any column; switch to per-90 values; hide column groups.
  A dash means "no value in the source", never zero. Click a player for the full breakdown.
- **Data coverage** (`coverage.php`): the field coverage report, generated live.

Players from football-data.org are linked to the dataset by name + birth year
(`player_links` table); unmatched players keep NULL for CL/WC instead of a guess.

## Structure

```
bin/import.php, bin/export.php   CLI commands
config/scope.php                 period, competitions and seasons
database/schema.sql              tables + team_strength and match_records views
src/FootballDataClient.php       API client: cache, throttling, 429 retry
src/Importer.php                 API -> database
src/Database.php                 SQLite connection, migrations, upsert
src/Score.php                    score normalisation (penalty shoot-outs)
src/View.php, templates/, public/  web viewer
```

Switching to MySQL later: change the DSN in `Database::connect()`, and adjust the
upsert (`ON DUPLICATE KEY UPDATE`) and the schema types.
