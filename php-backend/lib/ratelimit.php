<?php
/**
 * Rate limiting — the PHP equivalent of express-rate-limit, with the same windows, the same
 * limits, the same 429 bodies and the same headers.
 *
 * express-rate-limit counts every request (skipSuccessfulRequests is not set anywhere in
 * server.js), keys on the client IP, and rejects once the count EXCEEDS max — so a limiter with
 * max 20 rejects the 21st request in the window. Both of those are reproduced.
 *
 * `standardHeaders: true` and `legacyHeaders: false` in the original mean the draft
 * RateLimit-Limit / RateLimit-Remaining / RateLimit-Reset headers are sent and the older
 * X-RateLimit-* ones are not.
 *
 * The store is a table rather than process memory, which is the only way several PHP workers can
 * share a count at all. One consequence worth stating: the Node build's counters reset when the
 * process restarted, and these do not — a limit is now genuinely enforced across a deploy.
 *
 * If the database is unreachable the limiter FAILS OPEN. That matches the Node build, where an
 * in-memory store could not fail, and it is the right trade here: refusing every login because the
 * counter table is unavailable would turn a database blip into a total outage.
 */

require_once __DIR__ . '/db.php';

/** The five limiters, exactly as configured in server.js. */
function rate_limiters() {
    return [
        // Credential endpoints get a much tighter budget because they are worth brute-forcing.
        'auth' => [
            'windowMs' => 15 * 60 * 1000,
            'max'      => 20,
            'message'  => ['error' => 'Too many authentication attempts. Please try again in a few minutes.'],
            'skip'     => 'testing',
        ],
        'api' => [
            'windowMs' => 60 * 1000,
            'max'      => 600,
            'message'  => ['error' => 'Too many requests. Please slow down.'],
            // Polling-heavy game loops make this noisy in development, so the original skips it
            // entirely outside production. Reproduced, including the environment condition.
            'skip'     => 'non_production',
        ],
        'wallet' => [
            'windowMs' => 60 * 1000,
            'max'      => 120,
            'message'  => ['error' => 'Too many wallet operations. Please slow down.'],
            'skip'     => 'testing',
        ],
        'chat' => [
            'windowMs' => 60 * 1000,
            'max'      => 20,
            'message'  => ['error' => 'You are sending messages too quickly.'],
            'skip'     => 'testing',
        ],
        // Per-IP brake on verification codes. The per-phone cooldown and daily cap live in
        // lib/otp.php; this is the other axis — one attacker walking through many numbers, each of
        // which would pass its own per-phone check while still costing money on every send.
        'otp' => [
            'windowMs' => 60 * 60 * 1000,
            'max'      => 10,
            'message'  => ['error' => 'Too many verification requests. Please try again later.'],
            'skip'     => 'testing',
        ],
        'cashier' => [
            'windowMs' => 60 * 1000,
            'max'      => 10,
            'message'  => ['error' => 'Too many cashier requests. Please wait a moment.'],
            'skip'     => 'testing',
        ],
    ];
}

function rate_limit_should_skip($skip) {
    if ($skip === 'testing')        return (bool) cfg('DISABLE_RATE_LIMITS');
    if ($skip === 'non_production') return !cfg('IS_PRODUCTION');
    return false;
}

/**
 * Apply one limiter. Writes a 429 and returns true when the request should be rejected.
 * Used as middleware: `function ($req, $res) { rate_limit('auth', $req, $res); }`
 */
function rate_limit($bucket, Req $req, Res $res) {
    $limiters = rate_limiters();
    if (!isset($limiters[$bucket])) return false;
    $conf = $limiters[$bucket];

    if (rate_limit_should_skip($conf['skip'])) return false;
    if (!db_ready()) return false;   // fail open — see the file header

    $now = now_ms();
    $windowStart = (int) (floor($now / $conf['windowMs']) * $conf['windowMs']);
    $client = $req->ip;

    try {
        q('INSERT INTO "RateLimit" ("bucket","client","window_start","hits") VALUES (?,?,?,1)
           ON CONFLICT ("bucket","client","window_start") DO UPDATE SET "hits" = "RateLimit"."hits" + 1',
          [$bucket, $client, $windowStart]);
        $hits = (int) scalar('SELECT "hits" FROM "RateLimit" WHERE "bucket" = ? AND "client" = ? AND "window_start" = ?',
                             [$bucket, $client, $windowStart], 0);
    } catch (Throwable $e) {
        return false;   // fail open
    }

    $resetSeconds = (int) ceil((($windowStart + $conf['windowMs']) - $now) / 1000);
    $remaining = max(0, $conf['max'] - $hits);

    $res->setHeader('RateLimit-Limit', (string)$conf['max']);
    $res->setHeader('RateLimit-Remaining', (string)$remaining);
    $res->setHeader('RateLimit-Reset', (string)$resetSeconds);

    if ($hits > $conf['max']) {
        $res->setHeader('Retry-After', (string)$resetSeconds);
        $res->status(429)->json($conf['message']);
        return true;
    }
    return false;
}

/** Middleware factory, so a limiter can be dropped into a route's handler chain. */
function limiter($bucket) {
    return function (Req $req, Res $res) use ($bucket) { rate_limit($bucket, $req, $res); };
}

/** Drop counters from windows that can no longer be current. Called by the cron. */
function rate_limit_sweep() {
    try { q('DELETE FROM "RateLimit" WHERE "window_start" < ?', [now_ms() - (16 * 60 * 1000)]); }
    catch (Throwable $e) {}
}
