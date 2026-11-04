<?php
/**
 * bet1x Originals — maths, the real HTTP API and money races, against the REAL database.
 *
 *     php -d extension=pdo_pgsql -d extension=curl php-backend/test_originals.php [--quick]
 *     (MySQL: set DATABASE_URL=mysql://... or DB_DRIVER=mysql first; the spawned servers inherit it.)
 *
 * 1. Maths: every pay table's EXACT expected return is at the configured RTP or just under it
 *    (never over), for every option of every game; the fixed-rule games return what their rules say.
 * 2. Simulation: many thousands of rounds through each game's real play() code land on that exact
 *    return (catches dealing / drawing / path bugs the formulas cannot see).
 * 3. Blackjack rules on hand-built situations, and a basic-strategy simulation.
 * 4. Over HTTP on four servers: every game played through the endpoints the pages call, every wallet
 *    reconciled with its ledger to the paisa, double-click and parallel-request races, multi-step
 *    rounds, seed rotation (and re-deriving every round from the revealed seed), operator settings,
 *    player history.
 *
 * Writes to whatever php-backend/.env (or DATABASE_URL) points at — use a development database.
 * Touches only its own ortest_* accounts and their rows, and removes them at the end.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
if (cfg('IS_PRODUCTION')) { fwrite(STDERR, "Refusing to run money tests in production.\n"); exit(1); }
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'originals'] as $f) require_once __DIR__ . "/lib/$f.php";
foreach (array_keys(originals_catalogue()) as $g) originals_game($g);

$QUICK = in_array('--quick', $argv, true);
$pass = 0; $fail = 0; $failures = [];
function check($cond, $label) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function near($a, $b, $eps = 0.005) { return abs((float) $a - (float) $b) < $eps; }
function section($t) { echo "\n== $t ==\n"; }
function pct($x) { return sprintf('%.3f%%', $x * 100); }
/** Exact return is the target or just below it, never above. */
function on_target($ev, $rtp, $slack) { return $ev <= $rtp + 1e-9 && $ev >= $rtp - $slack; }

// =================================================================================================
section('1. Exact maths: every table returns the RTP (never more)');
// =================================================================================================
foreach ([[2, 'under'], [49.5, 'over'], [98, 'under'], [33.33, 'over']] as [$ch, $dir]) {
    $win = 0;
    for ($v = 0; $v < 10000; $v++) { $roll = $v / 100; if ($dir === 'under' ? $roll < $ch - 1e-9 : $roll >= 100 - $ch - 1e-9) $win++; }
    $ev = $win / 10000 * orig_dice_multiplier($ch, 0.99);
    check(near($win / 100, $ch, 1e-9) && on_target($ev, 0.99, 0.0005), "Dice $dir $ch%: wins on exactly $ch% of rolls, returns " . pct($ev));
}
$ok = true; for ($s = 1; $s <= 10; $s++) { $ev = pow(0.5, $s) * orig_coinflip_mult($s, 0.99); if (!on_target($ev, 0.99, 0.0005)) $ok = false; }
check($ok, 'Coinflip: cashing out after any streak 1-10 returns 99% (never more)');
$worst = 1; foreach (['low', 'medium', 'high'] as $r) foreach ([10, 20, 30] as $n) { $ev = array_sum(orig_wheel_table($r, $n, 0.97)) / $n; if (!on_target($ev, 0.97, 0.005)) $worst = 0; }
check($worst === 1, 'Wheel: all 9 layouts return 97% (within half a percent, never more)');
$ok = true; $tops = [];
foreach (['low', 'medium', 'high'] as $r) for ($n = 8; $n <= 16; $n++) {
    $t = orig_plinko_table($r, $n, 0.99);
    if (!on_target(orig_expected(orig_plinko_probs($n), $t), 0.99, 0.003)) $ok = false;
    if ($t[0] !== $t[$n]) $ok = false;   // symmetric
    if ($n === 16) $tops[$r] = $t[0];
}
check($ok, 'Plinko: all 27 tables (3 risks x 8-16 rows) are symmetric and return 99%');
check($tops['high'] == 1000 && $tops['medium'] > $tops['low'], 'Plinko 16 rows: High tops out at 1000x, Medium above Low (' . json_encode($tops) . ')');
$ok = true; for ($n = 1; $n <= 10; $n++) { $p = orig_keno_probs($n); if (!near(array_sum($p), 1, 1e-12) || !on_target(orig_expected($p, orig_keno_table($n, 0.98)), 0.98, 0.003)) $ok = false; }
check($ok, 'Keno: the hit chances for 1-10 picks sum to 1 and every pay table returns 98%');
$sr = orig_slots_rtp_of(orig_slots_table(0.96));
check(on_target($sr, 0.96, 0.0005), 'Slots: lines + free spins return exactly ' . pct($sr) . ' (target 96%)');
$lp = 0; foreach (orig_slots_line_outcomes() as $o) $lp += $o[0];
check(near($lp, 1, 1e-12), 'Slots: the exact line-outcome chances sum to 1');
$ab = orig_ab_pays(0.97); $ps = array_sum(orig_ab_position_probs());
check(near($ps, 1, 1e-12) && near($ab['andar']['prob'] + $ab['bahar']['prob'], 1, 1e-12), 'Andar Bahar: the exact match-position chances sum to 1');
$ok = $ab['andar']['prob'] * $ab['andar']['pays'] <= 0.97 && $ab['bahar']['prob'] * $ab['bahar']['pays'] <= 0.97;
foreach ($ab['cards'] as $c) if ($c['prob'] * $c['pays'] > 0.97 + 1e-9 || $c['prob'] * $c['pays'] < 0.96) $ok = false;
check($ok, sprintf('Andar Bahar: Andar %.2fx (p=%.4f), Bahar %.2fx and every side bet return at most 97%%', $ab['andar']['pays'], $ab['andar']['prob'], $ab['bahar']['pays']));
$mi = orig_matka_info(['rtp' => 0.95])['pays'];
check($mi['single'] == 9.5 && $mi['jodi'] == 95 && $mi['oddeven'] == 1.9 && $mi['panna_triple'] == 950 && near($mi['panna_single'] * 0.006, 0.95, 0.0001),
      'Matka: Single 9.5x, Jodi 95x, Odd/Even 1.9x, Panna 158.33x / 316.66x / 950x (95% each)');
$ok = true;
foreach ([['straight', '17'], ['red', ''], ['dozen', '2'], ['column', '3'], ['street', '4'], ['line', '6'], ['split', '0-2'], ['split', '14-17'], ['corner', '8']] as [$t, $v]) {
    $cov = orig_roulette_cover($t, $v);
    if (!near(count($cov) / 37 * (36 / count($cov)), 36 / 37, 1e-12)) $ok = false;
}
check($ok, 'Roulette: every bet type returns 36/37 = 97.30%');
foreach ([['split', '3-4'], ['corner', '3'], ['split', '0-5'], ['straight', '37']] as [$t, $v]) {
    $refused = false; try { orig_roulette_cover($t, $v); } catch (OrigError $e) { $refused = true; }
    check($refused, "Roulette: an impossible $t ($v) is refused");
}
// Baccarat, exactly: enumerate the six card VALUES of an infinite shoe (0 has weight 4/13).
$w = [4 / 13]; for ($v = 1; $v <= 9; $v++) $w[] = 1 / 13;
$pw = 0; $bw = 0; $tw = 0;
for ($i = 0; $i < 1000000; $i++) {
    $vals = [$i % 10, intdiv($i, 10) % 10, intdiv($i, 100) % 10, intdiv($i, 1000) % 10, intdiv($i, 10000) % 10, intdiv($i, 100000) % 10];
    $prob = 1; foreach ($vals as $v) $prob *= $w[$v];
    $k = 0;
    [$pc, $bc] = orig_bac_deal(function () use (&$k, $vals) { $v = $vals[$k++]; return ['r' => $v === 0 ? 10 : $v]; });
    $pt = orig_bac_total($pc); $bt = orig_bac_total($bc);
    if ($pt > $bt) $pw += $prob; elseif ($bt > $pt) $bw += $prob; else $tw += $prob;
}
$evB = $bw * 1.95 + $tw; $evP = $pw * 2 + $tw; $evT = $tw * 9;
check(near($pw + $bw + $tw, 1, 1e-9) && near($evB, 0.9894, 0.0005) && near($evP, 0.9876, 0.0005) && near($evT, 0.8588, 0.001),
      sprintf('Baccarat (exact, fresh pack per card): Banker %s, Player %s, Tie %s', pct($evB), pct($evP), pct($evT)));

// =================================================================================================
section('2. Simulation: the real play() code lands on the exact return');
// =================================================================================================
$N = $QUICK ? 15000 : 60000;
$sim = function ($game, $params, $exact, $rtp, $label) use ($N) {
    $cfg = originals_config_default($game); $cfg['rtp'] = $rtp; $cfg['max_win'] = 1e12;
    $prep = call_user_func('orig_' . $game . '_prepare', $params, $cfg);
    $sum = 0; $sq = 0;
    for ($i = 0; $i < $N; $i++) {
        $out = call_user_func('orig_' . $game . '_play', $prep['stake'], $prep['params'], new OrigRng('sim' . $game . $i, 'c', $i), $cfg);
        $m = $out['payout'] / $prep['stake']; $sum += $m; $sq += $m * $m;
    }
    $mean = $sum / $N; $sd = sqrt(max(0, $sq / $N - $mean * $mean)) / sqrt($N);
    check(abs($mean - $exact) <= 4 * $sd + 1e-9, sprintf('%s: simulated %s vs exact %s (4 s.e. = %.3f%%)', $label, pct($mean), pct($exact), 400 * $sd));
};
$sim('dice', ['bet_amount' => 10, 'chance' => 50, 'direction' => 'over'], 0.5 * orig_dice_multiplier(50, 0.99), 0.99, 'Dice 50% over');
$sim('wheel', ['bet_amount' => 10, 'risk' => 'medium', 'segments' => 20], array_sum(orig_wheel_table('medium', 20, 0.97)) / 20, 0.97, 'Wheel medium/20');
$sim('plinko', ['bet_amount' => 10, 'risk' => 'low', 'rows' => 12], orig_expected(orig_plinko_probs(12), orig_plinko_table('low', 12, 0.99)), 0.99, 'Plinko low/12');
$sim('keno', ['bet_amount' => 10, 'picks' => [3, 9, 14, 22, 31]], orig_expected(orig_keno_probs(5), orig_keno_table(5, 0.98)), 0.98, 'Keno 5 picks');
$sim('roulette', ['bets' => [['type' => 'red', 'amount' => 10], ['type' => 'dozen', 'value' => '1', 'amount' => 10]]], 36 / 37, null, 'Roulette red + dozen');
$sim('baccarat', ['bets' => [['type' => 'banker', 'amount' => 10]]], $evB, null, 'Baccarat banker');
$sim('andarbahar', ['bets' => [['type' => 'andar', 'amount' => 10], ['type' => 'cards', 'value' => '6-10', 'amount' => 10]]],
     ($ab['andar']['prob'] * $ab['andar']['pays'] + $ab['cards']['6-10']['prob'] * $ab['cards']['6-10']['pays']) / 2, 0.97, 'Andar Bahar andar + 6-10 cards');
$sim('matka', ['bets' => [['type' => 'single', 'value' => '7', 'amount' => 10], ['type' => 'oddeven', 'value' => 'even', 'amount' => 10]]], 0.95, 0.95, 'Matka single + even');
$sim('slots', ['bet_amount' => 10], $sr, 0.96, 'Slots (incl. free spins)');

// =================================================================================================
section('3. Blackjack rules');
// =================================================================================================
check(orig_bj_total(['AS', 'KH']) === [21, true] && orig_bj_total(['AS', 'AH', '9D']) === [21, true] && orig_bj_total(['KS', '6H', 'AD']) === [17, false],
      'totals: A+K = soft 21, A+A+9 = soft 21, K+6+A = hard 17');
check(orig_bj_is_blackjack(['AS', 'QD']) && !orig_bj_is_blackjack(['AS', 'QD'], true) && !orig_bj_is_blackjack(['7S', '7D', '7C']), 'blackjack only on a 2-card 21, never after a split');
$rng0 = new OrigRng('x', 'y', 0);
$st = ['hands' => [['cards' => ['KS', '9H'], 'bet' => 10, 'done' => true]], 'active' => 0, 'dealer' => ['QS', '8D'], 'phase' => 'player', 'insurance' => null];
$s = orig_bj_settle($st, $rng0, true);
check($s['hands'][0]['outcome'] === 'win' && $s['hands'][0]['payout'] == 20, '19 beats the dealer\'s 18: paid 2x');
$st['dealer'] = ['QS', '9D']; $s = orig_bj_settle($st, $rng0, true);
check($s['hands'][0]['outcome'] === 'push' && $s['hands'][0]['payout'] == 10, '19 against 19 is a push: stake back');
$st['hands'][0]['cards'] = ['AS', 'KH']; $st['dealer'] = ['QS', '9D']; $s = orig_bj_settle($st, $rng0, false);
check($s['hands'][0]['outcome'] === 'blackjack' && $s['hands'][0]['payout'] == 25, 'a blackjack pays 3:2 (2.5x)');
$st['hands'][0]['cards'] = ['KS', '5H', '9C']; $s = orig_bj_settle($st, $rng0, true);
check($s['hands'][0]['outcome'] === 'bust' && $s['hands'][0]['payout'] == 0 && count($s['dealer']) === 2, 'a bust loses, and the dealer does not draw against busted hands');
$st = ['hands' => [['cards' => ['8S', '8H'], 'bet' => 10, 'done' => false]], 'active' => 0, 'dealer' => ['6S', 'KD'], 'phase' => 'player', 'insurance' => null];
check(in_array('split', orig_bj_actions($st), true) && in_array('double', orig_bj_actions($st), true), 'a pair of 8s may split or double');
$st['phase'] = 'insurance';
check(orig_bj_actions($st) === ['insurance', 'no_insurance'], 'under a dealer Ace the only choice is insurance or not');
// A sensible basic strategy over many hands lands near the rules' ~99.5%.
$basic = function ($s) {
    $a = orig_bj_actions($s);
    if ($s['phase'] === 'insurance') return 'no_insurance';
    $h = $s['hands'][$s['active']]; [$t, $soft] = orig_bj_total($h['cards']); $up = orig_bj_value($s['dealer'][0]); if ($up === 1) $up = 11;
    if (in_array('split', $a, true) && in_array(orig_bj_rank($h['cards'][0]), ['A', '8'], true)) return 'split';
    if (in_array('double', $a, true) && !$soft && ($t === 11 || ($t === 10 && $up < 10) || ($t === 9 && $up >= 3 && $up <= 6))) return 'double';
    if ($soft) return $t >= 19 || ($t === 18 && $up <= 8) ? 'stand' : 'hit';
    if ($t >= 17) return 'stand';
    if ($t >= 13 && $up <= 6) return 'stand';
    if ($t === 12 && $up >= 4 && $up <= 6) return 'stand';
    return 'hit';
};
$H = $QUICK ? 40000 : 150000; $staked = 0; $paid = 0; $sq = 0;
$cfgBj = originals_config_default('blackjack'); $cfgBj['max_win'] = 1e12;
for ($i = 0; $i < $H; $i++) {
    $rng = new OrigRng('bj' . $i, 'c', $i);
    $out = orig_blackjack_play(10, [], $rng, $cfgBj); $stake = 10;
    while ($out['status'] === 'open') {
        $out = orig_blackjack_action(['bet' => $stake, 'params' => [], 'state' => $out['state']], $basic($out['state']), [], $rng, $cfgBj);
        $stake += $out['extra_stake'] ?? 0;
    }
    $staked += $stake; $paid += $out['payout']; $sq += pow($out['payout'] - $stake, 2) / 100;
}
$ret = $paid / $staked; $se = sqrt($sq / $H) * 10 / ($staked / $H) / sqrt($H);
check($ret > 0.985 - 4 * $se && $ret < 1.005 + 4 * $se, sprintf('basic strategy over %d hands returns %s (expected about 99.5%%)', $H, pct($ret)));

// =================================================================================================
section('4. Servers');
// =================================================================================================
$PORTS = [5141, 5142, 5143, 5144];
$root = dirname(__DIR__);
$servers = [];
$ext = db_is_mysql() ? 'pdo_mysql' : 'pdo_pgsql';
foreach ($PORTS as $p) {
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "originals_$p.log";
    $cmd = escapeshellarg(PHP_BINARY) . (extension_loaded($ext) ? '' : ' -d extension=' . $ext) . ' -S 127.0.0.1:' . $p . ' ' . escapeshellarg($root . '/router.php');
    $servers[] = proc_open($cmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root);
}
register_shutdown_function(function () use (&$servers) {
    foreach ($servers as $s) {
        if (!is_resource($s)) continue;
        $st = proc_get_status($s);
        if (stripos(PHP_OS, 'WIN') === 0) exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>NUL');
        else proc_terminate($s);
        proc_close($s);
    }
});
function http($method, $path, $token = null, $body = null, $port = 5141) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = ['Accept: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), $raw];
}
function burst($path, $token, $bodies) {
    global $PORTS;
    $mh = curl_multi_init(); $hs = [];
    foreach ($bodies as $i => $body) {
        $ch = curl_init('http://127.0.0.1:' . $PORTS[$i % count($PORTS)] . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode($body)]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05); } while ($running > 0);
    $out = [];
    foreach ($hs as $ch) {
        $out[] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
$up = 0;
foreach ($PORTS as $p) { for ($i = 0; $i < 60; $i++) { [$c] = http('GET', '/api/originals/catalogue', null, null, $p); if ($c === 200) { $up++; break; } usleep(250000); } }
check($up === count($PORTS), "all four servers answer ($up)");
if ($up !== count($PORTS)) exit(1);

function wipe() {
    foreach (['OriginalsRound', 'OriginalsSeed'] as $t) q('DELETE FROM "' . $t . '" WHERE "username" LIKE ?', ['ortest_%']);
    q('DELETE FROM "Transaction" WHERE "user" LIKE ?', ['ortest_%']);
    q('DELETE FROM "User" WHERE "username" LIKE ?', ['ortest_%']);
}
wipe();
$START = 100000;
$P = [];
foreach (['a', 'b', 'c'] as $n) {
    $row = get_or_create_user('ortest_' . $n, true);
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$START, (int) $row['id']]);
    $P[$n] = ['id' => (int) $row['id'], 'name' => 'ortest_' . $n, 'tok' => issue_token(['id' => (int) $row['id'], 'username' => 'ortest_' . $n, 'role' => 'user'])];
}
function bal($n) { global $P; return (float) find_user_by_id($P[$n]['id'])['wallet_balance']; }
function reconciles($n) {
    global $START;
    $u = 'ortest_' . $n;
    $in = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Withdrawal'], 0);
    $rIn = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "OriginalsRound" WHERE "username" = ?', [$u], 0);
    $rOut = (float) scalar('SELECT COALESCE(SUM("bet_amount"),0) FROM "OriginalsRound" WHERE "username" = ?', [$u], 0);
    return abs($START + $in - $out - bal($n)) < 0.005 && abs($in - $rIn) < 0.005 && abs($out - $rOut) < 0.005;
}
$refN = 0;
function ref() { global $refN; return 'ref' . bin2hex(random_bytes(6)) . ($refN++); }
$savedCfg = state_get('originals_config');
q('DELETE FROM "GameState" WHERE "key" = ?', ['originals_config']);

// =================================================================================================
section('5. Every game over HTTP');
// =================================================================================================
$A = $P['a']['tok'];
[$c, $b] = http('GET', '/api/originals/catalogue');
check($c === 200 && count($b['games'] ?? []) === 11, 'the catalogue lists the 11 games (' . $c . ')');
[$c, $b] = http('GET', '/api/originals/dice/state');
check($c === 200 && $b['signed_in'] === false && $b['config']['rtp'] == 0.99, 'a visitor can open a game page (settings, no account data)');
[$c] = http('POST', '/api/originals/dice/play', null, ['client_ref' => ref(), 'bet_amount' => 10, 'chance' => 50, 'direction' => 'under']);
check($c === 401, "playing without signing in is refused ($c)");
[$c] = http('GET', '/api/originals/nosuchgame/state');
check($c === 404, "an unknown game is 404 ($c)");

$bodies = [
    'dice'       => ['bet_amount' => 10, 'chance' => 49.5, 'direction' => 'over'],
    'wheel'      => ['bet_amount' => 10, 'risk' => 'high', 'segments' => 30],
    'plinko'     => ['bet_amount' => 10, 'risk' => 'high', 'rows' => 16],
    'keno'       => ['bet_amount' => 10, 'picks' => [1, 7, 13, 21, 28, 35, 40]],
    'roulette'   => ['bets' => [['type' => 'straight', 'value' => '17', 'amount' => 5], ['type' => 'black', 'amount' => 10], ['type' => 'split', 'value' => '0-3', 'amount' => 5]]],
    'baccarat'   => ['bets' => [['type' => 'player', 'amount' => 10], ['type' => 'tie', 'amount' => 2], ['type' => 'banker_pair', 'amount' => 1]]],
    'andarbahar' => ['bets' => [['type' => 'bahar', 'amount' => 10], ['type' => 'cards', 'value' => '1-5', 'amount' => 5]]],
    'slots'      => ['bet_amount' => 20],
    'matka'      => ['bets' => [['type' => 'jodi', 'value' => '42', 'amount' => 5], ['type' => 'panna', 'value' => '127', 'amount' => 2], ['type' => 'oddeven', 'value' => 'odd', 'amount' => 10]], 'market' => 'Kalyan'],
];
$ROUNDS = $QUICK ? 6 : 15;
foreach ($bodies as $g => $body) {
    $okAll = true; $bal = bal('a'); $stakes = 0; $wins = 0;
    for ($i = 0; $i < $ROUNDS; $i++) {
        [$c, $r] = http('POST', "/api/originals/$g/play", $A, $body + ['client_ref' => ref()]);
        if ($c !== 200 || empty($r['ok']) || $r['round']['status'] !== 'settled') { $okAll = false; echo "      $g -> $c " . json_encode($r) . "\n"; break; }
        $stakes += $r['round']['bet']; $wins += $r['round']['payout'];
        if (!near($r['balance'], $bal - $r['round']['bet'] + $r['round']['payout'])) $okAll = false;
        $bal = $r['balance'];
    }
    check($okAll && reconciles('a'), sprintf('%s: %d rounds over HTTP, every balance correct, wallet = ledger = rounds (staked %.2f, won %.2f)', $g, $ROUNDS, $stakes, $wins));
}

// Same request twice / eight at once: one bet.
$body = ['client_ref' => ref(), 'bet_amount' => 25, 'chance' => 50, 'direction' => 'under'];
$b0 = bal('a');
[$c1, $r1] = http('POST', '/api/originals/dice/play', $A, $body);
[$c2, $r2] = http('POST', '/api/originals/dice/play', $A, $body, 5142);
check($c2 === 200 && !empty($r2['replayed']) && $r2['round']['round_ref'] === $r1['round']['round_ref'] && near(bal('a'), $b0 - 25 + $r1['round']['payout']),
      'the same request sent twice is one bet (the second is a replay)');
[$cx] = http('POST', '/api/originals/coinflip/play', $A, ['client_ref' => $body['client_ref'], 'bet_amount' => 25, 'side' => 'heads']);
check($cx === 409 && reconciles('a'), 'reusing a request reference on another game is refused without a debit');
$b0 = bal('a'); $burstBody = ['client_ref' => ref(), 'bet_amount' => 30, 'risk' => 'low', 'segments' => 10];
$res = burst('/api/originals/wheel/play', $A, array_fill(0, 8, $burstBody));
$refs = array_unique(array_map(function ($x) { return $x[1]['round']['round_ref'] ?? null; }, $res));
check(count($refs) === 1 && (int) scalar('SELECT COUNT(*) FROM "OriginalsRound" WHERE "username" = ? AND "client_ref" = ?', ['ortest_a', $burstBody['client_ref']]) === 1
      && near(bal('a'), $b0 - 30 + $res[0][1]['round']['payout']), 'eight simultaneous copies of one click (four servers): exactly one bet');
$nBefore = (int) scalar('SELECT "nonce" FROM "OriginalsSeed" WHERE "username" = ?', ['ortest_a']);
$res = burst('/api/originals/dice/play', $A, array_map(function () { return ['client_ref' => ref(), 'bet_amount' => 10, 'chance' => 50, 'direction' => 'over']; }, range(1, 8)));
$nonces = array_map(function ($x) { return $x[1]['round']['nonce'] ?? -1; }, $res); sort($nonces);
check($nonces === range($nBefore, $nBefore + 7) && reconciles('a'), 'eight different simultaneous bets: eight rounds, nonces ' . $nBefore . '..' . ($nBefore + 7) . ' each used once, wallet reconciles');

// Refusals.
$b0 = bal('c');
q('UPDATE "User" SET "wallet_balance" = 5 WHERE "id" = ?', [$P['c']['id']]);
[$c, $r] = http('POST', '/api/originals/dice/play', $P['c']['tok'], ['client_ref' => ref(), 'bet_amount' => 10, 'chance' => 50, 'direction' => 'under']);
check($c === 400 && ($r['code'] ?? '') === 'insufficient_balance' && near(bal('c'), 5) && (int) scalar('SELECT COUNT(*) FROM "OriginalsRound" WHERE "username" = ?', ['ortest_c']) === 0,
      'a bet above the balance is refused and nothing is recorded');
q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$START, $P['c']['id']]);
foreach ([['dice', ['bet_amount' => 10, 'chance' => 99, 'direction' => 'under'], 'a 99% dice chance'],
          ['keno', ['bet_amount' => 10, 'picks' => range(1, 11)], '11 keno picks'],
          ['keno', ['bet_amount' => 10, 'picks' => [5, 5]], 'the same keno number twice'],
          ['roulette', ['bets' => [['type' => 'split', 'value' => '3-4', 'amount' => 10]]], 'a roulette split that is not adjacent'],
          ['matka', ['bets' => [['type' => 'panna', 'value' => '210', 'amount' => 10]]], 'a panna not written in order'],
          ['plinko', ['bet_amount' => 10, 'risk' => 'high', 'rows' => 20], '20 plinko rows'],
          ['dice', ['bet_amount' => 0.001, 'chance' => 50, 'direction' => 'under'], 'a bet below the minimum']] as [$g, $bd, $what]) {
    [$c] = http('POST', "/api/originals/$g/play", $A, $bd + ['client_ref' => ref()]);
    check($c === 400, "refused: $what ($c)");
}
[$c] = http('POST', '/api/originals/dice/play', $A, ['bet_amount' => 10, 'chance' => 50, 'direction' => 'under']);
check($c === 400, "a play without a request reference is refused ($c)");

// =================================================================================================
section('6. Multi-step rounds: Coinflip and Blackjack');
// =================================================================================================
$B = $P['b']['tok'];
// Coinflip: play until a first win, then a stale double-click, then cash out.
$won = null;
for ($i = 0; $i < 40 && !$won; $i++) {
    [$c, $r] = http('POST', '/api/originals/coinflip/play', $B, ['client_ref' => ref(), 'bet_amount' => 10, 'side' => 'heads']);
    if ($c === 200 && $r['round']['status'] === 'open') $won = $r['round'];
}
check($won && $won['result']['streak'] === 1 && $won['result']['multiplier'] == 1.98, 'Coinflip: a correct first call opens a streak at 1.98x');
originals_config_update('coinflip', ['rtp' => 0.80]);
[$c] = http('POST', '/api/originals/coinflip/play', $B, ['client_ref' => ref(), 'bet_amount' => 10, 'side' => 'heads']);
check($c === 409, "a second Coinflip round cannot start while one is open ($c)");
[$c, $r] = http('POST', '/api/originals/seed/rotate', $B, []);
check($c === 409, "seeds cannot be rotated while a round is open ($c)");
$b0 = bal('b');
$res = burst('/api/originals/coinflip/action', $B, array_fill(0, 6, ['round_ref' => $won['round_ref'], 'step' => 0, 'action' => 'cashout']));
$oks = count(array_filter($res, function ($x) { return $x[0] === 200; }));
check($oks === 1 && near(bal('b'), $b0 + 19.8), "six simultaneous cash-outs: paid once, 19.80 ($oks ok)");
originals_config_update('coinflip', ['rtp' => 0.99]);
check(near(bal('b'), $b0 + 19.8), 'an open Coinflip streak retains its original RTP after an operator changes settings');
[$c] = http('POST', '/api/originals/coinflip/action', $B, ['round_ref' => $won['round_ref'], 'step' => 1, 'action' => 'flip', 'side' => 'tails']);
check($c === 409, "a finished streak cannot be flipped again ($c)");
check(reconciles('b'), 'player B reconciles after Coinflip');

// Blackjack: play hands to the end with a simple strategy; doubles and splits take their extra stake.
$hands = 0; $extras = 0; $okBj = true; $sawActions = [];
for ($i = 0; $i < ($QUICK ? 12 : 30); $i++) {
    [$c, $r] = http('POST', '/api/originals/blackjack/play', $B, ['client_ref' => ref(), 'bet_amount' => 20]);
    if ($c !== 200) { $okBj = false; break; }
    $round = $r['round']; $step = 0; $guard = 0;
    while ($round['status'] === 'open' && $guard++ < 12) {
        $s = $round['result'];
        if ($s['dealer'][1] !== '??') { $okBj = false; break; }        // the hole card stays hidden
        $acts = $s['actions'];
        $h = $s['hands'][$s['active']];
        $act = $s['phase'] === 'insurance' ? (($i % 2) ? 'insurance' : 'no_insurance')
             : (in_array('split', $acts, true) ? 'split' : (in_array('double', $acts, true) && in_array($h['total'], [10, 11], true) ? 'double' : ($h['total'] < 17 ? 'hit' : 'stand')));
        $sawActions[$act] = true;
        [$c, $r] = http('POST', '/api/originals/blackjack/action', $B, ['round_ref' => $round['round_ref'], 'step' => $step, 'action' => $act]);
        if ($c !== 200) { $okBj = false; echo "      blackjack $act -> $c " . json_encode($r) . "\n"; break; }
        if (in_array($act, ['double', 'split', 'insurance'], true)) $extras++;
        $round = $r['round']; $step++;
    }
    if ($round['status'] !== 'settled' || in_array('??', $round['result']['dealer'], true)) $okBj = false;
    $hands++;
}
check($okBj, "Blackjack: $hands hands played to the end over HTTP; the hole card was hidden until each finished");
check(reconciles('b'), 'player B reconciles after Blackjack (' . $extras . ' doubles / splits / insurance taken as extra stakes)');
check(isset($sawActions['hit']) || isset($sawActions['stand']), 'actions seen: ' . implode(', ', array_keys($sawActions)));

// =================================================================================================
section('7. Provably fair: rotate, reveal, re-derive');
// =================================================================================================
[$c, $sd] = http('GET', '/api/originals/seed', $A);
$before = $sd['seed'];
[$c, $r] = http('POST', '/api/originals/seed/rotate', $A, ['client_seed' => 'my-lucky-seed']);
$prev = $r['seed']['previous'] ?? null;
check($c === 200 && $prev && hash('sha256', $prev['server_seed']) === $before['server_seed_hash'] && $r['seed']['client_seed'] === 'my-lucky-seed' && $r['seed']['nonce'] === 0,
      'rotation reveals the old server seed, which hashes to the hash shown before play; the new pair starts at nonce 0');
[$c, $st] = http('GET', '/api/originals/plinko/state', $A);
$checked = 0; $match = 0;
foreach (['dice', 'wheel', 'plinko', 'keno', 'roulette', 'baccarat', 'andarbahar', 'slots', 'matka'] as $g) {
    [$c, $st] = http('GET', "/api/originals/$g/state", $A);
    foreach ($st['recent'] ?? [] as $rd) {
        if (empty($rd['server_seed'])) continue;
        $checked++;
        $cfg = originals_config($g);
        $out = call_user_func('orig_' . $g . '_play', $rd['bet'], $rd['params'], new OrigRng($rd['server_seed'], $rd['client_seed'], $rd['nonce']), $cfg);
        if (json_encode($out['result']) === json_encode($rd['result']) && near(orig_cap($out['payout'], $cfg), $rd['payout'])) $match++;
    }
}
check($checked >= 9 && $match === $checked, "every revealed round re-derives exactly from its seed, client seed and nonce ($match / $checked)");
[$c, $st] = http('GET', '/api/originals/dice/state', $A);
check(!isset($st['recent'][0]['server_seed']) || $st['recent'][0]['server_seed_hash'] !== $r['seed']['server_seed_hash'], 'rounds on the new, unrevealed seed do not show it');
[$c] = http('POST', '/api/originals/seed/rotate', $A, ['client_seed' => 'bad seed!']);
check($c === 400, "a client seed with spaces or symbols is refused ($c)");

// =================================================================================================
section('8. Operator settings, ledger wording, player history');
// =================================================================================================
$ADMIN = issue_token(['id' => 0, 'username' => 'admin', 'role' => 'admin']);
[$c] = http('POST', '/api/admin/originals/config', $A, ['game' => 'dice', 'rtp' => 99]);
check($c === 403, "a player cannot change game settings ($c)");
[$c] = http('POST', '/api/admin/originals/config', $ADMIN, ['game' => 'dice', 'rtp' => 50]);
check($c === 400, "an RTP of 50% is refused ($c)");
[$c, $r] = http('POST', '/api/admin/originals/config', $ADMIN, ['game' => 'dice', 'rtp' => 95]);
[$c2, $r2] = http('POST', '/api/originals/dice/play', $A, ['client_ref' => ref(), 'bet_amount' => 10, 'chance' => 50, 'direction' => 'under']);
check($c === 200 && $r2['round']['result']['multiplier'] == 1.9, 'setting Dice to 95% makes a 50% roll pay 1.90x');
[$c, $r] = http('POST', '/api/admin/originals/config', $ADMIN, ['game' => 'roulette', 'rtp' => 90]);
check($c === 200 && near($r['config']['rtp'], 36 / 37, 1e-9), 'Roulette\'s return is fixed by its rules: an RTP setting is ignored');
http('POST', '/api/admin/originals/config', $ADMIN, ['game' => 'keno', 'enabled' => false]);
[$c] = http('POST', '/api/originals/keno/play', $A, ['client_ref' => ref(), 'bet_amount' => 10, 'picks' => [1, 2, 3]]);
check($c === 403, "a switched-off game refuses bets ($c)");
http('POST', '/api/admin/originals/config', $ADMIN, ['game' => 'keno', 'max_bet' => 50, 'enabled' => true]);
[$c] = http('POST', '/api/originals/keno/play', $A, ['client_ref' => ref(), 'bet_amount' => 60, 'picks' => [1, 2, 3]]);
check($c === 400, "a bet above the game's maximum is refused ($c)");
[$c, $r] = http('GET', '/api/admin/originals/config', $ADMIN);
check($c === 200 && count($r['games']) === 11, 'the operator sees all 11 games\' settings');
check(originals_classify('Plinko Bet') === ['game' => 'plinko', 'kind' => 'wager', 'label' => 'Plinko']
      && originals_classify('Andar Bahar Win x2') === ['game' => 'andarbahar', 'kind' => 'win', 'label' => 'Andar Bahar']
      && originals_classify('Mines Bet') === null, 'ledger wording is classified per game (and leaves other games alone)');
$cnt = (int) scalar('SELECT COUNT(*) FROM "Transaction" WHERE "user" = ? AND ("details" LIKE ? OR "details" LIKE ?)', ['ortest_a', 'Dice Bet%', 'Dice Win%'], 0);
check($cnt > 0, "the ledger carries 'Dice Bet' / 'Dice Win' rows ($cnt)");
[$c, $r] = http('GET', '/api/profile', $A);
$games = array_unique(array_map(function ($h) { return $h['game']; }, $r['history'] ?? []));
check($c === 200 && count(array_intersect($games, ['Dice', 'Plinko', 'Roulette', 'Slots', 'Matka'])) === 5, 'the player\'s history shows their originals rounds (' . implode(', ', array_slice($games, 0, 8)) . ')');
check(reconciles('a') && reconciles('b') && reconciles('c'), 'all three players reconcile to the paisa at the end');

if ($savedCfg === null) q('DELETE FROM "GameState" WHERE "key" = ?', ['originals_config']); else state_set('originals_config', $savedCfg);
wipe();
echo "\n------------------------------------------------------------\n";
echo "test_originals: $pass passed, $fail failed\n";
if ($failures) { echo "Failures:\n"; foreach ($failures as $f) echo "  - $f\n"; }
exit($fail ? 1 : 0);
