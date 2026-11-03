<?php
/**
 * The one-minute cron tick.
 *
 * =================================================================================================
 * WHY THIS FILE EXISTS
 * =================================================================================================
 * Most of the timer work in this port is lazy: whichever request touches a game advances it. That
 * covers everything a player can see, because every game page polls (Aviator 250 ms, Colour 300 ms,
 * Teen Patti 2 s). What it does NOT cover is a site with nobody on it:
 *
 *   - an empty Teen Patti lobby has nothing polling it, so simulated traffic would never fill a
 *     room and no table would ever start;
 *   - a colour round that ends while the site is idle would not settle until the next visitor;
 *   - Aviator would sit in whatever phase it was in when the last player left.
 *
 * This tick does exactly the same work those requests would have done, so an idle site keeps
 * running. It is deliberately the SAME functions, not a parallel implementation — there is no
 * second copy of any game rule here to drift out of step.
 *
 * INSTALL (cPanel -> Cron Jobs), every minute:
 *
 *     * * * * * /usr/local/bin/php /home/USER/public_html/php-backend/cron/tick.php >/dev/null 2>&1
 *
 * Adjust the PHP path and document root to match the account. Running it more often than once a
 * minute is unnecessary; running it less often means an unwatched lobby fills more slowly.
 *
 * The whole tick is wrapped in a named lock, so two overlapping runs (a slow tick plus the next
 * one) cannot both advance the same round.
 * =================================================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

require_once __DIR__ . '/../config.php';
date_default_timezone_set((string) env_get('APP_TIMEZONE', 'UTC'));

require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/rigaudit.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../games/color.php';
require_once __DIR__ . '/../games/aviator.php';
require_once __DIR__ . '/../games/teenpatti.php';
require_once __DIR__ . '/../games/mines.php';
require_once __DIR__ . '/../games/astronaut.php';

require_once __DIR__ . '/../lib/cron.php';

if (!db_ready()) {
    log_error('cron: database unreachable, tick skipped');
    exit(1);
}

// The work, the overlap lock and the heartbeat live in lib/cron.php, shared with /api/cron/tick.
cron_main_tick();
