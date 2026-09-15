<?php
/**
 * Email verification endpoints, used by the signup form. Mirrors routes/otp.php's phone flow.
 *
 * Both routes are public — the caller has no account yet — which is exactly why the brakes matter:
 * limiter('otp') caps sends per IP, and lib/email_otp.php caps them per address and caps guesses
 * against a live code.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/email_otp.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function bet1x_normalise_email($raw) {
    $email = strtolower(trim((string) $raw));
    if ($email === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) return null;
    return $email;
}

function register_email_otp_routes(Router $app) {

    // --- POST /api/email-otp/send -----------------------------------------------------------------
    $app->post('/api/email-otp/send', limiter('otp'), function (Req $req, Res $res) {
        if (!cfg('EMAIL_VERIFICATION_REQUIRED')) {
            $res->status(404)->json(['error' => 'Email verification is not enabled.']);
            return;
        }

        $email = bet1x_normalise_email($req->b('email'));
        if ($email === null) {
            $res->status(400)->json(['error' => 'Enter a valid email address.']);
            return;
        }

        try {
            // An already-registered email cannot start a new signup.
            $taken = one('SELECT "id" FROM "User" WHERE LOWER("email") = ? LIMIT 1', [$email]);
            if ($taken) {
                $res->status(409)->json([
                    'error' => 'This email cannot be used to register. If it is yours, please sign in instead.',
                ]);
                return;
            }

            $result = email_otp_issue($email);
            if (empty($result['ok'])) {
                $body = ['error' => $result['error']];
                if (!empty($result['retry_after'])) $body['retry_after'] = $result['retry_after'];
                $res->status(429)->json($body);
                return;
            }

            $res->json([
                'success'     => true,
                'message'     => 'Verification code sent.',
                'retry_after' => $result['retry_after'],
                'expires_in'  => (int) cfg('OTP_TTL_SECONDS', 300),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'email_otp');
        }
    });

    // --- POST /api/email-otp/verify ---------------------------------------------------------------
    //
    // Verifying does NOT create the account. It records that this address proved itself, and signup
    // then re-checks that server-side.
    $app->post('/api/email-otp/verify', limiter('otp'), function (Req $req, Res $res) {
        if (!cfg('EMAIL_VERIFICATION_REQUIRED')) {
            $res->status(404)->json(['error' => 'Email verification is not enabled.']);
            return;
        }

        $email = bet1x_normalise_email($req->b('email'));
        if ($email === null) {
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
            fail500($res, $err, 'email_otp');
        }
    });
}
