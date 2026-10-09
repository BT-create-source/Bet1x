<?php
/**
 * Chicken Road (the "2" edition, with traffic) — a hen crosses a road one lane at a time; every lane
 * raises the multiplier, and on any lane a car can run her over. Cash out before that, or lose the
 * stake.
 *
 * =================================================================================================
 * THE LADDERS
 * =================================================================================================
 *
 * Lane counts are 30 / 25 / 22 / 18 (Easy / Medium / Hard / Hardcore) and the multipliers are the
 * published game's, used verbatim like the Mines payout table. What is confirmed and what is not:
 *
 *   - Lanes 1-6 of every mode are read off gameplay footage of the original.
 *   - Easy's whole ladder (1.01x -> 23.24x) and Hardcore's top (3,608,855.25x) match published figures.
 *   - The remaining tail values are the best available reconstruction, kept smooth and rising.
 *
 * =================================================================================================
 * THE RISK, AND WHY ANY LADDER WORKS
 * =================================================================================================
 *
 * The chance of the car comes from the ladder itself, so that cashing out at ANY lane returns RTP:
 *
 *   P(survive n)               = RTP / M(n)
 *   P(hit on lane n | reached) = 1 - M(n-1) / M(n),   with M(0) = RTP
 *
 * so P(survive n) * M(n) = RTP for every lane of every mode. The operator's RTP setting moves the
 * risk, never the displayed multipliers. It must stay below the smallest first lane (1.01x), which
 * the 90-99% bound guarantees.
 *
 * =================================================================================================
 * FAIRNESS
 * =================================================================================================
 *
 * The whole road is decided when the bet is placed, from a random server seed whose SHA-256 is shown
 * before the first step. Lane n is hit when
 *
 *   u(n) = HMAC-SHA256(server_seed, "lane:" . n), first 13 hex digits / 16^13
 *   u(n) < 1 - M(n-1) / M(n)
 *
 * The seed is revealed when the road ends, so the player can recompute every lane and check the
 * hash. Nothing after the bet (not the player's timing, not an operator) can move the car.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/** The four difficulty modes, in display order, with their ladders (index 0 = lane 1). */
function cr_difficulties() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        'easy' => ['label' => 'Easy', 'ladder' => [
            1.01, 1.03, 1.06, 1.10, 1.15, 1.19, 1.24, 1.30, 1.35, 1.42, 1.48, 1.56, 1.65, 1.75, 1.85,
            1.98, 2.12, 2.28, 2.47, 2.70, 2.96, 3.28, 3.70, 4.11, 4.64, 5.39, 6.50, 8.36, 12.08, 23.24,
        ]],
        'medium' => ['label' => 'Medium', 'ladder' => [
            1.08, 1.21, 1.37, 1.56, 1.78, 2.05, 2.37, 2.77, 3.24, 3.85, 4.62, 5.61, 6.91, 8.64, 10.99,
            14.29, 18.96, 25.82, 36.27, 52.97, 80.91, 132.45, 239.08, 510.86, 1566.05,
        ]],
        'hard' => ['label' => 'Hard', 'ladder' => [
            1.18, 1.46, 1.83, 2.31, 2.95, 3.82, 5.02, 6.66, 9.04, 12.52, 17.74, 25.80, 38.71, 60.21,
            97.34, 165.07, 296.07, 568.38, 1183.30, 2738.10, 7569.80, 30279.20,
        ]],
        'hardcore' => ['label' => 'Hardcore', 'ladder' => [
            1.44, 2.21, 3.45, 5.53, 9.09, 15.30, 26.78, 48.70, 92.54, 185.08, 391.25, 894.29, 2235.72,
            6096.15, 19507.68, 78030.72, 429168.96, 3608855.25,
        ]],
    ];
    return $d;
}

function cr_lane_count($difficulty) {
    $d = cr_difficulties()[$difficulty] ?? null;
    return $d ? count($d['ladder']) : 0;
}

// -------------------------------------------------------------------------------------------------
// Operator settings
// -------------------------------------------------------------------------------------------------

function cr_config_default() {
    return [
        'enabled' => true,
        'rtp'     => 0.955,   // the original developer's stated figure
        // Largest single payout. Hardcore reaches 3.6 million x, so without a cap one lucky road on
        // a big stake is unbounded house exposure. Reaching the cap cashes out.
        'max_win' => 1000000.0,
        'min_bet' => (float) cfg('MIN_BET'),
        'max_bet' => min((float) cfg('MAX_BET'), 10000.0),
    ];
}

function cr_config_get() {
    try { $stored = state_get('chickenroad_config'); } catch (Throwable $e) { $stored = null; }
    $c = array_merge(cr_config_default(), is_array($stored) ? $stored : []);
    $c['enabled'] = (bool) $c['enabled'];
    $c['rtp']     = (float) $c['rtp'];
    $c['max_win'] = (float) $c['max_win'];
    $c['min_bet'] = (float) $c['min_bet'];
    $c['max_bet'] = (float) $c['max_bet'];
    return $c;
}

/**
 * Validate and store operator settings. Returns ['ok'=>true,'config'=>...] or ['ok'=>false,'error'=>...].
 * RTP is bounded to 90-99%: the top of that range keeps it below the lowest first-lane multiplier
 * (1.01x), which the risk formula above requires.
 */
function cr_config_update(array $patch) {
    $c = cr_config_get();
    if (array_key_exists('enabled', $patch)) $c['enabled'] = js_truthy($patch['enabled']);
    foreach (['rtp', 'max_win', 'min_bet', 'max_bet'] as $k) {
        if (!array_key_exists($k, $patch)) continue;
        $v = js_parse_float($patch[$k]);
        if (!js_is_finite($v)) return ['ok' => false, 'error' => "Invalid value for $k."];
        $c[$k] = (float) $v;
    }
    if ($c['rtp'] > 1.5) $c['rtp'] = $c['rtp'] / 100;   // accept 97 as well as 0.97
    if ($c['rtp'] < 0.90 || $c['rtp'] > 0.99) return ['ok' => false, 'error' => 'RTP must be between 90% and 99%.'];
    if ($c['min_bet'] <= 0) return ['ok' => false, 'error' => 'Minimum bet must be positive.'];
    if ($c['max_bet'] < $c['min_bet']) return ['ok' => false, 'error' => 'Maximum bet must be at least the minimum bet.'];
    if ($c['max_win'] < $c['max_bet']) return ['ok' => false, 'error' => 'Maximum win must be at least the maximum bet.'];
    $c['rtp'] = round($c['rtp'], 4);
    state_set('chickenroad_config', $c);
    return ['ok' => true, 'config' => $c];
}

// -------------------------------------------------------------------------------------------------
// Maths
// -------------------------------------------------------------------------------------------------

/** Multiplier for having crossed $n lanes (n >= 1). $rtp is accepted for call compatibility; the ladder is fixed. */
function cr_multiplier($difficulty, $n, $rtp = null) {
    $d = cr_difficulties()[$difficulty] ?? null;
    if (!$d || $n <= 0) return 1.0;
    $n = min($n, count($d['ladder']));
    return (float) $d['ladder'][$n - 1];
}

/** The full ladder for one difficulty: index 0 is lane 1. */
function cr_ladder($difficulty, $rtp = null) {
    $d = cr_difficulties()[$difficulty] ?? null;
    return $d ? array_map('floatval', $d['ladder']) : [];
}

/** Chance of surviving the first $n lanes: RTP / M(n). */
function cr_survival($difficulty, $n, $rtp) {
    if ($n <= 0) return 1.0;
    return $rtp / cr_multiplier($difficulty, $n);
}

/** Chance that lane $n is hit, given the hen reached it. */
function cr_hit_chance($difficulty, $n, $rtp) {
    $prev = $n <= 1 ? $rtp : cr_multiplier($difficulty, $n - 1);
    return 1 - $prev / cr_multiplier($difficulty, $n);
}

/** u(n) in [0,1) for lane n, derived from the round's seed — see the FAIRNESS note above. */
function cr_lane_unit($serverSeed, $n) {
    $h = hash_hmac('sha256', 'lane:' . (int) $n, (string) $serverSeed);
    return hexdec(substr($h, 0, 13)) / pow(16, 13);
}

/** The lane where the car hits on this road, or 0 if the hen can cross all of it. */
function cr_fail_step($serverSeed, $difficulty, $rtp) {
    $lanes = cr_lane_count($difficulty);
    if ($lanes === 0) return 1;
    for ($n = 1; $n <= $lanes; $n++) {
        if (cr_lane_unit($serverSeed, $n) < cr_hit_chance($difficulty, $n, $rtp)) return $n;
    }
    return 0;
}

/** What cashing out after $step lanes pays, after the operator's cap. */
function cr_payout($bet, $difficulty, $step, $config) {
    if ($step <= 0) return 0.0;
    $raw = round2($bet * cr_multiplier($difficulty, $step));
    return min($raw, (float) $config['max_win']);
}

// -------------------------------------------------------------------------------------------------
// Sessions — one row per player, the same claim discipline as MinesSession
// -------------------------------------------------------------------------------------------------

function cr_session_row_to_array($r) {
    return [
        'id'          => (int) $r['id'],
        'username'    => $r['username'],
        'status'      => $r['status'],
        'difficulty'  => $r['difficulty'],
        'bet_amount'  => (float) $r['bet_amount'],
        'step'        => (int) $r['step'],
        'fail_step'   => (int) $r['fail_step'],
        'server_seed' => $r['server_seed'],
        'seed_hash'   => $r['seed_hash'],
        'multiplier'  => (float) $r['multiplier'],
        'payout'      => (float) $r['payout'],
        'round_ref'   => $r['round_ref'],
    ];
}

function cr_session_get($username) {
    $r = one('SELECT * FROM "ChickenRoadSession" WHERE LOWER("username") = LOWER(?) LIMIT 1', [$username]);
    return $r ? cr_session_row_to_array($r) : null;
}

/**
 * Claim the player's single road. True on success, false when a road is already in progress. The
 * INSERT either wins the unique index or it does not, so a double-clicked Play takes one stake.
 */
function cr_session_claim($username) {
    try {
        q('INSERT INTO "ChickenRoadSession" ("username","status") VALUES (?, ?)', [$username, 'starting']);
        return true;
    } catch (Throwable $e) {
        $n = affected("UPDATE \"ChickenRoadSession\"
                       SET \"status\" = 'starting', \"bet_amount\" = 0, \"step\" = 0, \"fail_step\" = 0,
                           \"server_seed\" = NULL, \"seed_hash\" = NULL, \"multiplier\" = 1, \"payout\" = 0,
                           \"round_ref\" = NULL
                       WHERE LOWER(\"username\") = LOWER(?) AND \"status\" IN ('busted','cashed')", [$username]);
        return $n > 0;
    }
}

/** Release a claim that never became a real round, so the player is not locked out. */
function cr_session_release($username) {
    try {
        q("DELETE FROM \"ChickenRoadSession\" WHERE LOWER(\"username\") = LOWER(?) AND \"status\" = 'starting'", [$username]);
    } catch (Throwable $e) { /* best effort */ }
}

/** Turn the 'starting' claim into a live road. */
function cr_session_activate($username, array $s) {
    return affected('UPDATE "ChickenRoadSession"
                     SET "status" = \'active\', "difficulty" = ?, "bet_amount" = ?, "step" = 0, "fail_step" = ?,
                         "server_seed" = ?, "seed_hash" = ?, "multiplier" = 1, "payout" = 0, "round_ref" = ?
                     WHERE LOWER("username") = LOWER(?) AND "status" = \'starting\'', [
        $s['difficulty'], $s['bet_amount'], $s['fail_step'], $s['server_seed'], $s['seed_hash'],
        $s['round_ref'], $username,
    ]) > 0;
}

/**
 * Move the road from lane $fromStep to the outcome of the next lane, but only if nobody else has
 * moved it first. Two GO requests racing each other cannot both advance from the same lane, and a
 * GO racing a CASH OUT cannot both win: whichever UPDATE lands first changes the row the other one
 * is conditioned on.
 */
function cr_session_advance($username, $fromStep, $toStatus, $toStep, $multiplier, $payout) {
    return affected('UPDATE "ChickenRoadSession"
                     SET "status" = ?, "step" = ?, "multiplier" = ?, "payout" = ?
                     WHERE LOWER("username") = LOWER(?) AND "status" = \'active\' AND "step" = ?',
                    [$toStatus, $toStep, $multiplier, $payout, $username, $fromStep]) > 0;
}

/**
 * Public view of a session. Never exposes the hit lane or the seed while the road is live.
 * ('fire_lane' keeps its name from the first edition: it is the lane where the car hit.)
 */
function cr_public_state($session, $config, $balance) {
    $status = $session['status'] ?? 'idle';
    if ($status === 'starting' || !$session) $status = 'idle';
    $finished = $status === 'busted' || $status === 'cashed';
    $difficulty = $session['difficulty'] ?? 'easy';
    $step = (int) ($session['step'] ?? 0);
    $bet = (float) ($session['bet_amount'] ?? 0);
    $lanes = cr_lane_count($difficulty);

    $nextMult = ($status === 'active' && $step < $lanes) ? cr_multiplier($difficulty, $step + 1) : null;

    return [
        'status'          => $status,
        'difficulty'      => $difficulty,
        'lanes'           => $lanes,
        'bet_amount'      => $bet,
        'step'            => $step,
        'multiplier'      => $step > 0 ? cr_multiplier($difficulty, $step) : 1.0,
        'next_multiplier' => $nextMult,
        'cashout_value'   => $status === 'active' ? cr_payout($bet, $difficulty, $step, $config) : 0,
        'payout'          => $status === 'cashed' ? (float) ($session['payout'] ?? 0) : 0,
        'fire_lane'       => $status === 'busted' ? $step + 1 : null,
        'seed_hash'       => $session['seed_hash'] ?? null,
        'server_seed'     => $finished ? ($session['server_seed'] ?? null) : null,
        'round_ref'       => $session['round_ref'] ?? null,
        'balance'         => $balance,
    ];
}

/** The settings and ladders the page needs to draw the road before any round exists. */
function cr_public_config($config) {
    $diffs = [];
    foreach (cr_difficulties() as $key => $d) {
        $diffs[] = [
            'key'        => $key,
            'label'      => $d['label'],
            'lanes'      => count($d['ladder']),
            'ladder'     => cr_ladder($key),
            'lane1_risk' => round(cr_hit_chance($key, 1, $config['rtp']), 4),
        ];
    }
    return [
        'enabled'      => $config['enabled'],
        'rtp'          => $config['rtp'],
        'max_win'      => $config['max_win'],
        'min_bet'      => $config['min_bet'],
        'max_bet'      => $config['max_bet'],
        'difficulties' => $diffs,
    ];
}

/**
 * The "Live wins / Online" strip: real recent cash-outs (masked) and the number of players who have
 * touched a road in the last five minutes. Nothing here is invented.
 */
function cr_live_feed() {
    $rows = all('SELECT "user","amount","timestamp" FROM "Transaction"
                 WHERE "details" LIKE \'Chicken Road Cash Out%\' ORDER BY "timestamp" DESC LIMIT 15');
    $wins = array_map(function ($r) {
        $name = (string) $r['user'];
        $masked = strlen($name) <= 2 ? $name . '***' : substr($name, 0, 1) . '***' . substr($name, -1);
        return ['player' => $masked, 'amount' => (float) $r['amount']];
    }, $rows);
    $online = (int) scalar('SELECT COUNT(*) FROM "ChickenRoadSession"
                            WHERE "updated_at" > CURRENT_TIMESTAMP(3) - INTERVAL \'5 minutes\'', [], 0);
    return ['wins' => $wins, 'online' => $online];
}
