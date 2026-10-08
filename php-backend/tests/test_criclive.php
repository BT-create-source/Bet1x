<?php
/**
 * CricLive connector — checked against REAL replies saved from cricketliveapi.com (tests/fixtures/criclive/,
 * Namibia vs USA, ICC CWC League Two, 7 Oct 2026, match 174067). No network, no database.
 *
 *     php php-backend/tests/test_criclive.php
 */
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-criclive'] as $f) require_once "$root/lib/$f.php";

$pass = 0; $fail = 0;
function check($c, $l, $d = null) { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; if ($d !== null) echo '        ' . json_encode($d) . "\n"; } }
function fx($name) { return json_decode(file_get_contents(__DIR__ . '/fixtures/criclive/' . $name), true); }

echo "== Fixtures from /schedule ==\n";
$fixtures = criclive_fixtures_from_schedule(fx('schedule.json')['data']);
check(count($fixtures) > 5, count($fixtures) . ' fixtures read from three days of the real schedule');
$f0 = $fixtures[0];
check(strpos($f0['key'], 'cl_') === 0 && $f0['start_ms'] > 1700000000000 && $f0['teams']['a']['key'] !== $f0['teams']['b']['key'],
      'each fixture has our key, a start time in ms and two distinct team keys', $f0);
check(in_array($f0['format'], ['T20', 'ODI', 'TEST', 'T10', 'HUNDRED'], true) && $f0['tournament_name'] !== '', 'format and tournament name come through (' . $f0['format'] . ', ' . $f0['tournament_name'] . ')');

echo "== Squads and the playing XI ==\n";
$teamIds = ['a' => 161, 'b' => 15];   // team1 Namibia, team2 USA, as the schedule/live list orders them
$sq = criclive_parse_squads(fx('squads-174067.json')['data'], $teamIds);
$xi = function ($s) use ($sq) { return array_values(array_filter($sq['sides'][$s], function ($p) { return $p['in_xi']; })); };
check(count($xi('a')) === 11 && count($xi('b')) === 11 && $sq['xi_announced'], 'both XIs have exactly 11 and count as announced');
$usa = []; foreach ($sq['sides']['b'] as $p) $usa[$p['name']] = $p;
check(isset($usa['Smit Patel']) && $usa['Smit Patel']['role'] === 'WK' && $usa['Smit Patel']['is_keeper'], 'Smit Patel: wicket-keeper role and keeper flag', $usa['Smit Patel'] ?? null);
check(isset($usa['Monank Patel']) && $usa['Monank Patel']['is_captain'], 'Monank Patel is the captain');
check(isset($usa['Milind Kumar']) && $usa['Milind Kumar']['role'] === 'ALL', 'Batting Allrounder maps to ALL');
check(isset($usa['Jasdeep Singh']) && !$usa['Jasdeep Singh']['in_xi'] && $usa['Jasdeep Singh']['role'] === 'BOWL', 'bench players are kept, marked not in the XI (Jasdeep Singh, Bowler)');
$roles = array_count_values(array_column(array_merge($xi('a'), $xi('b')), 'role'));
check(isset($roles['WK'], $roles['BAT'], $roles['ALL'], $roles['BOWL']), 'all four Your 11 roles appear across the two XIs ' . json_encode($roles));
$beforeToss = fx('squads-174067.json')['data'];
foreach ($beforeToss as &$t) { $t['bench'] = array_merge($t['playing_xi'], $t['bench']); $t['playing_xi'] = []; } unset($t);
check(!criclive_parse_squads($beforeToss, $teamIds)['xi_announced'], 'a squad with no XI yet (before the toss) is not treated as announced');

echo "== Matching names from dismissal text to players ==\n";
$nam = $sq['sides']['a']; $usaP = $sq['sides']['b'];
check(criclive_resolve_player('Ruben Trumpelmann', $nam) === 'clp_14431', 'full name resolves exactly');
check(criclive_resolve_player('Smit', $usaP) === criclive_player_key($usa['Smit Patel']['id']), '"Smit" (as in "c and b Smit") resolves to the only USA player of that first name');
check(criclive_resolve_player('Patel', $usaP) === null, '"Patel" is ambiguous (Smit and Monank) and resolves to nobody rather than a guess');
check(criclive_resolve_player('Nobody Here', $usaP) === null, 'an unknown name resolves to nobody');

echo "== Dismissals ==\n";
$d = criclive_parse_dismissal('c Smit Patel b Ehsan Adil', 'CAUGHT');
check($d['kind'] === 'caught' && $d['fielder'] === 'Smit Patel' && $d['bowler'] === 'Ehsan Adil', 'caught: fielder and bowler split out');
$d = criclive_parse_dismissal('c and b Smit', 'CAUGHTBOWLED');
check($d['kind'] === 'caught_and_bowled' && $d['fielder'] === 'Smit' && $d['bowler'] === 'Smit', 'caught and bowled: the bowler is also the catcher');
check(criclive_parse_dismissal('lbw b Ehsan Adil', 'LBW')['kind'] === 'lbw' && criclive_parse_dismissal('b Shehan Jayasuriya', 'BOWLED')['bowler'] === 'Shehan Jayasuriya', 'lbw and bowled carry the bowler');
check(criclive_parse_dismissal('not out', '') === null && criclive_parse_dismissal('batting', '') === null, 'not out / batting is not a dismissal');
$ro = criclive_parse_dismissal('run out (Zane Green/Gerhard Erasmus)', 'RUNOUT');
check($ro['kind'] === 'run_out' && $ro['fielder'] === 'Zane Green/Gerhard Erasmus', 'run out keeps the fielder list for the relay rule');
check(criclive_parse_extras('Extras: 6 (b 0, lb 3, w 3, nb 0, p 0)') === ['total' => 6, 'b' => 0, 'lb' => 3, 'w' => 3, 'nb' => 0, 'p' => 0], 'extras line parses');

echo "== Scorecard (the reconciliation source) ==\n";
$sc = criclive_parse_scorecard(fx('scorecard-174067.json')['data']['innings'], $sq['sides']);
$vl = $sc['batting'][1]['clp_11136'] ?? null;
check($vl && $vl['runs'] === 124 && $vl['balls'] === 121 && $vl['out'] && $vl['dismissal']['kind'] === 'bowled', 'Michael van Lingen 124 (121), bowled — keyed to his player id', $vl);
$unresolved = 0; foreach ([$sc['batting'], $sc['bowling']] as $grp) foreach ($grp as $inn) foreach ($inn as $k => $_) if ($k[0] === '?') $unresolved++;
check($unresolved === 0, 'every batter and bowler on the card maps to a squad player (' . $unresolved . ' unmatched)');
$cbFielders = 0; $cbMatched = 0;
foreach ($sc['batting'] as $iid => $inn) foreach ($inn as $row) if (!empty($row['dismissal']['fielder'])) {
    $cbFielders++;
    $fieldingSide = $iid === 1 ? $sq['sides']['b'] : $sq['sides']['a'];
    if (criclive_resolve_player($row['dismissal']['fielder'], $fieldingSide)) $cbMatched++;
}
check($cbFielders > 5 && $cbMatched === $cbFielders, "every catcher named on the card maps to a fielding player ($cbMatched of $cbFielders)");

echo "== The match snapshot the pipeline ingests ==\n";
$fixture = ['key' => 'cl_174067', 'name' => 'Namibia vs United States of America, 130th Match', 'short_name' => 'NAM vs USA', 'format' => 'ODI',
            'start_ms' => 1791385200000, 'teams' => ['a' => ['key' => 'clt_161', 'name' => 'Namibia', 'code' => 'NAM'], 'b' => ['key' => 'clt_15', 'name' => 'United States of America', 'code' => 'USA']]];
$snap = criclive_snapshot($fixture, $sq, fx('commentary-174067.json')['data']);
check($snap['status'] === 'completed' && $snap['toss']['winner'] === 'clt_161' && $snap['toss']['elected'] === 'bat', 'completed, Namibia won the toss and batted');
check($snap['play']['innings_order'] === ['a_1', 'b_1'], 'innings order: Namibia then USA', $snap['play']['innings_order']);
check(count($snap['squad']['a']['playing_xi']) === 11 && count($snap['squad']['b']['playing_xi']) === 11, 'the snapshot carries both XIs');
$parsed = cricket_parse_snapshot($snap);
check(($parsed['status'] ?? null) === 'completed' && count($parsed['lineups']['a'] ?? []) === 11 && isset($parsed['players']['clp_8350'])
      && $parsed['players']['clp_8350']['role'] === 'WK', 'the existing pipeline parser accepts it: status, both lineups, roles', array_intersect_key($parsed, array_flip(['status', 'toss'])));

echo "\ntest_criclive: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
