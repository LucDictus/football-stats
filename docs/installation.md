# Installation and running locally

This guide takes you from an empty machine to the running app with the full 2025/26 ranking,
step by step. Every command below was run on a clean clone on 15 Oct 2026, and the rankings it
produced were identical, player for player, to the published runs.

The whole data pipeline takes about two minutes. The only slow part is installing dependencies.

## What you will have at the end

| What | Where |
|---|---|
| The app (ranking, player pages, compare, methodology) | http://localhost:5173 |
| The JSON API | http://localhost:8010/api/models (see the [API table](../DEVELOPERS.md#api)) |
| The data inspector (a developer tool for the raw and built data) | http://localhost:8010/inspector |
| PostgreSQL with the imported, built and scored season | localhost:5432, database `app` |

## 1. Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP | 8.3 or newer (the locked test tools need 8.3), with the extensions `pdo_pgsql`, `ctype`, `iconv`, `mbstring`, `xml` | The backend (Symfony 7.4) |
| Composer | 2.x | PHP dependencies |
| Docker with Compose | any recent | Runs PostgreSQL 16. A local PostgreSQL 16 works too; it needs the `unaccent` extension, which the migrations enable |
| Node.js | 22.22 or newer (tested on 24; React Router needs 22.22) | The frontend (Vite 8, Vitest 5) |
| git | any | Fetching the Top-5 dataset |
| curl (the command-line program) | any | Downloading the SofaScore Champions League data (see [why](#sofascore-returns-403)) |
| Google Chrome or Chromium | any | Only for the browser tests (`npm run e2e`) |

Check what you have:

```bash
php -v && php -m | grep -E 'pdo_pgsql|ctype|iconv|mbstring|xml'
```

```bash
composer -V && node -v && docker compose version && curl --version | head -1
```

On Ubuntu/Debian the PHP part is `sudo apt install php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml`
(ctype and iconv are built in).

## 2. Get the code and its dependencies

```bash
git clone <this repository> football-stats
```

```bash
cd football-stats && composer install
```

```bash
npm --prefix frontend ci
```

## 3. Configure

For running locally there is nothing to configure. The committed `.env` files hold
development defaults: `.env` has the database URL, which matches the Docker database below
(`postgresql://app:!ChangeMe!@127.0.0.1:5432/app`), and `.env.dev` and `.env.test` have
development secrets. These are not for a public server.

If you use your own PostgreSQL, put your own `DATABASE_URL` in **two** files that are not
committed: `.env.local` (for the app and the console) and `.env.test.local` (for the tests).
Symfony reads, each overriding the one before: `.env`, `.env.local`, `.env.<env>`,
`.env.<env>.local`; in the test environment it skips `.env.local`. The test database name gets
`_test` appended automatically.

The downloaded data (`var/sources/`) and the database are not in git: step 5 fetches and builds
them.

Everything that defines *what* is assessed (the season, its dates, the five leagues and the
Champions League ids at SofaScore and FotMob) is in `config/services.yaml`, under
`AssessmentScope`. You do not need to change it.

## 4. Start the database and create the tables

```bash
docker compose up -d database
```

```bash
php bin/console doctrine:migrations:migrate -n
```

The database keeps its data in a Docker volume (`database_data`), so it survives restarts.

## 5. Get the data and build the season

These steps are explained one by one in [data-pipeline.md](data-pipeline.md): what each source
is, what is downloaded, and what every step does to the data. In short:

**5.1 The Top-5 dataset** (league statistics for both seasons, team results). The rankings were
made with dataset commit `8940299c2c`; check it out to reproduce them exactly:

```bash
git clone https://github.com/m-mahadi/top5-football-dataset var/sources/top5-football-dataset
```

```bash
git -C var/sources/top5-football-dataset checkout 8940299c2c56c2c0932d3caf292336f5e1689893
```

**5.2 Import the three sources** (each stores what the source delivered, unchanged, as a new
import batch):

```bash
php bin/console app:import:top5
```

```bash
php bin/console app:import:sofascore-cl
```

```bash
php bin/console app:import:fotmob-cl
```

The SofaScore import downloads 9 pages (about 10 seconds, with a pause between pages) and keeps
them in `var/sources/sofascore/`. Use `--no-fetch` to import that saved snapshot again without
downloading.

**5.3 Build the season** (merge, check and link the raw data into players, teams and statistics):

```bash
php bin/console app:build-season
```

Expected: 2,691 league players, 878 CL players (550 linked to a league player), 157 roles
inferred from statistics.

**5.4 Score.** Each model's ranking is stored as a score run. The app shows the default model
(`mvp-v3`); the others are kept for comparison and reproducibility:

```bash
php bin/console app:score
```

```bash
for m in baseline-v1 positional-v1 positional-v2 mvp-v1 mvp-v2; do php bin/console app:score -m $m; done
```

Expected for `mvp-v3`: 1,531 ranked, 67 listed without a rank; Olise 99.1, Dimarco 95.1, Kane 93.6
at the top.

**5.5 Optional: the reports.** These write files in `docs/` that are already committed; running
them again on the same data changes nothing but the date.

| Command | Writes | What |
|---|---|---|
| `php bin/console app:data:audit` | `docs/data-coverage.md` | What the data can and cannot tell, measured |
| `php bin/console app:standards:derive` | `config/standards/standards-v1.json`, `docs/standards.md` | Derives the positional standards again from both seasons. With the same data it reports that nothing changed; it refuses to overwrite different numbers (`--dry-run` only prints) |
| `php bin/console app:analyse:overlap` | `docs/overlap-mvp-v3.md` | What drives the final score, per role |

## 6. Run the app

Two servers, each in its own terminal:

```bash
php -S 127.0.0.1:8010 -t public
```

```bash
npm --prefix frontend run dev
```

Open http://localhost:5173. The frontend server forwards every `/api` request to the backend on
port 8010, so both must run.

| Page | What it shows |
|---|---|
| Ranking (`/`) | Every ranked player, filterable by role and model |
| Player (`/players/{id}`) | His statistics with their source, the score built up step by step, and charts comparing every part of it with all players in his role |
| Compare (`/compare`) | Up to four players side by side: how each score is built, contribution in goals, the positional measures (radar), and where they stand among everyone. Plus the **stat explorer**: any statistic against any other as a scatter chart and a sortable table, filtered by line (forwards, midfield, defence, goalkeepers) or role |
| Methodology (`/methodology`) | The current model, generated from its definition |

## 7. Tests and quality checks

The tests run on a separate database, `app_test`. Create it once:

```bash
php bin/console --env=test doctrine:database:create
```

```bash
php bin/console --env=test doctrine:migrations:migrate -n
```

Then:

```bash
composer qa
```

`composer qa` runs everything that must pass before a commit: code style (PHP-CS-Fixer), static
analysis (PHPStan level 8), the Symfony linters, the Doctrine schema check, the backend tests
(PHPUnit: unit, integration on `app_test`, HTTP) and the frontend checks (TypeScript, oxlint,
Vitest). `composer fix` applies the code style.

The browser test needs both servers running and Chrome:

```bash
CHROME_PATH=/usr/bin/google-chrome npm --prefix frontend run e2e
```

## 8. Day to day

- **Start again after a reboot:** `docker compose up -d database`, then the two servers (step 6).
  The data stays in the database.
- **Rebuild after a code change to the pipeline:** `app:build-season`, then `app:score`. Nothing
  needs downloading again: the raw data is in the database.
- **New data from a source:** run its import again. A new import is a new batch; older batches
  are kept, and the build uses the latest. `app:score` stores a new run only when the results
  differ ("nothing new stored" otherwise).
- **Production build of the frontend:** `npm --prefix frontend run build` (output in
  `frontend/dist/`).

## 9. Troubleshooting

### SofaScore returns 403

SofaScore answers PHP's own HTTP client with 403 but answers the `curl` program, which is why the
import calls curl. If curl gets a 403 as well, open the pages in a browser and save each response
in `var/sources/sofascore/ut7-s76953/` as `page-1.json`, `page-2.json`, … up to the `pages` count
in the first response. Page *n* is
`https://api.sofascore.com/api/v1/unique-tournament/7/season/76953/statistics?limit=100&offset=<100·(n−1)>&order=-minutesPlayed&accumulation=total&fields=<the fields>`,
with the field list from `SofascoreClient::FIELDS` joined by commas (the URL of the failing page
is in the error message). Then run `php bin/console app:import:sofascore-cl --no-fetch`.

### "Connection refused" on port 5432

The database is not running: `docker compose up -d database`. If another PostgreSQL already uses
port 5432, stop it or change the port in `compose.override.yaml` and `DATABASE_URL`.

### The app says "The API is not reachable"

The backend is not running on port 8010 (the frontend only forwards to it). Start it (step 6)
and check http://localhost:8010/api/models.

### "Season 2025/26 is not built yet" or "No ranking yet for mvp-v3: run app:score"

The database is empty or not scored. Run step 5: the imports, `app:build-season` and `app:score`.
(The tests do not need this: they build their own data.)

### A command complains about constructor arguments after a code change

`php bin/console cache:clear`.

### Tests fail with "database app_test does not exist"

Create the test database (step 7). Tests run in parallel by setting `TEST_TOKEN`, which uses
`app_test<token>`; create that database the same way with `TEST_TOKEN=<n>` in front.

### `app:standards:derive` refuses to write

By design: the standards file belongs to a released model and must never change. With new data,
derive under a new id (`--id=standards-v2`), which becomes a new model version.
