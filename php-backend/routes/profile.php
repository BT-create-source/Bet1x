<?php
/**
 * Player profile: basic account info plus a reconstructed game history (which game, won or lost,
 * exact amount).
 *
 * There is no separate "round result" table anywhere in this codebase — every game logs its money
 * movements into the one shared Transaction ledger (a bet is a Withdrawal row, a win is a Deposit
 * row, each with a descriptive `details` string; see the insert_transaction() call sites in
 * games/*.php, routes/gamesync.php and routes/mines.php). A loss produces no second row of its own
 * — it is simply the absence of a following win — so history here is reconstructed read-only by
 * walking one player's transactions in chronological order and pairing every bet with the next win
 * of the same game (if any) before that game's next bet. This changes nothing about how any game
 * settles or how money moves; it only reinterprets rows already written by that settlement code.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * Classify one Transaction row's `details` text into a game + event kind, or null if it is not a
 * recognised game transaction (a cashier deposit/withdrawal, an admin adjustment, the signup
 * bonus, etc — all of which use different, unmatched wording).
 */
function profile_classify_txn($details) {
    $details = (string) $details;
    if (stripos($details, 'Aviator Wager') === 0)  return ['game' => 'Vimaan', 'kind' => 'bet'];
    if (stripos($details, 'Aviator Payout') === 0) return ['game' => 'Vimaan', 'kind' => 'win'];
    if (preg_match('/^Color Guess Wager Room:\s*(\S+)/i', $details, $m)) {
        return ['game' => profile_color_room_name($m[1]), 'kind' => 'bet'];
    }
    if (preg_match('/^Color Guess Win Payout Room:\s*(\S+)/i', $details, $m)) {
        return ['game' => profile_color_room_name($m[1]), 'kind' => 'win'];
    }
    if (stripos($details, 'Mines Bet') === 0)      return ['game' => 'Mines', 'kind' => 'bet'];
    if (stripos($details, 'Mines Cash Out') === 0) return ['game' => 'Mines', 'kind' => 'win'];
    if (stripos($details, 'Teen Patti Boot') === 0)     return ['game' => 'Teen Patti', 'kind' => 'bet'];
    if (stripos($details, 'Teen Patti Chaal') === 0)    return ['game' => 'Teen Patti', 'kind' => 'bet'];
    if (stripos($details, 'Teen Patti Won Pot') === 0)  return ['game' => 'Teen Patti', 'kind' => 'win'];
    return null;
}

function profile_color_room_name($room) {
    switch (strtoupper($room)) {
        case 'SAPRE':  return 'Sapre Color';
        case 'BECONE': return 'Becone Color';
        case 'EMRED':  return 'Emred Color';
        case 'VIP':    return 'VIP Room';
        default:       return 'Color Prediction';
    }
}

/**
 * Turn a chronological list of {game, kind, amount, timestamp, details} rows into resolved
 * rounds. Every bet is paired with the next win of the SAME game that occurs before that game's
 * next fresh bet; an unmatched bet is a loss of its own stake.
 *
 * Teen Patti's "Boot" (once per hand) and "Chaal" (any number of raises within that hand) are both
 * Withdrawals with no shared round number, so consecutive bet rows for Teen Patti are folded
 * together into one hand's stake, split only at the next Boot (the one row type that reliably
 * marks a new hand starting). Every OTHER game is strictly one bet == one round: two bet rows in a
 * row with no win between them are two separate LOSSES, not one round with a doubled-up stake —
 * merging them was the bug behind "a second Aviator loss doesn't get its own row, it just enlarges
 * the first one".
 */
function profile_build_rounds($txns) {
    $byGame = [];
    foreach ($txns as $t) {
        $byGame[$t['game']][] = $t;
    }

    $rounds = [];
    foreach ($byGame as $game => $rows) {
        $n = count($rows);
        $i = 0;
        while ($i < $n) {
            if ($rows[$i]['kind'] !== 'bet') { $i++; continue; }

            $stake = (float) $rows[$i]['amount'];
            $roundAt = $rows[$i]['timestamp'];
            $j = $i + 1;
            while ($game === 'Teen Patti' && $j < $n && $rows[$j]['kind'] === 'bet') {
                if (stripos($rows[$j]['details'], 'Teen Patti Boot') === 0) break;
                $stake += (float) $rows[$j]['amount'];
                $j++;
            }

            $won = null;
            if ($j < $n && $rows[$j]['kind'] === 'win') {
                $won = $rows[$j];
                $j++;
            }

            $rounds[] = [
                'game'      => $game,
                'result'    => $won ? 'won' : 'lost',
                'amount'    => round($won ? (float) $won['amount'] : $stake, 2),
                'timestamp' => $roundAt,
            ];
            $i = $j;
        }
    }

    usort($rounds, function ($a, $b) { return strcmp($b['timestamp'], $a['timestamp']); });
    return $rounds;
}

function register_profile_routes(Router $app) {
    $app->get('/api/profile', 'require_auth', function (Req $req, Res $res) {
        try {
            $username = $req->auth['username'];
            $user = find_user_ci($username);
            if (!$user) {
                $res->status(404)->json(['error' => 'Account not found.']);
                return;
            }

            $rows = all(
                'SELECT "details","amount","timestamp" FROM "Transaction" '
                . 'WHERE LOWER("user") = LOWER(?) ORDER BY "timestamp" ASC LIMIT 1000',
                [$username]
            );

            $classified = [];
            foreach ($rows as $r) {
                $cls = profile_classify_txn($r['details']);
                if (!$cls) continue;
                $classified[] = [
                    'game'      => $cls['game'],
                    'kind'      => $cls['kind'],
                    'amount'    => (float) $r['amount'],
                    'timestamp' => $r['timestamp'],
                    'details'   => $r['details'],
                ];
            }

            $rounds = profile_build_rounds($classified);

            $wins = 0; $losses = 0; $totalWon = 0.0; $totalLost = 0.0;
            foreach ($rounds as $r) {
                if ($r['result'] === 'won') { $wins++; $totalWon += $r['amount']; }
                else { $losses++; $totalLost += $r['amount']; }
            }

            $res->json([
                'success' => true,
                'profile' => [
                    'id'             => (int) $user['id'],
                    'username'       => $user['username'],
                    'email'          => $user['email'],
                    'phone'          => $user['phone'] ?? null,
                    'wallet_balance' => (float) $user['wallet_balance'],
                    // Referral commission wallet — see lib/referral.php. Kept here too (in
                    // addition to GET /api/referral) so the profile card's top balance row can
                    // show it without a second request; the referral code itself, and the full
                    // dashboard, are only fetched when the player actually opens that section.
                    'referral_balance' => (float) ($user['referral_balance'] ?? 0),
                    'member_since'   => $user['created_at'] ?? null,
                ],
                'stats' => [
                    'games_played' => count($rounds),
                    'wins'         => $wins,
                    'losses'       => $losses,
                    'total_won'    => round($totalWon, 2),
                    'total_lost'   => round($totalLost, 2),
                    'net'          => round($totalWon - $totalLost, 2),
                ],
                // Most recent first, capped — this is a profile view, not a full statement.
                'history' => array_slice($rounds, 0, 50),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'profile');
        }
    });
}
