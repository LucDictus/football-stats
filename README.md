# Football MVP calculator

A transparent Ballon d'Or / MVP ranking for season 2025/26: the top-5 European leagues
(Premier League, La Liga, Serie A, Bundesliga, Ligue 1) plus the Champions League.
Every score should be explainable from data, rules and evidence. (The Champions League data is
imported, linked and audited; it enters the score in roadmap phase 6.)

## Documentation

| Read this | To learn |
|---|---|
| [docs/installation.md](docs/installation.md) | How to install everything and run it locally, step by step, with troubleshooting |
| [docs/data-pipeline.md](docs/data-pipeline.md) | How we got the data: every source, every download and every step from raw rows to clean statistics |
| [docs/methodology.md](docs/methodology.md) | How players are measured and scored, every model version, worked examples and known limitations |
| [docs/standards.md](docs/standards.md) | The data-derived standards per role: every candidate statistic, the numbers behind it and why it counts or not (generated) |
| [docs/data-coverage.md](docs/data-coverage.md) | What the data can and cannot tell, measured on the actual records (generated) |
| [docs/overlap-mvp-v3.md](docs/overlap-mvp-v3.md) | What drives the final score, per role (generated per model) |
| [docs/data-model.md](docs/data-model.md) | The database: raw layer, players, roles, identity, statistics, score runs |
| [docs/onboarding.md](docs/onboarding.md) | Working on the code: architecture, a player followed through the pipeline, common tasks, gotchas |
| [docs/audit.md](docs/audit.md) | The original code and data audit that started the project |
| [frontend/README.md](frontend/README.md) | The React app |

Stack: Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16, PHPUnit; React 19 + TypeScript + Vite.

## How it works, in one minute

1. **Data.** League statistics for every player in the top-5 leagues come from the open
   [Top-5 Football Dataset](https://github.com/m-mahadi/top5-football-dataset) (FBref, Understat,
   SofaScore and EA SPORTS FC positions); Champions League statistics from SofaScore, checked
   against FotMob. Every download is stored unchanged, so every number can be traced back to it.
2. **Cleaning.** Transfers are merged into one season per player, each provider is checked
   against FBref's minutes, Champions League records are linked to league players, and every
   player gets one of eight roles (striker, winger, attacking/central/defensive midfielder,
   full-back, centre-back, goalkeeper). Missing is never counted as zero.
3. **Standards.** For each role, the data decides which statistics count and how much: a
   statistic counts when players who do more of it play in teams that win (in each of two seasons),
   and it weighs more when it is the player's own repeatable doing rather than luck.
4. **Score.** 50% *positional excellence* (how far a player stands above the others in his role on
   those standards; 50 = the role's average, every 10 points one standard deviation) + 50%
   *contribution* (the goals he added above a replacement-level player of his role).
5. **Explained.** The app shows every statistic with its source, how each point was earned, and
   charts that compare every part of the score with all other players.

## Quick start

The full guide, with prerequisites and expected output, is [docs/installation.md](docs/installation.md).

```bash
composer install && npm --prefix frontend ci
docker compose up -d database                 # PostgreSQL 16 on localhost:5432
php bin/console doctrine:migrations:migrate -n
git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
git -C var/sources/top5-football-dataset checkout 8940299c2c56c2c0932d3caf292336f5e1689893
php bin/console app:import:top5
php bin/console app:import:sofascore-cl       # needs the curl program
php bin/console app:import:fotmob-cl
php bin/console app:build-season
php bin/console app:score                     # the default model, mvp-v3
php -S 127.0.0.1:8010 -t public               # API + data inspector, keep running
npm --prefix frontend run dev                 # the app: http://localhost:5173
```

Tests and quality checks (after creating the test database once, see the installation guide):
`composer qa`.

## Commands

| Command | What |
|---|---|
| `app:import:top5` | League player seasons from the Top-5 dataset → a raw import batch |
| `app:import:sofascore-cl [--no-fetch]` | Champions League statistics from SofaScore → a raw batch (snapshot in `var/sources/sofascore/`) |
| `app:import:fotmob-cl` | CL minutes, goals and assists from FotMob → a raw batch (cross-check only) |
| `app:data:audit` | Writes `docs/data-coverage.md` |
| `app:build-season` | Players, teams, roles and season statistics from the latest batches (safe to re-run) |
| `app:standards:derive [--dry-run]` | Positional standards per role from two seasons → `config/standards/`, `docs/standards.md` |
| `app:score [-m model]` | Scores a model and stores the ranking as a score run |
| `app:analyse:overlap [-m model]` | Writes `docs/overlap-<model>.md` |

Every import is stored unchanged as a new `import_batch` with its `source_record`s
(source URL, dataset version, import date). Older batches are kept, so results can be
reproduced after a source changes. The assessment scope (season, competitions) is set
in `config/services.yaml`.

## API

| Endpoint | What |
|---|---|
| `GET /api/models` | Released scoring models and their full definitions |
| `GET /api/rankings?model=&run=&position=&limit=&offset=&unranked=` | A ranking (latest run of the model unless `run` is given); `position` is a role (ST, W, …) for mvp-v3, a group (FW, MF, DF, GK) for older models |
| `GET /api/distribution?model=&run=&position=&part=&component=&metric=` | How one part of the score is spread over all ranked players: `part` = final, positional, contribution, component or metric (the charts) |
| `GET /api/stats?x=&y=&per=90\|total&min_minutes=&model=&run=` | Two statistics (or parts of the score) for every league player, with role and line: the stat explorer. Unknown values are left out and counted per role |
| `GET /api/players?q=` | Accent-insensitive name search |
| `GET /api/players/{id}?model=&run=` | Season, statistics per scope with source and trust per value, score breakdown |

Errors are JSON with a status code: 400 for a malformed request, 404 for an unknown model, run
or player, or when nothing is scored yet.

The app's pages: ranking (`/`), player (`/players/{id}`), compare up to four players and the stat explorer
(`/compare?ids=`), methodology (`/methodology`). The data inspector (`/inspector` on the backend)
shows the raw and built data for developers.

## Structure

```
src/
  Command/       console commands (thin: they call services)
  Controller/    HTTP: JSON API (Api/) and the data inspector (developer tool)
  EventSubscriber/  JSON error responses for the API
  DataImport/    source readers and clients, raw import, identity matching, season builder
  DataQuality/   stint merging, provider consistency checks, data audit
  Entity/        raw layer and domain model (see docs/data-model.md)
  Api/           JSON presenters for the API
  Football/      assessment scope, field catalog, scoring models and engine, standards, model analysis
  Inspector/     read-only SQL for the inspector pages
  Repository/    Doctrine repositories
tests/           Unit/ (pure logic), Integration/ (pipeline on the test DB), Functional/ (HTTP)
config/standards/  the frozen, data-derived positional standards
config/identity/   manual player links
frontend/   React app (see frontend/README.md)
legacy/     the original plain-PHP importer and viewer, kept as a reference
            until the Symfony version replaces it (see legacy/README.md)
docs/       installation, data pipeline, methodology, data model, onboarding, generated reports
```
