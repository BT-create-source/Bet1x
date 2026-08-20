/**
 * Signed session tokens and the authorisation middleware that guards every endpoint able to move
 * money or change house configuration.
 *
 * The previous scheme encoded the session as plain base64 JSON with no signature, which meant a
 * player could hand-craft a token for any username — including an administrator — simply by
 * base64-encoding a JSON object. Tokens are now HMAC-SHA256 signed with APP_SECRET and verified in
 * constant time, so the payload can be read by the client but not altered.
 */

const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const config = require('../config');

const TOKEN_VERSION = 'v1';

function b64url(buf) {
  return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromB64url(str) {
  const padded = String(str).replace(/-/g, '+').replace(/_/g, '/');
  return Buffer.from(padded + '='.repeat((4 - (padded.length % 4)) % 4), 'base64');
}

function sign(payloadB64) {
  return b64url(crypto.createHmac('sha256', config.APP_SECRET).update(`${TOKEN_VERSION}.${payloadB64}`).digest());
}

/**
 * Issue a session token. `role` is 'user' for players and 'admin' for the operator console.
 */
function issueToken({ id, username, email, role = 'user', ttlMs = config.SESSION_TTL_MS }) {
  const payload = {
    v: TOKEN_VERSION,
    id: id || null,
    username,
    email: email || null,
    role,
    iat: Date.now(),
    exp: Date.now() + ttlMs
  };
  const payloadB64 = b64url(JSON.stringify(payload));
  return `${TOKEN_VERSION}.${payloadB64}.${sign(payloadB64)}`;
}

/**
 * Verify a token's signature and expiry. Returns the payload, or null for anything that is
 * malformed, unsigned, tampered with, or expired.
 */
function verifyToken(token) {
  if (!token || typeof token !== 'string') return null;
  const parts = token.split('.');
  if (parts.length !== 3) return null; // legacy unsigned tokens land here and are rejected
  const [version, payloadB64, signature] = parts;
  if (version !== TOKEN_VERSION) return null;

  const expected = sign(payloadB64);
  const a = Buffer.from(signature);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;

  let payload;
  try {
    payload = JSON.parse(fromB64url(payloadB64).toString('utf8'));
  } catch (e) {
    return null;
  }
  if (!payload || typeof payload.username !== 'string' || !payload.username) return null;
  if (!payload.exp || payload.exp < Date.now()) return null;
  return payload;
}

function extractToken(req) {
  const header = req.headers && req.headers.authorization;
  if (header && header.startsWith('Bearer ')) return header.slice(7).trim();
  if (req.query && typeof req.query.token === 'string') return req.query.token;
  if (req.body && typeof req.body.token === 'string') return req.body.token;
  return null;
}

/**
 * Populates `req.auth` with the verified session (or null). Never throws and never rejects a
 * request on its own — route-level guards decide what an anonymous caller may do.
 */
function attachSession(req, _res, next) {
  req.auth = verifyToken(extractToken(req));
  next();
}

/** Reject anonymous callers. */
function requireAuth(req, res, next) {
  if (!req.auth) {
    return res.status(401).json({ error: 'Authentication required. Please sign in again.' });
  }
  next();
}

/** Reject anyone who is not authenticated as an operator. */
function requireAdmin(req, res, next) {
  if (!req.auth) {
    return res.status(401).json({ error: 'Authentication required.' });
  }
  if (req.auth.role !== 'admin') {
    return res.status(403).json({ error: 'Administrator privileges required.' });
  }
  next();
}

/**
 * The authoritative username for a money-moving request.
 *
 * Client-supplied `username` fields are ignored for ordinary players — that parameter used to be
 * trusted, which let anyone place bets, cash out, or adjust the balance of any other account.
 * Administrators may still act on behalf of a named user (the admin console legitimately needs
 * this), but only after passing requireAdmin.
 */
function actingUsername(req) {
  if (!req.auth) return null;
  if (req.auth.role === 'admin') {
    const target = (req.body && req.body.username) || (req.query && req.query.username);
    if (typeof target === 'string' && target.trim()) return target.trim();
  }
  return req.auth.username;
}

/** Verify an operator password against ADMIN_PASSWORD_HASH (or the dev-only plaintext fallback). */
function verifyAdminPassword(password) {
  if (typeof password !== 'string' || !password) return false;
  if (config.ADMIN_PASSWORD_HASH) {
    try {
      return bcrypt.compareSync(password, config.ADMIN_PASSWORD_HASH);
    } catch (e) {
      return false;
    }
  }
  if (!config.IS_PRODUCTION && config.ADMIN_PASSWORD_PLAINTEXT) {
    const a = Buffer.from(password);
    const b = Buffer.from(config.ADMIN_PASSWORD_PLAINTEXT);
    return a.length === b.length && crypto.timingSafeEqual(a, b);
  }
  return false;
}

module.exports = {
  issueToken,
  verifyToken,
  extractToken,
  attachSession,
  requireAuth,
  requireAdmin,
  actingUsername,
  verifyAdminPassword
};
