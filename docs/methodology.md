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

## mvp-v3 (roadmap phase 5, release candidate, the current model)

**As mvp-v2, but every player is compared with his own role (striker, winger, attacking,
central or defensive midfielder, full-back, centre-back, goalkeeper) instead of a broad
position group, on components that fit the role, and against a replacement-level player of
that role.**

### Why

"Midfielder" (the source's position group) covered wingers, playmakers and holding
midfielders: one comparison group, one set of components, one replacement level. Wingers and
attacking midfielders stood out against holding midfielders, and holding midfielders were
measured on chance creation they are not asked to provide.

### Roles

Roles come from the EA SPORTS FC positions in the Top-5 dataset (details in
[data-model.md](data-model.md#roles)). 1,415 of the 1,522 ranked players have one; the other
107 (about 1 in 14) are inferred from their statistics: the role whose typical profile is
nearest. That inference is right for 68% of labelled players with 900+ minutes (872 of
1,286, each left out of the profiles in turn). Its misses are between roles that overlap:
attacking midfielder ↔ winger, central ↔ defensive midfielder; a k-nearest-neighbours
classifier did no better (65–68%). The player page says when a role was inferred.

| Role | Components (weight) |
|---|---|
| Strikers | attacking output 35%, attacking quality 30%, link-up 10%, pressing 5%, availability 20% |
| Wingers | chance creation 25%, attacking output 20%, ball carrying 15%, attacking quality 10%, pressing 10%, availability 20% |
| Attacking midfielders | chance creation 30%, attacking output 20%, build-up 15%, ball carrying 10%, pressing 5%, availability 20% |
| Central midfielders | build-up 30%, defending 20%, chance creation 15%, attacking output 15%, availability 20% |
| Defensive midfielders | defending 30%, build-up 30%, duel success 10%, chance creation 10%, availability 20% |
| Full-backs | defensive actions 20%, wide creation (key passes, accurate crosses, xA) 20%, duel success 15%, build-up 15%, ball carrying 10%, availability 20% |
| Centre-backs | defensive actions 25%, duel success 25%, build-up 20%, attacking threat 10%, availability 20% |
| Goalkeepers | as mvp-v2: shot stopping 50%, distribution 15%, reliability 10%, availability 25% |

New statistics (SofaScore, league only): successful dribbles (ball carrying), accurate crosses
(wide creation), possession won in the attacking third (pressing, possession-adjusted like
the other defensive counts; `app:score` prints the fit per role). The weights are judgement
calls, like those of every earlier model.

### Result (run #16)

| | mvp-v2 (run #14) | mvp-v3 (run #16) |
|---|---|---|
| Top 10 | 5 FW, 5 MF | 4 strikers, 4 wingers, 1 attacking midfielder, 1 full-back |
| Top 100 attackers (ST + W) / midfielders (AM + CM + DM) | – | 34 / 28 |
| Best per role | | W Olise #1, ST Kane #2, FB Dimarco #5, AM Bruno Fernandes #8, DM Kimmich #12, CB Orban #14, CM Baumgartner #19, GK Nübel #33 |

Top 100 per role (share of the role's ranked players): strikers 19 (10.2%), wingers 15 (7.8%),
central midfielders 13 (7.6%), attacking midfielders 9 (6.3%), centre-backs 20 (6.1%),
goalkeepers 6 (5.3%), full-backs 12 (4.9%), defensive midfielders 6 (4.2%).

- **Holding midfielders rise**, measured on defending and build-up and no longer against
  playmakers: Tchouaméni #205 → #61, Elliot Anderson #162 → #59.
- **Attacking midfielders fall**: their replacement level is now that of attacking
  midfielders (0.126 goals per 90), not of all midfielders (0.068), and their positional peers
  are the other playmakers: Wirtz #81 → #185, Bellingham #73 → #156, Enzo Fernández #16 → #102.
- **Replacement level per 90 by role**: strikers 0.159, attacking midfielders 0.126, wingers
  0.119, central midfielders 0.063, defensive midfielders and full-backs 0.040, centre-backs
  −0.004, goalkeepers −0.008. A goal above replacement is still a goal in every role.
- Overlap report: [overlap-mvp-v3.md](overlap-mvp-v3.md).

### Known limitations of mvp-v3

- **Roles come from a video game's position data.** EA SPORTS FC positions are curated and
  updated for the season, but they are a label, not a measurement of where a player played;
  a player who changed role mid-season keeps one role.
- **Inferred roles are right two times in three**; about 1 in 14 ranked players has one.
  Vinícius Júnior, for example, has no EA FC position and is inferred as an attacking
  midfielder.
- **Smaller comparison groups** (143 to 327 ranked players per role instead of 113 to 683 per
  group) make each role's average and replacement level a little less stable.
- The limitations of mvp-v2 below still apply.

## mvp-v2 (roadmap phase 5, released)

**Same blend as mvp-v1 (50% positional + 50% contribution in goals), with defending measured
against opportunity and errors counted in goals, for every outfield player.**

| Change | Why |
|---|---|
| Defensive counts per 90 (tackles won, interceptions, recoveries; for defenders also aerial duels won, blocks, clearances) adjusted for team possession | A team without the ball has more to defend; defenders of teams that defend a lot had the edge |
| How strongly each statistic depends on possession is fitted from the data, **per position** | A full adjustment (β = 1) overcorrects most statistics; one β over all outfield players overcorrected defenders |
| Goalkeepers: distribution (accurate passes per 90, possession-adjusted, 15%) added; shot stopping 50% (was 70%) | The overlap check found goals prevented in both halves; passing adds what it does not (r 0.03) |
| Errors leading to a goal: −1 goal each in the contribution for outfield players, the count shrunk toward the position's rate (prior 10 matches); out of defenders' positional reliability | An error that leads to a goal is a goal given away. It was a 10% positional component for defenders and goalkeepers only; midfielders' and forwards' errors were not counted |

**Possession** is not in the data, so it is estimated from passing: a team's outfield accurate
passes per 90 over that plus its league's average, p (`PossessionAdjustment`). For each
position and statistic, β is the slope of ln(the rate of the team's players in that position /
league average) on ln((1 − p) / (1 − league average p)) over the 96 teams; a player's count is
multiplied by ((1 − p) / (1 − league average p))^−β. `app:score` prints the fit:

| Position | Statistic | β | r with time without the ball |
|---|---|---|---|
| Defenders | Clearances | 1.22 | 0.73 |
| Defenders | Blocks | 0.87 | 0.40 |
| Defenders | Aerial duels won | 0.40 | 0.19 |
| Defenders | Interceptions | 0.32 | 0.21 |
| Defenders | Tackles won | 0.05 | 0.04 |
| Defenders | Ball recoveries | −0.62 | −0.56 |
| Midfielders | Interceptions | 0.71 | 0.47 |
| Midfielders | Tackles won | 0.49 | 0.34 |
| Midfielders | Ball recoveries | 0.02 | 0.03 |
| Goalkeepers | Accurate passes | −0.74 | −0.37 |

Negative β: the statistic rises *with* possession (defenders of dominant teams win the ball
back high up the pitch; their keepers pass more), so those players are scaled down. The player
page shows the factor next to each adjusted count. Check at player level (correlation of the
defending component with the team's estimated possession share p): defenders −0.11 → 0.00,
midfielders −0.14 → −0.01.

### Result (run #14)

| | mvp-v1 (run #11) | mvp-v2 (run #14) |
|---|---|---|
| Top 10 FW / MF | 5 / 5 | 5 / 5 (same ten players) |
| Top 100 FW / MF / DF / GK | 21 / 48 / 25 / 6 | 21 / 47 / 27 / 5 |
| Best defender / goalkeeper | Schlotterbeck #17 / Koffi #36 | Orban #15 / Nübel #41 |
| Positional ↔ contribution r: DF / GK | 0.58 / 0.89 | 0.50 / 0.85 |

- **Errors move the replacement level.** With errors in the contribution, the 20th-percentile
  player per position contributes less, so the replacement level falls (per 90: defenders
  0.033 → 0.010, midfielders 0.082 → 0.068, forwards 0.169 → 0.160), by about 0.75 goals over a
  full defender's season, while every player is charged his errors. Net, an error-free
  full-season defender gains about half a goal (+0.58 on average), one with an average record
  (about 0.7 a season) breaks even, and one with two errors loses about a goal. This, not the
  possession adjustment, is most of why several defenders rose (Konaté #189 → #164,
  Cubarsí #163 → #147: positional scores slightly down, contribution up).
- **Errors cost places**: van Dijk #57 → #63 (positional 73 → 77, but −1.75 goals for errors);
  the worst error record costs 3.3 goals, and 59 ranked outfield players lose more than 1.
- **Possession**: the defending component no longer favours teams that defend a lot (above). Its
  net effect on the ranking is small, because defending is 15–25% of the positional half.
- **Goalkeepers**: distribution lowers goalkeeping from 88% to 80% of a keeper's final score
  (overlap report: [overlap-mvp-v2.md](overlap-mvp-v2.md)). Shrinking positional shot stopping
  by shots on target (like the contribution) was tried and dropped: each position's score is
  standardised again within the position, which undoes any extra shrinkage of its dominant
  statistic, so it only made the two halves identical. Counting goals prevented once would take
  goalkeepers out of the top 100 (best #107); as it is, 5 of 113 goalkeepers are in it (4.4%,
  against 5.4% of defenders, 6.9% of midfielders and 9.3% of forwards).

### Known limitations of mvp-v2

- **Possession is estimated from passing**, not measured, and a player who moved clubs gets the
  factor of his main club (76 ranked players played for two).
- **Build-up still follows possession**: the positional half correlates +0.39 (defenders) and
  +0.54 (midfielders) with the team's estimated possession, through passing volume. Part of that
  is real (better teams have the ball because they have better players) and cannot be separated
  from style with this data.
- **Goalkeepers**: 80% of a keeper's final score is goals prevented, a statistic that is noisy
  over one season. Shot stopping is what the position is about, as attack is for a forward, so
  it stays in both halves; the contribution shrinks it hard.
- **Errors** are a provider's judgement and charged by outcome (a goal), while attack is credited
  by expected goals. Goalkeepers keep a 10% reliability component, and their errors are also in
  goals prevented.
- The limitations of mvp-v1 below still apply, apart from what is listed here.

## mvp-v1 (roadmap phase 5, released)

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
| Goalkeeping | goals prevented × shots on target faced / (faced + 300) | moderate |
| Defence | the team's goals prevented against the league average, shared among outfield players by their share of the team's defensive actions | weak |

- **The attack credits of a team add up exactly to its xG**: 0.5·xG + 0.3·xA + 0.2·xG +
  0.3·(xG − xA) = xG, so an assisted chance is not counted in full for both shooter and passer
  (tested). xGBuildup excludes only the player's own shots and key passes, so a passer still
  gets some build-up credit for other chances in the same possessions: mild overlap.
- **npxG and xA** come from Understat, or from SofaScore for the 89 players Understat has no
  record of (name-matching gaps): npxG = SofaScore xG − 0.76 per penalty taken. SofaScore runs
  lower than Understat, so the fallback is **calibrated** in every run on the players both
  cover with full-season data (at least 30): × Σ Understat / Σ SofaScore, on 2025/26 ×1.107
  for npxG and ×1.123 for xA (correlation 0.99). xGBuildup is Understat-only, so the
  fallback's **build-up is estimated** from accurate passes × the role's xGBuildup per
  accurate pass (correlation with xGBuildup 0.83 MF, 0.79 FW, 0.76 DF, 0.59 GK), shown as
  weak evidence; the estimate also
  counts in the team's build-up total. The player page says when the fallback is used.
- **Shrinkage**: most of one season's finishing and goals prevented is chance. Goals prevented
  is shrunk with a prior of 300 shots on target (more than a season); with 150, three
  goalkeepers were in the top 18 on noise.
- **Team defence**: expected goals on target conceded per 90 (goalkeepers' goals conceded +
  goals prevented), measured over the minutes the team's goalkeeping data covers (at least
  900) and applied to the whole season (38 or 34 matches). It uses post-shot xG (shot
  placement included), not the chances conceded: pre-shot xG against is not in the data.
- **Above replacement**: minus what the role's 20th-percentile player adds per 90 (among players
  who will be ranked), for the same minutes. This re-centres each role at its replacement
  level: a positional adjustment inside the contribution, by design.
- **Both halves must stand**: a player is ranked only when his positional part meets the
  minimum confidence on its own and his contribution is known.
- **No clamping** of blended scores: the parts shown add up to the total, and a runaway season
  can go above 100 (Olise: 50% × 93.2 + 50% × 113.0 = 103.1).
- Players who moved within a league are credited to their main club in team totals (small
  effect on team pools).

### Result (run #11)

| | positional-v2 | mvp-v1 |
|---|---|---|
| Top 10 by position | 8 MF, 2 FW | **5 FW, 5 MF** |
| Kane / Haaland / Mbappé | #3 / #9 / #26 | **#2 / #5 / #10** |
| Kimmich (build-up, 10 G+A) | #6 | #12 |
| Declan Rice | #30 | #13 |
| Saliba (team defence) | #270 | #93 |
| Best defender / goalkeeper | Orban #15 / Koffi #28 | Schlotterbeck #17 / Koffi #36 |

Top 10: Olise, Kane, Yamal, Luis Díaz, Haaland, Dimarco, Bruno Fernandes, Undav, Lautaro
Martínez, Mbappé. Olise #1 holds in both views (50% × 93.2 + 50% × 113.0 = 103.1), with
+12.16 goals above replacement, Kane +12.15. mvp-v1 ranks the same 1,523 players as
positional-v2. The calibrated fallback lifts the 63 ranked players on it to the build-up
level of comparable Understat players: Cherki #15, Gabriel Magalhães #24.

### Known limitations of mvp-v1

- **Defence in goals is weak evidence**: a team measure, shared by defensive actions, with small
  numbers (about ±3 goals), so defenders reach the top mainly through positional excellence.
- **Credit shares** (50/30/20), **shrinkage priors** and the **replacement percentile** are
  judgement calls, to be tested for sensitivity in phase 8.
- **Scope**: domestic league only; the Champions League enters in phase 6.
- **Goalkeepers are measured twice on goals prevented** (see the overlap check below).

### Overlap check (roadmap 5.2)

`php bin/console app:analyse:overlap` (`OverlapAnalysis`) breaks the final score down, per role,
into the share of its variance each part explains, and groups the parts that measure the same
thing in both halves into families. It also re-ranks everyone under counterfactuals without
the overlap. The full generated report is in [overlap-mvp-v1.md](overlap-mvp-v1.md).

| Role | Positional ↔ contribution r | Contribution SD | Final SD | Largest family |
|---|---|---|---|---|
| Forwards | 0.93 | 11.6 | 10.6 | attack (goals, xG, xA) 87% |
| Midfielders | 0.72 | 10.6 | 9.6 | attack 37%, creation 36%, build-up 22% |
| Defenders | 0.58 | 8.5 | 8.2 | defending 34%, build-up 29%, attack 18% |
| Goalkeepers | 0.89 | 8.1 | 8.8 | goalkeeping (goals prevented) 88% |

| Counterfactual | Top 10 FW / MF | Top 100 FW / MF / DF / GK | Best GK |
|---|---|---|---|
| mvp-v1 as is | 5 / 5 | 21 / 48 / 25 / 6 | #36 |
| Positional half only beyond the contribution | 3 / 7 | 17 / 51 / 32 / 0 | #103 |
| Forwards without xG + xA per 90 | 4 / 6 | 19 / 49 / 26 / 6 | #37 |
| Midfielders without creation | 5 / 5 | 21 / 49 / 24 / 6 | #31 |
| Goalkeepers without shot stopping | 5 / 5 | 22 / 50 / 27 / 1 | #96 |

Findings:

- **Forwards: both halves measure attack** (goals + assists and xG + xA per 90, r 0.80 with
  each other, against shooting and creation in goals). For a forward, attack *is* positional
  excellence, and the data has nothing else to measure them by (no dribbles, carries or
  pressing). Removing the overlap moves the top 10 a little toward midfielders (3 FW / 7 MF;
  Kane #4, Haaland #7), and leaving out only the duplicated xG + xA component moves one place
  (4 / 6). The overlap gives forwards a modest edge, not a distortion that needs removing.
- **The forwards' wider spread** (final SD 10.6 against 9.6 for midfielders) comes about half
  from their wider contribution (a goal counts the same in every role, and forwards differ
  most in goals) and half from the agreement between the halves.
- **Midfielders: creation is counted in both halves** (positional creation ↔ contribution
  creation r 0.85; inside the positional component xA ↔ big chances 0.92, xA ↔ key passes
  0.85). Those metrics share one component with a fixed weight, so they do not add weight to
  each other. Leaving the component out changes nothing in the top 10.
- **Defenders are the most balanced role** (r 0.58): the halves add different information.
- **Goalkeepers: real double-counting, with a visible effect.** Goals prevented is 70% of the
  positional half and almost all of the contribution (goalkeeping explains 92% of a keeper's
  contribution; shot stopping ↔ goalkeeping r 0.90), so 88% of a goalkeeper's final score is
  one statistic. It is noisy over one season: the contribution half shrinks it hard (a prior
  of 300 shots on target), but the positional half only by a prior worth 10 matches, so with a
  typical 31 matches about 76% of a keeper's deviation comes through. Counted once, goalkeepers
  in the top 100 go from 6 to 1 and the best from #36 to #96. The data has no other goalkeeping
  measure that is not also driven by the team (save % repeats goals prevented; clean sheets are
  team outcomes). **For the next model version:** shrink the positional shot stopping like the
  contribution's goalkeeping. That needs a new version, because released models are never edited.
  (Tried for mvp-v2 and dropped: see above.)
- **Minutes**: availability (20% of the positional half) and the season totals of the
  contribution both reward volume; the final score correlates 0.36 to 0.44 with minutes in
  every role.
- Without the overlap altogether (the residual counterfactual) goalkeepers leave the top 100
  entirely: their positional half adds almost nothing beyond goals prevented.

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
