<?php
/**
 * Tower Rush HTTP surface. Money-safety follows routes/chickenroad.php (itself from routes/mines.php):
 *
 *   - The first BUILD claims the player's single tower with a unique-index INSERT BEFORE debiting,
 *     so a double-clicked BUILD takes one stake, not two.
 *   - Every later BUILD and the CASHOUT move the row with a conditional UPDATE keyed on
 *     (status, floors). Of two racing requests exactly one changes the row; the other is refused.
 *   - A failed stake ledger write refunds the debit; a failed payout ledger write is logged loudly
 *     (the money was legitimately won and stands).
 *
 * Floors are decided at the first BUILD from the round's seed (games/towerrush.php); the seed never
 * leaves the server until the tower is finished.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../games/towerrush.php';

function tr_fmt_mult($m) {
    $s = rtrim(rtrim(number_format((float) $m, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/** Credit a finished tower and write its ledger row. Returns the balance after the credit. */
function tr_pay($user, $username, $payout, $multiplier, $auto) {
    $balance = credit_wallet($user['id'], $payout);
    try {
        insert_transaction(new_record_id('TOWER_WIN'), $username, 'Deposit', $payout,
            'Tower Rush Cash Out — x' . tr_fmt_mult($multiplier) . ($auto ? ' (max win)' : ''), 'Completed');
    } catch (Throwable $ledgerErr) {
        log_error('TOWER RUSH PAYOUT LEDGER WRITE FAILED - wallet credited without a ledger row', [
            'username' => $username, 'payout' => $payout, 'message' => $ledgerErr->getMessage(),
        ]);
    }
    return $balance;
}

function register_towerrush_routes(Router $app) {

    // --- GET /api/towerrush/state --- open to visitors too, so the street draws before sign-in.
    $app->get('/api/towerrush/state', function (Req $req, Res $res) {
        try {
            $config = tr_config_get();
            if (!$req->auth) {
                $res->json(['ok' => true, 'signed_in' => false, 'config' => tr_public_config($config),
                            'state' => tr_public_state(null, $config, null)]);
                return;
            }
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $res->json([
                'ok'        => true,
                'signed_in' => true,
                'config'    => tr_public_config($config),
                'state'     => tr_public_state(tr_session_get($user['username']), $config, (float) $user['wallet_balance']),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'towerrush');
        }
    });

    /**
     * --- POST /api/towerrush/build ---
     *   new tower:  {start: true, bet_amount, after_round}   after_round = round_ref of the last
     *               tower the page saw (null if none); a stale or duplicate click is refused (409).
     *   next floor: {round_ref, floors}                      must name the live tower and its height.
     * Every BUILD says which tower it means, so a double-click can never pay for a second tower or
     * add a floor to the wrong one.
     */
    $app->post('/api/towerrush/build', 'require_auth', function (Req $req, Res $res) {
        $config = tr_config_get();
        $username = null;
        $claimed = false;
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];
            $s = tr_session_get($username);
            $balance = (float) $user['wallet_balance'];
            $start = js_truthy($req->b('start'));

            if ($start) {
                if ($s && in_array($s['status'], ['active', 'starting'], true)) {
                    $res->status(409)->json(['ok' => false, 'error' => 'Your tower is already being built.']);
                    return;
                }
                $after = $req->b('after_round');
                $after = is_string($after) && $after !== '' ? $after : null;
                if (($s ? $s['round_ref'] : null) !== $after) {
                    $res->status(409)->json(['ok' => false, 'error' => 'That tower is finished. Refreshing…']);
                    return;
                }
            } else {
                if (!$s || $s['status'] !== 'active' || $s['round_ref'] !== $req->b('round_ref')
                    || js_parse_int($req->b('floors')) !== $s['floors']) {
                    $res->status(409)->json(['ok' => false, 'error' => 'The tower already moved on. Refreshing…']);
                    return;
                }
            }

            if ($start) {
                // ---- a new tower: the stake goes down with the first floor ----
                if (!$config['enabled']) {
                    $res->status(403)->json(['ok' => false, 'error' => 'Tower Rush is currently unavailable.']);
                    return;
                }
                $stake = validate_stake($req->b('bet_amount'));
                if (!$stake['ok']) { $res->status(400)->json(['ok' => false, 'error' => $stake['error']]); return; }
                $bet = $stake['value'];
                if ($bet < $config['min_bet']) {
                    $res->status(400)->json(['ok' => false, 'error' => 'Minimum bet is ₹' . js_num_str($config['min_bet']) . '.']);
                    return;
                }
                if ($bet > $config['max_bet']) {
                    $res->status(400)->json(['ok' => false, 'error' => 'Maximum bet is ₹' . js_num_str($config['max_bet']) . '.']);
                    return;
                }
                if (!tr_session_claim($username, $after)) {
                    $res->status(409)->json(['ok' => false, 'error' => 'Your tower is already being built.']);
                    return;
                }
                $claimed = true;
                $afterDebit = debit_wallet($user['id'], $bet);
                if ($afterDebit === null) {
                    tr_session_release($username);
                    $res->status(400)->json(['ok' => false,
                        'error' => 'Insufficient balance! You have ₹' . js_to_fixed($balance, 2) . '.']);
                    return;
                }
                try {
                    insert_transaction(new_record_id('TOWER'), $username, 'Withdrawal', $bet, 'Tower Rush Bet', 'Completed');
                } catch (Throwable $ledgerErr) {
                    log_error('tower rush stake ledger write failed - refunding the debit',
                              ['username' => $username, 'bet' => $bet, 'message' => $ledgerErr->getMessage()]);
                    try { credit_wallet($user['id'], $bet); }
                    catch (Throwable $refundErr) {
                        log_error('TOWER RUSH REFUND FAILED - player is short and needs manual correction',
                                  ['username' => $username, 'bet' => $bet, 'message' => $refundErr->getMessage()]);
                    }
                    tr_session_release($username);
                    $res->status(500)->json(['ok' => false, 'error' => 'Could not start the round. Your stake was not taken.']);
                    return;
                }
                $seed = bin2hex(random_bytes(16));
                tr_session_activate($username, [
                    'bet_amount'  => $bet,
                    'server_seed' => $seed,
                    'seed_hash'   => hash('sha256', $seed),
                    'round_ref'   => strtoupper(substr(hash('sha256', 'ref:' . $seed), 0, 12)),
                    'rtp'         => $config['rtp'],
                ]);
                $claimed = false;
                $balance = $afterDebit;
                $s = tr_session_get($username);
            }

            // ---- the next floor ----
            $from = $s['floors'];
            $n = $from + 1;
            $f = tr_floor_factor($s['server_seed'], $n, $s['rtp']);
            if ($f <= 0) {
                $factors = array_merge($s['factors'], [0]);
                if (!tr_session_advance($username, $from, 'busted', $from, $factors, 0, 0)) {
                    $res->status(409)->json(['ok' => false, 'error' => 'The tower already moved on. Refreshing…']);
                    return;
                }
                $res->json(['ok' => true, 'outcome' => 'collapse', 'factor' => 0,
                    'state' => tr_public_state(tr_session_get($username), $config, $balance)]);
                return;
            }

            $factors = array_merge($s['factors'], [$f]);
            $mult = tr_product($factors);
            $value = tr_value($s['bet_amount'], $mult, $config);
            $capped = $value >= $config['max_win'];
            if (!tr_session_advance($username, $from, $capped ? 'cashed' : 'active', $n, $factors, $mult, $capped ? $value : 0)) {
                $res->status(409)->json(['ok' => false, 'error' => 'The tower already moved on. Refreshing…']);
                return;
            }
            if ($capped) $balance = tr_pay($user, $username, $value, $mult, true);
            $res->json([
                'ok'      => true,
                'outcome' => $capped ? 'max_win' : 'floor',
                'factor'  => $f,
                'state'   => tr_public_state(tr_session_get($username), $config, $balance),
            ]);
        } catch (Throwable $err) {
            if ($claimed && $username !== null) tr_session_release($username);
            fail500($res, $err, 'towerrush');
        }
    });

    // --- POST /api/towerrush/cashout ---
    $app->post('/api/towerrush/cashout', 'require_auth', function (Req $req, Res $res) {
        try {
            $config = tr_config_get();
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];
            $s = tr_session_get($username);
            if (!$s || $s['status'] !== 'active') {
                $res->status(400)->json(['ok' => false, 'error' => 'No tower to cash out.']);
                return;
            }
            if ($s['floors'] < 1) {
                $res->status(400)->json(['ok' => false, 'error' => 'Build at least one floor before cashing out.']);
                return;
            }
            $value = tr_value($s['bet_amount'], $s['multiplier'], $config);
            if (!tr_session_advance($username, $s['floors'], 'cashed', $s['floors'], $s['factors'], $s['multiplier'], $value)) {
                $res->status(400)->json(['ok' => false, 'error' => 'No tower to cash out.']);
                return;
            }
            $balance = tr_pay($user, $username, $value, $s['multiplier'], false);
            $res->json(['ok' => true, 'payout' => $value,
                'state' => tr_public_state(tr_session_get($username), $config, $balance)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'towerrush');
        }
    });

    // --- Operator settings ---
    $app->get('/api/admin/towerrush/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['ok' => true, 'config' => tr_config_get()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'towerrush');
        }
    });

    $app->post('/api/admin/towerrush/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $patch = [];
            foreach (['enabled', 'rtp', 'max_win', 'min_bet', 'max_bet'] as $k) {
                if ($req->b($k) !== null) $patch[$k] = $req->b($k);
            }
            $r = tr_config_update($patch);
            if (!$r['ok']) { $res->status(400)->json($r); return; }
            $res->json($r);
        } catch (Throwable $err) {
            fail500($res, $err, 'towerrush');
        }
    });
}
