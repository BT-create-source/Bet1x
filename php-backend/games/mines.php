<?php
/**
 * Mines — 25-tile board, one live game per player.
 *
 * The only structural change is where a session lives. server.js kept boards in the
 * MINES_USER_SESSIONS object, so a process restart wiped every board in flight; here they are rows
 * in MinesSession, which means they survive. That is strictly better and is the one difference.
 *
 * The important guard is preserved exactly. server.js claims the player's single session slot
 * SYNCHRONOUSLY, before its first await, because testing showed twelve simultaneous starts were all
 * accepted and all twelve stakes taken for one board: every request read the map, saw no active
 * session, and only then hit the wallet debit. Here the claim is an INSERT against a UNIQUE index
 * on username — a colliding insert is the exact equivalent of finding a session already present,
 * and it holds across processes, which the in-memory map never did.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

/**
 * The multiplier after N safe reveals: the inverse of the cumulative safe-draw probability,
 * times a 0.97 house edge, rounded to 2dp.
 */
function calculate_mines_multiplier($gridSize, $minesCount, $revealedCount) {
    if ($revealedCount <= 0) return 1.0;
    $prob = 1.0;
    for ($i = 0; $i < $revealedCount; $i++) {
        $safeLeft  = $gridSize - $minesCount - $i;
        $totalLeft = $gridSize - $i;
        if ($safeLeft <= 0) return 0.0;
        $prob *= ($safeLeft / $totalLeft);
    }
    return to_fixed_num((1.0 / $prob) * 0.97, 2);
}

// -------------------------------------------------------------------------------------------------
// Sessions
// -------------------------------------------------------------------------------------------------

function mines_session_get($username) {
    $r = one('SELECT * FROM `MinesSession` WHERE LOWER(`username`) = LOWER(?) LIMIT 1', [$username]);
    if (!$r) return null;
    return [
        'id'               => (int)$r['id'],
        'username'         => $r['username'],
        'status'           => $r['status'],
        'bet_amount'       => (float)$r['bet_amount'],
        'mines_count'      => (int)$r['mines_count'],
        'server_seed'      => $r['server_seed'],
        'seed_hash'        => $r['seed_hash'],
        'mine_positions'   => $r['mine_positions'] === null ? [] : (json_decode($r['mine_positions'], true) ?: []),
        'revealed'         => $r['revealed'] === null ? [] : (json_decode($r['revealed'], true) ?: []),
        'multiplier'       => (float)$r['multiplier'],
        'potential_payout' => (float)$r['potential_payout'],
    ];
}

/** Every live session, for the operator's active-users view and the mass trap. */
function mines_sessions_all() {
    $rows = all('SELECT * FROM `MinesSession`');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['username']] = [
            'username'         => $r['username'],
            'status'           => $r['status'],
            'bet_amount'       => (float)$r['bet_amount'],
            'mines_count'      => (int)$r['mines_count'],
            'server_seed'      => $r['server_seed'],
            'seed_hash'        => $r['seed_hash'],
            'mine_positions'   => $r['mine_positions'] === null ? [] : (json_decode($r['mine_positions'], true) ?: []),
            'revealed'         => $r['revealed'] === null ? [] : (json_decode($r['revealed'], true) ?: []),
            'multiplier'       => (float)$r['multiplier'],
            'potential_payout' => (float)$r['potential_payout'],
        ];
    }
    return $out;
}

/**
 * Claim the player's single session slot.
 *
 * Returns true when the claim succeeded and false when a round is already in progress. The INSERT
 * either wins the unique index or it does not; there is no read-then-write window for a
 * double-clicked Start to slip through.
 */
function mines_session_claim($username) {
    try {
        q('INSERT INTO `MinesSession` (`username`,`status`,`bet_amount`,`mines_count`,`multiplier`,`potential_payout`)
           VALUES (?, ?, 0, 3, 1, 0)', [$username, 'starting']);
        return true;
    } catch (Throwable $e) {
        // Duplicate key: a session row already exists. Whether it BLOCKS the new round depends on
        // its status — a finished board ('busted'/'cashed') is replaced, exactly as the original
        // overwrote a stale entry in its map.
        $existing = mines_session_get($username);
        if ($existing && ($existing['status'] === 'active' || $existing['status'] === 'starting')) {
            return false;
        }
        $n = affected("UPDATE `MinesSession`
                       SET `status` = 'starting', `bet_amount` = 0, `mines_count` = 3, `server_seed` = NULL,
                           `seed_hash` = NULL, `mine_positions` = NULL, `revealed` = NULL,
                           `multiplier` = 1, `potential_payout` = 0
                       WHERE LOWER(`username`) = LOWER(?) AND `status` IN ('busted','cashed')", [$username]);
        return $n > 0;
    }
}

/** Release a claim that never became a real round, so the player is not locked out. */
function mines_session_release($username) {
    try {
        q("DELETE FROM `MinesSession` WHERE LOWER(`username`) = LOWER(?) AND `status` = 'starting'", [$username]);
    } catch (Throwable $e) { /* best effort */ }
}

function mines_session_write($username, array $s) {
    q('UPDATE `MinesSession`
       SET `status` = ?, `bet_amount` = ?, `mines_count` = ?, `server_seed` = ?, `seed_hash` = ?,
           `mine_positions` = ?, `revealed` = ?, `multiplier` = ?, `potential_payout` = ?
       WHERE LOWER(`username`) = LOWER(?)', [
        $s['status'], $s['bet_amount'], $s['mines_count'], $s['server_seed'], $s['seed_hash'],
        js_json_encode(array_values($s['mine_positions'])), js_json_encode(array_values($s['revealed'])),
        $s['multiplier'], $s['potential_payout'], $username,
    ]);
}

/**
 * Flip a session's status only if it currently holds the expected one.
 *
 * The PHP equivalent of `session.status = 'cashed'` happening before any await: two cash-out
 * requests racing each other cannot both see an 'active' session and both get paid.
 */
function mines_session_claim_status($username, $from, $to) {
    return affected('UPDATE `MinesSession` SET `status` = ? WHERE LOWER(`username`) = LOWER(?) AND `status` = ?',
                    [$to, $username, $from]) > 0;
}

// -------------------------------------------------------------------------------------------------
// Rig configuration
// -------------------------------------------------------------------------------------------------

function mines_rig_default() {
    return [
        'matrix'       => array_fill(0, 25, 'auto'),  // 'auto', 'safe', 'mine'
        'next_tile'    => null,                        // null, 'gem', 'mine'
        'rig_type'     => '',                          // '', 'guarantee_win', 'platform_profit'
        'target_users' => [],                          // targeted usernames for simultaneous traps
    ];
}

function mines_rig_get() {
    try { $stored = state_get('mines_rig_config'); } catch (Throwable $e) { $stored = null; }
    if (!is_array($stored)) return mines_rig_default();
    return array_merge(mines_rig_default(), $stored);
}

function mines_rig_set($config) {
    state_set('mines_rig_config', $config);
    return $config;
}

/** Replaces the MINES_TOTAL_TRAP_PROFIT module variable. */
function mines_trap_profit_get() {
    try { $rec = state_get('mines_total_trap_profit'); } catch (Throwable $e) { $rec = null; }
    return is_array($rec) ? (float)($rec['total'] ?? 0) : 0.0;
}

function mines_trap_profit_add($amount) {
    $total = mines_trap_profit_get() + (float)$amount;
    try { state_set('mines_total_trap_profit', ['total' => $total]); } catch (Throwable $e) {}
    return $total;
}
