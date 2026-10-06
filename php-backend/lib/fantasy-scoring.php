<?php
/**
 * "Your Eleven" — the fantasy points engine.
 *
 * =================================================================================================
 * THE RULES ARE DREAM11'S, PER FORMAT
 * =================================================================================================
 * The point values below are the established Dream11 cricket point system, read from Dream11's own
 * published points-system page (archived copy of dream11.com/fantasy-cricket/point-system, January
 * 2026), one table per format. The headline differences from a naive "1 point a run, 25 a wicket"
 * engine, each of which the big apps genuinely apply:
 *
 *   - boundary +4 and six +6 BONUSES on top of the runs;
 *   - run milestones 25 / 50 / 75 that STACK below a hundred, while a century pays ONLY the century
 *     bonus ("Any player scoring a century will only get points for the century");
 *   - a dot ball is worth +1 to the bowler in T20 (+1 per three in ODIs);
 *   - economy-rate and strike-rate bands, each with a minimum (2 overs / 10 balls in T20), and no
 *     strike-rate points at all for bowlers;
 *   - a one-off 3-catch bonus;
 *   - +4 just for being in the announced playing XI, and +4 for a playing substitute (impact
 *     player, concussion replacement); an ordinary substitute fielder scores nothing at all;
 *   - no points for anything in a super over (excluded upstream, in cricket_derive_player_stats).
 *
 * Every value lives in fantasy_scoring_rules() so the screen can show the table it is scored by.
 *
 * =================================================================================================
 * PURE, AND RECOMPUTED FROM TOTALS
 * =================================================================================================
 * Figures in, points out: no database, no feed, no clock. Scoring reads CUMULATIVE match figures and
 * recomputes the whole total every time, so a missed, duplicated or corrected update converges on
 * the right answer on the next pass.
 */

require_once __DIR__ . '/fantasy.php';

/** Normalise a format string to a key of the rules table. */
function fantasy_format_key($format) {
    $f = strtoupper((string) $format);
    if (strpos($f, 'TEST') !== false) return 'TEST';
    if ($f === 'ODI' || strpos($f, 'ONE') !== false || $f === 'OD' || $f === 'LIST A') return 'ODI';
    return 'T20';
}

/**
 * Every scoring value for a format, in one place.
 *
 * Bands are [lower, upper, points] and are matched inclusively on both ends, in order; the gaps
 * between them (e.g. 7.01–9.99 runs an over in T20) score nothing, exactly as the published table.
 */
function fantasy_scoring_table($format = 'T20') {
    $common = [
        'run'                    => 1.0,
        'four'                   => 4.0,     // boundary bonus
        'six'                    => 6.0,     // six bonus
        'duck_exempt_roles'      => ['BOWL'],
        'bowled_lbw_bonus'       => 8.0,
        'catch'                  => 8.0,
        'stumping'               => 12.0,
        'runout_direct'          => 12.0,
        'runout_shared'          => 6.0,
        'in_lineup'              => 4.0,
        'playing_substitute'     => 4.0,
        'captain_multiplier'     => 2.0,
        'vice_captain_multiplier' => 1.5,
        // Below a hundred the milestones stack; from a hundred up only the highest century-tier pays.
        'milestones_cumulative'  => true,
        // Wicket-haul bonuses: only the highest reached.
        'hauls_cumulative'       => false,
    ];

    $fk = strtoupper((string) $format);
    if (strpos($fk, 'OTHER') === 0) {
        // Dream11's "Other" tables: warm-up / practice matches, where more than 11 players may take part.
        // No lineup points, no dot balls, smaller boundary bonuses.
        $other = array_merge($common, ['four' => 1.0, 'six' => 2.0, 'in_lineup' => 0.0, 'playing_substitute' => 0.0]);
        $eco = [[0, 2.49, 6.0], [2.5, 3.49, 4.0], [3.5, 4.5, 2.0], [7.0, 8.0, -2.0], [8.01, 9.0, -4.0], [9.01, 999, -6.0]];
        $sr  = [[140.01, 9999, 6.0], [120.01, 140, 4.0], [100, 120, 2.0], [40, 50, -2.0], [30, 39.99, -4.0], [0, 29.99, -6.0]];
        if (strpos($fk, 'TEST') !== false) {
            return $other + ['format' => 'OTHER_TEST', 'milestones' => [[50, 4.0], [100, 8.0]], 'duck' => -4.0, 'dot_ball' => 0.0,
                'dot_balls_per_point' => 1, 'wicket' => 16.0, 'hauls' => [[4, 4.0], [5, 8.0]], 'maiden_over' => 0.0, 'catch_3_bonus' => 0.0,
                'economy_min_balls' => null, 'economy_bands' => [], 'strike_min_balls' => null, 'strike_bands' => []];
        }
        if (strpos($fk, 'OD') !== false) {
            return $other + ['format' => 'OTHER_ODI', 'milestones' => [[50, 4.0], [100, 8.0]], 'duck' => -3.0, 'dot_ball' => 0.0,
                'dot_balls_per_point' => 1, 'wicket' => 30.0, 'hauls' => [[4, 4.0], [5, 8.0]], 'maiden_over' => 4.0, 'catch_3_bonus' => 4.0,
                'economy_min_balls' => 30, 'economy_bands' => $eco, 'strike_min_balls' => 20, 'strike_bands' => $sr];
        }
        return $other + ['format' => 'OTHER_T20', 'milestones' => [[30, 4.0], [50, 8.0], [100, 16.0]], 'duck' => -2.0, 'dot_ball' => 0.0,
            'dot_balls_per_point' => 1, 'wicket' => 30.0, 'hauls' => [[3, 4.0], [4, 8.0], [5, 16.0]], 'maiden_over' => 12.0, 'catch_3_bonus' => 4.0,
            'economy_min_balls' => 12, 'economy_bands' => [[0, 2.49, 6.0], [2.5, 3.49, 4.0], [3.5, 4.5, 2.0], [7.0, 8.0, -2.0], [8.02, 9.0, -4.0], [9.01, 999, -6.0]],
            'strike_min_balls' => 20, 'strike_bands' => $sr];
    }
    if (strpos($fk, 'T10') !== false) {
        // Dream11 T10 table. No century bonus in T10 ("no points are awarded for centuries in T10").
        return $common + [
            'format' => 'T10',
            'milestones' => [[25, 8.0], [50, 12.0], [75, 16.0]],
            'duck' => -2.0, 'dot_ball' => 1.0, 'dot_balls_per_point' => 1, 'wicket' => 30.0,
            'hauls' => [[2, 4.0], [3, 8.0], [4, 12.0], [5, 16.0]], 'maiden_over' => 16.0, 'catch_3_bonus' => 4.0,
            'economy_min_balls' => 6,
            'economy_bands' => [[0, 6.99, 6.0], [7.0, 7.99, 4.0], [8.0, 9.0, 2.0], [14.0, 15.0, -2.0], [15.01, 16.0, -4.0], [16.01, 999, -6.0]],
            'strike_min_balls' => 5,
            'strike_bands' => [[190.01, 9999, 6.0], [170.01, 190, 4.0], [150, 170, 2.0], [70, 80, -2.0], [60, 69.99, -4.0], [0, 59.99, -6.0]],
        ];
    }
    if (strpos($fk, 'HUNDRED') !== false || strpos($fk, '100') !== false) {
        // Dream11 The Hundred table: smaller boundary bonuses, no dot/maiden/economy/strike-rate points.
        return array_merge($common, ['four' => 1.0, 'six' => 2.0]) + [
            'format' => 'HUNDRED',
            'milestones' => [[30, 5.0], [50, 10.0], [100, 20.0]],
            'duck' => -2.0, 'dot_ball' => 0.0, 'dot_balls_per_point' => 1, 'wicket' => 25.0,
            'hauls' => [[2, 3.0], [3, 5.0], [4, 10.0], [5, 20.0]], 'maiden_over' => 0.0, 'catch_3_bonus' => 4.0,
            'economy_min_balls' => null, 'economy_bands' => [], 'strike_min_balls' => null, 'strike_bands' => [],
        ];
    }

    switch (fantasy_format_key($format)) {
        case 'ODI':
            return $common + [
                'format'          => 'ODI',
                'milestones'      => [[25, 4.0], [50, 8.0], [75, 12.0], [100, 16.0], [125, 20.0], [150, 24.0]],
                'duck'            => -3.0,
                'dot_ball'        => 1.0,
                'dot_balls_per_point' => 3,
                'wicket'          => 30.0,
                'hauls'           => [[4, 4.0], [5, 8.0], [6, 12.0]],
                'maiden_over'     => 4.0,
                'catch_3_bonus'   => 4.0,
                'economy_min_balls' => 30,
                'economy_bands'   => [[0, 2.49, 6.0], [2.5, 3.49, 4.0], [3.5, 4.5, 2.0], [7.0, 8.0, -2.0], [8.01, 9.0, -4.0], [9.01, 999, -6.0]],
                'strike_min_balls' => 20,
                'strike_bands'    => [[140.01, 9999, 6.0], [120.01, 140, 4.0], [100, 120, 2.0], [40, 50, -2.0], [30, 39.99, -4.0], [0, 29.99, -6.0]],
            ];
        case 'TEST':
            return $common + [
                'format'          => 'TEST',
                'milestones'      => [[25, 4.0], [50, 8.0], [75, 12.0], [100, 16.0], [125, 20.0], [150, 24.0]],
                'duck'            => -4.0,
                'dot_ball'        => 0.0,
                'dot_balls_per_point' => 1,
                'wicket'          => 20.0,
                'hauls'           => [[4, 4.0], [5, 8.0], [6, 12.0]],
                'maiden_over'     => 0.0,
                'catch_3_bonus'   => 0.0,
                'economy_min_balls' => null,
                'economy_bands'   => [],
                'strike_min_balls' => null,
                'strike_bands'    => [],
            ];
        default:
            return $common + [
                'format'          => 'T20',
                'milestones'      => [[25, 4.0], [50, 8.0], [75, 12.0], [100, 16.0]],
                'duck'            => -2.0,
                'dot_ball'        => 1.0,
                'dot_balls_per_point' => 1,
                'wicket'          => 30.0,
                'hauls'           => [[3, 4.0], [4, 8.0], [5, 12.0]],
                'maiden_over'     => 12.0,
                'catch_3_bonus'   => 4.0,
                'economy_min_balls' => 12,
                'economy_bands'   => [[0, 4.99, 6.0], [5.0, 5.99, 4.0], [6.0, 7.0, 2.0], [10.0, 11.0, -2.0], [11.01, 12.0, -4.0], [12.01, 999, -6.0]],
                'strike_min_balls' => 10,
                'strike_bands'    => [[170.01, 9999, 6.0], [150.01, 170, 4.0], [130, 150, 2.0], [60, 70, -2.0], [50, 59.99, -4.0], [0, 49.99, -6.0]],
            ];
    }
}

/**
 * The two rule details Dream11's published page leaves open, as switchable variants:
 *   milestones_cumulative  below a century, do the 25/50/75 bonuses stack (75 runs = 4+8+12)?  default yes
 *   hauls_cumulative       does a 5-wicket haul also collect the 3- and 4-wicket bonuses?      default no
 * The defaults follow the most likely reading. fantasy_calibrate() settles them for good by comparing
 * the engine with Dream11's official totals for real players; its verdict is stored in the
 * "fantasy_rule_variants" state key and applied here.
 */
function fantasy_rule_variants() {
    static $cache = null, $epoch = -1;
    $now = $GLOBALS['BET1X_RULES_EPOCH'] ?? 0;
    if ($cache !== null && $epoch === $now) return $cache;
    $epoch = $now;
    $cache = [];
    if (function_exists('state_get')) {
        try { $v = state_get('fantasy_rule_variants'); if (is_array($v)) $cache = $v; } catch (Throwable $e) { $cache = []; }
    }
    return $cache;
}

/** Every scoring value for a format, with the calibrated rule variants applied. */
function fantasy_scoring_rules($format = 'T20', array $variants = null) {
    $r = fantasy_scoring_table($format);
    $v = $variants ?? fantasy_rule_variants();
    foreach (['milestones_cumulative', 'hauls_cumulative'] as $k) if (array_key_exists($k, $v)) $r[$k] = (bool) $v[$k];
    return $r;
}

/**
 * The run-milestone bonus.
 *
 * Below a hundred every tier reached pays (75 runs = 4 + 8 + 12). From a hundred up, ONLY the highest
 * century-or-above tier reached pays, and none of the lower ones — Dream11's published rule.
 */
function fantasy_milestone_bonus($runs, array $rules) {
    $runs = (int) $runs;
    $tiers = $rules['milestones'] ?? [];
    $sub = 0.0; $century = null;
    foreach ($tiers as $t) {
        if ($runs < $t[0]) continue;
        if ($t[0] >= 100) $century = (float) $t[1];
        else $sub = !empty($rules['milestones_cumulative']) ? $sub + (float) $t[1] : (float) $t[1];
    }
    return $century !== null ? $century : $sub;
}

/** The wicket-haul bonus: the highest tier reached (or the sum, if configured cumulative). */
function fantasy_haul_bonus($wickets, array $rules) {
    $wickets = (int) $wickets;
    $best = 0.0; $sum = 0.0;
    foreach (($rules['hauls'] ?? []) as $t) {
        if ($wickets >= $t[0]) { $best = (float) $t[1]; $sum += (float) $t[1]; }
    }
    return !empty($rules['hauls_cumulative']) ? $sum : $best;
}

/** Points from a band table, or 0 when the value falls in no band. */
function fantasy_band_points($value, array $bands) {
    $v = round((float) $value, 2);
    foreach ($bands as $b) {
        if ($v >= $b[0] && $v <= $b[1]) return (float) $b[2];
    }
    return 0.0;
}

/** Legal balls bowled, from the figure we have — older stat rows only carry decimal overs. */
function fantasy_balls_bowled(array $stats) {
    if (isset($stats['balls_bowled']) && (int) $stats['balls_bowled'] > 0) return (int) $stats['balls_bowled'];
    $o = (float) ($stats['overs'] ?? 0);
    $whole = (int) floor($o + 1e-9);
    return $whole * 6 + (int) round(($o - $whole) * 10);
}

/**
 * Score one player from their cumulative match figures.
 *
 * $stats keys (all optional, default 0):
 *   runs balls fours sixes is_out did_bat                       batting
 *   wickets balls_bowled|overs maidens runs_conceded bowled_lbw dot_balls   bowling
 *   catches stumpings runouts_direct runouts_shared              fielding
 *   in_lineup is_substitute batted_or_bowled fielded             participation
 *   role                                                         duck exemption + no SR for bowlers
 *
 * Returns ['points' => float, 'breakdown' => [label => points]].
 */
function fantasy_score_player(array $stats, array $rules = null) {
    $r = $rules ?: fantasy_scoring_rules();
    $n = function ($key) use ($stats) { return isset($stats[$key]) ? (float) $stats[$key] : 0.0; };
    $role = isset($stats['role']) ? fantasy_normalise_role($stats['role']) : null;

    // An ordinary substitute fielder — not in the announced XI, never batted or bowled — earns nothing
    // for anything, catches included. Only playing substitutes (impact/concussion) score.
    $inLineup = !empty($stats['in_lineup']);
    $isSub = !empty($stats['is_substitute']);
    $lineupsKnown = array_key_exists('in_lineup', $stats);
    if ($lineupsKnown && !$inLineup && !$isSub && $n('fielded') > 0 && empty($stats['batted_or_bowled'])) {
        return ['points' => 0.0, 'breakdown' => ['Substitute fielder (no points)' => 0.0]];
    }

    $bd = [];
    $add = function ($label, $pts) use (&$bd) { if (abs($pts) > 1e-9) $bd[$label] = round(($bd[$label] ?? 0) + $pts, 2); };

    // --- participation ---
    if ($inLineup) $add('Announced in playing XI', (float) $r['in_lineup']);
    if ($isSub)    $add('Playing substitute', (float) $r['playing_substitute']);

    // --- batting ---
    $runs = $n('runs');
    $add('Runs', $runs * (float) $r['run']);
    $add('Boundary bonus', $n('fours') * (float) $r['four']);
    $add('Six bonus', $n('sixes') * (float) $r['six']);
    $add('Milestone bonus', fantasy_milestone_bonus($runs, $r));

    $didBat = !empty($stats['did_bat']);
    $isOut  = !empty($stats['is_out']);
    $exempt = in_array($role, (array) ($r['duck_exempt_roles'] ?? []), true);
    if ($didBat && $isOut && (int) $runs === 0 && !$exempt) $add('Duck', (float) $r['duck']);

    $balls = (int) $n('balls');
    if ($role !== 'BOWL' && $r['strike_min_balls'] !== null && $balls >= (int) $r['strike_min_balls'] && $balls > 0) {
        $add('Strike rate', fantasy_band_points($runs * 100 / $balls, $r['strike_bands']));
    }

    // --- bowling ---
    $add('Wickets', $n('wickets') * (float) $r['wicket']);
    $add('LBW / bowled bonus', $n('bowled_lbw') * (float) $r['bowled_lbw_bonus']);
    $add('Wicket haul bonus', fantasy_haul_bonus($n('wickets'), $r));
    $add('Maiden overs', $n('maidens') * (float) $r['maiden_over']);
    $per = max(1, (int) ($r['dot_balls_per_point'] ?? 1));
    $add('Dot balls', floor($n('dot_balls') / $per) * (float) $r['dot_ball']);

    $bb = fantasy_balls_bowled($stats);
    if ($r['economy_min_balls'] !== null && $bb >= (int) $r['economy_min_balls'] && $bb > 0) {
        $add('Economy rate', fantasy_band_points($n('runs_conceded') * 6 / $bb, $r['economy_bands']));
    }

    // --- fielding ---
    $add('Catches', $n('catches') * (float) $r['catch']);
    if ($n('catches') >= 3) $add('3-catch bonus', (float) $r['catch_3_bonus']);
    $add('Stumpings', $n('stumpings') * (float) $r['stumping']);
    $add('Run out (direct)', $n('runouts_direct') * (float) $r['runout_direct']);
    $add('Run out (assist)', $n('runouts_shared') * (float) $r['runout_shared']);

    $total = 0.0;
    foreach ($bd as $v) $total += $v;
    return ['points' => round($total, 2), 'breakdown' => $bd];
}

/**
 * Apply the captain / vice-captain multiplier to a base score — the whole total, negatives included.
 */
function fantasy_apply_multiplier($basePoints, $isCaptain, $isViceCaptain, array $rules = null) {
    $r = $rules ?: fantasy_scoring_rules();
    $mult = 1.0;
    if ($isCaptain)          $mult = (float) $r['captain_multiplier'];
    elseif ($isViceCaptain)  $mult = (float) $r['vice_captain_multiplier'];
    return round(((float) $basePoints) * $mult, 2);
}

/**
 * Total one saved XI.
 *
 * $players: rows with at least id, role, is_captain, is_vice_captain.
 * $statsById: [player_id => stats array]. A player with no stats row scores 0.
 */
function fantasy_score_team(array $players, array $statsById, array $rules = null) {
    $r = $rules ?: fantasy_scoring_rules();
    $total = 0.0;
    $out = [];

    foreach ($players as $p) {
        $id = (int) $p['id'];
        $stats = isset($statsById[$id]) ? $statsById[$id] : [];
        if (!isset($stats['role']) && isset($p['role'])) $stats['role'] = $p['role'];

        $scored = fantasy_score_player($stats, $r);
        $isC  = !empty($p['is_captain']);
        $isVc = !empty($p['is_vice_captain']);
        $final = fantasy_apply_multiplier($scored['points'], $isC, $isVc, $r);

        $total += $final;
        $out[] = [
            'id'              => $id,
            'name'            => isset($p['name']) ? $p['name'] : '',
            'role'            => isset($p['role']) ? $p['role'] : '',
            'team_name'       => isset($p['team_name']) ? $p['team_name'] : '',
            'is_captain'      => $isC,
            'is_vice_captain' => $isVc,
            'multiplier'      => $isC ? (float) $r['captain_multiplier']
                                      : ($isVc ? (float) $r['vice_captain_multiplier'] : 1.0),
            'base_points'     => $scored['points'],
            'points'          => $final,
            'breakdown'       => $scored['breakdown'],
        ];
    }

    return ['total' => round($total, 2), 'players' => $out];
}
