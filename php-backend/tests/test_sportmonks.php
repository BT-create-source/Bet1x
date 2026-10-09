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
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-sportmonks'] as $f) require_once "$root/lib/$f.php";

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

echo "== Snapshot flags ==\n";
$s = sm_snapshot(fx('fixture-71344-live.json'));
check(cricket_parse_snapshot($s)['balls_complete'] === true, 'a Sportmonks snapshot says it lists every ball (so withdrawn balls can be removed)');
check(($s['play']['target'] ?? null) === 183, 'second-innings target = first innings + 1 (183, as Sportmonks\' note says)', $s['play']['target'] ?? null);

echo "\ntest_sportmonks: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
