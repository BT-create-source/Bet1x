<?php
/**
 * Punto Banco baccarat with the standard third-card tableau. Each card is drawn independently from a
 * full 52-card pack (an "infinite shoe"), which is what makes every round re-derivable from its seed.
 * Payouts (total returned): Player 2x, Banker 1.95x (5% commission), Tie 9x (8:1), Player Pair /
 * Banker Pair 12x (11:1). On a tie, Player and Banker bets are returned. Fixed by the rules.
 */

function orig_bac_value($card) { return $card['r'] >= 10 ? 0 : $card['r']; }

function orig_bac_total(array $cards) {
    $t = 0;
    foreach ($cards as $c) $t += orig_bac_value($c);
    return $t % 10;
}

/** Does the banker draw, given its total and the player's third card value (null if the player stood)? */
function orig_bac_banker_draws($b, $p3) {
    if ($p3 === null) return $b <= 5;
    if ($b <= 2) return true;
    if ($b === 3) return $p3 !== 8;
    if ($b === 4) return $p3 >= 2 && $p3 <= 7;
    if ($b === 5) return $p3 >= 4 && $p3 <= 7;
    if ($b === 6) return $p3 === 6 || $p3 === 7;
    return false;
}

/** Deal one coup from a card source; returns [playerCards, bankerCards]. */
function orig_bac_deal(callable $draw) {
    $p = [$draw(), null]; $b = [$draw(), null];
    $p[1] = $draw(); $b[1] = $draw();
    $pt = orig_bac_total($p); $bt = orig_bac_total($b);
    if ($pt >= 8 || $bt >= 8) return [$p, $b];
    $p3 = null;
    if ($pt <= 5) { $c = $draw(); $p[] = $c; $p3 = orig_bac_value($c); }
    if (orig_bac_banker_draws($bt, $p3)) $b[] = $draw();
    return [$p, $b];
}

const ORIG_BAC_PAYS = ['player' => 2.0, 'banker' => 1.95, 'tie' => 9.0, 'player_pair' => 12.0, 'banker_pair' => 12.0];

function orig_baccarat_prepare(array $in, array $cfg) {
    [$chips, $sum] = orig_read_chips($in['bets'] ?? null, 10);
    $merged = [];
    foreach ($chips as $c) {
        if (!isset(ORIG_BAC_PAYS[$c['type']])) orig_fail('Unknown bet.');
        $merged[$c['type']] = orig_money(($merged[$c['type']] ?? 0) + $c['amount']);
    }
    $bets = [];
    foreach ($merged as $t => $a) $bets[] = ['type' => $t, 'value' => '', 'amount' => $a];
    return ['stake' => $sum, 'params' => ['bets' => $bets]];
}

function orig_baccarat_play($stake, array $p, OrigRng $rng, array $cfg) {
    [$pc, $bc] = orig_bac_deal(function () use ($rng) { return orig_card($rng->int(52)); });
    $pt = orig_bac_total($pc); $bt = orig_bac_total($bc);
    $winner = $pt > $bt ? 'player' : ($bt > $pt ? 'banker' : 'tie');
    $pPair = $pc[0]['r'] === $pc[1]['r'];
    $bPair = $bc[0]['r'] === $bc[1]['r'];
    $payout = 0.0;
    foreach ($p['bets'] as $b) {
        $t = $b['type']; $a = $b['amount'];
        if ($t === 'player' || $t === 'banker') {
            if ($winner === 'tie') $payout += $a;                      // returned on a tie
            elseif ($winner === $t) $payout += $a * ORIG_BAC_PAYS[$t];
        } elseif ($t === 'tie') {
            if ($winner === 'tie') $payout += $a * ORIG_BAC_PAYS['tie'];
        } elseif ($t === 'player_pair') {
            if ($pPair) $payout += $a * ORIG_BAC_PAYS['player_pair'];
        } elseif ($t === 'banker_pair') {
            if ($bPair) $payout += $a * ORIG_BAC_PAYS['banker_pair'];
        }
    }
    $codes = function ($cs) { return array_map(function ($c) { return $c['c']; }, $cs); };
    return ['status' => 'settled', 'payout' => $payout, 'result' => [
        'player' => $codes($pc), 'banker' => $codes($bc), 'player_total' => $pt, 'banker_total' => $bt,
        'winner' => $winner, 'player_pair' => $pPair, 'banker_pair' => $bPair,
    ]];
}

function orig_baccarat_info(array $cfg) {
    return ['rtp' => 0.9894, 'pays' => ORIG_BAC_PAYS];
}
