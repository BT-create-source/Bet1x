<?php
/**
 * The cricket pricing engine — turns the live match state into Match Odds, Bookmaker and Fancy prices.
 *
 * =================================================================================================
 * WHERE THE NUMBERS COME FROM
 * =================================================================================================
 * data/cricket-model.json, built by tools/build_cricket_model.py from Cricsheet's free ball-by-ball
 * archive (5,800 men's T20s and 2,200 ODIs at the last build). It holds:
 *
 *   E[b][w], SD[b][w]   runs still to come with b legal balls left and w wickets down
 *   chase {a, c}        P(chase succeeds) = sigmoid(a + c·z),  z = (E[b][w] − runs needed) / SD[b][w]
 *   milestone / over    expected runs to the end of an over-milestone, and within a single over
 *
 * Tested on matches the fit never saw, a stated chance of 25% comes true 25% of the time, 75% comes
 * true 74% of the time (see the "calibration" block in the model file).
 *
 * The scorecard cannot know which side is STRONGER. That is the one input the operator supplies — the
 * favourite's price before the toss — and it enters as a logit shift that fades as the match is used
 * up: decisive before a ball, irrelevant at the death, exactly as in a real market.
 *
 * =================================================================================================
 * WHAT IT CANNOT DO, STATED PLAINLY
 * =================================================================================================
 * A real exchange also prices team news, pitch, weather and the speed of people watching at the
 * ground. Mid-innings the engine lands close to the market; around those events it can lag. The
 * exchange module therefore suspends after wickets, keeps sensible margins and stake limits, and
 * voids bets struck too close to a ball.
 *
 * Every function here is pure: state in, prices out. No database, no clock, no money.
 */

function odds_model($format) {
    static $model = null;
    if ($model === null) {
        $path = __DIR__ . '/../data/cricket-model.json';
        $model = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }
    $key = odds_format_key($format);
    return ($key && isset($model['formats'][$key])) ? $model['formats'][$key] : null;
}

/** T20 and ODI are priced; T10 and Tests are not (no model, no markets). */
function odds_format_key($format) {
    $f = strtoupper((string) $format);
    if ($f === 'T20') return 'T20';
    if ($f === 'ODI') return 'ODI';
    return null;
}

function odds_sigmoid($x) { return 1 / (1 + exp(-$x)); }
function odds_logit($p) { $p = max(1e-6, min(1 - 1e-6, $p)); return log($p / (1 - $p)); }

// -------------------------------------------------------------------------------------------------
// Match state
// -------------------------------------------------------------------------------------------------

/**
 * The state the engine prices from, derived from the feed row and its ordered deliveries.
 *
 * Returns innings (1|2), which side bats in each innings, runs/wickets/legal balls of the current
 * innings, the target, and whether each innings is over.
 */
function odds_state(array $feed, array $deliveries) {
    $model = odds_model($feed['format'] ?? 'T20');
    $maxBalls = $model ? (int) $model['max_balls'] : 120;
    $order = $feed['meta']['innings_order'] ?? [];
    $toss = $feed['toss'] ?? [];

    $inn = [];
    foreach ($deliveries as $d) {
        if (!empty($d['is_super_over'])) continue;
        $i = (int) $d['innings'];
        if (!isset($inn[$i])) $inn[$i] = ['runs' => 0, 'wkts' => 0, 'legal' => 0, 'side' => $d['batting_team'] ?? null, 'last_ms' => 0];
        $inn[$i]['runs'] += (int) $d['batsman_runs'] + (int) $d['extra_runs'];
        if (!empty($d['is_wicket'])) $inn[$i]['wkts']++;
        if (!empty($d['is_legal'])) $inn[$i]['legal']++;
        $inn[$i]['last_ms'] = max($inn[$i]['last_ms'], (int) ($d['first_seen_ms'] ?? 0));
    }
    // Who bats first: the feed's innings order, else the toss, else unknown.
    $first = null;
    if (!empty($order[0])) $first = substr((string) $order[0], 0, 1);
    elseif (!empty($inn[1]['side'])) $first = $inn[1]['side'];
    elseif (!empty($toss['winner'])) {
        $tw = ($toss['winner'] === 'a' || $toss['winner'] === ($feed['team_a_key'] ?? '')) ? 'a' : 'b';
        $first = ($toss['elected'] ?? '') === 'bat' ? $tw : ($tw === 'a' ? 'b' : 'a');
    }
    $second = $first ? ($first === 'a' ? 'b' : 'a') : null;

    $i1 = $inn[1] ?? ['runs' => 0, 'wkts' => 0, 'legal' => 0, 'last_ms' => 0];
    $i2 = $inn[2] ?? null;
    $done1 = $i1['wkts'] >= 10 || $i1['legal'] >= $maxBalls || $i2 !== null;
    $target = $done1 ? $i1['runs'] + 1 : null;
    if (!empty($feed['meta']['target'])) $target = (int) $feed['meta']['target'];

    $current = $i2 !== null ? 2 : 1;
    $cur = $current === 2 ? $i2 : $i1;
    // First innings finished, second not started yet (the break): price the chase from ball zero.
    if ($current === 1 && $done1) { $current = 2; $cur = ['runs' => 0, 'wkts' => 0, 'legal' => 0, 'last_ms' => 0]; }

    return [
        'format' => $feed['format'] ?? 'T20', 'max_balls' => $maxBalls,
        'first' => $first, 'second' => $second,
        'innings' => $current, 'batting' => $current === 1 ? $first : $second,
        'runs' => (int) $cur['runs'], 'wkts' => (int) $cur['wkts'], 'legal' => (int) $cur['legal'],
        'target' => $current === 2 ? $target : null,
        'first_innings' => $i1,
        'started' => !empty($deliveries),
        'last_ball_ms' => max((int) $i1['last_ms'], (int) ($i2['last_ms'] ?? 0)),
    ];
}

// -------------------------------------------------------------------------------------------------
// Win probability
// -------------------------------------------------------------------------------------------------

function odds_p_chase_raw(array $m, $need, $left, $wkts) {
    $e = $m['E'][$left][$wkts]; $sd = max(1.0, $m['SD'][$left][$wkts]);
    return odds_sigmoid($m['chase']['a'] + $m['chase']['c'] * ($e - $need) / $sd);
}

/**
 * P(chase succeeds). Made non-decreasing in balls left by a running maximum over fewer balls: the
 * fitted tables carry a little sampling noise, and an extra ball must never make a chase less likely.
 */
function odds_p_chase(array $m, $need, $left, $wkts) {
    if ($need <= 0) return 1.0;
    if ($left <= 0 || $wkts >= 10) return 0.0;
    $left = min((int) $left, (int) $m['max_balls']);
    static $memo = [];
    $k = $m['max_balls'] . ':' . (int) $need . ':' . (int) $wkts;
    if (!isset($memo[$k])) {
        $run = 0.0; $row = [0 => 0.0];
        for ($b = 1; $b <= (int) $m['max_balls']; $b++) { $run = max($run, odds_p_chase_raw($m, $need, $b, $wkts)); $row[$b] = $run; }
        if (count($memo) > 20000) $memo = [];
        $memo[$k] = $row;
    }
    return $memo[$k][$left];
}

/** P(the side batting first wins) during the first innings: integrate over the projected total. */
function odds_p_first(array $m, $runs, $left, $wkts) {
    $max = (int) $m['max_balls'];
    if ($left <= 0 || $wkts >= 10) return 1 - odds_p_chase($m, $runs + 1, $max, 0);
    $e = $m['E'][$left][$wkts]; $sd = $m['SD'][$left][$wkts];
    static $qz = [-1.75, -1.15, -0.67, -0.32, 0.0, 0.32, 0.67, 1.15, 1.75];
    static $qw = [0.06, 0.09, 0.12, 0.13, 0.20, 0.13, 0.12, 0.09, 0.06];
    $p = 0.0; $wsum = 0.0;
    foreach ($qz as $i => $z) {
        $total = (int) round($runs + max(0, $e + $z * $sd));
        $p += $qw[$i] * (1 - odds_p_chase($m, $total + 1, $max, 0));
        $wsum += $qw[$i];
    }
    return $p / $wsum;
}

/** The share of the match's batting resources still to be used — 1 before a ball, 0 at the end. */
function odds_resource_left(array $m, array $st) {
    $max = (int) $m['max_balls'];
    $full = max(1.0, $m['E'][$max][0]);
    $left = max(0, $max - $st['legal']);
    $cur = $st['wkts'] >= 10 ? 0.0 : $m['E'][$left][min(9, $st['wkts'])];
    return $st['innings'] === 1 ? ($cur + $full) / (2 * $full) : $cur / (2 * $full);
}

/**
 * P(side 'a' wins) right now.
 *
 * $delta: the operator's strength shift for side 'a' in logit units (0 = evenly matched).
 */
function odds_win_prob(array $st, $delta = 0.0) {
    $m = odds_model($st['format']);
    if (!$m) return null;
    $max = (int) $m['max_balls'];
    if (!$st['first']) {
        // Before the toss nobody knows who bats first: price on strength alone.
        $pa = 0.5;
    } elseif ($st['innings'] === 1) {
        $pFirst = odds_p_first($m, $st['runs'], $max - $st['legal'], min(9, $st['wkts']));
        $pa = $st['first'] === 'a' ? $pFirst : 1 - $pFirst;
    } else {
        $pChase = odds_p_chase($m, (int) $st['target'] - $st['runs'], $max - $st['legal'], $st['wkts']);
        $pa = $st['second'] === 'a' ? $pChase : 1 - $pChase;
    }
    if ($delta != 0 && $pa > 0 && $pa < 1) {
        $pa = odds_sigmoid(odds_logit($pa) + $delta * odds_resource_left($m, $st));
    }
    return max(0.0, min(1.0, $pa));
}

/**
 * Market-style tails. The statistics say a chase needing 41 off 66 with nine wickets in hand succeeds
 * ~98.5% of the time; a real exchange prices it nearer 94%, because markets always leave room for the
 * upset (the favourite-longshot bias). Pricing the raw statistic would both look unlike every other
 * site and hand sharp bettors value on the outsider. So beyond |logit| = $knee the logit is compressed
 * by $factor: mid-range prices (where the model is calibrated and the market agrees) are untouched,
 * the extremes are pulled to where markets actually trade.
 */
function odds_temper($p, $factor = 0.5, $knee = 1.5) {
    if ($p === null || $p <= 0 || $p >= 1) return $p;
    $l = odds_logit($p);
    if (abs($l) <= $knee) return $p;
    $l = ($l > 0 ? 1 : -1) * ($knee + (abs($l) - $knee) * $factor);
    return odds_sigmoid($l);
}

/** The strength shift that makes side $favSide priced at $favPrice before a ball is bowled. */
function odds_delta_from_price($favSide, $favPrice) {
    $p = 1 / max(1.01, (float) $favPrice);
    $p = max(0.02, min(0.98, $p));
    $d = odds_logit($p);   // baseline before the toss is 0.5, logit 0
    return $favSide === 'a' ? $d : -$d;
}

// -------------------------------------------------------------------------------------------------
// Prices
// -------------------------------------------------------------------------------------------------

/** The standard exchange price ladder (the increments Betfair uses). */
function odds_tick_size($price) {
    if ($price < 2) return 0.01;
    if ($price < 3) return 0.02;
    if ($price < 4) return 0.05;
    if ($price < 6) return 0.1;
    if ($price < 10) return 0.2;
    if ($price < 20) return 0.5;
    if ($price < 30) return 1;
    if ($price < 50) return 2;
    if ($price < 100) return 5;
    return 10;
}
function odds_tick_down($p) { $p = max(1.01, min(1000, $p)); $t = odds_tick_size($p); return round(max(1.01, floor($p / $t + 1e-9) * $t), 2); }
function odds_tick_up($p)   { $p = max(1.01, min(1000, $p)); $t = odds_tick_size($p); return round(min(1000, ceil($p / $t - 1e-9) * $t), 2); }
function odds_step($p, $n) {
    for ($i = 0; $i < abs($n); $i++) {
        if ($n > 0) $p = round($p + odds_tick_size($p), 2);
        else { $t = odds_tick_size($p - 0.001); $p = round(max(1.01, $p - $t), 2); }
    }
    return $p;
}

/**
 * Match Odds ladder for one side with win probability $p: three back prices (best first) and three lay
 * prices (best first). The best back sits just under fair value and the best lay just over it, a tick
 * apart at minimum — the shape of a liquid exchange market.
 */
function odds_ladder($p, $margin = 0.02) {
    if ($p <= 0.004 || $p >= 0.996) return null;
    $fair = 1 / $p;
    $back = odds_tick_down(1 + ($fair - 1) * (1 - $margin));
    $lay = odds_tick_up(1 + ($fair - 1) * (1 + $margin));
    if ($lay <= $back) $lay = odds_step($back, 1);
    return [
        'fair' => round($fair, 3),
        'back' => [$back, odds_step($back, -1), odds_step($back, -2)],
        'lay'  => [$lay, odds_step($lay, 1), odds_step($lay, 2)],
    ];
}

/** Bookmaker rates (profit per 100 staked), the Indian format: back below fair, lay above. */
function odds_bookmaker($p, $margin = 0.05) {
    if ($p <= 0.01 || $p >= 0.99) return null;
    $fair = 1 / $p;
    $back = (int) floor((($fair - 1) * (1 - $margin)) * 100);
    $lay = (int) ceil((($fair - 1) * (1 + $margin)) * 100);
    if ($lay <= $back) $lay = $back + 1;
    return ['back' => max(1, $back), 'lay' => max(2, $lay)];
}

// -------------------------------------------------------------------------------------------------
// Fancy (session) lines
// -------------------------------------------------------------------------------------------------

/**
 * Lines on the current innings: total runs at each over-milestone still ahead ("6 Over Runs") and the
 * runs in the next over ("Only 9th Over Runs").
 *
 * Quoted the way Indian exchanges quote session lines: No L / Yes L+1 at even money (e.g. 73/74). A Yes
 * bet wins if the runs reach the Yes line; a No bet wins if they stay below the No line — so exactly L
 * runs loses both sides, and that single value is the house's edge (≈3-4% on a milestone, more on a
 * single over).
 */
function odds_fancy(array $st, $teamShort) {
    $m = odds_model($st['format']);
    if (!$m || !$st['batting']) return [];
    $out = [];
    $b = (int) $st['legal'];
    $w = min(9, (int) $st['wkts']);
    $max = (int) $m['max_balls'];
    if ($st['wkts'] >= 10 || $b >= $max) return [];
    if ($st['innings'] === 2 && $st['target'] && $st['runs'] >= $st['target']) return [];

    // Milestones: the fixed ones (6/10/15/20 or 10..50) still ahead, plus the end of the over in
    // progress ("GRB 9 Over Runs" during the 9th over), the way Indian exchanges list them. A fixed
    // milestone stays open until its final over has STARTED.
    $current = intdiv($b, 6) + 1;
    $list = [];
    foreach ($m['milestones'] as $ms) if ($b <= $ms * 6 - 6) $list[$ms] = true;
    if ($current * 6 <= $max) $list[$current] = true;
    ksort($list);

    foreach (array_keys($list) as $ms) {
        $end = $ms * 6;
        if ($b >= $end) continue;
        if (isset($m['milestone'][(string) $ms])) {
            $tab = $m['milestone'][(string) $ms];
            $mean = $tab['mean'][$b][$w]; $sd = $tab['sd'][$b][$w];
        } else {
            // A milestone with no table of its own: the balls left in its over, at that over's rate.
            $o = $ms - 1; $rest = $end - $b;
            $mean = $m['over']['mean'][$o][$w] * $rest / 6;
            $sd = $m['over']['sd'][$o][$w] * sqrt($rest / 6);
        }
        $mu = $st['runs'] + $mean;
        // A chase stops at the target, so a milestone the chase is expected to pass first is not a real
        // question — the established sites do not list it, and neither does this.
        if ($st['innings'] === 2 && $st['target'] && $mu >= $st['target'] - 1) continue;
        $no = (int) floor($mu);
        $out[] = [
            'key' => 'ms_' . $st['innings'] . '_' . $ms, 'kind' => 'milestone', 'over' => $ms,
            'name' => $teamShort . ' ' . $ms . ' Over Runs' . ($end === $max ? ' (innings)' : ''),
            'no_line' => $no, 'no_rate' => 100, 'yes_line' => $no + 1, 'yes_rate' => 100,
            'mean' => round($mu, 1), 'sd' => round($sd, 1),
        ];
    }

    // The next over that has not started ("Only 10th Over Runs").
    $o = intdiv($b + 5, 6);
    if ($o < intdiv($max, 6)) {
        $mu = $m['over']['mean'][$o][$w];
        $no = (int) floor($mu);
        $ord = $o + 1;
        $suffix = ($ord % 100 >= 11 && $ord % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$ord % 10] ?? 'th');
        $out[] = [
            'key' => 'ov_' . $st['innings'] . '_' . $o, 'kind' => 'over', 'over' => $o,
            'name' => $teamShort . ' Only ' . $ord . $suffix . ' Over Runs',
            'no_line' => $no, 'no_rate' => 100, 'yes_line' => $no + 1, 'yes_rate' => 100,
            'mean' => round($mu, 1), 'sd' => round($m['over']['sd'][$o][$w], 1),
        ];
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Bet arithmetic — what a bet risks and what it wins
// -------------------------------------------------------------------------------------------------

/**
 * Liability (debited when the bet is struck) and profit (won on top of it), by side:
 *   BACK at decimal odds O, stake S:  risk S,          win S(O−1)
 *   LAY  at decimal odds O, stake S:  risk S(O−1),     win S          (stake = the backer's stake)
 *   YES  at rate R, stake S:          risk S,          win S·R/100
 *   NO   at rate R, stake S:          risk S·R/100,    win S
 */
function odds_bet_terms($side, $stake, $odds, $rate) {
    $s = round((float) $stake, 2);
    switch ($side) {
        case 'BACK': return ['liability' => $s, 'profit' => round($s * ((float) $odds - 1), 2)];
        case 'LAY':  return ['liability' => round($s * ((float) $odds - 1), 2), 'profit' => $s];
        case 'YES':  return ['liability' => $s, 'profit' => round($s * (float) $rate / 100, 2)];
        case 'NO':   return ['liability' => round($s * (float) $rate / 100, 2), 'profit' => $s];
    }
    return null;
}

/** Does a bet win, given the outcome (winning side, or actual runs for fancy)? */
function odds_bet_wins(array $bet, $outcome) {
    switch ($bet['side']) {
        case 'BACK': return (string) $bet['selection'] === (string) $outcome;
        case 'LAY':  return (string) $bet['selection'] !== (string) $outcome;
        case 'YES':  return (int) $outcome >= (int) $bet['line'];
        case 'NO':   return (int) $outcome < (int) $bet['line'];
    }
    return false;
}

/**
 * A user's position in a two-way market: what they would be credited if side 'a' wins and if 'b' wins
 * (liabilities included), and their net P&L on each.
 */
function odds_position(array $bets) {
    $credit = ['a' => 0.0, 'b' => 0.0];
    $locked = 0.0;
    foreach ($bets as $bt) {
        $locked += (float) $bt['liability'];
        foreach (['a', 'b'] as $o) {
            if (odds_bet_wins($bt, $o)) $credit[$o] += (float) $bt['liability'] + (float) $bt['profit'];
        }
    }
    return ['credit' => $credit, 'locked' => round($locked, 2),
            'pnl' => ['a' => round($credit['a'] - $locked, 2), 'b' => round($credit['b'] - $locked, 2)]];
}

/**
 * Cash out: the guaranteed amount the position is worth right now, by hedging on side 'a' at the
 * current prices — laying it if the position is long 'a', backing it if short. The two outcomes are
 * equalised exactly, so the offer is the same whichever side wins.
 *
 * $backA / $layA: the current best back and lay decimal odds for side 'a'.
 */
function odds_cashout_value(array $position, $backA, $layA) {
    $cA = $position['credit']['a']; $cB = $position['credit']['b'];
    if (abs($cA - $cB) < 0.005) return round($cA, 2);
    if ($cA > $cB) {
        $h = ($cA - $cB) / (float) $layA;   // lay 'a'
        return round($cB + $h, 2);
    }
    $h = ($cB - $cA) / (float) $backA;      // back 'a'
    return round($cB - $h, 2);
}
