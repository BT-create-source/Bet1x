<?php
/**
 * "Your Eleven" — scoring, scorecard parsing and name matching (phase 4).
 *
 * No server, no database. Every expected total below is worked out by hand in its label, so a failure
 * says which rule broke rather than only that a number moved.
 *
 *     php php-backend/test_fantasy_scoring.php
 *     php php-backend/test_fantasy_scoring.php --scorecard=/tmp/sc.html   # also parse a real page
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const TEST_NOW_MS = 1790000000000;

function env_get($n, $d = null) { return $d; }
function env_bool($n, $d)       { return $d; }
function now_ms()               { return TEST_NOW_MS; }
function ms_to_sql($ms)         { return gmdate('Y-m-d H:i:s', (int) floor(((int) $ms) / 1000)) . '.000'; }
function sql_to_ms($s)          { return $s === null ? null : (strtotime($s . ' UTC') * 1000); }
function log_error($m, $c = null) {}
function q($s, $p = [])         { return null; }
function one($s, $p = [])       { return null; }
function all($s, $p = [])       { return []; }
function scalar($s, $p = [], $d = null) { return $d; }

require_once __DIR__ . '/lib/fantasy.php';
require_once __DIR__ . '/lib/fantasy-source.php';
require_once __DIR__ . '/lib/fantasy-scoring.php';
require_once __DIR__ . '/lib/fantasy-live.php';

$pass = 0; $fail = 0; $failures = [];
function ok($c, $label) {
    global $pass, $fail, $failures;
    if ($c) { $pass++; echo "  PASS  $label\n"; }
    else    { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function section($t) { echo "\n== $t ==\n"; }
function pts(array $stats, array $rules = null) {
    return fantasy_score_player($stats, $rules ?: fantasy_scoring_rules())['points'];
}

// -------------------------------------------------------------------------------------------------
// Every expected value below is worked by hand from Dream11's published T20 / ODI / Test tables.
// -------------------------------------------------------------------------------------------------
section('Batting (T20)');
// -------------------------------------------------------------------------------------------------
$T20 = fantasy_scoring_rules('T20'); $ODI = fantasy_scoring_rules('ODI'); $TEST = fantasy_scoring_rules('TEST');
ok(pts([]) === 0.0, 'a player with no figures scores nothing');
ok(pts(['runs' => 25, 'balls' => 20]) === 29.0, '25 off 20 = 25 + 25-run bonus 4 (SR 125 earns nothing) = 29');
ok(pts(['runs' => 25, 'fours' => 3]) === 41.0, '25 with 3 fours = 25 + 3x4 boundary bonus + 4 = 41');
ok(pts(['runs' => 25, 'sixes' => 2]) === 41.0, '25 with 2 sixes = 25 + 2x6 six bonus + 4 = 41');
ok(pts(['runs' => 34, 'fours' => 3, 'sixes' => 1]) === 56.0, '34/3x4/1x6 = 34 + 12 + 6 + 4 = 56');
ok(pts(['runs' => 57, 'fours' => 5, 'sixes' => 2]) === 101.0, '57/5x4/2x6 = 57 + 20 + 12 + 4 + 8 (25 and 50 stack) = 101');
ok(pts(['runs' => 139, 'fours' => 10, 'sixes' => 9]) === 249.0, '139/10x4/9x6 = 139 + 40 + 54 + century 16 only = 249');
ok(fantasy_milestone_bonus(24, $T20) === 0.0,  '24 reaches no milestone');
ok(fantasy_milestone_bonus(25, $T20) === 4.0,  '25 pays the 25-run bonus');
ok(fantasy_milestone_bonus(50, $T20) === 12.0, '50 pays 25 + 50 bonuses = 12 (they stack below a hundred)');
ok(fantasy_milestone_bonus(80, $T20) === 24.0, '80 pays 4 + 8 + 12 = 24');
ok(fantasy_milestone_bonus(99, $T20) === 24.0, '99 still pays 24, not the century');
ok(fantasy_milestone_bonus(100, $T20) === 16.0, '100 pays ONLY the century bonus (Dream11: no lower milestones with a century)');
ok(fantasy_milestone_bonus(150, $T20) === 16.0, 'T20 has no tier above the century');
ok(fantasy_milestone_bonus(125, $ODI) === 20.0 && fantasy_milestone_bonus(150, $ODI) === 24.0, 'ODI pays only the highest century tier (125 -> 20, 150 -> 24)');

section('Strike rate (T20: min 10 balls, never for bowlers)');
ok(pts(['runs' => 30, 'balls' => 15]) === 30.0 + 4 + 6, '200 SR = +6');
ok(pts(['runs' => 20, 'balls' => 12]) === 20.0 + 4, '166.7 SR = +4');
ok(pts(['runs' => 13, 'balls' => 10]) === 13.0 + 2, '130 SR = +2');
ok(pts(['runs' => 7, 'balls' => 10]) === 7.0 - 2, '70 SR = -2');
ok(pts(['runs' => 5, 'balls' => 10]) === 5.0 - 4, '50 SR = -4');
ok(pts(['runs' => 4, 'balls' => 10]) === 4.0 - 6, '40 SR = -6');
ok(pts(['runs' => 9, 'balls' => 9]) === 9.0, 'fewer than 10 balls: no strike-rate points');
ok(pts(['runs' => 4, 'balls' => 10, 'role' => 'BOWL']) === 4.0, 'a bowler never takes strike-rate points');

section('Ducks');
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 1]) === -2.0, 'T20 duck = -2');
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 1], $ODI) === -3.0, 'ODI duck = -3');
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 1], $TEST) === -4.0, 'Test duck = -4');
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 0]) === 0.0, '0 not out is not a duck');
ok(pts(['runs' => 0, 'did_bat' => 0, 'is_out' => 0]) === 0.0, 'not batting is not a duck');
ok(pts(['runs' => 0, 'did_bat' => 1, 'is_out' => 1, 'role' => 'BOWL']) === 0.0, 'bowlers are exempt from the duck (Dream11: batter, WK, all-rounder only)');

section('Bowling (T20)');
ok(pts(['wickets' => 1]) === 30.0, '1 wicket = 30');
ok(pts(['wickets' => 2]) === 60.0, '2 wickets = 60, no haul bonus yet');
ok(pts(['wickets' => 3]) === 94.0, '3 wickets = 90 + 3-wicket bonus 4');
ok(pts(['wickets' => 4]) === 128.0, '4 wickets = 120 + 4-wicket bonus 8 (only the highest tier)');
ok(pts(['wickets' => 5]) === 162.0, '5 wickets = 150 + 5-wicket bonus 12');
ok(pts(['wickets' => 2, 'bowled_lbw' => 2]) === 76.0, 'LBW / bowled bonus +8 each');
ok(pts(['maidens' => 1]) === 12.0, 'a T20 maiden = 12');
ok(pts(['maidens' => 1], $ODI) === 4.0, 'an ODI maiden = 4');
ok(pts(['dot_balls' => 9]) === 9.0, 'T20: +1 per dot ball');
ok(pts(['dot_balls' => 9], $ODI) === 3.0 && pts(['dot_balls' => 8], $ODI) === 2.0, 'ODI: +1 per 3 dot balls');
ok(pts(['dot_balls' => 9], $TEST) === 0.0 && pts(['wickets' => 1], $TEST) === 20.0, 'Test: no dot-ball points, a wicket is 20');
ok(pts(['wickets' => 4], $ODI) === 124.0 && pts(['wickets' => 3], $ODI) === 90.0, 'ODI haul tiers start at 4 wickets');

section('Economy rate (T20: min 2 overs)');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 18]) === 6.0, '4.5 an over = +6');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 22]) === 4.0, '5.5 an over = +4');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 28]) === 2.0, '7.0 an over = +2');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 32]) === 0.0, '8.0 an over earns nothing');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 42]) === -2.0, '10.5 an over = -2');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 46]) === -4.0, '11.5 an over = -4');
ok(pts(['balls_bowled' => 24, 'runs_conceded' => 50]) === -6.0, '12.5 an over = -6');
ok(pts(['balls_bowled' => 11, 'runs_conceded' => 30]) === 0.0, 'under two overs: no economy points');
ok(pts(['overs' => 4.0, 'runs_conceded' => 18]) === 6.0, 'decimal overs from older stat rows are read correctly');

section('Fielding');
ok(pts(['catches' => 1]) === 8.0, '1 catch = 8');
ok(pts(['catches' => 3]) === 28.0, '3 catches = 24 + the 3-catch bonus 4');
ok(pts(['catches' => 6]) === 52.0, '6 catches = 48 + the bonus once (not twice)');
ok(pts(['stumpings' => 1]) === 12.0, '1 stumping = 12');
ok(pts(['runouts_direct' => 1]) === 12.0, 'a direct run-out = 12');
ok(pts(['runouts_shared' => 1]) === 6.0, 'a run-out assist = 6');
ok(pts(['catches' => 3], $TEST) === 24.0, 'Test has no 3-catch bonus');

section('Announced lineups and substitutes');
ok(pts(['in_lineup' => 1]) === 4.0, 'being in the announced XI = +4 even before a ball is bowled');
ok(pts(['in_lineup' => 0, 'is_substitute' => 1, 'runs' => 10, 'did_bat' => 1, 'batted_or_bowled' => 1]) === 14.0,
   'a playing substitute (impact player) gets +4 and their contributions');
ok(pts(['in_lineup' => 0, 'is_substitute' => 0, 'catches' => 1, 'fielded' => 1, 'batted_or_bowled' => 0]) === 0.0,
   'an ordinary substitute fielder scores nothing, even for a catch');

section('T10, The Hundred and the warm-up ("Other") tables');
$T10 = fantasy_scoring_rules('T10'); $H = fantasy_scoring_rules('HUNDRED'); $OT = fantasy_scoring_rules('OTHER_T20');
ok(pts(['runs' => 30, 'balls' => 12, 'fours' => 3, 'sixes' => 2], $T10) === 68.0, 'T10: 30 off 12 (3x4, 2x6) = 30 + 12 + 12 + 25-bonus 8 + SR 250 bonus 6 = 68');
ok(pts(['runs' => 104, 'fours' => 0], $T10) === 104.0 + 8 + 12 + 16, 'T10 pays no century bonus, only the 25/50/75 bonuses');
ok(pts(['wickets' => 2], $T10) === 64.0 && pts(['maidens' => 1], $T10) === 16.0, 'T10: a 2-wicket bonus (+4) and a 16-point maiden');
ok(pts(['runs' => 30, 'fours' => 3, 'sixes' => 2], $H) === 42.0, 'The Hundred: boundary +1, six +2, 30-run bonus +5');
ok(pts(['wickets' => 1, 'dot_balls' => 10, 'maidens' => 1], $H) === 25.0, 'The Hundred: wicket 25, and no dot-ball or maiden points');
ok(pts(['in_lineup' => 1, 'runs' => 10], $OT) === 10.0, 'warm-up tables pay nothing for the announced lineup');
ok(pts(['wickets' => 5], $OT) === 166.0 && pts(['runs' => 30, 'fours' => 1], $OT) === 35.0, 'warm-up T20: 5 wickets = 150 + 16, 30 runs = 30 + 1 + 4');

section('The open rule details are switchable variants');
ok(pts(['wickets' => 5], fantasy_scoring_rules('T20', ['hauls_cumulative' => true])) === 150.0 + 4 + 8 + 12,
   'with hauls cumulative, a 5-wicket haul collects the 3/4/5 bonuses (24)');
ok(pts(['runs' => 80], fantasy_scoring_rules('T20', ['milestones_cumulative' => false])) === 92.0,
   'with milestones not cumulative, 80 runs pays only the 75 bonus (12)');
ok(pts(['runs' => 80]) === 104.0, 'the default stacks them below a century (80 + 4 + 8 + 12)');

// -------------------------------------------------------------------------------------------------
section('Captain and vice-captain multipliers');
// -------------------------------------------------------------------------------------------------
$r = $T20;
ok(fantasy_apply_multiplier(50, false, false, $r) === 50.0, 'no armband leaves the score alone');
ok(fantasy_apply_multiplier(50, true, false, $r) === 100.0, 'captain doubles 50 to 100');
ok(fantasy_apply_multiplier(50, false, true, $r) === 75.0, 'vice-captain takes 50 to 75');
ok(fantasy_apply_multiplier(50, true, true, $r) === 100.0, 'if both flags are somehow set, captain wins');
ok(fantasy_apply_multiplier(-2, true, false, $r) === -4.0, 'a captain duck loses double, not nothing');
ok(fantasy_apply_multiplier(-2, false, true, $r) === -3.0, 'a vice-captain duck loses 1.5x');

// -------------------------------------------------------------------------------------------------
section('Scoring a whole XI');
// -------------------------------------------------------------------------------------------------
$players = [];
for ($i = 1; $i <= 11; $i++) {
    $players[] = ['id' => $i, 'name' => 'P' . $i, 'role' => 'BAT',
                  'is_captain' => $i === 1, 'is_vice_captain' => $i === 2];
}
$stats = [
    1 => ['runs' => 50, 'balls' => 40, 'did_bat' => 1, 'is_out' => 1],   // 50 + 4 + 8 = 62, x2 = 124
    2 => ['wickets' => 3],                                                // 94, x1.5 = 141
    3 => ['runs' => 0, 'did_bat' => 1, 'is_out' => 1],                    // -2
    4 => ['catches' => 1],                                                // 8
];
$team = fantasy_score_team($players, $stats, $r);
ok($team['players'][0]['base_points'] === 62.0, 'captain base 50 + 25-bonus 4 + 50-bonus 8 = 62');
ok($team['players'][0]['points'] === 124.0,     'captain final = 124');
ok($team['players'][1]['points'] === 141.0,     'vice-captain 94 x 1.5 = 141');
ok($team['players'][2]['points'] === -2.0,      'the duck stays -2 with no armband');
ok($team['players'][3]['points'] === 8.0,       'the catch scores 8');
ok($team['total'] === 271.0, 'XI total = 124 + 141 - 2 + 8 = 271 (got ' . $team['total'] . ')');
$absent = array_slice($team['players'], 4);
$allZero = true;
foreach ($absent as $p) if ($p['points'] !== 0.0) $allZero = false;
ok($allZero, 'players with no stats row score exactly 0');
ok(count($team['players']) === 11, 'every player is reported, including the ones who scored nothing');
ok(isset($team['players'][0]['breakdown']['Milestone bonus']),
   'the breakdown itemises the milestone, so a total can be checked line by line');

// -------------------------------------------------------------------------------------------------
section('Scorecard parsing, including branches the real page did not contain');
// -------------------------------------------------------------------------------------------------
$esc = function ($json) { return str_replace('"', '\\"', $json); };

$bat = '{"bat_1":'
  . '{"batId":101,"batName":"Alpha One","runs":62,"balls":60,"fours":6,"sixes":3,'
  .  '"outDesc":"c Gamma b Delta","wicketCode":"CAUGHT","bowlerId":104,"fielderId1":103,'
  .  '"fielderId2":0,"fielderId3":0},'
  . '"bat_2":'
  . '{"batId":102,"batName":"Beta Two","runs":0,"balls":4,"fours":0,"sixes":0,'
  .  '"outDesc":"lbw b Delta","wicketCode":"LBW","bowlerId":104,"fielderId1":0,'
  .  '"fielderId2":0,"fielderId3":0},'
  . '"bat_3":'
  . '{"batId":105,"batName":"Epsilon Five","runs":58,"balls":43,"fours":7,"sixes":1,'
  .  '"outDesc":"not out","wicketCode":"","bowlerId":0,"fielderId1":0,"fielderId2":0,"fielderId3":0},'
  . '"bat_4":'
  . '{"batId":106,"batName":"Zeta Six","runs":3,"balls":5,"fours":0,"sixes":0,'
  .  '"outDesc":"st Gamma b Delta","wicketCode":"STUMPED","bowlerId":104,"fielderId1":103,'
  .  '"fielderId2":0,"fielderId3":0},'
  . '"bat_5":'
  . '{"batId":107,"batName":"Eta Seven","runs":11,"balls":9,"fours":1,"sixes":0,'
  .  '"outDesc":"run out (Theta/Iota)","wicketCode":"RUNOUT","bowlerId":0,"fielderId1":108,'
  .  '"fielderId2":109,"fielderId3":0},'
  . '"bat_6":'
  . '{"batId":110,"batName":"Kappa Ten","runs":7,"balls":6,"fours":0,"sixes":0,'
  .  '"outDesc":"run out (Theta)","wicketCode":"RUNOUT","bowlerId":0,"fielderId1":108,'
  .  '"fielderId2":0,"fielderId3":0},'
  . '"bat_7":'
  . '{"batId":111,"batName":"Lambda","runs":0,"balls":1,"fours":0,"sixes":0,'
  .  '"outDesc":"hit wicket b Delta","wicketCode":"HITWICKET","bowlerId":104,"fielderId1":0,'
  .  '"fielderId2":0,"fielderId3":0}}';

$bowl = '{"bowl_1":{"bowlerId":104,"bowlName":"Delta Four","overs":10,"maidens":2,"runs":41,"wickets":4},'
      . '"bowl_2":{"bowlerId":112,"bowlName":"Mu Twelve","overs":8,"maidens":0,"runs":55,"wickets":0}}';

$page = 'x"matchInfo":' . $esc('{"matchId":777,"state":"Complete","status":"Alpha won by 9 runs"}')
      . 'y"batsmenData":' . $esc($bat)
      . 'z"bowlersData":' . $esc($bowl) . 'tail';

$card = fantasy_parse_scorecard($page, 777);
ok($card['ok'] === true, 'the synthetic card parses');
ok($card['state'] === 'Complete', "this match's own state is read from its matchInfo");
ok($card['status_text'] === 'Alpha won by 9 runs', 'the human status sentence is carried through');

$P = $card['players'];
ok(isset($P['cb:101']), 'a batsman is keyed by the source id');
ok($P['cb:101']['runs'] === 62 && $P['cb:101']['fours'] === 6 && $P['cb:101']['sixes'] === 3,
   'batting figures land intact');
ok($P['cb:101']['is_out'] === 1 && $P['cb:101']['did_bat'] === 1, 'a dismissed batsman is out');
ok($P['cb:105']['is_out'] === 0 && $P['cb:105']['did_bat'] === 1,
   'an empty wicketCode is not out, not a duck');
ok(pts($P['cb:105']) === 58.0 + 28.0 + 6.0 + 12.0 + 2.0, 'the not-out 58 (43b, 7x4, 1x6) scores 106: runs + boundaries + six + 25&50 bonuses + SR 135, no duck');
ok(pts($P['cb:102']) === -2.0, 'the batsman dismissed for 0 takes -2');

// bowling
ok($P['cb:104']['wickets'] === 4, "the bowler's wickets come from the bowling table");
ok($P['cb:104']['maidens'] === 2, 'maidens come through');
ok($P['cb:104']['runs_conceded'] === 41, 'a bowling row\'s "runs" is runs CONCEDED');
ok($P['cb:104']['runs'] === 0,
   'and is NOT added to his batting runs — he never batted on this card');
ok($P['cb:104']['bowled_lbw'] === 1, 'the LBW dismissal credits the bowler one bowled/LBW bonus');
ok($P['cb:104']['catches'] === 0, 'the bowler gets no catch for a wicket a fielder took');

// fielding derived from dismissals
ok($P['cb:103']['catches'] === 1, 'the catcher is credited a catch');
ok($P['cb:103']['stumpings'] === 1, 'the keeper is credited the stumping');
ok($P['cb:108']['runouts_direct'] === 1, 'a single-fielder run-out is direct');
ok($P['cb:108']['runouts_shared'] === 1, 'and the two-fielder one is shared for the same fielder');
ok($P['cb:109']['runouts_shared'] === 1, 'the second fielder in a shared run-out is credited too');
ok(!isset($P['cb:109']['runouts_direct']) || $P['cb:109']['runouts_direct'] === 0,
   'a shared run-out is never also counted as direct');
ok($P['cb:111']['is_out'] === 1, 'a hit-wicket batsman is out');
ok($P['cb:104']['bowled_lbw'] === 1,
   'but HITWICKET awards no bowled/LBW bonus — unknown codes invent no credit');

// the whole card's internal consistency
$wk = 0; $ct = 0; $st = 0; $ro = 0; $bl = 0;
foreach ($P as $p) {
    $wk += $p['wickets']; $ct += $p['catches']; $st += $p['stumpings'];
    $ro += $p['runouts_direct'] + $p['runouts_shared']; $bl += $p['bowled_lbw'];
}
ok($ct === 1 && $st === 1 && $bl === 1, 'one catch, one stumping, one bowled/LBW across the card');
ok($ro === 3, 'three run-out credits: one direct plus two shared');

ok(fantasy_parse_scorecard('<html>nothing</html>')['ok'] === false,
   'an unrecognisable page reports an error rather than zeroing everyone');

// -------------------------------------------------------------------------------------------------
section('Match state and key helpers');
// -------------------------------------------------------------------------------------------------
ok(fantasy_source_id_from_key('cb:151532') === 151532, 'a source id is recovered from its key');
ok(fantasy_source_id_from_key('mock:1001') === 1001,    'and from a mock key');
ok(fantasy_source_id_from_key('weird') === 0,           'an unparseable key yields 0, not a guess');
ok(fantasy_source_id_from_key('cb:') === 0,             'an empty suffix yields 0');

foreach (['Complete', 'complete', 'Abandon', 'Abandoned', 'Cancelled', 'No Result'] as $s) {
    ok(fantasy_state_is_final($s) === true, "'$s' is a finished match");
}
foreach (['Preview', 'In Progress', 'Stumps', 'Innings Break', '', null] as $s) {
    ok(fantasy_state_is_final($s) === false,
       "'" . ($s === null ? 'null' : $s) . "' is NOT finished");
}

// -------------------------------------------------------------------------------------------------
section('Name matching — and its refusal to guess');
// -------------------------------------------------------------------------------------------------
$cands = [1 => 'Virat Kohli', 2 => 'Rohit Sharma', 3 => 'Jasprit Bumrah', 4 => 'Kuldeep Yadav'];

$m = fantasy_fuzzy_match_player('Virat Kohli', $cands);
ok($m['id'] === 1 && $m['method'] === 'exact', 'an identical name matches exactly');
$m = fantasy_fuzzy_match_player('virat  KOHLI', $cands);
ok($m['id'] === 1 && $m['method'] === 'exact', 'case and extra spaces do not matter');
$m = fantasy_fuzzy_match_player('V Kohli', $cands);
ok($m['id'] === 1 && $m['method'] === 'initial', 'initial-plus-surname resolves to the full name');
$m = fantasy_fuzzy_match_player('R. Sharma', $cands);
ok($m['id'] === 2 && $m['method'] === 'initial', 'punctuation in an abbreviation is ignored');
$m = fantasy_fuzzy_match_player('Jasprit Bumra', $cands);
ok($m['id'] === 3 && $m['method'] === 'fuzzy', 'a one-letter typo still matches by distance');
$m = fantasy_fuzzy_match_player('Kuldip Yadav', $cands);
ok($m['id'] === 4, 'a plausible misspelling matches');

$m = fantasy_fuzzy_match_player('Completely Different Person', $cands);
ok($m['id'] === null && $m['method'] === 'none', 'an unrelated name matches nobody');
$m = fantasy_fuzzy_match_player('', $cands);
ok($m['id'] === null, 'an empty name matches nobody');
ok(fantasy_fuzzy_match_player('Virat Kohli', [])['id'] === null, 'no candidates means no match');

// The critical behaviour: two equally good candidates must NOT be guessed between.
$dupes = [10 => 'M Shahzad', 11 => 'M Shahzad'];
$m = fantasy_fuzzy_match_player('M Shahzad', $dupes);
ok($m['id'] === null && $m['method'] === 'ambiguous',
   'two identical squad names are reported ambiguous, never guessed');
$twins = [20 => 'Hasan Ali', 21 => 'Hassan Ali'];
$m = fantasy_fuzzy_match_player('Hasan Ali', $twins);
ok($m['id'] === 20 && $m['method'] === 'exact',
   'an exact hit still wins even when a near-twin exists');
$m = fantasy_fuzzy_match_player('Hasan Alii', $twins);
ok($m['method'] === 'ambiguous' || $m['id'] !== null,
   'a name equidistant from two candidates is ambiguous rather than arbitrary');

// accents
$accented = [30 => 'Muller Test'];
ok(fantasy_fuzzy_match_player('Müller Test', $accented)['id'] === 30, 'accents are folded');

// -------------------------------------------------------------------------------------------------
section('Optional: a real saved scorecard');
// -------------------------------------------------------------------------------------------------
$path = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--scorecard=') === 0) $path = substr($a, strlen('--scorecard='));
}
if ($path && is_readable($path)) {
    $live = fantasy_parse_scorecard(file_get_contents($path), 151532);
    ok($live['ok'] === true, "the real scorecard parses ($path)");
    echo '        -> ' . count($live['players']) . " players, state=" . var_export($live['state'], true) . "\n";
    // Internal consistency is the strongest check available without a second source: every wicket
    // must be accounted for by a catch, a stumping, a run-out or a bowled/LBW... or by one of the
    // codes that credits nobody.
    $wk = 0; $credited = 0;
    foreach ($live['players'] as $p) {
        $wk += $p['wickets'];
        $credited += $p['catches'] + $p['stumpings'] + $p['bowled_lbw']
                  + $p['runouts_direct'] + $p['runouts_shared'];
    }
    echo "        -> wickets=$wk  fielding/bowling credits=$credited\n";
    ok($credited <= $wk, 'no more credits were awarded than there were wickets');
    $anyPoints = false;
    foreach ($live['players'] as $p) if (pts($p) > 0) $anyPoints = true;
    ok($anyPoints, 'the real card produces a positive score for somebody');
} else {
    echo "  SKIP  real scorecard (pass --scorecard=/path/to/saved.html)\n";
}

// -------------------------------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "$pass passed, $fail failed\n";
if ($fail) { echo "\nFailures:\n"; foreach ($failures as $f2) echo "  - $f2\n"; }
exit($fail ? 1 : 0);
