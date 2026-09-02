<?php
/**
 * bet1x — front controller.
 *
 * Every /api/* request on the site arrives here, rewritten by the root .htaccess. This file is the
 * direct counterpart of the middleware stack and route registrations in backend/server.js, and it
 * follows that file's ORDER exactly, because in Express the order of registration is part of the
 * authorisation model rather than a stylistic choice:
 *
 *   - The six /api/db/users/* and GET /api/db/transactions aliases are registered BEFORE the
 *     /api/db admin gate, and deliberately escape it. Register them after and every player is
 *     locked out of login.
 *   - POST /api/db/:table/sync is registered AFTER /api/db/state/:key, so a request for
 *     /api/db/state/sync reaches the state handler, as it does in Express.
 *   - The /api catch-all 404 is registered last, so it only answers paths nothing else claimed.
 *
 * If you add a route, add it in the position its Node counterpart occupies. Do not sort this list.
 */

// -------------------------------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------------------------------

// Errors go to the log, never to the response body: a warning printed before the JSON would corrupt
// every response on the site.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// The one-time ?migrate= hook that lived here has been REMOVED, along with tools/web-migrate.php.
// It existed only to create the schema over HTTPS on a host with no shell access, and it did that
// job on 2026-09-02 (42 statements from sql/schema-postgres.sql, 3 from migration-002). Leaving it
// in place would have left an endpoint that executes DDL against the live money database behind
// nothing but a token that had already been written down in several places. Re-create the schema,
// if it is ever needed again, by running the .sql files against the database directly.

/**
 * Timezone.
 *
 * This is not cosmetic. The colour-prediction history and /api/db/recent-results both serialise
 * their timestamps with the JavaScript equivalent of toLocaleTimeString — a bare HH:MM:SS in the
 * SERVER's local zone, with no date and no offset — while round ids are computed in UTC. That mixed
 * time base is faithful to the Node build, and it means this setting has to match whatever zone the
 * Node process was running in, or the history strings shift by hours. Set APP_TIMEZONE in .env to
 * the same zone; UTC is the safe default.
 */
date_default_timezone_set((string) env_get('APP_TIMEZONE', 'UTC'));

require_once __DIR__ . '/lib/json.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/rigaudit.php';
require_once __DIR__ . '/lib/botengine.php';

require_once __DIR__ . '/games/color.php';
require_once __DIR__ . '/games/aviator.php';
require_once __DIR__ . '/games/teenpatti.php';
require_once __DIR__ . '/games/mines.php';

require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/wallet.php';
require_once __DIR__ . '/routes/chat.php';
require_once __DIR__ . '/routes/dbgateway.php';
require_once __DIR__ . '/routes/admin.php';
require_once __DIR__ . '/routes/bot.php';
require_once __DIR__ . '/routes/gamesync.php';
require_once __DIR__ . '/routes/teenpatti.php';
require_once __DIR__ . '/routes/mines.php';
require_once __DIR__ . '/routes/legacy.php';

// -------------------------------------------------------------------------------------------------
// Request / response
// -------------------------------------------------------------------------------------------------

$req = Req::capture();
$res = new Res();
$startedAt = microtime(true);

try {
    // --- middleware stack, in server.js's order ---

    // Terminate plaintext HTTP at the edge (needs TRUST_PROXY set alongside).
    force_https($req, $res);
    if (!$res->sent) security_headers($req, $res);          // helmet
    if (!$res->sent) cors_middleware($req, $res);           // cors (answers OPTIONS itself)
    if (!$res->sent) attach_session($req, $res);            // populates $req->auth, never rejects

    if (!$res->sent) {
        $app = new Router();

        // The global /api limiter. Note this is skipped entirely outside production, exactly as the
        // original does — polling-heavy game loops make it noisy in development.
        $app->useMw('/api', limiter('api'));

        // 1. Health, readiness, auth  (registers the /api/db/users/{status,login,signup} aliases)
        register_auth_routes($app);
        // 2. Wallet  (registers /api/db/users/{adjust-balance,reset-balance} and GET /api/db/transactions)
        register_wallet_routes($app);
        // 3. Chat
        register_chat_routes($app);
        // 4. The /api/db admin gate, then the raw table gateway
        register_db_gateway_routes($app);
        // 5. The /api/admin gate, then operator analytics
        register_admin_routes($app);
        // 6. House-edge status and decision endpoints
        register_bot_routes($app);
        // 7. Server clock, Colour Prediction, Aviator, and the legacy wallet/auth proxies
        register_gamesync_routes($app);
        // 8. Bulk table sync — after /api/db/state/:key so the more specific route wins first
        register_db_sync_route($app);
        // 9. Teen Patti
        register_teenpatti_routes($app);
        // 10. Mines
        register_mines_routes($app);
        // 11. The PHP-shaped legacy endpoints
        register_legacy_routes($app);
        // 12. Terminal /api 404 — must stay last
        register_fallback_routes($app);

        $app->dispatch($req, $res);
    }

    if (!$res->sent) {
        // Only reachable for a non-/api path, which normally never gets here because .htaccess only
        // rewrites /api/*. Mirrors the Node build's SPA fallback: 404 status, index.html body.
        $res->status(404)->sendFile(cfg('STATIC_ROOT') . '/index.html');
        if (!$res->sent) $res->status(404)->send('Not found');
    }
} catch (Throwable $err) {
    // Terminal error handler. Internal failures are logged in full but the client only ever sees a
    // generic message in production — raw exception messages leak schema, file paths and query text.
    $status = 500;
    log_error('unhandled error', [
        'path'    => $req->path,
        'method'  => $req->method,
        'message' => $err->getMessage(),
        'stack'   => $err->getTraceAsString(),
    ]);
    if (!$res->sent) {
        $res->status($status)->json([
            'error' => cfg('IS_PRODUCTION') ? 'Internal server error.' : ($err->getMessage() ?: 'Request failed.'),
        ]);
    }
}

// One log line per API request once the response is finished. Static assets never reach this file.
if (strpos($req->path, '/api/') === 0) {
    log_debug('request', [
        'method' => $req->method,
        'path'   => $req->path,
        'ms'     => (int) round((microtime(true) - $startedAt) * 1000),
        'user'   => $req->auth ? ($req->auth['username'] ?? null) : null,
    ]);
}
