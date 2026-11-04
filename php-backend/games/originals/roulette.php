<?php
/**
 * European roulette, single zero (0-36). Standard payouts (total returned, stake included):
 *   straight 36x   split 18x   street 12x   corner 9x   line 6x   dozen / column 3x
 *   red / black / odd / even / low (1-18) / high (19-36) 2x
 * Every bet covers k numbers and pays 36/k, so every bet returns 36/37 = 97.30%. The zero loses all
 * outside bets. Fixed by the rules; the operator cannot change it.
 */

function orig_roulette_reds() { return [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36]; }

/** The numbers a chip covers, or a failure for an invalid chip. */
function orig_roulette_cover($type, $value) {
    $v = trim((string) $value);
    switch ($type) {
        case 'straight':
            if (!preg_match('/^\d{1,2}$/', $v) || (int) $v > 36) orig_fail('Invalid number.');
            return [(int) $v];
        case 'red':   return orig_roulette_reds();
        case 'black': return array_values(array_diff(range(1, 36), orig_roulette_reds()));
        case 'odd':   return array_values(array_filter(range(1, 36), function ($n) { return $n % 2 === 1; }));
        case 'even':  return array_values(array_filter(range(1, 36), function ($n) { return $n % 2 === 0; }));
        case 'low':   return range(1, 18);
        case 'high':  return range(19, 36);
        case 'dozen':
            if (!in_array($v, ['1', '2', '3'], true)) orig_fail('Invalid dozen.');
            return range(12 * ((int) $v - 1) + 1, 12 * (int) $v);
        case 'column':
            if (!in_array($v, ['1', '2', '3'], true)) orig_fail('Invalid column.');
            return array_values(array_filter(range(1, 36), function ($n) use ($v) { return (($n - 1) % 3) + 1 === (int) $v; }));
        case 'street':   // row r = 3r-2, 3r-1, 3r
            if (!preg_match('/^\d{1,2}$/', $v) || (int) $v < 1 || (int) $v > 12) orig_fail('Invalid street.');
            return [3 * (int) $v - 2, 3 * (int) $v - 1, 3 * (int) $v];
        case 'line':     // two adjacent rows
            if (!preg_match('/^\d{1,2}$/', $v) || (int) $v < 1 || (int) $v > 11) orig_fail('Invalid line.');
            return range(3 * (int) $v - 2, 3 * (int) $v + 3);
        case 'split':
            if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $v, $m)) orig_fail('Invalid split.');
            $a = min((int) $m[1], (int) $m[2]); $b = max((int) $m[1], (int) $m[2]);
            $ok = $b <= 36 && (
                ($a >= 1 && $b - $a === 3) ||
                ($a >= 1 && $b - $a === 1 && intdiv($a - 1, 3) === intdiv($b - 1, 3)) ||
                ($a === 0 && in_array($b, [1, 2, 3], true)));
            if (!$ok) orig_fail('Those numbers are not next to each other.');
            return [$a, $b];
        case 'corner':   // the lowest number of a 2x2 block
            if (!preg_match('/^\d{1,2}$/', $v)) orig_fail('Invalid corner.');
            $a = (int) $v;
            if ($a < 1 || $a > 32 || $a % 3 === 0) orig_fail('Invalid corner.');
            return [$a, $a + 1, $a + 3, $a + 4];
    }
    orig_fail('Unknown bet type.');
}

function orig_roulette_prepare(array $in, array $cfg) {
    [$chips, $sum] = orig_read_chips($in['bets'] ?? null, 80);
    $norm = [];
    foreach ($chips as $c) {
        $cover = orig_roulette_cover($c['type'], $c['value']);
        $norm[] = ['type' => $c['type'], 'value' => $c['value'], 'amount' => $c['amount'], 'pays' => 36 / count($cover)];
    }
    return ['stake' => $sum, 'params' => ['bets' => $norm]];
}

function orig_roulette_color($n) {
    if ($n === 0) return 'green';
    return in_array($n, orig_roulette_reds(), true) ? 'red' : 'black';
}

function orig_roulette_play($stake, array $p, OrigRng $rng, array $cfg) {
    $n = $rng->int(37);
    $payout = 0.0; $winning = [];
    foreach ($p['bets'] as $i => $b) {
        if (in_array($n, orig_roulette_cover($b['type'], $b['value']), true)) {
            $payout += $b['amount'] * (36 / count(orig_roulette_cover($b['type'], $b['value'])));
            $winning[] = $i;
        }
    }
    return ['status' => 'settled', 'payout' => $payout, 'result' => ['number' => $n, 'color' => orig_roulette_color($n), 'winning_bets' => $winning]];
}

function orig_roulette_info(array $cfg) {
    return ['rtp' => 36 / 37, 'reds' => orig_roulette_reds()];
}
