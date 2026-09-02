<?php
/**
 * The house-edge engine: takeover configuration, the bucketed 100-slot rig bag, and the
 * percentage-based live targeting for players and tables.
 *
 * This is the most behaviour-sensitive file in the port, so the reasoning from server.js is
 * carried over in full rather than summarised — the exact shape of these algorithms is the thing
 * that had to be got right three times before it behaved as configured.
 *
 * WHAT CHANGED IN THE PORT: nothing about the algorithms. Only where their state lives.
 *   - The rig bags were already persisted to GameState (`bot_rig_bag_<ledger>`), debounced by two
 *     seconds; here they are written synchronously, because there is no process to hold the
 *     authoritative in-memory copy between requests. Strictly safer, identical distribution.
 *   - LIVE_USERS / LIVE_INSTANCES become the LivePresence table, with the same 45-second TTL
 *     applied at read time.
 *   - The 4-second setInterval that re-sampled the targeted subset becomes a staleness check on
 *     read: if the stored subset is older than 4 seconds it is re-sampled now. The selection is
 *     only ever consulted during a request, so the observable result is identical.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const BOT_RIG_BUCKETS      = 10;
const BOT_RIG_BUCKET_SIZE  = 10;   // BOT_RIG_BUCKETS * BOT_RIG_BUCKET_SIZE must stay 100
const LIVE_USER_TTL_MS     = 45000;
const LIVE_INSTANCE_TTL_MS = 45000;
const BOT_TARGET_REFRESH_MS = 4000;

/** Games whose live PLAYERS are tracked (the original LIVE_USERS map). */
function live_user_games() { return ['color_guess', 'aviator', 'teenpatti', 'mines']; }
/** Games whose live TABLES are tracked (the original LIVE_INSTANCES map). */
function live_instance_games() { return ['teenpatti']; }

function color_rooms()  { return ['sapre', 'becone', 'emred', 'vip']; }
function tp_room_ids()  { return ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106']; }

// -------------------------------------------------------------------------------------------------
// Takeover configuration
// -------------------------------------------------------------------------------------------------

/**
 * The full bot-takeover config, defaults merged with whatever is stored.
 *
 * Your 11's percentage counts CONTESTS. There is deliberately no `boundary` key: Boundary Baazi
 * resolves from the ball event log and nothing else, and an absent key can never be active — see
 * bot_takeover_active(). The `youreleven` key is retained even though the cricket games are not
 * part of this build, so the stored operator setting survives until they are.
 */
function bot_takeover_state() {
    if (isset($GLOBALS['BET1X_BOT_STATE']) && is_array($GLOBALS['BET1X_BOT_STATE'])) {
        return $GLOBALS['BET1X_BOT_STATE'];
    }
    $GLOBALS['BET1X_BOT_STATE'] = bot_takeover_state_fresh();
    return $GLOBALS['BET1X_BOT_STATE'];
}

/** Write one game's config, keeping the request cache in step so later reads in this request see it. */
function bot_takeover_set($gameKey, $conf) {
    state_set('bot_takeover_' . $gameKey, $conf);
    $state = bot_takeover_state();
    $state[$gameKey] = $conf;
    $GLOBALS['BET1X_BOT_STATE'] = $state;
}

/**
 * Is the takeover active for this game, and at what percentage?
 *
 * An unregistered key is never active, not even under the global master switch. That is what stops
 * a URL-supplied :gameKey typo drawing real decisions out of a bag created on the spot for it, and
 * it is the guarantee that a game with no key here (Boundary Baazi) has no rig path at all.
 */
function bot_takeover_active($gameKey) {
    $state = bot_takeover_state();
    $conf = $state[$gameKey] ?? null;

    if (!$conf) {
        return ['active' => false, 'profit_pct' => 0, 'source' => 'none'];
    }
    if (!empty($conf['enabled'])) {
        return ['active' => true, 'profit_pct' => js_truthy($conf['profit_pct'] ?? null) ? $conf['profit_pct'] : 90, 'source' => 'game'];
    }
    if (isset($conf['enabled']) && $conf['enabled'] === false) {
        // Explicitly turned off by the operator — respect that, do not fall through to global.
        return ['active' => false, 'profit_pct' => js_truthy($conf['profit_pct'] ?? null) ? $conf['profit_pct'] : 90, 'source' => 'none'];
    }
    if (!empty($state['global']['enabled'])) {
        $pct = js_truthy($conf['profit_pct'] ?? null) ? $conf['profit_pct']
             : (js_truthy($state['global']['profit_pct'] ?? null) ? $state['global']['profit_pct'] : 90);
        return ['active' => true, 'profit_pct' => $pct, 'source' => 'global'];
    }
    return ['active' => false, 'profit_pct' => js_truthy($conf['profit_pct'] ?? null) ? $conf['profit_pct'] : 90, 'source' => 'none'];
}

/** Re-read the config from the store, bypassing the request cache. */
function bot_takeover_state_fresh() {
    $keys = ['global', 'color_guess', 'aviator', 'teenpatti', 'mines', 'youreleven'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = ['enabled' => false, 'profit_pct' => 90];
        try {
            $stored = state_get('bot_takeover_' . $k);
            if (is_array($stored)) $out[$k] = array_merge($out[$k], $stored);
        } catch (Throwable $e) {}
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// The bucketed rig bag
// -------------------------------------------------------------------------------------------------

/**
 * Build a fresh 100-slot cycle for a percentage.
 *
 * v1 of this was a running counter — `(counter % 100) < pct` — which handed out the first `pct`
 * calls of every hundred all true in an unbroken row. v2 shuffled a flat 100-slot bag, which is
 * exact per cycle but can still cluster locally: 8 of the first 10 slots in a random permutation of
 * 50/50 land true about 4.6% of the time, which is what produced the "10 games, 8 won by admin, at
 * 50%" report.
 *
 * v3 (this one) keeps every 100-slot cycle exact for ANY integer percentage while also keeping
 * every 10-round window close to the ratio. The 100 slots are split into 10 buckets of 10; bucket i
 * gets floor((i+1)*pct/10) - floor(i*pct/10) true slots — the standard "spread K items across N
 * buckets as evenly as possible" formula, so every bucket is within one of every other and the ten
 * counts always sum to exactly `pct`. Slots are shuffled WITHIN each bucket for per-round
 * unpredictability, then the ORDER the buckets are drawn in is shuffled too. Measured at 50%: the
 * chance of an 8-or-worse 10-round window drops from ~4.6% to ~0.5%.
 */
function build_bot_rig_bag($pct) {
    $buckets = [];
    for ($i = 0; $i < BOT_RIG_BUCKETS; $i++) {
        $trueCount = (int) (floor(($i + 1) * $pct / BOT_RIG_BUCKETS) - floor($i * $pct / BOT_RIG_BUCKETS));
        $slots = [];
        for ($j = 0; $j < BOT_RIG_BUCKET_SIZE; $j++) $slots[] = ($j < $trueCount);
        $buckets[] = js_shuffle($slots);
    }
    $queue = [];
    foreach (js_shuffle($buckets) as $bucket) {
        foreach ($bucket as $slot) $queue[] = $slot;
    }
    return [
        'pct'            => $pct,
        'queue'          => $queue,
        'totalDecisions' => 0,
        'totalRigged'    => 0,
        'lastDecisionAt' => null,
        'lastRiggedAt'   => null,
    ];
}

function bot_rig_bag_load($ledgerKey) {
    if (isset($GLOBALS['BET1X_RIG_BAGS'][$ledgerKey])) return $GLOBALS['BET1X_RIG_BAGS'][$ledgerKey];
    try {
        $stored = state_get('bot_rig_bag_' . $ledgerKey);
    } catch (Throwable $e) {
        $stored = null;
    }
    $bag = (is_array($stored) && isset($stored['queue']) && is_array($stored['queue'])) ? $stored : null;
    $GLOBALS['BET1X_RIG_BAGS'][$ledgerKey] = $bag;
    return $bag;
}

function bot_rig_bag_save($ledgerKey, $bag) {
    $GLOBALS['BET1X_RIG_BAGS'][$ledgerKey] = $bag;
    try { state_set('bot_rig_bag_' . $ledgerKey, $bag); } catch (Throwable $e) { /* best effort */ }
}

/**
 * Build (or reuse) the bag for a ledger at a given percentage WITHOUT drawing from it.
 *
 * Shared by the real decision, which then draws, and by the status-peek endpoint, which only reads
 * the next slot — so both agree on exactly the same cycle. A changed percentage starts a fresh
 * cycle rather than finishing out the old one at the old ratio.
 */
function ensure_bot_rig_bag($ledgerKey, $pct) {
    $bag = bot_rig_bag_load($ledgerKey);
    if (!$bag || (float)$bag['pct'] !== (float)$pct || count($bag['queue']) === 0) {
        // An exhausted cycle at the SAME pct keeps its running totals; a changed pct starts clean.
        $carryOver = ($bag && (float)$bag['pct'] === (float)$pct) ? $bag : null;
        $fresh = build_bot_rig_bag($pct);
        if ($carryOver) {
            $fresh['totalDecisions'] = $carryOver['totalDecisions'];
            $fresh['totalRigged']    = $carryOver['totalRigged'];
            $fresh['lastDecisionAt'] = $carryOver['lastDecisionAt'];
            $fresh['lastRiggedAt']   = $carryOver['lastRiggedAt'];
        }
        $bag = $fresh;
        // Persist immediately, even though nothing has been drawn yet.
        //
        // This matters for /api/bot_status, which PEEKS the next slot without drawing it. In Node
        // the freshly built bag stayed in process memory, so the value the operator was shown was
        // genuinely the one the next round would draw. Without writing it here, the peek would
        // build a bag, discard it at end of request, and the next real draw would build a
        // different one — making the readout a lie.
        bot_rig_bag_save($ledgerKey, $bag);
    }
    return $bag;
}

/**
 * Call once per round/match/session for a game. Draws one slot.
 *
 * `ledgerKey` splits the 100-slot cycle into independent sub-ledgers while keeping a single shared
 * on/off/percentage config. Colour Prediction needs this: its four rooms run on 30s/60s/180s/300s
 * clocks, so one shared cycle let the fast room burn through most of the rigged slots before the
 * slow room had settled a handful of rounds. One ledger per room makes every room exact on its own.
 */
function should_bot_rig_this_round($gameKey, $ledgerKey = null) {
    $bot = bot_takeover_active($gameKey);
    if (!$bot['active']) {
        return ['shouldRig' => false, 'profit_pct' => $bot['profit_pct'], 'active' => false, 'source' => 'none'];
    }
    $pct = js_truthy($bot['profit_pct']) ? $bot['profit_pct'] : 90;
    $key = $ledgerKey !== null ? $ledgerKey : $gameKey;

    $bag = ensure_bot_rig_bag($key, $pct);
    $shouldRig = (bool) array_pop($bag['queue']);   // Array.prototype.pop — from the END

    // The bag itself is the memory: totals for diagnostics, and lastRiggedAt records exactly when
    // the house last entered a room / changed an outcome, which /api/bot_status surfaces.
    $bag['totalDecisions'] = (int)$bag['totalDecisions'] + 1;
    $bag['lastDecisionAt'] = now_ms();
    if ($shouldRig) {
        $bag['totalRigged']  = (int)$bag['totalRigged'] + 1;
        $bag['lastRiggedAt'] = now_ms();
    }
    bot_rig_bag_save($key, $bag);   // must match the bag that was actually drawn from

    return ['shouldRig' => $shouldRig, 'profit_pct' => $pct, 'active' => true, 'source' => $bot['source']];
}

// -------------------------------------------------------------------------------------------------
// Live presence
// -------------------------------------------------------------------------------------------------

function live_presence_touch($game, $kind, $subject) {
    try {
        q('INSERT INTO "LivePresence" ("game","kind","subject","last_seen") VALUES (?,?,?,?)
           ON CONFLICT ("game","kind","subject") DO UPDATE SET "last_seen" = EXCLUDED."last_seen"',
          [$game, $kind, (string)$subject, now_ms()]);
    } catch (Throwable $e) { /* presence is diagnostic; never break a request over it */ }
}

function live_presence_list($game, $kind, $ttlMs) {
    try {
        $rows = all('SELECT "subject" FROM "LivePresence" WHERE "game" = ? AND "kind" = ? AND "last_seen" >= ?',
                    [$game, $kind, now_ms() - $ttlMs]);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[] = $r['subject'];
    return $out;
}

/**
 * Mark a player as currently live in a game.
 *
 * A player who has just arrived must become eligible for selection immediately, not whenever the
 * refresh next happens to run. Load testing made the cost of waiting obvious: 25 players started
 * Mines boards inside 431 ms, targeting had not been re-sampled since they became live, so the
 * targeted subset was still empty and NONE of them were rigged — a bot configured at 90% delivered
 * 0%. Any session shorter than one refresh interval was previously never rigged at all.
 *
 * Only on genuine arrival, not on every heartbeat: this is called from polling endpoints several
 * times a second per player, and re-sampling that often would be pure waste.
 */
function mark_user_active($gameKey, $username) {
    if (!is_string($username) || $username === '') return; // anonymous viewers are not live players
    if (!in_array($gameKey, live_user_games(), true)) return;

    $live = live_presence_list($gameKey, 'user', LIVE_USER_TTL_MS);
    $wasLive = false;
    foreach ($live as $u) { if ($u === (string)$username) { $wasLive = true; break; } }

    live_presence_touch($gameKey, 'user', $username);
    if (!$wasLive) refresh_bot_targeting($gameKey, true);
}

function get_live_usernames($gameKey) {
    if (!in_array($gameKey, live_user_games(), true)) return [];
    return live_presence_list($gameKey, 'user', LIVE_USER_TTL_MS);
}

/**
 * Mark a TABLE as live. A table only counts once a real person is sitting at it: rigging a table
 * occupied purely by NPCs moves no money, and counting those tables in the denominator would
 * silently dilute the percentage the operator asked for.
 */
function mark_instance_active($gameKey, $instanceId) {
    if (!$instanceId || !in_array($gameKey, live_instance_games(), true)) return;
    live_presence_touch($gameKey, 'instance', $instanceId);
}

function get_live_instances($gameKey) {
    if (!in_array($gameKey, live_instance_games(), true)) return [];
    return live_presence_list($gameKey, 'instance', LIVE_INSTANCE_TTL_MS);
}

/** Drop presence rows that can no longer be live under any TTL. Called by the cron. */
function live_presence_sweep() {
    try { q('DELETE FROM "LivePresence" WHERE "last_seen" < ?', [now_ms() - (LIVE_USER_TTL_MS * 4)]); }
    catch (Throwable $e) {}
}

// -------------------------------------------------------------------------------------------------
// Percentage-based targeting of live players
// -------------------------------------------------------------------------------------------------

function bot_targeted_load($gameKey) {
    if (isset($GLOBALS['BET1X_TARGETED'][$gameKey])) return $GLOBALS['BET1X_TARGETED'][$gameKey];
    try { $stored = state_get('bot_targeted_' . $gameKey); } catch (Throwable $e) { $stored = null; }
    $rec = (is_array($stored) && isset($stored['list']) && is_array($stored['list']))
        ? $stored : ['list' => [], 'at' => 0];
    $GLOBALS['BET1X_TARGETED'][$gameKey] = $rec;
    return $rec;
}

function bot_targeted_save($gameKey, $list) {
    $rec = ['list' => array_values($list), 'at' => now_ms()];
    $GLOBALS['BET1X_TARGETED'][$gameKey] = $rec;
    try { state_set('bot_targeted_' . $gameKey, $rec); } catch (Throwable $e) {}
    return $rec;
}

/**
 * Re-sample the targeted subset: X% of the players currently live in this game.
 *
 * Keep whoever is still live and still selected, then top up from the rest at random. Re-drawing
 * the whole subset from scratch every pass meant a player could be targeted for one Mines reveal
 * and untargeted for the next within a single board. The proportion is identical either way; this
 * just stops it thrashing.
 *
 * Note this stickiness is safe for PLAYERS but was NOT for TABLES: a per-player subset is
 * re-sampled as players come and go, whereas a small set of long-lived tables would have pinned the
 * same tables for ever. Teen Patti therefore uses a per-table ledger instead of this engine.
 */
function refresh_bot_targeting($gameKey, $force = false) {
    if (!in_array($gameKey, live_user_games(), true)) return [];

    $rec = bot_targeted_load($gameKey);
    if (!$force && (now_ms() - (int)$rec['at']) < BOT_TARGET_REFRESH_MS) {
        return $rec['list'];
    }

    $bot = bot_takeover_active($gameKey);
    if (!$bot['active']) { bot_targeted_save($gameKey, []); return []; }

    $live = get_live_usernames($gameKey);
    if (count($live) === 0) { bot_targeted_save($gameKey, []); return []; }

    $pct = js_truthy($bot['profit_pct']) ? (float)$bot['profit_pct'] : 90.0;
    $count = $pct >= 100
        ? count($live)
        : (int) max(1, min(count($live), round(($pct / 100) * count($live))));

    $previous = array_values(array_filter($rec['list'], function ($u) use ($live) { return in_array($u, $live, true); }));
    $keep = array_slice($previous, 0, $count);
    $remaining = js_shuffle(array_values(array_filter($live, function ($u) use ($keep) { return !in_array($u, $keep, true); })));
    $topUp = array_slice($remaining, 0, max(0, $count - count($keep)));

    $list = array_merge($keep, $topUp);
    bot_targeted_save($gameKey, $list);
    return $list;
}

/** The current targeted subset, refreshed if stale. */
function bot_targeted_users($gameKey) {
    return refresh_bot_targeting($gameKey, false);
}

/** All four games' targeted subsets, in the shape the admin responses expect. */
function bot_targeted_users_all() {
    $out = [];
    foreach (live_user_games() as $g) $out[$g] = bot_targeted_users($g);
    return $out;
}

function is_user_targeted($gameKey, $username) {
    if (!$username) return false;
    $list = bot_targeted_users($gameKey);
    $lower = strtolower((string)$username);
    foreach ($list as $u) if (strtolower((string)$u) === $lower) return true;
    return false;
}
