/**
 * bot_core — the single import point for every bot/rig/house-edge mechanism in bet1x.
 *
 * Everything here was moved out of backend/server.js. Game loops, physics, card-dealing
 * orchestration, and every direct DB/wallet write stay in their original files (backend/server.js,
 * backend/lib/cricket/*) — those files call into bot_core only for a decision (a boolean, a number,
 * an outcome), then apply it themselves with their own existing settlement code. bot_core itself
 * never touches a database or a wallet directly; the one exception (persisting the rig-decision bag
 * / takeover config for restart-durability) goes through a Prisma client handed in via init(), never
 * one bot_core creates itself.
 *
 * Usage (see backend/server.js):
 *   const botCore = require('../bot_core');
 *   botCore.init({ prisma, resolveColorNumber, config: { AVIATOR_HIGH_STAKE_REF } });
 *   const { isBotTakeoverActive, shouldBotRigThisRound, ... } = botCore;
 *
 * `_houseEdgeInternals` in server.js re-exports a subset of these names for backend/test_rigging.js
 * — every one of those names is exported here under the identical name, so that block needed no
 * changes at all.
 */
const deps = require('./deps');

const rigBag = require('./engine/rig-bag');
const takeoverState = require('./engine/takeover-state');
const targeting = require('./engine/targeting');
const decide = require('./engine/decide');
const fillerNames = require('./engine/filler-names');

const aviatorBot = require('./games/aviator.bot');
const colorBot = require('./games/color.bot');
const minesBot = require('./games/mines.bot');
const cricketBot = require('./games/cricket.bot');

/**
 * Wires in everything bot_core needs from the host app and resumes any in-progress rig-bag cycles
 * from the database (fire-and-forget, matching the original code's startup behaviour — a couple of
 * seconds of lag before the restored bags land is harmless, and nothing blocks on it).
 *
 * `loadTakeoverStateFromDb()` (the operator's enabled/profit_pct config) is intentionally NOT called
 * here — server.js calls it explicitly itself, immediately followed by its own Teen Patti stale-seat
 * cleanup that must run in the same sequence. See server.js's own `initBotTakeoverState`.
 */
function init({ prisma, resolveColorNumber, config } = {}) {
  deps.init({ prisma, resolveColorNumber, config: config || {} });
  rigBag.loadBotRigBags();
}

module.exports = {
  init,
  loadTakeoverStateFromDb: takeoverState.loadTakeoverStateFromDb,

  // engine/takeover-state
  botTakeoverState: takeoverState.botTakeoverState,
  isBotTakeoverActive: takeoverState.isBotTakeoverActive,

  // engine/rig-bag
  botRigBags: rigBag.botRigBags,
  ensureBotRigBag: rigBag.ensureBotRigBag,
  buildBotRigBag: rigBag.buildBotRigBag,
  persistBotRigBag: rigBag.persistBotRigBag,
  loadBotRigBags: rigBag.loadBotRigBags,
  BOT_RIG_BUCKETS: rigBag.BOT_RIG_BUCKETS,
  BOT_RIG_BUCKET_SIZE: rigBag.BOT_RIG_BUCKET_SIZE,
  COLOR_ROOMS: rigBag.COLOR_ROOMS,
  TP_ROOM_IDS: rigBag.TP_ROOM_IDS,
  BOT_RIG_LEDGER_KEYS: rigBag.BOT_RIG_LEDGER_KEYS,

  // engine/targeting
  LIVE_USERS: targeting.LIVE_USERS,
  LIVE_USER_TTL_MS: targeting.LIVE_USER_TTL_MS,
  markUserActive: targeting.markUserActive,
  getLiveUsernames: targeting.getLiveUsernames,
  botTargetedUsers: targeting.botTargetedUsers,
  refreshBotTargeting: targeting.refreshBotTargeting,
  isUserTargeted: targeting.isUserTargeted,
  LIVE_INSTANCES: targeting.LIVE_INSTANCES,
  LIVE_INSTANCE_TTL_MS: targeting.LIVE_INSTANCE_TTL_MS,
  markInstanceActive: targeting.markInstanceActive,
  getLiveInstances: targeting.getLiveInstances,

  // engine/decide
  shouldBotRigThisRound: decide.shouldBotRigThisRound,

  // engine/filler-names
  TP_SIMULATED_NAMES: fillerNames.TP_SIMULATED_NAMES,
  randomFillerName: fillerNames.randomFillerName,
  nextRoomFillerUsername: fillerNames.nextRoomFillerUsername,

  // games/aviator.bot
  AVIATOR_CRASH_AGGRESSIVE: aviatorBot.AVIATOR_CRASH_AGGRESSIVE,
  AVIATOR_CRASH_RELAXED: aviatorBot.AVIATOR_CRASH_RELAXED,
  AVIATOR_CRASH_FLOOR: aviatorBot.AVIATOR_CRASH_FLOOR,
  pickAviatorCrashPoint: aviatorBot.pickAviatorCrashPoint,
  calculateAviatorLiveProfit: aviatorBot.calculateAviatorLiveProfit,
  aviatorShouldCrashNow: aviatorBot.aviatorShouldCrashNow,

  // games/color.bot
  calculateColorOptimalOutcome: colorBot.calculateColorOptimalOutcome,

  // games/mines.bot
  shouldRigMinesReveal: minesBot.shouldRigMinesReveal,

  // games/cricket.bot
  createCricketHouseEdgeAdapter: cricketBot.createCricketHouseEdgeAdapter
};
