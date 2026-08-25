/**
 * Bet1x Centralized Express Node.js Backend Server
 *
 * Connects all user data, authentication, wallet, payments, admin controls and game state engines
 * (Color Prediction, Aviator, Teen Patti, Mines). Backed by PostgreSQL via Prisma, with an optional
 * flat-file fallback for local development.
 *
 * Security model, in short:
 *   - Session tokens are HMAC-signed (see lib/auth.js); a client can read one but not forge one.
 *   - The account a request acts on is taken from the verified token, never from a `username`
 *     parameter, so no player can operate on another player's wallet.
 *   - Everything under /api/admin, /api/db and every rig/override control requires an operator token.
 *   - Wallet debits are conditional single-statement updates, so concurrent requests cannot spend
 *     the same balance twice.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const compression = require('compression');
const rateLimit = require('express-rate-limit');
const bcrypt = require('bcryptjs');
const { PrismaClient } = require('@prisma/client');

const config = require('./config');
const { logger, requestLogger, errorHandler } = require('./lib/logger');
const auth = require('./lib/auth');
const rigAudit = require('./lib/rig-audit');
const cricket = require('./lib/cricket');

const app = express();
const PORT = config.PORT;
const prisma = new PrismaClient();

// True once Prisma has proven it can reach the database. Routes consult this instead of discovering
// the outage per-query, and in production a dead database is surfaced as 503 rather than silently
// diverting live money into flat files.
let databaseReady = false;

// ---------------------------------------------------------------------------------------------
// JSON fallback store (development / no-Postgres mode)
// ---------------------------------------------------------------------------------------------

const DATA_DIR = config.DATA_DIR;
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

function readJsonTable(table) {
  const filePath = path.join(DATA_DIR, `${table}.json`);
  if (!fs.existsSync(filePath)) return [];
  try {
    const parsed = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    logger.error(`Corrupt JSON table ${table}.json - treating as empty`, { message: e.message });
    return [];
  }
}

function writeJsonTable(table, data) {
  const filePath = path.join(DATA_DIR, `${table}.json`);
  const tmpPath = `${filePath}.${process.pid}.tmp`;
  try {
    // Write-then-rename so a crash mid-write can never leave a half-serialised table behind.
    fs.writeFileSync(tmpPath, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmpPath, filePath);
  } catch (e) {
    logger.error(`Error writing ${table}.json`, { message: e.message });
    try { if (fs.existsSync(tmpPath)) fs.unlinkSync(tmpPath); } catch (_) { /* best effort */ }
  }
}

/**
 * Should this code path fall back to flat files when Prisma throws?
 *
 * A production deployment must not: quietly writing balances to a JSON file while the real database
 * is unreachable produces two divergent sources of truth for people's money.
 */
function jsonFallbackAllowed() {
  return config.ALLOW_JSON_FALLBACK;
}

// ---------------------------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------------------------

function generateAuthToken(user, role = 'user') {
  return auth.issueToken({ id: user.id, username: user.username, email: user.email, role });
}

/** Verified session payload for this request, or null. Signature failures return null. */
function parseAuthToken(req) {
  return req.auth || auth.verifyToken(auth.extractToken(req));
}

/**
 * Look up a user by name.
 *
 * This used to mint a fully funded account for any username that happened to appear in a request
 * body, which amounted to an unlimited free-money faucet reachable by anyone. Creation now only
 * happens when ALLOW_AUTO_USER_CREATION is explicitly enabled (development seeding); otherwise an
 * unknown username simply resolves to null and the caller reports "user not found".
 */
async function getOrCreateUser(username, { allowCreate = config.ALLOW_AUTO_USER_CREATION } = {}) {
  if (Array.isArray(username)) username = username[0];
  if (!username || typeof username !== 'string') return null;
  username = username.trim();
  if (!username) return null;

  try {
    let user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
    if (!user && allowCreate) {
      user = await prisma.user.create({
        data: {
          username,
          email: `${username.toLowerCase()}@bet1x.local`,
          password: bcrypt.hashSync(crypto.randomBytes(24).toString('hex'), config.BCRYPT_ROUNDS),
          wallet_balance: config.SIGNUP_BONUS
        }
      });
      logger.info(`Auto-created user record for "${username}"`, { balance: config.SIGNUP_BONUS });
    }
    return user;
  } catch (e) {
    if (!jsonFallbackAllowed()) throw e;
    const users = readJsonTable('users');
    let user = users.find(u => u.username && u.username.toLowerCase() === username.toLowerCase());
    if (!user && allowCreate) {
      user = {
        id: users.length + 1,
        username,
        email: `${username.toLowerCase()}@bet1x.local`,
        password: bcrypt.hashSync(crypto.randomBytes(24).toString('hex'), config.BCRYPT_ROUNDS),
        wallet_balance: config.SIGNUP_BONUS,
        created_at: new Date().toISOString()
      };
      users.push(user);
      writeJsonTable('users', users);
    }
    return user || null;
  }
}

/**
 * Generate a collision-resistant primary key for a ledger row.
 *
 * Every ledger id in this file used to be built from either a six-digit random number or a bare
 * millisecond timestamp, and both collide in ordinary use:
 *
 *   'TX_'    + random 6 digits   — only 900,000 possible values. By the birthday bound a 50% chance
 *                                  of a duplicate arrives after roughly 1,100 rows, not 900,000.
 *   'MINES_' + Date.now()        — two players starting a round in the same millisecond produce the
 *                                  same id. Observed in load testing: five 500s, and because the
 *                                  wallet is debited *before* the insert, ₹400 of player money was
 *                                  destroyed with no ledger row to show for it.
 *   'TP_'    + Date.now() + seat — the seat number only disambiguates within one room; two rooms
 *                                  dealing the same seat index in the same millisecond still collide.
 *
 * A timestamp keeps ids roughly sortable and readable, while 10 random bytes (80 bits) make a
 * same-millisecond collision effectively impossible.
 */
function newRecordId(prefix) {
  return `${prefix}_${Date.now()}_${crypto.randomBytes(10).toString('hex')}`;
}

/**
 * Debit a wallet atomically.
 *
 * The old pattern (read balance, check it, then write balance - amount) is a classic double-spend:
 * two simultaneous bets both read the same balance and both pass the check. This performs the check
 * and the write in one statement and reports whether it actually matched a row.
 */
async function debitWallet(userId, amount) {
  const result = await prisma.user.updateMany({
    where: { id: userId, wallet_balance: { gte: amount } },
    data: { wallet_balance: { decrement: amount } }
  });
  if (result.count === 0) return null;
  const user = await prisma.user.findUnique({ where: { id: userId } });
  return user ? user.wallet_balance : null;
}

/** Credit a wallet atomically and return the resulting balance. */
async function creditWallet(userId, amount) {
  const user = await prisma.user.update({
    where: { id: userId },
    data: { wallet_balance: { increment: amount } }
  });
  return user.wallet_balance;
}

/** Reject stake amounts outside the configured table limits before any money moves. */
function validateStake(amount) {
  const value = parseFloat(amount);
  if (!Number.isFinite(value) || value <= 0) return { ok: false, error: 'Invalid bet amount.' };
  if (value < config.MIN_BET) return { ok: false, error: `Minimum bet is ${config.MIN_BET}.` };
  if (value > config.MAX_BET) return { ok: false, error: `Maximum bet is ${config.MAX_BET}.` };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

/**
 * Canonicalise a colour-room selection, or reject it.
 *
 * settleColorRound() and calculateColorOptimalOutcome() both compare a stored bet against the
 * canonical outcome ('Green' | 'Red' | 'Violet', 'Big' | 'Small', 0-9) with a case-sensitive `===`.
 * Anything else — 'green', 'BIG', 'banana' — was previously accepted and debited, then silently lost
 * every round because no outcome could ever equal it. Normalise here so a case variation still plays,
 * and refuse outright anything that is not a real selection rather than taking money for a bet that
 * cannot win.
 */
function normalizeColorSelection(category, value) {
  const cat = String(category || '').trim().toLowerCase();
  const raw = String(value === undefined || value === null ? '' : value).trim();

  if (cat === 'color') {
    const canonical = { green: 'Green', red: 'Red', violet: 'Violet' }[raw.toLowerCase()];
    if (!canonical) return { ok: false, error: 'Colour must be Green, Red or Violet.' };
    return { ok: true, category: 'color', value: canonical };
  }
  if (cat === 'size') {
    const canonical = { big: 'Big', small: 'Small' }[raw.toLowerCase()];
    if (!canonical) return { ok: false, error: 'Size must be Big or Small.' };
    return { ok: true, category: 'size', value: canonical };
  }
  if (cat === 'number') {
    if (!/^[0-9]$/.test(raw)) return { ok: false, error: 'Number must be a single digit from 0 to 9.' };
    return { ok: true, category: 'number', value: raw };
  }
  return { ok: false, error: 'Bet category must be color, size or number.' };
}

// ---------------------------------------------------------------------------------------------
// HTTP middleware stack
// ---------------------------------------------------------------------------------------------

if (config.TRUST_PROXY > 0) {
  app.set('trust proxy', config.TRUST_PROXY);
}
app.disable('x-powered-by');

// Terminate plaintext HTTP at the edge. Relies on the reverse proxy setting X-Forwarded-Proto,
// which is why TRUST_PROXY must be configured alongside FORCE_HTTPS.
if (config.FORCE_HTTPS) {
  app.use((req, res, next) => {
    if (req.secure || req.get('x-forwarded-proto') === 'https') return next();
    if (req.method !== 'GET' && req.method !== 'HEAD') {
      return res.status(403).json({ error: 'HTTPS is required.' });
    }
    return res.redirect(308, `https://${req.get('host')}${req.originalUrl}`);
  });
}

app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      // The pages are hand-written HTML with inline <script>/<style> blocks throughout, so inline
      // execution has to stay allowed. The value of this policy is that it still pins every
      // *external* origin: no third-party script host can be injected, and 'unsafe-eval' is absent.
      scriptSrc: ["'self'", "'unsafe-inline'"],
      // scriptSrcAttr must be set explicitly. Helmet defaults it to 'none', which blocks inline
      // event-handler attributes specifically, and scriptSrc's 'unsafe-inline' does NOT cover them.
      // These pages wire up ~150 buttons with onclick=/onsubmit= (the login/signup modal's tabs,
      // close button and submit handler among them), so leaving the default in place silently kills
      // every one of them in the browser while the server looks perfectly healthy.
      scriptSrcAttr: ["'unsafe-inline'"],
      styleSrc: ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
      fontSrc: ["'self'", 'https://fonts.gstatic.com', 'data:'],
      imgSrc: ["'self'", 'data:', 'blob:'],
      connectSrc: ["'self'", ...config.CORS_ORIGINS],
      objectSrc: ["'none'"],
      baseUri: ["'self'"],
      formAction: ["'self'"],
      frameAncestors: ["'none'"],
      upgradeInsecureRequests: config.FORCE_HTTPS ? [] : null
    }
  },
  crossOriginEmbedderPolicy: false,
  crossOriginResourcePolicy: { policy: 'same-site' },
  hsts: config.FORCE_HTTPS ? { maxAge: 31536000, includeSubDomains: true } : false
}));

app.use(compression());

// Same-origin deployments need no CORS at all. When CORS_ORIGINS is configured the allowlist is
// exact - the previous callback approved every origin it was handed, which combined with
// `credentials: true` let any website on the internet drive a logged-in user's session.
app.use(cors({
  origin(origin, callback) {
    if (!origin) return callback(null, true); // same-origin, curl, server-to-server
    if (config.CORS_ORIGINS.includes(origin)) return callback(null, true);
    if (!config.IS_PRODUCTION && config.CORS_ORIGINS.length === 0) return callback(null, true);
    return callback(null, false);
  },
  credentials: true
}));

// The cricket push-feed receiver must be mounted HERE, ahead of the JSON parser and the /api rate
// limiter, and it is a no-op unless CRICKET_ENABLED is set. Two reasons, both of which are silent
// data loss if they are got wrong:
//
//   1. Verifying the feed's HMAC signature needs the raw request bytes. express.json() below
//      discards them, and re-serialising req.body does not reproduce what the provider signed.
//   2. apiLimiter caps /api at 600 req/min. A fast over arrives in a burst, and a rate-limited
//      webhook means balls missing from a permanent log that can never be re-fetched.
//
// The raw parser is scoped to that single path, and body-parser's own "already parsed" flag means
// the global parsers below skip it. No other route's body handling changes.
// The wallet adapter is handed in rather than reimplemented inside lib/cricket: debitWallet is a
// single conditional statement (the double-spend fix above), and a second copy of that logic in the
// cricket module would be a second chance to get it wrong.
cricket.init({
  prisma,
  logger,
  wallet: { debit: debitWallet, credit: creditWallet, newRecordId },
  // Your 11 draws from the same exact 100-slot bag every other game uses, rather than a second
  // mechanism that would drift from the configured percentage on its own. `fillerName` is the same
  // generator that names simulated Teen Patti players, so a house entry on a leaderboard is
  // indistinguishable from any other entrant.
  houseEdge: {
    shouldRig: shouldBotRigThisRound,
    fillerName: randomFillerName,
    account: config.CRICKET_HOUSE_ACCOUNT || null
  }
});
cricket.registerIngest(app);

app.use(express.json({ limit: '256kb' }));
app.use(express.urlencoded({ extended: true, limit: '256kb' }));
app.use(auth.attachSession);
app.use(requestLogger);

// Rate limits. Credential endpoints get a much tighter budget than ordinary gameplay because they
// are the ones worth brute-forcing.
// DISABLE_RATE_LIMITS (development only — config.js refuses to boot a production process with it
// set) turns these off so a load test can drive dozens of accounts from a single machine. Note that
// unlike apiLimiter below, this one is otherwise NOT relaxed in development: it is the login
// brute-force guard, so it stays on at full strength unless explicitly switched off for a test run.
const skipWhenTesting = () => config.DISABLE_RATE_LIMITS;

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 20,
  standardHeaders: true,
  legacyHeaders: false,
  skip: skipWhenTesting,
  message: { error: 'Too many authentication attempts. Please try again in a few minutes.' }
});

const apiLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 600,
  standardHeaders: true,
  legacyHeaders: false,
  skip: () => !config.IS_PRODUCTION, // polling-heavy game loops make this noisy in development
  message: { error: 'Too many requests. Please slow down.' }
});

const walletLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 120,
  standardHeaders: true,
  legacyHeaders: false,
  skip: skipWhenTesting,
  message: { error: 'Too many wallet operations. Please slow down.' }
});

app.use('/api/', apiLimiter);

/**
 * Guard for routes that have no flat-file fallback: without a database they would throw a 500 with
 * a raw Prisma message, so answer with an honest 503 instead.
 */
function requireDatabase(req, res, next) {
  if (databaseReady || jsonFallbackAllowed()) return next();
  return res.status(503).json({ error: 'Service temporarily unavailable. Please try again shortly.' });
}

// Connect to the database on start.
prisma.$connect()
  .then(() => {
    databaseReady = true;
    logger.info('Connected to PostgreSQL via Prisma');
  })
  .catch(err => {
    databaseReady = false;
    if (config.IS_PRODUCTION && !jsonFallbackAllowed()) {
      logger.error('Cannot reach the database and JSON fallback is disabled - refusing to serve traffic', { message: err.message });
      process.exit(1);
    }
    logger.warn('Database unreachable - running on the flat-file fallback store', { message: err.message });
  });

// --- Cricket (Your 11 / Boundary Baazi) ---
// Player-facing reads, the SSE live stream, and the operator endpoints. Behind CRICKET_ENABLED:
// with the flag off nothing is mounted and no timer starts, so the existing games are untouched.
cricket.register(app, { auth, requireDatabase });
cricket.start();

// --- Health Check ---
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', service: 'bet1x-backend', timestamp: new Date().toISOString() });
});

// Readiness differs from liveness: the process can be up while its datastore is not. Load balancers
// and orchestrators should gate traffic on this one.
app.get('/api/ready', (req, res) => {
  const ready = databaseReady || jsonFallbackAllowed();
  res.status(ready ? 200 : 503).json({
    ready,
    database: databaseReady ? 'connected' : 'unavailable',
    store: databaseReady ? 'postgres' : (jsonFallbackAllowed() ? 'json-fallback' : 'none'),
    env: config.NODE_ENV
  });
});

// --- Unified Auth Endpoints ---

// Get Status / Authenticate Session
app.all(['/api/auth/status', '/api/db/users/status'], async (req, res) => {
  // Identity is taken from the signed token alone. Honouring a `username` query parameter here let
  // anybody read any account's e-mail address and wallet balance just by guessing the name.
  const tokenData = parseAuthToken(req);
  const username = tokenData && tokenData.username;
  
  if (!username) {
    return res.json({ logged_in: false, message: 'Guest session' });
  }

  try {
    let user = null;
    try {
      user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
    } catch (e) {
      const users = readJsonTable('users');
      user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    }

    if (user) {
      return res.json({
        logged_in: true,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          wallet_balance: parseFloat(user.wallet_balance)
        }
      });
    }
    res.json({ logged_in: false });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Secure Login (Username or Email + Password)
app.post(['/api/auth/login', '/api/db/users/login'], authLimiter, async (req, res) => {
  const username = (req.body.username || '').trim();
  const password = req.body.password || '';

  if (!username || !password) {
    return res.status(400).json({ error: 'Username/email and password are required.' });
  }

  try {
    let user = null;
    try {
      user = await prisma.user.findFirst({
        where: {
          OR: [
            { username: { equals: username, mode: 'insensitive' } },
            { email: { equals: username, mode: 'insensitive' } }
          ]
        }
      });
    } catch (e) {
      if (!jsonFallbackAllowed()) throw e;
      const users = readJsonTable('users');
      user = users.find(u => (u.username || '').toLowerCase() === username.toLowerCase() || (u.email || '').toLowerCase() === username.toLowerCase());
    }

    if (user && bcrypt.compareSync(password, user.password)) {
      const token = generateAuthToken(user);
      return res.json({
        success: true,
        token: token,
        user: {
          id: user.id,
          username: user.username,
          email: user.email,
          wallet_balance: parseFloat(user.wallet_balance)
        }
      });
    } else {
      return res.status(400).json({ error: 'Incorrect username or password.' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Secure Signup (Register new account)
app.post(['/api/auth/signup', '/api/db/users/signup'], authLimiter, async (req, res) => {
  const username = (req.body.username || '').trim();
  let email = (req.body.email || '').trim();
  if (!email && username) {
    email = `${username.toLowerCase()}@bet1x.com`;
  }
  const password = req.body.password || '';
  const confirmPassword = req.body.confirm_password || password;
  // The opening balance is a server-side product decision (SIGNUP_BONUS). It used to be read from
  // the request body, which meant a new account could simply ask to be created with any balance.
  const startingBalance = config.SIGNUP_BONUS;

  if (!username || !password) {
    return res.status(400).json({ error: 'Username and password are required.' });
  }

  if (password !== confirmPassword) {
    return res.status(400).json({ error: 'Passwords do not match.' });
  }

  if (!/^[a-zA-Z0-9_]{3,20}$/.test(username)) {
    return res.status(400).json({ error: 'Username must be 3-20 alphanumeric characters or underscores.' });
  }

  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return res.status(400).json({ error: 'Invalid email address format.' });
  }

  if (password.length < 8) {
    return res.status(400).json({ error: 'Password must be at least 8 characters.' });
  }
  if (password.length > 128) {
    return res.status(400).json({ error: 'Password must be 128 characters or fewer.' });
  }

  const hashedPassword = bcrypt.hashSync(password, config.BCRYPT_ROUNDS);

  try {
    let newUser = null;
    try {
      newUser = await prisma.$transaction(async (tx) => {
        const existing = await tx.user.findFirst({
          where: {
            OR: [
              { username: { equals: username, mode: 'insensitive' } },
              { email: { equals: email, mode: 'insensitive' } }
            ]
          }
        });
        if (existing) {
          if (existing.username.toLowerCase() === username.toLowerCase()) {
            throw new Error('Username is already taken.');
          }
          throw new Error('Email is already registered.');
        }

        const created = await tx.user.create({
          data: {
            username,
            email,
            password: hashedPassword,
            wallet_balance: startingBalance
          }
        });

        if (startingBalance > 0) {
          await tx.transaction.create({
            data: {
              id: newRecordId('DEP'),
              user: username,
              type: 'Deposit',
              amount: startingBalance,
              details: 'Welcome Bonus Credits',
              status: 'Completed',
              timestamp: new Date()
            }
          });
        }

        return created;
      });
    } catch (dbErr) {
      if (dbErr.message === 'Username is already taken.' || dbErr.message === 'Email is already registered.') {
        return res.status(400).json({ error: dbErr.message });
      }

      // JSON Fallback
      if (!jsonFallbackAllowed()) throw dbErr;
      const users = readJsonTable('users');
      if (users.some(u => u.username.toLowerCase() === username.toLowerCase())) {
        return res.status(400).json({ error: 'Username is already taken.' });
      }
      if (users.some(u => u.email.toLowerCase() === email.toLowerCase())) {
        return res.status(400).json({ error: 'Email is already registered.' });
      }

      newUser = {
        id: users.length + 1,
        username,
        email,
        password: hashedPassword,
        wallet_balance: startingBalance,
        created_at: new Date().toISOString()
      };
      users.push(newUser);
      writeJsonTable('users', users);

      const txns = readJsonTable('transactions');
      txns.unshift({
        id: newRecordId('DEP'),
        user: username,
        type: 'Deposit',
        amount: startingBalance,
        details: 'Welcome Bonus Credits',
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
    }

    const token = generateAuthToken(newUser);
    return res.json({
      success: true,
      token: token,
      user: {
        id: newUser.id,
        username: newUser.username,
        email: newUser.email,
        wallet_balance: parseFloat(newUser.wallet_balance)
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Logout Endpoint
app.all(['/api/auth/logout'], (req, res) => {
  res.json({ success: true, message: 'Logged out successfully.' });
});

// --- Wallet Endpoints (Per Account Synchronized) ---

// Get User Wallet Balance
app.get('/api/wallet/balance', auth.requireAuth, async (req, res, next) => {
  const username = auth.actingUsername(req);
  try {
    const user = await getOrCreateUser(username);
    if (!user) return res.status(404).json({ error: 'Account not found.' });
    res.json({ balance: parseFloat(user.wallet_balance) });
  } catch (err) {
    next(err);
  }
});

// Adjust User Wallet Balance Atomically
// Direct balance manipulation is an operator action only. Left open, this single endpoint let
// anyone credit any account any amount — the most severe hole in the pre-hardening build.
app.post(['/api/wallet/adjust', '/api/db/users/adjust-balance'], walletLimiter, auth.requireAdmin, async (req, res) => {
  const username = req.body.username || (parseAuthToken(req) && parseAuthToken(req).username);
  const delta = parseFloat(req.body.delta) || 0;
  const details = req.body.details || req.body.reason || 'Game play';

  if (delta === 0) {
    return res.status(400).json({ error: 'Invalid adjustment amount.' });
  }

  try {
    let updatedBalance = 0;
    try {
      const result = await prisma.$transaction(async (tx) => {
        const user = await tx.user.findFirst({
          where: { username: { equals: username, mode: 'insensitive' } }
        });
        if (!user) throw new Error('User not found.');

        const newBal = user.wallet_balance + delta;
        if (newBal < 0) throw new Error('Insufficient wallet balance.');

        const updated = await tx.user.update({
          where: { id: user.id },
          data: { wallet_balance: newBal }
        });

        const type = (delta >= 0) ? 'Deposit' : 'Withdrawal';
        const txnId = type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000);

        await tx.transaction.create({
          data: {
            id: txnId,
            user: user.username,
            type,
            amount: Math.abs(delta),
            details,
            status: 'Completed',
            timestamp: new Date()
          }
        });

        return updated.wallet_balance;
      });
      updatedBalance = result;
    } catch (dbErr) {
      if (dbErr.message === 'Insufficient wallet balance.' || dbErr.message === 'User not found.') {
        return res.status(400).json({ error: dbErr.message });
      }

      // JSON Fallback
      if (!jsonFallbackAllowed()) throw dbErr;
      const users = readJsonTable('users');
      const user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
      if (!user) return res.status(404).json({ error: 'User not found.' });

      const newBal = (parseFloat(user.wallet_balance) || 0) + delta;
      if (newBal < 0) return res.status(400).json({ error: 'Insufficient wallet balance.' });

      user.wallet_balance = newBal;
      writeJsonTable('users', users);

      const txns = readJsonTable('transactions');
      const type = (delta >= 0) ? 'Deposit' : 'Withdrawal';
      txns.unshift({
        id: type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000),
        user: user.username,
        type,
        amount: Math.abs(delta),
        details,
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
      updatedBalance = newBal;
    }

    res.json({ success: true, new_balance: updatedBalance });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get Transactions for a specific User
app.get(['/api/wallet/transactions', '/api/db/transactions'], auth.requireAuth, async (req, res) => {
  // Players see only their own ledger; operators may pass ?username= to inspect anyone's.
  const username = auth.actingUsername(req);
  try {
    let txns = [];
    try {
      if (username) {
        txns = await prisma.transaction.findMany({
          where: { user: { equals: username, mode: 'insensitive' } },
          orderBy: { timestamp: 'desc' }
        });
      } else {
        txns = await prisma.transaction.findMany({
          orderBy: { timestamp: 'desc' }
        });
      }
    } catch (e) {
      if (!jsonFallbackAllowed()) throw e;
      const all = readJsonTable('transactions');
      if (username) {
        txns = all.filter(t => t.user && t.user.toLowerCase() === username.toLowerCase());
      } else {
        txns = all;
      }
    }
    res.json(txns);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Reset User Balance
app.post(['/api/wallet/reset', '/api/db/users/reset-balance'], auth.requireAdmin, async (req, res) => {
  const username = req.body.username || (parseAuthToken(req) && parseAuthToken(req).username);
  const targetBal = parseFloat(req.body.starting_balance) || 2000.00;

  try {
    try {
      await prisma.$transaction(async (tx) => {
        const user = await tx.user.findFirst({
          where: { username: { equals: username, mode: 'insensitive' } }
        });
        if (!user) throw new Error('User not found.');

        await tx.user.update({
          where: { id: user.id },
          data: { wallet_balance: targetBal }
        });

        const txnId = 'DEP_' + Math.floor(100000 + Math.random() * 900000);
        await tx.transaction.create({
          data: {
            id: txnId,
            user: user.username,
            type: 'Deposit',
            amount: targetBal,
            details: 'Wallet Demo Balance Reset',
            status: 'Completed',
            timestamp: new Date()
          }
        });
      });
    } catch (e) {
      const users = readJsonTable('users');
      const user = users.find(u => u.username.toLowerCase() === username.toLowerCase());
      if (user) {
        user.wallet_balance = targetBal;
        writeJsonTable('users', users);
      }
      const txns = readJsonTable('transactions');
      txns.unshift({
        id: newRecordId('DEP'),
        user: username,
        type: 'Deposit',
        amount: targetBal,
        details: 'Wallet Demo Balance Reset',
        status: 'Completed',
        timestamp: new Date().toISOString()
      });
      writeJsonTable('transactions', txns);
    }
    res.json({ success: true, balance: targetBal });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Live Chat Endpoints (Per User Account Synchronized) ---

// Fetch Chat Messages
app.get('/api/chat', async (req, res) => {
  try {
    let messages = [];
    try {
      messages = await prisma.chatMessage.findMany({
        orderBy: { timestamp: 'asc' },
        take: 50
      });
    } catch (e) {
      messages = readJsonTable('chat');
    }
    res.json(messages);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Post Chat Message
const chatLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 20,
  standardHeaders: true,
  legacyHeaders: false,
  skip: skipWhenTesting,
  message: { error: 'You are sending messages too quickly.' }
});

app.post('/api/chat', chatLimiter, auth.requireAuth, async (req, res) => {
  // The display name is taken from the session, so nobody can post under someone else's name.
  const username = auth.actingUsername(req);
  const message = (req.body.message || '').trim().slice(0, 300);

  if (!message) {
    return res.status(400).json({ error: 'Message cannot be empty.' });
  }

  const msgObj = {
    username,
    message,
    timestamp: new Date()
  };

  try {
    let saved = null;
    try {
      saved = await prisma.chatMessage.create({
        data: msgObj
      });
    } catch (e) {
      if (!jsonFallbackAllowed()) throw e;
      const chat = readJsonTable('chat');
      msgObj.id = chat.length + 1;
      msgObj.timestamp = new Date().toISOString();
      chat.push(msgObj);
      writeJsonTable('chat', chat.slice(-100));
      saved = msgObj;
    }
    res.json({ success: true, message: saved });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Everything below this line under /api/db is a direct read/write door into the users,
// transactions, deposits and withdrawals tables. It exists for the operator console and the legacy
// PHP gateway, never for players, so the whole namespace is gated once here rather than route by
// route. The /api/db/users/{login,signup,status,adjust-balance,reset-balance} and
// /api/db/transactions aliases are registered *above* this guard and keep their own, narrower rules.
app.use('/api/db', auth.requireAdmin, requireDatabase);

// --- Game Bets Recording Endpoints (Per Account Synchronized) ---

app.get('/api/db/game-bets', async (req, res) => {
  const username = req.query.username;
  const game = req.query.game;
  try {
    let bets = [];
    try {
      const where = {};
      if (username) where.username = { equals: username, mode: 'insensitive' };
      if (game) where.game = game;
      bets = await prisma.gameBet.findMany({
        where,
        orderBy: { created_at: 'desc' },
        take: 50
      });
    } catch (e) {
      bets = readJsonTable('game_bets');
      if (username) bets = bets.filter(b => b.username && b.username.toLowerCase() === username.toLowerCase());
      if (game) bets = bets.filter(b => b.game === game);
    }
    res.json(bets);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/db/game-bets', async (req, res) => {
  const { username, game, bet_amount, payout, status, metadata } = req.body;
  const betRecord = {
    username: username || 'DemoUser',
    game: game || 'unknown',
    bet_amount: parseFloat(bet_amount) || 0,
    payout: parseFloat(payout) || 0,
    status: status || 'active',
    metadata: metadata || null,
    created_at: new Date()
  };

  try {
    let saved = null;
    try {
      saved = await prisma.gameBet.create({ data: betRecord });
    } catch (e) {
      const bets = readJsonTable('game_bets');
      betRecord.id = 'BET_' + Math.floor(100000 + Math.random() * 900000);
      betRecord.created_at = new Date().toISOString();
      bets.unshift(betRecord);
      writeJsonTable('game_bets', bets.slice(0, 100));
      saved = betRecord;
    }
    res.json({ success: true, bet: saved });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Admin Endpoints ---
// Operator-only from here on: house statistics, the super dashboard and the per-game rig consoles.
app.use('/api/admin', auth.requireAdmin);

app.get('/api/admin/stats', async (req, res) => {
  try {
    let totalUsers = 0;
    let deposits = [];
    let withdrawals = [];
    let users = [];

    try {
      totalUsers = await prisma.user.count();
      deposits = await prisma.deposit.findMany({ where: { status: 'Completed' } });
      withdrawals = await prisma.withdrawal.findMany();
      users = await prisma.user.findMany();
    } catch (e) {
      users = readJsonTable('users');
      totalUsers = users.length;
      deposits = readJsonTable('deposits').filter(d => d.status === 'Completed');
      withdrawals = readJsonTable('withdrawals');
    }

    const totalDeposited = deposits.reduce((sum, d) => sum + (parseFloat(d.amount) || 0), 0);
    const totalWithdrawn = withdrawals.filter(w => w.status === 'Completed').reduce((sum, w) => sum + (parseFloat(w.amount) || 0), 0);
    const pendingWithdrawals = withdrawals.filter(w => w.status === 'Pending').length;
    const walletPool = users.reduce((sum, u) => sum + (parseFloat(u.wallet_balance) || 0), 0);

    res.json({
      total_users: totalUsers,
      total_deposited: totalDeposited,
      total_withdrawn: totalWithdrawn,
      wallet_pool: walletPool,
      pending_withdrawals: pendingWithdrawals
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Express Database CRUD API Gateway (Queried by PHP Backend layer) ---

// Get all users
app.get('/api/db/users', async (req, res) => {
  try {
    let users = [];
    try {
      users = await prisma.user.findMany();
    } catch (e) {
      users = readJsonTable('users');
    }
    res.json(users);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create direct Transaction record
app.post('/api/db/transactions', async (req, res) => {
  const { id, user, type, amount, details, status } = req.body;
  try {
    const txnId = id || (type.substring(0, 3).toUpperCase() + '_' + Math.floor(100000 + Math.random() * 900000));
    const txn = await prisma.transaction.create({
      data: {
        id: txnId,
        user,
        type,
        amount: parseFloat(amount),
        details,
        status,
        timestamp: new Date()
      }
    });
    res.json(txn);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all deposits
app.get('/api/db/deposits', async (req, res) => {
  try {
    const deposits = await prisma.deposit.findMany();
    res.json(deposits);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Deposit
app.post('/api/db/deposits', async (req, res) => {
  const { deposit_id, order_id, username, amount, utr, qr_type, custom_qr_data, status, gateway, gateway_id, created_at, updated_at } = req.body;
  try {
    const dep = await prisma.deposit.create({
      data: {
        deposit_id,
        order_id: order_id || null,
        username,
        amount: parseFloat(amount),
        utr: utr || null,
        qr_type: qr_type || null,
        custom_qr_data: custom_qr_data || null,
        status,
        gateway: gateway || null,
        gateway_id: gateway_id || null,
        created_at: created_at ? new Date(created_at) : new Date(),
        updated_at: updated_at ? new Date(updated_at) : new Date()
      }
    });
    res.json(dep);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Complete Deposit & transaction atomically (Webhooks flow)
app.post('/api/db/deposits/complete', async (req, res) => {
  const { orderId, paymentId } = req.body;
  try {
    const result = await prisma.$transaction(async (tx) => {
      const deposit = await tx.deposit.findUnique({
        where: { order_id: orderId }
      });
      if (!deposit) return { error: 'Deposit order not found.' };
      if (deposit.status !== 'Pending') return { success: true, message: 'Deposit already processed.' };

      // Update deposit status
      await tx.deposit.update({
        where: { deposit_id: deposit.deposit_id },
        data: { status: 'Completed', gateway_id: paymentId, updated_at: new Date() }
      });

      // Update user balance
      const user = await tx.user.findFirst({
        where: { username: { equals: deposit.username, mode: 'insensitive' } }
      });
      if (user) {
        await tx.user.update({
          where: { id: user.id },
          data: { wallet_balance: user.wallet_balance + deposit.amount }
        });
      }

      // Update transaction status
      const txn = await tx.transaction.findFirst({
        where: {
          user: deposit.username,
          details: { contains: orderId }
        }
      });
      if (txn) {
        await tx.transaction.update({
          where: { id: txn.id },
          data: { status: 'Completed' }
        });
      } else {
        await tx.transaction.create({
          data: {
            id: newRecordId('DEP'),
            user: deposit.username,
            type: 'Deposit',
            amount: deposit.amount,
            details: `Razorpay Deposit: ${paymentId}`,
            status: 'Completed',
            timestamp: new Date()
          }
        });
      }

      return { success: true, amount: deposit.amount, user: deposit.username };
    });

    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all withdrawals
app.get('/api/db/withdrawals', async (req, res) => {
  try {
    const withdrawals = await prisma.withdrawal.findMany();
    res.json(withdrawals);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Withdrawal
app.post('/api/db/withdrawals', async (req, res) => {
  const { withdrawal_id, username, amount, method, details, status, created_at, updated_at } = req.body;
  try {
    const wth = await prisma.withdrawal.create({
      data: {
        withdrawal_id,
        username,
        amount: parseFloat(amount),
        method,
        details,
        status,
        created_at: created_at ? new Date(created_at) : new Date(),
        updated_at: updated_at ? new Date(updated_at) : new Date()
      }
    });
    res.json(wth);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Get all payment logs
app.get('/api/db/payment-logs', async (req, res) => {
  try {
    const logs = await prisma.paymentLog.findMany();
    res.json(logs);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create Payment Log
app.post('/api/db/payment-logs', async (req, res) => {
  const { id, payload, signature, timestamp } = req.body;
  try {
    const log = await prisma.paymentLog.create({
      data: {
        id: id || 'LOG_' + Math.floor(100000 + Math.random() * 900000),
        payload,
        signature,
        timestamp: timestamp ? new Date(timestamp) : new Date()
      }
    });
    res.json(log);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fetch Game State (Ongoing game rounds - color_guess_ongoing, aviator_ongoing, teenpatti_ongoing)
app.get('/api/db/state/:key', async (req, res) => {
  const { key } = req.params;
  try {
    const state = await prisma.gameState.findUnique({
      where: { key }
    });
    res.json(state ? state.data : null);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Update/Save Game State
app.post('/api/db/state/:key', async (req, res) => {
  const { key } = req.params;
  const { data } = req.body;
  try {
    const state = await prisma.gameState.upsert({
      where: { key },
      update: { data },
      create: { key, data }
    });
    res.json({ success: true, state });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Fetch Recent Results for a room
app.get('/api/db/recent-results', async (req, res) => {
  const { room } = req.query;
  try {
    const results = await prisma.recentResult.findMany({
      where: room ? { room } : {},
      orderBy: { id: 'desc' },
      take: 20
    });
    // Format timestamp as time string for compatibility with frontend if needed
    const formatted = results.map(r => ({
      roundNumber: r.roundNumber,
      number: r.number,
      color: r.color,
      dotClass: r.dotClass,
      size: r.size,
      timestamp: new Date(r.timestamp).toLocaleTimeString('en-US', { hour12: false })
    }));
    res.json(formatted);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create/Upsert Recent Result
app.post('/api/db/recent-results', async (req, res) => {
  const { room, roundNumber, number, color, dotClass, size } = req.body;
  try {
    const result = await prisma.recentResult.upsert({
      where: {
        room_roundNumber: {
          room,
          roundNumber: String(roundNumber)
        }
      },
      update: {
        number: parseInt(number),
        color,
        dotClass,
        size
      },
      create: {
        room,
        roundNumber: String(roundNumber),
        number: parseInt(number),
        color,
        dotClass,
        size
      }
    });
    res.json({ success: true, result });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- UNIFIED GAMING BACKEND ENGINE (NODE.JS) ---

// Realistic filler names for empty-seat auto-fill — no seat is ever named/labeled "bot" anywhere in
// the app. The only seat that ever wins on purpose is explicitly renamed to "Admin" at the exact
// moment the takeover algorithm selects it (see tpStartRound's ADMIN AUTO-WIN / AI BOT TAKEOVER
// branches below); every other auto-filled seat just gets a plain human-looking name.
const TP_SIMULATED_NAMES = [
  'Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Arav', 'Pranav', 'Krishna',
  'Ishaan', 'Shaurya', 'Atharv', 'Rohan', 'Rudra', 'Aryan', 'Dev', 'Karan', 'Dhruv', 'Siddharth',
  'Ananya', 'Diya', 'Ishika', 'Kiara', 'Myra', 'Aria', 'Saanvi', 'Riya', 'Prisha', 'Anika'
];
function randomFillerName() {
  return TP_SIMULATED_NAMES[Math.floor(Math.random() * TP_SIMULATED_NAMES.length)] + '_' + (10 + Math.floor(Math.random() * 90));
}

// Called by every seat-fill path right before it occupies a seat with an ordinary filler player.
//
// This used to also decide whether the seat being filled was "Admin" — rooms were pre-selected at
// toggle time (round(pct/100 * 6) of them, a separate percentage-of-rooms calculation) to reserve a
// random arrival position for the house's own seat, independent of the per-round decision every
// other rig path draws from. That meant a room could win its "one guaranteed Admin seat" from this
// mechanism on top of whatever the per-round engine also produced afterwards — two independent
// percentage-pct mechanisms stacking instead of summing to the one percentage the operator configured
// is exactly why 50% could show up as "8 of 10 games." Every seat filled through here is now always
// an ordinary filler; "Admin" is seated exactly one way, in tpStartRound, for hands that table's own
// ledger selected — one ledger per table, one percentage, no double-booking.
function nextRoomFillerUsername() {
  return { username: randomFillerName(), is_bot: true };
}

function tpShuffle(arr) {
  const a = [...arr];
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

// Clearing admin_rig (done by every caller) stops any FUTURE round from being rigged, but does
// nothing about "Admin" already sitting in a seat from before the toggle changed — tpStartRound's
// ADMIN AUTO-WIN check rigs a round for a seated Admin unconditionally, "however it got seated", so a
// stale seat kept auto-winning every hand indefinitely even after the operator turned the bot off.
// This vacates that seat too, so disabling actually stops the room, not just the next round.
//
// A hand already in progress (status 'playing') is left alone — the cards are dealt and any stakes
// are already committed, so retroactively evicting the seat mid-hand would corrupt that round rather
// than fix anything. tpEndGame's own post-round reset already won't reseed Admin once the bot is off
// (shouldBotRigThisRound returns false), so an in-flight hand self-corrects the moment it finishes.
async function evictStaleAdminSeat(roomId) {
  try {
    const room = await prisma.teenPattiRoom.findUnique({ where: { id: roomId } });
    if (!room || room.status === 'playing') return;
    const result = await prisma.teenPattiSeat.updateMany({
      where: { room_id: roomId, username: 'Admin' },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });
    if (result.count > 0) {
      logger.info('evicted stale Admin seat after bot takeover toggled off', { roomId });
    }
  } catch (e) {
    console.error(`[TP] Error evicting stale Admin seat in ${roomId}:`, e.message);
  }
}

// Central AI Bot Takeover In-Memory State & DB Sync
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

async function initBotTakeoverState() {
  try {
    const keys = Object.keys(botTakeoverState);
    for (const k of keys) {
      const record = await prisma.gameState.findUnique({ where: { key: `bot_takeover_${k}` } });
      if (record && record.data) {
        botTakeoverState[k] = { ...botTakeoverState[k], ...record.data };
      }
    }

    // If Teen Patti's bot takeover isn't active on boot (or is active but a stale seat/rig record
    // somehow survived — e.g. the process crashed mid-hand), make sure no room comes back up with a
    // leftover "Admin" seat or a leftover admin_rig flag it shouldn't have. There is deliberately no
    // "arm N of 6 rooms" step here: each table decides per hand from its own ledger in tpStartRound,
    // so a selection computed once at boot — against rooms nobody is sitting at yet — would be
    // meaningless as well as duplicative.
    if (!botTakeoverState.teenpatti || !botTakeoverState.teenpatti.enabled) {
      const tpRooms = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
      for (const rId of tpRooms) {
        await prisma.teenPattiRoom.update({
          where: { id: rId },
          data: { admin_rig: null }
        });
        await evictStaleAdminSeat(rId);
      }
    }
  } catch (err) {
    console.error("Error initializing bot takeover state:", err);
  }
}
initBotTakeoverState();

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

// --- Memory-Tracked Bucketed Bot Decision Engine ---
//
// v1 of this was a plain running counter: `shouldRig = (counter % 100) < pct`. That handed out the
// first `pct` calls out of every 100 all true in a solid unbroken row, then the rest all false — an
// operator watching soon after enabling the bot saw a long deterministic streak, not a coin flip.
//
// v2 fixed the streak by shuffling a 100-slot bag (pct true, the rest false) instead of counting
// through it in order. That is exact over every complete 100-draw cycle, but a genuinely independent
// shuffle can still cluster locally — nothing stops 8 of the first 10 slots in a random permutation
// of 50 true/50 false from landing true purely by chance (measured: ~4.6% of 10-round windows did,
// almost as bad as a plain 50% coin flip). That is exactly what was reported next: "10 games, 8 won
// by admin, at 50%."
//
// v3 (this one) keeps every 100-slot cycle exact — for ANY integer percentage, not just multiples of
// ten, with no rounding drift ever — while also keeping every 10-round window close to the
// configured ratio. It splits the 100 slots into 10 buckets of 10, hands each bucket
// floor((i+1)*pct/10) - floor(i*pct/10) true slots (the standard "spread K items across N buckets as
// evenly as possible" formula — every bucket gets within one of every other bucket, and the ten
// bucket counts always sum to exactly `pct`), shuffles the true/false slots *within* each bucket for
// genuine per-round unpredictability, then shuffles the *order the buckets are drawn in* so which
// bucket comes first isn't fixed either. Measured improvement at 50%: the chance of an 8-or-worse
// 10-round window drops from ~4.6% to ~0.5% — the same 100 draws are still exactly 50/50 rigged, but
// no longer clumped.
//
// The in-progress bag doubles as the "memory" this is asking for: it is what decides whether the
// next match should be rigged, it is exactly what determines when the house last entered a room
// (lastRiggedAt below), and it is persisted per game (bot_rig_bag_<gameKey> in GameState) so a
// restart resumes the current cycle instead of silently starting a fresh one.
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
    buckets.push(tpShuffle(slots));
  }
  const queue = [].concat(...tpShuffle(buckets));
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
      await prisma.gameState.upsert({
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
      const record = await prisma.gameState.findUnique({ where: { key: `bot_rig_bag_${gameKey}` } });
      if (record && record.data && Array.isArray(record.data.queue)) {
        botRigBags[gameKey] = record.data;
      }
    } catch (e) { /* a fresh bag on next draw is a safe fallback */ }
  }
}
// Called here, immediately after its own definition, and not any earlier: botRigBags is a `const`
// declared further up this file but not yet *initialized* at the point initBotTakeoverState() runs,
// so calling this any earlier throws "Cannot access 'botRigBags' before initialization" the moment
// the for-of loop above evaluates Object.keys(botRigBags).
loadBotRigBags(); // resume each game's in-progress rig cycle instead of starting a fresh one on restart

// --- Live Active-User Tracking & Percentage-Based Targeting Engine ---
// Generalizes the Mines MINES_USER_SESSIONS/target_users precedent into a single, continuous,
// server-side mechanism that works for every game: whenever the bot is enabled at profit_pct X% for
// a game, a randomly-sampled X%-of-currently-live-users subset is kept fresh on a timer — entirely
// server side, so it keeps running even if the admin panel is never opened / gets closed.
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
  const remaining = tpShuffle(live.filter(u => !keep.includes(u)));
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

/**
 * Call this once per round/match/session for the given game.
 * Returns { shouldRig: boolean, profit_pct: number, active: boolean, source: string }
 *
 * `ledgerKey` optionally splits the 100-slot cycle into independent sub-ledgers while keeping a
 * single shared on/off/percentage config. Colour Prediction needs this: its four rooms run on
 * different clocks (30s / 60s / 180s / 300s), so a single shared cycle let the fast room burn through
 * most of the rigged slots before the slow room had settled a handful of rounds — each room was
 * nominally at the configured percentage but none of them actually was. One ledger per room makes
 * every room exact on its own. Omitting it keeps the original single-cycle behaviour for every
 * existing caller.
 */
function shouldBotRigThisRound(gameKey, ledgerKey) {
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) {
    return { shouldRig: false, profit_pct: bot.profit_pct, active: false, source: 'none' };
  }

  const pct = bot.profit_pct || 90;
  const bag = ensureBotRigBag(ledgerKey || gameKey, pct);
  const shouldRig = bag.queue.pop();

  // The bag itself is the memory: totals for diagnostics, and lastRiggedAt records exactly when the
  // house last entered a room / changed an outcome for this game, which is what /api/bot_status
  // surfaces to the operator.
  bag.totalDecisions++;
  bag.lastDecisionAt = Date.now();
  if (shouldRig) { bag.totalRigged++; bag.lastRiggedAt = Date.now(); }
  persistBotRigBag(ledgerKey || gameKey); // must match the bag that was actually drawn from

  return { shouldRig, profit_pct: pct, active: true, source: bot.source };
}

// --- Bot Status API Endpoint (for client-side games to query) ---
// House-edge configuration is operator information; exposing it publicly told any player exactly
// when the next round was going to be rigged against them.
app.get('/api/bot_status/:gameKey', auth.requireAdmin, (req, res) => {
  const gameKey = req.params.gameKey || '';
  const bot = isBotTakeoverActive(gameKey);
  if (!bot.active) {
    return res.json({ active: false, shouldRig: false, profit_pct: 0, source: 'none' });
  }

  // Peek at the next slot in the bag without drawing it (games draw for real when they actually
  // resolve, via shouldBotRigThisRound). `counter` stays in the response for any existing consumer of
  // this diagnostic field: it now means "decisions already drawn from the current 100-slot cycle".
  // The rest is the "memory" itself, exposed for the operator: how many decisions this game has ever
  // drawn, how many were rigged, and exactly when the house last entered a room / changed an outcome.
  // Games that keep one ledger per room (Colour Prediction) need the room named to peek at the right
  // cycle; without ?room= this reports the game-level ledger, which for those games is not the one
  // any round actually draws from.
  const pct = bot.profit_pct || 90;
  const room = typeof req.query.room === 'string' && req.query.room ? req.query.room : null;
  const ledgerKey = room ? `${gameKey}:${room}` : gameKey;
  const bag = ensureBotRigBag(ledgerKey, pct);
  const shouldRig = bag.queue[bag.queue.length - 1];
  const counter = 100 - bag.queue.length;

  res.json({
    active: true,
    shouldRig,
    profit_pct: pct,
    source: bot.source,
    ledger: ledgerKey,
    counter,
    total_decisions: bag.totalDecisions,
    total_rigged: bag.totalRigged,
    last_decision_at: bag.lastDecisionAt,
    last_rigged_at: bag.lastRiggedAt
  });
});

// --- Rig Audit API (read-only) ---
// Answers "is the configured percentage actually what the engine is doing?" with measured numbers
// rather than impressions. Operator-only: it describes exactly when the house takes rounds.
//   GET /api/admin/rig-audit?window_ms=600000&game=teenpatti&recent=50
app.get('/api/admin/rig-audit', auth.requireAdmin, (req, res) => {
  const windowMs = parseInt(req.query.window_ms, 10);
  const report = rigAudit.report({
    sinceMs: Number.isFinite(windowMs) && windowMs > 0 ? windowMs : undefined,
    game: req.query.game || undefined
  });
  // A disabled game legitimately rigs 0% of its rounds, but the ledger still carries whatever
  // percentage is stored against it, so the raw drift read as an alarming "-90" when nothing was
  // wrong at all. Annotate each game with whether its bot was actually on, and suppress the drift
  // figure when it was not — a percentage the engine was never trying to hit is not a deviation.
  Object.keys(report.games || {}).forEach(game => {
    const conf = botTakeoverState[game];
    const enabled = !!(conf && conf.enabled);
    const summary = report.games[game];
    summary.bot_enabled = enabled;
    if (!enabled) {
      summary.configured_pct = 0;
      summary.drift_pct = null;
      Object.values(summary.per_instance || {}).forEach(inst => {
        inst.configured_pct = 0;
        inst.drift_pct = null;
      });
    }
  });

  const wantRecent = parseInt(req.query.recent, 10);
  res.json({
    ...report,
    configured: botTakeoverState,
    live_users_count: Object.keys(LIVE_USERS).reduce((acc, k) => {
      acc[k] = getLiveUsernames(k).length;
      return acc;
    }, {}),
    targeted_users: botTargetedUsers,
    // Games measured in concurrent tables rather than players report both counts, so an operator can
    // see "3 of 6 tables" directly instead of inferring it from a player count.
    live_instances_count: Object.keys(LIVE_INSTANCES).reduce((acc, k) => {
      acc[k] = getLiveInstances(k).length;
      return acc;
    }, {}),
    recent: Number.isFinite(wantRecent) && wantRecent > 0 ? rigAudit.recent(wantRecent, req.query.game || undefined) : undefined
  });
});

// --- Bot Rig Decision API (draws from the bag — call once per round resolution) ---
// When a username is supplied, the decision is based on whether THAT specific user is currently
// part of the bot's randomly-selected live-player subset (see refreshBotTargeting above) rather than
// the old anonymous per-round counter, so two simultaneous callers can get independent decisions.
app.post('/api/bot_decide/:gameKey', auth.requireAuth, (req, res) => {
  const gameKey = req.params.gameKey || '';
  const username = auth.actingUsername(req);

  if (username && LIVE_USERS[gameKey]) {
    markUserActive(gameKey, username);
    const bot = isBotTakeoverActive(gameKey);
    const targeted = isUserTargeted(gameKey, username);
    const shouldRig = bot.active && targeted;
    return res.json({ shouldRig, was_rigged: shouldRig, targeted, profit_pct: bot.profit_pct, active: bot.active, source: bot.source });
  }

  const decision = shouldBotRigThisRound(gameKey);
  res.json({ ...decision, was_rigged: decision.shouldRig });
});

// --- Super Admin Dashboard: real-money-shaped analytics, derived entirely from the Transaction
// ledger + live User table + the in-memory live-targeting engine already powering every game. No
// figure here is estimated or fabricated — every number is a direct aggregation of rows that already
// exist for other reasons (gameplay wager/payout transactions, User.created_at, LIVE_USERS).
//
// Classifies a Transaction's free-text `details` into which game it belongs to and whether it was a
// wager (stake taken from a player) or a win (payout given to a player). Cashier deposits/withdrawals
// and the signup welcome bonus are deliberately excluded — they're the player moving their own virtual
// funds, not a bet outcome, so they don't belong in house-profit or win/loss figures.
function classifyGameplayTransaction(details) {
  if (!details || typeof details !== 'string') return null;
  if (details.startsWith('UPI Deposit') || details.startsWith('Withdrawal Request') || details === 'Welcome Bonus Credits') return null;

  if (details.includes('Color Guess Wager')) return { game: 'color_guess', kind: 'wager' };
  if (details.includes('Color Guess Win Payout')) return { game: 'color_guess', kind: 'win' };
  if (details.includes('Aviator Wager')) return { game: 'aviator', kind: 'wager' };
  if (details.includes('Aviator Payout')) return { game: 'aviator', kind: 'win' };
  if (details.includes('Teen Patti Boot') || details.includes('Teen Patti Chaal')) return { game: 'teenpatti', kind: 'wager' };
  if (details.includes('Teen Patti Won Pot')) return { game: 'teenpatti', kind: 'win' };
  if (details.includes('Mines Bet')) return { game: 'mines', kind: 'wager' };
  if (details.includes('Mines Cash Out')) return { game: 'mines', kind: 'win' };
  return null;
}

const GAME_LABELS = {
  color_guess: 'Color Prediction', aviator: 'Aviator', teenpatti: 'Teen Patti', mines: 'Mines'
};

app.get('/api/admin/super-dashboard', async (req, res) => {
  try {
    let users = [];
    let transactions = [];
    try {
      users = await prisma.user.findMany({ select: { username: true, wallet_balance: true, created_at: true } });
      transactions = await prisma.transaction.findMany({ select: { id: true, user: true, type: true, amount: true, details: true, timestamp: true } });
    } catch (dbErr) {
      users = readJsonTable('users').map(u => ({ username: u.username, wallet_balance: u.wallet_balance, created_at: new Date(u.created_at || Date.now()) }));
      transactions = readJsonTable('transactions').map(t => ({ id: t.id, user: t.user, type: t.type, amount: t.amount, details: t.details, timestamp: new Date(t.timestamp || Date.now()) }));
    }

    const now = new Date();
    const todayKey = now.toISOString().slice(0, 10);
    const monthKey = now.toISOString().slice(0, 7);
    const startOfToday = new Date(todayKey + 'T00:00:00.000Z');
    const startOfMonth = new Date(monthKey + '-01T00:00:00.000Z');

    // --- Registered users ---
    const newToday = users.filter(u => u.created_at && new Date(u.created_at) >= startOfToday).length;
    const newThisMonth = users.filter(u => u.created_at && new Date(u.created_at) >= startOfMonth).length;

    // --- Live users (from the same continuous engine every game already uses) ---
    const liveByGame = {};
    const liveUnion = new Set();
    Object.keys(LIVE_USERS).forEach(gameKey => {
      const list = getLiveUsernames(gameKey);
      liveByGame[gameKey] = list.length;
      list.forEach(u => liveUnion.add(u.toLowerCase()));
    });

    // --- Gameplay aggregation: house profit (all-time / today / this month), per-game breakdown,
    //     per-day and per-month trend, and per-user net position for the winners/losers view. ---
    const perGame = {};
    Object.keys(GAME_LABELS).forEach(g => { perGame[g] = { label: GAME_LABELS[g], wagered: 0, paid_out: 0, bet_count: 0, win_count: 0 }; });

    const dailyMap = {};   // 'YYYY-MM-DD' -> profit
    const monthlyMap = {}; // 'YYYY-MM' -> profit
    const perUserNet = {}; // username(lowercased, display-cased) -> { wagered, won }

    let houseProfitAllTime = 0, houseProfitToday = 0, houseProfitThisMonth = 0;
    let totalWagered = 0, totalPaidOut = 0, totalBets = 0, totalWins = 0;
    const recentTx = [];

    transactions.forEach(t => {
      const cls = classifyGameplayTransaction(t.details);
      const ts = t.timestamp ? new Date(t.timestamp) : now;
      const dayKey = ts.toISOString().slice(0, 10);
      const mKey = ts.toISOString().slice(0, 7);
      const amt = parseFloat(t.amount) || 0;
      // "Admin" is the house's own seat (see Teen Patti's ADMIN AUTO-WIN — the account the house plays
      // through, not a customer). Its wins are the house's profit landing in its own wallet, not a
      // payout cost, and its wagers aren't a real customer's stake — so it's excluded from the
      // wagered/won ledger entirely to keep the sign of "house profit" correct. It still shows up in
      // the recent-activity feed below for transparency.
      const isHouseAccount = String(t.user || '').toLowerCase() === 'admin';

      if (cls && !isHouseAccount && (cls.kind === 'wager' || cls.kind === 'win')) {
        const signedProfit = cls.kind === 'wager' ? amt : -amt;
        houseProfitAllTime += signedProfit;
        if (ts >= startOfToday) houseProfitToday += signedProfit;
        if (ts >= startOfMonth) houseProfitThisMonth += signedProfit;
        dailyMap[dayKey] = (dailyMap[dayKey] || 0) + signedProfit;
        monthlyMap[mKey] = (monthlyMap[mKey] || 0) + signedProfit;

        const pg = perGame[cls.game];
        if (pg) {
          if (cls.kind === 'wager') { pg.wagered += amt; pg.bet_count++; totalWagered += amt; totalBets++; }
          else { pg.paid_out += amt; pg.win_count++; totalPaidOut += amt; totalWins++; }
        }

        const uKey = String(t.user || 'Unknown').toLowerCase();
        if (!perUserNet[uKey]) perUserNet[uKey] = { username: t.user, wagered: 0, won: 0 };
        if (cls.kind === 'wager') perUserNet[uKey].wagered += amt; else perUserNet[uKey].won += amt;
      }

      if (cls) {
        recentTx.push({
          id: t.id, user: t.user, type: t.type, amount: amt, details: t.details,
          game: GAME_LABELS[cls.game] || cls.game, kind: cls.kind, is_house: isHouseAccount, timestamp: ts.toISOString()
        });
      }
    });

    recentTx.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

    // --- Winners / losers: net = wagered - won. Positive net = house is profiting from this player
    //     (they're net down); negative net = the player is net up overall. ---
    const netEntries = Object.values(perUserNet).map(e => ({
      username: e.username, wagered: parseFloat(e.wagered.toFixed(2)), won: parseFloat(e.won.toFixed(2)),
      net: parseFloat((e.wagered - e.won).toFixed(2))
    }));
    const losingUsers = netEntries.filter(e => e.net > 0);     // house is up against them
    const winningUsers = netEntries.filter(e => e.net < 0);    // they're up against the house
    const breakEvenUsers = netEntries.filter(e => e.net === 0);

    const topLosers = [...losingUsers].sort((a, b) => b.net - a.net).slice(0, 8);
    const topWinners = [...winningUsers].sort((a, b) => a.net - b.net).slice(0, 8);

    const dailyTrend = Object.keys(dailyMap).sort().slice(-14).map(d => ({ date: d, profit: parseFloat(dailyMap[d].toFixed(2)) }));
    const monthlyTrend = Object.keys(monthlyMap).sort().slice(-12).map(m => ({ month: m, profit: parseFloat(monthlyMap[m].toFixed(2)) }));

    Object.keys(perGame).forEach(g => {
      const pg = perGame[g];
      pg.wagered = parseFloat(pg.wagered.toFixed(2));
      pg.paid_out = parseFloat(pg.paid_out.toFixed(2));
      pg.profit = parseFloat((pg.wagered - pg.paid_out).toFixed(2));
    });

    res.json({
      generated_at: now.toISOString(),
      users: {
        total_registered: users.length,
        new_today: newToday,
        new_this_month: newThisMonth
      },
      live: {
        total_unique: liveUnion.size,
        per_game: liveByGame
      },
      gameplay: {
        total_wagered: parseFloat(totalWagered.toFixed(2)),
        total_paid_out: parseFloat(totalPaidOut.toFixed(2)),
        total_bets: totalBets,
        total_wins: totalWins,
        house_profit_all_time: parseFloat(houseProfitAllTime.toFixed(2)),
        house_profit_today: parseFloat(houseProfitToday.toFixed(2)),
        house_profit_this_month: parseFloat(houseProfitThisMonth.toFixed(2)),
        daily_trend: dailyTrend,
        monthly_trend: monthlyTrend,
        per_game: perGame
      },
      players: {
        net_losing_count: losingUsers.length,
        net_winning_count: winningUsers.length,
        break_even_count: breakEvenUsers.length,
        top_losers: topLosers,
        top_winners: topWinners
      },
      bot_takeover: botTakeoverState,
      recent_transactions: recentTx.slice(0, 60)
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// 1. Centralized Aviator State Engine
let aviatorState = {
  round_id: 10001,
  phase: 'waiting',
  phase_start: Date.now(),
  duration: 5.0, // 5 seconds wait
  crash_point: 1.85,
  current_multiplier: 1.00,
  bets: [],
  history: [1.25, 4.80, 1.05, 2.10, 1.62]
};

let nextAviatorOverride = null;

// Aviator's live profit-advisory calculator — the Aviator equivalent of calculateColorOptimalOutcome.
// Computes what the admin's profit would be if the round crashed RIGHT NOW: still-pending stakes and
// already-lost stakes become house profit, while payouts already given to users who cashed out early
// are a cost. Optionally scoped to a subset of usernames (the bot's currently-targeted live players).
function calculateAviatorLiveProfit(bets, targetedUsernames) {
  const list = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scoped = targeted ? list.filter(b => targeted.has(String(b.username || '').toLowerCase())) : list;

  const pendingStake = scoped.filter(b => b.status === 'pending').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const lostStake = scoped.filter(b => b.status === 'lost').reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  const alreadyPaid = scoped.filter(b => b.status === 'won').reduce((s, b) => s + (parseFloat(b.amount) || 0) * (parseFloat(b.cashed_multiplier) || 1), 0);

  return {
    scoped_count: scoped.length,
    pending_stake: parseFloat(pendingStake.toFixed(2)),
    already_paid: parseFloat(alreadyPaid.toFixed(2)),
    profit_if_crash_now: parseFloat((pendingStake + lostStake - alreadyPaid).toFixed(2))
  };
}

// --- Aviator crash-point selection, driven by the live book -------------------------------------
//
// The original rigged crash point was `1.12 + Math.random() * 0.42` — a number that never looked at
// a single bet on the table. calculateAviatorLiveProfit above already knew what the round was
// actually worth, but nothing consumed it outside an admin readout. These two functions close that
// gap: the same profit figure the operator sees is now what decides the round.
//
// One property of this game drives the whole design. profit_if_crash_now is
// `pendingStake + lostStake - alreadyPaid`, and during a flight it can only ever move DOWN: the sole
// event that changes it is a player cashing out, which removes their stake from `pending` and adds
// `stake × multiplier` to `alreadyPaid`. So house profit peaks the instant the plane takes off and
// erodes from there. A naive "maximise profit" rule therefore degenerates to "crash at 1.00x every
// round", which would be maximally profitable and instantly obvious.
//
// So the real objective is: take the profit near its peak, but not so early that the crash history
// stops looking like a game. That is a stake-weighted trade-off, and it is what these two do —
// pickAviatorCrashPoint sets the ceiling before takeoff, and aviatorShouldCrashNow watches for the
// first sign of erosion during the flight and takes the money then.

const AVIATOR_CRASH_AGGRESSIVE = 1.12; // tightest plausible crash — used when a lot of stake is exposed
const AVIATOR_CRASH_RELAXED = 1.54;    // upper end of the rigged band — the original code's ceiling
const AVIATOR_CRASH_FLOOR = 1.10;      // never intercept below this: a sub-1.10 crash reads as broken

/**
 * Chooses the crash point for a round the takeover engine has already selected.
 *
 * Scaling is deliberate rather than cosmetic: crashing low costs credibility, so it is spent only
 * where it buys something. A round with heavy targeted exposure crashes near AVIATOR_CRASH_AGGRESSIVE
 * because the profit justifies it; a near-empty round is allowed to run to a natural-looking
 * multiplier, because holding it down would burn plausibility to win almost nothing.
 *
 * Returns null when the round has no targeted stake to act on, letting the caller keep its existing
 * behaviour untouched.
 */
function pickAviatorCrashPoint(bets, targetedUsernames) {
  const list = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;

  const pending = list.filter(b => b.status === 'pending');
  if (pending.length === 0) return null; // nothing at risk — caller keeps its no-bets behaviour

  const scoped = targeted ? pending.filter(b => targeted.has(String(b.username || '').toLowerCase())) : pending;
  const scopedStake = scoped.reduce((s, b) => s + (parseFloat(b.amount) || 0), 0);
  if (scopedStake <= 0) return null;

  // 0 → no meaningful exposure, 1 → at or above the "large round" reference.
  const ref = config.AVIATOR_HIGH_STAKE_REF > 0 ? config.AVIATOR_HIGH_STAKE_REF : 1000;
  const exposure = Math.max(0, Math.min(1, scopedStake / ref));

  const band = AVIATOR_CRASH_RELAXED - AVIATOR_CRASH_AGGRESSIVE;
  const base = AVIATOR_CRASH_RELAXED - (exposure * band);

  // A little jitter so repeated similar rounds do not produce an identical multiplier every time,
  // which would be a clearer tell than the low crash itself.
  const jitter = (Math.random() - 0.5) * 0.08;
  const crash = Math.max(AVIATOR_CRASH_FLOOR, base + jitter);
  return parseFloat(crash.toFixed(2));
}

/**
 * In-flight erosion check: has a cash-out started eating into the round's profit?
 *
 * Because profit only falls, any drop below the high-water mark means a player has taken money off
 * the table and the rest of the pending stake is now at risk of following. That is the moment to
 * crash. The `epsilon` avoids reacting to floating-point noise, and the multiplier floor keeps an
 * early cash-out from producing an implausible sub-1.10 crash.
 */
function aviatorShouldCrashNow(currentMultiplier, peakProfit, currentProfit) {
  if (currentMultiplier < AVIATOR_CRASH_FLOOR) return false;
  if (!Number.isFinite(peakProfit) || !Number.isFinite(currentProfit)) return false;
  const epsilon = 0.01;
  return currentProfit < peakProfit - epsilon;
}

function tickAviator() {
  const now = Date.now();
  const elapsed = (now - aviatorState.phase_start) / 1000;

  if (aviatorState.phase === 'waiting') {
    if (elapsed >= aviatorState.duration) {
      aviatorState.phase = 'running';
      aviatorState.phase_start = now;
      
      if (nextAviatorOverride && nextAviatorOverride >= 1.0) {
        // Manual admin override always takes priority — rigs the ENTIRE round (every pending bettor)
        aviatorState.crash_point = nextAviatorOverride;
        nextAviatorOverride = null;
        aviatorState._riggedThisRound = true;
        aviatorState._riggedTargets = null; // null = disclose to everyone pending this round
      } else {
        // Use the bot decision, scoped to the live-targeted-subset engine, to decide if/how this round is rigged
        const botDecision = shouldBotRigThisRound('aviator');
        const targeted = botTargetedUsers.aviator;
        const targetedHasPendingBet = targeted.length > 0 && aviatorState.bets.some(b => b.status === 'pending' && targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()));

        if (botDecision.shouldRig && (targeted.length === 0 || targetedHasPendingBet)) {
          // --- RIGGED ROUND: Crash early for admin profit ---
          // If a live-targeted subset exists, scope the "should we bother rigging" stake check to just
          // them; otherwise (no targeting info yet) fall back to the prior aggregate-stake behavior.
          const relevantBets = targeted.length > 0
            ? aviatorState.bets.filter(b => targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()))
            : aviatorState.bets;
          const totalStake = relevantBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
          if (totalStake > 0) {
            // The original fixed band is kept as both the fallback and a hard bound. The computed
            // crash point is clamped back into it, so smart selection can never produce a crash
            // outside the range this round could already have drawn at random — it only decides
            // *where* in that band to land, using the stake actually exposed instead of chance.
            let crash = parseFloat((AVIATOR_CRASH_AGGRESSIVE + Math.random() * (AVIATOR_CRASH_RELAXED - AVIATOR_CRASH_AGGRESSIVE)).toFixed(2));
            if (config.AVIATOR_SMART_CRASH) {
              const smart = pickAviatorCrashPoint(aviatorState.bets, targeted);
              if (smart !== null) {
                crash = Math.max(AVIATOR_CRASH_AGGRESSIVE, Math.min(AVIATOR_CRASH_RELAXED, smart));
              }
            }
            aviatorState.crash_point = crash;
          } else {
            // No bets — still crash low-ish to keep history looking natural
            aviatorState.crash_point = parseFloat((1.20 + Math.random() * 1.00).toFixed(2));
          }
          aviatorState._riggedThisRound = true;
          // Non-empty targeted subset → only THOSE bettors are disclosed as rigged when they lose;
          // empty (bot on, but no live-targeting info yet) → whole round is rigged, disclose to everyone.
          aviatorState._riggedTargets = targeted.length > 0 ? targeted.slice() : null;
        } else {
          // --- FAIR ROUND: Natural RNG crash point (bot off, or no targeted bettor is playing this round) ---
          const p = Math.random();
          if (Math.random() < 0.03) {
            aviatorState.crash_point = 1.00;
          } else {
            const crash = 0.99 / (1.0 - p);
            aviatorState.crash_point = Math.max(1.00, Math.min(50.0, Math.floor(crash * 100) / 100));
          }
          aviatorState._riggedThisRound = false;
          aviatorState._riggedTargets = null;
        }

        // Audit only — records what was decided, changes nothing about the round.
        rigAudit.record({
          game: 'aviator',
          round: aviatorState.round_id,
          configured_pct: botDecision.profit_pct,
          rigged: aviatorState._riggedThisRound,
          live: getLiveUsernames('aviator').length,
          targeted: targeted.length,
          note: aviatorState._riggedThisRound ? `rigged crash @ ${aviatorState.crash_point}` : 'fair round'
        });
      }
      aviatorState.current_multiplier = 1.00;
      // High-water mark for the in-flight erosion check. Profit is at its maximum the moment the
      // plane takes off and can only fall from here, so this is the number every later tick is
      // compared against.
      aviatorState._peakProfit = calculateAviatorLiveProfit(
        aviatorState.bets,
        aviatorState._riggedTargets
      ).profit_if_crash_now;
    }
  } else if (aviatorState.phase === 'running') {
    const computedMult = Math.exp(0.06 * elapsed);
    
    // Only apply in-flight crash intercept if this round was marked for rigging
    if (aviatorState._riggedThisRound && computedMult >= 1.15) {
      const targets = aviatorState._riggedTargets;
      const inFlightBets = aviatorState.bets.filter(b => b.status === 'pending' && (!targets || targets.some(u => u.toLowerCase() === (b.username || '').toLowerCase())));
      const inFlightStake = inFlightBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
      if (inFlightStake > 200 && computedMult >= aviatorState.crash_point * 0.9) {
        aviatorState.crash_point = Math.min(aviatorState.crash_point, parseFloat(computedMult.toFixed(2)));
      }

      // Erosion intercept. The original clamp above reacts to raw stake size; this one reacts to the
      // round's actual profit falling, which is the thing that matters and the only signal that a
      // player has just taken money off the table. Both can only ever pull the crash point DOWN
      // (Math.min), so neither can extend a flight or increase what the house pays out.
      if (config.AVIATOR_SMART_CRASH) {
        const liveProfit = calculateAviatorLiveProfit(aviatorState.bets, targets).profit_if_crash_now;
        if (aviatorShouldCrashNow(computedMult, aviatorState._peakProfit, liveProfit)) {
          aviatorState.crash_point = Math.min(aviatorState.crash_point, parseFloat(computedMult.toFixed(2)));
        }
        if (!Number.isFinite(aviatorState._peakProfit) || liveProfit > aviatorState._peakProfit) {
          // Late bets can legitimately raise the ceiling; track it so the comparison stays honest.
          aviatorState._peakProfit = liveProfit;
        }
      }
    }

    if (computedMult >= aviatorState.crash_point) {
      aviatorState.phase = 'crashed';
      aviatorState.phase_start = now;
      aviatorState.current_multiplier = aviatorState.crash_point;

      aviatorState.bets.forEach(b => {
        if (b.status === 'pending') {
          b.status = 'lost';
          const targets = aviatorState._riggedTargets;
          b.was_rigged = !!(aviatorState._riggedThisRound && (!targets || targets.some(u => u.toLowerCase() === (b.username || '').toLowerCase())));
        }
      });

      aviatorState.history.push(aviatorState.crash_point);
      if (aviatorState.history.length > 15) {
        aviatorState.history.shift();
      }
    } else {
      aviatorState.current_multiplier = computedMult;
    }
  } else if (aviatorState.phase === 'crashed') {
    if (elapsed >= 4.0) {
      aviatorState.phase = 'waiting';
      aviatorState.phase_start = now;
      aviatorState.duration = 5.0;
      aviatorState.round_id++;
      aviatorState.bets = [];
      aviatorState._riggedThisRound = false;
      aviatorState._riggedTargets = null;
      aviatorState._peakProfit = null; // cleared with the book it was measured against
    }
  }
}
setInterval(tickAviator, 100);

// Helper for Color prediction logic
function resolveColorNumber(num) {
  if (num === 0) return { color: 'Violet', dotClass: 'violet', size: 'Small' };
  if (num === 5) return { color: 'Violet', dotClass: 'violet', size: 'Big' };
  if ([1, 3, 7, 9].includes(num)) return { color: 'Green', dotClass: 'green', size: num >= 5 ? 'Big' : 'Small' };
  return { color: 'Red', dotClass: 'red', size: num >= 5 ? 'Big' : 'Small' };
}

// Calculate the exact optimal outcome for Admin profit across all numbers (0-9)
// `targetedUsernames`, when provided, scopes the profit/payout calculation to ONLY that subset of
// bettors (the bot's currently-targeted live players) — the returned best_number/max_profit then
// reflects the number that maximizes admin profit against just that subset, not the whole room.
// Omitting it (existing behavior, used by every manual-override call site) is unaffected.
function calculateColorOptimalOutcome(bets, roundSeed, targetedUsernames) {
  const roundBets = Array.isArray(bets) ? bets : [];
  const targeted = Array.isArray(targetedUsernames) && targetedUsernames.length > 0
    ? new Set(targetedUsernames.map(u => String(u).toLowerCase()))
    : null;
  const scopedBets = targeted ? roundBets.filter(b => targeted.has(String(b.username || '').toLowerCase())) : roundBets;
  const totalVolume = roundBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
  const scopedVolume = scopedBets.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);

  const outcomes = [];
  for (let n = 0; n <= 9; n++) {
    const resolved = resolveColorNumber(n);
    let playerPayout = 0;

    for (const b of scopedBets) {
      const amt = parseFloat(b.amount) || 0;
      if (b.category === 'color') {
        if (b.value === resolved.color) {
          playerPayout += amt * (b.value === 'Violet' ? 4.5 : 2.0);
        }
      } else if (b.category === 'number') {
        if (parseInt(b.value) === n) {
          playerPayout += amt * 9.0;
        }
      } else if (b.category === 'size') {
        if (b.value === resolved.size) {
          playerPayout += amt * 2.0;
        }
      }
    }

    const adminProfit = scopedVolume - playerPayout;
    outcomes.push({
      number: n,
      color: resolved.color,
      dotClass: resolved.dotClass,
      size: resolved.size,
      playerPayout: parseFloat(playerPayout.toFixed(2)),
      adminProfit: parseFloat(adminProfit.toFixed(2))
    });
  }

  // Find max and min profit
  const maxProfit = Math.max(...outcomes.map(o => o.adminProfit));
  const minProfit = Math.min(...outcomes.map(o => o.adminProfit));
  
  const bestCandidates = outcomes.filter(o => o.adminProfit === maxProfit);
  const worstCandidates = outcomes.filter(o => o.adminProfit === minProfit);

  // Pick deterministically among equally profitable choices using roundSeed
  const roundSeedNum = parseInt(String(roundSeed || '').slice(-5)) || 0;
  const best = bestCandidates[roundSeedNum % bestCandidates.length] || bestCandidates[0];
  const worst = worstCandidates[0] || outcomes[0];

  return {
    total_volume: parseFloat(totalVolume.toFixed(2)),
    total_bets_count: roundBets.length,
    scoped_volume: parseFloat(scopedVolume.toFixed(2)),
    scoped_bets_count: scopedBets.length,
    best_number: best.number,
    best_color: best.color,
    best_size: best.size,
    max_profit: best.adminProfit,
    min_payout: best.playerPayout,
    worst_number: worst.number,
    worst_loss: worst.playerPayout,
    outcomes: outcomes // Index 0..9 for fast lookup
  };
}

function generateInitialSeedHistory(room, currentSec) {
  const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
  const dur = durations[room] || 30;
  const history = [];
  for (let i = 10; i >= 1; i--) {
    const pastSec = currentSec - (i * dur);
    const rId = getColorRoundId(room, pastSec);
    const seedNum = parseInt(String(rId).slice(-5)) || 0;
    const num = seedNum % 10;
    const res = resolveColorNumber(num);
    history.push({
      roundNumber: rId,
      number: num,
      color: res.color,
      dotClass: res.dotClass,
      size: res.size,
      is_rigged: false,
      rig_desc: 'Natural Draw',
      timestamp: new Date(pastSec * 1000).toLocaleTimeString('en-US', { hour12: false })
    });
  }
  return history;
}

async function loadColorState() {
  const record = await prisma.gameState.findUnique({ where: { key: 'color_guess_ongoing' } });
  if (record && record.data) {
    const state = record.data;
    const nowSec = Math.floor(Date.now() / 1000);
    let updated = false;
    ['sapre', 'becone', 'emred', 'vip'].forEach(r => {
      if (!state[r]) state[r] = { last_settled_round: '', bets: {}, overrides: {}, history: [] };
      if (!state[r].history || state[r].history.length === 0) {
        state[r].history = generateInitialSeedHistory(r, nowSec);
        updated = true;
      }
    });
    if (updated) await saveColorState(state);
    return state;
  }
  
  const nowSec = Math.floor(Date.now() / 1000);
  const defaultState = {
    sapre: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('sapre', nowSec) },
    becone: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('becone', nowSec) },
    emred: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('emred', nowSec) },
    vip: { last_settled_round: '', bets: {}, overrides: {}, history: generateInitialSeedHistory('vip', nowSec) }
  };
  await prisma.gameState.create({
    data: { key: 'color_guess_ongoing', data: defaultState }
  });
  return defaultState;
}

// Every colour room, round and bet lives in ONE GameState row, and each caller reads that whole blob,
// mutates it, then writes the whole blob back — with awaits on both ends. Two requests overlapping in
// that window both read the same snapshot and the second write silently discards the first one's
// change. For a bet that is worse than it sounds: the stake is debited *before* the state is read, so
// the player pays and their bet simply vanishes, never settles, and is never paid out. Load testing
// caught it directly — a player bet Big on a round that came up Big and received nothing.
//
// Serialising every read-modify-write of that row removes the window. The work inside is short (an
// in-memory mutation plus one upsert), so queueing it costs nothing noticeable, and it keeps the
// existing single-blob storage design rather than restructuring the schema. It protects within this
// process only, which matches how the app is deployed — one Node process owning the game loops.
let colorStateQueue = Promise.resolve();
function withColorState(fn) {
  const result = colorStateQueue.then(fn, fn);
  // Keep the chain alive even if a caller throws, or every later mutation would reject forever.
  colorStateQueue = result.then(() => undefined, () => undefined);
  return result;
}

async function saveColorState(state) {
  await prisma.gameState.update({
    where: { key: 'color_guess_ongoing' },
    data: { data: state }
  });
}

function getColorRoundId(room, timestampSec) {
  const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
  const duration = durations[room] || 30;
  const roundStart = Math.floor(timestampSec / duration) * duration;
  
  const d = new Date(roundStart * 1000);
  const yyyy = d.getUTCFullYear();
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  const hh = String(d.getUTCHours()).padStart(2, '0');
  
  const bucket = Math.floor((roundStart % 3600) / duration);
  const bucketStr = String(bucket).padStart(3, '0');
  
  return `${yyyy}${mm}${dd}${hh}${bucketStr}`;
}

async function settleColorRound(room, targetRound, state) {
  const overrideKey = `color_guess_overrides_${room}`;
  const overrideRecord = await prisma.gameState.findUnique({ where: { key: overrideKey } });
  const override = overrideRecord ? overrideRecord.data : {};
  const roundBets = (state[room].bets && state[room].bets[targetRound]) ? state[room].bets[targetRound] : [];

  const bot = isBotTakeoverActive('color_guess');
  // Use deterministic round counter to decide if this round should be rigged.
  // Each room draws from its OWN 100-slot cycle: the four rooms settle at 30s/60s/180s/300s, so a
  // shared cycle let Sapre consume most of the rigged slots long before VIP had settled enough
  // rounds to see its share. Same configured percentage, now exact within every room independently.
  const botDecision = shouldBotRigThisRound('color_guess', `color_guess:${room}`);

  let num = null;
  let was_rigged = false;
  let rig_desc = '';

  if (override && override.number !== undefined && override.number !== null && override.number !== '') {
    num = parseInt(override.number);
    was_rigged = true;
    rig_desc = `Number Fixed: ${override.number} `;
  } else if (override && (override.rig_type === 'platform_profit' || override.rig_type === 'max_profit')) {
    const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
    num = optimal.best_number;
    was_rigged = true;
    rig_desc = `Auto-Rig: Max Profit `;
  } else if (override && override.rig_type === 'user_win') {
    const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
    num = optimal.worst_number;
    was_rigged = true;
    rig_desc = `Auto-Rig: User Win `;
  } else if (override && (override.color || override.size)) {
    let possible = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
    if (override.color) {
      const c = override.color;
      if (c === 'Green') possible = possible.filter(n => [1, 3, 5, 7, 9].includes(n));
      else if (c === 'Red') possible = possible.filter(n => [0, 2, 4, 6, 8].includes(n));
      else if (c === 'Violet') possible = possible.filter(n => [0, 5].includes(n));
    }
    if (override.size) {
      const sz = override.size;
      if (sz === 'Small') possible = possible.filter(n => n <= 4);
      else if (sz === 'Big') possible = possible.filter(n => n >= 5);
    }
    
    if (possible.length > 0) {
      const optimal = calculateColorOptimalOutcome(roundBets, targetRound);
      const bestPossible = optimal.outcomes
        .filter(o => possible.includes(o.number))
        .sort((a, b) => b.adminProfit - a.adminProfit);
      num = (bestPossible.length > 0) ? bestPossible[0].number : possible[0];
    } else {
      num = 0;
    }
    was_rigged = true;
    if (override.color) rig_desc += `Color Fixed: ${override.color} `;
    if (override.size) rig_desc += `Size Fixed: ${override.size} `;
  } else if (botDecision.shouldRig) {
    // --- Bot rig: only when a currently-targeted live player actually has a bet this round ---
    const targeted = botTargetedUsers.color_guess;
    const targetedHasBet = targeted.length > 0 && roundBets.some(b => targeted.some(u => u.toLowerCase() === (b.username || '').toLowerCase()));

    if (targeted.length === 0 || targetedHasBet) {
      // Pick the outcome that maximizes admin profit against the targeted subset specifically
      // (falls back to the whole room when no live-targeting info exists yet, i.e. `targeted` is empty).
      const optimal = calculateColorOptimalOutcome(roundBets, targetRound, targeted.length > 0 ? targeted : undefined);
      num = optimal.best_number;
      was_rigged = true;
      rig_desc = `🤖 AI Bot (${botDecision.profit_pct}% Target, ${targeted.length} targeted) - Rigged Round - Max Profit #${optimal.best_number}`;
    } else {
      // Bot wants to rig, but no currently-targeted user has a bet this round — resolve fairly instead
      num = Math.floor(Math.random() * 10);
      was_rigged = false;
      rig_desc = `🤖 AI Bot (targeted subset has no bet this round) - Fair #${num}`;
    }
  } else if (botDecision.active && !botDecision.shouldRig) {
    // --- FAIR ROUND (bot active but this round is allowed to be fair) ---
    num = Math.floor(Math.random() * 10);
    was_rigged = false;
    rig_desc = `🤖 AI Bot (${botDecision.profit_pct}% Target) - Fair Round - Natural #${num}`;
  } else {
    // --- No bot active: truly random outcome ---
    num = Math.floor(Math.random() * 10);
  }

  const resolved = resolveColorNumber(num);

  // Audit only — records the decision and what the house actually made on it. `adminProfit` for the
  // number that was drawn is already computed by the same helper the rig paths use, so this reports
  // realised house profit rather than an estimate.
  try {
    const auditOutcome = calculateColorOptimalOutcome(roundBets, targetRound);
    const drawn = auditOutcome.outcomes[num];
    rigAudit.record({
      game: 'color_guess',
      instance: room,
      round: targetRound,
      configured_pct: botDecision.profit_pct,
      rigged: was_rigged,
      live: getLiveUsernames('color_guess').length,
      targeted: botTargetedUsers.color_guess.length,
      house_profit: drawn ? drawn.adminProfit : null,
      note: rig_desc.trim() || 'natural draw'
    });
  } catch (e) { /* audit is diagnostic; never let it interrupt a settlement */ }

  const historyEntry = {
    roundNumber: targetRound,
    number: num,
    color: resolved.color,
    dotClass: resolved.dotClass,
    size: resolved.size,
    is_rigged: was_rigged,
    rig_desc: rig_desc.trim(),
    timestamp: new Date().toLocaleTimeString('en-US', { hour12: false })
  };
  
  if (!state[room].history) state[room].history = [];
  state[room].history.push(historyEntry);
  if (state[room].history.length > 20) {
    state[room].history.shift();
  }
  
  try {
    await prisma.recentResult.upsert({
      where: { room_roundNumber: { room, roundNumber: String(targetRound) } },
      update: { number: num, color: resolved.color, dotClass: resolved.dotClass, size: resolved.size },
      create: { room, roundNumber: String(targetRound), number: num, color: resolved.color, dotClass: resolved.dotClass, size: resolved.size }
    });
  } catch (err) {
    console.error("Error saving recent result:", err);
  }
  
  for (const b of roundBets) {
    let won = false;
    let multiplier = 0;
    
    if (b.category === 'color') {
      if (b.value === resolved.color) {
        won = true;
        multiplier = (b.value === 'Violet') ? 4.5 : 2.0;
      }
    } else if (b.category === 'number') {
      if (parseInt(b.value) === num) {
        won = true;
        multiplier = 9.0;
      }
    } else if (b.category === 'size') {
      if (b.value === resolved.size) {
        won = true;
        multiplier = 2.0;
      }
    }
    
    if (won) {
      const payout = b.amount * multiplier;
      const user = await prisma.user.findFirst({ where: { username: { equals: b.username, mode: 'insensitive' } } });
      if (user) {
        const newBal = user.wallet_balance + payout;
        await prisma.user.update({
          where: { id: user.id },
          data: { wallet_balance: newBal }
        });
        
        await prisma.transaction.create({
          data: {
            id: newRecordId('TX'),
            user: b.username,
            type: 'Deposit',
            amount: payout,
            details: `Color Guess Win Payout Room: ${room.toUpperCase()} Round #${targetRound} Selection: ${b.category} (${b.value})`,
            status: 'Completed'
          }
        });
      }
    }
  }
}

// Central Server Clock API endpoint
app.get('/api/server_time', (req, res) => {
  const now = Date.now();
  const nowSec = Math.floor(now / 1000);
  const avElapsed = (now - aviatorState.phase_start) / 1000;

  res.json({
    server_time: now,
    server_time_sec: nowSec,
    iso: new Date(now).toISOString(),
    rooms: {
      sapre: { duration: 30, time_left: 30 - (nowSec % 30), round_id: getColorRoundId('sapre', nowSec) },
      becone: { duration: 60, time_left: 60 - (nowSec % 60), round_id: getColorRoundId('becone', nowSec) },
      emred: { duration: 180, time_left: 180 - (nowSec % 180), round_id: getColorRoundId('emred', nowSec) },
      vip: { duration: 300, time_left: 300 - (nowSec % 300), round_id: getColorRoundId('vip', nowSec) }
    },
    aviator: {
      round_id: aviatorState.round_id,
      phase: aviatorState.phase,
      phase_start: aviatorState.phase_start,
      time_elapsed: avElapsed,
      time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
      duration: aviatorState.duration || 5.0,
      current_multiplier: aviatorState.current_multiplier,
      crash_point: aviatorState.crash_point
    }
  });
});

// Custom route proxies to implement Central Game Sync API
app.get('/api/game_sync.php', async (req, res) => {
  const action = req.query.action || '';

  // The admin views return every player's open bets, the active rig overrides and the takeover
  // targeting list, so they require an operator token.
  if (action === 'admin_get_live_state' || action === 'admin_get_games') {
    if (!req.auth || req.auth.role !== 'admin') {
      return res.status(403).json({ error: 'Administrator privileges required.' });
    }
  }

  const isOperator = !!(req.auth && req.auth.role === 'admin');
  // Never trust a `username` parameter for per-player views; an empty string simply means the caller
  // is browsing anonymously and gets the public round state with no personal bets or balance.
  const username = auth.actingUsername(req) || '';

  try {
    const now = Date.now();
    const nowSec = Math.floor(now / 1000);

    if (action === 'server_time') {
      const avElapsed = (now - aviatorState.phase_start) / 1000;
      return res.json({
        server_time: now,
        server_time_sec: nowSec,
        iso: new Date(now).toISOString(),
        rooms: {
          sapre: { duration: 30, time_left: 30 - (nowSec % 30), round_id: getColorRoundId('sapre', nowSec) },
          becone: { duration: 60, time_left: 60 - (nowSec % 60), round_id: getColorRoundId('becone', nowSec) },
          emred: { duration: 180, time_left: 180 - (nowSec % 180), round_id: getColorRoundId('emred', nowSec) },
          vip: { duration: 300, time_left: 300 - (nowSec % 300), round_id: getColorRoundId('vip', nowSec) }
        },
        aviator: {
          round_id: aviatorState.round_id,
          phase: aviatorState.phase,
          phase_start: aviatorState.phase_start,
          time_elapsed: avElapsed,
          time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
          duration: aviatorState.duration || 5.0,
          current_multiplier: aviatorState.current_multiplier,
          crash_point: aviatorState.crash_point
        }
      });
    } else if (action === 'color_get_state') {
      const room = req.query.room || 'sapre';
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      const duration = durations[room] || 30;

      const time_left = duration - (nowSec % duration);
      const round_id = getColorRoundId(room, nowSec);

      // Settlement is driven by this polling endpoint, so with a crowd in a room dozens of requests
      // reach it at once the instant a round ends. The alreadySettled guard only protects against a
      // round already settled in the snapshot this request read — two pollers reading before either
      // writes would both see "not settled" and both pay the round out. Serialising the whole
      // read-settle-write makes that guard actually hold.
      const state = await withColorState(async () => {
        const st = await loadColorState();

        const prev_round_id = getColorRoundId(room, nowSec - duration);

        let stateChanged = false;
        if (!st[room].last_settled_round) {
          st[room].last_settled_round = prev_round_id;
          stateChanged = true;
        } else if (st[room].last_settled_round !== prev_round_id) {
          const alreadySettled = st[room].history && st[room].history.some(h => String(h.roundNumber) === String(prev_round_id));
          if (!alreadySettled) {
            await settleColorRound(room, prev_round_id, st);
          }
          st[room].last_settled_round = prev_round_id;
          stateChanged = true;
        }
        if (stateChanged) {
          await saveColorState(st);
        }
        return st;
      });

      const activeBets = (state[room].bets && state[room].bets[round_id]) ? state[room].bets[round_id] : [];
      const myBets = activeBets.filter(b => b.username.toLowerCase() === username.toLowerCase());
      const overridesRecord = await prisma.gameState.findUnique({ where: { key: `color_guess_overrides_${room}` } });

      const user = await getOrCreateUser(username);
      markUserActive('color_guess', username);
      const optimal = calculateColorOptimalOutcome(activeBets, round_id);
      const targetedUsers = botTargetedUsers.color_guess;
      const optimalTargeted = targetedUsers.length > 0 ? calculateColorOptimalOutcome(activeBets, round_id, targetedUsers) : null;

      res.json({
        server_time: now,
        server_time_sec: nowSec,
        round_id,
        time_left,
        duration,
        history: state[room].history || [],
        bets: myBets,
        overrides: overridesRecord ? overridesRecord.data : {},
        wallet_balance: user ? user.wallet_balance : 0,
        active_users: activeBets.length,
        // House-side planning data stays server-side unless an operator is asking. Shipping it to
        // players told them the winning number before the round closed.
        optimal_rig: isOperator ? optimal : undefined,
        optimal_rig_targeted: isOperator ? optimalTargeted : undefined,
        targeted_usernames: isOperator ? targetedUsers : undefined
      });
    } else if (action === 'aviator_get_state') {
      const elapsed = (now - aviatorState.phase_start) / 1000;

      const user = await getOrCreateUser(username);
      const balance = user ? user.wallet_balance : 0;
      markUserActive('aviator', username);

      res.json({
        server_time: now,
        server_time_sec: nowSec,
        round_id: aviatorState.round_id,
        phase: aviatorState.phase,
        phase_start: aviatorState.phase_start,
        time_elapsed: elapsed,
        time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - elapsed) : 0,
        duration: aviatorState.duration || 5.0,
        current_multiplier: aviatorState.current_multiplier,
        crash_point: aviatorState.crash_point,
        bets: aviatorState.bets,
        history: aviatorState.history,
        wallet_balance: balance
      });
    } else if (action === 'admin_get_live_state' || action === 'admin_get_games') {
      // Unified admin live state endpoint
      const avElapsed = (now - aviatorState.phase_start) / 1000;

      // Color guess state for all rooms
      const colorGuess = {};
      const rooms = ['sapre', 'becone', 'emred', 'vip'];
      const durations = { sapre: 30, becone: 60, emred: 180, vip: 300 };
      // Same serialisation as the player-facing state endpoint: this admin view settles rounds too,
      // and an operator refreshing the console while a room is settling must not race the players.
      await withColorState(async () => {
      const state = await loadColorState();

      let stateChanged = false;
      for (const room of rooms) {
        const duration = durations[room] || 30;
        const time_left = duration - (nowSec % duration);
        const round_id = getColorRoundId(room, nowSec);

        const prev_round_id = getColorRoundId(room, nowSec - duration);

        if (!state[room].last_settled_round) {
          state[room].last_settled_round = prev_round_id;
          stateChanged = true;
        } else if (state[room].last_settled_round !== prev_round_id) {
          const alreadySettled = state[room].history && state[room].history.some(h => String(h.roundNumber) === String(prev_round_id));
          if (!alreadySettled) {
            await settleColorRound(room, prev_round_id, state);
          }
          state[room].last_settled_round = prev_round_id;
          stateChanged = true;
        }

        const activeBets = (state[room].bets && state[room].bets[round_id]) ? state[room].bets[round_id] : [];
        const overridesRecord = await prisma.gameState.findUnique({ where: { key: `color_guess_overrides_${room}` } });
        const optimal = calculateColorOptimalOutcome(activeBets, round_id);
        const colorTargeted = botTargetedUsers.color_guess;
        const optimalTargeted = colorTargeted.length > 0 ? calculateColorOptimalOutcome(activeBets, round_id, colorTargeted) : null;

        colorGuess[room] = {
          round_id,
          time_left,
          duration,
          history: state[room].history || [],
          bets: activeBets,
          overrides: overridesRecord ? overridesRecord.data : {},
          optimal_rig: optimal,
          optimal_rig_targeted: optimalTargeted,
          targeted_usernames: colorTargeted
        };
      }
      if (stateChanged) {
        await saveColorState(state);
      }
      });

      const liveUsersCount = {};
      Object.keys(LIVE_USERS).forEach(k => { liveUsersCount[k] = getLiveUsernames(k).length; });

      res.json({
        server_time: now,
        server_time_sec: nowSec,
        aviator: {
          round_id: aviatorState.round_id,
          phase: aviatorState.phase,
          time_elapsed: avElapsed,
          phase_start: aviatorState.phase_start,
          time_left: aviatorState.phase === 'waiting' ? Math.max(0, aviatorState.duration - avElapsed) : 0,
          duration: aviatorState.duration || 5.0,
          current_multiplier: aviatorState.current_multiplier,
          crash_point: aviatorState.crash_point,
          bets: aviatorState.bets,
          history: aviatorState.history,
          targeted_usernames: botTargetedUsers.aviator,
          live_profit_targeted: calculateAviatorLiveProfit(aviatorState.bets, botTargetedUsers.aviator)
        },
        color_guess: colorGuess,
        teen_patti: [],
        bot_takeover: botTakeoverState,
        bot_targeted_users: botTargetedUsers,
        live_users_count: liveUsersCount
      });
    } else {
      res.status(400).json({ error: 'Unsupported GET action' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Actions that reconfigure the house rather than place a bet.
const GAME_SYNC_ADMIN_ACTIONS = new Set(['admin_set_bot_takeover', 'admin_set_override']);

app.post('/api/game_sync.php', async (req, res) => {
  const action = req.query.action || req.body.action || '';

  if (GAME_SYNC_ADMIN_ACTIONS.has(action)) {
    if (!req.auth || req.auth.role !== 'admin') {
      return res.status(403).json({ error: 'Administrator privileges required.' });
    }
  } else if (!req.auth) {
    // Every remaining action moves money (place bet / cash out), so none of them may run anonymously.
    return res.status(401).json({ error: 'Authentication required. Please sign in again.' });
  }

  // Authoritative account for this request. Reading it from the body previously let any caller bet
  // from, and cash out into, any other player's wallet.
  const username = auth.actingUsername(req);

  try {
    if (action === 'admin_set_bot_takeover') {
      const { game, enabled, profit_pct } = req.body;
      const gameKey = game || 'global';
      const isEnabled = String(enabled) === 'true' || enabled === true;
      const pct = parseInt(profit_pct) || 90;

      botTakeoverState[gameKey] = {
        enabled: isEnabled,
        profit_pct: Math.max(1, Math.min(100, pct))
      };

      await prisma.gameState.upsert({
        where: { key: `bot_takeover_${gameKey}` },
        update: { data: botTakeoverState[gameKey] },
        create: { key: `bot_takeover_${gameKey}`, data: botTakeoverState[gameKey] }
      });

      // The "global" master switch must reach every individual game's own config server-side, not
      // just admin.html's own UI (which happens to loop through every game itself today). Every game
      // is always pre-initialized with an explicit enabled:true/false, so isBotTakeoverActive()'s
      // per-game check short-circuits before it would ever fall through to a "global" default — a
      // bare `game:'global'` toggle with no per-game cascade would otherwise silently rig nothing.
      // Cascading here makes the backend correct on its own, independent of any particular frontend.
      if (gameKey === 'global') {
        for (const k of Object.keys(botTakeoverState)) {
          if (k === 'global') continue;
          botTakeoverState[k] = { enabled: isEnabled, profit_pct: Math.max(1, Math.min(100, pct)) };
          try {
            await prisma.gameState.upsert({
              where: { key: `bot_takeover_${k}` },
              update: { data: botTakeoverState[k] },
              create: { key: `bot_takeover_${k}`, data: botTakeoverState[k] }
            });
          } catch (e) { console.error(`Error cascading global bot state to ${k}:`, e.message); }
        }
      }

      // Immediately refresh the live-targeted-subset engine so the very first toggle takes effect
      // right away instead of waiting for the next 4s tick (the interval keeps it fresh afterward).
      if (gameKey === 'global') {
        Object.keys(LIVE_USERS).forEach(k => refreshBotTargeting(k));
      } else {
        if (LIVE_USERS[gameKey]) refreshBotTargeting(gameKey);
      }

      // Turning Teen Patti's bot takeover OFF must stop every room immediately, not just future
      // selection cycles: clearing the targeted set stops future hands, but a room where "Admin" is
      // already seated would otherwise keep auto-winning, because tpStartRound's ADMIN AUTO-WIN path
      // rigs for a seated Admin unconditionally, however that seat got there.
      //
      // Enabling needs no room bookkeeping here at all. Each table draws from its own ledger in
      // tpStartRound, which is the single mechanism deciding which hands are the house's — there is
      // no second "arm N of 6 rooms" pass, and adding one is precisely what turned a configured 50%
      // into "8 of 10 games" before.
      if ((gameKey === 'teenpatti' || gameKey === 'global') && !isEnabled) {
        const tpRooms = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
        for (const rId of tpRooms) {
          try {
            const room = await prisma.teenPattiRoom.findUnique({ where: { id: rId } });
            if (room && room.admin_rig) {
              await prisma.teenPattiRoom.update({
                where: { id: rId },
                data: { admin_rig: null }
              });
            }
            await evictStaleAdminSeat(rId);
          } catch (err) {
            console.error(`Error clearing bot seat for room ${rId}:`, err.message);
          }
        }
      }

      res.json({
        success: true,
        game: gameKey,
        config: botTakeoverState[gameKey],
        all_states: botTakeoverState
      });
    } else if (action === 'color_place_bet') {
      const { room } = req.body;
      const stake = validateStake(req.body.amount);
      if (!stake.ok) return res.status(400).json({ error: stake.error });
      const betAmt = stake.value;

      if (!room) {
        return res.status(400).json({ error: 'Invalid bet details.' });
      }
      if (!['sapre', 'becone', 'emred', 'vip'].includes(room)) {
        return res.status(400).json({ error: 'Unknown room.' });
      }

      const selection = normalizeColorSelection(req.body.category, req.body.value);
      if (!selection.ok) return res.status(400).json({ error: selection.error });
      const { category, value } = selection;

      const user = await getOrCreateUser(username);
      if (!user) return res.status(404).json({ error: 'Account not found.' });
      markUserActive('color_guess', username);

      const nowSec = Math.floor(Date.now() / 1000);
      const round_id = getColorRoundId(room, nowSec);

      // Single-statement conditional debit — see debitWallet(). The previous read-check-write
      // sequence let two concurrent bets spend the same balance twice.
      const newBal = await debitWallet(user.id, betAmt);
      if (newBal === null) {
        return res.status(400).json({ error: 'Insufficient wallet balance.' });
      }

      await prisma.transaction.create({
        data: {
          id: newRecordId('TX'),
          user: username,
          type: 'Withdrawal',
          amount: betAmt,
          details: `Color Guess Wager Room: ${room.toUpperCase()} Round #${round_id} Selection: ${category} (${value})`,
          status: 'Completed'
        }
      });

      // Serialised: see withColorState. Without this, concurrent bets overwrite each other and the
      // losing player has already been debited above.
      await withColorState(async () => {
        const state = await loadColorState();
        if (!state[room].bets) state[room].bets = {};
        if (!state[room].bets[round_id]) state[room].bets[round_id] = [];
        state[room].bets[round_id].push({
          username,
          category,
          value,
          amount: betAmt,
          timestamp: new Date().toISOString()
        });
        await saveColorState(state);
      });

      res.json({ success: true, new_balance: newBal });
    } else if (action === 'admin_set_override') {
      const { game, room, color, number, size, rig_type, crash_point, instant_crash, winner } = req.body;

      if (game === 'color_guess') {
        const overrideKey = `color_guess_overrides_${room}`;
        const overrides = { color: color || '', number: number || '', size: size || '', rig_type: rig_type || '' };
        
        await prisma.gameState.upsert({
          where: { key: overrideKey },
          update: { data: overrides },
          create: { key: overrideKey, data: overrides }
        });

        res.json({ success: true });
      } else if (game === 'aviator') {
        if (instant_crash === 'true') {
          if (aviatorState.phase === 'running') {
            aviatorState.phase = 'crashed';
            aviatorState.phase_start = Date.now();
            const finalCrash = parseFloat(crash_point) || aviatorState.current_multiplier;
            aviatorState.crash_point = Math.max(1.00, parseFloat(finalCrash.toFixed(2)));
            aviatorState.current_multiplier = aviatorState.crash_point;
            aviatorState._riggedThisRound = true;
            aviatorState._riggedTargets = null; // manual instant-crash rigs the whole round

            aviatorState.bets.forEach(b => {
              if (b.status === 'pending') {
                b.status = 'lost';
                b.was_rigged = true;
              }
            });
            aviatorState.history.push(aviatorState.crash_point);
            if (aviatorState.history.length > 15) aviatorState.history.shift();
          }
        } else {
          nextAviatorOverride = parseFloat(crash_point) || null;
        }
        res.json({ success: true });
      } else {
        res.status(400).json({ error: 'Unsupported game for override' });
      }
    } else if (action === 'aviator_place_bet') {
      const { console_id } = req.body;
      const stake = validateStake(req.body.amount);
      if (!stake.ok) return res.status(400).json({ error: stake.error });
      const betAmt = stake.value;

      // The UI has exactly two betting consoles. Anything else parses to NaN further down, and a bet
      // stored under a NaN console can never be matched by aviator_cashout (NaN === NaN is false) —
      // the stake would be taken for a bet the player is unable to cash out.
      const consoleId = parseInt(console_id, 10);
      if (consoleId !== 1 && consoleId !== 2) {
        return res.status(400).json({ error: 'Invalid bet details.' });
      }
      if (aviatorState.phase !== 'waiting') {
        return res.status(400).json({ error: 'Betting for this round has closed.' });
      }
      if (aviatorState.bets.some(b => b.username.toLowerCase() === String(username).toLowerCase() && b.console_id === consoleId && b.status === 'pending')) {
        return res.status(400).json({ error: 'You already have a bet on this console for this round.' });
      }

      const user = await getOrCreateUser(username);
      if (!user) return res.status(404).json({ error: 'Account not found.' });
      markUserActive('aviator', username);

      const newBal = await debitWallet(user.id, betAmt);
      if (newBal === null) {
        return res.status(400).json({ error: 'Insufficient wallet balance.' });
      }

      await prisma.transaction.create({
        data: {
          id: newRecordId('TX'),
          user: username,
          type: 'Withdrawal',
          amount: betAmt,
          details: `Aviator Wager Round #${aviatorState.round_id}`,
          status: 'Completed'
        }
      });

      aviatorState.bets.push({
        username,
        amount: betAmt,
        status: 'pending',
        console_id: consoleId,
        cashed_multiplier: 0,
        was_rigged: false
      });

      res.json({ success: true, new_balance: newBal });
    } else if (action === 'aviator_cashout') {
      const { console_id } = req.body;
      const cId = parseInt(console_id);

      const bet = aviatorState.bets.find(b => b.username.toLowerCase() === username.toLowerCase() && b.status === 'pending' && b.console_id === cId);
      if (!bet) {
        return res.status(400).json({ error: 'No active bet found for this console.' });
      }

      if (aviatorState.phase !== 'running') {
        return res.status(400).json({ error: 'The round is not in progress.' });
      }

      // Claim the bet before any await so a double-clicked cash-out cannot be paid twice.
      bet.status = 'won';
      bet.cashed_multiplier = aviatorState.current_multiplier;
      bet.was_rigged = false; // a successful cashout was never a rigged outcome
      const payout = Math.round(bet.amount * bet.cashed_multiplier * 100) / 100;

      const user = await getOrCreateUser(username);
      if (user) {
        const newBal = await creditWallet(user.id, payout);

        await prisma.transaction.create({
          data: {
            id: newRecordId('TX'),
            user: username,
            type: 'Deposit',
            amount: payout,
            details: `Aviator Payout @ ${bet.cashed_multiplier.toFixed(2)}x`,
            status: 'Completed'
          }
        });
        res.json({ success: true, multiplier: bet.cashed_multiplier, payout, new_balance: newBal });
      } else {
        res.status(404).json({ error: 'User not found.' });
      }
    } else {
      res.status(400).json({ error: 'Unsupported POST action' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Wallet adjustment proxy
// Legacy URL kept for the older frontend code paths. GET is a balance read for the signed-in
// player; any balance *change* is an operator action, exactly as with /api/wallet/adjust.
app.all('/api/wallet.php', walletLimiter, auth.requireAuth, async (req, res, next) => {
  const rawDelta = parseFloat(req.query.delta || req.body.delta || 0) || 0;
  const reason = req.query.reason || req.body.reason || 'Manual Adjustment';
  const isOperator = req.auth.role === 'admin';

  if (rawDelta !== 0 && !isOperator) {
    return res.status(403).json({ error: 'Administrator privileges required to adjust a balance.' });
  }

  const username = auth.actingUsername(req);
  const delta = rawDelta;

  try {
    const user = await getOrCreateUser(username);
    if (!user) {
      return res.status(404).json({ error: 'User not found.' });
    }

    if (delta === 0) {
      return res.json({ success: true, new_balance: user.wallet_balance });
    }

    const newBal = user.wallet_balance + delta;
    if (newBal < 0) {
      return res.status(400).json({ error: 'Insufficient wallet balance.' });
    }
    await prisma.user.update({
      where: { id: user.id },
      data: { wallet_balance: newBal }
    });

    await prisma.transaction.create({
      data: {
        id: newRecordId('TX'),
        user: username,
        type: delta >= 0 ? 'Deposit' : 'Withdrawal',
        amount: Math.abs(delta),
        details: reason,
        status: 'Completed'
      }
    });

    res.json({ success: true, new_balance: newBal });
  } catch (err) {
    next(err);
  }
});

// Auth proxy
app.all('/api/auth.php', authLimiter, async (req, res) => {
  const action = req.query.action || req.body.action || '';
  const username = req.query.username || req.body.username || '';
  const password = req.query.password || req.body.password || '';

  try {
    if (action === 'login') {
      // The original condition also accepted the literal passwords 'admin' and '123456' for *any*
      // account. That was a universal backdoor into every wallet on the platform.
      const user = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
      if (user && bcrypt.compareSync(password, user.password)) {
        res.json({
          success: true,
          token: generateAuthToken(user),
          user: { id: user.id, username: user.username, email: user.email, wallet_balance: user.wallet_balance }
        });
      } else {
        res.status(400).json({ error: 'Invalid credentials' });
      }
    } else if (action === 'signup') {
      const email = req.query.email || req.body.email || `${username.toLowerCase()}@demo.com`;
      const existing = await prisma.user.findFirst({ where: { username: { equals: username, mode: 'insensitive' } } });
      if (existing) {
        return res.status(400).json({ error: 'Username is already taken.' });
      }
      if (!password || password.length < 8) {
        return res.status(400).json({ error: 'Password must be at least 8 characters.' });
      }
      const hashedPassword = bcrypt.hashSync(password, config.BCRYPT_ROUNDS);
      const user = await prisma.user.create({
        data: {
          username: username,
          email: email,
          password: hashedPassword,
          wallet_balance: config.SIGNUP_BONUS
        }
      });
      res.json({
        success: true,
        token: generateAuthToken(user),
        user: { id: user.id, username: user.username, email: user.email, wallet_balance: user.wallet_balance }
      });
    } else if (action === 'status') {
      // Session-derived only; the old version reported on whatever username was asked for.
      const tokenUser = req.auth && req.auth.username;
      if (tokenUser) {
        const user = await prisma.user.findFirst({ where: { username: { equals: tokenUser, mode: 'insensitive' } } });
        return res.json(user
          ? { logged_in: true, user: { username: user.username, email: user.email, wallet_balance: user.wallet_balance } }
          : { logged_in: false });
      }
      res.json({ logged_in: false });
    } else if (action === 'logout') {
      res.json({ success: true });
    } else {
      res.json({ success: true, message: 'Auth endpoint working' });
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Sync full table data back from PHP db_transaction callback edits
// Bulk overwrite of an entire table. Already covered by the /api/db namespace guard above; the
// explicit repeat documents that this is the single most destructive endpoint in the service.
app.post('/api/db/:table/sync', auth.requireAdmin, async (req, res) => {
  const { table } = req.params;
  const data = req.body;
  try {
    if (!Array.isArray(data)) {
      return res.status(400).json({ error: 'Body must be an array' });
    }

    await prisma.$transaction(async (tx) => {
      if (table === 'users') {
        for (const item of data) {
          const existing = await tx.user.findUnique({ where: { username: item.username } });
          if (existing) {
            await tx.user.update({
              where: { id: existing.id },
              data: {
                email: item.email,
                password: item.password,
                wallet_balance: parseFloat(item.wallet_balance),
                created_at: new Date(item.created_at || Date.now())
              }
            });
          } else {
            await tx.user.create({
              data: {
                username: item.username,
                email: item.email,
                password: item.password,
                wallet_balance: parseFloat(item.wallet_balance),
                created_at: new Date(item.created_at || Date.now())
              }
            });
          }
        }
      } else if (table === 'transactions') {
        for (const item of data) {
          await tx.transaction.upsert({
            where: { id: item.id },
            update: {
              user: item.user,
              type: item.type,
              amount: parseFloat(item.amount),
              details: item.details,
              status: item.status,
              timestamp: new Date(item.timestamp || Date.now())
            },
            create: {
              id: item.id,
              user: item.user,
              type: item.type,
              amount: parseFloat(item.amount),
              details: item.details,
              status: item.status,
              timestamp: new Date(item.timestamp || Date.now())
            }
          });
        }
      } else if (table === 'deposits') {
        for (const item of data) {
          await tx.deposit.upsert({
            where: { deposit_id: item.deposit_id },
            update: {
              order_id: item.order_id || null,
              username: item.username,
              amount: parseFloat(item.amount),
              utr: item.utr || null,
              qr_type: item.qr_type || null,
              custom_qr_data: item.custom_qr_data || null,
              status: item.status,
              gateway: item.gateway || null,
              gateway_id: item.gateway_id || null,
              updated_at: new Date(item.updated_at || Date.now())
            },
            create: {
              deposit_id: item.deposit_id,
              order_id: item.order_id || null,
              username: item.username,
              amount: parseFloat(item.amount),
              utr: item.utr || null,
              qr_type: item.qr_type || null,
              custom_qr_data: item.custom_qr_data || null,
              status: item.status,
              gateway: item.gateway || null,
              gateway_id: item.gateway_id || null,
              created_at: new Date(item.created_at || Date.now()),
              updated_at: new Date(item.updated_at || Date.now())
            }
          });
        }
      } else if (table === 'withdrawals') {
        for (const item of data) {
          await tx.withdrawal.upsert({
            where: { withdrawal_id: item.withdrawal_id },
            update: {
              username: item.username,
              amount: parseFloat(item.amount),
              method: item.method,
              details: item.details,
              status: item.status,
              updated_at: new Date(item.updated_at || Date.now())
            },
            create: {
              withdrawal_id: item.withdrawal_id,
              username: item.username,
              amount: parseFloat(item.amount),
              method: item.method,
              details: item.details,
              status: item.status,
              created_at: new Date(item.created_at || Date.now()),
              updated_at: new Date(item.updated_at || Date.now())
            }
          });
        }
      } else if (table === 'payment_logs') {
        for (const item of data) {
          const logId = item.id || 'LOG_' + Math.floor(100000 + Math.random() * 900000);
          await tx.paymentLog.upsert({
            where: { id: logId },
            update: {
              payload: item.payload,
              signature: item.signature || null,
              timestamp: new Date(item.timestamp || Date.now())
            },
            create: {
              id: logId,
              payload: item.payload,
              signature: item.signature || null,
              timestamp: new Date(item.timestamp || Date.now())
            }
          });
        }
      }
    });

    res.json({ success: true });
  } catch (err) {
    console.error(`Sync error on table ${table}:`, err);
    res.status(500).json({ error: err.message });
  }
});
// ========================================================================
// TEEN PATTI — REAL-TIME MULTIPLAYER ENGINE
// ========================================================================

const TP_ROOMS = [
  { id: 'room_101', name: 'Room 1', boot_amount: 10 },
  { id: 'room_102', name: 'Room 2', boot_amount: 100 },
  { id: 'room_103', name: 'Room 3', boot_amount: 50 },
  { id: 'room_104', name: 'Room 4', boot_amount: 50 },
  { id: 'room_105', name: 'Room 5', boot_amount: 25 },
  { id: 'room_106', name: 'Room 6', boot_amount: 250 },
];

const TP_TURN_TIMEOUT = 15; // seconds
const TP_BOT_FILL_DELAY = 15000; // 15s before bots fill empty seats
const TP_BOT_THINK_MIN = 1500;
const TP_BOT_THINK_MAX = 3500;
const TP_ROUND_DELAY = 5000; // 5s between rounds
const TP_SEAT_HEARTBEATS = {};

// -- Card utilities --
function tpCreateDeck() {
  const suits = ['S', 'H', 'C', 'D'];
  const deck = [];
  for (let r = 2; r <= 14; r++) {
    for (const s of suits) deck.push({ r, s });
  }
  return tpShuffle(deck);
}

function tpEvaluateHand(cards) {
  if (!cards || cards.length < 3) return [0, [0], 0];
  const ranks = cards.map(c => c.r).sort((a, b) => b - a);
  const suits = cards.map(c => c.s || c.suit); // handle both raw {r,s} and formatted {r,suit}
  const isColor = suits[0] === suits[1] && suits[1] === suits[2];
  let isSeq = false;
  let seqTiebreak = ranks;
  if (ranks[0] - ranks[1] === 1 && ranks[1] - ranks[2] === 1) {
    isSeq = true;
  } else if (ranks[0] === 14 && ranks[1] === 3 && ranks[2] === 2) {
    isSeq = true;
    seqTiebreak = [3, 2, 1];
  }
  const bestSuit = Math.max(...suits.map(s => ({ S: 4, H: 3, C: 2, D: 1 }[s] || 0)));

  if (ranks[0] === ranks[1] && ranks[1] === ranks[2]) return [6, [ranks[0]], bestSuit];
  if (isSeq && isColor) return [5, seqTiebreak, bestSuit];
  if (isSeq) return [4, seqTiebreak, bestSuit];
  if (isColor) return [3, ranks, bestSuit];
  if (ranks[0] === ranks[1]) return [2, [ranks[0], ranks[2]], bestSuit];
  if (ranks[1] === ranks[2]) return [2, [ranks[1], ranks[0]], bestSuit];
  return [1, ranks, bestSuit];
}

function tpHandWins(a, b) {
  if (a[0] !== b[0]) return a[0] > b[0];
  for (let i = 0; i < a[1].length; i++) {
    if ((a[1][i] || 0) !== (b[1][i] || 0)) return a[1][i] > b[1][i];
  }
  return a[2] > b[2];
}

function tpFindObliviousWinningHand(deck, rivalHand) {
  let bestCandidateCards = null;
  let bestCandidateEval = null;

  for (let i = 0; i < deck.length - 2; i++) {
    for (let j = i + 1; j < deck.length - 1; j++) {
      for (let k = j + 1; k < deck.length; k++) {
        const cards = [deck[i], deck[j], deck[k]];
        const ev = tpEvaluateHand(cards);

        // Must strictly beat rivalHand
        if (tpHandWins(ev, rivalHand)) {
          // We want the candidate hand with minimum winning margin over rivalHand
          if (!bestCandidateEval || tpHandWins(bestCandidateEval, ev)) {
            bestCandidateEval = ev;
            bestCandidateCards = cards;
          }
        }
      }
    }
  }

  return { cards: bestCandidateCards, evaluation: bestCandidateEval };
}

function tpHandLabel(cat) {
  return { 6: 'Trail', 5: 'Pure Sequence', 4: 'Sequence', 3: 'Color', 2: 'Pair', 1: 'High Card' }[cat] || 'Unknown';
}

function tpRankLabel(r) {
  return { 11: 'J', 12: 'Q', 13: 'K', 14: 'A' }[r] || String(r);
}

function tpSuitSymbol(s) {
  return { S: '♠', H: '♥', C: '♣', D: '♦' }[s] || s;
}

function tpFormatCards(cards) {
  if (!cards) return null;
  return cards.map(c => {
    const suitKey = c.s || c.suit; // handle both raw {r,s} and pre-formatted {r,suit}
    return {
      label: tpRankLabel(c.r),
      suit: suitKey,
      symbol: tpSuitSymbol(suitKey),
      red: suitKey === 'H' || suitKey === 'D',
      r: c.r
    };
  });
}

// -- Seed rooms --
async function tpSeedRooms() {
  const defaultRooms = [
    { id: 'room_101', name: 'Room 1', boot_amount: 10 },
    { id: 'room_102', name: 'Room 2', boot_amount: 100 },
    { id: 'room_103', name: 'Room 3', boot_amount: 50 },
    { id: 'room_104', name: 'Room 4', boot_amount: 50 },
    { id: 'room_105', name: 'Room 5', boot_amount: 25 },
    { id: 'room_106', name: 'Room 6', boot_amount: 250 },
  ];

  for (const r of defaultRooms) {
    const existing = await prisma.teenPattiRoom.findUnique({ where: { id: r.id } });
    if (!existing) {
      await prisma.teenPattiRoom.create({
        data: {
          id: r.id,
          name: r.name,
          boot_amount: r.boot_amount,
          status: 'waiting',
          pot: 0,
          current_stake: r.boot_amount,
          log: [],
          seats: {
            create: [
              { seat: 0, is_bot: false },
              { seat: 1, is_bot: false },
              { seat: 2, is_bot: false },
              { seat: 3, is_bot: false },
            ]
          }
        }
      });
    } else {
      // Full reset: clear ALL seats from previous run so rooms start fresh at 0/4
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: r.id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      await prisma.teenPattiRoom.update({
        where: { id: r.id },
        data: { status: 'waiting', pot: 0, winner_seat: null, admin_rig: null }
      });
    }
  }
}

// -- Bot decision --
function tpBotDecide(cards, stake) {
  const hand = tpEvaluateHand(cards);
  const cat = hand[0];
  const rand = Math.random() * 100;
  if (cat >= 5) return 'chaal';
  if (cat === 4 && rand <= 90) return 'chaal';
  if (cat === 3 && rand <= 70) return 'chaal';
  if (cat === 2 && rand <= 55) return 'chaal';
  if (cat === 1 && rand <= 25) return 'chaal';
  return 'fold';
}

// -- Find next active seat --
function tpNextActiveSeat(seats, currentTurnSeat) {
  const activeSeatNums = seats
    .filter(s => s.username && !s.folded)
    .map(s => s.seat)
    .sort((a, b) => a - b);
  if (activeSeatNums.length === 0) return null;
  const idx = activeSeatNums.indexOf(currentTurnSeat);
  const nextIdx = (idx + 1) % activeSeatNums.length;
  return activeSeatNums[nextIdx];
}

// -- Deal cards and start a round --
async function tpStartRound(roomId) {
  const room = await prisma.teenPattiRoom.findUnique({
    where: { id: roomId },
    include: { seats: true }
  });
  if (!room) return;

  const occupiedSeats = room.seats.filter(s => s.username);
  if (occupiedSeats.length < 2) return; // need at least 2 players

  const bootAmt = room.boot_amount;

  // Verify all real players have sufficient balance for the boot
  let ejectedCount = 0;
  for (const seat of occupiedSeats) {
    if (!seat.is_bot && seat.username) {
      if (seat.username.toLowerCase() === 'admin') {
        const adminUser = await prisma.user.findFirst({ where: { username: 'Admin' } });
        if (adminUser) {
          await prisma.user.update({
            where: { id: adminUser.id },
            data: { wallet_balance: 5000.0 }
          });
        }
      }

      const user = await prisma.user.findFirst({
        where: { username: { equals: seat.username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < bootAmt) {
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        seat.username = null; // Mark as empty in memory
        ejectedCount++;
      }
    }
  }

  // Refetch occupied seats in memory
  const activeOccupied = occupiedSeats.filter(s => s.username);
  if (activeOccupied.length < 2) {
    // Not enough players left, cancel round and reset room to waiting
    await prisma.teenPattiSeat.updateMany({
      where: { room_id: roomId },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });
    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: { status: 'waiting', pot: 0, winner_seat: null }
    });
    return;
  }

  const deck = tpCreateDeck();
  let pot = 0;

  // Deal cards and deduct boot from each player
  for (const seat of activeOccupied) {
    const cards = [deck.shift(), deck.shift(), deck.shift()];
    pot += bootAmt;

    // Deduct boot from real player wallets
    if (!seat.is_bot && seat.username) {
      try {
        await prisma.user.updateMany({
          where: { username: { equals: seat.username, mode: 'insensitive' } },
          data: { wallet_balance: { decrement: bootAmt } }
        });
        await prisma.transaction.create({
          data: {
            id: newRecordId('TP'),
            user: seat.username,
            type: 'Withdrawal',
            amount: bootAmt,
            details: `Teen Patti Boot — ${room.name} Round #${room.round + 1}`,
            status: 'Completed'
          }
        });
      } catch (e) { console.error('[TP] Boot deduct error:', e.message); }
    }

    await prisma.teenPattiSeat.update({
      where: { id: seat.id },
      data: {
        cards: cards,
        folded: false,
        balance: seat.is_bot ? 5000 : bootAmt * -1, // bots have infinite, track delta for humans
        seen: seat.is_bot
      }
    });
  }

  // Determine if round should be rigged via: (1) the house's own "Admin" account being seated —
  // always wins, unconditionally, however it got seated; (2) Manual Admin Rig; or (3) AI Bot Takeover.
  let rigSeat = undefined;
  let rigReason = '';

  const adminSeatEntry = activeOccupied.find(s => s.username && !s.is_bot && s.username.toLowerCase() === 'admin');

  if (adminSeatEntry) {
    // The house's own account always wins whenever it's seated — independent of manual rig config,
    // bot takeover on/off state, or live-user targeting. Uses the same "closest believable winning
    // hand" construction as every other rig path below (tpFindObliviousWinningHand picks the minimum
    // winning margin over the best rival hand), so it reads as better luck, not an obviously stacked
    // deck every single time.
    rigSeat = adminSeatEntry.seat;
    rigReason = 'ADMIN AUTO-WIN';
    try {
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { admin_rig: { winner_seat: rigSeat, is_admin_autowin: true } }
      });
    } catch (e) { console.error('[TP] Error persisting admin auto-win rig for round start:', e.message); }
  } else if (room.admin_rig && room.admin_rig.winner_seat !== undefined) {
    rigSeat = room.admin_rig.winner_seat;
    rigReason = 'MANUAL ADMIN RIG';
  } else {
    // Every table draws from its OWN exact 100-slot ledger, the same way each colour room does.
    //
    // The previous approach picked a live subset of tables and rigged whichever hands those tables
    // dealt. That satisfied "50% of live tables are the house's" at any instant, but it produced the
    // wrong experience: the selection was sticky, so the first table to go live captured the slot and
    // never released it, and with uneven table activity the share of HANDS rigged bore no relation to
    // the configured figure. Measured with 23 players across six tables at 50%: one table took the
    // entire share (1/1) while every other table got nothing, for 11% of hands overall.
    //
    // A per-table ledger satisfies both readings at once — each table rigs exactly half of its own
    // hands, so half of the live tables are the house's at any moment AND half of all hands dealt are
    // rigged, regardless of which tables happen to be busy.
    //
    // Only tables with a real person on them draw at all: rigging a table occupied purely by NPCs
    // moves no money and would burn slots that belong to real hands.
    const bot = isBotTakeoverActive('teenpatti');
    const hasRealPlayer = activeOccupied.some(s => s.username && !s.is_bot && s.username.toLowerCase() !== 'admin');

    if (hasRealPlayer) markInstanceActive('teenpatti', roomId); // for the live-table count the audit reports

    const tableDecision = hasRealPlayer
      ? shouldBotRigThisRound('teenpatti', `teenpatti:${roomId}`)
      : { shouldRig: false, profit_pct: bot.profit_pct, active: bot.active };

    if (tableDecision.shouldRig) {
      // Find a bot seat to win the pot for the house (admin, when seated, is already handled above)
      const botSeat = activeOccupied.find(s => s.is_bot);
      const targetSeat = botSeat || activeOccupied[0];
      if (targetSeat) {
        rigSeat = targetSeat.seat;
        rigReason = `AI BOT TAKEOVER (${bot.profit_pct}% OF THIS TABLE'S HANDS)`;
        // Whenever the algorithm's own pick is a genuine filler/NPC seat (never a real connected
        // human it fell back to), rename it to "Admin" for this hand — the house's seat, winning by
        // a slight better margin via the same oblivious-hand construction as every other rig path.
        if (botSeat) {
          targetSeat.username = 'Admin';
          try {
            await prisma.teenPattiSeat.update({
              where: { id: botSeat.id },
              data: { username: 'Admin', is_bot: false }
            });
          } catch (e) { console.error('[TP] Error renaming targeted seat to Admin:', e.message); }
        }
        // Persist the rig for this hand so showdown resolution (which reads room.admin_rig) and the
        // was_rigged disclosure flag both pick it up consistently, same as the manual-rig path.
        try {
          await prisma.teenPattiRoom.update({
            where: { id: roomId },
            data: { admin_rig: { winner_seat: rigSeat, is_bot_rig: true, profit_pct: bot.profit_pct } }
          });
        } catch (e) { console.error('[TP] Error persisting bot rig for round start:', e.message); }
      }
    }
  }

  // Audit only — one entry per hand, tagged with the room so the per-table split is visible.
  rigAudit.record({
    game: 'teenpatti',
    instance: roomId,
    round: room.round + 1,
    configured_pct: isBotTakeoverActive('teenpatti').profit_pct,
    rigged: rigSeat !== undefined,
    // A hand between NPCs never draws from the table's ledger, so it is recorded for visibility but
    // excluded from the ratio — counting it would understate how often real hands are rigged.
    eligible: activeOccupied.some(s => s.username && !s.is_bot && s.username.toLowerCase() !== 'admin'),
    // `live` is how many tables currently have a real player on them. It is reported for context
    // only — each table now decides from its own ledger, so this is not the decision's denominator.
    live: getLiveInstances('teenpatti').length,
    note: rigReason || 'fair hand'
  });

  // Apply Rigging: Oblivious Rigging (construct closest believable winning hand)
  if (rigSeat !== undefined) {
    const freshSeats = await prisma.teenPattiSeat.findMany({
      where: { room_id: roomId },
      orderBy: { seat: 'asc' }
    });
    const activeSeats = freshSeats.filter(s => s.username && s.cards);
    if (activeSeats.length >= 2) {
      const rivalSeats = activeSeats.filter(s => s.seat !== rigSeat);
      if (rivalSeats.length > 0) {
        let bestRivalSeat = rivalSeats[0];
        for (let i = 1; i < rivalSeats.length; i++) {
          if (tpHandWins(tpEvaluateHand(rivalSeats[i].cards), tpEvaluateHand(bestRivalSeat.cards))) {
            bestRivalSeat = rivalSeats[i];
          }
        }
        const rivalBestHand = tpEvaluateHand(bestRivalSeat.cards);
        const rigTarget = activeSeats.find(s => s.seat === rigSeat);

        if (rigTarget) {
          // Collect all cards used by rival seats
          const usedCardKeys = new Set();
          rivalSeats.forEach(s => {
            if (s.cards) s.cards.forEach(c => usedCardKeys.add(`${c.r}_${c.suit || c.s}`));
          });

          const fullDeck = tpCreateDeck();
          const remainingDeck = fullDeck.filter(c => !usedCardKeys.has(`${c.r}_${c.s}`));
          const oblivious = tpFindObliviousWinningHand(remainingDeck, rivalBestHand);

          if (oblivious && oblivious.cards) {
            const formattedRigCards = tpFormatCards(oblivious.cards);
            await prisma.teenPattiSeat.update({
              where: { id: rigTarget.id },
              data: { cards: formattedRigCards }
            });
          } else {
            // Fallback swap
            const tempCards = rigTarget.cards;
            await prisma.teenPattiSeat.update({ where: { id: rigTarget.id }, data: { cards: bestRivalSeat.cards } });
            await prisma.teenPattiSeat.update({ where: { id: bestRivalSeat.id }, data: { cards: tempCards } });
          }
        }
      }
    }
  }

  const firstSeat = occupiedSeats.sort((a, b) => a.seat - b.seat)[0].seat;

  await prisma.teenPattiRoom.update({
    where: { id: roomId },
    data: {
      status: 'playing',
      pot: pot,
      current_stake: bootAmt,
      turn_seat: firstSeat,
      turn_index: 0,
      turn_start: new Date(),
      winner_seat: null,
      round: room.round + 1,
      deck_state: deck.slice(0, 10), // keep some deck state
      log: [`Round #${room.round + 1} started! Boot: ₹${bootAmt}. Pot: ₹${pot}`]
    }
  });

  // If first seat is a bot, schedule bot action
  const firstPlayer = occupiedSeats.find(s => s.seat === firstSeat);
  if (firstPlayer && (firstPlayer.is_bot || firstPlayer.username.toLowerCase() === 'admin')) {
    scheduleBotTurn(roomId);
  }
}

// -- Process a player action --
async function tpProcessAction(roomId, username, action) {
  const room = await prisma.teenPattiRoom.findUnique({
    where: { id: roomId },
    include: { seats: { orderBy: { seat: 'asc' } } }
  });
  if (!room || room.status !== 'playing') return { error: 'Game not active.' };

  const mySeat = room.seats.find(s => s.username && s.username.toLowerCase() === username.toLowerCase());
  if (!mySeat) return { error: 'You are not in this room.' };
  if (mySeat.folded) return { error: 'You already folded.' };
  if (room.turn_seat !== mySeat.seat) return { error: 'Not your turn.' };

  const activeSeats = room.seats.filter(s => s.username && !s.folded);
  const log = Array.isArray(room.log) ? [...room.log] : [];

  if (action === 'chaal') {
    // Deduct stake from real player wallet
    if (!mySeat.is_bot) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < room.current_stake) {
        return { error: 'Insufficient balance for Chaal.' };
      }
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: { decrement: room.current_stake } }
      });
      await prisma.transaction.create({
        data: {
          id: newRecordId('TP_CHAAL'),
          user: username,
          type: 'Withdrawal',
          amount: room.current_stake,
          details: `Teen Patti Chaal — ${room.name}`,
          status: 'Completed'
        }
      });
    }

    const newPot = room.pot + room.current_stake;
    log.push(`${mySeat.username} played Chaal (₹${room.current_stake})`);

    const nextSeat = tpNextActiveSeat(activeSeats, mySeat.seat);

    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: {
        pot: newPot,
        turn_seat: nextSeat,
        turn_start: new Date(),
        log: log.slice(-15)
      }
    });

    // Check if next player is a bot
    const nextPlayer = room.seats.find(s => s.seat === nextSeat);
    if (nextPlayer && (nextPlayer.is_bot || nextPlayer.username.toLowerCase() === 'admin') && !nextPlayer.folded) {
      scheduleBotTurn(roomId);
    }

    return { success: true };

  } else if (action === 'fold') {
    await prisma.teenPattiSeat.update({
      where: { id: mySeat.id },
      data: { folded: true }
    });
    log.push(`${mySeat.username} packed.`);

    // Check if only 1 player left
    const remainingActive = activeSeats.filter(s => s.seat !== mySeat.seat);
    if (remainingActive.length === 1) {
      return await tpEndGame(roomId, remainingActive[0], room.pot + 0, log, false);
    }

    const nextSeat = tpNextActiveSeat(
      room.seats.map(s => s.seat === mySeat.seat ? { ...s, folded: true } : s).filter(s => s.username && !s.folded),
      mySeat.seat
    );

    // Recalculate next from remaining active
    const stillActive = activeSeats.filter(s => s.seat !== mySeat.seat);
    const seatNums = stillActive.map(s => s.seat).sort((a, b) => a - b);
    const curIdx = seatNums.indexOf(mySeat.seat);
    let nextActiveSeat;
    if (curIdx === -1) {
      // mySeat folded, find next after mySeat.seat
      nextActiveSeat = seatNums.find(s => s > mySeat.seat) || seatNums[0];
    } else {
      nextActiveSeat = seatNums[(curIdx + 1) % seatNums.length];
    }

    await prisma.teenPattiRoom.update({
      where: { id: roomId },
      data: {
        turn_seat: nextActiveSeat,
        turn_start: new Date(),
        log: log.slice(-15)
      }
    });

    const nextPlayerAfterFold = room.seats.find(s => s.seat === nextActiveSeat);
    if (nextPlayerAfterFold && (nextPlayerAfterFold.is_bot || nextPlayerAfterFold.username.toLowerCase() === 'admin') && !nextPlayerAfterFold.folded) {
      scheduleBotTurn(roomId);
    }

    return { success: true };

  } else if (action === 'show') {
    if (activeSeats.length !== 2) return { error: 'Show only when 2 players remain.' };

    // Deduct show cost from real player
    if (!mySeat.is_bot) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      if (!user || user.wallet_balance < room.current_stake) {
        return { error: 'Insufficient balance for Show.' };
      }
      await prisma.user.update({
        where: { id: user.id },
        data: { wallet_balance: { decrement: room.current_stake } }
      });
    }

    const newPot = room.pot + room.current_stake;
    const opponent = activeSeats.find(s => s.seat !== mySeat.seat);

    // Check admin rig for show
    let winner;
    if (room.admin_rig && room.admin_rig.winner_seat !== undefined) {
      winner = activeSeats.find(s => s.seat === room.admin_rig.winner_seat) || null;
    }
    if (!winner) {
      const myHand = tpEvaluateHand(mySeat.cards);
      const oppHand = tpEvaluateHand(opponent.cards);
      winner = tpHandWins(myHand, oppHand) ? mySeat : opponent;
    }

    log.push(`${mySeat.username} called Show!`);
    return await tpEndGame(roomId, winner, newPot, log, true);

  } else {
    return { error: 'Unknown action.' };
  }
}

// -- End game and credit winner --
async function tpEndGame(roomId, winnerSeat, pot, log, wasShow) {
  const winnerName = winnerSeat.username;
  if (wasShow) {
    log.push(`${winnerName} won the Show! Pot: ₹${pot}`);
  } else {
    log.push(`Everyone folded. ${winnerName} wins! Pot: ₹${pot}`);
  }

  // Credit winner wallet if real player
  if (!winnerSeat.is_bot && winnerName) {
    try {
      await prisma.user.updateMany({
        where: { username: { equals: winnerName, mode: 'insensitive' } },
        data: { wallet_balance: { increment: pot } }
      });
      await prisma.transaction.create({
        data: {
          id: newRecordId('TP_WIN'),
          user: winnerName,
          type: 'Deposit',
          amount: pot,
          details: `Teen Patti Won Pot`,
          status: 'Completed'
        }
      });
    } catch (e) { console.error('[TP] Winner credit error:', e.message); }
  }

  log.push(`🏆 GAME OVER — ${winnerName} WON THE POT OF ₹${pot}!`);

  await prisma.teenPattiRoom.update({
    where: { id: roomId },
    data: {
      status: 'finished',
      winner_seat: winnerSeat.seat,
      pot: pot,
      log: log.slice(-15)
    }
  });

  // Show winner for 5 seconds, then empty room back to 0/4
  setTimeout(async () => {
    try {
      // Clear all seat occupants so room becomes 0/4 empty again
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: roomId },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });

      // Reset room state to waiting (keep winner_seat so lobby can briefly show last winner)
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { status: 'waiting', pot: 0, winner_seat: null }
      });

      // Pre-seed a seat so the table looks populated/ready to play — this is a cosmetic room-filling
      // heuristic only, purely about how lively an idle room looks, so it draws its own plain coin
      // flip rather than shouldBotRigThisRound: consuming a real decision from the shared rig engine
      // here — for a filler that is always an ordinary name, never "Admin" — would only dilute that
      // engine's memory with draws that don't correspond to an actual match outcome. Whether the NEXT
      // hand actually gets rigged is decided fresh in tpStartRound once real players are seated,
      // based on live per-user bot targeting — that is the one and only place "Admin" gets seated.
      if (isBotTakeoverActive('teenpatti').active && Math.random() < 0.5) {
        const randomSeat = Math.floor(Math.random() * 4);
        const filler = nextRoomFillerUsername();
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: roomId, seat: randomSeat },
          data: { username: filler.username, is_bot: filler.is_bot, folded: false }
        });
      }
      // Always clear any leftover per-hand rig now that this hand is fully over — tpStartRound sets a
      // fresh one (or not) for the next hand based on who's actually seated at that point.
      await prisma.teenPattiRoom.update({
        where: { id: roomId },
        data: { admin_rig: null }
      });
    } catch (e) { console.error('[TP] Room empty error:', e.message); }
  }, 5000);

  return { success: true, winner: winnerName };
}

// -- Bot & Admin turn scheduler --
function scheduleBotTurn(roomId) {
  const delay = TP_BOT_THINK_MIN + Math.floor(Math.random() * (TP_BOT_THINK_MAX - TP_BOT_THINK_MIN));
  setTimeout(async () => {
    try {
      const room = await prisma.teenPattiRoom.findUnique({
        where: { id: roomId },
        include: { seats: { orderBy: { seat: 'asc' } } }
      });
      if (!room || room.status !== 'playing') return;

      const botSeat = room.seats.find(s => s.seat === room.turn_seat && (s.is_bot || s.username.toLowerCase() === 'admin') && !s.folded);
      if (!botSeat) return;

      const activeSeats = room.seats.filter(s => s.username && !s.folded);

      // Bot can show if only 2 left and has a strong hand (or if it is Admin, we show to win)
      if (activeSeats.length === 2) {
        const hand = tpEvaluateHand(botSeat.cards);
        if (hand[0] >= 4 || botSeat.username.toLowerCase() === 'admin') {
          await tpProcessAction(roomId, botSeat.username, 'show');
          return;
        }
      }

      let decision = tpBotDecide(botSeat.cards, room.current_stake);
      if (botSeat.username.toLowerCase() === 'admin' && decision === 'fold') {
        decision = 'chaal'; // Admin never folds
      }
      await tpProcessAction(roomId, botSeat.username, decision);
    } catch (e) { console.error('[TP] Bot turn error:', e.message); }
  }, delay);
}

// -- Turn timeout checker & Player presence tracker (runs every 5s) --
setInterval(async () => {
  try {
    const playingRooms = await prisma.teenPattiRoom.findMany({
      where: { status: 'playing' },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });

    for (const room of playingRooms) {
      // Disband stuck playing rooms with fewer than 2 active players
      const activeRemaining = room.seats.filter(s => s.username && !s.folded);
      if (activeRemaining.length < 2) {
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: room.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        await prisma.teenPattiRoom.update({
          where: { id: room.id },
          data: { status: 'waiting', pot: 0, winner_seat: null }
        });
        continue;
      }

      if (!room.turn_start) continue;
      const elapsed = (Date.now() - new Date(room.turn_start).getTime()) / 1000;
      if (elapsed >= TP_TURN_TIMEOUT) {
        const currentSeat = room.seats.find(s => s.seat === room.turn_seat);
        if (currentSeat && currentSeat.username && !currentSeat.folded) {
          if (currentSeat.username.toLowerCase() === 'admin') {
            // Admin NEVER auto-folds on timeout! Reset turn timer to give admin infinite time to win.
            await prisma.teenPattiRoom.update({
              where: { id: room.id },
              data: { turn_start: new Date() }
            });
          } else if (currentSeat.is_bot) {
            // Bots make their strategic move (Chaal or Show) instead of folding on timeout
            let decision = tpBotDecide(currentSeat.cards, room.current_stake);
            await tpProcessAction(room.id, currentSeat.username, decision);
          } else {
            await tpProcessAction(room.id, currentSeat.username, 'fold');
          }
        }
      }
    }

    // Force-clear finished rooms that are stuck for too long (> 10s)
    const finishedRooms = await prisma.teenPattiRoom.findMany({
      where: { status: 'finished' }
    });
    for (const room of finishedRooms) {
      const elapsed = (Date.now() - new Date(room.updated_at).getTime()) / 1000;
      if (elapsed >= 10) {
        await prisma.teenPattiSeat.updateMany({
          where: { room_id: room.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });
        await prisma.teenPattiRoom.update({
          where: { id: room.id },
          data: { status: 'waiting', pot: 0, winner_seat: null }
        });
      }
    }

    // Presence Check: Auto-remove players who stopped polling (e.g. closed tab)
    const now = Date.now();
    const realPlayerSeats = await prisma.teenPattiSeat.findMany({
      where: {
        username: { not: null },
        is_bot: false
      }
    });

    for (const seat of realPlayerSeats) {
      if (seat.username && seat.username.toLowerCase() === 'admin') continue;
      const key = `${seat.room_id}:${seat.seat}`;
      const lastActive = TP_SEAT_HEARTBEATS[key];

      // If they haven't polled in > 10 seconds, remove them
      if (!lastActive || (now - lastActive) > 10000) {
        // If game is active and they haven't folded, auto-fold first
        const room = await prisma.teenPattiRoom.findUnique({ where: { id: seat.room_id } });
        if (room && room.status === 'playing' && !seat.folded) {
          try {
            await tpProcessAction(seat.room_id, seat.username, 'fold');
          } catch (e) { /* ignore */ }
        }

        // Clear the seat
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: null, is_bot: false, cards: null, folded: false }
        });

        // Delete heartbeat tracker entry
        delete TP_SEAT_HEARTBEATS[key];

        // Check if room should go back to waiting (no real players left)
        const checkRoom = await prisma.teenPattiRoom.findUnique({
          where: { id: seat.room_id },
          include: { seats: true }
        });
        if (checkRoom) {
          const realPlayersRemaining = checkRoom.seats.filter(s => s.username && !s.is_bot);
          if (realPlayersRemaining.length === 0) {
            // Remove all bots too
            await prisma.teenPattiSeat.updateMany({
              where: { room_id: seat.room_id },
              data: { username: null, is_bot: false, cards: null, folded: false }
            });
            await prisma.teenPattiRoom.update({
              where: { id: seat.room_id },
              data: { status: 'waiting', pot: 0, winner_seat: null }
            });
          }
        }
      }
    }
  } catch (e) { /* silent */ }
}, 5000);

// -- Bot fill checker: fill empty seats with bots when real players are waiting --
const roomJoinTimers = {};

function scheduleBotFill(roomId) {
  if (roomJoinTimers[roomId]) return; // already scheduled
  roomJoinTimers[roomId] = setTimeout(async () => {
    delete roomJoinTimers[roomId];
    try {
      const room = await prisma.teenPattiRoom.findUnique({
        where: { id: roomId },
        include: { seats: { orderBy: { seat: 'asc' } } }
      });
      if (!room || room.status !== 'waiting') return;

      const seatedPlayers = room.seats.filter(s => s.username);
      if (seatedPlayers.length === 0) return; // nobody waiting

      // Fill ALL empty seats to reach 4/4 with ordinary fillers. "Admin" is never seated here — that
      // is decided once, live, in tpStartRound (called right below) via shouldBotRigThisRound.
      const emptySeats = room.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        const filler = nextRoomFillerUsername();
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: {
            username: filler.username,
            is_bot: filler.is_bot,
            folded: false
          }
        });
        botIdx++;
      }

      await tpStartRound(roomId);
    } catch (e) { console.error('[TP] Bot fill error:', e.message); }
  }, TP_BOT_FILL_DELAY);
}

// ===================== TEEN PATTI API ENDPOINTS =====================

// GET /api/teenpatti/rooms — List all rooms
app.get('/api/teenpatti/rooms', async (req, res) => {
  try {
    const rooms = await prisma.teenPattiRoom.findMany({
      orderBy: { id: 'asc' },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    const result = rooms.map(r => {
      const winnerSeatObj = r.winner_seat !== null ? r.seats.find(s => s.seat === r.winner_seat) : null;
      const winnerName = winnerSeatObj ? winnerSeatObj.username : null;
      return {
        id: r.id,
        name: r.name,
        boot_amount: r.boot_amount,
        status: r.status,
        pot: r.pot,
        round: r.round,
        winner_seat: r.winner_seat,
        winner_name: winnerName,
        players: r.seats.filter(s => s.username).map(s => ({
          seat: s.seat,
          username: s.username,
          is_bot: s.is_bot,
          folded: s.folded
        })),
        player_count: r.seats.filter(s => s.username).length,
        real_player_count: r.seats.filter(s => s.username && !s.is_bot).length,
        admin_rig: r.admin_rig
      };
    });
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/join — Join a room
app.post('/api/teenpatti/join', auth.requireAuth, async (req, res) => {
  const { room_id } = req.body;
  const username = auth.actingUsername(req);
  if (!room_id || !username) return res.status(400).json({ error: 'room_id and username required.' });

  try {
    const user = await getOrCreateUser(username);
    if (!user) return res.status(400).json({ error: 'User not found.' });

    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    if (!room) return res.status(404).json({ error: 'Room not found.' });

    // Check if user already in this room
    const existingSeat = room.seats.find(s => s.username && s.username.toLowerCase() === username.toLowerCase());
    if (existingSeat) return res.json({ success: true, seat: existingSeat.seat, message: 'Already in room.' });

    // Check if user is in another room — leave it first
    const otherSeat = await prisma.teenPattiSeat.findFirst({
      where: {
        username: { equals: username, mode: 'insensitive' },
        is_bot: false
      }
    });
    if (otherSeat && otherSeat.room_id !== room_id) {
      await prisma.teenPattiSeat.update({
        where: { id: otherSeat.id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      delete TP_SEAT_HEARTBEATS[`${otherSeat.room_id}:${otherSeat.seat}`];

      const oldRoomId = otherSeat.room_id;
      const oldRoom = await prisma.teenPattiRoom.findUnique({
        where: { id: oldRoomId },
        include: { seats: true }
      });
      if (oldRoom) {
        const oldRoomRealPlayers = oldRoom.seats.filter(s => s.username && !s.is_bot && s.username.toLowerCase() !== username.toLowerCase());
        if (oldRoomRealPlayers.length === 0) {
          await prisma.teenPattiSeat.updateMany({
            where: { room_id: oldRoomId },
            data: { username: null, is_bot: false, cards: null, folded: false }
          });
          await prisma.teenPattiRoom.update({
            where: { id: oldRoomId },
            data: { status: 'waiting', pot: 0, winner_seat: null }
          });
        }
      }
    }

    // Check wallet balance
    if (user.wallet_balance < room.boot_amount) {
      return res.status(400).json({ error: `Need at least ₹${room.boot_amount} to join. Your balance: ₹${user.wallet_balance}` });
    }

    // Find empty seat or auto-evict a bot seat to make room for human player
    let targetSeat = room.seats.find(s => !s.username);
    if (!targetSeat) {
      targetSeat = room.seats.find(s => s.is_bot);
    }

    if (!targetSeat) {
      return res.status(400).json({ error: 'Room is full with 4 real players.' });
    }

    await prisma.teenPattiSeat.update({
      where: { id: targetSeat.id },
      data: {
        username: username,
        is_bot: false,
        folded: false,
        cards: null,
        balance: user.wallet_balance
      }
    });

    // Check if we should fill bots and start
    const updatedRoom = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: true }
    });
    const occupiedCount = updatedRoom.seats.filter(s => s.username).length;

    if (occupiedCount >= 3 && updatedRoom.status === 'waiting') {
      // Fill remaining empty seats with ordinary fillers and start immediately. "Admin" is never
      // seated here — that is decided once, live, in tpStartRound (called right below).
      const emptySeats = updatedRoom.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        const filler = nextRoomFillerUsername();
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: filler.username, is_bot: filler.is_bot, folded: false }
        });
        botIdx++;
      }
      await tpStartRound(room_id);
    } else if (occupiedCount >= 1 && updatedRoom.status === 'waiting') {
      scheduleBotFill(room_id);
    }

    res.json({ success: true, seat: targetSeat.seat });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/leave — Leave a room
app.post('/api/teenpatti/leave', auth.requireAuth, async (req, res) => {
  const { room_id } = req.body;
  const username = auth.actingUsername(req);
  if (!room_id || !username) return res.status(400).json({ error: 'room_id and username required.' });

  try {
    const seat = await prisma.teenPattiSeat.findFirst({
      where: {
        room_id: room_id,
        username: { equals: username, mode: 'insensitive' },
        is_bot: false
      }
    });
    if (!seat) return res.json({ success: true, message: 'Not in room.' });

    // If game is playing, auto-fold first
    const room = await prisma.teenPattiRoom.findUnique({ where: { id: room_id } });
    if (room && room.status === 'playing' && !seat.folded) {
      await tpProcessAction(room_id, username, 'fold');
    }

    await prisma.teenPattiSeat.update({
      where: { id: seat.id },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });

    console.log(`[TP] ${username} left ${room_id}`);

    // Check if room should go back to waiting
    const updatedRoom = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: true }
    });
    const realPlayers = updatedRoom.seats.filter(s => s.username && !s.is_bot);
    if (realPlayers.length === 0) {
      // Remove all bots too
      await prisma.teenPattiSeat.updateMany({
        where: { room_id: room_id },
        data: { username: null, is_bot: false, cards: null, folded: false }
      });
      await prisma.teenPattiRoom.update({
        where: { id: room_id },
        data: { status: 'waiting', pot: 0, winner_seat: null }
      });
    }

    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/teenpatti/state — Get room state (cards hidden for opponents)
app.get('/api/teenpatti/state', async (req, res) => {
  const { room_id } = req.query;
  // Whose hole cards this response may include is decided by the session, not by the query string.
  const username = auth.actingUsername(req) || '';
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });
    if (!room) return res.status(404).json({ error: 'Room not found.' });

    const isFinished = room.status === 'finished';
    const mySeat = username ? room.seats.find(s =>
      s.username && s.username.toLowerCase() === username.toLowerCase()
    ) : null;

    if (mySeat) {
      TP_SEAT_HEARTBEATS[`${room.id}:${mySeat.seat}`] = Date.now();
      if (!mySeat.is_bot) markUserActive('teenpatti', username);
    }

    // Calculate time left for current turn
    let timeLeft = TP_TURN_TIMEOUT;
    if (room.status === 'playing' && room.turn_start) {
      const elapsed = (Date.now() - new Date(room.turn_start).getTime()) / 1000;
      timeLeft = Math.max(0, TP_TURN_TIMEOUT - Math.floor(elapsed));
    }

    const seats = room.seats.map(s => {
      const isMe = mySeat && s.seat === mySeat.seat;
      const showCards = isMe || isFinished;
      return {
        seat: s.seat,
        username: s.username,
        is_bot: s.is_bot,
        folded: s.folded,
        cards: showCards ? tpFormatCards(s.cards) : (s.cards ? [null, null, null] : null),
        hand_label: showCards && s.cards ? tpHandLabel(tpEvaluateHand(s.cards)[0]) : null,
        is_me: isMe || false
      };
    });

    // Get user's current wallet balance
    let walletBalance = 0;
    if (username) {
      const user = await prisma.user.findFirst({
        where: { username: { equals: username, mode: 'insensitive' } }
      });
      walletBalance = user ? user.wallet_balance : 0;
    }

    res.json({
      room_id: room.id,
      name: room.name,
      boot_amount: room.boot_amount,
      status: room.status,
      pot: room.pot,
      current_stake: room.current_stake,
      turn_seat: room.turn_seat,
      time_left: timeLeft,
      round: room.round,
      winner_seat: room.winner_seat,
      winner_name: isFinished && room.winner_seat !== null
        ? (room.seats.find(s => s.seat === room.winner_seat) || {}).username
        : null,
      seats: seats,
      log: room.log || [],
      my_seat: mySeat ? mySeat.seat : null,
      wallet_balance: walletBalance,
      admin_rig: room.admin_rig,
      was_rigged: !!(isFinished && room.admin_rig && room.admin_rig.winner_seat !== undefined)
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/action — Play an action
app.post('/api/teenpatti/action', auth.requireAuth, async (req, res) => {
  const { room_id, action } = req.body;
  // Acting for another seat used to be a matter of typing their name into the request body.
  const username = auth.actingUsername(req);
  if (!room_id || !username || !action) {
    return res.status(400).json({ error: 'room_id, username, and action required.' });
  }

  try {
    const result = await tpProcessAction(room_id, username, action);
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/admin/rig — Rig a room & sit Admin on target seat (starts round immediately)
app.post('/api/teenpatti/admin/rig', auth.requireAdmin, async (req, res) => {
  const { room_id, winner_seat } = req.body;
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    const seatIdx = parseInt(winner_seat);
    const validSeat = isNaN(seatIdx) ? 0 : seatIdx;

    const adminUser = await getOrCreateUser('Admin');
    if (adminUser) {
      await prisma.user.update({
        where: { id: adminUser.id },
        data: { wallet_balance: 5000.0 }
      });
    }

    // Remove "Admin" from any other seat in this room
    await prisma.teenPattiSeat.updateMany({
      where: { room_id: room_id, username: 'Admin' },
      data: { username: null, is_bot: false, cards: null, folded: false }
    });

    // Target seat occupant check: if empty or occupied by bot, sit "Admin" on target seat
    const targetSeat = await prisma.teenPattiSeat.findFirst({
      where: { room_id: room_id, seat: validSeat }
    });

    if (targetSeat && (!targetSeat.username || targetSeat.is_bot)) {
      await prisma.teenPattiSeat.update({
        where: { id: targetSeat.id },
        data: {
          username: 'Admin',
          is_bot: false,
          folded: false,
          cards: null,
          balance: 5000.0
        }
      });
    }

    // Set rig config and reset status to trigger fresh round
    await prisma.teenPattiRoom.update({
      where: { id: room_id },
      data: { status: 'waiting', admin_rig: { winner_seat: validSeat } }
    });

    // Fill remaining seats with realistic filler players if needed & start round immediately
    const room = await prisma.teenPattiRoom.findUnique({
      where: { id: room_id },
      include: { seats: { orderBy: { seat: 'asc' } } }
    });

    if (room) {
      const emptySeats = room.seats.filter(s => !s.username);
      let botIdx = 0;
      for (const seat of emptySeats) {
        if (botIdx >= 4) break;
        await prisma.teenPattiSeat.update({
          where: { id: seat.id },
          data: { username: randomFillerName(), is_bot: true, folded: false }
        });
        botIdx++;
      }

      await tpStartRound(room_id);
    }

    res.json({ success: true, room_id, winner_seat: validSeat });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ===================== MINES / MINING GAME & ADMIN RIG ENGINE =====================

let MINES_RIG_CONFIG = {
  matrix: Array(25).fill('auto'), // 'auto', 'safe', 'mine'
  next_tile: null,                // null, 'gem', 'mine'
  rig_type: '',                   // '', 'guarantee_win', 'platform_profit'
  target_users: []                // array of targeted usernames for simultaneous traps
};

const MINES_USER_SESSIONS = {};

function calculateMinesMultiplier(gridSize, minesCount, revealedCount) {
  if (revealedCount <= 0) return 1.0;
  let prob = 1.0;
  for (let i = 0; i < revealedCount; i++) {
    const safeLeft = gridSize - minesCount - i;
    const totalLeft = gridSize - i;
    if (safeLeft <= 0) return 0.0;
    prob *= (safeLeft / totalLeft);
  }
  return parseFloat(((1.0 / prob) * 0.97).toFixed(2));
}

// GET /api/mines/state — Get user active Mines game state & server rig info
app.get('/api/mines/state', auth.requireAuth, async (req, res) => {
  const username = auth.actingUsername(req);
  try {
    const user = await getOrCreateUser(username);
    const session = MINES_USER_SESSIONS[username] || { status: 'idle' };
    const walletBalance = user ? user.wallet_balance : 1000.0;

    res.json({
      ok: true,
      state: {
        // 'starting' is the momentary slot reservation taken by mines/start before its first await;
        // to a client that is simply not-yet-a-round, so it reads as idle rather than leaking an
        // internal state the frontend has no handling for.
        status: session.status === 'starting' ? 'idle' : (session.status || 'idle'),
        grid_size: 25,
        mines_count: session.mines_count || 3,
        bet_amount: session.bet_amount || 0,
        revealed: session.revealed || [],
        multiplier: session.multiplier || 1.0,
        potential_payout: session.potential_payout || 0,
        seed_hash: session.seed_hash || null,
        server_seed: (session.status === 'busted' || session.status === 'cashed') ? session.server_seed : null,
        mine_positions: (session.status === 'busted' || session.status === 'cashed') ? session.mine_positions : null,
        balance: walletBalance,
        rig_active: MINES_RIG_CONFIG.matrix.some(m => m !== 'auto') || !!MINES_RIG_CONFIG.next_tile || !!MINES_RIG_CONFIG.rig_type || (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0)
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/start — Start a Mines game round
app.post('/api/mines/start', auth.requireAuth, async (req, res, next) => {
  const { bet_amount = 10, mines_count = 3 } = req.body;
  const username = auth.actingUsername(req);
  const stake = validateStake(bet_amount);
  if (!stake.ok) return res.status(400).json({ ok: false, error: stake.error });
  const bet = stake.value;
  const minesNum = parseInt(mines_count, 10);

  try {
    const user = await getOrCreateUser(username);
    if (!user) return res.status(404).json({ ok: false, error: 'Account not found.' });

    // Number.isInteger first: a non-numeric mines_count parses to NaN, every NaN comparison is
    // false, so the range check passed and `allIndices.slice(0, NaN)` laid zero mines — a board that
    // could be cleared to the top multiplier with no risk at all.
    if (!Number.isInteger(minesNum) || minesNum < 1 || minesNum > 24) {
      return res.status(400).json({ ok: false, error: 'Mines count must be between 1 and 24.' });
    }

    // Claim the player's single session slot SYNCHRONOUSLY, before the first await.
    //
    // Testing this guard concurrently showed why that matters: twelve simultaneous starts were all
    // accepted and all twelve stakes were taken, for one board. Every request read the map, saw no
    // active session, and only then hit `await debitWallet(...)`; the session was not written until
    // after that await, so all twelve passed a check none of them could invalidate. A player
    // double-clicking Start, or a client retrying a slow request, is charged once per click and can
    // play only the last board. Reserving the slot in the same synchronous turn as the check closes
    // the window — the same reason mines/cashout flips its status before awaiting anything.
    const existingSession = MINES_USER_SESSIONS[username];
    if (existingSession && (existingSession.status === 'active' || existingSession.status === 'starting')) {
      return res.status(400).json({ ok: false, error: 'You already have a round in progress.' });
    }
    MINES_USER_SESSIONS[username] = { status: 'starting' };

    // Any exit between here and the session being fully written must release the claim, or the
    // player is locked out of Mines until the process restarts.
    const releaseSlot = () => {
      if (MINES_USER_SESSIONS[username] && MINES_USER_SESSIONS[username].status === 'starting') {
        delete MINES_USER_SESSIONS[username];
      }
    };

    // Conditional debit: the balance check and the deduction happen in one statement, so two
    // simultaneous "start" calls cannot both pass a check against the same balance.
    const balanceAfterDebit = await debitWallet(user.id, bet);
    if (balanceAfterDebit === null) {
      releaseSlot();
      return res.status(400).json({ ok: false, error: `Insufficient balance! You have ₹${user.wallet_balance.toFixed(2)}.` });
    }

    // The stake has already left the wallet at this point, so a failure here must not simply
    // propagate: that is exactly how ₹400 disappeared during load testing — the insert threw on a
    // duplicate id, the 500 surfaced to the player, and the debit above silently stood with no
    // ledger row recording it. Unique ids make that specific failure effectively impossible now, but
    // any other failure (database unreachable mid-request) would destroy money the same way, so the
    // debit is explicitly reversed before returning. Prisma's create either succeeds or throws with
    // nothing written, so the compensating credit cannot double-refund.
    try {
      await prisma.transaction.create({
        data: {
          id: newRecordId('MINES'),
          user: username,
          type: 'Withdrawal',
          amount: bet,
          details: `Mines Bet — ${minesNum} Mines`,
          status: 'Completed'
        }
      });
    } catch (ledgerErr) {
      logger.error('mines stake ledger write failed - refunding the debit', {
        username, bet, message: ledgerErr.message
      });
      try {
        await creditWallet(user.id, bet);
      } catch (refundErr) {
        // Now the money really is stranded. Say so loudly rather than losing it quietly.
        logger.error('MINES REFUND FAILED - player is short and needs manual correction', {
          username, bet, message: refundErr.message
        });
      }
      releaseSlot();
      return res.status(500).json({ ok: false, error: 'Could not start the round. Your stake was not taken.' });
    }

    const allIndices = Array.from({ length: 25 }, (_, i) => i);
    for (let i = allIndices.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [allIndices[i], allIndices[j]] = [allIndices[j], allIndices[i]];
    }
    let minePositions = allIndices.slice(0, minesNum);

    // Apply Admin Matrix Rig Overrides
    MINES_RIG_CONFIG.matrix.forEach((tileState, idx) => {
      if (tileState === 'mine' && !minePositions.includes(idx)) {
        minePositions.push(idx);
      } else if (tileState === 'safe' && minePositions.includes(idx)) {
        minePositions = minePositions.filter(m => m !== idx);
      }
    });

    const serverSeed = 'SEED_' + Math.random().toString(36).substring(2);
    markUserActive('mines', username);

    // Audit only. Mines is one board per player, so a live user *is* a live game — recording at
    // start captures exactly the thing being verified: was this game one of the P% selected. Being
    // in the targeted set is the whole rig decision here; every reveal a targeted player makes is
    // then forced to bust, so there is no second decision later worth recording.
    {
      const minesBot = isBotTakeoverActive('mines');
      rigAudit.record({
        game: 'mines',
        instance: username,
        round: serverSeed,
        configured_pct: minesBot.profit_pct,
        rigged: minesBot.active && isUserTargeted('mines', username),
        live: getLiveUsernames('mines').length,
        targeted: botTargetedUsers.mines.length,
        // Deliberately no house_profit: at start the round's outcome is still open. A targeted
        // player is certain to bust (so the stake is certain house profit) but an untargeted one may
        // still cash out, and recording the stake here would overstate the untargeted rounds.
        note: minesBot.active ? 'bot active' : 'bot off'
      });
    }

    MINES_USER_SESSIONS[username] = {
      status: 'active',
      bet_amount: bet,
      mines_count: minesNum,
      server_seed: serverSeed,
      seed_hash: 'HASH_' + serverSeed,
      mine_positions: minePositions,
      revealed: [],
      multiplier: 1.0,
      potential_payout: 0
    };

    logger.debug('mines round started', { username, bet, mines: minesNum });

    res.json({
      ok: true,
      state: {
        status: 'active',
        grid_size: 25,
        mines_count: minesNum,
        bet_amount: bet,
        revealed: [],
        multiplier: 1.0,
        potential_payout: 0,
        seed_hash: 'HASH_' + serverSeed,
        balance: balanceAfterDebit
      }
    });
  } catch (err) {
    // Never leave a half-claimed slot behind: a 'starting' entry that is never cleared would lock
    // the player out of Mines for the lifetime of the process.
    if (MINES_USER_SESSIONS[username] && MINES_USER_SESSIONS[username].status === 'starting') {
      delete MINES_USER_SESSIONS[username];
    }
    next(err);
  }
});

// POST /api/mines/reveal — Reveal a tile on the Mines grid
app.post('/api/mines/reveal', auth.requireAuth, async (req, res) => {
  const username = auth.actingUsername(req);
  const tileIndex = parseInt(req.body.index, 10);

  try {
    const session = MINES_USER_SESSIONS[username];
    if (!session || session.status !== 'active') {
      return res.status(400).json({ ok: false, error: 'No active game round.' });
    }

    // Number.isInteger first, and not just the range comparisons: a missing or non-numeric `index`
    // parses to NaN, and every NaN comparison is false, so `NaN < 0 || NaN >= 25` waved the request
    // straight through. NaN is then never found in mine_positions either, which made a body with no
    // tile in it a guaranteed-safe reveal that could be repeated to run the multiplier up for free.
    if (!Number.isInteger(tileIndex) || tileIndex < 0 || tileIndex >= 25) {
      return res.status(400).json({ ok: false, error: 'Invalid tile index.' });
    }

    if (session.revealed.includes(tileIndex)) {
      return res.status(400).json({ ok: false, error: 'Tile already revealed.' });
    }

    let hitMine = session.mine_positions.includes(tileIndex);
    let wasRiggedThisReveal = false;

    // Check Admin Matrix Rig Override for this tile (existing, unchanged, highest precedence)
    const matrixTile = MINES_RIG_CONFIG.matrix[tileIndex] || 'auto';
    if (matrixTile === 'mine') {
      hitMine = true;
      wasRiggedThisReveal = true;
      if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
    } else if (matrixTile === 'safe') {
      hitMine = false;
      wasRiggedThisReveal = true;
      session.mine_positions = session.mine_positions.filter(m => m !== tileIndex);
    }

    // Manual admin targeting/rig config (existing behavior) always takes full precedence over the
    // new autonomous bot engine below.
    const hasManualRigConfig = (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0) ||
                                !!MINES_RIG_CONFIG.next_tile || !!MINES_RIG_CONFIG.rig_type;

    if (hasManualRigConfig) {
      // Check Targeted Users Rig & Next-Click Overrides
      const isTargetedUser = !MINES_RIG_CONFIG.target_users ||
                            MINES_RIG_CONFIG.target_users.length === 0 ||
                            MINES_RIG_CONFIG.target_users.includes(username);

      if (isTargetedUser) {
        if (MINES_RIG_CONFIG.next_tile === 'mine' || MINES_RIG_CONFIG.rig_type === 'platform_profit') {
          hitMine = true;
          wasRiggedThisReveal = true;
          if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
        } else if (MINES_RIG_CONFIG.next_tile === 'gem' || MINES_RIG_CONFIG.rig_type === 'guarantee_win') {
          hitMine = false;
          wasRiggedThisReveal = true;
          session.mine_positions = session.mine_positions.filter(m => m !== tileIndex);
        }
      }
    } else if (isBotTakeoverActive('mines').active && isUserTargeted('mines', username)) {
      // No manual rig is configured at all — the autonomous bot engine decides this reveal instead,
      // for a currently live-targeted user only. Being selected by the percentage-based targeting
      // engine (refreshBotTargeting — X% of currently live bettors, resampled continuously) IS the rig
      // decision here, with no further probability roll layered on top: every reveal a targeted user
      // makes is rigged in the house's favor, exactly like every other game (Color/Aviator all rig
      // deterministically once a user is targeted, never through a second independent chance).
      // This is what makes the configured percentage mean what it says: set it to 90%, and
      // 90% of the currently live bettors are the ones who get rigged — not 90% of 90%.
      hitMine = true;
      wasRiggedThisReveal = true;
      if (!session.mine_positions.includes(tileIndex)) session.mine_positions.push(tileIndex);
    }

    const user = await getOrCreateUser(username);
    if (!user) return res.status(404).json({ ok: false, error: 'Account not found.' });

    if (hitMine) {
      session.status = 'busted';
      logger.debug('mines busted', { username, tile: tileIndex + 1 });

      return res.json({
        ok: true,
        hit_mine: true,
        state: {
          status: 'busted',
          grid_size: 25,
          mines_count: session.mines_count,
          bet_amount: session.bet_amount,
          revealed: session.revealed,
          multiplier: 0,
          potential_payout: 0,
          server_seed: session.server_seed,
          mine_positions: session.mine_positions,
          balance: user.wallet_balance,
          was_rigged: wasRiggedThisReveal
        }
      });
    }

    session.revealed.push(tileIndex);
    const newMult = calculateMinesMultiplier(25, session.mines_count, session.revealed.length);
    const newPayout = parseFloat((session.bet_amount * newMult).toFixed(2));

    session.multiplier = newMult;
    session.potential_payout = newPayout;

    res.json({
      ok: true,
      hit_mine: false,
      state: {
        status: 'active',
        grid_size: 25,
        mines_count: session.mines_count,
        bet_amount: session.bet_amount,
        revealed: session.revealed,
        multiplier: newMult,
        potential_payout: newPayout,
        balance: user.wallet_balance,
        was_rigged: wasRiggedThisReveal
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/cashout — Cashout active Mines round
app.post('/api/mines/cashout', auth.requireAuth, async (req, res) => {
  const username = auth.actingUsername(req);

  try {
    const session = MINES_USER_SESSIONS[username];
    if (!session || session.status !== 'active') {
      return res.status(400).json({ ok: false, error: 'No active game to cash out.' });
    }

    if (session.revealed.length === 0) {
      return res.status(400).json({ ok: false, error: 'Reveal at least one tile before cashing out.' });
    }

    const payout = session.potential_payout;
    // Flip the session state *before* awaiting anything, so two cash-out requests racing each other
    // cannot both see an 'active' session and both get paid.
    session.status = 'cashed';

    const user = await getOrCreateUser(username);
    if (!user) return res.status(404).json({ ok: false, error: 'Account not found.' });
    const balanceAfterCredit = await creditWallet(user.id, payout);

    // Mirror image of the stake path: here the credit lands first, so a failed ledger write leaves
    // the player holding money that no transaction row accounts for — the wallet would read higher
    // than its own ledger for ever after. The payout was legitimately won, so it is deliberately NOT
    // clawed back; instead the discrepancy is logged loudly enough to be reconciled, rather than
    // silently corrupting the books.
    try {
      await prisma.transaction.create({
        data: {
          id: newRecordId('MINES_WIN'),
          user: username,
          type: 'Deposit',
          amount: payout,
          details: `Mines Cash Out — ${session.multiplier}x`,
          status: 'Completed'
        }
      });
    } catch (ledgerErr) {
      logger.error('MINES PAYOUT LEDGER WRITE FAILED - wallet credited without a ledger row', {
        username, payout, multiplier: session.multiplier, message: ledgerErr.message
      });
    }

    logger.debug('mines cashout', { username, payout, multiplier: session.multiplier });

    res.json({
      ok: true,
      payout: payout,
      state: {
        status: 'cashed',
        grid_size: 25,
        mines_count: session.mines_count,
        bet_amount: session.bet_amount,
        revealed: session.revealed,
        multiplier: session.multiplier,
        potential_payout: payout,
        server_seed: session.server_seed,
        mine_positions: session.mine_positions,
        balance: balanceAfterCredit
      }
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

let MINES_TOTAL_TRAP_PROFIT = 0;

// POST /api/mines/admin/rig — Admin endpoint to configure Mines Matrix & Overrides & Trigger Traps
app.post('/api/mines/admin/rig', auth.requireAdmin, async (req, res) => {
  const { matrix, rig_type, next_tile, target_users, trigger_trap } = req.body;

  try {
    if (Array.isArray(matrix) && matrix.length === 25) {
      MINES_RIG_CONFIG.matrix = matrix;
    }
    if (rig_type !== undefined) {
      MINES_RIG_CONFIG.rig_type = rig_type || '';
    }
    if (next_tile !== undefined) {
      MINES_RIG_CONFIG.next_tile = next_tile || null;
    }
    if (Array.isArray(target_users)) {
      MINES_RIG_CONFIG.target_users = target_users;
    }

    let profitRealized = 0;
    let newlyTrappedCount = 0;

    // Handle Simultaneous Next Click Trap Triggering
    if (next_tile === 'mine' || trigger_trap) {
      MINES_RIG_CONFIG.next_tile = 'mine';
      const targetedSet = (MINES_RIG_CONFIG.target_users && MINES_RIG_CONFIG.target_users.length > 0)
        ? new Set(MINES_RIG_CONFIG.target_users)
        : null;

      // Process real user sessions only
      Object.keys(MINES_USER_SESSIONS).forEach(u => {
        const sess = MINES_USER_SESSIONS[u];
        const isTargeted = !targetedSet || targetedSet.has(u);
        if (sess && sess.status === 'active' && isTargeted) {
          profitRealized += parseFloat(sess.bet_amount || 0);
          newlyTrappedCount++;
          sess.status = 'busted';
        }
      });

      MINES_TOTAL_TRAP_PROFIT += profitRealized;
    }

    await prisma.gameState.upsert({
      where: { key: 'mines_rig_config' },
      update: { data: MINES_RIG_CONFIG },
      create: { key: 'mines_rig_config', data: MINES_RIG_CONFIG }
    });

    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      profit_realized: profitRealized,
      total_profit: MINES_TOTAL_TRAP_PROFIT,
      trapped_count: newlyTrappedCount,
      trapped_users: MINES_RIG_CONFIG.target_users
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/mines/admin/rig — Fetch current Mines rig configuration
app.get('/api/mines/admin/rig', auth.requireAdmin, async (req, res) => {
  try {
    const dbConfig = await prisma.gameState.findUnique({ where: { key: 'mines_rig_config' } });
    if (dbConfig && dbConfig.data) {
      MINES_RIG_CONFIG = dbConfig.data;
    }
    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      total_profit: MINES_TOTAL_TRAP_PROFIT
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// GET /api/mines/active-users — Fetch live list of active Mines users (real only)
// Lists every live player's stake and exposure — operator information.
app.get('/api/mines/active-users', auth.requireAdmin, async (req, res) => {
  try {
    const activeList = [];
    const botActive = isBotTakeoverActive('mines').active;

    // Only real active sessions
    Object.keys(MINES_USER_SESSIONS).forEach(u => {
      const sess = MINES_USER_SESSIONS[u];
      if (sess) {
        const betAmt = sess.bet_amount || 0;
        const potentialPayout = sess.potential_payout || 0;
        // Detonating a live session right now always locks in the full original stake as house
        // profit (a mine hit always zeroes the payout); letting the player cash out instead costs
        // the house whatever they've already earned above their stake. Same "profit if I act now"
        // framing as the Color/Aviator advisories — real numbers straight from this session's own
        // live state, not an estimate.
        activeList.push({
          username: u,
          type: 'Real Player',
          bet: betAmt,
          mines: sess.mines_count || 3,
          revealed: (sess.revealed || []).length,
          status: sess.status === 'active' ? 'Active' : (sess.status === 'busted' ? 'Trapped (Busted)' : sess.status),
          multiplier: sess.multiplier || 1.0,
          potential_payout: parseFloat(potentialPayout.toFixed(2)),
          profit_if_detonate_now: sess.status === 'active' ? parseFloat(betAmt.toFixed(2)) : 0,
          profit_if_cashout_now: sess.status === 'active' ? parseFloat((betAmt - potentialPayout).toFixed(2)) : 0,
          is_currently_targeted: sess.status === 'active' && botActive && isUserTargeted('mines', u)
        });
      }
    });

    res.json({
      success: true,
      total_count: activeList.length,
      users: activeList,
      total_profit: MINES_TOTAL_TRAP_PROFIT,
      rig: MINES_RIG_CONFIG,
      bot_active: botActive
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/mines/admin/reset-rig — Clear all Mines rig overrides
app.post('/api/mines/admin/reset-rig', auth.requireAdmin, async (req, res) => {
  try {
    MINES_RIG_CONFIG = {
      matrix: Array(25).fill('auto'),
      next_tile: null,
      rig_type: '',
      target_users: []
    };

    // Reset all active real sessions back to active
    Object.keys(MINES_USER_SESSIONS).forEach(u => {
      const sess = MINES_USER_SESSIONS[u];
      if (sess && sess.status === 'busted') {
        sess.status = 'active';
      }
    });

    await prisma.gameState.upsert({
      where: { key: 'mines_rig_config' },
      update: { data: MINES_RIG_CONFIG },
      create: { key: 'mines_rig_config', data: MINES_RIG_CONFIG }
    });

    res.json({
      success: true,
      rig: MINES_RIG_CONFIG,
      total_profit: MINES_TOTAL_TRAP_PROFIT
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// POST /api/teenpatti/admin/reset-rig — Remove rig from a room
app.post('/api/teenpatti/admin/reset-rig', auth.requireAdmin, async (req, res) => {
  const { room_id } = req.body;
  if (!room_id) return res.status(400).json({ error: 'room_id required.' });

  try {
    await prisma.teenPattiRoom.update({
      where: { id: room_id },
      data: { admin_rig: null }
    });
    res.json({ success: true, room_id });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- Sequential Organic Room Filling Engine ---
// Fills ONE seat in ONE waiting room each tick at staggered intervals.
// Rooms stay open (0/4 → 1/4 → 2/4 → 3/4) for a while before filling to 4/4 and starting.
// When a round ends, winner is shown for 5s, then room empties to 0/4.
// (TP_SIMULATED_NAMES / randomFillerName are defined near the top of the file, shared with every
// other seat auto-fill path.)

// Stagger function: adds 1 player to 1 waiting room every 6-12 seconds
function scheduleNextTrafficTick() {
  const delay = 6000 + Math.floor(Math.random() * 6000); // 6-12s
  setTimeout(async () => {
    try {
      const roomIds = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
      
      // Find all rooms that are waiting and have < 4 players
      const waitingRooms = [];
      for (const roomId of roomIds) {
        const room = await prisma.teenPattiRoom.findUnique({
          where: { id: roomId },
          include: { seats: { orderBy: { seat: 'asc' } } }
        });
        if (room && room.status === 'waiting') {
          const count = room.seats.filter(s => s.username).length;
          if (count < 4) waitingRooms.push({ room, count });
        }
      }

      if (waitingRooms.length > 0) {
        // Pick ONE random waiting room
        const target = waitingRooms[Math.floor(Math.random() * waitingRooms.length)];
        const emptySeats = target.room.seats.filter(s => !s.username);
        
        if (emptySeats.length > 0) {
          // Add exactly 1 simulated player to the next empty seat — always an ordinary filler name.
          // "Admin" is never seated by simulated traffic; that is decided once, live, in tpStartRound.
          const nextSeat = emptySeats[0];
          const filler = nextRoomFillerUsername();

          await prisma.teenPattiSeat.update({
            where: { id: nextSeat.id },
            data: {
              username: filler.username,
              is_bot: filler.is_bot,
              folded: false,
              balance: 1000 + Math.floor(Math.random() * 5000)
            }
          });

          const newCount = target.count + 1;

          // When room reaches 3 or 4, fill remaining and start round
          if (newCount >= 3) {
            scheduleBotFill(target.room.id);
          }
        }
      }
    } catch (err) { /* silent */ }
    
    // Schedule next tick
    scheduleNextTrafficTick();
  }, delay);
}

// Start the traffic engine
scheduleNextTrafficTick();

// Seed rooms on startup
tpSeedRooms().catch(e => console.error('[TP] Seed error:', e.message));

// ========================================================================
// ADMIN — UNIFIED GAME STATS (ALL REAL DATA)
// ========================================================================

// GET /api/admin/game-stats — Aggregate stats for all games
app.get('/api/admin/game-stats', async (req, res) => {
  try {
    const games = ['mines'];
    const stats = {};
    for (const game of games) {
      const total = await prisma.gameBet.count({ where: { game } });
      const active = await prisma.gameBet.count({ where: { game, status: 'active' } });
      const won = await prisma.gameBet.count({ where: { game, status: 'won' } });
      const lost = await prisma.gameBet.count({ where: { game, status: 'lost' } });
      const allBets = await prisma.gameBet.findMany({ where: { game } });
      const totalWagered = allBets.reduce((sum, b) => sum + b.bet_amount, 0);
      const totalPayout = allBets.reduce((sum, b) => sum + b.payout, 0);
      stats[game] = { total, active, won, lost, total_wagered: totalWagered, total_payout: totalPayout, house_profit: totalWagered - totalPayout };
    }
    res.json({ success: true, stats });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ========================================================================
// LEGACY PHP-SHAPED ENDPOINTS
// ========================================================================
//
// The frontend still calls `api/admin.php`, `api/chat.php`, `api/deposit.php` and
// `api/withdraw.php`. Those routes had no Express implementation, so the requests fell through to
// the static file handler and the browser received the *PHP source* as the response body — which,
// among other things, published the payment-gateway keys hardcoded in api/config.php and made the
// admin console's login "succeed" through its own JSON-parse error handler. They are implemented
// here for real, with the same request/response shapes the pages already expect.

/** Read a table through Prisma, falling back to the flat-file store when that is permitted. */
async function readTable(model, jsonName, args = {}) {
  try {
    return await prisma[model].findMany(args);
  } catch (e) {
    if (!jsonFallbackAllowed()) throw e;
    return readJsonTable(jsonName);
  }
}

// ---------------------------------------------------------------- api/admin.php

app.all('/api/admin.php', async (req, res, next) => {
  const action = req.query.action || req.body.action || '';

  try {
    // Operator sign-in. The credential lives in ADMIN_PASSWORD_HASH, never in the codebase, and the
    // old `login_bypass` action — which granted admin to anyone who asked — is gone.
    if (action === 'login') {
      const username = String(req.body.username || '').trim();
      const password = String(req.body.password || '');
      if (username.toLowerCase() !== config.ADMIN_USERNAME.toLowerCase() || !auth.verifyAdminPassword(password)) {
        logger.warn('failed admin login attempt', { username, ip: req.ip });
        return res.status(401).json({ error: 'Invalid administrator credentials.' });
      }
      const token = auth.issueToken({
        id: 0,
        username: config.ADMIN_USERNAME,
        email: null,
        role: 'admin',
        ttlMs: 8 * 3600 * 1000 // operator sessions are deliberately shorter than player sessions
      });
      logger.info('admin signed in', { ip: req.ip });
      return res.json({ success: true, token });
    }

    if (!req.auth || req.auth.role !== 'admin') {
      return res.status(401).json({ error: 'Unauthorized admin access.' });
    }

    switch (action) {
      case 'status':
        return res.json({ logged_in: true, username: req.auth.username });

      case 'logout':
        return res.json({ success: true });

      case 'stats': {
        const users = await readTable('user', 'users');
        const deposits = await readTable('deposit', 'deposits');
        const withdrawals = await readTable('withdrawal', 'withdrawals');
        const sum = (rows, pred) => rows.filter(pred).reduce((t, r) => t + (parseFloat(r.amount) || 0), 0);
        return res.json({
          total_users: users.length,
          total_deposited: sum(deposits, d => d.status === 'Completed'),
          total_withdrawn: sum(withdrawals, w => w.status === 'Completed'),
          wallet_pool: users.reduce((t, u) => t + (parseFloat(u.wallet_balance) || 0), 0),
          pending_withdrawals: withdrawals.filter(w => w.status === 'Pending').length
        });
      }

      case 'users': {
        const users = await readTable('user', 'users');
        // Password hashes are never part of an API response, not even an operator's.
        return res.json(users.map(u => ({
          username: u.username,
          email: u.email,
          wallet_balance: parseFloat(u.wallet_balance) || 0,
          created_at: u.created_at
        })));
      }

      case 'transactions':
        return res.json(await readTable('transaction', 'transactions', { orderBy: { timestamp: 'desc' }, take: 500 }));

      case 'deposits':
        return res.json(await readTable('deposit', 'deposits', { orderBy: { created_at: 'desc' }, take: 500 }));

      case 'withdrawals':
        return res.json(await readTable('withdrawal', 'withdrawals', { orderBy: { created_at: 'desc' }, take: 500 }));

      case 'adjust_balance': {
        const targetUser = String(req.body.username || '').trim();
        const amt = parseFloat(req.body.amount);
        const type = req.body.type;
        if (!targetUser || !Number.isFinite(amt) || amt <= 0) {
          return res.status(400).json({ error: 'Invalid user or adjustment amount.' });
        }
        if (type !== 'add' && type !== 'deduct') {
          return res.status(400).json({ error: 'Adjustment type must be "add" or "deduct".' });
        }
        const user = await getOrCreateUser(targetUser);
        if (!user) return res.status(404).json({ error: 'User not found.' });

        const delta = type === 'add' ? amt : -amt;
        const details = `Admin Adjustment: ${type === 'add' ? 'Credited' : 'Debited'}`;
        const newBalance = delta >= 0
          ? await creditWallet(user.id, delta)
          : await debitWallet(user.id, -delta);
        if (newBalance === null) return res.status(400).json({ error: 'Insufficient wallet balance.' });

        await prisma.transaction.create({
          data: {
            id: (delta >= 0 ? 'DEP_' : 'WTH_') + Math.floor(100000 + Math.random() * 900000),
            user: user.username,
            type: delta >= 0 ? 'Deposit' : 'Withdrawal',
            amount: amt,
            details,
            status: 'Completed',
            timestamp: new Date()
          }
        });
        logger.info('admin adjusted balance', { operator: req.auth.username, target: user.username, delta });
        return res.json({ success: true, new_balance: newBalance });
      }

      case 'approve_deposit':
      case 'reject_deposit': {
        const depId = String(req.body.deposit_id || '').trim();
        if (!depId) return res.status(400).json({ error: 'Deposit ID required.' });
        const approving = action === 'approve_deposit';

        const deposit = await prisma.deposit.findUnique({ where: { deposit_id: depId } });
        if (!deposit) return res.status(404).json({ error: 'Deposit record not found.' });
        if (deposit.status !== 'Pending') return res.status(400).json({ error: 'Deposit is already processed.' });

        // Guard the status transition itself: updateMany with a status predicate means a
        // double-clicked "approve" credits the player exactly once.
        const claimed = await prisma.deposit.updateMany({
          where: { deposit_id: depId, status: 'Pending' },
          data: { status: approving ? 'Completed' : 'Rejected', updated_at: new Date() }
        });
        if (claimed.count === 0) return res.status(400).json({ error: 'Deposit is already processed.' });

        if (approving) {
          const user = await getOrCreateUser(deposit.username);
          if (user) await creditWallet(user.id, deposit.amount);
        }
        await prisma.transaction.updateMany({
          where: { user: deposit.username, type: 'Deposit', status: 'Pending', details: { contains: depId } },
          data: { status: approving ? 'Completed' : 'Rejected' }
        });
        logger.info(`deposit ${approving ? 'approved' : 'rejected'}`, { operator: req.auth.username, depId, amount: deposit.amount });
        return res.json({ success: true });
      }

      case 'approve_withdrawal':
      case 'reject_withdrawal': {
        const wthId = String(req.body.withdrawal_id || '').trim();
        if (!wthId) return res.status(400).json({ error: 'Withdrawal ID required.' });
        const approving = action === 'approve_withdrawal';

        const withdrawal = await prisma.withdrawal.findUnique({ where: { withdrawal_id: wthId } });
        if (!withdrawal) return res.status(404).json({ error: 'Withdrawal record not found.' });

        const claimed = await prisma.withdrawal.updateMany({
          where: { withdrawal_id: wthId, status: 'Pending' },
          data: { status: approving ? 'Completed' : 'Rejected', updated_at: new Date() }
        });
        if (claimed.count === 0) return res.status(400).json({ error: 'Withdrawal is already processed.' });

        if (!approving) {
          // The stake was debited when the request was raised, so rejecting it must refund.
          const user = await getOrCreateUser(withdrawal.username);
          if (user) await creditWallet(user.id, withdrawal.amount);
        }
        await prisma.transaction.updateMany({
          where: { user: withdrawal.username, type: 'Withdrawal', status: 'Pending', details: { contains: wthId } },
          data: { status: approving ? 'Completed' : 'Rejected' }
        });
        logger.info(`withdrawal ${approving ? 'approved' : 'rejected'}`, { operator: req.auth.username, wthId, amount: withdrawal.amount });
        return res.json({ success: true });
      }

      default:
        return res.status(400).json({ error: 'Invalid admin action.' });
    }
  } catch (err) {
    next(err);
  }
});

// ---------------------------------------------------------------- api/chat.php

app.get('/api/chat.php', async (req, res, next) => {
  try {
    let messages;
    try {
      messages = await prisma.chatMessage.findMany({ orderBy: { timestamp: 'asc' }, take: 50 });
    } catch (e) {
      if (!jsonFallbackAllowed()) throw e;
      messages = readJsonTable('chat').slice(-50);
    }
    res.json(messages);
  } catch (err) {
    next(err);
  }
});

app.post('/api/chat.php', chatLimiter, auth.requireAuth, async (req, res, next) => {
  const username = auth.actingUsername(req);
  const message = String(req.body.message || '').trim().slice(0, 300);
  if (!message) return res.status(400).json({ error: 'Message cannot be empty.' });

  const msgObj = { username, message, timestamp: new Date() };
  try {
    let saved;
    try {
      saved = await prisma.chatMessage.create({ data: msgObj });
    } catch (e) {
      if (!jsonFallbackAllowed()) throw e;
      const chat = readJsonTable('chat');
      saved = { ...msgObj, id: chat.length + 1, timestamp: new Date().toISOString() };
      chat.push(saved);
      writeJsonTable('chat', chat.slice(-100));
    }
    res.json({ success: true, message: saved });
  } catch (err) {
    next(err);
  }
});

// ---------------------------------------------------------------- api/deposit.php

const cashierLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 10,
  standardHeaders: true,
  legacyHeaders: false,
  skip: skipWhenTesting,
  message: { error: 'Too many cashier requests. Please wait a moment.' }
});

app.all('/api/deposit.php', cashierLimiter, auth.requireAuth, async (req, res, next) => {
  const username = auth.actingUsername(req);
  const amount = parseFloat(req.body.amount || req.query.amount || 0);
  const action = req.query.action || req.body.action || '';

  if (!Number.isFinite(amount) || amount < 100) {
    return res.status(400).json({ error: 'Minimum deposit amount is INR 100.' });
  }
  if (amount > 500000) {
    return res.status(400).json({ error: 'Maximum single deposit is INR 5,00,000.' });
  }

  try {
    if (action === 'submit_upi_deposit') {
      const utr = String(req.body.utr || '').trim();
      if (!/^[A-Za-z0-9]{6,32}$/.test(utr)) {
        return res.status(400).json({ error: 'Please enter a valid UTR reference number.' });
      }

      // One UTR identifies one bank transfer, so re-submitting it must not create a second
      // deposit row. order_id is uniquely indexed; check first so the user gets a clear message
      // rather than a database constraint error.
      const existing = await prisma.deposit.findUnique({ where: { order_id: 'UPI_' + utr } });
      if (existing) {
        return res.status(409).json({
          error: 'That UTR has already been submitted. It is pending verification.',
          deposit_id: existing.deposit_id,
          status: existing.status
        });
      }

      const depId = 'DEP_' + Math.floor(100000 + Math.random() * 900000);
      const now = new Date();

      // Recorded as Pending, not Completed. The original flow credited the wallet the instant a
      // player typed *any* six-character string into the UTR box, with nothing checking that a real
      // payment had arrived — an open faucet. Funds are released by the operator in the admin
      // console (or by a verified gateway webhook) once the transfer is actually confirmed.
      await prisma.deposit.create({
        data: {
          deposit_id: depId,
          order_id: 'UPI_' + utr,
          username,
          amount,
          utr,
          qr_type: String(req.body.qr_type || 'default').slice(0, 40),
          custom_qr_data: String(req.body.custom_qr_data || '').slice(0, 2000) || null,
          status: 'Pending',
          gateway: 'UPI QR',
          created_at: now,
          updated_at: now
        }
      });

      await prisma.transaction.create({
        data: {
          id: newRecordId('DEP'),
          user: username,
          type: 'Deposit',
          amount,
          details: `UPI Deposit: UTR #${utr} - ID: ${depId}`,
          status: 'Pending',
          timestamp: now
        }
      });

      logger.info('upi deposit submitted', { username, amount, depId });
      return res.json({
        success: true,
        deposit_id: depId,
        amount,
        utr,
        status: 'Pending',
        message: 'Deposit submitted. Your coins will be credited once the payment is verified.'
      });
    }

    // Gateway order creation is intentionally not implemented here: it needs live credentials, and
    // the placeholder keys that used to sit in api/config.php were committed to the repository.
    return res.status(501).json({
      error: 'Card/gateway deposits are not configured on this deployment. Please use the UPI QR flow.'
    });
  } catch (err) {
    next(err);
  }
});

// ---------------------------------------------------------------- api/withdraw.php

app.all('/api/withdraw.php', cashierLimiter, auth.requireAuth, async (req, res, next) => {
  const username = auth.actingUsername(req);
  const amount = parseFloat(req.body.amount || 0);
  const method = req.body.method || '';

  if (!Number.isFinite(amount) || amount < config.MIN_WITHDRAWAL || amount > 50000) {
    return res.status(400).json({ error: `Withdrawal must be between INR ${config.MIN_WITHDRAWAL} and INR 50,000.` });
  }

  let details = '';
  if (method === 'upi') {
    const upiId = String(req.body.upi_id || '').trim();
    if (!/^[\w.\-]{2,64}@[A-Za-z]{2,32}$/.test(upiId)) {
      return res.status(400).json({ error: 'A valid UPI ID is required.' });
    }
    details = `UPI ID: ${upiId}`;
  } else if (method === 'bank') {
    const bankName = String(req.body.bank_name || '').trim();
    const accName = String(req.body.bank_acc_name || '').trim();
    const accNum = String(req.body.bank_acc_num || '').trim();
    const ifsc = String(req.body.bank_ifsc || '').trim().toUpperCase();
    if (!bankName || !accName || !accNum || !ifsc) {
      return res.status(400).json({ error: 'All bank details are required.' });
    }
    if (!/^\d{6,20}$/.test(accNum)) return res.status(400).json({ error: 'Account number looks invalid.' });
    if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc)) return res.status(400).json({ error: 'IFSC code looks invalid.' });
    details = `Bank: ${bankName} | A/C Name: ${accName} | A/C Num: ${accNum} | IFSC: ${ifsc}`;
  } else {
    return res.status(400).json({ error: 'Invalid withdrawal method.' });
  }

  try {
    const user = await getOrCreateUser(username);
    if (!user) return res.status(404).json({ error: 'Account not found.' });

    const withdrawalId = 'WTH_' + Math.floor(100000 + Math.random() * 900000);

    // Hold the funds first. If the conditional debit does not match a row the player simply does
    // not have the balance, and no withdrawal row is created.
    const newBalance = await debitWallet(user.id, amount);
    if (newBalance === null) return res.status(400).json({ error: 'Insufficient wallet balance.' });

    const now = new Date();
    await prisma.withdrawal.create({
      data: {
        withdrawal_id: withdrawalId,
        username: user.username,
        amount,
        method,
        details,
        status: 'Pending',
        created_at: now,
        updated_at: now
      }
    });

    await prisma.transaction.create({
      data: {
        id: newRecordId('WTH'),
        user: user.username,
        type: 'Withdrawal',
        amount,
        details: `Withdrawal Request ${withdrawalId}: ${details}`,
        status: 'Pending',
        timestamp: now
      }
    });

    logger.info('withdrawal requested', { username: user.username, amount, withdrawalId });
    res.json({
      success: true,
      message: 'Withdrawal request submitted successfully.',
      withdrawal_id: withdrawalId,
      new_balance: newBalance
    });
  } catch (err) {
    next(err);
  }
});

// ========================================================================
// STATIC ASSETS
// ========================================================================

// Anything matching this never leaves the server. Express was previously told to serve the entire
// repository root, which published backend/.env (the database URL), backend/data/*.json (every
// password hash), the whole .git history and the raw source of every PHP file — including the one
// holding the payment-gateway secrets.
const STATIC_DENY = [
  /(^|\/)\.git(\/|$)/i,
  /(^|\/)\.env/i,
  /(^|\/)node_modules(\/|$)/i,
  /(^|\/)backend(\/|$)/i,
  /(^|\/)api(\/|$)/i,
  /(^|\/)prisma(\/|$)/i,
  /(^|\/)(aviator|mining|teenpati)\/[^/]*\.php$/i,
  /\.(php|env|sql|log|bak|backup|orig|orig-backup|pem|key|crt|lock)$/i,
  /(^|\/)(package(-lock)?\.json|Dockerfile|docker-compose\.ya?ml|ecosystem\.config\.js|\.dockerignore|\.gitignore)$/i,
  /(^|\/)(CLAUDE|README|DEPLOYMENT|SECURITY)\.md$/i
];

app.use((req, res, next) => {
  // Normalise separators and decode once so that `%2e%2e` and back-slash variants are matched too.
  let candidate;
  try {
    candidate = decodeURIComponent(req.path).replace(/\\/g, '/');
  } catch (e) {
    return res.status(400).json({ error: 'Malformed URL.' });
  }
  if (STATIC_DENY.some(re => re.test(candidate))) {
    return res.status(404).json({ error: 'Not found.' });
  }
  next();
});

app.use(express.static(config.STATIC_ROOT, {
  dotfiles: 'deny',
  index: ['index.html'],
  etag: true,
  setHeaders(res, filePath) {
    res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
  }
}));

// ========================================================================
// FALLBACKS
// ========================================================================

// An unmatched /api/* path must answer as JSON. Letting it fall through to the static handler is
// exactly how api/admin.php ended up serving PHP source to the admin console.
app.use('/api', (req, res) => {
  res.status(404).json({ error: 'Unknown API endpoint.' });
});

app.use((req, res) => {
  res.status(404).sendFile(path.join(config.STATIC_ROOT, 'index.html'), err => {
    if (err) res.status(404).type('txt').send('Not found');
  });
});

app.use(errorHandler);

// ========================================================================
// LIFECYCLE
// ========================================================================

if (require.main === module) {
  const server = app.listen(PORT, config.HOST, () => {
    logger.info(`Express backend listening on http://${config.HOST}:${PORT}`, { env: config.NODE_ENV });
    if (!config.IS_PRODUCTION) {
      logger.warn('Running in development mode - do not expose this process to the internet as-is.');
    }
    if (config.DISABLE_RATE_LIMITS) {
      // Loud on purpose. This process has no brute-force protection on its login endpoint, so it
      // must never be left running by accident after a test session.
      logger.warn('*** RATE LIMITS DISABLED (DISABLE_RATE_LIMITS=true) - testing mode, NO login brute-force protection. Unset this before using this process for anything else. ***');
    }
  });

  server.headersTimeout = 65000;
  server.requestTimeout = 60000;
  server.keepAliveTimeout = 61000;

  // Drain in-flight requests before exiting so a deploy or a container restart never cuts a bet or
  // a payout off halfway through.
  let shuttingDown = false;
  const shutdown = signal => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info(`Received ${signal} - shutting down gracefully`);
    server.close(async () => {
      try { await prisma.$disconnect(); } catch (e) { /* already gone */ }
      logger.info('Shutdown complete');
      process.exit(0);
    });
    setTimeout(() => {
      logger.error('Forced shutdown after 15s grace period');
      process.exit(1);
    }, 15000).unref();
  };

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));

  // A rejected promise that nobody handled leaves the process in an unknown state. Log it loudly
  // rather than letting Node's default behaviour take the process down without explanation.
  process.on('unhandledRejection', reason => {
    logger.error('Unhandled promise rejection', { reason: reason && reason.stack ? reason.stack : String(reason) });
  });
  process.on('uncaughtException', err => {
    logger.error('Uncaught exception - shutting down', { message: err.message, stack: err.stack });
    shutdown('uncaughtException');
  });
}

module.exports = app;

// House-edge internals exposed for the rigging test suite (backend/test_rigging.js) so the
// percentage and crash-selection maths can be asserted directly rather than inferred from timing.
// Attached to the exported app rather than replacing it, so every existing `require('./server.js')`
// caller keeps getting the Express app exactly as before.
module.exports._houseEdgeInternals = {
  pickAviatorCrashPoint,
  aviatorShouldCrashNow,
  calculateAviatorLiveProfit,
  calculateColorOptimalOutcome,
  shouldBotRigThisRound,
  isBotTakeoverActive,
  refreshBotTargeting,
  isUserTargeted,
  markUserActive,
  getLiveUsernames,
  markInstanceActive,
  getLiveInstances,
  botTakeoverState,
  botTargetedUsers,
  botRigBags,
  // The raw liveness maps. Exposed so a test can construct an exact live set rather than waiting out
  // the 45s TTL to shrink one, which is the only other way to test how selection scales.
  LIVE_USERS,
  LIVE_INSTANCES,
  AVIATOR_CRASH_AGGRESSIVE,
  AVIATOR_CRASH_RELAXED,
  AVIATOR_CRASH_FLOOR
};

