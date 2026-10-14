# Football MVP calculator

A transparent Ballon d'Or / MVP ranking for season 2025/26: the top-5 European leagues
(Premier League, La Liga, Serie A, Bundesliga, Ligue 1) plus the Champions League.
Every score should be explainable from data, rules and evidence.

- Roadmap status and the baseline data audit: [docs/AUDIT.md](docs/AUDIT.md)
- Stack: Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16, PHPUnit

## Setup

```bash
composer install
docker compose up -d database          # PostgreSQL on localhost:5432
cp .env .env.local                     # then set FOOTBALL_DATA_API_KEY and APP_SECRET
php bin/console doctrine:migrations:migrate
php bin/phpunit
```

Source data (Top-5 Football Dataset: FBref + Understat + SofaScore):

```bash
git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
```

## Structure

```
src/        Symfony application (domain model, importers, scoring)
legacy/     the original plain-PHP importer and viewer, kept as a reference
            until the Symfony version replaces it (see legacy/README.md)
docs/       audit and methodology
```
