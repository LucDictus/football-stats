# Onboarding: working in this codebase

Read this once, top to bottom, with the code open next to it. Then do the
"first hour" exercise at the end.

## 1. What the project is

A Ballon d'Or / MVP calculator for season 2025/26: top-5 leagues + Champions League.
The rule that drives every design decision is: **every score must be explainable**.
That means we must always be able to say where a number came from, whether it can be
trusted, and what was missing. Most of the code so far exists to make that possible.

The roadmap has 10 phases (see the README). Done: 1 (data audit), 2 (domain model).
Next: 3 (baseline score), with a React frontend on a JSON API.

## 2. The mental model: three layers

```
 SOURCES                RAW LAYER                    DOMAIN LAYER                    OUTPUT
 ───────                ─────────                    ────────────                    ──────
 Top-5 CSV  ─┐                                       Player ── PlayerIdentifier
 SofaScore  ─┼─ app:import:* ─▶ ImportBatch ─┐       PlayerSeason ── Stint
 FotMob     ─┘                  SourceRecord │       PlayerCompetitionStats    ─▶ inspector, audit,
                               (unchanged)   │       Team, Competition, Season     (later) scores, API
                                             │
                                             └─ app:build-season ─▶ (merge, check, link)
                                             └─ app:data:audit   ─▶ docs/data-coverage.md
```

1. **Raw layer.** An import stores what a source delivered, *unchanged*, as one
   `ImportBatch` with many `SourceRecord`s (JSONB). Batches are never overwritten: a new
   import is a new batch, and "current" means "latest". This gives provenance and
   reproducibility for free.
2. **Domain layer.** `app:build-season` reads the latest batches, merges, checks and
   links them, and writes clean entities. It can be re-run at any time.
3. **Output.** Everything downstream (audit report, inspector, later the scoring engine
   and API) reads the domain layer, or for the audit, the raw layer.

Why the split: sources are messy and change. If interpretation bugs are found (and we
found several), you fix the code and rebuild; you never need to re-download anything.

## 3. Directory map

```
src/
  Command/            console commands: thin, they call services and print results
  Controller/         HTTP. Today only the data inspector (dev tool)
  DataImport/
    Top5/             reads the Top-5 dataset CSV
    Sofascore/        fetches CL stats (via curl, see gotchas) into snapshot files
    Fotmob/           fetches FotMob stat lists (cross-check source)
    RawImporter.php   writes one batch + its records in one transaction
    Identity/         who is who: CL↔league matching, identifier registry, manual overrides
    Normalizer/       SeasonBuilder: raw batches → domain entities
  DataQuality/        stint merging, provider consistency checks, the data audit
  Entity/             Doctrine entities (raw layer + domain layer)
  Football/
    Assessment/       what is being assessed: season, competitions (AssessmentScope)
    Data/             the vocabulary: Field, FieldCatalog, Origin, OriginStatus
  Inspector/          read-only SQL for the inspector pages
  Repository/
config/
  services.yaml       assessment scope values + dataset path
  identity/players.yaml   manual identity links
docs/                 audit, coverage report (generated), data model, this file
legacy/               the original plain-PHP app; reference only, will be deleted
tests/                Unit/ (pure logic), Integration/ (real DB), Functional/ (HTTP)
```

## 4. Core vocabulary (learn these five)

| Concept | Where | Meaning |
|---|---|---|
| **Field** | `src/Football/Data/FieldCatalog.php` | A statistic the model may use: label, category, provider, its column in league data and in CL data. Adding a stat starts here. |
| **Origin** | `src/Football/Data/Origin.php` | The provider a number comes from: FBref, Understat, SofaScore, or derived. Each provider has its *own minutes*. |
| **OriginStatus** | `src/Football/Data/OriginStatus.php` | How far a provider can be trusted *for one player*: `ok`, `partial` (covers part of the season), `suspect` (probably someone else's record), `missing`. |
| **StatsScope** | `src/Entity/StatsScope.php` | `league` or `CL`. Never mixed: a CL goal must never end up in league totals. |
| **Identifier** | `src/Entity/PlayerIdentifier.php` | "Source X calls this player Y". How player ids stay stable. |

Three rules follow from these and are enforced in code:

- **Missing is never zero.** Unknown values are simply absent (`value()` returns null).
- **Per-90 uses the provider's own minutes** (`PlayerCompetitionStats::per90()`).
- **Only `ok` is "trusted"**; `partial` is usable for rates; `suspect`/`missing` are not.

## 5. Follow one player through the code

The best way to understand the pipeline: follow **Piero Hincapié** (Leverkusen → Arsenal
in January). Open `/inspector/players/22` in the browser next to the code.

1. **Import.** `ImportTop5Command` → `Top5CsvReader::read()` returns his two CSV rows
   (one per club), keyed `player|born|team|league`. `RawImporter::import()` stores them
   as `SourceRecord`s in batch #1, untouched.

2. **Merge.** `SeasonBuilder::build()` loads the batch and calls
   `Top5StintMerger::merge()` (`src/DataQuality/Top5StintMerger.php:35`):
   - Rows are grouped by name + birth year. His two rows become one player.
   - (A group with more than `MAX_SEASON_APPEARANCES` = 42 is two different people and
     gets split again: that is the "two Vitinhas" case.)
   - `mergePlayer()` (`:76`): FBref columns (minutes, goals, shots) are **summed** over the
     stints (1,792 + 90 = 1,882 min). Understat/SofaScore columns are **taken once**,
     because the source repeats the same value on every row.

3. **Check.** For each provider, `OriginConsistency::check()` compares the provider's
   minutes with FBref's. SofaScore has 90 minutes against FBref's 1,882, and he had two
   stints, so SofaScore is **partial** ("covers 90 of 1882 minutes (one club stint)").
   Understat has 1,786, within 15%, so **ok**. Derived fields like non-penalty goals are
   only calculated from trusted inputs.

4. **Link to the CL.** `ClPlayerMatcher::match()` (`src/DataImport/Identity/ClPlayerMatcher.php:36`)
   finds his SofaScore CL record. His league row carries SofaScore id 1002837 and so does the
   CL record, so rule 2 (exact id) links them. Read the class docblock for the four rules.

5. **Identity.** `SeasonBuilder::buildLeagueSeason()` (`src/DataImport/Normalizer/SeasonBuilder.php:102`)
   asks `IdentityRegistry` for a player with identifier `top5-football-dataset / Piero Hincapié|2002`.
   First build: none, so a `Player` is created and identifiers are stored. Every later
   build finds him through that identifier, so he stays player #22.

6. **Store.** One `PlayerSeason`, two `PlayerSeasonStint`s (Arsenal PL 1,792, Leverkusen
   BL1 90), and a `PlayerCompetitionStats` for the league with `values`, `valueOrigins`
   (which provider gave which number) and `originChecks` (status + minutes per provider).
   `buildClSeason()` (`:161`) adds the CL `PlayerCompetitionStats` to the same season.

7. **Read.** `PlayerCompetitionStats` (`src/Entity/PlayerCompetitionStats.php`) is the API
   for everything downstream: `value()`, `origin()`, `fieldStatus()`, `trusted()` and
   `per90()` (`:143`). His league tackles per 90 are calculated over SofaScore's 90
   minutes, not FBref's 1,882. That is the transfer bug from the audit, fixed in one place.

## 6. Commands

```bash
docker compose up -d database                 # PostgreSQL
php bin/console app:import:top5               # raw: league CSV → batch
php bin/console app:import:sofascore-cl       # raw: CL stats (--no-fetch to reuse the snapshot)
php bin/console app:import:fotmob-cl          # raw: cross-check data
php bin/console app:build-season              # domain: rebuild players/stats (idempotent)
php bin/console app:data:audit                # docs/data-coverage.md
php -S 127.0.0.1:8010 -t public               # inspector at http://127.0.0.1:8010
php bin/phpunit                               # all tests (needs the test DB, see README)
```

## 7. Tests

- `tests/Unit`: pure logic, no database: the merger, consistency checks, matcher and
  catalog. Fast; write these first. Cases are named after the real players that taught us
  the rule (Hincapié, Wesley, Vitinha).
- `tests/Integration/SeasonBuilderTest.php`: the real pipeline on the `app_test`
  database: no duplicates on rebuild, stable ids, per-90 on provider minutes.
- `tests/Functional`: the inspector pages render.
- `tests/Support/SourceFixtures.php`: builds realistic raw records; use it instead of
  hand-writing CSV rows.

## 8. How to do common tasks

**Add a statistic.** Add a `Field` to `FieldCatalog` (the league column, plus the CL column
if SofaScore has it). If it is a SofaScore CL field, add it to `SofascoreClient::FIELDS`
(`FieldCatalogTest` fails if you forget). Re-import the CL, then rebuild and audit. The
merger, the stats storage, the audit and the inspector pick it up automatically.

**Add a source.** Write a reader/client in `DataImport/<Source>/` and a command that hands
payloads to `RawImporter`. Then decide where it enters the domain: in `SeasonBuilder`, as a
new `Origin` if it provides stats from its own model.

**Fix a wrong player link.** Add the SofaScore id → league key to
`config/identity/players.yaml` and rebuild. The build output lists any identifier it
moved ("relinked").

**Change the season or competitions.** `AssessmentScope` in `config/services.yaml`.

## 9. Gotchas

- **SofaScore blocks PHP's HTTP stack** (TLS fingerprinting → 403) but accepts the `curl`
  binary. That is why `SofascoreClient` shells out to curl and works from snapshot files
  in `var/sources/sofascore/`. Don't "fix" it back to Symfony HttpClient.
- **Rebuilding recreates `PlayerSeason` rows**, so their ids change; `Player` ids don't.
  Link to players, never to player seasons.
- **League and CL xG are different models** (Understat vs SofaScore). Don't add them.
- **Container cache.** After changing a constructor, run `php bin/console cache:clear` if a
  command complains about arguments.
- **The audit reads the raw layer**, not the domain layer: it reports on the sources.
  The inspector reads the domain layer.
- **`legacy/`** has its own SQLite database and `.env`. Nothing in `src/` uses it.

## 10. Known rough edges (good first tasks)

- `DataAudit` returns a large array; a few small result objects would make it clearer.
- Dataset name constants live on the import commands (`ImportSofascoreCommand::DATASET`);
  they belong on the client classes.
- No minimum minutes per provider yet: a `partial` provider can rest on 90 minutes.
  Phase 3 needs a threshold before a per-90 counts.
- The 15 unlinked CL youngsters, and position data for CL-only players, are unknown.

## 11. Your first hour

1. Start the inspector and open Hincapié, a Vitinha, and Osimhen (CL only).
2. Read `FieldCatalog`, then `Top5StintMerger`, then `OriginConsistency`, with their unit tests.
3. Read `SeasonBuilder::build()` top to bottom.
4. Exercise: add SofaScore's `successfulDribbles` as a field (league column
   `ss_successfulDribbles`, CL field `successfulDribbles`). Re-import the CL, rebuild,
   run the tests, and find the new row in the inspector and the coverage report.
