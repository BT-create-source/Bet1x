<?php
/**
 * Forgot-password: verify ownership of the account's registered email via OTP, then set a new
 * password. Reuses the exact same email-OTP primitives signup uses (lib/email_otp.php,
 * lib/mailer.php) — same hashing, same cooldown/daily-cap/attempt brakes, same
 * issue/verify/is-verified/consume shape — just applied to an existing account instead of a new
 * one. Available unconditionally: unlike signup's email verification, this does not depend on
 * EMAIL_VERIFICATION_REQUIRED — a password-recovery path should exist regardless of whether new
 * signups are gated on email.
 *
 * Three steps, three requests, mirroring the signup flow's own send/verify split:
 *   1. POST /api/auth/forgot-password/send    — {email}
 *   2. POST /api/auth/forgot-password/verify  — {email, otp}
 *   3. POST /api/auth/reset-password          — {email, password, confirm_password}
 *
 * Step 3 re-checks email_otp_is_verified() itself — exactly like signup re-checking
 * otp_is_verified()/email_otp_is_verified() server-side rather than trusting a client flag — so a
 * client that skips step 2 or replays an old verification gets a rejected reset, not a changed
 * password.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/email_otp.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function register_password_reset_routes(Router $app) {

    // --- POST /api/auth/forgot-password/send ----------------------------------------------------
    //
    // Deliberately answers the SAME way whether or not the email has an account: an attacker
    // walking through addresses to see which ones are registered gets nothing to distinguish them
    // by. A genuine account gets a real code; anything else gets an identical response and no email.
    $app->post('/api/auth/forgot-password/send', limiter('otp'), function (Req $req, Res $res) {
        $email = strtolower(trim((string) ($req->b('email') ?? '')));
        if ($email === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $res->status(400)->json(['error' => 'Enter a valid email address.']);
            return;
        }

        try {
            $user = find_user_by_email($email);
            if ($user) {
                $result = email_otp_issue($email);
                if (empty($result['ok'])) {
                    $body = ['error' => $result['error']];
                    if (!empty($result['retry_after'])) $body['retry_after'] = $result['retry_after'];
                    $res->status(429)->json($body);
                    return;
                }
            }

            $res->json([
                'success' => true,
                'message' => 'If that email is registered, a verification code has been sent.',
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'password_reset');
        }
    });

    // --- POST /api/auth/forgot-password/verify --------------------------------------------------
    //
    // Records the verification server-side (email_otp_check), the same as signup's
    // /api/email-otp/verify. Does not change the password itself — that is /api/auth/reset-password,
    // which re-checks this independently.
    $app->post('/api/auth/forgot-password/verify', limiter('otp'), function (Req $req, Res $res) {
        $email = strtolower(trim((string) ($req->b('email') ?? '')));
        if ($email === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $res->status(400)->json(['error' => 'Enter a valid email address.']);
            return;
        }
        $code = (string) ($req->b('otp') ?? $req->b('code') ?? '');
        if ($code === '') {
            $res->status(400)->json(['error' => 'Enter the code that was sent to your email.']);
            return;
        }

        try {
            $result = email_otp_check($email, $code);
            if (empty($result['ok'])) {
                $res->status(400)->json(['error' => $result['error']]);
                return;
            }
            $res->json(['success' => true, 'verified' => true]);
        } catch (Throwable $err) {
            fail500($res, $err, 'password_reset');
        }
    });

    // --- POST /api/auth/reset-password -----------------------------------------------------------
    $app->post('/api/auth/reset-password', limiter('auth'), function (Req $req, Res $res) {
        $email = strtolower(trim((string) ($req->b('email') ?? '')));
        $password = (string) ($req->b('password') ?? '');
        $confirmPassword = (string) ($req->b('confirm_password') ?? $password);

        if ($email === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $res->status(400)->json(['error' => 'Enter a valid email address.']);
            return;
        }
        if ($password === '' || strlen($password) < 8) {
            $res->status(400)->json(['error' => 'Password must be at least 8 characters.']);
            return;
        }
        if (strlen($password) > 128) {
            $res->status(400)->json(['error' => 'Password must be 128 characters or fewer.']);
            return;
        }
        if ($password !== $confirmPassword) {
            $res->status(400)->json(['error' => 'Passwords do not match.']);
            return;
        }

        try {
            // Never trust a client-supplied "verified: true" — the only thing that counts is the
            // server's own record of a recent, successful /api/auth/forgot-password/verify.
            if (!email_otp_is_verified($email)) {
                $res->status(400)->json(['error' => 'Please verify your email first.']);
                return;
            }

            $user = find_user_by_email($email);
            if (!$user) {
                // The email passed verification (a real code was checked against a real row in
                // EmailOtp), so this branch means the account was deleted in the meantime, not a
                // guessing attack — safe to say plainly.
                $res->status(404)->json(['error' => 'No account found for that email.']);
                return;
            }

            q('UPDATE "User" SET "password" = ? WHERE "id" = ?', [hash_password($password), (int) $user['id']]);
            // One code should not be replayable into a second reset.
            email_otp_consume($email);

            $token = issue_token([
                'id' => (int) $user['id'], 'username' => $user['username'],
                'email' => $user['email'], 'role' => 'user',
            ]);
            $res->json([
                'success' => true,
                'token'   => $token,
                'user'    => [
                    'id'             => (int) $user['id'],
                    'username'       => $user['username'],
                    'email'          => $user['email'],
                    'wallet_balance' => (float) $user['wallet_balance'],
                ],
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'password_reset');
        }
    });
}
