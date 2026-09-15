-- =================================================================================================
-- Migration 002 — risk controls (PostgreSQL edition)
--
-- Adds the columns the withdrawal/abuse checks in php-backend/lib/riskcontrols.php depend on.
-- This is the PostgreSQL sibling of sql/migration-002-risk-controls.sql (the MySQL version).
--
-- Safe to run on a live database:
--   * Both statements are additive; no column is dropped, renamed or retyped.
--   * Both are nullable/defaulted with no backfill, so existing rows are untouched and no table
--     rewrite is forced beyond adding the column.
--   * IF NOT EXISTS makes re-running it a no-op rather than an error.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-002-risk-controls-postgres.sql
-- =================================================================================================

-- The IP the account was registered from.
--
-- Registration is unverified (no email confirmation, no phone, no KYC), so this is the only signal
-- available for spotting one person opening accounts in bulk to farm the signup bonus. VARCHAR(45)
-- is long enough for a full IPv6 address including an IPv4-mapped form.
--
-- Nullable because every account that existed before this migration has no recorded IP, and
-- "unknown" must be distinguishable from "registered from an address we captured".
ALTER TABLE "User"
  ADD COLUMN IF NOT EXISTS "signup_ip" VARCHAR(45) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS "User_signup_ip_idx" ON "User" ("signup_ip");

-- Marks an account whose balance originated from the signup bonus rather than from money it paid
-- in. Used by the "must have deposited before withdrawing" rule so that a bonus cannot be cashed
-- out directly. Existing accounts default to 0 (not bonus-funded), which is the permissive value —
-- this migration must not retroactively freeze withdrawals for real players.
--
-- SMALLINT, not BOOLEAN — see the type-choices note at the top of sql/schema-postgres.sql: the
-- application reads/writes this the same 0/1 integer way it does every other former-TINYINT flag.
ALTER TABLE "User"
  ADD COLUMN IF NOT EXISTS "bonus_credited" SMALLINT NOT NULL DEFAULT 0;
