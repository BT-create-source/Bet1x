-- =================================================================================================
-- Migration 002 — risk controls
--
-- Adds the columns the withdrawal/abuse checks in php-backend/lib/riskcontrols.php depend on.
--
-- Safe to run on a live database:
--   * Both statements are additive; no column is dropped, renamed or retyped.
--   * Both are nullable with no default backfill, so existing rows are untouched and no table
--     rewrite is forced beyond adding the column.
--   * Re-running it is harmless on MySQL 8.0+/MariaDB 10.5+ thanks to IF NOT EXISTS. On an older
--     server that does not support IF NOT EXISTS on ADD COLUMN, a second run errors with
--     "Duplicate column name" — that error means the migration is already applied, and can be
--     ignored.
--
-- Apply with:
--     mysql -u DBUSER -p DBNAME < php-backend/sql/migration-002-risk-controls.sql
-- =================================================================================================

-- The IP the account was registered from.
--
-- Registration is unverified (no email confirmation, no phone, no KYC), so this is the only signal
-- available for spotting one person opening accounts in bulk to farm the signup bonus. It is
-- deliberately VARCHAR(45): long enough for a full IPv6 address including an IPv4-mapped form.
--
-- Nullable because every account that existed before this migration has no recorded IP, and
-- "unknown" must be distinguishable from "registered from an address we captured".
ALTER TABLE `User`
  ADD COLUMN IF NOT EXISTS `signup_ip` VARCHAR(45) NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `User_signup_ip_idx` ON `User` (`signup_ip`);

-- Marks an account whose balance originated from the signup bonus rather than from money it paid
-- in. Used by the "must have deposited before withdrawing" rule so that a bonus cannot be cashed
-- out directly. Existing accounts default to 0 (not bonus-funded), which is the permissive value —
-- this migration must not retroactively freeze withdrawals for real players.
ALTER TABLE `User`
  ADD COLUMN IF NOT EXISTS `bonus_credited` TINYINT(1) NOT NULL DEFAULT 0;
