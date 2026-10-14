-- SQLite schema. Every table is safe to re-run (IF NOT EXISTS); views are recreated.

CREATE TABLE IF NOT EXISTS competitions (
    id          INTEGER PRIMARY KEY,          -- football-data.org id
    code        TEXT NOT NULL UNIQUE,         -- PL, CL, WC, ...
    name        TEXT NOT NULL,
    type        TEXT,                         -- LEAGUE | CUP
    emblem      TEXT
);

CREATE TABLE IF NOT EXISTS seasons (
    id              INTEGER PRIMARY KEY,
    competition_id  INTEGER NOT NULL REFERENCES competitions(id),
    start_date      TEXT NOT NULL,
    end_date        TEXT,
    winner_team_id  INTEGER                   -- no FK: winner may not be imported as a team yet
);

CREATE TABLE IF NOT EXISTS teams (
    id          INTEGER PRIMARY KEY,
    name        TEXT NOT NULL,
    short_name  TEXT,
    tla         TEXT,
    crest       TEXT
);

CREATE TABLE IF NOT EXISTS matches (
    id              INTEGER PRIMARY KEY,
    competition_id  INTEGER NOT NULL REFERENCES competitions(id),
    season_id       INTEGER NOT NULL REFERENCES seasons(id),
    utc_date        TEXT NOT NULL,
    status          TEXT NOT NULL,            -- SCHEDULED | TIMED | FINISHED | POSTPONED | ...
    match_type      TEXT NOT NULL,            -- league | league_phase | group | knockout | qualification
    stage           TEXT,
    matchday        INTEGER,
    group_name      TEXT,
    home_team_id    INTEGER REFERENCES teams(id),
    away_team_id    INTEGER REFERENCES teams(id),
    home_score      INTEGER,                  -- after regular + extra time, excl. shoot-out
    away_score      INTEGER,
    ht_home         INTEGER,
    ht_away         INTEGER,
    duration        TEXT,                     -- REGULAR | EXTRA_TIME | PENALTY_SHOOTOUT
    pen_home        INTEGER,
    pen_away        INTEGER,
    winner          TEXT,                     -- HOME_TEAM | AWAY_TEAM | DRAW
    missing_fields  TEXT NOT NULL DEFAULT '[]', -- JSON list of fields that should be there but are not
    source          TEXT NOT NULL DEFAULT 'football-data.org v4',
    source_updated  TEXT,                     -- lastUpdated from the API
    imported_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_matches_date ON matches(utc_date);
CREATE INDEX IF NOT EXISTS idx_matches_teams ON matches(home_team_id, away_team_id);

-- Final (or latest) table per competition season. Used for opponent strength.
CREATE TABLE IF NOT EXISTS standings (
    competition_id  INTEGER NOT NULL REFERENCES competitions(id),
    season_id       INTEGER NOT NULL REFERENCES seasons(id),
    stage           TEXT NOT NULL,
    group_name      TEXT NOT NULL DEFAULT '',
    team_id         INTEGER NOT NULL REFERENCES teams(id),
    position        INTEGER NOT NULL,
    played          INTEGER, won INTEGER, draw INTEGER, lost INTEGER,
    goals_for       INTEGER, goals_against INTEGER, goal_difference INTEGER,
    points          INTEGER,
    imported_at     TEXT NOT NULL,
    PRIMARY KEY (competition_id, season_id, stage, group_name, team_id)
);

CREATE TABLE IF NOT EXISTS players (
    id              INTEGER PRIMARY KEY,
    name            TEXT NOT NULL,
    first_name      TEXT,
    last_name       TEXT,
    date_of_birth   TEXT,
    nationality     TEXT,
    position        TEXT                      -- coarse: Goalkeeper / Defence / Midfield / Offence (when provided)
);

-- Season totals from the /scorers endpoint. Only covers the top N scorers per competition.
CREATE TABLE IF NOT EXISTS player_competition_stats (
    player_id       INTEGER NOT NULL REFERENCES players(id),
    competition_id  INTEGER NOT NULL REFERENCES competitions(id),
    season_id       INTEGER NOT NULL REFERENCES seasons(id),
    team_id         INTEGER REFERENCES teams(id),
    played_matches  INTEGER,
    goals           INTEGER,
    assists         INTEGER,
    penalties       INTEGER,
    source          TEXT NOT NULL DEFAULT 'football-data.org v4 /scorers',
    imported_at     TEXT NOT NULL,
    PRIMARY KEY (player_id, competition_id, season_id)
);

-- Provenance: every API call the importer made, and how it went.
CREATE TABLE IF NOT EXISTS import_log (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    competition_code  TEXT NOT NULL,
    season            INTEGER NOT NULL,
    resource          TEXT NOT NULL,          -- matches | standings | scorers
    status            TEXT NOT NULL,          -- ok | cached | error
    http_code         INTEGER,
    message           TEXT,
    rows              INTEGER,
    fetched_at        TEXT NOT NULL
);

-- Opponent strength: final table position scaled to 0..1 (1 = top of the table).
DROP VIEW IF EXISTS team_strength;
CREATE VIEW team_strength AS
SELECT s.competition_id,
       s.season_id,
       s.team_id,
       s.stage,
       s.group_name,
       s.position,
       sz.table_size,
       CASE WHEN sz.table_size > 1
            THEN ROUND(1.0 - (s.position - 1) * 1.0 / (sz.table_size - 1), 3)
       END AS strength
FROM standings s
JOIN (SELECT competition_id, season_id, stage, group_name, COUNT(*) AS table_size
      FROM standings
      GROUP BY competition_id, season_id, stage, group_name) sz
  ON sz.competition_id = s.competition_id
 AND sz.season_id = s.season_id
 AND sz.stage = s.stage
 AND sz.group_name = s.group_name;

-- One record per match: the main output of version 1.
DROP VIEW IF EXISTS match_records;
CREATE VIEW match_records AS
SELECT m.id                         AS match_id,
       date(m.utc_date)             AS match_date,
       m.utc_date,
       c.code                       AS competition_code,
       c.name                       AS competition,
       c.type                       AS competition_type,
       substr(se.start_date, 1, 4)  AS season,
       m.match_type,
       m.stage,
       m.matchday,
       m.group_name,
       m.status,
       ht.name                      AS home_team,
       at.name                      AS away_team,
       m.home_score,
       m.away_score,
       m.ht_home,
       m.ht_away,
       m.duration,
       m.pen_home,
       m.pen_away,
       m.winner,
       hs.position                  AS home_table_position,
       hs.strength                  AS home_strength,
       aws.position                 AS away_table_position,
       aws.strength                 AS away_strength,
       m.missing_fields,
       m.source,
       m.source_updated,
       m.imported_at
FROM matches m
JOIN competitions c   ON c.id = m.competition_id
JOIN seasons se       ON se.id = m.season_id
LEFT JOIN teams ht    ON ht.id = m.home_team_id
LEFT JOIN teams at    ON at.id = m.away_team_id
LEFT JOIN team_strength hs
       ON hs.competition_id = m.competition_id AND hs.season_id = m.season_id AND hs.team_id = m.home_team_id
LEFT JOIN team_strength aws
       ON aws.competition_id = m.competition_id AND aws.season_id = m.season_id AND aws.team_id = m.away_team_id;
