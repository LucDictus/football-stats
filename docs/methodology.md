# Methodology

The public version of this page is in the app (`/methodology`) and is generated from the
model definition the API serves. This document adds the engineering detail.

## Models are versioned

A model (`src/Football/Scoring/ModelCatalog.php`) defines its scope, who is eligible, its
components, their metrics and all weights. A released model is never edited: a change is a
new version. Every `ScoreRun` stores the full definition, its SHA-256 hash and the import
batches its statistics came from, so any published ranking can be explained and reproduced.
`app:score` does not store a run that would be identical to the latest one.

## baseline-v1 (roadmap phase 3)

| Component | Weight | Metrics (50/50 within the component) |
|---|---:|---|
| Attacking output | 40% | non-penalty goals + assists per 90; season total |
| Attacking quality | 40% | non-penalty xG + xA per 90; season total |
| Availability | 20% | league minutes |

- **Scope:** domestic league only. The Champions League is shown on player pages but enters
  the score in the competition-context phase.
- **Eligible:** outfield players (DF, MF, FW) with 900+ league minutes. Goalkeepers need
  their own metrics (phase 4).
- **Minimum confidence:** 60% of the model must be measurable to be ranked.

## How a score is calculated (`ScoreCalculator`)

1. **Metric values** (`MetricValue`): a total needs every field *trusted* (its provider
   covers the whole season); a per-90 rate needs every field *usable* (ok or partial) and
   uses each provider's own minutes, with at least 450 of them
   (`PlayerCompetitionStats::MIN_PER90_MINUTES`). One unknown part makes the metric unknown.
2. **Percentiles** (`Percentile`): mid-rank percentile among eligible players with a value:
   share below + half the share equal. Unknown values are not counted.
3. **Components:** weighted mean of the known metric percentiles.
4. **Score:** weighted mean of the known components. What is unknown is left out, never
   zero; **confidence** is the share of the model's weight that could be measured.
5. **Order:** ranked players by score, ties by minutes then player id (deterministic);
   players below the minimum confidence follow without a rank.

Non-penalty goals are FBref goals minus SofaScore penalty goals when SofaScore is trusted for
the player, otherwise Understat's own non-penalty goal count when Understat is trusted.

## Sanity check of the first run

Top of run #2: Haaland, Bruno Fernandes, Dimarco, Baumgartner, Mbappé, Watkins, Muriqi,
Luis Díaz, João Pedro, Kane. Attacking players dominate, as expected for a model that only
measures attack and availability.

## Known limitations (and the phase that addresses them)

- **Percentiles flatten outliers.** Kane (36 goals) and a 25-goal striker both sit at the 99th
  percentile, so availability (where Kane is 75th) decides among the elite. Phase 4 looks at
  standardised scores instead of, or next to, percentiles.
- **Defenders and goalkeepers** are measured only on attack and availability (phase 4–5).
- **Champions League** is not in the score (phase 6).
- **League strength** is not adjusted (phase 6).
- **Weights are judgement calls**, to be tested for sensitivity (phase 8).
