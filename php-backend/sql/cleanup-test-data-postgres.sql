-- =================================================================================================
-- Purge all test/dummy data and return the platform to a fresh, zeroed state.  PostgreSQL.
--
--   *** THIS IS IRREVERSIBLE.  TAKE A BACKUP FIRST AND VERIFY IT RESTORES. ***
--
--   php php-backend/cron/backup.php          -- or cPanel > Backup > Download a Full Account Backup
--
-- It deletes EVERY player account and everything attached to one: wallets, transactions, deposits,
-- withdrawals, bets, game history, chat, referral commissions and the revenue figures the admin
-- panel reports. If a single real player has registered or deposited on the live site, running this
-- destroys their balance and their record of it. Confirm the site has only ever carried test
-- accounts before you run it.
--
-- WHAT IS DELIBERATELY KEPT (configuration, not data — deleting these would break the site):
--   * the "Admin" house account. Teen Patti's house-win paths seat a player literally named Admin
--     and look it up in "User"; with the row missing the seat is ejected and the house silently
--     wins nothing, with no error anywhere. Its balance is reset to the seeded 5000.
--   * the six TeenPattiRoom rows and their TeenPattiSeat rows — the lobby itself. Their runtime
--     state (status, pot, cards, occupants) IS cleared, so every room comes back empty and waiting.
--   * GameState keys that hold operator CONFIGURATION: payment_config (your UPI details),
--     bot_takeover_* (bot on/off and percentages), mines_rig_config and tp_room_bot_config.
--     Runtime/accumulated keys are cleared — see the GameState step below.
--
-- Run as one transaction: it either all applies or none of it does.
-- =================================================================================================

BEGIN;

-- 1. Everything that hangs off a player account. Child rows first so foreign keys never block.
DELETE FROM "ReferralCommission";
DELETE FROM "GameBet";
DELETE FROM "MinesSession";
DELETE FROM "Transaction";
DELETE FROM "Deposit";
DELETE FROM "Withdrawal";
DELETE FROM "PaymentLog";
DELETE FROM "ChatMessage";
DELETE FROM "PhoneOtp";
DELETE FROM "EmailOtp";

-- 2. Game history and operational logs — these feed the admin panel's stats/revenue figures.
DELETE FROM "RecentResult";
DELETE FROM "RigAudit";

-- 3. Transient runtime tables. Safe to empty at any time; they rebuild themselves.
DELETE FROM "LivePresence";
DELETE FROM "RateLimit";

-- 4. Player accounts. The Admin house account is the one row that must survive.
DELETE FROM "User" WHERE LOWER("username") <> 'admin';

-- 5. Put the house account back to its seeded state.
UPDATE "User"
   SET "wallet_balance" = 5000,
       "referral_balance" = 0
 WHERE LOWER("username") = 'admin';

-- 6. Clear accumulated/runtime GameState, keeping operator configuration.
--    Removed here: mines_total_trap_profit (an accumulated revenue figure), the per-game rig
--    cycle memory and targeting sets, per-round colour overrides, and the cron/backup heartbeats.
DELETE FROM "GameState"
 WHERE "key" = 'mines_total_trap_profit'
    OR "key" LIKE 'bot_rig_bag_%'
    OR "key" LIKE 'bot_targeted_%'
    OR "key" LIKE 'color_guess_overrides_%'
    OR "key" IN ('tp_traffic_next_at', 'cron_last_run', 'backup_last_run');

-- 7. Return every Teen Patti room to an empty, waiting lobby (rooms themselves are kept).
UPDATE "TeenPattiSeat"
   SET "username" = NULL, "is_bot" = 0, "cards" = NULL, "folded" = 0,
       "balance" = 0, "last_seen_at" = NULL;

UPDATE "TeenPattiRoom"
   SET "status" = 'waiting', "pot" = 0, "current_stake" = 0, "round" = 0,
       "turn_seat" = NULL, "turn_index" = 0, "turn_start" = NULL, "winner_seat" = NULL,
       "admin_rig" = NULL, "deck_state" = NULL, "log" = NULL,
       "bot_fill_due_at" = NULL, "bot_turn_due_at" = NULL, "round_end_due_at" = NULL;

COMMIT;

-- Verification — every count below should be 0, and users should be exactly 1 (Admin).
-- SELECT
--   (SELECT COUNT(*) FROM "User")               AS users_should_be_1,
--   (SELECT COUNT(*) FROM "Transaction")        AS transactions,
--   (SELECT COUNT(*) FROM "Deposit")            AS deposits,
--   (SELECT COUNT(*) FROM "Withdrawal")         AS withdrawals,
--   (SELECT COUNT(*) FROM "GameBet")            AS bets,
--   (SELECT COUNT(*) FROM "RecentResult")       AS results,
--   (SELECT COUNT(*) FROM "RigAudit")           AS rig_audit,
--   (SELECT COUNT(*) FROM "ReferralCommission") AS referrals;
