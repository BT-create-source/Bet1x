/**
 * Mines had no dedicated rig-calculation function in backend/server.js — its /api/mines/reveal
 * handler simply combined isBotTakeoverActive('mines') and isUserTargeted('mines', username) inline.
 * This wraps that exact same expression in one named decision, matching the single-call pattern
 * every other game already gets from shouldBotRigThisRound: being selected by the live-targeting
 * engine (see engine/targeting.js) already IS the rig decision here, with no separate probability
 * roll layered on top, exactly like Color/Aviator/Teen Patti never roll twice either.
 *
 * The manual MINES_RIG_CONFIG override check, the actual reveal/bust logic, and every wallet/DB
 * write stay in backend/server.js — this only decides the boolean.
 */
const { isBotTakeoverActive } = require('../engine/takeover-state');
const { isUserTargeted } = require('../engine/targeting');

function shouldRigMinesReveal(username) {
  return isBotTakeoverActive('mines').active && isUserTargeted('mines', username);
}

module.exports = { shouldRigMinesReveal };
