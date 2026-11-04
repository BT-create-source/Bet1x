<?php
/**
 * Plinko. 8 to 16 rows of pins; at each row the ball goes left or right with equal chance, so it
 * lands in bucket k (0..rows) with probability C(rows,k) / 2^rows. Low / Medium / High risk set how
 * steeply the buckets grow from the middle to the edges; each table is scaled so its expected return
 * is exactly the RTP, with the top capped at 1000x.
 */

/**
 * Bucket shape: centre value + (edge value - centre) x distance^steepness. The edge value grows with
 * the number of rows (more rows = rarer edges = bigger edge prize), between the familiar 8-row and
 * 16-row tops: Low 5.6x -> 16x, Medium 13x -> 110x, High 29x -> 1000x. The scaling to the RTP then
 * adjusts every bucket together.
 */
function orig_plinko_shape($risk, $rows) {
    $spec = ['low' => [0.5, 5.6, 16, 3.0], 'medium' => [0.4, 13, 110, 6.0], 'high' => [0.2, 29, 1000, 10.0]][$risk];
    [$centre, $top8, $top16, $steep] = $spec;
    $top = $top8 * pow($top16 / $top8, ($rows - 8) / 8);
    $half = $rows / 2;
    $shape = [];
    for ($k = 0; $k <= $rows; $k++) {
        $d = abs($k - $half) / $half;   // 0 in the middle .. 1 at the edges
        $shape[] = $centre + ($top - $centre) * pow($d, $steep);
    }
    return $shape;
}

function orig_plinko_probs($rows) {
    $p = [];
    for ($k = 0; $k <= $rows; $k++) $p[] = orig_choose($rows, $k) / pow(2, $rows);
    return $p;
}

function orig_plinko_table($risk, $rows, $rtp) {
    static $cache = [];
    $key = "$risk|$rows|$rtp";
    if (!isset($cache[$key])) {
        $cache[$key] = orig_scale_to_rtp(orig_plinko_probs($rows), orig_plinko_shape($risk, $rows), $rtp, 1000.0, 2);
    }
    return $cache[$key];
}

function orig_plinko_prepare(array $in, array $cfg) {
    $risk = strtolower((string) ($in['risk'] ?? 'medium'));
    if (!in_array($risk, ['low', 'medium', 'high'], true)) orig_fail('Choose a risk level.');
    $rows = (int) ($in['rows'] ?? 16);
    if ($rows < 8 || $rows > 16) orig_fail('Rows must be between 8 and 16.');
    return ['stake' => orig_bet_amount($in), 'params' => ['risk' => $risk, 'rows' => $rows]];
}

function orig_plinko_play($stake, array $p, OrigRng $rng, array $cfg) {
    $path = [];
    $bucket = 0;
    for ($i = 0; $i < $p['rows']; $i++) {
        $right = $rng->float() >= 0.5 ? 1 : 0;
        $path[] = $right;
        $bucket += $right;
    }
    $mult = orig_plinko_table($p['risk'], $p['rows'], $cfg['rtp'])[$bucket];
    return ['status' => 'settled', 'payout' => $stake * $mult, 'result' => ['path' => $path, 'bucket' => $bucket, 'multiplier' => $mult]];
}

function orig_plinko_info(array $cfg) {
    $t = [];
    foreach (['low', 'medium', 'high'] as $r) for ($n = 8; $n <= 16; $n++) $t[$r][$n] = orig_plinko_table($r, $n, $cfg['rtp']);
    return ['rtp' => $cfg['rtp'], 'tables' => $t];
}
