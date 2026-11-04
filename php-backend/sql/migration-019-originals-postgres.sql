-- =================================================================================================
-- Migration 019 — bet1x Originals (Dice, Coinflip, Wheel, Plinko, Keno, Roulette, Baccarat,
-- Andar Bahar, Slots, Blackjack, Matka). Applied automatically on first use by lib/originals.php
-- (originals_ensure); safe to run by hand too. The MySQL twin is in sql/schema-mysql.sql.
--
--   OriginalsSeed   one row per player: the provably-fair seed pair (hidden server seed + its hash,
--                   the player's client seed, the round counter) and the last revealed pair.
--   OriginalsRound  one row per round. client_ref is unique per player, so a repeated request finds
--                   the round it already created instead of betting twice. state holds what the
--                   server must keep secret while a round is open (Blackjack, Coinflip streak).
-- =================================================================================================

CREATE TABLE IF NOT EXISTS "OriginalsSeed" (
  "username"              TEXT PRIMARY KEY,
  "server_seed"           TEXT NOT NULL,
  "server_seed_hash"      TEXT NOT NULL,
  "client_seed"           TEXT NOT NULL,
  "nonce"                 BIGINT NOT NULL DEFAULT 0,
  "prev_server_seed"      TEXT,
  "prev_server_seed_hash" TEXT,
  "prev_client_seed"      TEXT,
  "prev_nonce"            BIGINT,
  "updated_at"            TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

CREATE TABLE IF NOT EXISTS "OriginalsRound" (
  "id"               BIGSERIAL PRIMARY KEY,
  "round_ref"        TEXT NOT NULL,
  "username"         TEXT NOT NULL,
  "game"             TEXT NOT NULL,
  "client_ref"       TEXT NOT NULL,
  "bet_amount"       DOUBLE PRECISION NOT NULL,
  "payout"           DOUBLE PRECISION NOT NULL DEFAULT 0,
  "multiplier"       DOUBLE PRECISION NOT NULL DEFAULT 0,
  "status"           TEXT NOT NULL,
  "params"           TEXT,
  "state"            TEXT,
  "result"           TEXT,
  "server_seed"      TEXT NOT NULL,
  "server_seed_hash" TEXT NOT NULL,
  "client_seed"      TEXT NOT NULL,
  "nonce"            BIGINT NOT NULL,
  "created_at"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "settled_at"       TIMESTAMP(3),
  CONSTRAINT "OriginalsRound_ref_key" UNIQUE ("round_ref"),
  CONSTRAINT "OriginalsRound_user_ref_key" UNIQUE ("username", "client_ref")
);
CREATE INDEX IF NOT EXISTS "OriginalsRound_user_idx" ON "OriginalsRound" ("username", "id");
CREATE INDEX IF NOT EXISTS "OriginalsRound_open_idx" ON "OriginalsRound" ("username", "game", "status");
CREATE INDEX IF NOT EXISTS "OriginalsRound_created_idx" ON "OriginalsRound" ("created_at");
