-- =================================================================================================
-- Migration 012 — Chicken Road and Astronaut.
--
-- Chicken Road is one board per player, exactly like Mines: a single row per username, claimed with
-- a unique-index INSERT so a double-clicked Play cannot take two stakes for one road.
--
-- Astronaut is one shared round for everybody, like Aviator, but its bets live in their own table
-- rather than inside the round's JSON blob. That is what lets a cash-out be a conditional UPDATE on
-- one row (pending -> won) instead of a read-modify-write of the whole book, and lets the unique
-- index (round, player, panel) refuse a second bet on the same panel at the database level.
--
-- The round state itself (phase, timing, seed, history) lives in GameState under
-- 'astronaut_runtime'; operator settings for both games live in GameState too
-- ('chickenroad_config', 'astronaut_config'), so neither needs a table of its own.
--
-- Additive and idempotent: safe to run more than once, touches no existing table.
-- =================================================================================================

-- Restated from schema-postgres.sql (identical body) so this file also applies to a database that
-- was built without it; CREATE OR REPLACE makes it a no-op where it already exists.
CREATE OR REPLACE FUNCTION set_updated_at_snake_column() RETURNS trigger AS $$
BEGIN
  NEW."updated_at" = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TABLE IF NOT EXISTS "ChickenRoadSession" (
  "id"           SERIAL PRIMARY KEY,
  "username"     TEXT NOT NULL,
  "status"       TEXT NOT NULL,                          -- starting | active | busted | cashed
  "difficulty"   TEXT NOT NULL DEFAULT 'easy',           -- easy | medium | hard | hardcore
  "bet_amount"   DOUBLE PRECISION NOT NULL DEFAULT 0,
  "step"         INTEGER NOT NULL DEFAULT 0,             -- lanes safely crossed
  "fail_step"    INTEGER NOT NULL DEFAULT 0,             -- lane that burns (0 = none); never sent while active
  "server_seed"  TEXT,
  "seed_hash"    TEXT,
  "multiplier"   DOUBLE PRECISION NOT NULL DEFAULT 1,
  "payout"       DOUBLE PRECISION NOT NULL DEFAULT 0,
  "round_ref"    TEXT,
  "updated_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "ChickenRoadSession_username_key" UNIQUE ("username")
);
DROP TRIGGER IF EXISTS trg_chickenroadsession_updated_at ON "ChickenRoadSession";
CREATE TRIGGER trg_chickenroadsession_updated_at BEFORE UPDATE ON "ChickenRoadSession"
  FOR EACH ROW EXECUTE FUNCTION set_updated_at_snake_column();

CREATE TABLE IF NOT EXISTS "AstronautBet" (
  "id"              BIGSERIAL PRIMARY KEY,
  "round_id"        BIGINT NOT NULL,
  "username"        TEXT NOT NULL,
  "panel"           SMALLINT NOT NULL,                   -- 1 or 2: the two bet panels
  "amount"          DOUBLE PRECISION NOT NULL,
  "auto_cashout"    DOUBLE PRECISION,                    -- NULL = manual cash-out only
  "status"          TEXT NOT NULL DEFAULT 'pending',     -- pending | won | lost | cancelled
  "cashout_mult"    DOUBLE PRECISION,
  "payout"          DOUBLE PRECISION NOT NULL DEFAULT 0,
  "created_at"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "settled_at"      TIMESTAMP(3),
  CONSTRAINT "AstronautBet_round_user_panel_key" UNIQUE ("round_id", "username", "panel"),
  CONSTRAINT "AstronautBet_panel_chk"  CHECK ("panel" IN (1, 2)),
  CONSTRAINT "AstronautBet_status_chk" CHECK ("status" IN ('pending', 'won', 'lost', 'cancelled'))
);
CREATE INDEX IF NOT EXISTS "AstronautBet_round_status_idx" ON "AstronautBet" ("round_id", "status");
CREATE INDEX IF NOT EXISTS "AstronautBet_user_idx"         ON "AstronautBet" ("username", "id");
