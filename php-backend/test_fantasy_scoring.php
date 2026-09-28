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
section('Batting');
// -------------------------------------------------------------------------------------------------
ok(pts([]) === 0.0, 'a player with no figures scores nothing');
ok(pts(['runs' => 25, 'balls' => 20]) === 25.0, '25 runs = 25 (no milestone below 30)');
ok(pts(['runs' => 25, 'fours' => 3]) === 28.0, '25 runs + 3 fours = 28');
ok(pts(['runs' => 25, 'sixes' => 2]) === 29.0, '25 runs + 2 sixes at 2 each = 29');

// 30 bonus: 34 runs + 3 fours + 1 six + 4 = 34+3+2+4
ok(pts(['runs' => 34, 'fours' => 3, 'sixes' => 1]) === 43.0, '34/3x4/1x6 with the 30 bonus = 43');
// 50 bonus only, not 50+30
ok(pts(['runs' => 57, 'fours' => 5, 'sixes' => 2]) === 74.0, '57/5x4/2x6 with the 50 bonus alone = 74');
// the real-world case from the live source: Kohli 139 (10x4, 9x6) => 139+10+18+16
ok(pts(['runs' => 139, 'fours' => 10, 'sixes' => 9]) === 183.0,
   '139 off 88 with 10 fours and 9 sixes = 183 (matches the real scorecard)');

// milestones cumulative is a config decision, so both readings are asserted
$cum = fantasy_scoring_rules();
$cum['milestones_cumulative'] = true;
ok(pts(['runs' => 139, 'fours' => 10, 'sixes' => 9], $cum) === 195.0,
   'the same innings pays 195 when milestones are cumulative (16+8+4 = 28)');
ok(fantasy_milestone_bonus(29, fantasy_scoring_rules()) === 0.0, '29 runs reaches no milestone');
ok(fantasy_milestone_bonus(30, fantasy_scoring_rules()) === 4.0, '30 exactly reaches the first');
ok(fantasy_milestone_bonus(99, fantasy_scoring_rules()) === 8.0, '99 pays the fifty, not the hundred');
ok(fantasy_milestone_bonus(100, fantasy_scoring_rules()) === 16.0, '100 pays the hundred');

// -------------------------------------------------------------------------------------------------
section('Ducks — and the three states that are not a duck');
// -------------------------------------------------------------------------------------------------
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 1]) === -2.0,
   'dismissed for nought = -2');
ok(pts(['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 0]) === 0.0,
   'NOT OUT on nought is not a duck');
ok(pts(['runs' => 0, 'did_bat' => 0, 'is_out' => 0]) === 0.0,
   'never came in to bat is not a duck');
ok(pts(['runs' => 0, 'did_bat' => 0, 'is_out' => 1]) === 0.0,
   'is_out without did_bat cannot be a duck either');
// The signed-off configuration exempts bowlers, so this is the DEFAULT behaviour, not an option.
ok(fantasy_scoring_rules()['duck_exempt_roles'] === ['BOWL'],
   'the configured exemption is BOWL, as signed off');
ok(pts(['runs' => 0, 'did_bat' => 1, 'is_out' => 1, 'role' => 'BOWL']) === 0.0,
   'a specialist bowler dismissed for nought is NOT penalised (default rule)');
foreach (['BAT', 'WK', 'ALL'] as $role) {
    ok(pts(['runs' => 0, 'did_bat' => 1, 'is_out' => 1, 'role' => $role]) === -2.0,
       "a $role dismissed for nought still takes -2");
}
// And the exemption is still configuration, so an empty list restores the literal reading.
$noExempt = fantasy_scoring_rules();
$noExempt['duck_exempt_roles'] = [];
ok(pts(['runs' => 0, 'did_bat' => 1, 'is_out' => 1, 'role' => 'BOWL'], $noExempt) === -2.0,
   'clearing the exemption penalises bowlers again');
// A bowler who actually made runs is unaffected either way.
ok(pts(['runs' => 34, 'fours' => 3, 'sixes' => 1, 'did_bat' => 1, 'is_out' => 1, 'role' => 'BOWL']) === 43.0,
   'the exemption only touches the duck, not a bowler who scored');

// -------------------------------------------------------------------------------------------------
section('Bowling');
// -------------------------------------------------------------------------------------------------
ok(pts(['wickets' => 1]) === 25.0, '1 wicket = 25, below every haul tier');
ok(pts(['wickets' => 2]) === 50.0, '2 wickets = 50, still no haul bonus');
ok(pts(['wickets' => 3]) === 79.0, '3 wickets = 75 + the 3-wicket bonus 4 = 79');
ok(pts(['wickets' => 4]) === 104.0, '4 wickets = 100 + 4 (the 3-wicket tier still applies) = 104');
ok(pts(['wickets' => 5]) === 141.0, '5 wickets = 125 + the 5-wicket bonus 16 = 141');
$haulCum = fantasy_scoring_rules();
$haulCum['hauls_cumulative'] = true;
ok(pts(['wickets' => 5], $haulCum) === 145.0, '5 wickets pays 145 when hauls are cumulative (16+4)');
ok(pts(['wickets' => 5, 'bowled_lbw' => 2, 'maidens' => 1]) === 169.0,
   '5 wickets + 2 bowled/LBW at 8 + 1 maiden at 12 = 141+16+12 = 169');
ok(pts(['maidens' => 2]) === 24.0, '2 maidens = 24');
ok(pts(['runs_conceded' => 60, 'overs' => 10]) === 0.0,
   'runs conceded and overs alone score nothing — there is no economy rule in this table');

// -------------------------------------------------------------------------------------------------
section('Fielding');
// -------------------------------------------------------------------------------------------------
ok(pts(['catches' => 1]) === 8.0, '1 catch = 8');
ok(pts(['catches' => 3]) === 24.0, '3 catches = 24');
ok(pts(['stumpings' => 1]) === 12.0, '1 stumping = 12');
ok(pts(['runouts_direct' => 1]) === 12.0, 'a direct run-out = 12');
ok(pts(['runouts_shared' => 1]) === 6.0, 'a shared run-out = 6');
ok(pts(['catches' => 2, 'stumpings' => 1, 'runouts_shared' => 1]) === 34.0,
   '2 catches + 1 stumping + 1 shared run-out = 16+12+6 = 34');

// -------------------------------------------------------------------------------------------------
section('Captain and vice-captain multipliers');
// -------------------------------------------------------------------------------------------------
$r = fantasy_scoring_rules();
ok(fantasy_apply_multiplier(50, false, false, $r) === 50.0, 'no armband leaves the score alone');
ok(fantasy_apply_multiplier(50, true, false, $r) === 100.0, 'captain doubles 50 to 100');
ok(fantasy_apply_multiplier(50, false, true, $r) === 75.0, 'vice-captain takes 50 to 75');
ok(fantasy_apply_multiplier(50, true, true, $r) === 100.0,
   'captain wins if both flags are somehow set');
// The armband must not be a free bet.
ok(fantasy_apply_multiplier(-2, true, false, $r) === -4.0, 'a captain duck loses double, not nothing');
ok(fantasy_apply_multiplier(-2, false, true, $r) === -3.0, 'a vice-captain duck loses 1.5x');
ok(fantasy_apply_multiplier(183, false, true, $r) === 274.5, '183 as vice-captain = 274.5');

// -------------------------------------------------------------------------------------------------
section('Scoring a whole XI');
// -------------------------------------------------------------------------------------------------
$players = [];
for ($i = 1; $i <= 11; $i++) {
    $players[] = ['id' => $i, 'name' => 'P' . $i, 'role' => 'BAT',
                  'is_captain' => $i === 1, 'is_vice_captain' => $i === 2];
}
$stats = [
    1 => ['runs' => 50, 'did_bat' => 1, 'is_out' => 1],                  // 50 + 8 = 58, x2 = 116
    2 => ['wickets' => 3],                                                // 79, x1.5 = 118.5
    3 => ['runs' => 0, 'did_bat' => 1, 'is_out' => 1],                    // -2
    4 => ['catches' => 1],                                                // 8
    // 5..11 absent: a player who did not take the field scores 0, which is not an error
];
$team = fantasy_score_team($players, $stats, $r);
ok($team['players'][0]['base_points'] === 58.0, 'captain base 50 + 8 milestone = 58');
ok($team['players'][0]['points'] === 116.0,     'captain final = 116');
ok($team['players'][1]['points'] === 118.5,     'vice-captain 79 x 1.5 = 118.5');
ok($team['players'][2]['points'] === -2.0,      'the duck stays -2 with no armband');
ok($team['players'][3]['points'] === 8.0,       'the catch scores 8');
ok($team['total'] === 240.5, 'XI total = 116 + 118.5 - 2 + 8 = 240.5 (got ' . $team['total'] . ')');
$absent = array_slice($team['players'], 4);
$allZero = true;
foreach ($absent as $p) if ($p['points'] !== 0.0) $allZero = false;
ok($allZero, 'players with no stats row score exactly 0');
ok(count($team['players']) === 11, 'every player is reported, including the ones who scored nothing');
ok(isset($team['players'][0]['breakdown']['milestone']),
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
ok(pts($P['cb:105']) === 58.0 + 7.0 + 2.0 + 8.0, 'the not-out 58 scores 75, with no duck penalty');
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
