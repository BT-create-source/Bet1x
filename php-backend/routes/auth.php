<?php
/**
 * Health, readiness and the unified auth endpoints.
 *
 * Every status code and error string here is the one the frontend already handles, so they are
 * reproduced verbatim — including the two different "already taken" messages on signup and the
 * deliberate 400 (not 401) on a failed login.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function register_auth_routes(Router $app) {

    // --- Health check ---
    $app->get('/api/health', function (Req $req, Res $res) {
        $res->json(['status' => 'ok', 'service' => 'bet1x-backend', 'timestamp' => js_iso()]);
    });

    // Readiness differs from liveness: the process can be up while its datastore is not.
    $app->get('/api/ready', function (Req $req, Res $res) {
        $ready = db_ready() || json_fallback_allowed();
        $res->status($ready ? 200 : 503)->json([
            'ready'    => $ready,
            'database' => db_ready() ? 'connected' : 'unavailable',
            'store'    => db_ready() ? 'postgres' : (json_fallback_allowed() ? 'json-fallback' : 'none'),
            'env'      => cfg('NODE_ENV'),
        ]);
    });

    // --- Session status ---
    // Identity is taken from the signed token alone. Honouring a `username` query parameter here
    // let anybody read any account's e-mail address and wallet balance just by guessing the name.
    $app->all(['/api/auth/status', '/api/db/users/status'], function (Req $req, Res $res) {
        $tokenData = $req->auth ?: verify_token(extract_token($req));
        $username = $tokenData ? ($tokenData['username'] ?? null) : null;

        if (!$username) {
            $res->json(['logged_in' => false, 'message' => 'Guest session']);
            return;
        }

        try {
            $user = null;
            try {
                $user = find_user_ci($username);
            } catch (Throwable $e) {
                // Note: this inner fallback deliberately does NOT consult ALLOW_JSON_FALLBACK,
                // matching the original.
                foreach (readJsonTable('users') as $u) {
                    if (strtolower($u['username'] ?? '') === strtolower($username)) { $user = $u; break; }
                }
            }

            if ($user) {
                $res->json([
                    'logged_in' => true,
                    'user' => [
                        'id'             => (int)$user['id'],
                        'username'       => $user['username'],
                        'email'          => $user['email'],
                        'wallet_balance' => (float)$user['wallet_balance'],
                    ],
                ]);
                return;
            }
            $res->json(['logged_in' => false]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });

    // --- Login (username or email + password) ---
    $app->post(['/api/auth/login', '/api/db/users/login'], limiter('auth'), function (Req $req, Res $res) {
        $username = trim((string)($req->b('username') ?? ''));
        $password = (string)($req->b('password') ?? '');

        if ($username === '' || $password === '') {
            $res->status(400)->json(['error' => 'Username/email and password are required.']);
            return;
        }

        try {
            $user = null;
            try {
                $user = one('SELECT * FROM `User` WHERE LOWER(`username`) = LOWER(?) OR LOWER(`email`) = LOWER(?) LIMIT 1',
                            [$username, $username]);
            } catch (Throwable $e) {
                if (!json_fallback_allowed()) throw $e;
                foreach (readJsonTable('users') as $u) {
                    if (strtolower($u['username'] ?? '') === strtolower($username)
                        || strtolower($u['email'] ?? '') === strtolower($username)) { $user = $u; break; }
                }
            }

            if ($user && check_password($password, $user['password'])) {
                $token = issue_token([
                    'id' => (int)$user['id'], 'username' => $user['username'],
                    'email' => $user['email'], 'role' => 'user',
                ]);
                $res->json([
                    'success' => true,
                    'token'   => $token,
                    'user'    => [
                        'id'             => (int)$user['id'],
                        'username'       => $user['username'],
                        'email'          => $user['email'],
                        'wallet_balance' => (float)$user['wallet_balance'],
                    ],
                ]);
            } else {
                // Deliberately 400, not 401 — the frontend branches on this.
                $res->status(400)->json(['error' => 'Incorrect username or password.']);
            }
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });

    // --- Signup ---
    $app->post(['/api/auth/signup', '/api/db/users/signup'], limiter('auth'), function (Req $req, Res $res) {
        $username = trim((string)($req->b('username') ?? ''));
        $email = trim((string)($req->b('email') ?? ''));
        if ($email === '' && $username !== '') {
            $email = strtolower($username) . '@bet1x.com';
        }
        $password = (string)($req->b('password') ?? '');
        $confirmPassword = $req->b('confirm_password');
        if ($confirmPassword === null) $confirmPassword = $password;
        $confirmPassword = (string)$confirmPassword;

        // The opening balance is a server-side product decision. It used to be read from the
        // request body, which meant a new account could simply ask to be created with any balance.
        $startingBalance = (float) cfg('SIGNUP_BONUS');

        if ($username === '' || $password === '') {
            $res->status(400)->json(['error' => 'Username and password are required.']);
            return;
        }
        if ($password !== $confirmPassword) {
            $res->status(400)->json(['error' => 'Passwords do not match.']);
            return;
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $res->status(400)->json(['error' => 'Username must be 3-20 alphanumeric characters or underscores.']);
            return;
        }
        if ($email !== '' && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $res->status(400)->json(['error' => 'Invalid email address format.']);
            return;
        }
        if (strlen($password) < 8) {
            $res->status(400)->json(['error' => 'Password must be at least 8 characters.']);
            return;
        }
        if (strlen($password) > 128) {
            $res->status(400)->json(['error' => 'Password must be 128 characters or fewer.']);
            return;
        }

        $hashedPassword = hash_password($password);

        try {
            $newUser = null;
            try {
                $newUser = tx(function () use ($username, $email, $hashedPassword, $startingBalance) {
                    $existing = one('SELECT * FROM `User` WHERE LOWER(`username`) = LOWER(?) OR LOWER(`email`) = LOWER(?) LIMIT 1',
                                    [$username, $email]);
                    if ($existing) {
                        if (strtolower($existing['username']) === strtolower($username)) {
                            throw new RuntimeException('Username is already taken.');
                        }
                        throw new RuntimeException('Email is already registered.');
                    }

                    q('INSERT INTO `User` (`username`,`email`,`password`,`wallet_balance`,`created_at`) VALUES (?,?,?,?,?)',
                      [$username, $email, $hashedPassword, $startingBalance, ms_to_sql()]);
                    $created = find_user_ci($username);

                    if ($startingBalance > 0) {
                        insert_transaction(new_record_id('DEP'), $username, 'Deposit', $startingBalance,
                                           'Welcome Bonus Credits', 'Completed');
                    }
                    return $created;
                });
            } catch (Throwable $dbErr) {
                $msg = $dbErr->getMessage();
                if ($msg === 'Username is already taken.' || $msg === 'Email is already registered.') {
                    $res->status(400)->json(['error' => $msg]);
                    return;
                }

                if (!json_fallback_allowed()) throw $dbErr;
                $users = readJsonTable('users');
                foreach ($users as $u) {
                    if (strtolower($u['username'] ?? '') === strtolower($username)) {
                        $res->status(400)->json(['error' => 'Username is already taken.']);
                        return;
                    }
                }
                foreach ($users as $u) {
                    if (strtolower($u['email'] ?? '') === strtolower($email)) {
                        $res->status(400)->json(['error' => 'Email is already registered.']);
                        return;
                    }
                }

                $newUser = [
                    'id'             => count($users) + 1,
                    'username'       => $username,
                    'email'          => $email,
                    'password'       => $hashedPassword,
                    'wallet_balance' => $startingBalance,
                    'created_at'     => js_iso(),
                ];
                $users[] = $newUser;
                writeJsonTable('users', $users);

                $txns = readJsonTable('transactions');
                array_unshift($txns, [
                    'id'        => new_record_id('DEP'),
                    'user'      => $username,
                    'type'      => 'Deposit',
                    'amount'    => $startingBalance,
                    'details'   => 'Welcome Bonus Credits',
                    'status'    => 'Completed',
                    'timestamp' => js_iso(),
                ]);
                writeJsonTable('transactions', $txns);
            }

            $token = issue_token([
                'id' => (int)$newUser['id'], 'username' => $newUser['username'],
                'email' => $newUser['email'], 'role' => 'user',
            ]);
            $res->json([
                'success' => true,
                'token'   => $token,
                'user'    => [
                    'id'             => (int)$newUser['id'],
                    'username'       => $newUser['username'],
                    'email'          => $newUser['email'],
                    'wallet_balance' => (float)$newUser['wallet_balance'],
                ],
            ]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });

    // --- Logout ---
    // Tokens are stateless and self-expiring, so nothing is revoked; the client drops its copy.
    $app->all(['/api/auth/logout'], function (Req $req, Res $res) {
        $res->json(['success' => true, 'message' => 'Logged out successfully.']);
    });
}
