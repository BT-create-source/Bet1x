/**
 * Editable cricket configuration.
 *
 * Ground rule 6 of the build brief: everything score- or money-related is config, not a constant.
 * Point values, credit weights, rake and the house-entry trajectory all need tuning against real
 * matches, so none of them may be a literal buried in a function.
 *
 * Storage reuses the existing `GameState` table (a `key` plus a JSON `data` blob), which is already
 * this codebase's idiom for per-game configuration — the colour rooms and the bot-takeover config
 * both live there. No new table, and the admin console can edit these the same way it edits
 * everything else.
 *
 * ---------------------------------------------------------------------------------------------
 * EVERY DEFAULT BELOW IS A PLACEHOLDER AWAITING CLIENT SIGN-OFF.
 *
 * The numbers are conventional T20 fantasy values, chosen to be plausible rather than final. They
 * are business logic, not a technical detail, and the brief is explicit that they must be confirmed
 * before launch. `needsSignoff()` reports which sections are still running on unreviewed defaults,
 * and the admin console should show that plainly rather than letting it be forgotten.
 * ---------------------------------------------------------------------------------------------
 */

const context = require('./context');

const KEYS = {
  scoring: 'cricket:scoring_rules',
  credits: 'cricket:credit_weights',
  contest: 'cricket:contest_defaults',
  house: 'cricket:house_trajectory',
  boundary: 'cricket:boundary_defaults'
};

/**
 * Fantasy scoring, per match format.
 *
 * `_signed_off: false` marks a section as still carrying placeholder values. Editing a section
 * through the admin console is what flips it true — that is the sign-off record.
 */
const DEFAULT_SCORING = {
  _signed_off: false,
  _note: 'PLACEHOLDER VALUES - require client sign-off before launch (build brief section 6)',
  T20: {
    // batting
    run: 1,
    four_bonus: 1,
    six_bonus: 2,
    runs_25_bonus: 4,
    runs_50_bonus: 8,
    runs_100_bonus: 16,
    duck_penalty: -2,
    // A bowler is not penalised for a duck in most rule sets. Which roles are exempt is config,
    // because it is precisely the sort of thing a product owner changes their mind about.
    duck_exempt_roles: ['BOWL'],

    // Do milestone and wicket-haul bonuses stack, or does only the highest reached apply? Rule sets
    // genuinely differ, and the difference is worth ~20 points on a century — far too large to be
    // an implementation detail. Default: highest only.
    milestones_cumulative: false,
    hauls_cumulative: false,

    // bowling
    wicket: 25,
    wickets_3_bonus: 4,
    wickets_4_bonus: 8,
    wickets_5_bonus: 16,
    maiden_over: 12,

    // fielding
    catch: 8,
    catches_3_bonus: 4,
    stumping: 12,
    run_out_direct: 12,
    run_out_assisted: 6,

    // participation
    in_playing_xi: 4,

    // Strike rate applies only once a batsman has faced enough balls for it to mean anything —
    // otherwise a first-ball six would score the top bonus.
    strike_rate: {
      min_balls: 10,
      bands: [
        { above: 170, points: 6 },
        { above: 150, points: 4 },
        { above: 130, points: 2 },
        { below: 50, points: -6 },
        { below: 60, points: -4 },
        { below: 70, points: -2 }
      ]
    },
    economy: {
      min_overs: 2,
      bands: [
        { below: 5, points: 6 },
        { below: 6, points: 4 },
        { below: 7, points: 2 },
        { above: 12, points: -6 },
        { above: 11, points: -4 },
        { above: 10, points: -2 }
      ]
    },

    captain_multiplier: 2,
    vice_captain_multiplier: 1.5
  }
};

/** Credit pricing. Recency-weighted form, clamped and rounded to the nearest 0.5. */
const DEFAULT_CREDITS = {
  _signed_off: false,
  _note: 'PLACEHOLDER VALUES - tune against real selection spread after the first tournament (brief section 9)',
  recent_matches: 5,
  weight_recent_form: 0.6,      // average fantasy points across the last N matches
  weight_tournament: 0.4,       // average across the current tournament

  // Fantasy points -> credits divisor. This value has to be set against the credit BAND, not picked
  // for feeling about right: credits = min + form/divisor, so the divisor is what decides which
  // form score reaches the ceiling. At 22, a form of ~100 lands on max_credits and the whole 7.0
  // to 11.5 range is actually used. Set it much lower and almost every established player clamps to
  // the ceiling, the credit budget stops constraining anything, and every team looks the same.
  points_per_credit: 22,
  min_credits: 7.0,
  max_credits: 11.5,
  default_credits: 8.0,         // unseen player with no cold-start data
  round_to: 0.5
};

/** Contest defaults. Fill-or-cancel is the approved default policy. */
const DEFAULT_CONTEST = {
  _signed_off: false,
  _note: 'PLACEHOLDER VALUES - require client sign-off before launch',
  rake_pct: 15,
  credit_budget: 100,
  squad_size: 11,
  max_per_real_team: 7,
  role_limits: {
    WK: { min: 1, max: 4 },
    BAT: { min: 3, max: 6 },
    AR: { min: 1, max: 4 },
    BOWL: { min: 3, max: 6 }
  },
  guaranteed_by_default: false, // false = fill-or-cancel
  min_entrants_default: 2
};

/**
 * House-entry trajectory (Your 11 only; Boundary Baazi has no equivalent and never will).
 *
 * See docs/YOUR11-SCOPE.md section 4. The entry entering low and finishing near — but never at —
 * the top is the whole behaviour, and every number governing it is tunable here.
 */
const DEFAULT_HOUSE = {
  _signed_off: false,
  _note: 'PLACEHOLDER VALUES - governs the leaderboard house entry (docs/YOUR11-SCOPE.md section 4)',
  entry_percentile_band: [60, 85],   // where it sits at lock: bottom quartile-ish
  finish_percentile_band: [2, 8],    // where it lands: near the top, never first
  never_rank_first: true,
  min_contest_entrants: 8,           // below this a house entry is too conspicuous
  excluded_formats: ['practice', 'private', 'h2h'],
  climb_easing: 'ease_in_out',
  plateau_chance: 0.25               // occasional flat stretches so the climb is not a straight line
};

/**
 * Boundary Baazi.
 *
 * The market is PARIMUTUEL, not fixed-odds, and that is a structural decision rather than a
 * preference: every stake on a delivery goes into one pool, the house takes `rake_pct`, and whoever
 * backed the actual outcome splits the rest in proportion to their stake. The house therefore has no
 * position on any outcome and nothing to gain from any particular ball — which is what
 * docs/YOUR11-SCOPE.md means by Boundary Baazi being structurally non-riggable. Fixed odds would
 * hand the house an exposure it might want to manage, and a reason to want to manage it.
 *
 * The options are deliberately mutually exclusive and exhaustive: every legal or illegal delivery
 * resolves to exactly one of them, so there is no ball the market cannot settle.
 */
const DEFAULT_BOUNDARY = {
  _signed_off: false,
  _note: 'PLACEHOLDER VALUES - require client sign-off before launch (build brief section 7)',
  rake_pct: 10,
  min_stake: 10,
  max_stake: 5000,
  // Resolution order matters and is applied top to bottom: a wicket off a no-ball is a wicket, and
  // a no-ball hit for six is an extra. Changing this order changes who wins, so it is config with
  // the options rather than a hidden precedence rule in code.
  options: [
    { key: 'wicket', label: 'Wicket' },
    { key: 'extra', label: 'Wide / No-ball' },
    { key: 'six', label: 'Six' },
    { key: 'four', label: 'Four' },
    { key: 'dot', label: 'Dot ball' },
    { key: 'runs', label: '1, 2 or 3 runs' }
  ],
  // What to do when a delivery resolves without the market ever having been locked, i.e. no
  // ball-start event arrived for it. 'void' refunds every stake in full. It is the default because
  // the alternative is settling bets that may have been placed after the ball was already bowled,
  // which is the exact scoring-integrity failure the event-gated lock exists to prevent.
  on_missing_lock: 'void'
};

const DEFAULTS = {
  [KEYS.scoring]: DEFAULT_SCORING,
  [KEYS.credits]: DEFAULT_CREDITS,
  [KEYS.contest]: DEFAULT_CONTEST,
  [KEYS.house]: DEFAULT_HOUSE,
  [KEYS.boundary]: DEFAULT_BOUNDARY
};

/** Short-lived cache so a per-ball recompute does not hit the database for the rules every time. */
const cache = new Map();
const CACHE_TTL_MS = 30000;

function deepMerge(base, override) {
  if (!override || typeof override !== 'object' || Array.isArray(override)) return override ?? base;
  const out = Array.isArray(base) ? base.slice() : { ...base };
  for (const [k, v] of Object.entries(override)) {
    out[k] = v && typeof v === 'object' && !Array.isArray(v) && base && typeof base[k] === 'object'
      ? deepMerge(base[k], v)
      : v;
  }
  return out;
}

/**
 * Read a config section, merged over its defaults.
 *
 * Merging rather than replacing matters: an operator who saves only `{ T20: { wicket: 30 } }` must
 * not silently wipe every other value in the section.
 */
async function get(key) {
  const cached = cache.get(key);
  if (cached && Date.now() < cached.expires) return cached.value;

  const { prisma, logger } = context.get();
  let value = DEFAULTS[key];

  try {
    const row = await prisma.gameState.findUnique({ where: { key } });
    if (row && row.data) value = deepMerge(DEFAULTS[key], row.data);
  } catch (e) {
    // Falling back to defaults is correct here: scoring must not stop because the config read
    // failed. It is logged so the fallback is never silent.
    logger.warn('cricket: config read failed, using defaults', { key, message: e.message });
  }

  cache.set(key, { value, expires: Date.now() + CACHE_TTL_MS });
  return value;
}

/** Persist a config section. Saving marks it signed off unless explicitly told otherwise. */
async function set(key, patch, { signedOff = true, updatedBy = null } = {}) {
  const { prisma } = context.get();
  const current = await get(key);
  const merged = deepMerge(current, patch);
  merged._signed_off = signedOff;
  merged._updated_by = updatedBy;
  merged._updated_at = new Date().toISOString();

  await prisma.gameState.upsert({
    where: { key },
    update: { data: merged },
    create: { key, data: merged }
  });
  cache.delete(key);
  return merged;
}

const scoring = () => get(KEYS.scoring);
const credits = () => get(KEYS.credits);
const contest = () => get(KEYS.contest);
const boundary = () => get(KEYS.boundary);
const house = () => get(KEYS.house);

/**
 * Which sections are still running on unreviewed placeholder values.
 *
 * Surfaced on the admin health endpoint so "we never signed off the scoring table" is visible
 * rather than something discovered after a match has already paid out.
 */
async function needsSignoff() {
  const pending = [];
  for (const [name, key] of Object.entries(KEYS)) {
    const value = await get(key);
    if (!value._signed_off) pending.push(name);
  }
  return pending;
}

/** Scoring rules for a format, falling back to T20 for anything not separately configured. */
async function scoringFor(format) {
  const all = await scoring();
  const key = String(format || 'T20').toUpperCase();
  return all[key] || all.T20;
}

function clearCache() {
  cache.clear();
}

module.exports = {
  KEYS,
  get,
  set,
  scoring,
  scoringFor,
  credits,
  contest,
  house,
  boundary,
  needsSignoff,
  clearCache,
  DEFAULT_SCORING,
  DEFAULT_CREDITS,
  DEFAULT_CONTEST,
  DEFAULT_HOUSE,
  DEFAULT_BOUNDARY
};
