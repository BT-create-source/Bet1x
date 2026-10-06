<?php
/**
 * Your 11 on the shared feed — a full contest lifecycle against the REAL database.
 *
 *     php -d extension=pdo_pgsql php-backend/test_your11_feed.php
 *
 * Walks a simulated T20 from fixture to settlement through the exact path production uses (mock
 * snapshots -> cricket_feed_ingest -> fantasy_feed_sync), with three test accounts building teams and
 * entering every kind of contest, then checks Dream11-parity behaviour and reconciles every wallet:
 *
 *   - the standard contest set appears on its own; credits make the 100 cap a real choice
 *   - multi-entry up to the cap; switching an entry's team before — and not after — the deadline
 *   - "lineups out" flags who is playing and pays the +4 announced bonus
 *   - rival teams are hidden before the deadline and visible after it
 *   - live points equal the engine run over the stored deliveries
 *   - settlement: head-to-head paid to the higher score; under-filled flexible contests refunded
 *   - an abandoned match cancels every contest and refunds every fee in full
 *
 * It only touches its own far-future mock fixtures and y11_test_* accounts, and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
putenv('CRICKET_SOURCE=mock');
putenv('FANTASY_SOURCE=feed');
putenv('CRICKET_MOCK_ABANDON_EVERY=0');
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'bbb', 'fantasy-feed'] as $f) require_once __DIR__ . "/lib/$f.php";

$pass = 0; $fail = 0;
function check($cond, $label) { global $pass, $fail; if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; } }
function near($a, $b) { return abs((float) $a - (float) $b) < 0.005; }
function at($ms) { $GLOBALS['BET1X_TEST_NOW_MS'] = (int) $ms; }

if (!db_ready()) { echo "SKIP: database unreachable\n"; exit(0); }

function mock_fixture($slot) {
    $meta = cricket_mock_match_meta($slot);
    return roanuz_normalise_fixture([
        'key' => $meta['key'], 'name' => $meta['title'], 'short_name' => '', 'format' => 't20',
        'start_at' => (int) floor($meta['start_ms'] / 1000), 'status' => 'not_started',
        'teams' => ['a' => $meta['team_a'], 'b' => $meta['team_b']], 'venue' => ['name' => $meta['venue']],
        '_tournament' => ['key' => 'mock_league_2026', 'name' => 'Test League'],
    ]);
}
function wipe_fixture($key) {
    q('DELETE FROM "fantasy_matches" WHERE "feed_key" = ?', [$key]);
    q('DELETE FROM "bbb_rounds" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_deliveries" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_feed_raw" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_match_feed" WHERE "match_key" = ?', [$key]);
}
/** A random legal XI under the cap: 1 WK, 4 BAT, 2 ALL, 4 BOWL, max 7 from one side. */
function legal_xi($matchId, $seed) {
    $g = fantasy_players_grouped($matchId);
    mt_srand($seed);
    for ($try = 0; $try < 500; $try++) {
        $pick = [];
        foreach (['WK' => 1, 'BAT' => 4, 'ALL' => 2, 'BOWL' => 4] as $role => $n) {
            $pool = $g[$role]; shuffle($pool);
            $pick = array_merge($pick, array_slice($pool, 0, $n));
        }
        $cred = array_sum(array_column($pick, 'credits'));
        $sides = array_count_values(array_column($pick, 'team_name'));
        if ($cred <= 100 && max($sides) <= 7) return $pick;
    }
    return null;
}

$SLOT = 950000 + random_int(0, 40000);
$KEY = cricket_mock_match_key($SLOT);
wipe_fixture($KEY);
$users = [];
foreach (['y11_test_a', 'y11_test_b', 'y11_test_c'] as $u) {
    $row = get_or_create_user($u, true);
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [10000, (int) $row['id']]);
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$u]);
    $users[$u] = find_user_by_id($row['id']);
}
[$A, $B, $C] = array_values($users);

$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];

echo "\n== 1. Fixture, squads, credits, contests ==\n";
at($start - 3 * 3600000);
$r = fantasy_feed_upsert_fixture(mock_fixture($SLOT));
$matchId = $r['match_id'];
$match = fantasy_find_match($matchId);
check($matchId > 0 && (int) $match['squads_ready'] === 1, 'the fixture is upserted with a playable squad');
check((int) scalar('SELECT COUNT(*) FROM "fantasy_players" WHERE "match_id" = ?', [$matchId], 0) === 32, 'both 16-man squads are in');
$cr = one('SELECT MIN("credits") AS lo, MAX("credits") AS hi, AVG("credits") AS av FROM "fantasy_players" WHERE "match_id" = ?', [$matchId]);
check((float) $cr['hi'] - (float) $cr['lo'] >= 2.0 && (float) $cr['av'] > 8 && (float) $cr['av'] < 9.2, sprintf('credits spread %.1f–%.1f, average %.2f', $cr['lo'], $cr['hi'], $cr['av']));
$contests = fantasy_list_contests($matchId);
$byType = [];
foreach ($contests as $c) $byType[$c['contest_type']] = $c;
check(isset($byType['mega'], $byType['h2h'], $byType['small'], $byType['winner_takes_all'], $byType['practice']), 'the standard contest set was created on its own');
check(end($contests)['is_practice'] === true, 'practice is listed last');
check(fantasy_feed_upsert_fixture(mock_fixture($SLOT))['contests'] === 0, 're-syncing does not duplicate contests');

echo "\n== 2. Teams, multi-entry, switching ==\n";
$teams = [];
foreach ([$A, $B, $C] as $i => $u) {
    for ($k = 0; $k < 3; $k++) {
        $xi = legal_xi($matchId, $i * 10 + $k + 1);
        $res = fantasy_save_team((int) $u['id'], $matchId, ['players' => array_column($xi, 'id'),
            'captain' => $xi[0]['id'], 'vice_captain' => $xi[1]['id'], 'team_name' => 'T' . ($k + 1)]);
        if ($res['ok']) $teams[$u['username']][] = $res['team_id'];
    }
}
check(count($teams['y11_test_a'] ?? []) === 3 && count($teams['y11_test_b'] ?? []) === 3, 'legal XIs under 100 credits can be built');

$h2h = $byType['h2h']['id']; $small = $byType['small']['id']; $mega = $byType['mega']['id'];
$wta = $byType['wta']['id'] ?? $byType['winner_takes_all']['id']; $prac = $byType['practice']['id'];
$j = [];
$j[] = fantasy_join_contest((int) $A['id'], $A['username'], $h2h, $teams['y11_test_a'][0]);
$j[] = fantasy_join_contest((int) $B['id'], $B['username'], $h2h, $teams['y11_test_b'][0]);
$full = fantasy_join_contest((int) $C['id'], $C['username'], $h2h, $teams['y11_test_c'][0]);
check($j[0]['ok'] && $j[1]['ok'] && !$full['ok'], 'head-to-head takes exactly two and then reports full');
$s1 = fantasy_join_contest((int) $A['id'], $A['username'], $small, $teams['y11_test_a'][0]);
$s2 = fantasy_join_contest((int) $A['id'], $A['username'], $small, $teams['y11_test_a'][1]);
$s3 = fantasy_join_contest((int) $A['id'], $A['username'], $small, $teams['y11_test_a'][2]);
check($s1['ok'] && $s2['ok'] && !$s3['ok'], 'small league takes two of A\'s teams and refuses a third (max 2)');
$dup = fantasy_join_contest((int) $B['id'], $B['username'], $small, $teams['y11_test_b'][0]);
$dup2 = fantasy_join_contest((int) $B['id'], $B['username'], $small, $teams['y11_test_b'][0]);
check($dup['ok'] && !$dup2['ok'], 'the same team can never be entered twice');
fantasy_join_contest((int) $C['id'], $C['username'], $wta, $teams['y11_test_c'][0]);
fantasy_join_contest((int) $C['id'], $C['username'], $mega, $teams['y11_test_c'][1]);
foreach ([$A, $B, $C] as $u) fantasy_join_contest((int) $u['id'], $u['username'], $prac, $teams[$u['username']][2]);
$fees = ['y11_test_a' => 50 + 25 + 25, 'y11_test_b' => 50 + 25, 'y11_test_c' => 100 + 29];
foreach ([$A, $B, $C] as $u) {
    check(near(find_user_by_id($u['id'])['wallet_balance'], 10000 - $fees[$u['username']]), "{$u['username']} paid exactly the fees for their entries; practice cost nothing");
}
$entryA = one('SELECT "id" FROM "fantasy_contest_entries" WHERE "contest_id" = ? AND "user_id" = ? ORDER BY "id" LIMIT 1', [$h2h, (int) $A['id']]);
$sw = fantasy_switch_entry_team((int) $A['id'], (int) $entryA['id'], $teams['y11_test_a'][1]);
check($sw['ok'] && (int) scalar('SELECT "user_team_id" FROM "fantasy_contest_entries" WHERE "id" = ?', [(int) $entryA['id']]) === $teams['y11_test_a'][1], 'switching an entry\'s team works before the deadline, with no money moved');
$peek = fantasy_entry_team_public((int) $entryA['id']);
check(!$peek['ok'] && $peek['status'] === 403, 'a rival\'s team is hidden before the deadline');
$sel = fantasy_selection_stats($matchId);
check($sel['teams'] === 9 && max(array_map(function ($p) { return $p['sel'] ?? 0; }, $sel['players'])) <= 100, '"selected by" percentages are computed over all 9 teams');

echo "\n== 3. Lineups out ==\n";
at($start - 20 * 60000);
cricket_feed_ingest(cricket_mock_snapshot($KEY, $start - 20 * 60000), 'mock', $start - 20 * 60000);
fantasy_feed_sync($KEY, true);
$match = fantasy_find_match($matchId);
check((int) $match['lineups_announced'] === 1 && $match['toss_text'], 'lineups are announced with the toss: ' . $match['toss_text']);
check((int) scalar('SELECT COUNT(*) FROM "fantasy_players" WHERE "match_id" = ? AND "is_playing" = 1', [$matchId], 0) === 22, '22 players are flagged playing');
check((int) scalar('SELECT COUNT(*) FROM "fantasy_players" WHERE "match_id" = ? AND "is_playing" = 0', [$matchId], 0) === 10, 'the other 10 are flagged not playing');
$pre = (float) scalar('SELECT MAX("total_points") FROM "fantasy_user_teams" WHERE "match_id" = ?', [$matchId], 0);
check($pre > 0 && fmod($pre, 2) == 0, "teams already carry the +4 announced-lineup points before a ball ($pre)");

echo "\n== 4. The match, live ==\n";
at($start + 60000);
$late = fantasy_switch_entry_team((int) $A['id'], (int) $entryA['id'], $teams['y11_test_a'][0]);
check(!$late['ok'], 'switching is refused once the deadline has passed');
$lateJoin = fantasy_join_contest((int) $C['id'], $C['username'], $small, $teams['y11_test_c'][2]);
check(!$lateJoin['ok'], 'joining is refused once the deadline has passed');
for ($t = $start; $t <= $sim['ended_ms'] + 60000; $t += 60000) {
    at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY, $t), 'mock', $t);
}
at($sim['ended_ms'] + 120000);
fantasy_feed_sync($KEY, true);
$feed = cricket_match_feed_row($KEY);
check($feed['status'] === 'completed', 'the feed reports the match completed: ' . $feed['result_text']);
$peek = fantasy_entry_team_public((int) $entryA['id']);
check($peek['ok'] && count($peek['players']) === 11, 'after the deadline any entry\'s team can be viewed with its points');

// Points integrity: stored points == the engine over the stored deliveries.
$derived = cricket_derive_player_stats(cricket_match_deliveries($KEY), $feed['lineups']);
$rules = fantasy_scoring_rules('T20');
$bad = 0; $n = 0;
foreach (all('SELECT p."external_key", p."role", s."calculated_fantasy_points" FROM "fantasy_player_live_stats" s JOIN "fantasy_players" p ON p."id" = s."player_id" WHERE s."match_id" = ?', [$matchId]) as $row) {
    $st = $derived[$row['external_key']] ?? null; if (!$st) continue;
    $st['role'] = $row['role'];
    if (!near(fantasy_score_player($st, $rules)['points'], $row['calculated_fantasy_points'])) $bad++;
    $n++;
}
check($n >= 22 && $bad === 0, "every stored player score equals the engine run over the deliveries ($n players)");
$teamOk = true;
foreach (all('SELECT "id","total_points" FROM "fantasy_user_teams" WHERE "match_id" = ?', [$matchId]) as $tm) {
    $full = fantasy_team_with_players((int) $tm['id'], (int) scalar('SELECT "user_id" FROM "fantasy_user_teams" WHERE "id" = ?', [(int) $tm['id']]));
    $sc = fantasy_score_team($full['players'], fantasy_stored_stats($matchId), $rules);
    if (!near($sc['total'], $tm['total_points'])) $teamOk = false;
}
check($teamOk, 'every team total equals the sum of its players with C 2x / VC 1.5x applied');
$hist = (int) scalar('SELECT COUNT(*) FROM "fantasy_player_history" WHERE "match_id" = ?', [$matchId], 0);
check($hist >= 22, "player history recorded for credit pricing ($hist players)");

echo "\n== 4b. Calibration against official points ==\n";
// Pretend the official platform stacks wicket-haul bonuses: build its "official" totals that way, and
// check calibration identifies that variant and that applying it changes the stored points.
$cands = all('SELECT p."id", s."wickets" FROM "fantasy_player_live_stats" s JOIN "fantasy_players" p ON p."id" = s."player_id" WHERE s."match_id" = ? ORDER BY s."wickets" DESC LIMIT 6', [$matchId]);
$stored = fantasy_stored_stats($matchId);
$official = [];
foreach ($cands as $c) {
    $st = $stored[(int) $c['id']]; $st['role'] = scalar('SELECT "role" FROM "fantasy_players" WHERE "id" = ?', [(int) $c['id']]);
    $official[(int) $c['id']] = fantasy_score_player($st, fantasy_scoring_rules('T20', ['milestones_cumulative' => true, 'hauls_cumulative' => true]))['points'];
}
$hasHaul = (int) $cands[0]['wickets'] >= 4;
$cal = fantasy_calibrate($matchId, $official, false);
check($cal['ok'] && $cal['best']['exact'] === count($official), 'calibration reproduces every official total exactly with the right variant');
check(!$hasHaul || $cal['best']['variant']['hauls_cumulative'] === true, 'and identifies that the official platform stacks haul bonuses' . ($hasHaul ? '' : ' (no 4-wicket haul in this match, so either variant fits)'));
$savedVariants = state_get('fantasy_rule_variants');
fantasy_calibrate($matchId, $official, true);
$after = (float) scalar('SELECT "calculated_fantasy_points" FROM "fantasy_player_live_stats" WHERE "match_id" = ? AND "player_id" = ?', [$matchId, (int) $cands[0]['id']]);
check(abs($after - $official[(int) $cands[0]['id']]) < 0.01, 'applying it re-scores the stored points to the official figure');
state_set('fantasy_rule_variants', is_array($savedVariants) ? $savedVariants : []);
$GLOBALS['BET1X_RULES_EPOCH'] = ($GLOBALS['BET1X_RULES_EPOCH'] ?? 0) + 1;
fantasy_rescore_match($matchId);

echo "\n== 5. Settlement ==\n";
$set = fantasy_settle_match($matchId, false);
check(empty($set['errors']), 'the match settles without errors');
$h2hRows = all('SELECT "user_id","points","rank","prize_won" FROM "fantasy_contest_entries" WHERE "contest_id" = ? ORDER BY "points" DESC', [$h2h]);
if (near($h2hRows[0]['points'], $h2hRows[1]['points'])) {
    check(near($h2hRows[0]['prize_won'] + $h2hRows[1]['prize_won'], 85), 'a tied head-to-head splits the 85 pool');
} else {
    check(near($h2hRows[0]['prize_won'], 85) && near($h2hRows[1]['prize_won'], 0), 'head-to-head pays 85 (100 less 15% rake) to the higher score');
}
$st = function ($id) { return one('SELECT "status" FROM "fantasy_contests" WHERE "id" = ?', [$id])['status']; };
check($st($small) === 'CANCELLED' && $st($mega) === 'CANCELLED' && $st($wta) === 'CANCELLED', 'under-filled flexible contests are cancelled');
check($st($h2h) === 'SETTLED' && $st($prac) === 'SETTLED', 'filled contests settle');
foreach ([$A, $B, $C] as $u) {
    $won = (float) scalar('SELECT COALESCE(SUM("prize_won"),0) FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE e."user_id" = ? AND c."status" = ?', [(int) $u['id'], 'SETTLED'], 0);
    $refunded = (float) scalar('SELECT COALESCE(SUM(e."entry_fee_paid"),0) FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE e."user_id" = ? AND c."status" = ?', [(int) $u['id'], 'CANCELLED'], 0);
    $bal = (float) find_user_by_id($u['id'])['wallet_balance'];
    check(near($bal, 10000 - $fees[$u['username']] + $won + $refunded), sprintf('%s: 10000 − fees %.0f + prizes %.2f + refunds %.2f = %.2f', $u['username'], $fees[$u['username']], $won, $refunded, $bal));
}
$again = fantasy_settle_match($matchId, false);
check(near($again['paid'], 0), 'settling again pays nothing');
check(fantasy_find_match($matchId)['status'] === 'SETTLED', 'the fixture is marked settled');

echo "\n== 6. An abandoned match refunds everything ==\n";
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$SLOT2 = $SLOT + 1; $KEY2 = cricket_mock_match_key($SLOT2);
wipe_fixture($KEY2);
$sim2 = cricket_mock_simulate($SLOT2);
at($sim2['meta']['start_ms'] - 3 * 3600000);
$r2 = fantasy_feed_upsert_fixture(mock_fixture($SLOT2));
$m2 = $r2['match_id'];
$c2 = [];
foreach (fantasy_list_contests($m2) as $c) $c2[$c['contest_type']] = $c['id'];
$balBefore = [];
foreach ([$A, $B] as $i => $u) {
    $balBefore[$u['username']] = (float) find_user_by_id($u['id'])['wallet_balance'];
    $xi = legal_xi($m2, 77 + $i);
    $t = fantasy_save_team((int) $u['id'], $m2, ['players' => array_column($xi, 'id'), 'captain' => $xi[0]['id'], 'vice_captain' => $xi[1]['id']]);
    fantasy_join_contest((int) $u['id'], $u['username'], $c2['h2h'], $t['team_id']);
}
check((int) one('SELECT "filled_spots" FROM "fantasy_contests" WHERE "id" = ?', [$c2['h2h']])['filled_spots'] === 2, 'a full head-to-head on the rain-hit match');
for ($t = $sim2['meta']['start_ms'] - 20 * 60000; $t <= $sim2['ended_ms'] + 60000; $t += 60000) {
    at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY2, $t), 'mock', $t);
}
at($sim2['ended_ms'] + 120000);
fantasy_feed_sync($KEY2, true);
check(fantasy_find_match($m2)['source_state'] === 'abandoned', 'the match is recorded abandoned');
$set2 = fantasy_settle_match($m2, false);
check(one('SELECT "status" FROM "fantasy_contests" WHERE "id" = ?', [$c2['h2h']])['status'] === 'CANCELLED', 'even the FULL head-to-head is cancelled, not paid');
check(fantasy_find_match($m2)['status'] === 'CANCELLED', 'the fixture is marked cancelled');
foreach ([$A, $B] as $u) {
    check(near(find_user_by_id($u['id'])['wallet_balance'], $balBefore[$u['username']]), "{$u['username']} got the full 50 back — no rake on an abandoned match");
}
$force = fantasy_settle_contest($c2['h2h'], true);
check(!empty($force['already_cancelled']) || (isset($force['ok']) && $force['ok'] === false) || !empty($force['voided']), 'not even a forced settle can pay out an abandoned match');

echo "\n== 7. Credits learn from history ==\n";
foreach ([1, 2, 3] as $i) {
    q('INSERT INTO "fantasy_player_history" ("player_key","match_id","format","points","played_at") VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
      ['y11_test_star', 900000 + $i, 'T20', 60, ms_to_sql(now_ms())]);
}
check(fantasy_feed_credits('y11_test_star', 'BAT', 0.3) === 10.5 - 0.5 || fantasy_feed_credits('y11_test_star', 'BAT', 0.3) === 10.5,
      'a player averaging 60 points prices at the top of the band regardless of the source rating: ' . fantasy_feed_credits('y11_test_star', 'BAT', 0.3));
check(fantasy_feed_credits('y11_test_nobody', 'ALL', null) === 8.5, 'with no history and no rating, a role default (all-rounder 8.5)');

// cleanup
unset($GLOBALS['BET1X_TEST_NOW_MS']);
q('DELETE FROM "fantasy_player_history" WHERE "player_key" = ? OR "match_id" IN (?, ?)', ['y11_test_star', $matchId, $m2]);
wipe_fixture($KEY); wipe_fixture($KEY2);
foreach ($users as $u) {
    q('DELETE FROM "Transaction" WHERE "user" = ?', [$u['username']]);
    q('DELETE FROM "User" WHERE "id" = ?', [(int) $u['id']]);
}

echo "\n------------------------------------------------------------\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
