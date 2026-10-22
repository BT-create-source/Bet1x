-- =================================================================================================
-- Migration 018 — the Sub-Admin system.
--
-- A sub-admin sits between the Admin and the players: the Admin allocates coins to them (from an
-- UNLIMITED supply, so nothing is ever deducted from the Admin), they sell coins to their own users
-- for cash collected outside the site (UPI / cash), and they pay out their users' withdrawals.
--
--   SubAdmin             one row per sub-admin account; "coin_balance" is their tracked inventory
--   SubAdminUser         which sub-admin a player belongs to (one at most; no row = belongs to no one)
--   SubAdminAllocation   Admin -> sub-admin coin allocations (the coin side of a sale)
--   SubAdminDeposit      a user's coin purchase from their sub-admin (requested / unconfirmed / confirmed)
--   SubAdminWithdrawal   the sub-admin's handling of a player's withdrawal request (Withdrawal table)
--   SubAdminCash         the CASH ledger: payments to the Admin, cash from users, payouts to users
--   SubAdminLedger       the COIN ledger: every movement of coins into / out of a sub-admin's hands
--   SubAdminEvent        non-money history: account created, suspended, password reset, user assigned
--
-- Ledger rows are append-only: corrections are new reversal/adjustment rows that reference the
-- original. A trigger refuses DELETE on every money table and any UPDATE of a coin-ledger row.
--
-- lib/subadmin.php runs this file itself on first use (subadmin_ensure), so running it by hand is
-- optional. Additive and idempotent: safe to re-run; it touches no existing table.
-- =================================================================================================

CREATE TABLE IF NOT EXISTS "SubAdmin" (
  "id"                   SERIAL PRIMARY KEY,
  "public_id"            TEXT NOT NULL,
  "full_name"            TEXT NOT NULL,
  "username"             TEXT NOT NULL,
  "password_hash"        TEXT NOT NULL,
  "must_change_password" SMALLINT NOT NULL DEFAULT 1,
  "status"               TEXT NOT NULL DEFAULT 'active',
  "coin_balance"         DOUBLE PRECISION NOT NULL DEFAULT 0,
  "created_at"           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "created_by"           TEXT NOT NULL,
  "created_by_role"      TEXT NOT NULL,
  "status_changed_at"    TIMESTAMP(3),
  "status_changed_by"    TEXT,
  "status_reason"        TEXT,
  "password_changed_at"  TIMESTAMP(3),
  "last_login_at"        TIMESTAMP(3),
  CONSTRAINT "SubAdmin_public_id_key" UNIQUE ("public_id"),
  CONSTRAINT "SubAdmin_status_check" CHECK ("status" IN ('active', 'suspended')),
  CONSTRAINT "SubAdmin_coin_balance_check" CHECK ("coin_balance" >= 0)
);
CREATE UNIQUE INDEX IF NOT EXISTS "SubAdmin_username_lower_key" ON "SubAdmin" (LOWER("username"));

CREATE TABLE IF NOT EXISTS "SubAdminUser" (
  "user_id"          INTEGER PRIMARY KEY,
  "subadmin_id"      INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "assigned_at"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "assigned_by"      TEXT NOT NULL,
  "assigned_by_role" TEXT NOT NULL,
  "how"              TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "SubAdminUser_subadmin_idx" ON "SubAdminUser" ("subadmin_id");

CREATE TABLE IF NOT EXISTS "SubAdminAllocation" (
  "id"              TEXT PRIMARY KEY,
  "subadmin_id"     INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "coins"           DOUBLE PRECISION NOT NULL,
  "agreed_amount"   DOUBLE PRECISION NOT NULL,
  "credit_mode"     TEXT NOT NULL,
  "status"          TEXT NOT NULL,
  "notes"           TEXT,
  "idempotency_key" TEXT,
  "created_by"      TEXT NOT NULL,
  "created_by_role" TEXT NOT NULL,
  "created_at"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "credited_by"     TEXT,
  "credited_at"     TIMESTAMP(3),
  "cancelled_by"    TEXT,
  "cancelled_at"    TIMESTAMP(3),
  "cancel_reason"   TEXT,
  "reversed_by"     TEXT,
  "reversed_at"     TIMESTAMP(3),
  "reverse_reason"  TEXT,
  CONSTRAINT "SubAdminAllocation_idempotency_key" UNIQUE ("idempotency_key"),
  CONSTRAINT "SubAdminAllocation_coins_check" CHECK ("coins" > 0),
  CONSTRAINT "SubAdminAllocation_amount_check" CHECK ("agreed_amount" >= 0),
  CONSTRAINT "SubAdminAllocation_mode_check" CHECK ("credit_mode" IN ('after_payment', 'immediate')),
  CONSTRAINT "SubAdminAllocation_status_check" CHECK ("status" IN ('awaiting_payment', 'credited', 'cancelled', 'reversed'))
);
CREATE INDEX IF NOT EXISTS "SubAdminAllocation_subadmin_idx" ON "SubAdminAllocation" ("subadmin_id", "created_at");

CREATE TABLE IF NOT EXISTS "SubAdminDeposit" (
  "id"                  TEXT PRIMARY KEY,
  "subadmin_id"         INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "user_id"             INTEGER NOT NULL,
  "username"            TEXT NOT NULL,
  "coins"               DOUBLE PRECISION NOT NULL,
  "amount_inr"          DOUBLE PRECISION NOT NULL DEFAULT 0,
  "status"              TEXT NOT NULL,
  "method"              TEXT,
  "reference"           TEXT,
  "notes"               TEXT,
  "cash_id"             TEXT,
  "idempotency_key"     TEXT,
  "created_by"          TEXT NOT NULL,
  "created_by_role"     TEXT NOT NULL,
  "created_at"          TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "payment_recorded_at" TIMESTAMP(3),
  "confirmed_by"        TEXT,
  "confirmed_at"        TIMESTAMP(3),
  "cancelled_by"        TEXT,
  "cancelled_at"        TIMESTAMP(3),
  "cancel_reason"       TEXT,
  "reversed_by"         TEXT,
  "reversed_at"         TIMESTAMP(3),
  "reverse_reason"      TEXT,
  CONSTRAINT "SubAdminDeposit_idempotency_key" UNIQUE ("idempotency_key"),
  CONSTRAINT "SubAdminDeposit_coins_check" CHECK ("coins" > 0),
  CONSTRAINT "SubAdminDeposit_amount_check" CHECK ("amount_inr" >= 0),
  CONSTRAINT "SubAdminDeposit_status_check" CHECK ("status" IN ('Requested', 'Unconfirmed', 'Confirmed', 'Cancelled', 'Reversed'))
);
CREATE INDEX IF NOT EXISTS "SubAdminDeposit_subadmin_idx" ON "SubAdminDeposit" ("subadmin_id", "created_at");
CREATE INDEX IF NOT EXISTS "SubAdminDeposit_user_idx" ON "SubAdminDeposit" ("user_id");

CREATE TABLE IF NOT EXISTS "SubAdminWithdrawal" (
  "withdrawal_id"    TEXT PRIMARY KEY,
  "subadmin_id"      INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "user_id"          INTEGER NOT NULL,
  "username"         TEXT NOT NULL,
  "coins"            DOUBLE PRECISION NOT NULL,
  "rate"             DOUBLE PRECISION NOT NULL,
  "rupee_equivalent" DOUBLE PRECISION NOT NULL,
  "status"           TEXT NOT NULL,
  "coins_to"         TEXT,
  "approved_by"      TEXT,
  "approved_at"      TIMESTAMP(3),
  "paid_amount"      DOUBLE PRECISION,
  "payout_method"    TEXT,
  "payout_reference" TEXT,
  "paid_by"          TEXT,
  "paid_at"          TIMESTAMP(3),
  "cash_id"          TEXT,
  "rejected_by"      TEXT,
  "rejected_at"      TIMESTAMP(3),
  "reject_reason"    TEXT,
  "reversed_by"      TEXT,
  "reversed_at"      TIMESTAMP(3),
  "reverse_reason"   TEXT,
  "returned_by"      TEXT,
  "returned_at"      TIMESTAMP(3),
  "created_at"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "SubAdminWithdrawal_status_check" CHECK ("status" IN ('Pending', 'Approved', 'Paid', 'Rejected', 'Reversed', 'Returned'))
);
CREATE INDEX IF NOT EXISTS "SubAdminWithdrawal_subadmin_idx" ON "SubAdminWithdrawal" ("subadmin_id", "status");
CREATE INDEX IF NOT EXISTS "SubAdminWithdrawal_user_idx" ON "SubAdminWithdrawal" ("user_id");

CREATE TABLE IF NOT EXISTS "SubAdminCash" (
  "id"                TEXT PRIMARY KEY,
  "kind"              TEXT NOT NULL,
  "direction"         TEXT NOT NULL,
  "subadmin_id"       INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "user_id"           INTEGER,
  "username"          TEXT,
  "amount"            DOUBLE PRECISION NOT NULL,
  "method"            TEXT,
  "reference"         TEXT,
  "status"            TEXT NOT NULL,
  "allocation_id"     TEXT,
  "deposit_id"        TEXT,
  "withdrawal_id"     TEXT,
  "reverses"          TEXT,
  "notes"             TEXT,
  "created_by"        TEXT NOT NULL,
  "created_by_role"   TEXT NOT NULL,
  "created_at"        TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "confirmed_by"      TEXT,
  "confirmed_by_role" TEXT,
  "confirmed_at"      TIMESTAMP(3),
  "failed_by"         TEXT,
  "failed_at"         TIMESTAMP(3),
  "fail_reason"       TEXT,
  "reversed_by"       TEXT,
  "reversed_at"       TIMESTAMP(3),
  "reverse_reason"    TEXT,
  CONSTRAINT "SubAdminCash_amount_check" CHECK ("amount" >= 0),
  CONSTRAINT "SubAdminCash_kind_check" CHECK ("kind" IN ('allocation_payment', 'user_deposit', 'user_payout', 'reversal')),
  CONSTRAINT "SubAdminCash_direction_check" CHECK ("direction" IN ('subadmin_to_admin', 'admin_to_subadmin', 'user_to_subadmin', 'subadmin_to_user')),
  CONSTRAINT "SubAdminCash_status_check" CHECK ("status" IN ('Unconfirmed', 'Confirmed', 'Failed', 'Reversed'))
);
CREATE INDEX IF NOT EXISTS "SubAdminCash_subadmin_idx" ON "SubAdminCash" ("subadmin_id", "created_at");
CREATE INDEX IF NOT EXISTS "SubAdminCash_allocation_idx" ON "SubAdminCash" ("allocation_id");
CREATE INDEX IF NOT EXISTS "SubAdminCash_deposit_idx" ON "SubAdminCash" ("deposit_id");
CREATE INDEX IF NOT EXISTS "SubAdminCash_withdrawal_idx" ON "SubAdminCash" ("withdrawal_id");
-- One external payment reference (a UPI UTR, a bank ref) can back only one live cash record of a kind.
CREATE UNIQUE INDEX IF NOT EXISTS "SubAdminCash_reference_key" ON "SubAdminCash" ("kind", UPPER("reference"))
  WHERE "reference" IS NOT NULL AND "reference" <> '' AND "status" IN ('Unconfirmed', 'Confirmed');

CREATE TABLE IF NOT EXISTS "SubAdminLedger" (
  "id"              TEXT PRIMARY KEY,
  "type"            TEXT NOT NULL,
  "from_account"    TEXT NOT NULL,
  "to_account"      TEXT NOT NULL,
  "coins"           DOUBLE PRECISION NOT NULL,
  "rupees"          DOUBLE PRECISION,
  "subadmin_id"     INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "user_id"         INTEGER,
  "username"        TEXT,
  "ref"             TEXT,
  "cash_id"         TEXT,
  "reverses"        TEXT,
  "inventory_after" DOUBLE PRECISION,
  "actor"           TEXT NOT NULL,
  "actor_role"      TEXT NOT NULL,
  "notes"           TEXT,
  "created_at"      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT "SubAdminLedger_coins_check" CHECK ("coins" > 0)
);
CREATE INDEX IF NOT EXISTS "SubAdminLedger_subadmin_idx" ON "SubAdminLedger" ("subadmin_id", "created_at");
CREATE INDEX IF NOT EXISTS "SubAdminLedger_ref_idx" ON "SubAdminLedger" ("ref");
CREATE INDEX IF NOT EXISTS "SubAdminLedger_user_idx" ON "SubAdminLedger" ("user_id");

CREATE TABLE IF NOT EXISTS "SubAdminEvent" (
  "id"          SERIAL PRIMARY KEY,
  "subadmin_id" INTEGER NOT NULL REFERENCES "SubAdmin" ("id"),
  "event"       TEXT NOT NULL,
  "user_id"     INTEGER,
  "username"    TEXT,
  "actor"       TEXT NOT NULL,
  "actor_role"  TEXT NOT NULL,
  "details"     TEXT,
  "created_at"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);
CREATE INDEX IF NOT EXISTS "SubAdminEvent_subadmin_idx" ON "SubAdminEvent" ("subadmin_id", "created_at");

-- Financial records are never deleted, and a coin-ledger row is never edited. The only way past
-- this is the session setting bet1x.allow_ledger_delete = 'on', which exists for the test suite's
-- clean-up of its own throwaway accounts and is never set by the application.
CREATE OR REPLACE FUNCTION subadmin_protect_records() RETURNS trigger AS $$
BEGIN
  IF current_setting('bet1x.allow_ledger_delete', true) = 'on' THEN
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
  END IF;
  RAISE EXCEPTION 'Sub-admin financial records are permanent: % on % is not allowed (record a reversal instead)', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_subadminledger_protect ON "SubAdminLedger";
CREATE TRIGGER trg_subadminledger_protect BEFORE UPDATE OR DELETE ON "SubAdminLedger"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
DROP TRIGGER IF EXISTS trg_subadmincash_protect ON "SubAdminCash";
CREATE TRIGGER trg_subadmincash_protect BEFORE DELETE ON "SubAdminCash"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
DROP TRIGGER IF EXISTS trg_subadminallocation_protect ON "SubAdminAllocation";
CREATE TRIGGER trg_subadminallocation_protect BEFORE DELETE ON "SubAdminAllocation"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
DROP TRIGGER IF EXISTS trg_subadmindeposit_protect ON "SubAdminDeposit";
CREATE TRIGGER trg_subadmindeposit_protect BEFORE DELETE ON "SubAdminDeposit"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
DROP TRIGGER IF EXISTS trg_subadminwithdrawal_protect ON "SubAdminWithdrawal";
CREATE TRIGGER trg_subadminwithdrawal_protect BEFORE DELETE ON "SubAdminWithdrawal"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
DROP TRIGGER IF EXISTS trg_subadmin_protect ON "SubAdmin";
CREATE TRIGGER trg_subadmin_protect BEFORE DELETE ON "SubAdmin"
  FOR EACH ROW EXECUTE FUNCTION subadmin_protect_records();
