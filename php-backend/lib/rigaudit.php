<?php
/**
 * Rig audit ledger — a read-only observability layer over the house-edge engine.
 * Port of backend/lib/rig-audit.js.
 *
 * Answers "is the configured percentage actually what the engine is doing?" with measured numbers
 * rather than impressions. It is deliberately inert with respect to gameplay: record() only
 * appends, never returns a value a caller could branch on, and never throws — a bug in here must
 * not be able to change or interrupt a round.
 *
 * PORT NOTE. The Node version kept a 5,000-entry ring buffer in memory, explicitly to keep a
 * database write out of the path of every round resolution. PHP has no memory between requests, so
 * this is now a capped table. The cap and the reporting maths are unchanged; the cost is one small
 * INSERT per decision. Trimming happens on roughly 1 in 50 writes (and on every cron tick) rather
 * than on every write, so the common path stays a single INSERT.
 */

require_once __DIR__ . '/db.php';

const RIG_AUDIT_MAX_ENTRIES = 5000;

/**
 * Record one rig decision. Swallows every error by design.
 *
 * $d keys: game, instance, round, configured_pct, rigged, eligible, live, targeted, house_profit, note
 */
function rig_record($d) {
    try {
        if (!$d || empty($d['game'])) return;
        q('INSERT INTO "RigAudit"
             ("ts","game","instance","round","configured_pct","rigged","eligible","live","targeted","house_profit","note")
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            now_ms(),
            (string)$d['game'],
            isset($d['instance']) && $d['instance'] !== null ? (string)$d['instance'] : null,
            isset($d['round']) && $d['round'] !== null ? (string)$d['round'] : null,
            js_is_finite(js_parse_float($d['configured_pct'] ?? 0)) ? (float)js_parse_float($d['configured_pct'] ?? 0) : 0.0,
            !empty($d['rigged']) ? 1 : 0,
            (!array_key_exists('eligible', $d) || $d['eligible'] === null) ? 1 : (!empty($d['eligible']) ? 1 : 0),
            (isset($d['live']) && js_is_finite($d['live'])) ? (int)$d['live'] : null,
            (isset($d['targeted']) && js_is_finite($d['targeted'])) ? (int)$d['targeted'] : null,
            (isset($d['house_profit']) && js_is_finite($d['house_profit'])) ? (float)$d['house_profit'] : null,
            isset($d['note']) && $d['note'] !== null ? mb_substr((string)$d['note'], 0, 200) : null,
        ]);
        if (mt_rand(1, 50) === 1) rig_trim();
    } catch (Throwable $e) {
        // Audit must never break a round. Swallowing here is the whole point.
    }
}

/** Keep the table to the same 5,000-entry cap the ring buffer had. */
function rig_trim() {
    try {
        $cutoff = scalar(
            'SELECT "id" FROM "RigAudit" ORDER BY "id" DESC LIMIT 1 OFFSET ' . (int) RIG_AUDIT_MAX_ENTRIES
        );
        if ($cutoff !== null && $cutoff !== false) {
            q('DELETE FROM "RigAudit" WHERE "id" <= ?', [(int)$cutoff]);
        }
    } catch (Throwable $e) { /* best effort */ }
}

/**
 * Summarise a list of decision rows.
 *
 * Only rounds that actually consulted the engine count toward the ratio; rounds it was never asked
 * about are reported separately rather than diluting the figure. Teen Patti only draws for tables
 * with a real player on them, and counting NPC-only hands as decisions dragged a correct 50% down
 * to a reported 29%.
 */
function rig_summarise(array $list) {
    $considered = array_values(array_filter($list, function ($e) { return (int)$e['eligible'] !== 0; }));
    $decisions = count($considered);
    $skipped   = count($list) - $decisions;
    $rigged    = count(array_filter($considered, function ($e) { return (int)$e['rigged'] !== 0; }));

    // The configured percentage can change mid-window, so report the most recent one rather than
    // an average of settings that were never simultaneously in effect.
    $configured = $decisions > 0 ? (float)$considered[$decisions - 1]['configured_pct'] : 0.0;
    $observed   = $decisions > 0 ? ($rigged / $decisions) * 100 : 0.0;

    $profitEntries = array_values(array_filter($considered, function ($e) { return $e['house_profit'] !== null; }));
    $profit = null;
    if (count($profitEntries)) {
        $sum = 0.0;
        foreach ($profitEntries as $e) $sum += (float)$e['house_profit'];
        $profit = to_fixed_num($sum, 2);
    }

    return [
        'decisions'      => $decisions,
        'skipped'        => $skipped,
        'rigged'         => $rigged,
        'configured_pct' => $configured,
        'observed_pct'   => to_fixed_num($observed, 2),
        // Positive drift means the house took more rounds than configured; negative means fewer.
        'drift_pct'      => to_fixed_num($observed - $configured, 2),
        'house_profit'   => $profit,
    ];
}

/**
 * Observed-versus-configured ratios, overall and broken down per concurrent instance.
 *
 * The per-instance breakdown is the part that matters for the multi-room games: an overall 50% can
 * hide one Teen Patti table being rigged almost every hand while another is never touched.
 */
function rig_report($opts = []) {
    $sinceMs = isset($opts['sinceMs']) && js_is_finite($opts['sinceMs']) ? (int)$opts['sinceMs'] : null;
    $game    = !empty($opts['game']) ? (string)$opts['game'] : null;

    $sql = 'SELECT * FROM "RigAudit" WHERE 1=1';
    $params = [];
    if ($sinceMs !== null) { $sql .= ' AND "ts" >= ?'; $params[] = now_ms() - $sinceMs; }
    if ($game !== null)    { $sql .= ' AND "game" = ?'; $params[] = $game; }
    $sql .= ' ORDER BY "id" ASC';

    try { $list = all($sql, $params); } catch (Throwable $e) { $list = []; }

    $games = [];
    foreach ($list as $e) {
        $games[$e['game']][] = $e;
    }

    $out = [];
    foreach ($games as $gameKey => $gameEntries) {
        $instances = [];
        foreach ($gameEntries as $e) {
            $key = ($e['instance'] !== null && $e['instance'] !== '') ? $e['instance'] : '(global)';
            $instances[$key][] = $e;
        }
        $perInstance = [];
        foreach ($instances as $key => $rows) $perInstance[$key] = rig_summarise($rows);
        $out[$gameKey] = array_merge(rig_summarise($gameEntries), ['per_instance' => js_object($perInstance)]);
    }

    return [
        'window_ms'        => $sinceMs,
        'total_decisions'  => count($list),
        'games'            => js_object($out),
    ];
}

/**
 * Most recent decisions, newest first.
 *
 * Takes the same `game` filter as rig_report(): without it, asking for one game's recent activity
 * returned the last N decisions across every game, so Teen Patti hands showed up in what looked
 * like an Aviator listing.
 */
function rig_recent($limit, $game = null) {
    $parsed = js_parse_int($limit);
    $n = max(1, min(500, is_nan($parsed) ? 50 : (int)$parsed));
    $sql = 'SELECT * FROM "RigAudit"' . ($game ? ' WHERE "game" = ?' : '') . ' ORDER BY "id" DESC LIMIT ' . (int)$n;
    try { $rows = all($sql, $game ? [$game] : []); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'ts'             => (int)$r['ts'],
            'game'           => $r['game'],
            'instance'       => $r['instance'],
            'round'          => $r['round'],
            'configured_pct' => (float)$r['configured_pct'],
            'rigged'         => (int)$r['rigged'] !== 0,
            'eligible'       => (int)$r['eligible'] !== 0,
            'live'           => $r['live'] === null ? null : (int)$r['live'],
            'targeted'       => $r['targeted'] === null ? null : (int)$r['targeted'],
            'house_profit'   => $r['house_profit'] === null ? null : (float)$r['house_profit'],
            'note'           => $r['note'],
        ];
    }
    return $out;
}
