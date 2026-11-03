<?php
/**
 * Database access (PDO/PostgreSQL) and the flat-file fallback store.
 *
 * This is the PostgreSQL edition of this file — see sql/schema-postgres.sql for the matching
 * schema and the type-choices note there for why a couple of columns are SMALLINT rather than
 * native BOOLEAN. A MySQL edition of this same layer exists for hosts that only offer MySQL.
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
    $mysql = ($c['driver'] ?? 'pgsql') === 'mysql';
    $dsn = $mysql
        ? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name'])
        : sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['name']);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Native prepares, so the driver returns real INT/DOUBLE rather than strings for every
        // column. That matters here: a wallet_balance arriving as the string "2000" would be
        // re-encoded as `"2000"` in a JSON response where the Node build sent `2000`.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];
    if ($mysql) {
        // UPDATE's row count = rows MATCHED (as in Postgres), not rows whose value changed. Every
        // conditional-update guard in the code branches on that count.
        $options[db_mysql_found_rows_attr()] = true;
        $options[PDO::ATTR_STATEMENT_CLASS] = ['Bet1xMysqlStatement', []];
    }
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
        if ($mysql) {
            // ANSI_QUOTES: "Name" is an identifier, exactly as in Postgres, so the one set of SQL
            // strings in this codebase runs on both. PIPES_AS_CONCAT: || joins strings, as in
            // Postgres. Strict mode: bad data is an error, never silently truncated.
            $pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES,PIPES_AS_CONCAT,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,"
                     . "NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'");
            // Postgres's default. InnoDB's own default (REPEATABLE READ) would let a request keep
            // reading a snapshot from before the lock it just waited for was released.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        } else {
            // Store and read every timestamp in UTC regardless of what the host's Postgres is set to.
            $pdo->exec("SET TIME ZONE 'UTC'");
        }
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

/** 'pgsql' or 'mysql' — which database engine this deployment runs on (DB_DRIVER in .env). */
function db_driver() {
    $c = cfg('DB');
    return ($c['driver'] ?? 'pgsql') === 'mysql' ? 'mysql' : 'pgsql';
}

function db_is_mysql() {
    return db_driver() === 'mysql';
}

/** Run a statement and return it. */
function q($sql, $params = []) {
    $pdo = db_or_throw();
    if (!db_is_mysql()) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    $stmt = $pdo->prepare(db_mysql_sql($sql));
    // Bind with real types. MySQL's server-side prepares need an integer for LIMIT/OFFSET, and a
    // PHP false must reach a SMALLINT flag column as 0, not as the empty string.
    $positional = array_keys($params) === range(0, count($params) - 1);
    $i = 0;
    foreach ($params as $k => $v) {
        $name = $positional ? ++$i : (is_int($k) ? $k + 1 : (strpos($k, ':') === 0 ? $k : ':' . $k));
        if ($v === null)        $stmt->bindValue($name, null, PDO::PARAM_NULL);
        elseif (is_bool($v))    $stmt->bindValue($name, $v ? 1 : 0, PDO::PARAM_INT);
        elseif (is_int($v))     $stmt->bindValue($name, $v, PDO::PARAM_INT);
        else                    $stmt->bindValue($name, (string) $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
}

/**
 * The application's SQL is written once, in PostgreSQL's dialect. With ANSI_QUOTES and
 * PIPES_AS_CONCAT set on the session (see db()), MySQL already accepts nearly all of it; this
 * rewrites the few remaining Postgres-only phrases into their exact MySQL equivalents:
 *
 *   INSERT ... ON CONFLICT (k) DO NOTHING      -> INSERT IGNORE ...     (row count 1 = inserted, 0 = existed)
 *   ON CONFLICT (k) DO UPDATE SET c = EXCLUDED.c -> ON DUPLICATE KEY UPDATE c = VALUES(c)
 *   a IS NOT DISTINCT FROM b                   -> a <=> b
 *   CAST(x AS integer) / CAST(x AS numeric)    -> CAST(x AS SIGNED) / CAST(x AS DECIMAL(30,10))
 *
 * Anything else Postgres-specific (advisory locks, RETURNING, INTERVAL arithmetic) is written
 * portably at its call site instead, through the helpers below.
 */
function db_mysql_sql($sql) {
    static $cache = [];
    if (isset($cache[$sql])) return $cache[$sql];
    $out = $sql;
    if (stripos($out, 'ON CONFLICT') !== false) {
        $n = 0;
        $out = preg_replace('/\s*\bON\s+CONFLICT\s*(\([^)]*\))?\s*DO\s+NOTHING\b/i', '', $out, -1, $n);
        if ($n > 0) $out = preg_replace('/^(\s*)INSERT\s+INTO\b/i', '$1INSERT IGNORE INTO', $out, 1);
        $out = preg_replace('/\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET\b/i', 'ON DUPLICATE KEY UPDATE', $out);
        $out = preg_replace('/\bEXCLUDED\s*\.\s*"(\w+)"/i', 'VALUES("$1")', $out);
        $out = preg_replace('/\bEXCLUDED\s*\.\s*(\w+)/i', 'VALUES("$1")', $out);
    }
    if (stripos($out, 'DISTINCT FROM') !== false) {
        $out = preg_replace('/\bIS\s+NOT\s+DISTINCT\s+FROM\b/i', '<=>', $out);
    }
    if (stripos($out, 'CAST(') !== false) {
        $out = preg_replace('/\bAS\s+(integer|int|bigint)\s*\)/i', 'AS SIGNED)', $out);
        $out = preg_replace('/\bAS\s+numeric\s*\)/i', 'AS DECIMAL(30,10))', $out);
    }
    if (count($cache) > 1000) $cache = [];
    return $cache[$sql] = $out;
}

/** PDO's MySQL "found rows" flag, under its PHP 8.4+ name where that exists. */
function db_mysql_found_rows_attr() {
    if (class_exists('Pdo\\Mysql') && defined('Pdo\\Mysql::ATTR_FOUND_ROWS')) return constant('Pdo\\Mysql::ATTR_FOUND_ROWS');
    return PDO::MYSQL_ATTR_FOUND_ROWS;
}

/**
 * MySQL statements, made to hand back the same PHP types Postgres does.
 *
 * The one difference that reaches the application: MySQL returns SUM() over an INTEGER column as a
 * DECIMAL, which PDO delivers as the string "5", where Postgres returns a BIGINT and PDO an int 5.
 * A whole-number DECIMAL result (scale 0 — real DECIMAL columns in this schema all have decimals)
 * is therefore turned back into an int. Everything else passes through unchanged.
 */
class Bet1xMysqlStatement extends PDOStatement {
    private $intCols = null;

    protected function __construct() {}

    private function intCols() {
        if ($this->intCols !== null) return $this->intCols;
        $this->intCols = [];
        $n = $this->columnCount();
        for ($i = 0; $i < $n; $i++) {
            $m = @$this->getColumnMeta($i);
            if (is_array($m) && ($m['native_type'] ?? '') === 'NEWDECIMAL' && (int) ($m['precision'] ?? -1) === 0) {
                $this->intCols[$i] = (string) $m['name'];
            }
        }
        return $this->intCols;
    }

    private static function toInt($v) {
        return (is_string($v) && preg_match('/^-?\d{1,18}$/', $v)) ? (int) $v : $v;
    }

    private function fixRow($row) {
        if (!is_array($row)) return $row;
        foreach ($this->intCols() as $i => $name) {
            if (array_key_exists($name, $row)) $row[$name] = self::toInt($row[$name]);
            elseif (array_key_exists($i, $row)) $row[$i] = self::toInt($row[$i]);
        }
        return $row;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        return $this->intCols() ? $this->fixRow($row) : $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = parent::fetchAll($mode, ...$args);
        $cols = $this->intCols();
        if (!$cols) return $rows;
        if ($mode !== PDO::FETCH_DEFAULT && ($mode & PDO::FETCH_COLUMN)) {
            $c = (int) ($args[0] ?? 0);
            return isset($cols[$c]) ? array_map(function ($v) { return self::toInt($v); }, $rows) : $rows;
        }
        return array_map(function ($r) { return $this->fixRow($r); }, $rows);
    }

    public function fetchColumn(int $column = 0): mixed {
        $v = parent::fetchColumn($column);
        return isset($this->intCols()[$column]) ? self::toInt($v) : $v;
    }
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
/**
 * Run a statement whose failure must not matter (an audit row, a diagnostic) — safely even inside a
 * transaction. In PostgreSQL one failed statement aborts the whole transaction, so a plain try/catch
 * around it would still break everything after it (every later query: "current transaction is
 * aborted"). A savepoint confines the failure to this statement. Returns $fn()'s result, or null.
 */
function db_best_effort(callable $fn) {
    $pdo = db();
    $inTx = $pdo && $pdo->inTransaction();
    try {
        if ($inTx) $pdo->exec('SAVEPOINT best_effort');
        $r = $fn();
        if ($inTx) $pdo->exec('RELEASE SAVEPOINT best_effort');
        return $r;
    } catch (Throwable $e) {
        if ($inTx) { try { $pdo->exec('ROLLBACK TO SAVEPOINT best_effort'); } catch (Throwable $ignored) {} }
        return null;
    }
}

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

/** A string folded to a signed 32-bit integer, for Postgres's two-key advisory locks. */
function lock_key_int($s) {
    $n = crc32((string) $s);
    return $n > 0x7FFFFFFF ? $n - 0x100000000 : $n;
}

/**
 * Run $fn in a transaction while holding an exclusive lock on one thing — one player's Mines board,
 * one Teen Patti table — so two requests about that thing run one after the other, never at once.
 *
 * It is what stops a player firing the same action several times in parallel to be paid twice:
 * every request re-reads the state AFTER the lock is granted, so the second one sees what the first
 * one did. The lock is released when the transaction ends. Re-entrant: a nested call for the same
 * $scope/$subject inside the same transaction is granted immediately.
 */
function tx_locked($scope, $subject, callable $fn) {
    return tx(function ($pdo) use ($scope, $subject, $fn) {
        db_xact_lock(lock_key_int($scope), lock_key_int(strtolower((string) $subject)));
        return $fn($pdo);
    });
}

/**
 * An exclusive lock held until the current transaction commits or rolls back. MUST be inside tx().
 *
 * Postgres: pg_advisory_xact_lock, with one bigint key ($b null) or two int keys — the two key
 * spaces are separate, exactly as Postgres keeps them. MySQL has no transaction-scoped advisory
 * lock, so it locks a row of the "DbLock" table instead: INSERT ... ON DUPLICATE KEY UPDATE takes an
 * exclusive row lock whether the row is new or already there, InnoDB holds it to the end of the
 * transaction, and a second request for the same key waits — the same three properties. Re-entrant
 * within one transaction, like the Postgres lock.
 */
function db_xact_lock($a, $b = null) {
    if (!db_is_mysql()) {
        if ($b === null) q('SELECT pg_advisory_xact_lock(?)', [(int) $a]);
        else q('SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))', [(int) $a, (int) $b]);
        return;
    }
    $name = $b === null ? '1:' . (int) $a : '2:' . (int) $a . ':' . (int) $b;
    q('INSERT INTO "DbLock" ("name") VALUES (?) ON DUPLICATE KEY UPDATE "name" = "name"', [$name]);
}

/**
 * Upper bound of "now minus N units", as a SQL timestamp string, for portable age filters
 * (`"created_at" > ?`) in place of Postgres's `NOW() - INTERVAL '1 day'`.
 */
function sql_ago($seconds) {
    return ms_to_sql(now_ms() - (int) round($seconds * 1000));
}

// -------------------------------------------------------------------------------------------------
// GameState — the generic key/JSON store
// -------------------------------------------------------------------------------------------------

/** Read a GameState blob as an associative array, or null when the key does not exist. */
function state_get($key) {
    $row = one('SELECT "data" FROM "GameState" WHERE "key" = ?', [$key]);
    if (!$row) return null;
    $decoded = json_decode($row['data'], true);
    return $decoded === null ? null : $decoded;
}

/** Upsert a GameState blob. */
function state_set($key, $data) {
    // "updatedAt" is refreshed by the set_updated_at trigger (sql/schema-postgres.sql), the
    // Postgres equivalent of MySQL's ON UPDATE CURRENT_TIMESTAMP(3) — not set explicitly here.
    q('INSERT INTO "GameState" ("key", "data") VALUES (?, ?)
       ON CONFLICT ("key") DO UPDATE SET "data" = EXCLUDED."data"',
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
    $row = one('SELECT "data" FROM "GameState" WHERE "key" = ? FOR UPDATE', [$key]);
    if (!$row) return null;
    $decoded = json_decode($row['data'], true);
    return $decoded === null ? null : $decoded;
}

/**
 * Take a named advisory lock for the duration of a closure.
 *
 * Used where the thing being serialised is not a single row — the Aviator tick, which advances one
 * global phase machine and must not be advanced twice concurrently. Postgres advisory locks
 * (pg_advisory_lock / pg_advisory_unlock) are session-scoped exactly like MySQL's GET_LOCK, so a
 * crashed request cannot strand one — it is released the moment the connection closes, and this
 * codebase opens one connection per request (see db(), top of file).
 *
 * Postgres advisory locks key on a bigint, not a string name, and pg_try_advisory_lock (unlike
 * MySQL's GET_LOCK) has no built-in timeout — it is non-blocking by design — so the timeout is
 * reproduced here as a short poll loop instead.
 */
function with_named_lock($name, $timeoutSec, callable $fn) {
    $pdo = db();
    if (!$pdo) return $fn();
    if (db_is_mysql()) {
        // MySQL's GET_LOCK is exactly this: session-scoped, named, with its own timeout. Names are
        // capped at 64 characters, so a long one is hashed (deterministically, so every process
        // agrees on it).
        $lockName = 'bet1x_' . $name;
        if (strlen($lockName) > 64) $lockName = 'bet1x_' . sha1($name);
        $got = (int) scalar('SELECT GET_LOCK(?, ?)', [$lockName, (int) ceil(max(0, (float) $timeoutSec))], 0) === 1;
        if (!$got) return null;
        try {
            return $fn();
        } finally {
            try { q('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (Throwable $ignored) {}
        }
    }
    // crc32 keeps the key well inside bigint range and deterministic across requests/processes,
    // which is all pg_try_advisory_lock needs — it does not have to be collision-proof against
    // every other lock name in the system, only stable for this one.
    $lockKey = crc32('bet1x_' . $name);
    $deadline = microtime(true) + max(0, (float)$timeoutSec);
    $got = false;
    while (true) {
        $got = (bool) scalar('SELECT pg_try_advisory_lock(?)', [$lockKey], false);
        if ($got || microtime(true) >= $deadline) break;
        usleep(50000);
    }
    if (!$got) {
        // Somebody else is already doing this work. Skipping is correct: the other request will
        // finish it, and every caller here re-reads state afterwards anyway.
        return null;
    }
    try {
        return $fn();
    } finally {
        try { q('SELECT pg_advisory_unlock(?)', [$lockKey]); } catch (Throwable $ignored) {}
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
