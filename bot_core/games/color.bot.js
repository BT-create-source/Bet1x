/**
 * Moved verbatim from backend/server.js. settleColorRound() (which decides manual overrides, calls
 * this, and applies the fair-random fallback) stays in backend/server.js — this file only holds the
 * pure "which outcome maximizes admin profit" calculation.
 *
 * `resolveColorNumber` (number -> {color, dotClass, size}) is core game-rule logic used by the fair
 * path too, not bot logic, so it stays in server.js and is injected here via deps rather than
 * duplicated.
 *
 * Payout multipliers (2026-09-20): Colour/Big-Small = 1.96x, Number = 8.96x, uniformly across every
 * color including Violet — matches the real per-bet settlement in server.js's settleColorRound()
 * exactly, which is what this advisory calculation must always agree with for the "maximum profit"
 * outcome it picks to actually be the maximum-profit one.
 */
const deps = require('../deps');

// Calculate the exact optimal outcome for Admin profit across all numbers (0-9)
// `targetedUsernames`, when provided, scopes the profit/payout calculation to ONLY that subset of
// bettors (the bot's currently-targeted live players) — the returned best_number/max_profit then
// reflects the number that maximizes admin profit against just that subset, not the whole room.
// Omitting it (existing behavior, used by every manual-override call site) is unaffected.
function calculateColorOptimalOutcome(bets, roundSeed, targetedUsernames) {
  const resolveColorNumber = deps.get().resolveColorNumber;
  const roundBets = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scopedBets = targeted ? roundBets.filter(b => targeted.has(String(b.username || '').toLowerCase())) : roundBets;
  const totalVolume = roundBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
  const scopedVolume = scopedBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);

  const outcomes = [];
  for (let n = 0; n <= 9; n++) {
    const resolved = resolveColorNumber(n);
    let playerPayout = 0;

    for (const b of scopedBets) {
      const amt = parseFloat(b.amount) || 0;
      if (b.category === 'color') {
        if (b.value === resolved.color) {
          playerPayout += amt * 1.96;
        }
      } else if (b.category === 'number') {
        if (parseInt(b.value) === n) {
          playerPayout += amt * 8.96;
        }
      } else if (b.category === 'size') {
        if (b.value === resolved.size) {
          playerPayout += amt * 1.96;
        }
      }
    }

    const adminProfit = scopedVolume - playerPayout;
    outcomes.push({
      number: n,
      color: resolved.color,
      dotClass: resolved.dotClass,
      size: resolved.size,
      playerPayout: parseFloat(playerPayout.toFixed(2)),
      adminProfit: parseFloat(adminProfit.toFixed(2))
    });
  }

  // Find max and min profit
  const maxProfit = Math.max(...outcomes.map(o => o.adminProfit));
  const minProfit = Math.min(...outcomes.map(o => o.adminProfit));

  const bestCandidates = outcomes.filter(o => o.adminProfit === maxProfit);
  const worstCandidates = outcomes.filter(o => o.adminProfit === minProfit);

  // Pick deterministically among equally profitable choices using roundSeed
  const roundSeedNum = parseInt(String(roundSeed || '').slice(-5)) || 0;
  const best = bestCandidates[roundSeedNum % bestCandidates.length] || bestCandidates[0];
  const worst = worstCandidates[0] || outcomes[0];

  return {
    total_volume: parseFloat(totalVolume.toFixed(2)),
    total_bets_count: roundBets.length,
    scoped_volume: parseFloat(scopedVolume.toFixed(2)),
    scoped_bets_count: scopedBets.length,
    best_number: best.number,
    best_color: best.color,
    best_size: best.size,
    max_profit: best.adminProfit,
    min_payout: best.playerPayout,
    worst_number: worst.number,
    worst_loss: worst.playerPayout,
    outcomes: outcomes // Index 0..9 for fast lookup
  };
}

module.exports = { calculateColorOptimalOutcome };
