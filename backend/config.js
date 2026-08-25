/**
 * Centralised, validated runtime configuration for the bet1x backend.
 *
 * Every tunable the server needs is resolved exactly once, here, so that no other module has to
 * reach into `process.env` and guess at defaults. In production the process refuses to boot when a
 * security-critical value is missing or is still set to a development placeholder — failing loudly
 * at startup is far safer than silently running a live money platform on a default secret.
 */

const path = require('path');
const crypto = require('crypto');

require('dotenv').config({ path: path.join(__dirname, '.env') });
require('dotenv').config(); // fall back to a repo-root .env if one exists

const NODE_ENV = process.env.NODE_ENV || 'development';
const IS_PRODUCTION = NODE_ENV === 'production';

const fatal = [];

function requireInProduction(name, value, hint) {
  if (IS_PRODUCTION && (value === undefined || value === null || value === '')) {
    fatal.push(`${name} is required when NODE_ENV=production. ${hint}`);
  }
  return value;
}

function bool(value, fallback) {
  if (value === undefined || value === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function num(value, fallback) {
  const parsed = parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function list(value) {
  if (!value) return [];
  return String(value)
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);
}

// --- Secrets -------------------------------------------------------------------------------------

// In development we derive a stable-per-boot secret so sessions survive page reloads but never
// escape the machine. In production a real, high-entropy APP_SECRET is mandatory: it is the only
// thing standing between a forged auth token and someone else's wallet.
let APP_SECRET = process.env.APP_SECRET || '';
if (!APP_SECRET) {
  requireInProduction(
    'APP_SECRET',
    '',
    'Generate one with:  node -e "console.log(require(\'crypto\').randomBytes(48).toString(\'hex\'))"'
  );
  APP_SECRET = crypto.randomBytes(48).toString('hex');
} else if (APP_SECRET.length < 32) {
  fatal.push('APP_SECRET must be at least 32 characters of high-entropy random data.');
}

// --- Admin credentials ---------------------------------------------------------------------------

// The admin console can flip house controls and move money, so it gets its own credential that is
// never stored in plaintext. ADMIN_PASSWORD_HASH is a bcrypt hash; ADMIN_PASSWORD (plaintext) is
// accepted for local development convenience only and is rejected outright in production.
const ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
const ADMIN_PASSWORD_HASH = process.env.ADMIN_PASSWORD_HASH || '';
const ADMIN_PASSWORD_PLAINTEXT = process.env.ADMIN_PASSWORD || '';

if (IS_PRODUCTION) {
  if (!ADMIN_PASSWORD_HASH) {
    fatal.push(
      'ADMIN_PASSWORD_HASH is required when NODE_ENV=production. Generate one with:  ' +
      'node -e "console.log(require(\'bcryptjs\').hashSync(process.argv[1],12))" \'your-password\''
    );
  }
  if (ADMIN_PASSWORD_PLAINTEXT) {
    fatal.push('ADMIN_PASSWORD (plaintext) must not be used in production — set ADMIN_PASSWORD_HASH instead.');
  }
} else if (!ADMIN_PASSWORD_HASH && !ADMIN_PASSWORD_PLAINTEXT) {
  // Development default so a fresh clone is usable without any setup at all.
  process.env.ADMIN_PASSWORD = 'admin123';
}

// --- Database ------------------------------------------------------------------------------------

const DATABASE_URL = requireInProduction(
  'DATABASE_URL',
  process.env.DATABASE_URL,
  'Point it at your PostgreSQL instance, e.g. postgresql://user:pass@host:5432/bet1x?schema=public'
);

// The flat-file fallback in backend/data keeps a laptop demo alive without Postgres, but it is not
// crash-safe or multi-process-safe, so it is off by default in production. Set
// ALLOW_JSON_FALLBACK=true only if you consciously accept that trade-off.
const ALLOW_JSON_FALLBACK = bool(process.env.ALLOW_JSON_FALLBACK, !IS_PRODUCTION);

// --- HTTP ----------------------------------------------------------------------------------------

const PORT = parseInt(process.env.PORT, 10) || 5000;
const HOST = process.env.HOST || '0.0.0.0';

// Number of reverse proxies in front of the app (nginx, Cloudflare, a PaaS router...). Required for
// correct client IPs in rate limiting and logs. 0 disables proxy trust entirely.
const TRUST_PROXY = parseInt(process.env.TRUST_PROXY, 10) || 0;

// Browser origins allowed to call the API. Same-origin deployments (the Express app also serving the
// static site, which is the default here) need no entries at all.
const CORS_ORIGINS = list(process.env.CORS_ORIGINS);

// Redirect http:// to https:// using the proxy's X-Forwarded-Proto header.
const FORCE_HTTPS = bool(process.env.FORCE_HTTPS, IS_PRODUCTION);

// --- Product rules --------------------------------------------------------------------------------

// Credits handed to a brand new account. A real-money deployment almost always wants 0 here; the
// non-zero development default is what makes the demo playable straight after signup.
const SIGNUP_BONUS = num(process.env.SIGNUP_BONUS, IS_PRODUCTION ? 0 : 2000);

// Historically any unknown username appearing in a request body was silently created with a funded
// wallet. That is an unlimited free-money faucet, so it is disabled unless explicitly re-enabled.
const ALLOW_AUTO_USER_CREATION = bool(process.env.ALLOW_AUTO_USER_CREATION, false);

const BCRYPT_ROUNDS = parseInt(process.env.BCRYPT_ROUNDS, 10) || 12;
const SESSION_TTL_MS = num(process.env.SESSION_TTL_HOURS, 24 * 7) * 3600 * 1000;

const MIN_BET = num(process.env.MIN_BET, 1);
const MAX_BET = num(process.env.MAX_BET, 100000);
const MIN_WITHDRAWAL = num(process.env.MIN_WITHDRAWAL, 100);

// --- Testing switches ---------------------------------------------------------------------------

// Relaxes the tight per-IP limiters (auth, cashier, chat, wallet) so a load test can drive dozens of
// accounts from one machine. Without it the auth limiter's 20-requests-per-15-minutes budget makes
// creating 50 players take about 45 minutes, and the cashier's 10-per-minute budget rejects every
// withdrawal once ten deposits have gone through.
//
// This is deliberately an environment switch and NOT a control in the admin panel. The limiters it
// turns off are the brute-force protection on the login endpoint; a button capable of disabling that
// on a running site would itself be the vulnerability, reachable by anyone who got as far as the
// admin console. As an env var it cannot be flipped remotely, and the guard below means a production
// process will refuse to start rather than run unprotected.
const DISABLE_RATE_LIMITS = bool(process.env.DISABLE_RATE_LIMITS, false);

if (IS_PRODUCTION && DISABLE_RATE_LIMITS) {
  fatal.push('DISABLE_RATE_LIMITS must never be set when NODE_ENV=production — it turns off login brute-force protection.');
}

// --- House-edge engine ------------------------------------------------------------------------------

// When a round is selected for rigging, Aviator's crash point can either be drawn from a fixed
// random band (the original behaviour, which ignores the bets on the table entirely) or computed
// from the live stake actually at risk. Set AVIATOR_SMART_CRASH=false to fall straight back to the
// original band if the computed one ever behaves unexpectedly — this changes nothing else, and has
// no effect at all on rounds the takeover engine did not already select.
const AVIATOR_SMART_CRASH = bool(process.env.AVIATOR_SMART_CRASH, true);

// Targeted pending stake (in credits) at which the crash point is pulled all the way down to the
// aggressive end of its band. Below this it scales proportionally, so small rounds still produce
// natural-looking multipliers and only genuinely large exposure triggers the tightest crash.
const AVIATOR_HIGH_STAKE_REF = num(process.env.AVIATOR_HIGH_STAKE_REF, 1000);

// --- Cricket (Your 11 / Boundary Baazi) -------------------------------------------------------------

// Master feature flag for both cricket games. Off by default: with this false no cricket route is
// registered, no scheduled sync runs, and the rest of the site behaves exactly as it did before the
// integration existed. This is the Section 9 rollout flag, and it is also what keeps the existing
// games provably unaffected.
const CRICKET_ENABLED = bool(process.env.CRICKET_ENABLED, false);

// Roanuz Cricket API (cricketapi.com), Standard licence. Only the Match Via Push product is paid
// for; fixtures and squads come from the free tournament-level endpoints.
const ROANUZ_API_TOKEN = process.env.ROANUZ_API_TOKEN || '';
const ROANUZ_PROJECT_KEY = process.env.ROANUZ_PROJECT_KEY || '';

// Confirmed 2026-08-24 against Roanuz's own public docs (auth lives under /core/, everything else
// under /cricket/) — see docs/CRICKET-BUILD-BRIEF.md. Configurable rather than hardcoded so a
// sandbox host, a proxy, or a version bump on Roanuz's side is a config change, never a deploy.
const ROANUZ_AUTH_BASE_URL = process.env.ROANUZ_AUTH_BASE_URL || 'https://api.sports.roanuz.com/v5/core';
const ROANUZ_BASE_URL = process.env.ROANUZ_BASE_URL || 'https://api.sports.roanuz.com/v5/cricket';

// Which transport backend/lib/cricket/roanuz.js talks through: 'live' makes real HTTP calls,
// 'mock' returns the canned, documentation-shaped fixtures in roanuz-transport.js. Left unset, it
// resolves itself from whether real credentials are present — so the ENTIRE flip from "developing
// against a mock" to "talking to the real API" is dropping in ROANUZ_API_TOKEN/ROANUZ_PROJECT_KEY,
// no code change and no other flag to remember. 'mock' can still be forced with real credentials
// present (e.g. to develop against the mock without risking a real API call by accident).
const ROANUZ_TRANSPORT_RAW = (process.env.ROANUZ_TRANSPORT || '').trim().toLowerCase();
const ROANUZ_TRANSPORT = ['live', 'mock'].includes(ROANUZ_TRANSPORT_RAW)
  ? ROANUZ_TRANSPORT_RAW
  : (ROANUZ_API_TOKEN && ROANUZ_PROJECT_KEY ? 'live' : 'mock');

// Shared secret used to verify the signature on every incoming push-feed delivery. Without it the
// webhook is an unauthenticated endpoint that writes straight into the permanent event log, so a
// production process refuses to boot with cricket enabled and this unset.
const ROANUZ_WEBHOOK_SECRET = process.env.ROANUZ_WEBHOOK_SECRET || '';

// Tournaments currently being covered, by Roanuz tournament key.
const ROANUZ_TOURNAMENT_IDS = list(process.env.ROANUZ_TOURNAMENT_IDS);

if (CRICKET_ENABLED && IS_PRODUCTION) {
  if (!ROANUZ_API_TOKEN) {
    fatal.push('ROANUZ_API_TOKEN is required when CRICKET_ENABLED=true in production.');
  }
  if (!ROANUZ_WEBHOOK_SECRET) {
    fatal.push(
      'ROANUZ_WEBHOOK_SECRET is required when CRICKET_ENABLED=true in production — without it the ' +
      'push webhook accepts unsigned writes into the permanent ball event log.'
    );
  }
  if (ROANUZ_TRANSPORT === 'mock') {
    fatal.push(
      'ROANUZ_TRANSPORT=mock (or no credentials, which defaults to it) in a production boot with ' +
      'CRICKET_ENABLED=true — this would serve fabricated match data to real players. Set ' +
      'ROANUZ_API_TOKEN/ROANUZ_PROJECT_KEY (or explicitly ROANUZ_TRANSPORT=live once they are set).'
    );
  }
}

// How long a covered match may go without a ball event before the feed is treated as stalled. A
// silent stall is the failure mode most likely to actually happen in production; 90s is the brief's
// figure, kept configurable because it is a tuning value, not a constant.
const CRICKET_STALL_MS = num(process.env.CRICKET_STALL_SECONDS, 90) * 1000;

// How often the fixtures/squad sync polls the free tournament endpoints. Deliberately infrequent:
// the Standard licence allows ~1,200 unique resources a month and the push feed — not polling — is
// what delivers live data.
const CRICKET_FIXTURE_SYNC_MS = num(process.env.CRICKET_FIXTURE_SYNC_HOURS, 6) * 3600 * 1000;

// The account the Your 11 house entry plays from. It pays its own entry fee into the pool from
// this account's wallet, so it has to be a real user row — the pool arithmetic is only honest if the
// house's stake is actually in it. Unset means no house entry is ever placed, which is the safe
// default: the feature switches itself off rather than entering contests for free.
const CRICKET_HOUSE_ACCOUNT = (process.env.CRICKET_HOUSE_ACCOUNT || '').trim();

const LOG_LEVEL = process.env.LOG_LEVEL || 'info';

// --- Fail fast -------------------------------------------------------------------------------------

if (fatal.length) {
  console.error('\n[bet1x-backend] Refusing to start — configuration is not production-safe:\n');
  fatal.forEach(msg => console.error(`  ✗ ${msg}`));
  console.error('\nSee backend/.env.example and DEPLOYMENT.md for the full list.\n');
  process.exit(1);
}

module.exports = {
  NODE_ENV,
  IS_PRODUCTION,
  APP_SECRET,
  ADMIN_USERNAME,
  ADMIN_PASSWORD_HASH,
  ADMIN_PASSWORD_PLAINTEXT: process.env.ADMIN_PASSWORD || '',
  DATABASE_URL,
  ALLOW_JSON_FALLBACK,
  PORT,
  HOST,
  TRUST_PROXY,
  CORS_ORIGINS,
  FORCE_HTTPS,
  SIGNUP_BONUS,
  ALLOW_AUTO_USER_CREATION,
  BCRYPT_ROUNDS,
  SESSION_TTL_MS,
  MIN_BET,
  MAX_BET,
  MIN_WITHDRAWAL,
  DISABLE_RATE_LIMITS,
  AVIATOR_SMART_CRASH,
  AVIATOR_HIGH_STAKE_REF,
  CRICKET_ENABLED,
  ROANUZ_API_TOKEN,
  ROANUZ_PROJECT_KEY,
  ROANUZ_AUTH_BASE_URL,
  ROANUZ_BASE_URL,
  ROANUZ_TRANSPORT,
  ROANUZ_WEBHOOK_SECRET,
  ROANUZ_TOURNAMENT_IDS,
  CRICKET_STALL_MS,
  CRICKET_FIXTURE_SYNC_MS,
  CRICKET_HOUSE_ACCOUNT,
  LOG_LEVEL,
  DATA_DIR: path.join(__dirname, 'data'),
  STATIC_ROOT: path.join(__dirname, '..')
};

