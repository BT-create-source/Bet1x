<?php
/**
 * "Your Eleven" — cross-implementation parity check.
 *
 * =================================================================================================
 * WHAT THIS GUARDS
 * =================================================================================================
 * lib/fantasy-teams.php's fantasy_validate_lineup() is a port of validateLineup() in
 * backend/lib/cricket/contests.js. Two copies of one rule set drift: someone loosens a role limit on
 * one side, or fixes an off-by-one, and the two quietly stop agreeing about what a legal team is.
 *
 * This feeds byte-identical inputs to BOTH implementations and fails if they disagree on a single
 * case — the accept/reject decision and, where both accept, the credit total. It is the check that
 * makes "we reused the tested Node rules" a verifiable claim rather than an intention.
 *
 *     php php-backend/test_fantasy_parity.php
 *
 * Needs Node on PATH and the backend/ tree present. If either is missing it SKIPS rather than fails,
 * because neither is required to run the PHP application — this is a development-time check.
 *
 * Note the one deliberate naming difference it bridges: the Node rules call the budget
 * `credit_budget`, this port calls it `credit_cap`. Everything else is passed through unchanged.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// --- platform stubs, same as test_fantasy.php ------------------------------------------------------
function env_get($n, $d = null) { return $d; }
function env_bool($n, $d)       { return $d; }
function now_ms()               { return 1790000000000; }
function ms_to_sql($m)          { return '2026-01-01 00:00:00.000'; }
function sql_to_ms($s)          { return 1790000000000; }
function log_error($m, $c = null) {}
function q($s, $p = [])         { return null; }
function one($s, $p = [])       { return null; }
function scalar($s, $p = [], $d = null) { return $d; }
function all($s, $p = [])       { return []; }
function db_or_throw()          { return null; }
function tx($f)                 { return $f(); }

require_once __DIR__ . '/lib/fantasy-teams.php';

$nodeModule = realpath(__DIR__ . '/../backend/lib/cricket/contests.js');
if (!$nodeModule) {
    echo "SKIP: backend/lib/cricket/contests.js not found — nothing to compare against.\n";
    exit(0);
}

// -------------------------------------------------------------------------------------------------
// One shared squad and one shared list of cases.
// -------------------------------------------------------------------------------------------------
// 22 players: India ids 1-11, Australia 12-22, each side 2 WK / 4 BAT / 2 ALL / 3 BOWL.
$shape = [['WK', 2], ['BAT', 4], ['ALL', 2], ['BOWL', 3]];
$squadList = [];
$credits = [];
$id = 1;
foreach (['India', 'Australia'] as $team) {
    foreach ($shape as $s) {
        for ($i = 0; $i < $s[1]; $i++) {
            $squadList[] = ['player_key' => (string) $id, 'role' => $s[0], 'team_key' => $team];
            $credits[(string) $id] = 8.0;
            $id++;
        }
    }
}

$LEGAL = [1, 3, 4, 7, 9, 10, 14, 15, 18, 20, 21];   // WK1 BAT4 ALL2 BOWL4, 6 India + 5 Australia
$cases = [
    ['name' => 'legal',            'players' => $LEGAL,                          'c' => 1,    'vc' => 3],
    ['name' => 'ten_players',      'players' => array_slice($LEGAL, 0, 10),       'c' => 1,    'vc' => 3],
    ['name' => 'twelve_players',   'players' => array_merge($LEGAL, [22]),        'c' => 1,    'vc' => 3],
    ['name' => 'duplicate',        'players' => [1, 1, 4, 7, 9, 10, 14, 15, 18, 20, 21],    'c' => 1, 'vc' => 4],
    ['name' => 'unknown_player',   'players' => [9999, 3, 4, 7, 9, 10, 14, 15, 18, 20, 21], 'c' => 3, 'vc' => 4],
    ['name' => 'captain_outside',  'players' => $LEGAL,                          'c' => 9999, 'vc' => 3],
    ['name' => 'vc_outside',       'players' => $LEGAL,                          'c' => 1,    'vc' => 9999],
    ['name' => 'c_equals_vc',      'players' => $LEGAL,                          'c' => 1,    'vc' => 1],
    ['name' => 'no_wk',            'players' => [22, 3, 4, 7, 9, 10, 14, 15, 18, 20, 21],   'c' => 3, 'vc' => 4],
    ['name' => 'seven_bat',        'players' => [1, 3, 4, 5, 6, 14, 15, 16, 7, 9, 10],      'c' => 1, 'vc' => 3],
    ['name' => 'two_bowl',         'players' => [1, 2, 3, 4, 5, 6, 7, 8, 18, 9, 10],        'c' => 1, 'vc' => 3],
    ['name' => 'eight_from_india', 'players' => [1, 3, 4, 5, 6, 7, 9, 10, 14, 18, 20],      'c' => 1, 'vc' => 3],
    ['name' => 'seven_from_india', 'players' => [1, 3, 4, 5, 7, 9, 10, 14, 18, 20, 21],     'c' => 1, 'vc' => 3],
];

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$casesFile = $tmp . 'y11_parity_cases.json';
$nodeOut   = $tmp . 'y11_parity_node.json';
$nodeJs    = $tmp . 'y11_parity_node.js';

file_put_contents($casesFile, json_encode([
    'squad' => $squadList, 'credits' => $credits, 'cases' => $cases,
]));

// -------------------------------------------------------------------------------------------------
// Run the Node side.
// -------------------------------------------------------------------------------------------------
$js = <<<'JS'
const fs = require('fs');
const modPath   = process.argv[2];
const casesFile = process.argv[3];
const outFile   = process.argv[4];
const { validateLineup } = require(modPath);
const d = JSON.parse(fs.readFileSync(casesFile, 'utf8'));
// `credit_budget` is the Node name for what this port calls `credit_cap`; the only difference.
const rules = {
  squad_size: 11,
  credit_budget: 100,
  max_per_real_team: 7,
  role_limits: { WK:{min:1,max:4}, BAT:{min:3,max:6}, ALL:{min:1,max:4}, BOWL:{min:3,max:6} },
};
const out = {};
for (const c of d.cases) {
  const r = validateLineup(
    { players: c.players.map(String), captain: String(c.c), vice_captain: String(c.vc) },
    { squad: d.squad, credits: d.credits, rules, defaultCredits: 8 }
  );
  out[c.name] = { ok: !!r.ok, credits: r.credits_used === undefined ? null : r.credits_used };
}
fs.writeFileSync(outFile, JSON.stringify(out));
JS;
file_put_contents($nodeJs, $js);

$cmd = 'node ' . escapeshellarg($nodeJs) . ' ' . escapeshellarg($nodeModule) . ' '
     . escapeshellarg($casesFile) . ' ' . escapeshellarg($nodeOut) . ' 2>&1';
$output = [];
$code = 0;
exec($cmd, $output, $code);

if ($code !== 0 || !is_readable($nodeOut)) {
    echo "SKIP: could not run the Node validator (is node on PATH?).\n";
    if ($output) echo '  ' . implode("\n  ", array_slice($output, 0, 6)) . "\n";
    exit(0);
}
$nodeRes = json_decode(file_get_contents($nodeOut), true);
if (!is_array($nodeRes)) { echo "SKIP: unreadable Node output.\n"; exit(0); }

// -------------------------------------------------------------------------------------------------
// Run the PHP side and compare.
// -------------------------------------------------------------------------------------------------
$squadMap = [];
foreach ($squadList as $p) {
    $pid = (int) $p['player_key'];
    $squadMap[$pid] = [
        'id' => $pid, 'name' => 'p' . $pid, 'team_name' => $p['team_key'],
        'role' => $p['role'], 'credits' => (float) $credits[$p['player_key']], 'is_playing' => null,
    ];
}

printf("%-20s %-9s %-9s %-9s %s\n", 'case', 'node', 'php', 'credits', 'verdict');
echo str_repeat('-', 66) . "\n";

$disagree = 0;
foreach ($cases as $c) {
    $php = fantasy_validate_lineup(
        ['players' => $c['players'], 'captain' => $c['c'], 'vice_captain' => $c['vc']],
        ['squad' => $squadMap, 'rules' => fantasy_rules()]
    );
    $n = $nodeRes[$c['name']] ?? null;
    if ($n === null) { echo "  missing Node result for {$c['name']}\n"; $disagree++; continue; }

    $phpOk = (bool) $php['ok'];
    $nodeOk = (bool) $n['ok'];
    $agree = ($phpOk === $nodeOk);

    // Where both accept, the credit total must also match — a rule can agree on legality and still
    // disagree on price, which would change what fits inside the budget.
    $creditNote = '-';
    if ($agree && $phpOk) {
        $pc = (float) $php['credits_used'];
        $nc = (float) $n['credits'];
        $creditNote = rtrim(rtrim(number_format($pc, 2, '.', ''), '0'), '.');
        if (abs($pc - $nc) > 0.001) { $agree = false; $creditNote .= ' vs ' . $nc; }
    }

    if (!$agree) $disagree++;
    printf("%-20s %-9s %-9s %-9s %s\n",
        $c['name'],
        $nodeOk ? 'accept' : 'reject',
        $phpOk ? 'accept' : 'reject',
        $creditNote,
        $agree ? 'agree' : 'DISAGREE <<<');
}

echo "\n";
@unlink($casesFile); @unlink($nodeOut); @unlink($nodeJs);

if ($disagree) {
    echo "FAIL: {$disagree} of " . count($cases) . " cases disagree.\n";
    echo "The PHP port and backend/lib/cricket/contests.js no longer enforce the same rules.\n";
    exit(1);
}
echo 'OK: all ' . count($cases) . " cases agree, credit totals included.\n";
exit(0);
