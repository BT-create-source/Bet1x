<?php
/**
 * bet1x Originals — HTTP surface. All the rules live in lib/originals.php and games/originals/*.php.
 *
 *   GET  /api/originals/catalogue              every game, its label and whether it is open
 *   GET  /api/originals/:game/state            settings, the player's open round, seeds, recent rounds
 *   POST /api/originals/:game/play             a new round        {client_ref, bet_amount | bets, ...}
 *   POST /api/originals/:game/action           next step of an open round {round_ref, step, action, ...}
 *   GET  /api/originals/seed                   the player's seed pair
 *   POST /api/originals/seed/rotate            reveal the server seed, start a new pair {client_seed?}
 *   GET  /api/admin/originals/config           operator settings for every game
 *   POST /api/admin/originals/config           {game, enabled?, rtp?, min_bet?, max_bet?, max_win?}
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/originals.php';

/** Run a handler; OrigError becomes its own status and message, anything else a 500. */
function orig_handle(Res $res, callable $fn) {
    try {
        if (!originals_ensure()) { $res->status(503)->json(['ok' => false, 'error' => 'Games are temporarily unavailable.']); return; }
        $out = $fn();
        if (!$res->sent) $res->json(['ok' => true] + (array) $out);
    } catch (OrigError $e) {
        $res->status($e->status)->json(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->errCode]);
    } catch (Throwable $e) {
        fail500($res, $e, 'originals');
    }
}

function orig_user(Req $req) {
    $user = get_or_create_user(acting_username($req));
    if (!$user) orig_fail('Account not found.', 404);
    return $user;
}

function register_originals_routes(Router $app) {

    $app->get('/api/originals/:game/public', function (Req $req, Res $res) {
        $res->setHeader('Cache-Control', 'no-store');
        orig_handle($res, function () use ($req) {
            $public = originals_shared_public((string) $req->p('game'));
            return ['clock' => $public['clock'] ?? originals_clock(), 'public_rounds' => $public];
        });
    });

    $app->get('/api/originals/clock', function (Req $req, Res $res) {
        $res->setHeader('Cache-Control', 'no-store');
        $res->json(['ok' => true, 'clock' => originals_clock()]);
    });

    $app->get('/api/originals/catalogue', function (Req $req, Res $res) {
        orig_handle($res, function () {
            $cfg = originals_config_all();
            $games = [];
            foreach (originals_catalogue() as $k => $g) {
                $games[] = ['key' => $k, 'label' => $g['label'], 'tag' => $g['tag'], 'enabled' => $cfg[$k]['enabled'],
                            'rtp' => $cfg[$k]['rtp'], 'page' => $k . '.html'];
            }
            return ['games' => $games];
        });
    });

    $app->get('/api/originals/seed', 'require_auth', function (Req $req, Res $res) {
        orig_handle($res, function () use ($req) { return ['seed' => originals_seed_info(orig_user($req)['username'])]; });
    });

    $app->post('/api/originals/seed/rotate', 'require_auth', function (Req $req, Res $res) {
        orig_handle($res, function () use ($req) {
            $cs = $req->b('client_seed');
            return ['seed' => originals_rotate_seed(orig_user($req)['username'], is_string($cs) ? trim($cs) : null)];
        });
    });

    $app->get('/api/originals/:game/state', function (Req $req, Res $res) {
        $res->setHeader('Cache-Control', 'no-store');
        orig_handle($res, function () use ($req) {
            $user = $req->auth ? orig_user($req) : null;
            return originals_state($user, (string) $req->p('game'));
        });
    });

    $app->post('/api/originals/:game/play', 'require_auth', function (Req $req, Res $res) {
        orig_handle($res, function () use ($req) {
            return originals_play(orig_user($req), (string) $req->p('game'), (array) $req->body);
        });
    });

    $app->post('/api/originals/:game/action', 'require_auth', function (Req $req, Res $res) {
        orig_handle($res, function () use ($req) {
            return originals_action(orig_user($req), (string) $req->p('game'), (array) $req->body);
        });
    });

    $app->get('/api/admin/originals/config', 'require_admin', function (Req $req, Res $res) {
        orig_handle($res, function () {
            $out = [];
            $cfg = originals_config_all();
            foreach (originals_catalogue() as $k => $g) {
                $out[] = ['key' => $k, 'label' => $g['label'], 'rtp_fixed' => $g['rtp'] === null] + $cfg[$k];
            }
            return ['games' => $out];
        });
    });

    $app->post('/api/admin/originals/config', 'require_admin', function (Req $req, Res $res) {
        orig_handle($res, function () use ($req) {
            $patch = [];
            foreach (['enabled', 'rtp', 'min_bet', 'max_bet', 'max_win'] as $k) {
                if ($req->b($k) !== null) $patch[$k] = $req->b($k);
            }
            $r = originals_config_update((string) $req->b('game'), $patch);
            if (!$r['ok']) orig_fail($r['error']);
            return ['config' => $r['config']];
        });
    });
}
