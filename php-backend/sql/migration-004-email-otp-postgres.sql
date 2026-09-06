-- =================================================================================================
-- Migration 004 — email OTP verification at signup (PostgreSQL)
--
-- Adds what email verification needs. Safe to run on a live database: every statement is additive
-- and IF NOT EXISTS, so re-running it is harmless. Phone verification (migration-003) is left
-- exactly as it is — the phone number is still collected at signup, just no longer gated behind an
-- SMS code, so its columns and index stay useful.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-004-email-otp-postgres.sql
-- =================================================================================================

-- ------------------------------------------------------------------------------------------------
-- EmailOtp: one in-flight code per email address (lowercased before every read/write, so
-- Person@Example.com and person@example.com share the same row).
--
-- Shape mirrors PhoneOtp from migration-003 exactly, for the same reasons: the code itself is never
-- stored (otp_hash is a salted hash), attempts/sent_count/window_start are the same abuse brakes,
-- and verified_at is read by signup and then deleted so one code cannot register two accounts.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "EmailOtp" (
  "email"        TEXT PRIMARY KEY,
  "otp_hash"     TEXT NOT NULL,
  "expires_at"   BIGINT NOT NULL,        -- epoch ms; the code is dead after this
  "attempts"     SMALLINT NOT NULL DEFAULT 0,
  "last_sent_at" BIGINT NOT NULL,        -- epoch ms; drives the resend cooldown
  "sent_count"   SMALLINT NOT NULL DEFAULT 0,
  "window_start" BIGINT NOT NULL,        -- epoch ms; start of the per-day send window
  "verified_at"  BIGINT                  -- epoch ms; set on success, consumed by signup
);

CREATE INDEX IF NOT EXISTS "EmailOtp_expires_at_idx" ON "EmailOtp" ("expires_at");
