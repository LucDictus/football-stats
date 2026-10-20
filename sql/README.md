# SQL: questions about the season

Eleven questions someone could ask about this data, from easy to hard. Each says **why** someone
would ask it, **where** the data is, **what SQL** it teaches, and the **first rows of the right
answer**, so you can check yourself. Write each answer in its file in [`exercises/`](exercises/)
(the question is already at the top). There are no solutions in the repo: when you are stuck or
done, ask for a review.

## How to run a query

The database runs in Docker (see [installation.md](../docs/installation.md)). Three ways, pick one:

1. **A database app (recommended to start).** [DBeaver](https://dbeaver.io/) (free) or the
   PostgreSQL extension in VS Code. Connect with host `localhost`, port `5432`, database `app`,
   user `app`, password `!ChangeMe!`. You get a table view of the results and autocomplete for
   table and column names.
2. **A file, from the terminal:**

   ```bash
   docker compose exec -T database psql -U app -d app < sql/exercises/01_score_runs.sql
   ```

3. **Interactive:** `docker compose exec database psql -U app -d app`, type a query ending in `;`,
   `\q` to quit. `\d player_score` shows a table's columns.

## The data in five tables

```
player ──< player_season ──< player_competition_stats      what a player did (statistics)
              │    └──> team
              │
score_run ──< player_score ──> player                       what the model made of it (scores)
```

| Table | One row is | Columns you will use |
|---|---|---|
| `player` | a player | `id`, `name`, `birth_year` |
| `player_season` | a player's 2025/26 season | `player_id`, `main_team_id` (his club with the most minutes), `role` (ST, W, AM, CM, DM, FB, CB, GK) |
| `team` | a club | `id`, `name` |
| `player_competition_stats` | his statistics in one competition | `player_season_id`, `scope` (`league` or `CL`), `minutes`, `values` (all statistics, as JSON) |
| `score_run` | one calculation of a ranking by one model | `id`, `model_id` (`mvp-v3` is the current model), `created_at`, `ranked_count` |
| `player_score` | a player's result in one run | `run_id`, `player_id`, `rank` (empty = not ranked: too little data), `score`, `positional`, `team`, `minutes`, `position_group` |

Three things that will surprise you:

- **`player_score.position_group` holds the role** (ST, W, …) for the mvp-v3 model. Older models used
  broad groups there (FW, MF, DF, GK).
- **Statistics are JSON.** `values` looks like `{"goals": 36, "assists": 5, "np_xg": 21.2, …}`, one key
  per statistic (the keys are in `src/Football/Data/FieldCatalog.php`). `values->>'goals'` gives the
  value as text; cast it to use it as a number: `(values->>'goals')::numeric`. A statistic the
  provider does not have is simply not in the JSON (it is unknown, not zero).
- **There are several runs.** Every time the model is scored, a new `score_run` is stored. Always
  pick the run you mean, normally the latest of `mvp-v3`. Never type a run id like `22` into a
  query: on another computer the ids are different.

## Level 1: select, filter, sort

**1. Which rankings exist?** · `score_run` · `SELECT`, `ORDER BY`

*Why:* before analysing anything, know what is in the database. List every run: its id, the model,
when it was made and how many players it ranked, newest first.

```
 id |   model_id    |     created_at      | ranked_count
 22 | mvp-v3        | 2026-10-15 07:46:16 |         1531
 14 | mvp-v2        | 2026-10-14 12:23:51 |         1523
 11 | mvp-v1        | 2026-10-14 11:32:19 |         1523
```

(Your ids and dates may differ; the counts will not.)

**2. The top 10 of the current ranking** · `player_score`, `player`, `score_run` · `JOIN`, a subquery

*Why:* the headline result. Show rank, name, team, role and score (one decimal) of the top 10 of the
**latest** mvp-v3 run. Find that run with a subquery instead of typing its id.

Hint: `WHERE run_id = (SELECT max(id) FROM score_run WHERE model_id = 'mvp-v3')`; `round(score::numeric, 1)`.

```
 rank |       name       |     team      | role | score
    1 | Michael Olise    | Bayern Munich | W    |  99.1
    2 | Federico Dimarco | Inter         | FB   |  95.1
    3 | Harry Kane       | Bayern Munich | ST   |  93.6
```

## Level 2: group and count

**3. Is every role treated equally?** · `player_score` · `GROUP BY`, `count`, `avg`, `max`

*Why:* a fair ranking should not favour one position. Per role: how many players are ranked, their
average score and the best score, highest average first. Then think: why are all the averages so
close to 50?

```
 role | players | avg_score | best
 ST   |     186 |      50.9 | 93.6
 W    |     193 |      50.4 | 99.1
 AM   |     148 |      50.3 | 90.3
```

**4. Which clubs have the most players in the top 100?** · `player_score` · `GROUP BY`, `HAVING`

*Why:* is the ranking dominated by a few rich clubs? Count top-100 players per club, only clubs with
at least 3, most first (ties alphabetical).

```
     team      | count
 Barcelona     |     8
 Bayern Munich |     8
 Inter         |     8
 Dortmund      |     6
```

13 clubs in total.

## Level 3: statistics from JSON

**5. The league's top scorers** · `player_competition_stats`, `player_season`, `player`, `team` · JSON, joining four tables

*Why:* the classic question, from the raw statistics rather than the model. Name, club, goals and
minutes, league only (`scope = 'league'`), most goals first.

Hint: the chain is `player_competition_stats.player_season_id → player_season.id`,
`player_season.player_id → player.id`, `player_season.main_team_id → team.id`.

```
      name      |      team       | goals | minutes
 Harry Kane     | Bayern Munich   |  36.0 |    2377
 Erling Haaland | Manchester City |  27.0 |    2953
 Kylian Mbappé  | Real Madrid     |  25.0 |    2599
```

**6. Goals per 90 minutes** · same tables · a calculated column, a minimum sample

*Why:* totals favour players who played a lot. Goals per 90 minutes (goals ÷ minutes × 90, two
decimals) is fairer, but only with enough minutes: keep players with at least 900. Top 6.

```
       name        |     team      | minutes | goals | goals_per_90
 Harry Kane        | Bayern Munich |    2377 |  36.0 |         1.36
 Ansu Fati         | Monaco        |    1093 |  11.0 |         0.91
 Jonathan Burkardt | Frankfurt     |    1336 |  13.0 |         0.88
```

Then try 450 minutes instead of 900 and see who appears. That is why the threshold exists.

## Level 4: window functions

**7. The best player in each role** · `player_score`, `player` · `ROW_NUMBER() OVER (PARTITION BY …)`, a CTE (`WITH`)

*Why:* "who is the best goalkeeper?" is a different question from "who is the best player?". One row
per role: the highest-scoring player, with his overall rank, ordered by that rank.

Hint: number the players within each role by score, then keep number 1. You cannot filter on a
window function in the same `SELECT`, so put the numbering in a `WITH` block first.

```
 role |       name       |      team      | score | rank
 W    | Michael Olise    | Bayern Munich  |  99.1 |    1
 FB   | Federico Dimarco | Inter          |  95.1 |    2
 ST   | Harry Kane       | Bayern Munich  |  93.6 |    3
 …
 GK   | Joan García      | Barcelona      |  69.9 |   35
```

**8. How far above his role's average?** · `player_score`, `player` · `avg(…) OVER (PARTITION BY …)`

*Why:* a score means more next to its group. For every ranked player: score, and score minus the
average of his role (one decimal). Top 5 by that difference.

```
       name       | role | score | above_role_avg
 Michael Olise    | W    |  99.1 |           48.6
 Federico Dimarco | FB   |  95.1 |           46.0
 Harry Kane       | ST   |  93.6 |           42.7
```

## Level 5: compare two rankings

**9. Who moved most between model versions?** · `player_score` twice · two CTEs, `JOIN … USING`

*Why:* when a model changes, you must be able to say who it affects. Rank in the latest mvp-v2 run,
rank in the latest mvp-v3 run, and places gained (v2 rank − v3 rank), for players ranked in both
(1,521 players). Biggest risers first.

```
      name      |  v2  | v3  | places_up
 Obed Nkambadio | 1514 | 362 |      1152
 Enzo Ebosse    | 1438 | 485 |       953
 Tom Rothe      | 1164 | 216 |       948
```

The biggest movers are at the bottom of the table, which matters less. Refine it: only players in
the **top 100 of mvp-v3** (ties alphabetical):

```
      name       | v2  | v3 | places_up
 Ramy Bensebaini | 399 | 80 |       319
 Facundo Medina  | 370 | 83 |       287
 Danny da Costa  | 313 | 77 |       236
```

And the biggest fallers (`ORDER BY places_up` ascending): Curtis Jones 393 → 1462.

**10. The strongest squad** · `player_score` · `ROW_NUMBER` per club, then `GROUP BY`

*Why:* a team is eleven players, not one star. Per club: the average score of its best 11 ranked
players. Only clubs with at least 11 ranked players. Top 5.

```
     team      | best_11_avg
 Bayern Munich |        72.4
 Inter         |        69.8
 Barcelona     |        69.2
 PSG           |        65.2
 Lens          |        64.6
```

## Level 6: build something reusable

**11. A view for dashboards** · everything · `CREATE VIEW`

*Why:* analysts do not write the same five joins every time. They build one clean table (a *view*)
that a dashboard or notebook reads. Create `analytics_player_season` with one row per player with
league statistics: name, club, role, minutes, goals, assists, non-penalty xG (`np_xg`), xA (`xa`),
and from the latest mvp-v3 run: rank, score, positional score and goals above replacement
(`impact->>'above'` in `player_score`). Players without a score still appear, with empty score
columns (`LEFT JOIN`).

Check: `SELECT count(*) FROM analytics_player_season` gives 2,691, and Harry Kane's row reads 2377
minutes, 36 goals, 5 assists, rank 3, score 93.6, goals above replacement 11.7.

This view is what the Tableau dashboard (step 7) will be built on.

## Learning resources

- [SQLBolt](https://sqlbolt.com/): short interactive lessons, good for levels 1–2.
- [PostgreSQL tutorial: window functions](https://www.postgresql.org/docs/current/tutorial-window.html) for level 4.
- [PostgreSQL JSON functions](https://www.postgresql.org/docs/current/functions-json.html) for `->>` and friends.
