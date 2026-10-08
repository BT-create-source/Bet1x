<?php
/**
 * CricLive (cricketliveapi.com) connector — the candidate low-cost live feed.
 *
 * Endpoints (relative to https://cricketliveapi.com/api/v1/cricket, Bearer token), as confirmed against
 * real replies on 2026-10-08:
 *
 *   GET /live                        matches in or near play
 *   GET /schedule                    the calendar: days -> series -> matches
 *   GET /match/{id}/squads           playing XI + bench per team, with ids, roles, captain/keeper
 *   GET /match/{id}/scorecard        per-innings batting/bowling cards, dismissal text + code
 *   GET /match/{id}/commentary       miniscore (live state) + the last ~20 commentary lines
 *
 * Everything here turns those replies into the shapes the rest of the pipeline already speaks — the
 * same normalised fixtures as roanuz_normalise_fixture() and the same Roanuz-shaped match snapshot that
 * cricket_feed_ingest() takes — so Your 11, Ball by Ball and match betting need no changes.
 *
 * NOT here yet, on purpose: reading individual deliveries out of the commentary text. That waits for
 * the live-match test (tools/criclive-live-sample.php), which shows how wides, no-balls, byes and
 * run-outs are actually worded while play is on. Until then a snapshot carries no balls.
 *
 * The data is Cricbuzz's underneath (identical match ids); treat it as unofficial.
 */

require_once __DIR__ . '/cricket-feed.php';

function criclive_conf() {
    return [
        'token' => trim((string) env_get('CRICLIVE_API_TOKEN', '')),
        'base'  => rtrim((string) env_get('CRICLIVE_BASE_URL', 'https://cricketliveapi.com/api/v1/cricket'), '/'),
        // The plan's daily allowance (free 100, Starter 1,000, Pro 5,000). Calls stop short of it so a
        // match in progress is never the one that runs out.
        'daily_limit' => max(1, (int) env_get('CRICLIVE_DAILY_LIMIT', 100)),
        'reserve'     => max(0, (int) env_get('CRICLIVE_DAILY_RESERVE', 10)),
    ];
}

/** Calls made today (UTC), from the shared state store so every PHP process counts against one budget. */
function criclive_calls_today() {
    $s = state_get('criclive_calls_' . gmdate('Ymd'));
    return is_array($s) ? (int) ($s['n'] ?? 0) : 0;
}

/**
 * One GET. Returns ['ok', 'status', 'data', 'error']; never throws.
 * Tests replace the network with $GLOBALS['CRICLIVE_FAKE'] = function ($path) { return [status, body]; }.
 */
function criclive_get($path) {
    $c = criclive_conf();
    $path = '/' . ltrim($path, '/');
    if (isset($GLOBALS['CRICLIVE_FAKE']) && is_callable($GLOBALS['CRICLIVE_FAKE'])) {
        [$status, $body] = ($GLOBALS['CRICLIVE_FAKE'])($path);
        $data = is_array($body) ? $body : json_decode((string) $body, true);
        $ok = $status >= 200 && $status < 300 && is_array($data) && ($data['success'] ?? true) !== false;
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $ok ? null : 'HTTP ' . $status];
    }
    if ($c['token'] === '') return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'CRICLIVE_API_TOKEN is not set'];
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl extension unavailable'];
    $used = criclive_calls_today();
    if ($used >= $c['daily_limit'] - $c['reserve']) {
        return ['ok' => false, 'status' => 429, 'data' => null, 'error' => "daily call budget reached ($used of {$c['daily_limit']})"];
    }
    $ch = curl_init($c['base'] . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $c['token']]]);
    $ca = (string) env_get('CA_BUNDLE', '');
    if ($ca !== '' && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    state_set('criclive_calls_' . gmdate('Ymd'), ['n' => $used + 1]);
    if ($raw === false) return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $err ?: 'request failed'];
    $data = json_decode((string) $raw, true);
    $ok = $status >= 200 && $status < 300 && is_array($data) && ($data['success'] ?? true) !== false;
    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $ok ? null : 'HTTP ' . $status . ' ' . substr((string) $raw, 0, 160)];
}

function criclive_match_key($id) { return 'cl_' . (int) $id; }
function criclive_match_id($key) { return (int) preg_replace('/^cl_/', '', (string) $key); }
function criclive_player_key($id) { return 'clp_' . (int) $id; }
function criclive_team_key($id) { return 'clt_' . (int) $id; }

/** CricLive/Cricbuzz match state -> our status vocabulary. */
function criclive_status($state, $statusText = '') {
    $s = strtolower(trim((string) $state));
    $t = strtolower((string) $statusText);
    foreach (['abandon', 'no result', 'cancel', 'called off'] as $n) if (strpos($s, $n) !== false || strpos($t, $n) !== false) return 'abandoned';
    if ($s === 'complete' || $s === 'completed' || $s === 'result') return 'completed';
    if ($s === 'innings break') return 'innings_break';
    if (in_array($s, ['in progress', 'live', 'stumps', 'rain', 'drink', 'drinks', 'lunch', 'tea', 'dinner', 'wet outfield', 'bad light'], true)) return 'live';
    return 'not_started';   // Preview, Upcoming, Toss
}

function criclive_format($f) {
    $f = strtoupper((string) $f);
    if (strpos($f, 'TEST') !== false) return 'TEST';
    if (strpos($f, 'ODI') !== false) return 'ODI';
    if (strpos($f, 'T10') !== false) return 'T10';
    if (strpos($f, '100') !== false || strpos($f, 'HUNDRED') !== false) return 'HUNDRED';
    return 'T20';
}

/**
 * The schedule as normalised fixtures — the same shape roanuz_normalise_fixture() returns, so the
 * existing fixture sync takes them unchanged. $data is the /schedule reply's "data" (days -> series ->
 * matches).
 */
function criclive_fixtures_from_schedule(array $days) {
    $out = [];
    foreach ($days as $day) {
        foreach ((array) ($day['series'] ?? []) as $series) {
            foreach ((array) ($series['matches'] ?? []) as $m) {
                if (empty($m['match_id'])) continue;
                $out[] = [
                    'key'        => criclive_match_key($m['match_id']),
                    'name'       => trim(($m['team1'] ?? 'Team A') . ' vs ' . ($m['team2'] ?? 'Team B') . (!empty($m['match_desc']) ? ', ' . $m['match_desc'] : '')),
                    'short_name' => trim(($m['team1_short'] ?? '') . ' vs ' . ($m['team2_short'] ?? '')),
                    'format'     => criclive_format($m['match_format'] ?? 'T20'),
                    'start_ms'   => isset($m['start_date']) ? (int) $m['start_date'] : null,
                    'status'     => 'not_started',
                    'teams'      => [
                        'a' => ['key' => criclive_team_key($m['team1_id'] ?? 0), 'name' => (string) ($m['team1'] ?? 'Team A'), 'code' => (string) ($m['team1_short'] ?? 'A')],
                        'b' => ['key' => criclive_team_key($m['team2_id'] ?? 0), 'name' => (string) ($m['team2'] ?? 'Team B'), 'code' => (string) ($m['team2_short'] ?? 'B')],
                    ],
                    'venue'           => trim(($m['ground'] ?? '') . (!empty($m['city']) ? ', ' . $m['city'] : ''), ', '),
                    'tournament_key'  => 'cls_' . (int) ($series['series_id'] ?? 0),
                    'tournament_name' => (string) ($series['series_name'] ?? ''),
                    '_criclive_team_ids' => ['a' => (int) ($m['team1_id'] ?? 0), 'b' => (int) ($m['team2_id'] ?? 0)],
                ];
            }
        }
    }
    return $out;
}

function criclive_fixtures() {
    $r = criclive_get('/schedule');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'fixtures' => []];
    return ['ok' => true, 'error' => null, 'fixtures' => criclive_fixtures_from_schedule((array) ($r['data']['data'] ?? []))];
}

/**
 * Squads -> per side, every player with our key, role and flags.
 * $teamIds = ['a' => <team1 id>, 'b' => <team2 id>] fixes which CricLive team is side a.
 * Returns ['sides' => ['a' => [...], 'b' => [...]], 'xi_announced' => bool] where each player is
 * ['key', 'id', 'name', 'role' (WK/BAT/ALL/BOWL), 'side', 'in_xi', 'is_captain', 'is_keeper'].
 */
function criclive_parse_squads(array $teams, array $teamIds) {
    $sides = ['a' => [], 'b' => []];
    $bySideId = array_flip(array_map('intval', $teamIds));
    $xiTotal = 0;
    foreach ($teams as $i => $t) {
        $side = $bySideId[(int) ($t['team_id'] ?? 0)] ?? ($i === 0 ? 'a' : 'b');
        foreach (['playing_xi' => true, 'bench' => false] as $group => $inXi) {
            foreach ((array) ($t[$group] ?? []) as $p) {
                if (empty($p['id'])) continue;
                $sides[$side][] = [
                    'key' => criclive_player_key($p['id']), 'id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? ''),
                    'role' => cricket_role($p['role'] ?? ''), 'side' => $side, 'in_xi' => $inXi,
                    'is_captain' => !empty($p['is_captain']), 'is_keeper' => !empty($p['is_keeper']),
                ];
                if ($inXi) $xiTotal++;
            }
        }
    }
    // Before the toss Cricbuzz lists the whole squad with no XI; an XI is "announced" only once both
    // sides show exactly eleven.
    $xiA = count(array_filter($sides['a'], function ($p) { return $p['in_xi']; }));
    $xiB = count(array_filter($sides['b'], function ($p) { return $p['in_xi']; }));
    return ['sides' => $sides, 'xi_announced' => $xiA === 11 && $xiB === 11];
}

/**
 * A commentary/scorecard name -> one of our player keys on the given side, or null.
 * Cricbuzz writes full names in the squad but sometimes only a first or last name in dismissals
 * ("c and b Smit"). Exact match first, then a unique first- or last-name match, never a guess.
 */
function criclive_resolve_player($name, array $players) {
    $n = strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    $n = preg_replace('/\s*\((c|wk|w|c & wk)\)\s*$/', '', $n);
    if ($n === '') return null;
    foreach ($players as $p) if (strtolower($p['name']) === $n) return $p['key'];
    $hits = [];
    foreach ($players as $p) {
        $parts = explode(' ', strtolower($p['name']));
        if (in_array($n, [reset($parts), end($parts)], true)) $hits[$p['key']] = true;
    }
    return count($hits) === 1 ? array_key_first($hits) : null;
}

/** "c Smit Patel b Ehsan Adil" + CAUGHT -> ['kind' => 'caught', 'fielder' => 'Smit Patel', 'bowler' => 'Ehsan Adil']. */
function criclive_parse_dismissal($outDesc, $code) {
    $d = trim((string) $outDesc);
    $code = strtoupper((string) $code);
    $kind = ['BOWLED' => 'bowled', 'CAUGHT' => 'caught', 'CAUGHTBOWLED' => 'caught_and_bowled', 'LBW' => 'lbw', 'STUMPED' => 'stumped',
             'RUNOUT' => 'run_out', 'RUN OUT' => 'run_out', 'HITWICKET' => 'hit_wicket', 'RETIREDHURT' => 'retired_hurt',
             'RETIRED' => 'retired_out', 'OBSTRUCTINGFIELD' => 'obstructing_field', 'TIMEDOUT' => 'timed_out'][$code] ?? null;
    if ($kind === null) {
        if ($d === '' || stripos($d, 'not out') !== false || stripos($d, 'batting') !== false) return null;
        $kind = stripos($d, 'run out') !== false ? 'run_out' : (stripos($d, 'st ') === 0 ? 'stumped' : (stripos($d, 'lbw') === 0 ? 'lbw' : 'other'));
    }
    $fielder = null; $bowler = null;
    if (preg_match('/\bb\s+(.+)$/', $d, $m)) $bowler = trim($m[1]);
    if ($kind === 'caught' && preg_match('/^c\s+(.+?)\s+b\s+/', $d, $m)) $fielder = trim($m[1]);
    if ($kind === 'caught_and_bowled') $fielder = $bowler;
    if ($kind === 'stumped' && preg_match('/^st\s+(.+?)\s+b\s+/', $d, $m)) $fielder = trim($m[1]);
    if ($kind === 'run_out' && preg_match('/run out\s*\(([^)]+)\)/i', $d, $m)) $fielder = trim($m[1]);   // may be "A/B"
    return ['kind' => $kind, 'fielder' => $fielder, 'bowler' => $bowler, 'text' => $d];
}

/**
 * The scorecard, per player, keyed by our player key where the name resolves (else "?name").
 * Used to reconcile what the ball-by-ball reading produced — never as the source of points, because
 * the card has no dot-ball counts for bowlers.
 */
function criclive_parse_scorecard(array $innings, array $sides) {
    $all = array_merge($sides['a'], $sides['b']);
    $byId = []; foreach ($all as $p) $byId[$p['id']] = $p;
    $bat = []; $bowl = []; $totals = [];
    foreach (array_values($innings) as $pos => $inn) {
        // Numbered by position (batting order), never by the reply's innings_id: the real scorecard sends
        // innings_id 0 for every innings, which would merge them.
        $iid = $pos + 1;
        $totals[$iid] = ['score' => (string) ($inn['score'] ?? ''), 'extras' => criclive_parse_extras($inn['extras'] ?? ''),
                         'bat_team' => (string) ($inn['bat_team'] ?? ''), 'bat_team_short' => (string) ($inn['bat_team_short'] ?? '')];
        foreach ((array) ($inn['batsmen'] ?? []) as $b) {
            $key = isset($byId[(int) ($b['player_id'] ?? 0)]) ? $byId[(int) $b['player_id']]['key'] : '?' . ($b['name'] ?? '');
            $dis = criclive_parse_dismissal($b['out_desc'] ?? '', $b['wicket_code'] ?? '');
            $bat[$iid][$key] = ['runs' => (int) ($b['runs'] ?? 0), 'balls' => (int) ($b['balls'] ?? 0), 'fours' => (int) ($b['fours'] ?? 0),
                                'sixes' => (int) ($b['sixes'] ?? 0), 'out' => $dis !== null && $dis['kind'] !== 'retired_hurt', 'dismissal' => $dis];
        }
        foreach ((array) ($inn['bowlers'] ?? []) as $w) {
            $key = isset($byId[(int) ($w['player_id'] ?? 0)]) ? $byId[(int) $w['player_id']]['key'] : '?' . ($w['name'] ?? '');
            $bowl[$iid][$key] = ['overs' => (float) ($w['overs'] ?? 0), 'maidens' => (int) ($w['maidens'] ?? 0), 'runs' => (int) ($w['runs'] ?? 0),
                                 'wickets' => (int) ($w['wickets'] ?? 0), 'wides' => (int) ($w['wides'] ?? 0), 'no_balls' => (int) ($w['no_balls'] ?? 0)];
        }
    }
    return ['batting' => $bat, 'bowling' => $bowl, 'innings' => $totals];
}

/** "Extras: 6 (b 0, lb 3, w 3, nb 0, p 0)" -> ['total'=>6,'b'=>0,'lb'=>3,'w'=>3,'nb'=>0,'p'=>0]. */
function criclive_parse_extras($text) {
    $out = ['total' => 0, 'b' => 0, 'lb' => 0, 'w' => 0, 'nb' => 0, 'p' => 0];
    if (preg_match('/Extras:\s*(\d+)/i', (string) $text, $m)) $out['total'] = (int) $m[1];
    foreach (['b', 'lb', 'w', 'nb', 'p'] as $k) if (preg_match('/\b' . $k . '\s+(\d+)/', (string) $text, $m)) $out[$k] = (int) $m[1];
    return $out;
}

/**
 * The Roanuz-shaped snapshot cricket_feed_ingest() takes, from a fixture + squads + commentary reply.
 * related_balls stays empty until the commentary reader exists (after the live test).
 */
function criclive_snapshot(array $fixture, array $squads, array $commentary) {
    $mini = (array) ($commentary['miniscore'] ?? []);
    $head = (array) ($commentary['match_header'] ?? []);
    $sides = $squads['sides'];
    $players = []; $squad = ['a' => ['player_keys' => []], 'b' => ['player_keys' => []]];
    foreach (['a', 'b'] as $s) {
        foreach ($sides[$s] as $p) {
            $players[$p['key']] = ['player' => ['key' => $p['key'], 'name' => $p['name'], 'seasonal_role' => $p['role']]];
            $squad[$s]['player_keys'][] = $p['key'];
        }
        if ($squads['xi_announced']) {
            $squad[$s]['playing_xi'] = array_values(array_map(function ($p) { return $p['key']; }, array_filter($sides[$s], function ($p) { return $p['in_xi']; })));
        }
    }
    // Toss and innings order arrive as team names / short codes; map them to sides.
    $sideOf = function ($nameOrCode) use ($fixture) {
        $x = strtolower(trim((string) $nameOrCode));
        foreach (['a', 'b'] as $s) {
            if ($x !== '' && ($x === strtolower($fixture['teams'][$s]['name']) || $x === strtolower($fixture['teams'][$s]['code']))) return $s;
        }
        return null;
    };
    $toss = null;
    if (!empty($head['toss_winner']) && ($ts = $sideOf($head['toss_winner']))) {
        $dec = strtolower((string) ($head['toss_decision'] ?? ''));
        $toss = ['winner' => $fixture['teams'][$ts]['key'], 'elected' => strpos($dec, 'bowl') !== false ? 'bowl' : 'bat'];
    }
    $order = []; $count = ['a' => 0, 'b' => 0];
    foreach ((array) ($mini['innings_scores'] ?? []) as $inn) {
        $s = $sideOf($inn['bat_team'] ?? '');
        if ($s) { $count[$s]++; $order[] = $s . '_' . $count[$s]; }
    }
    $state = $mini['state'] ?? ($head['state'] ?? '');
    $status = criclive_status($state, $mini['status'] ?? ($head['status'] ?? ''));
    return [
        'key' => $fixture['key'], 'name' => $fixture['name'], 'short_name' => $fixture['short_name'] ?? '',
        'format' => strtolower($fixture['format']), 'start_at' => $fixture['start_ms'] ? (int) floor($fixture['start_ms'] / 1000) : null,
        'status' => $status === 'live' || $status === 'innings_break' ? 'started' : ($status === 'completed' ? 'completed' : ($status === 'abandoned' ? 'abandoned' : 'not_started')),
        'play_status' => $status === 'innings_break' ? 'innings_break' : ($status === 'live' ? 'in_play' : ($status === 'completed' ? 'result' : '')),
        'teams' => ['a' => $fixture['teams']['a'], 'b' => $fixture['teams']['b']],
        'squad' => $squad, 'players' => $players, 'toss' => $toss,
        'play' => ['innings_order' => $order, 'result' => $status === 'completed' || $status === 'abandoned' ? ['msg' => (string) ($mini['status'] ?? $head['status'] ?? '')] : null],
        'related_balls' => [],
        '_source' => 'criclive',
    ];
}
