<?php
/**
 * Signed session tokens and the authorisation guards. Port of backend/lib/auth.js.
 *
 * The token format is reproduced BYTE FOR BYTE, which is what lets a token already sitting in a
 * player's localStorage keep working across the cutover:
 *
 *     v1.<base64url(JSON payload)>.<base64url(HMAC-SHA256 of "v1." + payloadB64)>
 *
 * base64url here means standard base64 with '+'->'-', '/'->'_' and trailing '=' removed.
 * Verification never re-serialises the payload — it signs the payloadB64 string exactly as
 * received — so a token minted by the Node build verifies here unchanged, provided APP_SECRET is
 * the same. Issuing matches too: the payload keys are written in the same order Node wrote them,
 * and js_json_encode() reproduces JSON.stringify's exact output.
 *
 * Passwords: bcryptjs and PHP produce interchangeable hashes. password_verify() accepts the $2a$
 * and $2b$ prefixes already in this database, and password_hash(PASSWORD_BCRYPT) emits $2y$, which
 * bcryptjs also verifies. Cost stays at BCRYPT_ROUNDS (12) so new hashes match the old ones.
 */

require_once __DIR__ . '/json.php';

const TOKEN_VERSION = 'v1';

function b64url($bytes) {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function from_b64url($str) {
    $padded = strtr((string)$str, '-_', '+/');
    $rem = strlen($padded) % 4;
    if ($rem) $padded .= str_repeat('=', 4 - $rem);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? '' : $decoded;
}

function token_sign($payloadB64) {
    return b64url(hash_hmac('sha256', TOKEN_VERSION . '.' . $payloadB64, cfg('APP_SECRET'), true));
}

/**
 * Issue a session token. `role` is 'user' for players and 'admin' for the operator console.
 *
 * Note `id ?: null` and `email ?: null`: the original wrote `id || null` and `email || null`, so a
 * falsy id — the operator token is issued with id 0 — serialises as null. Reproduced deliberately.
 */
function issue_token($opts) {
    $ttl = isset($opts['ttlMs']) ? (int)$opts['ttlMs'] : (int) cfg('SESSION_TTL_MS');
    $now = now_ms();
    $payload = [
        'v'        => TOKEN_VERSION,
        'id'       => !empty($opts['id']) ? $opts['id'] : null,
        'username' => $opts['username'],
        'email'    => !empty($opts['email']) ? $opts['email'] : null,
        'role'     => isset($opts['role']) ? $opts['role'] : 'user',
        'iat'      => $now,
        'exp'      => $now + $ttl,
    ];
    $payloadB64 = b64url(js_json_encode($payload));
    return TOKEN_VERSION . '.' . $payloadB64 . '.' . token_sign($payloadB64);
}

/**
 * Verify signature and expiry. Returns the payload array, or null for anything malformed,
 * unsigned, tampered with, or expired.
 */
function verify_token($token) {
    if (!is_string($token) || $token === '') return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null; // legacy unsigned tokens land here and are rejected
    list($version, $payloadB64, $signature) = $parts;
    if ($version !== TOKEN_VERSION) return null;

    // hash_equals is constant time and length-aware, matching the Node build's explicit length
    // check plus crypto.timingSafeEqual.
    if (!hash_equals(token_sign($payloadB64), $signature)) return null;

    $payload = json_decode(from_b64url($payloadB64), true);
    if (!is_array($payload)) return null;
    if (!isset($payload['username']) || !is_string($payload['username']) || $payload['username'] === '') return null;
    if (empty($payload['exp']) || $payload['exp'] < now_ms()) return null;
    return $payload;
}

/** Bearer header, then ?token=, then a body `token` field — same order as the original. */
function extract_token(Req $req) {
    $header = $req->header('authorization');
    if (is_string($header) && strpos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    $qt = $req->q('token');
    if (is_string($qt)) return $qt;
    $bt = $req->b('token');
    if (is_string($bt)) return $bt;
    return null;
}

/** Populates $req->auth with the verified session (or null). Never rejects a request on its own. */
function attach_session(Req $req, Res $res) {
    $req->auth = verify_token(extract_token($req));
}

/** Reject anonymous callers. */
function require_auth(Req $req, Res $res) {
    if (!$req->auth) {
        $res->status(401)->json(['error' => 'Authentication required. Please sign in again.']);
    }
}

/** Reject anyone who is not authenticated as an operator or super administrator. */
function require_admin(Req $req, Res $res) {
    if (!$req->auth) {
        $res->status(401)->json(['error' => 'Authentication required.']);
        return;
    }
    if (($req->auth['role'] ?? '') !== 'admin' && ($req->auth['role'] ?? '') !== 'superadmin') {
        $res->status(403)->json(['error' => 'Administrator privileges required.']);
    }
}

/**
 * Reject anyone who is not the super administrator specifically.
 *
 * The platform issues two distinct credentials against two distinct login pages, which implies two
 * privilege tiers — but until this existed only require_admin was ever applied, so both tiers had
 * identical authority and the separation was cosmetic. Any operator token could read the whole
 * super-dashboard: all-time house profit, every player's net win/loss position, the top winners and
 * losers, and the full transaction feed.
 *
 * Note the ordering: 401 when there is no session at all, 403 when there is one that simply is not
 * privileged enough. Collapsing those would tell an ordinary admin that the endpoint exists but is
 * out of reach, and tell an unauthenticated caller nothing useful either way.
 */
function require_superadmin(Req $req, Res $res) {
    if (!$req->auth) {
        $res->status(401)->json(['error' => 'Authentication required.']);
        return;
    }
    if (($req->auth['role'] ?? '') !== 'superadmin') {
        log_warn('super-admin endpoint refused', [
            'role' => $req->auth['role'] ?? null,
            'user' => $req->auth['username'] ?? null,
        ]);
        $res->status(403)->json(['error' => 'Super administrator privileges required.']);
    }
}

function is_admin(Req $req) {
    return $req->auth && in_array($req->auth['role'] ?? '', ['admin', 'superadmin'], true);
}

function is_superadmin(Req $req) {
    return $req->auth && ($req->auth['role'] ?? '') === 'superadmin';
}

/**
 * The authoritative username for a money-moving request.
 *
 * Client-supplied `username` fields are ignored for ordinary players. Administrators may act on
 * behalf of a named user, because the admin console legitimately needs it, but only after passing
 * require_admin.
 */
function acting_username(Req $req) {
    if (!$req->auth) return null;
    if (($req->auth['role'] ?? '') === 'admin' || ($req->auth['role'] ?? '') === 'superadmin') {
        $target = $req->b('username');
        if (!is_string($target) || trim($target) === '') $target = $req->q('username');
        if (is_string($target) && trim($target) !== '') return trim($target);
    }
    return $req->auth['username'];
}

/** Verify an operator password against ADMIN_PASSWORD_HASH (or the dev-only plaintext fallback). */
function verify_admin_password($password) {
    if (!is_string($password) || $password === '') return false;
    $hash = cfg('ADMIN_PASSWORD_HASH');
    if ($hash !== '') {
        try { return password_verify($password, $hash); } catch (Throwable $e) { return false; }
    }
    $plain = cfg('ADMIN_PASSWORD_PLAINTEXT');
    if (!cfg('IS_PRODUCTION') && $plain !== '') {
        return hash_equals($plain, $password);
    }
    return false;
}

/** Verify a super operator password against SUPERADMIN_PASSWORD_HASH (or the dev-only plaintext fallback). */
function verify_superadmin_password($password) {
    if (!is_string($password) || $password === '') return false;
    $hash = cfg('SUPERADMIN_PASSWORD_HASH');
    if ($hash !== '') {
        try { return password_verify($password, $hash); } catch (Throwable $e) { return false; }
    }
    $plain = cfg('SUPERADMIN_PASSWORD_PLAINTEXT');
    if (!cfg('IS_PRODUCTION') && $plain !== '') {
        return hash_equals($plain, $password);
    }
    return false;
}

/** bcrypt hash at the configured cost, matching bcryptjs.hashSync(p, BCRYPT_ROUNDS). */
function hash_password($plain) {
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => (int) cfg('BCRYPT_ROUNDS', 12)]);
}

/** bcryptjs.compareSync equivalent. Handles $2a$, $2b$ and $2y$ hashes alike. */
function check_password($plain, $hash) {
    if (!is_string($hash) || $hash === '') return false;
    try { return password_verify($plain, $hash); } catch (Throwable $e) { return false; }
}
