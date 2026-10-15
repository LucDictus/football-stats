# Football MVP calculator

A transparent Ballon d'Or / MVP ranking for season 2025/26: the top-5 European leagues
(Premier League, La Liga, Serie A, Bundesliga, Ligue 1) plus the Champions League.
Every score should be explainable from data, rules and evidence.

- Data coverage report (what the data can and cannot tell): [docs/data-coverage.md](docs/data-coverage.md)
- New to the code? Start with [docs/onboarding.md](docs/onboarding.md)
- How scores are made: [docs/methodology.md](docs/methodology.md); the data-derived standards per role: [docs/standards.md](docs/standards.md) (generated); what drives the score per position: [docs/overlap-mvp-v3.md](docs/overlap-mvp-v3.md) (generated per model)
- Data model and identity rules: [docs/data-model.md](docs/data-model.md)
- Baseline code and data audit: [docs/audit.md](docs/audit.md)
- Stack: Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16, PHPUnit; React 19 + TypeScript + Vite frontend

## Setup

```bash
composer install
docker compose up -d database          # PostgreSQL on localhost:5432
cp .env .env.local                     # then set APP_SECRET
php bin/console doctrine:migrations:migrate
php bin/console --env=test doctrine:database:create && php bin/console --env=test doctrine:migrations:migrate -n
php bin/phpunit                        # unit tests + integration tests on the test database
composer qa                            # all quality checks: style, PHPStan level 8, linters, schema, tests
```

## Data

| Source | What | Command |
|---|---|---|
| [Top-5 Football Dataset](https://github.com/m-mahadi/top5-football-dataset) (FBref + Understat + SofaScore) | League player seasons | `php bin/console app:import:top5` |
| SofaScore (unofficial API) | Champions League player seasons | `php bin/console app:import:sofascore-cl` |
| FotMob (static JSON) | CL minutes/goals/assists, cross-check only | `php bin/console app:import:fotmob-cl` |

```bash
git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
php bin/console app:import:top5
php bin/console app:import:sofascore-cl    # needs the curl binary (SofaScore blocks PHP's TLS stack)
php bin/console app:import:fotmob-cl
php bin/console app:data:audit             # writes docs/data-coverage.md
php bin/console app:build-season           # players, teams, season stats (safe to re-run)
php bin/console app:standards:derive       # positional standards from two seasons → config/standards/, docs/standards.md
php bin/console app:score                  # ranking with the default model (mvp-v3), stored as a score run
php bin/console app:analyse:overlap        # writes docs/overlap-<model>.md: what drives the score per position
php -S 127.0.0.1:8010 -t public            # API + data inspector: http://127.0.0.1:8010
npm --prefix frontend install && npm --prefix frontend run dev   # the app: http://127.0.0.1:5173
```

Every import is stored unchanged as a new `import_batch` with its `source_record`s
(source URL, dataset version, import date). Older batches are kept, so results can be
reproduced after a source changes. The assessment scope (season, competitions) is set
in `config/services.yaml`.

## API

| Endpoint | What |
|---|---|
| `GET /api/models` | Released scoring models and their full definitions |
| `GET /api/rankings?model=&position=&limit=&offset=&unranked=` | Latest ranking of a model |
| `GET /api/players?q=` | Accent-insensitive name search |
| `GET /api/players/{id}` | Season, statistics per scope with source and trust per value, score breakdown |

## Structure

```
src/
  Command/       console commands (thin: they call services)
  Controller/    HTTP: JSON API (Api/) and the data inspector (developer tool)
  DataImport/    source readers and clients, raw import, identity matching, season builder
  DataQuality/   stint merging, provider consistency checks, data audit
  Entity/        raw layer and domain model (see docs/data-model.md)
  Api/           JSON presenters for the API
  Football/      assessment scope, field catalog, scoring models and engine, standards, model analysis
  Inspector/     read-only SQL for the inspector pages
  Repository/    Doctrine repositories
tests/           Unit/ (pure logic), Integration/ (pipeline on the test DB), Functional/ (HTTP)
frontend/   React app (see frontend/README.md)
legacy/     the original plain-PHP importer and viewer, kept as a reference
            until the Symfony version replaces it (see legacy/README.md)
docs/       audit and methodology
```
