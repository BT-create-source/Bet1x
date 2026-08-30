<?php
/**
 * Database access (PDO/MySQL) and the flat-file fallback store.
 *
 * Port of the Prisma + readJsonTable/writeJsonTable layer in backend/server.js.
 *
 * EVERY query in this codebase goes through here with bound parameters. There is no string
 * interpolation of user input into SQL anywhere, and the one place a caller supplies part of a
 * query (the /api/db/:table/sync endpoint's table name) resolves it through a fixed allowlist
 * rather than passing it through.
 *
 * Connection semantics differ from Node in one way worth stating: the Node process held a single
 * pooled connection open for its lifetime and set `databaseReady` once at boot. PHP connects per
 * request, so readiness is discovered lazily on first use and cached for the rest of the request.
 * The observable behaviour — a 503 from requireDatabase when the database is unreachable and the
 * JSON fallback is disabled — is identical.
 */

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/logger.php';

$GLOBALS['BET1X_PDO'] = null;
$GLOBALS['BET1X_DB_READY'] = null;   // null = not yet attempted
$GLOBALS['BET1X_DB_ERROR'] = null;

/**
 * The PDO handle, connecting on first use. Returns null when the database cannot be reached —
 * callers either fall back to flat files or answer 503, exactly as the Node build did.
 */
function db() {
    if ($GLOBALS['BET1X_DB_READY'] !== null) {
        return $GLOBALS['BET1X_PDO'];
    }
    $c = cfg('DB');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['name'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Native prepares, so MySQL returns real INT/DOUBLE rather than strings for every
            // column. That matters here: a wallet_balance arriving as the string "2000" would be
            // re-encoded as `"2000"` in a JSON response where the Node build sent `2000`.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Store and read every timestamp in UTC regardless of what the host's MySQL is set to.
        $pdo->exec("SET time_zone = '+00:00'");
        $GLOBALS['BET1X_PDO'] = $pdo;
        $GLOBALS['BET1X_DB_READY'] = true;
    } catch (Throwable $e) {
        $GLOBALS['BET1X_PDO'] = null;
        $GLOBALS['BET1X_DB_READY'] = false;
        $GLOBALS['BET1X_DB_ERROR'] = $e->getMessage();
        log_warn('Database unreachable', ['message' => $e->getMessage()]);
    }
    return $GLOBALS['BET1X_PDO'];
}

/** Has the database proven reachable this request? */
function db_ready() {
    db();
    return $GLOBALS['BET1X_DB_READY'] === true;
}

/**
 * Should this code path fall back to flat files when a query throws?
 *
 * A production deployment must not: quietly writing balances to a JSON file while the real database
 * is unreachable produces two divergent sources of truth for people's money.
 */
function json_fallback_allowed() {
    return (bool) cfg('ALLOW_JSON_FALLBACK');
}

/**
 * Raise the same condition Prisma raised when it could not reach the database, so the try/catch
 * shape of every ported route stays identical to the original.
 */
class DbUnavailable extends RuntimeException {}

function db_or_throw() {
    $pdo = db();
    if (!$pdo) throw new DbUnavailable('Database unavailable: ' . (string)$GLOBALS['BET1X_DB_ERROR']);
    return $pdo;
}

/** Run a statement and return it. */
function q($sql, $params = []) {
    $stmt = db_or_throw()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** First row, or null. */
function one($sql, $params = []) {
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function all($sql, $params = []) {
    return q($sql, $params)->fetchAll();
}

/** Single scalar from the first column of the first row. */
function scalar($sql, $params = [], $default = null) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Rows affected. This is the return value the conditional-update guards branch on. */
function affected($sql, $params = []) {
    return q($sql, $params)->rowCount();
}

/**
 * Run a closure inside a transaction, mirroring prisma.$transaction.
 *
 * Rethrows whatever the closure threw after rolling back, because several routes inspect the
 * message (`'User not found.'`, `'Insufficient wallet balance.'`) to decide their status code.
 */
function tx(callable $fn) {
    $pdo = db_or_throw();
    // Nested calls reuse the outer transaction rather than failing on a second begin.
    if ($pdo->inTransaction()) return $fn($pdo);
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

// -------------------------------------------------------------------------------------------------
// GameState — the generic key/JSON store
// -------------------------------------------------------------------------------------------------

/** Read a GameState blob as an associative array, or null when the key does not exist. */
function state_get($key) {
    $row = one('SELECT `data` FROM `GameState` WHERE `key` = ?', [$key]);
    if (!$row) return null;
    $decoded = json_decode($row['data'], true);
    return $decoded === null ? null : $decoded;
}

/** Upsert a GameState blob. */
function state_set($key, $data) {
    q('INSERT INTO `GameState` (`key`, `data`) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE `data` = VALUES(`data`)',
      [$key, js_json_encode($data)]);
    return $data;
}

/**
 * Read a GameState blob with the row locked FOR UPDATE, for callers that must read-modify-write it
 * without another request interleaving.
 *
 * This replaces withColorState()'s in-process promise queue. The queue serialised the whole
 * read-settle-write of the colour state so two pollers could not both settle the same round and
 * pay it out twice — a bug load testing caught destroying real money. A row lock is a strictly
 * stronger guarantee than the promise chain was, because it also holds across separate PHP
 * processes, which the single-process queue never did.
 *
 * MUST be called inside tx().
 */
function state_get_for_update($key) {
    $row = one('SELECT `data` FROM `GameState` WHERE `key` = ? FOR UPDATE', [$key]);
    if (!$row) return null;
    $decoded = json_decode($row['data'], true);
    return $decoded === null ? null : $decoded;
}

/**
 * Take a named advisory lock for the duration of a closure.
 *
 * Used where the thing being serialised is not a single row — the Aviator tick, which advances one
 * global phase machine and must not be advanced twice concurrently. MySQL's GET_LOCK is
 * connection-scoped and released explicitly, so a crashed request cannot strand it.
 */
function with_named_lock($name, $timeoutSec, callable $fn) {
    $pdo = db();
    if (!$pdo) return $fn();
    $got = scalar('SELECT GET_LOCK(?, ?)', ['bet1x_' . $name, $timeoutSec], 0);
    if ((int)$got !== 1) {
        // Somebody else is already doing this work. Skipping is correct: the other request will
        // finish it, and every caller here re-reads state afterwards anyway.
        return null;
    }
    try {
        return $fn();
    } finally {
        try { q('SELECT RELEASE_LOCK(?)', ['bet1x_' . $name]); } catch (Throwable $ignored) {}
    }
}

// -------------------------------------------------------------------------------------------------
// Flat-file fallback store (backend/data/*.json)
//
// Same directory and same file format as the Node build, so an existing development dataset keeps
// working and nothing has to be migrated to try this out.
// -------------------------------------------------------------------------------------------------

function data_dir() {
    $dir = cfg('DATA_DIR');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function readJsonTable($table) {
    $path = data_dir() . '/' . $table . '.json';
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        log_error("Corrupt JSON table {$table}.json - treating as empty");
        return [];
    }
    return $parsed;
}

function writeJsonTable($table, $data) {
    $path = data_dir() . '/' . $table . '.json';
    $tmp  = $path . '.' . getmypid() . '.tmp';
    // Write-then-rename so a crash mid-write can never leave a half-serialised table behind.
    $ok = @file_put_contents($tmp, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($ok === false) {
        log_error("Error writing {$table}.json");
        @unlink($tmp);
        return;
    }
    if (!@rename($tmp, $path)) {
        log_error("Error renaming {$table}.json");
        @unlink($tmp);
    }
}
