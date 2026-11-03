<?php
/**
 * GET/POST /api/cron/tick — the background tick over HTTP, for hosts whose cron jobs do not run.
 *
 * Off unless CRON_HTTP_KEY is set in .env (then this URL 404s like any unknown path). When it is
 * set, the caller must present that key, as ?key=... or an X-Cron-Key header; anything else gets
 * 403. Point a free scheduler (cron-job.org, UptimeRobot, ...) at
 *
 *     https://SITE/api/cron/tick?key=CRON_HTTP_KEY        every 1 minute
 *
 * The work is cron_main_tick() (lib/cron.php), the same function cron/tick.php runs, under the same
 * overlap lock — so a real cron and this URL can both be active without ever doubling a tick, and a
 * leaked key lets someone do no more than run the tick slightly more often than once a minute.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/cron.php';

function register_cron_routes(Router $app) {
    $handler = function (Req $req, Res $res) {
        $want = (string) cfg('CRON_HTTP_KEY', '');
        if ($want === '') {
            $res->status(404)->json(['error' => 'Unknown API endpoint.']);
            return;
        }
        $got = $req->header('x-cron-key') ?? $req->q('key', '');
        if (!is_string($got) || $got === '' || !hash_equals($want, $got)) {
            $res->status(403)->json(['error' => 'Forbidden.']);
            return;
        }
        if (!db_ready()) {
            $res->status(503)->json(['ok' => false, 'error' => 'Database unavailable.']);
            return;
        }
        $r = cron_main_tick();
        $res->json(['ok' => true, 'ran' => $r['ran'], 'ms' => $r['ms']]);
    };
    $app->get('/api/cron/tick', $handler);
    $app->post('/api/cron/tick', $handler);
}
