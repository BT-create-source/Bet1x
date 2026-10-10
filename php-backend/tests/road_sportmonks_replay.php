<?php
/**
 * Road test — two REAL Sportmonks matches replayed through the whole pipeline with players betting.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_sportmonks_replay.php [--users=40] [--keep]
 *
 * Source: the snapshots the live poller archived in cricket_feed_raw on 9 Oct 2026 (Pakistan v Sri Lanka
 * sm_71344, India v West Indies sm_71010), replayed in order at the instants they were received, under a
 * private match key, with CRICKET_SOURCE=sportmonks so Ball by Ball uses the polled-feed rules (15s
 * window, 20s confirmation hold). Between snapshots the clock steps every 4s exactly as the cron does.
 *
 * Checked independently: the final scores against the real ones; every settled Ball by Ball round against
 * a fresh reading of that ball in the final snapshot; every round's pool = payouts + rake (or a full
 * refund); Match Odds on the real winner; no wallet negative; every rupee reconciled.
 *
 * The India v West Indies archive was recorded before the result cross-check existed and carries the
 * wrong winner Sportmonks first sent (India); the replay corrects it to the real one (West Indies), as
 * the live poller now waits for. Needs the archive in the dev database (skips cleanly when absent).
 */
require __DIR__ . '/road_common.php';
putenv('CRICKET_SOURCE=sportmonks');   // polled-feed Ball by Ball rules for the whole run

$N = 40;
foreach ($argv as $a) if (preg_match('/^--users=(\d+)$/', $a, $m)) $N = max(4, (int) $m[1]);
road_wipe_users('s');
mt_srand(91026);
$users = road_users('s', $N, function ($i) { return $i === 1 ? 300 : 20000; });
$U = array_values($users);
$cfg = bbb_config();
road_section('Setup');
road_info("$N players; Ball by Ball window {$cfg['window_seconds']}s, confirmation hold {$cfg['confirm_seconds']}s, rake {$cfg['rake_pct']}%");
road_check(cricket_source_mode() === 'sportmonks' && $cfg['confirm_seconds'] >= 20 && $cfg['window_seconds'] >= 15, 'the polled-feed rules are in force');

$MATCHES = [
    'sm_71344' => ['label' => 'Pakistan v Sri Lanka', 'scores' => ['182/8', '187/6'], 'winner' => 'a', 'msg' => 'Pakistan won by 4 wickets (with 7 balls remaining)'],
    'sm_71010' => ['label' => 'India v West Indies', 'scores' => ['249/5', '252/4'], 'winner' => 'b', 'msg' => 'West Indies won by 6 wickets (with 9 balls remaining)'],
];
$keys = [];

foreach ($MATCHES as $src => $real) {
    road_section("Replay: {$real['label']} ($src)");
    $rows = all('SELECT "body","received_at" FROM "cricket_feed_raw" WHERE "match_key" = ? ORDER BY "id" ASC', [$src]);
    if (count($rows) < 20) { road_info('archive not in this database — SKIPPED'); continue; }
    $K = 'sm_9' . random_int(1000000, 9999999);
    $keys[] = $K;
    road_wipe_match($K);
    road_info(count($rows) . ' archived snapshots → replayed as ' . $K);

    $bbbBets = 0; $bbbRefused = 0; $moBets = 0; $last = null;
    $times = array_map(function ($r) { return sql_to_ms($r['received_at']); }, $rows);
    foreach ($rows as $i => $row) {
        $body = json_decode($row['body'], true);
        $body['key'] = $K;
        $done = in_array($body['status'] ?? '', ['completed'], true);
        if ($done) $body['play']['result'] = ['msg' => $real['msg'], 'winner' => $real['winner']];   // the corrected result
        $t = $times[$i];
        road_at($t);
        cricket_feed_ingest($body, 'poll', $t);
        // The cron's 4-second passes until the next snapshot arrived.
        $next = $times[$i + 1] ?? $t + 60000;
        for ($c = $t; $c < $next; $c += 4000) {
            road_at($c);
            bbb_process_match($K, $c);
            mx_process_match($K, $c);
            if ($c !== $t && ($c - $t) % 8000) continue;   // players act every ~8s
            // Ball by Ball: a few players back an outcome on the open round.
            $open = one('SELECT "id","closes_at" FROM "bbb_rounds" WHERE "match_key" = ? AND "status" = ? ORDER BY "id" DESC LIMIT 1', [$K, 'OPEN']);
            if ($open && sql_to_ms($open['closes_at']) > $c + 1000) {
                for ($k = 0; $k < 4; $k++) {
                    $u = $U[mt_rand(0, $N - 1)];
                    $r = bbb_place_bet($u, (int) $open['id'], ['0', '1', '2', '4', '6', 'W', 'EX'][mt_rand(0, 6)], [10, 50, 100, 200][mt_rand(0, 3)]);
                    $r['ok'] ? $bbbBets++ : $bbbRefused++;
                }
            }
            // Match Odds when open.
            $book = mx_markets($K, $c);
            if ($book && !empty($book['markets']['MO']['open']) && mt_rand(0, 2) === 0) {
                $sel = mt_rand(0, 1) ? 'a' : 'b';
                $r = mx_place_bet($U[mt_rand(1, $N - 1)], $K, ['market_key' => 'MO', 'side' => 'BACK', 'selection' => $sel, 'stake' => 200]);
                if ($r['ok']) $moBets++;
            }
        }
        $last = $body;
    }
    // Let the last holds expire.
    $end = end($times) + 120000;
    road_at($end); bbb_process_match($K, $end); mx_process_match($K, $end);
    road_clock_off();

    $feed = cricket_match_feed_row($K);
    $board = cricket_derive_scoreboard($feed, cricket_match_deliveries($K));
    $scores = array_map(function ($x) { return $x['runs'] . '/' . $x['wickets']; }, $board['innings'] ?? []);
    road_info("$bbbBets Ball by Ball bets ($bbbRefused refused), $moBets Match Odds bets; final " . implode(', ', $scores));
    road_check($scores === $real['scores'], 'final scores equal the real ones (' . implode(', ', $real['scores']) . ')', $scores);
    road_check($feed['status'] === 'completed' && ($feed['meta']['winner'] ?? null) === $real['winner'], "completed with the real winner ({$real['msg']})");

    // Ball by Ball: every settled round against an independent reading of its ball in the final snapshot.
    $order = array_flip($last['play']['innings_order'] ?? []);
    $byInn = [];
    foreach ($last['related_balls'] as $b) {
        $inn = ($order[$b['innings']] ?? 0) + 1;
        $byInn[$inn][] = $b;
    }
    $bad = []; $stat = [];
    foreach (all('SELECT * FROM "bbb_rounds" WHERE "match_key" = ?', [$K]) as $r) {
        $stat[$r['status']] = ($stat[$r['status']] ?? 0) + 1;
        $pool = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ?', [(int) $r['id']], 0);
        $paid = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "round_id" = ?', [(int) $r['id']], 0);
        if ($r['status'] === 'VOID') { if (!road_near($paid, $pool)) $bad[] = "void round {$r['id']} refunded $paid of $pool"; continue; }
        if ($r['status'] !== 'SETTLED') { $bad[] = "round {$r['id']} left {$r['status']}"; continue; }
        $b = $byInn[(int) $r['innings']][(int) $r['delivery_idx']] ?? null;
        if (!$b) { $bad[] = "round {$r['id']}: no such ball"; continue; }
        $type = $b['ball_type']; $runs = (int) $b['team_score']['runs'];
        $expect = in_array($type, ['wide', 'no_ball'], true) ? 'EX' : (!empty($b['team_score']['is_wicket']) ? 'W' : (string) $runs);
        if ($expect !== (string) $r['outcome']) $bad[] = "round {$r['id']} ({$r['label']}): settled {$r['outcome']}, ball says $expect";
        $winners = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ? AND "outcome" = ?', [(int) $r['id'], (string) $r['outcome']], 0);
        // Pool rules: nobody on the winner -> full refund; else winners share pool less rake, never below their stake.
        if ($pool > 0) {
            if ($winners == 0.0) { if (!road_near($paid, $pool)) $bad[] = "round {$r['id']}: nobody won but refunded $paid of $pool"; }
            else {
                $net = round($pool * (1 - (float) $r['rake_pct'] / 100), 2);
                if ($paid > $pool + 0.01 || $paid + 0.02 < min($net, $pool) && $paid + 0.02 < $winners) $bad[] = "round {$r['id']}: paid $paid of pool $pool (net $net, winners staked $winners)";
            }
        }
    }
    road_check(!$bad, 'every Ball by Ball round: outcome = an independent reading of its ball; pools paid or refunded exactly ' . json_encode($stat), array_slice($bad, 0, 8));
    $mo = one('SELECT * FROM "mx_markets" WHERE "match_key" = ? AND "market_key" = ?', [$K, 'MO']);
    road_check(!$moBets || ($mo && $mo['status'] === 'SETTLED' && $mo['result'] === $real['winner']), 'Match Odds settled on the real winner', $mo);
    road_check((int) scalar('SELECT COUNT(*) FROM "mx_bets" WHERE "match_key" = ? AND "status" = ?', [$K, 'PENDING'], 0) === 0
               && (int) scalar('SELECT COUNT(*) FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" WHERE r."match_key" = ? AND b."status" = ?', [$K, 'PENDING'], 0) === 0,
               'nothing left pending after the match');
}

road_section('Every rupee reconciled');
$neg = 0; $badU = [];
foreach ($U as $u) {
    $now = road_balance($u);
    if ($now < -0.001) $neg++;
    $stake = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']], 0)
           + (float) scalar('SELECT COALESCE(SUM("liability"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $back = (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "bbb_bets" WHERE "user_id" = ?', [(int) $u['id']], 0)
          + (float) scalar('SELECT COALESCE(SUM("payout"),0) FROM "mx_bets" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $l = road_ledger($u['username']);
    if (!road_near($now, $u['_start'] - $stake + $back, 0.02) || !road_near($l['out'], $stake, 0.02) || !road_near($l['in'], $back, 0.02)) $badU[] = "{$u['username']}: now $now, start {$u['_start']} − $stake + $back; ledger out {$l['out']} in {$l['in']}";
}
road_check($neg === 0, 'no wallet went negative (one player started with only ₹300)');
road_check(!$badU, "all $N wallets: start − staked + returned = balance, and the ledger agrees", array_slice($badU, 0, 5));

if (!$GLOBALS['ROAD_KEEP']) { foreach ($keys as $K) road_wipe_match($K); road_wipe_users('s'); }
road_finish('road_sportmonks_replay');
