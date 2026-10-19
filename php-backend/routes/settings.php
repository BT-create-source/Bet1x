<?php
/**
 * Account settings: change username, update email, change password. Three separate endpoints so
 * the settings panel can save one field at a time rather than one combined form.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../games/aviator.php';
require_once __DIR__ . '/../games/color.php';

/**
 * Why this player cannot change username right now, or null if they can.
 *
 * Deposits, withdrawals, game sessions, seats and the Aviator/Colour bets are all filed under the
 * player's NAME. Renamed mid-flight, an approved deposit or a rejected withdrawal's refund was
 * credited to the old name (to nobody, or to whoever registered that name next), an open game
 * could no longer be cashed out, and a pending bet could no longer be paid. So a rename waits until
 * nothing is in play.
 */
function rename_blocked_reason($username) {
    $checks = [
        ['SELECT COUNT(*) FROM "Deposit" WHERE LOWER("username") = LOWER(?) AND "status" = \'Pending\'',
         'You have a deposit waiting for approval.'],
        ['SELECT COUNT(*) FROM "Withdrawal" WHERE LOWER("username") = LOWER(?) AND "status" = \'Pending\'',
         'You have a withdrawal waiting for approval.'],
        ['SELECT COUNT(*) FROM "MinesSession" WHERE LOWER("username") = LOWER(?) AND "status" IN (\'active\',\'starting\')',
         'You have a Mines game in progress.'],
        ['SELECT COUNT(*) FROM "ChickenRoadSession" WHERE LOWER("username") = LOWER(?) AND "status" IN (\'active\',\'starting\')',
         'You have a Chicken Road game in progress.'],
        ['SELECT COUNT(*) FROM "TowerRushSession" WHERE LOWER("username") = LOWER(?) AND "status" IN (\'active\',\'starting\')',
         'You have a Tower Rush game in progress.'],
        ['SELECT COUNT(*) FROM "AstronautBet" WHERE LOWER("username") = LOWER(?) AND "status" = \'pending\'',
         'You have an Astronaut bet in play.'],
        ['SELECT COUNT(*) FROM "TeenPattiSeat" WHERE LOWER("username") = LOWER(?)',
         'You are seated at a Teen Patti table.'],
    ];
    foreach ($checks as [$sql, $reason]) {
        try {
            if ((int) scalar($sql, [$username], 0) > 0) return $reason;
        } catch (Throwable $e) { /* table not present on this deployment: nothing in play there */ }
    }
    try {
        foreach (aviator_load(false)['bets'] ?? [] as $b) {
            if (($b['status'] ?? '') === 'pending' && strtolower((string) ($b['username'] ?? '')) === strtolower($username)) {
                return 'You have an Aviator bet in play.';
            }
        }
    } catch (Throwable $e) { /* no Aviator state yet */ }
    try {
        $st = load_color_state();
        foreach (color_rooms() as $room) {
            $last = (string) ($st[$room]['last_settled_round'] ?? '');
            foreach (($st[$room]['bets'] ?? []) as $rid => $bets) {
                if ($last !== '' && strcmp((string) $rid, $last) <= 0) continue;   // already settled
                foreach ((array) $bets as $b) {
                    if (strtolower((string) ($b['username'] ?? '')) === strtolower($username)) {
                        return 'You have a Colour Prediction bet waiting for its result.';
                    }
                }
            }
        }
    } catch (Throwable $e) { /* no colour state yet */ }
    return null;
}

function register_settings_routes(Router $app) {

    // --- POST /api/profile/username --------------------------------------------------------------
    //
    // KNOWN LIMITATION: usernames are used as the identifying string (not just a numeric id) all
    // over this codebase — Transaction.user, GameBet.username, Deposit/Withdrawal.username, chat,
    // Teen Patti seats, and more. Renaming here only updates User.username itself; historical rows
    // under the old name are not rewritten, so past activity (transaction/game history, deposits,
    // etc.) recorded under the old name will no longer show up under the new one. This was left
    // deliberately out of scope rather than bulk-rewriting money-ledger tables.
    $app->post('/api/profile/username', limiter('auth'), 'require_auth', function (Req $req, Res $res) {
        try {
            $newUsername = trim((string) ($req->b('username') ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newUsername)) {
                $res->status(400)->json(['error' => 'Username must be 3-20 alphanumeric characters or underscores.']);
                return;
            }

            $currentUsername = $req->auth['username'];
            $user = find_user_ci($currentUsername);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }

            if (strtolower($newUsername) !== strtolower($currentUsername)) {
                $existing = find_user_ci($newUsername);
                if ($existing) { $res->status(409)->json(['error' => 'Username is already taken.']); return; }
            }

            $busy = rename_blocked_reason($user['username']);
            if ($busy !== null) {
                $res->status(409)->json(['error' => $busy . ' Please change your username once it has finished.']);
                return;
            }

            ensure_user_identity_indexes();
            try {
                q('UPDATE "User" SET "username" = ? WHERE "id" = ?', [$newUsername, (int) $user['id']]);
            } catch (Throwable $dupErr) {
                // Someone else took the name between the check above and this write.
                if (is_unique_violation($dupErr)) {
                    $res->status(409)->json(['error' => 'Username is already taken.']);
                    return;
                }
                throw $dupErr;
            }

            // The signed token embeds the username, so a rename has to reissue one — otherwise
            // every subsequent request would keep acting as the now-nonexistent old name.
            $token = issue_token([
                'id' => (int) $user['id'], 'username' => $newUsername,
                'email' => $user['email'], 'role' => 'user',
            ]);
            $res->json(['success' => true, 'username' => $newUsername, 'token' => $token]);
        } catch (Throwable $err) {
            fail500($res, $err, 'settings');
        }
    });

    // --- POST /api/profile/email -----------------------------------------------------------------
    $app->post('/api/profile/email', limiter('auth'), 'require_auth', function (Req $req, Res $res) {
        try {
            $newEmail = strtolower(trim((string) ($req->b('email') ?? '')));
            if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $newEmail)) {
                $res->status(400)->json(['error' => 'Enter a valid email address.']);
                return;
            }

            $user = find_user_ci($req->auth['username']);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }

            $existing = one('SELECT "id" FROM "User" WHERE LOWER("email") = ? AND "id" != ? LIMIT 1',
                             [$newEmail, (int) $user['id']]);
            if ($existing) { $res->status(409)->json(['error' => 'This email is already registered.']); return; }

            q('UPDATE "User" SET "email" = ? WHERE "id" = ?', [$newEmail, (int) $user['id']]);
            $res->json(['success' => true, 'email' => $newEmail]);
        } catch (Throwable $err) {
            fail500($res, $err, 'settings');
        }
    });

    // --- POST /api/profile/password --------------------------------------------------------------
    $app->post('/api/profile/password', limiter('auth'), 'require_auth', function (Req $req, Res $res) {
        try {
            $current = (string) ($req->b('current_password') ?? '');
            $new = (string) ($req->b('new_password') ?? '');
            $confirm = (string) ($req->b('confirm_password') ?? $new);

            if (strlen($new) < 8) {
                $res->status(400)->json(['error' => 'New password must be at least 8 characters.']);
                return;
            }
            if (strlen($new) > 128) {
                $res->status(400)->json(['error' => 'New password must be 128 characters or fewer.']);
                return;
            }
            if ($new !== $confirm) {
                $res->status(400)->json(['error' => 'New passwords do not match.']);
                return;
            }

            $user = find_user_ci($req->auth['username']);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            if (!check_password($current, $user['password'])) {
                $res->status(400)->json(['error' => 'Current password is incorrect.']);
                return;
            }

            q('UPDATE "User" SET "password" = ? WHERE "id" = ?', [hash_password($new), (int) $user['id']]);
            // Changing the password ends every session issued before it (lib/auth.php
            // session_still_valid), including this one, so hand this device a fresh token.
            $token = issue_token([
                'id' => (int) $user['id'], 'username' => $user['username'],
                'email' => $user['email'], 'role' => 'user',
            ]);
            $res->json(['success' => true, 'token' => $token]);
        } catch (Throwable $err) {
            fail500($res, $err, 'settings');
        }
    });
}
