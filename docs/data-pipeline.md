# How we got the data

Every number in the ranking can be traced back to a download, through the steps below. This page
explains each step in order: what is fetched, from where, what is done to it, and why. The
measured results of these steps (coverage, conflicts between providers, unlinked players) are in
the generated [data-coverage.md](data-coverage.md). How the data becomes a score is in
[methodology.md](methodology.md).

```
 1. Scope        what is assessed: season 2025/26, top-5 leagues + Champions League
 2. Sources      Top-5 dataset (git) · SofaScore API (curl) · FotMob (JSON)
 3. Raw import   stored unchanged, one batch per import       app:import:top5 / -sofascore-cl / -fotmob-cl
 4. Audit        what the data can and cannot tell             app:data:audit → data-coverage.md
 5. Build        merge stints, check providers, link CL,       app:build-season
                 identity, roles
 6. Standards    two seasons, in memory, + team results         app:standards:derive → config/standards/
 7. Score        model definition + built data → ranking        app:score → score run
```

## 1. Scope: what is assessed

Set once in `config/services.yaml` (`AssessmentScope`), and shown at the top of the coverage
report:

- **Season 2025/26**, matches from 1 Aug 2025 to 30 May 2026.
- **Domestic league matches** in the Premier League, La Liga, Serie A, Bundesliga and Ligue 1.
- **The UEFA Champions League** from the league phase on.
- **Left out:** CL qualifying, domestic cups, super cups, the Club World Cup and international
  matches. No free source has player statistics for these at the same level of detail.

## 2. The sources, and why these

We needed the same detailed statistics (expected goals, passing, defending, goalkeeping) for
every player in five leagues, from sources anyone can download again. No single free source has
it all, so three are combined. Each source and each provider inside a source keeps its own name
all the way into the database (`Origin`: FBref, Understat, SofaScore, derived), so every value
in the app says where it came from.

| Source | What it gives us | How it is fetched | Used for |
|---|---|---|---|
| [Top-5 Football Dataset](https://github.com/m-mahadi/top5-football-dataset) (m-mahadi; a compilation, each provider keeps its rights) | One row per player per club, 2024/25 and 2025/26, 220 columns from four providers: **FBref** (minutes, goals, assists, shots; the reference for playing time), **Understat** (xG, npxG, xA, xGChain, xGBuildup, with its own minutes), **SofaScore** (115 columns: passing, duels, tackles, saves, errors, …, with its own minutes), **EA SPORTS FC** (63 columns: positions and ratings). Also every league match with its score | `git clone` into `var/sources/top5-football-dataset`. The rankings use commit `8940299c2c` | League statistics, roles, team results |
| SofaScore web API (public but unofficial) | Season totals per player for the Champions League: 29 statistics, the same names as the dataset's SofaScore columns | `api.sofascore.com/api/v1/unique-tournament/7/season/76953/statistics`, 100 players a page, 9 pages, fetched with `curl` and saved as `var/sources/sofascore/ut7-s76953/page-N.json` | Champions League statistics |
| FotMob (static stat lists) | Minutes, goals and assists per CL player | `data.fotmob.com/stats/42/season/28184/{mins_played,goals,goal_assist}.json` | A cross-check of the SofaScore CL data only, never in a score |

From the Top-5 dataset two files are read: `data/master/player_seasons.csv` (players) and
`data/schedule/matches.csv` (every league match with its score, for team results).

**Why the earlier CL source was dropped.** The first version of this project (still in
`legacy/`) took its Champions League numbers from football-data.org. The [audit](audit.md) found
that its free tier has only the top-100 scorers (goals, assists, penalties, appearances), no
minutes and no xG, and returns "empty" where it means zero. SofaScore replaced it.

## 3. Raw import: store exactly what was delivered

```bash
php bin/console app:import:top5
php bin/console app:import:sofascore-cl
php bin/console app:import:fotmob-cl
```

Each import stores what the source delivered, **unchanged**, as one `ImportBatch` with one
`SourceRecord` (JSON) per row (`RawImporter`). A batch records the source, the dataset, the
source URL, the dataset version (the git commit for the Top-5 dataset), the import time and the
number of rows rejected. 2025/26 gives:

| Source | Records | What a record is |
|---|---:|---|
| Top-5 dataset | 2,839 | a player's stint at one club (148 players moved during the season) |
| SofaScore | 878 | a player's CL campaign at one club |
| FotMob | 1,441 | a player's value for one statistic |

Rules:

- **Batches are never overwritten.** Importing again creates a new batch, and "current" means
  "latest". An old ranking can always be traced to the exact data it was made from.
- **An empty import is refused**, so a failed download can never replace the season with
  nothing.
- **SofaScore is fetched with the `curl` program**, because SofaScore answers PHP's HTTP client
  with 403. Pages are fetched one at a time with a pause between them. Pages go to a temporary folder first and replace the snapshot only when
  all of them arrived. `--no-fetch` imports the saved snapshot again.

Because interpretation happens only after this step, a bug in how we read the data is fixed in
code and rebuilt, without downloading anything again. That happened several times (see the
audit).

## 4. Audit: what can the data tell?

```bash
php bin/console app:data:audit      # writes docs/data-coverage.md
```

Before any scoring we measured, on the actual records, which statistics exist for how many
players, where providers disagree and why. The audit reads the raw layer, so it reports on the
sources themselves. The problems it found, and how each is handled in the next step:

| Problem found | Example | How it is handled |
|---|---|---|
| A transferred player has one row per club. FBref numbers are per club, but Understat and SofaScore repeat **one** league's numbers on every row | Hincapié: SofaScore covers 90 of his 1,882 minutes | FBref is summed; the other providers are taken once and marked *partial*; per-90 rates use each provider's own minutes |
| The dataset merges two different people with the same name and birth year | Two Vitinhas born in 2000 | Split again; values that cannot be attributed are marked *suspect* |
| Understat has no record for some well-known players (name matching in the dataset) | 89 players with 900+ minutes | SofaScore estimates, calibrated (see the methodology) |
| FBref goals include penalties | | Non-penalty goals = goals − SofaScore penalty goals, only when SofaScore is trusted for the player |
| League and CL xG come from different models (Understat vs SofaScore) | | Never added together |
| A missing value must not count as zero | | Missing is stored as absent, never 0 |

The full list, with numbers, is in [audit.md](audit.md) (the original audit) and
[data-coverage.md](data-coverage.md) (regenerated from the current data).

## 5. Build the season

```bash
php bin/console app:build-season
```

`SeasonBuilder` reads the latest batch of each source and writes the domain layer: players,
teams, their season and statistics ([data-model.md](data-model.md)). It runs in one transaction
and can be repeated at any time; it never creates a player twice. Step by step:

### 5.1 One season per player (`Top5StintMerger`)

Rows are grouped by name + birth year (the dataset's identity).

- **FBref** columns are per club and are **summed** over the stints. One unknown stint makes the
  sum unknown.
- **Understat and SofaScore** columns are repeated on every row, so they are **taken once**,
  with that provider's own minutes.
- A group with more than 42 appearances (`MAX_SEASON_APPEARANCES`) cannot be one player in a
  league season: it is two people, split again by club.

### 5.2 Can each provider be trusted for this player? (`OriginConsistency`)

FBref is the reference for playing time. For every player, Understat's and SofaScore's minutes
are compared with FBref's:

| Status | Meaning | Used for |
|---|---|---|
| **ok** | Within 15% of FBref's minutes (at least 30 minutes), and SofaScore goals within 1 of FBref | Everything |
| **partial** | The player changed leagues and the provider has only one of them: real numbers, but fewer minutes | Per-90 rates on the provider's own minutes |
| **suspect** | Disagrees with FBref for a single club: probably someone else's record merged in | Nothing (treated as missing) |
| **missing** | No record | Nothing |

Of the 1,598 players with 900+ league minutes, Understat is *ok* for 1,475 and SofaScore for
1,499.

**Derived values** are calculated only from trusted inputs. Non-penalty goals are FBref goals
minus SofaScore penalty goals when SofaScore is *ok*, otherwise Understat's own non-penalty
goals.

### 5.3 What is stored per player

A `PlayerCompetitionStats` row per competition (league or CL, never mixed) holds:

- `values`: every known statistic. 78 statistics are defined in `FieldCatalog`: 7 from FBref,
  5 from Understat, 62 from SofaScore and 4 derived.
- `valueOrigins`: which provider gave each number.
- `originChecks`: each provider's status, minutes and reason.

**Per 90** is always calculated on the minutes of the provider that supplied the number, and only
from 450 minutes on. Fewer minutes give no rate, because 90 minutes of data is not a rate.

### 5.4 Who is who (`IdentityRegistry`)

Each source's way of naming a player is stored as a `PlayerIdentifier`: name + birth year for the
Top-5 dataset, the player id for SofaScore. The build looks these up before creating anything, so
a player keeps the same id across rebuilds and new imports.

### 5.5 Link the Champions League (`ClPlayerMatcher`)

Each SofaScore CL record is linked to a league player by the first rule that matches:

1. a manual link in `config/identity/players.yaml`;
2. the same SofaScore id (the league row carries it), when that record is not suspect;
3. first initial + surname, unique (narrowed by club when ambiguous);
4. same club, and one name contains the other ("Fabián Ruiz" / "Fabián Ruiz Peña").

Rules 3 and 4 only consider league players without a trusted SofaScore id. Each league player is
linked to at most one CL record. Result for 2025/26: 535 of the 550 CL players at top-5 clubs are
linked (514 by id, 11 by initial + surname, 10 by name + club). The 15 unlinked are youth players
with no league minutes. 328 CL players are at clubs outside the top five; 15 of them also played in a top-5 league
(linked as transfers), so 313 have CL data only. SofaScore
was checked against FotMob: minutes, goals and assists agree for all 822 players matched by name.

### 5.6 Roles (`RoleCatalog`, `RoleInference`)

The source's position (FBref: GK, DF, MF, FW) is too coarse to compare like with like, so every
player also gets one of eight roles: striker, winger, attacking midfielder, central midfielder,
defensive midfielder, full-back, centre-back, goalkeeper.

- From **EA SPORTS FC** (in the dataset): the club line-up position (`fifa_club_position`),
  otherwise the player's best position (`fifa_best_position`). A wide midfielder in the line-up
  whose best position is full-back is a wing-back in a back three, so a full-back.
- Goalkeepers are goalkeepers whatever the game says, and the role must suit the FBref position
  group (a "winger" listed as a defender becomes a full-back).
- **Without an EA FC position** (157 players), the role whose typical statistical profile is
  nearest is used. This is right for 68% of the labelled players it can be checked on (872 of
  1,292), against 47% for simply taking each group's most common role.
- Players with too few statistics to infer from get their group's most common role.

The player page shows where each role came from (`roleSource`: `ea_fc_club`, `ea_fc_best`,
`inferred`, `default`, `position`).

### 5.7 Teams

Teams are matched by name per source (`TeamIdentifier`: "PSG" in the Top-5 dataset, id 1644 at
SofaScore). A player who moved clubs counts with his main club (the most minutes) in team totals.

## 6. The second season and team results (for the standards)

The positional standards ask "which statistics go with winning, and repeat from season to
season?" That needs **two** seasons and the **team results**:

- `Top5StandardsSample` reads 2024/25 and 2025/26 from the same `player_seasons.csv`, with exactly
  the same merging, trust checks and roles as the build, but **in memory**. The 2024/25 season is
  not stored in the database, because it is not being ranked.
- `Top5TeamResults` reads `matches.csv`: every league match with its score, giving each team's
  goal difference per match (play-off matches excluded).
- Possession is not in any source, so it is estimated from passing: a team's accurate passes per
  90 against its league's average (`PossessionAdjustment`).

```bash
php bin/console app:standards:derive
```

This writes `config/standards/standards-v1.json` (frozen: a released model uses it) and the
readable [standards.md](standards.md). How a statistic earns points is explained in
[methodology.md](methodology.md#the-positional-standards-how-a-statistic-earns-points).

## 7. Score

```bash
php bin/console app:score
```

The model definition (`ModelCatalog`) and the built season give a ranking, stored as a
`ScoreRun`. A run keeps the model's full definition and its SHA-256 hash, the import batches its
data came from, and per player the rank, score, confidence and the complete breakdown the app
shows. A new run is stored only when it differs from the latest. Read
[methodology.md](methodology.md) for how the score is calculated.

## Reproducing a ranking

Everything needed to produce a ranking again is fixed:

- the source data: the dataset commit, the SofaScore snapshot and the raw batches kept in the
  database;
- the code: this repository;
- the model: its definition and hash, pinned by a test once released;
- the standards file: frozen, and refused if anything would change it.

On 15 Oct 2026 a clean clone, with a fresh download from all three sources, reproduced all six
released models' rankings player for player (see [installation.md](installation.md)).

## What the data does not have

These limits follow from the sources and are repeated in the methodology where they matter:

- **No match-level or event data**: season totals only. So there is no "with and without the
  player on the pitch" and no game-state context.
- **No possession figures**: possession is estimated from passing.
- **No pre-shot xG against** for defending, and no blocks, distance or sprints in 2024/25.
- **Champions League data is SofaScore only**: 29 statistics, no Understat. It is imported, linked
  and audited, but not yet part of a score (roadmap phase 6).
- **EA SPORTS FC positions are a label from a video game**: curated for the season, but not a
  measurement. They are missing for a quarter of La Liga rows.

## Data terms and attribution

The statistics belong to their providers: **FBref** (Sports Reference), **Understat**,
**SofaScore**, **EA SPORTS FC** (positions) and **FotMob**, compiled for the leagues by the
[Top-5 Football Dataset](https://github.com/m-mahadi/top5-football-dataset), which states that it
does not license the data itself and that each source's terms apply. The SofaScore API is public
but unofficial. This project downloads the data for analysis; it does not redistribute it: the
downloads (`var/sources/`) and the database are not in git. The generated reports in `docs/`
(coverage, standards, overlap) quote statistics of individual players. Check the providers' terms
before publishing the repository or the app.

