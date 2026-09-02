<?php
/**
 * Shared primitives: JavaScript-compatible number parsing, id generation, wallet movement,
 * stake validation and user lookup.
 *
 * The number-parsing helpers at the top are not padding. A great deal of this application's
 * validation is written as `parseFloat(x) || 0`, `parseInt(x, 10)` and `Number.isFinite(...)`, and
 * those have specific semantics that PHP's own casts do not share:
 *
 *     parseFloat("12abc")  -> 12      (PHP (float) agrees, but emits a notice)
 *     parseFloat("abc")    -> NaN     (PHP (float) gives 0.0 — a real behaviour difference:
 *                                      a garbage `delta` would become a silent no-op instead of
 *                                      the 'Invalid adjustment amount.' the original returns)
 *     parseFloat("")       -> NaN     (PHP gives 0.0)
 *     parseInt("3.9", 10)  -> 3
 *     parseInt("0x1A", 10) -> 0       (radix 10 stops at the 'x')
 *
 * Getting these wrong changes which requests are rejected, so they are modelled explicitly.
 */

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/logger.php';

// -------------------------------------------------------------------------------------------------
// JavaScript number semantics
// -------------------------------------------------------------------------------------------------

/** JavaScript parseFloat. Returns NAN when nothing parses, exactly as JS does. */
function js_parse_float($value) {
    if (is_float($value)) return $value;
    if (is_int($value))   return (float)$value;
    if (is_bool($value) || $value === null || is_array($value)) return NAN;
    $s = (string)$value;
    if (preg_match('/^[\s\x{FEFF}\x{00A0}]*([+-]?(?:Infinity|\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+(?:[eE][+-]?\d+)?))/u', $s, $m)) {
        if (substr($m[1], -8) === 'Infinity') return (strpos($m[1], '-') === 0) ? -INF : INF;
        return (float)$m[1];
    }
    return NAN;
}

/** JavaScript parseInt(value, 10). Returns NAN when nothing parses. */
function js_parse_int($value) {
    if (is_int($value))   return $value;
    if (is_float($value)) return is_nan($value) || is_infinite($value) ? NAN : (int)$value;
    if (is_bool($value) || $value === null || is_array($value)) return NAN;
    $s = (string)$value;
    if (preg_match('/^[\s\x{FEFF}\x{00A0}]*([+-]?\d+)/u', $s, $m)) return (int)$m[1];
    return NAN;
}

/** Number.isFinite — false for NAN, INF and non-numbers. */
function js_is_finite($v) {
    return (is_int($v) || (is_float($v) && !is_nan($v) && !is_infinite($v)));
}

/** Number.isInteger. */
function js_is_integer($v) {
    if (is_int($v)) return true;
    return is_float($v) && !is_nan($v) && !is_infinite($v) && floor($v) === $v;
}

/** JavaScript truthiness, for faithful `x || fallback` ports. */
function js_truthy($v) {
    if ($v === null || $v === false) return false;
    if (is_string($v)) return $v !== '';
    if (is_int($v))    return $v !== 0;
    if (is_float($v))  return !is_nan($v) && $v != 0.0;
    if (is_array($v))  return true;   // [] and {} are truthy in JS
    return (bool)$v;
}

/**
 * JavaScript's Number.prototype.toFixed, as a STRING.
 *
 * sprintf('%.NF') rather than number_format(), and the difference is not academic. toFixed rounds
 * the EXACT binary value of the double; number_format() applies PHP's pre-rounding correction
 * first. The classic divergence is 1.005, which as a double is really 1.004999999999999893...:
 * JavaScript renders "1.00", number_format() renders "1.01". A one-paisa disagreement on a payout
 * is exactly the kind of drift this port is not allowed to introduce, so the C-library formatter —
 * which rounds the true value, like JavaScript — is used instead. %F (uppercase) is
 * locale-independent, so a host with a comma decimal separator cannot corrupt the output.
 */
function js_to_fixed($value, $decimals = 2) {
    $f = is_float($value) || is_int($value) ? (float)$value : js_parse_float($value);
    if (is_nan($f)) return 'NaN';
    return sprintf('%.' . (int)$decimals . 'F', $f);
}

/** parseFloat(x.toFixed(n)) — the codebase's standard rounding idiom, as a number. */
function to_fixed_num($value, $decimals = 2) {
    $f = is_float($value) || is_int($value) ? (float)$value : js_parse_float($value);
    if (is_nan($f)) return 0.0;
    return (float) js_to_fixed($f, $decimals);
}

/**
 * Math.round(x * 100) / 100 — the other rounding idiom, used on stakes and payouts.
 *
 * Math.round is floor(x + 0.5): it rounds a half UP, toward positive infinity. PHP's round() rounds
 * a half AWAY FROM ZERO, so the two disagree on negative halves (Math.round(-2.5) is -2, PHP's
 * round(-2.5) is -3). Every current caller passes a positive amount, but the semantics are matched
 * rather than assumed, because a future caller that does not would fail silently.
 */
function round2($value) {
    $f = is_float($value) || is_int($value) ? (float)$value : js_parse_float($value);
    if (is_nan($f)) return 0.0;
    return floor($f * 100 + 0.5) / 100;
}

/** Math.random() in [0,1). */
function js_random() {
    return mt_rand() / (mt_getrandmax() + 1.0);
}

/** Fisher-Yates, matching tpShuffle. */
function js_shuffle(array $arr) {
    $a = array_values($arr);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = (int) floor(js_random() * ($i + 1));
        $tmp = $a[$i]; $a[$i] = $a[$j]; $a[$j] = $tmp;
    }
    return $a;
}

// -------------------------------------------------------------------------------------------------
// Identifiers
// -------------------------------------------------------------------------------------------------

/**
 * Collision-resistant ledger id: PREFIX_<ms epoch>_<10 random bytes as hex>.
 *
 * The comment in server.js explains why this exists — 'MINES_' + Date.now() collided when two
 * players started in the same millisecond, and because the wallet is debited BEFORE the insert,
 * money was destroyed with no ledger row to show for it.
 */
function new_record_id($prefix) {
    return $prefix . '_' . now_ms() . '_' . bin2hex(random_bytes(10));
}

/**
 * The OTHER id generator, kept deliberately.
 *
 * Three endpoints (/api/wallet/adjust, /api/wallet/reset and admin.php's adjust_balance) build ids
 * as PREFIX_ + a six-digit random number instead of using new_record_id. That is only 900,000
 * possible values, so by the birthday bound a collision becomes likely at around 1,100 rows — a
 * real latent bug. It is reproduced exactly rather than fixed, because this port is not allowed to
 * change behaviour; it is flagged in the migration dossier as a separate decision to make later.
 */
function legacy_record_id($prefix) {
    return $prefix . (int) floor(100000 + js_random() * 900000);
}

// -------------------------------------------------------------------------------------------------
// Row mappers — a database row rendered exactly as Prisma would have serialised it
// -------------------------------------------------------------------------------------------------

function map_user($r) {
    if (!$r) return null;
    return [
        'id'             => (int)$r['id'],
        'username'       => $r['username'],
        'email'          => $r['email'],
        'password'       => $r['password'],
        'wallet_balance' => (float)$r['wallet_balance'],
        'created_at'     => js_iso($r['created_at']),
    ];
}

function map_transaction($r) {
    return [
        'id'        => $r['id'],
        'user'      => $r['user'],
        'type'      => $r['type'],
        'amount'    => (float)$r['amount'],
        'details'   => $r['details'],
        'status'    => $r['status'],
        'timestamp' => js_iso($r['timestamp']),
    ];
}

function map_deposit($r) {
    return [
        'deposit_id'     => $r['deposit_id'],
        'order_id'       => $r['order_id'],
        'username'       => $r['username'],
        'amount'         => (float)$r['amount'],
        'utr'            => $r['utr'],
        'qr_type'        => $r['qr_type'],
        'custom_qr_data' => $r['custom_qr_data'],
        'status'         => $r['status'],
        'gateway'        => $r['gateway'],
        'gateway_id'     => $r['gateway_id'],
        'created_at'     => js_iso($r['created_at']),
        'updated_at'     => js_iso($r['updated_at']),
    ];
}

function map_withdrawal($r) {
    return [
        'withdrawal_id' => $r['withdrawal_id'],
        'username'      => $r['username'],
        'amount'        => (float)$r['amount'],
        'method'        => $r['method'],
        'details'       => $r['details'],
        'status'        => $r['status'],
        'created_at'    => js_iso($r['created_at']),
        'updated_at'    => js_iso($r['updated_at']),
    ];
}

function map_payment_log($r) {
    return [
        'id'        => $r['id'],
        'payload'   => json_decode($r['payload'], true),
        'signature' => $r['signature'],
        'timestamp' => js_iso($r['timestamp']),
    ];
}

function map_chat($r) {
    return [
        'id'        => (int)$r['id'],
        'username'  => $r['username'],
        'message'   => $r['message'],
        'timestamp' => js_iso($r['timestamp']),
    ];
}

function map_game_bet($r) {
    return [
        'id'         => $r['id'],
        'username'   => $r['username'],
        'game'       => $r['game'],
        'bet_amount' => (float)$r['bet_amount'],
        'payout'     => (float)$r['payout'],
        'status'     => $r['status'],
        'metadata'   => $r['metadata'] === null ? null : json_decode($r['metadata'], true),
        'created_at' => js_iso($r['created_at']),
        'settled_at' => $r['settled_at'] === null ? null : js_iso($r['settled_at']),
    ];
}

// -------------------------------------------------------------------------------------------------
// Users
// -------------------------------------------------------------------------------------------------

/**
 * Case-insensitive user lookup — the PHP spelling of Prisma's `mode: 'insensitive'`.
 *
 * LOWER() on both sides rather than relying on a case-insensitive column collation, because the
 * columns are deliberately utf8mb4_bin so that uniqueness stays case-SENSITIVE the way PostgreSQL's
 * default collation made it. See the note at the top of sql/schema.sql.
 */
function find_user_ci($username) {
    if (!is_string($username) || $username === '') return null;
    return one('SELECT * FROM "User" WHERE LOWER("username") = LOWER(?) LIMIT 1', [$username]);
}

function find_user_by_id($id) {
    return one('SELECT * FROM "User" WHERE "id" = ? LIMIT 1', [(int)$id]);
}

/**
 * Look up a user by name, optionally creating one.
 *
 * Creation only happens when ALLOW_AUTO_USER_CREATION is explicitly enabled. It used to mint a
 * fully funded account for any username appearing in a request body, which was an unlimited
 * free-money faucet; an unknown username now simply resolves to null.
 */
function get_or_create_user($username, $allowCreate = null) {
    if (is_array($username)) $username = $username[0] ?? null;
    if (!is_string($username)) return null;
    $username = trim($username);
    if ($username === '') return null;
    if ($allowCreate === null) $allowCreate = (bool) cfg('ALLOW_AUTO_USER_CREATION');

    try {
        $user = find_user_ci($username);
        if (!$user && $allowCreate) {
            $bonus = (float) cfg('SIGNUP_BONUS');
            q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at") VALUES (?,?,?,?,?)', [
                $username,
                strtolower($username) . '@bet1x.local',
                hash_password(bin2hex(random_bytes(24))),
                $bonus,
                ms_to_sql(),
            ]);
            log_info("Auto-created user record for \"{$username}\"", ['balance' => $bonus]);
            $user = find_user_ci($username);
        }
        return $user;
    } catch (Throwable $e) {
        if (!json_fallback_allowed()) throw $e;
        $users = readJsonTable('users');
        foreach ($users as $u) {
            if (isset($u['username']) && strtolower($u['username']) === strtolower($username)) return $u;
        }
        if ($allowCreate) {
            $user = [
                'id'             => count($users) + 1,
                'username'       => $username,
                'email'          => strtolower($username) . '@bet1x.local',
                'password'       => hash_password(bin2hex(random_bytes(24))),
                'wallet_balance' => (float) cfg('SIGNUP_BONUS'),
                'created_at'     => js_iso(),
            ];
            $users[] = $user;
            writeJsonTable('users', $users);
            return $user;
        }
        return null;
    }
}

// -------------------------------------------------------------------------------------------------
// Wallet
// -------------------------------------------------------------------------------------------------

/**
 * Debit a wallet atomically. Returns the new balance, or null when the balance was insufficient.
 *
 * The condition and the write happen in ONE statement, which is what makes concurrent bets unable
 * to spend the same balance twice. The old read-check-write pattern is a classic double-spend:
 * two simultaneous bets both read the same balance and both pass the check.
 */
function debit_wallet($userId, $amount) {
    $count = affected(
        'UPDATE "User" SET "wallet_balance" = "wallet_balance" - ? WHERE "id" = ? AND "wallet_balance" >= ?',
        [$amount, (int)$userId, $amount]
    );
    if ($count === 0) return null;
    $user = find_user_by_id($userId);
    return $user ? (float)$user['wallet_balance'] : null;
}

/** Credit a wallet atomically and return the resulting balance. */
function credit_wallet($userId, $amount) {
    q('UPDATE "User" SET "wallet_balance" = "wallet_balance" + ? WHERE "id" = ?', [$amount, (int)$userId]);
    $user = find_user_by_id($userId);
    return $user ? (float)$user['wallet_balance'] : null;
}

/** Insert a ledger row. */
function insert_transaction($id, $user, $type, $amount, $details, $status, $timestampMs = null) {
    q('INSERT INTO "Transaction" ("id","user","type","amount","details","status","timestamp") VALUES (?,?,?,?,?,?,?)',
      [$id, $user, $type, $amount, $details, $status, ms_to_sql($timestampMs)]);
}

// -------------------------------------------------------------------------------------------------
// Validation
// -------------------------------------------------------------------------------------------------

/** Reject stake amounts outside the configured table limits before any money moves. */
function validate_stake($amount) {
    $value = js_parse_float($amount);
    if (!js_is_finite($value) || $value <= 0) return ['ok' => false, 'error' => 'Invalid bet amount.'];
    $min = cfg('MIN_BET'); $max = cfg('MAX_BET');
    if ($value < $min) return ['ok' => false, 'error' => 'Minimum bet is ' . js_num_str($min) . '.'];
    if ($value > $max) return ['ok' => false, 'error' => 'Maximum bet is ' . js_num_str($max) . '.'];
    return ['ok' => true, 'value' => round2($value)];
}

/**
 * A number interpolated into a string the way JavaScript would render it: `1` not `1.0`,
 * `100000` not `100000.0`. Used in error messages that the frontend shows verbatim.
 */
function js_num_str($n) {
    if (is_float($n) && $n == floor($n) && abs($n) < 1e15) return (string)(int)$n;
    return (string)$n;
}

/**
 * Canonicalise a colour-room selection, or reject it.
 *
 * settle_color_round() and color_optimal_outcome() compare a stored bet against the canonical
 * outcome with a case-sensitive ===. Anything else — 'green', 'BIG', 'banana' — was previously
 * accepted and debited, then silently lost every round because no outcome could ever equal it.
 */
function normalize_color_selection($category, $value) {
    $cat = strtolower(trim((string)($category ?? '')));
    $raw = trim((string)($value ?? ''));

    if ($cat === 'color') {
        $map = ['green' => 'Green', 'red' => 'Red', 'violet' => 'Violet'];
        $canonical = $map[strtolower($raw)] ?? null;
        if (!$canonical) return ['ok' => false, 'error' => 'Colour must be Green, Red or Violet.'];
        return ['ok' => true, 'category' => 'color', 'value' => $canonical];
    }
    if ($cat === 'size') {
        $map = ['big' => 'Big', 'small' => 'Small'];
        $canonical = $map[strtolower($raw)] ?? null;
        if (!$canonical) return ['ok' => false, 'error' => 'Size must be Big or Small.'];
        return ['ok' => true, 'category' => 'size', 'value' => $canonical];
    }
    if ($cat === 'number') {
        if (!preg_match('/^[0-9]$/', $raw)) return ['ok' => false, 'error' => 'Number must be a single digit from 0 to 9.'];
        return ['ok' => true, 'category' => 'number', 'value' => $raw];
    }
    return ['ok' => false, 'error' => 'Bet category must be color, size or number.'];
}

/**
 * Guard for routes that have no flat-file fallback: without a database they would throw a 500 with
 * a raw driver message, so answer with an honest 503 instead.
 */
function require_database(Req $req, Res $res) {
    if (db_ready() || json_fallback_allowed()) return;
    $res->status(503)->json(['error' => 'Service temporarily unavailable. Please try again shortly.']);
}
