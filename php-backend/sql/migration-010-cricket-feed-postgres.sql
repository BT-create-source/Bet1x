-- =================================================================================================
-- Migration 010 — the shared cricket feed, Ball by Ball, and the Your 11 parity changes.
--
-- Additive and idempotent: every statement is IF NOT EXISTS / IF EXISTS, so re-running it is a
-- no-op. Nothing here touches a table outside the cricket games.
--
-- 1. ONE FEED FOR BOTH GAMES
--    Roanuz pushes the current match state; every push is archived verbatim in cricket_feed_raw
--    (so a field-name correction later can be replayed over history), and the deliveries it carries
--    are upserted into cricket_deliveries keyed by the provider's own ball identity. Everything both
--    games show — the scoreboard, every player's fantasy figures, every Ball by Ball result — is
--    DERIVED from cricket_deliveries on each update, never incremented, so a duplicated, late or
--    out-of-order push converges on the same answer.
--
-- 2. BALL BY BALL
--    One market (bbb_rounds) per delivery, one pool per market, stakes in bbb_bets. The house never
--    holds a position: it takes rake_pct of a settled pool and nothing else.
--
-- 3. YOUR 11
--    Dream11-parity columns: dot balls / legal balls bowled for the economy and dot-ball points,
--    announced-lineup and playing-substitute flags, multi-entry contests, a wider credit band.
-- =================================================================================================

-- ---------- 1. the feed --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "cricket_feed_raw" (
  "id"          BIGSERIAL PRIMARY KEY,
  "match_key"   TEXT NOT NULL,
  "body_sha"    TEXT NOT NULL,
  "body"        TEXT NOT NULL,
  "source"      TEXT NOT NULL DEFAULT 'push',
  "received_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "cricket_feed_raw_dedup" UNIQUE ("match_key", "body_sha")
);
CREATE INDEX IF NOT EXISTS "cricket_feed_raw_match_idx" ON "cricket_feed_raw" ("match_key", "received_at");

CREATE TABLE IF NOT EXISTS "cricket_deliveries" (
  "id"              BIGSERIAL PRIMARY KEY,
  "match_key"       TEXT NOT NULL,
  "ball_uid"        TEXT NOT NULL,
  "seq"             BIGINT NOT NULL,
  "innings"         INTEGER NOT NULL,
  "over_no"         INTEGER NOT NULL,
  "ball_no"         INTEGER NOT NULL,
  "is_legal"        SMALLINT NOT NULL DEFAULT 1,
  "is_super_over"   SMALLINT NOT NULL DEFAULT 0,
  "batting_team"    TEXT,
  "batsman_key"     TEXT,
  "non_striker_key" TEXT,
  "bowler_key"      TEXT,
  "batsman_runs"    INTEGER NOT NULL DEFAULT 0,
  "extra_type"      TEXT,
  "extra_runs"      INTEGER NOT NULL DEFAULT 0,
  "is_boundary"     SMALLINT NOT NULL DEFAULT 0,
  "is_wicket"       SMALLINT NOT NULL DEFAULT 0,
  "wicket_type"     TEXT,
  "out_player_key"  TEXT,
  "fielders"        TEXT NOT NULL DEFAULT '[]',
  "commentary"      TEXT,
  "first_seen_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "cricket_deliveries_uid" UNIQUE ("match_key", "ball_uid")
);
CREATE INDEX IF NOT EXISTS "cricket_deliveries_seq_idx" ON "cricket_deliveries" ("match_key", "seq");

-- One row per match: the latest match-level facts the feed reported (toss, lineups, status, the
-- result text) plus our own bookkeeping (when we last heard from the feed, which drives the stall
-- detector).
CREATE TABLE IF NOT EXISTS "cricket_match_feed" (
  "match_key"      TEXT PRIMARY KEY,
  "format"         TEXT NOT NULL DEFAULT 'T20',
  "title"          TEXT NOT NULL DEFAULT '',
  "team_a_key"     TEXT,
  "team_b_key"     TEXT,
  "team_a"         TEXT NOT NULL DEFAULT '',
  "team_b"         TEXT NOT NULL DEFAULT '',
  "team_a_short"   TEXT NOT NULL DEFAULT '',
  "team_b_short"   TEXT NOT NULL DEFAULT '',
  "start_time"     TIMESTAMP(3),
  "status"         TEXT NOT NULL DEFAULT 'not_started',
  "status_text"    TEXT,
  "toss"           TEXT NOT NULL DEFAULT '{}',
  "lineups"        TEXT NOT NULL DEFAULT '{}',
  "players"        TEXT NOT NULL DEFAULT '{}',
  "result_text"    TEXT,
  "last_feed_at"   TIMESTAMP(3),
  "last_ball_at"   TIMESTAMP(3),
  "stalled"        SMALLINT NOT NULL DEFAULT 0,
  "updated_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

-- ---------- 2. Ball by Ball ----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "bbb_rounds" (
  "id"            BIGSERIAL PRIMARY KEY,
  "match_key"     TEXT NOT NULL,
  -- The delivery this market is about, identified by its position in the innings: the number of
  -- deliveries (legal or not) already bowled. A wide's re-bowl therefore gets its own market.
  "innings"       INTEGER NOT NULL,
  "delivery_idx"  INTEGER NOT NULL,
  "over_no"       INTEGER NOT NULL,
  "ball_no"       INTEGER NOT NULL,
  "label"         TEXT NOT NULL DEFAULT '',
  "status"        TEXT NOT NULL DEFAULT 'OPEN',
  "rake_pct"      NUMERIC(5,2) NOT NULL DEFAULT 10,
  "opened_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "closes_at"     TIMESTAMP(3) NOT NULL,
  "result_ball_uid" TEXT,
  "outcome"       TEXT,
  "result_text"   TEXT,
  "pool_total"    NUMERIC(14,2) NOT NULL DEFAULT 0,
  "paid_total"    NUMERIC(14,2) NOT NULL DEFAULT 0,
  "rake_taken"    NUMERIC(14,2) NOT NULL DEFAULT 0,
  "void_reason"   TEXT,
  "settled_at"    TIMESTAMP(3),
  CONSTRAINT "bbb_rounds_one_per_delivery" UNIQUE ("match_key", "innings", "delivery_idx"),
  CONSTRAINT "bbb_rounds_status_chk" CHECK ("status" IN ('OPEN', 'SUSPENDED', 'SETTLED', 'VOID'))
);
CREATE INDEX IF NOT EXISTS "bbb_rounds_match_idx" ON "bbb_rounds" ("match_key", "id");

CREATE TABLE IF NOT EXISTS "bbb_bets" (
  "id"          BIGSERIAL PRIMARY KEY,
  "round_id"    BIGINT NOT NULL,
  "user_id"     INTEGER NOT NULL,
  "username"    TEXT NOT NULL,
  "outcome"     TEXT NOT NULL,
  "stake"       NUMERIC(12,2) NOT NULL,
  "txn_id"      TEXT,
  "status"      TEXT NOT NULL DEFAULT 'PENDING',
  "payout"      NUMERIC(14,2) NOT NULL DEFAULT 0,
  "created_at"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "settled_at"  TIMESTAMP(3),
  CONSTRAINT "bbb_bets_round_fk" FOREIGN KEY ("round_id") REFERENCES "bbb_rounds" ("id") ON DELETE CASCADE,
  CONSTRAINT "bbb_bets_user_fk"  FOREIGN KEY ("user_id")  REFERENCES "User" ("id") ON DELETE CASCADE,
  CONSTRAINT "bbb_bets_status_chk" CHECK ("status" IN ('PENDING', 'WON', 'LOST', 'REFUNDED'))
);
CREATE INDEX IF NOT EXISTS "bbb_bets_round_idx" ON "bbb_bets" ("round_id");
CREATE INDEX IF NOT EXISTS "bbb_bets_user_idx"  ON "bbb_bets" ("user_id", "id");

-- ---------- 3. Your 11 parity --------------------------------------------------------------------

ALTER TABLE "fantasy_player_live_stats" ADD COLUMN IF NOT EXISTS "dot_balls"     INTEGER  NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_player_live_stats" ADD COLUMN IF NOT EXISTS "balls_bowled"  INTEGER  NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_player_live_stats" ADD COLUMN IF NOT EXISTS "in_lineup"     SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_player_live_stats" ADD COLUMN IF NOT EXISTS "is_substitute" SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_player_live_stats" ADD COLUMN IF NOT EXISTS "breakdown"     TEXT     NOT NULL DEFAULT '{}';

-- Announced-lineup bookkeeping on the fixture, so the lobby can show "Lineups out".
ALTER TABLE "fantasy_matches" ADD COLUMN IF NOT EXISTS "lineups_announced" SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_matches" ADD COLUMN IF NOT EXISTS "feed_key"          TEXT;
ALTER TABLE "fantasy_matches" ADD COLUMN IF NOT EXISTS "toss_text"         TEXT;

-- Playing substitutes (impact player etc.) are flagged by the feed, not chosen by us.
ALTER TABLE "fantasy_players" ADD COLUMN IF NOT EXISTS "is_substitute" SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_players" ADD COLUMN IF NOT EXISTS "credits_locked" SMALLINT NOT NULL DEFAULT 0;

-- Credits on the established apps span roughly 6 to 11, which is what makes the 100-credit cap a
-- real choice. The original 8.0-10.5 band was too narrow to price a star against a debutant.
ALTER TABLE "fantasy_players" DROP CONSTRAINT IF EXISTS "fantasy_players_credits_chk";
ALTER TABLE "fantasy_players" ADD CONSTRAINT "fantasy_players_credits_chk"
  CHECK ("credits" >= 5.0 AND "credits" <= 11.5);

-- Multi-entry contests: a player may enter up to max_entries_per_user DISTINCT teams. The old
-- one-entry-per-user unique index is replaced by a row lock on the contest inside the join
-- transaction (see fantasy_join_contest), and (contest_id, user_team_id) stays unique so the same
-- team can never be entered twice.
ALTER TABLE "fantasy_contests" ADD COLUMN IF NOT EXISTS "max_entries_per_user" INTEGER NOT NULL DEFAULT 1;
ALTER TABLE "fantasy_contests" ADD COLUMN IF NOT EXISTS "contest_type"        TEXT    NOT NULL DEFAULT 'custom';
ALTER TABLE "fantasy_contests" ADD COLUMN IF NOT EXISTS "is_guaranteed"       SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "fantasy_contests" ADD COLUMN IF NOT EXISTS "template_key"        TEXT;
DROP INDEX IF EXISTS "fantasy_entries_one_per_user";
CREATE UNIQUE INDEX IF NOT EXISTS "fantasy_contests_template_once"
  ON "fantasy_contests" ("match_id", "template_key") WHERE "template_key" IS NOT NULL;

-- Fantasy points history per real player across fixtures, which is what the credit model prices from.
CREATE TABLE IF NOT EXISTS "fantasy_player_history" (
  "id"           SERIAL PRIMARY KEY,
  "player_key"   TEXT NOT NULL,
  "match_id"     INTEGER NOT NULL,
  "format"       TEXT NOT NULL DEFAULT 'T20',
  "points"       NUMERIC(10,2) NOT NULL DEFAULT 0,
  "played_at"    TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "fantasy_player_history_once" UNIQUE ("player_key", "match_id")
);
CREATE INDEX IF NOT EXISTS "fantasy_player_history_key_idx" ON "fantasy_player_history" ("player_key", "played_at");

-- Change detection for re-sent deliveries (a corrected ball re-delivers with the same uid), and the
-- match-level facts that are not worth a column each (innings order, target, venue, tournament).
ALTER TABLE "cricket_deliveries" ADD COLUMN IF NOT EXISTS "fp"   TEXT NOT NULL DEFAULT '';
ALTER TABLE "cricket_match_feed" ADD COLUMN IF NOT EXISTS "meta" TEXT NOT NULL DEFAULT '{}';
