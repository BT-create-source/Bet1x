/**
 * Boundary Baazi — ball-by-ball prediction.
 *
 * Two things in this file carry the whole game, and both are about integrity rather than features.
 *
 * 1. THE LOCK IS EVENT-GATED, NEVER TIME-GATED.
 *
 *    A market closes when the feed says the next ball is starting, not a fixed interval after the
 *    previous one. This is not a latency optimisation. A user watching a broadcast is seconds to a
 *    minute behind the push feed, so under a time-based lock someone on a delayed stream could see
 *    the ball land and still get a bet in — and no amount of paying for a faster data plan fixes
 *    that, because the gap is in the broadcast, not the feed.
 *
 *    The consequence is handled honestly rather than hidden: if a delivery resolves and its market
 *    was never locked (no ball-start event arrived for it), the round VOIDS and every stake is
 *    refunded. Settling it would mean paying out bets that might have been placed after the ball was
 *    already bowled. That behaviour is config (`on_missing_lock`), because an operator who knows
 *    their feed always sends ball-start may reasonably choose otherwise — but the default protects
 *    the player.
 *
 * 2. THE MARKET IS PARIMUTUEL, WHICH IS WHY THIS GAME CANNOT BE RIGGED.
 *
 *    Every stake on a delivery goes into one pool; the house takes `rake_pct`; whoever backed the
 *    actual outcome splits the remainder in proportion to their stake. The house holds no position
 *    on any outcome, so there is no ball it would prefer. There is deliberately no rig endpoint, no
 *    admin override, and no `boundary` key in `botTakeoverState` — and test_rigging.js asserts all
 *    of that positively, so it cannot be reintroduced by accident.
 *
 * Settlement reads the permanent event log and is idempotent at two levels — the round's
 * `resolved_at` and each bet's `paid_at` — exactly as Your 11's contest settlement is.
 */

const context = require('./context');
const configStore = require('./config-store');
const normalize = require('./normalize');
const liveState = require('./live-state');
const fanout = require('./fanout');

const TX_STAKE = 'Boundary Baazi Bet';
const TX_PAYOUT = 'Boundary Baazi Payout';
const TX_REFUND = 'Boundary Baazi Refund';

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

/** Round DOWN to the paisa: a proportional split must never pay out more than the pool holds. */
function floor2(n) {
  return Math.floor((Number(n) || 0) * 100) / 100;
}

function walletOf(passed) {
  const wallet = passed || context.get().wallet;
  if (!wallet) throw new Error('cricket: no wallet adapter — pass cricket.init({ wallet }) at boot');
  return wallet;
}

// ------------------------------------------------------------------------------------------------
// outcome classification
// ------------------------------------------------------------------------------------------------

/**
 * Which option a delivery actually resolved to.
 *
 * Pure, and driven by the same normaliser every other consumer of the log uses, so a market settles
 * on exactly the reading of a ball that the scorecard and the fantasy engine already agree on.
 *
 * The order below is the documented precedence and is exhaustive — every delivery, legal or not,
 * lands on exactly one option, so there is no ball this market cannot settle:
 *
 *   wicket  a dismissal credited to this delivery. Retired hurt is NOT a wicket: the batter is not
 *           out, and treating it as one would settle a market on something that did not happen.
 *   extra   a wide or a no-ball. It takes precedence over runs because the illegality of the
 *           delivery is the headline event; a no-ball hit for six settles as an extra.
 *   six/four off the bat. Byes and leg-byes are not the batter's runs and never read as a boundary.
 *   dot     nothing at all came of it — no runs of any kind, no wicket.
 *   runs    everything else: 1, 2, 3, or runs that reached the total without the bat.
 */
function classifyOutcome(detail) {
  if (!detail) return null;

  if (detail.is_wicket && detail.wicket_type !== 'retired_hurt') return 'wicket';
  if (detail.extra_type === 'wide' || detail.extra_type === 'noball') return 'extra';

  const off = normalize.batsmanRuns(detail);
  if (off === 6) return 'six';
  if (off === 4) return 'four';
  if (normalize.totalRuns(detail) === 0) return 'dot';
  return 'runs';
}

/** The market question for a delivery, from the live state. */
function questionFor(state, deliveryKey) {
  const [, over, ball] = String(deliveryKey || '').split(':');
  return `Over ${Number(over) + 1}.${ball} — what happens off this ball?`;
}

// ------------------------------------------------------------------------------------------------
// pool arithmetic
// ------------------------------------------------------------------------------------------------

/**
 * Split a resolved pool across the winning stakes.
 *
 * Pure, so the payout algorithm is testable with no database and no server. Three cases, each of
 * which is a real outcome rather than an edge case to wave at:
 *
 *   - Nobody backed the winner. There is no one to pay; the pool is the house's. Reported, not
 *     silently absorbed.
 *   - Everybody backed the winner. Each stake comes back less the rake. That is ordinary parimutuel
 *     behaviour, not a bug, and it is the only case where a "winning" bet returns less than it cost.
 *   - The usual case. Winners split the post-rake pool in proportion to stake, each amount rounded
 *     DOWN to the paisa, with the undividable remainder going to the largest stake (ties broken by
 *     the earliest bet), so total paid is exactly the distributable pool and never a paisa more.
 */
function allocatePayouts(bets, outcome, rakePct) {
  const all = bets || [];
  const pool = round2(all.reduce((sum, b) => sum + (Number(b.stake) || 0), 0));
  const rake = round2(pool * ((Number(rakePct) || 0) / 100));
  const distributable = round2(pool - rake);

  const winners = all.filter(b => b.option_key === outcome);
  const winningStake = round2(winners.reduce((sum, b) => sum + (Number(b.stake) || 0), 0));

  if (!winners.length || winningStake <= 0) {
    return {
      pool, rake, distributable, winning_stake: 0, paid: 0, house_keeps: distributable,
      payouts: all.map(b => ({ ...b, won: false, payout: 0 }))
    };
  }

  const ordered = [...winners].sort((a, b) =>
    (Number(b.stake) || 0) - (Number(a.stake) || 0) ||
    new Date(a.created_at || 0) - new Date(b.created_at || 0));

  const shares = ordered.map(b => floor2(distributable * ((Number(b.stake) || 0) / winningStake)));
  const remainder = round2(distributable - shares.reduce((s, v) => s + v, 0));
  if (shares.length) shares[0] = round2(shares[0] + remainder);

  const byId = new Map();
  ordered.forEach((b, i) => byId.set(b.id, shares[i]));

  return {
    pool,
    rake,
    distributable,
    winning_stake: winningStake,
    paid: round2(shares.reduce((s, v) => s + v, 0)),
    house_keeps: 0,
    payouts: all.map(b => ({
      ...b,
      won: b.option_key === outcome,
      payout: byId.has(b.id) ? byId.get(b.id) : 0
    }))
  };
}

// ------------------------------------------------------------------------------------------------
// round lifecycle
// ------------------------------------------------------------------------------------------------

/**
 * Open the market on the delivery that is coming next, if it is not open already.
 *
 * Idempotent by unique constraint: a redelivered feed message finds the existing round rather than
 * opening a second market on the same ball, and the P2002 that proves it is a normal outcome here,
 * not an error.
 */
async function openNextRound(fixtureKey, state) {
  const { prisma, logger } = context.get();

  const deliveryKey = liveState.nextDeliveryKey(state);
  if (!deliveryKey) return { ok: true, reason: 'no_next_delivery' };
  if (state.match_ended || state.abandoned) return { ok: true, reason: 'match_over' };

  const existing = await prisma.boundaryRound.findUnique({
    where: { fixture_key_delivery_key: { fixture_key: fixtureKey, delivery_key: deliveryKey } }
  });
  if (existing) return { ok: true, round: existing, reason: 'already_open' };

  const cfg = await configStore.boundary();
  const [innings, over, ball] = deliveryKey.split(':').map(Number);

  try {
    const round = await prisma.boundaryRound.create({
      data: {
        fixture_key: fixtureKey,
        delivery_key: deliveryKey,
        innings, over, ball,
        question: questionFor(state, deliveryKey),
        options: cfg.options,
        rake_pct: cfg.rake_pct
      }
    });
    fanout.publish(fixtureKey, 'boundary_open', { round });
    return { ok: true, round, reason: 'opened' };
  } catch (e) {
    if (e.code === 'P2002') {
      const round = await prisma.boundaryRound.findUnique({
        where: { fixture_key_delivery_key: { fixture_key: fixtureKey, delivery_key: deliveryKey } }
      });
      return { ok: true, round, reason: 'already_open' };
    }
    logger.error('cricket: could not open a boundary round', { fixture_key: fixtureKey, deliveryKey, message: e.message });
    return { ok: false, error: e.message };
  }
}

/**
 * Close the market. Driven by the feed's ball-start event and nothing else.
 *
 * The conditional updateMany is what makes the cutoff exact: a bet arriving in the same instant
 * either lands before the status flips (and is honoured) or finds a locked round (and is refused).
 * There is no window in which both can be true.
 */
async function lockRound(fixtureKey, state) {
  const { prisma, logger } = context.get();

  const deliveryKey = liveState.nextDeliveryKey(state);
  if (!deliveryKey) return { ok: true, reason: 'no_next_delivery' };

  const claim = await prisma.boundaryRound.updateMany({
    where: { fixture_key: fixtureKey, delivery_key: deliveryKey, status: 'open' },
    data: { status: 'locked', locked_at: new Date() }
  });

  if (claim.count > 0) {
    logger.info('cricket: boundary market locked on the feed event', { fixture_key: fixtureKey, deliveryKey });
    fanout.publish(fixtureKey, 'boundary_locked', { delivery_key: deliveryKey });
  }
  return { ok: true, locked: claim.count, delivery_key: deliveryKey };
}

/**
 * Place a stake.
 *
 * Same money discipline as everywhere else in this pipeline: validate for free first, then debit,
 * then write the ledger row, reversing the debit if the ledger or the bet write fails. Validation
 * after a debit is how a rejected bet keeps a player's money.
 */
async function placeBet(fixtureKey, deliveryKey, username, optionKey, stake, { wallet: passedWallet } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);
  const cfg = await configStore.boundary();

  const amount = round2(stake);
  if (!Number.isFinite(amount) || amount <= 0) {
    return { ok: false, status: 400, error: 'Invalid stake.' };
  }
  if (amount < cfg.min_stake) return { ok: false, status: 400, error: `Minimum stake is ₹${cfg.min_stake}.` };
  if (amount > cfg.max_stake) return { ok: false, status: 400, error: `Maximum stake is ₹${cfg.max_stake}.` };

  const round = await prisma.boundaryRound.findUnique({
    where: { fixture_key_delivery_key: { fixture_key: String(fixtureKey), delivery_key: String(deliveryKey) } }
  });
  if (!round) return { ok: false, status: 404, error: 'No market is open on that ball.' };
  if (round.status !== 'open') {
    return { ok: false, status: 400, error: 'This ball is already under way — betting is closed.' };
  }

  const options = Array.isArray(round.options) ? round.options : [];
  if (!options.some(o => o.key === optionKey)) {
    return { ok: false, status: 400, error: 'That is not one of the options on this ball.' };
  }

  const user = await prisma.user.findUnique({ where: { username } });
  if (!user) return { ok: false, status: 404, error: 'User not found.' };

  const balanceAfter = await wallet.debit(user.id, amount);
  if (balanceAfter === null) {
    return { ok: false, status: 400, error: `Insufficient balance! You have ₹${Number(user.wallet_balance).toFixed(2)}.` };
  }

  const reverse = async reason => {
    try {
      await wallet.credit(user.id, amount);
    } catch (e) {
      logger.error('cricket: FAILED TO REVERSE A BOUNDARY STAKE - money is missing', {
        username, round_id: round.id, amount, reason, message: e.message
      });
    }
  };

  try {
    await prisma.transaction.create({
      data: {
        id: wallet.newRecordId('BB'),
        user: username,
        type: 'Withdrawal',
        amount,
        details: TX_STAKE,
        status: 'Completed'
      }
    });
  } catch (e) {
    await reverse('ledger_write_failed');
    return { ok: false, status: 500, error: 'Could not record the bet. Nothing was charged.' };
  }

  try {
    // Re-checked inside the write path. The status could have flipped to `locked` while the wallet
    // was being debited, and a stake accepted after the ball started is the one thing this game
    // cannot allow.
    const stillOpen = await prisma.boundaryRound.findUnique({ where: { id: round.id } });
    if (!stillOpen || stillOpen.status !== 'open') {
      await reverse('locked_during_debit');
      await prisma.transaction.create({
        data: {
          id: wallet.newRecordId('BBR'),
          user: username,
          type: 'Deposit',
          amount,
          details: TX_REFUND,
          status: 'Completed'
        }
      }).catch(() => {});
      return { ok: false, status: 400, error: 'The ball started before your bet landed — you have not been charged.' };
    }

    const bet = await prisma.boundaryBet.create({
      data: { round_id: round.id, username, option_key: String(optionKey), stake: amount }
    });
    return { ok: true, bet, balance: balanceAfter };
  } catch (e) {
    await reverse('bet_write_failed');
    logger.error('cricket: boundary bet write failed, stake reversed', {
      username, round_id: round.id, message: e.message
    });
    return { ok: false, status: 500, error: 'Could not place the bet. Nothing was charged.' };
  }
}

/**
 * Settle the market on a delivery that has now been bowled.
 *
 * Idempotent: `resolved_at` is claimed with a conditional updateMany, so a redelivered ball event
 * settles nothing a second time. Each bet then carries its own `paid_at` claim, so a crash partway
 * through a payout resumes without paying the same bet twice.
 */
async function resolveRound(fixtureKey, deliveryKey, detail, { wallet: passedWallet } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);

  const round = await prisma.boundaryRound.findUnique({
    where: { fixture_key_delivery_key: { fixture_key: String(fixtureKey), delivery_key: String(deliveryKey) } }
  });
  if (!round) return { ok: true, reason: 'no_market' };
  if (round.resolved_at || round.status === 'resolved') return { ok: true, already_resolved: true };
  if (round.status === 'voided') return { ok: true, voided: true };

  // The market was never locked, so there is no moment at which betting provably closed before the
  // ball. Refund rather than settle: paying out here would mean honouring bets that may have been
  // placed after the outcome was already known to someone on a delayed stream.
  const cfg = await configStore.boundary();
  if (round.status === 'open' && cfg.on_missing_lock !== 'settle') {
    logger.warn('cricket: boundary market resolved without ever being locked - voiding and refunding', {
      fixture_key: fixtureKey, delivery_key: deliveryKey
    });
    return voidRound(round.id, 'never_locked', { wallet });
  }

  const outcome = classifyOutcome(detail);
  if (!outcome) return { ok: false, error: 'Could not classify the delivery.' };

  const bets = await prisma.boundaryBet.findMany({ where: { round_id: round.id } });
  const split = allocatePayouts(bets, outcome, round.rake_pct);

  const claim = await prisma.boundaryRound.updateMany({
    where: { id: round.id, resolved_at: null },
    data: {
      status: 'resolved',
      outcome,
      resolved_at: new Date(),
      pool: split.pool,
      rake: split.rake,
      paid: split.paid
    }
  });
  if (claim.count === 0) return { ok: true, already_resolved: true };

  let paidCount = 0;
  for (const bet of split.payouts) {
    await prisma.boundaryBet.updateMany({
      where: { id: bet.id },
      data: { won: bet.won, payout: bet.payout }
    });
    if (bet.payout <= 0) continue;

    const betClaim = await prisma.boundaryBet.updateMany({
      where: { id: bet.id, paid_at: null },
      data: { paid_at: new Date() }
    });
    if (betClaim.count === 0) continue;

    try {
      const user = await prisma.user.findUnique({ where: { username: bet.username } });
      if (!user) throw new Error(`no such user: ${bet.username}`);

      await wallet.credit(user.id, bet.payout);
      try {
        await prisma.transaction.create({
          data: {
            id: wallet.newRecordId('BBW'),
            user: bet.username,
            type: 'Deposit',
            amount: bet.payout,
            details: TX_PAYOUT,
            status: 'Completed'
          }
        });
      } catch (ledgerErr) {
        try {
          await wallet.debit(user.id, bet.payout);
        } catch (reverseErr) {
          logger.error('cricket: BOUNDARY PAYOUT REVERSAL FAILED - unledgered money is in a wallet', {
            round_id: round.id, bet_id: bet.id, username: bet.username, payout: bet.payout,
            message: reverseErr.message
          });
        }
        throw ledgerErr;
      }
      paidCount += 1;
    } catch (e) {
      await prisma.boundaryBet.updateMany({ where: { id: bet.id }, data: { paid_at: null } });
      logger.error('cricket: boundary payout failed, claim released for retry', {
        round_id: round.id, bet_id: bet.id, username: bet.username, message: e.message
      });
    }
  }

  fanout.publish(fixtureKey, 'boundary_resolved', {
    delivery_key: deliveryKey, outcome, pool: split.pool, paid: split.paid
  });

  logger.info('cricket: boundary market resolved', {
    fixture_key: fixtureKey, delivery_key: deliveryKey, outcome,
    pool: split.pool, rake: split.rake, paid: split.paid, winners: paidCount
  });

  return {
    ok: true,
    outcome,
    pool: split.pool,
    rake: split.rake,
    paid: split.paid,
    house_keeps: split.house_keeps,
    winners: paidCount
  };
}

/** Void a market and refund every stake in full, rake included. Per-bet claim makes a re-run safe. */
async function voidRound(roundId, reason, { wallet: passedWallet } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);

  await prisma.boundaryRound.updateMany({
    where: { id: String(roundId), status: { in: ['open', 'locked'] } },
    data: { status: 'voided', voided_at: new Date(), void_reason: String(reason || 'operator_void') }
  });

  const bets = await prisma.boundaryBet.findMany({ where: { round_id: String(roundId), paid_at: null } });

  let refunded = 0;
  for (const bet of bets) {
    const claim = await prisma.boundaryBet.updateMany({
      where: { id: bet.id, paid_at: null },
      data: { paid_at: new Date(), payout: bet.stake }
    });
    if (claim.count === 0) continue;

    try {
      const user = await prisma.user.findUnique({ where: { username: bet.username } });
      if (!user) throw new Error(`no such user: ${bet.username}`);
      await wallet.credit(user.id, bet.stake);
      await prisma.transaction.create({
        data: {
          id: wallet.newRecordId('BBR'),
          user: bet.username,
          type: 'Deposit',
          amount: bet.stake,
          details: TX_REFUND,
          status: 'Completed'
        }
      });
      refunded += 1;
    } catch (e) {
      await prisma.boundaryBet.updateMany({ where: { id: bet.id }, data: { paid_at: null, payout: 0 } });
      logger.error('cricket: boundary refund failed, claim released for retry', {
        round_id: roundId, bet_id: bet.id, message: e.message
      });
    }
  }

  return { ok: true, voided: true, reason, refunded };
}

/**
 * Void every market still standing on a fixture. Called when a match is abandoned, and by the
 * operator endpoint — an unresolved market holds real money and must not be left holding it.
 */
async function voidOpenRounds(fixtureKey, reason, { wallet } = {}) {
  const { prisma } = context.get();
  const open = await prisma.boundaryRound.findMany({
    where: { fixture_key: String(fixtureKey), status: { in: ['open', 'locked'] } }
  });
  let voided = 0;
  for (const round of open) {
    const result = await voidRound(round.id, reason, { wallet });
    if (result.ok) voided += 1;
  }
  return { ok: true, voided };
}

/**
 * Drive the whole game from one delivery's worth of feed events.
 *
 * Called by the collector after the state has been recomputed. The ordering is the game:
 * resolve what was just bowled, then lock or open whatever comes next.
 */
async function advance(fixtureKey, types, state, events, { wallet } = {}) {
  const { prisma, logger } = context.get();
  const results = { resolved: 0, locked: 0, opened: 0 };

  try {
    // 1. Settle every delivery that arrived in this batch.
    for (const event of events || []) {
      if (event.event_type !== 'ball') continue;
      const norm = normalize.normalizeEvent(event.payload, { fixtureKey });

      // Resolve whatever market is actually pending for this fixture, rather than reconstructing
      // its key from the ball's own over/ball fields. Cricket's own over.ball numbering does not
      // advance on a wide or no-ball — the legal re-bowl that follows an extra is reported at the
      // SAME over.ball position the extra was — so a market opened for "the next delivery" and a
      // key rebuilt from "this ball's own over.ball" can name the same slot for both the extra and
      // the legal delivery that follows it. Resolving by reconstructed key would let the extra's
      // resolution silently consume the round, leaving the legal ball that actually decided the
      // market unresolved (or worse, resolved on the wrong outcome). There is at most one
      // open-or-locked round per fixture at a time by construction (`openNextRound` never creates a
      // second one), so "whichever one is pending" is unambiguous and needs no key reconstruction.
      const pending = await prisma.boundaryRound.findFirst({
        where: { fixture_key: fixtureKey, status: { in: ['open', 'locked'] } },
        orderBy: { created_at: 'desc' }
      });
      if (!pending) continue; // nothing was open for this delivery — same as the old no_market case

      const out = await resolveRound(fixtureKey, pending.delivery_key, norm.detail, { wallet });
      if (out && out.ok && (out.outcome || out.voided)) results.resolved += 1;
    }

    // 2. An abandoned match settles nothing — every market still holding money is refunded.
    if (state && state.abandoned) {
      await voidOpenRounds(fixtureKey, 'match_abandoned', { wallet });
      return results;
    }
    if (!state || state.match_ended) {
      if (state && state.match_ended) await voidOpenRounds(fixtureKey, 'match_ended', { wallet });
      return results;
    }

    // 3. A ball-start event closes the market on the ball about to be bowled. This is the ONLY
    //    thing that closes it — there is no timer anywhere in this file.
    if (types && types.has('ball_start')) {
      const locked = await lockRound(fixtureKey, state);
      results.locked += locked.locked || 0;
    }

    // 4. Open the market on whatever comes next.
    const opened = await openNextRound(fixtureKey, state);
    if (opened.reason === 'opened') results.opened += 1;
  } catch (e) {
    // The events are already durably stored, so a failure here is repairable from the log on the
    // next delivery. It must never fail the webhook and make the provider redeliver stored balls.
    logger.error('cricket: boundary advance failed - events are stored, will repair on the next ball', {
      fixture_key: fixtureKey, message: e.message
    });
  }

  return results;
}

module.exports = {
  // pure — no database, no server
  classifyOutcome,
  allocatePayouts,
  questionFor,
  round2,
  floor2,
  // lifecycle
  openNextRound,
  lockRound,
  placeBet,
  resolveRound,
  voidRound,
  voidOpenRounds,
  advance,
  // constants
  TX_STAKE,
  TX_PAYOUT,
  TX_REFUND
};
