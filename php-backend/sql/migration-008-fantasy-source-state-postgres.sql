-- =================================================================================================
-- Migration 008 — "Your Eleven": record what the source says about a live match (PostgreSQL)
--
-- Additive. Two nullable columns on "fantasy_matches", a table introduced by migration 006. No
-- existing platform table is touched, no data changes, and re-running it is harmless.
--
-- WHY THESE COLUMNS EXIST
-- -----------------------
-- "status" is OUR lifecycle (UPCOMING / LIVE / SETTLED / CANCELLED) and only we move it. What the
-- source thinks is a different fact, and the live worker needs to keep it:
--
--   * Without it the worker cannot tell a match that is still being played from one that finished an
--     hour ago, so it would keep polling a completed scorecard for ever.
--   * Settlement must not begin merely because a start time has passed; it needs evidence that the
--     match is actually over, and "the source reported Complete" is that evidence.
--
-- Deliberately kept SEPARATE from "status" rather than adding a value to it. Conflating "the source
-- says the game ended" with "we have paid everyone out" is how a fixture gets marked finished while
-- prize money is still owed.
--
-- source_status_text holds the human sentence the source gives ("India won by 8 wkts", "Match
-- abandoned due to rain"), which is what an operator actually needs to see when deciding whether to
-- settle or void.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-008-fantasy-source-state-postgres.sql
-- or paste it into phpPgAdmin's SQL tab.
-- =================================================================================================

ALTER TABLE "fantasy_matches" ADD COLUMN IF NOT EXISTS "source_state"       TEXT;
ALTER TABLE "fantasy_matches" ADD COLUMN IF NOT EXISTS "source_status_text" TEXT;

-- Verification.
-- SELECT column_name FROM information_schema.columns
--  WHERE table_name = 'fantasy_matches' AND column_name LIKE 'source_%';
