<?php
/**
 * Astronaut HTTP surface.
 *
 * Placing and cancelling a bet both run inside one transaction holding the round's state row lock,
 * which is the same lock every phase transition takes. So "is the countdown still open?" and "take
 * the stake" cannot be split by the round launching in between, and the stake debit, the bet row
 * and its ledger row commit together or not at all.
 *
 * Cashing out does not need the round lock: the crash instant is fixed when the countdown opens, so
 * "is it still flying?" is a pure time check. What it needs is that a bet is paid once, which the
 * conditional pending -> won UPDATE in astro_settle_win() guarantees against a double-click, an auto
 * cash-out and the crash settlement all racing each other.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../games/astronaut.php';

/** A refusal raised inside a transaction, carried out to become a 400 after the rollback. */
class AstroRefusal extends RuntimeException {}

function astro_panel_param(Req $req) {
    $p = js_parse_int($req->b('panel'));
    return ($p === 1 || $p === 2) ? (int) $p : null;
}

function register_astronaut_routes(Router $app) {

    // --- GET /api/astronaut/state --- public, so the sky animates for visitors too.
    $app->get('/api/astronaut/state', function (Req $req, Res $res) {
        try {
            $config = astro_config_get();
            $state = astro_tick();
            $username = null; $balance = null;
            if ($req->auth) {
                $user = find_user_ci($req->auth['username']);
                if ($user) { $username = $user['username']; $balance = (float) $user['wallet_balance']; }
            }
            $out = astro_public_state($state, $username, $config);
            $out['balance'] = $balance;
            $res->json(['ok' => true, 'config' => astro_public_config($config), 'state' => $out]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- POST /api/astronaut/bet --- {panel, amount, auto_cashout?}
    $app->post('/api/astronaut/bet', 'require_auth', function (Req $req, Res $res) {
        $config = astro_config_get();
        if (!$config['enabled']) {
            $res->status(403)->json(['ok' => false, 'error' => 'Astronaut is currently unavailable.']);
            return;
        }
        $panel = astro_panel_param($req);
        if ($panel === null) { $res->status(400)->json(['ok' => false, 'error' => 'Invalid bet panel.']); return; }

        $stake = validate_stake($req->b('amount'));
        if (!$stake['ok']) { $res->status(400)->json(['ok' => false, 'error' => $stake['error']]); return; }
        $amount = $stake['value'];
        if ($amount < $config['min_bet']) {
            $res->status(400)->json(['ok' => false, 'error' => 'Minimum bet is ₹' . js_num_str($config['min_bet']) . '.']);
            return;
        }
        if ($amount > $config['max_bet']) {
            $res->status(400)->json(['ok' => false, 'error' => 'Maximum bet is ₹' . js_num_str($config['max_bet']) . '.']);
            return;
        }

        $auto = null;
        $rawAuto = $req->b('auto_cashout');
        if ($rawAuto !== null && $rawAuto !== '' && $rawAuto !== false) {
            $a = js_parse_float($rawAuto);
            if (!js_is_finite($a) || $a < 1.01 || $a > $config['max_multiplier']) {
                $res->status(400)->json(['ok' => false,
                    'error' => 'Auto cash-out must be between 1.01x and ' . js_num_str($config['max_multiplier']) . 'x.']);
                return;
            }
            $auto = floor($a * 100 + 1e-7) / 100;
        }

        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];

            $result = tx(function () use ($config, $user, $username, $panel, $amount, $auto) {
                $state = astro_advance_locked(astro_load(true), $config);
                astro_save($state);
                $closesAt = (int) $state['phase_start'] + ASTRO_BETTING_MS - ASTRO_BET_CUTOFF_MS;
                if ($state['phase'] !== 'betting' || now_ms() >= $closesAt) {
                    throw new AstroRefusal('Betting for this round has closed.');
                }
                $existing = one('SELECT "status" FROM "AstronautBet" WHERE "round_id" = ? AND LOWER("username") = LOWER(?) AND "panel" = ?',
                                [(int) $state['round_id'], $username, $panel]);
                if ($existing && $existing['status'] !== 'cancelled') {
                    throw new AstroRefusal('You already have a bet on this panel for this round.');
                }
                $balance = debit_wallet($user['id'], $amount);
                if ($balance === null) throw new AstroRefusal('Insufficient wallet balance.');
                if ($existing) {
                    // Re-betting a panel cancelled earlier in the same countdown reuses its row.
                    q('UPDATE "AstronautBet" SET "amount" = ?, "auto_cashout" = ?, "status" = \'pending\',
                              "cashout_mult" = NULL, "payout" = 0, "settled_at" = NULL, "created_at" = CURRENT_TIMESTAMP(3)
                       WHERE "round_id" = ? AND LOWER("username") = LOWER(?) AND "panel" = ?',
                      [$amount, $auto, (int) $state['round_id'], $username, $panel]);
                } else {
                    q('INSERT INTO "AstronautBet" ("round_id","username","panel","amount","auto_cashout") VALUES (?,?,?,?,?)',
                      [(int) $state['round_id'], $username, $panel, $amount, $auto]);
                }
                insert_transaction(new_record_id('ASTRO'), $username, 'Withdrawal', $amount,
                                   'Astronaut Bet Round #' . (int) $state['round_id'], 'Completed');
                return ['round_id' => (int) $state['round_id'], 'balance' => $balance];
            });

            $res->json(['ok' => true, 'round_id' => $result['round_id'], 'panel' => $panel,
                        'amount' => $amount, 'auto_cashout' => $auto, 'balance' => $result['balance']]);
        } catch (AstroRefusal $r) {
            $res->status(400)->json(['ok' => false, 'error' => $r->getMessage()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- POST /api/astronaut/cancel --- {panel}: withdraw a bet while the countdown is still open.
    $app->post('/api/astronaut/cancel', 'require_auth', function (Req $req, Res $res) {
        $panel = astro_panel_param($req);
        if ($panel === null) { $res->status(400)->json(['ok' => false, 'error' => 'Invalid bet panel.']); return; }
        try {
            $config = astro_config_get();
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];

            $result = tx(function () use ($config, $user, $username, $panel) {
                $state = astro_advance_locked(astro_load(true), $config);
                astro_save($state);
                if ($state['phase'] !== 'betting') throw new AstroRefusal('The round has already launched.');
                $bet = one('SELECT * FROM "AstronautBet" WHERE "round_id" = ? AND LOWER("username") = LOWER(?) AND "panel" = ?
                            AND "status" = \'pending\'', [(int) $state['round_id'], $username, $panel]);
                if (!$bet) throw new AstroRefusal('No bet to cancel on this panel.');
                q('UPDATE "AstronautBet" SET "status" = \'cancelled\', "settled_at" = CURRENT_TIMESTAMP(3) WHERE "id" = ?',
                  [(int) $bet['id']]);
                $balance = credit_wallet($user['id'], (float) $bet['amount']);
                insert_transaction(new_record_id('ASTRO_REF'), $username, 'Deposit', (float) $bet['amount'],
                                   'Astronaut Bet Cancelled Round #' . (int) $state['round_id'], 'Completed');
                return ['balance' => $balance, 'amount' => (float) $bet['amount']];
            });
            $res->json(['ok' => true, 'panel' => $panel, 'refund' => $result['amount'], 'balance' => $result['balance']]);
        } catch (AstroRefusal $r) {
            $res->status(400)->json(['ok' => false, 'error' => $r->getMessage()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- POST /api/astronaut/cashout --- {panel}
    $app->post('/api/astronaut/cashout', 'require_auth', function (Req $req, Res $res) {
        $panel = astro_panel_param($req);
        if ($panel === null) { $res->status(400)->json(['ok' => false, 'error' => 'Invalid bet panel.']); return; }
        try {
            $config = astro_config_get();
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];

            $state = astro_tick();
            $now = now_ms();
            if ($state['phase'] !== 'flying' || $now >= astro_crash_at($state)) {
                $res->status(400)->json(['ok' => false, 'error' => 'Too late — the astronaut is gone.']);
                return;
            }
            $bet = one('SELECT * FROM "AstronautBet" WHERE "round_id" = ? AND LOWER("username") = LOWER(?) AND "panel" = ?',
                       [(int) $state['round_id'], $username, $panel]);
            if (!$bet || $bet['status'] !== 'pending') {
                if ($bet && $bet['status'] === 'won') {
                    // Already paid — most often an auto cash-out that landed a moment before the click.
                    $res->json(['ok' => true, 'panel' => $panel, 'multiplier' => (float) $bet['cashout_mult'],
                                'payout' => (float) $bet['payout'], 'balance' => (float) find_user_by_id($user['id'])['wallet_balance'],
                                'already' => true]);
                    return;
                }
                $res->status(400)->json(['ok' => false, 'error' => 'No active bet on this panel.']);
                return;
            }

            $mult = astro_mult_now($state, $now);
            // A manual click above the bet's own auto target (the poll lagged) is paid at the target,
            // which is what the auto cash-out would have paid had it been settled a moment sooner.
            $mult = min($mult, astro_effective_target($bet, $config));

            $win = tx(function () use ($bet, $mult, $config) { return astro_settle_win($bet, $mult, $config, ''); });
            if ($win === null) {
                $fresh = one('SELECT * FROM "AstronautBet" WHERE "id" = ?', [(int) $bet['id']]);
                if ($fresh && $fresh['status'] === 'won') {
                    $res->json(['ok' => true, 'panel' => $panel, 'multiplier' => (float) $fresh['cashout_mult'],
                                'payout' => (float) $fresh['payout'], 'balance' => (float) find_user_by_id($user['id'])['wallet_balance'],
                                'already' => true]);
                    return;
                }
                $res->status(400)->json(['ok' => false, 'error' => 'Too late — the astronaut is gone.']);
                return;
            }
            $res->json(['ok' => true, 'panel' => $panel, 'multiplier' => $win['multiplier'],
                        'payout' => $win['payout'], 'balance' => $win['balance']]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- GET /api/astronaut/my-bets --- the player's last 30 settled bets.
    $app->get('/api/astronaut/my-bets', 'require_auth', function (Req $req, Res $res) {
        try {
            $rows = all('SELECT "round_id","panel","amount","status","cashout_mult","payout","created_at" FROM "AstronautBet"
                         WHERE LOWER("username") = LOWER(?) AND "status" IN (\'won\',\'lost\')
                         ORDER BY "id" DESC LIMIT 30', [acting_username($req)]);
            $res->json(['ok' => true, 'bets' => array_map(function ($b) {
                return [
                    'round_id' => (int) $b['round_id'], 'panel' => (int) $b['panel'], 'amount' => (float) $b['amount'],
                    'status' => $b['status'], 'cashout_mult' => $b['cashout_mult'] !== null ? (float) $b['cashout_mult'] : null,
                    'payout' => (float) $b['payout'], 'created_at' => $b['created_at'],
                ];
            }, $rows)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- GET /api/astronaut/top --- the day's biggest cash-out multipliers, masked.
    $app->get('/api/astronaut/top', function (Req $req, Res $res) {
        try {
            $rows = all('SELECT "username","amount","cashout_mult","payout","round_id" FROM "AstronautBet"
                         WHERE "status" = \'won\' AND "created_at" > CURRENT_TIMESTAMP(3) - INTERVAL \'1 day\'
                         ORDER BY "cashout_mult" DESC, "payout" DESC LIMIT 20');
            $res->json(['ok' => true, 'top' => array_map(function ($b) {
                return ['player' => astro_mask_name($b['username']), 'amount' => (float) $b['amount'],
                        'cashout_mult' => (float) $b['cashout_mult'], 'payout' => (float) $b['payout'],
                        'round_id' => (int) $b['round_id']];
            }, $rows)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    // --- Operator settings ---
    $app->get('/api/admin/astronaut/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['ok' => true, 'config' => astro_config_get()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });

    $app->post('/api/admin/astronaut/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $patch = [];
            foreach (['enabled', 'rtp', 'max_multiplier', 'max_win', 'min_bet', 'max_bet'] as $k) {
                if ($req->b($k) !== null) $patch[$k] = $req->b($k);
            }
            $r = astro_config_update($patch);
            if (!$r['ok']) { $res->status(400)->json($r); return; }
            $res->json($r);
        } catch (Throwable $err) {
            fail500($res, $err, 'astronaut');
        }
    });
}
