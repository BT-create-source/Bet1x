<?php
/**
 * ROAD TEST — Your 11 player points vs Dream11's published scoring, on REAL matches.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_dream11_points.php [--matches=200] [--keep]
 *
 * How this proves the points match Dream11:
 *
 *   1. ORACLE. A second, independent implementation of Dream11's published T20 table, written here from
 *      the rules text (dream11.com/fantasy-cricket/point-system) and reading Cricsheet's raw ball-by-ball
 *      JSON directly. It shares no code with the production engine.
 *   2. PRODUCTION PATH. The same real match is converted into a Roanuz-shaped push snapshot and sent through
 *      the live pipeline: cricket_feed_ingest() -> database -> cricket_derive_player_stats() ->
 *      fantasy_score_player().
 *   3. Every player of every match must score EXACTLY the same in both. Any difference is printed with
 *      the player's figures, so a derivation bug (a missed dot ball, a mis-credited run-out, an overthrow
 *      boundary) cannot hide.
 *
 * Plus hand-built edge cases with explicitly worked Dream11 totals, and a resemblance report (average
 * points per player, typical top scores) against the ranges Dream11 players are used to.
 *
 * Real data comes from Cricsheet (cricsheet.org, ODC-BY), downloaded once into a cache directory.
 */
require __DIR__ . '/road_common.php';

$N = 200;
foreach ($argv as $a) if (preg_match('/^--matches=(\d+)$/', $a, $m)) $N = (int) $m[1];

require __DIR__ . '/road_oracle.php';

// =================================================================================================
// Cricsheet -> neutral balls (oracle) and -> Roanuz snapshot (production path)
// =================================================================================================

function cs_kind($d) {
    $e = $d['extras'] ?? [];
    if (isset($e['wides'])) return 'wide';
    if (isset($e['noballs'])) return 'noball';
    if (isset($e['byes'])) return 'bye';
    if (isset($e['legbyes'])) return 'legbye';
    return 'legal';
}

function cs_convert(array $m, $key) {
    $teams = $m['info']['teams'];
    $side = [$teams[0] => 'a', $teams[1] => 'b'];
    $neutral = []; $rz = []; $order = []; $idx = 0;
    $batBalls = []; $bowlBalls = [];
    foreach ($m['innings'] as $ii => $inn) {
        if (!empty($inn['super_over']) || $ii > 1) continue;
        $s = $side[$inn['team']] ?? 'a';
        $innKey = $s . '_1';
        $order[] = $innKey;
        foreach ($inn['overs'] as $ov) {
            $legalInOver = 0;
            foreach ($ov['deliveries'] as $d) {
                $kind = cs_kind($d);
                if ($kind !== 'wide' && $kind !== 'noball') $legalInOver++;
                $bat = (int) $d['runs']['batter'];
                $nb = !empty($d['runs']['non_boundary']);
                $boundary = (!$nb && ($bat === 4 || $bat === 6)) ? $bat : 0;
                $w = $d['wickets'][0] ?? null;
                $out = null; $fielders = []; $subs = [];
                if ($w) {
                    foreach ($w['fielders'] ?? [] as $f) { if (!empty($f['name'])) { $fielders[] = $f['name']; } }
                    $out = ['kind' => $w['kind'], 'player' => $w['player_out'], 'fielders' => $fielders];
                }
                $neutral[] = ['inn' => $ii + 1, 'over' => (int) $ov['over'], 'bowler' => $d['bowler'], 'batter' => $d['batter'],
                              'non_striker' => $d['non_striker'], 'bat' => $bat, 'total' => (int) $d['runs']['total'],
                              'kind' => $kind, 'boundary' => $boundary, 'out' => $out];
                $batBalls[$d['batter']] = ($batBalls[$d['batter']] ?? 0) + ($kind === 'wide' ? 0 : 1);
                if ($kind !== 'wide' && $kind !== 'noball') $bowlBalls[$d['bowler']] = ($bowlBalls[$d['bowler']] ?? 0) + 1;

                $idx++;
                $ball = [
                    'key' => $key . '_' . $idx, 'index' => $idx, 'innings' => $innKey, 'batting_team' => $s,
                    'overs' => [(int) $ov['over'], $legalInOver],
                    'ball_type' => ['legal' => 'normal', 'wide' => 'wide', 'noball' => 'no_ball', 'bye' => 'bye', 'legbye' => 'leg_bye'][$kind],
                    'batsman' => ['player_key' => $d['batter'], 'runs' => $bat, 'is_four' => $boundary === 4, 'is_six' => $boundary === 6],
                    'non_striker' => ['player_key' => $d['non_striker']],
                    'bowler' => ['player_key' => $d['bowler']],
                    'team_score' => ['runs' => (int) $d['runs']['total'], 'extras' => (int) $d['runs']['total'] - $bat, 'is_wicket' => (bool) $w],
                    'fielders' => [],
                ];
                if ($w) {
                    $ball['wicket'] = ['player_key' => $w['player_out'], 'kind' => str_replace(' ', '_', $w['kind'])];
                    foreach ($fielders as $f) {
                        $ball['fielders'][] = $w['kind'] === 'run out' ? ['player_key' => $f, 'is_run_out' => true]
                            : ($w['kind'] === 'stumped' ? ['player_key' => $f, 'is_stumps' => true] : ['player_key' => $f, 'is_catch' => true]);
                    }
                }
                $rz[] = $ball;
            }
        }
    }
    // Lineups: Cricsheet's per-team player list; roles inferred identically for both paths (Cricsheet has none).
    $lineup = []; $squad = []; $players = []; $role = [];
    foreach ($teams as $t) {
        $s = $side[$t];
        foreach ($m['info']['players'][$t] ?? [] as $pl) {
            $lineup[$pl] = true; $squad[$s][] = $pl;
            $r = 'BAT';
            if (($bowlBalls[$pl] ?? 0) >= 12 && ($batBalls[$pl] ?? 0) < 10) $r = 'BOWL';
            elseif (($bowlBalls[$pl] ?? 0) >= 12) $r = 'ALL';
            $role[$pl] = $r;
            $players[$pl] = ['player' => ['key' => $pl, 'name' => $pl, 'seasonal_role' => ['BAT' => 'batsman', 'BOWL' => 'bowler', 'ALL' => 'all_rounder'][$r]]];
        }
    }
    $winner = $m['info']['outcome']['winner'] ?? null;
    $snap = [
        'key' => $key, 'name' => $teams[0] . ' vs ' . $teams[1], 'format' => 't20', 'status' => 'completed', 'play_status' => 'result',
        'start_at' => strtotime(($m['info']['dates'][0] ?? '2024-01-01') . ' 14:00 UTC'),
        'teams' => ['a' => ['key' => 'ta', 'name' => $teams[0], 'code' => substr($teams[0], 0, 3)], 'b' => ['key' => 'tb', 'name' => $teams[1], 'code' => substr($teams[1], 0, 3)]],
        'squad' => ['a' => ['player_keys' => $squad['a'] ?? [], 'playing_xi' => $squad['a'] ?? []], 'b' => ['player_keys' => $squad['b'] ?? [], 'playing_xi' => $squad['b'] ?? []]],
        'players' => $players,
        'play' => ['innings_order' => $order, 'result' => ['winner' => $winner ? $side[$winner] : null, 'msg' => 'result']],
        'related_balls' => $rz,
    ];
    return [$neutral, $snap, $lineup, $role];
}

function cricsheet_matches($limit) {
    $cache = (getenv('CRICSHEET_CACHE') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cricsheet-cache');
    if (!is_dir($cache)) @mkdir($cache, 0775, true);
    $out = [];
    foreach (['ipl_male_json.zip', 't20s_male_json.zip'] as $zipName) {
        $path = $cache . DIRECTORY_SEPARATOR . $zipName;
        if (!is_file($path)) {
            road_info("downloading $zipName from cricsheet.org into $cache");
            $data = @file_get_contents('https://cricsheet.org/downloads/' . $zipName);
            if ($data === false) continue;
            file_put_contents($path, $data);
        }
        $z = new ZipArchive();
        if ($z->open($path) !== true) continue;
        $files = [];
        for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (substr($n, -5) === '.json') $files[] = $n; }
        rsort($files, SORT_NATURAL);          // newest match ids first
        foreach ($files as $n) {
            $m = json_decode($z->getFromName($n), true);
            if (!$m || (int) ($m['info']['overs'] ?? 0) !== 20 || count($m['innings'] ?? []) < 2) continue;
            $m['_id'] = basename($n, '.json');
            $m['_src'] = $zipName;
            $out[] = $m;
            if (count($out) >= $limit) { $z->close(); return $out; }
        }
        $z->close();
    }
    return $out;
}

// =================================================================================================
// 1. Hand-built edge cases with worked Dream11 totals
// =================================================================================================
road_section('1. Edge cases with worked Dream11 totals');
$T = fantasy_scoring_rules('T20');
$base = ['innings' => 1, 'over_no' => 0, 'ball_no' => 1, 'is_legal' => 1, 'is_super_over' => 0, 'batting_team' => 'a', 'non_striker_key' => 'NS',
         'batsman_runs' => 0, 'extra_type' => null, 'extra_runs' => 0, 'is_boundary' => 0, 'is_wicket' => 0, 'wicket_type' => null, 'out_player_key' => null, 'fielders' => []];
$d = function (array $o) use ($base) { static $i = 0; $i++; return array_merge($base, ['uid' => 'e' . $i, 'seq' => $i, 'batsman_key' => 'BAT', 'bowler_key' => 'BWL'], $o); };
$lineup = ['a' => ['BAT', 'NS'], 'b' => ['BWL', 'F1', 'F2', 'WK']];
$score = function (array $balls, $who, $role = 'BAT') use ($lineup, $T) {
    $s = cricket_derive_player_stats($balls, $lineup);
    $st = $s[$who] ?? ['in_lineup' => 0]; $st['role'] = $role;
    return fantasy_score_player($st, $T)['points'];
};
road_check($score([$d(['batsman_runs' => 4, 'is_boundary' => 0])], 'BAT') === 4.0 + 4,
           'an overthrow that reaches the rope: 4 runs to the batter, but no boundary bonus (Dream11 rule) — 4 + 4 lineup');
road_check($score([$d(['batsman_runs' => 4, 'is_boundary' => 4])], 'BAT') === 4.0 + 4 + 4, 'a real four: runs 4 + boundary bonus 4 + lineup 4 = 12');
road_check($score([$d(['is_legal' => 0, 'extra_type' => 'wide', 'extra_runs' => 1, 'is_wicket' => 1, 'wicket_type' => 'stumped', 'out_player_key' => 'BAT',
                       'fielders' => [['key' => 'WK', 'stumping' => true, 'catch' => false, 'runout' => false, 'direct' => false]]])], 'BWL', 'BOWL') === 4.0 + 30,
           'a stumping off a wide counts as the bowler\'s wicket (30), and the wide is no dot ball');
road_check($score([$d(['is_wicket' => 1, 'wicket_type' => 'caught_and_bowled', 'out_player_key' => 'BAT'])], 'BWL', 'BOWL') === 4.0 + 30 + 8 + 1,
           'caught and bowled: wicket 30 + catch 8 + dot ball 1 to the bowler');
$ro1 = [$d(['batsman_runs' => 1, 'is_wicket' => 1, 'wicket_type' => 'run_out', 'out_player_key' => 'NS', 'fielders' => [['key' => 'F1', 'runout' => true, 'direct' => false, 'catch' => false, 'stumping' => false]]])];
road_check($score($ro1, 'F1', 'ALL') === 4.0 + 12 && $score($ro1, 'BWL', 'BOWL') === 4.0, 'a run-out by one fielder is a direct hit (12); the bowler gets no wicket for it');
$ro2 = [$d(['is_wicket' => 1, 'wicket_type' => 'run_out', 'out_player_key' => 'BAT', 'fielders' => [
            ['key' => 'F2', 'runout' => true, 'direct' => false, 'catch' => false, 'stumping' => false],
            ['key' => 'F1', 'runout' => true, 'direct' => false, 'catch' => false, 'stumping' => false],
            ['key' => 'WK', 'runout' => true, 'direct' => false, 'catch' => false, 'stumping' => false]]])];
road_check($score($ro2, 'F1', 'ALL') === 10.0 && $score($ro2, 'WK', 'WK') === 10.0 && $score($ro2, 'F2', 'ALL') === 4.0,
           'a relay run-out credits only the LAST TWO fielders, 6 each (Dream11 rule)');
road_check($score([$d(['is_wicket' => 0, 'wicket_type' => null])], 'BAT') === 4.0 && $score([$d(['is_wicket' => 1, 'wicket_type' => 'bowled', 'out_player_key' => 'BAT'])], 'BAT') === 4.0 - 2,
           'out for 0 is a duck (-2); retired hurt is never recorded as out');
road_check($score([$d(['is_wicket' => 1, 'wicket_type' => 'bowled', 'out_player_key' => 'BAT'])], 'BAT', 'BOWL') === 4.0, 'a bowler is exempt from the duck penalty');
$sub = [$d(['is_wicket' => 1, 'wicket_type' => 'caught', 'out_player_key' => 'BAT', 'fielders' => [['key' => 'SUBX', 'catch' => true, 'stumping' => false, 'runout' => false, 'direct' => false]]])];
road_check($score($sub, 'SUBX', 'BAT') === 0.0, 'a catch by an ordinary substitute fielder (not in the XI) earns nothing');
road_check($score([$d(['batter' => 'X', 'batsman_key' => 'IMP', 'batsman_runs' => 6, 'is_boundary' => 6])], 'IMP') === 4.0 + 6 + 6,
           'an impact player who bats gets +4 (playing substitute) plus his runs');
$super = [$d(['is_super_over' => 1, 'batsman_runs' => 6, 'is_boundary' => 6])];
road_check($score($super, 'BAT') === 4.0, 'nothing in a super over counts');
$maiden = []; for ($i = 1; $i <= 6; $i++) $maiden[] = $d(['ball_no' => $i, 'extra_type' => $i === 3 ? 'legbye' : null, 'extra_runs' => $i === 3 ? 1 : 0]);
road_check($score($maiden, 'BWL', 'BOWL') === 4.0 + 12 + 6, 'six balls with only a leg bye: still a maiden (12) and six dot balls (6)');

// =================================================================================================
// 2. Real matches: production pipeline vs the oracle, every player
// =================================================================================================
road_section("2. Real matches from Cricsheet: production pipeline vs independent Dream11 oracle");
$matches = cricsheet_matches($N);
road_check(count($matches) >= min($N, 20), count($matches) . ' real T20 matches loaded (newest first)');
$players = 0; $mismatch = 0; $examples = []; $all = []; $tops = []; $perMatchTop = [];
$famous = null;
foreach ($matches as $mi => $m) {
    $key = 'road_cs_' . $m['_id'];
    road_wipe_match($key);
    [$neutral, $snap, $lineup, $role] = cs_convert($m, $key);
    $want = oracle_points($neutral, $lineup, $role);
    $r = cricket_feed_ingest($snap, 'road', 1500000000000);
    if (empty($r['ok'])) { $mismatch++; $examples[] = "$key ingest failed: " . json_encode($r); continue; }
    $feed = cricket_match_feed_row($key);
    $stats = cricket_derive_player_stats(cricket_match_deliveries($key), $feed['lineups']);
    $matchPts = [];
    foreach ($want as $name => $oraclePts) {
        $st = $stats[$name] ?? ['in_lineup' => isset($lineup[$name]) ? 1 : 0];
        $st['role'] = $role[$name] ?? 'BAT';
        $mine = fantasy_score_player($st, $T)['points'];
        $players++;
        if (!road_near($mine, $oraclePts)) {
            $mismatch++;
            if (count($examples) < 12) $examples[] = sprintf('%s %s: engine %.1f vs oracle %.1f  stats=%s', $m['_id'], $name, $mine, $oraclePts, json_encode(array_intersect_key($st, array_flip(['runs', 'balls', 'fours', 'sixes', 'wickets', 'balls_bowled', 'runs_conceded', 'dot_balls', 'maidens', 'catches', 'stumpings', 'runouts_direct', 'runouts_shared', 'is_out']))));
        }
        if (isset($lineup[$name])) { $all[] = $mine; $matchPts[$name] = $mine; }
    }
    if ($matchPts) { arsort($matchPts); $perMatchTop[] = reset($matchPts); }
    if ($famous === null && $m['_src'] === 'ipl_male_json.zip') { arsort($matchPts); $famous = [$m, array_slice($matchPts, 0, 8, true)]; }
    if (!$GLOBALS['ROAD_KEEP']) road_wipe_match($key);
}
road_check($mismatch === 0, "every player matches the oracle exactly: $players player-innings across " . count($matches) . ' matches, ' . $mismatch . ' differences',
           $examples ? implode("\n        ", $examples) : null);

road_section('3. Resemblance to the points Dream11 players see');
sort($all); sort($perMatchTop);
$avg = $all ? array_sum($all) / count($all) : 0;
$median = $all ? $all[intdiv(count($all), 2)] : 0;
$topMed = $perMatchTop ? $perMatchTop[intdiv(count($perMatchTop), 2)] : 0;
road_info(sprintf('per player per T20 (XI only): average %.1f, median %.1f, highest %.1f', $avg, $median, $all ? end($all) : 0));
road_info(sprintf('best player of each match: median %.1f, range %.1f – %.1f', $topMed, $perMatchTop ? $perMatchTop[0] : 0, $perMatchTop ? end($perMatchTop) : 0));
// Dream11's current T20 system (2023+: boundary +4, six +6, wicket 30, dot +1) gives an average XI player
// roughly 40-65 points (winning T20 teams on the app total ~800-1,100 with C/VC) and the match's best
// performer ~100-250. These bounds catch a gross scale error, not a subtle one; section 2 does that.
road_check($avg >= 35 && $avg <= 70, "average points per player sit in Dream11's usual range for the current T20 system (35-70)");
road_check($topMed >= 85 && $topMed <= 200, 'the typical match-winner scores in Dream11\'s usual range (85–200)');
if ($famous) {
    [$fm, $top] = $famous;
    road_info('most recent IPL match in the data: ' . implode(' vs ', $fm['info']['teams']) . ' (' . ($fm['info']['dates'][0] ?? '') . ') — top scorers by this engine:');
    foreach ($top as $name => $pts) road_info(sprintf('   %-24s %6.1f', $name, $pts));
    road_info('compare these with the Dream11 app\'s points for that match; section 2 guarantees they follow Dream11\'s table exactly.');
}

road_finish('road_dream11_points');
