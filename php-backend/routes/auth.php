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
require_once __DIR__ . '/../lib/riskcontrols.php';

function register_auth_routes(Router $app) {

    /**
     * Does this candidate match SUPERADMIN_ACCESS_TOKEN?
     *
     * The admin console asks before it renders the super-admin entry point. Deliberately a
     * server-side check answering only yes/no: shipping the real token to the browser so the page
     * could compare it locally would put the secret in the page source of every operator's browser,
     * which is precisely what this is meant to prevent.
     *
     * This is NOT authentication. A yes only reveals a link; reaching the console behind it still
     * requires the super-admin login. Rate limited with the auth bucket so the token cannot be
     * guessed by brute force, and compared with hash_equals to keep the comparison constant time.
     *
     * require_admin is named explicitly rather than inherited from the useMw('/api/admin', ...) gate
     * in routes/admin.php: layers dispatch in registration order, and this file is registered first,
     * so this route would otherwise slip past that gate entirely. Only an operator who is already
     * signed in can probe the token, which also means the rate limiter is not the only thing
     * standing between a stranger and an unlimited guessing loop.
     */
    $app->post('/api/admin/superadmin-entry', limiter('auth'), 'require_admin', function (Req $req, Res $res) {
        $configured = (string) cfg('SUPERADMIN_ACCESS_TOKEN', '');
        $candidate  = (string) ($req->b('key') ?? $req->q('key') ?? '');

        // Unset means the feature is off: never reveal the entry point rather than accepting ''.
        if ($configured === '') {
            $res->json(['ok' => false]);
            return;
        }
        $res->json(['ok' => hash_equals($configured, $candidate)]);
    });

    // --- Health check ---
    $app->get('/api/health', function (Req $req, Res $res) {
        // `env` is reported so an operator can confirm from outside that the production cutover
        // actually took — a deployment still reading a development .env is the single most likely
        // go-live mistake, and it is otherwise invisible from the browser.
        $res->json([
            'status'    => 'ok',
            'service'   => 'bet1x-backend',
            'env'       => cfg('NODE_ENV'),
            'timestamp' => js_iso(),
        ]);
    });

    // Readiness differs from liveness: the process can be up while its datastore is not, or while
    // the cron that advances idle games has silently stopped.
    $app->get('/api/ready', function (Req $req, Res $res) {
        $dbOk = db_ready();
        $storeOk = $dbOk || json_fallback_allowed();

        // --- Cron freshness -------------------------------------------------------------------
        // A stopped cron does not break page loads, so nothing else surfaces it. Games simply stop
        // advancing whenever nobody is polling them: colour rounds never settle, Aviator freezes
        // mid-phase. Treating a stale heartbeat as not-ready is what lets an uptime monitor catch
        // that within minutes instead of via customer complaints.
        $staleAfter = (int) cfg('CRON_STALE_SECONDS', 300);
        $cronAgeSec = null;
        $cronOk = true;
        try {
            $rec = state_get('cron_last_run');
            $at = is_array($rec) ? ($rec['at'] ?? null) : null;
            if ($at !== null) {
                $cronAgeSec = (int) floor((now_ms() - (int)$at) / 1000);
                if ($staleAfter > 0) $cronOk = $cronAgeSec <= $staleAfter;
            } else {
                // Never recorded. Only a problem once a heartbeat is expected at all — a brand new
                // deployment legitimately has none until the first minute elapses, so this reports
                // as unknown rather than failing readiness outright.
                $cronOk = true;
            }
        } catch (Throwable $e) {
            $cronOk = true;   // never let a monitoring read take the site down
        }

        $ready = $storeOk && $cronOk;
        $res->status($ready ? 200 : 503)->json([
            'ready'         => $ready,
            'database'      => $dbOk ? 'connected' : 'unavailable',
            'store'         => $dbOk ? 'postgres' : (json_fallback_allowed() ? 'json-fallback' : 'none'),
            'cron'          => $cronAgeSec === null ? 'never-run' : ($cronOk ? 'ok' : 'stale'),
            'cron_age_sec'  => $cronAgeSec,
            'env'           => cfg('NODE_ENV'),
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
            fail500($res, $err, 'auth');
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
                $user = one('SELECT * FROM "User" WHERE LOWER("username") = LOWER(?) OR LOWER("email") = LOWER(?) LIMIT 1',
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
            fail500($res, $err, 'auth');
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

        // Bulk-registration brake. Runs after cheap format validation so a malformed request is
        // still rejected on its own merits, but before any row is written.
        $signupIp = risk_client_ip();
        $signupBlock = risk_check_signup($signupIp);
        if ($signupBlock !== null) {
            $res->status(429)->json(['error' => $signupBlock]);
            return;
        }

        $hashedPassword = hash_password($password);

        try {
            $newUser = null;
            try {
                $newUser = tx(function () use ($username, $email, $hashedPassword, $startingBalance, $signupIp) {
                    $existing = one('SELECT * FROM "User" WHERE LOWER("username") = LOWER(?) OR LOWER("email") = LOWER(?) LIMIT 1',
                                    [$username, $email]);
                    if ($existing) {
                        if (strtolower($existing['username']) === strtolower($username)) {
                            throw new RuntimeException('Username is already taken.');
                        }
                        throw new RuntimeException('Email is already registered.');
                    }

                    // signup_ip / bonus_credited come from migration-002. Try the full insert first
                    // and fall back to the original column set, so a database that has not had the
                    // migration applied yet still registers users instead of 500-ing.
                    try {
                        q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at","signup_ip","bonus_credited")
                           VALUES (?,?,?,?,?,?,?)',
                          [$username, $email, $hashedPassword, $startingBalance, ms_to_sql(),
                           $signupIp, $startingBalance > 0 ? 1 : 0]);
                    } catch (Throwable $colErr) {
                        log_debug('signup falling back to pre-migration-002 column set: ' . $colErr->getMessage());
                        q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at") VALUES (?,?,?,?,?)',
                          [$username, $email, $hashedPassword, $startingBalance, ms_to_sql()]);
                    }
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
            fail500($res, $err, 'auth');
        }
    });

    // --- Logout ---
    // Tokens are stateless and self-expiring, so nothing is revoked; the client drops its copy.
    $app->all(['/api/auth/logout'], function (Req $req, Res $res) {
        $res->json(['success' => true, 'message' => 'Logged out successfully.']);
    });
}
