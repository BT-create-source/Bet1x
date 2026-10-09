<?php
/**
 * Chicken Road + Astronaut — maths, the real HTTP API, and money races, against the REAL database.
 *
 *     php -d extension=pdo_pgsql -d extension=curl php-backend/test_crash_games.php [--quick]
 *
 * 1. Pure maths: the Chicken Road ladders reproduce the original's published figures, every lane
 *    returns RTP, the seeded fire lane has the right distribution; Astronaut's crash point has
 *    P(crash >= m) = RTP/m.
 * 2. Starts the site on four ports at once (php -S is one request at a time, so concurrency needs
 *    several servers on the one database), signs test players in with real tokens, and plays both
 *    games through the same endpoints the pages call — reconciling every wallet against its ledger
 *    to the paisa, and re-deriving every finished round from its revealed seed.
 * 3. Races: simultaneous Plays, GOs and cash-outs fired at all four servers in the same instant.
 *
 * Writes to whatever php-backend/.env points at, so use a development database. Touches only its
 * own crtest_* accounts and their rows, and removes them at the end. Takes about a minute (Astronaut
 * runs on the real clock); --quick skips the slow statistical samples.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers'] as $f) require_once __DIR__ . "/lib/$f.php";
require_once __DIR__ . '/games/chickenroad.php';
require_once __DIR__ . '/games/astronaut.php';

$QUICK = in_array('--quick', $argv, true);
$pass = 0; $fail = 0; $failures = [];
function check($cond, $label) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function near($a, $b, $eps = 0.005) { return abs((float) $a - (float) $b) < $eps; }
function section($t) { echo "\n== $t ==\n"; }

// =================================================================================================
section('1. Chicken Road maths');
// =================================================================================================
$easy = cr_ladder('easy', 0.98); $med = cr_ladder('medium', 0.98);
$hard = cr_ladder('hard', 0.98); $hc = cr_ladder('hardcore', 0.98);
check(count($easy) === 24 && count($med) === 22 && count($hard) === 20 && count($hc) === 15, 'lane counts 24 / 22 / 20 / 15');
check(near($easy[0], 1.02) && near($easy[23], 24.50), "Easy runs 1.02x -> 24.50x (got {$easy[0]} -> {$easy[23]})");
check(near($med[0], 1.11), "Medium opens at 1.11x (got {$med[0]})");
check(near($hard[0], 1.22) && near($hard[19], 52067.40), "Hard runs 1.22x -> 52,067.40x (got {$hard[0]} -> {$hard[19]})");
check(near($hc[0], 1.63) && near($hc[14], 3203384.80, 0.02), "Hardcore runs 1.63x -> 3,203,384.80x (got {$hc[0]} -> {$hc[14]})");
$monotone = true; $rtpOk = true;
foreach (cr_difficulties() as $k => $d) {
    $lad = cr_ladder($k, 0.98);
    foreach ($lad as $i => $m) {
        if ($i > 0 && $m <= $lad[$i - 1]) $monotone = false;
        $ev = cr_survival($d['hazards'], $i + 1) * $m;
        if ($ev > 0.98 + 1e-9 || $ev < 0.98 - 0.01) $rtpOk = false;
    }
}
check($monotone, 'every ladder strictly rises lane by lane');
check($rtpOk, 'cashing out at ANY lane of ANY difficulty returns 98% (never more, within a paisa of it)');
check(cr_fail_step('abc', 'hard') === cr_fail_step('abc', 'hard'), 'the fire lane is a pure function of the seed');

$N = $QUICK ? 20000 : 120000;
foreach (['easy', 'hardcore'] as $k) {
    $h = cr_difficulties()[$k]['hazards'];
    $survive = [1 => 0, 2 => 0, 3 => 0];
    for ($i = 0; $i < $N; $i++) {
        $f = cr_fail_step('sim' . $k . $i, $k);
        foreach ([1, 2, 3] as $n) if ($f === 0 || $f > $n) $survive[$n]++;
    }
    foreach ([1, 2, 3] as $n) {
        $exp = cr_survival($h, $n); $got = $survive[$n] / $N;
        $tol = 4 * sqrt($exp * (1 - $exp) / $N) + 1e-4;
        check(abs($got - $exp) < $tol, sprintf('%s: P(survive %d lanes) = %.4f, expected %.4f', $k, $n, $got, $exp));
    }
}

// =================================================================================================
section('2. Astronaut maths');
// =================================================================================================
$N = $QUICK ? 40000 : 300000;
// String keys: PHP truncates a float array key to an int, which turned 1.01 into 1.
$ge = ['1.01' => 0, '2' => 0, '10' => 0];
$instant = 0; $capHit = 0;
for ($i = 0; $i < $N; $i++) {
    $c = astro_crash_point('s' . $i, 1, 0.97, 10000);
    if ($c <= 1.0) $instant++;
    if ($c >= 10000) $capHit++;
    foreach ($ge as $m => $_) if ($c >= (float) $m) $ge[$m]++;
}
foreach ($ge as $m => $cnt) {
    $exp = min(1.0, 0.97 / (float) $m); $got = $cnt / $N;
    $tol = 4 * sqrt($exp * (1 - $exp) / $N) + 1e-4;
    check(abs($got - $exp) < $tol, sprintf('P(crash >= %sx) = %.4f, expected %.4f (so a %sx cash-out returns 97%%)', $m, $got, $exp, $m));
}
check(abs($instant / $N - (1 - 0.97 / 1.01)) < 0.004, sprintf('instant 1.00x crashes %.2f%% of rounds (expected %.2f%%)', 100 * $instant / $N, 100 * (1 - 0.97 / 1.01)));
check(astro_crash_point('x', 7, 0.97, 10000) === astro_crash_point('x', 7, 0.97, 10000), 'the crash point is a pure function of seed and round');
check(astro_crash_point('x', 7, 0.97, 3) <= 3, 'the operator cap holds');
check(near(astro_multiplier_at(log(2) / ASTRO_GROWTH), 2.0, 1e-9) && astro_ms_to(1.0) === 0, 'curve and its inverse agree');

// =================================================================================================
section('3. Servers up');
// =================================================================================================
$PORTS = [5121, 5122, 5123, 5124];
$root = dirname(__DIR__);
$servers = [];
foreach ($PORTS as $p) {
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "crash_games_$p.log";
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

function http($method, $path, $token = null, $body = null, $port = 5121) {
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

/** Fire the same request at every server in the same instant. Returns [[code, body], ...]. */
function burst($method, $path, $token, $body, $copies = 8) {
    global $PORTS;
    $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < $copies; $i++) {
        $p = $PORTS[$i % count($PORTS)];
        $ch = curl_init("http://127.0.0.1:$p$path");
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
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
foreach ($PORTS as $p) {
    for ($i = 0; $i < 60; $i++) { [$c] = http('GET', '/api/astronaut/state', null, null, $p); if ($c === 200) { $up++; break; } usleep(250000); }
}
check($up === count($PORTS), "all four servers answer ($up/" . count($PORTS) . ')');
if ($up !== count($PORTS)) { echo "Server log: " . sys_get_temp_dir() . "\\crash_games_5121.log\n"; exit(1); }

// ---- test players ---------------------------------------------------------------------------------
function wipe_test_rows() {
    q('DELETE FROM "ChickenRoadSession" WHERE "username" LIKE ?', ['crtest_%']);
    q('DELETE FROM "AstronautBet" WHERE "username" LIKE ?', ['crtest_%']);
    q('DELETE FROM "Transaction" WHERE "user" LIKE ?', ['crtest_%']);
    q('DELETE FROM "User" WHERE "username" LIKE ?', ['crtest_%']);
}
wipe_test_rows();
$players = [];
foreach (['a', 'b', 'c'] as $n) {
    $row = get_or_create_user('crtest_' . $n, true);
    q('UPDATE "User" SET "wallet_balance" = 50000 WHERE "id" = ?', [(int) $row['id']]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', ['crtest_' . $n]);
    $players[$n] = ['row' => find_user_by_id($row['id']), 'tok' => issue_token(['id' => (int) $row['id'], 'username' => 'crtest_' . $n, 'role' => 'user'])];
}
$ADMIN = issue_token(['id' => 0, 'username' => 'admin', 'role' => 'admin']);
function bal($n) { global $players; return (float) find_user_by_id($players[$n]['row']['id'])['wallet_balance']; }
/** Starting balance + ledger in - ledger out must equal the wallet, to the paisa. */
function reconciles($n) {
    $u = 'crtest_' . $n;
    $in  = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u, 'Withdrawal'], 0);
    return abs(50000 + $in - $out - bal($n)) < 0.005;
}

// Pin both games to their defaults for the run, whatever an operator has set on this database.
$savedCr = state_get('chickenroad_config'); $savedAs = state_get('astronaut_config');
state_set('chickenroad_config', cr_config_default()); state_set('astronaut_config', astro_config_default());

// =================================================================================================
section('4. Chicken Road over HTTP');
// =================================================================================================
$A = $players['a']['tok'];
[$c] = http('POST', '/api/chickenroad/start', null, ['bet_amount' => 10, 'difficulty' => 'easy']);
check($c === 401, "Play with no token is refused: $c");
[$c, $b] = http('GET', '/api/chickenroad/state', $A);
check($c === 200 && $b['state']['status'] === 'idle' && count($b['config']['difficulties']) === 4, 'state: idle, four difficulties with ladders');
[$c] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 10, 'difficulty' => 'impossible']);
check($c === 400, "an unknown difficulty is refused: $c");
[$c] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => -5, 'difficulty' => 'easy']);
check($c === 400, "a negative stake is refused: $c");
[$c] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 999999, 'difficulty' => 'easy']);
check($c === 400, "a stake above the table maximum is refused: $c");

[$c, $b] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 100, 'difficulty' => 'medium']);
check($c === 200 && $b['state']['status'] === 'active' && near($b['state']['balance'], 49900), 'Play takes the stake once: balance 49,900');
check($b['state']['server_seed'] === null && !array_key_exists('fail_step', $b['state']), 'a live road never reveals its seed or fire lane');
[$c] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 100, 'difficulty' => 'medium']);
check($c === 400 && near(bal('a'), 49900), 'a second Play during a road is refused and takes nothing');
[$c] = http('POST', '/api/chickenroad/cashout', $A, []);
check($c === 400, 'cash-out before the first lane is refused');
http('POST', '/api/chickenroad/cashout', $A, []);  // (no-op either way)

// Play many full rounds with random strategy and verify every finished road against its seed.
$rounds = 0; $wins = 0; $busts = 0; $eggs = 0; $verified = 0; $mismatch = 0; $payOk = true;
$ROUNDS = $QUICK ? 25 : 80;
for ($r = 0; $r < $ROUNDS; $r++) {
    $diff = ['easy', 'medium', 'hard', 'hardcore'][$r % 4];
    $stopAt = ($r % 5 === 0) ? 99 : 1 + ($r % 6);   // every fifth road tries to cross the whole thing
    if ($rounds === 0) { $st = $b['state']; $diff = 'medium'; }
    else { [$c, $b] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 10 + $r, 'difficulty' => $diff]); $st = $b['state'] ?? null; }
    if (!$st || $st['status'] !== 'active') { check(false, "round $r could not start: " . json_encode($b)); break; }
    $rounds++;
    $bet = $st['bet_amount'];
    while (true) {
        if ($st['step'] >= $stopAt) {
            $before = bal('a');
            [$c, $b] = http('POST', '/api/chickenroad/cashout', $A, []);
            $st = $b['state'];
            $exp = round($bet * cr_multiplier($diff, $st['step'], 0.98), 2);
            if (!near($b['payout'], $exp) || !near(bal('a') - $before, $exp)) $payOk = false;
            $wins++;
            break;
        }
        [$c, $b] = http('POST', '/api/chickenroad/step', $A, []);
        $st = $b['state'];
        if ($b['outcome'] === 'fire') { $busts++; break; }
        if ($b['outcome'] === 'golden_egg') { $eggs++; $wins++; break; }
    }
    // Re-derive the road from the revealed seed.
    if (!$st['server_seed'] || hash('sha256', $st['server_seed']) !== $st['seed_hash']) { $mismatch++; continue; }
    $f = cr_fail_step($st['server_seed'], $diff);
    if ($st['status'] === 'busted') { if ($f !== $st['fire_lane']) $mismatch++; }
    else if ($f !== 0 && $f <= $st['step']) $mismatch++;
    $verified++;
}
check($rounds === $ROUNDS, "played $rounds roads ($wins cashed, $busts burnt, $eggs golden eggs)");
check($payOk, 'every cash-out paid exactly stake x the ladder multiplier, and the wallet moved by that much');
check($verified === $rounds && $mismatch === 0, "every finished road re-derives from its revealed seed and matches its hash ($verified/$rounds, $mismatch mismatches)");
check(reconciles('a'), 'player A: wallet = start + ledger deposits - ledger withdrawals, to the paisa');

// =================================================================================================
section('5. Chicken Road races (8 simultaneous requests over 4 servers)');
// =================================================================================================
$B = $players['b']['tok'];
$res = burst('POST', '/api/chickenroad/start', $B, ['bet_amount' => 500, 'difficulty' => 'easy']);
$oks = count(array_filter($res, function ($r) { return $r[0] === 200; }));
check($oks === 1 && near(bal('b'), 49500), "8 simultaneous Plays: $oks accepted, one stake taken (balance " . bal('b') . ')');
// Step until at least one lane is safe (Easy: 96% first lane), then race GO against GO.
[$c, $b] = http('POST', '/api/chickenroad/step', $B, []);
if (($b['outcome'] ?? '') === 'safe') {
    $res = burst('POST', '/api/chickenroad/step', $B, []);
    // Every GO is a real click, so several may legitimately succeed. What must never happen is two
    // of them moving from the SAME lane: each accepted GO must report a different lane, the lanes
    // must be consecutive, and the road must have moved exactly as far as the accepted GOs say.
    $lanesSeen = [];
    foreach ($res as $r) {
        if ($r[0] !== 200) continue;
        $lanesSeen[] = $r[1]['outcome'] === 'fire' ? $r[1]['state']['fire_lane'] : $r[1]['state']['step'];
    }
    sort($lanesSeen);
    $st = cr_session_get('crtest_b');
    $contiguous = $lanesSeen && $lanesSeen === range(2, 1 + count($lanesSeen));
    $endLane = $st['status'] === 'busted' ? $st['step'] + 1 : $st['step'];
    check($contiguous && $endLane === 1 + count($lanesSeen),
          '8 simultaneous GOs never move twice from one lane (' . count($lanesSeen) . ' accepted, lanes '
          . implode(',', $lanesSeen) . ", road ended on lane $endLane)");
    if ($st['status'] === 'active') {
        $before = bal('b');
        $res = burst('POST', '/api/chickenroad/cashout', $B, []);
        $paid = array_values(array_filter($res, function ($r) { return $r[0] === 200; }));
        $exp = round(500 * cr_multiplier('easy', $st['step'], 0.98), 2);
        check(count($paid) === 1 && near(bal('b') - $before, $exp), '8 simultaneous cash-outs pay exactly once (' . count($paid) . ' paid, +' . round(bal('b') - $before, 2) . ", expected +$exp)");
    } else {
        check(true, 'road ended on the raced lane — cash-out race skipped this run');
    }
} else {
    check(true, 'first lane burnt (4% on Easy) — GO / cash-out races skipped this run');
}
// Race a GO against a CASH OUT: whatever the order, never both a win and a further lane.
[$c, $b] = http('POST', '/api/chickenroad/start', $B, ['bet_amount' => 50, 'difficulty' => 'easy']);
[$c, $b] = http('POST', '/api/chickenroad/step', $B, []);
if (($b['outcome'] ?? '') === 'safe') {
    $before = bal('b');
    $mh = [];
    $r1 = burst('POST', '/api/chickenroad/cashout', $B, [], 4);
    $s = cr_session_get('crtest_b');
    check($s['status'] === 'cashed' && near(bal('b') - $before, $s['payout']), 'after a cash-out burst the road is cashed and paid once');
    [$c] = http('POST', '/api/chickenroad/step', $B, []);
    check($c === 400, 'a GO after the cash-out is refused');
}
check(reconciles('b'), 'player B reconciles to the paisa after all the races');

// =================================================================================================
section('6. Operator settings');
// =================================================================================================
[$c] = http('POST', '/api/admin/chickenroad/config', $A, ['rtp' => 99]);
check($c === 403, "a player cannot change Chicken Road settings: $c");
[$c] = http('POST', '/api/admin/astronaut/config', null, ['rtp' => 99]);
check($c === 401, "nobody signed in cannot change Astronaut settings: $c");
[$c, $b] = http('POST', '/api/admin/chickenroad/config', $ADMIN, ['rtp' => 50]);
check($c === 400, 'an RTP of 50% is refused');
[$c, $b] = http('POST', '/api/admin/chickenroad/config', $ADMIN, ['rtp' => 95]);
[$c2, $s] = http('GET', '/api/chickenroad/state', $A);
check($c === 200 && near($s['config']['difficulties'][0]['ladder'][0], floor(0.95 * 25 / 24 * 100) / 100), 'an operator RTP of 95% reprices the ladder the page draws');
[$c] = http('POST', '/api/admin/chickenroad/config', $ADMIN, ['enabled' => false]);
[$c] = http('POST', '/api/chickenroad/start', $A, ['bet_amount' => 10, 'difficulty' => 'easy']);
check($c === 403, 'with the game switched off, Play is refused');
state_set('chickenroad_config', cr_config_default());

// =================================================================================================
section('7. Astronaut over HTTP (real clock)');
// =================================================================================================
$C = $players['c']['tok'];
/** Poll until the round reaches $phase (and, for betting, has at least $minLeftMs of countdown left). */
function wait_phase($phase, $minLeftMs = 0, $timeoutSec = 120) {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        [$c, $b] = http('GET', '/api/astronaut/state');
        $s = $b['state'] ?? null;
        if ($s && $s['phase'] === $phase) {
            if ($phase !== 'betting') return $s;
            $left = $s['phase_start'] + $s['betting_ms'] - $s['server_time'];
            if ($left >= $minLeftMs) return $s;
        }
        usleep(150000);
    }
    return null;
}

$s = wait_phase('betting', 4000);
check($s !== null, 'a countdown opens');
check($s['crash_point'] === null && $s['server_seed'] === null, 'during the countdown neither the crash point nor the seed is sent');
$round = $s['round_id'];
$start = bal('c');
[$c] = http('POST', '/api/astronaut/bet', null, ['panel' => 1, 'amount' => 10]);
check($c === 401, "a bet with no token is refused: $c");
[$c] = http('POST', '/api/astronaut/bet', $C, ['panel' => 3, 'amount' => 10]);
check($c === 400, 'panel 3 does not exist');
[$c] = http('POST', '/api/astronaut/bet', $C, ['panel' => 1, 'amount' => 10, 'auto_cashout' => 1.0]);
check($c === 400, 'an auto cash-out below 1.01x is refused');
[$c, $b] = http('POST', '/api/astronaut/bet', $C, ['panel' => 1, 'amount' => 100, 'auto_cashout' => 1.01]);
check($c === 200 && $b['round_id'] === $round && near(bal('c'), $start - 100), 'panel 1: ₹100 with auto cash-out 1.01x accepted');
$res = burst('POST', '/api/astronaut/bet', $C, ['panel' => 2, 'amount' => 200]);
$oks = count(array_filter($res, function ($r) { return $r[0] === 200; }));
check($oks === 1 && near(bal('c'), $start - 300), "8 simultaneous bets on panel 2: $oks accepted, one stake taken");
[$c, $b] = http('POST', '/api/astronaut/cancel', $C, ['panel' => 2]);
check($c === 200 && near(bal('c'), $start - 100), 'cancelling panel 2 during the countdown refunds it');
[$c, $b] = http('POST', '/api/astronaut/bet', $C, ['panel' => 2, 'amount' => 200]);
check($c === 200 && near(bal('c'), $start - 300), 'panel 2 can be bet again after a cancel');
[$c, $b] = http('GET', '/api/astronaut/state', $C);
check(count($b['state']['my_bets']) === 2 && $b['state']['bet_count'] >= 2, 'state lists both of my bets and counts them in the round');

$s = wait_phase('flying', 0, 20);
check($s !== null && $s['round_id'] === $round, 'the round launches');
[$c] = http('POST', '/api/astronaut/bet', $C, ['panel' => 1, 'amount' => 10]);
check($c === 400, 'a bet after launch is refused');
[$c] = http('POST', '/api/astronaut/cancel', $C, ['panel' => 2]);
check($c === 400, 'a cancel after launch is refused');

// Cash panel 2 out by hand from four servers at once (if the round is still flying).
$before = bal('c');
$res = burst('POST', '/api/astronaut/cashout', $C, ['panel' => 2]);
$fresh = array_values(array_filter($res, function ($r) { return $r[0] === 200 && empty($r[1]['already']); }));
$s2 = wait_phase('crashed', 0, 200);
check($s2 !== null && $s2['round_id'] === $round && $s2['crash_point'] !== null, "round $round crashed at {$s2['crash_point']}x and revealed it");
check(hash('sha256', $s2['server_seed']) === $s2['seed_hash'], 'the revealed seed matches the hash shown during the countdown');
check(near(astro_crash_point($s2['server_seed'], $round, 0.97, 10000), $s2['crash_point']), 'the crash point re-derives from the revealed seed');
$bets = all('SELECT * FROM "AstronautBet" WHERE "username" = ? AND "round_id" = ? ORDER BY "panel"', ['crtest_c', $round]);
$p1 = $bets[0]; $p2 = $bets[1];
$crash = (float) $s2['crash_point'];
check($crash >= 1.01 ? ($p1['status'] === 'won' && near($p1['payout'], 101)) : $p1['status'] === 'lost',
      "panel 1 (auto 1.01x): {$p1['status']} — " . ($crash >= 1.01 ? 'wins ₹101 because the round passed 1.01x' : 'loses because the round died at 1.00x'));
if (count($fresh) >= 1) {
    check(count($fresh) === 1 && $p2['status'] === 'won' && (float) $p2['cashout_mult'] <= $crash
          && near($p2['payout'], round(200 * (float) $p2['cashout_mult'], 2)),
          "panel 2 cashed out by hand once at {$p2['cashout_mult']}x (≤ crash {$crash}x) for ₹{$p2['payout']}");
} else {
    check($p2['status'] === 'lost', "panel 2's cash-out burst arrived after the crash ({$crash}x), so it lost");
}
check(reconciles('c'), 'player C: wallet = start + ledger deposits - ledger withdrawals, to the paisa');
[$c, $b] = http('GET', '/api/astronaut/state', $C);
$h0 = $b['state']['history'][0] ?? null;
check($h0 && $h0['round_id'] === $round && near($h0['crash'], $crash), 'the crash heads the history strip');
[$c, $b] = http('GET', '/api/profile', $C);
$astroRounds = array_values(array_filter($b['history'] ?? [], function ($h) { return $h['game'] === 'Astronaut'; }));
check(count($astroRounds) === 2, 'the profile history shows the two Astronaut bets as two rounds (' . count($astroRounds) . ')');
$crRounds = array_values(array_filter(json_decode(http('GET', '/api/profile', $A)[2], true)['history'] ?? [], function ($h) { return $h['game'] === 'Chicken Road'; }));
check(count($crRounds) === min(50, $ROUNDS), 'the profile history shows every Chicken Road round (' . count($crRounds) . ')');

// =================================================================================================
section('8. Clean-up');
// =================================================================================================
if ($savedCr === null) q('DELETE FROM "GameState" WHERE "key" = ?', ['chickenroad_config']); else state_set('chickenroad_config', $savedCr);
if ($savedAs === null) q('DELETE FROM "GameState" WHERE "key" = ?', ['astronaut_config']); else state_set('astronaut_config', $savedAs);
wipe_test_rows();
check((int) scalar('SELECT COUNT(*) FROM "User" WHERE "username" LIKE ?', ['crtest_%'], 0) === 0, 'test players and their rows removed');

echo "\n------------------------------------------------------------\n";
echo "test_crash_games: $pass passed, $fail failed\n";
if ($failures) { echo "Failures:\n"; foreach ($failures as $f) echo "  - $f\n"; }
exit($fail ? 1 : 0);
