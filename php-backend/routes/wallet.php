<?php
/**
 * Wallet and ledger endpoints.
 *
 * Two oddities are reproduced rather than corrected, and both are flagged in the migration dossier:
 *
 *   - Transaction ids here are PREFIX_ + a six-digit random number, not the collision-resistant
 *     generator used everywhere else. Only 900,000 values exist, so a duplicate becomes likely at
 *     around 1,100 rows.
 *   - The prefix comes from `type.substring(0,3).toUpperCase()`, which yields 'DEP' for Deposit and
 *     'WIT' for Withdrawal — note WIT, not the WTH used by the cashier.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function register_wallet_routes(Router $app) {

    // --- Balance ---
    $app->get('/api/wallet/balance', 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        try {
            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $res->json(['balance' => (float)$user['wallet_balance']]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // --- Adjust balance (operator only) ---
    // Direct balance manipulation is an operator action only. Left open, this single endpoint let
    // anyone credit any account any amount.
    $app->post(['/api/wallet/adjust', '/api/db/users/adjust-balance'], limiter('wallet'), 'require_admin',
        function (Req $req, Res $res) {
            $username = $req->b('username');
            if (!js_truthy($username)) {
                $tok = $req->auth ?: verify_token(extract_token($req));
                $username = $tok ? ($tok['username'] ?? null) : null;
            }
            $deltaParsed = js_parse_float($req->b('delta'));
            $delta = js_truthy($deltaParsed) ? (float)$deltaParsed : 0.0;

            $details = $req->b('details');
            if (!js_truthy($details)) $details = $req->b('reason');
            if (!js_truthy($details)) $details = 'Game play';

            if ($delta == 0) {
                $res->status(400)->json(['error' => 'Invalid adjustment amount.']);
                return;
            }

            try {
                $updatedBalance = 0.0;
                try {
                    $updatedBalance = tx(function () use ($username, $delta, $details) {
                        $user = find_user_ci($username);
                        if (!$user) throw new RuntimeException('User not found.');

                        $newBal = (float)$user['wallet_balance'] + $delta;
                        if ($newBal < 0) throw new RuntimeException('Insufficient wallet balance.');

                        q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$newBal, (int)$user['id']]);

                        $type = ($delta >= 0) ? 'Deposit' : 'Withdrawal';
                        $txnId = strtoupper(substr($type, 0, 3)) . '_' . (int) floor(100000 + js_random() * 900000);
                        insert_transaction($txnId, $user['username'], $type, abs($delta), $details, 'Completed');

                        return $newBal;
                    });
                } catch (Throwable $dbErr) {
                    $msg = $dbErr->getMessage();
                    if ($msg === 'Insufficient wallet balance.' || $msg === 'User not found.') {
                        $res->status(400)->json(['error' => $msg]);
                        return;
                    }
                    if (!json_fallback_allowed()) throw $dbErr;

                    $users = readJsonTable('users');
                    $found = null; $foundIdx = null;
                    foreach ($users as $i => $u) {
                        if (strtolower($u['username'] ?? '') === strtolower((string)$username)) { $found = $u; $foundIdx = $i; break; }
                    }
                    if (!$found) { $res->status(404)->json(['error' => 'User not found.']); return; }

                    $parsed = js_parse_float($found['wallet_balance'] ?? 0);
                    $newBal = (js_truthy($parsed) ? (float)$parsed : 0.0) + $delta;
                    if ($newBal < 0) { $res->status(400)->json(['error' => 'Insufficient wallet balance.']); return; }

                    $users[$foundIdx]['wallet_balance'] = $newBal;
                    writeJsonTable('users', $users);

                    $txns = readJsonTable('transactions');
                    $type = ($delta >= 0) ? 'Deposit' : 'Withdrawal';
                    array_unshift($txns, [
                        'id'        => strtoupper(substr($type, 0, 3)) . '_' . (int) floor(100000 + js_random() * 900000),
                        'user'      => $found['username'],
                        'type'      => $type,
                        'amount'    => abs($delta),
                        'details'   => $details,
                        'status'    => 'Completed',
                        'timestamp' => js_iso(),
                    ]);
                    writeJsonTable('transactions', $txns);
                    $updatedBalance = $newBal;
                }

                $res->json(['success' => true, 'new_balance' => $updatedBalance]);
            } catch (Throwable $err) {
                fail500($res, $err, 'wallet');
            }
        });

    // --- Transactions ---
    // Players see only their own ledger; operators may pass ?username= to inspect anyone's.
    // Registered ABOVE the /api/db admin gate on purpose, so the alias stays player-accessible.
    $app->get(['/api/wallet/transactions', '/api/db/transactions'], 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        try {
            $txns = [];
            try {
                if (js_truthy($username)) {
                    $rows = all('SELECT * FROM "Transaction" WHERE LOWER("user") = LOWER(?) ORDER BY "timestamp" DESC', [$username]);
                } else {
                    $rows = all('SELECT * FROM "Transaction" ORDER BY "timestamp" DESC');
                }
                foreach ($rows as $r) $txns[] = map_transaction($r);
            } catch (Throwable $e) {
                if (!json_fallback_allowed()) throw $e;
                $allRows = readJsonTable('transactions');
                if (js_truthy($username)) {
                    $txns = array_values(array_filter($allRows, function ($t) use ($username) {
                        return isset($t['user']) && strtolower($t['user']) === strtolower((string)$username);
                    }));
                } else {
                    $txns = $allRows;
                }
            }
            $res->json($txns);
        } catch (Throwable $err) {
            fail500($res, $err, 'wallet');
        }
    });

    // --- Reset balance (operator only) ---
    $app->post(['/api/wallet/reset', '/api/db/users/reset-balance'], 'require_admin', function (Req $req, Res $res) {
        $username = $req->b('username');
        if (!js_truthy($username)) {
            $tok = $req->auth ?: verify_token(extract_token($req));
            $username = $tok ? ($tok['username'] ?? null) : null;
        }
        // Same falsy-zero trap as the takeover percentage: `parseFloat(x) || 2000` credited 2000 to
        // a player whose balance an operator explicitly reset to 0. Only an absent or unparseable
        // value may take the default. Negatives are clamped rather than written — no reset should
        // create a debt.
        $parsedBal = js_parse_float($req->b('starting_balance'));
        $targetBal = js_is_finite($parsedBal) ? max(0.0, (float)$parsedBal) : 2000.00;

        try {
            try {
                tx(function () use ($username, $targetBal) {
                    $user = find_user_ci($username);
                    if (!$user) throw new RuntimeException('User not found.');
                    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$targetBal, (int)$user['id']]);
                    $txnId = 'DEP_' . (int) floor(100000 + js_random() * 900000);
                    insert_transaction($txnId, $user['username'], 'Deposit', $targetBal,
                                       'Operator Balance Adjustment', 'Completed');
                });
            } catch (Throwable $e) {
                // Note: unlike every other fallback, this one does not consult
                // ALLOW_JSON_FALLBACK and does not 404 on an unknown user — it writes the ledger
                // row regardless. Reproduced as-is.
                $users = readJsonTable('users');
                foreach ($users as $i => $u) {
                    if (strtolower($u['username'] ?? '') === strtolower((string)$username)) {
                        $users[$i]['wallet_balance'] = $targetBal;
                        writeJsonTable('users', $users);
                        break;
                    }
                }
                $txns = readJsonTable('transactions');
                array_unshift($txns, [
                    'id'        => new_record_id('DEP'),
                    'user'      => $username,
                    'type'      => 'Deposit',
                    'amount'    => $targetBal,
                    'details'   => 'Operator Balance Adjustment',
                    'status'    => 'Completed',
                    'timestamp' => js_iso(),
                ]);
                writeJsonTable('transactions', $txns);
            }
            $res->json(['success' => true, 'balance' => $targetBal]);
        } catch (Throwable $err) {
            fail500($res, $err, 'wallet');
        }
    });
}
