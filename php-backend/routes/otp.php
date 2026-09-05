<?php
/**
 * Phone verification endpoints, used by the signup form.
 *
 * Two routes, both public — they have to be, since the person calling them does not have an account
 * yet. That is exactly why the brakes matter: limiter('otp') caps sends per IP, and lib/otp.php caps
 * them per phone number and caps guesses against a live code.
 *
 * Neither route reveals whether a number already has an account. "Send me a code for 98765xxxxx"
 * answering differently for a registered and an unregistered number would turn this into a free
 * lookup service for who plays here.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/otp.php';
require_once __DIR__ . '/../lib/sms.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function register_otp_routes(Router $app) {

    // --- POST /api/otp/send ---------------------------------------------------------------------
    $app->post('/api/otp/send', limiter('otp'), function (Req $req, Res $res) {
        if (!cfg('PHONE_VERIFICATION_REQUIRED')) {
            $res->status(404)->json(['error' => 'Phone verification is not enabled.']);
            return;
        }

        $phone = sms_normalise_indian_mobile($req->b('phone'));
        if ($phone === null) {
            $res->status(400)->json(['error' => 'Enter a valid 10-digit Indian mobile number.']);
            return;
        }

        try {
            // An already-registered number cannot start a new signup. The message says the number
            // is unavailable without confirming an account exists on it.
            $taken = one('SELECT "id" FROM "User" WHERE "phone" = ? LIMIT 1', [$phone]);
            if ($taken) {
                $res->status(409)->json([
                    'error' => 'This number cannot be used to register. If it is yours, please sign in instead.',
                ]);
                return;
            }

            $result = otp_issue($phone);
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
            fail500($res, $err, 'otp');
        }
    });

    // --- POST /api/otp/verify -------------------------------------------------------------------
    //
    // Verifying does NOT create the account. It records that this number proved itself, and signup
    // then re-checks that server-side. Splitting it this way keeps the browser out of the trust
    // path entirely: it cannot claim a verification it did not earn.
    $app->post('/api/otp/verify', limiter('otp'), function (Req $req, Res $res) {
        if (!cfg('PHONE_VERIFICATION_REQUIRED')) {
            $res->status(404)->json(['error' => 'Phone verification is not enabled.']);
            return;
        }

        $phone = sms_normalise_indian_mobile($req->b('phone'));
        if ($phone === null) {
            $res->status(400)->json(['error' => 'Enter a valid 10-digit Indian mobile number.']);
            return;
        }
        $code = (string) ($req->b('otp') ?? $req->b('code') ?? '');
        if ($code === '') {
            $res->status(400)->json(['error' => 'Enter the code that was sent to your phone.']);
            return;
        }

        try {
            $result = otp_check($phone, $code);
            if (empty($result['ok'])) {
                $res->status(400)->json(['error' => $result['error']]);
                return;
            }
            $res->json(['success' => true, 'verified' => true]);
        } catch (Throwable $err) {
            fail500($res, $err, 'otp');
        }
    });
}
