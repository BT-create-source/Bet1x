/**
 * Copy this file to bot_core/games/<yourgame>.bot.js when adding bot support for a new game.
 *
 * What belongs in here:
 *   - Pure decision/calculation functions: "which outcome maximizes house profit", "should this
 *     round/reveal/hand be rigged", "what should the crash point / target tile / winning seat be".
 *   - Nothing here may write to the database, mutate wallets, deal cards, or run a game's tick loop.
 *     Those stay in the game's own file (e.g. backend/server.js) — that file only *calls* this one
 *     for a decision, then applies it using its own existing settlement/orchestration code.
 *
 * What you get for free from bot_core/engine (require them directly, same as every other game):
 *   - shouldBotRigThisRound(gameKey, ledgerKey) — the shared bucketed rig-decision bag. Give your
 *     game a `ledgerKey` (e.g. `<gameKey>:<roomOrTableId>`) if it runs several concurrent
 *     instances/clocks, like Colour Prediction's four rooms — otherwise omit it.
 *   - isBotTakeoverActive(gameKey) — reads the operator's enabled/profit_pct config for your game.
 *     Register your game's default config in bot_core/engine/takeover-state.js's
 *     `botTakeoverState` object (enabled:false, profit_pct:90) — an unregistered key is NEVER
 *     active, which is the guarantee that a game with no rig path (like Boundary Baazi) stays that
 *     way. Only add a key here if the game is genuinely meant to have a rig path.
 *   - markUserActive / getLiveUsernames / isUserTargeted / refreshBotTargeting (engine/targeting.js)
 *     — if your game's concurrency unit is "one board/session per player", register it in
 *     LIVE_USERS and botTargetedUsers there and this gives you the same X%-of-live-players
 *     targeting Mines uses, for free.
 *   - markInstanceActive / getLiveInstances (engine/targeting.js) — if your game's concurrency unit
 *     is a table/room instead (like Teen Patti), register it in LIVE_INSTANCES instead.
 *   - randomFillerName() / nextRoomFillerUsername() (engine/filler-names.js) — realistic-looking
 *     names for any simulated/filler participant your game needs.
 *
 * Anything your decision function needs that lives outside bot_core (a Prisma client, a core
 * game-rule function like resolveColorNumber, a tunable config value) must be injected via
 * bot_core/deps.js at boot — see color.bot.js's use of `deps.get().resolveColorNumber` and
 * aviator.bot.js's use of `deps.get().config.AVIATOR_HIGH_STAKE_REF` for the pattern. Never
 * `require()` anything from backend/ directly from inside bot_core/ — that is what keeps this
 * folder extractable as an independent package or microservice later.
 *
 * Finally, add every new export to bot_core/index.js's require + module.exports list so the rest of
 * the app can keep importing everything from one place: `const botCore = require('../bot_core')`.
 */
// const deps = require('../deps');
// const { shouldBotRigThisRound } = require('../engine/decide');

function calculateYourGameOptimalOutcome(/* bets, ...whatever your game needs */) {
  throw new Error('TEMPLATE.bot.js is a boilerplate — implement this for your game and delete this line.');
}

module.exports = { calculateYourGameOptimalOutcome };
