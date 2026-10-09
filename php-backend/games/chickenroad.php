<?php
/**
 * Chicken Road — a hen crosses a road one lane at a time; every lane raises the multiplier, and any
 * lane can be the manhole that bursts into flame. Cash out before that, or lose the stake.
 *
 * =================================================================================================
 * THE MATHS, AND WHY IT IS THIS AND NOT SOMETHING SIMPLER
 * =================================================================================================
 *
 * The published game's figures pin the model down exactly. Treat the road as 25 hidden slots, of
 * which H are fire (1 / 3 / 5 / 10 by difficulty), and walk the slots without replacement:
 *
 *   lanes(H)         = 25 - H                                  24 / 22 / 20 / 15
 *   P(survive n)     = prod_{i<n} (25-H-i) / (25-i)
 *   multiplier(n)    = RTP / P(survive n)
 *
 * At RTP 98% that reproduces every number the original quotes: Easy opens at 1.02x and tops out at
 * 24.50x on lane 24; Hard tops out at 52,067.40x on lane 20; Hardcore at 3,203,384.80x on lane 15.
 * A flat per-lane hazard (the obvious alternative) cannot hit both ends of those ladders at once.
 *
 * Multipliers are floored to two decimals, so the paid figure never exceeds the true fair value
 * times RTP — rounding can only ever cost the player a fraction of a paisa, never the house.
 *
 * Cashing out at ANY lane n returns RTP on average: P(survive n) * multiplier(n) = RTP. There is no
 * lane where stopping is better or worse value than any other, exactly as in the original.
 *
 * =================================================================================================
 * FAIRNESS
 * =================================================================================================
 *
 * The whole road is decided at the moment the bet is placed, from a random server seed whose
 * SHA-256 is shown to the player before the first step. Lane n burns when
 *
 *   u(n) = HMAC-SHA256(server_seed, "lane:" . n), first 13 hex digits / 16^13
 *   u(n) < H / (26 - n)
 *
 * — the conditional chance that slot n is fire given the n-1 before it were not. The seed is
 * revealed when the round ends, so the player can recompute every lane and check the hash. Nothing
 * after the bet (not the player's timing, not an operator) can move the fire.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

const CR_SLOTS = 25;

/** The four difficulty modes, in display order. */
function cr_difficulties() {
    return [
        'easy'     => ['label' => 'Easy',     'hazards' => 1],
        'medium'   => ['label' => 'Medium',   'hazards' => 3],
        'hard'     => ['label' => 'Hard',     'hazards' => 5],
        'hardcore' => ['label' => 'Hardcore', 'hazards' => 10],
    ];
}

function cr_lane_count($difficulty) {
    $d = cr_difficulties()[$difficulty] ?? null;
    return $d ? CR_SLOTS - $d['hazards'] : 0;
}

// -------------------------------------------------------------------------------------------------
// Operator settings
// -------------------------------------------------------------------------------------------------

function cr_config_default() {
    return [
        'enabled' => true,
        'rtp'     => 0.98,
        // Largest single payout. The Hardcore ladder reaches 3.2 million x, so without a cap one
        // lucky road on a big stake is unbounded house exposure. Reaching the cap cashes out.
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
 * RTP is bounded to 90-99%: below that the game stops resembling the original, above it the house
 * edge is too thin to absorb the cap and rounding.
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

/** Chance of surviving the first $n lanes of a road with $hazards fire slots. */
function cr_survival($hazards, $n) {
    $p = 1.0;
    for ($i = 0; $i < $n; $i++) $p *= (CR_SLOTS - $hazards - $i) / (CR_SLOTS - $i);
    return $p;
}

/** Multiplier for having crossed $n lanes (n >= 1), floored to two decimals. */
function cr_multiplier($difficulty, $n, $rtp) {
    $d = cr_difficulties()[$difficulty] ?? null;
    if (!$d || $n <= 0) return 1.0;
    $n = min($n, CR_SLOTS - $d['hazards']);
    // The small epsilon keeps exact values (24.50 on Easy) from flooring to 24.49 through float noise.
    return floor(($rtp / cr_survival($d['hazards'], $n)) * 100 + 1e-7) / 100;
}

/** The full ladder for one difficulty: index 0 is lane 1. */
function cr_ladder($difficulty, $rtp) {
    $out = [];
    $lanes = cr_lane_count($difficulty);
    for ($n = 1; $n <= $lanes; $n++) $out[] = cr_multiplier($difficulty, $n, $rtp);
    return $out;
}

/** u(n) in [0,1) for lane n, derived from the round's seed — see the FAIRNESS note above. */
function cr_lane_unit($serverSeed, $n) {
    $h = hash_hmac('sha256', 'lane:' . (int) $n, (string) $serverSeed);
    return hexdec(substr($h, 0, 13)) / pow(16, 13);
}

/** The lane that burns on this road, or 0 if the hen can cross all of it. */
function cr_fail_step($serverSeed, $difficulty) {
    $d = cr_difficulties()[$difficulty] ?? null;
    if (!$d) return 1;
    $lanes = CR_SLOTS - $d['hazards'];
    for ($n = 1; $n <= $lanes; $n++) {
        if (cr_lane_unit($serverSeed, $n) < $d['hazards'] / (CR_SLOTS + 1 - $n)) return $n;
    }
    return 0;
}

/** What cashing out after $step lanes pays, after the operator's cap. */
function cr_payout($bet, $difficulty, $step, $config) {
    if ($step <= 0) return 0.0;
    $raw = round2($bet * cr_multiplier($difficulty, $step, $config['rtp']));
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

/** Public view of a session. Never exposes the fire lane or the seed while the road is live. */
function cr_public_state($session, $config, $balance) {
    $status = $session['status'] ?? 'idle';
    if ($status === 'starting' || !$session) $status = 'idle';
    $finished = $status === 'busted' || $status === 'cashed';
    $difficulty = $session['difficulty'] ?? 'easy';
    $step = (int) ($session['step'] ?? 0);
    $bet = (float) ($session['bet_amount'] ?? 0);
    $lanes = cr_lane_count($difficulty);

    $nextMult = ($status === 'active' && $step < $lanes) ? cr_multiplier($difficulty, $step + 1, $config['rtp']) : null;

    return [
        'status'          => $status,
        'difficulty'      => $difficulty,
        'lanes'           => $lanes,
        'bet_amount'      => $bet,
        'step'            => $step,
        'multiplier'      => $step > 0 ? cr_multiplier($difficulty, $step, $config['rtp']) : 1.0,
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
            'key'     => $key,
            'label'   => $d['label'],
            'hazards' => $d['hazards'],
            'lanes'   => CR_SLOTS - $d['hazards'],
            'ladder'  => cr_ladder($key, $config['rtp']),
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
