<?php
/**
 * Keno. A board of 40 numbers; the player picks 1 to 10 of them and 10 are drawn. With n picks the
 * chance of exactly h hits is C(n,h) * C(40-n, 10-h) / C(40,10). Each pick count has its own pay
 * table: nothing below a minimum number of hits, then payouts that grow steeply with rarer results,
 * scaled so the table's expected return is exactly the RTP (top capped at 1000x).
 */

const ORIG_KENO_BOARD = 40;
const ORIG_KENO_DRAWN = 10;

function orig_keno_probs($n) {
    $total = orig_choose(ORIG_KENO_BOARD, ORIG_KENO_DRAWN);
    $p = [];
    for ($h = 0; $h <= $n; $h++) $p[] = orig_choose($n, $h) * orig_choose(ORIG_KENO_BOARD - $n, ORIG_KENO_DRAWN - $h) / $total;
    return $p;
}

function orig_keno_min_hits($n) {
    return [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 2, 6 => 3, 7 => 3, 8 => 3, 9 => 4, 10 => 4][$n];
}

function orig_keno_table($n, $rtp) {
    static $cache = [];
    $key = "$n|$rtp";
    if (!isset($cache[$key])) {
        $p = orig_keno_probs($n);
        $shape = [];
        foreach ($p as $h => $ph) $shape[$h] = ($h < orig_keno_min_hits($n) || $ph <= 0) ? 0 : pow($ph, -0.6);
        $cache[$key] = orig_scale_to_rtp($p, $shape, $rtp, 1000.0, 2);
    }
    return $cache[$key];
}

function orig_keno_prepare(array $in, array $cfg) {
    $picks = $in['picks'] ?? null;
    if (!is_array($picks) || !$picks) orig_fail('Pick at least one number.');
    $clean = [];
    foreach ($picks as $v) {
        $v = (int) $v;
        if ($v < 1 || $v > ORIG_KENO_BOARD) orig_fail('Numbers must be 1 to ' . ORIG_KENO_BOARD . '.');
        $clean[$v] = true;
    }
    $clean = array_keys($clean);
    sort($clean);
    if (count($clean) > 10) orig_fail('Pick at most 10 numbers.');
    if (count($clean) !== count($picks)) orig_fail('Each number can be picked only once.');
    return ['stake' => orig_bet_amount($in), 'params' => ['picks' => $clean]];
}

function orig_keno_play($stake, array $p, OrigRng $rng, array $cfg) {
    $board = range(1, ORIG_KENO_BOARD);
    $drawn = [];
    for ($i = 0; $i < ORIG_KENO_DRAWN; $i++) {
        $j = $rng->int(count($board));
        $drawn[] = $board[$j];
        array_splice($board, $j, 1);
    }
    $hits = array_values(array_intersect($p['picks'], $drawn));
    $mult = orig_keno_table(count($p['picks']), $cfg['rtp'])[count($hits)];
    return ['status' => 'settled', 'payout' => $stake * $mult,
            'result' => ['drawn' => $drawn, 'hits' => $hits, 'multiplier' => $mult]];
}

function orig_keno_info(array $cfg) {
    $t = [];
    for ($n = 1; $n <= 10; $n++) $t[$n] = orig_keno_table($n, $cfg['rtp']);
    return ['rtp' => $cfg['rtp'], 'board' => ORIG_KENO_BOARD, 'drawn' => ORIG_KENO_DRAWN, 'tables' => $t];
}
