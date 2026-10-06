<?php
/**
 * ROAD TEST — both cricket games over real HTTP, 50 signed-in players.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_http.php [--users=50] [--port=5099]
 *
 * Starts the site on its own port (php -S 127.0.0.1:5099 router.php — never touches a server you
 * already have running on 5000), signs 50 players in with real session tokens, and drives the same
 * endpoints the pages call: Your 11 (matches, players, save team, contests, join, my teams, my entries,
 * leaderboard) and Ball by Ball (lobby, live state, bet, my bets) — plus the attacks: no token, a forged
 * token, a player token on operator routes, somebody else's team, a bet on another match's market.
 *
 * Your 11 runs on a private far-future fixture created for the test. Ball by Ball needs a match that is
 * live on the real clock, so it uses whichever demo-league match is in play; if none is, that part
 * reports itself as skipped rather than failing.
 */
require __DIR__ . '/road_common.php';

$NU = 50; $PORT = 5099;
foreach ($argv as $a) {
    if (preg_match('/^--users=(\d+)$/', $a, $m)) $NU = max(5, (int) $m[1]);
    if (preg_match('/^--port=(\d+)$/', $a, $m)) $PORT = (int) $m[1];
}
$BASE = "http://127.0.0.1:$PORT";

function http($method, $path, $token = null, $body = null) {
    $ch = curl_init($GLOBALS['BASE'] . $path);
    $h = ['Accept: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), $raw];
}

// ---- server -------------------------------------------------------------------------------------
$env = array_merge(getenv(), ['CRICKET_SOURCE' => 'mock', 'FANTASY_SOURCE' => 'feed', 'CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true',
                              'CRICKET_MOCK_ABANDON_EVERY' => '0']);
$root = dirname(ROAD_ROOT);
$cmd = escapeshellarg(PHP_BINARY) . ' -d extension=pdo_pgsql -S 127.0.0.1:' . $PORT . ' ' . escapeshellarg($root . '/router.php');
$log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'road_http_server.log';
$server = proc_open($cmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
register_shutdown_function(function () use ($server) {
    if (!is_resource($server)) return;
    $st = proc_get_status($server);
    if (stripos(PHP_OS, 'WIN') === 0) exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>NUL');
    else proc_terminate($server);
    proc_close($server);
});
$up = false;
for ($i = 0; $i < 60 && !$up; $i++) { usleep(250000); [$c] = http('GET', '/api/cricket/exchange-lobby'); $up = $c === 200; }

road_section("1. Server up on :$PORT, {$NU} players signed in");
road_check($up, "the site answers on $BASE");
if (!$up) road_finish('road_http');
$users = road_users('h', $NU, function ($i) { return 3000; });
$tok = [];
foreach ($users as $n => $u) $tok[$n] = issue_token(['id' => (int) $u['id'], 'username' => $n, 'role' => 'user']);
$names = array_keys($users);

road_section('2. Attacks that must bounce');
[$c] = http('POST', '/api/fantasy/teams', null, ['match_id' => 1]);
road_check($c === 401, "saving a team with no token: $c");
[$c] = http('POST', '/api/cricket/bbb/x/bet', null, ['round_id' => 1, 'outcome' => '4', 'stake' => 10]);
road_check($c === 401, "a Ball by Ball bet with no token: $c");
$parts = explode('.', $tok[$names[0]]);
$forged = $parts[0] . '.' . rtrim(strtr(base64_encode(json_encode(['v' => $parts[0], 'id' => 1, 'username' => 'admin', 'role' => 'admin', 'iat' => now_ms(), 'exp' => now_ms() + 9e6])), '+/', '-_'), '=') . '.' . $parts[2];
[$c] = http('POST', '/api/admin/fantasy/sync', $forged, []);
road_check($c === 401, "a forged admin token (re-signed payload, old signature): $c");
[$c] = http('POST', '/api/admin/cricket/bbb/config', $tok[$names[0]], ['rake_pct' => 0]);
road_check($c === 403, "a player's token on an operator route: $c");
[$c] = http('POST', '/api/admin/fantasy/contests/1/settle', $tok[$names[0]], []);
road_check($c === 403, "a player trying to settle a contest: $c");

// ---- Your 11 ------------------------------------------------------------------------------------
road_section('3. Your 11 over HTTP');
$SLOT = road_slot(1800000);
$KEY = cricket_mock_match_key($SLOT);
road_wipe_match($KEY);
$mid = fantasy_feed_upsert_fixture(road_mock_fixture($SLOT))['match_id'];
[$c, $j] = http('GET', '/api/fantasy/matches');
$listed = false;
foreach ($j['groups'] ?? [] as $g) foreach ($g['matches'] as $m) if ((int) $m['id'] === $mid) $listed = true;
road_check($c === 200 && $listed, 'the new fixture shows in the matches lobby');
[$c, $pj] = http('GET', "/api/fantasy/matches/$mid/players");
road_check($c === 200 && count($pj['players']['WK'] ?? []) > 0 && isset($pj['scoring_rules']), 'players endpoint returns the squad grouped by role, with the scoring rules');
[$c, $cj] = http('GET', "/api/fantasy/matches/$mid/contests");
$contests = $cj['contests'] ?? [];
road_check($c === 200 && count($contests) >= 5, count($contests) . ' contests listed');
usort($contests, function ($a, $b) { return $b['total_spots'] <=> $a['total_spots']; });
$mega = $contests[0];

$saved = 0; $joined = 0; $teamOf = []; $moneyOk = true; $codes = [];
foreach ($names as $i => $n) {
    $xi = road_legal_xi($mid, 40000 + $i);
    $ids = array_column($xi, 'id');
    [$c, $tj] = http('POST', '/api/fantasy/teams', $tok[$n], ['match_id' => $mid, 'players' => $ids, 'captain_player_id' => $ids[$i % 11], 'vice_captain_player_id' => $ids[($i + 1) % 11], 'team_name' => 'H' . $i]);
    if ($c !== 200) { $codes[] = "save $c " . json_encode($tj); continue; }
    $saved++;
    $teamOf[$n] = (int) $tj['team']['id'];
    $before = road_balance($users[$n]);
    [$c, $jj] = http('POST', "/api/fantasy/contests/{$mega['id']}/join", $tok[$n], ['team_id' => $teamOf[$n]]);
    if ($c === 200) {
        $joined++;
        if (!road_near($jj['new_balance'], $before - $mega['entry_fee']) || !road_near(road_balance($users[$n]), $before - $mega['entry_fee'])) $moneyOk = false;
    } else $codes[] = "join $c " . json_encode($jj);
}
road_check($saved === $NU && $joined === $NU, "$saved teams saved and $joined mega entries paid over HTTP", $codes ? array_slice($codes, 0, 4) : null);
road_check($moneyOk, 'each join charged exactly the entry fee, and the response reported the true new balance');
[$c, $e] = http('POST', "/api/fantasy/contests/{$mega['id']}/join", $tok[$names[0]], ['team_id' => $teamOf[$names[1]]]);
road_check($c >= 400 && $c < 500, "joining with somebody else's team is refused ($c)");
[$c] = http('POST', "/api/fantasy/contests/{$mega['id']}/join", $tok[$names[0]], ['team_id' => 0]);
road_check($c === 422, "a join with no team is refused ($c)");
[$c] = http('POST', "/api/fantasy/teams/{$teamOf[$names[1]]}", $tok[$names[0]], ['match_id' => $mid, 'players' => [], 'captain_player_id' => 0, 'vice_captain_player_id' => 0]);
road_check($c >= 400 && $c < 500, "editing somebody else's team is refused ($c)");
[$c, $t] = http('GET', "/api/fantasy/teams/{$teamOf[$names[1]]}", $tok[$names[0]]);
road_check($c >= 400 && $c < 500, "viewing somebody else's team before the deadline is refused ($c)");
[$c, $mt] = http('GET', '/api/fantasy/my-teams?match_id=' . $mid, $tok[$names[2]]);
[$c2, $me] = http('GET', '/api/fantasy/my-entries?match_id=' . $mid, $tok[$names[2]]);
road_check($c === 200 && $c2 === 200 && count($mt['teams'] ?? []) === 1 && count($me['entries'] ?? []) === 1, 'my teams / my entries show exactly that player\'s own team and entry');
[$c, $lb] = http('GET', "/api/fantasy/contests/{$mega['id']}/leaderboard");
road_check($c === 200 && count($lb['entries'] ?? $lb['leaderboard'] ?? []) === $NU, "the contest leaderboard lists all $NU entries");
[$c, $mm] = http('GET', '/api/fantasy/my-matches', $tok[$names[3]]);
road_check($c === 200, 'my matches loads');

// ---- Ball by Ball -------------------------------------------------------------------------------
road_section('4. Ball by Ball over HTTP (on whichever demo match is live right now)');
[$c, $lob] = http('GET', '/api/cricket/bbb/matches');
road_check($c === 200 && isset($lob['live']), 'the Ball by Ball lobby loads (' . count($lob['live'] ?? []) . ' live, ' . count($lob['upcoming'] ?? []) . ' upcoming)');
$live = $lob['live'][0]['match_key'] ?? null;
if (!$live) {
    road_info('SKIPPED: no demo match is live on the real clock right now — rerun during a match to cover live betting over HTTP.');
} else {
    $round = null; $deadline = microtime(true) + 120;
    while (microtime(true) < $deadline) {
        [$c, $st] = http('GET', "/api/cricket/bbb/$live/state", $tok[$names[0]]);
        $r = $st['round'] ?? null;
        if ($r && $r['status'] === 'OPEN' && $r['closes_ms'] - $st['server_time_ms'] >= 6000) { $round = $r; break; }
        usleep(700000);
    }
    if (!$round) {
        road_info('SKIPPED: the live match never offered a market with 6 seconds left in two minutes (innings break or stall).');
    } else {
        $okBets = 0; $refused = []; $outs = ['0', '1', '2', '3', '4', '6', 'W', 'EX'];
        $before = []; foreach ($names as $n) $before[$n] = road_balance($users[$n]);
        $t0 = microtime(true);
        foreach ($names as $i => $n) {
            [$c, $bj] = http('POST', "/api/cricket/bbb/$live/bet", $tok[$n], ['round_id' => $round['id'], 'outcome' => $outs[$i % 8], 'stake' => 10]);
            if ($c === 200) $okBets++; else $refused[] = "$c " . ($bj['error'] ?? '');
        }
        $secs = microtime(true) - $t0;
        road_info(sprintf('%d bets sent in %.1fs (%.0f ms each)', $NU, $secs, 1000 * $secs / $NU));
        $closedRefusals = count(array_filter($refused, function ($x) { return stripos($x, 'clos') !== false || stripos($x, 'suspend') !== false; }));
        road_check($okBets + $closedRefusals === $NU && $okBets > 0, "$okBets bets accepted on {$round['label']}" . ($closedRefusals ? ", $closedRefusals refused because the market closed mid-burst" : ''), $refused ? array_slice($refused, 0, 4) : null);
        $mine = (int) scalar('SELECT COUNT(*) FROM "bbb_bets" b JOIN "User" u ON u."id" = b."user_id" WHERE b."round_id" = ? AND u."username" LIKE ?', [(int) $round['id'], 'road_h%'], 0);
        road_check($mine === $okBets, "the database holds exactly those $okBets bets");
        [$c, $st] = http('GET', "/api/cricket/bbb/$live/state", $tok[$names[0]]);
        road_check(count($st['my_bets'] ?? []) + count($st['my_last_bets'] ?? []) >= 1, "the state poll shows the player their own bet");
        [$c, $mb] = http('GET', '/api/cricket/bbb/my-bets', $tok[$names[1]]);
        road_check($c === 200 && count($mb['bets'] ?? []) >= 1, 'my bets lists the player\'s bet');
        // wrong-match key
        [$c] = http('POST', '/api/cricket/bbb/not_this_match/bet', $tok[$names[0]], ['round_id' => $round['id'], 'outcome' => '4', 'stake' => 10]);
        road_check($c === 404, "a bet sent to another match's address for this market is refused ($c)");
        [$c] = http('POST', "/api/cricket/bbb/$live/bet", $tok[$names[0]], ['round_id' => $round['id'], 'outcome' => '4', 'stake' => 'abc']);
        road_check($c >= 400 && $c < 500, "a non-numeric stake is refused ($c)");
        // wait for the market to resolve, then reconcile every wallet
        $deadline = microtime(true) + 150; $final = null;
        while (microtime(true) < $deadline) {
            http('GET', "/api/cricket/bbb/$live/state");
            $final = one('SELECT * FROM "bbb_rounds" WHERE "id" = ?', [(int) $round['id']]);
            if (in_array($final['status'], ['SETTLED', 'VOID'], true)) break;
            usleep(1500000);
        }
        if (!in_array($final['status'] ?? '', ['SETTLED', 'VOID'], true)) {
            road_info('the market had not resolved within 150s; wallet reconciliation skipped');
        } else {
            $ok = true;
            foreach ($names as $n) {
                $net = (float) scalar('SELECT COALESCE(SUM("payout"),0) - COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ?', [(int) $round['id'], (int) $users[$n]['id']], 0);
                if (!road_near(road_balance($users[$n]), $before[$n] + $net)) $ok = false;
            }
            road_check($ok, "{$round['label']} resolved ({$final['status']}" . ($final['outcome'] ? ": {$final['outcome']}" : '') . '); every wallet moved by exactly its stake and payout');
        }
    }
}

road_section('5. Server health');
$errs = array_filter(explode("\n", (string) @file_get_contents($log)), function ($l) { return preg_match('/Fatal|Uncaught|Warning|Parse error/i', $l); });
road_check(!$errs, 'no PHP fatals or warnings in the server log', $errs ? array_slice(array_values($errs), 0, 5) : null);

road_clock_off();
if (!$GLOBALS['ROAD_KEEP']) road_wipe_match($KEY);
// The road_h players are kept (reset at the start of every run) because any Ball by Ball bets they
// placed sit in a real demo-league pool; deleting them would leave that pool not adding up.
road_finish('road_http');
