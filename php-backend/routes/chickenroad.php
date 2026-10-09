<?php
/**
 * Chicken Road HTTP surface.
 *
 * Money-safety follows routes/mines.php, which learned each of these the hard way:
 *
 *   - PLAY claims the player's single road with a unique-index INSERT BEFORE debiting, so a
 *     double-clicked Play takes one stake, not two.
 *   - GO and CASH OUT both move the row with a conditional UPDATE keyed on (status, step). Of two
 *     racing requests exactly one changes the row; the other is refused. A cash-out can therefore
 *     never be paid twice, and a GO can never land after a cash-out was paid.
 *   - The stake debit is followed by its ledger row; if that write fails the debit is refunded,
 *     because a debit with no ledger row is money that silently disappeared.
 *
 * The fire lane is decided at PLAY from the round's seed (games/chickenroad.php) and never leaves
 * the server until the road is over.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../games/chickenroad.php';

/** Pay out a finished road and write its ledger row. Returns the balance after the credit. */
function cr_pay($user, $username, $payout, $multiplier, $goldenEgg) {
    $balance = credit_wallet($user['id'], $payout);
    try {
        insert_transaction(new_record_id('CROAD_WIN'), $username, 'Deposit', $payout,
            'Chicken Road Cash Out — ' . js_to_fixed($multiplier, 2) . 'x' . ($goldenEgg ? ' (Golden Egg)' : ''),
            'Completed');
    } catch (Throwable $ledgerErr) {
        // The credit landed and was legitimately won, so it stands; log loudly for reconciliation.
        log_error('CHICKEN ROAD PAYOUT LEDGER WRITE FAILED - wallet credited without a ledger row', [
            'username' => $username, 'payout' => $payout, 'message' => $ledgerErr->getMessage(),
        ]);
    }
    return $balance;
}

function register_chickenroad_routes(Router $app) {

    // --- GET /api/chickenroad/state ---
    $app->get('/api/chickenroad/state', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $config = cr_config_get();
            $session = cr_session_get($user['username']);
            $res->json([
                'ok'     => true,
                'config' => cr_public_config($config),
                'state'  => cr_public_state($session, $config, (float) $user['wallet_balance']),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'chickenroad');
        }
    });

    // --- POST /api/chickenroad/start --- place the bet; the hen waits on the kerb for the first GO.
    $app->post('/api/chickenroad/start', 'require_auth', function (Req $req, Res $res) {
        $config = cr_config_get();
        if (!$config['enabled']) {
            $res->status(403)->json(['ok' => false, 'error' => 'Chicken Road is currently unavailable.']);
            return;
        }

        $difficulty = strtolower(trim((string) ($req->b('difficulty') ?? 'easy')));
        if (!isset(cr_difficulties()[$difficulty])) {
            $res->status(400)->json(['ok' => false, 'error' => 'Choose a difficulty: Easy, Medium, Hard or Hardcore.']);
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

        $username = null;
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];

            if (!cr_session_claim($username)) {
                $res->status(400)->json(['ok' => false, 'error' => 'You already have a round in progress.']);
                return;
            }

            $balance = debit_wallet($user['id'], $bet);
            if ($balance === null) {
                cr_session_release($username);
                $res->status(400)->json([
                    'ok' => false,
                    'error' => 'Insufficient balance! You have ₹' . js_to_fixed((float) $user['wallet_balance'], 2) . '.',
                ]);
                return;
            }

            try {
                insert_transaction(new_record_id('CROAD'), $username, 'Withdrawal', $bet,
                    'Chicken Road Bet — ' . cr_difficulties()[$difficulty]['label'], 'Completed');
            } catch (Throwable $ledgerErr) {
                log_error('chicken road stake ledger write failed - refunding the debit',
                          ['username' => $username, 'bet' => $bet, 'message' => $ledgerErr->getMessage()]);
                try { credit_wallet($user['id'], $bet); }
                catch (Throwable $refundErr) {
                    log_error('CHICKEN ROAD REFUND FAILED - player is short and needs manual correction',
                              ['username' => $username, 'bet' => $bet, 'message' => $refundErr->getMessage()]);
                }
                cr_session_release($username);
                $res->status(500)->json(['ok' => false, 'error' => 'Could not start the round. Your stake was not taken.']);
                return;
            }

            $seed = bin2hex(random_bytes(16));
            $session = [
                'difficulty'  => $difficulty,
                'bet_amount'  => $bet,
                'fail_step'   => cr_fail_step($seed, $difficulty),
                'server_seed' => $seed,
                'seed_hash'   => hash('sha256', $seed),
                'round_ref'   => strtoupper(substr(hash('sha256', 'ref:' . $seed), 0, 12)),
            ];
            cr_session_activate($username, $session);

            $res->json(['ok' => true, 'state' => cr_public_state(cr_session_get($username), $config, $balance)]);
        } catch (Throwable $err) {
            // Never strand a half-claimed slot: it would lock the player out of the game for good.
            if ($username !== null) cr_session_release($username);
            fail500($res, $err, 'chickenroad');
        }
    });

    // --- POST /api/chickenroad/step --- GO: hop into the next lane.
    $app->post('/api/chickenroad/step', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];
            $config = cr_config_get();

            $s = cr_session_get($username);
            if (!$s || $s['status'] !== 'active') {
                $res->status(400)->json(['ok' => false, 'error' => 'No active round.']);
                return;
            }

            $lanes = cr_lane_count($s['difficulty']);
            $from = $s['step'];
            $to = $from + 1;
            if ($to > $lanes) {
                $res->status(400)->json(['ok' => false, 'error' => 'The road is already crossed.']);
                return;
            }

            if ($s['fail_step'] === $to) {
                if (!cr_session_advance($username, $from, 'busted', $from, 0, 0)) {
                    $res->status(409)->json(['ok' => false, 'error' => 'Round already moved on. Refreshing…']);
                    return;
                }
                $user = find_user_by_id($user['id']);
                $res->json(['ok' => true, 'outcome' => 'fire',
                    'state' => cr_public_state(cr_session_get($username), $config, (float) $user['wallet_balance'])]);
                return;
            }

            $mult = cr_multiplier($s['difficulty'], $to, $config['rtp']);
            $payout = cr_payout($s['bet_amount'], $s['difficulty'], $to, $config);
            $goldenEgg = $to === $lanes;
            // The far kerb, or the operator's cap, ends the round as a win on the same UPDATE that
            // records the lane — there is no window between "safe" and "paid" for anything to race.
            $capped = $payout >= $config['max_win'];
            $finish = $goldenEgg || $capped;

            if (!cr_session_advance($username, $from, $finish ? 'cashed' : 'active', $to, $mult, $finish ? $payout : 0)) {
                $res->status(409)->json(['ok' => false, 'error' => 'Round already moved on. Refreshing…']);
                return;
            }

            $balance = (float) $user['wallet_balance'];
            if ($finish) $balance = cr_pay($user, $username, $payout, $mult, $goldenEgg);

            $res->json([
                'ok'      => true,
                'outcome' => $goldenEgg ? 'golden_egg' : ($capped ? 'max_win' : 'safe'),
                'state'   => cr_public_state(cr_session_get($username), $config, $balance),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'chickenroad');
        }
    });

    // --- POST /api/chickenroad/cashout ---
    $app->post('/api/chickenroad/cashout', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $username = $user['username'];
            $config = cr_config_get();

            $s = cr_session_get($username);
            if (!$s || $s['status'] !== 'active') {
                $res->status(400)->json(['ok' => false, 'error' => 'No active round to cash out.']);
                return;
            }
            if ($s['step'] < 1) {
                $res->status(400)->json(['ok' => false, 'error' => 'Cross at least one lane before cashing out.']);
                return;
            }

            $mult = cr_multiplier($s['difficulty'], $s['step'], $config['rtp']);
            $payout = cr_payout($s['bet_amount'], $s['difficulty'], $s['step'], $config);

            // Same row, same step, active -> cashed: the one statement that decides who gets paid.
            if (!cr_session_advance($username, $s['step'], 'cashed', $s['step'], $mult, $payout)) {
                $res->status(400)->json(['ok' => false, 'error' => 'No active round to cash out.']);
                return;
            }

            $balance = cr_pay($user, $username, $payout, $mult, false);
            $res->json(['ok' => true, 'payout' => $payout,
                'state' => cr_public_state(cr_session_get($username), $config, $balance)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'chickenroad');
        }
    });

    // --- Operator settings ---
    $app->get('/api/admin/chickenroad/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['ok' => true, 'config' => cr_config_get()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'chickenroad');
        }
    });

    $app->post('/api/admin/chickenroad/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $patch = [];
            foreach (['enabled', 'rtp', 'max_win', 'min_bet', 'max_bet'] as $k) {
                if ($req->b($k) !== null) $patch[$k] = $req->b($k);
            }
            $r = cr_config_update($patch);
            if (!$r['ok']) { $res->status(400)->json($r); return; }
            $res->json($r);
        } catch (Throwable $err) {
            fail500($res, $err, 'chickenroad');
        }
    });
}
