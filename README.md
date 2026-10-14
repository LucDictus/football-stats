# Football MVP calculator

A transparent Ballon d'Or / MVP ranking for season 2025/26: the top-5 European leagues
(Premier League, La Liga, Serie A, Bundesliga, Ligue 1) plus the Champions League.
Every score should be explainable from data, rules and evidence.

- Data coverage report (what the data can and cannot tell): [docs/data-coverage.md](docs/data-coverage.md)
- New to the code? Start with [docs/onboarding.md](docs/onboarding.md)
- Data model and identity rules: [docs/data-model.md](docs/data-model.md)
- Baseline code and data audit: [docs/AUDIT.md](docs/AUDIT.md)
- Stack: Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16, PHPUnit

## Setup

```bash
composer install
docker compose up -d database          # PostgreSQL on localhost:5432
cp .env .env.local                     # then set APP_SECRET
php bin/console doctrine:migrations:migrate
php bin/console --env=test doctrine:database:create && php bin/console --env=test doctrine:migrations:migrate -n
php bin/phpunit                        # unit tests + integration tests on the test database
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
php -S 127.0.0.1:8010 -t public            # data inspector: http://127.0.0.1:8010
```

Every import is stored unchanged as a new `import_batch` with its `source_record`s
(source URL, dataset version, import date). Older batches are kept, so results can be
reproduced after a source changes. The assessment scope (season, competitions) is set
in `config/services.yaml`.

## Structure

```
src/
  Entity/        domain model (see docs/data-model.md)
  DataImport/    source readers and HTTP clients, raw import, identity matching, season builder
  DataQuality/   stint merging, provider consistency checks, data audit
  Football/      assessment scope, field catalog (later: metrics, scoring, ranking)
  Command/       console commands
legacy/     the original plain-PHP importer and viewer, kept as a reference
            until the Symfony version replaces it (see legacy/README.md)
docs/       audit and methodology
```
