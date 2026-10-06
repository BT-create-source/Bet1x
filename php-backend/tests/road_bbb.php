<?php
/**
 * ROAD TEST — Ball by Ball with 50 fake users betting through a whole match.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_bbb.php [--users=50] [--keep]
 *
 * Every delivery of a simulated T20 gets a market; on each one a random crowd of players stakes on the
 * eight outcomes, and some deliberately try what should be refused. In between, separate processes hit
 * the database at the same instant: a small wallet firing several bets at once, one player trying to
 * beat the per-ball cap, and duplicate pushes racing to settle the same ball. At the end every market,
 * every bet and every rupee is reconciled, and every result is checked against an independent reading
 * of the raw ball.
 */
require __DIR__ . '/road_common.php';
require __DIR__ . '/road_oracle.php';

$NU = 50;
foreach ($argv as $a) if (preg_match('/^--users=(\d+)$/', $a, $m)) $NU = max(10, (int) $m[1]);

road_wipe_users('b');
$SLOT = road_slot(1500000);
$KEY = cricket_mock_match_key($SLOT);
road_wipe_match($KEY);
$users = road_users('b', $NU, function ($i) use ($NU) { return $i > $NU - 5 ? [15, 40, 100, 9, 250][$i - ($NU - 4)] : 20000; });
$U = array_values($users);
$cfg = bbb_config();
$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];
$outcomes = array_keys(bbb_outcomes());

road_section("1. A full match of markets with {$NU} players");
mt_srand(2024);
$placed = 0; $staked = 0.0; $refused = ['closed' => 0, 'min' => 0, 'max' => 0, 'outcome' => 0, 'cap' => 0, 'balance' => 0, 'other' => []];
$raceDone = false; $race = [];
$step = 3000;
for ($t = $start - 60000; $t <= $sim['ended_ms'] + 120000; $t += $step) {
    road_at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY, $t), 'mock', $t);
    bbb_process_match($KEY, $t);
    $open = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? AND "closes_at" > ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN', ms_to_sql($t)]);
    if (!$open) continue;

    // ---- the crowd ----
    $crowd = mt_rand(6, 14);
    for ($c = 0; $c < $crowd; $c++) {
        $u = $U[mt_rand(0, $NU - 1)];
        $o = $outcomes[mt_rand(0, 7)];
        $stake = [10, 20, 50, 100, 250, 500, 1000, 2000][mt_rand(0, 7)];
        $r = bbb_place_bet($u, (int) $open['id'], $o, $stake);
        if ($r['ok']) { $placed++; $staked += $stake; }
        elseif ($r['status'] === 402) $refused['balance']++;
        elseif (stripos($r['error'], 'at most') !== false) $refused['cap']++;
        else $refused['other'][] = $r['error'];
    }
    // ---- deliberate bad bets ----
    if (mt_rand(0, 9) === 0) {
        $u = $U[0];
        if (!bbb_place_bet($u, (int) $open['id'], '4', 5)['ok']) $refused['min']++;
        if (!bbb_place_bet($u, (int) $open['id'], '4', 6000)['ok']) $refused['max']++;
        if (!bbb_place_bet($u, (int) $open['id'], '5', 100)['ok']) $refused['outcome']++;
        $last = one('SELECT "id" FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?,?) ORDER BY "id" DESC LIMIT 1', [$KEY, 'SETTLED', 'VOID']);
        if ($last && !bbb_place_bet($u, (int) $last['id'], '4', 100)['ok']) $refused['closed']++;
    }
    // ---- parallel races, once, mid-match ----
    if (!$raceDone && $t > $start + 15 * 60000) {
        $raceDone = true;
        // (a) a ₹100 wallet fires five ₹50 bets at the same instant: two can be paid for, never more.
        $poor = $users['road_b' . str_pad((string) ($NU - 2), 2, '0', STR_PAD_LEFT)];   // balance 100
        q('UPDATE "User" SET "wallet_balance" = 100 WHERE "id" = ?', [(int) $poor['id']]);
        $res = road_parallel(array_fill(0, 5, ['action' => 'bbb_bet', 'user_id' => $poor['id'], 'round_id' => (int) $open['id'], 'outcome' => '1', 'stake' => 50]));
        $race['poor_ok'] = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
        $race['poor_bal'] = road_balance($poor);
        $users[$poor['username']]['_start'] = 100.0 + 0;   // his reconciliation starts from the reset balance
        $race['poor_name'] = $poor['username'];
        // (b) one player fires six ₹2,000 bets at once against the per-ball cap.
        $rich = $U[5];
        $already = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ?', [(int) $open['id'], (int) $rich['id']], 0);
        $res = road_parallel(array_fill(0, 6, ['action' => 'bbb_bet', 'user_id' => $rich['id'], 'round_id' => (int) $open['id'], 'outcome' => 'W', 'stake' => 2000]));
        $race['cap_total'] = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ?', [(int) $open['id'], (int) $rich['id']], 0);
        $race['cap_ok'] = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
        $race['cap_already'] = $already;
        foreach (all('SELECT "stake" FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" IN (?, ?) AND "created_at" > ?', [(int) $open['id'], (int) $poor['id'], (int) $rich['id'], ms_to_sql(0)]) as $_) {}
        $placed += $race['poor_ok'] + $race['cap_ok']; $staked += 50 * $race['poor_ok'] + 2000 * $race['cap_ok'];
        // (c) the next ball's push delivered by four processes at once: the market settles once.
        $nextTs = null; foreach ($sim['balls'] as $bb) if ($bb['_ts'] > $t) { $nextTs = $bb['_ts'] + 1000; break; }
        $race['round'] = (int) $open['id'];
        road_parallel(array_fill(0, 4, ['action' => 'ingest', 'match_key' => $KEY, 'at' => $nextTs]));
        $race['round_after'] = one('SELECT * FROM "bbb_rounds" WHERE "id" = ?', [(int) $open['id']]);
        $race['payout_rows'] = (int) scalar('SELECT COUNT(*) FROM "Transaction" WHERE "details" LIKE ? AND "user" LIKE ?', ['Ball by Ball%' . $open['label'] . '%', 'road_b%'], 0);
        $t = $nextTs;   // continue from there
    }
}
road_clock_off();
$feed = cricket_match_feed_row($KEY);
$rounds = all('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? ORDER BY "id"', [$KEY]);
$settled = count(array_filter($rounds, function ($r) { return $r['status'] === 'SETTLED'; }));
$voided = count(array_filter($rounds, function ($r) { return $r['status'] === 'VOID'; }));
road_info(sprintf('%s · %d markets (%d settled, %d void) · %d bets, ₹%s staked', $feed['result_text'], count($rounds), $settled, $voided, $placed, number_format($staked)));
road_check($feed['status'] === 'completed' && count($rounds) > 150, 'the match ran to its result with a market on (almost) every ball');
road_check($placed > 1000, "$placed bets struck through the match");
road_info('refused as intended: ' . json_encode(array_diff_key($refused, ['other' => 1])));
road_check($refused['min'] > 0 && $refused['max'] > 0 && $refused['outcome'] > 0 && $refused['closed'] > 0,
           'below-minimum, above-maximum, unlisted-outcome and closed-market bets are all refused');
road_check($refused['balance'] > 0, 'players who run out of money are refused (402)');
road_check(!$refused['other'], 'no unexpected refusals', $refused['other'] ? array_slice(array_unique($refused['other']), 0, 5) : null);

road_section('2. Parallel races');
road_check($race['poor_ok'] === 2 && road_near($race['poor_bal'], 0), sprintf('five simultaneous ₹50 bets from a ₹100 wallet: %d paid, balance ₹%.2f (never negative)', $race['poor_ok'], $race['poor_bal']));
road_check($race['cap_total'] <= $cfg['max_user_stake_per_ball'] + 0.01,
           sprintf('six simultaneous ₹2,000 bets against the ₹%s per-ball cap: ₹%s staked in total', number_format($cfg['max_user_stake_per_ball']), number_format($race['cap_total'])));
$ra = $race['round_after'];
$dupPay = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "round_id" = ?', [$race['round']], 0);
$credited = 0.0;
foreach (all('SELECT "user_id","payout" FROM "bbb_bets" WHERE "round_id" = ?', [$race['round']]) as $b) $credited += (float) $b['payout'];
road_check(in_array($ra['status'], ['SETTLED', 'VOID'], true) && road_near($ra['paid_total'], $dupPay) && road_near((float) $ra['pool_total'], (float) $ra['paid_total'] + (float) $ra['rake_taken']),
           'the same push delivered by four processes at once: the race-round settled exactly once (' . $ra['status'] . ')');

road_section('3. Every market against an independent reading of its ball');
$final = cricket_mock_snapshot($KEY, $sim['ended_ms'] + 200000);
$order = $final['play']['innings_order'];
$byPos = []; $cnt = [];
foreach ($final['related_balls'] as $b) { $i = array_search($b['innings'], $order, true) + 1; $idx = $cnt[$i] ?? 0; $byPos[$i . ':' . $idx] = $b; $cnt[$i] = $idx + 1; }
$wrong = []; $voidOk = true; $poolOk = true; $rakeOk = true; $minOk = true; $noWinnerOk = true; $rakeTotal = 0.0;
foreach ($rounds as $r) {
    $raw = $byPos[$r['innings'] . ':' . $r['delivery_idx']] ?? null;
    $bets = all('SELECT * FROM "bbb_bets" WHERE "round_id" = ?', [(int) $r['id']]);
    $pool = array_sum(array_map(function ($b) { return (float) $b['stake']; }, $bets));
    $paid = array_sum(array_map(function ($b) { return (float) $b['payout']; }, $bets));
    if ($r['status'] === 'SETTLED') {
        $want = $raw ? oracle_bbb_outcome($raw) : null;
        if ($want !== $r['outcome']) $wrong[] = "round {$r['id']} {$r['label']}: settled " . $r['outcome'] . ', the ball says ' . json_encode($want);
        if (!road_near($pool, $paid + (float) $r['rake_taken'])) $poolOk = false;
        if ((float) $r['rake_taken'] > $pool * (float) $r['rake_pct'] / 100 + 0.01) $rakeOk = false;
        $rakeTotal += (float) $r['rake_taken'];
        $winners = array_filter($bets, function ($b) use ($r) { return $b['outcome'] === $r['outcome']; });
        foreach ($winners as $w) if ((float) $w['payout'] + 0.001 < (float) $w['stake']) $minOk = false;
        if ($bets && !$winners) foreach ($bets as $b) if ($b['status'] !== 'REFUNDED' || !road_near($b['payout'], $b['stake'])) $noWinnerOk = false;
    } elseif ($r['status'] === 'VOID') {
        foreach ($bets as $b) if ($b['status'] !== 'REFUNDED' || !road_near($b['payout'], $b['stake'])) $voidOk = false;
        if ($raw && oracle_bbb_outcome($raw) !== null && !in_array($r['void_reason'], ['ball_before_close', 'feed_stalled', 'match_ended', 'match_abandoned'], true)) $wrong[] = "round {$r['id']} void as {$r['void_reason']} but its ball is listed";
    } else $wrong[] = "round {$r['id']} left {$r['status']}";
}
road_check(!$wrong, "every settled market's result equals the independent reading of its ball (" . count($rounds) . ' markets)', $wrong ? array_slice($wrong, 0, 6) : null);
road_check($poolOk, 'every settled pool: payouts + house rake = stakes, to the paisa');
road_check($rakeOk, 'the house never took more than its rake percentage of any pool');
road_check($minOk, 'no winning bet was ever paid back less than its stake');
road_check($noWinnerOk, 'when nobody picked the result, every stake was refunded in full');
road_check($voidOk, 'every voided market refunded every stake in full');
road_check((int) scalar('SELECT COUNT(*) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" WHERE r."match_key" = ? AND b."status" = ?', [$KEY, 'PENDING'], 0) === 0, 'no bet is left pending');

road_section('4. Every rupee reconciled');
$allOk = true; $why = []; $sumDelta = 0.0;
foreach ($users as $name => $u) {
    $now = road_balance($u);
    $stakes = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $back = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $led = road_ledger($name);
    $start0 = $name === $race['poor_name'] ? null : $u['_start'];
    if ($start0 === null) { $sumDelta += 0; continue; }   // his balance was reset mid-test; checked in section 2
    $expect = $start0 - $stakes + $back;
    if (!road_near($now, $expect) || !road_near($led['out'], $stakes) || !road_near($led['in'], $back)) { $allOk = false; $why[] = "$name now $now expect $expect ledger " . json_encode($led); }
    $sumDelta += $now - $start0;
}
road_check($allOk, 'every wallet = start − stakes + returns, and the ledger agrees row for row', $why ? array_slice($why, 0, 5) : null);
$poorStakes = (float) scalar('SELECT COALESCE(SUM("stake"),0) - COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $users[$race['poor_name']]['id']], 0);
road_check(road_near(-$sumDelta + $poorStakes, $rakeTotal, 0.5),
           sprintf('across all players: down ₹%.2f, which equals the house rake ₹%.2f', -$sumDelta + $poorStakes, $rakeTotal));
$bal = []; foreach ($users as $n => $u) $bal[$n] = road_balance($u);
foreach ([$sim['ended_ms'] + 300000, $sim['ended_ms'] + 400000] as $again) { road_at($again); cricket_feed_ingest(cricket_mock_snapshot($KEY, $again), 'mock', $again); bbb_process_match($KEY, $again); }
road_clock_off();
$same = true; foreach ($users as $n => $u) if (!road_near(road_balance($u), $bal[$n])) $same = false;
road_check($same, 're-delivering the final push and re-processing moves no money');

road_section('5. A rained-off match: everything open is refunded');
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$KEY2 = cricket_mock_match_key($SLOT + 1); road_wipe_match($KEY2);
$s2 = cricket_mock_simulate($SLOT + 1);
$b2 = []; foreach (array_slice($U, 0, 20) as $u) $b2[$u['username']] = road_balance($u);
$n2 = 0;
for ($t = $s2['meta']['start_ms'] - 60000; $t <= $s2['ended_ms'] + 60000; $t += 3000) {
    road_at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY2, $t), 'mock', $t);
    bbb_process_match($KEY2, $t);
    $o = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? AND "closes_at" > ? ORDER BY "id" DESC LIMIT 1', [$KEY2, 'OPEN', ms_to_sql($t)]);
    if ($o) foreach (array_slice($U, 0, 20) as $u) if (mt_rand(0, 3) === 0 && bbb_place_bet($u, (int) $o['id'], $outcomes[mt_rand(0, 7)], 100)['ok']) $n2++;
}
road_clock_off();
$pend = (int) scalar('SELECT COUNT(*) FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?,?)', [$KEY2, 'OPEN', 'SUSPENDED'], 0);
$ledgerOk = true;
foreach (array_slice($U, 0, 20) as $u) {
    $st = (float) scalar('SELECT COALESCE(SUM(b."stake"),0) - COALESCE(SUM(b."payout"),0) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" WHERE r."match_key" = ? AND b."user_id" = ?', [$KEY2, (int) $u['id']], 0);
    if (!road_near(road_balance($u), $b2[$u['username']] - $st)) $ledgerOk = false;
}
road_check(cricket_match_feed_row($KEY2)['status'] === 'abandoned' && $pend === 0, "$n2 bets on the rained-off match; no market survives the abandonment");
road_check($ledgerOk, 'and every wallet matches its settled bets exactly (open ones refunded)');
putenv('CRICKET_MOCK_ABANDON_EVERY=0');

if (!$GLOBALS['ROAD_KEEP']) { road_wipe_match($KEY); road_wipe_match($KEY2); road_wipe_users('b'); }
road_finish('road_bbb');
