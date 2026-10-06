/**
 * Moved verbatim from backend/server.js's "Memory-Tracked Bucketed Bot Decision Engine" section.
 *
 * v1 of this was a plain running counter: `shouldRig = (counter % 100) < pct`. That handed out the
 * first `pct` calls out of every 100 all true in a solid unbroken row, then the rest all false — an
 * operator watching soon after enabling the bot saw a long deterministic streak, not a coin flip.
 *
 * v2 fixed the streak by shuffling a 100-slot bag (pct true, the rest false) instead of counting
 * through it in order. That is exact over every complete 100-draw cycle, but a genuinely independent
 * shuffle can still cluster locally — nothing stops 8 of the first 10 slots in a random permutation
 * of 50 true/50 false from landing true purely by chance (measured: ~4.6% of 10-round windows did,
 * almost as bad as a plain 50% coin flip). That is exactly what was reported next: "10 games, 8 won
 * by admin, at 50%."
 *
 * v3 (this one) keeps every 100-slot cycle exact — for ANY integer percentage, not just multiples of
 * ten, with no rounding drift ever — while also keeping every 10-round window close to the
 * configured ratio. It splits the 100 slots into 10 buckets of 10, hands each bucket
 * floor((i+1)*pct/10) - floor(i*pct/10) true slots (the standard "spread K items across N buckets as
 * evenly as possible" formula — every bucket gets within one of every other bucket, and the ten
 * bucket counts always sum to exactly `pct`), shuffles the true/false slots *within* each bucket for
 * genuine per-round unpredictability, then shuffles the *order the buckets are drawn in* so which
 * bucket comes first isn't fixed either. Measured improvement at 50%: the chance of an 8-or-worse
 * 10-round window drops from ~4.6% to ~0.5% — the same 100 draws are still exactly 50/50 rigged, but
 * no longer clumped.
 *
 * The in-progress bag doubles as the "memory" this is asking for: it is what decides whether the
 * next match should be rigged, it is exactly what determines when the house last entered a room
 * (lastRiggedAt below), and it is persisted per game (bot_rig_bag_<gameKey> in GameState) so a
 * restart resumes the current cycle instead of silently starting a fresh one.
 */
const deps = require('../deps');
const { shuffle } = require('./shuffle');

const BOT_RIG_BUCKETS = 10;
const BOT_RIG_BUCKET_SIZE = 10; // BOT_RIG_BUCKETS * BOT_RIG_BUCKET_SIZE must stay 100

const botRigBags = {
  color_guess: null,
  aviator: null,
  teenpatti: null,
  mines: null
};

function buildBotRigBag(pct) {
  const buckets = [];
  for (let i = 0; i < BOT_RIG_BUCKETS; i++) {
    const trueCount = Math.floor((i + 1) * pct / BOT_RIG_BUCKETS) - Math.floor(i * pct / BOT_RIG_BUCKETS);
    const slots = [];
    for (let j = 0; j < BOT_RIG_BUCKET_SIZE; j++) slots.push(j < trueCount);
    buckets.push(shuffle(slots));
  }
  const queue = [].concat(...shuffle(buckets));
  return {
    pct,
    queue,
    totalDecisions: 0,
    totalRigged: 0,
    lastDecisionAt: null,
    lastRiggedAt: null
  };
}

// Builds (or reuses) the bag for `gameKey` at the given percentage, WITHOUT drawing from it. Shared
// by the real decision (which then draws) and the status-peek endpoint (which only reads the next
// slot), so both agree on exactly the same cycle. A changed percentage starts a fresh cycle rather
// than finishing out the old one at the old ratio.
function ensureBotRigBag(gameKey, pct) {
  let bag = botRigBags[gameKey];
  if (!bag || bag.pct !== pct || bag.queue.length === 0) {
    const carryOver = bag && bag.pct === pct ? bag : null; // exhausted cycle at the same pct: keep the running totals
    bag = buildBotRigBag(pct);
    if (carryOver) {
      bag.totalDecisions = carryOver.totalDecisions;
      bag.totalRigged = carryOver.totalRigged;
      bag.lastDecisionAt = carryOver.lastDecisionAt;
      bag.lastRiggedAt = carryOver.lastRiggedAt;
    }
    botRigBags[gameKey] = bag;
  }
  return bag;
}

let botRigBagSaveQueued = {};
function persistBotRigBag(gameKey) {
  // Debounced: a busy room can draw several decisions a second, and every draw does not need its own
  // database round trip. The in-memory copy is already authoritative moment to moment — this only
  // needs to survive a restart, so a couple of seconds of lag on the saved copy is harmless.
  if (botRigBagSaveQueued[gameKey]) return;
  botRigBagSaveQueued[gameKey] = true;
  setTimeout(async () => {
    botRigBagSaveQueued[gameKey] = false;
    const bag = botRigBags[gameKey];
    if (!bag) return;
    try {
      await deps.get().prisma.gameState.upsert({
        where: { key: `bot_rig_bag_${gameKey}` },
        update: { data: bag },
        create: { key: `bot_rig_bag_${gameKey}`, data: bag }
      });
    } catch (e) { /* best-effort persistence; the in-memory bag stays authoritative either way */ }
  }, 2000);
}

// Colour Prediction and Teen Patti both keep one cycle per room/table (see shouldBotRigThisRound's
// ledgerKey). Those ledgers are created on demand, so they have to be named explicitly here to be
// restored after a restart — iterating botRigBags alone would only ever find the game-level keys.
const COLOR_ROOMS = ['sapre', 'becone', 'emred', 'vip'];
const TP_ROOM_IDS = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
const BOT_RIG_LEDGER_KEYS = Object.keys(botRigBags)
  .concat(COLOR_ROOMS.map(r => `color_guess:${r}`))
  .concat(TP_ROOM_IDS.map(r => `teenpatti:${r}`));

async function loadBotRigBags() {
  for (const gameKey of BOT_RIG_LEDGER_KEYS) {
    try {
      const record = await deps.get().prisma.gameState.findUnique({ where: { key: `bot_rig_bag_${gameKey}` } });
      if (record && record.data && Array.isArray(record.data.queue)) {
        botRigBags[gameKey] = record.data;
      }
    } catch (e) { /* a fresh bag on next draw is a safe fallback */ }
  }
}

module.exports = {
  BOT_RIG_BUCKETS,
  BOT_RIG_BUCKET_SIZE,
  botRigBags,
  buildBotRigBag,
  ensureBotRigBag,
  persistBotRigBag,
  COLOR_ROOMS,
  TP_ROOM_IDS,
  BOT_RIG_LEDGER_KEYS,
  loadBotRigBags
};
