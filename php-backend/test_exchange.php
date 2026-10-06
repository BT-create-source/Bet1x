<?php
/**
 * Cricket match betting — engine maths, bet arithmetic, cash out, and a full simulated match against
 * the REAL database with every wallet reconciled.
 *
 *     php -d extension=pdo_pgsql php-backend/test_exchange.php
 *
 * Touches only its own far-future mock fixtures and mx_test_* accounts, and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
putenv('CRICKET_SOURCE=mock');
putenv('CRICKET_MOCK_ABANDON_EVERY=0');
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'bbb', 'exchange'] as $f) require_once __DIR__ . "/lib/$f.php";

$pass = 0; $fail = 0;
function check($c, $l) { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function near($a, $b, $eps = 0.011) { return abs((float) $a - (float) $b) < $eps; }
function at($ms) { $GLOBALS['BET1X_TEST_NOW_MS'] = (int) $ms; }

echo "\n== 1. Engine behaves like cricket ==\n";
$m = odds_model('T20');
check($m && $m['matches'] > 1000, 'the T20 model is loaded (' . ($m['matches'] ?? 0) . ' matches)');
// This suite runs on the simulated league, so the VIRTUAL model is the one loaded: T20 only, learned from
// the simulator itself. The real-cricket model (T20 + ODI, from Cricsheet) is checked straight from disk.
$real = json_decode((string) file_get_contents(__DIR__ . '/data/cricket-model.json'), true);
$virt = json_decode((string) file_get_contents(__DIR__ . '/data/cricket-model-virtual.json'), true);
check(isset($real['formats']['T20'], $real['formats']['ODI']) && odds_model('TEST') === null && odds_model('T10') === null, 'real cricket: T20 and ODI are priced; Tests and T10 are not offered');
check(abs($m['E'][120][0] - $virt['formats']['T20']['E'][120][0]) < 1e-9 && odds_model('ODI') === null,
      "virtual cricket is priced from the simulator's own model (T20 only), never from real-cricket numbers");
$sims = []; for ($s0 = 3100000; $s0 < 3100300; $s0++) { $sim = cricket_mock_simulate($s0); $r0 = 0; foreach ($sim['balls'] as $b0) if ($b0['innings'] === $sim['innings_order'][0]) $r0 += (int) $b0['team_score']['runs']; $sims[] = $r0; }
$avg = array_sum($sims) / count($sims);
check(abs($avg - $m['E'][120][0]) < 6, sprintf('the virtual model matches the simulator it prices: first innings %.1f simulated vs %.1f expected', $avg, $m['E'][120][0]));
$mono = true;
for ($need = 10; $need <= 150; $need += 10) if (odds_p_chase($m, $need, 60, 3) < odds_p_chase($m, $need + 10, 60, 3)) $mono = false;
check($mono, 'needing more runs never makes a chase likelier');
$mono = true;
for ($w = 0; $w < 9; $w++) if (odds_p_chase($m, 80, 60, $w) < odds_p_chase($m, 80, 60, $w + 1)) $mono = false;
check($mono, 'losing a wicket never makes a chase likelier');
$mono = true;
for ($b = 12; $b < 120; $b += 6) if (odds_p_chase($m, 80, $b, 3) > odds_p_chase($m, 80, $b + 6, 3) + 1e-9) $mono = false;
check($mono, 'more balls left never makes a chase less likely');
check(odds_p_first($m, 80, 60, 2) > odds_p_first($m, 60, 60, 2), 'a higher first-innings score raises the batting side\'s chance');
check(odds_p_chase($m, 0, 30, 3) === 1.0 && odds_p_chase($m, 5, 0, 3) === 0.0 && odds_p_chase($m, 5, 10, 10) === 0.0, 'won / out of balls / all out are certain');
$cal = $m['calibration'];
$worst = 0; foreach ($cal['reliability'] as $r) $worst = max($worst, abs($r['predicted'] - $r['actual']));
check($worst <= 0.05, sprintf('held-out calibration: worst band off by %.3f across %d unseen matches', $worst, $cal['test_matches']));
$d = odds_delta_from_price('a', 1.60);
$pre = odds_win_prob(['format' => 'T20', 'first' => null, 'innings' => 1, 'runs' => 0, 'wkts' => 0, 'legal' => 0, 'target' => null, 'second' => null, 'batting' => null], $d);
check(near($pre, 1 / 1.6, 0.002), 'the operator\'s pre-match price is reproduced exactly before a ball (A @1.60)');
$late = ['format' => 'T20', 'first' => 'a', 'second' => 'b', 'innings' => 2, 'batting' => 'b', 'runs' => 170, 'wkts' => 5, 'legal' => 114, 'target' => 181];
check(abs(odds_win_prob($late, $d) - odds_win_prob($late, 0)) < 0.03, 'and its influence fades to almost nothing at the death');

echo "\n== 2. Prices ==\n";
$okTicks = true;
foreach ([0.9, 0.7, 0.55, 0.4, 0.25, 0.1, 0.03] as $p) {
    $l = odds_ladder($p);
    foreach (array_merge($l['back'], $l['lay']) as $px) {
        $t = odds_tick_size($px - 1e-9);
        if (abs(round($px / odds_tick_size($px), 6) - round($px / odds_tick_size($px))) > 1e-6 && abs(round($px / $t) * $t - $px) > 1e-6) $okTicks = false;
    }
    if (!($l['back'][0] < $l['lay'][0] && $l['back'][1] < $l['back'][0] && $l['lay'][1] > $l['lay'][0])) $okTicks = false;
    if (!($l['back'][0] <= $l['fair'] && $l['lay'][0] >= $l['fair'])) $okTicks = false;
}
check($okTicks, 'ladders sit on the standard price increments, back < fair < lay, and step away from the best price');
check(odds_ladder(0.999) === null && odds_bookmaker(0.995) === null, 'a near-certain market is not priced (it suspends)');
$bm = odds_bookmaker(0.222);
check($bm['back'] < (1 / 0.222 - 1) * 100 && $bm['lay'] > (1 / 0.222 - 1) * 100, "bookmaker rates straddle fair value ({$bm['back']}/{$bm['lay']} for a 4.5 shot)");
$fancy = odds_fancy(['format' => 'T20', 'first' => 'a', 'second' => 'b', 'innings' => 1, 'batting' => 'a', 'runs' => 48, 'wkts' => 1, 'legal' => 30, 'target' => null], 'GRB');
$names = array_column($fancy, 'name');
$f31 = array_column(odds_fancy(['format' => 'T20', 'first' => 'a', 'second' => 'b', 'innings' => 1, 'batting' => 'a', 'runs' => 50, 'wkts' => 1, 'legal' => 31, 'target' => null], 'GRB'), 'name');
check(in_array('GRB 10 Over Runs', $names, true) && in_array('GRB Only 6th Over Runs', $names, true) && in_array('GRB 6 Over Runs', $names, true),
      'at 5.0 overs fancy offers the 6/10/15/20 milestones and the next over');
check(in_array('GRB 6 Over Runs', $f31, true) && in_array('GRB Only 7th Over Runs', $f31, true) && !in_array('GRB Only 6th Over Runs', $f31, true),
      'during the 6th over its milestone is still quoted (the over in progress) but its single-over line has closed');
$g = odds_fancy(['format' => 'T20', 'first' => 'a', 'second' => 'b', 'innings' => 2, 'batting' => 'b', 'runs' => 60, 'wkts' => 1, 'legal' => 50, 'target' => 107], 'GRB');
$gn = array_column($g, 'name');
check(in_array('GRB 9 Over Runs', $gn, true) && in_array('GRB 10 Over Runs', $gn, true), 'mid-over, the current over\'s milestone is listed alongside the next fixed one (9 and 10 Over Runs)');
check($g[0]['yes_line'] === $g[0]['no_line'] + 1, 'lines are quoted one run apart, No L / Yes L+1, like the established sites');
check(odds_temper(0.985) < 0.97 && odds_temper(0.985) > 0.93 && abs(odds_temper(0.6) - 0.6) < 1e-9,
      'market-style tails: a 98.5% statistic prices like the market (~95%), mid-range prices are untouched');

echo "\n== 3. Bet arithmetic and cash out ==\n";
$t = odds_bet_terms('BACK', 100, 2.5, 0); check($t['liability'] == 100 && $t['profit'] == 150, 'BACK 100 @2.5 risks 100 to win 150');
$t = odds_bet_terms('LAY', 100, 2.5, 0);  check($t['liability'] == 150 && $t['profit'] == 100, 'LAY 100 @2.5 risks 150 to win 100');
$t = odds_bet_terms('YES', 100, 0, 90);   check($t['liability'] == 100 && $t['profit'] == 90, 'YES 100 @90 risks 100 to win 90');
$t = odds_bet_terms('NO', 100, 0, 110);   check($t['liability'] == 110 && $t['profit'] == 100, 'NO 100 @110 risks 110 to win 100');
check(odds_bet_wins(['side' => 'YES', 'line' => 42], 42) && !odds_bet_wins(['side' => 'NO', 'line' => 40], 40) && odds_bet_wins(['side' => 'NO', 'line' => 40], 39),
      'YES wins at or above its line; NO wins only below its line');
$bets = [['side' => 'BACK', 'selection' => 'a', 'liability' => 100, 'profit' => 150]];
$pos = odds_position($bets);
$co = odds_cashout_value($pos, 1.8, 1.82);
// Check the hedge really equalises: lay a at 1.82 with stake h.
$h = ($pos['credit']['a'] - $pos['credit']['b']) / 1.82;
check(near($pos['credit']['a'] - $h * 0.82, $pos['credit']['b'] + $h) && near($co, $pos['credit']['b'] + $h), sprintf('cash out on BACK 100 @2.5 after the price shortens to 1.80/1.82 = ₹%.2f, the same whichever team wins', $co));
check($co > 100, 'a back bet whose price shortened cashes out at a profit');
$co2 = odds_cashout_value($pos, 3.4, 3.5);
check($co2 < 100 && $co2 > 0, sprintf('…and at a loss (₹%.2f) when the price drifted', $co2));
$bets2 = [['side' => 'LAY', 'selection' => 'a', 'liability' => 150, 'profit' => 100]];
$p2 = odds_position($bets2);
$co3 = odds_cashout_value($p2, 3.4, 3.5);
$h3 = ($p2['credit']['b'] - $p2['credit']['a']) / 3.4;
check(near($p2['credit']['a'] + $h3 * 2.4, $p2['credit']['b'] - $h3), 'a lay position hedges by backing, and is equalised too');
check(mx_house_worst([['side' => 'BACK', 'selection' => 'a', 'liability' => 100, 'profit' => 150], ['side' => 'BACK', 'selection' => 'b', 'liability' => 100, 'profit' => 50]], 'MATCH_ODDS') == -50,
      'house worst case: two backs, the house loses 50 if a wins');

if (!db_ready()) { echo "SKIP database parts: unreachable\n"; goto done; }

echo "\n== 4. A full simulated match, real database ==\n";
$SLOT = 970000 + random_int(0, 20000);
$KEY = cricket_mock_match_key($SLOT);
function wipe($key) {
    foreach (['mx_bets', 'mx_markets', 'mx_match_settings', 'bbb_rounds', 'cricket_deliveries', 'cricket_feed_raw', 'cricket_match_feed'] as $t) q('DELETE FROM "' . $t . '" WHERE "match_key" = ?', [$key]);
}
wipe($KEY);
$users = [];
foreach (['mx_test_a', 'mx_test_b', 'mx_test_c'] as $u) {
    $row = get_or_create_user($u, true);
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [100000, (int) $row['id']]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$u]);
    $users[] = find_user_by_id($row['id']);
}
$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];
mt_srand(11);
$placed = 0; $rejectedSusp = 0; $cashouts = 0; $types = [];
for ($t = $start - 25 * 60000; $t <= $sim['ended_ms'] + 120000; $t += ($t < $start - 60000 ? 300000 : 3000)) {
    at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY, $t), 'mock', $t);
    mx_process_match($KEY, $t);
    $book = mx_markets($KEY, $t);
    foreach ($users as $i => $u) {
        if (mt_rand(0, 100) > 30) continue;
        $keys = array_keys($book['markets']);
        $mk = $keys[mt_rand(0, count($keys) - 1)];
        $mm = $book['markets'][$mk];
        if ($mm['type'] === 'FANCY') {
            $side = mt_rand(0, 1) ? 'YES' : 'NO';
            $in = ['market_key' => $mk, 'side' => $side, 'line' => $side === 'YES' ? $mm['yes_line'] : $mm['no_line'],
                   'rate' => $side === 'YES' ? $mm['yes_rate'] : $mm['no_rate'], 'stake' => [100, 200, 500][mt_rand(0, 2)]];
        } else {
            $sel = mt_rand(0, 1) ? 'a' : 'b';
            $r = $mm['runners'][$sel === 'a' ? 0 : 1];
            $side = mt_rand(0, 2) ? 'BACK' : 'LAY';
            if (!$r['back']) continue;
            $price = $mm['type'] === 'MATCH_ODDS' ? ($side === 'BACK' ? $r['back'][0] : $r['lay'][0]) : ($side === 'BACK' ? $r['back'] : $r['lay']);
            $in = ['market_key' => $mk, 'side' => $side, 'selection' => $sel, 'price' => $price, 'stake' => [100, 250, 1000][mt_rand(0, 2)]];
        }
        $res = mx_place_bet($u, $KEY, $in);
        if ($res['ok']) { $placed++; $types[$mm['type']] = ($types[$mm['type']] ?? 0) + 1; }
        elseif (!$mm['open'] && $res['status'] === 409) $rejectedSusp++;
    }
    if (mt_rand(0, 60) === 0) {
        $u = $users[mt_rand(0, 2)];
        $c = mx_cashout($u, $KEY, mt_rand(0, 1) ? 'MO' : 'BM');
        if ($c['ok']) $cashouts++;
    }
}
unset($GLOBALS['BET1X_TEST_NOW_MS']);
$feed = cricket_match_feed_row($KEY);
echo "  info  $placed bets (" . json_encode($types) . "), $cashouts cash-outs, result: {$feed['result_text']}\n";
check($placed > 200 && count($types) === 3, 'bets were struck on Match Odds, Bookmaker and Fancy throughout the match');
check($rejectedSusp > 0, 'bets on a suspended market (ball running) are refused');
check((int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "status" = ?', [$KEY, 'PENDING'], 0) === 0, 'no bet is left pending after the match');
$mo = one('SELECT * FROM "mx_markets" WHERE "match_key" = ? AND "market_key" = ?', [$KEY, 'MO']);
check($mo && $mo['status'] === 'SETTLED' && $mo['result'] === $sim['result']['winner'], 'Match Odds settled on the actual winner (' . ($mo['result'] ?? '?') . ')');

// Every fancy market's result, recomputed independently from the deliveries.
$dl = cricket_match_deliveries($KEY);
$inn = []; foreach ($dl as $x) $inn[$x['innings']][] = $x;
$badFancy = 0; $nFancy = 0;
foreach (all('SELECT * FROM "mx_markets" WHERE "match_key" = ? AND "market_type" = ? AND "status" = ?', [$KEY, 'FANCY', 'SETTLED']) as $fm) {
    preg_match('/^(ms|ov)_(\d)_(\d+)$/', $fm['market_key'], $mm);
    $balls = $inn[(int) $mm[2]]; $n = (int) $mm[3];
    $legal = 0; $cum = 0; $res = null; $ov = 0;
    foreach ($balls as $x) {
        $r = $x['batsman_runs'] + $x['extra_runs'];
        if ($mm[1] === 'ov' && $legal >= $n * 6 && $legal < $n * 6 + 6) $ov += $r;
        $cum += $r;
        if ($x['is_legal']) { $legal++; if ($mm[1] === 'ms' && $legal === $n * 6) $res = $cum; if ($mm[1] === 'ov' && $legal === $n * 6 + 6) $res = $ov; }
    }
    if ($res === null && $mm[1] === 'ms') $res = $cum;
    $nFancy++;
    if ((string) $res !== (string) $fm['result']) $badFancy++;
}
check($nFancy > 3 && $badFancy === 0, "every settled fancy line matches an independent recount of the deliveries ($nFancy lines)");

// Settled bets agree with their market's result.
$wrong = 0;
foreach (all('SELECT b.*, m."result", m."status" AS "mstatus" FROM "mx_bets" b JOIN "mx_markets" m ON m."match_key" = b."match_key" AND m."market_key" = b."market_key" '
           . 'WHERE b."match_key" = ? AND b."status" IN (?, ?)', [$KEY, 'WON', 'LOST']) as $b) {
    $should = odds_bet_wins($b, $b['result']);
    if ($should !== ($b['status'] === 'WON')) $wrong++;
    if ($b['status'] === 'WON' && !near($b['payout'], (float) $b['liability'] + (float) $b['profit'])) $wrong++;
}
check($wrong === 0, 'every settled bet won or lost exactly as its market\'s result says, and winners got liability + profit');

foreach ($users as $u) {
    $now = (float) find_user_by_id($u['id'])['wallet_balance'];
    $liab = (float) scalar('SELECT COALESCE(SUM("liability"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $paid = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $in = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u['username'], 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$u['username'], 'Withdrawal'], 0);
    check(near($now, 100000 - $liab + $paid) && near($in, $paid) && near($out, $liab),
          sprintf('%s: 100000 − risked %.2f + returned %.2f = %.2f, and the ledger agrees', $u['username'], $liab, $paid, $now));
}

echo "\n== 5. The rules ==\n";
wipe($KEY);
// Find a live moment with an open market.
$tOpen = null;
foreach ($sim['balls'] as $i => $bb) {
    if ($bb['_ts'] > $start + 10 * 60000 && empty($bb['team_score']['is_wicket'])) { $tOpen = $bb['_ts'] + 1500; $nextTs = $sim['balls'][$i + 1]['_ts']; break; }
}
at($tOpen);
cricket_feed_ingest(cricket_mock_snapshot($KEY, $tOpen), 'mock', $tOpen);
$book = mx_markets($KEY, $tOpen);
check($book['markets']['MO']['open'], 'Match Odds are open just after a ball');
$a = $book['markets']['MO']['runners'][0];
$r = mx_place_bet($users[0], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'price' => $a['back'][0] + 0.5, 'stake' => 100]);
check(!$r['ok'] && $r['status'] === 409 && strpos($r['error'], 'Odds changed') === 0, 'asking for a better price than offered is refused: ' . $r['error']);
$r = mx_place_bet($users[0], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'price' => $a['back'][0] - 0.2, 'stake' => 100]);
check($r['ok'] && near($r['odds'], $a['back'][0]), 'asking for a worse price is struck at the better current price');
$r = mx_place_bet($users[0], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 50]);
check(!$r['ok'] && strpos($r['error'], 'Minimum') === 0, 'below the minimum stake is refused');
$r = mx_place_bet($users[0], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 9999999]);
check(!$r['ok'] && strpos($r['error'], 'Maximum') === 0, 'above the maximum stake is refused');
$saved = state_get('mx_config');
mx_config_save(['user_max_liability_market' => 1000]);
$r = mx_place_bet($users[1], $KEY, ['market_key' => 'MO', 'side' => 'LAY', 'selection' => 'a', 'stake' => 5000]);
check(!$r['ok'] && strpos($r['error'], 'exposure') !== false, 'a bet that takes a player past their exposure cap is refused');
mx_config_save(['user_max_liability_market' => 100000, 'house_max_loss_market' => 300]);
$r = mx_place_bet($users[1], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'b', 'stake' => 1000]);
check(!$r['ok'] && strpos($r['error'], 'limit') !== false, 'a bet that would take the house past its worst-case loss cap is refused');
state_set('mx_config', is_array($saved) ? $saved : mx_config_defaults());

// A bet struck just before the next ball lands is voided once that ball is reported.
$lateAt = $nextTs - 500;
at($lateAt);
$bookL = mx_markets($KEY, $lateAt);
if ($bookL['markets']['MO']['open']) {
    $rl = mx_place_bet($users[2], $KEY, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'b', 'stake' => 100]);
} else { $rl = ['ok' => false]; }
at($nextTs + 2000);
cricket_feed_ingest(cricket_mock_snapshot($KEY, $nextTs + 2000), 'mock', $nextTs + 2000);
$st = $rl['ok'] ? one('SELECT "status","void_reason" FROM "mx_bets" WHERE "id" = ?', [$rl['bet_id']]) : null;
check(!$bookL['markets']['MO']['open'] || ($st && $st['status'] === 'VOID'), 'a bet struck half a second before a ball is voided and refunded (or the market was already suspended)');

// Ball running: once the window has passed, the market suspends.
$cfgNow = mx_config();
at($nextTs + 2000 + ($cfgNow['window_seconds'] + 1) * 1000);
$b2 = mx_markets($KEY, $nextTs + 2000 + ($cfgNow['window_seconds'] + 1) * 1000);
check(!$b2['markets']['MO']['open'] && $b2['markets']['MO']['reason'] === 'Ball running', 'after the betting window the markets show BALL RUNNING');

// Abandoned: everything void.
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$K2 = cricket_mock_match_key($SLOT + 1); wipe($K2);
$s2 = cricket_mock_simulate($SLOT + 1);
$before = (float) find_user_by_id($users[0]['id'])['wallet_balance'];
for ($t = $s2['meta']['start_ms'] - 60000; $t <= $s2['ended_ms'] + 60000; $t += 3000) {
    at($t);
    cricket_feed_ingest(cricket_mock_snapshot($K2, $t), 'mock', $t);
    mx_process_match($K2, $t);
    $bk = mx_markets($K2, $t);
    if ($bk['markets']['MO']['open'] && mt_rand(0, 4) === 0) mx_place_bet($users[0], $K2, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => 'a', 'stake' => 100]);
}
unset($GLOBALS['BET1X_TEST_NOW_MS']);
$voids = (int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "status" = ?', [$K2, 'VOID'], 0);
$pend = (int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "status" = ?', [$K2, 'PENDING'], 0);
check($voids > 0 && $pend === 0 && near((float) find_user_by_id($users[0]['id'])['wallet_balance'], $before), "an abandoned match voids every match bet and refunds in full ($voids bets)");

foreach ([$KEY, $K2] as $k) wipe($k);
foreach ($users as $u) { q('DELETE FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']]); q('DELETE FROM "Transaction" WHERE "user" = ?', [$u['username']]); q('DELETE FROM "User" WHERE "id" = ?', [(int) $u['id']]); }

done:
echo "\n------------------------------------------------------------\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
