<?php
/**
 * Teen Patti HTTP surface.
 *
 * Every handler calls tp_sweep() first. That is what replaces the 5-second setInterval: turn
 * timeouts, stuck tables, presence eviction, the post-hand reset, filler turns and the organic
 * traffic engine all advance on the traffic that reads the game. The client polls /state every
 * 2 seconds, so a table with someone at it advances just as promptly as it did on the timer.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../games/teenpatti.php';

function register_teenpatti_routes(Router $app) {

    // --- GET /api/teenpatti/rooms — lobby list ---
    $app->get('/api/teenpatti/rooms', function (Req $req, Res $res) {
        try {
            tp_sweep();
            $rooms = all('SELECT * FROM "TeenPattiRoom" ORDER BY "id" ASC');
            $result = [];
            foreach ($rooms as $r) {
                $seats = tp_get_seats($r['id']);

                $winnerName = null;
                if ($r['winner_seat'] !== null) {
                    foreach ($seats as $s) {
                        if ((int)$s['seat'] === (int)$r['winner_seat']) { $winnerName = $s['username']; break; }
                    }
                }

                $players = [];
                $playerCount = 0; $realPlayerCount = 0;
                foreach ($seats as $s) {
                    if (empty($s['username'])) continue;
                    $playerCount++;
                    if (empty($s['is_bot'])) $realPlayerCount++;
                    $players[] = [
                        'seat'     => (int)$s['seat'],
                        'username' => $s['username'],
                        'is_bot'   => (bool)$s['is_bot'],
                        'folded'   => (bool)$s['folded'],
                    ];
                }

                $result[] = [
                    'id'                => $r['id'],
                    'name'              => $r['name'],
                    'boot_amount'       => (float)$r['boot_amount'],
                    'status'            => $r['status'],
                    'pot'               => (float)$r['pot'],
                    'round'             => (int)$r['round'],
                    'winner_seat'       => $r['winner_seat'] === null ? null : (int)$r['winner_seat'],
                    'winner_name'       => $winnerName,
                    'players'           => $players,
                    'player_count'      => $playerCount,
                    'real_player_count' => $realPlayerCount,
                    'admin_rig'         => tp_room_admin_rig($r),
                ];
            }
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/join ---
    $app->post('/api/teenpatti/join', 'require_auth', function (Req $req, Res $res) {
        $roomId = $req->b('room_id');
        $username = acting_username($req);
        if (!js_truthy($roomId) || !js_truthy($username)) {
            $res->status(400)->json(['error' => 'room_id and username required.']);
            return;
        }

        try {
            tp_sweep();

            $user = get_or_create_user($username);
            if (!$user) { $res->status(400)->json(['error' => 'User not found.']); return; }

            $room = tp_get_room($roomId);
            if (!$room) { $res->status(404)->json(['error' => 'Room not found.']); return; }
            $seats = tp_get_seats($roomId);

            // Already in this room?
            foreach ($seats as $s) {
                if (!empty($s['username']) && strtolower($s['username']) === strtolower((string)$username)) {
                    $res->json(['success' => true, 'seat' => (int)$s['seat'], 'message' => 'Already in room.']);
                    return;
                }
            }

            // In another room? Leave it first.
            $otherSeat = one('SELECT * FROM "TeenPattiSeat" WHERE LOWER("username") = LOWER(?) AND "is_bot" = 0 LIMIT 1', [$username]);
            if ($otherSeat && $otherSeat['room_id'] !== $roomId) {
                tp_update_seat($otherSeat['id'], ['username' => null, 'is_bot' => 0, 'cards' => null, 'folded' => 0, 'last_seen_at' => null]);

                $oldRoomId = $otherSeat['room_id'];
                $oldSeats = tp_get_seats($oldRoomId);
                $oldRoomRealPlayers = array_values(array_filter($oldSeats, function ($s) use ($username) {
                    return !empty($s['username']) && empty($s['is_bot'])
                        && strtolower($s['username']) !== strtolower((string)$username);
                }));
                if (count($oldRoomRealPlayers) === 0) {
                    tp_reset_room_to_waiting($oldRoomId);
                }
            }

            if ((float)$user['wallet_balance'] < (float)$room['boot_amount']) {
                $res->status(400)->json([
                    'error' => 'Need at least ₹' . js_num_str((float)$room['boot_amount'])
                             . ' to join. Your balance: ₹' . js_num_str((float)$user['wallet_balance']),
                ]);
                return;
            }

            // Find an empty seat, or evict a filler to make room for a human player.
            $targetSeat = null;
            foreach ($seats as $s) { if (empty($s['username'])) { $targetSeat = $s; break; } }
            if (!$targetSeat) {
                foreach ($seats as $s) { if (!empty($s['is_bot'])) { $targetSeat = $s; break; } }
            }
            if (!$targetSeat) {
                $res->status(400)->json(['error' => 'Room is full with 4 real players.']);
                return;
            }

            tp_update_seat($targetSeat['id'], [
                'username' => $username,
                'is_bot'   => 0,
                'folded'   => 0,
                'cards'    => null,
                'balance'  => (float)$user['wallet_balance'],
                // Record the heartbeat AT JOIN, which server.js did not do.
                //
                // Not a gratuitous change — it is what keeps the observable behaviour the same.
                // In Node the presence sweep ran on its own 5-second interval, so a player who sat
                // down and then polled /state (the client does so every 2s) always recorded a
                // heartbeat before the sweeper next looked, and kept their seat. Here the sweep is
                // synchronous at the top of every Teen Patti request, so without this the player's
                // OWN next request evicts them before its handler can set the heartbeat, and nobody
                // can ever stay seated. The player has demonstrably just made a request, so
                // treating them as present is also simply true.
                'last_seen_at' => now_ms(),
            ]);

            $updatedSeats = tp_get_seats($roomId);
            $updatedRoom = tp_get_room($roomId);
            $occupiedCount = count(array_filter($updatedSeats, function ($s) { return !empty($s['username']); }));

            if (!cfg('TEENPATTI_AUTO_BOT_FILL')) {
                // Real players only, and mandatory full table: tp_start_round's own guard refuses
                // anything short of 4/4, so this is safe to attempt on every join and never pads
                // the table with filler names.
                if ($updatedRoom['status'] === 'waiting') {
                    tp_start_round($roomId);
                }
            } elseif ($updatedRoom['status'] === 'waiting') {
                // Bot-padded mode: top this room's bots up to its configured ambient target (never
                // straight to full capacity — see tp_apply_room_bot_target / lib/botengine.php).
                // tp_start_round's own mandatory-4 guard means none of these branches can deal a
                // hand early, however the remaining seats end up filled.
                $botCount = count(array_filter($updatedSeats, function ($s) { return !empty($s['username']) && !empty($s['is_bot']); }));
                $target = tp_room_bot_target($roomId);

                if ($occupiedCount >= 3) {
                    // Already real activity in this room — top up and try to start without the
                    // usual pause, same as the original "third player triggers an instant fill" feel.
                    tp_run_bot_fill($roomId);
                } elseif ($botCount < $target) {
                    // Below this room's ambient target — the usual 15s fill-and-deal pause, rather
                    // than surrounding a lone new arrival with bots instantly.
                    tp_schedule_bot_fill($roomId);
                } else {
                    tp_start_round($roomId); // no-ops unless this join happened to complete 4/4
                }
            }

            $res->json(['success' => true, 'seat' => (int)$targetSeat['seat']]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/leave ---
    $app->post('/api/teenpatti/leave', 'require_auth', function (Req $req, Res $res) {
        $roomId = $req->b('room_id');
        $username = acting_username($req);
        if (!js_truthy($roomId) || !js_truthy($username)) {
            $res->status(400)->json(['error' => 'room_id and username required.']);
            return;
        }

        try {
            $seat = one('SELECT * FROM "TeenPattiSeat" WHERE "room_id" = ? AND LOWER("username") = LOWER(?) AND "is_bot" = 0 LIMIT 1',
                        [$roomId, $username]);
            if (!$seat) { $res->json(['success' => true, 'message' => 'Not in room.']); return; }

            $room = tp_get_room($roomId);
            if ($room && $room['status'] === 'playing' && (int)$seat['folded'] === 0) {
                tp_process_action($roomId, $username, 'fold');
            }

            tp_update_seat($seat['id'], ['username' => null, 'is_bot' => 0, 'cards' => null, 'folded' => 0, 'last_seen_at' => null]);
            log_debug("[TP] {$username} left {$roomId}");

            $updatedSeats = tp_get_seats($roomId);
            $realPlayers = array_values(array_filter($updatedSeats, function ($s) {
                return !empty($s['username']) && empty($s['is_bot']);
            }));
            if (count($realPlayers) === 0) {
                tp_reset_room_to_waiting($roomId);
            }

            $res->json(['success' => true]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- GET /api/teenpatti/state — also the seat heartbeat ---
    $app->get('/api/teenpatti/state', function (Req $req, Res $res) {
        $roomId = $req->q('room_id');
        // Whose hole cards this response may include is decided by the session, not the query string.
        $username = acting_username($req);
        if (!is_string($username)) $username = '';
        if (!js_truthy($roomId)) { $res->status(400)->json(['error' => 'room_id required.']); return; }

        try {
            tp_sweep();

            $room = tp_get_room($roomId);
            if (!$room) { $res->status(404)->json(['error' => 'Room not found.']); return; }
            $seats = tp_get_seats($roomId);

            $isFinished = ($room['status'] === 'finished');

            $mySeat = null;
            if ($username !== '') {
                foreach ($seats as $s) {
                    if (!empty($s['username']) && strtolower($s['username']) === strtolower($username)) { $mySeat = $s; break; }
                }
            }

            if ($mySeat) {
                // This request IS the heartbeat that keeps the seat occupied.
                tp_update_seat($mySeat['id'], ['last_seen_at' => now_ms()]);
                if (empty($mySeat['is_bot'])) mark_user_active('teenpatti', $username);
            }

            $timeLeft = TP_TURN_TIMEOUT;
            if ($room['status'] === 'playing' && $room['turn_start'] !== null) {
                $elapsed = (now_ms() - sql_to_ms($room['turn_start'])) / 1000.0;
                $timeLeft = max(0, TP_TURN_TIMEOUT - (int) floor($elapsed));
            }

            $seatPayload = [];
            foreach ($seats as $s) {
                $isMe = $mySeat && (int)$s['seat'] === (int)$mySeat['seat'];
                $showCards = $isMe || $isFinished;
                $seatPayload[] = [
                    'seat'       => (int)$s['seat'],
                    'username'   => $s['username'],
                    'is_bot'     => (bool)$s['is_bot'],
                    'folded'     => (bool)$s['folded'],
                    'cards'      => $showCards ? tp_format_cards($s['cards']) : ($s['cards'] ? [null, null, null] : null),
                    'hand_label' => ($showCards && $s['cards']) ? tp_hand_label(tp_evaluate_hand($s['cards'])[0]) : null,
                    'is_me'      => $isMe ? true : false,
                ];
            }

            $walletBalance = 0;
            if ($username !== '') {
                $user = find_user_ci($username);
                $walletBalance = $user ? (float)$user['wallet_balance'] : 0;
            }

            $winnerName = null;
            if ($isFinished && $room['winner_seat'] !== null) {
                foreach ($seats as $s) {
                    if ((int)$s['seat'] === (int)$room['winner_seat']) { $winnerName = $s['username']; break; }
                }
            }

            $rig = tp_room_admin_rig($room);

            $res->json([
                'room_id'        => $room['id'],
                'name'           => $room['name'],
                'boot_amount'    => (float)$room['boot_amount'],
                'status'         => $room['status'],
                'pot'            => (float)$room['pot'],
                'current_stake'  => (float)$room['current_stake'],
                'turn_seat'      => $room['turn_seat'] === null ? null : (int)$room['turn_seat'],
                'time_left'      => $timeLeft,
                'round'          => (int)$room['round'],
                'winner_seat'    => $room['winner_seat'] === null ? null : (int)$room['winner_seat'],
                'winner_name'    => $winnerName,
                'seats'          => $seatPayload,
                'log'            => tp_room_log($room),
                'my_seat'        => $mySeat ? (int)$mySeat['seat'] : null,
                'wallet_balance' => $walletBalance,
                'admin_rig'      => $rig,
                'was_rigged'     => (bool)($isFinished && $rig !== null && array_key_exists('winner_seat', $rig)),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/action ---
    $app->post('/api/teenpatti/action', 'require_auth', function (Req $req, Res $res) {
        $roomId = $req->b('room_id');
        $action = $req->b('action');
        // Acting for another seat used to be a matter of typing their name into the request body.
        $username = acting_username($req);
        if (!js_truthy($roomId) || !js_truthy($username) || !js_truthy($action)) {
            $res->status(400)->json(['error' => 'room_id, username, and action required.']);
            return;
        }

        try {
            tp_sweep();
            $result = tp_process_action($roomId, $username, $action);
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/admin/rig — seat Admin and deal immediately ---
    $app->post('/api/teenpatti/admin/rig', 'require_admin', function (Req $req, Res $res) {
        $roomId = $req->b('room_id');
        if (!js_truthy($roomId)) { $res->status(400)->json(['error' => 'room_id required.']); return; }

        try {
            $seatIdx = js_parse_int($req->b('winner_seat'));
            $validSeat = is_nan($seatIdx) ? 0 : (int)$seatIdx;

            $adminUser = get_or_create_user('Admin');
            if ($adminUser) {
                q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [5000.0, (int)$adminUser['id']]);
            }

            // Remove "Admin" from any other seat in this room.
            q("UPDATE \"TeenPattiSeat\" SET \"username\" = NULL, \"is_bot\" = 0, \"cards\" = NULL, \"folded\" = 0
               WHERE \"room_id\" = ? AND \"username\" = 'Admin'", [$roomId]);

            // If the target seat is empty or held by a filler, sit "Admin" on it.
            $targetSeat = one('SELECT * FROM "TeenPattiSeat" WHERE "room_id" = ? AND "seat" = ? LIMIT 1', [$roomId, $validSeat]);
            if ($targetSeat && (empty($targetSeat['username']) || (int)$targetSeat['is_bot'] === 1)) {
                tp_update_seat($targetSeat['id'], [
                    'username' => 'Admin', 'is_bot' => 0, 'folded' => 0, 'cards' => null, 'balance' => 5000.0,
                ]);
            }

            // Set the rig and reset status so a fresh round is dealt.
            tp_update_room($roomId, ['status' => 'waiting', 'admin_rig' => js_json_encode(['winner_seat' => $validSeat])]);

            $room = tp_get_room($roomId);
            if ($room) {
                if (cfg('TEENPATTI_AUTO_BOT_FILL')) {
                    $seats = tp_get_seats($roomId);
                    $empty = array_values(array_filter($seats, function ($s) { return empty($s['username']); }));
                    $botIdx = 0;
                    foreach ($empty as $seat) {
                        if ($botIdx >= 4) break;
                        tp_update_seat($seat['id'], ['username' => random_filler_name(), 'is_bot' => 1, 'folded' => 0]);
                        $botIdx++;
                    }
                }
                // With Admin now seated, tp_start_round's own >= 2 minimum is all that's needed — real
                // players already at the table are enough; no filler padding in real-players-only mode.
                tp_start_round($roomId);
            }

            $res->json(['success' => true, 'room_id' => $roomId, 'winner_seat' => $validSeat]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/admin/reset-rig ---
    $app->post('/api/teenpatti/admin/reset-rig', 'require_admin', function (Req $req, Res $res) {
        $roomId = $req->b('room_id');
        if (!js_truthy($roomId)) { $res->status(400)->json(['error' => 'room_id required.']); return; }
        try {
            tp_update_room($roomId, ['admin_rig' => null]);
            $res->json(['success' => true, 'room_id' => $roomId]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- GET /api/teenpatti/admin/room-bots — per-room ambient bot-seat targets ---
    $app->get('/api/teenpatti/admin/room-bots', 'require_admin', function (Req $req, Res $res) {
        try {
            tp_sweep(); // make sure today's daily shuffle has already run before reporting state
            $res->json([
                'success'    => true,
                'seat_count' => TP_ROOM_SEAT_COUNT,
                'auto_fill_enabled' => (bool) cfg('TEENPATTI_AUTO_BOT_FILL'),
                'config'     => tp_room_bot_config(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });

    // --- POST /api/teenpatti/admin/room-bots — master mode toggle, or one room's manual target ---
    $app->post('/api/teenpatti/admin/room-bots', 'require_admin', function (Req $req, Res $res) {
        try {
            $action = (string) ($req->b('action') ?? 'set_room');

            if ($action === 'set_master') {
                // The single ON/OFF switch: OFF (false) = Auto, every room comes from the periodic
                // shuffle; ON (true) = Manual, the shuffle stops entirely and every room is exactly
                // what an operator sets below.
                //
                // Deliberately NOT js_truthy() here: the frontend always sends the literal string
                // "true" or "false", and js_truthy('false') is true — a non-empty string is truthy
                // in JS, faithfully reproduced by that helper — so a request to switch back to Auto
                // was silently being read as "stay Manual" and the toggle button appeared dead.
                $manual = ($req->b('master_manual') === 'true');
                $config = tp_room_bot_config();
                $config['master_manual'] = $manual;

                if ($manual) {
                    tp_room_bot_config_save($config);
                } else {
                    // Switching back to Auto: reshuffle right now rather than waiting out the
                    // usual interval to pick up a fresh distribution.
                    $config['last_shuffle_at'] = null;
                    tp_room_bot_config_save($config);
                    tp_maybe_shuffle_room_bot_targets();
                }

                foreach (tp_room_ids() as $roomId) { tp_apply_room_bot_target($roomId); }
                $res->json(['success' => true, 'config' => tp_room_bot_config()]);
                return;
            }

            // action === 'set_room' — only meaningful in Manual mode, but harmless either way: an
            // Auto-mode shuffle will simply overwrite it at the next reshuffle regardless.
            $roomId = (string) ($req->b('room_id') ?? '');
            if (!in_array($roomId, tp_room_ids(), true)) {
                $res->status(400)->json(['error' => 'Unknown room_id.']);
                return;
            }
            $target = js_parse_int($req->b('target'));
            if (!js_is_finite($target)) {
                $res->status(400)->json(['error' => 'target must be a number.']);
                return;
            }

            $config = tp_room_bot_config();
            $config['rooms'][$roomId]['target'] = max(0, min(TP_ROOM_SEAT_COUNT, (int) $target));
            tp_room_bot_config_save($config);

            // Reflect the new target on the room's actual seats immediately — an admin change
            // must not wait for the next organic traffic tick or a hand to end. A room currently
            // mid-hand is left alone by tp_apply_room_bot_target and picks up the new target the
            // moment it next resets to 'waiting' on its own.
            tp_apply_room_bot_target($roomId);

            $res->json(['success' => true, 'config' => tp_room_bot_config()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'teenpatti');
        }
    });
}
