<?php
/**
 * Colour Prediction — four rooms (Sapre 30s, Becone 60s, Emred 180s, VIP 300s).
 *
 * Settlement is driven by whoever polls first, exactly as in the Node build: there is no timer,
 * the round that just ended is settled by the next request that notices it has ended. That was
 * already the design, so this part of the game needed no redesign for shared hosting at all.
 *
 * CONCURRENCY. All four rooms' bets, history and last-settled markers live in ONE GameState row,
 * and every caller reads the whole blob, mutates it, and writes it back. Two requests overlapping
 * in that window both read the same snapshot and the second write silently discards the first —
 * and because the stake is debited BEFORE the state is read, the player pays and their bet simply
 * vanishes, never settles, and is never paid out. Load testing caught exactly that: a player bet
 * Big on a round that came up Big and received nothing.
 *
 * server.js closed the window with an in-process promise queue (withColorState). That works for one
 * Node process and nothing else. Here the equivalent is a real row lock — SELECT ... FOR UPDATE
 * inside a transaction — which is strictly stronger, because it also holds across the separate PHP
 * processes a shared host will run.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../lib/rigaudit.php';

const COLOR_STATE_KEY = 'color_guess_ongoing';

function color_durations() {
    return ['sapre' => 30, 'becone' => 60, 'emred' => 180, 'vip' => 300];
}

function color_duration($room) {
    $d = color_durations();
    return $d[$room] ?? 30;
}

/** Number -> colour, dot class and size. */
function resolve_color_number($num) {
    $num = (int)$num;
    if ($num === 0) return ['color' => 'Violet', 'dotClass' => 'violet', 'size' => 'Small'];
    if ($num === 5) return ['color' => 'Violet', 'dotClass' => 'violet', 'size' => 'Big'];
    if (in_array($num, [1, 3, 7, 9], true)) {
        return ['color' => 'Green', 'dotClass' => 'green', 'size' => $num >= 5 ? 'Big' : 'Small'];
    }
    return ['color' => 'Red', 'dotClass' => 'red', 'size' => $num >= 5 ? 'Big' : 'Small'];
}

/**
 * Round id: YYYYMMDDHH + a zero-padded 3-digit bucket index within the hour, all computed in UTC.
 *
 * Note the mixed time bases this produces in a single response: round ids are UTC while the
 * history timestamps beside them are server-local HH:MM:SS. That is faithful to the original.
 */
function get_color_round_id($room, $timestampSec) {
    $duration = color_duration($room);
    $roundStart = (int) (floor($timestampSec / $duration) * $duration);

    $d = (new DateTimeImmutable('@' . $roundStart))->setTimezone(new DateTimeZone('UTC'));
    $yyyy = $d->format('Y');
    $mm   = $d->format('m');
    $dd   = $d->format('d');
    $hh   = $d->format('H');

    $bucket = (int) floor(($roundStart % 3600) / $duration);
    $bucketStr = str_pad((string)$bucket, 3, '0', STR_PAD_LEFT);

    return $yyyy . $mm . $dd . $hh . $bucketStr;
}

/**
 * The exact profit-maximising outcome across all ten numbers.
 *
 * `targetedUsernames`, when supplied, scopes the payout calculation to ONLY that subset of bettors
 * (the bot's currently-targeted live players), so best_number/max_profit reflect the number that
 * maximises house profit against just them rather than against the whole room. Omitting it keeps
 * the original whole-room behaviour used by every manual-override call site.
 */
function color_optimal_outcome($bets, $roundSeed, $targetedUsernames = null) {
    $roundBets = is_array($bets) ? array_values($bets) : [];

    $targeted = null;
    if (is_array($targetedUsernames) && count($targetedUsernames) > 0) {
        $targeted = [];
        foreach ($targetedUsernames as $u) $targeted[strtolower((string)$u)] = true;
    }
    $scopedBets = $targeted === null ? $roundBets : array_values(array_filter($roundBets,
        function ($b) use ($targeted) { return isset($targeted[strtolower((string)($b['username'] ?? ''))]); }));

    $totalVolume = 0.0;
    foreach ($roundBets as $b) { $v = js_parse_float($b['amount'] ?? 0); $totalVolume += is_nan($v) ? 0 : $v; }
    $scopedVolume = 0.0;
    foreach ($scopedBets as $b) { $v = js_parse_float($b['amount'] ?? 0); $scopedVolume += is_nan($v) ? 0 : $v; }

    $outcomes = [];
    for ($n = 0; $n <= 9; $n++) {
        $resolved = resolve_color_number($n);
        $playerPayout = 0.0;
        foreach ($scopedBets as $b) {
            $amt = js_parse_float($b['amount'] ?? 0);
            if (is_nan($amt)) $amt = 0.0;
            $cat = $b['category'] ?? null;
            $val = $b['value'] ?? null;
            if ($cat === 'color') {
                if ($val === $resolved['color']) $playerPayout += $amt * ($val === 'Violet' ? 4.5 : 2.0);
            } elseif ($cat === 'number') {
                $parsed = js_parse_int($val);
                if (!is_nan($parsed) && (int)$parsed === $n) $playerPayout += $amt * 9.0;
            } elseif ($cat === 'size') {
                if ($val === $resolved['size']) $playerPayout += $amt * 2.0;
            }
        }
        $outcomes[] = [
            'number'        => $n,
            'color'         => $resolved['color'],
            'dotClass'      => $resolved['dotClass'],
            'size'          => $resolved['size'],
            'playerPayout'  => to_fixed_num($playerPayout, 2),
            'adminProfit'   => to_fixed_num($scopedVolume - $playerPayout, 2),
        ];
    }

    $profits = array_map(function ($o) { return $o['adminProfit']; }, $outcomes);
    $maxProfit = max($profits);
    $minProfit = min($profits);

    $bestCandidates  = array_values(array_filter($outcomes, function ($o) use ($maxProfit) { return $o['adminProfit'] === $maxProfit; }));
    $worstCandidates = array_values(array_filter($outcomes, function ($o) use ($minProfit) { return $o['adminProfit'] === $minProfit; }));

    // Pick deterministically among equally profitable choices using the round seed.
    $seedTail = substr((string)($roundSeed ?? ''), -5);
    $seedParsed = js_parse_int($seedTail);
    $roundSeedNum = (is_nan($seedParsed) || !js_truthy($seedParsed)) ? 0 : (int)$seedParsed;

    $best  = count($bestCandidates)  ? $bestCandidates[$roundSeedNum % count($bestCandidates)] : $outcomes[0];
    $worst = count($worstCandidates) ? $worstCandidates[0] : $outcomes[0];

    return [
        'total_volume'      => to_fixed_num($totalVolume, 2),
        'total_bets_count'  => count($roundBets),
        'scoped_volume'     => to_fixed_num($scopedVolume, 2),
        'scoped_bets_count' => count($scopedBets),
        'best_number'       => $best['number'],
        'best_color'        => $best['color'],
        'best_size'         => $best['size'],
        'max_profit'        => $best['adminProfit'],
        'min_payout'        => $best['playerPayout'],
        'worst_number'      => $worst['number'],
        'worst_loss'        => $worst['playerPayout'],
        'outcomes'          => $outcomes,   // index 0..9 for fast lookup
    ];
}

/** Ten plausible past rounds, derived from the round ids themselves, so a fresh room is not empty. */
function generate_initial_seed_history($room, $currentSec) {
    $dur = color_duration($room);
    $history = [];
    for ($i = 10; $i >= 1; $i--) {
        $pastSec = $currentSec - ($i * $dur);
        $rId = get_color_round_id($room, $pastSec);
        $seedParsed = js_parse_int(substr($rId, -5));
        $seedNum = (is_nan($seedParsed) || !js_truthy($seedParsed)) ? 0 : (int)$seedParsed;
        $num = $seedNum % 10;
        $res = resolve_color_number($num);
        $history[] = [
            'roundNumber' => $rId,
            'number'      => $num,
            'color'       => $res['color'],
            'dotClass'    => $res['dotClass'],
            'size'        => $res['size'],
            'is_rigged'   => false,
            'rig_desc'    => 'Natural Draw',
            'timestamp'   => js_locale_time($pastSec * 1000),
        ];
    }
    return $history;
}

function color_empty_room() {
    return ['last_settled_round' => '', 'bets' => [], 'overrides' => [], 'history' => []];
}

/**
 * Load the colour state, seeding it on first use.
 *
 * MUST be called inside color_with_state() so the row is locked; loading it unlocked and writing it
 * back is precisely the race this game had.
 */
function load_color_state() {
    $state = state_get_for_update(COLOR_STATE_KEY);
    $nowSec = (int) floor(now_ms() / 1000);

    if (is_array($state)) {
        $updated = false;
        foreach (color_rooms() as $r) {
            if (!isset($state[$r]) || !is_array($state[$r])) { $state[$r] = color_empty_room(); }
            if (empty($state[$r]['history'])) {
                $state[$r]['history'] = generate_initial_seed_history($r, $nowSec);
                $updated = true;
            }
        }
        if ($updated) save_color_state($state);
        return $state;
    }

    $default = [];
    foreach (color_rooms() as $r) {
        $default[$r] = [
            'last_settled_round' => '',
            'bets'               => [],
            'overrides'          => [],
            'history'            => generate_initial_seed_history($r, $nowSec),
        ];
    }
    state_set(COLOR_STATE_KEY, $default);
    return $default;
}

function save_color_state($state) {
    state_set(COLOR_STATE_KEY, $state);
}

/**
 * Serialise a read-modify-write of the colour state row.
 *
 * The direct replacement for withColorState(). Everything inside runs in one transaction with the
 * GameState row held FOR UPDATE, so two pollers arriving the instant a round ends cannot both see
 * "not settled" and both pay the round out.
 */
function color_with_state(callable $fn) {
    return tx(function () use ($fn) {
        return $fn();
    });
}

/**
 * Settle one finished round for one room.
 *
 * Called with the state row already locked. Mutates $state in place (by reference) the way the
 * original mutated its object.
 */
function settle_color_round($room, $targetRound, array &$state) {
    $overrideKey = 'color_guess_overrides_' . $room;
    $override = state_get($overrideKey);
    if (!is_array($override)) $override = [];

    $roundBets = [];
    if (isset($state[$room]['bets'][$targetRound]) && is_array($state[$room]['bets'][$targetRound])) {
        $roundBets = array_values($state[$room]['bets'][$targetRound]);
    }

    // Each room draws from its OWN 100-slot cycle: the four rooms settle at 30s/60s/180s/300s, so a
    // shared cycle let Sapre consume most of the rigged slots long before VIP had settled enough
    // rounds to see its share.
    $botDecision = should_bot_rig_this_round('color_guess', 'color_guess:' . $room);

    $num = null;
    $was_rigged = false;
    $rig_desc = '';

    $ovNumber = $override['number'] ?? null;
    $ovRigType = $override['rig_type'] ?? null;
    $ovColor = $override['color'] ?? null;
    $ovSize = $override['size'] ?? null;

    if ($ovNumber !== null && $ovNumber !== '') {
        $num = (int) js_parse_int($ovNumber);
        $was_rigged = true;
        $rig_desc = "Number Fixed: {$ovNumber} ";
    } elseif ($ovRigType === 'platform_profit' || $ovRigType === 'max_profit') {
        $optimal = color_optimal_outcome($roundBets, $targetRound);
        $num = $optimal['best_number'];
        $was_rigged = true;
        $rig_desc = 'Auto-Rig: Max Profit ';
    } elseif ($ovRigType === 'user_win') {
        $optimal = color_optimal_outcome($roundBets, $targetRound);
        $num = $optimal['worst_number'];
        $was_rigged = true;
        $rig_desc = 'Auto-Rig: User Win ';
    } elseif (js_truthy($ovColor) || js_truthy($ovSize)) {
        $possible = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
        if (js_truthy($ovColor)) {
            if ($ovColor === 'Green')       $possible = array_values(array_intersect($possible, [1, 3, 5, 7, 9]));
            elseif ($ovColor === 'Red')     $possible = array_values(array_intersect($possible, [0, 2, 4, 6, 8]));
            elseif ($ovColor === 'Violet')  $possible = array_values(array_intersect($possible, [0, 5]));
        }
        if (js_truthy($ovSize)) {
            if ($ovSize === 'Small')     $possible = array_values(array_filter($possible, function ($n) { return $n <= 4; }));
            elseif ($ovSize === 'Big')   $possible = array_values(array_filter($possible, function ($n) { return $n >= 5; }));
        }
        $possible = array_values($possible);

        if (count($possible) > 0) {
            $optimal = color_optimal_outcome($roundBets, $targetRound);
            $bestPossible = array_values(array_filter($optimal['outcomes'],
                function ($o) use ($possible) { return in_array($o['number'], $possible, true); }));
            usort($bestPossible, function ($a, $b) {
                if ($a['adminProfit'] == $b['adminProfit']) return 0;
                return ($b['adminProfit'] < $a['adminProfit']) ? -1 : 1;
            });
            $num = count($bestPossible) > 0 ? $bestPossible[0]['number'] : $possible[0];
        } else {
            $num = 0;
        }
        $was_rigged = true;
        if (js_truthy($ovColor)) $rig_desc .= "Color Fixed: {$ovColor} ";
        if (js_truthy($ovSize))  $rig_desc .= "Size Fixed: {$ovSize} ";
    } elseif ($botDecision['shouldRig']) {
        // Bot rig: only when a currently-targeted live player actually has a bet this round.
        $targeted = bot_targeted_users('color_guess');
        $targetedHasBet = false;
        if (count($targeted) > 0) {
            foreach ($roundBets as $b) {
                foreach ($targeted as $u) {
                    if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) { $targetedHasBet = true; break 2; }
                }
            }
        }

        if (count($targeted) === 0 || $targetedHasBet) {
            $optimal = color_optimal_outcome($roundBets, $targetRound, count($targeted) > 0 ? $targeted : null);
            $num = $optimal['best_number'];
            $was_rigged = true;
            $rig_desc = '🤖 AI Bot (' . js_num_str($botDecision['profit_pct']) . '% Target, ' . count($targeted)
                      . ' targeted) - Rigged Round - Max Profit #' . $optimal['best_number'];
        } else {
            // Bot wants to rig, but no targeted user has a bet this round — resolve fairly instead.
            $num = (int) floor(js_random() * 10);
            $was_rigged = false;
            $rig_desc = '🤖 AI Bot (targeted subset has no bet this round) - Fair #' . $num;
        }
    } elseif ($botDecision['active'] && !$botDecision['shouldRig']) {
        $num = (int) floor(js_random() * 10);
        $was_rigged = false;
        $rig_desc = '🤖 AI Bot (' . js_num_str($botDecision['profit_pct']) . '% Target) - Fair Round - Natural #' . $num;
    } else {
        // No bot active: truly random outcome.
        $num = (int) floor(js_random() * 10);
    }

    $resolved = resolve_color_number($num);

    // Audit only. adminProfit for the number actually drawn comes from the same helper the rig
    // paths use, so this reports realised house profit rather than an estimate.
    try {
        $auditOutcome = color_optimal_outcome($roundBets, $targetRound);
        $drawn = $auditOutcome['outcomes'][$num] ?? null;
        rig_record([
            'game'           => 'color_guess',
            'instance'       => $room,
            'round'          => $targetRound,
            'configured_pct' => $botDecision['profit_pct'],
            'rigged'         => $was_rigged,
            'live'           => count(get_live_usernames('color_guess')),
            'targeted'       => count(bot_targeted_users('color_guess')),
            'house_profit'   => $drawn ? $drawn['adminProfit'] : null,
            'note'           => trim($rig_desc) !== '' ? trim($rig_desc) : 'natural draw',
        ]);
    } catch (Throwable $e) { /* audit is diagnostic; never let it interrupt a settlement */ }

    $historyEntry = [
        'roundNumber' => $targetRound,
        'number'      => $num,
        'color'       => $resolved['color'],
        'dotClass'    => $resolved['dotClass'],
        'size'        => $resolved['size'],
        'is_rigged'   => $was_rigged,
        'rig_desc'    => trim($rig_desc),
        'timestamp'   => js_locale_time(),
    ];

    if (!isset($state[$room]['history']) || !is_array($state[$room]['history'])) $state[$room]['history'] = [];
    $state[$room]['history'][] = $historyEntry;
    if (count($state[$room]['history']) > 20) {
        array_shift($state[$room]['history']);
        $state[$room]['history'] = array_values($state[$room]['history']);
    }

    try {
        q('INSERT INTO "RecentResult" ("room","roundNumber","number","color","dotClass","size")
           VALUES (?,?,?,?,?,?)
           ON CONFLICT ("room","roundNumber") DO UPDATE SET "number"=EXCLUDED."number", "color"=EXCLUDED."color",
                                   "dotClass"=EXCLUDED."dotClass", "size"=EXCLUDED."size"',
          [$room, (string)$targetRound, $num, $resolved['color'], $resolved['dotClass'], $resolved['size']]);
    } catch (Throwable $e) {
        log_error('Error saving recent result: ' . $e->getMessage());
    }

    foreach ($roundBets as $b) {
        $won = false;
        $multiplier = 0.0;
        $cat = $b['category'] ?? null;
        $val = $b['value'] ?? null;

        if ($cat === 'color') {
            if ($val === $resolved['color']) { $won = true; $multiplier = ($val === 'Violet') ? 4.5 : 2.0; }
        } elseif ($cat === 'number') {
            $parsed = js_parse_int($val);
            if (!is_nan($parsed) && (int)$parsed === $num) { $won = true; $multiplier = 9.0; }
        } elseif ($cat === 'size') {
            if ($val === $resolved['size']) { $won = true; $multiplier = 2.0; }
        }

        if ($won) {
            $payout = ((float)$b['amount']) * $multiplier;
            $user = find_user_ci($b['username'] ?? '');
            if ($user) {
                // NOTE: read-then-write, not an atomic increment. This is what the original does,
                // and it is reproduced rather than corrected. The row lock held by the caller is
                // what actually keeps it safe here.
                $newBal = (float)$user['wallet_balance'] + $payout;
                q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$newBal, (int)$user['id']]);

                insert_transaction(
                    new_record_id('TX'),
                    $b['username'],
                    'Deposit',
                    $payout,
                    'Color Guess Win Payout Room: ' . strtoupper($room) . ' Round #' . $targetRound
                        . ' Selection: ' . $cat . ' (' . $val . ')',
                    'Completed'
                );
            }
        }
    }
}

/**
 * Settle any round that has ended for one room, then return the state.
 *
 * The alreadySettled guard only protects against a round already settled in the snapshot THIS
 * request read; two pollers reading before either writes would both see "not settled" and both pay
 * out. The surrounding lock is what makes the guard actually hold.
 */
function color_advance_room($room) {
    return color_with_state(function () use ($room) {
        $st = load_color_state();
        $nowSec = (int) floor(now_ms() / 1000);
        $duration = color_duration($room);
        $prevRoundId = get_color_round_id($room, $nowSec - $duration);

        $stateChanged = false;
        if (empty($st[$room]['last_settled_round'])) {
            $st[$room]['last_settled_round'] = $prevRoundId;
            $stateChanged = true;
        } elseif ((string)$st[$room]['last_settled_round'] !== (string)$prevRoundId) {
            $alreadySettled = false;
            if (!empty($st[$room]['history'])) {
                foreach ($st[$room]['history'] as $h) {
                    if ((string)($h['roundNumber'] ?? '') === (string)$prevRoundId) { $alreadySettled = true; break; }
                }
            }
            if (!$alreadySettled) settle_color_round($room, $prevRoundId, $st);
            $st[$room]['last_settled_round'] = $prevRoundId;
            $stateChanged = true;
        }

        if ($stateChanged) save_color_state($st);
        return $st;
    });
}

/** The same sweep across every room — used by the admin live-state view and by the cron. */
function color_advance_all_rooms() {
    return color_with_state(function () {
        $state = load_color_state();
        $nowSec = (int) floor(now_ms() / 1000);
        $stateChanged = false;

        foreach (color_rooms() as $room) {
            $duration = color_duration($room);
            $prevRoundId = get_color_round_id($room, $nowSec - $duration);

            if (empty($state[$room]['last_settled_round'])) {
                $state[$room]['last_settled_round'] = $prevRoundId;
                $stateChanged = true;
            } elseif ((string)$state[$room]['last_settled_round'] !== (string)$prevRoundId) {
                $alreadySettled = false;
                if (!empty($state[$room]['history'])) {
                    foreach ($state[$room]['history'] as $h) {
                        if ((string)($h['roundNumber'] ?? '') === (string)$prevRoundId) { $alreadySettled = true; break; }
                    }
                }
                if (!$alreadySettled) settle_color_round($room, $prevRoundId, $state);
                $state[$room]['last_settled_round'] = $prevRoundId;
                $stateChanged = true;
            }
        }

        if ($stateChanged) save_color_state($state);
        return $state;
    });
}
