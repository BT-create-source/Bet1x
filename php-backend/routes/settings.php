<?php
/**
 * Account settings: change username, update email, change password. Three separate endpoints so
 * the settings panel can save one field at a time rather than one combined form.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';

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

            q('UPDATE "User" SET "username" = ? WHERE "id" = ?', [$newUsername, (int) $user['id']]);

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
            $res->json(['success' => true]);
        } catch (Throwable $err) {
            fail500($res, $err, 'settings');
        }
    });
}
