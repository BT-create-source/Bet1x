/**
 * Your 11 — contests, entries and settlement.
 *
 * This is the highest-risk module in the cricket build: it is the only one that moves real money.
 * Everything here follows the disciplines docs/YOUR11-SCOPE.md section 2 pinned down, and those
 * disciplines are copied from paths already proven in server.js rather than invented here:
 *
 *   - The wallet is never read-then-written. `wallet.debit` performs the balance check and the
 *     deduction in one statement (server.js:177), so two simultaneous joins cannot both pass a
 *     check against the same balance.
 *   - Money moves first, the ledger row is written second, and a ledger failure REVERSES the money
 *     movement (the Mines discipline, server.js:4700-4726). A debit standing with no ledger row is
 *     how ₹400 disappeared during load testing.
 *   - Settlement is claimed with a conditional updateMany, the same compare-and-set the
 *     deposit/withdrawal approval paths use (server.js:5409-5444). `count === 0` means someone else
 *     already settled it: return success, pay nothing.
 *   - Every entry carries its OWN `paid_at` claim, so a crash halfway through a ten-thousand-winner
 *     payout resumes without paying the first half twice.
 *   - Settlement never reads live state. It recomputes from the permanent event log, because the
 *     live cache is derived and a settled contest is not.
 *
 * The pure functions at the top (lineup validation, pool maths, ranking, prize allocation) take
 * plain arguments and touch nothing, so the whole prize-money algorithm is testable without a
 * database or a server — which is how test_cricket.js exercises it.
 *
 * The house entry (docs/YOUR11-SCOPE.md section 4) is deliberately NOT here yet. It needs the
 * hindsight lineup back-solver, and settling contests correctly is a prerequisite for rigging them.
 * `is_house` rows are already handled correctly by everything below: they rank, they win, and they
 * are paid, because the house entry pays its own entry fee and takes its winnings like any entrant.
 */

const context = require('./context');
const configStore = require('./config-store');
const eventLog = require('./event-log');
const liveState = require('./live-state');
const scoring = require('./scoring');

/** Ledger `details` strings. These must match what classifyGameplayTransaction (server.js:1759)
 *  already recognises, so cricket rows fold into admin stats instead of being invisible to them. */
const TX_ENTRY_FEE = 'Fantasy Cricket Entry Fee';
const TX_PAYOUT = 'Fantasy Cricket Payout';
const TX_REFUND = 'Fantasy Cricket Refund';

/** Formats that never involve money, and so never involve settlement. */
const FREE_FORMATS = ['practice'];

/**
 * Resolve the wallet adapter, preferring an explicitly passed one (the test seam) over the one
 * injected at boot. Throws rather than defaulting to a no-op: a settlement that silently pays
 * nothing because a dependency was missing is worse than one that fails loudly.
 */
function walletOf(passed) {
  const wallet = passed || context.get().wallet;
  if (!wallet) throw new Error('cricket: no wallet adapter — pass cricket.init({ wallet }) at boot');
  return wallet;
}

// ------------------------------------------------------------------------------------------------
// money
// ------------------------------------------------------------------------------------------------

/** Round to the paisa. Used for anything shown or stored as a balance. */
function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

/**
 * Round DOWN to the paisa.
 *
 * Splitting a tied prize must never round up: three winners each rounded up on a ⅓ split pays out
 * more than the pool holds. The shortfall is handed to one winner as a remainder instead, so the
 * sum of the prizes is exactly the pool every time.
 */
function floor2(n) {
  return Math.floor((Number(n) || 0) * 100) / 100;
}

// ------------------------------------------------------------------------------------------------
// prize breakup
// ------------------------------------------------------------------------------------------------

/**
 * Validate a prize table.
 *
 * `pct` is the percentage of the prize pool won by EACH rank in the range, not by the range as a
 * whole — the standard fantasy model, where every place in a band wins the same amount. The total
 * therefore weights each band by how many ranks it covers.
 *
 * NOTE: the worked example in docs/YOUR11-SCOPE.md section 1 does not sum to 100 under either
 * reading, so it is illustrative rather than a fixture. This reading is the one implemented and the
 * one settlement pays from; if the client signs off the other, this function and prizeForRank are
 * the only two places that change.
 */
function validatePrizeBreakup(breakup) {
  if (!Array.isArray(breakup) || breakup.length === 0) {
    return { ok: false, error: 'Prize breakup must be a non-empty array.' };
  }

  const rows = [];
  for (const row of breakup) {
    const from = Number(row && row.from);
    const to = Number(row && row.to);
    const pct = Number(row && row.pct);
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to < from) {
      return { ok: false, error: 'Each prize row needs integer from/to with from >= 1 and to >= from.' };
    }
    if (!Number.isFinite(pct) || pct <= 0) {
      return { ok: false, error: 'Each prize row needs a positive pct.' };
    }
    rows.push({ from, to, pct });
  }

  rows.sort((a, b) => a.from - b.from);

  if (rows[0].from !== 1) {
    return { ok: false, error: 'Prize breakup must start at rank 1.' };
  }

  // Contiguous and non-overlapping. A gap would silently pay nothing to a rank the UI advertises as
  // winning; an overlap would pay it twice.
  for (let i = 1; i < rows.length; i += 1) {
    if (rows[i].from !== rows[i - 1].to + 1) {
      return {
        ok: false,
        error: `Prize breakup must be contiguous: rank ${rows[i - 1].to + 1} is ${rows[i].from > rows[i - 1].to + 1 ? 'missing' : 'covered twice'}.`
      };
    }
  }

  const total = rows.reduce((sum, r) => sum + (r.to - r.from + 1) * r.pct, 0);
  if (Math.abs(total - 100) > 0.01) {
    return { ok: false, error: `Prize percentages must total 100 (got ${total.toFixed(2)}).` };
  }

  return { ok: true, breakup: rows, total_pct: total };
}

/** The percentage won by one rank, or 0 for a rank outside the paid places. */
function pctForRank(breakup, rank) {
  for (const row of breakup) {
    if (rank >= row.from && rank <= row.to) return row.pct;
  }
  return 0;
}

/**
 * gross → rake → prize pool.
 *
 * `rake_pct` is operator config and the platform's only revenue on this game, so it is read from the
 * contest row rather than a constant (Ground Rule 6).
 */
function computePool(entryFee, entrants, rakePct) {
  const gross = round2(Number(entryFee || 0) * Number(entrants || 0));
  const rake = round2(gross * (Number(rakePct || 0) / 100));
  return { gross_pool: gross, rake, prize_pool: round2(gross - rake) };
}

// ------------------------------------------------------------------------------------------------
// lineup validation
// ------------------------------------------------------------------------------------------------

/**
 * Check a submitted XI against every rule a real user faces.
 *
 * The house-entry back-solver (section 4) will call this same function on the lineups it generates,
 * which is the point of keeping it pure: a house team that could not have been picked by a user is
 * the tell that undoes the whole design, and the only way to guarantee it never happens is to put
 * both through one validator.
 */
function validateLineup(lineup, { squad, credits = {}, rules, defaultCredits = 8 }) {
  const players = Array.isArray(lineup && lineup.players) ? lineup.players.map(String) : [];
  const captain = lineup && lineup.captain ? String(lineup.captain) : '';
  const viceCaptain = lineup && lineup.vice_captain ? String(lineup.vice_captain) : '';

  const size = rules.squad_size || 11;
  if (players.length !== size) {
    return { ok: false, error: `Pick exactly ${size} players (you picked ${players.length}).` };
  }
  if (new Set(players).size !== players.length) {
    return { ok: false, error: 'The same player cannot be picked twice.' };
  }

  const byKey = new Map();
  for (const p of squad || []) byKey.set(String(p.player_key), p);

  const missing = players.filter(p => !byKey.has(p));
  if (missing.length) {
    return { ok: false, error: `Not in this match's squad: ${missing.join(', ')}.` };
  }

  if (!captain || !players.includes(captain)) {
    return { ok: false, error: 'The captain must be one of your 11.' };
  }
  if (!viceCaptain || !players.includes(viceCaptain)) {
    return { ok: false, error: 'The vice-captain must be one of your 11.' };
  }
  if (captain === viceCaptain) {
    return { ok: false, error: 'The captain and vice-captain must be different players.' };
  }

  const roleCounts = {};
  const teamCounts = {};
  const roles = {};
  let creditsUsed = 0;

  for (const key of players) {
    const p = byKey.get(key);
    const role = String(p.role || 'BAT').toUpperCase();
    roles[key] = role;
    roleCounts[role] = (roleCounts[role] || 0) + 1;
    teamCounts[p.team_key] = (teamCounts[p.team_key] || 0) + 1;
    // An unpriced player defaults to the mid-band rather than to free — a missing credit row must
    // never make a player cheaper than one that has been priced.
    const price = credits[key];
    creditsUsed += Number.isFinite(price) ? price : defaultCredits;
  }

  for (const [role, limit] of Object.entries(rules.role_limits || {})) {
    const count = roleCounts[role] || 0;
    if (count < (limit.min || 0)) {
      return { ok: false, error: `Pick at least ${limit.min} ${role} (you have ${count}).` };
    }
    if (count > (limit.max || size)) {
      return { ok: false, error: `Pick at most ${limit.max} ${role} (you have ${count}).` };
    }
  }

  const maxPerTeam = rules.max_per_real_team || size;
  for (const [teamKey, count] of Object.entries(teamCounts)) {
    if (count > maxPerTeam) {
      return { ok: false, error: `At most ${maxPerTeam} players from one team (you have ${count} from ${teamKey}).` };
    }
  }

  creditsUsed = round2(creditsUsed);
  const budget = rules.credit_budget || 100;
  if (creditsUsed > budget + 0.001) {
    return { ok: false, error: `Over budget: ${creditsUsed.toFixed(1)} of ${budget} credits.` };
  }

  return { ok: true, credits_used: creditsUsed, roles, role_counts: roleCounts, team_counts: teamCounts };
}

/**
 * docs/YOUR11-SCOPE.md §3: a player replaced in the confirmed XI after the original squad
 * announcement is auto-substituted with the highest-credit valid bench player from the same team
 * and role — never left to silently score zero.
 *
 * Same team + same role is what keeps the swap legal for free: it cannot change any role count or
 * team count the lineup already satisfied. Only the credit budget can still break, so the candidate
 * pool is filtered to what still fits before picking the priciest one. Captaincy travels with the
 * seat: if the removed player was C/VC, the substitute inherits that designation rather than the
 * multiplier silently vanishing.
 *
 * Pure — takes `entry.players`/`captain`/`vice_captain` plus the squad/credits/rules already loaded
 * by the caller, and returns a description of the change rather than writing anything itself. Kept
 * pure for the same reason `validateLineup` is: it needs to be testable without a database, and the
 * house entry never goes through this path (it is always solved from the confirmed XI already).
 */
function substituteExcludedPlayers(entry, { squad, credits = {}, rules, confirmedXi, defaultCredits = 8 }) {
  const xi = new Set((confirmedXi || []).map(String));
  const players = Array.isArray(entry.players) ? entry.players.map(String) : [];
  const excluded = players.filter(p => !xi.has(p));
  if (!excluded.length) return { changed: false };

  const byKey = new Map();
  for (const p of squad || []) byKey.set(String(p.player_key), p);

  const priceOf = key => {
    const price = credits[key];
    return Number.isFinite(price) ? price : defaultCredits;
  };

  const working = players.slice();
  let captain = entry.captain ? String(entry.captain) : '';
  let viceCaptain = entry.vice_captain ? String(entry.vice_captain) : '';
  let creditsUsed = round2(working.reduce((sum, k) => sum + priceOf(k), 0));
  const budget = (rules && rules.credit_budget) || 100;
  const substitutions = [];

  for (const removedKey of excluded) {
    const removed = byKey.get(removedKey);
    if (!removed) {
      return { changed: false, ok: false, error: `${removedKey} is not part of this match's squad.` };
    }

    const usedSet = new Set(working);
    const roomLeft = budget - (creditsUsed - priceOf(removedKey));

    const candidates = (squad || [])
      .filter(p => {
        const key = String(p.player_key);
        return xi.has(key) &&
          String(p.team_key) === String(removed.team_key) &&
          String(p.role || 'BAT').toUpperCase() === String(removed.role || 'BAT').toUpperCase() &&
          !usedSet.has(key) &&
          priceOf(key) <= roomLeft + 0.001;
      })
      .sort((a, b) => priceOf(String(b.player_key)) - priceOf(String(a.player_key)));

    if (!candidates.length) {
      return {
        changed: false, ok: false,
        error: `No same-team, same-role, in-budget replacement in the confirmed XI for ${removedKey}.`
      };
    }

    const subKey = String(candidates[0].player_key);
    const idx = working.indexOf(removedKey);
    working[idx] = subKey;
    creditsUsed = round2(creditsUsed - priceOf(removedKey) + priceOf(subKey));
    if (captain === removedKey) captain = subKey;
    if (viceCaptain === removedKey) viceCaptain = subKey;
    substitutions.push({ out: removedKey, in: subKey });
  }

  return {
    changed: true, ok: true,
    players: working, captain, vice_captain: viceCaptain, credits_used: creditsUsed, substitutions
  };
}

// ------------------------------------------------------------------------------------------------
// ranking and prize allocation
// ------------------------------------------------------------------------------------------------

/**
 * Order entries and assign ranks.
 *
 * Order is points descending, then earliest submission first. Equal points share a rank
 * (competition ranking: 1, 2, 2, 4), because they genuinely tied — submission time only decides the
 * order they are listed in and who collects the remainder paisa when a tied prize does not divide
 * evenly. It is deliberately NOT used to break the tie for money: rewarding whoever submitted first
 * would make an early entry strictly more valuable than an identical late one.
 */
function rankEntries(entries) {
  const sorted = [...(entries || [])].sort((a, b) => {
    const diff = (Number(b.points) || 0) - (Number(a.points) || 0);
    if (Math.abs(diff) > 0.0001) return diff;
    return new Date(a.created_at || 0) - new Date(b.created_at || 0);
  });

  let lastPoints = null;
  let lastRank = 0;
  return sorted.map((entry, index) => {
    const points = Number(entry.points) || 0;
    const rank = lastPoints !== null && Math.abs(points - lastPoints) <= 0.0001 ? lastRank : index + 1;
    lastPoints = points;
    lastRank = rank;
    return { ...entry, points, rank };
  });
}

/**
 * Split the prize pool across ranked entries.
 *
 * Tied entries pool the prizes for every rank slot they occupy and split them evenly, rounded down
 * to the paisa, with the undividable remainder going to the lowest-numbered rank in the group
 * (docs/YOUR11-SCOPE.md section 1). Pooling the slots — rather than paying each tied entry the prize
 * for the shared rank — is what keeps total payout equal to the pool: three entries tied on rank 2
 * consume ranks 2, 3 and 4, and nobody is ranked 3rd.
 */
function allocatePrizes(rankedEntries, breakup, prizePool) {
  const ranked = rankEntries(rankedEntries);
  const pool = Number(prizePool) || 0;
  if (pool <= 0) return ranked.map(e => ({ ...e, prize: 0 }));

  const groups = new Map();
  for (const entry of ranked) {
    if (!groups.has(entry.rank)) groups.set(entry.rank, []);
    groups.get(entry.rank).push(entry);
  }

  const out = [];
  for (const [rank, group] of groups) {
    // The slots this tie consumes: a 3-way tie on rank 2 occupies ranks 2, 3 and 4.
    let slotTotal = 0;
    for (let slot = rank; slot < rank + group.length; slot += 1) {
      slotTotal += pool * (pctForRank(breakup, slot) / 100);
    }

    if (slotTotal <= 0) {
      for (const entry of group) out.push({ ...entry, prize: 0 });
      continue;
    }

    const each = floor2(slotTotal / group.length);
    const remainder = round2(slotTotal - each * group.length);
    group.forEach((entry, i) => {
      out.push({ ...entry, prize: round2(i === 0 ? each + remainder : each) });
    });
  }

  out.sort((a, b) => a.rank - b.rank || new Date(a.created_at || 0) - new Date(b.created_at || 0));
  return out;
}

// ------------------------------------------------------------------------------------------------
// scoring an entry from the permanent log
// ------------------------------------------------------------------------------------------------

/**
 * Recompute every entry's points for a fixture from the event log.
 *
 * Recomputed in full, never incremented — the same guarantee the live-state builder rests on. A
 * dropped or out-of-order push delivery must be harmless, and an incremented total is the one design
 * where it silently is not.
 */
async function scoreEntries(fixtureKey, entries, { fixture = null } = {}) {
  const { prisma } = context.get();

  const rows = await eventLog.readEvents(fixtureKey);
  const state = liveState.buildLiveState(rows, { fixtureKey });

  const squad = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: fixtureKey } });
  const roles = {};
  const confirmedXi = [];
  for (const p of squad) {
    roles[p.player_key] = p.role;
    if (p.in_confirmed_xi) confirmedXi.push(p.player_key);
  }

  const format = (fixture && fixture.format) || 'T20';
  const rules = await configStore.scoringFor(format);

  return {
    state,
    scored: entries.map(entry => ({
      ...entry,
      points: scoring.scoreTeam(entry, state, rules, {
        roles,
        confirmedXi: confirmedXi.length ? confirmedXi : null
      }).total
    }))
  };
}

// ------------------------------------------------------------------------------------------------
// contest lifecycle
// ------------------------------------------------------------------------------------------------

/** Create a contest. Rejects an invalid prize table outright rather than storing one settlement
 *  cannot pay from — the table is validated here, on save, and trusted thereafter. */
async function createContest(input) {
  const { prisma } = context.get();
  const defaults = await configStore.contest();

  const format = String(input.format || 'small').toLowerCase();
  const entryFee = FREE_FORMATS.includes(format) ? 0 : round2(input.entry_fee);
  const isFree = entryFee <= 0;

  if (!input.fixture_key) return { ok: false, error: 'fixture_key is required.' };
  if (!isFree && entryFee <= 0) return { ok: false, error: 'A paid contest needs a positive entry fee.' };

  const maxEntrants = Number(input.max_entrants) || 0;
  const minEntrants = Number(input.min_entrants) || defaults.min_entrants_default || 2;
  if (maxEntrants < 2 && !isFree) return { ok: false, error: 'max_entrants must be at least 2.' };
  if (minEntrants > maxEntrants && maxEntrants > 0) {
    return { ok: false, error: 'min_entrants cannot exceed max_entrants.' };
  }

  const guaranteed = input.guaranteed != null ? !!input.guaranteed : !!defaults.guaranteed_by_default;
  if (guaranteed && !isFree && !(round2(input.guaranteed_pool) > 0)) {
    // A guaranteed contest with no advertised pool has nothing to guarantee, and would silently
    // behave as fill-or-cancel while the UI promised otherwise.
    return { ok: false, error: 'A guaranteed contest needs a positive guaranteed_pool.' };
  }

  // A free contest pays nothing, so it needs no prize table at all.
  let breakup = [];
  if (!isFree) {
    const check = validatePrizeBreakup(input.prize_breakup);
    if (!check.ok) return check;
    breakup = check.breakup;
  }

  const contest = await prisma.cricketContest.create({
    data: {
      fixture_key: String(input.fixture_key),
      name: String(input.name || 'Contest'),
      format,
      entry_fee: entryFee,
      rake_pct: isFree ? 0 : round2(input.rake_pct != null ? input.rake_pct : defaults.rake_pct),
      prize_breakup: breakup,
      min_entrants: minEntrants,
      max_entrants: maxEntrants || 1000000,
      max_entries_per_user: Number(input.max_entries_per_user) || 1,
      guaranteed: guaranteed,
      guaranteed_pool: guaranteed ? round2(input.guaranteed_pool) : 0,
      invite_code: input.invite_code ? String(input.invite_code) : null,
      created_by: input.created_by ? String(input.created_by) : null
    }
  });

  return { ok: true, contest };
}

/**
 * Join a contest.
 *
 * Order of operations is the part that matters: validate everything that can be validated for free,
 * then take the money, then write the ledger row, then write the entry — reversing the money if
 * either write fails. Validation after a debit is how a rejected entry keeps a user's stake.
 */
async function joinContest(contestId, username, lineup, { wallet: passedWallet, isHouse = false, bypassLock = false } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);

  const contest = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
  if (!contest) return { ok: false, status: 404, error: 'Contest not found.' };

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: contest.fixture_key } });
  if (!fixture) return { ok: false, status: 404, error: 'Match not found.' };

  // `bypassLock` exists for exactly one caller: the house entry, which is placed AT the confirmed-XI
  // lock, once the final field size the guardrails check is actually known. It is never reachable
  // from a route — no request body can set it — and it skips only the lock gate. Every other rule
  // below (field limits, lineup legality, the entry fee, the wallet debit) still applies to the
  // house exactly as it does to a real user.
  if (!bypassLock) {
    if (contest.status !== 'open') return { ok: false, status: 400, error: 'This contest is closed.' };

    // Teams lock on the confirmed-XI event from the feed, never on a clock (brief section 6).
    if (fixture.lineups_confirmed_at) {
      return { ok: false, status: 400, error: 'Teams are locked — the line-ups for this match are confirmed.' };
    }
    if (['live', 'completed', 'abandoned'].includes(fixture.status)) {
      return { ok: false, status: 400, error: 'This match has already started.' };
    }
  } else if (!['open', 'locked'].includes(contest.status)) {
    return { ok: false, status: 400, error: 'This contest is closed.' };
  }

  const existing = await prisma.cricketEntry.findMany({ where: { contest_id: contest.id, username } });
  if (existing.length >= contest.max_entries_per_user) {
    return {
      ok: false,
      status: 400,
      error: contest.max_entries_per_user === 1
        ? 'You have already entered this contest.'
        : `You have used all ${contest.max_entries_per_user} of your entries.`
    };
  }

  const totalEntries = await prisma.cricketEntry.count({ where: { contest_id: contest.id } });
  if (totalEntries >= contest.max_entrants) {
    return { ok: false, status: 400, error: 'This contest is full.' };
  }

  const squad = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: contest.fixture_key } });
  const creditRows = await prisma.cricketPlayerCredit.findMany({ where: { fixture_key: contest.fixture_key } });
  const credits = {};
  for (const row of creditRows) credits[row.player_key] = row.credits;

  const rules = await configStore.contest();
  const creditRules = await configStore.credits();
  const check = validateLineup(lineup, {
    squad, credits, rules, defaultCredits: creditRules.default_credits
  });
  if (!check.ok) return { ok: false, status: 400, error: check.error };

  const user = await prisma.user.findUnique({ where: { username } });
  if (!user) return { ok: false, status: 404, error: 'User not found.' };

  const fee = round2(contest.entry_fee);
  let balanceAfter = user.wallet_balance;

  if (fee > 0) {
    balanceAfter = await wallet.debit(user.id, fee);
    if (balanceAfter === null) {
      return {
        ok: false,
        status: 400,
        error: `Insufficient balance! You have ₹${Number(user.wallet_balance).toFixed(2)}.`
      };
    }
  }

  // From here the money has moved. Every exit must either complete the entry or put it back.
  const refund = async reason => {
    try {
      if (fee > 0) await wallet.credit(user.id, fee);
    } catch (e) {
      logger.error('cricket: FAILED TO REVERSE ENTRY FEE - money is missing', {
        username, contest_id: contest.id, fee, reason, message: e.message
      });
    }
  };

  try {
    if (fee > 0) {
      await prisma.transaction.create({
        data: {
          id: wallet.newRecordId('Y11'),
          user: username,
          type: 'Withdrawal',
          amount: fee,
          details: TX_ENTRY_FEE,
          status: 'Completed'
        }
      });
    }
  } catch (e) {
    await refund('ledger_write_failed');
    logger.error('cricket: entry-fee ledger write failed, fee reversed', {
      username, contest_id: contest.id, message: e.message
    });
    return { ok: false, status: 500, error: 'Could not record the entry fee. Nothing was charged.' };
  }

  try {
    const entry = await prisma.cricketEntry.create({
      data: {
        contest_id: contest.id,
        username,
        team_name: String(lineup.team_name || `${username}'s XI`),
        display_name: lineup.display_name ? String(lineup.display_name) : null,
        players: lineup.players.map(String),
        captain: String(lineup.captain),
        vice_captain: String(lineup.vice_captain),
        credits_used: check.credits_used,
        is_house: !!isHouse,
        entry_index: existing.length + 1
      }
    });
    return { ok: true, entry, balance: balanceAfter, credits_used: check.credits_used };
  } catch (e) {
    // The unique constraint on (contest_id, username, entry_index) is what turns two simultaneous
    // joins into one entry plus one clean refund, rather than two entries for one seat.
    await refund('entry_write_failed');
    logger.error('cricket: entry write failed, fee reversed', {
      username, contest_id: contest.id, message: e.message
    });
    return { ok: false, status: 500, error: 'Could not save your team. Nothing was charged.' };
  }
}

/**
 * Lock every open contest on a fixture. Called when the confirmed-XI event arrives, not on a timer.
 *
 * Fill-or-cancel is applied here, at the only moment the final entrant count is known: a contest
 * short of `min_entrants` voids and refunds in full rather than paying a prize table out of a pool
 * that never filled. A guaranteed contest locks regardless — the house covers the shortfall, which
 * is the liability that distinguishes the two policies.
 */
async function lockContestsForFixture(fixtureKey, { wallet } = {}) {
  const { prisma, logger } = context.get();

  const contests = await prisma.cricketContest.findMany({
    where: { fixture_key: String(fixtureKey), status: 'open' }
  });

  const locked = [];
  const voided = [];
  let substituted = 0;

  // Auto-substitute any picked player the toss-time lineup event excluded (docs/YOUR11-SCOPE.md §3),
  // before deciding fill-or-cancel or locking anything — a voided contest refunds regardless of who
  // was on the team, so this only needs to run once, ahead of both.
  const squad = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: String(fixtureKey) } });
  const confirmedXi = squad.filter(p => p.in_confirmed_xi).map(p => String(p.player_key));

  if (confirmedXi.length && contests.length) {
    const creditRows = await prisma.cricketPlayerCredit.findMany({ where: { fixture_key: String(fixtureKey) } });
    const credits = {};
    for (const row of creditRows) credits[row.player_key] = row.credits;
    const rules = await configStore.contest();
    const creditRules = await configStore.credits();

    for (const contest of contests) {
      const entries = await prisma.cricketEntry.findMany({ where: { contest_id: contest.id } });
      for (const entry of entries) {
        const sub = substituteExcludedPlayers(entry, {
          squad, credits, rules, confirmedXi, defaultCredits: creditRules.default_credits
        });
        if (!sub.changed) continue;

        if (sub.ok) {
          await prisma.cricketEntry.update({
            where: { id: entry.id },
            data: { players: sub.players, captain: sub.captain, vice_captain: sub.vice_captain, credits_used: sub.credits_used }
          });
          substituted += 1;
          logger.info('cricket: auto-substituted a player excluded from the confirmed XI', {
            fixture_key: fixtureKey, contest_id: contest.id, entry_id: entry.id, subs: sub.substitutions
          });
        } else {
          // No legal same-team/same-role/in-budget bench player exists. Rare — logged loudly, and the
          // entry is left as picked: the excluded player scores zero for that slot at settlement,
          // which is the documented fallback (docs/YOUR11-SCOPE.md §3) short of voiding the entry.
          logger.warn('cricket: excluded player has no legal auto-substitute, entry left unchanged', {
            fixture_key: fixtureKey, contest_id: contest.id, entry_id: entry.id, reason: sub.error
          });
        }
      }
    }
  }

  for (const contest of contests) {
    const entrants = await prisma.cricketEntry.count({ where: { contest_id: contest.id } });

    if (!contest.guaranteed && entrants < contest.min_entrants && contest.entry_fee > 0) {
      const result = await voidContest(contest.id, 'fill_or_cancel_shortfall', { wallet });
      voided.push({ id: contest.id, entrants, refunded: result.refunded });
      continue;
    }

    const claim = await prisma.cricketContest.updateMany({
      where: { id: contest.id, status: 'open' },
      data: { status: 'locked' }
    });
    if (claim.count > 0) locked.push({ id: contest.id, entrants });
  }

  logger.info('cricket: contests locked at confirmed XI', {
    fixture_key: fixtureKey, locked: locked.length, voided: voided.length, substituted
  });
  return { ok: true, locked, voided, substituted };
}

/**
 * Void a contest and refund every entrant in full, rake included.
 *
 * Used by fill-or-cancel, by an abandoned match, and by an operator void. The per-entry `paid_at`
 * claim is what makes a re-run safe: an entry already refunded is skipped, not refunded twice.
 */
async function voidContest(contestId, reason, { wallet: passedWallet } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);

  const claim = await prisma.cricketContest.updateMany({
    where: { id: String(contestId), status: { in: ['open', 'locked'] } },
    data: { status: 'voided', voided_at: new Date(), void_reason: String(reason || 'operator_void') }
  });
  if (claim.count === 0) {
    // Already voided or already settled. Refunds below are still attempted for a void, because a
    // crash mid-refund leaves the contest marked voided with entries unpaid.
    const current = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
    if (!current || current.status !== 'voided') {
      return { ok: false, error: 'Contest cannot be voided in its current state.', status: current && current.status };
    }
  }

  const contest = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
  const fee = round2(contest.entry_fee);
  if (fee <= 0) return { ok: true, refunded: 0, reason };

  const entries = await prisma.cricketEntry.findMany({
    where: { contest_id: contest.id, paid_at: null }
  });

  let refunded = 0;
  for (const entry of entries) {
    const entryClaim = await prisma.cricketEntry.updateMany({
      where: { id: entry.id, paid_at: null },
      data: { paid_at: new Date(), prize: fee }
    });
    if (entryClaim.count === 0) continue; // another run already refunded this one

    try {
      const user = await prisma.user.findUnique({ where: { username: entry.username } });
      if (!user) throw new Error(`no such user: ${entry.username}`);
      await wallet.credit(user.id, fee);
      await prisma.transaction.create({
        data: {
          id: wallet.newRecordId('Y11R'),
          user: entry.username,
          type: 'Deposit',
          amount: fee,
          details: TX_REFUND,
          status: 'Completed'
        }
      });
      refunded += 1;
    } catch (e) {
      // Release the claim so the next run retries this entry rather than skipping it forever.
      await prisma.cricketEntry.updateMany({ where: { id: entry.id }, data: { paid_at: null, prize: 0 } });
      logger.error('cricket: refund failed, claim released for retry', {
        contest_id: contest.id, entry_id: entry.id, username: entry.username, message: e.message
      });
    }
  }

  logger.info('cricket: contest voided', { contest_id: contest.id, reason, refunded, entries: entries.length });
  return { ok: true, refunded, reason };
}

/**
 * Settle a contest.
 *
 * Automatic on match end. It pauses and returns `needs_review` ONLY when reconciliation against the
 * provider's own final scorecard finds a genuine discrepancy — the exception path, not something
 * every match waits on (brief section 6).
 */
async function settleContest(contestId, { wallet: passedWallet, force = false } = {}) {
  const { prisma, logger } = context.get();
  const wallet = walletOf(passedWallet);

  const contest = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
  if (!contest) return { ok: false, status: 404, error: 'Contest not found.' };

  // Idempotent by design: re-running settlement for a settled contest is a safe no-op, never a
  // second payout.
  if (contest.settled_at) {
    return { ok: true, already_settled: true, paid: 0, contest_id: contest.id };
  }
  if (contest.status === 'voided') {
    return { ok: true, voided: true, paid: 0, contest_id: contest.id };
  }
  if (FREE_FORMATS.includes(contest.format) || contest.entry_fee <= 0) {
    await prisma.cricketContest.updateMany({
      where: { id: contest.id, settled_at: null },
      data: { settled_at: new Date(), status: 'settled' }
    });
    return { ok: true, free: true, paid: 0, contest_id: contest.id };
  }

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: contest.fixture_key } });
  if (!fixture) return { ok: false, status: 404, error: 'Match not found.' };

  // An abandoned match voids and refunds — no payout, no house payout (scope section 3).
  if (fixture.status === 'abandoned') {
    return voidContest(contest.id, 'match_abandoned', { wallet });
  }
  if (fixture.status !== 'completed' && !force) {
    return { ok: false, status: 400, error: 'This match has not finished.' };
  }

  const rawEntries = await prisma.cricketEntry.findMany({ where: { contest_id: contest.id } });
  if (rawEntries.length === 0) {
    await prisma.cricketContest.updateMany({
      where: { id: contest.id, settled_at: null },
      data: { settled_at: new Date(), status: 'settled' }
    });
    return { ok: true, paid: 0, entrants: 0, contest_id: contest.id };
  }

  // Recomputed from the permanent log, never from the live cache.
  const { state, scored } = await scoreEntries(contest.fixture_key, rawEntries, { fixture });

  const check = scoring.reconcile(state, fixture.official_scorecard, { tolerance: 0 });
  if (!check.ok && !force) {
    logger.error('cricket: settlement paused for review - reconciliation failed', {
      contest_id: contest.id,
      fixture_key: contest.fixture_key,
      reason: check.reason,
      discrepancies: check.discrepancies.slice(0, 10)
    });
    return {
      ok: false,
      status: 409,
      needs_review: true,
      error: 'Settlement paused: computed scores do not match the official scorecard.',
      reconciliation: check
    };
  }

  const { prize_pool: prizePool, rake, gross_pool: grossPool } = computePool(
    contest.entry_fee, scored.length, contest.rake_pct
  );

  // A guaranteed contest pays the advertised pool even when it under-fills; the house covers the
  // difference, and that shortfall is reported so the liability is visible rather than absorbed.
  const advertised = round2(Number(contest.guaranteed_pool) || 0);
  const payoutPool = contest.guaranteed && advertised > prizePool ? advertised : prizePool;
  const shortfall = round2(Math.max(0, payoutPool - prizePool));

  const allocated = allocatePrizes(scored, contest.prize_breakup || [], payoutPool);

  // Claim the contest BEFORE paying anyone. A second concurrent run gets count === 0 and pays
  // nothing, which is the whole point of the compare-and-set.
  const claim = await prisma.cricketContest.updateMany({
    where: { id: contest.id, settled_at: null },
    data: { settled_at: new Date(), status: 'settled' }
  });
  if (claim.count === 0) {
    return { ok: true, already_settled: true, paid: 0, contest_id: contest.id };
  }

  let paid = 0;
  let paidAmount = 0;

  for (const entry of allocated) {
    await prisma.cricketEntry.update({
      where: { id: entry.id },
      data: { points: round2(entry.points), rank: entry.rank }
    });

    if (entry.prize <= 0) continue;

    // Per-entry claim: a crash partway through resumes here without re-paying anyone above.
    const entryClaim = await prisma.cricketEntry.updateMany({
      where: { id: entry.id, paid_at: null },
      data: { paid_at: new Date(), prize: entry.prize }
    });
    if (entryClaim.count === 0) continue;

    try {
      const user = await prisma.user.findUnique({ where: { username: entry.username } });
      if (!user) throw new Error(`no such user: ${entry.username}`);

      // Credit, then ledger, then reverse on failure — the Mines discipline (server.js:4700-4726).
      await wallet.credit(user.id, entry.prize);
      try {
        await prisma.transaction.create({
          data: {
            id: wallet.newRecordId('Y11W'),
            user: entry.username,
            type: 'Deposit',
            amount: entry.prize,
            details: TX_PAYOUT,
            status: 'Completed'
          }
        });
      } catch (ledgerErr) {
        try {
          await wallet.debit(user.id, entry.prize);
        } catch (reverseErr) {
          logger.error('cricket: PAYOUT REVERSAL FAILED - unledgered money is in a wallet', {
            contest_id: contest.id, entry_id: entry.id, username: entry.username,
            prize: entry.prize, message: reverseErr.message
          });
        }
        throw ledgerErr;
      }

      paid += 1;
      paidAmount = round2(paidAmount + entry.prize);
    } catch (e) {
      await prisma.cricketEntry.updateMany({ where: { id: entry.id }, data: { paid_at: null } });
      logger.error('cricket: payout failed, claim released for retry', {
        contest_id: contest.id, entry_id: entry.id, username: entry.username, message: e.message
      });
    }
  }

  logger.info('cricket: contest settled', {
    contest_id: contest.id, entrants: scored.length, paid, paid_amount: paidAmount, rake, shortfall
  });

  return {
    ok: true,
    contest_id: contest.id,
    entrants: scored.length,
    gross_pool: grossPool,
    rake,
    prize_pool: payoutPool,
    guarantee_shortfall: shortfall,
    paid,
    paid_amount: paidAmount,
    leaderboard: allocated.map(e => ({
      rank: e.rank, username: e.username, team_name: e.team_name, points: round2(e.points), prize: e.prize
    }))
  };
}

/**
 * Settle every contest on a finished fixture. This is what the match-end event calls.
 *
 * One contest failing reconciliation must not stop the rest from paying, so failures are collected
 * and reported rather than thrown.
 */
async function settleFixture(fixtureKey, { wallet } = {}) {
  const { prisma } = context.get();

  const contests = await prisma.cricketContest.findMany({
    where: { fixture_key: String(fixtureKey), status: { in: ['open', 'locked'] } }
  });

  const settled = [];
  const review = [];
  for (const contest of contests) {
    const result = await settleContest(contest.id, { wallet });
    if (result.needs_review) review.push({ contest_id: contest.id, reconciliation: result.reconciliation });
    else settled.push(result);
  }

  return { ok: true, settled: settled.length, needs_review: review.length, review, results: settled };
}

/**
 * The player-facing leaderboard.
 *
 * Opponent lineups are withheld until the match ends (docs/YOUR11-SCOPE.md section 4). This is a
 * uniform product rule, not a special case: it applies to every entrant, so no row is the one that
 * cannot be opened. After the match it is lifted for everyone at once.
 */
async function leaderboard(contestId, { viewer = null } = {}) {
  const { prisma } = context.get();

  const contest = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
  if (!contest) return { ok: false, status: 404, error: 'Contest not found.' };

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: contest.fixture_key } });
  const matchEnded = !!fixture && ['completed', 'abandoned'].includes(fixture.status);

  const entries = await prisma.cricketEntry.findMany({ where: { contest_id: contest.id } });
  const pool = computePool(contest.entry_fee, entries.length, contest.rake_pct);

  // A settled contest reports what it actually PAID, read straight off the entry rows. Re-deriving
  // it would be wrong in two ways that both surface as a player being shown a number they did not
  // receive: a guaranteed contest pays an advertised pool larger than the entry fees raised, and a
  // payout that failed and was left for retry has no money behind it yet. Settlement is the record;
  // this is a read of it, not a second opinion.
  let ranked;
  let prizePool;
  if (contest.status === 'settled') {
    prizePool = round2(entries.reduce((sum, e) => sum + (Number(e.prize) || 0), 0));
    ranked = [...entries].sort((a, b) =>
      (a.rank || Number.MAX_SAFE_INTEGER) - (b.rank || Number.MAX_SAFE_INTEGER) ||
      new Date(a.created_at || 0) - new Date(b.created_at || 0));
  } else {
    // Live: recompute from the permanent log and show the prizes the standings imply right now.
    const { scored } = await scoreEntries(contest.fixture_key, entries, { fixture });
    prizePool = contest.guaranteed && contest.guaranteed_pool > pool.prize_pool
      ? round2(contest.guaranteed_pool)
      : pool.prize_pool;
    ranked = allocatePrizes(scored, contest.prize_breakup || [], prizePool);
  }

  return {
    ok: true,
    contest: {
      id: contest.id, name: contest.name, format: contest.format, status: contest.status,
      entry_fee: contest.entry_fee, entrants: entries.length, max_entrants: contest.max_entrants,
      prize_pool: prizePool, prize_breakup: contest.prize_breakup
    },
    lineups_visible: matchEnded,
    rows: ranked.map(e => ({
      rank: e.rank,
      // The account is never exposed; the display name is what a viewer sees. For every real entry
      // these are the same string, which is precisely what makes the house entry unremarkable.
      username: e.display_name || e.username,
      team_name: e.team_name,
      points: round2(e.points),
      prize: round2(e.prize),
      is_you: viewer != null && e.username === viewer,
      // Own team always; everyone else's only once the match is over. Uniform across every entrant,
      // which is what stops the house entry being the one row that cannot be opened.
      players: matchEnded || (viewer != null && e.username === viewer)
        ? { players: e.players, captain: e.captain, vice_captain: e.vice_captain }
        : null
    }))
  };
}

module.exports = {
  // pure — no database, no server
  round2,
  floor2,
  validatePrizeBreakup,
  pctForRank,
  computePool,
  validateLineup,
  substituteExcludedPlayers,
  rankEntries,
  allocatePrizes,
  // lifecycle
  createContest,
  joinContest,
  lockContestsForFixture,
  voidContest,
  settleContest,
  settleFixture,
  leaderboard,
  scoreEntries,
  // constants
  TX_ENTRY_FEE,
  TX_PAYOUT,
  TX_REFUND,
  FREE_FORMATS
};
