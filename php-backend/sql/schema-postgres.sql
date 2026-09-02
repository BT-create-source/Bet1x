-- =================================================================================================
-- bet1x — PostgreSQL schema (cPanel port of backend/prisma/schema.prisma, PostgreSQL edition)
-- =================================================================================================
--
-- This is the PostgreSQL sibling of sql/schema.sql (the MySQL version written for hosts that only
-- offer MySQL/MariaDB). Use this one when the cPanel account's "Databases" section offers
-- PostgreSQL Databases instead of (or in addition to) MySQL Databases. Everything else about the
-- PHP backend — routes, games, admin, house-edge engine — is identical between the two; only
-- lib/db.php's connection and this schema differ.
--
-- Table and column names are IDENTICAL to the Prisma model/field names, deliberately: it keeps the
-- mapping between this file and the original readable, and it means a dump exported from the old
-- Node-era PostgreSQL database can be loaded here with no column renaming (see backend/prisma
-- migrations for that original schema — this file re-derives it directly rather than depending on
-- Prisma at deploy time).
--
-- TYPE CHOICES THAT AFFECT BEHAVIOUR — read before changing anything:
--
--   * Money is DOUBLE PRECISION, not NUMERIC/DECIMAL. This is deliberate and is NOT an oversight.
--     Prisma's original Float mapped to PostgreSQL double precision, and the application code leans
--     on that: it rounds ad hoc, at specific points, via round(x*100)/100-style PHP arithmetic.
--     NUMERIC would round differently at every write, so balances would diverge from both the
--     original Node build and the MySQL port. Moving to NUMERIC is a worthwhile change, but a
--     separate and deliberate one, made after this port is proven.
--
--   * `username` and `email` use PostgreSQL's default collation, which is already byte-comparison
--     case-SENSITIVE — 'Bob' and 'bob' are different values for uniqueness purposes, matching
--     Prisma's original behaviour with no extra COLLATE clause needed (unlike the MySQL port, which
--     had to opt into utf8mb4_bin explicitly to get the same result). Every case-INsensitive lookup
--     in the app is written as an explicit LOWER(col) = LOWER(?), exactly mirroring Prisma's
--     `mode: 'insensitive'` — this file does not need to do anything extra for that to work.
--
--   * Former MySQL TINYINT(1) flag columns (is_bot, folded, seen, rigged, eligible, bonus_credited)
--     are SMALLINT here, NOT native PostgreSQL BOOLEAN. This is deliberate, not a port oversight:
--     the PHP application code was written and tested against the MySQL port's TINYINT semantics —
--     it does `(int)$row['is_bot'] !== 0` and writes raw SQL literals like `"is_bot" = 0`. PDO's
--     pgsql driver returns a native BOOLEAN column as the string 't'/'f' rather than a PHP bool, and
--     `(int)'f'` is 0 in PHP just like `(int)'t'` is 0 — both are non-numeric strings — so a real
--     BOOLEAN column would make every one of those `!== 0` checks silently false regardless of the
--     stored value, and `"is_bot" = 0` would raise "operator does not exist: boolean = integer"
--     outright. SMALLINT reproduces MySQL's 0/1 integer semantics exactly, so none of that
--     already-written PHP code needed to change.
--
--   * JSON columns are JSONB, matching Prisma's original Json mapping (and strictly better than the
--     MySQL port's JSON type: indexable, and PHP's json_decode() on the fetched text works exactly
--     the same either way).
--
--   * TIMESTAMP(3) (no time zone), not TIMESTAMPTZ. Millisecond precision matches Prisma's
--     DateTime. All timestamps are written and read as UTC by the application; lib/db.php issues
--     `SET TIME ZONE 'UTC'` on every connection so the server's own configured zone cannot shift
--     stored values regardless of what NOW() would otherwise return.
--
--   * `"updatedAt"` / `"updated_at"` columns that MySQL auto-refreshed via `ON UPDATE
--     CURRENT_TIMESTAMP(3)` have no equivalent column-level clause in PostgreSQL. The
--     set_updated_at() trigger function at the bottom of the "Core" section reproduces it — attached
--     to GameState, TeenPattiRoom and MinesSession, the three tables whose application code never
--     sets that column explicitly (Deposit/Withdrawal always set updated_at themselves in every
--     UPDATE, so they need no trigger).
--
-- Run this file once, then php-backend/cron/tick.php on a one-minute cron. Do NOT run
-- schema-cricket.sql — the two cricket games are gated off until v2.
-- =================================================================================================

SET client_min_messages = WARNING;

-- -------------------------------------------------------------------------------------------------
-- updated_at trigger helper — see the type-choices note above.
-- -------------------------------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION set_updated_at_column() RETURNS trigger AS $$
BEGIN
  NEW."updatedAt" = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION set_updated_at_snake_column() RETURNS trigger AS $$
BEGIN
  NEW."updated_at" = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- -------------------------------------------------------------------------------------------------
-- Core account + ledger
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "User" (
  "id"             SERIAL PRIMARY KEY,
  "username"       TEXT NOT NULL,
  "email"          TEXT NOT NULL,
  "password"       TEXT NOT NULL,
  "wallet_balance" DOUBLE PRECISION NOT NULL DEFAULT 1000,
  "created_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "User_username_key" UNIQUE ("username")
);

CREATE TABLE IF NOT EXISTS "Transaction" (
  "id"        TEXT PRIMARY KEY,
  "user"      TEXT NOT NULL,
  "type"      TEXT NOT NULL,
  "amount"    DOUBLE PRECISION NOT NULL,
  "details"   TEXT NOT NULL,
  "status"    TEXT NOT NULL,
  "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "Transaction_user_idx" ON "Transaction" ("user");
CREATE INDEX IF NOT EXISTS "Transaction_timestamp_idx" ON "Transaction" ("timestamp");

CREATE TABLE IF NOT EXISTS "Deposit" (
  "deposit_id"     TEXT PRIMARY KEY,
  "order_id"       TEXT,
  "username"       TEXT NOT NULL,
  "amount"         DOUBLE PRECISION NOT NULL,
  "utr"            TEXT,
  "qr_type"        TEXT,
  "custom_qr_data" TEXT,
  "status"         TEXT NOT NULL,
  "gateway"        TEXT,
  "gateway_id"     TEXT,
  "created_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "Deposit_order_id_key" UNIQUE ("order_id")
);
CREATE INDEX IF NOT EXISTS "Deposit_username_idx" ON "Deposit" ("username");
CREATE INDEX IF NOT EXISTS "Deposit_status_idx" ON "Deposit" ("status");

CREATE TABLE IF NOT EXISTS "Withdrawal" (
  "withdrawal_id" TEXT PRIMARY KEY,
  "username"      TEXT NOT NULL,
  "amount"        DOUBLE PRECISION NOT NULL,
  "method"        TEXT NOT NULL,
  "details"       TEXT NOT NULL,
  "status"        TEXT NOT NULL,
  "created_at"    TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"    TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "Withdrawal_username_idx" ON "Withdrawal" ("username");
CREATE INDEX IF NOT EXISTS "Withdrawal_status_idx" ON "Withdrawal" ("status");

CREATE TABLE IF NOT EXISTS "PaymentLog" (
  "id"        TEXT PRIMARY KEY,
  "payload"   JSONB NOT NULL,
  "signature" TEXT,
  "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

-- -------------------------------------------------------------------------------------------------
-- Generic key/JSON store.
--
-- Carries far more than its name suggests: the whole colour-prediction round state, every per-room
-- rig override, the bot-takeover config, each rig ledger's in-progress 100-slot bag, the Mines rig
-- matrix, and the Aviator runtime.
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "GameState" (
  "key"       TEXT PRIMARY KEY,
  "data"      JSONB NOT NULL,
  "updatedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
DROP TRIGGER IF EXISTS trg_gamestate_updated_at ON "GameState";
CREATE TRIGGER trg_gamestate_updated_at BEFORE UPDATE ON "GameState"
  FOR EACH ROW EXECUTE FUNCTION set_updated_at_column();

CREATE TABLE IF NOT EXISTS "RecentResult" (
  "id"          SERIAL PRIMARY KEY,
  "room"        TEXT NOT NULL,
  "roundNumber" TEXT NOT NULL,
  "number"      INTEGER NOT NULL,
  "color"       TEXT NOT NULL,
  "dotClass"    TEXT NOT NULL,
  "size"        TEXT NOT NULL,
  "timestamp"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "RecentResult_room_roundNumber_key" UNIQUE ("room", "roundNumber")
);

CREATE TABLE IF NOT EXISTS "ChatMessage" (
  "id"        SERIAL PRIMARY KEY,
  "username"  TEXT NOT NULL,
  "message"   TEXT NOT NULL,
  "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "ChatMessage_timestamp_idx" ON "ChatMessage" ("timestamp");

-- -------------------------------------------------------------------------------------------------
-- Teen Patti
--
-- `bot_turn_due_at`, `round_end_due_at` and `bot_fill_due_at` hold deadlines that used to be
-- setTimeout handles living in the Node process; PHP has no process to hold them, so the deadline
-- is written down and whichever request (or the one-minute cron) arrives after it has passed
-- performs the work. Same observable timing for anyone actually sitting at a table, because the
-- client polls every 2 seconds.
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS "TeenPattiRoom" (
  "id"               TEXT PRIMARY KEY,
  "name"             TEXT NOT NULL,
  "boot_amount"      DOUBLE PRECISION NOT NULL DEFAULT 10,
  "status"           TEXT NOT NULL DEFAULT 'waiting',
  "pot"              DOUBLE PRECISION NOT NULL DEFAULT 0,
  "current_stake"    DOUBLE PRECISION NOT NULL DEFAULT 10,
  "turn_index"       INTEGER NOT NULL DEFAULT 0,
  "turn_seat"        INTEGER,
  "turn_start"       TIMESTAMP(3),
  "round"            INTEGER NOT NULL DEFAULT 1,
  "winner_seat"      INTEGER,
  "deck_state"       JSONB,
  "admin_rig"        JSONB,
  "log"              JSONB NOT NULL DEFAULT '[]',
  "created_at"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "updated_at"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "bot_turn_due_at"  BIGINT,
  "round_end_due_at" BIGINT,
  "bot_fill_due_at"  BIGINT
);
CREATE INDEX IF NOT EXISTS "TeenPattiRoom_status_idx" ON "TeenPattiRoom" ("status");
DROP TRIGGER IF EXISTS trg_teenpattiroom_updated_at ON "TeenPattiRoom";
CREATE TRIGGER trg_teenpattiroom_updated_at BEFORE UPDATE ON "TeenPattiRoom"
  FOR EACH ROW EXECUTE FUNCTION set_updated_at_snake_column();

CREATE TABLE IF NOT EXISTS "TeenPattiSeat" (
  "id"            SERIAL PRIMARY KEY,
  "room_id"       TEXT NOT NULL,
  "seat"          INTEGER NOT NULL,
  "username"      TEXT,
  "is_bot"        SMALLINT NOT NULL DEFAULT 0,
  "cards"         JSONB,
  "folded"        SMALLINT NOT NULL DEFAULT 0,
  "balance"       DOUBLE PRECISION NOT NULL DEFAULT 0,
  "seen"          SMALLINT NOT NULL DEFAULT 0,
  "last_seen_at"  BIGINT,
  CONSTRAINT "TeenPattiSeat_room_id_seat_key" UNIQUE ("room_id", "seat"),
  CONSTRAINT "TeenPattiSeat_room_id_fkey"
    FOREIGN KEY ("room_id") REFERENCES "TeenPattiRoom" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS "TeenPattiSeat_username_idx" ON "TeenPattiSeat" ("username");

CREATE TABLE IF NOT EXISTS "GameBet" (
  "id"         TEXT PRIMARY KEY,
  "username"   TEXT NOT NULL,
  "game"       TEXT NOT NULL,
  "bet_amount" DOUBLE PRECISION NOT NULL,
  "payout"     DOUBLE PRECISION NOT NULL DEFAULT 0,
  "status"     TEXT NOT NULL DEFAULT 'active',
  "metadata"   JSONB,
  "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "settled_at" TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "GameBet_username_game_idx" ON "GameBet" ("username", "game");
CREATE INDEX IF NOT EXISTS "GameBet_game_status_idx" ON "GameBet" ("game", "status");

-- =================================================================================================
-- RUNTIME TABLES — new in the PHP port
--
-- None of these existed in the Node build because none of them needed to: they held state in
-- process memory that survived between requests. PHP starts from nothing on every request, so the
-- state has to be written down. Behaviour is unchanged; only its storage is.
-- =================================================================================================

-- Replaces MINES_USER_SESSIONS. One live board per player.
--
-- The UNIQUE key on username is load-bearing: it is what reproduces the synchronous "claim the
-- player's single session slot before the first await" guard from server.js. An INSERT that
-- collides is the PHP equivalent of finding a session already present, and it is what stops twelve
-- double-clicked Start requests all taking a stake for one board.
CREATE TABLE IF NOT EXISTS "MinesSession" (
  "id"               SERIAL PRIMARY KEY,
  "username"         TEXT NOT NULL,
  "status"           TEXT NOT NULL,
  "bet_amount"       DOUBLE PRECISION NOT NULL DEFAULT 0,
  "mines_count"      INTEGER NOT NULL DEFAULT 3,
  "server_seed"      TEXT,
  "seed_hash"        TEXT,
  "mine_positions"   JSONB,
  "revealed"         JSONB,
  "multiplier"       DOUBLE PRECISION NOT NULL DEFAULT 1,
  "potential_payout" DOUBLE PRECISION NOT NULL DEFAULT 0,
  "updated_at"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "MinesSession_username_key" UNIQUE ("username")
);
DROP TRIGGER IF EXISTS trg_minessession_updated_at ON "MinesSession";
CREATE TRIGGER trg_minessession_updated_at BEFORE UPDATE ON "MinesSession"
  FOR EACH ROW EXECUTE FUNCTION set_updated_at_snake_column();

-- Replaces LIVE_USERS and LIVE_INSTANCES. `kind` is 'user' or 'instance'.
-- The 45-second TTL is applied at read time, exactly as the in-memory version did.
CREATE TABLE IF NOT EXISTS "LivePresence" (
  "id"        SERIAL PRIMARY KEY,
  "game"      TEXT NOT NULL,
  "kind"      TEXT NOT NULL,
  "subject"   TEXT NOT NULL,
  "last_seen" BIGINT NOT NULL,
  CONSTRAINT "LivePresence_game_kind_subject_key" UNIQUE ("game", "kind", "subject")
);
CREATE INDEX IF NOT EXISTS "LivePresence_last_seen_idx" ON "LivePresence" ("last_seen");

-- Replaces lib/rig-audit.js's in-memory ring buffer. Trimmed to MAX_ENTRIES (5000) by the cron and
-- opportunistically on write, matching the ring buffer's cap.
CREATE TABLE IF NOT EXISTS "RigAudit" (
  "id"             BIGSERIAL PRIMARY KEY,
  "ts"             BIGINT NOT NULL,
  "game"           TEXT NOT NULL,
  "instance"       TEXT,
  "round"          TEXT,
  "configured_pct" DOUBLE PRECISION NOT NULL DEFAULT 0,
  "rigged"         SMALLINT NOT NULL DEFAULT 0,
  "eligible"       SMALLINT NOT NULL DEFAULT 1,
  "live"           INTEGER,
  "targeted"       INTEGER,
  "house_profit"   DOUBLE PRECISION,
  "note"           VARCHAR(200)
);
CREATE INDEX IF NOT EXISTS "RigAudit_game_ts_idx" ON "RigAudit" ("game", "ts");
CREATE INDEX IF NOT EXISTS "RigAudit_ts_idx" ON "RigAudit" ("ts");

-- Replaces express-rate-limit's in-memory store. One row per (bucket, client, window).
CREATE TABLE IF NOT EXISTS "RateLimit" (
  "id"           BIGSERIAL PRIMARY KEY,
  "bucket"       TEXT NOT NULL,
  "client"       TEXT NOT NULL,
  "window_start" BIGINT NOT NULL,
  "hits"         INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT "RateLimit_bucket_client_window_key" UNIQUE ("bucket", "client", "window_start")
);
CREATE INDEX IF NOT EXISTS "RateLimit_window_start_idx" ON "RateLimit" ("window_start");

-- =================================================================================================
-- SEED — the house account.
--
-- REQUIRED. Teen Patti's house-win paths do not work without it, and they fail SILENTLY.
--
-- Both the operator rig (/api/teenpatti/admin/rig) and the per-table takeover seat a player literally
-- named "Admin". tp_start_round then runs its can-this-player-cover-the-boot check over every seated
-- non-filler, looks the name up in `User`, finds nothing, and ejects the seat — so the operator flips
-- the rig, watches the seat vanish, and the house wins nothing. The original Node build behaves
-- exactly the same way; this is an install gap in both, not a difference between them.
--
-- The rest of the design plainly assumes this row exists: tp_start_round tops the account up to 5000
-- before each hand, tp_end_game credits it the pot when it wins, and the superadmin dashboard
-- excludes it from house-profit maths by name, describing it as "the account the house plays
-- through". The winnings have to land in a real wallet.
--
-- The seeded row's password column deliberately holds a value that is not a valid bcrypt hash, so
-- password_verify() can never match it and nobody can sign in as the house. Operators use
-- ADMIN_USERNAME / ADMIN_PASSWORD_HASH for the console; this is a ledger account, not a login.
-- =================================================================================================

INSERT INTO "User" ("username", "email", "password", "wallet_balance")
VALUES ('Admin', 'admin@bet1x.local', '!house-account-login-disabled', 5000)
ON CONFLICT ("username") DO NOTHING;

-- =================================================================================================
-- SEED — the six Teen Patti rooms.
--
-- tpSeedRooms() ran on every Node boot and, for an existing room, cleared every seat and every rig.
-- There is no "boot" in PHP, so that reset now belongs to the installer: running this file resets
-- the rooms exactly the way restarting the old server did.
-- =================================================================================================

INSERT INTO "TeenPattiRoom" ("id","name","boot_amount","status","pot","current_stake","log")
VALUES
  ('room_101','Room 1', 10,'waiting',0, 10,'[]'),
  ('room_102','Room 2',100,'waiting',0,100,'[]'),
  ('room_103','Room 3', 50,'waiting',0, 50,'[]'),
  ('room_104','Room 4', 50,'waiting',0, 50,'[]'),
  ('room_105','Room 5', 25,'waiting',0, 25,'[]'),
  ('room_106','Room 6',250,'waiting',0,250,'[]')
ON CONFLICT ("id") DO UPDATE SET
  "status"='waiting', "pot"=0, "winner_seat"=NULL, "admin_rig"=NULL,
  "bot_turn_due_at"=NULL, "round_end_due_at"=NULL, "bot_fill_due_at"=NULL;

INSERT INTO "TeenPattiSeat" ("room_id","seat","username","is_bot","cards","folded")
VALUES
  ('room_101',0,NULL,0,NULL,0),('room_101',1,NULL,0,NULL,0),('room_101',2,NULL,0,NULL,0),('room_101',3,NULL,0,NULL,0),
  ('room_102',0,NULL,0,NULL,0),('room_102',1,NULL,0,NULL,0),('room_102',2,NULL,0,NULL,0),('room_102',3,NULL,0,NULL,0),
  ('room_103',0,NULL,0,NULL,0),('room_103',1,NULL,0,NULL,0),('room_103',2,NULL,0,NULL,0),('room_103',3,NULL,0,NULL,0),
  ('room_104',0,NULL,0,NULL,0),('room_104',1,NULL,0,NULL,0),('room_104',2,NULL,0,NULL,0),('room_104',3,NULL,0,NULL,0),
  ('room_105',0,NULL,0,NULL,0),('room_105',1,NULL,0,NULL,0),('room_105',2,NULL,0,NULL,0),('room_105',3,NULL,0,NULL,0),
  ('room_106',0,NULL,0,NULL,0),('room_106',1,NULL,0,NULL,0),('room_106',2,NULL,0,NULL,0),('room_106',3,NULL,0,NULL,0)
ON CONFLICT ("room_id","seat") DO UPDATE SET
  "username"=NULL, "is_bot"=0, "cards"=NULL, "folded"=0, "last_seen_at"=NULL;
