<?php
/**
 * Referral system: codes, signup attribution, and lifetime deposit commission.
 *
 * Design:
 *   - User.referral_code: a short code every player can share. Generated LAZILY (first time the
 *     referral dashboard is opened), not backfilled in bulk, so migration-005 stays a plain
 *     additive ALTER TABLE with no data migration step.
 *   - User.referred_by: the inviter's username, set once at signup from an optional referral_code
 *     in the signup request (see routes/gamesync.php's legacy signup handler) and never changed
 *     after that.
 *   - Commission triggers ONLY from an approved cashier deposit (routes/legacy.php's
 *     approve_deposit action) — never from game wins, admin wallet adjustments, or the signup
 *     bonus. Each approved deposit gets its own commission row at a rate randomised between 1.50%
 *     and 1.80% for that one deposit (not a rate fixed per inviter or per referred player).
 *   - Commission accrues in User.referral_balance, a wallet kept separate from wallet_balance. It
 *     only reaches wallet_balance when the inviter calls referral_claim() (routes/referral.php),
 *     which is the one place that writes a "Transaction" row for it — referral_balance itself is
 *     not part of that ledger until claimed.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logger.php';

/** A short, shareable code: 3 letters + 5 digits, e.g. "BET48213". */
function referral_generate_code() {
    $letters = '';
    for ($i = 0; $i < 3; $i++) $letters .= chr(random_int(65, 90));
    $digits = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    return $letters . $digits;
}

/** This user's referral code, generating and persisting one on first use. */
function referral_get_or_create_code($userId, $currentCode) {
    if (is_string($currentCode) && $currentCode !== '') return $currentCode;

    for ($i = 0; $i < 20; $i++) {
        $candidate = referral_generate_code();
        $exists = one('SELECT "id" FROM "User" WHERE "referral_code" = ? LIMIT 1', [$candidate]);
        if (!$exists) {
            q('UPDATE "User" SET "referral_code" = ? WHERE "id" = ?', [$candidate, (int) $userId]);
            return $candidate;
        }
    }
    // A collision 20 times running on a 26^3 * 10^5 code space is not realistically reachable;
    // fail loudly rather than silently handing out (or overwriting another account's) code.
    throw new RuntimeException('Could not generate a unique referral code.');
}

/**
 * Resolve a referral code — as typed into signup, or carried in a ?ref= link — to the inviter's
 * username, or null if it does not match any account.
 */
function referral_resolve_inviter($code) {
    $code = strtoupper(trim((string) $code));
    if ($code === '') return null;
    $row = one('SELECT "username" FROM "User" WHERE "referral_code" = ? LIMIT 1', [$code]);
    return $row ? $row['username'] : null;
}

/**
 * Credit the inviter's referral wallet for one approved deposit. A no-op if the depositor has no
 * inviter on record. Never throws into the caller: a referral-ledger failure must not undo an
 * already-approved deposit, so failures are logged loudly instead of propagated.
 */
function referral_credit_commission($referredUsername, $depositAmount, $sourceDepositId) {
    try {
        $referred = find_user_ci($referredUsername);
        if (!$referred || empty($referred['referred_by'])) return;

        $inviter = find_user_ci($referred['referred_by']);
        if (!$inviter) return;

        // Randomised per-deposit rate, 1.50%-1.80% in steps of 0.01%.
        $rate = (150 + random_int(0, 30)) / 10000;
        $commission = round(((float) $depositAmount) * $rate, 2);
        if ($commission <= 0) return;

        q('INSERT INTO "ReferralCommission"
             ("id","inviter_username","referred_username","source_deposit_id","deposit_amount","commission_rate","commission_amount","created_at")
           VALUES (?,?,?,?,?,?,?,?)',
          [new_record_id('REF'), $inviter['username'], $referred['username'], $sourceDepositId,
           (float) $depositAmount, $rate, $commission, ms_to_sql()]);

        q('UPDATE "User" SET "referral_balance" = "referral_balance" + ? WHERE "id" = ?',
          [$commission, (int) $inviter['id']]);

        log_info('referral: commission credited', [
            'inviter' => $inviter['username'], 'referred' => $referred['username'],
            'deposit' => (float) $depositAmount, 'rate' => $rate, 'commission' => $commission,
        ]);
    } catch (Throwable $e) {
        log_error('referral: commission credit failed', [
            'referred' => $referredUsername, 'deposit_id' => $sourceDepositId, 'message' => $e->getMessage(),
        ]);
    }
}
