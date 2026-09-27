-- =================================================================================================
-- Migration 007 — "Your Eleven": one entry per account per contest (PostgreSQL)
--
-- Additive and IF NOT EXISTS, like 006. Adds one unique index to a table introduced by 006; touches
-- no existing table and no data.
--
-- WHY THIS IS A DATABASE CONSTRAINT AND NOT A PHP CHECK
-- -----------------------------------------------------
-- 006 already carried UNIQUE (contest_id, user_team_id), which stops the SAME TEAM being entered
-- into one contest twice — the double-tap on "Join". It does not stop one account entering the same
-- contest with two DIFFERENT teams, and in a two-spot Head-to-Head that means a player can occupy
-- both sides and face themselves.
--
-- A PHP "have you already joined?" check cannot close that: two simultaneous requests both read
-- "no entry yet" before either writes, and both then insert rows with different user_team_id values,
-- so the existing constraint does not fire. Only the database can make the decision atomically, so
-- the rule lives here.
--
-- Consequence to be aware of: this makes every contest single-entry. Multi-entry contests, where a
-- player may field several teams in one large contest, would need a per-contest flag (for example
-- "max_entries_per_user") and this index replaced with one that includes an entry sequence. That is
-- a deliberate trade: single-entry is the conservative default, and relaxing it later is a migration
-- rather than a bug to discover in production.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-007-fantasy-entry-guard-postgres.sql
-- or paste it into phpPgAdmin's SQL tab.
-- =================================================================================================

CREATE UNIQUE INDEX IF NOT EXISTS "fantasy_entries_one_per_user"
    ON "fantasy_contest_entries" ("contest_id", "user_id");

-- Verification — should list both the 006 constraint and this index.
-- SELECT indexname FROM pg_indexes WHERE tablename = 'fantasy_contest_entries' ORDER BY indexname;
