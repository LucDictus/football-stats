# Codebase & data audit — 14 Oct 2026

Baseline audit before starting the Ballon d'Or / MVP roadmap. Everything below was
checked against the actual database (`var/data.sqlite`) and source CSV, not just the code.

## 1. What exists

**Stack:** plain PHP 8.3, no framework, no Composer, hand-written autoloader, SQLite,
`php -S` in Docker. ~1,000 lines of PHP in `src/`, 4 CLI scripts, 3 web pages.
**Not a git repository. No tests.**

| Part | Files | State |
|---|---|---|
| football-data.org client | `FootballDataClient` | Good: disk cache, throttling, 429 retry |
| Match/standings/scorers import | `Importer`, `bin/import.php`, `schema.sql` | Good: transactional per resource, upserts, `import_log`, season check |
| Top-5 dataset import | `Top5Importer`, `bin/import-top5.php` | Works, but one wrong assumption about transfers (see 3.1) |
| Field registry | `config/fields.php` | Good idea — a single place that maps stat → source column, level, caveats |
| Coverage report | `CoverageReport`, `bin/coverage.php`, `public/coverage.php` | Measures *presence* (NULLs), not *correctness* |
| Player linking | `PlayerLinker` | Name + birth-year matching, ambiguous matches skipped |
| Viewer | `public/*.php`, `templates/`, `View`, `PlayerTable` | Player table with filters/per-90/sort, player detail, live API competition pages |
| Scoring / ranking | — | **Nothing yet** |

Good principles already in place that should be kept: NULL is never turned into 0,
provenance columns (`source`, `source_updated`, `imported_at`), dataset git commit
recorded in `source_imports`, assessment period in config.

## 2. What data is actually in the database

**Assessment period:** 1 Jul 2025 – 19 Jul 2026 (`config/scope.php`). Season 2025/26.

| Source | Content | Volume |
|---|---|---|
| Top-5 dataset (FBref + Understat + SofaScore), commit `8940299c` (18 Aug 2026) | Player-season stats, top-5 leagues only | 2,839 stint rows → 2,690 players (149 merged transfers), 1,597 with 900+ min, 194 GKs |
| football-data.org matches | Every match, scores, stage | 1,941 matches (PL/PD/SA 380, BL1/FL1/DED 306, CL 189, WC 104); all finished except 1 awarded (Nantes–Toulouse) |
| football-data.org standings | Final tables | All 8 competitions |
| football-data.org `/scorers` | Top 100 scorers per competition: apps, goals, assists, pens | 800 rows, 689 players |

The season is complete in the source (max 38 apps PL/PD/SA, 34 BL1/FL1).

### Coverage matrix (Phase 1.1)

| Statistic | Top-5 leagues | Champions League | Usable for v1? |
|---|---|---|---|
| Minutes, apps, starts | FBref, 100% | **None** | Leagues: yes |
| Goals, assists | FBref, 100% | Top-100 scorers only (≥2 goals), no minutes | Leagues: yes. CL: partial |
| Penalty goals | SofaScore, 96% | Top scorers only | Yes (see 3.4) |
| xG, npxG, xA, xGChain, xGBuildup | Understat, 94% | None | Yes, with 3.1 + 3.3 fixed |
| Shots, shots on target | FBref, 100% | None | Yes |
| Key passes, big chances, passing | SofaScore, 96% | None | Yes, with 3.1 fixed |
| Progressive passes | **No source** | None | No |
| Tackles, interceptions, blocks, clearances, recoveries, aerials, errors | SofaScore, 96% | None | Yes, with 3.1 fixed |
| Saves, goals conceded, clean sheets | SofaScore, 95% of GKs | None | Yes |
| Goals prevented | SofaScore, 93% of GKs | None | Yes |
| Opponent strength, results | football-data, 100% of matches | 100% of matches | Team level only — not linked to players |
| Trophies, awards | **No source** | — | Later |

## 3. Problems found (in order of impact on a ranking)

### 3.1 Transferred players: Understat/SofaScore values are often one club only — BUG

`Top5Importer` treats `us_*` and `ss_*` columns as "already season totals, take once".
That is only true for transfers **within** one league. For transfers **between** leagues
the value covers only one of the leagues, while minutes (FBref) are summed over both.

| Player | FBref minutes (summed) | SofaScore minutes | Understat minutes |
|---|---|---|---|
| Piero Hincapié (Leverkusen → Arsenal) | 1,882 | **90** | 1,786 |
| Álex Jiménez | 2,362 | **45** | — |
| Pascal Groß | 2,249 | 618 | 1,635 |
| Donyell Malen | 2,123 | 1,478 | 1,499 |

Of 149 multi-club players, 65 have partial SofaScore and 59 partial Understat values;
**76 of them have 900+ minutes**. Per-90s computed with FBref minutes are then badly
understated (Hincapié's defensive stats per 90 would be ~5% of reality).

The importer's conflict check never fires because the source *repeats the same partial
value* on every stint row. Fix: for every source, store its own minutes
(`us_minutes`, `ss_minutesPlayed`) and compute per-90s against **that source's** minutes,
or flag/exclude the row when source minutes < ~90% of FBref minutes.

### 3.2 Wrong-person merges in the source dataset

Duplicate names with different birth years (Nicolás González 1998/2002, David López
1989/2003, Wesley 2003/2005) got the wrong SofaScore record: e.g. Wesley (Roma)
2,448 FBref min vs 91 SofaScore min; Nicolás González (Man City) 1 goal vs 5.
93 single-club players differ by >20% in minutes between FBref and SofaScore.
→ Add a **cross-source consistency check** (minutes and goals) to the coverage report,
and mark rows that fail it.

### 3.3 Understat missing for prominent players

58 players with 1,500+ minutes have no Understat data at all, including
Gabriel Magalhães, Igor Thiago (22 goals), Rayan Cherki, Đorđe Petrović. A name-match
failure in the dataset, not "no xG". The model must treat these as missing, never as 0,
and the ranking must show reduced confidence for them.

### 3.4 Penalties: not yet a field the model can use

`fb_gls` **includes** penalties (Kane 36 = 26 non-penalty + 10 pens; verified against
`us_np_goals` for 2,323 of 2,335 players). Non-penalty goals are not imported as a field.
→ Add `np_goals` (derived: `goals − penalty_goals`, or `us_np_goals`).

### 3.5 Champions League data is too thin for a CL component

Only football-data's top-100 scorers (minimum 2 goals): goals, assists, pens, apps.
No minutes, no xG, nothing for defenders, keepers or non-scoring midfielders.
71 of 100 are linked to a top-5 player; the rest are mostly non-top-5 clubs (correct),
but **Estêvão (Chelsea)** and **Marcus Thuram (Inter)** failed on name variants.
Note: the free tier has no CL lineups/minutes. The Top-5 source repo has a
`sofascore_ucl_shots.csv` but only for Barcelona.

### 3.6 NULL semantics of football-data `/scorers`

The code treats NULL assists/penalties as "unknown". In practice the API seems to
return NULL for zero (Kvaratskhelia: 10 CL goals, penalties NULL; Haaland: assists NULL).
32% of CL assists and 75% of CL penalties are NULL. Needs a decision: treat as 0 for
players on the list, documented.

### 3.7 No stable player identity

`ext_player_seasons` is dropped and recreated on each import (AUTOINCREMENT id), and
`player_links` is rebuilt. Player IDs in URLs change on every re-import and nothing can
reference a player durably (scores, awards, comparisons). The roadmap's
`Player` / `PlayerSeason` split doesn't exist yet: identity = name + birth year string.

### 3.8 Smaller issues

- Coverage report says "complete" for fields that are present but inconsistent (3.1, 3.2).
- football-data league goals differ from FBref by ±1 for 10 players (attribution/own goals). Acceptable; document.
- Opponent strength = final table position (hindsight, relative within one competition), not connected to player data.
- Scope includes **Eredivisie** and the **World Cup**, which are outside the roadmap target. DED scorers cannot link (no Eredivisie in the Top-5 dataset: 2 of 100 linked). Decide whether WC counts (Phase 1.2).
- Dead code: `templates/player.php`, `templates/players.php`, and `PlayerRepository::ranking()`, `competitionsWithoutStats()`, `player()`, `SORTS` are no longer used.
- `public/index.php` calls the live API on every page view (fine for a viewer, but separate from the imported data).
- The Top-5 source also has **2024/25**, useful later as a second season for validation.

## 4. Decisions needed before Phase 2

1. **Assessment scope:** top-5 leagues + CL only? Drop Eredivisie? Include World Cup 2026?
2. **Framework:** migrate to Symfony now (roadmap Phase 2) or keep plain PHP and add Composer + PHPUnit first?
3. **Database:** stay on SQLite, or move to PostgreSQL as the roadmap suggests?
4. **CL data:** accept goals/assists-only for CL in v1, or look for a richer source?
5. **Transfers (3.1):** per-source minutes for per-90s (recommended), or exclude partial rows?

## 5. Suggested immediate next steps

1. `git init` and a first commit (before any refactoring).
2. Fix 3.1 and add the consistency checks (3.2, 3.3) to the coverage report — this is the
   real Phase 1 deliverable: the report must say which numbers are *trustworthy*, not just present.
3. Add `np_goals` (3.4) and resolve the NULL decision (3.6).
4. Then Phase 2: stable identities and the domain model.
