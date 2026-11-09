<?php
require_once __DIR__ . '/../lib/otp.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/ratelimit.php';

/** Legacy, unverified phone fields cannot be used to take over an account. */
function phone_reset_user($phone) {
    $users = all('SELECT * FROM "User" WHERE "phone" = ? AND "phone_verified" = 1 LIMIT 2', [$phone]);
    return count($users) === 1 ? $users[0] : null;
}

function register_phone_reset_routes(Router $app) {
    foreach (['send', 'verify', 'reset'] as $action) {
        $app->post('/api/auth/phone-reset/' . $action, limiter($action === 'reset' ? 'auth' : 'otp'), function (Req $req, Res $res) use ($action) {
            if (!cfg('PHONE_VERIFICATION_REQUIRED')) { $res->status(404)->json(['error' => 'Phone verification is not enabled.']); return; }
            $phone = sms_normalise_indian_mobile($req->b('phone'));
            if (!$phone) { $res->status(400)->json(['error' => 'Enter a valid 10-digit Indian mobile number.']); return; }
            try {
                if ($action === 'send') {
                    if (phone_reset_user($phone)) {
                        $result = otp_issue($phone, 'reset');
                        if (!$result['ok']) { $res->status($result['status'] ?? 429)->json($result); return; }
                    }
                    $res->json(['success' => true, 'message' => 'If this number is verified on an account, a code has been sent.']); return;
                }
                if ($action === 'verify') {
                    $result = otp_check($phone, (string) $req->b('otp'), 'reset');
                    if (!$result['ok']) { $res->status(400)->json($result); return; }
                    $res->json(['success' => true, 'verified' => true]); return;
                }
                $password = (string) $req->b('password');
                if (strlen($password) < 8 || strlen($password) > 128 || $password !== (string) $req->b('confirm_password')) {
                    $res->status(400)->json(['error' => 'Use a matching password and confirmation, 8–128 characters long.']); return;
                }
                $user = tx_locked('phone-otp', otp_storage_key($phone, 'reset'), function () use ($phone, $password) {
                    if (!otp_is_verified($phone, 900, 'reset')) return null;
                    $user = phone_reset_user($phone);
                    if (!$user) return null;
                    q('UPDATE "User" SET "password" = ? WHERE "id" = ?', [hash_password($password), $user['id']]);
                    otp_consume($phone, 'reset');
                    return $user;
                });
                if (!$user) { $res->status(400)->json(['error' => 'Please verify your registered mobile number first.']); return; }
                $res->json(['success' => true, 'token' => issue_token(['id' => (int) $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'role' => 'user']),
                    'user' => ['id' => (int) $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'wallet_balance' => (float) $user['wallet_balance']]]);
            } catch (Throwable $e) { fail500($res, $e, 'phone_reset'); }
        });
    }
}
