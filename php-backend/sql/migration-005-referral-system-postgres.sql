-- =================================================================================================
-- Migration 005 — referral system (PostgreSQL)
--
-- Adds what the referral program needs. Safe to run on a live database: every statement is
-- additive and IF NOT EXISTS, so re-running it is harmless. Existing accounts get NULL
-- referral_code/referred_by and 0 referral_balance — none of them are retroactively enrolled in
-- anything, and a referral_code is generated for a player the first time they open their referral
-- dashboard (see referral_get_or_create_code() in lib/referral.php), not by this migration.
--
-- Apply with:
--     psql -U DBUSER -d DBNAME -f php-backend/sql/migration-005-referral-system-postgres.sql
-- =================================================================================================

-- ------------------------------------------------------------------------------------------------
-- User: referral identity + the separate "invite bonus" wallet.
--
-- referral_balance is deliberately NOT part of wallet_balance — commission accrues here and only
-- merges into wallet_balance when the player calls POST /api/referral/claim.
-- ------------------------------------------------------------------------------------------------
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "referral_code" TEXT;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "referred_by" TEXT;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "referral_balance" DOUBLE PRECISION NOT NULL DEFAULT 0;

-- One code per account. Partial (only rows where a code has actually been generated), same
-- reasoning as the phone/email partial unique indexes in earlier migrations.
CREATE UNIQUE INDEX IF NOT EXISTS "User_referral_code_key" ON "User" ("referral_code") WHERE "referral_code" IS NOT NULL;
CREATE INDEX IF NOT EXISTS "User_referred_by_idx" ON "User" ("referred_by");

-- ------------------------------------------------------------------------------------------------
-- ReferralCommission: one row per approved deposit that earned the inviter a commission.
--
-- This is the audit trail — it is what the referral dashboard sums to show "earned from this
-- player", and what claim_at marks off when the inviter cashes out their referral_balance. It is
-- deliberately separate from the "Transaction" ledger: referral_balance is not spendable wallet
-- money until claimed, so it has no place in the wallet_balance transaction history until then.
-- ------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS "ReferralCommission" (
  "id"                 TEXT PRIMARY KEY,
  "inviter_username"   TEXT NOT NULL,
  "referred_username"  TEXT NOT NULL,
  "source_deposit_id"  TEXT NOT NULL,
  "deposit_amount"     DOUBLE PRECISION NOT NULL,
  "commission_rate"    DOUBLE PRECISION NOT NULL,   -- e.g. 0.017 for 1.70%
  "commission_amount"  DOUBLE PRECISION NOT NULL,
  "created_at"         TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "claimed_at"         TIMESTAMP(3)                 -- NULL until the inviter claims it
);
CREATE INDEX IF NOT EXISTS "ReferralCommission_inviter_idx"  ON "ReferralCommission" ("inviter_username");
CREATE INDEX IF NOT EXISTS "ReferralCommission_referred_idx" ON "ReferralCommission" ("referred_username");
