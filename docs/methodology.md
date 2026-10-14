# Methodology

The public version of this page is in the app (`/methodology`) and is generated from the
model definition the API serves. This document adds the engineering detail.

## Models are versioned

A model (`src/Football/Scoring/ModelCatalog.php`) defines its scope, who is eligible, its
components, their metrics and all weights. A new model is a **release candidate** until the
review of its roadmap phase passes: until then it may still change, and its score runs are
replaced. Once released, a model is never edited (its hash is pinned by a test): a change is
a new version. Every `ScoreRun` stores the full definition, its SHA-256 hash and the import
batches its statistics came from, so any published ranking can be explained and reproduced.
`app:score` does not store a run that would be identical to the latest one.

## mvp-v1 (roadmap phase 5, release candidate, the current model)

**Final score = 50% positional excellence (positional-v2, unchanged) + 50% contribution in goals.**

### Why: positional excellence alone favoured midfielders

positional-v2 answers "how far does a player stand above others in his position?". On the
2025/26 data that put 8 midfielders in the top 10 (run #7). The analysis:

| Position | Ranked players | Share | Top 10 | Top 100 | Composite skew |
|---|---|---|---|---|---|
| Forwards | 226 | 15% | 2 | 13 | 0.58 |
| Midfielders | 683 | 45% | 8 | 44 | 0.79 |
| Defenders | 501 | 33% | 0 | 37 | 0.24 |
| Goalkeepers | 113 | 7% | 0 | 6 | −0.48 |

- Per player the model was fair (midfielders: 45% of players, 44% of the top 100), but at the
  very top the biggest group with the longest right tail wins: elite midfielders are strong on
  creation, build-up and output at once.
- Re-labelling wingers (Olise, Díaz, Yamal are "MF" in the source) as attackers did **not**
  fix it: the remaining central midfielders then stood out further (6–7 of the top 10 stayed
  midfielders under every rule tried).
- A Ballon d'Or-style ranking asks "who contributed most?" instead. Contribution needs a unit
  that is the same for every position: goals.

### Contribution in goals (`ImpactCalculator`, `ImpactModel`)

| Component | Measure | Evidence |
|---|---|---|
| Shooting | 50% of his non-penalty xG | strong |
| Creation | 30% of his xA | strong |
| Build-up | the team's build-up pool (20% of team xG, plus the passer's 30% of unassisted xG), shared by xGBuildup | moderate |
| Finishing | 50% × (non-penalty goals − npxG) × shots / (shots + 60) | moderate |
| Goalkeeping | goals prevented × shots on target faced / (faced + 150) | moderate |
| Defence | the team's goals prevented against the league average (expected goals on target conceded per 90, from its goalkeepers' goals conceded + goals prevented), shared among outfield players by their share of the team's defensive actions | weak |

- The attack credits of a team add up exactly to its xG: an assisted chance is not counted
  for both shooter and passer in full (tested).
- Shrinkage of finishing and goals prevented: most of one season's over- or under-performance
  is chance. Without it, goalkeepers took 12 of the top 25 places (experiment).
- **Above replacement**: minus what the role's 20th-percentile player adds per 90, for the same
  minutes; playing time counts naturally.
- The contribution score standardises goals above replacement across **all** ranked players
  (not within the role), so a goal counts the same whoever adds it. Contribution has a long
  tail (Kane +12 goals, a typical player +1 to +2), so the elite exceed 100 on that part; the
  page shows 100, the ranking uses the exact value, and the goals are always shown.
- Providers covering part of a season (transfer between leagues) are extrapolated from their
  rate; a player without attacking data (Understat) has no contribution and is not ranked.

### Result (run #9)

| | positional-v2 | mvp-v1 |
|---|---|---|
| Top 10 by position | 8 MF, 2 FW | **5 FW, 5 MF** |
| Kane / Haaland / Mbappé | #3 / #9 / #26 | **#2 / #5 / #10** |
| Kimmich (build-up, 10 G+A) | #6 | #14 |
| Declan Rice | #30 | #13 |
| Saliba (team defence) | #270 | #89 |
| Best defender / goalkeeper | Orban #15 / Koffi #28 | Orban #20 / Nübel #11 |

Top 10: Olise, Kane, Yamal, Luis Díaz, Haaland, Dimarco, Bruno Fernandes, Undav, Lautaro
Martínez, Mbappé. Olise #1 holds in both views: +12.1 goals above replacement, the same as Kane.

### Known limitations of mvp-v1

- **Defence in goals is weak evidence**: a team measure, shared by defensive actions; its numbers
  are small (a few goals), so defenders reach the top mainly through positional excellence.
- **Credit shares** (50/30/20) and **shrinkage priors** are judgement calls, to be tested for
  sensitivity in phase 8.
- **Scope**: domestic league only; the Champions League enters in phase 6.

## positional-v2 (roadmap phase 4, released)

Every position group has its own components and is compared only with its own group:

| Role | Components (weight) |
|---|---|
| Forwards | attacking output 40%, attacking quality 30%, link-up play 10%, availability 20% |
| Midfielders | chance creation 25%, build-up 25%, attacking output 15%, defending 15%, availability 20% |
| Defenders | defensive actions 20%, duel success 20%, build-up 20%, reliability 10%, attacking threat 10%, availability 20% |
| Goalkeepers | shot stopping (goals prevented per 90) 70%, reliability 10%, availability 20% |

How one ranking holds all positions:

1. **Rates only, plus minutes.** Every metric is a per-90 rate or a success rate; volume is
   measured once, by league minutes (availability). Season totals are rate × minutes, so
   adding them would count minutes again (roadmap 3.3).
2. **Shrinkage.** A rate built on little evidence is pulled toward the role average
   (empirical Bayes): `(rate × n + average × prior) / (n + prior)`, with n the player's
   matches (per 90; prior 10 matches) or attempts (success rates; prior 40, because success
   rates are noisy: at 60% over 40 attempts the binomial spread is ±7.7 points, about the
   real spread between players). The player page shows the raw and the adjusted value.
3. **Square-root scale for counts.** Per-90 counts are skewed: most defenders almost never
   score, a few attacking full-backs often. On the raw scale such a minor metric can put a
   player 5+ standard deviations above his peers and outweigh everything else. The square
   root (the variance-stabilising transform for counts) tames that without cutting off the
   top. Not used for goals prevented (can be negative) and errors (mostly zero: a root would
   widen the gap between none and one).
4. **Standard scores within the role**, against the ranked players only: 50 + 10 × standard
   deviations from their average.
5. The **composite** is the weighted mean of the components; the **score** is the composite
   standardised again within the role (against ranked players). Averaging several metrics
   pulls composites toward 50, more so the more (and the less correlated) metrics a role has;
   this step gives every role the same spread, so the score reads "how far above his
   positional peers". It is a design choice: it decides by definition that the best of each
   role are comparable, rather than measuring it.

Avoiding overlap (roadmap 5.2): xGBuildup measures build-up without the player's own shots
and key passes; save % is left out because goals prevented already measures shot stopping.
Duel success: aerial duels won % and tackles won % (SofaScore: tackles that won the ball).

### Roadmap 4.3 checks (run #7)

- **Does the striker rank highly for the right reasons?** Kane #3 (86.8) and Haaland #9 on
  attacking output and quality.
- **Can a midfielder rank highly without scoring many goals?** Yes: Kimmich #6 with 10 goals +
  assists, on build-up (91.3) and creation (68.9).
- **Can an outstanding defender reach the top?** Yes, and now centre-backs for their defending:
  Willi Orban #15, Bastoni #21, van Dijk #27 (duel success 65.7). Nuno Mendes, a full-back whose
  attacking numbers come from 1,256 minutes, went from #11 (v1) to #22.
- **Can a goalkeeper compete for the top spot?** In principle (best: Koffi #28), but goals
  prevented over one season is noisy, so goalkeeper results deserve the least trust.

### How positional-v2 came about (experiments on the 2025/26 data)

1. **Percentiles vs standard scores** (first draft of positional-v1, same components): with
   percentiles #2–#20 were within 10 points and four goalkeepers made the top 12 (noise in goals
   prevented gets amplified); standard scores keep the distance between a runaway season and the
   rest. Top 5 of that draft: percentiles Kane, Lautaro, Undav, Haaland, Mbappé; standard
   scores Kane, Olise, Haaland, Bruno Fernandes, Yamal.
2. **Re-standardising the composite within the role** (released as positional-v1): best defender
   from #54 to #11. Top 5 of run #5: Olise, Bruno Fernandes, Yamal, Dimarco, Kane.
3. **Review of positional-v1** found that standard scores on skewed metrics give minor
   components far more effect than their weight (a full-back's attacking output made him the
   best defender), that small samples count fully (15 of 15 tackles), that players with very
   little data stretched each role's scale, and that minutes counted three times for forwards.
   The fixes: shrinkage, rates plus minutes, the ranked-only reference.
4. **For the skew, a ±3 standard-deviation cap was tried first and rejected.** It bound mostly on
   the main metrics: 125 capped metric scores, 30 of them in the top 10, so the elite were
   flattened again (the problem v2 set out to fix). Variants measured on the same data:

   | Variant | Capped scores (all / top 10) | Top 3 | Mendes |
   |---|---|---|---|
   | raw scale, cap ±3 | 125 / 30 | Fernandes 88.0, Dimarco 86.0, Olise 85.8 | #26 |
   | raw scale, cap ±4 | 40 / 19 | Olise 93.0, Fernandes 92.5, Kane 91.9 | #17 |
   | square root, cap ±3 | 74 / 23 | Fernandes 86.7, Dimarco 84.7, Olise 84.5 | #28 |
   | **square root, no cap** (chosen) | 0 / 0 | Olise 93.2, Fernandes 90.3, Kane 86.8 | #22 |

### Known limitations of positional-v2

- **Broad position labels.** "MF" covers wingers and holding midfielders alike, so attacking
  midfielders stand far above that mixed group: 8 of the top 10 are midfielders. Finer roles
  (inferred from the data, not the label) are planned.
- **Defensive volume is not possession-adjusted.** Tackles and interceptions favour defenders of
  teams that defend a lot (Saliba #270). Duel success partly offsets this; possession
  adjustment belongs to the impact model (roadmap 5.1).
- **Errors leading to goal** are rare: most defenders have none, so one error still costs
  noticeably, even after shrinkage (van Dijk: reliability 37.0).
- **Scope** is still the domestic league only (Champions League: phase 6).

## positional-v1 (superseded by v2, kept for reproducibility)

The first positional model: the same idea with season totals next to per-90 rates, no
shrinkage, no cap, and all eligible players as reference. See "How positional-v2 came about".

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
again within the group (see positional-v2). Model options used by positional-v2: shrinkage
of small samples, ranked players as the reference group, and a cap on standard scores.


1. **Metric values** (`MetricValue`): a total needs every field *trusted* (its provider
   covers the whole season); a per-90 rate needs every field *usable* (ok or partial) and
   uses each provider's own minutes, with at least 450 of them
   (`PlayerCompetitionStats::MIN_PER90_MINUTES`); a rate (a percentage) needs the field
   usable. One unknown part makes the metric unknown.
2. **Normalisation** (`Normalization`): a mid-rank percentile, or a standard score, among the
   group members with a value. Unknown values are not counted.
3. **Components:** weighted mean of the known normalised metric scores.
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
