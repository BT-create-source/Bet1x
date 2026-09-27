-- =================================================================================================
-- Migration 006 — "Your Eleven" daily fantasy cricket (PostgreSQL)
--
-- Every statement here is ADDITIVE and IF NOT EXISTS. Nothing existing is dropped, altered or
-- renamed: no ALTER TABLE on a live table, no column changes, no new enum types, no touched
-- indexes. Running this on the live database adds eight inert tables and changes the behaviour of
-- nothing, because no route reads them until FANTASY_ENABLED is switched on (see lib/fantasy.php).
-- Re-running it is harmless.
--
-- Wallet and identity are deliberately NOT re-modelled. There is no fantasy user table and no
-- fantasy balance column: teams and entries point at the existing "User"."id", and every rupee
-- moves through the existing debit_wallet()/credit_wallet()/insert_transaction() helpers in
-- lib/helpers.php, so the normal ledger and the profile's game history stay the single source of
-- truth.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-006-fantasy-postgres.sql
-- or paste it into phpPgAdmin's SQL tab.
-- =================================================================================================

-- ------------------------------------------------------------------------------------------------
-- Matches. One row per fixture the scheduler has discovered.
--
-- "external_key" is what makes the ingestion worker idempotent: it is a stable fingerprint of the
-- fixture at the source, so re-scraping the same schedule page every few hours UPDATEs the row it
-- already created instead of inserting a duplicate. Without it, a cron running twice a day would
-- accumulate a fresh copy of every upcoming match on each pass.
--
-- "lock_time" is stored separately from "start_time" even though the two are equal today. Team
-- edits are refused against lock_time, so an operator can freeze entries early for a delayed toss
-- without rewriting the real start time the countdown shows.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_matches" (
  "id"                   SERIAL PRIMARY KEY,
  "external_key"         TEXT NOT NULL,
  "series_name"          TEXT NOT NULL DEFAULT '',
  "match_title"          TEXT NOT NULL DEFAULT '',
  "team_a"               TEXT NOT NULL DEFAULT '',
  "team_b"               TEXT NOT NULL DEFAULT '',
  "team_a_short"         TEXT NOT NULL DEFAULT '',
  "team_b_short"         TEXT NOT NULL DEFAULT '',
  "team_a_logo"          TEXT,
  "team_b_logo"          TEXT,
  "format"               TEXT NOT NULL DEFAULT 'T20',
  "venue"                TEXT,
  "start_time"           TIMESTAMP(3) NOT NULL,
  "lock_time"            TIMESTAMP(3) NOT NULL,
  "status"               TEXT NOT NULL DEFAULT 'UPCOMING',
  "scorecard_source_url" TEXT,
  -- 0 until the squads have been ingested. The lobby hides a match with no squad, because a
  -- "Create Team" screen with an empty player list is worse than no card at all.
  "squads_ready"         SMALLINT NOT NULL DEFAULT 0,
  "last_synced_at"       TIMESTAMP(3),
  "created_at"           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_matches_external_key_key" UNIQUE ("external_key"),
  CONSTRAINT "fantasy_matches_status_chk"
    CHECK ("status" IN ('UPCOMING', 'LIVE', 'SETTLED', 'CANCELLED'))
);
CREATE INDEX IF NOT EXISTS "fantasy_matches_status_idx"     ON "fantasy_matches" ("status");
CREATE INDEX IF NOT EXISTS "fantasy_matches_start_time_idx" ON "fantasy_matches" ("start_time");

-- ------------------------------------------------------------------------------------------------
-- Squads. Players belong to a MATCH, not to a global player table.
--
-- That is deliberate: credits are per fixture (the same player is worth different credits in
-- different games), squads are announced per fixture, and it stops a bad scrape of one match from
-- corrupting another. The cost is that the same human appears once per match, which is exactly what
-- the credit model wants.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_players" (
  "id"           SERIAL PRIMARY KEY,
  "match_id"     INTEGER NOT NULL,
  "external_key" TEXT NOT NULL,
  "name"         TEXT NOT NULL,
  "full_name"    TEXT NOT NULL DEFAULT '',
  "team_name"    TEXT NOT NULL DEFAULT '',
  "role"         TEXT NOT NULL DEFAULT 'BAT',
  "credits"      NUMERIC(4,1) NOT NULL DEFAULT 8.0,
  -- Set once a confirmed XI is known. NULL means "not announced yet", which is not the same as 0.
  "is_playing"   SMALLINT,
  "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_players_match_fk" FOREIGN KEY ("match_id")
    REFERENCES "fantasy_matches" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_players_match_key_key" UNIQUE ("match_id", "external_key"),
  CONSTRAINT "fantasy_players_role_chk" CHECK ("role" IN ('WK', 'BAT', 'ALL', 'BOWL')),
  CONSTRAINT "fantasy_players_credits_chk" CHECK ("credits" >= 8.0 AND "credits" <= 10.5)
);
CREATE INDEX IF NOT EXISTS "fantasy_players_match_idx" ON "fantasy_players" ("match_id");

-- ------------------------------------------------------------------------------------------------
-- Contests. "prize_rules" is the rank-to-percentage table, stored as JSON text. It is validated to
-- total 100% before a contest is ever opened, and settlement allocates from the real collected pool
-- so the amounts paid out add up to exactly what was taken in.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_contests" (
  "id"           SERIAL PRIMARY KEY,
  "match_id"     INTEGER NOT NULL,
  "title"        TEXT NOT NULL,
  "entry_fee"    NUMERIC(12,2) NOT NULL DEFAULT 0,
  "total_spots"  INTEGER NOT NULL DEFAULT 0,
  "filled_spots" INTEGER NOT NULL DEFAULT 0,
  "prize_pool"   NUMERIC(14,2) NOT NULL DEFAULT 0,
  "prize_rules"  TEXT NOT NULL DEFAULT '[]',
  "rake_pct"     NUMERIC(5,2) NOT NULL DEFAULT 0,
  "status"       TEXT NOT NULL DEFAULT 'OPEN',
  "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_contests_match_fk" FOREIGN KEY ("match_id")
    REFERENCES "fantasy_matches" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_contests_status_chk"
    CHECK ("status" IN ('OPEN', 'LOCKED', 'SETTLED', 'CANCELLED'))
);
CREATE INDEX IF NOT EXISTS "fantasy_contests_match_idx" ON "fantasy_contests" ("match_id");

-- ------------------------------------------------------------------------------------------------
-- A player's saved XI for a match. Bound to the existing "User"."id" — no fantasy user table.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_user_teams" (
  "id"                     SERIAL PRIMARY KEY,
  "user_id"                INTEGER NOT NULL,
  "match_id"               INTEGER NOT NULL,
  "team_name"              TEXT NOT NULL DEFAULT '',
  "captain_player_id"      INTEGER NOT NULL,
  "vice_captain_player_id" INTEGER NOT NULL,
  "total_credits"          NUMERIC(5,1) NOT NULL DEFAULT 0,
  "total_points"           NUMERIC(10,2) NOT NULL DEFAULT 0,
  "rank"                   INTEGER,
  "created_at"             TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"             TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_user_teams_user_fk" FOREIGN KEY ("user_id")
    REFERENCES "User" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_user_teams_match_fk" FOREIGN KEY ("match_id")
    REFERENCES "fantasy_matches" ("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "fantasy_user_teams_user_idx"  ON "fantasy_user_teams" ("user_id");
CREATE INDEX IF NOT EXISTS "fantasy_user_teams_match_idx" ON "fantasy_user_teams" ("match_id");

-- The eleven rows behind one saved team. The UNIQUE stops the same player being picked twice, which
-- PHP checks as well but which is cheap to also make impossible at the storage level.
CREATE TABLE IF NOT EXISTS "fantasy_team_players" (
  "id"           SERIAL PRIMARY KEY,
  "user_team_id" INTEGER NOT NULL,
  "player_id"    INTEGER NOT NULL,
  CONSTRAINT "fantasy_team_players_team_fk" FOREIGN KEY ("user_team_id")
    REFERENCES "fantasy_user_teams" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_team_players_player_fk" FOREIGN KEY ("player_id")
    REFERENCES "fantasy_players" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_team_players_unique" UNIQUE ("user_team_id", "player_id")
);
CREATE INDEX IF NOT EXISTS "fantasy_team_players_team_idx" ON "fantasy_team_players" ("user_team_id");

-- ------------------------------------------------------------------------------------------------
-- One row per paid entry. "txn_id" stores the id of the row written to the existing "Transaction"
-- ledger for the entry fee, so an entry can always be traced back to the exact debit that paid for
-- it. The UNIQUE on (contest, team) is what makes a double-tap on "Join" impossible.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_contest_entries" (
  "id"             SERIAL PRIMARY KEY,
  "contest_id"     INTEGER NOT NULL,
  "user_team_id"   INTEGER NOT NULL,
  "user_id"        INTEGER NOT NULL,
  "entry_fee_paid" NUMERIC(12,2) NOT NULL DEFAULT 0,
  "txn_id"         TEXT,
  "points"         NUMERIC(10,2) NOT NULL DEFAULT 0,
  "rank"           INTEGER,
  "prize_won"      NUMERIC(14,2) NOT NULL DEFAULT 0,
  "is_settled"     SMALLINT NOT NULL DEFAULT 0,
  "settled_at"     TIMESTAMP(3),
  "created_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_entries_contest_fk" FOREIGN KEY ("contest_id")
    REFERENCES "fantasy_contests" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_entries_team_fk" FOREIGN KEY ("user_team_id")
    REFERENCES "fantasy_user_teams" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_entries_user_fk" FOREIGN KEY ("user_id")
    REFERENCES "User" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_entries_unique" UNIQUE ("contest_id", "user_team_id")
);
CREATE INDEX IF NOT EXISTS "fantasy_entries_contest_idx" ON "fantasy_contest_entries" ("contest_id");
CREATE INDEX IF NOT EXISTS "fantasy_entries_user_idx"    ON "fantasy_contest_entries" ("user_id");

-- ------------------------------------------------------------------------------------------------
-- Live per-player stats, one row per (match, player), overwritten on every poll.
--
-- The source reports cumulative figures ("34 runs off 20 balls"), never deltas, and this table
-- stores them the same way: each poll REPLACES the row rather than adding to it. That is what makes
-- a missed poll, a duplicated poll or an out-of-order poll harmless — the same property the Node
-- build gets from recomputing state off its event log. Points are recomputed from these numbers as
-- well, never incremented.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_player_live_stats" (
  "id"                        SERIAL PRIMARY KEY,
  "match_id"                  INTEGER NOT NULL,
  "player_id"                 INTEGER NOT NULL,
  "runs"                      INTEGER NOT NULL DEFAULT 0,
  "balls"                     INTEGER NOT NULL DEFAULT 0,
  "fours"                     INTEGER NOT NULL DEFAULT 0,
  "sixes"                     INTEGER NOT NULL DEFAULT 0,
  "wickets"                   INTEGER NOT NULL DEFAULT 0,
  "overs"                     NUMERIC(5,1) NOT NULL DEFAULT 0,
  "maidens"                   INTEGER NOT NULL DEFAULT 0,
  "runs_conceded"             INTEGER NOT NULL DEFAULT 0,
  "bowled_lbw"                INTEGER NOT NULL DEFAULT 0,
  "catches"                   INTEGER NOT NULL DEFAULT 0,
  "stumpings"                 INTEGER NOT NULL DEFAULT 0,
  "runouts_direct"            INTEGER NOT NULL DEFAULT 0,
  "runouts_shared"            INTEGER NOT NULL DEFAULT 0,
  "is_out"                    SMALLINT NOT NULL DEFAULT 0,
  "did_bat"                   SMALLINT NOT NULL DEFAULT 0,
  "calculated_fantasy_points" NUMERIC(10,2) NOT NULL DEFAULT 0,
  "updated_at"                TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_live_stats_match_fk" FOREIGN KEY ("match_id")
    REFERENCES "fantasy_matches" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_live_stats_player_fk" FOREIGN KEY ("player_id")
    REFERENCES "fantasy_players" ("id") ON DELETE CASCADE,
  CONSTRAINT "fantasy_live_stats_unique" UNIQUE ("match_id", "player_id")
);
CREATE INDEX IF NOT EXISTS "fantasy_live_stats_match_idx" ON "fantasy_player_live_stats" ("match_id");

-- ------------------------------------------------------------------------------------------------
-- Worker bookkeeping: when each cron last ran and what it found. Kept out of "GameState" so the
-- fantasy module owns none of the existing config surface.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "fantasy_sync_log" (
  "id"     SERIAL PRIMARY KEY,
  "worker" TEXT NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'ok',
  "detail" TEXT,
  "ran_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "fantasy_sync_log_worker_idx" ON "fantasy_sync_log" ("worker", "ran_at");

-- Verification — every count should be 0 on a fresh apply.
-- SELECT
--   (SELECT COUNT(*) FROM "fantasy_matches")           AS matches,
--   (SELECT COUNT(*) FROM "fantasy_players")           AS players,
--   (SELECT COUNT(*) FROM "fantasy_contests")          AS contests,
--   (SELECT COUNT(*) FROM "fantasy_user_teams")        AS teams,
--   (SELECT COUNT(*) FROM "fantasy_team_players")      AS team_players,
--   (SELECT COUNT(*) FROM "fantasy_contest_entries")   AS entries,
--   (SELECT COUNT(*) FROM "fantasy_player_live_stats") AS live_stats,
--   (SELECT COUNT(*) FROM "fantasy_sync_log")          AS sync_log;
