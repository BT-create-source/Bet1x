<?php
/**
 * The independent Dream11 T20 oracle used by the road tests — a second implementation of Dream11's
 * published table (dream11.com/fantasy-cricket/point-system), sharing no code with lib/fantasy-scoring.php
 * or lib/cricket-feed.php, so the production engine is checked against something it cannot agree with by
 * accident.
 */

// =================================================================================================
// The oracle: Dream11 T20, from the published table, on a neutral ball list
// =================================================================================================

/**
 * $balls: [['inn','bowler','batter','non_striker','bat','total','kind' (legal|wide|noball|bye|legbye),
 *           'boundary' (0|4|6), 'out' => null | ['kind','player','fielders' => [names], 'sub' => [bool]]]]
 * $lineup: [name => true]; $role: [name => WK|BAT|ALL|BOWL]
 */
function oracle_points(array $balls, array $lineup, array $role) {
    $p = [];
    $get = function ($n) use (&$p) { if (!isset($p[$n])) $p[$n] = ['r' => 0, 'b' => 0, '4' => 0, '6' => 0, 'out' => false, 'bat' => false,
        'w' => 0, 'lbwb' => 0, 'lb' => 0, 'conc' => 0, 'dots' => 0, 'md' => 0, 'ct' => 0, 'st' => 0, 'rod' => 0, 'roa' => 0, 'bowled_any' => false]; return $n; };
    // Every announced XI player is in the result, involved or not: Dream11 pays +4 for being named in
    // the XI even to a player who never bats, bowls or fields.
    foreach ($lineup as $name => $_) $get($name);
    $overs = [];
    foreach ($balls as $x) {
        $get($x['batter']); $get($x['non_striker']); $get($x['bowler']);
        $p[$x['batter']]['bat'] = true; $p[$x['non_striker']]['bat'] = true;
        $legal = !in_array($x['kind'], ['wide', 'noball'], true);
        // batting
        $p[$x['batter']]['r'] += $x['bat'];
        if ($x['kind'] !== 'wide') $p[$x['batter']]['b']++;
        if ($x['boundary'] === 4) $p[$x['batter']]['4']++;
        if ($x['boundary'] === 6) $p[$x['batter']]['6']++;
        // bowling: runs conceded = off the bat + wides + no-balls (byes and leg byes are not the bowler's)
        $conc = $x['bat'] + (in_array($x['kind'], ['wide', 'noball'], true) ? ($x['total'] - $x['bat']) : 0);
        $p[$x['bowler']]['conc'] += $conc;
        $p[$x['bowler']]['bowled_any'] = true;
        if ($legal) { $p[$x['bowler']]['lb']++; if ($x['bat'] === 0) $p[$x['bowler']]['dots']++; }
        $ok = $x['inn'] . '|' . $x['over'] . '|' . $x['bowler'];
        if (!isset($overs[$ok])) $overs[$ok] = [0, 0];
        if ($legal) $overs[$ok][0]++;
        $overs[$ok][1] += $conc;
        if ($x['out']) {
            $k = $x['out']['kind'];
            if (!in_array($k, ['retired hurt', 'retired not out'], true)) { $get($x['out']['player']); $p[$x['out']['player']]['out'] = true; $p[$x['out']['player']]['bat'] = true; }
            if (in_array($k, ['bowled', 'caught', 'lbw', 'stumped', 'caught and bowled', 'hit wicket'], true)) {
                $p[$x['bowler']]['w']++;
                if ($k === 'bowled' || $k === 'lbw') $p[$x['bowler']]['lbwb']++;
            }
            $fs = $x['out']['fielders'];
            if ($k === 'caught' && $fs) { $get($fs[0]); $p[$fs[0]]['ct']++; }
            if ($k === 'caught and bowled') $p[$x['bowler']]['ct']++;
            if ($k === 'stumped' && $fs) { $get($fs[0]); $p[$fs[0]]['st']++; }
            if ($k === 'run out' && $fs) {
                if (count($fs) === 1) { $get($fs[0]); $p[$fs[0]]['rod']++; }
                else foreach (array_slice($fs, -2) as $f) { $get($f); $p[$f]['roa']++; }
            }
        }
    }
    foreach ($overs as $ok => [$lg, $c]) if ($lg >= 6 && $c === 0) $p[explode('|', $ok, 3)[2]]['md']++;

    $out = [];
    foreach ($p as $name => $s) {
        $inXI = isset($lineup[$name]);
        $playedSub = !$inXI && ($s['bat'] || $s['bowled_any']);
        if (!$inXI && !$playedSub) { $out[$name] = 0.0; continue; }      // substitute fielder: nothing at all
        $r = $role[$name] ?? 'BAT';
        $pts = 4.0;                                                       // announced lineup / playing substitute
        $pts += $s['r'] + 4 * $s['4'] + 6 * $s['6'];
        if ($s['r'] >= 100) $pts += 16;
        else { if ($s['r'] >= 25) $pts += 4; if ($s['r'] >= 50) $pts += 8; if ($s['r'] >= 75) $pts += 12; }
        if ($s['out'] && $s['r'] === 0 && $r !== 'BOWL') $pts -= 2;
        if ($r !== 'BOWL' && $s['b'] >= 10) {
            $sr = round($s['r'] * 100 / $s['b'], 2);
            if ($sr > 170) $pts += 6; elseif ($sr > 150) $pts += 4; elseif ($sr >= 130) $pts += 2;
            elseif ($sr < 50) $pts -= 6; elseif ($sr < 60) $pts -= 4; elseif ($sr <= 70) $pts -= 2;
        }
        $pts += 30 * $s['w'] + 8 * $s['lbwb'];
        if ($s['w'] >= 5) $pts += 12; elseif ($s['w'] >= 4) $pts += 8; elseif ($s['w'] >= 3) $pts += 4;
        $pts += 12 * $s['md'] + $s['dots'];
        if ($s['lb'] >= 12) {
            $e = round($s['conc'] * 6 / $s['lb'], 2);
            if ($e < 5) $pts += 6; elseif ($e < 6) $pts += 4; elseif ($e <= 7) $pts += 2;
            elseif ($e > 12) $pts -= 6; elseif ($e > 11) $pts -= 4; elseif ($e >= 10) $pts -= 2;
        }
        $pts += 8 * $s['ct'] + ($s['ct'] >= 3 ? 4 : 0) + 12 * $s['st'] + 12 * $s['rod'] + 6 * $s['roa'];
        $out[$name] = (float) $pts;
    }
    return $out;
}


/** Raw Roanuz-shaped ball objects (as the mock or a real push delivers them) -> the oracle's neutral list. */
function oracle_balls_from_snapshot(array $snap) {
    $order = $snap['play']['innings_order'] ?? [];
    $out = [];
    foreach ($snap['related_balls'] as $b) {
        $innNo = array_search($b['innings'], $order, true);
        $innNo = $innNo === false ? 1 : $innNo + 1;
        if ($innNo > 2) continue;
        $type = $b['ball_type'];
        $kind = ['normal' => 'legal', 'wide' => 'wide', 'no_ball' => 'noball', 'bye' => 'bye', 'leg_bye' => 'legbye'][$type] ?? 'legal';
        $w = $b['wicket'] ?? null;
        $outInfo = null;
        if ($w) {
            $k = str_replace('_', ' ', $w['kind']);
            $outInfo = ['kind' => $k, 'player' => $w['player_key'], 'fielders' => array_map(function ($f) { return $f['player_key']; }, $b['fielders'] ?? [])];
        }
        $out[] = ['inn' => $innNo, 'over' => (int) $b['overs'][0], 'bowler' => $b['bowler']['player_key'], 'batter' => $b['batsman']['player_key'],
                  'non_striker' => $b['non_striker']['player_key'], 'bat' => (int) $b['batsman']['runs'], 'total' => (int) $b['team_score']['runs'],
                  'kind' => $kind, 'boundary' => !empty($b['batsman']['is_six']) ? 6 : (!empty($b['batsman']['is_four']) ? 4 : 0), 'out' => $outInfo];
    }
    return $out;
}

/** Ball by Ball outcome of one raw ball, decided independently of lib/bbb.php. */
function oracle_bbb_outcome(array $b) {
    if (in_array($b['ball_type'], ['wide', 'no_ball'], true)) return 'EX';
    if (!empty($b['wicket']) && !in_array($b['wicket']['kind'], ['retired_hurt', 'retired_not_out'], true)) return 'W';
    $t = (int) $b['team_score']['runs'];
    return in_array($t, [0, 1, 2, 3, 4, 6], true) ? (string) $t : null;
}
