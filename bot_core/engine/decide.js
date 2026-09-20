/**
 * Moved verbatim from backend/server.js. The single entry point every game calls once per
 * round/match/session to get a rig decision.
 */
const { isBotTakeoverActive } = require('./takeover-state');
const { ensureBotRigBag, persistBotRigBag } = require('./rig-bag');

/**
 * Call this once per round/match/session for the given game.
 * Returns { shouldRig: boolean, profit_pct: number, active: boolean, source: string }
 *
 * `ledgerKey` optionally splits the 100-slot cycle into independent sub-ledgers while keeping a
 * single shared on/off/percentage config. Colour Prediction needs this: its four rooms run on
 * different clocks (30s / 60s / 180s / 300s), so a single shared cycle let the fast room burn through
 * most of the rigged slots before the slow room had settled a handful of rounds — each room was
 * nominally at the configured percentage but none of them actually was. One ledger per room makes
 * every room exact on its own. Omitting it keeps the original single-cycle behaviour for every
 * existing caller.
 */
function shouldBotRigThisRound(gameKey, ledgerKey) {
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) {
    return { shouldRig: false, profit_pct: bot.profit_pct, active: false, source: 'none' };
  }

  const pct = bot.profit_pct || 90;
  const bag = ensureBotRigBag(ledgerKey || gameKey, pct);
  const shouldRig = bag.queue.pop();

  // The bag itself is the memory: totals for diagnostics, and lastRiggedAt records exactly when the
  // house last entered a room / changed an outcome for this game, which is what /api/bot_status
  // surfaces to the operator.
  bag.totalDecisions++;
  bag.lastDecisionAt = Date.now();
  if (shouldRig) { bag.totalRigged++; bag.lastRiggedAt = Date.now(); }
  persistBotRigBag(ledgerKey || gameKey); // must match the bag that was actually drawn from

  return { shouldRig, profit_pct: pct, active: true, source: bot.source };
}

module.exports = { shouldBotRigThisRound };
