<?php
/**
 * "Your Eleven" — the fantasy points engine.
 *
 * =================================================================================================
 * PURE, AND RECOMPUTED FROM TOTALS
 * =================================================================================================
 * Every function here is pure: figures in, points out. No database, no feed, no clock. That is what
 * makes the whole scoring surface testable, and it is why the live worker can re-score a match as
 * often as it likes.
 *
 * Just as importantly, scoring reads CUMULATIVE figures ("62 runs off 60 balls, 6 fours, 3 sixes")
 * and recomputes the full total every time. It never adds points for a delivery. A missed poll, a
 * duplicated poll or a corrected scorecard therefore all produce the right answer on the next pass,
 * which is the same property the Node build gets by recomputing off its event log.
 *
 * =================================================================================================
 * THE NUMBERS ARE CONFIGURATION, NOT CONSTANTS BURIED IN CODE
 * =================================================================================================
 * fantasy_scoring_rules() is the single place every value lives, so an operator can be shown exactly
 * what the engine will pay before real money rides on it. Two of those values resolve genuine
 * ambiguities in the specification and should be confirmed before go-live:
 *
 *   milestones_cumulative  A century also passes 50 and 30. Does a hundred pay 16, or 16+8+4 = 28?
 *   hauls_cumulative       Five wickets also passes three. Does it pay 16, or 16+4 = 20?
 *
 * Both default to FALSE — only the highest tier reached pays — because that is what the established
 * fantasy apps do and it is the reading the Node build already implements (see milestoneBonus() and
 * haulBonus() in backend/lib/cricket/scoring.js, which carry the same two flags). Flipping either is
 * a one-line change here and nothing else.
 *
 * One further deviation worth naming: the specification's "Duck = -2" is applied to any player who
 * batted and was dismissed for nought, including bowlers. The established apps exempt bowlers from
 * the duck penalty. duck_exempt_roles makes that an operator decision rather than a silent one.
 */

require_once __DIR__ . '/fantasy.php';

/** Every scoring value, in one place. */
function fantasy_scoring_rules() {
    return [
        // Batting
        'run'                   => 1.0,
        'four'                  => 1.0,
        'six'                   => 2.0,
        'runs_30_bonus'         => 4.0,
        'runs_50_bonus'         => 8.0,
        'runs_100_bonus'        => 16.0,
        'milestones_cumulative' => false,
        'duck'                  => -2.0,
        // Roles that do NOT incur the duck penalty. Empty follows the specification literally;
        // ['BOWL'] would match the established apps.
        'duck_exempt_roles'     => [],

        // Bowling
        'wicket'                => 25.0,
        'bowled_lbw_bonus'      => 8.0,
        'wickets_3_bonus'       => 4.0,
        'wickets_5_bonus'       => 16.0,
        'hauls_cumulative'      => false,
        'maiden_over'           => 12.0,

        // Fielding
        'catch'                 => 8.0,
        'stumping'              => 12.0,
        'runout_direct'         => 12.0,
        'runout_shared'         => 6.0,

        // Leadership
        'captain_multiplier'      => 2.0,
        'vice_captain_multiplier' => 1.5,
    ];
}

/**
 * The milestone bonus for a score.
 *
 * Tiers are checked highest-first so that, when milestones_cumulative is false, the first match is
 * the highest reached. A hundred pays the hundred bonus, not the thirty bonus.
 */
function fantasy_milestone_bonus($runs, array $rules) {
    $runs = (int) $runs;
    $tiers = [
        [100, (float) $rules['runs_100_bonus']],
        [50,  (float) $rules['runs_50_bonus']],
        [30,  (float) $rules['runs_30_bonus']],
    ];
    $reached = [];
    foreach ($tiers as $t) if ($runs >= $t[0]) $reached[] = $t[1];
    if (!$reached) return 0.0;
    if (!empty($rules['milestones_cumulative'])) return array_sum($reached);
    return $reached[0];
}

/** The wicket-haul bonus, same highest-first rule as milestones. */
function fantasy_haul_bonus($wickets, array $rules) {
    $wickets = (int) $wickets;
    $tiers = [
        [5, (float) $rules['wickets_5_bonus']],
        [3, (float) $rules['wickets_3_bonus']],
    ];
    $reached = [];
    foreach ($tiers as $t) if ($wickets >= $t[0]) $reached[] = $t[1];
    if (!$reached) return 0.0;
    if (!empty($rules['hauls_cumulative'])) return array_sum($reached);
    return $reached[0];
}

/**
 * Score one player from their cumulative match figures.
 *
 * $stats keys (all optional, all default to 0):
 *   runs balls fours sixes                      batting
 *   is_out did_bat                              needed to tell a duck from "has not batted"
 *   wickets overs maidens runs_conceded bowled_lbw   bowling
 *   catches stumpings runouts_direct runouts_shared  fielding
 *   role                                        only used for the duck exemption
 *
 * Returns ['points' => float, 'breakdown' => [label => points]]. The breakdown exists so a player
 * can be shown WHY they scored what they did, and so a disputed total can be checked line by line
 * rather than argued about.
 */
function fantasy_score_player(array $stats, array $rules = null) {
    $r = $rules ?: fantasy_scoring_rules();
    $n = function ($key) use ($stats) {
        return isset($stats[$key]) ? (float) $stats[$key] : 0.0;
    };

    $breakdown = [];

    // --- batting ---
    $runs = $n('runs');
    if ($runs != 0) $breakdown['runs'] = $runs * (float) $r['run'];
    if ($n('fours') > 0) $breakdown['fours'] = $n('fours') * (float) $r['four'];
    if ($n('sixes') > 0) $breakdown['sixes'] = $n('sixes') * (float) $r['six'];

    $milestone = fantasy_milestone_bonus($runs, $r);
    if ($milestone != 0) $breakdown['milestone'] = $milestone;

    // A duck is being dismissed for nought, which is not the same as not having batted, and not the
    // same as being 0 not out. Both of those must score nothing rather than -2.
    $didBat = !empty($stats['did_bat']);
    $isOut  = !empty($stats['is_out']);
    $role   = isset($stats['role']) ? fantasy_normalise_role($stats['role']) : null;
    $exempt = in_array($role, (array) ($r['duck_exempt_roles'] ?? []), true);
    if ($didBat && $isOut && (int) $runs === 0 && !$exempt) {
        $breakdown['duck'] = (float) $r['duck'];
    }

    // --- bowling ---
    if ($n('wickets') > 0) $breakdown['wickets'] = $n('wickets') * (float) $r['wicket'];
    if ($n('bowled_lbw') > 0) $breakdown['bowled_lbw'] = $n('bowled_lbw') * (float) $r['bowled_lbw_bonus'];
    $haul = fantasy_haul_bonus($n('wickets'), $r);
    if ($haul != 0) $breakdown['haul'] = $haul;
    if ($n('maidens') > 0) $breakdown['maidens'] = $n('maidens') * (float) $r['maiden_over'];

    // --- fielding ---
    if ($n('catches') > 0)        $breakdown['catches']        = $n('catches') * (float) $r['catch'];
    if ($n('stumpings') > 0)      $breakdown['stumpings']      = $n('stumpings') * (float) $r['stumping'];
    if ($n('runouts_direct') > 0) $breakdown['runouts_direct'] = $n('runouts_direct') * (float) $r['runout_direct'];
    if ($n('runouts_shared') > 0) $breakdown['runouts_shared'] = $n('runouts_shared') * (float) $r['runout_shared'];

    $total = 0.0;
    foreach ($breakdown as $v) $total += $v;

    return ['points' => round($total, 2), 'breakdown' => $breakdown];
}

/**
 * Apply the captain / vice-captain multiplier to a base score.
 *
 * Multiplies the WHOLE total, negatives included: a captain who is dismissed for nought loses double.
 * That is how the established apps behave, and the alternative — multiplying only the positive part —
 * would make the armband a free bet.
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
 * $statsById: [player_id => stats array]. A player with no stats row scores 0 — someone who did not
 * take the field is not an error.
 *
 * Returns ['total' => float, 'players' => [...per player, with base and final points...]].
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
