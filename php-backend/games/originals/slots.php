<?php
/**
 * Diamond Slots. 5 reels x 3 rows, 10 fixed paylines, the bet split evenly across the lines. Every cell
 * is drawn independently from weighted symbols. A line pays for 3, 4 or 5 of a kind from the left;
 * WILD stands in for any symbol (a line of leading wilds pays as wild if that is worth more). SCATTER
 * pays nothing on a line, but 3 or more anywhere start 5 FREE SPINS with every win x3 (no retrigger).
 *
 * Because cells are independent, the chance of every line result is exact:
 *   w leading wilds then symbol X:  pw^w * pX,   then each further cell extends the run with pX + pw.
 * So a line's expected win e (in line bets) is exact, the free-spin trigger chance T is binomial, and
 *   RTP = e x (1 + 5 spins x 3 x T)
 * The pay table's shape is fixed; it is scaled so the RTP is as close to the target as the paisa
 * flooring allows, never above it.
 */

function orig_slots_symbols() {
    // symbol => weight (out of the total)
    return ['ten' => 24.6, 'jack' => 22, 'queen' => 20, 'king' => 14, 'gem' => 7, 'crown' => 4.5,
            'seven' => 2.5, 'wild' => 1.8, 'scatter' => 3.6];
}

function orig_slots_shape() {
    // relative pays for 3 / 4 / 5 of a kind
    return ['ten' => [1, 3, 8], 'jack' => [1.2, 3.5, 10], 'queen' => [1.5, 4, 12], 'king' => [2, 6, 20],
            'gem' => [4, 12, 50], 'crown' => [6, 20, 100], 'seven' => [10, 40, 250], 'wild' => [15, 60, 500]];
}

function orig_slots_lines() {
    return [[1, 1, 1, 1, 1], [0, 0, 0, 0, 0], [2, 2, 2, 2, 2], [0, 1, 2, 1, 0], [2, 1, 0, 1, 2],
            [0, 0, 1, 2, 2], [2, 2, 1, 0, 0], [1, 0, 0, 0, 1], [1, 2, 2, 2, 1], [0, 1, 1, 1, 0]];
}

const ORIG_SLOTS_FREE_SPINS = 5;
const ORIG_SLOTS_FREE_MULT = 3;

function orig_slots_p() {
    $w = orig_slots_symbols();
    $t = array_sum($w);
    return array_map(function ($x) use ($t) { return $x / $t; }, $w);
}

/** Chance of 3+ scatters among the 15 cells. */
function orig_slots_trigger_prob() {
    $ps = orig_slots_p()['scatter'];
    $t = 0.0;
    for ($k = 3; $k <= 15; $k++) $t += orig_choose(15, $k) * pow($ps, $k) * pow(1 - $ps, 15 - $k);
    return $t;
}

/**
 * Every distinct line outcome as [prob, symbol, count, leadingWilds]. A line's win is
 * max(pay[symbol][count], pay[wild][leadingWilds]).
 */
function orig_slots_line_outcomes() {
    static $out = null;
    if ($out !== null) return $out;
    $p = orig_slots_p();
    $pw = $p['wild'];
    $out = [];
    $out[] = [pow($pw, 5), 'wild', 5, 5];
    for ($w = 0; $w <= 4; $w++) {
        foreach ($p as $sym => $px) {
            if ($sym === 'wild') continue;
            $base = pow($pw, $w) * $px;
            if ($sym === 'scatter') { $out[] = [$base, 'scatter', 0, $w]; continue; }
            $q = $px + $pw;
            $rest = 5 - $w - 1;       // cells after the first X
            for ($j = 0; $j <= $rest; $j++) {
                $pj = $j < $rest ? pow($q, $j) * (1 - $q) : pow($q, $j);
                $out[] = [$base * $pj, $sym, $w + 1 + $j, $w];
            }
        }
    }
    return $out;
}

function orig_slots_pay(array $table, $sym, $count) {
    if ($sym === 'scatter' || $count < 3) return 0.0;
    return $table[$sym][$count - 3];
}

function orig_slots_line_ev(array $table) {
    $e = 0.0;
    foreach (orig_slots_line_outcomes() as [$prob, $sym, $c, $w]) {
        $e += $prob * max(orig_slots_pay($table, $sym, $c), orig_slots_pay($table, 'wild', $w));
    }
    return $e;
}

function orig_slots_rtp_of(array $table) {
    return orig_slots_line_ev($table) * (1 + ORIG_SLOTS_FREE_SPINS * ORIG_SLOTS_FREE_MULT * orig_slots_trigger_prob());
}

/** The pay table (in line bets) for this RTP. */
function orig_slots_table($rtp) {
    static $cache = [];
    if (isset($cache[(string) $rtp])) return $cache[(string) $rtp];
    $shape = orig_slots_shape();
    $build = function ($k) use ($shape) {
        $t = [];
        foreach ($shape as $sym => $v) $t[$sym] = array_map(function ($x) use ($k) { return floor($x * $k * 100 + 1e-9) / 100; }, $v);
        return $t;
    };
    $lo = 0.0; $hi = 1.0;
    while (orig_slots_rtp_of($build($hi)) < $rtp) $hi *= 2;
    for ($i = 0; $i < 80; $i++) {
        $mid = ($lo + $hi) / 2;
        if (orig_slots_rtp_of($build($mid)) <= $rtp) $lo = $mid; else $hi = $mid;
    }
    return $cache[(string) $rtp] = $build($lo);
}

function orig_slots_spin_grid(OrigRng $rng) {
    $w = orig_slots_symbols();
    $total = array_sum($w);
    $grid = [];   // grid[col][row]
    for ($c = 0; $c < 5; $c++) {
        for ($r = 0; $r < 3; $r++) {
            $x = $rng->float() * $total;
            foreach ($w as $sym => $wt) { if ($x < $wt) break; $x -= $wt; }
            $grid[$c][$r] = $sym;
        }
    }
    return $grid;
}

/** Wins on one grid, in line bets, and the winning lines. */
function orig_slots_evaluate(array $grid, array $table) {
    $total = 0.0; $wins = [];
    foreach (orig_slots_lines() as $li => $rows) {
        $cells = [];
        foreach ($rows as $c => $r) $cells[] = $grid[$c][$r];
        $w = 0;
        while ($w < 5 && $cells[$w] === 'wild') $w++;
        if ($w === 5) { $sym = 'wild'; $count = 5; }
        else {
            $sym = $cells[$w];
            $count = $w + 1;
            if ($sym !== 'scatter') while ($count < 5 && ($cells[$count] === $sym || $cells[$count] === 'wild')) $count++;
        }
        $pay = max(orig_slots_pay($table, $sym, $count), orig_slots_pay($table, 'wild', $w));
        if ($pay > 0) {
            $useWild = orig_slots_pay($table, 'wild', $w) >= orig_slots_pay($table, $sym, $count) && $w >= 3;
            $wins[] = ['line' => $li, 'symbol' => $useWild ? 'wild' : $sym, 'count' => $useWild ? $w : $count, 'pays' => $pay];
            $total += $pay;
        }
    }
    $scatters = 0;
    foreach ($grid as $col) foreach ($col as $s) if ($s === 'scatter') $scatters++;
    return ['line_win' => $total, 'wins' => $wins, 'scatters' => $scatters];
}

function orig_slots_prepare(array $in, array $cfg) {
    return ['stake' => orig_bet_amount($in), 'params' => []];
}

function orig_slots_play($stake, array $p, OrigRng $rng, array $cfg) {
    $table = orig_slots_table($cfg['rtp']);
    $lineBet = $stake / 10;
    $grid = orig_slots_spin_grid($rng);
    $ev = orig_slots_evaluate($grid, $table);
    $payout = $ev['line_win'] * $lineBet;
    $free = [];
    if ($ev['scatters'] >= 3) {
        for ($i = 0; $i < ORIG_SLOTS_FREE_SPINS; $i++) {
            $g = orig_slots_spin_grid($rng);
            $e = orig_slots_evaluate($g, $table);
            $win = $e['line_win'] * $lineBet * ORIG_SLOTS_FREE_MULT;
            $payout += $win;
            $free[] = ['grid' => $g, 'wins' => $e['wins'], 'win' => orig_money($win)];
        }
    }
    return ['status' => 'settled', 'payout' => $payout, 'result' => [
        'grid' => $grid, 'wins' => $ev['wins'], 'scatters' => $ev['scatters'], 'base_win' => orig_money($ev['line_win'] * $lineBet),
        'free_spins' => $free,
    ]];
}

function orig_slots_info(array $cfg) {
    return ['rtp' => $cfg['rtp'], 'paytable' => orig_slots_table($cfg['rtp']), 'lines' => orig_slots_lines(),
            'free_spins' => ORIG_SLOTS_FREE_SPINS, 'free_multiplier' => ORIG_SLOTS_FREE_MULT];
}
