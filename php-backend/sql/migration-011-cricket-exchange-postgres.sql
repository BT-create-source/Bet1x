-- =================================================================================================
-- Migration 011 — cricket match betting: Match Odds, Bookmaker, Fancy (session) and Cash Out.
--
-- Prices are NOT stored: lib/odds-engine.php recomputes them from the live match state on every read,
-- so a price can never go stale in the database. What is stored is what money depends on — each bet
-- with the exact price/line it was struck at, the result each market settled on, and the operator's
-- per-match settings. Additive and idempotent.
-- =================================================================================================

CREATE TABLE IF NOT EXISTS "mx_bets" (
  "id"           BIGSERIAL PRIMARY KEY,
  "match_key"    TEXT NOT NULL,
  "market_key"   TEXT NOT NULL,          -- MO | BM | ms_<inn>_<over> | ov_<inn>_<over>
  "market_type"  TEXT NOT NULL,          -- MATCH_ODDS | BOOKMAKER | FANCY
  "market_name"  TEXT NOT NULL DEFAULT '',
  "selection"    TEXT NOT NULL,          -- team side 'a'/'b', or 'RUNS' for fancy
  "selection_name" TEXT NOT NULL DEFAULT '',
  "side"         TEXT NOT NULL,          -- BACK | LAY | YES | NO
  "odds"         NUMERIC(10,3) NOT NULL DEFAULT 0,   -- decimal odds (Match Odds; Bookmaker as decimal)
  "rate"         NUMERIC(10,2) NOT NULL DEFAULT 0,   -- Bookmaker rate (profit per 100) or fancy rate
  "line"         INTEGER,                            -- fancy runs line
  "stake"        NUMERIC(12,2) NOT NULL,
  "liability"    NUMERIC(14,2) NOT NULL,             -- what was debited
  "profit"       NUMERIC(14,2) NOT NULL,             -- what is won on top of liability
  "user_id"      INTEGER NOT NULL,
  "username"     TEXT NOT NULL,
  "txn_id"       TEXT,
  "status"       TEXT NOT NULL DEFAULT 'PENDING',
  "payout"       NUMERIC(14,2) NOT NULL DEFAULT 0,
  "void_reason"  TEXT,
  "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "settled_at"   TIMESTAMP(3),
  CONSTRAINT "mx_bets_user_fk" FOREIGN KEY ("user_id") REFERENCES "User" ("id") ON DELETE CASCADE,
  CONSTRAINT "mx_bets_status_chk" CHECK ("status" IN ('PENDING', 'WON', 'LOST', 'VOID', 'CASHED_OUT')),
  CONSTRAINT "mx_bets_side_chk" CHECK ("side" IN ('BACK', 'LAY', 'YES', 'NO'))
);
CREATE INDEX IF NOT EXISTS "mx_bets_match_idx"  ON "mx_bets" ("match_key", "market_key", "status");
CREATE INDEX IF NOT EXISTS "mx_bets_user_idx"   ON "mx_bets" ("user_id", "id");
CREATE INDEX IF NOT EXISTS "mx_bets_created_idx" ON "mx_bets" ("match_key", "created_at");

-- The result each market settled on (or why it was voided). One row per market, written inside the
-- settlement transaction, so a market is settled at most once whoever runs the worker.
CREATE TABLE IF NOT EXISTS "mx_markets" (
  "match_key"   TEXT NOT NULL,
  "market_key"  TEXT NOT NULL,
  "market_type" TEXT NOT NULL,
  "market_name" TEXT NOT NULL DEFAULT '',
  "status"      TEXT NOT NULL,           -- SETTLED | VOID
  "result"      TEXT,                    -- winning side, or the actual runs for a fancy line
  "void_reason" TEXT,
  "settled_at"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "mx_markets_pk" PRIMARY KEY ("match_key", "market_key")
);

-- Operator settings per match: the pre-match strength price (the one number the engine cannot know
-- from the scorecard), a manual suspend switch, and per-match overrides.
CREATE TABLE IF NOT EXISTS "mx_match_settings" (
  "match_key"   TEXT PRIMARY KEY,
  "fav_side"    TEXT,                    -- 'a' | 'b'
  "fav_price"   NUMERIC(8,3),            -- decimal odds of the favourite before the toss
  "delta"       NUMERIC(10,5) NOT NULL DEFAULT 0,   -- derived logit strength shift for side 'a'
  "suspended"   SMALLINT NOT NULL DEFAULT 0,
  "source"      TEXT NOT NULL DEFAULT 'operator',
  "updated_at"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
