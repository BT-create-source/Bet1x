<?php
/**
 * Withdrawal and registration abuse controls.
 *
 * =================================================================================================
 * WHY THIS EXISTS
 * =================================================================================================
 * Registration on this platform is unverified: no email confirmation, no phone OTP, no KYC. That
 * makes two things trivially exploitable, and manual operator approval was the only thing standing
 * in the way of either:
 *
 *   1. BONUS FARMING. With a non-zero SIGNUP_BONUS, one person registers N throwaway accounts,
 *      collects N x bonus in withdrawable credit, and cashes it out. Nothing linked the accounts and
 *      nothing stopped the payout.
 *
 *   2. UNBOUNDED CASHOUT VELOCITY. A compromised or fraudulent account could file withdrawal after
 *      withdrawal with no daily ceiling, relying on an operator to notice the pattern by eye across
 *      a queue that shows no history.
 *
 * These checks run BEFORE any wallet money is held, so a rejected request never debits the player.
 * Each one is individually switchable, because a control an operator cannot turn off during an
 * incident is a control they will end up bypassing in the database instead.
 *
 * Everything here is advisory-by-configuration and fail-closed-by-default in production: the
 * generated production .env turns all three on. Development leaves them relaxed so the test suites
 * are not rewritten around them.
 * =================================================================================================
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * The caller's IP, honouring X-Forwarded-For only when TRUST_PROXY is on.
 *
 * Reading XFF unconditionally would make every one of these controls trivially bypassable — the
 * header is attacker-supplied, so a farmer would just rotate it. It is consulted only when the
 * operator has declared that the app genuinely sits behind a proxy that rewrites it.
 */
function risk_client_ip() {
    if (js_truthy(cfg('TRUST_PROXY'))) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            // Left-most entry is the original client; the rest are proxies that appended themselves.
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/**
 * How many accounts have already been registered from this IP within the window.
 *
 * Returns 0 when the IP is unknown or the column is missing (i.e. migration-002 has not been run),
 * so an un-migrated database degrades to the old permissive behaviour rather than blocking every
 * signup on the site.
 */
function risk_signups_from_ip($ip, $windowHours = 24) {
    if (!$ip) return 0;
    try {
        $row = one(
            'SELECT COUNT(*) AS c FROM `User`
             WHERE `signup_ip` = ? AND `created_at` >= (NOW() - INTERVAL ? HOUR)',
            [$ip, (int)$windowHours]
        );
        return $row ? (int)$row['c'] : 0;
    } catch (Throwable $e) {
        log_debug('risk_signups_from_ip unavailable: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Gate a registration attempt.
 *
 * Returns null to allow, or a human-readable refusal string. The message deliberately does not say
 * "too many accounts from your IP" — telling a farmer exactly which signal tripped is free
 * intelligence for them. Operators get the real reason in the log.
 */
function risk_check_signup($ip) {
    $max = (int) cfg('SIGNUP_MAX_PER_IP_PER_DAY', 0);
    if ($max <= 0) return null;   // control disabled

    $count = risk_signups_from_ip($ip, 24);
    if ($count >= $max) {
        log_warn('signup blocked by per-IP limit', ['ip' => $ip, 'count' => $count, 'max' => $max]);
        return 'We could not complete your registration right now. Please contact support.';
    }
    return null;
}

/**
 * Total withdrawal amount and request count for a user inside a rolling window.
 *
 * Counts Pending as well as Completed on purpose: a queue full of unapproved requests is exactly
 * the pattern these limits exist to catch, and excluding Pending would let someone file twenty
 * requests before the first is actioned.
 */
function risk_withdrawal_totals($username, $windowHours = 24) {
    try {
        $row = one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(`amount`), 0) AS total
             FROM `Withdrawal`
             WHERE LOWER(`username`) = LOWER(?)
               AND `status` IN ('Pending', 'Completed')
               AND `created_at` >= (NOW() - INTERVAL ? HOUR)",
            [$username, (int)$windowHours]
        );
        return [
            'count' => $row ? (int)$row['c'] : 0,
            'total' => $row ? (float)$row['total'] : 0.0,
        ];
    } catch (Throwable $e) {
        log_debug('risk_withdrawal_totals unavailable: ' . $e->getMessage());
        return ['count' => 0, 'total' => 0.0];
    }
}

/** Has this account ever had a deposit actually approved and credited? */
function risk_has_completed_deposit($username) {
    try {
        $row = one(
            "SELECT COUNT(*) AS c FROM `Deposit`
             WHERE LOWER(`username`) = LOWER(?) AND `status` = 'Completed'",
            [$username]
        );
        return $row && (int)$row['c'] > 0;
    } catch (Throwable $e) {
        log_debug('risk_has_completed_deposit unavailable: ' . $e->getMessage());
        // Fail OPEN on an infrastructure error. A database hiccup must not freeze withdrawals for
        // legitimate players; the operator still approves every payout by hand.
        return true;
    }
}

/**
 * Gate a withdrawal request. Call BEFORE holding any funds.
 *
 * Returns null to allow, or a refusal string safe to show the player. Unlike the signup message,
 * these are specific: the player can act on every one of them, and vagueness here just generates
 * support tickets.
 */
function risk_check_withdrawal($username, $amount) {
    // --- 1. Must have funded the account before taking money out -------------------------------
    // This is what closes signup-bonus farming: bonus credit is not withdrawable on its own, so an
    // account that never paid in has nothing to cash out no matter how many are registered.
    if (js_truthy(cfg('WITHDRAWAL_REQUIRE_DEPOSIT', false))) {
        if (!risk_has_completed_deposit($username)) {
            log_info('withdrawal blocked: no completed deposit', ['username' => $username]);
            return 'You need at least one approved deposit on your account before you can withdraw.';
        }
    }

    $totals = risk_withdrawal_totals($username, 24);

    // --- 2. Daily request-count ceiling --------------------------------------------------------
    $maxCount = (int) cfg('WITHDRAWAL_DAILY_COUNT_MAX', 0);
    if ($maxCount > 0 && $totals['count'] >= $maxCount) {
        log_info('withdrawal blocked: daily count', ['username' => $username, 'count' => $totals['count']]);
        return 'You have reached the maximum number of withdrawal requests for today. Please try again tomorrow.';
    }

    // --- 3. Daily amount ceiling ---------------------------------------------------------------
    $maxAmount = (float) cfg('WITHDRAWAL_DAILY_MAX', 0);
    if ($maxAmount > 0 && ($totals['total'] + (float)$amount) > $maxAmount) {
        $remaining = max(0, $maxAmount - $totals['total']);
        log_info('withdrawal blocked: daily amount', [
            'username' => $username, 'already' => $totals['total'], 'requested' => $amount,
        ]);
        return 'This request exceeds your daily withdrawal limit of INR ' . js_num_str($maxAmount)
             . '. You can still withdraw INR ' . js_num_str($remaining) . ' today.';
    }

    return null;
}
