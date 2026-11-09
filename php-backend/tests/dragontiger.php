<?php
/** php -d extension=pdo_pgsql php-backend/tests/dragontiger.php [--math-only] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/originals.php';
originals_game('dragontiger');
$checks = 0;
function dt_check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function dt_reject(callable $fn, $code = null) {
    try { $fn(); } catch (OrigError $e) { dt_check($code === null || $e->errCode === $code, 'rejection ' . $code); return; }
    throw new RuntimeException('Expected request to be rejected: ' . $code);
}
$cfg = ['rtp' => 25 / 26, 'max_win' => 1000000];
$totals = ['dragon' => 0, 'tiger' => 0, 'tie' => 0];
$wins = ['dragon' => 0, 'tiger' => 0, 'tie' => 0];
for ($d = 0; $d < 52; $d++) for ($t = 0; $t < 52; $t++) {
    $dc = orig_card($d); $tc = orig_card($t);
    foreach (array_keys($totals) as $side) {
        $out = orig_dragontiger_settle([['type' => $side, 'amount' => 1]], $dc, $tc);
        $totals[$side] += $out['payout'];
        dt_check($out['result']['winner'] === ($dc['r'] > $tc['r'] ? 'dragon' : ($dc['r'] < $tc['r'] ? 'tiger' : 'tie')), 'rank winner');
        if ($dc['r'] === $tc['r']) dt_check($out['payout'] === ($side === 'tie' ? 12.0 : 0.5), 'tie settlement');
    }
    $wins[$out['result']['winner']]++;
}
foreach ($totals as $side => $returned) {
    dt_check(abs($returned / 2704 - ($side === 'tie' ? 12 / 13 : 25 / 26)) < 1e-10, "$side exact RTP");
}
dt_check($wins['tie'] === 208 && $wins['dragon'] === $wins['tiger'], 'independent-card probabilities');
$prep = orig_dragontiger_prepare(['bets' => [['type' => 'dragon', 'amount' => 10], ['type' => 'dragon', 'amount' => 5], ['type' => 'tie', 'amount' => 2]]], $cfg);
dt_check($prep['stake'] === 17.0 && count($prep['params']['bets']) === 2, 'merge chip selections and stake');
$mixed = orig_dragontiger_settle($prep['params']['bets'], orig_card(0), orig_card(1));
dt_check($mixed['payout'] === 31.5, 'mixed tied bets return 15/2 plus 2*12');
foreach ([[], [['type' => 'draw', 'amount' => 10]], [['type' => 'dragon', 'amount' => -1]], [['type' => 'tie', 'amount' => 0]]] as $bets) {
    dt_reject(function () use ($bets, $cfg) { orig_dragontiger_prepare(['bets' => $bets], $cfg); });
}
for ($nonce = 0; $nonce < 200; $nonce++) {
    $rng = new OrigRng('dt-test-server', 'dt-test-client', $nonce);
    $round = orig_dragontiger_play(17, $prep['params'], $rng, $cfg);
    $dragon = orig_card((int) floor(orig_float_at('dt-test-server', 'dt-test-client', $nonce, 0) * 52));
    $tiger = orig_card((int) floor(orig_float_at('dt-test-server', 'dt-test-client', $nonce, 1) * 52));
    dt_check($round === orig_dragontiger_settle($prep['params']['bets'], $dragon, $tiger), 'seed reproduces cards and payout');
    dt_check($rng->index() === 2, 'exactly two fairness floats');
}
dt_check(originals_classify('Dragon Tiger Bet Round ORTEST')['game'] === 'dragontiger', 'stake classification');
dt_check(originals_classify('Dragon Tiger Win x2 Round ORTEST')['kind'] === 'win', 'win classification');
echo "PASS $checks Dragon Tiger math, validation and fairness checks\n";
if (in_array('--math-only', $argv, true)) exit;
if (cfg('IS_PRODUCTION')) { fwrite(STDERR, "Refusing wallet tests in production.\n"); exit(1); }
if (!originals_ensure()) throw new RuntimeException('Development database unavailable');
$name = 'dttest_' . bin2hex(random_bytes(6));
$user = get_or_create_user($name, true);
if (!$user) throw new RuntimeException('Could not create isolated test account');
try {
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [10000, $user['id']]);
    $body = ['client_ref' => 'dt_' . bin2hex(random_bytes(10)), 'bets' => [['type' => 'dragon', 'amount' => 10], ['type' => 'tie', 'amount' => 10]]];
    echo "Checking live betting windows and isolated development wallet...\n";
    // Ensure sufficient time to exercise the transaction inside the real shared window.
    while (($clock = originals_clock())['phase'] !== 'betting' || $clock['betting_closes_at'] - $clock['server_time'] < 2000) usleep(100000);
    $body['round_id'] = $clock['round_id'];
    $out = originals_play($user, 'dragontiger', $body);
    dt_check($out['round']['status'] === 'queued' && $out['round']['result'] === null, 'accepted bet hides result until close');
    dt_check(abs($out['balance'] - (10000 - $out['round']['bet'] + $out['round']['payout'])) < .005, 'wallet follows result');
    $replay = originals_play($user, 'dragontiger', $body);
    dt_check($replay['replayed'] && $replay['balance'] === $out['balance'], 'duplicate request never debits twice');
    $history = originals_state(find_user_by_id($user['id']), 'dragontiger');
    dt_check(count($history['pending']) === 1 && !$history['recent'], 'accepted deal waits for public reveal');
    dt_check(isset($history['clock'], $history['info']['rtp_by_bet']['tie']), 'state includes timer and per-bet returns');
    $bad = $body; $bad['client_ref'] = 'dt_' . bin2hex(random_bytes(10)); $bad['round_id']--;
    dt_reject(function () use ($user, $bad) { originals_play($user, 'dragontiger', $bad); }, 'stale_round');
    while (originals_clock()['phase'] === 'betting') usleep(100000);
    $history = originals_state(find_user_by_id($user['id']), 'dragontiger');
    dt_check(count($history['recent']) === 1 && $history['recent'][0]['round_ref'] === $out['round']['round_ref'], 'settled history includes deal');
    $out = ['round' => $history['recent'][0], 'balance' => $history['balance']];
    $late = $body; $late['client_ref'] = 'dt_' . bin2hex(random_bytes(10)); unset($late['round_id']);
    dt_reject(function () use ($user, $late) { originals_play($user, 'dragontiger', $late); }, 'betting_closed');
    $replay = originals_play($user, 'dragontiger', $body);
    dt_check($replay['replayed'] && $replay['balance'] === $out['balance'], 'retry remains safe after betting closes');
    dt_check((int) scalar('SELECT COUNT(*) FROM "OriginalsRound" WHERE "username" = ?', [$name]) === 1, 'rejected bets create no rounds');
    $debits = (float) scalar('SELECT COALESCE(SUM("amount"), 0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$name, 'Withdrawal']);
    $credits = (float) scalar('SELECT COALESCE(SUM("amount"), 0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$name, 'Deposit']);
    dt_check(abs($debits - $out['round']['bet']) < .005 && abs($credits - $out['round']['payout']) < .005, 'ledger reconciles with round');
    dt_check(abs(orig_balance($user['id']) - $out['balance']) < .005, 'rejected bets do not change wallet');
    echo "PASS isolated development wallet, ledger, history, replay and betting-window checks ($checks checks total)\n";
} finally {
    q('DELETE FROM "OriginalsRound" WHERE "username" = ?', [$name]);
    q('DELETE FROM "OriginalsSeed" WHERE "username" = ?', [$name]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$name]);
    q('DELETE FROM "User" WHERE "id" = ?', [$user['id']]);
}
