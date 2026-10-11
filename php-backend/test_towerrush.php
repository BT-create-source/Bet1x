<?php
/**
 * Tower Rush — maths, the real HTTP API, and money races, against the REAL database.
 *
 *     php -d extension=pdo_pgsql -d extension=curl php-backend/test_towerrush.php [--quick]
 *
 * 1. Maths: every floor's expected factor equals the RTP; the seeded floors have the right
 *    distribution; outcomes are a pure function of the seed.
 * 2. Starts the site on four ports (php -S serves one request at a time), signs test players in with
 *    real tokens and plays many towers through the endpoints the page calls — reconciling every
 *    wallet against its ledger to the paisa and re-deriving every finished tower from its seed.
 * 3. Races: simultaneous first BUILDs, BUILDs and CASHOUTs across all four servers.
 *
 * Writes to whatever php-backend/.env points at — use a development database. Touches only its own
 * trtest_* accounts and their rows, and removes them at the end.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers'] as $f) require_once __DIR__ . "/lib/$f.php";
require_once __DIR__ . '/games/towerrush.php';

$QUICK = in_array('--quick', $argv, true);
$pass = 0; $fail = 0; $failures = [];
function check($cond, $label) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function near($a, $b, $eps = 0.005) { return abs((float) $a - (float) $b) < $eps; }
function section($t) { echo "\n== $t ==\n"; }

// =================================================================================================
section('1. Maths');
// =================================================================================================
$RTP = 0.97;
$tot = 0; foreach (tr_factor_table() as [, $w]) $tot += $w;
$ev = 0; foreach (tr_factor_table() as [$v, $w]) $ev += (1 - tr_collapse_chance($RTP)) * $v * $w / $tot;
check(near($ev, $RTP, 1e-9), sprintf('expected factor per floor = %.6f (RTP %.2f)', $ev, $RTP));
check(near(tr_collapse_chance($RTP), 0.1330, 0.0005), sprintf('collapse chance per floor at 97%%: %.2f%%', 100 * tr_collapse_chance($RTP)));
check(tr_floor_factor('abc', 3, $RTP) === tr_floor_factor('abc', 3, $RTP), 'a floor is a pure function of the seed');
check(near(tr_value(90, 1.35 * 0.7 * 0.7, tr_config_default()), 59.53), 'cash-out value floors to the paisa: 90 x 1.35 x 0.7 x 0.7 = 59.53 (as in the original)');
$N = $QUICK ? 40000 : 200000;
$zero = 0; $sum = 0.0; $seen = [];
for ($i = 0; $i < $N; $i++) {
    $f = tr_floor_factor('s' . $i, 1 + ($i % 7), $RTP);
    if ($f == 0) $zero++;
    $sum += $f; $seen[(string) $f] = true;
}
$p0 = tr_collapse_chance($RTP);
check(abs($zero / $N - $p0) < 4 * sqrt($p0 * (1 - $p0) / $N), sprintf('seeded collapse rate %.4f vs %.4f', $zero / $N, $p0));
check(abs($sum / $N - $RTP) < 0.012, sprintf('seeded mean factor %.4f vs RTP %.2f', $sum / $N, $RTP));
check(count($seen) === 11, 'all 11 outcomes occur (x0 plus the ten factors): ' . implode(', ', array_keys($seen)));

// =================================================================================================
section('2. Servers');
// =================================================================================================
$PORTS = [5131, 5132, 5133, 5134];
$root = dirname(__DIR__);
$servers = [];
foreach ($PORTS as $p) {
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "towerrush_$p.log";
    $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=pdo_pgsql -S 127.0.0.1:' . $p . ' ' . escapeshellarg($root . '/router.php');
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
function http($method, $path, $token = null, $body = null, $port = 5131) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = ['Accept: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), $raw];
}
function burst($path, $token, $body, $copies = 8) {
    global $PORTS;
    $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < $copies; $i++) {
        $ch = curl_init('http://127.0.0.1:' . $PORTS[$i % count($PORTS)] . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
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
foreach ($PORTS as $p) { for ($i = 0; $i < 60; $i++) { [$c] = http('GET', '/api/towerrush/state', null, null, $p); if ($c === 200) { $up++; break; } usleep(250000); } }
check($up === count($PORTS), "all four servers answer ($up)");
if ($up !== count($PORTS)) exit(1);

function wipe() {
    q('DELETE FROM "TowerRushSession" WHERE "username" LIKE ?', ['trtest_%']);
    q('DELETE FROM "Transaction" WHERE "user" LIKE ?', ['trtest_%']);
    q('DELETE FROM "User" WHERE "username" LIKE ?', ['trtest_%']);
}
wipe();
$P = [];
foreach (['a', 'b'] as $n) {
    $row = get_or_create_user('trtest_' . $n, true);
    q('UPDATE "User" SET "wallet_balance" = 50000 WHERE "id" = ?', [(int) $row['id']]);
    $P[$n] = ['id' => (int) $row['id'], 'tok' => issue_token(['id' => (int) $row['id'], 'username' => 'trtest_' . $n, 'role' => 'user'])];
}
function bal($n) { global $P; return (float) find_user_by_id($P[$n]['id'])['wallet_balance']; }
function reconciles($n) {
    $u = 'trtest_' . $n;
    $in = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Withdrawal'], 0);
    return abs(50000 + $in - $out - bal($n)) < 0.005;
}
$saved = state_get('towerrush_config');
state_set('towerrush_config', tr_config_default());

// =================================================================================================
section('3. Tower Rush over HTTP');
// =================================================================================================
$A = $P['a']['tok'];
[$c, $b] = http('GET', '/api/towerrush/state');
check($c === 200 && $b['signed_in'] === false && $b['state']['status'] === 'idle' && count($b['config']['factors']) === 11, 'a visitor gets an idle street and the factor table');
[$c] = http('POST', '/api/towerrush/build', null, ['start' => true, 'bet_amount' => 10]);
check($c === 401, "BUILD with no token is refused: $c");
[$c] = http('POST', '/api/towerrush/build', $A, ['start' => true, 'bet_amount' => -1]);
check($c === 400, 'a negative stake is refused');
[$c] = http('POST', '/api/towerrush/build', $A, ['start' => true, 'bet_amount' => 999999]);
check($c === 400 && near(bal('a'), 50000), 'a stake above the maximum is refused and takes nothing');
[$c] = http('POST', '/api/towerrush/cashout', $A, []);
check($c === 400, 'cash-out with no tower is refused');

[$c] = http('POST', '/api/towerrush/build', $A, ['round_ref' => 'NOPE', 'floors' => 0]);
check($c === 409, 'a next-floor BUILD with no live tower is refused, never turned into a new bet');
$ROUNDS = $QUICK ? 25 : 70;
$lastRef = null;
$played = 0; $cashed = 0; $collapsed = 0; $payOk = true; $verified = 0; $bad = 0; $floorsMax = 0;
for ($r = 0; $r < $ROUNDS; $r++) {
    $target = 1 + ($r % 5);                     // try to build 1..5 floors, then cash out
    $bet = 10 + $r;
    $before = bal('a');
    [$c, $b] = http('POST', '/api/towerrush/build', $A, ['start' => true, 'bet_amount' => $bet, 'after_round' => $lastRef]);
    if ($c !== 200) { check(false, "round $r first build: $c " . json_encode($b)); break; }
    $played++;
    if (!near(bal('a'), $before - $bet + ($b['outcome'] === 'max_win' ? $b['state']['payout'] : 0))) $payOk = false;
    $st = $b['state'];
    while ($st['status'] === 'active' && $st['floors'] < $target) {
        [$c, $b] = http('POST', '/api/towerrush/build', $A, ['round_ref' => $st['round_ref'], 'floors' => $st['floors']]);
        $st = $b['state'];
    }
    if ($st['status'] === 'active') {
        $exp = floor($bet * array_product($st['factors']) * 100 + 1e-7) / 100;
        $pre = bal('a');
        [$c, $b] = http('POST', '/api/towerrush/cashout', $A, []);
        $st = $b['state'];
        if (!near($b['payout'], $exp) || !near(bal('a') - $pre, $exp)) $payOk = false;
        $cashed++;
    } elseif ($st['status'] === 'busted') {
        $collapsed++;
    }
    $floorsMax = max($floorsMax, $st['floors']);
    $lastRef = $st['round_ref'];
    // Re-derive every floor from the revealed seed.
    if (!$st['server_seed'] || hash('sha256', $st['server_seed']) !== $st['seed_hash']) { $bad++; continue; }
    foreach ($st['factors'] as $i => $f) {
        if (!near(tr_floor_factor($st['server_seed'], $i + 1, $st['rtp']), $f, 1e-9)) { $bad++; break; }
    }
    $verified++;
}
check($played === $ROUNDS, "played $played towers ($cashed cashed out, $collapsed collapsed, tallest $floorsMax floors)");
check($payOk, 'every stake was taken once and every cash-out paid exactly stake x product (floored to the paisa)');
check($verified === $played && $bad === 0, "every finished tower re-derives from its revealed seed ($verified/$played)");
check(reconciles('a'), 'player A reconciles: start + deposits - withdrawals = wallet, to the paisa');
[$c] = http('POST', '/api/towerrush/build', $A, ['start' => true, 'bet_amount' => 10, 'after_round' => 'SOME-OLD-ROUND']);
check($c === 409 && reconciles('a'), 'a start that names the wrong previous tower (a stale double-click) is refused and takes nothing');

// =================================================================================================
section('4. Races (8 simultaneous requests over 4 servers)');
// =================================================================================================
$B = $P['b']['tok'];
$res = burst('/api/towerrush/build', $B, ['start' => true, 'bet_amount' => 500, 'after_round' => null]);
$okFirst = count(array_filter($res, function ($x) { return $x[0] === 200; }));
$debits = (int) scalar('SELECT COUNT(*) FROM "Transaction" WHERE "user" = ? AND "details" = ?', ['trtest_b', 'Tower Rush Bet'], 0);
check($debits === 1, "8 simultaneous first BUILDs take exactly one stake ($debits bet rows; $okFirst requests built a floor)");
$s = tr_session_get('trtest_b');
check(count($s['factors']) === ($s['status'] === 'busted' ? $s['floors'] + 1 : $s['floors']), 'the tower\'s floor list matches its floor count after the burst');
if ($s['status'] === 'active') {
    $pre = bal('b');
    $res = burst('/api/towerrush/cashout', $B, []);
    $paid = count(array_filter($res, function ($x) { return $x[0] === 200; }));
    $exp = floor(500 * array_product($s['factors']) * 100 + 1e-7) / 100;
    check($paid === 1 && near(bal('b') - $pre, $exp), "8 simultaneous cash-outs pay exactly once ($paid paid, +" . round(bal('b') - $pre, 2) . ", expected +$exp)");
    [$c] = http('POST', '/api/towerrush/build', $B, ['round_ref' => $s['round_ref'], 'floors' => $s['floors']]);
    check($c === 409, 'a late next-floor BUILD on the cashed-out tower is refused');
    [$c] = http('POST', '/api/towerrush/build', $B, ['start' => true, 'bet_amount' => 20, 'after_round' => $s['round_ref']]);
    $s2 = tr_session_get('trtest_b');
    check($c === 200 && $s2['round_ref'] !== $s['round_ref'], 'a start naming the cashed-out tower begins a NEW tower');
} else {
    check(true, 'the burst ended the tower (collapse) — cash-out race skipped this run');
}
check(reconciles('b'), 'player B reconciles to the paisa after the races');

// =================================================================================================
section('5. Operator settings');
// =================================================================================================
$ADMIN = issue_token(['id' => 0, 'username' => 'admin', 'role' => 'admin']);
[$c] = http('POST', '/api/admin/towerrush/config', $A, ['rtp' => 99]);
check($c === 403, "a player cannot change Tower Rush settings: $c");
[$c] = http('POST', '/api/admin/towerrush/config', $ADMIN, ['rtp' => 50]);
check($c === 400, 'an RTP of 50% is refused');
[$c, $b] = http('POST', '/api/admin/towerrush/config', $ADMIN, ['rtp' => 95]);
[$c2, $st] = http('GET', '/api/towerrush/state');
check($c === 200 && near($st['config']['factors'][0]['chance'], round(1 - 0.95 / tr_factor_mean(), 4), 0.0001), 'an RTP of 95% raises the collapse chance the page shows');
[$c] = http('POST', '/api/admin/towerrush/config', $ADMIN, ['enabled' => false]);
q('DELETE FROM "TowerRushSession" WHERE "username" = ?', ['trtest_a']);
[$c] = http('POST', '/api/towerrush/build', $A, ['start' => true, 'bet_amount' => 10, 'after_round' => null]);
check($c === 403, 'with the game switched off, a new tower is refused');
[$c, $b] = http('GET', '/api/profile', $A);
$tr = array_values(array_filter($b['history'] ?? [], function ($h) { return $h['game'] === 'Tower Rush'; }));
check(count($tr) === min(50, $ROUNDS), 'the profile history shows the Tower Rush rounds (' . count($tr) . ')');

if ($saved === null) q('DELETE FROM "GameState" WHERE "key" = ?', ['towerrush_config']); else state_set('towerrush_config', $saved);
wipe();
echo "\n------------------------------------------------------------\n";
echo "test_towerrush: $pass passed, $fail failed\n";
if ($failures) { echo "Failures:\n"; foreach ($failures as $f) echo "  - $f\n"; }
exit($fail ? 1 : 0);
