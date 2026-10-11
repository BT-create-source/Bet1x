-- =================================================================================================
-- Migration 013 — Tower Rush.
--
-- One tower per player, exactly like ChickenRoadSession: a single row per username, claimed with a
-- unique-index INSERT so a double-clicked BUILD cannot take two stakes for one tower. Each floor's
-- multiplier is derived from the round's server seed (games/towerrush.php), so only the floors
-- already built are stored. Operator settings live in GameState ('towerrush_config').
--
-- Additive and idempotent: safe to run more than once, touches no existing table.
-- =================================================================================================

CREATE OR REPLACE FUNCTION set_updated_at_snake_column() RETURNS trigger AS $$
BEGIN
  NEW."updated_at" = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TABLE IF NOT EXISTS "TowerRushSession" (
  "id"           SERIAL PRIMARY KEY,
  "username"     TEXT NOT NULL,
  "status"       TEXT NOT NULL,                          -- starting | active | busted | cashed
  "bet_amount"   DOUBLE PRECISION NOT NULL DEFAULT 0,
  "floors"       INTEGER NOT NULL DEFAULT 0,             -- floors built so far (the collapse is not counted)
  "factors"      JSONB,                                  -- each built floor's multiplier, in order
  "multiplier"   DOUBLE PRECISION NOT NULL DEFAULT 1,    -- product of the factors
  "rtp"          DOUBLE PRECISION NOT NULL DEFAULT 0.97, -- the RTP this round was dealt at (fixes its floors)
  "payout"       DOUBLE PRECISION NOT NULL DEFAULT 0,
  "server_seed"  TEXT,
  "seed_hash"    TEXT,
  "round_ref"    TEXT,
  "updated_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "TowerRushSession_username_key" UNIQUE ("username")
);
DROP TRIGGER IF EXISTS trg_towerrushsession_updated_at ON "TowerRushSession";
CREATE TRIGGER trg_towerrushsession_updated_at BEFORE UPDATE ON "TowerRushSession"
  FOR EACH ROW EXECUTE FUNCTION set_updated_at_snake_column();
