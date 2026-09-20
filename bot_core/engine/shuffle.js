/**
 * Generic Fisher-Yates shuffle, private to bot_core's engine.
 *
 * backend/server.js has its own `tpShuffle` that shuffles the real Teen Patti playing-card deck —
 * that one stays in server.js (card-dealing is game orchestration, not bot logic) and this one is
 * a separate copy used only for shuffling rig-bag slots and the live-targeting subset. Keeping two
 * copies of a standard, dependency-free algorithm carries no drift risk (there is no game-specific
 * behaviour to keep in sync) and avoids bot_core depending on card-dealing code, or vice versa.
 */
function shuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

module.exports = { shuffle };
