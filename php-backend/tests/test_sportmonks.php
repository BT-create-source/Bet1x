<?php
/**
 * Sportmonks connector — checked against REAL replies saved during the 9 Oct 2026 live test
 * (tests/fixtures/sportmonks/: Pakistan v Sri Lanka 71344 and India v West Indies 71010, both T20Is,
 * fetched mid-match with SM_LIVE_INCLUDE). No network, no database.
 *
 *     php php-backend/tests/test_sportmonks.php
 *
 * The strongest check here is reconciliation: every innings is rebuilt ball by ball from our parsed
 * deliveries and must land exactly on Sportmonks' own scoreboard (runs, wickets, overs).
 */
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-sportmonks', 'fantasy-feed'] as $f) require_once "$root/lib/$f.php";

$pass = 0; $fail = 0;
function check($c, $l, $d = null) { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; if ($d !== null) echo '        ' . json_encode($d) . "\n"; } }
function fx($name) { return json_decode(file_get_contents(__DIR__ . '/fixtures/sportmonks/' . $name), true)['data']; }

foreach (['71344' => 'Pakistan v Sri Lanka', '71010' => 'India v West Indies'] as $id => $label) {
    echo "== $label ($id) ==\n";
    $f = fx("fixture-$id-live.json");
    $fx = sm_normalise_fixture($f);
    check($fx['key'] === "sm_$id" && $fx['format'] === 'T20' && $fx['status'] === 'live' && $fx['start_ms'] > 1700000000000
          && $fx['teams']['a']['key'] !== $fx['teams']['b']['key'] && $fx['tournament_key'] !== '',
          "fixture: key, T20, live, start time, two teams, season id ({$fx['name']})", $fx);

    $snap = sm_snapshot($f);
    $p = cricket_parse_snapshot($snap);
    check($p['key'] === "sm_$id" && $p['status'] === 'live', 'the pipeline parser accepts the snapshot as a live match');
    check(count($p['lineups']['a'] ?? []) === 11 && count($p['lineups']['b'] ?? []) === 11, 'both playing XIs have exactly 11');
    $roles = []; foreach (array_merge($p['lineups']['a'], $p['lineups']['b']) as $k) $roles[$p['players'][$k]['role']] = true;
    check(isset($roles['WK'], $roles['BAT'], $roles['ALL'], $roles['BOWL']), 'all four Your 11 roles appear across the XIs ' . json_encode(array_keys($roles)));
    $tossSide = $p['toss']['winner'] ?? null;
    check($tossSide === sm_team_key($f['toss_won_team_id']) && in_array($p['toss']['elected'], ['bat', 'bowl'], true), 'toss winner and decision map across');
    check(count($p['balls']) === count(sm_list($f['balls'])), 'every Sportmonks ball becomes one delivery (' . count($p['balls']) . ')');
    $nInn = count(sm_list($f['runs']));
    check(count($p['innings_order']) === $nInn && ($nInn < 2 || $p['innings_order'][0][0] !== $p['innings_order'][1][0]),
          "innings order: $nInn innings so far, alternating sides " . json_encode($p['innings_order']));

    // Reconciliation against Sportmonks' own scoreboard.
    $mine = [];
    foreach ($p['balls'] as $b) {
        $i = $b['innings'];
        $mine[$i] = $mine[$i] ?? ['runs' => 0, 'wkts' => 0, 'legal' => 0];
        $mine[$i]['runs'] += $b['batsman_runs'] + $b['extra_runs'];
        $mine[$i]['wkts'] += $b['is_wicket'];
        $mine[$i]['legal'] += $b['is_legal'];
    }
    foreach (sm_list($f['runs']) as $r) {
        $i = (int) $r['inning'];
        $m = $mine[$i] ?? ['runs' => 0, 'wkts' => 0, 'legal' => 0];
        $ov = intdiv($m['legal'], 6) + ($m['legal'] % 6) / 10;
        check($m['runs'] === (int) $r['score'] && $m['wkts'] === (int) $r['wickets'] && abs($ov - (float) $r['overs']) < 0.001,
              "innings $i rebuilt ball by ball = Sportmonks' scoreboard: {$m['runs']}/{$m['wkts']} ($ov) vs {$r['score']}/{$r['wickets']} ({$r['overs']})");
    }
    // Every batter, bowler and dismissed player is someone in the lineups.
    $known = $p['players']; $missing = [];
    foreach ($p['balls'] as $b) foreach (['batsman_key', 'bowler_key', 'out_player_key'] as $c) if ($b[$c] && !isset($known[$b[$c]])) $missing[$b[$c]] = true;
    check(!$missing, 'every batter, bowler and dismissed player is in the lineups', array_keys($missing));
}

echo "== Ball mapping (score objects as observed live) ==\n";
$ball = function ($score, $extra = []) {
    $raw = sm_ball_raw($extra + ['id' => 1, 'ball' => 3.4, 'batsman_id' => 10, 'bowler_id' => 20, 'batsman_one_on_creeze_id' => 10,
                                 'batsman_two_on_creeze_id' => 11, 'updated_at' => '2026-10-09T14:34:12.000000Z',
                                 'score' => $score + ['runs' => 0, 'four' => false, 'six' => false, 'bye' => 0, 'leg_bye' => 0, 'noball' => 0, 'noball_runs' => 0, 'is_wicket' => false]],
                       'a_1', 'smt_1');
    return cricket_parse_ball($raw, ['a_1' => 1]);
};
$d = $ball(['name' => '5 Wides', 'runs' => 5, 'ball' => false]);
check($d['extra_type'] === 'wide' && $d['extra_runs'] === 5 && $d['batsman_runs'] === 0 && !$d['is_legal'] && !$d['is_boundary'], '"5 Wides": 5 extras, nothing to the batter, not legal, not a boundary', $d);
$d = $ball(['name' => '1 No Ball + 1 Run', 'runs' => 2, 'noball' => 1, 'noball_runs' => 1]);
check($d['extra_type'] === 'noball' && $d['batsman_runs'] === 1 && $d['extra_runs'] === 1 && !$d['is_legal'], '"1 No Ball + 1 Run": 1 to the batter, 1 extra, not legal');
$d = $ball(['name' => '1 No Ball + 1 Bye', 'runs' => 1, 'noball' => 1, 'noball_runs' => 1, 'bye' => 1]);
check($d['batsman_runs'] === 0 && $d['extra_runs'] === 2, '"1 No Ball + 1 Bye": 2 extras (runs excludes the bye)');
$d = $ball(['name' => '4 Byes', 'bye' => 4]);
check($d['extra_type'] === 'bye' && $d['extra_runs'] === 4 && $d['batsman_runs'] === 0 && $d['is_legal'] && !$d['is_boundary'], '"4 Byes": 4 extras, legal, not a batter\'s boundary');
$d = $ball(['name' => 'FOUR', 'runs' => 4, 'four' => true]);
check($d['batsman_runs'] === 4 && $d['is_boundary'] === 4 && $d['over_no'] === 3 && $d['ball_no'] === 4 && $d['non_striker_key'] === 'smp_11'
      && $d['ts_ms'] === strtotime('2026-10-09T14:34:12Z') * 1000, 'FOUR: boundary, over 3 ball 4, non-striker, and the ball keeps its own posting time');
$d = $ball(['name' => 'Catch Out', 'is_wicket' => true, 'out' => true], ['catchstump_id' => 30, 'batsmanout_id' => 10]);
check($d['is_wicket'] && $d['wicket_type'] === 'caught' && $d['out_player_key'] === 'smp_10' && $d['fielders'][0]['key'] === 'smp_30' && $d['fielders'][0]['catch'], 'Catch Out: caught, the catcher credited');
$d = $ball(['name' => 'Catch Out', 'is_wicket' => true], ['catchstump_id' => 20]);
check($d['wicket_type'] === 'caught_and_bowled', 'a catch taken by the bowler is caught and bowled');
$d = $ball(['name' => 'Run Out', 'is_wicket' => true], ['runout_by_id' => 31, 'batsmanout_id' => 11]);
check($d['wicket_type'] === 'run_out' && $d['out_player_key'] === 'smp_11' && $d['fielders'][0]['runout'], 'Run Out: the non-striker out, the thrower credited');
check($ball(['name' => 'Clean Bowled', 'is_wicket' => true])['wicket_type'] === 'bowled' && $ball(['name' => 'LBW OUT', 'is_wicket' => true])['wicket_type'] === 'lbw'
      && $ball(['name' => 'Stump Out', 'is_wicket' => true], ['catchstump_id' => 40])['wicket_type'] === 'stumped', 'bowled, lbw and stumped');

echo "== Status ==\n";
check(sm_status('NS') === 'not_started' && sm_status('1st Innings') === 'live' && sm_status('2nd Innings') === 'live' && sm_status('Innings Break') === 'innings_break'
      && sm_status('Finished') === 'completed' && sm_status('Aban.') === 'abandoned' && sm_status('Cancl.') === 'abandoned' && sm_status('Int.') === 'live',
      'NS, innings, break, finished, abandoned, cancelled, interrupted');
check(sm_status('NS', true) === 'not_started' && sm_status('Delayed', true) === 'not_started',
      'the "live" flag (live coverage available, true days ahead) never makes an unstarted match live');
$ns = fx('fixture-71344-live.json'); $ns['status'] = 'NS'; $ns['live'] = true; $ns['balls'] = []; $ns['runs'] = [];
check(sm_normalise_fixture($ns)['status'] === 'not_started' && cricket_parse_snapshot(sm_snapshot($ns))['status'] === 'not_started',
      'an NS fixture with live coverage is not_started in the fixture list and in the snapshot');

check(sm_status('Finished', false, 'Match abandoned without a ball bowled') === 'abandoned' && sm_status('Finished', false, '', 'no result') === 'abandoned'
      && sm_status('Finished', false, 'India won by 25 runs') === 'completed', '"Finished" with a no-result note refunds; a real result settles');
$done = fx('fixture-71344-live.json'); $done['status'] = 'Finished'; $done['live'] = false; $done['winner_team_id'] = $done['localteam_id']; $done['note'] = 'Pakistan won by 5 wickets';
foreach ($done['runs'] as &$r) if ((int) $r['inning'] === 2) { $r['score'] = 187; $r['wickets'] = 6; $r['overs'] = 18.5; } unset($r);   // the real final: PAK 187/6 chasing 182
$p = cricket_parse_snapshot(sm_snapshot($done));
check($p['status'] === 'completed' && $p['winner'] === 'a' && $p['result_text'] === 'Pakistan won by 5 wickets', 'a finished match: completed, winner side a, result text kept for settlement', array_intersect_key($p, array_flip(['status', 'winner', 'result_text'])));
// The real 9 Oct failure: India v West Indies "Finished", winner_team_id = India, no note — but WI chased 252/4 v 249/5.
$wrong = fx('fixture-71010-live.json'); $wrong['status'] = 'Finished'; $wrong['live'] = false; $wrong['winner_team_id'] = $wrong['localteam_id']; $wrong['note'] = null;
$wrong['runs'] = [['team_id' => $wrong['localteam_id'], 'inning' => 1, 'score' => 249, 'wickets' => 5, 'overs' => 20], ['team_id' => $wrong['visitorteam_id'], 'inning' => 2, 'score' => 252, 'wickets' => 4, 'overs' => 18.3]];
$p = cricket_parse_snapshot(sm_snapshot($wrong));
check($p['status'] === 'live' && $p['status_text'] === 'awaiting confirmed result', 'REAL CASE: Finished with a winner and no note is held, nothing settles', array_intersect_key($p, array_flip(['status', 'status_text'])));
$wrong['note'] = 'India won by 6 wickets';
check(cricket_parse_snapshot(sm_snapshot($wrong))['status'] === 'live', 'REAL CASE: even with a note, a winner who scored fewer runs is held');
$wrong['winner_team_id'] = $wrong['visitorteam_id']; $wrong['note'] = 'West Indies won by 6 wickets (with 9 balls remaining)';
$p = cricket_parse_snapshot(sm_snapshot($wrong));
check($p['status'] === 'completed' && $p['winner'] === 'b', 'once Sportmonks corrects it (winner WI, note agrees, runs agree) it completes on West Indies');
$early = $done; $early['winner_team_id'] = null; $early['note'] = '';
check(cricket_parse_snapshot(sm_snapshot($early))['status'] === 'live', '"Finished" before the winner is filled in stays in play (else match bets would void as a tie)');
$early['note'] = 'Match tied (Pakistan won the Super Over)';
check(cricket_parse_snapshot(sm_snapshot($early))['status'] === 'completed', 'a genuine tie with no winner id does complete');
$done['winner_team_id'] = null; $done['draw_noresult'] = 'no result'; $done['note'] = 'No result';
check(cricket_parse_snapshot(sm_snapshot($done))['status'] === 'abandoned', 'a finished match with no result is abandoned (refund path)');

echo "== Snapshot flags ==\n";
$s = sm_snapshot(fx('fixture-71344-live.json'));
check(cricket_parse_snapshot($s)['balls_complete'] === true, 'a Sportmonks snapshot says it lists every ball (so withdrawn balls can be removed)');
check(($s['play']['target'] ?? null) === 183, 'second-innings target = first innings + 1 (183, as Sportmonks\' note says)', $s['play']['target'] ?? null);

echo "== Credits from career form ==\n";
$career = fx('player-3338-career.json')['career'];
$sk = sm_skill_from_career($career, 'T20');
check($sk !== null && fantasy_feed_credits('x', 'ALL', $sk, 'T20') >= 9.5, 'Abhishek Sharma (T20 + T20I record) prices as a star: ' . fantasy_feed_credits('x', 'ALL', $sk, 'T20') . ' credits');
check(sm_skill_from_career(array_slice($career, 0, 1), 'T20') === null, 'under 5 matches of record: no skill (falls back to the role default)');
check(sm_skill_from_career($career, 'TEST') === null, 'a format with no record gives no skill');
$row = function ($m, $runs, $balls, $f, $s, $wm, $wk, $ov, $conc) { return ['type' => 'T20', 'batting' => ['matches' => $m, 'runs_scored' => $runs, 'balls_faced' => $balls, 'four_x' => $f, 'six_x' => $s], 'bowling' => ['matches' => $wm, 'wickets' => $wk, 'overs' => $ov, 'runs' => $conc]]; };
$tail = sm_skill_from_career([$row(30, 120, 130, 8, 1, 30, 4, 60, 560)], 'T20');
$strikeBowler = sm_skill_from_career([$row(30, 80, 90, 5, 1, 30, 45, 110, 800)], 'T20');
$opener = sm_skill_from_career([$row(30, 1050, 740, 110, 40, 2, 0, 2, 25)], 'T20');
check($tail < $strikeBowler && $tail < $opener, 'a tail-ender costs less than a wicket-taker or a heavy-scoring opener');
check(fantasy_feed_credits('x', 'BAT', $tail, 'T20') >= 6 && fantasy_feed_credits('x', 'BAT', $opener, 'T20') <= 10.5, 'credits stay inside 6-10.5');

echo "== Pre-match price from rankings / standings ==\n";
$rk = [10 => ['T20I|men' => 269], 43 => ['T20I|men' => 234], 300 => ['T20I|women' => 291], 301 => ['T20I|women' => 180]];
$p = sm_prematch_prob(10, 43, 'T20', $rk, []);
check($p > 0.65 && $p < 0.75, sprintf('India (269) v West Indies (234): India %.0f%%', 100 * $p));
check(abs(sm_prematch_prob(10, 43, 'T20', $rk, []) + sm_prematch_prob(43, 10, 'T20', $rk, []) - 1) < 1e-9, 'symmetric: swapping the teams gives the complement');
check(sm_prematch_prob(300, 301, 'T20', $rk, []) <= 0.75, 'a huge gap is capped at 75% (T20 is volatile)');
check(sm_prematch_prob(10, 300, 'T20', $rk, []) === 0.5, 'a men\'s side and a women\'s side are never compared on rating (falls back to even)');
$st = [];
foreach (fx('standings-1849.json') as $r) $st[(int) $r['team_id']] = $r;
$ids = array_keys($st);
$p = sm_prematch_prob($ids[0], end($ids), 'T20', [], $st);
check($p !== null && $p > 0.5 && $p <= 0.65, sprintf('domestic: table-topper v bottom side from the real CSA standings %.0f%% (capped 65%%)', 100 * $p));
check(sm_prematch_prob(999998, 999999, 'T20', $rk, $st) === 0.5, 'no information at all: an even match, so the fixture can still be bet pre-match');

echo "\ntest_sportmonks: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
