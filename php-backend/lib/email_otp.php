<?php
/**
 * Email verification codes.
 *
 * One in-flight code per email address, held in EmailOtp (see
 * sql/migration-004-email-otp-postgres.sql). Structurally identical to lib/otp.php's phone
 * verification — same hashing, same brakes, same verify-then-consume flow — just keyed by email
 * and delivered through lib/mailer.php (Brevo) instead of lib/sms.php (Fast2SMS).
 *
 * The code is stored as a salted hash and never in the clear, for the same reason passwords are:
 * a database dump, a stray log line or a backup file must not hand anyone a working code. It is
 * compared with hash_equals so the comparison is constant time.
 *
 * Three separate brakes, same as phone verification:
 *   cooldown    — seconds between two sends to one address
 *   daily cap   — sends per address per 24h
 *   attempts    — wrong guesses against one live code before it is burned
 * The per-IP limiter in lib/ratelimit.php (the shared 'otp' bucket) sits on top of these.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mailer.php';

/** Hash a code for storage. APP_SECRET is the salt, so hashes are useless in another deployment. */
function email_otp_hash($email, $code) {
    return hash_hmac('sha256', $email . '|' . $code, (string) cfg('APP_SECRET'));
}

/** A cryptographically random numeric code of the configured length. */
function email_otp_generate() {
    $len = (int) cfg('OTP_LENGTH', 6);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= (string) random_int(0, 9);
    }
    return $out;
}

/**
 * Issue and send a code.
 *
 * $email must already be trimmed and lowercased by the caller.
 * Returns ['ok'=>true,'retry_after'=>int] or ['ok'=>false,'error'=>string,'retry_after'=>int|null].
 */
function email_otp_issue($email) {
    $now       = now_ms();
    $cooldown  = (int) cfg('OTP_RESEND_COOLDOWN_SEC', 60) * 1000;
    $dailyCap  = (int) cfg('OTP_MAX_SENDS_PER_DAY', 8);
    $ttl       = (int) cfg('OTP_TTL_SECONDS', 300) * 1000;
    $dayMs     = 24 * 60 * 60 * 1000;

    $row = one('SELECT * FROM "EmailOtp" WHERE "email" = ?', [$email]);

    if ($row) {
        $lastSent = (int) $row['last_sent_at'];
        if ($now - $lastSent < $cooldown) {
            $wait = (int) ceil(($cooldown - ($now - $lastSent)) / 1000);
            return ['ok' => false, 'error' => 'Please wait before requesting another code.',
                    'retry_after' => $wait];
        }
        $windowStart = (int) $row['window_start'];
        $sentCount   = (int) $row['sent_count'];
        if ($now - $windowStart >= $dayMs) {
            $windowStart = $now;
            $sentCount   = 0;
        }
        if ($sentCount >= $dailyCap) {
            log_warn('email_otp: daily send cap reached', ['email' => $email, 'cap' => $dailyCap]);
            return ['ok' => false,
                    'error' => 'Too many codes requested for this address today. Please try again tomorrow.',
                    'retry_after' => null];
        }
    } else {
        $windowStart = $now;
        $sentCount   = 0;
    }

    $code = email_otp_generate();
    $hash = email_otp_hash($email, $code);

    // Send BEFORE writing, so a provider failure does not consume one of the user's daily sends or
    // start a cooldown they cannot use.
    $sent = mailer_send_otp($email, $code);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => $sent['error'] ?? 'Could not send the code.',
                'retry_after' => null];
    }

    q('INSERT INTO "EmailOtp" ("email","otp_hash","expires_at","attempts","last_sent_at","sent_count","window_start","verified_at")
       VALUES (?,?,?,0,?,?,?,NULL)
       ON CONFLICT ("email") DO UPDATE SET
         "otp_hash"     = EXCLUDED."otp_hash",
         "expires_at"   = EXCLUDED."expires_at",
         "attempts"     = 0,
         "last_sent_at" = EXCLUDED."last_sent_at",
         "sent_count"   = EXCLUDED."sent_count",
         "window_start" = EXCLUDED."window_start",
         "verified_at"  = NULL',
      [$email, $hash, $now + $ttl, $now, $sentCount + 1, $windowStart]);

    return ['ok' => true, 'retry_after' => (int) cfg('OTP_RESEND_COOLDOWN_SEC', 60)];
}

/**
 * Check a code and, on success, mark the address verified.
 *
 * The verification is recorded on the EmailOtp row rather than returned as a token, so signup can
 * confirm server-side that this address really was verified — a client that simply claims it was
 * gets nowhere.
 */
function email_otp_check($email, $code) {
    $now = now_ms();
    $row = one('SELECT * FROM "EmailOtp" WHERE "email" = ?', [$email]);
    if (!$row) {
        return ['ok' => false, 'error' => 'Request a code first.'];
    }
    if ($now > (int) $row['expires_at']) {
        return ['ok' => false, 'error' => 'That code has expired. Please request a new one.'];
    }

    $maxAttempts = (int) cfg('OTP_MAX_ATTEMPTS', 5);
    if ((int) $row['attempts'] >= $maxAttempts) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }

    $code = preg_replace('/\D+/', '', (string) $code);
    if (!hash_equals((string) $row['otp_hash'], email_otp_hash($email, $code))) {
        // Burn one attempt. Expiring the code entirely on the last attempt stops an attacker
        // grinding a live code, and costs an honest user only a resend.
        q('UPDATE "EmailOtp" SET "attempts" = "attempts" + 1 WHERE "email" = ?', [$email]);
        $left = $maxAttempts - ((int) $row['attempts'] + 1);
        return ['ok' => false,
                'error' => $left > 0
                    ? 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.'
                    : 'Incorrect code. Please request a new one.'];
    }

    q('UPDATE "EmailOtp" SET "verified_at" = ? WHERE "email" = ?', [$now, $email]);
    return ['ok' => true];
}

/**
 * Has this address been verified recently enough to finish a signup?
 *
 * Signup calls this instead of trusting anything the browser sends. The window is deliberately
 * short: a verification is meant to be spent immediately on the registration that prompted it, not
 * banked and reused hours later.
 */
function email_otp_is_verified($email, $withinSeconds = 900) {
    $row = one('SELECT "verified_at" FROM "EmailOtp" WHERE "email" = ?', [$email]);
    if (!$row || $row['verified_at'] === null) return false;
    return (now_ms() - (int) $row['verified_at']) <= $withinSeconds * 1000;
}

/** Consume the verification so one code cannot register two accounts. */
function email_otp_consume($email) {
    q('DELETE FROM "EmailOtp" WHERE "email" = ?', [$email]);
}
