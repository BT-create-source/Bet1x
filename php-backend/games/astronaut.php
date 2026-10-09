<?php
/**
 * Astronaut — one shared crash round for every player. Bet during the countdown, the astronaut
 * launches, the multiplier climbs, and the round ends the instant the astronaut is lost in space.
 * Anyone still aboard loses their stake; anyone who cashed out (by hand or by auto cash-out) is paid
 * their stake times the multiplier at that moment.
 *
 * =================================================================================================
 * HOW THE ROUND RUNS WITHOUT A GAME LOOP
 * =================================================================================================
 *
 * The same resolution games/aviator.php uses on shared hosting: the phase machine is a pure
 * function of the wall clock, so it is evaluated on demand by whatever request arrives (every open
 * page polls) and by the one-minute cron, never by a timer. A transition is stamped at the instant
 * it became due, not when a request noticed it, so timing never drifts.
 *
 *   betting  (ASTRO_BETTING_MS)  -> flying (until the crash instant) -> crashed (ASTRO_CRASHED_MS)
 *   -> betting for the next round ...
 *
 * After a quiet spell with nobody watching, the loop does not replay every empty round it missed:
 * it settles the round that was in progress, then opens a fresh countdown at "now".
 *
 * =================================================================================================
 * THE CRASH POINT
 * =================================================================================================
 *
 * Each round gets a random server seed when its countdown opens; its SHA-256 is shown during the
 * countdown and the seed itself is revealed after the crash. The crash point is
 *
 *   u     = HMAC-SHA256(server_seed, "astronaut:" . round_id), first 13 hex digits / 16^13
 *   crash = floor(100 * RTP / (1 - u)) / 100, at least 1.00, at most the operator's cap
 *
 * so P(crash >= m) = RTP / m for every two-decimal m: cashing out at ANY target returns RTP on
 * average, and a round ends at 1.00 (nobody can win) about (1 - RTP/1.01) of the time — exactly the
 * shape of the original. It is fixed before the first bet is accepted, never sent to the browser
 * while the round is live, and nothing after the countdown can move it.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

const ASTRO_STATE_KEY   = 'astronaut_runtime';
const ASTRO_BETTING_MS  = 7000;    // countdown in which bets are accepted
const ASTRO_CRASHED_MS  = 3000;    // the "lost in space" pause before the next countdown
const ASTRO_BET_CUTOFF_MS = 250;   // bets are refused in the last instant of the countdown
const ASTRO_GROWTH      = 0.075;   // multiplier = e^(0.075 t): 2x at ~9.2s, 10x at ~31s
const ASTRO_HISTORY_LEN = 40;

// -------------------------------------------------------------------------------------------------
// Operator settings
// -------------------------------------------------------------------------------------------------

function astro_config_default() {
    return [
        'enabled'        => true,
        'rtp'            => 0.97,
        'max_multiplier' => 10000.0,   // the highest the astronaut can ever fly
        'max_win'        => 1000000.0, // a single bet is auto-cashed out on reaching this payout
        'min_bet'        => (float) cfg('MIN_BET'),
        'max_bet'        => min((float) cfg('MAX_BET'), 10000.0),
    ];
}

function astro_config_get() {
    try { $stored = state_get('astronaut_config'); } catch (Throwable $e) { $stored = null; }
    $c = array_merge(astro_config_default(), is_array($stored) ? $stored : []);
    $c['enabled'] = (bool) $c['enabled'];
    foreach (['rtp', 'max_multiplier', 'max_win', 'min_bet', 'max_bet'] as $k) $c[$k] = (float) $c[$k];
    return $c;
}

function astro_config_update(array $patch) {
    $c = astro_config_get();
    if (array_key_exists('enabled', $patch)) $c['enabled'] = js_truthy($patch['enabled']);
    foreach (['rtp', 'max_multiplier', 'max_win', 'min_bet', 'max_bet'] as $k) {
        if (!array_key_exists($k, $patch)) continue;
        $v = js_parse_float($patch[$k]);
        if (!js_is_finite($v)) return ['ok' => false, 'error' => "Invalid value for $k."];
        $c[$k] = (float) $v;
    }
    if ($c['rtp'] > 1.5) $c['rtp'] = $c['rtp'] / 100;
    if ($c['rtp'] < 0.90 || $c['rtp'] > 0.99) return ['ok' => false, 'error' => 'RTP must be between 90% and 99%.'];
    if ($c['max_multiplier'] < 2 || $c['max_multiplier'] > 1000000) {
        return ['ok' => false, 'error' => 'Maximum multiplier must be between 2x and 1,000,000x.'];
    }
    if ($c['min_bet'] <= 0) return ['ok' => false, 'error' => 'Minimum bet must be positive.'];
    if ($c['max_bet'] < $c['min_bet']) return ['ok' => false, 'error' => 'Maximum bet must be at least the minimum bet.'];
    if ($c['max_win'] < $c['max_bet']) return ['ok' => false, 'error' => 'Maximum win must be at least the maximum bet.'];
    $c['rtp'] = round($c['rtp'], 4);
    state_set('astronaut_config', $c);
    return ['ok' => true, 'config' => $c];
}

// -------------------------------------------------------------------------------------------------
// Maths
// -------------------------------------------------------------------------------------------------

function astro_multiplier_at($elapsedSec) {
    return exp(ASTRO_GROWTH * max(0.0, $elapsedSec));
}

/** Milliseconds from launch until the multiplier reaches $m. */
function astro_ms_to($m) {
    if ($m <= 1.0) return 0;
    return (int) ceil(log($m) / ASTRO_GROWTH * 1000);
}

/** Displayed / paid multiplier at a moment of the flight: floored to two decimals, never above the crash. */
function astro_mult_now($state, $nowMs) {
    $m = astro_multiplier_at(($nowMs - (int) $state['phase_start']) / 1000.0);
    return min(floor($m * 100 + 1e-7) / 100, (float) $state['crash_point']);
}

function astro_crash_point($serverSeed, $roundId, $rtp, $cap) {
    $h = hash_hmac('sha256', 'astronaut:' . (int) $roundId, (string) $serverSeed);
    $u = hexdec(substr($h, 0, 13)) / pow(16, 13);
    $crash = floor(100 * $rtp / (1 - $u)) / 100;
    return max(1.00, min((float) $cap, $crash));
}

/** The instant (ms) the current flight ends. */
function astro_crash_at($state) {
    return (int) $state['phase_start'] + astro_ms_to((float) $state['crash_point']);
}

/** The multiplier a pending bet is cashed out at automatically: its own target, or the max-win cap. */
function astro_effective_target($bet, $config) {
    $amount = (float) $bet['amount'];
    $capMult = $amount > 0 ? floor(((float) $config['max_win'] / $amount) * 100) / 100 : INF;
    $auto = $bet['auto_cashout'] !== null ? (float) $bet['auto_cashout'] : INF;
    return min($auto, $capMult);
}

// -------------------------------------------------------------------------------------------------
// Round state
// -------------------------------------------------------------------------------------------------

/** Open a fresh countdown for round $roundId starting at $startMs. */
function astro_new_round(array &$state, $roundId, $startMs, $config) {
    $seed = bin2hex(random_bytes(16));
    $state['round_id']    = (int) $roundId;
    $state['phase']       = 'betting';
    $state['phase_start'] = (int) $startMs;
    $state['server_seed'] = $seed;
    $state['seed_hash']   = hash('sha256', $seed);
    $state['crash_point'] = astro_crash_point($seed, $roundId, $config['rtp'], $config['max_multiplier']);
}

function astro_load($forUpdate = false) {
    $state = $forUpdate ? state_get_for_update(ASTRO_STATE_KEY) : state_get(ASTRO_STATE_KEY);
    if (!is_array($state) || !isset($state['phase'])) {
        $state = ['history' => []];
        astro_new_round($state, 1, now_ms(), astro_config_get());
        state_set(ASTRO_STATE_KEY, $state);
        if ($forUpdate) $state = state_get_for_update(ASTRO_STATE_KEY);
    }
    if (!isset($state['history']) || !is_array($state['history'])) $state['history'] = [];
    return $state;
}

function astro_save($state) {
    state_set(ASTRO_STATE_KEY, $state);
}

/** Pay a pending bet as a win at $mult. Idempotent: only the request that flips the row pays. */
function astro_settle_win($bet, $mult, $config, $reason) {
    $payout = min(round2((float) $bet['amount'] * $mult), (float) $config['max_win']);
    $flipped = affected('UPDATE "AstronautBet" SET "status" = \'won\', "cashout_mult" = ?, "payout" = ?,
                                "settled_at" = CURRENT_TIMESTAMP(3)
                         WHERE "id" = ? AND "status" = \'pending\'', [$mult, $payout, (int) $bet['id']]) > 0;
    if (!$flipped) return null;
    $user = find_user_ci($bet['username']);
    if (!$user) throw new RuntimeException('Astronaut: bettor account missing: ' . $bet['username']);
    $balance = credit_wallet($user['id'], $payout);
    insert_transaction(new_record_id('ASTRO_WIN'), $user['username'], 'Deposit', $payout,
        'Astronaut Cash Out @ ' . js_to_fixed($mult, 2) . 'x' . ($reason !== '' ? ' (' . $reason . ')' : ''), 'Completed');
    return ['payout' => $payout, 'balance' => $balance, 'multiplier' => $mult];
}

/** Settle every pending bet whose auto cash-out (or max-win cap) has been reached by $mult. */
function astro_settle_autos($roundId, $mult, $config) {
    $rows = all('SELECT * FROM "AstronautBet" WHERE "round_id" = ? AND "status" = \'pending\'', [(int) $roundId]);
    foreach ($rows as $b) {
        $target = astro_effective_target($b, $config);
        if ($target <= $mult) {
            $isCap = $b['auto_cashout'] === null || $target < (float) $b['auto_cashout'];
            astro_settle_win($b, $target, $config, $isCap ? 'max win' : 'auto');
        }
    }
}

/** End the flight: auto cash-outs at or below the crash point win, everything else still aboard loses. */
function astro_crash(array &$state, $config) {
    astro_settle_autos($state['round_id'], (float) $state['crash_point'], $config);
    q('UPDATE "AstronautBet" SET "status" = \'lost\', "settled_at" = CURRENT_TIMESTAMP(3)
       WHERE "round_id" = ? AND "status" = \'pending\'', [(int) $state['round_id']]);

    $state['history'][] = [
        'round_id'    => (int) $state['round_id'],
        'crash'       => (float) $state['crash_point'],
        'seed_hash'   => $state['seed_hash'],
        'server_seed' => $state['server_seed'],
    ];
    if (count($state['history']) > ASTRO_HISTORY_LEN) {
        $state['history'] = array_values(array_slice($state['history'], -ASTRO_HISTORY_LEN));
    }
    $state['phase'] = 'crashed';
    $state['phase_start'] = astro_crash_at($state);
}

/** Is there anything for a tick to do right now? Cheap, lock-free, and false on most polls. */
function astro_needs_work($state, $now, $config) {
    if ($state['phase'] === 'betting') return $now >= (int) $state['phase_start'] + ASTRO_BETTING_MS;
    if ($state['phase'] === 'crashed') return $now >= (int) $state['phase_start'] + ASTRO_CRASHED_MS;
    if ($now >= astro_crash_at($state)) return true;

    // In flight: work only if some pending bet's auto target has been passed.
    $mult = astro_mult_now($state, $now);
    $rows = all('SELECT "amount", "auto_cashout" FROM "AstronautBet" WHERE "round_id" = ? AND "status" = \'pending\'',
                [(int) $state['round_id']]);
    foreach ($rows as $b) {
        if (astro_effective_target($b, $config) <= $mult) return true;
    }
    return false;
}

/**
 * Advance the round to "now". Must be called inside tx() with the state row locked: this is the one
 * place transitions happen, so two pollers can never both crash (and settle) the same round.
 */
function astro_advance_locked(array $state, $config) {
    $guard = 0;
    while ($guard++ < 10) {
        $now = now_ms();
        if ($state['phase'] === 'betting') {
            $end = (int) $state['phase_start'] + ASTRO_BETTING_MS;
            if ($now < $end) break;
            $state['phase'] = 'flying';
            $state['phase_start'] = $end;
            continue;
        }
        if ($state['phase'] === 'flying') {
            if ($now >= astro_crash_at($state)) { astro_crash($state, $config); continue; }
            astro_settle_autos($state['round_id'], astro_mult_now($state, $now), $config);
            break;
        }
        if ($state['phase'] === 'crashed') {
            $end = (int) $state['phase_start'] + ASTRO_CRASHED_MS;
            if ($now < $end) break;
            // After an idle gap, open the next countdown now rather than replaying empty rounds.
            $start = ($now - $end > ASTRO_BETTING_MS) ? $now : $end;
            astro_new_round($state, (int) $state['round_id'] + 1, $start, $config);
            continue;
        }
        break;
    }
    return $state;
}

/** Bring the round up to date if it needs it, and return the current state. */
function astro_tick() {
    $config = astro_config_get();
    $state = astro_load(false);
    if (!astro_needs_work($state, now_ms(), $config)) return $state;
    return tx(function () use ($config) {
        $state = astro_advance_locked(astro_load(true), $config);
        astro_save($state);
        return $state;
    });
}

// -------------------------------------------------------------------------------------------------
// Views
// -------------------------------------------------------------------------------------------------

/** "rahul99" -> "r***9": enough to recognise yourself, not enough to identify anyone else. */
function astro_mask_name($name) {
    $name = (string) $name;
    $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($len <= 2) return $name . '***';
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    $last  = function_exists('mb_substr') ? mb_substr($name, -1)    : substr($name, -1);
    return $first . '***' . $last;
}

function astro_bet_view($b, $phase, $mine = false) {
    $v = [
        'panel'        => (int) $b['panel'],
        'amount'       => (float) $b['amount'],
        'status'       => $b['status'],
        'cashout_mult' => $b['cashout_mult'] !== null ? (float) $b['cashout_mult'] : null,
        'payout'       => (float) $b['payout'],
    ];
    if ($mine) $v['auto_cashout'] = $b['auto_cashout'] !== null ? (float) $b['auto_cashout'] : null;
    else $v['player'] = astro_mask_name($b['username']);
    return $v;
}

/**
 * The public state every client renders. crash_point and server_seed are withheld until the round
 * has crashed; while flying the client draws the curve itself from phase_start and server_time.
 */
function astro_public_state($state, $username, $config) {
    $now = now_ms();
    $phase = $state['phase'];
    $crashed = $phase === 'crashed';

    $rows = all('SELECT * FROM "AstronautBet" WHERE "round_id" = ? AND "status" <> \'cancelled\'
                 ORDER BY "amount" DESC, "id" ASC LIMIT 200', [(int) $state['round_id']]);
    $all = []; $mine = []; $totalStake = 0.0;
    foreach ($rows as $b) {
        $totalStake += (float) $b['amount'];
        $all[] = astro_bet_view($b, $phase);
        if ($username !== null && strtolower($b['username']) === strtolower($username)) {
            $mine[] = astro_bet_view($b, $phase, true);
        }
    }
    $betCount = (int) scalar('SELECT COUNT(*) FROM "AstronautBet" WHERE "round_id" = ? AND "status" <> \'cancelled\'',
                             [(int) $state['round_id']], 0);

    $history = array_reverse(array_map(function ($h) {
        return ['round_id' => (int) $h['round_id'], 'crash' => (float) $h['crash'],
                'seed_hash' => $h['seed_hash'], 'server_seed' => $h['server_seed']];
    }, $state['history']));

    $out = [
        'round_id'    => (int) $state['round_id'],
        'phase'       => $phase,
        'phase_start' => (int) $state['phase_start'],
        'server_time' => $now,
        'betting_ms'  => ASTRO_BETTING_MS,
        'crashed_ms'  => ASTRO_CRASHED_MS,
        'growth'      => ASTRO_GROWTH,
        'seed_hash'   => $state['seed_hash'],
        'multiplier'  => $phase === 'flying' ? astro_mult_now($state, $now) : ($crashed ? (float) $state['crash_point'] : 1.0),
        'crash_point' => $crashed ? (float) $state['crash_point'] : null,
        'server_seed' => $crashed ? $state['server_seed'] : null,
        'bets'        => $all,
        'bet_count'   => $betCount,
        'total_stake' => round2($totalStake),
        'my_bets'     => $mine,
        'history'     => $history,
    ];
    return $out;
}

function astro_public_config($config) {
    return [
        'enabled'        => $config['enabled'],
        'rtp'            => $config['rtp'],
        'max_multiplier' => $config['max_multiplier'],
        'max_win'        => $config['max_win'],
        'min_bet'        => $config['min_bet'],
        'max_bet'        => $config['max_bet'],
    ];
}
