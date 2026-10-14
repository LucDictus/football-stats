# Data model

```
ImportBatch ──< SourceRecord              raw layer: every import, unchanged, with provenance
     │
     │ (latest batches)  app:build-season
     ▼
Player ──< PlayerIdentifier               stable identity; source ids point to it
  │
  └──< PlayerSeason ──< PlayerSeasonStint ──> Team, Competition
            │
            └──< PlayerCompetitionStats   one per scope: league | CL
                     values        {field: number}         known values only (missing ≠ 0)
                     valueOrigins  {field: provider}       who supplied each number
                     originChecks  {provider: status, minutes, reason}
                     batch         ──> ImportBatch         which import it came from

Team ──< TeamIdentifier                   "PSG" (Top-5 dataset), 1644 (SofaScore)
Competition, Season

ScoreRun ──< PlayerScore ──> Player        app:score: model definition + hash, source batches;
                                           rank, score, confidence and full breakdown per player
```

## Identity

A `Player` is never recreated by an import. Each source's way of referring to a player
is stored as a `PlayerIdentifier` (source + external id + how the link was made):

| Source | External id | Method |
|---|---|---|
| `top5-football-dataset` | `name\|birth year` (split clashes: `name\|year\|club`) | source key |
| `sofascore` | SofaScore player id | id in league data, sofascore id, initial + surname, name + club, manual, source id |

`app:build-season` looks identifiers up before creating anything, so re-running it
creates no duplicates and keeps every player id. The season's `PlayerSeason`, stints and
stats are rebuilt from the latest batches in one transaction. Matching rules for
Champions League players are in `src/DataImport/Identity/ClPlayerMatcher.php`;
manual links go in `config/identity/players.yaml`.

## Statistics

`PlayerCompetitionStats` keeps league and Champions League numbers apart (`StatsScope`).
Ask it, not the raw arrays:

- `value($field)`: the number, or null when unknown
- `origin($field)`: the provider that supplied it (FBref, Understat, SofaScore, derived)
- `fieldStatus($field)`: ok / partial / suspect / missing (see the coverage report)
- `trusted($field)`: value exists and its provider covers the whole scope
- `per90($field)`: rate on the supplying provider's own minutes; null when not usable

`PlayerScore` points to `Player` (stable), not to `PlayerSeason` (rebuilt every build), so old
score runs stay readable. Not modelled yet: awards and trophies (phase 7).
