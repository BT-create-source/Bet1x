/**
 * Moved verbatim from backend/server.js's "Central AI Bot Takeover In-Memory State & DB Sync"
 * section. `loadTakeoverStateFromDb` is only the DB-key-loading loop — the original
 * `initBotTakeoverState` also evicted stale Teen Patti "Admin" seats afterward, which is core Teen
 * Patti seat/game-state logic, not bot logic, and stays in server.js as a thin wrapper that calls
 * this and then does that cleanup itself (see server.js's own `initBotTakeoverState`).
 */
const deps = require('../deps');

const botTakeoverState = {
  global: { enabled: false, profit_pct: 90 },
  color_guess: { enabled: false, profit_pct: 90 },
  aviator: { enabled: false, profit_pct: 90 },
  teenpatti: { enabled: false, profit_pct: 90 },
  mines: { enabled: false, profit_pct: 90 },
  // Your 11's percentage counts CONTESTS, drawn per match (docs/YOUR11-SCOPE.md section 4). There is
  // deliberately no `boundary` key: Boundary Baazi resolves from the ball event log and nothing
  // else, and test_cricket.js asserts positively that no rig path for it exists.
  youreleven: { enabled: false, profit_pct: 90 }
};

async function loadTakeoverStateFromDb() {
  const keys = Object.keys(botTakeoverState);
  for (const k of keys) {
    const record = await deps.get().prisma.gameState.findUnique({ where: { key: `bot_takeover_${k}` } });
    if (record && record.data) {
      botTakeoverState[k] = { ...botTakeoverState[k], ...record.data };
    }
  }
}

function isBotTakeoverActive(gameKey) {
  const gameConf = botTakeoverState[gameKey];

  // An unregistered key is never active, not even under the global master switch.
  //
  // Every real game is pre-initialised in botTakeoverState with an explicit enabled:true/false, so
  // this costs nothing for any of them — the per-game branches below always short-circuit first.
  // What it stops is a key that is NOT a game being treated as one: `/api/bot_status/:gameKey` and
  // `/api/bot_decide/:gameKey` take the key straight from the URL, so before this, a typo or an
  // invented name reported `active: true` whenever the global switch was on, and would have drawn
  // real decisions out of a bag created on the spot for it.
  //
  // It is also the guarantee that Boundary Baazi has no rig path: that game deliberately has no key
  // here, and adding one has to be a deliberate act rather than something the global switch confers.
  // test_rigging.js asserts this positively.
  if (!gameConf) {
    return { active: false, profit_pct: 0, source: 'none' };
  }

  if (gameConf.enabled) {
    return { active: true, profit_pct: gameConf.profit_pct || 90, source: 'game' };
  }
  if (gameConf.enabled === false) {
    // If the game was explicitly turned off by the admin, respect that!
    return { active: false, profit_pct: gameConf.profit_pct || 90, source: 'none' };
  }
  if (botTakeoverState.global && botTakeoverState.global.enabled) {
    const pct = (gameConf && gameConf.profit_pct) ? gameConf.profit_pct : (botTakeoverState.global.profit_pct || 90);
    return { active: true, profit_pct: pct, source: 'global' };
  }
  return { active: false, profit_pct: (gameConf && gameConf.profit_pct) || 90, source: 'none' };
}

module.exports = { botTakeoverState, loadTakeoverStateFromDb, isBotTakeoverActive };
