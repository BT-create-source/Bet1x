<?php
/**
 * Fortune Wheel. Low / Medium / High risk, 10, 20 or 30 equal segments. Each layout is a fixed shape
 * (how big each segment is relative to the others) scaled so the average segment pays exactly the
 * RTP — the source project's layouts paid back up to 138% and were not used.
 */

function orig_wheel_shape($risk, $n) {
    $base = [
        'low'    => [1.5, 1.2, 1.2, 1.2, 0, 1.5, 1.2, 1.2, 1.2, 0],
        'medium' => [5, 0, 1.5, 0, 2, 0, 1.5, 0, 3, 0],
        'high'   => [10, 0, 0, 0, 0, 2, 0, 0, 0, 0],
    ][$risk];
    $shape = [];
    for ($i = 0; $i < $n; $i++) $shape[] = $base[$i % 10];
    // Bigger wheels get a bigger top prize.
    if ($n >= 20) {
        if ($risk === 'low')    { $shape[0] = 2; }
        if ($risk === 'medium') { $shape[0] = $n === 30 ? 15 : 10; }
        if ($risk === 'high')   { $shape[0] = $n === 30 ? 45 : 25; }
    }
    return $shape;
}

/** The layout's multipliers, scaled to the RTP. */
function orig_wheel_table($risk, $n, $rtp) {
    static $cache = [];
    $k = "$risk|$n|$rtp";
    if (!isset($cache[$k])) {
        $shape = orig_wheel_shape($risk, $n);
        $cache[$k] = orig_scale_to_rtp(array_fill(0, $n, 1 / $n), $shape, $rtp, 1000.0, 2);
    }
    return $cache[$k];
}

function orig_wheel_prepare(array $in, array $cfg) {
    $risk = strtolower((string) ($in['risk'] ?? 'medium'));
    if (!in_array($risk, ['low', 'medium', 'high'], true)) orig_fail('Choose a risk level.');
    $n = (int) ($in['segments'] ?? 10);
    if (!in_array($n, [10, 20, 30], true)) orig_fail('Choose 10, 20 or 30 segments.');
    return ['stake' => orig_bet_amount($in), 'params' => ['risk' => $risk, 'segments' => $n]];
}

function orig_wheel_play($stake, array $p, OrigRng $rng, array $cfg) {
    $table = orig_wheel_table($p['risk'], $p['segments'], $cfg['rtp']);
    $idx = $rng->int($p['segments']);
    $mult = $table[$idx];
    return ['status' => 'settled', 'payout' => $stake * $mult, 'result' => ['index' => $idx, 'multiplier' => $mult]];
}

function orig_wheel_info(array $cfg) {
    $t = [];
    foreach (['low', 'medium', 'high'] as $r) foreach ([10, 20, 30] as $n) $t[$r][$n] = orig_wheel_table($r, $n, $cfg['rtp']);
    return ['rtp' => $cfg['rtp'], 'tables' => $t];
}
