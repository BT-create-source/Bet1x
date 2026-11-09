<?php
/** Real development database, isolated accounts; php -d extension=pdo_pgsql php-backend/tests/originals_public.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/originals.php';
set_exception_handler(function ($e) { fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); exit(1); });
if (cfg('IS_PRODUCTION')) { fwrite(STDERR, "Refusing money tests in production.\n"); exit(1); }
if (!originals_ensure()) throw new RuntimeException('Development database unavailable');
$checks = 0;
function pub_check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function pub_body($game, $body, $who) {
    if ($who !== 'b') return $body;
    if ($game === 'dice') $body['direction'] = 'over';
    if ($game === 'keno') $body['picks'] = [10,11,12];
    if ($game === 'roulette') $body['bets'][0]['type'] = 'black';
    if ($game === 'dragontiger') $body['bets'][0]['type'] = 'tiger';
    if ($game === 'baccarat') $body['bets'][0]['type'] = 'banker';
    if ($game === 'andarbahar') $body['bets'][0]['type'] = 'bahar';
    if ($game === 'matka') $body['bets'][0]['value'] = '8';
    return $body;
}
function pub_event($game, $result) {
    $keys = ['dice'=>['roll'], 'wheel'=>['index'], 'plinko'=>['path','bucket'], 'keno'=>['drawn'],
        'roulette'=>['number','color'], 'baccarat'=>['player','banker','winner'], 'dragontiger'=>['dragon','tiger','winner'],
        'andarbahar'=>['joker','andar','bahar','winner'], 'slots'=>['grid'], 'matka'=>['display']];
    return array_intersect_key($result, array_flip($keys[$game]));
}
$bodies = [
    'dice' => ['bet_amount' => 10, 'chance' => 50, 'direction' => 'under'],
    'wheel' => ['bet_amount' => 10, 'risk' => 'medium', 'segments' => 10],
    'plinko' => ['bet_amount' => 10, 'risk' => 'medium', 'rows' => 16],
    'keno' => ['bet_amount' => 10, 'picks' => [1,2,3]],
    'roulette' => ['bets' => [['type' => 'red', 'amount' => 10]]],
    'baccarat' => ['bets' => [['type' => 'player', 'amount' => 10]]],
    'dragontiger' => ['bets' => [['type' => 'dragon', 'amount' => 10]]],
    'andarbahar' => ['bets' => [['type' => 'andar', 'amount' => 10]]],
    'slots' => ['bet_amount' => 10],
    'matka' => ['bets' => [['type' => 'single', 'value' => '7', 'amount' => 10]]],
];
$users = []; $bets = [];
try {
    foreach (['a','b','spectator'] as $suffix) {
        $user = get_or_create_user('pubtest_' . bin2hex(random_bytes(5)) . $suffix, true);
        $users[$suffix] = $user;
        q('UPDATE "User" SET "wallet_balance" = 10000 WHERE "id" = ?', [$user['id']]);
    }
    foreach ($bodies as $game => $body) {
        $public = originals_state(null, $game)['public_rounds'];
        pub_check(is_array($public) && isset($public['current']['server_seed_hash']), "$game guest commitment");
        pub_check(!isset($public['current']['server_seed'], $public['current']['result']), "$game future result hidden");
    }
    echo "Public commitments initialized for all ten games. Waiting for a full betting window...\n";
    while (($clock = originals_clock())['phase'] !== 'betting' || $clock['betting_closes_at'] - $clock['server_time'] < 12000) usleep(100000);
    $roundId = $clock['round_id'];
    foreach ($bodies as $game => $body) {
        foreach (['a','b'] as $who) {
            $request = pub_body($game, $body, $who) + ['client_ref' => 'public_' . bin2hex(random_bytes(8)), 'round_id' => $roundId];
            $out = originals_play($users[$who], $game, $request);
            $bets[$game][$who] = $out['round'];
            pub_check($out['round']['status'] === 'queued' && $out['round']['result'] === null && !isset($out['round']['server_seed']), "$game bet waits without leaking result");
            $replay = originals_play($users[$who], $game, $request);
            pub_check($replay['replayed'] && $replay['balance'] === $out['balance'], "$game idempotent pending bet");
        }
        pub_check($bets[$game]['a']['server_seed_hash'] === $bets[$game]['b']['server_seed_hash'], "$game same seed for both users");
    }
    echo "Accepted both players' bets for all ten games. Waiting for the public reveal...\n";
    while (originals_clock()['server_time'] < $clock['betting_closes_at']) usleep(100000);
    foreach ($bodies as $game => $body) {
        // A guest triggers settlement; neither bettor needs to be connected.
        $guest = originals_state(null, $game);
        $spectator = originals_state($users['spectator'], $game);
        pub_check($guest['public_rounds'] === $spectator['public_rounds'] || $guest['public_rounds']['history'] === $spectator['public_rounds']['history'], "$game anonymous and signed-in spectators see identical results");
        $published = array_values(array_filter($guest['public_rounds']['history'], function ($r) use ($roundId) { return $r['round_id'] === $roundId; }))[0];
        pub_check(hash('sha256', $published['server_seed']) === $published['server_seed_hash'], "$game revealed seed matches commitment");
        $settled = [];
        foreach (['a','b'] as $who) {
            $state = originals_state($users[$who], $game);
            $r = $state['recent'][0]; $settled[$who] = $r;
            pub_check($r['status'] === 'settled' && $r['shared_round_id'] === $roundId, "$game settlement has shared round identity");
            $cfg = originals_config($game);
            $prep = call_user_func('orig_' . $game . '_prepare', pub_body($game, $body, $who), $cfg);
            $expected = call_user_func('orig_' . $game . '_play', $prep['stake'], $prep['params'], new OrigRng($published['server_seed'], $published['client_seed'], $roundId), $cfg);
            pub_check($r['result'] == $expected['result'] && abs($r['payout'] - orig_cap($expected['payout'], $cfg)) < .005, "$game accepted bet uses the public draw");
        }
        pub_check(pub_event($game, $settled['a']['result']) === pub_event($game, $settled['b']['result']) && pub_event($game, $published['result']) === pub_event($game, $settled['a']['result']), "$game same public event despite different selections");
        originals_shared_public($game); // Retry settlement: no duplicate credits.
        pub_check((int) scalar('SELECT COUNT(*) FROM "OriginalsRound" WHERE "game" = ? AND "username" = ?', [$game, $users['a']['username']]) === 1, "$game one accepted round");
        echo "PASS $game: two bettors, a non-bettor and a guest share the same result\n";
    }
    foreach ($users as $who => $user) {
        $staked = (float) scalar('SELECT COALESCE(SUM("bet_amount"),0) FROM "OriginalsRound" WHERE "username" = ?', [$user['username']]);
        $paid = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "OriginalsRound" WHERE "username" = ?', [$user['username']]);
        pub_check(abs(orig_balance($user['id']) - (10000 - $staked + $paid)) < .005, "$who wallet reconciles");
        $credits = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = \'Deposit\'', [$user['username']]);
        pub_check(abs($credits - $paid) < .005, "$who ledger reconciles with no duplicate payouts");
        if ($who === 'spectator') pub_check($staked === 0.0 && $paid === 0.0, 'spectator sees results without financial records');
    }
    pub_check(!originals_is_shared('blackjack') && !originals_is_shared('coinflip'), 'interactive games remain personal');
    pub_check(originals_clock()['betting_closes_at'] - originals_clock()['betting_opens_at'] === 15000, '15-second betting window');
    echo "PASS $checks public-round integration checks\n";
} finally {
    foreach ($users as $u) {
        q('DELETE FROM "OriginalsRound" WHERE "username" = ?', [$u['username']]);
        q('DELETE FROM "OriginalsSeed" WHERE "username" = ?', [$u['username']]);
        q('DELETE FROM "Transaction" WHERE "user" = ?', [$u['username']]);
        q('DELETE FROM "User" WHERE "id" = ?', [$u['id']]);
    }
}
