<?php
/**
 * The Sub-Admin system: accounts, sessions, and every coin and cash movement a sub-admin makes.
 *
 * Roles: Super Admin and Admin (the two .env operator logins, unchanged), Sub-Admin (database
 * accounts, this file), User (the existing players). A sub-admin buys coins from the Admin, sells
 * them to their own users for cash collected outside the site, and pays out their users' withdrawals.
 *
 * THE RULES THIS FILE ENFORCES
 *
 *   1. The Admin's coin supply is UNLIMITED. There is no Admin coin balance anywhere: an allocation
 *      creates coins in the sub-admin's inventory from the "admin_supply" account, which is never
 *      debited, never capped and never read back as a number.
 *   2. A sub-admin's inventory ("SubAdmin"."coin_balance") changes only here, only inside a
 *      transaction holding that sub-admin's lock (sa_lock), and only by an atomic conditional
 *      UPDATE — it can never go negative (there is also a CHECK constraint behind that).
 *   3. Every coin movement writes one append-only row to SubAdminLedger; every money movement writes
 *      a row to the separate cash ledger SubAdminCash, linked by reference ids. Corrections are new
 *      reversal / adjustment rows that reference the original; a trigger refuses deletes.
 *   4. Game play is untouched. A user's wins and losses move coins between their wallet and the
 *      platform (the house) exactly as before; they never touch a sub-admin's inventory. Losses do
 *      NOT return to the sub-admin. The sub-admin's coins move only through: allocations (+),
 *      confirmed sales to users (−) and authorised corrections. When a sub-admin pays out a
 *      withdrawal, the player's coins are retired to the Admin's supply (a fixed rule): the
 *      sub-admin's inventory does not grow, so to sell more they buy more from the Admin.
 *   5. Repeats are harmless: creations carry an idempotency key, every state change is a
 *      conditional UPDATE on the current status, and an external payment reference can back only
 *      one live cash record (a unique index).
 *
 * Schema: sql/migration-018-subadmins-postgres.sql, applied automatically on first use.
 */

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

const SUBADMIN_SCHEMA_VERSION = 18;
const SUBADMIN_SESSION_TTL_MS = 12 * 3600 * 1000;

/** A refusal with an HTTP status, safe to show to the operator as-is. */
class SubAdminError extends RuntimeException {
    public $httpStatus;
    public $errCode;
    public $extra;
    public function __construct($message, $httpStatus = 400, $errCode = null, $extra = []) {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
        $this->errCode = $errCode;
        $this->extra = $extra;
    }
}

function sa_fail($message, $status = 400, $code = null, $extra = []) {
    throw new SubAdminError($message, $status, $code, $extra);
}

// -------------------------------------------------------------------------------------------------
// Schema
// -------------------------------------------------------------------------------------------------

/**
 * Create the sub-admin tables once per deployment. MUST run outside a transaction (it is DDL).
 * Returns false when there is no database (JSON-fallback development mode) — every sub-admin route
 * then answers 503 rather than half-working.
 */
function subadmin_ensure($allowDdl = true) {
    static $done = null;
    if ($done !== null) return $done;
    if (!$allowDdl) {
        // Inside a transaction: only look at the version flag (never run DDL there), and do not cache.
        $v = db_best_effort(function () { return (int) state_get('subadmin_schema_version'); });
        return (int) $v >= SUBADMIN_SCHEMA_VERSION;
    }
    try {
        if (!db_ready()) { $done = false; return false; }
        if ((int) state_get('subadmin_schema_version') < SUBADMIN_SCHEMA_VERSION) {
            $sql = @file_get_contents(__DIR__ . '/../sql/migration-018-subadmins-postgres.sql');
            if (!is_string($sql) || $sql === '') throw new RuntimeException('migration-018 file missing');
            db_or_throw()->exec($sql);
            state_set('subadmin_schema_version', SUBADMIN_SCHEMA_VERSION);
            log_info('sub-admin schema applied (migration 018)');
        }
        $done = true;
    } catch (Throwable $e) {
        log_error('sub-admin schema could not be applied', ['message' => $e->getMessage()]);
        $done = false;
    }
    return $done;
}

// -------------------------------------------------------------------------------------------------
// Small helpers: validation, ids, settings
// -------------------------------------------------------------------------------------------------

/** A readable, collision-safe reference id: PREFIX_ + 12 characters (60 random bits). */
function sa_ref($prefix) {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $bytes = random_bytes(12);
    $s = '';
    for ($i = 0; $i < 12; $i++) $s .= $alphabet[ord($bytes[$i]) & 31];
    return $prefix . '_' . $s;
}

/**
 * A coin or rupee amount from user input: finite, within bounds, at most two decimal places.
 * Returns the amount rounded to the paisa, or throws with a message that names the field.
 */
function sa_amount($value, $label, $min = 0.01, $max = 100000000) {
    if (is_string($value)) {
        $value = trim($value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) sa_fail($label . ' must be a number.');
        $f = (float) $value;
    } elseif (is_int($value) || is_float($value)) {
        $f = (float) $value;
    } else {
        sa_fail($label . ' is required.');
    }
    if (!js_is_finite($f)) sa_fail($label . ' must be a number.');
    if (abs($f * 100 - round($f * 100)) > 1e-6) sa_fail($label . ' can have at most 2 decimal places.');
    $f = round2($f);
    if ($f < $min) sa_fail($label . ' must be at least ' . js_num_str($min) . '.');
    if ($f > $max) sa_fail($label . ' must be at most ' . number_format($max, 0, '.', ',') . '.');
    return $f;
}

/** Free text (notes, reasons): trimmed, control characters removed, length-capped. */
function sa_text($value, $max, $label, $required = false, $min = 0) {
    $s = is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    if ($s === '') {
        if ($required) sa_fail($label . ' is required.');
        return null;
    }
    if (mb_strlen($s) < $min) sa_fail($label . ' must be at least ' . $min . ' characters.');
    if (mb_strlen($s) > $max) sa_fail($label . ' must be ' . $max . ' characters or fewer.');
    return $s;
}

/** A yes/no flag from a form or JSON body. */
function sa_bool($v) {
    if (is_bool($v)) return $v;
    if (is_int($v)) return $v === 1;
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
}

function sa_methods() { return ['UPI', 'Cash', 'Bank transfer', 'Other']; }

/** A payment method from the fixed list, canonical spelling. */
function sa_method($value, $required = true) {
    $v = strtolower(trim((string) ($value ?? '')));
    if ($v === '') {
        if ($required) sa_fail('Payment method is required (' . implode(', ', sa_methods()) . ').');
        return null;
    }
    foreach (sa_methods() as $m) if (strtolower($m) === $v) return $m;
    sa_fail('Payment method must be one of: ' . implode(', ', sa_methods()) . '.');
}

/** An external payment reference (UPI UTR, bank reference, receipt number), or null. */
function sa_reference($value) {
    $v = trim((string) ($value ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^[A-Za-z0-9 _\-\/.#:]{3,64}$/', $v)) {
        sa_fail('Payment reference must be 3-64 letters, digits or - _ / . # : characters.');
    }
    return $v;
}

/** The client-generated key that makes a create request safe to repeat. */
function sa_idempotency_key($value) {
    $v = trim((string) ($value ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $v)) sa_fail('Invalid request key.');
    return $v;
}

function sa_rate() { return (float) cfg('COIN_RUPEE_RATE', 1); }

/** Operator-configurable workflow rules, with safe defaults. */
function sa_settings_defaults() {
    return [
        // When an allocation's coins reach the sub-admin: once its payment is confirmed in full
        // ('after_payment', the safe default), or straight away ('immediate', on credit — the agreed
        // amount is then an outstanding obligation until the payment is confirmed).
        'allocation_credit'   => 'after_payment',
        // FIXED business rule, not a setting: the coins of a withdrawal a sub-admin pays out are
        // retired to the Admin's unlimited supply. The sub-admin's inventory does not grow from
        // payouts, so to sell more coins they must buy them from the Admin.
        'withdrawal_coins_to' => 'admin_supply',
    ];
}

function sa_settings() {
    $out = sa_settings_defaults();
    try { $s = state_get('subadmin_settings'); } catch (Throwable $e) { $s = null; }
    if (is_array($s)) {
        if (in_array($s['allocation_credit'] ?? '', ['after_payment', 'immediate'], true)) $out['allocation_credit'] = $s['allocation_credit'];
    }
    // Always the fixed rule, whatever an older saved setting says.
    $out['withdrawal_coins_to'] = 'admin_supply';
    return $out;
}

function sa_settings_save(array $patch) {
    $cur = sa_settings();
    if (array_key_exists('allocation_credit', $patch)) {
        if (!in_array($patch['allocation_credit'], ['after_payment', 'immediate'], true)) sa_fail('allocation_credit must be after_payment or immediate.');
        $cur['allocation_credit'] = $patch['allocation_credit'];
    }
    if (array_key_exists('withdrawal_coins_to', $patch) && $patch['withdrawal_coins_to'] !== 'admin_supply') {
        sa_fail('Paid-out withdrawal coins always return to the Admin\'s supply; this rule cannot be changed.');
    }
    $cur['withdrawal_coins_to'] = 'admin_supply';
    state_set('subadmin_settings', $cur);
    return $cur;
}

/** Who is acting: ['name' => ..., 'role' => 'superadmin'|'admin'|'subadmin', 'subadmin_id' => ?]. */
function sa_actor(Req $req) {
    if ($req->subadmin) {
        return ['name' => (string) $req->subadmin['username'], 'role' => 'subadmin', 'subadmin_id' => (int) $req->subadmin['id']];
    }
    if ($req->auth && in_array($req->auth['role'] ?? '', ['admin', 'superadmin'], true)) {
        return ['name' => (string) ($req->auth['username'] ?? 'admin'), 'role' => (string) $req->auth['role'], 'subadmin_id' => null];
    }
    return ['name' => 'system', 'role' => 'system', 'subadmin_id' => null];
}

/** An ISO time string for a timestamp column, or null. */
function sa_iso($sqlTs) {
    if ($sqlTs === null || $sqlTs === '') return null;
    $ms = sql_to_ms($sqlTs);
    return $ms === null ? null : js_iso($ms);
}

/**
 * An India-time day range from YYYY-MM-DD strings (both inclusive), as SQL timestamps [lo, hi).
 * Either end may be missing. The operator consoles already speak India days.
 */
function sa_ist_range($from, $to) {
    $istOff = 330 * 60 * 1000;
    $ok = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d . 'T00:00:00Z') !== false; };
    $lo = $ok($from) ? gmdate('Y-m-d H:i:s', (int) ((strtotime($from . 'T00:00:00Z') * 1000 - $istOff) / 1000)) : null;
    $hi = $ok($to) ? gmdate('Y-m-d H:i:s', (int) ((strtotime($to . 'T00:00:00Z') * 1000 - $istOff + 86400000) / 1000)) : null;
    if ($lo !== null && $hi !== null && strcmp($lo, $hi) >= 0) { $t = $lo; $lo = $hi; $hi = $t; }
    return [$lo, $hi];
}

// -------------------------------------------------------------------------------------------------
// Accounts and sessions
// -------------------------------------------------------------------------------------------------

/** A fingerprint of the stored password hash: changing the password ends every older session. */
function sa_password_version($hash) {
    return substr(hash_hmac('sha256', 'subadmin|' . (string) $hash, (string) cfg('APP_SECRET')), 0, 16);
}

function sa_password_problem($p) {
    if (!is_string($p) || $p === '') return 'Password is required.';
    if (strlen($p) < 8) return 'Password must be at least 8 characters.';
    if (strlen($p) > 128) return 'Password must be 128 characters or fewer.';
    return null;
}

/** A temporary password such as "Kp7x-Qm4z-T9wd": readable over a phone call, 70+ bits. */
function sa_generate_password() {
    $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
            $groups[] = $s;
        }
        $p = implode('-', $groups);
    } while (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/[0-9]/', $p));
    return $p;
}

function sa_new_public_id() {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $alphabet[random_int(0, 31)];
    return 'SA-' . $s;
}

function sa_find($id) {
    return one('SELECT * FROM "SubAdmin" WHERE "id" = ?', [(int) $id]);
}

function sa_find_by_username($username) {
    return one('SELECT * FROM "SubAdmin" WHERE LOWER("username") = LOWER(?)', [(string) $username]);
}

/** The account as it may be shown to an operator or the sub-admin themself — never the hash. */
function sa_public(array $r) {
    return [
        'id'                   => (int) $r['id'],
        'public_id'            => $r['public_id'],
        'full_name'            => $r['full_name'],
        'username'             => $r['username'],
        'status'               => $r['status'],
        'coin_balance'         => (float) $r['coin_balance'],
        'must_change_password' => (int) $r['must_change_password'] === 1,
        'created_at'           => sa_iso($r['created_at']),
        'created_by'           => $r['created_by'],
        'created_by_role'      => $r['created_by_role'],
        'status_changed_at'    => sa_iso($r['status_changed_at']),
        'status_changed_by'    => $r['status_changed_by'],
        'status_reason'        => $r['status_reason'],
        'password_changed_at'  => sa_iso($r['password_changed_at']),
        'last_login_at'        => sa_iso($r['last_login_at']),
    ];
}

/**
 * Turn a verified sub-admin token into a live session, or null.
 *
 * Fails CLOSED on any problem: the account is gone, suspended, renamed, its password changed since
 * the token was issued, or the table cannot be read at all.
 */
function subadmin_session_from_payload(array $payload) {
    if (empty($payload['id'])) return null;
    try {
        $r = one('SELECT "id","username","full_name","public_id","status","password_hash","must_change_password" FROM "SubAdmin" WHERE "id" = ?',
                 [(int) $payload['id']]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$r || $r['status'] !== 'active') return null;
    if (strtolower((string) $r['username']) !== strtolower((string) ($payload['username'] ?? ''))) return null;
    if (!isset($payload['pv']) || !hash_equals(sa_password_version($r['password_hash']), (string) $payload['pv'])) return null;
    return [
        'id'                   => (int) $r['id'],
        'username'             => $r['username'],
        'full_name'            => $r['full_name'],
        'public_id'            => $r['public_id'],
        'must_change_password' => (int) $r['must_change_password'] === 1,
    ];
}

function sa_issue_token(array $row) {
    return issue_token([
        'id'       => (int) $row['id'],
        'username' => $row['username'],
        'email'    => null,
        'role'     => 'subadmin',
        'ttlMs'    => SUBADMIN_SESSION_TTL_MS,
        'pv'       => sa_password_version($row['password_hash']),
    ]);
}

/** Route guard: a signed-in, active sub-admin. */
function require_subadmin(Req $req, Res $res) {
    if (!subadmin_ensure()) {
        $res->status(503)->json(['error' => 'Sub-admin service is unavailable right now. Please try again shortly.']);
        return;
    }
    if (!$req->subadmin) {
        $res->status(401)->json(['error' => 'Please sign in as a sub-admin.', 'code' => 'subadmin_auth_required']);
    }
}

/** Route guard for everything except the password change: a temporary password must go first. */
function require_subadmin_ready(Req $req, Res $res) {
    require_subadmin($req, $res);
    if ($res->sent) return;
    if (!empty($req->subadmin['must_change_password'])) {
        $res->status(403)->json(['error' => 'Please change your temporary password before continuing.', 'code' => 'password_change_required']);
    }
}

/** Serialise every money operation for one sub-admin. MUST be called inside tx(). */
function sa_lock($subadminId) {
    q('SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))', [lock_key_int('subadmin'), (int) $subadminId]);
}

function sa_event($subadminId, $event, array $actor, $details = null, $userId = null, $username = null) {
    q('INSERT INTO "SubAdminEvent" ("subadmin_id","event","user_id","username","actor","actor_role","details","created_at") VALUES (?,?,?,?,?,?,?,?)',
      [(int) $subadminId, $event, $userId === null ? null : (int) $userId, $username, $actor['name'], $actor['role'],
       $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
       ms_to_sql()]);
}

/** Create a sub-admin. Returns ['subadmin' => public row, 'temporary_password' => string|null]. */
function sa_create(array $in, array $actor) {
    $name = sa_text($in['full_name'] ?? null, 60, 'Full name', true, 2);
    if (!preg_match("/^[\\p{L}][\\p{L} .'\\-]{1,59}$/u", $name)) sa_fail('Full name may contain letters, spaces, dots, hyphens and apostrophes only.');
    $username = trim((string) ($in['username'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) sa_fail('Username must be 3-20 letters, digits or underscores.');
    $reserved = array_map('strtolower', [(string) cfg('ADMIN_USERNAME'), (string) cfg('SUPERADMIN_USERNAME'), 'admin', 'superadmin', 'system']);
    if (in_array(strtolower($username), $reserved, true)) sa_fail('That username is reserved. Please choose another.');

    $generated = null;
    $password = (string) ($in['password'] ?? '');
    if (sa_bool($in['generate_password'] ?? false) || $password === '') {
        $generated = sa_generate_password();
        $password = $generated;
    } elseif (($p = sa_password_problem($password)) !== null) {
        sa_fail($p);
    }
    $hash = hash_password($password);

    $row = tx(function () use ($name, $username, $hash, $actor) {
        if (sa_find_by_username($username)) sa_fail('A sub-admin with that username already exists.', 409, 'username_taken');
        $now = ms_to_sql();
        for ($attempt = 0; ; $attempt++) {
            $pid = sa_new_public_id();
            if (!one('SELECT 1 FROM "SubAdmin" WHERE "public_id" = ?', [$pid])) break;
            if ($attempt > 20) throw new RuntimeException('could not allocate a sub-admin id');
        }
        try {
            $id = (int) scalar('INSERT INTO "SubAdmin" ("public_id","full_name","username","password_hash","must_change_password","status","coin_balance","created_at","created_by","created_by_role")
                                VALUES (?,?,?,?,1,\'active\',0,?,?,?) RETURNING "id"',
                               [$pid, $name, $username, $hash, $now, $actor['name'], $actor['role']]);
        } catch (Throwable $e) {
            if (is_unique_violation($e)) sa_fail('A sub-admin with that username already exists.', 409, 'username_taken');
            throw $e;
        }
        sa_event($id, 'created', $actor, ['full_name' => $name, 'username' => $username]);
        return sa_find($id);
    });
    log_info('sub-admin created', ['id' => (int) $row['id'], 'username' => $username, 'by' => $actor['name']]);
    return ['subadmin' => sa_public($row), 'temporary_password' => $generated];
}

function sa_set_status($id, $status, $reason, array $actor) {
    if (!in_array($status, ['active', 'suspended'], true)) sa_fail('Status must be active or suspended.');
    $reason = sa_text($reason, 300, 'Reason', $status === 'suspended', $status === 'suspended' ? 3 : 0);
    return tx(function () use ($id, $status, $reason, $actor) {
        sa_lock($id);
        $r = one('SELECT * FROM "SubAdmin" WHERE "id" = ? FOR UPDATE', [(int) $id]);
        if (!$r) sa_fail('Sub-admin not found.', 404);
        if ($r['status'] === $status) sa_fail('This sub-admin is already ' . $status . '.', 409);
        q('UPDATE "SubAdmin" SET "status" = ?, "status_changed_at" = ?, "status_changed_by" = ?, "status_reason" = ? WHERE "id" = ?',
          [$status, ms_to_sql(), $actor['name'], $reason, (int) $id]);
        sa_event($id, $status === 'suspended' ? 'suspended' : 'reactivated', $actor, $reason);
        return sa_public(sa_find($id));
    });
}

/** An operator-initiated reset: a new temporary password, shown once, never stored in clear. */
function sa_reset_password($id, array $actor) {
    $temp = sa_generate_password();
    $hash = hash_password($temp);
    $row = tx(function () use ($id, $hash, $actor) {
        sa_lock($id);
        $r = one('SELECT * FROM "SubAdmin" WHERE "id" = ? FOR UPDATE', [(int) $id]);
        if (!$r) sa_fail('Sub-admin not found.', 404);
        q('UPDATE "SubAdmin" SET "password_hash" = ?, "must_change_password" = 1, "password_changed_at" = ? WHERE "id" = ?',
          [$hash, ms_to_sql(), (int) $id]);
        sa_event($id, 'password_reset', $actor, 'Temporary password issued; every open session was signed out.');
        return sa_find($id);
    });
    return ['subadmin' => sa_public($row), 'temporary_password' => $temp];
}

/** The sub-admin changes their own password. Returns a fresh token (older sessions end). */
function sa_change_password($id, $current, $new) {
    if (!is_string($current) || $current === '') sa_fail('Enter your current password.');
    if (($p = sa_password_problem($new)) !== null) sa_fail('New password: ' . $p);
    if (hash_equals((string) $current, (string) $new)) sa_fail('The new password must be different from the current one.');
    $r = sa_find($id);
    if (!$r) sa_fail('Account not found.', 404);
    if (!check_password((string) $current, $r['password_hash'])) sa_fail('Your current password is not correct.', 400, 'wrong_password');
    $hash = hash_password((string) $new);
    $row = tx(function () use ($id, $hash, $r) {
        sa_lock($id);
        q('UPDATE "SubAdmin" SET "password_hash" = ?, "must_change_password" = 0, "password_changed_at" = ? WHERE "id" = ?',
          [$hash, ms_to_sql(), (int) $id]);
        sa_event($id, 'password_changed', ['name' => $r['username'], 'role' => 'subadmin'], null);
        return sa_find($id);
    });
    return ['token' => sa_issue_token($row), 'subadmin' => sa_public($row)];
}

/** Sign-in. Returns ['token', 'subadmin'] or throws. */
function sa_login($username, $password) {
    $username = trim((string) $username);
    if ($username === '' || !is_string($password) || $password === '') sa_fail('Username and password are required.');
    $r = sa_find_by_username($username);
    if ($r) {
        $ok = check_password($password, $r['password_hash']);
    } else {
        hash_password($password);   // the same bcrypt cost, so timing does not reveal which names exist
        $ok = false;
    }
    if (!$r || !$ok) {
        log_warn('failed sub-admin login', ['username' => $username]);
        sa_fail('Incorrect username or password.', 401, 'bad_credentials');
    }
    if ($r['status'] !== 'active') {
        sa_fail('This sub-admin account is suspended. Please contact the administrator.', 403, 'suspended');
    }
    q('UPDATE "SubAdmin" SET "last_login_at" = ? WHERE "id" = ?', [ms_to_sql(), (int) $r['id']]);
    log_info('sub-admin signed in', ['id' => (int) $r['id'], 'username' => $r['username']]);
    return ['token' => sa_issue_token($r), 'subadmin' => sa_public(sa_find($r['id']))];
}

// -------------------------------------------------------------------------------------------------
// Inventory and ledger primitives — call only inside tx() after sa_lock()
// -------------------------------------------------------------------------------------------------

function sa_inventory_add($subadminId, $coins) {
    return (float) scalar('UPDATE "SubAdmin" SET "coin_balance" = ROUND(CAST("coin_balance" + ? AS numeric), 2) WHERE "id" = ? RETURNING "coin_balance"',
                          [round2($coins), (int) $subadminId]);
}

/** Atomic conditional take. Returns the new balance, or null when the inventory is short. */
function sa_inventory_take($subadminId, $coins) {
    $v = scalar('UPDATE "SubAdmin" SET "coin_balance" = ROUND(CAST("coin_balance" - ? AS numeric), 2) WHERE "id" = ? AND "coin_balance" >= ? RETURNING "coin_balance"',
                [round2($coins), (int) $subadminId, round2($coins)], null);
    return $v === null ? null : (float) $v;
}

function sa_inventory($subadminId) {
    return (float) scalar('SELECT "coin_balance" FROM "SubAdmin" WHERE "id" = ?', [(int) $subadminId], 0);
}

/** Append one coin-ledger row. Returns its id. */
function sa_ledger($type, $from, $to, $coins, $subadminId, array $actor, array $o = []) {
    $id = sa_ref('SAL');
    q('INSERT INTO "SubAdminLedger" ("id","type","from_account","to_account","coins","rupees","subadmin_id","user_id","username","ref","cash_id","reverses","inventory_after","actor","actor_role","notes","created_at")
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $type, $from, $to, round2($coins),
        array_key_exists('rupees', $o) && $o['rupees'] !== null ? round2($o['rupees']) : null,
        (int) $subadminId,
        isset($o['user_id']) ? (int) $o['user_id'] : null, $o['username'] ?? null,
        $o['ref'] ?? null, $o['cash_id'] ?? null, $o['reverses'] ?? null,
        array_key_exists('inventory_after', $o) ? round2($o['inventory_after']) : null,
        $actor['name'], $actor['role'], $o['notes'] ?? null, ms_to_sql(),
    ]);
    return $id;
}

/** Insert one cash-ledger row. Returns its id. */
function sa_cash_insert(array $f, array $actor) {
    $id = sa_ref('CSH');
    $now = ms_to_sql();
    $confirmed = ($f['status'] ?? '') === 'Confirmed';
    try {
        q('INSERT INTO "SubAdminCash" ("id","kind","direction","subadmin_id","user_id","username","amount","method","reference","status","allocation_id","deposit_id","withdrawal_id","reverses","notes","created_by","created_by_role","created_at","confirmed_by","confirmed_by_role","confirmed_at")
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $id, $f['kind'], $f['direction'], (int) $f['subadmin_id'],
            isset($f['user_id']) ? (int) $f['user_id'] : null, $f['username'] ?? null,
            round2($f['amount']), $f['method'] ?? null, $f['reference'] ?? null, $f['status'],
            $f['allocation_id'] ?? null, $f['deposit_id'] ?? null, $f['withdrawal_id'] ?? null, $f['reverses'] ?? null,
            $f['notes'] ?? null, $actor['name'], $actor['role'], $now,
            $confirmed ? $actor['name'] : null, $confirmed ? $actor['role'] : null, $confirmed ? $now : null,
        ]);
    } catch (Throwable $e) {
        if (is_unique_violation($e)) sa_fail('That payment reference has already been recorded. Each payment can be recorded only once.', 409, 'duplicate_reference');
        throw $e;
    }
    return $id;
}

/** Refuse a payment reference that already backs a live cash record of the same kind. */
function sa_reference_unused($kind, $reference) {
    if ($reference === null || $reference === '') return;
    $hit = one('SELECT "id","subadmin_id" FROM "SubAdminCash" WHERE "kind" = ? AND UPPER("reference") = UPPER(?) AND "status" IN (\'Unconfirmed\',\'Confirmed\') LIMIT 1',
               [$kind, $reference]);
    if ($hit) sa_fail('That payment reference has already been recorded (' . $hit['id'] . '). Each payment can be recorded only once.', 409, 'duplicate_reference');
}

/** Reverse a cash row: mark the original Reversed and append a reversal row that points at it. */
function sa_cash_reverse(array $cash, $reason, array $actor) {
    $n = affected('UPDATE "SubAdminCash" SET "status" = \'Reversed\', "reversed_by" = ?, "reversed_at" = ?, "reverse_reason" = ? WHERE "id" = ? AND "status" = \'Confirmed\'',
                  [$actor['name'], ms_to_sql(), $reason, $cash['id']]);
    if ($n === 0) sa_fail('That payment is no longer confirmed, so it cannot be reversed.', 409);
    $opposite = ['subadmin_to_admin' => 'admin_to_subadmin', 'admin_to_subadmin' => 'subadmin_to_admin',
                 'user_to_subadmin' => 'subadmin_to_user', 'subadmin_to_user' => 'user_to_subadmin'];
    return sa_cash_insert([
        'kind' => 'reversal', 'direction' => $opposite[$cash['direction']] ?? $cash['direction'],
        'subadmin_id' => $cash['subadmin_id'], 'user_id' => $cash['user_id'], 'username' => $cash['username'],
        'amount' => (float) $cash['amount'], 'method' => $cash['method'], 'reference' => null, 'status' => 'Confirmed',
        'allocation_id' => $cash['allocation_id'], 'deposit_id' => $cash['deposit_id'], 'withdrawal_id' => $cash['withdrawal_id'],
        'reverses' => $cash['id'], 'notes' => $reason,
    ], $actor);
}

// -------------------------------------------------------------------------------------------------
// Allocations: Admin -> sub-admin, from the unlimited supply
// -------------------------------------------------------------------------------------------------

function sa_allocation_paid($allocationId) {
    $out = ['confirmed' => 0.0, 'unconfirmed' => 0.0];
    foreach (all('SELECT "status", COALESCE(SUM("amount"),0) AS s FROM "SubAdminCash" WHERE "allocation_id" = ? AND "kind" = \'allocation_payment\' GROUP BY "status"', [$allocationId]) as $r) {
        if ($r['status'] === 'Confirmed') $out['confirmed'] = round2((float) $r['s']);
        if ($r['status'] === 'Unconfirmed') $out['unconfirmed'] = round2((float) $r['s']);
    }
    return $out;
}

/** Credit an allocation's coins if it is still waiting. Inside tx, sub-admin locked. */
function sa_allocation_credit_locked(array $alloc, array $actor) {
    $n = affected('UPDATE "SubAdminAllocation" SET "status" = \'credited\', "credited_by" = ?, "credited_at" = ? WHERE "id" = ? AND "status" = \'awaiting_payment\'',
                  [$actor['name'], ms_to_sql(), $alloc['id']]);
    if ($n === 0) return false;
    $after = sa_inventory_add($alloc['subadmin_id'], (float) $alloc['coins']);
    sa_ledger('allocation_credit', 'admin_supply', 'subadmin:' . (int) $alloc['subadmin_id'], (float) $alloc['coins'], $alloc['subadmin_id'], $actor, [
        'rupees' => (float) $alloc['agreed_amount'], 'ref' => $alloc['id'], 'inventory_after' => $after,
        'notes' => 'Allocated from the Admin\'s unlimited supply',
    ]);
    return true;
}

/** After a payment is confirmed: in after_payment mode, credit once the agreed amount is fully paid. */
function sa_allocation_maybe_credit($allocationId, array $actor) {
    $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ? FOR UPDATE', [$allocationId]);
    if (!$a || $a['status'] !== 'awaiting_payment') return false;
    $paid = sa_allocation_paid($allocationId);
    if ($a['credit_mode'] === 'immediate' || $paid['confirmed'] + 0.001 >= (float) $a['agreed_amount']) {
        return sa_allocation_credit_locked($a, $actor);
    }
    return false;
}

function sa_allocation_view(array $a) {
    $pays = all('SELECT * FROM "SubAdminCash" WHERE "allocation_id" = ? AND "kind" IN (\'allocation_payment\',\'reversal\') ORDER BY "created_at"', [$a['id']]);
    $paid = ['confirmed' => 0.0, 'unconfirmed' => 0.0];
    foreach ($pays as $p) {
        if ($p['kind'] !== 'allocation_payment') continue;
        if ($p['status'] === 'Confirmed') $paid['confirmed'] += (float) $p['amount'];
        if ($p['status'] === 'Unconfirmed') $paid['unconfirmed'] += (float) $p['amount'];
    }
    $agreed = (float) $a['agreed_amount'];
    $outstanding = in_array($a['status'], ['awaiting_payment', 'credited'], true) ? max(0, round2($agreed - $paid['confirmed'])) : 0.0;
    if ($a['status'] === 'cancelled' || $a['status'] === 'reversed') $payStatus = ucfirst($a['status']);
    elseif ($agreed <= 0) $payStatus = 'No payment due';
    elseif ($paid['confirmed'] + 0.001 >= $agreed) $payStatus = 'Paid';
    elseif ($paid['unconfirmed'] > 0) $payStatus = 'Awaiting confirmation';
    elseif ($paid['confirmed'] > 0) $payStatus = 'Part paid';
    else $payStatus = 'Unpaid';
    return [
        'id' => $a['id'], 'subadmin_id' => (int) $a['subadmin_id'], 'coins' => (float) $a['coins'], 'agreed_amount' => $agreed,
        'credit_mode' => $a['credit_mode'], 'status' => $a['status'], 'notes' => $a['notes'],
        'created_by' => $a['created_by'], 'created_by_role' => $a['created_by_role'], 'created_at' => sa_iso($a['created_at']),
        'credited_by' => $a['credited_by'], 'credited_at' => sa_iso($a['credited_at']),
        'cancelled_by' => $a['cancelled_by'], 'cancelled_at' => sa_iso($a['cancelled_at']), 'cancel_reason' => $a['cancel_reason'],
        'reversed_by' => $a['reversed_by'], 'reversed_at' => sa_iso($a['reversed_at']), 'reverse_reason' => $a['reverse_reason'],
        'paid_confirmed' => round2($paid['confirmed']), 'paid_unconfirmed' => round2($paid['unconfirmed']),
        'outstanding' => $outstanding, 'payment_status' => $payStatus,
        'remaining_to_record' => max(0, round2($agreed - $paid['confirmed'] - $paid['unconfirmed'])),
        'payments' => array_map('sa_cash_view', $pays),
    ];
}

/**
 * The Admin allocates coins to a sub-admin. Nothing is ever deducted from the Admin.
 * $in: coins, agreed_amount, payment_method?, payment_reference?, payment_received?, notes?, idempotency_key
 */
function sa_allocation_create($subadminId, array $in, array $actor) {
    $coins = sa_amount($in['coins'] ?? null, 'Coins', 1, 100000000);
    $agreed = sa_amount($in['agreed_amount'] ?? 0, 'Agreed amount', 0, 100000000);
    $notes = sa_text($in['notes'] ?? null, 300, 'Notes');
    $key = sa_idempotency_key($in['idempotency_key'] ?? null);
    if ($key === null) sa_fail('Missing request key — please reload the page and try again.');
    $method = sa_method($in['payment_method'] ?? null, false);
    $reference = sa_reference($in['payment_reference'] ?? null);
    $received = sa_bool($in['payment_received'] ?? false);
    if ($received && $agreed > 0 && $method === null) sa_fail('Choose how the payment was received.');
    $mode = sa_settings()['allocation_credit'];

    return tx(function () use ($subadminId, $coins, $agreed, $notes, $key, $method, $reference, $received, $mode, $actor) {
        sa_lock($subadminId);
        $existing = one('SELECT * FROM "SubAdminAllocation" WHERE "idempotency_key" = ?', [$key]);
        if ($existing) {
            if ((int) $existing['subadmin_id'] !== (int) $subadminId) sa_fail('Invalid request key.', 409);
            return ['allocation' => sa_allocation_view($existing), 'replayed' => true];
        }
        $sa = one('SELECT * FROM "SubAdmin" WHERE "id" = ? FOR UPDATE', [(int) $subadminId]);
        if (!$sa) sa_fail('Sub-admin not found.', 404);
        if ($sa['status'] !== 'active') sa_fail('This sub-admin is suspended. Reactivate the account before allocating coins.', 409);

        $id = sa_ref('ALC');
        q('INSERT INTO "SubAdminAllocation" ("id","subadmin_id","coins","agreed_amount","credit_mode","status","notes","idempotency_key","created_by","created_by_role","created_at")
           VALUES (?,?,?,?,?,\'awaiting_payment\',?,?,?,?,?)',
          [$id, (int) $subadminId, $coins, $agreed, $mode, $notes, $key, $actor['name'], $actor['role'], ms_to_sql()]);

        if ($agreed > 0 && ($method !== null || $received)) {
            sa_reference_unused('allocation_payment', $reference);
            sa_cash_insert([
                'kind' => 'allocation_payment', 'direction' => 'subadmin_to_admin', 'subadmin_id' => $subadminId,
                'amount' => $agreed, 'method' => $method, 'reference' => $reference,
                'status' => $received ? 'Confirmed' : 'Unconfirmed', 'allocation_id' => $id,
                'notes' => $received ? 'Recorded and confirmed with the allocation' : 'Recorded with the allocation',
            ], $actor);
        }
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ? FOR UPDATE', [$id]);
        if ($mode === 'immediate' || $agreed <= 0) sa_allocation_credit_locked($a, $actor);
        else sa_allocation_maybe_credit($id, $actor);
        return ['allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$id])), 'replayed' => false];
    });
}

/**
 * Record a payment towards an allocation. Admins may confirm it in the same step; a sub-admin may
 * only record it (the Admin confirms that the money actually arrived).
 */
function sa_allocation_payment_record($allocationId, array $in, array $actor, $onlySubadminId = null) {
    $amount = sa_amount($in['amount'] ?? null, 'Amount', 0.01, 100000000);
    $method = sa_method($in['method'] ?? null, true);
    $reference = sa_reference($in['reference'] ?? null);
    $notes = sa_text($in['notes'] ?? null, 300, 'Notes');
    $confirm = $onlySubadminId === null && sa_bool($in['confirm'] ?? false);

    return tx(function () use ($allocationId, $amount, $method, $reference, $notes, $confirm, $actor, $onlySubadminId) {
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]);
        if (!$a || ($onlySubadminId !== null && (int) $a['subadmin_id'] !== (int) $onlySubadminId)) sa_fail('Allocation not found.', 404);
        sa_lock($a['subadmin_id']);
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ? FOR UPDATE', [$allocationId]);
        if (!in_array($a['status'], ['awaiting_payment', 'credited'], true)) sa_fail('This allocation is ' . $a['status'] . '; no payment can be recorded against it.', 409);
        $paid = sa_allocation_paid($allocationId);
        $remaining = round2((float) $a['agreed_amount'] - $paid['confirmed'] - $paid['unconfirmed']);
        if ($remaining <= 0) sa_fail('The agreed amount is already fully recorded' . ($paid['unconfirmed'] > 0 ? ' (part of it is awaiting confirmation)' : '') . '.', 409, 'fully_recorded');
        if ($amount > $remaining + 0.001) sa_fail('That is more than the ₹' . number_format($remaining, 2) . ' still to be recorded for this allocation.', 409);
        sa_reference_unused('allocation_payment', $reference);
        $cashId = sa_cash_insert([
            'kind' => 'allocation_payment', 'direction' => 'subadmin_to_admin', 'subadmin_id' => $a['subadmin_id'],
            'amount' => $amount, 'method' => $method, 'reference' => $reference,
            'status' => $confirm ? 'Confirmed' : 'Unconfirmed', 'allocation_id' => $allocationId, 'notes' => $notes,
        ], $actor);
        if ($confirm) sa_allocation_maybe_credit($allocationId, $actor);
        return ['payment' => sa_cash_view(one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$cashId])),
                'allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]))];
    });
}

/** Load an allocation payment for an admin action, locked. */
function sa_payment_for_update($cashId) {
    $c = one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$cashId]);
    if (!$c || $c['kind'] !== 'allocation_payment') sa_fail('Payment record not found.', 404);
    sa_lock($c['subadmin_id']);
    return one('SELECT * FROM "SubAdminCash" WHERE "id" = ? FOR UPDATE', [$cashId]);
}

/** The Admin confirms an allocation payment really arrived. */
function sa_payment_confirm($cashId, array $actor) {
    return tx(function () use ($cashId, $actor) {
        $c = sa_payment_for_update($cashId);
        $n = affected('UPDATE "SubAdminCash" SET "status" = \'Confirmed\', "confirmed_by" = ?, "confirmed_by_role" = ?, "confirmed_at" = ? WHERE "id" = ? AND "status" = \'Unconfirmed\'',
                      [$actor['name'], $actor['role'], ms_to_sql(), $cashId]);
        if ($n === 0) sa_fail('This payment is already ' . strtolower($c['status']) . '.', 409, 'already_processed');
        sa_allocation_maybe_credit($c['allocation_id'], $actor);
        return ['payment' => sa_cash_view(one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$cashId])),
                'allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$c['allocation_id']]))];
    });
}

/** The Admin records that a claimed payment never arrived. */
function sa_payment_fail($cashId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($cashId, $reason, $actor) {
        $c = sa_payment_for_update($cashId);
        $n = affected('UPDATE "SubAdminCash" SET "status" = \'Failed\', "failed_by" = ?, "failed_at" = ?, "fail_reason" = ? WHERE "id" = ? AND "status" = \'Unconfirmed\'',
                      [$actor['name'], ms_to_sql(), $reason, $cashId]);
        if ($n === 0) sa_fail('Only a payment awaiting confirmation can be marked failed (this one is ' . strtolower($c['status']) . ').', 409);
        return ['payment' => sa_cash_view(one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$cashId]))];
    });
}

/** The Admin reverses a confirmed payment (refunded, or confirmed by mistake). Coins are not touched. */
function sa_payment_reverse($cashId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($cashId, $reason, $actor) {
        $c = sa_payment_for_update($cashId);
        if ($c['status'] !== 'Confirmed') sa_fail('Only a confirmed payment can be reversed (this one is ' . strtolower($c['status']) . ').', 409);
        $revId = sa_cash_reverse($c, $reason, $actor);
        return ['payment' => sa_cash_view(one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$cashId])), 'reversal_id' => $revId,
                'allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$c['allocation_id']]))];
    });
}

/** Cancel an allocation whose coins were never credited. */
function sa_allocation_cancel($allocationId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($allocationId, $reason, $actor) {
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]);
        if (!$a) sa_fail('Allocation not found.', 404);
        sa_lock($a['subadmin_id']);
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ? FOR UPDATE', [$allocationId]);
        if ($a['status'] !== 'awaiting_payment') sa_fail('Only an allocation that has not been credited can be cancelled (this one is ' . $a['status'] . '). Use Reverse for a credited one.', 409);
        if (sa_allocation_paid($allocationId)['confirmed'] > 0) sa_fail('Money has already been confirmed against this allocation. Reverse those payments first.', 409);
        q('UPDATE "SubAdminCash" SET "status" = \'Failed\', "failed_by" = ?, "failed_at" = ?, "fail_reason" = ? WHERE "allocation_id" = ? AND "status" = \'Unconfirmed\'',
          [$actor['name'], ms_to_sql(), 'Allocation cancelled: ' . $reason, $allocationId]);
        q('UPDATE "SubAdminAllocation" SET "status" = \'cancelled\', "cancelled_by" = ?, "cancelled_at" = ?, "cancel_reason" = ? WHERE "id" = ? AND "status" = \'awaiting_payment\'',
          [$actor['name'], ms_to_sql(), $reason, $allocationId]);
        return ['allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]))];
    });
}

/** Take a credited allocation's coins back (needs that many coins still in the inventory). */
function sa_allocation_reverse($allocationId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($allocationId, $reason, $actor) {
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]);
        if (!$a) sa_fail('Allocation not found.', 404);
        sa_lock($a['subadmin_id']);
        $a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ? FOR UPDATE', [$allocationId]);
        if ($a['status'] !== 'credited') sa_fail('Only a credited allocation can be reversed (this one is ' . $a['status'] . ').', 409);
        $after = sa_inventory_take($a['subadmin_id'], (float) $a['coins']);
        if ($after === null) {
            sa_fail('The sub-admin has only ' . number_format(sa_inventory($a['subadmin_id']), 2) . ' coins left; reversing this allocation needs '
                    . number_format((float) $a['coins'], 2) . '. Adjust a smaller amount instead.', 409, 'insufficient_inventory');
        }
        $orig = one('SELECT "id" FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'allocation_credit\' LIMIT 1', [$allocationId]);
        sa_ledger('allocation_reversal', 'subadmin:' . (int) $a['subadmin_id'], 'admin_supply', (float) $a['coins'], $a['subadmin_id'], $actor, [
            'rupees' => (float) $a['agreed_amount'], 'ref' => $allocationId, 'reverses' => $orig ? $orig['id'] : null,
            'inventory_after' => $after, 'notes' => $reason,
        ]);
        q('UPDATE "SubAdminAllocation" SET "status" = \'reversed\', "reversed_by" = ?, "reversed_at" = ?, "reverse_reason" = ? WHERE "id" = ?',
          [$actor['name'], ms_to_sql(), $reason, $allocationId]);
        return ['allocation' => sa_allocation_view(one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$allocationId]))];
    });
}

/** An authorised correction of a sub-admin's inventory (+ from the unlimited supply, − back to it). */
function sa_adjust($subadminId, $coinsSigned, $reason, array $actor) {
    $f = js_parse_float($coinsSigned);
    if (!js_is_finite($f) || $f == 0) sa_fail('Enter a non-zero number of coins (negative to remove).');
    $coins = sa_amount(abs($f), 'Coins', 0.01, 100000000);
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($subadminId, $f, $coins, $reason, $actor) {
        sa_lock($subadminId);
        if (!sa_find($subadminId)) sa_fail('Sub-admin not found.', 404);
        if ($f > 0) {
            $after = sa_inventory_add($subadminId, $coins);
            $lid = sa_ledger('adjustment_credit', 'admin_supply', 'subadmin:' . (int) $subadminId, $coins, $subadminId, $actor, ['inventory_after' => $after, 'notes' => $reason]);
        } else {
            $after = sa_inventory_take($subadminId, $coins);
            if ($after === null) sa_fail('The sub-admin has only ' . number_format(sa_inventory($subadminId), 2) . ' coins; cannot remove ' . number_format($coins, 2) . '.', 409, 'insufficient_inventory');
            $lid = sa_ledger('adjustment_debit', 'subadmin:' . (int) $subadminId, 'admin_supply', $coins, $subadminId, $actor, ['inventory_after' => $after, 'notes' => $reason]);
        }
        return ['ledger_id' => $lid, 'coin_balance' => $after];
    });
}

// -------------------------------------------------------------------------------------------------
// Users: assignment
// -------------------------------------------------------------------------------------------------

/** The player row if they are assigned to this sub-admin; otherwise "not found" (no existence leak). */
function sa_user_of($subadminId, $userId) {
    $u = one('SELECT u.*, s."assigned_at", s."how" FROM "SubAdminUser" s JOIN "User" u ON u."id" = s."user_id" WHERE s."user_id" = ? AND s."subadmin_id" = ?',
             [(int) $userId, (int) $subadminId]);
    if (!$u) sa_fail('User not found among your users.', 404);
    return $u;
}

/** A sub-admin opens a new player account, which belongs to them from the start. No signup bonus. */
function sa_user_create($subadminId, array $in, array $actor) {
    $username = trim((string) ($in['username'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) sa_fail('Username must be 3-20 letters, digits or underscores.');
    if (strtolower($username) === 'admin') sa_fail('That username is reserved.');
    $password = (string) ($in['password'] ?? '');
    if (($p = sa_password_problem($password)) !== null) sa_fail($p);
    $email = trim((string) ($in['email'] ?? ''));
    if ($email === '') $email = strtolower($username) . '@bet1x.com';
    elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) || strlen($email) > 120) sa_fail('Invalid email address.');
    $hash = hash_password($password);
    ensure_user_identity_indexes();

    return tx(function () use ($subadminId, $username, $email, $hash, $actor) {
        sa_lock($subadminId);
        $sa = sa_find($subadminId);
        if (!$sa || $sa['status'] !== 'active') sa_fail('Your account is not active.', 403);
        $existing = one('SELECT "username","email" FROM "User" WHERE LOWER("username") = LOWER(?) OR LOWER("email") = LOWER(?) LIMIT 1', [$username, $email]);
        if ($existing) sa_fail(strtolower($existing['username']) === strtolower($username) ? 'That username is already taken.' : 'That email is already registered.', 409);
        try {
            $uid = (int) scalar('INSERT INTO "User" ("username","email","password","wallet_balance","created_at") VALUES (?,?,?,0,?) RETURNING "id"',
                                [$username, $email, $hash, ms_to_sql()]);
        } catch (Throwable $e) {
            if (is_unique_violation($e)) sa_fail('That username is already taken.', 409);
            throw $e;
        }
        q('INSERT INTO "SubAdminUser" ("user_id","subadmin_id","assigned_at","assigned_by","assigned_by_role","how") VALUES (?,?,?,?,?,\'created_by_subadmin\')',
          [$uid, (int) $subadminId, ms_to_sql(), $actor['name'], $actor['role']]);
        sa_event($subadminId, 'user_created', $actor, null, $uid, $username);
        return ['user' => ['id' => $uid, 'username' => $username, 'email' => $email, 'wallet_balance' => 0.0]];
    });
}

/**
 * The Admin assigns an existing player to a sub-admin (or moves them from another). A move takes the
 * player's not-yet-paid withdrawal requests along, back to Pending for the new sub-admin.
 */
function sa_user_assign($subadminId, $username, array $actor, $allowReassign = false) {
    $username = trim((string) $username);
    if ($username === '') sa_fail('Enter the player\'s username.');
    return tx(function () use ($subadminId, $username, $actor, $allowReassign) {
        $user = find_user_ci($username);
        if (!$user) sa_fail('No player with that username.', 404);
        if (strtolower($user['username']) === 'admin') sa_fail('The house account cannot be assigned to a sub-admin.');
        $cur = one('SELECT * FROM "SubAdminUser" WHERE "user_id" = ?', [(int) $user['id']]);
        $ids = [(int) $subadminId];
        if ($cur) $ids[] = (int) $cur['subadmin_id'];
        sort($ids);
        foreach (array_unique($ids) as $id) sa_lock($id);
        $cur = one('SELECT * FROM "SubAdminUser" WHERE "user_id" = ? FOR UPDATE', [(int) $user['id']]);
        $target = sa_find($subadminId);
        if (!$target) sa_fail('Sub-admin not found.', 404);
        if ($target['status'] !== 'active') sa_fail('This sub-admin is suspended. Reactivate the account before assigning users.', 409);
        if ($cur && (int) $cur['subadmin_id'] === (int) $subadminId) {
            return ['changed' => false, 'user' => ['id' => (int) $user['id'], 'username' => $user['username']]];
        }
        $now = ms_to_sql();
        if ($cur) {
            $from = sa_find($cur['subadmin_id']);
            if (!$allowReassign) {
                sa_fail('This player already belongs to sub-admin ' . ($from ? $from['username'] : '#' . $cur['subadmin_id']) . '. Confirm to move them.', 409, 'already_assigned',
                        ['current_subadmin' => $from ? $from['username'] : null]);
            }
            q('UPDATE "SubAdminUser" SET "subadmin_id" = ?, "assigned_at" = ?, "assigned_by" = ?, "assigned_by_role" = ?, "how" = \'moved_by_admin\' WHERE "user_id" = ?',
              [(int) $subadminId, $now, $actor['name'], $actor['role'], (int) $user['id']]);
            $moved = affected('UPDATE "SubAdminWithdrawal" SET "subadmin_id" = ?, "status" = \'Pending\', "approved_by" = NULL, "approved_at" = NULL
                               WHERE "user_id" = ? AND "subadmin_id" = ? AND "status" IN (\'Pending\',\'Approved\')',
                              [(int) $subadminId, (int) $user['id'], (int) $cur['subadmin_id']]);
            sa_event($cur['subadmin_id'], 'user_moved_out', $actor, ['to' => $target['username'], 'pending_withdrawals_moved' => $moved], $user['id'], $user['username']);
            sa_event($subadminId, 'user_moved_in', $actor, ['from' => $from ? $from['username'] : null, 'pending_withdrawals_moved' => $moved], $user['id'], $user['username']);
        } else {
            q('INSERT INTO "SubAdminUser" ("user_id","subadmin_id","assigned_at","assigned_by","assigned_by_role","how") VALUES (?,?,?,?,?,\'assigned_by_admin\')',
              [(int) $user['id'], (int) $subadminId, $now, $actor['name'], $actor['role']]);
            sa_event($subadminId, 'user_assigned', $actor, null, $user['id'], $user['username']);
        }
        return ['changed' => true, 'user' => ['id' => (int) $user['id'], 'username' => $user['username']]];
    });
}

/** Remove a player from a sub-admin. Their not-yet-paid withdrawals return to the Admin's queue. */
function sa_user_unassign($subadminId, $userId, array $actor) {
    return tx(function () use ($subadminId, $userId, $actor) {
        sa_lock($subadminId);
        $cur = one('SELECT s.*, u."username" FROM "SubAdminUser" s LEFT JOIN "User" u ON u."id" = s."user_id" WHERE s."user_id" = ? AND s."subadmin_id" = ? FOR UPDATE OF s',
                   [(int) $userId, (int) $subadminId]);
        if (!$cur) sa_fail('That player is not assigned to this sub-admin.', 404);
        q('DELETE FROM "SubAdminUser" WHERE "user_id" = ? AND "subadmin_id" = ?', [(int) $userId, (int) $subadminId]);
        $returned = affected('UPDATE "SubAdminWithdrawal" SET "status" = \'Returned\', "returned_by" = ?, "returned_at" = ?
                              WHERE "user_id" = ? AND "subadmin_id" = ? AND "status" IN (\'Pending\',\'Approved\')',
                             [$actor['name'], ms_to_sql(), (int) $userId, (int) $subadminId]);
        sa_event($subadminId, 'user_unassigned', $actor, ['withdrawals_returned_to_admin' => $returned], $userId, $cur['username']);
        return ['unassigned' => true, 'withdrawals_returned_to_admin' => $returned];
    });
}

// -------------------------------------------------------------------------------------------------
// User deposits: a player buys coins from their sub-admin
// -------------------------------------------------------------------------------------------------

function sa_deposit_for_update($depositId, $onlySubadminId = null) {
    $d = one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$depositId]);
    if (!$d || ($onlySubadminId !== null && (int) $d['subadmin_id'] !== (int) $onlySubadminId)) sa_fail('Deposit not found.', 404);
    sa_lock($d['subadmin_id']);
    return one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ? FOR UPDATE', [$depositId]);
}

/** Attach a payment (Requested -> Unconfirmed). Inside tx, locked. */
function sa_deposit_attach_payment(array $d, $amountInr, $method, $reference, array $actor) {
    sa_reference_unused('user_deposit', $reference);
    $cashId = sa_cash_insert([
        'kind' => 'user_deposit', 'direction' => 'user_to_subadmin', 'subadmin_id' => $d['subadmin_id'],
        'user_id' => $d['user_id'], 'username' => $d['username'], 'amount' => $amountInr,
        'method' => $method, 'reference' => $reference, 'status' => 'Unconfirmed', 'deposit_id' => $d['id'],
    ], $actor);
    $n = affected('UPDATE "SubAdminDeposit" SET "status" = \'Unconfirmed\', "amount_inr" = ?, "method" = ?, "reference" = ?, "cash_id" = ?, "payment_recorded_at" = ?
                   WHERE "id" = ? AND "status" = \'Requested\'',
                  [$amountInr, $method, $reference, $cashId, ms_to_sql(), $d['id']]);
    if ($n === 0) sa_fail('A payment is already recorded for this deposit.', 409, 'already_processed');
    return $cashId;
}

/** Confirm the payment and credit the coins (inventory -> user wallet). Inside tx, locked. */
function sa_deposit_confirm_locked(array $d, array $actor) {
    if ($d['status'] !== 'Unconfirmed') sa_fail('This deposit is ' . strtolower($d['status']) . '; only one with a recorded payment can be confirmed.', 409, 'already_processed');
    $user = find_user_by_id($d['user_id']);
    if (!$user) sa_fail('The player account no longer exists.', 409);
    $coins = (float) $d['coins'];
    $after = sa_inventory_take($d['subadmin_id'], $coins);
    if ($after === null) {
        sa_fail('Not enough coins in your inventory: you have ' . number_format(sa_inventory($d['subadmin_id']), 2) . ', this deposit needs '
                . number_format($coins, 2) . '. Ask the Admin for an allocation.', 409, 'insufficient_inventory');
    }
    $now = ms_to_sql();
    $n = affected('UPDATE "SubAdminDeposit" SET "status" = \'Confirmed\', "confirmed_by" = ?, "confirmed_at" = ? WHERE "id" = ? AND "status" = \'Unconfirmed\'',
                  [$actor['name'], $now, $d['id']]);
    if ($n === 0) sa_fail('This deposit was processed by another request.', 409, 'already_processed');
    q('UPDATE "SubAdminCash" SET "status" = \'Confirmed\', "confirmed_by" = ?, "confirmed_by_role" = ?, "confirmed_at" = ? WHERE "id" = ? AND "status" = \'Unconfirmed\'',
      [$actor['name'], $actor['role'], $now, $d['cash_id']]);
    credit_wallet((int) $user['id'], $coins);
    $sa = sa_find($d['subadmin_id']);
    insert_transaction(new_record_id('DEP'), $user['username'], 'Deposit', $coins,
                       'Sub-Admin Deposit ' . $d['id'] . ' (via ' . ($sa ? $sa['username'] : 'sub-admin') . ')', 'Completed');
    sa_ledger('user_credit', 'subadmin:' . (int) $d['subadmin_id'], 'user:' . (int) $user['id'], $coins, $d['subadmin_id'], $actor, [
        'rupees' => (float) $d['amount_inr'], 'user_id' => $user['id'], 'username' => $user['username'],
        'ref' => $d['id'], 'cash_id' => $d['cash_id'], 'inventory_after' => $after,
    ]);
    return $after;
}

/**
 * Record a user's coin purchase.
 * $in: user_id, coins, amount_inr, method, reference, notes, stage (requested|unconfirmed|confirmed), idempotency_key
 */
function sa_deposit_create($subadminId, array $in, array $actor) {
    $stage = strtolower(trim((string) ($in['stage'] ?? 'unconfirmed')));
    if (!in_array($stage, ['requested', 'unconfirmed', 'confirmed'], true)) sa_fail('Stage must be requested, unconfirmed or confirmed.');
    $coins = sa_amount($in['coins'] ?? null, 'Coins', 1, 10000000);
    $amountInr = sa_amount($in['amount_inr'] ?? 0, 'Amount received (₹)', 0, 10000000);
    $method = $stage === 'requested' ? sa_method($in['method'] ?? null, false) : sa_method($in['method'] ?? null, true);
    $reference = sa_reference($in['reference'] ?? null);
    $notes = sa_text($in['notes'] ?? null, 300, 'Notes');
    $key = sa_idempotency_key($in['idempotency_key'] ?? null);
    if ($key === null) sa_fail('Missing request key — please reload the page and try again.');
    $userId = is_numeric($in['user_id'] ?? null) ? (int) $in['user_id'] : 0;

    return tx(function () use ($subadminId, $stage, $coins, $amountInr, $method, $reference, $notes, $key, $userId, $actor) {
        sa_lock($subadminId);
        $existing = one('SELECT * FROM "SubAdminDeposit" WHERE "idempotency_key" = ?', [$key]);
        if ($existing) {
            if ((int) $existing['subadmin_id'] !== (int) $subadminId) sa_fail('Invalid request key.', 409);
            return ['deposit' => sa_deposit_view($existing), 'replayed' => true];
        }
        $sa = sa_find($subadminId);
        if (!$sa || $sa['status'] !== 'active') sa_fail('This sub-admin account is not active.', 403);
        $user = sa_user_of($subadminId, $userId);
        $id = sa_ref('SDP');
        q('INSERT INTO "SubAdminDeposit" ("id","subadmin_id","user_id","username","coins","amount_inr","status","method","reference","notes","idempotency_key","created_by","created_by_role","created_at")
           VALUES (?,?,?,?,?,?,\'Requested\',?,?,?,?,?,?,?)',
          [$id, (int) $subadminId, (int) $user['id'], $user['username'], $coins, $amountInr,
           $stage === 'requested' ? $method : null, null, $notes, $key, $actor['name'], $actor['role'], ms_to_sql()]);
        if ($stage !== 'requested') {
            $d = one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ? FOR UPDATE', [$id]);
            sa_deposit_attach_payment($d, $amountInr, $method, $reference, $actor);
            if ($stage === 'confirmed') sa_deposit_confirm_locked(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ? FOR UPDATE', [$id]), $actor);
        }
        return ['deposit' => sa_deposit_view(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$id])), 'replayed' => false];
    });
}

/** Requested -> Unconfirmed: the sub-admin records the payment details. */
function sa_deposit_record_payment($depositId, $subadminId, array $in, array $actor) {
    $amountInr = sa_amount($in['amount_inr'] ?? null, 'Amount received (₹)', 0, 10000000);
    $method = sa_method($in['method'] ?? null, true);
    $reference = sa_reference($in['reference'] ?? null);
    return tx(function () use ($depositId, $subadminId, $amountInr, $method, $reference, $actor) {
        $d = sa_deposit_for_update($depositId, $subadminId);
        if ($d['status'] !== 'Requested') sa_fail('This deposit is ' . strtolower($d['status']) . '; a payment can only be recorded on a requested one.', 409, 'already_processed');
        sa_deposit_attach_payment($d, $amountInr, $method, $reference, $actor);
        return ['deposit' => sa_deposit_view(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$depositId]))];
    });
}

/** Unconfirmed -> Confirmed: payment received, coins credited. Requested deposits may pass payment details in the same call. */
function sa_deposit_confirm($depositId, $subadminId, array $in, array $actor) {
    return tx(function () use ($depositId, $subadminId, $in, $actor) {
        $d = sa_deposit_for_update($depositId, $subadminId);
        if ($d['status'] === 'Requested') {
            $amountInr = sa_amount($in['amount_inr'] ?? (float) $d['amount_inr'], 'Amount received (₹)', 0, 10000000);
            sa_deposit_attach_payment($d, $amountInr, sa_method($in['method'] ?? $d['method'], true), sa_reference($in['reference'] ?? null), $actor);
            $d = one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ? FOR UPDATE', [$depositId]);
        }
        $after = sa_deposit_confirm_locked($d, $actor);
        return ['deposit' => sa_deposit_view(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$depositId])), 'coin_balance' => $after];
    });
}

/** Requested/Unconfirmed -> Cancelled. No coins have moved, so none move now. */
function sa_deposit_cancel($depositId, $subadminId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($depositId, $subadminId, $reason, $actor) {
        $d = sa_deposit_for_update($depositId, $subadminId);
        if (!in_array($d['status'], ['Requested', 'Unconfirmed'], true)) sa_fail('This deposit is ' . strtolower($d['status']) . ' and can no longer be cancelled.', 409, 'already_processed');
        $now = ms_to_sql();
        q('UPDATE "SubAdminDeposit" SET "status" = \'Cancelled\', "cancelled_by" = ?, "cancelled_at" = ?, "cancel_reason" = ? WHERE "id" = ?', [$actor['name'], $now, $reason, $depositId]);
        if ($d['cash_id']) {
            q('UPDATE "SubAdminCash" SET "status" = \'Failed\', "failed_by" = ?, "failed_at" = ?, "fail_reason" = ? WHERE "id" = ? AND "status" = \'Unconfirmed\'',
              [$actor['name'], $now, 'Deposit cancelled: ' . $reason, $d['cash_id']]);
        }
        return ['deposit' => sa_deposit_view(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$depositId]))];
    });
}

/** Admin correction: Confirmed -> Reversed. Takes the coins back from the player (they must still have them). */
function sa_deposit_reverse($depositId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($depositId, $reason, $actor) {
        $d = sa_deposit_for_update($depositId);
        if ($d['status'] !== 'Confirmed') sa_fail('Only a confirmed deposit can be reversed (this one is ' . strtolower($d['status']) . ').', 409);
        $coins = (float) $d['coins'];
        $user = find_user_by_id($d['user_id']);
        if (!$user) sa_fail('The player account no longer exists.', 409);
        if (debit_wallet((int) $user['id'], $coins) === null) {
            sa_fail('The player now has only ' . number_format((float) $user['wallet_balance'], 2) . ' coins; reversing needs ' . number_format($coins, 2) . '.', 409, 'insufficient_balance');
        }
        $after = sa_inventory_add($d['subadmin_id'], $coins);
        insert_transaction(new_record_id('WTH'), $user['username'], 'Withdrawal', $coins, 'Sub-Admin Deposit Reversed ' . $d['id'] . ': ' . $reason, 'Completed');
        $orig = one('SELECT "id" FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'user_credit\' LIMIT 1', [$depositId]);
        sa_ledger('user_credit_reversal', 'user:' . (int) $user['id'], 'subadmin:' . (int) $d['subadmin_id'], $coins, $d['subadmin_id'], $actor, [
            'rupees' => (float) $d['amount_inr'], 'user_id' => $user['id'], 'username' => $user['username'], 'ref' => $depositId,
            'reverses' => $orig ? $orig['id'] : null, 'cash_id' => $d['cash_id'], 'inventory_after' => $after, 'notes' => $reason,
        ]);
        $cash = $d['cash_id'] ? one('SELECT * FROM "SubAdminCash" WHERE "id" = ? FOR UPDATE', [$d['cash_id']]) : null;
        if ($cash && $cash['status'] === 'Confirmed') sa_cash_reverse($cash, $reason, $actor);
        q('UPDATE "SubAdminDeposit" SET "status" = \'Reversed\', "reversed_by" = ?, "reversed_at" = ?, "reverse_reason" = ? WHERE "id" = ?',
          [$actor['name'], ms_to_sql(), $reason, $depositId]);
        return ['deposit' => sa_deposit_view(one('SELECT * FROM "SubAdminDeposit" WHERE "id" = ?', [$depositId]))];
    });
}

// -------------------------------------------------------------------------------------------------
// Withdrawals: the existing player flow, routed to the player's sub-admin
// -------------------------------------------------------------------------------------------------

/**
 * Called by the player's withdrawal request (routes/legacy.php), INSIDE the same transaction that
 * holds the coins and writes the Withdrawal row. If the player belongs to an active sub-admin, the
 * request goes to that sub-admin's queue instead of the Admin's. Returns the sub-admin's username,
 * or null (the request stays in the Admin's queue, exactly as before).
 */
function subadmin_route_withdrawal($withdrawalId, array $user, $amount) {
    if (!subadmin_ensure(false)) return null;
    // Everything runs inside a savepoint (db_best_effort): a failure here can never abort the
    // player's own transaction, it only leaves the request in the Admin's queue as before.
    $routed = db_best_effort(function () use ($withdrawalId, $user, $amount) {
        $sa = one('SELECT sa."id", sa."username" FROM "SubAdminUser" s JOIN "SubAdmin" sa ON sa."id" = s."subadmin_id"
                   WHERE s."user_id" = ? AND sa."status" = \'active\'', [(int) $user['id']]);
        if (!$sa) return false;
        $rate = sa_rate();
        q('INSERT INTO "SubAdminWithdrawal" ("withdrawal_id","subadmin_id","user_id","username","coins","rate","rupee_equivalent","status","created_at")
           VALUES (?,?,?,?,?,?,?,\'Pending\',?)',
          [$withdrawalId, (int) $sa['id'], (int) $user['id'], $user['username'], round2($amount), $rate, round2($amount * $rate), ms_to_sql()]);
        sa_ledger('withdrawal_reserved', 'user:' . (int) $user['id'], 'reserve:' . $withdrawalId, (float) $amount, $sa['id'],
                  ['name' => $user['username'], 'role' => 'user'],
                  ['rupees' => round2($amount * $rate), 'user_id' => $user['id'], 'username' => $user['username'], 'ref' => $withdrawalId,
                   'inventory_after' => sa_inventory($sa['id']),
                   'notes' => 'Coins held from the player\'s balance until the request is paid or rejected']);
        return $sa['username'];
    });
    if ($routed === null) {
        // db_best_effort answers null only when something threw: nothing was half-written and the
        // request stays in the Admin's queue, but the operator should know.
        log_error('withdrawal could not be routed to its sub-admin; left in the Admin queue', ['withdrawal_id' => $withdrawalId]);
    }
    return is_string($routed) ? $routed : null;
}

/** The sub-admin handling this withdrawal (not handed back to the Admin), or null. Never throws. */
function subadmin_withdrawal_route($withdrawalId) {
    try {
        return one('SELECT w."withdrawal_id", w."status", sa."id" AS subadmin_id, sa."username", sa."full_name" FROM "SubAdminWithdrawal" w
                    JOIN "SubAdmin" sa ON sa."id" = w."subadmin_id" WHERE w."withdrawal_id" = ? AND w."status" <> \'Returned\'', [(string) $withdrawalId]);
    } catch (Throwable $e) {
        return null;
    }
}

/** withdrawal_id => sub-admin username for every withdrawal a sub-admin handles. Never throws. */
function subadmin_routed_withdrawals() {
    $out = [];
    try {
        foreach (all('SELECT w."withdrawal_id", sa."username" FROM "SubAdminWithdrawal" w JOIN "SubAdmin" sa ON sa."id" = w."subadmin_id" WHERE w."status" <> \'Returned\'') as $r) {
            $out[$r['withdrawal_id']] = $r['username'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Coins a player bought through sub-admins (confirmed), for the cashier's risk checks. Never throws. */
function subadmin_confirmed_deposit_total($username) {
    $v = db_best_effort(function () use ($username) {
        return (float) scalar('SELECT COALESCE(SUM(d."coins"),0) FROM "SubAdminDeposit" d JOIN "User" u ON u."id" = d."user_id"
                               WHERE LOWER(u."username") = LOWER(?) AND d."status" = \'Confirmed\'', [(string) $username], 0);
    });
    return $v === null ? 0.0 : (float) $v;
}

function sa_withdrawal_for_update($withdrawalId, $onlySubadminId = null) {
    $w = one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$withdrawalId]);
    if (!$w || ($onlySubadminId !== null && (int) $w['subadmin_id'] !== (int) $onlySubadminId)) sa_fail('Withdrawal request not found.', 404);
    sa_lock($w['subadmin_id']);
    $w = one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ? FOR UPDATE', [$withdrawalId]);
    if ($onlySubadminId !== null && (int) $w['subadmin_id'] !== (int) $onlySubadminId) sa_fail('Withdrawal request not found.', 404);
    return $w;
}

/** Pending -> Approved: the sub-admin accepts the request and will pay it. No coins or cash move yet. */
function sa_withdrawal_approve($withdrawalId, $subadminId, array $actor) {
    return tx(function () use ($withdrawalId, $subadminId, $actor) {
        $w = sa_withdrawal_for_update($withdrawalId, $subadminId);
        $n = affected('UPDATE "SubAdminWithdrawal" SET "status" = \'Approved\', "approved_by" = ?, "approved_at" = ? WHERE "withdrawal_id" = ? AND "status" = \'Pending\'',
                      [$actor['name'], ms_to_sql(), $withdrawalId]);
        if ($n === 0) sa_fail('This request is ' . strtolower($w['status']) . '; only a pending one can be approved.', 409, 'already_processed');
        return ['withdrawal' => sa_withdrawal_view(one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$withdrawalId]))];
    });
}

/**
 * Pending/Approved -> Paid: the sub-admin confirms the payout was sent.
 * $in: amount_paid, method, reference, confirm (must be true)
 */
function sa_withdrawal_paid($withdrawalId, $subadminId, array $in, array $actor) {
    if (!sa_bool($in['confirm'] ?? false)) sa_fail('Tick the box to confirm the payout has actually been sent.');
    $amountPaid = sa_amount($in['amount_paid'] ?? null, 'Amount paid (₹)', 0.01, 10000000);
    $method = sa_method($in['method'] ?? null, true);
    $reference = sa_reference($in['reference'] ?? null);
    $coinsTo = sa_settings()['withdrawal_coins_to'];
    return tx(function () use ($withdrawalId, $subadminId, $amountPaid, $method, $reference, $coinsTo, $actor) {
        $w = sa_withdrawal_for_update($withdrawalId, $subadminId);
        if (!in_array($w['status'], ['Pending', 'Approved'], true)) sa_fail('This request is ' . strtolower($w['status']) . '; it cannot be paid.', 409, 'already_processed');
        sa_reference_unused('user_payout', $reference);
        $now = ms_to_sql();
        $claimed = affected('UPDATE "Withdrawal" SET "status" = \'Completed\', "updated_at" = ? WHERE "withdrawal_id" = ? AND "status" = \'Pending\'', [$now, $withdrawalId]);
        if ($claimed === 0) sa_fail('The withdrawal request is no longer pending.', 409, 'already_processed');
        q('UPDATE "Transaction" SET "status" = \'Completed\' WHERE "user" = ? AND "type" = \'Withdrawal\' AND "status" = \'Pending\' AND "details" LIKE ?',
          [$w['username'], '%' . $withdrawalId . '%']);
        $cashId = sa_cash_insert([
            'kind' => 'user_payout', 'direction' => 'subadmin_to_user', 'subadmin_id' => $w['subadmin_id'],
            'user_id' => $w['user_id'], 'username' => $w['username'], 'amount' => $amountPaid,
            'method' => $method, 'reference' => $reference, 'status' => 'Confirmed', 'withdrawal_id' => $withdrawalId,
            'notes' => 'Payout confirmed by ' . $actor['name'],
        ], $actor);
        $coins = (float) $w['coins'];
        if ($coinsTo === 'subadmin') {
            $after = sa_inventory_add($w['subadmin_id'], $coins);
            $to = 'subadmin:' . (int) $w['subadmin_id'];
            $note = 'Coins bought back by the sub-admin (returned to their inventory)';
        } else {
            $after = sa_inventory($w['subadmin_id']);
            $to = 'admin_supply';
            $note = 'Coins retired to the Admin\'s unlimited supply';
        }
        sa_ledger('withdrawal_paid', 'reserve:' . $withdrawalId, $to, $coins, $w['subadmin_id'], $actor, [
            'rupees' => $amountPaid, 'user_id' => $w['user_id'], 'username' => $w['username'], 'ref' => $withdrawalId,
            'cash_id' => $cashId, 'inventory_after' => $after, 'notes' => $note,
        ]);
        q('UPDATE "SubAdminWithdrawal" SET "status" = \'Paid\', "paid_amount" = ?, "payout_method" = ?, "payout_reference" = ?, "paid_by" = ?, "paid_at" = ?, "cash_id" = ?, "coins_to" = ?
           WHERE "withdrawal_id" = ?', [$amountPaid, $method, $reference, $actor['name'], $now, $cashId, $coinsTo, $withdrawalId]);
        return ['withdrawal' => sa_withdrawal_view(one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$withdrawalId])), 'coin_balance' => $after];
    });
}

/** Pending/Approved -> Rejected: the held coins go back to the player. */
function sa_withdrawal_reject($withdrawalId, $subadminId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($withdrawalId, $subadminId, $reason, $actor) {
        $w = sa_withdrawal_for_update($withdrawalId, $subadminId);
        if (!in_array($w['status'], ['Pending', 'Approved'], true)) sa_fail('This request is ' . strtolower($w['status']) . '; it cannot be rejected.', 409, 'already_processed');
        $now = ms_to_sql();
        $claimed = affected('UPDATE "Withdrawal" SET "status" = \'Rejected\', "updated_at" = ? WHERE "withdrawal_id" = ? AND "status" = \'Pending\'', [$now, $withdrawalId]);
        if ($claimed === 0) sa_fail('The withdrawal request is no longer pending.', 409, 'already_processed');
        credit_wallet((int) $w['user_id'], (float) $w['coins']);
        q('UPDATE "Transaction" SET "status" = \'Rejected\' WHERE "user" = ? AND "type" = \'Withdrawal\' AND "status" = \'Pending\' AND "details" LIKE ?',
          [$w['username'], '%' . $withdrawalId . '%']);
        sa_ledger('withdrawal_refund', 'reserve:' . $withdrawalId, 'user:' . (int) $w['user_id'], (float) $w['coins'], $w['subadmin_id'], $actor, [
            'user_id' => $w['user_id'], 'username' => $w['username'], 'ref' => $withdrawalId,
            'inventory_after' => sa_inventory($w['subadmin_id']), 'notes' => 'Rejected: ' . $reason,
        ]);
        q('UPDATE "SubAdminWithdrawal" SET "status" = \'Rejected\', "rejected_by" = ?, "rejected_at" = ?, "reject_reason" = ? WHERE "withdrawal_id" = ?',
          [$actor['name'], $now, $reason, $withdrawalId]);
        return ['withdrawal' => sa_withdrawal_view(one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$withdrawalId]))];
    });
}

/** Admin correction: Paid -> Reversed (the payout never reached the player). Coins go back to the player. */
function sa_withdrawal_reverse($withdrawalId, $reason, array $actor) {
    $reason = sa_text($reason, 300, 'Reason', true, 3);
    return tx(function () use ($withdrawalId, $reason, $actor) {
        $w = sa_withdrawal_for_update($withdrawalId);
        if ($w['status'] !== 'Paid') sa_fail('Only a paid withdrawal can be reversed (this one is ' . strtolower($w['status']) . ').', 409);
        $coins = (float) $w['coins'];
        if ($w['coins_to'] === 'subadmin') {
            $after = sa_inventory_take($w['subadmin_id'], $coins);
            if ($after === null) {
                sa_fail('The sub-admin has only ' . number_format(sa_inventory($w['subadmin_id']), 2) . ' coins; reversing this payout needs the '
                        . number_format($coins, 2) . ' bought-back coins.', 409, 'insufficient_inventory');
            }
            $from = 'subadmin:' . (int) $w['subadmin_id'];
        } else {
            $after = sa_inventory($w['subadmin_id']);
            $from = 'admin_supply';
        }
        $now = ms_to_sql();
        $n = affected('UPDATE "Withdrawal" SET "status" = \'Reversed\', "updated_at" = ? WHERE "withdrawal_id" = ? AND "status" = \'Completed\'', [$now, $withdrawalId]);
        if ($n === 0) sa_fail('The withdrawal is not in a completed state.', 409);
        credit_wallet((int) $w['user_id'], $coins);
        insert_transaction(new_record_id('DEP'), $w['username'], 'Deposit', $coins, 'Withdrawal Reversed ' . $withdrawalId . ': ' . $reason, 'Completed');
        $orig = one('SELECT "id" FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'withdrawal_paid\' LIMIT 1', [$withdrawalId]);
        sa_ledger('withdrawal_reversal', $from, 'user:' . (int) $w['user_id'], $coins, $w['subadmin_id'], $actor, [
            'rupees' => (float) $w['paid_amount'], 'user_id' => $w['user_id'], 'username' => $w['username'], 'ref' => $withdrawalId,
            'reverses' => $orig ? $orig['id'] : null, 'cash_id' => $w['cash_id'], 'inventory_after' => $after, 'notes' => $reason,
        ]);
        $cash = $w['cash_id'] ? one('SELECT * FROM "SubAdminCash" WHERE "id" = ? FOR UPDATE', [$w['cash_id']]) : null;
        if ($cash && $cash['status'] === 'Confirmed') sa_cash_reverse($cash, $reason, $actor);
        q('UPDATE "SubAdminWithdrawal" SET "status" = \'Reversed\', "reversed_by" = ?, "reversed_at" = ?, "reverse_reason" = ? WHERE "withdrawal_id" = ?',
          [$actor['name'], $now, $reason, $withdrawalId]);
        return ['withdrawal' => sa_withdrawal_view(one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$withdrawalId]))];
    });
}

// -------------------------------------------------------------------------------------------------
// Views
// -------------------------------------------------------------------------------------------------

function sa_cash_view(array $c) {
    return [
        'id' => $c['id'], 'kind' => $c['kind'], 'direction' => $c['direction'], 'subadmin_id' => (int) $c['subadmin_id'],
        'user_id' => $c['user_id'] === null ? null : (int) $c['user_id'], 'username' => $c['username'],
        'amount' => (float) $c['amount'], 'method' => $c['method'], 'reference' => $c['reference'], 'status' => $c['status'],
        'allocation_id' => $c['allocation_id'], 'deposit_id' => $c['deposit_id'], 'withdrawal_id' => $c['withdrawal_id'],
        'reverses' => $c['reverses'], 'notes' => $c['notes'],
        'created_by' => $c['created_by'], 'created_by_role' => $c['created_by_role'], 'created_at' => sa_iso($c['created_at']),
        'confirmed_by' => $c['confirmed_by'], 'confirmed_by_role' => $c['confirmed_by_role'], 'confirmed_at' => sa_iso($c['confirmed_at']),
        'failed_by' => $c['failed_by'], 'failed_at' => sa_iso($c['failed_at']), 'fail_reason' => $c['fail_reason'],
        'reversed_by' => $c['reversed_by'], 'reversed_at' => sa_iso($c['reversed_at']), 'reverse_reason' => $c['reverse_reason'],
    ];
}

function sa_deposit_view(array $d) {
    return [
        'id' => $d['id'], 'subadmin_id' => (int) $d['subadmin_id'], 'user_id' => (int) $d['user_id'], 'username' => $d['username'],
        'coins' => (float) $d['coins'], 'amount_inr' => (float) $d['amount_inr'], 'status' => $d['status'],
        'method' => $d['method'], 'reference' => $d['reference'], 'notes' => $d['notes'], 'cash_id' => $d['cash_id'],
        'created_by' => $d['created_by'], 'created_at' => sa_iso($d['created_at']), 'payment_recorded_at' => sa_iso($d['payment_recorded_at']),
        'confirmed_by' => $d['confirmed_by'], 'confirmed_at' => sa_iso($d['confirmed_at']),
        'cancelled_by' => $d['cancelled_by'], 'cancelled_at' => sa_iso($d['cancelled_at']), 'cancel_reason' => $d['cancel_reason'],
        'reversed_by' => $d['reversed_by'], 'reversed_at' => sa_iso($d['reversed_at']), 'reverse_reason' => $d['reverse_reason'],
    ];
}

function sa_withdrawal_view(array $w) {
    $wd = null;
    if (array_key_exists('dest_details', $w)) {
        $wd = ['method' => $w['dest_method'], 'details' => $w['dest_details'], 'status' => $w['request_status']];
    } else {
        try { $wd = one('SELECT "method","details","status" FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$w['withdrawal_id']]); } catch (Throwable $e) {}
    }
    return [
        'withdrawal_id' => $w['withdrawal_id'], 'subadmin_id' => (int) $w['subadmin_id'], 'user_id' => (int) $w['user_id'], 'username' => $w['username'],
        'coins' => (float) $w['coins'], 'rate' => (float) $w['rate'], 'rupee_equivalent' => (float) $w['rupee_equivalent'], 'status' => $w['status'],
        'destination_method' => $wd ? $wd['method'] : null, 'destination' => $wd ? $wd['details'] : null, 'request_status' => $wd ? $wd['status'] : null,
        'coins_to' => $w['coins_to'], 'approved_by' => $w['approved_by'], 'approved_at' => sa_iso($w['approved_at']),
        'paid_amount' => $w['paid_amount'] === null ? null : (float) $w['paid_amount'], 'payout_method' => $w['payout_method'],
        'payout_reference' => $w['payout_reference'], 'paid_by' => $w['paid_by'], 'paid_at' => sa_iso($w['paid_at']), 'cash_id' => $w['cash_id'],
        'rejected_by' => $w['rejected_by'], 'rejected_at' => sa_iso($w['rejected_at']), 'reject_reason' => $w['reject_reason'],
        'reversed_by' => $w['reversed_by'], 'reversed_at' => sa_iso($w['reversed_at']), 'reverse_reason' => $w['reverse_reason'],
        'returned_by' => $w['returned_by'], 'returned_at' => sa_iso($w['returned_at']),
        'created_at' => sa_iso($w['created_at']),
    ];
}

function sa_ledger_view(array $l) {
    return [
        'id' => $l['id'], 'type' => $l['type'], 'from_account' => $l['from_account'], 'to_account' => $l['to_account'],
        'coins' => (float) $l['coins'], 'rupees' => $l['rupees'] === null ? null : (float) $l['rupees'],
        'subadmin_id' => (int) $l['subadmin_id'], 'subadmin' => $l['subadmin_username'] ?? null,
        'user_id' => $l['user_id'] === null ? null : (int) $l['user_id'], 'username' => $l['username'],
        'ref' => $l['ref'], 'cash_id' => $l['cash_id'], 'reverses' => $l['reverses'],
        'inventory_after' => $l['inventory_after'] === null ? null : (float) $l['inventory_after'],
        'actor' => $l['actor'], 'actor_role' => $l['actor_role'], 'notes' => $l['notes'], 'created_at' => sa_iso($l['created_at']),
    ];
}

// -------------------------------------------------------------------------------------------------
// Figures. Every number below is an aggregate of the rows above — nothing is stored twice or guessed.
// -------------------------------------------------------------------------------------------------

/** Per-sub-admin aggregates for one id or all (null). Returns [subadmin_id => figures]. */
function sa_figures($onlyId = null) {
    $where = $onlyId === null ? '' : ' WHERE "subadmin_id" = ' . (int) $onlyId;
    $and = $onlyId === null ? '' : ' AND "subadmin_id" = ' . (int) $onlyId;
    $blank = [
        'coin_balance' => 0.0, 'users' => 0,
        'alloc_credited_coins' => 0.0, 'alloc_credited_count' => 0, 'alloc_awaiting_coins' => 0.0, 'alloc_awaiting_count' => 0,
        'alloc_reversed_coins' => 0.0, 'agreed_credited' => 0.0,
        'paid_admin_confirmed' => 0.0, 'paid_admin_unconfirmed' => 0.0, 'owed_admin' => 0.0,
        'dep_confirmed_coins' => 0.0, 'dep_confirmed_inr' => 0.0, 'dep_confirmed_count' => 0,
        'dep_unconfirmed_inr' => 0.0, 'dep_unconfirmed_count' => 0, 'dep_requested_count' => 0, 'dep_requested_coins' => 0.0,
        'dep_reversed_coins' => 0.0,
        'wd_pending_count' => 0, 'wd_pending_coins' => 0.0, 'wd_pending_inr' => 0.0,
        'wd_approved_count' => 0, 'wd_approved_coins' => 0.0, 'wd_approved_inr' => 0.0,
        'wd_paid_count' => 0, 'wd_paid_coins' => 0.0, 'wd_paid_inr' => 0.0, 'wd_rejected_count' => 0,
        'payout_confirmed_inr' => 0.0, 'cash_from_users_confirmed' => 0.0, 'cash_from_users_unconfirmed' => 0.0,
        'adjust_in' => 0.0, 'adjust_out' => 0.0, 'ledger_inventory' => 0.0,
    ];
    $F = [];
    foreach (all('SELECT "id","coin_balance" FROM "SubAdmin"' . ($onlyId === null ? '' : ' WHERE "id" = ' . (int) $onlyId)) as $r) {
        $F[(int) $r['id']] = $blank;
        $F[(int) $r['id']]['coin_balance'] = (float) $r['coin_balance'];
    }
    $get = function ($id) use (&$F, $blank) { if (!isset($F[$id])) $F[$id] = $blank; return $id; };

    foreach (all('SELECT "subadmin_id", COUNT(*) AS c FROM "SubAdminUser"' . $where . ' GROUP BY "subadmin_id"') as $r) $F[$get((int) $r['subadmin_id'])]['users'] = (int) $r['c'];
    foreach (all('SELECT "subadmin_id","status", COUNT(*) AS c, SUM("coins") AS coins, SUM("agreed_amount") AS agreed FROM "SubAdminAllocation"' . $where . ' GROUP BY "subadmin_id","status"') as $r) {
        $i = $get((int) $r['subadmin_id']);
        if ($r['status'] === 'credited') { $F[$i]['alloc_credited_coins'] = (float) $r['coins']; $F[$i]['alloc_credited_count'] = (int) $r['c']; $F[$i]['agreed_credited'] = (float) $r['agreed']; }
        if ($r['status'] === 'awaiting_payment') { $F[$i]['alloc_awaiting_coins'] = (float) $r['coins']; $F[$i]['alloc_awaiting_count'] = (int) $r['c']; }
        if ($r['status'] === 'reversed') $F[$i]['alloc_reversed_coins'] = (float) $r['coins'];
    }
    foreach (all('SELECT "subadmin_id","kind","status", SUM("amount") AS s FROM "SubAdminCash" WHERE "kind" <> \'reversal\'' . $and . ' GROUP BY "subadmin_id","kind","status"') as $r) {
        $i = $get((int) $r['subadmin_id']); $s = (float) $r['s'];
        if ($r['kind'] === 'allocation_payment' && $r['status'] === 'Confirmed') $F[$i]['paid_admin_confirmed'] = $s;
        if ($r['kind'] === 'allocation_payment' && $r['status'] === 'Unconfirmed') $F[$i]['paid_admin_unconfirmed'] = $s;
        if ($r['kind'] === 'user_deposit' && $r['status'] === 'Confirmed') $F[$i]['cash_from_users_confirmed'] = $s;
        if ($r['kind'] === 'user_deposit' && $r['status'] === 'Unconfirmed') $F[$i]['cash_from_users_unconfirmed'] = $s;
        if ($r['kind'] === 'user_payout' && $r['status'] === 'Confirmed') $F[$i]['payout_confirmed_inr'] = $s;
    }
    // Owed to the Admin: for every credited allocation, what is still unpaid (never below zero).
    foreach (all('SELECT a."subadmin_id", SUM(GREATEST(a."agreed_amount" - COALESCE(p.paid, 0), 0)) AS owed FROM "SubAdminAllocation" a
                  LEFT JOIN (SELECT "allocation_id", SUM("amount") AS paid FROM "SubAdminCash" WHERE "kind" = \'allocation_payment\' AND "status" = \'Confirmed\' GROUP BY "allocation_id") p
                    ON p."allocation_id" = a."id"
                  WHERE a."status" = \'credited\'' . ($onlyId === null ? '' : ' AND a."subadmin_id" = ' . (int) $onlyId) . ' GROUP BY a."subadmin_id"') as $r) {
        $F[$get((int) $r['subadmin_id'])]['owed_admin'] = round2((float) $r['owed']);
    }
    foreach (all('SELECT "subadmin_id","status", COUNT(*) AS c, SUM("coins") AS coins, SUM("amount_inr") AS inr FROM "SubAdminDeposit"' . $where . ' GROUP BY "subadmin_id","status"') as $r) {
        $i = $get((int) $r['subadmin_id']);
        if ($r['status'] === 'Confirmed') { $F[$i]['dep_confirmed_coins'] = (float) $r['coins']; $F[$i]['dep_confirmed_inr'] = (float) $r['inr']; $F[$i]['dep_confirmed_count'] = (int) $r['c']; }
        if ($r['status'] === 'Unconfirmed') { $F[$i]['dep_unconfirmed_inr'] = (float) $r['inr']; $F[$i]['dep_unconfirmed_count'] = (int) $r['c']; }
        if ($r['status'] === 'Requested') { $F[$i]['dep_requested_count'] = (int) $r['c']; $F[$i]['dep_requested_coins'] = (float) $r['coins']; }
        if ($r['status'] === 'Reversed') $F[$i]['dep_reversed_coins'] = (float) $r['coins'];
    }
    foreach (all('SELECT "subadmin_id","status", COUNT(*) AS c, SUM("coins") AS coins, SUM("rupee_equivalent") AS inr, SUM("paid_amount") AS paid FROM "SubAdminWithdrawal"' . $where . ' GROUP BY "subadmin_id","status"') as $r) {
        $i = $get((int) $r['subadmin_id']);
        $st = strtolower($r['status']);
        if (in_array($st, ['pending', 'approved'], true)) {
            $F[$i]['wd_' . $st . '_count'] = (int) $r['c']; $F[$i]['wd_' . $st . '_coins'] = (float) $r['coins']; $F[$i]['wd_' . $st . '_inr'] = (float) $r['inr'];
        }
        if ($st === 'paid') { $F[$i]['wd_paid_count'] = (int) $r['c']; $F[$i]['wd_paid_coins'] = (float) $r['coins']; $F[$i]['wd_paid_inr'] = (float) $r['paid']; }
        if ($st === 'rejected') $F[$i]['wd_rejected_count'] = (int) $r['c'];
    }
    foreach (all('SELECT "subadmin_id","type", SUM("coins") AS s FROM "SubAdminLedger" WHERE "type" IN (\'adjustment_credit\',\'adjustment_debit\')' . $and . ' GROUP BY "subadmin_id","type"') as $r) {
        $i = $get((int) $r['subadmin_id']);
        if ($r['type'] === 'adjustment_credit') $F[$i]['adjust_in'] = (float) $r['s']; else $F[$i]['adjust_out'] = (float) $r['s'];
    }
    // Inventory re-derived from the coin ledger alone, to prove it agrees with the stored balance.
    foreach (all('SELECT CAST(SUBSTRING(acct FROM 10) AS integer) AS sid, SUM(delta) AS inv FROM (
                    SELECT "to_account" AS acct, "coins" AS delta FROM "SubAdminLedger" WHERE "to_account" LIKE \'subadmin:%\'
                    UNION ALL
                    SELECT "from_account" AS acct, -"coins" AS delta FROM "SubAdminLedger" WHERE "from_account" LIKE \'subadmin:%\'
                  ) x GROUP BY acct') as $r) {
        $sid = (int) $r['sid'];
        if ($onlyId !== null && $sid !== (int) $onlyId) continue;
        $F[$get($sid)]['ledger_inventory'] = round2((float) $r['inv']);
    }

    foreach ($F as $id => $f) {
        foreach ($f as $k => $v) if (is_float($v)) $F[$id][$k] = round2($v);
        $F[$id]['reconciled'] = abs($F[$id]['ledger_inventory'] - $F[$id]['coin_balance']) < 0.005;
        $F[$id] = array_merge($F[$id], sa_position($F[$id]));
    }
    return $F;
}

/**
 * The accounting rules, stated once. Cash basis: only CONFIRMED cash counts as received or paid.
 *
 *   gross_cash_surplus     = cash received from users − cash paid out to users
 *   cash_balance           = gross_cash_surplus − cash paid to the Admin
 *   outstanding_liabilities= still owed to the Admin for credited allocations
 *                            + rupee value of withdrawal requests not yet paid (Pending/Approved)
 *   estimated_net_position = cash_balance − outstanding_liabilities          (an ESTIMATE)
 *   finalized_profit_loss  = cash_balance, but ONLY when nothing is open: no unconfirmed or requested
 *                            deposit, no unconfirmed payment to the Admin, no unpaid withdrawal, and
 *                            nothing owed to the Admin. Otherwise null — there is no final figure yet.
 *
 * Coins still in the inventory are not counted as income: what was paid for them is already in
 * "cash paid to the Admin". The Admin's unlimited supply is never an expense anywhere.
 */
function sa_position(array $f) {
    $gross = round2($f['cash_from_users_confirmed'] - $f['payout_confirmed_inr']);
    $cash = round2($gross - $f['paid_admin_confirmed']);
    $pendingPayouts = round2($f['wd_pending_inr'] + $f['wd_approved_inr']);
    $liab = round2($f['owed_admin'] + $pendingPayouts);
    $open = $f['dep_unconfirmed_count'] + $f['dep_requested_count'] + $f['wd_pending_count'] + $f['wd_approved_count']
          + ($f['paid_admin_unconfirmed'] > 0 ? 1 : 0) + ($f['owed_admin'] > 0 ? 1 : 0);
    return [
        'gross_cash_surplus' => $gross,
        'cash_balance' => $cash,
        'pending_payouts_inr' => $pendingPayouts,
        'outstanding_liabilities' => $liab,
        'estimated_net_position' => round2($cash - $liab),
        'open_items' => $open,
        'finalized_profit_loss' => $open === 0 ? $cash : null,
    ];
}

/** Gameplay of a sub-admin's users since each was assigned, from the existing Transaction ledger. */
function sa_game_activity($subadminId) {
    $out = ['wagered' => 0.0, 'won' => 0.0, 'bets' => 0, 'per_user' => [], 'per_game' => []];
    if (!function_exists('classify_gameplay_transaction')) return $out;
    $rows = all('SELECT t."user", t."amount", t."details" FROM "Transaction" t
                 JOIN "User" u ON u."username" = t."user"
                 JOIN "SubAdminUser" s ON s."user_id" = u."id"
                 WHERE s."subadmin_id" = ? AND t."timestamp" >= s."assigned_at" AND t."status" = \'Completed\'', [(int) $subadminId]);
    $labels = function_exists('game_labels') ? game_labels() : [];
    foreach ($rows as $t) {
        $cls = classify_gameplay_transaction($t['details']);
        if (!$cls || $cls['game'] === 'referral') continue;
        $amt = abs((float) $t['amount']);
        $u = strtolower($t['user']);
        if (!isset($out['per_user'][$u])) $out['per_user'][$u] = ['wagered' => 0.0, 'won' => 0.0, 'bets' => 0];
        $g = $cls['game'];
        if (!isset($out['per_game'][$g])) $out['per_game'][$g] = ['label' => $labels[$g] ?? $g, 'wagered' => 0.0, 'won' => 0.0, 'bets' => 0];
        if ($cls['kind'] === 'wager') {
            $out['wagered'] += $amt; $out['bets']++;
            $out['per_user'][$u]['wagered'] += $amt; $out['per_user'][$u]['bets']++;
            $out['per_game'][$g]['wagered'] += $amt; $out['per_game'][$g]['bets']++;
        } else {
            $out['won'] += $amt; $out['per_user'][$u]['won'] += $amt; $out['per_game'][$g]['won'] += $amt;
        }
    }
    $out['wagered'] = round2($out['wagered']); $out['won'] = round2($out['won']);
    $out['house_result'] = round2($out['wagered'] - $out['won']);
    foreach ($out['per_game'] as $g => $v) { $out['per_game'][$g]['wagered'] = round2($v['wagered']); $out['per_game'][$g]['won'] = round2($v['won']); }
    $out['per_game'] = array_values($out['per_game']);
    return $out;
}

/** The sub-admin's users with balances and what they did through this sub-admin. */
function sa_users_list($subadminId, $q = '') {
    $params = [(int) $subadminId];
    $filter = '';
    if (trim((string) $q) !== '') { $filter = ' AND LOWER(u."username") LIKE LOWER(?)'; $params[] = '%' . trim((string) $q) . '%'; }
    $rows = all('SELECT u."id", u."username", u."email", u."wallet_balance", u."created_at", s."assigned_at", s."how", s."assigned_by"
                 FROM "SubAdminUser" s JOIN "User" u ON u."id" = s."user_id" WHERE s."subadmin_id" = ?' . $filter . ' ORDER BY LOWER(u."username") LIMIT 1000', $params);
    $dep = []; $wd = [];
    foreach (all('SELECT "user_id", SUM("coins") AS c, COUNT(*) AS n FROM "SubAdminDeposit" WHERE "subadmin_id" = ? AND "status" = \'Confirmed\' GROUP BY "user_id"', [(int) $subadminId]) as $r) $dep[(int) $r['user_id']] = $r;
    foreach (all('SELECT "user_id", SUM(CASE WHEN "status" = \'Paid\' THEN "coins" ELSE 0 END) AS paid, SUM(CASE WHEN "status" IN (\'Pending\',\'Approved\') THEN 1 ELSE 0 END) AS open
                  FROM "SubAdminWithdrawal" WHERE "subadmin_id" = ? GROUP BY "user_id"', [(int) $subadminId]) as $r) $wd[(int) $r['user_id']] = $r;
    $act = sa_game_activity($subadminId)['per_user'];
    return array_map(function ($u) use ($dep, $wd, $act) {
        $id = (int) $u['id']; $a = $act[strtolower($u['username'])] ?? ['wagered' => 0.0, 'won' => 0.0, 'bets' => 0];
        return [
            'id' => $id, 'username' => $u['username'], 'email' => $u['email'], 'wallet_balance' => (float) $u['wallet_balance'],
            'joined_at' => sa_iso($u['created_at']), 'assigned_at' => sa_iso($u['assigned_at']), 'how' => $u['how'], 'assigned_by' => $u['assigned_by'],
            'coins_bought' => round2((float) ($dep[$id]['c'] ?? 0)), 'deposits' => (int) ($dep[$id]['n'] ?? 0),
            'coins_withdrawn' => round2((float) ($wd[$id]['paid'] ?? 0)), 'open_withdrawals' => (int) ($wd[$id]['open'] ?? 0),
            'wagered' => round2($a['wagered']), 'won' => round2($a['won']), 'bets' => $a['bets'],
        ];
    }, $rows);
}

/** One user's detail for their sub-admin: account, existing Transaction history, deposits and withdrawals. */
function sa_user_detail($subadminId, $userId) {
    $u = sa_user_of($subadminId, $userId);
    $tx = all('SELECT * FROM "Transaction" WHERE "user" = ? ORDER BY "timestamp" DESC LIMIT 200', [$u['username']]);
    return [
        'user' => ['id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'wallet_balance' => (float) $u['wallet_balance'],
                   'joined_at' => sa_iso($u['created_at']), 'assigned_at' => sa_iso($u['assigned_at']), 'how' => $u['how']],
        'transactions' => array_map('map_transaction', $tx),
        'deposits' => array_map('sa_deposit_view', all('SELECT * FROM "SubAdminDeposit" WHERE "subadmin_id" = ? AND "user_id" = ? ORDER BY "created_at" DESC LIMIT 200', [(int) $subadminId, (int) $userId])),
        'withdrawals' => sa_withdrawals_list('w."subadmin_id" = ? AND w."user_id" = ?', [(int) $subadminId, (int) $userId], 200),
    ];
}

/** Withdrawal rows with the player's payout destination joined in (one query, not one per row). */
function sa_withdrawals_list($whereSql, array $params, $limit = 500) {
    $rows = all('SELECT w.*, wd."method" AS dest_method, wd."details" AS dest_details, wd."status" AS request_status
                 FROM "SubAdminWithdrawal" w LEFT JOIN "Withdrawal" wd ON wd."withdrawal_id" = w."withdrawal_id"
                 WHERE ' . $whereSql . ' ORDER BY w."created_at" DESC LIMIT ' . max(1, min(2000, (int) $limit)), $params);
    return array_map('sa_withdrawal_view', $rows);
}

/**
 * The combined history (coin ledger and/or cash ledger) with filters.
 * $f: book (coin|cash|all), subadmin_id, user, from, to, type, status, limit
 */
function sa_history(array $f) {
    $book = in_array($f['book'] ?? 'all', ['coin', 'cash', 'all'], true) ? ($f['book'] ?? 'all') : 'all';
    [$lo, $hi] = sa_ist_range($f['from'] ?? null, $f['to'] ?? null);
    $limit = max(1, min(1000, (int) ($f['limit'] ?? 500)));
    $type = trim((string) ($f['type'] ?? ''));
    $status = trim((string) ($f['status'] ?? ''));
    $user = trim((string) ($f['user'] ?? ''));
    $sid = isset($f['subadmin_id']) && $f['subadmin_id'] !== '' && $f['subadmin_id'] !== null ? (int) $f['subadmin_id'] : null;
    $rows = [];
    $totals = ['coin_in' => 0.0, 'coin_out' => 0.0, 'cash' => []];

    $common = function ($alias, $tsCol) use ($lo, $hi, $user, $sid) {
        $w = []; $p = [];
        if ($sid !== null) { $w[] = "$alias.\"subadmin_id\" = ?"; $p[] = $sid; }
        if ($lo !== null) { $w[] = "$alias.\"$tsCol\" >= ?"; $p[] = $lo; }
        if ($hi !== null) { $w[] = "$alias.\"$tsCol\" < ?"; $p[] = $hi; }
        if ($user !== '') { $w[] = "LOWER(COALESCE($alias.\"username\", '')) LIKE LOWER(?)"; $p[] = '%' . $user . '%'; }
        return [$w, $p];
    };

    if ($book !== 'cash' && ($status === '' || $status === 'Completed')) {
        [$w, $p] = $common('l', 'created_at');
        if ($type !== '') { $w[] = 'l."type" = ?'; $p[] = $type; }
        $sql = 'SELECT l.*, sa."username" AS subadmin_username FROM "SubAdminLedger" l JOIN "SubAdmin" sa ON sa."id" = l."subadmin_id"'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY l."created_at" DESC LIMIT ' . $limit;
        foreach (all($sql, $p) as $l) {
            $v = sa_ledger_view($l);
            $v['book'] = 'coin'; $v['status'] = 'Completed';
            $rows[] = $v;
            if (strpos($l['to_account'], 'subadmin:') === 0) $totals['coin_in'] += (float) $l['coins'];
            if (strpos($l['from_account'], 'subadmin:') === 0) $totals['coin_out'] += (float) $l['coins'];
        }
    }
    if ($book !== 'coin') {
        [$w, $p] = $common('c', 'created_at');
        if ($type !== '') { $w[] = 'c."kind" = ?'; $p[] = $type; }
        if ($status !== '' && $status !== 'Completed') { $w[] = 'c."status" = ?'; $p[] = $status; }
        $sql = 'SELECT c.*, sa."username" AS subadmin_username FROM "SubAdminCash" c JOIN "SubAdmin" sa ON sa."id" = c."subadmin_id"'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c."created_at" DESC LIMIT ' . $limit;
        foreach (all($sql, $p) as $c) {
            $v = sa_cash_view($c);
            $v['book'] = 'cash'; $v['subadmin'] = $c['subadmin_username'];
            $rows[] = $v;
            if ($c['kind'] !== 'reversal') {
                $k = $c['kind'] . '|' . $c['status'];
                $totals['cash'][$k] = round2(($totals['cash'][$k] ?? 0) + (float) $c['amount']);
            }
        }
    }
    usort($rows, function ($a, $b) { return strcmp((string) $b['created_at'], (string) $a['created_at']); });
    $totals['coin_in'] = round2($totals['coin_in']); $totals['coin_out'] = round2($totals['coin_out']);
    return ['rows' => array_slice($rows, 0, $limit), 'count' => count($rows), 'capped' => count($rows) > $limit, 'totals' => $totals];
}

/**
 * The financial report for a period (India days, both inclusive; either may be blank for "all time").
 * Flows are dated by when they became final (confirmed / paid / credited); the position block is
 * always as of now.
 */
function sa_report($subadminId, $from, $to) {
    [$lo, $hi] = sa_ist_range($from, $to);
    $range = function ($col) use ($lo, $hi) {
        $w = ''; $p = [];
        if ($lo !== null) { $w .= " AND \"$col\" >= ?"; $p[] = $lo; }
        if ($hi !== null) { $w .= " AND \"$col\" < ?"; $p[] = $hi; }
        return [$w, $p];
    };
    $sid = (int) $subadminId;
    [$w, $p] = $range('confirmed_at');
    $cash = [];
    foreach (all('SELECT "kind", COUNT(*) AS n, SUM("amount") AS s FROM "SubAdminCash" WHERE "subadmin_id" = ? AND "status" = \'Confirmed\' AND "kind" <> \'reversal\'' . $w . ' GROUP BY "kind"', array_merge([$sid], $p)) as $r) {
        $cash[$r['kind']] = ['count' => (int) $r['n'], 'amount' => round2((float) $r['s'])];
    }
    [$w2, $p2] = $range('created_at');
    $coins = [];
    foreach (all('SELECT "type", COUNT(*) AS n, SUM("coins") AS s FROM "SubAdminLedger" WHERE "subadmin_id" = ?' . $w2 . ' GROUP BY "type"', array_merge([$sid], $p2)) as $r) {
        $coins[$r['type']] = ['count' => (int) $r['n'], 'coins' => round2((float) $r['s'])];
    }
    $open = 0;
    foreach (all('SELECT COUNT(*) AS n FROM "SubAdminDeposit" WHERE "subadmin_id" = ? AND "status" IN (\'Requested\',\'Unconfirmed\')' . $w2, array_merge([$sid], $p2)) as $r) $open += (int) $r['n'];
    foreach (all('SELECT COUNT(*) AS n FROM "SubAdminWithdrawal" WHERE "subadmin_id" = ? AND "status" IN (\'Pending\',\'Approved\')' . $w2, array_merge([$sid], $p2)) as $r) $open += (int) $r['n'];
    foreach (all('SELECT COUNT(*) AS n FROM "SubAdminCash" WHERE "subadmin_id" = ? AND "status" = \'Unconfirmed\'' . $w2, array_merge([$sid], $p2)) as $r) $open += (int) $r['n'];

    $in = $cash['user_deposit']['amount'] ?? 0.0;
    $out = $cash['user_payout']['amount'] ?? 0.0;
    $toAdmin = $cash['allocation_payment']['amount'] ?? 0.0;
    $gross = round2($in - $out);
    $result = round2($gross - $toAdmin);
    $f = sa_figures($sid)[$sid] ?? null;
    return [
        'from' => $from ?: null, 'to' => $to ?: null,
        'period' => [
            'cash_from_users' => $in, 'cash_paid_to_users' => $out, 'cash_paid_to_admin' => $toAdmin,
            'gross_cash_surplus' => $gross, 'cash_result' => $result,
            'open_items_in_period' => $open,
            'finalized_profit_loss' => $open === 0 && ($f ? $f['owed_admin'] <= 0 : true) ? $result : null,
            'cash' => $cash, 'coins' => $coins,
        ],
        'position_now' => $f,
        'rules' => [
            'Only confirmed cash counts. Unconfirmed amounts are shown separately and never added in.',
            'Gross cash surplus = cash received from users − cash paid out to users.',
            'Cash result = gross cash surplus − cash paid to the Admin for coin allocations.',
            'Outstanding liabilities = amounts still owed to the Admin for credited allocations + the rupee value of withdrawal requests not yet paid.',
            'Estimated net position = cash balance − outstanding liabilities. It is an estimate, not a final profit.',
            'A finalized profit/loss is shown only when nothing is open: no unconfirmed or requested deposit, no unpaid withdrawal, no unconfirmed payment to the Admin and nothing owed to the Admin.',
            'Coins still in the inventory are not counted as income; what was paid for them is already included.',
            'The Admin\'s coin supply is unlimited and is never counted as a cost.',
        ],
    ];
}

/** Platform-wide totals across every sub-admin, from the same per-sub-admin figures. */
function sa_platform_totals(array $figures) {
    $keys = ['coin_balance', 'users', 'alloc_credited_coins', 'alloc_awaiting_coins', 'paid_admin_confirmed', 'paid_admin_unconfirmed', 'owed_admin',
             'dep_confirmed_coins', 'dep_confirmed_inr', 'dep_unconfirmed_inr', 'wd_pending_count', 'wd_approved_count', 'wd_pending_inr', 'wd_approved_inr',
             'wd_paid_count', 'wd_paid_inr', 'cash_from_users_confirmed', 'cash_from_users_unconfirmed', 'payout_confirmed_inr',
             'gross_cash_surplus', 'cash_balance', 'outstanding_liabilities', 'estimated_net_position', 'adjust_in', 'adjust_out'];
    $t = array_fill_keys($keys, 0.0);
    $allReconciled = true;
    foreach ($figures as $f) {
        foreach ($keys as $k) $t[$k] += $f[$k];
        if (!$f['reconciled']) $allReconciled = false;
    }
    foreach ($t as $k => $v) $t[$k] = is_float($v) ? round2($v) : $v;
    $t['subadmins'] = count($figures);
    $t['all_reconciled'] = $allReconciled;
    // Coins ever issued from the Admin's unlimited supply, net of coins handed back to it.
    $issued = 0.0;
    try {
        $issued = (float) scalar('SELECT COALESCE(SUM(CASE WHEN "from_account" = \'admin_supply\' THEN "coins" WHEN "to_account" = \'admin_supply\' THEN -"coins" ELSE 0 END),0) FROM "SubAdminLedger"', [], 0);
    } catch (Throwable $e) {}
    $t['net_coins_issued_from_supply'] = round2($issued);
    $t['admin_supply'] = 'unlimited';
    return $t;
}
