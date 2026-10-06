<?php
/**
 * Cricket feed webhook + Ball by Ball endpoints.
 *
 * Only required and registered when CRICKET_ENABLED is true (see index.php), so with the flag off
 * every URL here 404s like any unknown path.
 *
 *   POST /api/cricket/feed/webhook              Roanuz Match Via Push lands here
 *   GET  /api/cricket/bbb/matches               lobby: live, upcoming, recently finished
 *   GET  /api/cricket/bbb/:key/state            everything the game screen needs, in one poll
 *   POST /api/cricket/bbb/:key/bet              {round_id, outcome, stake}
 *   GET  /api/cricket/bbb/my-bets               the caller's bet history
 *   GET  /api/admin/cricket/health              feed health per match
 *   POST /api/admin/cricket/bbb/config          rake, limits, window
 *   POST /api/admin/cricket/bbb/rounds/:id/void refund a market by hand
 *   POST /api/admin/cricket/matches/:key/resync re-pull one match by REST
 *   POST /api/admin/cricket/matches/:key/replay rebuild a match from its archived pushes
 *
 *   Match betting (Match Odds, Bookmaker, Fancy, Cash Out):
 *   GET  /api/cricket/exchange/:key             every market, live prices, the caller's positions and bets
 *   POST /api/cricket/exchange/:key/bet         {market_key, side, selection, price | line+rate, stake}
 *   POST /api/cricket/exchange/:key/cashout     {market_key, expected}
 *   GET  /api/cricket/exchange/my-bets          the caller's match bets across every match
 *   POST /api/admin/cricket/exchange/:key/settings  {fav_side, fav_price, suspended}
 *   POST /api/admin/cricket/exchange/config     margins, limits, windows
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/cricket-feed.php';
require_once __DIR__ . '/../lib/cricket-roanuz.php';
require_once __DIR__ . '/../lib/bbb.php';
require_once __DIR__ . '/../lib/exchange.php';
if (cfg('FANTASY_LIVE')) require_once __DIR__ . '/../lib/fantasy-feed.php';

/**
 * Keep live markets moving on reads: in mock mode advance the simulation; in any mode close windows
 * that have run out and catch a stalled feed. Cheap, throttled, and never allowed to fail a read.
 */
function cricket_lazy_tick($matchKey = null) {
    try {
        cricket_mock_pump();
        if ($matchKey !== null) {
            $last = $GLOBALS['BET1X_BBB_TICKED'][$matchKey] ?? 0;
            if (now_ms() - $last > 900) {
                $GLOBALS['BET1X_BBB_TICKED'][$matchKey] = now_ms();
                bbb_process_match($matchKey);
            }
        }
    } catch (Throwable $e) {
        log_warn('cricket: lazy tick failed', ['match' => $matchKey, 'message' => $e->getMessage()]);
    }
}

function register_cricket_routes(Router $app) {

    /**
     * POST /api/cricket/feed/webhook
     *
     * Authenticated by a shared secret in the URL Roanuz is given (?secret=...) or an
     * x-roanuz-secret header — the only mechanism Roanuz's docs describe for webhooks. In production
     * a missing secret refuses every delivery rather than accepting the world's pushes.
     *
     * Always answers quickly with 200 for a delivery it has recorded (even a duplicate), because a
     * non-2xx makes Roanuz retry, and a retry of a delivery we already hold is pure load.
     */
    $app->post('/api/cricket/feed/webhook', function (Req $req, Res $res) {
        try {
            $secret = roanuz_conf()['webhook_secret'];
            $given = (string) ($req->q('secret', '') ?: $req->header('x-roanuz-secret', ''));
            if ($secret === '') {
                if (cfg('IS_PRODUCTION')) {
                    $res->status(503)->json(['error' => 'Webhook secret not configured.']);
                    return;
                }
            } elseif (!hash_equals($secret, $given)) {
                $res->status(401)->json(['error' => 'Bad secret.']);
                return;
            }
            $body = roanuz_decode_webhook_body($req->rawBody);
            if ($body === null) { $res->status(400)->json(['error' => 'Body is not JSON.']); return; }
            $out = cricket_feed_ingest($body, 'push');
            if (!$out['ok']) { $res->status(422)->json(['error' => $out['error']]); return; }
            $res->json(['ok' => true, 'match_key' => $out['match_key'], 'new_balls' => $out['new_balls'] ?? 0,
                        'duplicate' => !empty($out['duplicate'])]);
        } catch (Throwable $err) {
            fail500($res, $err, 'cricket_webhook');
        }
    });

    $app->get('/api/cricket/bbb/matches', function (Req $req, Res $res) {
        try {
            cricket_lazy_tick();
            if (function_exists('fantasy_lazy_feed_refresh')) fantasy_lazy_feed_refresh();
            $res->json(['success' => true] + bbb_list_matches() + [
                'source' => cricket_source_mode(),
                'virtual' => cricket_source_mode() === 'mock',   // simulated league: pages label it Virtual Cricket
                'server_time_ms' => now_ms(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_matches');
        }
    });

    $app->get('/api/cricket/bbb/my-bets', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $res->json(['success' => true, 'bets' => bbb_my_bets((int) $user['id'], (int) $req->q('limit', 50))]);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_my_bets');
        }
    });

    $app->get('/api/cricket/bbb/:key/state', function (Req $req, Res $res) {
        try {
            $key = (string) $req->p('key');
            cricket_lazy_tick($key);
            $userId = null;
            if ($req->auth) {
                $user = get_or_create_user(acting_username($req));
                if ($user) $userId = (int) $user['id'];
            }
            $state = bbb_match_state($key, $userId);
            if (!$state) { $res->status(404)->json(['error' => 'Match not found.']); return; }
            $res->json(['success' => true] + $state);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_state');
        }
    });

    $app->post('/api/cricket/bbb/:key/bet', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $roundId = (int) $req->b('round_id', 0);
            $round = $roundId ? one('SELECT "match_key" FROM "bbb_rounds" WHERE "id" = ?', [$roundId]) : null;
            if (!$round || $round['match_key'] !== (string) $req->p('key')) {
                $res->status(404)->json(['error' => 'This market no longer exists.']);
                return;
            }
            $r = bbb_place_bet($user, $roundId, (string) $req->b('outcome', ''), $req->b('stake', null));
            if (!$r['ok']) { $res->status((int) $r['status'])->json(['error' => $r['error']]); return; }
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_bet');
        }
    });

    // --- operator ---------------------------------------------------------------------------------

    $app->get('/api/admin/cricket/health', 'require_admin', function (Req $req, Res $res) {
        try {
            $rows = all('SELECT "match_key","title","status","stalled","last_feed_at","last_ball_at","updated_at" FROM "cricket_match_feed" '
                      . 'ORDER BY "updated_at" DESC LIMIT 30');
            $res->json(['success' => true, 'source' => cricket_source_mode(), 'config' => bbb_config(),
                        'webhook_secret_set' => roanuz_conf()['webhook_secret'] !== '', 'matches' => $rows]);
        } catch (Throwable $err) {
            fail500($res, $err, 'cricket_health');
        }
    });

    $app->post('/api/admin/cricket/bbb/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $r = bbb_config_save((array) $req->body);
            if (!$r['ok']) { $res->status(422)->json(['error' => $r['error']]); return; }
            $res->json(['success' => true, 'config' => $r['config']]);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_config');
        }
    });

    $app->post('/api/admin/cricket/bbb/rounds/:id/void', 'require_admin', function (Req $req, Res $res) {
        try {
            $r = bbb_void_round((int) $req->p('id'), 'operator_void');
            if (!$r['ok']) { $res->status(409)->json(['error' => 'That market is already settled or void.']); return; }
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'bbb_void');
        }
    });

    $app->post('/api/admin/cricket/matches/:key/resync', 'require_admin', function (Req $req, Res $res) {
        try {
            $snap = roanuz_match_snapshot((string) $req->p('key'));
            if (!$snap['ok']) { $res->status(502)->json(['error' => $snap['error']]); return; }
            $res->json(['success' => true, 'result' => cricket_feed_ingest($snap['snapshot'], 'rest')]);
        } catch (Throwable $err) {
            fail500($res, $err, 'cricket_resync');
        }
    });

    $app->post('/api/admin/cricket/matches/:key/replay', 'require_admin', function (Req $req, Res $res) {
        try {
            $res->json(['success' => true, 'result' => cricket_feed_replay((string) $req->p('key'))]);
        } catch (Throwable $err) {
            fail500($res, $err, 'cricket_replay');
        }
    });

    // --- match betting ---------------------------------------------------------------------------

    $userIdOf = function (Req $req) {
        if (!$req->auth) return null;
        $u = get_or_create_user(acting_username($req));
        return $u ? (int) $u['id'] : null;
    };

    $app->get('/api/cricket/exchange/my-bets', 'require_auth', function (Req $req, Res $res) use ($userIdOf) {
        try {
            $rows = mx_my_bets($userIdOf($req), (int) $req->q('limit', 100));
            $res->json(['success' => true, 'bets' => array_map(function ($b) {
                return ['id' => (int) $b['id'], 'match_key' => $b['match_key'], 'match_title' => $b['title'], 'market' => $b['market_name'],
                        'type' => $b['market_type'], 'selection' => $b['selection_name'], 'side' => $b['side'], 'odds' => (float) $b['odds'],
                        'rate' => (float) $b['rate'], 'line' => $b['line'] === null ? null : (int) $b['line'], 'stake' => (float) $b['stake'],
                        'liability' => (float) $b['liability'], 'profit' => (float) $b['profit'], 'status' => $b['status'],
                        'payout' => (float) $b['payout'], 'void_reason' => $b['void_reason'], 'created_ms' => sql_to_ms($b['created_at'])];
            }, $rows)]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_my_bets');
        }
    });

    $app->get('/api/cricket/exchange/:key', function (Req $req, Res $res) use ($userIdOf) {
        try {
            $key = (string) $req->p('key');
            cricket_lazy_tick($key);
            try { mx_process_match($key); } catch (Throwable $e) { log_warn('mx lazy process failed', ['m' => $e->getMessage()]); }
            $page = mx_page($key, $userIdOf($req));
            if (!$page) { $res->status(404)->json(['error' => 'Match not found.']); return; }
            $res->json(['success' => true] + $page);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_page');
        }
    });

    $app->post('/api/cricket/exchange/:key/bet', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $r = mx_place_bet($user, (string) $req->p('key'), (array) $req->body);
            if (!$r['ok']) { $res->status((int) $r['status'])->json(['error' => $r['error']]); return; }
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_bet');
        }
    });

    $app->post('/api/cricket/exchange/:key/cashout', 'require_auth', function (Req $req, Res $res) {
        try {
            $user = get_or_create_user(acting_username($req));
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }
            $r = mx_cashout($user, (string) $req->p('key'), (string) $req->b('market_key', ''), $req->b('expected', null));
            if (!$r['ok']) { $res->status((int) $r['status'])->json(['error' => $r['error']]); return; }
            $res->json(['success' => true] + $r);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_cashout');
        }
    });

    $app->post('/api/admin/cricket/exchange/:key/settings', 'require_admin', function (Req $req, Res $res) {
        try {
            $side = $req->b('fav_side', null);
            $price = $req->b('fav_price', null);
            if ($side !== null && !in_array($side, ['a', 'b'], true)) { $res->status(422)->json(['error' => 'fav_side must be a or b.']); return; }
            if ($price !== null && (!is_numeric($price) || $price < 1.01 || $price > 50)) { $res->status(422)->json(['error' => 'fav_price must be decimal odds between 1.01 and 50.']); return; }
            $cur = mx_settings((string) $req->p('key'));
            mx_settings_save((string) $req->p('key'), $side ?? $cur['fav_side'], $price ?? $cur['fav_price'], 'operator',
                             $req->b('suspended', null) === null ? null : (bool) $req->b('suspended'));
            $res->json(['success' => true, 'settings' => mx_settings((string) $req->p('key'))]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_settings');
        }
    });

    $app->post('/api/admin/cricket/exchange/config', 'require_admin', function (Req $req, Res $res) {
        try {
            $r = mx_config_save((array) $req->body);
            if (!$r['ok']) { $res->status(422)->json(['error' => $r['error']]); return; }
            $res->json(['success' => true, 'config' => $r['config']]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_config');
        }
    });

    /**
     * GET /api/cricket/exchange-lobby — live and upcoming matches with their best Match Odds prices,
     * the list a sportsbook's cricket page opens on.
     */
    $app->get('/api/cricket/exchange-lobby', function (Req $req, Res $res) {
        try {
            cricket_lazy_tick();
            if (function_exists('fantasy_lazy_feed_refresh')) fantasy_lazy_feed_refresh();
            $lists = bbb_list_matches();
            $withOdds = function ($m) {
                $m['odds'] = null;
                try {
                    if (cricket_match_feed_row($m['match_key'])) {
                        $bk = mx_markets($m['match_key']);
                        $mo = $bk['markets']['MO'] ?? null;
                        if ($mo) {
                            $m['odds'] = ['open' => $mo['open'], 'reason' => $mo['reason'], 'runners' => array_map(function ($r) {
                                return ['side' => $r['side'], 'short' => $r['short'], 'back' => $r['back'] ? $r['back'][0] : null, 'lay' => $r['lay'] ? $r['lay'][0] : null];
                            }, $mo['runners'])];
                        }
                        $m['fancy_count'] = count(array_filter($bk['markets'], function ($x) { return $x['type'] === 'FANCY'; }));
                    }
                } catch (Throwable $e) { /* a match that cannot be priced still lists */ }
                return $m;
            };
            $res->json(['success' => true, 'source' => cricket_source_mode(), 'virtual' => cricket_source_mode() === 'mock',
                        'live' => array_map($withOdds, $lists['live']), 'upcoming' => array_map($withOdds, $lists['upcoming']),
                        'completed' => $lists['completed'], 'server_time_ms' => now_ms()]);
        } catch (Throwable $err) {
            fail500($res, $err, 'mx_lobby');
        }
    });
}
