<?php
/**
 * Tower Rush — a crane drops building blocks onto a tower; every floor that lands carries a
 * multiplier, the multipliers are MULTIPLIED together, and the player can cash out at any time for
 * stake x the running product. A floor can also be x0: the tower collapses and the stake is lost.
 *
 * =================================================================================================
 * THE MATHS
 * =================================================================================================
 *
 * Each floor draws one factor. The non-zero factors and their relative weights reproduce what the
 * original shows (0.45, 0.7, 0.8, 0.85, 1, 1.35, 1.6 most of the time; 2, 3 and 5 rarely):
 *
 *   mean of the non-zero factors   M = sum(v*w) / sum(w) = 1.11875
 *   chance of x0 (collapse)        p0 = 1 - RTP / M          (13.3% at RTP 97%)
 *
 * so every floor's expected factor is exactly RTP: E[factor] = (1 - p0) * M = RTP. Cashing out
 * after n floors returns RTP^n on average — each extra floor is another bet at the same edge, as in
 * the original, where the running total can rise or fall from one floor to the next.
 *
 * The cash-out value is stake x product, floored to the paisa, so rounding never pays more than the
 * product is worth. The operator's max win cashes a tower out automatically when it is reached.
 *
 * =================================================================================================
 * FAIRNESS
 * =================================================================================================
 *
 * Every floor of the round is decided when the bet is placed, from a random server seed whose
 * SHA-256 is shown before the first drop:
 *
 *   u(n) = HMAC-SHA256(server_seed, "floor:" . n), first 13 hex digits / 16^13
 *   u(n) < p0                          -> x0, the tower collapses
 *   otherwise (u(n) - p0) / (1 - p0)   -> picks a factor by the cumulative weights below
 *
 * The seed is revealed when the round ends, so the player can recompute every floor and check the
 * hash. Nothing after the bet (not the player's timing, not an operator) can change a floor.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/** Non-zero floor factors and their relative weights. Order matters (cumulative pick). */
function tr_factor_table() {
    return [
        [0.45, 6], [0.7, 13], [0.8, 13], [0.85, 13], [1.0, 13],
        [1.35, 12], [1.6, 10], [2.0, 5], [3.0, 2.5], [5.0, 0.5],
    ];
}

function tr_factor_mean() {
    $sw = 0.0; $svw = 0.0;
    foreach (tr_factor_table() as [$v, $w]) { $sw += $w; $svw += $v * $w; }
    return $svw / $sw;
}

/** The chance a floor collapses (x0) at this RTP. */
function tr_collapse_chance($rtp) {
    return 1 - $rtp / tr_factor_mean();
}

// -------------------------------------------------------------------------------------------------
// Operator settings
// -------------------------------------------------------------------------------------------------

function tr_config_default() {
    return [
        'enabled' => true,
        'rtp'     => 0.97,
        'max_win' => 1000000.0,
        'min_bet' => (float) cfg('MIN_BET'),
        'max_bet' => min((float) cfg('MAX_BET'), 10000.0),
    ];
}

function tr_config_get() {
    try { $stored = state_get('towerrush_config'); } catch (Throwable $e) { $stored = null; }
    $c = array_merge(tr_config_default(), is_array($stored) ? $stored : []);
    $c['enabled'] = (bool) $c['enabled'];
    foreach (['rtp', 'max_win', 'min_bet', 'max_bet'] as $k) $c[$k] = (float) $c[$k];
    return $c;
}

function tr_config_update(array $patch) {
    $c = tr_config_get();
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
    state_set('towerrush_config', $c);
    return ['ok' => true, 'config' => $c];
}

// -------------------------------------------------------------------------------------------------
// Floors
// -------------------------------------------------------------------------------------------------

/** u(n) in [0,1) for floor n, from the round's seed — see the FAIRNESS note above. */
function tr_floor_unit($serverSeed, $n) {
    $h = hash_hmac('sha256', 'floor:' . (int) $n, (string) $serverSeed);
    return hexdec(substr($h, 0, 13)) / pow(16, 13);
}

/** The factor floor n carries on this round: 0 for a collapse, else one of the table values. */
function tr_floor_factor($serverSeed, $n, $rtp) {
    $u = tr_floor_unit($serverSeed, $n);
    $p0 = tr_collapse_chance($rtp);
    if ($u < $p0) return 0.0;
    $x = ($u - $p0) / (1 - $p0);
    $total = 0.0;
    foreach (tr_factor_table() as [, $w]) $total += $w;
    $acc = 0.0;
    foreach (tr_factor_table() as [$v, $w]) {
        $acc += $w / $total;
        if ($x < $acc) return (float) $v;
    }
    $t = tr_factor_table();
    return (float) $t[count($t) - 1][0];
}

/** Stake x product of factors, floored to the paisa, after the operator's cap. */
function tr_value($bet, $multiplier, $config) {
    $raw = floor($bet * $multiplier * 100 + 1e-7) / 100;
    return min($raw, (float) $config['max_win']);
}

function tr_product(array $factors) {
    $p = 1.0;
    foreach ($factors as $f) $p *= (float) $f;
    return $p;
}

// -------------------------------------------------------------------------------------------------
// Sessions — one row per player, the same claim discipline as ChickenRoadSession
// -------------------------------------------------------------------------------------------------

function tr_session_row($r) {
    return [
        'id'          => (int) $r['id'],
        'username'    => $r['username'],
        'status'      => $r['status'],
        'bet_amount'  => (float) $r['bet_amount'],
        'floors'      => (int) $r['floors'],
        'factors'     => $r['factors'] === null ? [] : (json_decode($r['factors'], true) ?: []),
        'multiplier'  => (float) $r['multiplier'],
        'rtp'         => (float) $r['rtp'],
        'payout'      => (float) $r['payout'],
        'server_seed' => $r['server_seed'],
        'seed_hash'   => $r['seed_hash'],
        'round_ref'   => $r['round_ref'],
    ];
}

function tr_session_get($username) {
    $r = one('SELECT * FROM "TowerRushSession" WHERE LOWER("username") = LOWER(?) LIMIT 1', [$username]);
    return $r ? tr_session_row($r) : null;
}

/**
 * Claim the player's single tower. True on success, false when one is already being built — or when
 * the player's last tower is not the one the request says it follows ($afterRound, the round_ref the
 * page last saw; null if it has seen none). That second condition is what stops a double-clicked
 * BUILD from paying for a new tower: if the first click's floor collapses and finishes the round
 * before the second click lands, the second still names the OLD round and is refused, even when
 * both arrive at the same instant (the check is inside the UPDATE that takes the claim).
 */
function tr_session_claim($username, $afterRound) {
    try {
        if ($afterRound !== null) throw new RuntimeException('the client knows a previous round, so a row must exist');
        q('INSERT INTO "TowerRushSession" ("username","status") VALUES (?, ?)', [$username, 'starting']);
        return true;
    } catch (Throwable $e) {
        $n = affected("UPDATE \"TowerRushSession\"
                       SET \"status\" = 'starting', \"bet_amount\" = 0, \"floors\" = 0, \"factors\" = NULL,
                           \"multiplier\" = 1, \"payout\" = 0, \"server_seed\" = NULL, \"seed_hash\" = NULL,
                           \"round_ref\" = NULL
                       WHERE LOWER(\"username\") = LOWER(?) AND \"status\" IN ('busted','cashed')
                         AND \"round_ref\" IS NOT DISTINCT FROM ?", [$username, $afterRound]);
        return $n > 0;
    }
}

function tr_session_release($username) {
    try {
        q("DELETE FROM \"TowerRushSession\" WHERE LOWER(\"username\") = LOWER(?) AND \"status\" = 'starting'", [$username]);
    } catch (Throwable $e) { /* best effort */ }
}

function tr_session_activate($username, array $s) {
    return affected('UPDATE "TowerRushSession"
                     SET "status" = \'active\', "bet_amount" = ?, "floors" = 0, "factors" = \'[]\', "multiplier" = 1,
                         "payout" = 0, "server_seed" = ?, "seed_hash" = ?, "round_ref" = ?, "rtp" = ?
                     WHERE LOWER("username") = LOWER(?) AND "status" = \'starting\'', [
        $s['bet_amount'], $s['server_seed'], $s['seed_hash'], $s['round_ref'], $s['rtp'], $username,
    ]) > 0;
}

/**
 * Move the tower from $fromFloors to its next state, only if nobody else moved it first: two
 * racing BUILDs cannot both add the same floor, and a BUILD racing a CASHOUT cannot both win.
 */
function tr_session_advance($username, $fromFloors, $toStatus, $toFloors, array $factors, $multiplier, $payout) {
    return affected('UPDATE "TowerRushSession"
                     SET "status" = ?, "floors" = ?, "factors" = ?, "multiplier" = ?, "payout" = ?
                     WHERE LOWER("username") = LOWER(?) AND "status" = \'active\' AND "floors" = ?',
                    [$toStatus, $toFloors, js_json_encode(array_values($factors)), $multiplier, $payout,
                     $username, $fromFloors]) > 0;
}

/** Public view of a session. Never exposes the seed while the tower is still being built. */
function tr_public_state($session, $config, $balance) {
    $status = $session['status'] ?? 'idle';
    if ($status === 'starting' || !$session) $status = 'idle';
    $finished = $status === 'busted' || $status === 'cashed';
    $bet = (float) ($session['bet_amount'] ?? 0);
    $mult = (float) ($session['multiplier'] ?? 1);
    return [
        'status'        => $status,
        'bet_amount'    => $bet,
        'floors'        => (int) ($session['floors'] ?? 0),
        'factors'       => array_values($session['factors'] ?? []),
        'multiplier'    => round($mult, 6),
        'cashout_value' => $status === 'active' && ($session['floors'] ?? 0) > 0 ? tr_value($bet, $mult, $config) : 0,
        'payout'        => $status === 'cashed' ? (float) ($session['payout'] ?? 0) : 0,
        'seed_hash'     => $session['seed_hash'] ?? null,
        'server_seed'   => $finished ? ($session['server_seed'] ?? null) : null,
        'round_ref'     => $session['round_ref'] ?? null,
        'rtp'           => (float) ($session['rtp'] ?? $config['rtp']),
        'balance'       => $balance,
    ];
}

function tr_public_config($config) {
    $total = 0.0;
    foreach (tr_factor_table() as [, $w]) $total += $w;
    $p0 = tr_collapse_chance($config['rtp']);
    $table = [['factor' => 0, 'chance' => round($p0, 4)]];
    foreach (tr_factor_table() as [$v, $w]) $table[] = ['factor' => $v, 'chance' => round((1 - $p0) * $w / $total, 4)];
    return [
        'enabled'  => $config['enabled'],
        'rtp'      => $config['rtp'],
        'max_win'  => $config['max_win'],
        'min_bet'  => $config['min_bet'],
        'max_bet'  => $config['max_bet'],
        'factors'  => $table,
    ];
}
