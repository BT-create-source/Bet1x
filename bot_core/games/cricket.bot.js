/**
 * Your 11 (backend/lib/cricket/house-entry.js) never reaches into server.js or bot_core directly —
 * it receives a `houseEdge` adapter object via backend/lib/cricket/context.js's init(), exactly the
 * dependency-injection pattern bot_core itself uses (see bot_core/deps.js). This just assembles that
 * adapter from the same shared primitives every other game uses, so Your 11 draws from the identical
 * rig-decision bag and filler-name generator rather than a second mechanism that could drift.
 */
const { shouldBotRigThisRound } = require('../engine/decide');
const { randomFillerName } = require('../engine/filler-names');

function createCricketHouseEdgeAdapter({ account } = {}) {
  return {
    shouldRig: shouldBotRigThisRound,
    fillerName: randomFillerName,
    account: account || null
  };
}

module.exports = { createCricketHouseEdgeAdapter };
