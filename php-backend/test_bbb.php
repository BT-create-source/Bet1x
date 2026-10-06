<?php
/**
 * Ball by Ball — rules, pool arithmetic, and a full simulated match against the REAL database.
 *
 *     php -d extension=pdo_pgsql php-backend/test_bbb.php
 *
 * Part 1 is pure (no database): outcome classification and the pool split.
 * Part 2 walks complete mock matches through time — the same ingest path the Roanuz webhook uses —
 * with three test accounts betting on every market, then reconciles every wallet to the paisa and
 * checks that every market ended settled or void with nothing left pending. It writes to whatever
 * database php-backend/.env points at, so run it against a development database. It only touches
 * its own far-future mock fixtures and its own bbb_test_* accounts, and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

putenv('CRICKET_SOURCE=mock');
putenv('CRICKET_MOCK_ABANDON_EVERY=0');
require_once __DIR__ . '/config.php';
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'bbb'] as $f) require_once __DIR__ . "/lib/$f.php";

$pass = 0; $fail = 0;
function check($cond, $label) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}
function near($a, $b) { return abs((float) $a - (float) $b) < 0.005; }

echo "\n== 1. Outcome classification ==\n";
$base = ['is_super_over' => 0, 'extra_type' => null, 'is_wicket' => 0, 'batsman_runs' => 0, 'extra_runs' => 0];
check(bbb_classify($base) === '0', 'a dot ball is 0');
check(bbb_classify(['batsman_runs' => 4] + $base) === '4', 'four off the bat is 4');
check(bbb_classify(['batsman_runs' => 6] + $base) === '6', 'six off the bat is 6');
check(bbb_classify(['extra_type' => 'legbye', 'extra_runs' => 1] + $base) === '1', 'a leg bye counts as the runs it added');
check(bbb_classify(['extra_type' => 'bye', 'extra_runs' => 4] + $base) === '4', 'four byes count as 4');
check(bbb_classify(['extra_type' => 'wide', 'extra_runs' => 1] + $base) === 'EX', 'a wide is EXTRA');
check(bbb_classify(['extra_type' => 'noball', 'extra_runs' => 1, 'batsman_runs' => 6] + $base) === 'EX', 'a no-ball hit for six is still EXTRA');
check(bbb_classify(['extra_type' => 'wide', 'is_wicket' => 1, 'wicket_type' => 'stumped'] + $base) === 'EX', 'a stumping off a wide is EXTRA');
check(bbb_classify(['is_wicket' => 1, 'wicket_type' => 'caught'] + $base) === 'W', 'a catch is WICKET');
check(bbb_classify(['is_wicket' => 1, 'wicket_type' => 'run_out', 'batsman_runs' => 1] + $base) === 'W', 'a run out with a completed run is WICKET');
check(bbb_classify(['batsman_runs' => 5] + $base) === null, 'five runs is not listed, so the market voids');
check(bbb_classify(['is_super_over' => 1] + $base) === null, 'super over deliveries have no market');

echo "\n== 2. Pool arithmetic ==\n";
$bets = [
    ['id' => 1, 'outcome' => '4', 'stake' => 100, 'created_at' => '1'],
    ['id' => 2, 'outcome' => '4', 'stake' => 50,  'created_at' => '2'],
    ['id' => 3, 'outcome' => '0', 'stake' => 300, 'created_at' => '3'],
    ['id' => 4, 'outcome' => 'W', 'stake' => 33.33, 'created_at' => '4'],
];
$a = bbb_allocate($bets, '4', 10);
check($a['mode'] === 'paid', 'winners exist, so the pool is paid');
check(near($a['pool'], 483.33), 'pool is every stake');
check(near($a['paid'] + $a['rake'], $a['pool']), 'paid + rake == pool exactly');
check(near(array_sum($a['payouts']), $a['paid']), 'payouts sum to exactly what is paid');
check($a['payouts'][1] >= 2 * $a['payouts'][2] - 0.02, 'payouts are in proportion to stake');
check(!isset($a['payouts'][3]) && !isset($a['payouts'][4]), 'losers are paid nothing');
$b = bbb_allocate($bets, '6', 10);
check($b['mode'] === 'refund_no_winner' && near($b['rake'], 0) && near(array_sum($b['payouts']), 483.33), 'nobody on the winner: every stake refunded, no rake');
$c = bbb_allocate([['id' => 1, 'outcome' => '1', 'stake' => 100, 'created_at' => '1'], ['id' => 2, 'outcome' => '1', 'stake' => 200, 'created_at' => '2']], '1', 10);
check(near($c['payouts'][1], 100) && near($c['payouts'][2], 200) && near($c['rake'], 0), 'everyone on the winner: stakes back, the house takes nothing');
$d = bbb_allocate([['id' => 1, 'outcome' => '1', 'stake' => 100, 'created_at' => '1'], ['id' => 2, 'outcome' => '0', 'stake' => 5, 'created_at' => '2']], '1', 10);
check($d['payouts'][1] >= 100 && near($d['payouts'][1] + $d['rake'], 105), 'a lopsided pool never pays a winner less than their stake');
$e = bbb_allocate([['id' => 1, 'outcome' => '2', 'stake' => 10, 'created_at' => 'a'], ['id' => 2, 'outcome' => '2', 'stake' => 10, 'created_at' => 'b'], ['id' => 3, 'outcome' => '2', 'stake' => 10, 'created_at' => 'c'], ['id' => 4, 'outcome' => '0', 'stake' => 10, 'created_at' => 'd']], '2', 0);
check(near(array_sum($e['payouts']), 40), 'an indivisible split still pays out the whole pool (remainder to one winner)');
$rnd = 0; $okAll = true;
mt_srand(7);
for ($i = 0; $i < 400; $i++) {
    $bs = []; $n = mt_rand(1, 9);
    for ($j = 0; $j < $n; $j++) $bs[] = ['id' => $j + 1, 'outcome' => ['0','1','2','3','4','6','W','EX'][mt_rand(0, 7)], 'stake' => mt_rand(1000, 500000) / 100, 'created_at' => (string) $j];
    $o = ['0','1','2','3','4','6','W','EX'][mt_rand(0, 7)];
    $al = bbb_allocate($bs, $o, mt_rand(0, 20));
    if (!near(array_sum($al['payouts']) + $al['rake'], $al['pool']) || $al['rake'] < -0.001) $okAll = false;
}
check($okAll, '400 random pools: payouts + rake always equal the pool, rake never negative');

// -------------------------------------------------------------------------------------------------
echo "\n== 3. A full simulated match, real database ==\n";
if (!db_ready()) { echo "  SKIP  database unreachable\n"; goto done; }

$SLOT = 900000 + random_int(0, 99999);
$KEY = cricket_mock_match_key($SLOT);
function cleanup($key) {
    $ids = array_column(all('SELECT "id" FROM "bbb_rounds" WHERE "match_key" = ?', [$key]), 'id');
    q('DELETE FROM "bbb_rounds" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_deliveries" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_feed_raw" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_match_feed" WHERE "match_key" = ?', [$key]);
}
cleanup($KEY);

$users = [];
foreach (['bbb_test_a', 'bbb_test_b', 'bbb_test_c'] as $u) {
    $row = get_or_create_user($u, true);
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [100000, (int) $row['id']]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$u]);
    $users[] = find_user_by_id($row['id']);
}

$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];
$end = $sim['ended_ms'];
mt_srand(42);
$placed = 0; $rejectedLate = 0; $wideMarkets = 0;
$staked = [0, 0, 0];
for ($t = $start - 40 * 60000; $t <= $end + 120000; $t += ($t < $start - 60000 ? 300000 : 4000)) {
    $GLOBALS['BET1X_TEST_NOW_MS'] = $t;
    $snap = cricket_mock_snapshot($KEY, $t);
    cricket_feed_ingest($snap, 'mock', $t);
    bbb_process_match($KEY, $t);
    $open = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? AND "closes_at" > ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN', ms_to_sql($t)]);
    if ($open) {
        foreach ($users as $i => $u) {
            if (mt_rand(0, 100) < 55) {
                $o = ['0','1','2','3','4','6','W','EX'][mt_rand(0, 7)];
                $stake = [10, 25, 50, 100, 250][mt_rand(0, 4)];
                $r = bbb_place_bet($u, (int) $open['id'], $o, $stake);
                if ($r['ok']) { $placed++; $staked[$i] += $stake; }
            }
        }
    }
    // Every so often try to sneak a bet onto a market whose window has closed.
    $closed = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?,?) AND "closes_at" <= ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN', 'SUSPENDED', ms_to_sql($t)]);
    if ($closed && mt_rand(0, 10) === 0) {
        $r = bbb_place_bet($users[0], (int) $closed['id'], '4', 10);
        if (!$r['ok'] && $r['status'] === 409) $rejectedLate++;
    }
}
unset($GLOBALS['BET1X_TEST_NOW_MS']);

$rounds = all('SELECT * FROM "bbb_rounds" WHERE "match_key" = ?', [$KEY]);
$settled = count(array_filter($rounds, function ($r) { return $r['status'] === 'SETTLED'; }));
$voided  = count(array_filter($rounds, function ($r) { return $r['status'] === 'VOID'; }));
$pendingRounds = count(array_filter($rounds, function ($r) { return in_array($r['status'], ['OPEN', 'SUSPENDED'], true); }));
echo "  info  " . count($rounds) . " markets: $settled settled, $voided void · $placed bets placed\n";
check(count($rounds) > 150, 'a market opened on (almost) every delivery of the match');
check($pendingRounds === 0, 'no market is left open or suspended after the match');
check((int) scalar('SELECT COUNT(*) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" WHERE r."match_key" = ? AND b."status" = ?', [$KEY, 'PENDING'], 0) === 0, 'no bet is left pending');
check($placed > 100, 'bets were actually placed throughout');
check($rejectedLate > 0, 'bets on a closed market are refused');

// Markets must line up with deliveries: each settled market's outcome is its delivery's outcome.
$deliveries = cricket_match_deliveries($KEY);
$byPos = []; $cnt = [];
foreach ($deliveries as $d) { $idx = $cnt[$d['innings']] ?? 0; $byPos[$d['innings'] . ':' . $idx] = $d; $cnt[$d['innings']] = $idx + 1; }
$mismatch = 0;
foreach ($rounds as $r) {
    if ($r['status'] !== 'SETTLED') continue;
    $d = $byPos[$r['innings'] . ':' . $r['delivery_idx']] ?? null;
    if (!$d || bbb_classify($d) !== $r['outcome'] || $d['uid'] !== $r['result_ball_uid']) $mismatch++;
    if ($d && !$d['is_legal']) $wideMarkets++;
}
check($mismatch === 0, 'every settled market settled on exactly its own delivery');
check($wideMarkets > 0, 'a wide or no-ball got its own market, and the re-bowl got the next one');

// Money: the house's take is exactly the rake on settled rounds, and every wallet reconciles.
$sumStake = (float) scalar('SELECT COALESCE(SUM(b."stake"),0) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id"=b."round_id" WHERE r."match_key"=?', [$KEY], 0);
$sumPay   = (float) scalar('SELECT COALESCE(SUM(b."payout"),0) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id"=b."round_id" WHERE r."match_key"=?', [$KEY], 0);
$sumRake  = (float) scalar('SELECT COALESCE(SUM("rake_taken"),0) FROM "bbb_rounds" WHERE "match_key"=?', [$KEY], 0);
check(near($sumStake, $sumPay + $sumRake), sprintf('stakes ₹%.2f == payouts ₹%.2f + rake ₹%.2f', $sumStake, $sumPay, $sumRake));
$rakeOk = true;
foreach ($rounds as $r) if ($r['status'] === 'SETTLED' && (float) $r['rake_taken'] > (float) $r['pool_total'] * ((float) $r['rake_pct']) / 100 + 0.01) $rakeOk = false;
check($rakeOk, 'the house never takes more than rake_pct of any pool');
foreach ($users as $i => $u) {
    $now = find_user_by_id($u['id']);
    $won = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $in  = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u['username'], 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u['username'], 'Withdrawal'], 0);
    check(near((float) $now['wallet_balance'], 100000 - $staked[$i] + $won) && near($in, $won) && near($out, $staked[$i]),
          "{$u['username']}: wallet == 100000 − stakes + returns, and the ledger agrees to the paisa");
}

// -------------------------------------------------------------------------------------------------
echo "\n== 4. Integrity voids ==\n";
// (a) a ball that lands before betting had safely closed voids its market.
cleanup($KEY);
// Pick a moment one second after a ball, as a live feed would deliver it.
$t0 = null;
foreach ($sim['balls'] as $bb) if ($bb['_ts'] > $start + 5 * 60000) { $t0 = $bb['_ts'] + 1000; break; }
$GLOBALS['BET1X_TEST_NOW_MS'] = $t0;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $t0), 'mock', $t0);
bbb_process_match($KEY, $t0);
$open = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN']);
check((bool) $open, 'a market is open on the next ball');
$before = (float) find_user_by_id($users[1]['id'])['wallet_balance'];
$bet = bbb_place_bet($users[1], (int) $open['id'], '1', 100);
check($bet['ok'], 'a bet inside the window is accepted');
// The next delivery arrives only 2s after the market opened — inside the window.
$nextBallTs = null;
foreach ($sim['balls'] as $bb) if ($bb['_ts'] > $t0) { $nextBallTs = $bb['_ts']; break; }
$early = sql_to_ms($open['opened_at']) + 2000;
$GLOBALS['BET1X_TEST_NOW_MS'] = $early;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $nextBallTs), 'mock', $early);
$after = one('SELECT * FROM "bbb_rounds" WHERE "id" = ?', [(int) $open['id']]);
check($after['status'] === 'VOID' && $after['void_reason'] === 'ball_before_close', 'that market is VOID because the ball came before betting closed');
check(near((float) find_user_by_id($users[1]['id'])['wallet_balance'], $before), 'and the stake is back in the wallet in full');

// (a3) a market is not opened at all when too little of its window is left (the previous ball was
//      reported late), instead of being opened only to be voided.
cleanup($KEY);
$GLOBALS['BET1X_TEST_NOW_MS'] = $t0 + 15000;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $t0 - 1000), 'mock', $t0 + 15000);
bbb_process_match($KEY, $t0 + 15000);
check(!one('SELECT "id" FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ?', [$KEY, 'OPEN']),
      'a market whose window would already be over is never opened');
cleanup($KEY);
$GLOBALS['BET1X_TEST_NOW_MS'] = $t0;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $t0), 'mock', $t0);
bbb_process_match($KEY, $t0);
$early = $t0 + 2000;

// (b) a stalled feed voids what is open and opens nothing new.
$GLOBALS['BET1X_TEST_NOW_MS'] = $early + 1000;
bbb_process_match($KEY, $early + 1000);
$open2 = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN']);
if ($open2) bbb_place_bet($users[2], (int) $open2['id'], '0', 50);
$GLOBALS['BET1X_TEST_NOW_MS'] = $early + 5 * 60000;
bbb_process_match($KEY, $early + 5 * 60000);
$feedRow = cricket_match_feed_row($KEY);
check((int) $feedRow['stalled'] === 1, 'five silent minutes mid-match marks the feed stalled');
check((int) scalar('SELECT COUNT(*) FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?,?)', [$KEY, 'OPEN', 'SUSPENDED'], 0) === 0, 'and every open market is voided');
$r = $open2 ? bbb_place_bet($users[2], (int) $open2['id'], '0', 50) : ['ok' => false];
check(!$r['ok'], 'no bet can be placed while stalled');
// the feed coming back clears the stall
$back = $early + 5 * 60000 + 1000;
$GLOBALS['BET1X_TEST_NOW_MS'] = $back;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $back), 'mock', $back);
check((int) cricket_match_feed_row($KEY)['stalled'] === 0, 'a fresh push clears the stall and markets resume');

// (a2) a ball bowled INSIDE the window but reported late in a batched update still voids, because the
//      ball carries its own timestamp and that — not when we heard about it — is what the guard checks.
cleanup($KEY);
$prevTs = null; $nextTs = null;
foreach ($sim['balls'] as $i => $bb) {
    if ($bb['_ts'] > $start + 8 * 60000 && isset($sim['balls'][$i + 1])) {
        $prevTs = $bb['_ts']; $nextTs = $sim['balls'][$i + 1]['_ts']; break;
    }
}
$tOpen = $prevTs + 1000;                       // the market opens a second after the previous ball
$GLOBALS['BET1X_TEST_NOW_MS'] = $tOpen;
cricket_feed_ingest(cricket_mock_snapshot($KEY, $tOpen), 'mock', $tOpen);
bbb_process_match($KEY, $tOpen);
$o3 = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? ORDER BY "id" DESC LIMIT 1', [$KEY, 'OPEN']);
// The next ball is stamped as bowled 5s after the previous one — inside the window — but the update
// carrying it only lands a minute later.
$snapLate = cricket_mock_snapshot($KEY, $nextTs);
$lastIdx = count($snapLate['related_balls']) - 1;
$snapLate['related_balls'][$lastIdx]['timestamp'] = (int) floor(($prevTs + 5000) / 1000);
$late = $nextTs + 60000;
$GLOBALS['BET1X_TEST_NOW_MS'] = $late;
cricket_feed_ingest($snapLate, 'mock', $late);
$o3 = $o3 ? one('SELECT * FROM "bbb_rounds" WHERE "id" = ?', [(int) $o3['id']]) : null;
check($o3 && $o3['status'] === 'VOID' && $o3['void_reason'] === 'ball_before_close',
      'a ball bowled inside the window voids its market even when the update reporting it arrives late');

// (c) an abandoned match voids everything and refunds.
cleanup($KEY);
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$simA = cricket_mock_simulate($SLOT + 1);   // fresh slot so the static cache holds the abandoned variant
$KEYA = cricket_mock_match_key($SLOT + 1);
cleanup($KEYA);
$abandonedAny = false;
for ($t = $simA['meta']['start_ms'] - 60000; $t <= $simA['ended_ms'] + 60000; $t += 4000) {
    $GLOBALS['BET1X_TEST_NOW_MS'] = $t;
    cricket_feed_ingest(cricket_mock_snapshot($KEYA, $t), 'mock', $t);
    bbb_process_match($KEYA, $t);
    $o = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? AND "closes_at" > ? ORDER BY "id" DESC LIMIT 1', [$KEYA, 'OPEN', ms_to_sql($t)]);
    if ($o) bbb_place_bet($users[0], (int) $o['id'], 'W', 10);
}
unset($GLOBALS['BET1X_TEST_NOW_MS']);
$fa = cricket_match_feed_row($KEYA);
check($fa['status'] === 'abandoned', 'the rained-off match is recorded as abandoned');
check((int) scalar('SELECT COUNT(*) FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?,?)', [$KEYA, 'OPEN', 'SUSPENDED'], 0) === 0, 'no market survives the abandonment');
check((int) scalar('SELECT COUNT(*) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id"=b."round_id" WHERE r."match_key"=? AND b."status"=?', [$KEYA, 'PENDING'], 0) === 0, 'and no bet is left pending');

cleanup($KEY); cleanup($KEYA);
foreach ($users as $u) {
    q('DELETE FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$u['username']]);
    q('DELETE FROM "User" WHERE "id" = ?', [(int) $u['id']]);
}

done:
echo "\n------------------------------------------------------------\n";
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
