<?php
/**
 * "Your Eleven" — Phase 1 test suite (ingestion + lobby reads).
 *
 * Needs neither a server nor a database. The handful of database helpers the module calls are
 * stubbed with an in-memory table, the same way backend/test_cricket.js drives the Node modules
 * against an in-memory store, so this runs in well under a second and can be run on any checkout.
 *
 *     php php-backend/test_fantasy.php
 *
 * Optionally, point it at real saved copies of the source pages to prove the parsers still work
 * against the live markup rather than only against the sample payload compiled in here:
 *
 *     php php-backend/test_fantasy.php --fixtures=/tmp/cb.html --squad=/tmp/sq.html
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

// -------------------------------------------------------------------------------------------------
// Stubs for the platform helpers the module expects to already be loaded.
// -------------------------------------------------------------------------------------------------

const TEST_NOW_MS = 1790000000000;   // a fixed clock, so countdown maths is deterministic

function env_get($name, $default = null) { return getenv($name) !== false ? getenv($name) : $default; }
function env_bool($name, $default)       { return $default; }
function now_ms()                        { return TEST_NOW_MS; }
function ms_to_sql($ms) {
    $ms = (int) $ms;
    return gmdate('Y-m-d H:i:s', (int) floor($ms / 1000)) . '.' . sprintf('%03d', $ms % 1000);
}
function sql_to_ms($s) {
    if ($s === null || $s === '') return null;
    $whole = $s; $frac = 0;
    if (strpos($s, '.') !== false) { [$whole, $f] = explode('.', $s, 2); $frac = (int) substr($f . '000', 0, 3); }
    $t = strtotime($whole . ' UTC');
    return $t === false ? null : ($t * 1000 + $frac);
}
function log_error($m, $c = null) { /* silent in tests */ }

// In-memory "fantasy_players" rows, so the grouping and usability checks can be exercised.
$GLOBALS['TEST_PLAYERS'] = [];
function q($sql, $params = [])                  { return null; }
function one($sql, $params = [])                { return null; }
function scalar($sql, $params = [], $def = null) { return $def; }
function all($sql, $params = []) {
    // The only read this suite needs is the squad query in fantasy_players_grouped().
    if (strpos($sql, '"fantasy_players"') !== false) {
        $rows = $GLOBALS['TEST_PLAYERS'];
        usort($rows, function ($a, $b) {
            if ((float) $a['credits'] === (float) $b['credits']) return strcmp($a['name'], $b['name']);
            return ((float) $a['credits'] < (float) $b['credits']) ? 1 : -1;
        });
        return $rows;
    }
    return [];
}

require_once __DIR__ . '/lib/fantasy.php';
require_once __DIR__ . '/lib/fantasy-source.php';
require_once __DIR__ . '/lib/fantasy-teams.php';

// -------------------------------------------------------------------------------------------------

$pass = 0; $fail = 0; $failures = [];
function ok($cond, $label) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

// -------------------------------------------------------------------------------------------------
section('Rules match the Node build (backend/lib/cricket/contests.js)');
// -------------------------------------------------------------------------------------------------
$r = fantasy_rules();
ok($r['squad_size'] === 11,            'squad size is 11');
ok($r['credit_cap'] === 100.0,         'credit cap is 100');
ok($r['max_per_real_team'] === 7,      'at most 7 from one real team');
ok($r['role_limits']['WK']   === ['min' => 1, 'max' => 4], 'WK 1-4');
ok($r['role_limits']['BAT']  === ['min' => 3, 'max' => 6], 'BAT 3-6');
ok($r['role_limits']['ALL']  === ['min' => 1, 'max' => 4], 'ALL 1-4');
ok($r['role_limits']['BOWL'] === ['min' => 3, 'max' => 6], 'BOWL 3-6');
ok($r['captain_multiplier'] === 2.0,      'captain 2x');
ok($r['vice_captain_multiplier'] === 1.5, 'vice-captain 1.5x');
// The minimums must be satisfiable at all — if they summed above 11 no legal team could exist.
$minSum = 0; foreach ($r['role_limits'] as $l) $minSum += $l['min'];
ok($minSum <= $r['squad_size'], "role minimums ($minSum) fit inside the squad size");
$maxSum = 0; foreach ($r['role_limits'] as $l) $maxSum += $l['max'];
ok($maxSum >= $r['squad_size'], "role maximums ($maxSum) can reach the squad size");

// -------------------------------------------------------------------------------------------------
section('Role normalisation (real role strings from the source)');
// -------------------------------------------------------------------------------------------------
$roleCases = [
    'WK-Batter'          => 'WK',
    'WK-Batsman'         => 'WK',
    'Wicketkeeper Batter' => 'WK',
    'Batter'             => 'BAT',
    'Batsman'            => 'BAT',
    'Bowler'             => 'BOWL',
    'Bowling Allrounder' => 'ALL',
    'Batting Allrounder' => 'ALL',
    'All-Rounder'        => 'ALL',
    ''                   => 'BAT',
    'Mystery Spinner'    => 'BAT',   // unknown -> least advantaged role
];
foreach ($roleCases as $in => $want) {
    $got = fantasy_normalise_role($in);
    ok($got === $want, "role '" . ($in === '' ? '(empty)' : $in) . "' -> $want" . ($got === $want ? '' : " (got $got)"));
}

// -------------------------------------------------------------------------------------------------
section('Credits stay inside the column CHECK constraint (8.0 - 10.5)');
// -------------------------------------------------------------------------------------------------
foreach ([-5, 0, 7.4, 8.0, 9.25, 10.5, 99] as $v) {
    $c = fantasy_clamp_credits($v);
    ok($c >= 8.0 && $c <= 10.5, "clamp($v) = $c is within 8.0-10.5");
}
ok(fantasy_clamp_credits(9.24) === 9.2, 'clamp rounds to one decimal place (9.24 -> 9.2)');
$capt = fantasy_provisional_credits(['role' => 'Batter', 'captain' => true]);
$plain = fantasy_provisional_credits(['role' => 'Batter', 'captain' => false]);
ok($capt > $plain, "a real captain prices above a plain batter ($capt > $plain)");
ok(fantasy_provisional_credits(['role' => 'Bowling Allrounder']) > $plain, 'all-rounders price above plain batters');
foreach ([['role' => 'WK', 'keeper' => true, 'captain' => true], ['role' => 'Bowler', 'substitute' => true]] as $p) {
    $c = fantasy_provisional_credits($p);
    ok($c >= 8.0 && $c <= 10.5, "provisional credits stay in band (got $c)");
}

// -------------------------------------------------------------------------------------------------
section('Embedded-JSON extraction');
// -------------------------------------------------------------------------------------------------
$blob = '{"a":1,"matchInfo":{"matchId":7,"team1":{"teamName":"A {weird}"},"nested":{"x":{"y":2}}},"z":3}'
      . 'junk"matchInfo":{"matchId":8}tail';
$objs = fantasy_extract_json_objects($blob, 'matchInfo');
ok(count($objs) === 2, 'finds both matchInfo objects');
ok(($objs[0]['matchId'] ?? null) === 7, 'first object decodes');
ok(($objs[0]['team1']['teamName'] ?? '') === 'A {weird}', 'a brace inside a string does not break depth counting');
ok(($objs[0]['nested']['x']['y'] ?? null) === 2, 'nested objects are captured whole');
ok(($objs[1]['matchId'] ?? null) === 8, 'second object decodes');
// A near-miss key must not match.
ok(count(fantasy_extract_json_objects('"matchInfoExtra":{"matchId":9}', 'matchInfo')) === 0,
   '"matchInfoExtra" is not mistaken for "matchInfo"');
ok(count(fantasy_extract_json_objects('no json here', 'matchInfo')) === 0, 'absent key yields nothing');

// -------------------------------------------------------------------------------------------------
section('Fixture parsing (sample payload in the real escaped shape)');
// -------------------------------------------------------------------------------------------------
$hour = 3600 * 1000;
$future = TEST_NOW_MS + (6 * $hour);
$past   = TEST_NOW_MS - (2 * $hour);
// Backslash-escaped exactly as the source page embeds it, so fantasy_unescape_payload() is exercised.
$mk = function ($id, $start, $state, $timeAnnounced = true) {
    $j = '{"matchId":' . $id . ',"seriesName":"Test Series 2026","matchDesc":"1st T20I",'
       . '"matchFormat":"T20","startDate":' . $start . ',"state":"' . $state . '",'
       . '"isTimeAnnounced":' . ($timeAnnounced ? 'true' : 'false') . ','
       . '"team1":{"teamName":"India","teamSName":"IND","imageId":12},'
       . '"team2":{"teamName":"Australia","teamSName":"AUS","imageId":34},'
       . '"venueInfo":{"ground":"Test Ground","city":"Testville"}}';
    return '"matchInfo":' . str_replace('"', '\\"', $j);
};
$payload = 'x' . $mk(9001, $future, 'Preview')
         . 'y' . $mk(9002, $past,   'Preview')          // already started
         . 'z' . $mk(9003, $future, 'Complete')         // finished
         . 'w' . $mk(9004, $future, 'Preview', false);  // no announced time

$parsed = fantasy_parse_fixtures($payload);
ok($parsed['ok'] === true, 'parse succeeds');
$keys = array_column($parsed['fixtures'], 'external_key');
ok($keys === ['cb:9001'], 'only the future, time-announced, unfinished fixture survives (got ' . implode(',', $keys) . ')');

$f = $parsed['fixtures'][0];
ok($f['series_name'] === 'Test Series 2026',    'series name');
ok($f['team_a'] === 'India' && $f['team_b'] === 'Australia', 'team names');
ok($f['team_a_short'] === 'IND' && $f['team_b_short'] === 'AUS', 'team short codes');
ok($f['format'] === 'T20',                      'format');
ok($f['venue'] === 'Test Ground, Testville',    'venue combines ground and city');
ok($f['start_time_ms'] === $future,             'start time is the source epoch, unmodified');
ok($f['lock_time_ms'] === $f['start_time_ms'],  'lock time defaults to the start time');
ok(strpos((string) $f['team_a_logo'], '/c12/') !== false, 'logo URL is built from the image id');
ok(strpos($f['match_title'], 'India vs Australia') === 0, 'title leads with the two teams');
ok($f['source_match_id'] === 9001,              'source id is carried through for the squad fetch');

// A shape change must fail loudly rather than produce a plausible row.
$broken = fantasy_parse_fixtures('<html>totally different markup</html>');
ok($broken['ok'] === false && $broken['fixtures'] === [], 'unrecognisable page reports an error, invents nothing');

// -------------------------------------------------------------------------------------------------
section('Squad parsing');
// -------------------------------------------------------------------------------------------------
$squadJson = '{"Squad":['
  . '{"id":1,"name":"Sanju Samson","fullName":"Sanju Samson","role":"WK-Batter","keeper":true,"captain":false,"teamName":"IND"},'
  . '{"id":2,"name":"Shreyas Iyer","fullName":"Shreyas Iyer","role":"Batter","keeper":false,"captain":true,"teamName":"IND"},'
  . '{"id":3,"name":"A Bowler","fullName":"A Bowler","role":"Bowler","keeper":false,"captain":false,"teamName":"AUS"},'
  . '{"id":4,"name":"An Allrounder","fullName":"An Allrounder","role":"Bowling Allrounder","keeper":false,"captain":false,"teamName":"AUS"},'
  . '{"id":1,"name":"Sanju Samson","fullName":"Sanju Samson","role":"WK-Batter","keeper":true,"captain":false,"teamName":"IND"}'
  . ']}';
$sq = fantasy_parse_squad('junk"players":' . str_replace('"', '\\"', $squadJson) . 'tail');
ok($sq['ok'] === true, 'squad parse succeeds');
ok(count($sq['players']) === 4, 'the duplicated player is ingested once (got ' . count($sq['players']) . ')');
$byKey = [];
foreach ($sq['players'] as $p) $byKey[$p['external_key']] = $p;
ok($byKey['cb:1']['role'] === 'WK',   'keeper flag wins: "WK-Batter" -> WK');
ok($byKey['cb:2']['role'] === 'BAT',  '"Batter" -> BAT');
ok($byKey['cb:3']['role'] === 'BOWL', '"Bowler" -> BOWL');
ok($byKey['cb:4']['role'] === 'ALL',  '"Bowling Allrounder" -> ALL');
ok($byKey['cb:2']['credits'] > $byKey['cb:3']['credits'], 'the captain prices above a plain bowler');
$allInBand = true;
foreach ($sq['players'] as $p) if ($p['credits'] < 8.0 || $p['credits'] > 10.5) $allInBand = false;
ok($allInBand, 'every parsed credit is inside the CHECK constraint');
ok(fantasy_parse_squad('<html>nope</html>')['ok'] === false, 'unrecognisable squad page reports an error');

// -------------------------------------------------------------------------------------------------
section('Support staff must never become selectable players');
// -------------------------------------------------------------------------------------------------
// The real source ships a "support staff" bucket next to "Squad" in the same object. Ingesting it
// let a head coach appear in the team builder, where picking him would burn a slot on someone who
// cannot score. Both guards are tested independently.
foreach (['Head Coach', 'Batting Coach', 'Bowling Coach', 'Team Manager', 'Physio',
          'Performance Analyst', 'Support Staff', 'Fielding Coach'] as $title) {
    ok(fantasy_is_support_role($title) === true, "'$title' is recognised as support staff");
}
foreach (['WK-Batter', 'Batter', 'Bowler', 'Bowling Allrounder', ''] as $title) {
    ok(fantasy_is_support_role($title) === false, "'" . ($title === '' ? '(empty)' : $title) . "' is a playing role");
}

$mixed = '{"Squad":['
  . '{"id":11,"name":"Real Player","fullName":"Real Player","role":"Batter","keeper":false,"teamName":"IND"},'
  . '{"id":12,"name":"Sneaky Coach","fullName":"Sneaky Coach","role":"Fielding Coach","keeper":false,"teamName":"IND"}'
  . '],"support staff":['
  . '{"id":13,"name":"VVS Laxman","fullName":"VVS Laxman","role":"Head Coach","keeper":false,"teamName":"IND"},'
  . '{"id":14,"name":"Hrishikesh Kanitkar","fullName":"Hrishikesh Kanitkar","role":"Batting Coach","keeper":false,"teamName":"IND"}'
  . ']}';
$mixedRes = fantasy_parse_squad('q"players":' . str_replace('"', '\\"', $mixed) . 'z');
$names = array_column($mixedRes['players'], 'name');
ok($names === ['Real Player'], 'only the real player survives (got ' . implode(', ', $names) . ')');
ok(!in_array('VVS Laxman', $names, true),          'the "support staff" bucket is skipped entirely');
ok(!in_array('Sneaky Coach', $names, true),        'a coach hidden inside the Squad bucket is still rejected');

// An unknown bucket name is skipped rather than trusted.
$oddBucket = '{"Mystery Group":[{"id":21,"name":"Who Knows","fullName":"Who Knows","role":"Batter","teamName":"IND"}]}';
$oddRes = fantasy_parse_squad('q"players":' . str_replace('"', '\\"', $oddBucket) . 'z');
ok(count($oddRes['players']) === 0, 'an unrecognised bucket is skipped, so a match stays hidden rather than wrong');

// -------------------------------------------------------------------------------------------------
section('Mock source is usable for end-to-end development');
// -------------------------------------------------------------------------------------------------
$mockFx = fantasy_mock_fixtures();
ok($mockFx['ok'] && count($mockFx['fixtures']) >= 2, 'mock returns at least two fixtures');
$allFuture = true;
foreach ($mockFx['fixtures'] as $m) if ($m['start_time_ms'] <= TEST_NOW_MS) $allFuture = false;
ok($allFuture, 'every mock fixture starts in the future');
$sameShape = true;
foreach (array_keys($f) as $k) {
    foreach ($mockFx['fixtures'] as $m) if (!array_key_exists($k, $m)) $sameShape = false;
}
ok($sameShape, 'mock fixtures carry exactly the same keys as parsed live ones');

// Feed a mock squad through the grouping + usability check.
$mockSquad = fantasy_mock_squad(1001);
ok(count($mockSquad['players']) === 22, 'mock squad is 22 players (got ' . count($mockSquad['players']) . ')');
$GLOBALS['TEST_PLAYERS'] = [];
$i = 1;
foreach ($mockSquad['players'] as $p) {
    $GLOBALS['TEST_PLAYERS'][] = [
        'id' => $i++, 'name' => $p['name'], 'full_name' => $p['full_name'],
        'team_name' => $p['team_name'], 'role' => $p['role'],
        'credits' => $p['credits'], 'is_playing' => null,
    ];
}
$grouped = fantasy_players_grouped(1);
ok(array_keys($grouped) === ['WK', 'BAT', 'ALL', 'BOWL'], 'grouped in team-builder tab order');
$counted = 0; foreach ($grouped as $g) $counted += count($g);
ok($counted === 22, 'grouping loses nobody');
ok(count($grouped['WK']) === 4 && count($grouped['BOWL']) === 6, 'roles land in the right buckets');
$desc = true;
foreach ($grouped as $g) for ($k = 1; $k < count($g); $k++) if ($g[$k - 1]['credits'] < $g[$k]['credits']) $desc = false;
ok($desc, 'each role group is ordered by credits, dearest first');
ok(fantasy_squad_is_usable(1) === true, 'a legal XI can be built from the mock squad');

// A squad missing its keepers must be rejected, not published.
$GLOBALS['TEST_PLAYERS'] = array_values(array_filter($GLOBALS['TEST_PLAYERS'], function ($p) {
    return $p['role'] !== 'WK';
}));
ok(fantasy_squad_is_usable(1) === false, 'a squad with no wicket-keeper is NOT marked playable');

// -------------------------------------------------------------------------------------------------
section('Lobby shaping and the lock deadline');
// -------------------------------------------------------------------------------------------------
$row = [
    'id' => 5, 'series_name' => 'IPL 2026', 'match_title' => 'A vs B',
    'team_a' => 'A', 'team_b' => 'B', 'team_a_short' => 'AA', 'team_b_short' => 'BB',
    'team_a_logo' => null, 'team_b_logo' => null, 'format' => 'T20', 'venue' => 'V',
    'start_time' => ms_to_sql(TEST_NOW_MS + (2 * $hour)),
    'lock_time'  => ms_to_sql(TEST_NOW_MS + (2 * $hour)),
    'status' => 'UPCOMING', 'squads_ready' => 1,
];
$pub = fantasy_public_match($row);
ok($pub['seconds_to_start'] === 7200, 'seconds_to_start is exact (got ' . $pub['seconds_to_start'] . ')');
ok($pub['is_locked'] === false,       'a future match is not locked');
ok($pub['squads_ready'] === true,     'squads_ready comes back as a boolean');

$row['lock_time'] = ms_to_sql(TEST_NOW_MS - 1000);
$pastPub = fantasy_public_match($row);
ok($pastPub['is_locked'] === true,          'a passed lock time is locked');
ok($pastPub['seconds_to_lock'] < 0,         'seconds_to_lock goes negative rather than clamping');

// -------------------------------------------------------------------------------------------------
section('Lineup validation (phase 2) — ported from contests.js validateLineup()');
// -------------------------------------------------------------------------------------------------

/**
 * Build a squad map of 22 players, 11 per side, with a controllable price so budget cases can be
 * set up exactly. Ids are 1..22; India is 1..11, Australia is 12..22.
 */
function mkSquad(callable $price = null) {
    $shape = [['WK', 2], ['BAT', 4], ['ALL', 2], ['BOWL', 3]];
    $map = [];
    $id = 1;
    foreach (['India', 'Australia'] as $team) {
        foreach ($shape as $s) {
            for ($i = 0; $i < $s[1]; $i++) {
                $map[$id] = [
                    'id' => $id, 'name' => $team . ' ' . $s[0] . ($i + 1), 'team_name' => $team,
                    'role' => $s[0],
                    'credits' => $price ? (float) $price($id, $s[0], $team) : 8.0,
                    'is_playing' => null,
                ];
                $id++;
            }
        }
    }
    return $map;
}
// India ids by role: WK 1-2, BAT 3-6, ALL 7-8, BOWL 9-11
// Aus   ids by role: WK 12-13, BAT 14-17, ALL 18-19, BOWL 20-22
// A legal XI: 6 from India + 5 from Australia -> WK1 BAT4 ALL2 BOWL4
$LEGAL = [1, 3, 4, 7, 9, 10,   14, 15, 18, 20, 21];
function lineup($players, $c = null, $vc = null) {
    return ['players' => $players,
            'captain' => $c === null ? $players[0] : $c,
            'vice_captain' => $vc === null ? $players[1] : $vc];
}
function vres($lineup, $squad = null) {
    return fantasy_validate_lineup($lineup, ['squad' => $squad ?: mkSquad(), 'rules' => fantasy_rules()]);
}

$good = vres(lineup($LEGAL));
ok($good['ok'] === true, 'a legal XI validates' . ($good['ok'] ? '' : ' — ' . $good['error']));
ok(abs($good['credits_used'] - 88.0) < 0.001, 'credits total 11 x 8.0 = 88 (got ' . $good['credits_used'] . ')');
ok($good['role_counts'] === ['WK' => 1, 'BAT' => 4, 'ALL' => 2, 'BOWL' => 4], 'role counts reported');
ok($good['team_counts'] === ['India' => 6, 'Australia' => 5], 'per-team counts reported');

// --- squad size ---
$short = vres(lineup(array_slice($LEGAL, 0, 10)));
ok($short['ok'] === false && strpos($short['error'], 'exactly 11') !== false,
   '10 players rejected: ' . $short['error']);
$long = vres(lineup(array_merge($LEGAL, [22])));
ok($long['ok'] === false && strpos($long['error'], 'exactly 11') !== false,
   '12 players rejected: ' . $long['error']);

// --- duplicates and unknown players ---
$dup = $LEGAL; $dup[10] = $dup[0];
$dupRes = vres(lineup($dup));
ok($dupRes['ok'] === false && stripos($dupRes['error'], 'twice') !== false,
   'the same player twice is rejected: ' . $dupRes['error']);
$ghost = $LEGAL; $ghost[10] = 9999;
$ghostRes = vres(lineup($ghost));
ok($ghostRes['ok'] === false && stripos($ghostRes['error'], 'squad') !== false,
   'a player outside the squad is rejected: ' . $ghostRes['error']);
// Ids are the ONLY thing taken from the request, so a forged id must not slip through.
$negative = $LEGAL; $negative[10] = -5;
ok(vres(lineup($negative))['ok'] === false, 'a negative player id is rejected');

// --- captain / vice-captain ---
$cOut = vres(lineup($LEGAL, 9999));
ok($cOut['ok'] === false && stripos($cOut['error'], 'captain') !== false,
   'a captain outside the XI is rejected: ' . $cOut['error']);
$cNone = vres(lineup($LEGAL, 0));
ok($cNone['ok'] === false && stripos($cNone['error'], 'captain') !== false, 'a missing captain is rejected');
$vcOut = vres(lineup($LEGAL, 1, 9999));
ok($vcOut['ok'] === false && stripos($vcOut['error'], 'vice-captain') !== false,
   'a vice-captain outside the XI is rejected: ' . $vcOut['error']);
$vcNone = vres(lineup($LEGAL, 1, 0));
ok($vcNone['ok'] === false && stripos($vcNone['error'], 'vice-captain') !== false, 'a missing vice-captain is rejected');
$same = vres(lineup($LEGAL, 1, 1));
ok($same['ok'] === false && stripos($same['error'], 'different') !== false,
   'captain and vice-captain must differ: ' . $same['error']);
// The pair may be any two of the eleven, in either order.
ok(vres(lineup($LEGAL, 21, 3))['ok'] === true, 'any two distinct members can be C and VC');

// --- role composition ---
// No wicket-keeper: swap the WK for another bowler (Aus BOWL 22).
$noWk = $LEGAL; $noWk[0] = 22;
$noWkRes = vres(lineup($noWk, 3, 4));
ok($noWkRes['ok'] === false && strpos($noWkRes['error'], 'at least 1 WK') !== false,
   'zero WK rejected: ' . $noWkRes['error']);
// Seven batsmen (max 6): WK1 + BAT 3,4,5,6,14,15,16 + ALL 7 + BOWL 9,10  -> BAT 7
$manyBat = [1, 3, 4, 5, 6, 14, 15, 16, 7, 9, 10];
$manyBatRes = vres(lineup($manyBat, 1, 3));
ok($manyBatRes['ok'] === false && strpos($manyBatRes['error'], 'at most 6 BAT') !== false,
   'seven BAT rejected: ' . $manyBatRes['error']);
// Only two bowlers (min 3): WK1,2 + BAT 3,4,5,6 + ALL 7,8,18 + BOWL 9,10
$fewBowl = [1, 2, 3, 4, 5, 6, 7, 8, 18, 9, 10];
$fewBowlRes = vres(lineup($fewBowl, 1, 3));
ok($fewBowlRes['ok'] === false && strpos($fewBowlRes['error'], 'at least 3 BOWL') !== false,
   'two BOWL rejected: ' . $fewBowlRes['error']);
// Five WK (max 4) is impossible with only 4 keepers in the squad, so check the max via ALL instead:
// five all-rounders needs 5 ALL but only 4 exist — use BAT max, already covered above.

// --- max players from one real team ---
// Eight from India: WK1, BAT 3,4,5,6, ALL 7, BOWL 9,10 = 8 India + 3 Aus
$stacked = [1, 3, 4, 5, 6, 7, 9, 10, 14, 18, 20];
$stackedRes = vres(lineup($stacked, 1, 3));
ok($stackedRes['ok'] === false && stripos($stackedRes['error'], 'at most 7 players from one team') !== false,
   'eight from one team rejected: ' . $stackedRes['error']);
// Exactly seven is allowed: 7 India + 4 Aus, WK1 BAT4 ALL2 BOWL4
$sevens = [1, 3, 4, 5, 7, 9, 10,   14, 18, 20, 21];
$sevensRes = vres(lineup($sevens, 1, 3));
ok($sevensRes['ok'] === true, 'exactly seven from one team is allowed' . ($sevensRes['ok'] ? '' : ' — ' . $sevensRes['error']));

// --- credit budget ---
$dear = mkSquad(function () { return 10.0; });           // 11 x 10.0 = 110
$dearRes = vres(lineup($LEGAL), $dear);
ok($dearRes['ok'] === false && stripos($dearRes['error'], 'over budget') !== false,
   'over budget rejected: ' . $dearRes['error']);
// Exactly 100.0: ten at 9.1 plus one at 9.0 = 100.0. Proves the epsilon, because summing 9.1
// eleven times in floating point does not land on a round number.
$exact = mkSquad(function ($id) { return $id === 1 ? 9.0 : 9.1; });
$exactRes = vres(lineup($LEGAL), $exact);
ok($exactRes['ok'] === true, 'a team costing exactly the cap is allowed (float epsilon)'
   . ($exactRes['ok'] ? ' — used ' . $exactRes['credits_used'] : ' — ' . $exactRes['error']));
// A hair over must still fail.
$over = mkSquad(function ($id) { return $id === 1 ? 9.2 : 9.1; });
ok(vres(lineup($LEGAL), $over)['ok'] === false, 'a team a tenth over the cap is rejected');

// --- the "unpriced is not free" rule ---
// A zero/missing credit must fall back to the mid-band, never to free: otherwise a botched ingest
// becomes a way to field an otherwise-unaffordable XI.
$unpriced = mkSquad(function ($id) { return $id <= 11 ? 0.0 : 10.0; });
$unpricedRes = vres(lineup($LEGAL), $unpriced);
$expected = (6 * 8.0) + (5 * 10.0);   // 6 India unpriced -> 8.0 each, 5 Aus at 10.0
ok(abs($unpricedRes['credits_used'] - $expected) < 0.001,
   "unpriced players cost the 8.0 default, not 0 (got {$unpricedRes['credits_used']}, want $expected)");

// -------------------------------------------------------------------------------------------------
section('Lock-time enforcement');
// -------------------------------------------------------------------------------------------------
$openMatch   = ['status' => 'UPCOMING', 'lock_time' => ms_to_sql(TEST_NOW_MS + 60000)];
$closedMatch = ['status' => 'UPCOMING', 'lock_time' => ms_to_sql(TEST_NOW_MS - 1)];
ok(fantasy_match_open_for_entry($openMatch)['ok'] === true, 'a match before its deadline is open');
$closed = fantasy_match_open_for_entry($closedMatch);
ok($closed['ok'] === false && stripos($closed['error'], 'closed') !== false,
   'one second past the deadline is closed: ' . $closed['error']);
// The boundary itself is closed, not open — >= not >.
$exactLock = ['status' => 'UPCOMING', 'lock_time' => ms_to_sql(TEST_NOW_MS)];
ok(fantasy_match_open_for_entry($exactLock)['ok'] === false, 'the deadline instant itself is closed');
foreach (['LIVE', 'SETTLED', 'CANCELLED'] as $st) {
    $r2 = fantasy_match_open_for_entry(['status' => $st, 'lock_time' => ms_to_sql(TEST_NOW_MS + 60000)]);
    ok($r2['ok'] === false, "status $st refuses entry even before the deadline");
}
ok(fantasy_match_open_for_entry(['status' => 'UPCOMING', 'lock_time' => null])['ok'] === false,
   'a match with no deadline set refuses entry rather than defaulting to open');

// -------------------------------------------------------------------------------------------------
section('API contract the lobby page depends on');
// -------------------------------------------------------------------------------------------------
// youreleven.html reads exactly these fields. Asserting them here means a rename in
// fantasy_public_match() fails this suite instead of silently emptying a card on the live page.
$needed = ['id', 'series_name', 'team_a', 'team_b', 'team_a_short', 'team_b_short',
           'team_a_logo', 'team_b_logo', 'format', 'venue', 'start_time', 'lock_time',
           'status', 'squads_ready', 'seconds_to_start', 'seconds_to_lock', 'is_locked'];
foreach ($needed as $k) {
    ok(array_key_exists($k, $pub), "lobby field '$k' is present");
}
ok(is_int($pub['seconds_to_lock']), 'seconds_to_lock is a number the countdown can do maths on');
ok(is_bool($pub['is_locked']),      'is_locked is a boolean');

// The squad payload the Squad tab renders.
$GLOBALS['TEST_PLAYERS'] = [[
    'id' => 3, 'name' => 'A Player', 'full_name' => 'A Player Full', 'team_name' => 'India',
    'role' => 'WK', 'credits' => 9.5, 'is_playing' => null,
]];
$g2 = fantasy_players_grouped(1);
foreach (['id', 'name', 'full_name', 'team_name', 'role', 'credits', 'is_playing'] as $k) {
    ok(array_key_exists($k, $g2['WK'][0]), "squad field '$k' is present");
}
ok($g2['WK'][0]['is_playing'] === null, 'is_playing stays null when no XI is announced (not false)');
ok(is_float($g2['WK'][0]['credits']), 'credits is a float, so toFixed(1) renders correctly');

// -------------------------------------------------------------------------------------------------
section('Optional: the parsers against real saved pages');
// -------------------------------------------------------------------------------------------------
$argsList = array_slice($argv, 1);
$pathFor = function ($flag) use ($argsList) {
    foreach ($argsList as $a) if (strpos($a, "--$flag=") === 0) return substr($a, strlen("--$flag="));
    return null;
};
$fxPath = $pathFor('fixtures');
$sqPath = $pathFor('squad');

if ($fxPath && is_readable($fxPath)) {
    $live = fantasy_parse_fixtures(file_get_contents($fxPath));
    ok($live['ok'] === true, "real fixtures page parses ($fxPath)");
    echo "        -> " . count($live['fixtures']) . " joinable fixtures found\n";
    $sane = true;
    foreach ($live['fixtures'] as $m) {
        if ($m['team_a'] === '' || $m['team_b'] === '' || $m['start_time_ms'] <= 0) $sane = false;
    }
    ok($sane, 'every fixture from the real page has both teams and a start time');
} else {
    echo "  SKIP  real fixtures page (pass --fixtures=/path/to/saved.html)\n";
}
if ($sqPath && is_readable($sqPath)) {
    $live = fantasy_parse_squad(file_get_contents($sqPath));
    ok($live['ok'] === true, "real squad page parses ($sqPath)");
    echo "        -> " . count($live['players']) . " players found\n";
    $roles = [];
    foreach ($live['players'] as $p) $roles[$p['role']] = ($roles[$p['role']] ?? 0) + 1;
    ksort($roles);
    echo "        -> roles: " . json_encode($roles) . "\n";
    $band = true;
    foreach ($live['players'] as $p) if ($p['credits'] < 8.0 || $p['credits'] > 10.5) $band = false;
    ok($band, 'every credit derived from the real squad is inside the CHECK constraint');
} else {
    echo "  SKIP  real squad page (pass --squad=/path/to/saved.html)\n";
}

// -------------------------------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "$pass passed, $fail failed\n";
if ($fail) { echo "\nFailures:\n"; foreach ($failures as $f2) echo "  - $f2\n"; }
exit($fail ? 1 : 0);
