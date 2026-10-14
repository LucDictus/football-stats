# Methodology

The public version of this page is in the app (`/methodology`) and is generated from the
model definition the API serves. This document adds the engineering detail.

## Models are versioned

A model (`src/Football/Scoring/ModelCatalog.php`) defines its scope, who is eligible, its
components, their metrics and all weights. A released model is never edited: a change is a
new version. Every `ScoreRun` stores the full definition, its SHA-256 hash and the import
batches its statistics came from, so any published ranking can be explained and reproduced.
`app:score` does not store a run that would be identical to the latest one.

## positional-v1 (roadmap phase 4, the current model)

Every position group has its own components and is compared only with its own group:

| Role | Components (weight) |
|---|---|
| Forwards | attacking output 40%, attacking quality 30%, link-up play 15%, availability 15% |
| Midfielders | chance creation 30%, build-up 25%, attacking output 20%, defending 10%, availability 15% |
| Defenders | defensive actions 25%, duel success 20%, build-up 20%, reliability 10%, attacking threat 10%, availability 15% |
| Goalkeepers | shot stopping (goals prevented) 70%, reliability 15%, availability 15% |

How one ranking holds all positions:

1. Each metric becomes a **standard score within the role**: 50 + 10 × standard deviations
   from the role's average, clamped to 0–100 (`Normalization::Standard`).
2. A player's **composite** is the weighted mean of his components.
3. The **score** is the composite standardised again within the role. Averaging several
   metrics pulls composites toward 50, the more so the more (and the less correlated)
   metrics a role has: without this step defenders, with six defensive metrics, could not
   reach the top. The score therefore reads "how far above his positional peers".

Avoiding overlap (roadmap 5.2): xGBuildup measures build-up without the player's own shots
and key passes; save % is left out because goals prevented already measures shot stopping.
New measures: duel success rates (aerial duels won %, tackle success %, minimum 20 duels /
15 tackles) and errors leading to goal, where fewer is better.

### Why standard scores, not percentiles

Both were run on the 2025/26 data (same model, only the normalisation differs):

| | Percentiles | Standard scores |
|---|---|---|
| Top 5 | Kane, Lautaro, Undav, Haaland, Mbappé | Kane, Olise, Haaland, Bruno Fernandes, Yamal |
| Separation at the top | #2–#20 within 10 points (flat) | runaway seasons stand out |
| Goalkeepers in top 12 | 4 (noise in goals prevented gets amplified) | 0 |

Percentiles only know that a player is first, not by how much; that was the main weakness of
baseline-v1 (Kane's 36 goals scored like a 25-goal season). Standard scores keep the distance.
Re-standardising the composite within the role was then added to put all roles on the same
spread (best defender before: #54; after: #11).

### Roadmap 4.3 checks (run #5)

- **Does the striker rank highly for the right reasons?** Kane #5 (90.3): attacking output 88.5
  and quality 84.1, held back by availability (2,377 minutes).
- **Can a midfielder rank highly without scoring many goals?** Yes: Kimmich #9 with 10 goals +
  assists, on build-up 95.9 (best in his role on accurate passes and xGBuildup).
- **Can an outstanding defender reach the top?** Yes: Nuno Mendes #11, Bischof #25, Kim Min-jae
  #29. Not yet the right ones, though: see the limitations.
- **Can a goalkeeper compete for the top spot?** In principle (best: Koffi #21), but goals
  prevented over one season is noisy, so goalkeeper results deserve the least trust.

### Known limitations of positional-v1

- **Broad position labels.** "MF" covers wingers and holding midfielders alike, so attacking
  midfielders stand far above that mixed group: 20 of the top 30 are midfielders. Finer roles
  (inferred from the data, not the label) are planned.
- **Defensive volume is not possession-adjusted.** Tackles and interceptions favour defenders of
  teams that defend a lot (van Dijk #74, Saliba far lower). Duel success rates partly offset
  this; possession adjustment belongs to the impact model (roadmap 5.1).
- **Scope** is still the domestic league only (Champions League: phase 6).

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

For role models the steps below run per position group, and the composite is normalised
again within the group (see positional-v1).


1. **Metric values** (`MetricValue`): a total needs every field *trusted* (its provider
   covers the whole season); a per-90 rate needs every field *usable* (ok or partial) and
   uses each provider's own minutes, with at least 450 of them
   (`PlayerCompetitionStats::MIN_PER90_MINUTES`); a rate (a percentage) needs the field
   usable. One unknown part makes the metric unknown.
2. **Normalisation** (`Normalization`): a mid-rank percentile, or a standard score, among the
   group members with a value. Unknown values are not counted.
3. **Components:** weighted mean of the known metric percentiles.
4. **Score:** weighted mean of the known components. What is unknown is left out, never
   zero; **confidence** is the share of the model's weight that could be measured.
5. **Order:** ranked players by score, ties by minutes then player id (deterministic);
   players below the minimum confidence follow without a rank.

Non-penalty goals are FBref goals minus SofaScore penalty goals when SofaScore is trusted for
the player, otherwise Understat's own non-penalty goal count when Understat is trusted.

## Sanity check of the first baseline run

Top of run #2: Haaland, Bruno Fernandes, Dimarco, Baumgartner, Mbappé, Watkins, Muriqi,
Luis Díaz, João Pedro, Kane. Attacking players dominate, as expected for a model that only
measures attack and availability.

## Known limitations of baseline-v1 (addressed by positional-v1)

- **Percentiles flatten outliers.** Kane (36 goals) and a 25-goal striker both sit at the 99th
  percentile, so availability (where Kane is 75th) decides among the elite.
- **Defenders and goalkeepers** are measured only on attack and availability.
- **Champions League** is not in the score (phase 6).
- **League strength** is not adjusted (phase 6).
- **Weights are judgement calls**, to be tested for sensitivity (phase 8).
