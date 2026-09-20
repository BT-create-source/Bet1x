/**
 * Moved verbatim from backend/server.js's "Live Active-User Tracking & Percentage-Based Targeting
 * Engine" and "Live Instance Tracking & Percentage-Based Instance Targeting" sections.
 *
 * Generalizes the Mines MINES_USER_SESSIONS/target_users precedent into a single, continuous,
 * server-side mechanism that works for every game: whenever the bot is enabled at profit_pct X% for
 * a game, a randomly-sampled X%-of-currently-live-users subset is kept fresh on a timer — entirely
 * server side, so it keeps running even if the admin panel is never opened / gets closed.
 */
const { shuffle } = require('./shuffle');
const { isBotTakeoverActive } = require('./takeover-state');

const LIVE_USERS = {
  color_guess: {},
  aviator: {},
  teenpatti: {},
  mines: {}
};
const LIVE_USER_TTL_MS = 45000; // a user drops out of "currently active" if not refreshed within 45s

function markUserActive(gameKey, username) {
  if (!username || typeof username !== 'string') return; // anonymous viewers are not "live players"
  if (!username || !LIVE_USERS[gameKey]) return;
  const key = String(username);
  const bucket = LIVE_USERS[gameKey];
  const wasLive = bucket[key] !== undefined && (Date.now() - bucket[key]) <= LIVE_USER_TTL_MS;
  bucket[key] = Date.now();

  // A player who has just arrived must become eligible for selection immediately, not whenever the
  // 4-second timer next happens to fire. Load testing made the cost of waiting obvious: 25 players
  // started Mines boards inside 431ms, the timer had not run since they became live, so the targeted
  // subset was still empty and NONE of them were rigged — a bot configured at 90% delivered 0%.
  // Any session shorter than one timer tick was previously never rigged at all.
  //
  // Only on genuine arrival, not on every heartbeat: this is called from polling endpoints several
  // times a second per player, and re-sampling that often would be pure waste.
  if (!wasLive) refreshBotTargeting(gameKey);
}

function getLiveUsernames(gameKey) {
  const bucket = LIVE_USERS[gameKey];
  if (!bucket) return [];
  const now = Date.now();
  return Object.keys(bucket).filter(u => (now - bucket[u]) <= LIVE_USER_TTL_MS);
}

// The current server-computed targeted subset per game, refreshed continuously by the interval below.
const botTargetedUsers = {
  color_guess: [],
  aviator: [],
  teenpatti: [],
  mines: []
};

function refreshBotTargeting(gameKey) {
  if (!LIVE_USERS[gameKey]) return;
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) { botTargetedUsers[gameKey] = []; return; }
  const live = getLiveUsernames(gameKey);
  if (live.length === 0) { botTargetedUsers[gameKey] = []; return; }
  const pct = bot.profit_pct || 90;
  const count = pct >= 100 ? live.length : Math.max(1, Math.min(live.length, Math.round((pct / 100) * live.length)));

  // Keep whoever is still live and still selected, then top up from the rest at random. Re-drawing
  // the whole subset from scratch on every pass used to mean a player could be targeted for one
  // reveal and untargeted for the next within a single Mines board, and now that arrivals also
  // trigger a refresh, a busy room would reshuffle constantly. The proportion is identical either
  // way; this just stops it thrashing.
  //
  // Note this stickiness is safe for PLAYERS but was not for TABLES: a per-player subset is
  // re-sampled as players come and go, whereas a small set of long-lived tables would have pinned
  // the same tables for ever. Teen Patti therefore uses a per-table ledger instead of this engine.
  const previous = (botTargetedUsers[gameKey] || []).filter(u => live.includes(u));
  const keep = previous.slice(0, count);
  const remaining = shuffle(live.filter(u => !keep.includes(u)));
  botTargetedUsers[gameKey] = keep.concat(remaining.slice(0, count - keep.length));
}

function isUserTargeted(gameKey, username) {
  if (!username || !botTargetedUsers[gameKey]) return false;
  const lower = String(username).toLowerCase();
  return botTargetedUsers[gameKey].some(u => u.toLowerCase() === lower);
}

// --- Live Instance Tracking & Percentage-Based Instance Targeting -------------------------------
//
// The engine above samples X% of live *players*. For a game whose concurrent unit is a table rather
// than a player that is the wrong denominator: Teen Patti runs six rooms at once, and "50%" is meant
// to mean three of those six tables are the house's, not "half the people somewhere across all six".
//
// This is deliberately the ONLY rig decision for such a game — it replaces the per-round bag draw for
// Teen Patti rather than stacking on top of it. That distinction matters and is not stylistic: an
// earlier version of this file ran a separate "arm N of 6 rooms" pass *alongside* the per-round
// decision, and the two mechanisms multiplied instead of agreeing, which is exactly how a configured
// 50% turned into a reported "8 of 10 games". One ledger, one percentage.
//
// A table only counts as live once a real person is sitting at it. Rigging a table occupied purely
// by NPCs moves no money, and counting those tables in the denominator would silently dilute the
// percentage the operator asked for.
const LIVE_INSTANCES = { teenpatti: {} };
const LIVE_INSTANCE_TTL_MS = 45000;

function markInstanceActive(gameKey, instanceId) {
  if (!instanceId || !LIVE_INSTANCES[gameKey]) return;
  LIVE_INSTANCES[gameKey][String(instanceId)] = Date.now();
}

function getLiveInstances(gameKey) {
  const bucket = LIVE_INSTANCES[gameKey];
  if (!bucket) return [];
  const now = Date.now();
  return Object.keys(bucket).filter(id => (now - bucket[id]) <= LIVE_INSTANCE_TTL_MS);
}

// Keep every game's targeted subset fresh continuously, regardless of whether admin.html is open.
setInterval(() => {
  Object.keys(LIVE_USERS).forEach(gameKey => refreshBotTargeting(gameKey));
}, 4000);

module.exports = {
  LIVE_USERS,
  LIVE_USER_TTL_MS,
  markUserActive,
  getLiveUsernames,
  botTargetedUsers,
  refreshBotTargeting,
  isUserTargeted,
  LIVE_INSTANCES,
  LIVE_INSTANCE_TTL_MS,
  markInstanceActive,
  getLiveInstances
};
