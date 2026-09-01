<?php
/**
 * The PHP-shaped endpoints the frontend has always called: api/admin.php, api/chat.php,
 * api/deposit.php and api/withdraw.php.
 *
 * A note on why these matter more in this build than they did before. In the Node deployment these
 * paths had no handler at first, so requests fell through to the static file handler and the
 * browser was served the PHP SOURCE — which published the payment-gateway keys hardcoded in
 * api/config.php and made the admin console's login "succeed" through its own JSON-parse error
 * handler. Now that the backend genuinely IS PHP, the stale files in api/ and backend/api/ would be
 * EXECUTED rather than merely leaked. The root .htaccess rewrites every /api/* request into the
 * front controller unconditionally, before Apache can resolve any of those files, and denies them
 * directly as a second layer. Both guards are required.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/riskcontrols.php';
require_once __DIR__ . '/chat.php';

/** Read a table, falling back to the flat-file store when that is permitted. */
function read_table($sql, $params, $jsonName, $mapper = null) {
    try {
        $rows = all($sql, $params);
        if ($mapper === null) return $rows;
        $out = [];
        foreach ($rows as $r) $out[] = $mapper($r);
        return $out;
    } catch (Throwable $e) {
        if (!json_fallback_allowed()) throw $e;
        return readJsonTable($jsonName);
    }
}

function register_legacy_routes(Router $app) {

    // ---------------------------------------------------------------- api/admin.php
    $app->all('/api/admin.php', function (Req $req, Res $res) {
        $action = $req->q('action');
        if (!js_truthy($action)) $action = $req->b('action');
        $action = (string)($action ?? '');

        try {
            // Operator sign-in. The credential lives in ADMIN_PASSWORD_HASH, never in the codebase,
            // and the old `login_bypass` action — which granted admin to anyone who asked — is gone.
            if ($action === 'login') {
                $username = trim((string)($req->b('username') ?? ''));
                $password = (string)($req->b('password') ?? '');
                if (strtolower($username) !== strtolower((string) cfg('ADMIN_USERNAME'))
                    || !verify_admin_password($password)) {
                    log_warn('failed admin login attempt', ['username' => $username, 'ip' => $req->ip]);
                    $res->status(401)->json(['error' => 'Invalid administrator credentials.']);
                    return;
                }
                $token = issue_token([
                    'id'       => 0,
                    'username' => cfg('ADMIN_USERNAME'),
                    'email'    => null,
                    'role'     => 'admin',
                    // Operator sessions are deliberately shorter than player sessions.
                    'ttlMs'    => 8 * 3600 * 1000,
                ]);
                log_info('admin signed in', ['ip' => $req->ip]);
                $res->json(['success' => true, 'token' => $token]);
                return;
            }

            if ($action === 'superadmin_login') {
                $username = trim((string)($req->b('username') ?? ''));
                $password = (string)($req->b('password') ?? '');
                if (($username !== '' && strtolower($username) !== strtolower((string) cfg('SUPERADMIN_USERNAME')) && strtolower($username) !== 'admin')
                    || !verify_superadmin_password($password)) {
                    log_warn('failed superadmin login attempt', ['username' => $username, 'ip' => $req->ip]);
                    $res->status(401)->json(['error' => 'Invalid super administrator credentials.']);
                    return;
                }
                $token = issue_token([
                    'id'       => 0,
                    'username' => cfg('SUPERADMIN_USERNAME'),
                    'email'    => null,
                    'role'     => 'superadmin',
                    'ttlMs'    => 8 * 3600 * 1000,
                ]);
                log_info('superadmin signed in', ['ip' => $req->ip]);
                $res->json(['success' => true, 'token' => $token, 'role' => 'superadmin']);
                return;
            }

            if (!is_admin($req)) {
                $res->status(401)->json(['error' => 'Unauthorized admin access.']);
                return;
            }

            switch ($action) {
                case 'status':
                    $res->json(['logged_in' => true, 'username' => $req->auth['username']]);
                    return;

                case 'logout':
                    $res->json(['success' => true]);
                    return;

                case 'stats': {
                    $users       = read_table('SELECT * FROM `User`', [], 'users');
                    $deposits    = read_table('SELECT * FROM `Deposit`', [], 'deposits');
                    $withdrawals = read_table('SELECT * FROM `Withdrawal`', [], 'withdrawals');

                    $sum = function ($rows, $pred) {
                        $t = 0.0;
                        foreach ($rows as $r) {
                            if (!$pred($r)) continue;
                            $v = js_parse_float($r['amount'] ?? 0);
                            $t += js_truthy($v) ? (float)$v : 0.0;
                        }
                        return $t;
                    };
                    $walletPool = 0.0;
                    foreach ($users as $u) {
                        $v = js_parse_float($u['wallet_balance'] ?? 0);
                        $walletPool += js_truthy($v) ? (float)$v : 0.0;
                    }

                    $res->json([
                        'total_users'         => count($users),
                        'total_deposited'     => $sum($deposits, function ($d) { return ($d['status'] ?? null) === 'Completed'; }),
                        'total_withdrawn'     => $sum($withdrawals, function ($w) { return ($w['status'] ?? null) === 'Completed'; }),
                        'wallet_pool'         => $walletPool,
                        'pending_withdrawals' => count(array_filter($withdrawals, function ($w) { return ($w['status'] ?? null) === 'Pending'; })),
                    ]);
                    return;
                }

                case 'users': {
                    $users = read_table('SELECT * FROM `User`', [], 'users');
                    // Password hashes are never part of an API response, not even an operator's.
                    // (Note /api/db/users does include them — both shapes are preserved as-is.)
                    $out = [];
                    foreach ($users as $u) {
                        $bal = js_parse_float($u['wallet_balance'] ?? 0);
                        $out[] = [
                            'username'       => $u['username'] ?? null,
                            'email'          => $u['email'] ?? null,
                            'wallet_balance' => js_truthy($bal) ? (float)$bal : 0.0,
                            'created_at'     => isset($u['created_at']) ? js_iso($u['created_at']) : null,
                        ];
                    }
                    $res->json($out);
                    return;
                }

                case 'transactions':
                    $res->json(read_table('SELECT * FROM `Transaction` ORDER BY `timestamp` DESC LIMIT 500', [],
                                          'transactions', 'map_transaction'));
                    return;

                case 'deposits':
                    $res->json(read_table('SELECT * FROM `Deposit` ORDER BY `created_at` DESC LIMIT 500', [],
                                          'deposits', 'map_deposit'));
                    return;

                case 'withdrawals':
                    $res->json(read_table('SELECT * FROM `Withdrawal` ORDER BY `created_at` DESC LIMIT 500', [],
                                          'withdrawals', 'map_withdrawal'));
                    return;

                case 'get_payment_config': {
                    $cfg = state_get('payment_config') ?? [];
                    $res->json(['upi_id' => $cfg['upi_id'] ?? '', 'payee_name' => $cfg['payee_name'] ?? 'bet1x']);
                    return;
                }

                case 'set_payment_config': {
                    $upiId = trim((string)($req->b('upi_id') ?? ''));
                    $payeeName = trim((string)($req->b('payee_name') ?? '')) ?: 'bet1x';
                    if ($upiId !== '' && !preg_match('/^[\w.\-]{2,64}@[A-Za-z][\w.\-]{1,64}$/', $upiId)) {
                        $res->status(400)->json(['error' => 'Enter a valid UPI ID, e.g. yourname@bank.']);
                        return;
                    }
                    $data = ['upi_id' => $upiId, 'payee_name' => $payeeName];
                    state_set('payment_config', $data);
                    $res->json(array_merge(['success' => true], $data));
                    return;
                }

                case 'adjust_balance': {
                    $targetUser = trim((string)($req->b('username') ?? ''));
                    $amt = js_parse_float($req->b('amount'));
                    $type = $req->b('type');

                    if ($targetUser === '' || !js_is_finite($amt) || $amt <= 0) {
                        $res->status(400)->json(['error' => 'Invalid user or adjustment amount.']);
                        return;
                    }
                    if ($type !== 'add' && $type !== 'deduct') {
                        $res->status(400)->json(['error' => 'Adjustment type must be "add" or "deduct".']);
                        return;
                    }
                    $user = get_or_create_user($targetUser);
                    if (!$user) { $res->status(404)->json(['error' => 'User not found.']); return; }

                    $delta = $type === 'add' ? (float)$amt : -((float)$amt);
                    $details = 'Admin Adjustment: ' . ($type === 'add' ? 'Credited' : 'Debited');
                    $newBalance = $delta >= 0
                        ? credit_wallet($user['id'], $delta)
                        : debit_wallet($user['id'], -$delta);
                    if ($newBalance === null) {
                        $res->status(400)->json(['error' => 'Insufficient wallet balance.']);
                        return;
                    }

                    insert_transaction(
                        ($delta >= 0 ? 'DEP_' : 'WTH_') . (int) floor(100000 + js_random() * 900000),
                        $user['username'], $delta >= 0 ? 'Deposit' : 'Withdrawal',
                        (float)$amt, $details, 'Completed'
                    );
                    log_info('admin adjusted balance', [
                        'operator' => $req->auth['username'], 'target' => $user['username'], 'delta' => $delta,
                    ]);
                    $res->json(['success' => true, 'new_balance' => $newBalance]);
                    return;
                }

                case 'approve_deposit':
                case 'reject_deposit': {
                    $depId = trim((string)($req->b('deposit_id') ?? ''));
                    if ($depId === '') { $res->status(400)->json(['error' => 'Deposit ID required.']); return; }
                    $approving = ($action === 'approve_deposit');

                    $deposit = one('SELECT * FROM `Deposit` WHERE `deposit_id` = ? LIMIT 1', [$depId]);
                    if (!$deposit) { $res->status(404)->json(['error' => 'Deposit record not found.']); return; }
                    if ($deposit['status'] !== 'Pending') {
                        $res->status(400)->json(['error' => 'Deposit is already processed.']);
                        return;
                    }

                    // Guard the status transition itself: a conditional update means a
                    // double-clicked "approve" credits the player exactly once.
                    $claimed = affected("UPDATE `Deposit` SET `status` = ?, `updated_at` = ?
                                         WHERE `deposit_id` = ? AND `status` = 'Pending'",
                                        [$approving ? 'Completed' : 'Rejected', ms_to_sql(), $depId]);
                    if ($claimed === 0) {
                        $res->status(400)->json(['error' => 'Deposit is already processed.']);
                        return;
                    }

                    if ($approving) {
                        $user = get_or_create_user($deposit['username']);
                        if ($user) credit_wallet($user['id'], (float)$deposit['amount']);
                    }
                    q("UPDATE `Transaction` SET `status` = ?
                       WHERE `user` = ? AND `type` = 'Deposit' AND `status` = 'Pending' AND `details` LIKE ?",
                      [$approving ? 'Completed' : 'Rejected', $deposit['username'], '%' . $depId . '%']);

                    log_info('deposit ' . ($approving ? 'approved' : 'rejected'), [
                        'operator' => $req->auth['username'], 'depId' => $depId, 'amount' => (float)$deposit['amount'],
                    ]);
                    $res->json(['success' => true]);
                    return;
                }

                case 'approve_withdrawal':
                case 'reject_withdrawal': {
                    $wthId = trim((string)($req->b('withdrawal_id') ?? ''));
                    if ($wthId === '') { $res->status(400)->json(['error' => 'Withdrawal ID required.']); return; }
                    $approving = ($action === 'approve_withdrawal');

                    $withdrawal = one('SELECT * FROM `Withdrawal` WHERE `withdrawal_id` = ? LIMIT 1', [$wthId]);
                    if (!$withdrawal) { $res->status(404)->json(['error' => 'Withdrawal record not found.']); return; }

                    $claimed = affected("UPDATE `Withdrawal` SET `status` = ?, `updated_at` = ?
                                         WHERE `withdrawal_id` = ? AND `status` = 'Pending'",
                                        [$approving ? 'Completed' : 'Rejected', ms_to_sql(), $wthId]);
                    if ($claimed === 0) {
                        $res->status(400)->json(['error' => 'Withdrawal is already processed.']);
                        return;
                    }

                    if (!$approving) {
                        // The amount was debited when the request was raised, so rejecting refunds.
                        $user = get_or_create_user($withdrawal['username']);
                        if ($user) credit_wallet($user['id'], (float)$withdrawal['amount']);
                    }
                    q("UPDATE `Transaction` SET `status` = ?
                       WHERE `user` = ? AND `type` = 'Withdrawal' AND `status` = 'Pending' AND `details` LIKE ?",
                      [$approving ? 'Completed' : 'Rejected', $withdrawal['username'], '%' . $wthId . '%']);

                    log_info('withdrawal ' . ($approving ? 'approved' : 'rejected'), [
                        'operator' => $req->auth['username'], 'wthId' => $wthId, 'amount' => (float)$withdrawal['amount'],
                    ]);
                    $res->json(['success' => true]);
                    return;
                }

                default:
                    $res->status(400)->json(['error' => 'Invalid admin action.']);
                    return;
            }
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // ---------------------------------------------------------------- api/chat.php
    $app->get('/api/chat.php', function (Req $req, Res $res) {
        try {
            try {
                $messages = chat_fetch_messages();
            } catch (Throwable $e) {
                if (!json_fallback_allowed()) throw $e;
                $messages = array_slice(readJsonTable('chat'), -50);
            }
            $res->json($messages);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    $app->post('/api/chat.php', limiter('chat'), 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        $message = mb_substr(trim((string)($req->b('message') ?? '')), 0, 300);
        if ($message === '') { $res->status(400)->json(['error' => 'Message cannot be empty.']); return; }

        try {
            $saved = chat_store_message($username, $message);
            $res->json(['success' => true, 'message' => $saved]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // The platform's own UPI ID/payee name for the deposit QR code — set once by an operator via the
    // admin panel (see 'get_payment_config'/'set_payment_config' above) and read here by any
    // logged-in player so the cashier page can render a real, scannable UPI QR rather than a
    // decorative placeholder. Not sensitive, so ordinary auth is enough — no admin gate to read it.
    $app->get('/api/payment-config', 'require_auth', function (Req $req, Res $res) {
        $cfg = state_get('payment_config') ?? [];
        $res->json(['upi_id' => $cfg['upi_id'] ?? '', 'payee_name' => $cfg['payee_name'] ?? 'bet1x']);
    });

    // ---------------------------------------------------------------- api/deposit.php
    $app->all('/api/deposit.php', limiter('cashier'), 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        $amountSrc = $req->b('amount');
        if (!js_truthy($amountSrc)) $amountSrc = $req->q('amount');
        if ($amountSrc === null) $amountSrc = 0;
        $amount = js_parse_float($amountSrc);

        $action = $req->q('action');
        if (!js_truthy($action)) $action = $req->b('action');
        $action = (string)($action ?? '');

        if (!js_is_finite($amount) || $amount < 100) {
            $res->status(400)->json(['error' => 'Minimum deposit amount is INR 100.']);
            return;
        }
        if ($amount > 500000) {
            $res->status(400)->json(['error' => 'Maximum single deposit is INR 5,00,000.']);
            return;
        }
        $amount = (float)$amount;

        try {
            if ($action === 'submit_upi_deposit') {
                $utr = trim((string)($req->b('utr') ?? ''));
                if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $utr)) {
                    $res->status(400)->json(['error' => 'Please enter a valid UTR reference number.']);
                    return;
                }

                // One UTR identifies one bank transfer, so re-submitting it must not create a second
                // deposit row. order_id is uniquely indexed; check first so the user gets a clear
                // message rather than a database constraint error.
                $existing = one('SELECT * FROM `Deposit` WHERE `order_id` = ? LIMIT 1', ['UPI_' . $utr]);
                if ($existing) {
                    $res->status(409)->json([
                        'error'      => 'That UTR has already been submitted. It is pending verification.',
                        'deposit_id' => $existing['deposit_id'],
                        'status'     => $existing['status'],
                    ]);
                    return;
                }

                $depId = 'DEP_' . (int) floor(100000 + js_random() * 900000);
                $nowSql = ms_to_sql();

                // Recorded as Pending, not Completed. The original flow credited the wallet the
                // instant a player typed ANY six-character string into the UTR box, with nothing
                // checking that a real payment had arrived — an open faucet. Funds are released by
                // the operator in the admin console once the transfer is actually confirmed.
                q('INSERT INTO `Deposit`
                   (`deposit_id`,`order_id`,`username`,`amount`,`utr`,`qr_type`,`custom_qr_data`,`status`,`gateway`,`created_at`,`updated_at`)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                    $depId, 'UPI_' . $utr, $username, $amount, $utr,
                    mb_substr((string)($req->b('qr_type') ?? 'default'), 0, 40),
                    js_truthy($req->b('custom_qr_data'))
                        ? mb_substr((string)$req->b('custom_qr_data'), 0, 2000) : null,
                    'Pending', 'UPI QR', $nowSql, $nowSql,
                ]);

                insert_transaction(new_record_id('DEP'), $username, 'Deposit', $amount,
                                   'UPI Deposit: UTR #' . $utr . ' - ID: ' . $depId, 'Pending');

                log_info('upi deposit submitted', ['username' => $username, 'amount' => $amount, 'depId' => $depId]);
                $res->json([
                    'success'    => true,
                    'deposit_id' => $depId,
                    'amount'     => $amount,
                    'utr'        => $utr,
                    'status'     => 'Pending',
                    'message'    => 'Deposit submitted. Your coins will be credited once the payment is verified.',
                ]);
                return;
            }

            // Gateway order creation is intentionally not implemented: it needs live credentials,
            // and the placeholder keys that used to sit in api/config.php were committed to the
            // repository.
            $res->status(501)->json([
                'error' => 'Card/gateway deposits are not configured on this deployment. Please use the UPI QR flow.',
            ]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // ---------------------------------------------------------------- api/withdraw.php
    $app->all('/api/withdraw.php', limiter('cashier'), 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        $amountSrc = $req->b('amount');
        if ($amountSrc === null) $amountSrc = 0;
        $amount = js_parse_float($amountSrc);
        $method = $req->b('method');
        if (!js_truthy($method)) $method = '';

        $minWithdrawal = (float) cfg('MIN_WITHDRAWAL');
        if (!js_is_finite($amount) || $amount < $minWithdrawal || $amount > 50000) {
            $res->status(400)->json([
                'error' => 'Withdrawal must be between INR ' . js_num_str($minWithdrawal) . ' and INR 50,000.',
            ]);
            return;
        }
        $amount = (float)$amount;

        $details = '';
        if ($method === 'upi') {
            $upiId = trim((string)($req->b('upi_id') ?? ''));
            if (!preg_match('/^[\w.\-]{2,64}@[A-Za-z]{2,32}$/', $upiId)) {
                $res->status(400)->json(['error' => 'A valid UPI ID is required.']);
                return;
            }
            $details = 'UPI ID: ' . $upiId;
        } elseif ($method === 'bank') {
            $bankName = trim((string)($req->b('bank_name') ?? ''));
            $accName  = trim((string)($req->b('bank_acc_name') ?? ''));
            $accNum   = trim((string)($req->b('bank_acc_num') ?? ''));
            $ifsc     = strtoupper(trim((string)($req->b('bank_ifsc') ?? '')));
            if ($bankName === '' || $accName === '' || $accNum === '' || $ifsc === '') {
                $res->status(400)->json(['error' => 'All bank details are required.']);
                return;
            }
            // Free-text bank/account-holder names are concatenated into `details`, stored, and then
            // rendered in the OPERATOR's pending-cashouts table. Before this check they accepted any
            // bytes at all, which made them a stored-XSS delivery path aimed at the admin session.
            // Output is escaped too (see escapeHtml in ui-common.js); this is the second layer, and
            // it also keeps the ledger readable. Letters, digits, space, dot, hyphen, ampersand and
            // apostrophe cover real Indian bank and account-holder names.
            if (!preg_match("/^[A-Za-z0-9 .\-&']{2,60}$/u", $bankName)) {
                $res->status(400)->json(['error' => 'Bank name contains invalid characters.']);
                return;
            }
            if (!preg_match("/^[A-Za-z .\-']{2,60}$/u", $accName)) {
                $res->status(400)->json(['error' => 'Account holder name contains invalid characters.']);
                return;
            }
            if (!preg_match('/^\d{6,20}$/', $accNum)) {
                $res->status(400)->json(['error' => 'Account number looks invalid.']);
                return;
            }
            if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                $res->status(400)->json(['error' => 'IFSC code looks invalid.']);
                return;
            }
            $details = 'Bank: ' . $bankName . ' | A/C Name: ' . $accName . ' | A/C Num: ' . $accNum . ' | IFSC: ' . $ifsc;
        } else {
            $res->status(400)->json(['error' => 'Invalid withdrawal method.']);
            return;
        }

        // Abuse checks run here — after the request is well-formed, but BEFORE debit_wallet() below
        // holds any money. A refusal at this point leaves the player's balance untouched.
        $riskError = risk_check_withdrawal($username, $amount);
        if ($riskError !== null) {
            $res->status(400)->json(['error' => $riskError]);
            return;
        }

        try {
            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }

            $withdrawalId = 'WTH_' . (int) floor(100000 + js_random() * 900000);

            // Hold the funds first. If the conditional debit does not match a row the player simply
            // does not have the balance, and no withdrawal row is created.
            $newBalance = debit_wallet($user['id'], $amount);
            if ($newBalance === null) {
                $res->status(400)->json(['error' => 'Insufficient wallet balance.']);
                return;
            }

            $nowSql = ms_to_sql();
            q('INSERT INTO `Withdrawal` (`withdrawal_id`,`username`,`amount`,`method`,`details`,`status`,`created_at`,`updated_at`)
               VALUES (?,?,?,?,?,?,?,?)',
              [$withdrawalId, $user['username'], $amount, $method, $details, 'Pending', $nowSql, $nowSql]);

            insert_transaction(new_record_id('WTH'), $user['username'], 'Withdrawal', $amount,
                               'Withdrawal Request ' . $withdrawalId . ': ' . $details, 'Pending');

            log_info('withdrawal requested', ['username' => $user['username'], 'amount' => $amount, 'withdrawalId' => $withdrawalId]);
            $res->json([
                'success'       => true,
                'message'       => 'Withdrawal request submitted successfully.',
                'withdrawal_id' => $withdrawalId,
                'new_balance'   => $newBalance,
            ]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });
}

/**
 * Terminal fallbacks.
 *
 * An unmatched /api/* path must answer as JSON. Letting it fall through to the static handler is
 * exactly how api/admin.php ended up serving PHP source to the admin console.
 */
function register_fallback_routes(Router $app) {
    $app->useMw('/api', function (Req $req, Res $res) {
        $res->status(404)->json(['error' => 'Unknown API endpoint.']);
    });
}
