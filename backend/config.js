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
  LOG_LEVEL,
  DATA_DIR: path.join(__dirname, 'data'),
  STATIC_ROOT: path.join(__dirname, '..')
};

