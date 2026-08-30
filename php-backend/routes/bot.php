<?php
/**
 * House-edge status and decision endpoints.
 *
 * Both are operator-facing in effect: /api/bot_status describes exactly when the house will next
 * take a round, which is why it requires an admin token — exposing it publicly told any player
 * precisely which round was going to be rigged against them.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/botengine.php';

function register_bot_routes(Router $app) {

    /**
     * Peek at the next slot in a ledger's bag WITHOUT drawing it. Games draw for real when they
     * actually resolve, via should_bot_rig_this_round().
     *
     * `counter` stays in the response for any existing consumer of this diagnostic field: it means
     * "decisions already drawn from the current 100-slot cycle". Games that keep one ledger per
     * room (Colour Prediction) need ?room= to peek at the right cycle; without it this reports the
     * game-level ledger, which for those games is not the one any round actually draws from.
     */
    $app->get('/api/bot_status/:gameKey', 'require_admin', function (Req $req, Res $res) {
        $gameKey = (string)($req->p('gameKey') ?? '');
        $bot = bot_takeover_active($gameKey);

        if (!$bot['active']) {
            $res->json(['active' => false, 'shouldRig' => false, 'profit_pct' => 0, 'source' => 'none']);
            return;
        }

        $pct = js_truthy($bot['profit_pct']) ? $bot['profit_pct'] : 90;
        $roomQ = $req->q('room');
        $room = (is_string($roomQ) && $roomQ !== '') ? $roomQ : null;
        $ledgerKey = $room !== null ? ($gameKey . ':' . $room) : $gameKey;

        $bag = ensure_bot_rig_bag($ledgerKey, $pct);
        $shouldRig = count($bag['queue']) > 0 ? (bool)$bag['queue'][count($bag['queue']) - 1] : false;
        $counter = 100 - count($bag['queue']);

        $res->json([
            'active'           => true,
            'shouldRig'        => $shouldRig,
            'profit_pct'       => $pct,
            'source'           => $bot['source'],
            'ledger'           => $ledgerKey,
            'counter'          => $counter,
            'total_decisions'  => (int)$bag['totalDecisions'],
            'total_rigged'     => (int)$bag['totalRigged'],
            'last_decision_at' => $bag['lastDecisionAt'],
            'last_rigged_at'   => $bag['lastRiggedAt'],
        ]);
    });

    /**
     * Draw a decision — call once per round resolution.
     *
     * When a username is supplied, the decision is based on whether THAT specific user is currently
     * part of the bot's randomly-selected live-player subset, rather than an anonymous per-round
     * counter, so two simultaneous callers get independent decisions.
     */
    $app->post('/api/bot_decide/:gameKey', 'require_auth', function (Req $req, Res $res) {
        $gameKey = (string)($req->p('gameKey') ?? '');
        $username = acting_username($req);

        if (js_truthy($username) && in_array($gameKey, live_user_games(), true)) {
            mark_user_active($gameKey, $username);
            $bot = bot_takeover_active($gameKey);
            $targeted = is_user_targeted($gameKey, $username);
            $shouldRig = $bot['active'] && $targeted;
            $res->json([
                'shouldRig'  => $shouldRig,
                'was_rigged' => $shouldRig,
                'targeted'   => $targeted,
                'profit_pct' => $bot['profit_pct'],
                'active'     => $bot['active'],
                'source'     => $bot['source'],
            ]);
            return;
        }

        $decision = should_bot_rig_this_round($gameKey);
        $res->json(array_merge($decision, ['was_rigged' => $decision['shouldRig']]));
    });
}
