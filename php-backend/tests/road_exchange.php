<?php
/**
 * Road test — cricket match betting (Match Odds, Bookmaker, Fancy, Cash Out) under load.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_exchange.php [--users=50] [--keep]
 *
 * 50 players bet through a whole simulated T20 on every market, cash out, and fire genuinely
 * simultaneous bets from separate processes. Every result and every payout is recomputed here
 * independently — the winner from the innings totals, each fancy line from the raw deliveries, each
 * bet's terms from its stake and price, each "too late" void from the ball times — and every rupee is
 * reconciled against the wallets and the ledger. A rained-off match must refund every stake.
 */
require __DIR__ . '/road_common.php';

$N = 50;
foreach ($argv as $a) if (preg_match('/^--users=(\d+)$/', $a, $m)) $N = max(4, (int) $m[1]);
road_wipe_users('x');
mt_srand(20261010);

// -------------------------------------------------------------------------------------------------
road_section('Setup');
$SLOT = road_slot(1300000);
$KEY = cricket_mock_match_key($SLOT);
road_wipe_match($KEY);
// Most players well funded, a few nearly broke (for the balance races), one exactly at the minimum.
$users = road_users('x', $N, function ($i) { return $i <= 3 ? [150, 250, 100][$i - 1] : 50000; });
$U = array_values($users);
$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];
$cfg = mx_config();
road_info("$N players, match $KEY ({$sim['meta']['title']}), min stake ₹{$cfg['min_stake']}, house cap ₹{$cfg['house_max_loss_market']} per market");
road_check(count($sim['balls']) > 150, 'the simulated match has a full set of deliveries (' . count($sim['balls']) . ')');

// Independent recomputations from the simulation's own ball list -------------------------------------
// Read straight from the simulator's raw balls (the provider's shape), not through our parser: innings by
// position in its innings order, runs = team_score.runs, legal = not a wide / no-ball.
$innPos = array_flip(array_values($sim['innings_order']));
$inns = [];
foreach ($sim['balls'] as $b) {
    $i = ($innPos[$b['innings']] ?? 0) + 1;
    $inns[$i][] = ['side' => substr($b['innings'], 0, 1), 'runs' => (int) $b['team_score']['runs'],
                   'legal' => !in_array(strtolower(str_replace([' ', '-'], '_', $b['ball_type'])), ['wide', 'no_ball', 'noball'], true)];
}

// -------------------------------------------------------------------------------------------------
road_section('Bad bets are refused before money moves');
road_at($start - 20 * 60000);
cricket_feed_ingest(cricket_mock_snapshot($KEY, $start - 20 * 60000), 'mock', $start - 20 * 60000);
mx_process_match($KEY, $start - 20 * 60000);
$book = mx_markets($KEY, $start - 20 * 60000);
$mo = $book['markets']['MO'] ?? null;
road_check($mo && $mo['open'], 'pre-match: Match Odds is open (' . ($mo['reason'] ?? '?') . ')');
$u = $U[10]; $before = road_balance($u);
$bad = [
    'zero stake'          => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 0],
    'text stake'          => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 'abc'],
    'below minimum'       => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => $cfg['min_stake'] - 1],
    'above maximum'       => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => $cfg['max_stake_mo'] + 1],
    'no such market'      => ['market_key' => 'XX', 'side' => 'BACK', 'selection' => 'a', 'stake' => 200],
    'no side'             => ['market_key' => 'MO', 'side' => 'UP', 'selection' => 'a', 'stake' => 200],
    'no team'             => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'z', 'stake' => 200],
    'price better than offered' => ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'price' => (float) $mo['runners'][0]['back'][0] + 1, 'stake' => 200],
];
foreach ($bad as $label => $in) {
    $r = mx_place_bet($u, $KEY, $in);
    road_check(!$r['ok'], "refused: $label" . (!$r['ok'] ? ' (' . $r['error'] . ')' : ''));
}
$r = mx_place_bet($U[2], $KEY, ['market_key' => 'MO', 'side' => 'LAY', 'selection' => 'a', 'stake' => 5000]);
road_check(!$r['ok'] && $r['status'] === 402, 'refused: a lay whose liability is more than the wallet holds');
road_check(road_near(road_balance($u), $before), 'no refused bet moved a paisa');

// -------------------------------------------------------------------------------------------------
road_section('Simultaneous bets from separate processes');
// A ₹250 wallet fires five ₹100 bets at the same instant: at most two can be paid for.
$poor = $U[1];
$clock = $start - 19 * 60000;
road_at($clock);
$tasks = [];
for ($i = 0; $i < 5; $i++) $tasks[] = ['action' => 'mx_bet', 'user_id' => $poor['id'], 'match_key' => $KEY, 'clock' => $clock,
                                       'bet' => ['market_key' => $i % 2 ? 'BM' : 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 100]];
$res = road_parallel($tasks);
$ok = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
$bal = road_balance($poor);
road_check($ok === 2 && road_near($bal, 50), "a ₹250 wallet firing five ₹100 bets at once: exactly $ok struck, balance ₹$bal, never negative", $res);
// Twenty different players hit the same market at once: every one is struck, none lost, exposure seen in full.
$tasks = [];
for ($i = 10; $i < 30; $i++) $tasks[] = ['action' => 'mx_bet', 'user_id' => $U[$i]['id'], 'match_key' => $KEY, 'clock' => $clock,
                                         'bet' => ['market_key' => 'MO', 'side' => $i % 3 ? 'BACK' : 'LAY', 'selection' => $i % 2 ? 'a' : 'b', 'stake' => 500]];
$res = road_parallel($tasks);
$ok = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
$rows = (int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "user_id" IN (' . implode(',', array_map(function ($i) use ($U) { return (int) $U[$i]['id']; }, range(10, 29))) . ')', [$KEY], 0);
road_check($ok === 20 && $rows === 20, "20 players betting at the same instant: $ok struck, $rows rows, nothing lost or doubled", array_filter($res, function ($r) { return empty($r['ok']); }));
// One player cashes out the same market from three processes at once: paid once.
$cu = $U[12]; $b0 = road_balance($cu);
$res = road_parallel(array_fill(0, 3, ['action' => 'mx_cashout', 'user_id' => $cu['id'], 'match_key' => $KEY, 'market_key' => 'MO', 'clock' => $clock]));
$ok = array_values(array_filter($res, function ($r) { return !empty($r['ok']); }));
$credited = road_balance($cu) - $b0;
road_check(count($ok) === 1 && road_near($credited, $ok[0]['value'] ?? -1), 'three simultaneous cash-outs of one position: paid exactly once (₹' . round($credited, 2) . ')', $res);
// A very large lay on one side runs into the house cap rather than past it.
$big = 0; $refusedCap = false;
for ($i = 30; $i < 50 && $i < $N; $i++) {
    $r = mx_place_bet($U[$i], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'b', 'stake' => $cfg['max_stake_mo']]);
    if ($r['ok']) $big++; elseif (strpos($r['error'], 'limit') !== false) { $refusedCap = true; break; }
}
$worst = mx_house_worst(all('SELECT * FROM "mx_bets" WHERE "match_key" = ? AND "market_key" = ? AND "status" = ?', [$KEY, 'MO', 'PENDING']), 'MATCH_ODDS');
road_check($refusedCap && $worst >= -(float) $cfg['house_max_loss_market'] - 0.01,
           "piling onto one side stops at the house cap: $big maximum bets struck, then refused; house worst case ₹" . round($worst, 2));

// -------------------------------------------------------------------------------------------------
road_section("A full match: $N players on every market");
$placed = 0; $refused = 0; $types = []; $cashouts = 0; $susp = 0;
for ($t = $start - 18 * 60000; $t <= $sim['ended_ms'] + 120000; $t += ($t < $start - 60000 ? 240000 : 3000)) {
    road_at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY, $t), 'mock', $t);
    mx_process_match($KEY, $t);
    $book = mx_markets($KEY, $t);
    if (!$book) continue;
    $keys = array_keys($book['markets']);
    for ($k = 0; $k < 3; $k++) {
        $u = $U[mt_rand(3, $N - 1)];
        $mk = $keys[mt_rand(0, count($keys) - 1)];
        $mm = $book['markets'][$mk];
        if ($mm['type'] === 'FANCY') {
            $side = mt_rand(0, 1) ? 'YES' : 'NO';
            $in = ['market_key' => $mk, 'side' => $side, 'line' => $side === 'YES' ? $mm['yes_line'] : $mm['no_line'],
                   'rate' => $side === 'YES' ? $mm['yes_rate'] : $mm['no_rate'], 'stake' => [100, 200, 500][mt_rand(0, 2)]];
        } else {
            $sel = mt_rand(0, 1) ? 'a' : 'b';
            $rr = $mm['runners'][$sel === 'a' ? 0 : 1];
            $side = mt_rand(0, 2) ? 'BACK' : 'LAY';
            if (!$rr['back']) continue;
            $price = $mm['type'] === 'MATCH_ODDS' ? ($side === 'BACK' ? $rr['back'][0] : $rr['lay'][0]) : ($side === 'BACK' ? $rr['back'] : $rr['lay']);
            $in = ['market_key' => $mk, 'side' => $side, 'selection' => $sel, 'price' => $price, 'stake' => [100, 250, 1000][mt_rand(0, 2)]];
        }
        $r = mx_place_bet($u, $KEY, $in);
        if ($r['ok']) { $placed++; $types[$mm['type']] = ($types[$mm['type']] ?? 0) + 1; }
        else { $refused++; if (!$mm['open']) $susp++; }
    }
    if (mt_rand(0, 25) === 0) { $c = mx_cashout($U[mt_rand(3, $N - 1)], $KEY, mt_rand(0, 1) ? 'MO' : 'BM'); if ($c['ok']) $cashouts++; }
}
road_clock_off();
$feed = cricket_match_feed_row($KEY);
road_info("$placed bets struck (" . json_encode($types) . "), $refused refused ($susp on a suspended market), $cashouts cash-outs; result: {$feed['result_text']}");
road_check($placed > 400 && count($types) === 3, 'bets struck on Match Odds, Bookmaker and Fancy all match long');
road_check($susp > 0, 'bets on a suspended market (ball running / wicket) are refused');
road_check((int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "status" = ?', [$KEY, 'PENDING'], 0) === 0, 'no bet is left pending once the match is over');

// Winner, independently: higher innings total (the simulation never ties a road match by construction; a tie voids).
$tot = []; $sideAt = [];
foreach ($inns as $i => $balls) { $tot[$i] = array_sum(array_column($balls, 'runs')); $sideAt[$i] = $balls[0]['side']; }
$winner = ($tot[1] ?? 0) === ($tot[2] ?? 0) ? null : (($tot[1] ?? 0) > ($tot[2] ?? 0) ? $sideAt[1] : $sideAt[2]);
road_check($winner === ($sim['result']['winner'] ?? null), 'the independent recount agrees with the simulator own result (' . ($sim['result']['msg'] ?? '') . ')');
foreach (['MO', 'BM'] as $mk) {
    $m = one('SELECT * FROM "mx_markets" WHERE "match_key" = ? AND "market_key" = ?', [$KEY, $mk]);
    road_check($m && ($winner === null ? $m['status'] === 'VOID' : ($m['status'] === 'SETTLED' && $m['result'] === $winner)),
               "$mk settled on the side that scored more (" . json_encode($tot) . " → " . ($winner ?? 'tie') . ')', $m);
}

// Every fancy line, recounted from the simulation's balls (not from our stored deliveries).
$badF = 0; $nF = 0;
foreach (all('SELECT * FROM "mx_markets" WHERE "match_key" = ? AND "market_type" = ?', [$KEY, 'FANCY']) as $fm) {
    preg_match('/^(ms|ov)_(\d)_(\d+)$/', $fm['market_key'], $mm);
    $balls = $inns[(int) $mm[2]] ?? []; $n = (int) $mm[3];
    $legal = 0; $cum = 0; $res = null; $ov = 0; $ovDone = false;
    foreach ($balls as $b) {
        $r = $b['runs'];
        if ($mm[1] === 'ov' && $legal >= $n * 6 && $legal < $n * 6 + 6) $ov += $r;
        $cum += $r;
        if ($b['legal']) { $legal++; if ($mm[1] === 'ms' && $legal === $n * 6) $res = $cum; if ($mm[1] === 'ov' && $legal === $n * 6 + 6) { $res = $ov; $ovDone = true; } }
    }
    if ($res === null && $mm[1] === 'ms') $res = $cum;
    $nF++;
    $expectVoid = $mm[1] === 'ov' && !$ovDone;
    if ($expectVoid ? $fm['status'] !== 'VOID' : ($fm['status'] !== 'SETTLED' || (string) $res !== (string) $fm['result'])) $badF++;
}
road_check($nF > 5 && $badF === 0, "every fancy line agrees with an independent recount of the simulation's balls ($nF lines)");

// Every bet, independently: its terms from stake and price, its result from its market, its payout.
$bad = []; $byStatus = [];
foreach (all('SELECT b.*, m."result" AS "mres", m."status" AS "mstat" FROM "mx_bets" b LEFT JOIN "mx_markets" m ON m."match_key" = b."match_key" AND m."market_key" = b."market_key" WHERE b."match_key" = ?', [$KEY]) as $b) {
    $byStatus[$b['status']] = ($byStatus[$b['status']] ?? 0) + 1;
    $s = (float) $b['stake']; $o = (float) $b['odds']; $rt = (float) $b['rate'];
    [$liab, $prof] = ['BACK' => [$s, $s * ($o - 1)], 'LAY' => [$s * ($o - 1), $s], 'YES' => [$s, $s * $rt / 100], 'NO' => [$s * $rt / 100, $s]][$b['side']];
    if (!road_near($liab, $b['liability'], 0.02) || !road_near($prof, $b['profit'], 0.02)) { $bad[] = "terms #{$b['id']}"; continue; }
    if ($b['market_type'] === 'BOOKMAKER' && !road_near($o, 1 + $rt / 100, 0.0001)) { $bad[] = "bm odds #{$b['id']}"; continue; }
    switch ($b['status']) {
        case 'CASHED_OUT': if ((float) $b['payout'] < 0) $bad[] = "cashout #{$b['id']}"; break;
        case 'VOID':
            if (!road_near($b['payout'], $liab, 0.02)) $bad[] = "void payout #{$b['id']}";
            if ($b['mstat'] !== 'VOID' && strpos((string) $b['void_reason'], 'ball') === false) $bad[] = "void reason #{$b['id']}";
            break;
        case 'WON': case 'LOST':
            $r = $b['mres'];
            $wins = ['BACK' => $b['selection'] === $r, 'LAY' => $b['selection'] !== $r, 'YES' => (int) $r >= (int) $b['line'], 'NO' => (int) $r < (int) $b['line']][$b['side']];
            if ($wins !== ($b['status'] === 'WON')) $bad[] = "result #{$b['id']}";
            if ($b['status'] === 'WON' && !road_near($b['payout'], $liab + $prof, 0.03)) $bad[] = "win payout #{$b['id']}";
            if ($b['status'] === 'LOST' && (float) $b['payout'] !== 0.0) $bad[] = "lost payout #{$b['id']}";
            break;
        default: $bad[] = "status {$b['status']} #{$b['id']}";
    }
}
road_check(!$bad, 'every bet: terms from stake × price, result from its market, payout exactly right ' . json_encode($byStatus), array_slice($bad, 0, 10));

// A bet voided as "struck while a ball was being bowled" really was struck inside that window.
$guard = (int) $cfg['ball_guard_seconds'] * 1000; $badLate = 0; $nLate = 0;
$dl = cricket_match_deliveries($KEY);
foreach (all('SELECT * FROM "mx_bets" WHERE "match_key" = ? AND "void_reason" LIKE ?', [$KEY, '%ball%']) as $b) {
    $nLate++; $t = sql_to_ms($b['created_at']); $inside = false;
    foreach ($dl as $d) if ($t >= $d['first_seen_ms'] - $guard && $t <= max($d['first_seen_ms'], $d['received_ms'])) { $inside = true; break; }
    if (!$inside) $badLate++;
}
road_check($badLate === 0, "every 'too late' void was genuinely struck inside a ball's window ($nLate voids)");

// -------------------------------------------------------------------------------------------------
road_section('A rained-off match refunds everything');
$SLOT2 = road_slot(1400000);
$K2 = cricket_mock_match_key($SLOT2);
road_wipe_match($K2);
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$sim2 = cricket_mock_simulate($SLOT2);
$s2 = $sim2['meta']['start_ms'];
$staked = []; $n2 = 0;
for ($t = $s2 - 10 * 60000; $t <= ($sim2['ended_ms'] ?? $s2 + 3 * 3600000) + 120000; $t += ($t < $s2 ? 120000 : 6000)) {
    road_at($t);
    cricket_feed_ingest(cricket_mock_snapshot($K2, $t), 'mock', $t);
    mx_process_match($K2, $t);
    if ($n2 < 60) {
        $u = $U[mt_rand(3, $N - 1)];
        $r = mx_place_bet($u, $K2, ['market_key' => mt_rand(0, 1) ? 'MO' : 'BM', 'side' => 'BACK', 'selection' => mt_rand(0, 1) ? 'a' : 'b', 'stake' => 200]);
        if ($r['ok']) $n2++;
    }
}
road_clock_off();
putenv('CRICKET_MOCK_ABANDON_EVERY=0');
$f2 = cricket_match_feed_row($K2);
$left = all('SELECT "status", COUNT(*) n FROM "mx_bets" WHERE "match_key" = ? GROUP BY 1', [$K2]);
$refundOk = (int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND ("status" <> ? OR "payout" <> "liability")', [$K2, 'VOID'], 0) === 0;
road_info("match status: {$f2['status']}, $n2 bets: " . json_encode($left));
road_check($f2['status'] === 'abandoned' && $n2 > 0 && $refundOk, 'abandoned: every bet VOID with its full stake back');

// -------------------------------------------------------------------------------------------------
road_section('Every rupee reconciled');
$neg = 0; $badU = [];
foreach ($U as $u) {
    $now = road_balance($u);
    if ($now < -0.001) $neg++;
    $liab = (float) scalar('SELECT COALESCE(SUM("liability"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $paid = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $l = road_ledger($u['username']);
    if (!road_near($now, $u['_start'] - $liab + $paid, 0.02) || !road_near($l['out'], $liab, 0.02) || !road_near($l['in'], $paid, 0.02)) $badU[] = $u['username'];
}
road_check($neg === 0, 'no wallet ever went negative');
road_check(!$badU, "all $N wallets: start − risked + returned = balance now, and the ledger says the same", $badU);
$risked = (float) scalar('SELECT COALESCE(SUM("liability"),0) FROM "mx_bets" WHERE "match_key" IN (?,?)', [$KEY, $K2], 0);
$returned = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "mx_bets" WHERE "match_key" IN (?,?)', [$KEY, $K2], 0);
road_info(sprintf('players risked ₹%s, got back ₹%s; house result ₹%s', number_format($risked, 2), number_format($returned, 2), number_format($risked - $returned, 2)));

if (!$GLOBALS['ROAD_KEEP']) { road_wipe_match($KEY); road_wipe_match($K2); road_wipe_users('x'); }
road_finish('road_exchange');
