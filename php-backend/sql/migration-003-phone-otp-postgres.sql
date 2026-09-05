-- =================================================================================================
-- Migration 003 — phone number + OTP verification at signup (PostgreSQL)
--
-- Adds what phone verification needs and nothing else. Safe to run on a live database:
--   * Every statement is additive and IF NOT EXISTS, so re-running it is harmless.
--   * The new User columns are nullable / defaulted, so existing rows are untouched and no
--     existing account is retroactively locked out.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-003-phone-otp-postgres.sql
-- =================================================================================================

-- ------------------------------------------------------------------------------------------------
-- User: the verified phone number.
--
-- NULL means "no phone on this account", which is every account that existed before this migration.
-- phone_verified is SMALLINT rather than BOOLEAN for the same reason as every other flag column in
-- this schema — see the type-choices note at the top of schema-postgres.sql. The PHP reads these
-- with (int) casts and compares them against 0/1 in raw SQL.
-- ------------------------------------------------------------------------------------------------
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "phone" TEXT;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "phone_verified" SMALLINT NOT NULL DEFAULT 0;

-- One account per phone number.
--
-- Deliberately a PARTIAL unique index: it constrains only rows where a phone is actually set, so the
-- pre-existing accounts (all of which have NULL) do not collide with each other. Without the WHERE
-- clause a plain unique index would still allow many NULLs in Postgres, but being explicit says the
-- intent out loud — one real number cannot register twice, which is the multi-account brake this
-- whole feature exists to provide.
CREATE UNIQUE INDEX IF NOT EXISTS "User_phone_key" ON "User" ("phone") WHERE "phone" IS NOT NULL;

-- ------------------------------------------------------------------------------------------------
-- PhoneOtp: one in-flight code per number.
--
-- The code itself is NEVER stored. otp_hash is a salted hash, so a leaked database dump does not
-- hand an attacker the live codes — the same reason the password column holds a bcrypt hash.
--
-- attempts and sent_count are the abuse brakes, and they matter more here than on a normal form:
-- every send costs real money at the SMS provider, so an unmetered endpoint is a way to bill the
-- operator until the balance is gone. attempts caps guessing of a live code; sent_count caps how
-- many codes one number can request in a day.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "PhoneOtp" (
  "phone"        TEXT PRIMARY KEY,
  "otp_hash"     TEXT NOT NULL,
  "expires_at"   BIGINT NOT NULL,        -- epoch ms; the code is dead after this
  "attempts"     SMALLINT NOT NULL DEFAULT 0,
  "last_sent_at" BIGINT NOT NULL,        -- epoch ms; drives the resend cooldown
  "sent_count"   SMALLINT NOT NULL DEFAULT 0,
  "window_start" BIGINT NOT NULL,        -- epoch ms; start of the per-day send window
  "verified_at"  BIGINT                  -- epoch ms; set on success, consumed by signup
);

CREATE INDEX IF NOT EXISTS "PhoneOtp_expires_at_idx" ON "PhoneOtp" ("expires_at");
