<?php
/**
 * Sportmonks Cricket API v2 connector — the live feed chosen after the 9 Oct 2026 live test
 * (tools/sportmonks-live-sample.php: a new ball reached us a median 5.6s after Sportmonks posted it).
 *
 * Endpoints (https://cricket.sportmonks.com/api/v2.0, ?api_token=..., limit 2,000 calls/hour each):
 *
 *   GET /fixtures?filter[starts_between]=..&include=localteam,visitorteam,league,venue   the schedule
 *   GET /teams/{team}/squad/{season}                                                   a season squad
 *   GET /livescores?include=<SM_LIVE_INCLUDE>              every live match, with every ball, in ONE call
 *   GET /fixtures/{id}?include=<SM_LIVE_INCLUDE>           one match in full (resync / finished matches)
 *
 * Everything here turns those replies into the shapes the pipeline already speaks — the normalised
 * fixtures roanuz_normalise_fixture() returns and the Roanuz-shaped snapshot cricket_feed_ingest()
 * takes — so Your 11, Ball by Ball and match betting need no changes of their own.
 *
 * Two things the live test showed about this feed, and how they are handled:
 *   - about 1 ball in 7 is first posted wrong and corrected within ~10s (FOUR -> 5 Wides, SIX -> FOUR):
 *     Ball by Ball holds every settlement for confirm_seconds after a ball last changed (bbb.php);
 *   - a wrong entry is sometimes DELETED: every snapshot here lists the full ball set, so it is marked
 *     _balls_complete and the ingest removes a stored ball the provider no longer lists.
 *
 * Score objects, as observed on real T20Is (runs never include byes / leg-byes):
 *   "1 Wide"/"5 Wides"   runs = all the wides, ball=false      "1 No Ball + 1 Run"  runs 2, noball_runs 1
 *   "1 Bye"/"4 Byes"     runs 0, bye = n                       "1 No Ball + 1 Bye"  runs 1, noball_runs 1, bye 1
 *   "1 Leg Bye"          runs 0, leg_bye = n                   "Catch Out" / "Clean Bowled"  is_wicket, out
 */

require_once __DIR__ . '/cricket-feed.php';

const SM_LIVE_INCLUDE = 'localteam,visitorteam,lineup,balls.score,runs,venue,league';

function sm_conf() {
    return [
        'token' => trim((string) env_get('SPORTMONKS_API_TOKEN', '')),
        'base'  => rtrim((string) env_get('SPORTMONKS_BASE_URL', 'https://cricket.sportmonks.com/api/v2.0'), '/'),
    ];
}

/**
 * One GET. Returns ['ok', 'status', 'data', 'error']; never throws, never logs the token.
 * Tests replace the network with $GLOBALS['SPORTMONKS_FAKE'] = function ($path, $query) { return [status, body]; }.
 */
function sm_get($path, array $query = []) {
    $path = '/' . ltrim($path, '/');
    if (isset($GLOBALS['SPORTMONKS_FAKE']) && is_callable($GLOBALS['SPORTMONKS_FAKE'])) {
        [$status, $body] = ($GLOBALS['SPORTMONKS_FAKE'])($path, $query);
        $data = is_array($body) ? $body : json_decode((string) $body, true);
        $ok = $status >= 200 && $status < 300 && is_array($data) && array_key_exists('data', $data);
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $ok ? null : 'HTTP ' . $status];
    }
    $c = sm_conf();
    if ($c['token'] === '') return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'SPORTMONKS_API_TOKEN is not set'];
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl extension unavailable'];
    $ch = curl_init($c['base'] . $path . '?' . http_build_query($query + ['api_token' => $c['token']]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $ca = (string) env_get('CA_BUNDLE', '');
    if ($ca !== '' && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $err ?: 'request failed'];
    $raw = str_replace($c['token'], '<token>', (string) $raw);
    $data = json_decode($raw, true);
    $ok = $status >= 200 && $status < 300 && is_array($data) && array_key_exists('data', $data);
    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $ok ? null : 'HTTP ' . $status . ' ' . substr($raw, 0, 160)];
}

function sm_match_key($id)  { return 'sm_' . (int) $id; }
function sm_match_id($key)  { return (int) preg_replace('/^sm_/', '', (string) $key); }
function sm_player_key($id) { return 'smp_' . (int) $id; }
function sm_team_key($id)   { return 'smt_' . (int) $id; }
function sm_is_key($key)    { return strpos((string) $key, 'sm_') === 0; }

/** Sportmonks includes come either bare or wrapped as {data: [...]}, depending on the endpoint. */
function sm_list($v) {
    if (is_array($v) && isset($v['data']) && is_array($v['data'])) return $v['data'];
    return is_array($v) ? $v : [];
}

function sm_format($type) {
    $t = strtoupper((string) $type);
    if (strpos($t, 'T10') !== false) return 'T10';
    if (strpos($t, 'T20') !== false) return 'T20';
    if (strpos($t, 'ODI') !== false || strpos($t, 'LIST') !== false || strpos($t, 'ONE') !== false) return 'ODI';
    if (strpos($t, 'TEST') !== false || strpos($t, '4DAY') !== false || strpos($t, '5DAY') !== false) return 'TEST';
    return 'T20';
}

/** Our status from Sportmonks' fixture status string (+ the live flag). */
function sm_status($status, $live = false) {
    $s = strtolower(trim((string) $status));
    foreach (['aban', 'cancl', 'cancel', 'no result', 'postp'] as $n) if (strpos($s, $n) !== false) return 'abandoned';
    if ($s === 'finished' || strpos($s, 'finish') !== false) return 'completed';
    if (strpos($s, 'innings break') !== false || $s === 'break') return 'innings_break';
    if (strpos($s, 'innings') !== false || strpos($s, 'stump') !== false || strpos($s, 'int.') !== false
        || strpos($s, 'tea') !== false || strpos($s, 'lunch') !== false || strpos($s, 'dinner') !== false || $live) return 'live';
    return 'not_started';
}

function sm_team(array $t = null, $fallbackId = 0) {
    $id = (int) ($t['id'] ?? $fallbackId);
    return ['key' => sm_team_key($id), 'name' => (string) ($t['name'] ?? 'Team ' . $id), 'code' => (string) ($t['code'] ?? substr((string) ($t['name'] ?? 'T'), 0, 3))];
}

/** One Sportmonks fixture -> the normalised fixture roanuz_normalise_fixture() returns. */
function sm_normalise_fixture(array $f) {
    $a = sm_team($f['localteam'] ?? null, $f['localteam_id'] ?? 0);
    $b = sm_team($f['visitorteam'] ?? null, $f['visitorteam_id'] ?? 0);
    $start = !empty($f['starting_at']) ? strtotime((string) $f['starting_at']) : false;
    $league = (array) ($f['league'] ?? []);
    $round = trim((string) ($f['round'] ?? ''));
    return [
        'key'        => sm_match_key($f['id']),
        'name'       => $a['name'] . ' vs ' . $b['name'] . ($round !== '' ? ', ' . $round : ''),
        'short_name' => $a['code'] . ' vs ' . $b['code'],
        'format'     => sm_format($f['type'] ?? ''),
        'start_ms'   => $start ? $start * 1000 : null,
        'status'     => sm_status($f['status'] ?? '', !empty($f['live'])),
        'teams'      => ['a' => $a, 'b' => $b],
        'venue'      => (string) cricket_pick($f, ['venue.name'], ''),
        // The squad endpoint is per team per SEASON, so the season id stands in for Roanuz's tournament key.
        'tournament_key'  => (string) ($f['season_id'] ?? ''),
        'tournament_name' => (string) ($league['name'] ?? ''),
    ];
}

/** Fixtures from 6 hours ago to 7 days ahead (the plan only returns leagues it covers). */
function sm_fixtures($nowMs = null) {
    $now = (int) floor(($nowMs ?? now_ms()) / 1000);
    $res = sm_get('/fixtures', [
        'filter[starts_between]' => gmdate('Y-m-d\TH:i:s', $now - 6 * 3600) . ',' . gmdate('Y-m-d\TH:i:s', $now + 7 * 86400),
        'include' => 'localteam,visitorteam,league,venue', 'sort' => 'starting_at',
    ]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'fixtures' => []];
    return ['ok' => true, 'error' => null, 'fixtures' => array_map('sm_normalise_fixture', sm_list($res['data']['data']))];
}

/** A team's season squad, in roanuz_team_squad()'s shape. Players without a season squad fall back to none. */
function sm_team_squad($seasonId, $teamKey) {
    $tid = (int) preg_replace('/^smt_/', '', (string) $teamKey);
    $res = sm_get('/teams/' . $tid . '/squad/' . (int) $seasonId);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'players' => []];
    $players = [];
    foreach (sm_list(cricket_pick($res['data'], ['data.squad'], [])) as $p) {
        if (!is_array($p) || empty($p['id'])) continue;
        $players[] = ['key' => sm_player_key($p['id']), 'name' => (string) ($p['fullname'] ?? trim(($p['firstname'] ?? '') . ' ' . ($p['lastname'] ?? ''))),
                      'role' => cricket_role(cricket_pick($p, ['position.name'], '')), 'skill' => null];
    }
    return ['ok' => true, 'error' => null, 'players' => $players];
}

/** Dismissal kind from the score name, in the words cricket-feed.php and the scorers use. */
function sm_wicket_kind($name, $catcherId, $bowlerId) {
    $n = strtolower((string) $name);
    if (strpos($n, 'run out') !== false || strpos($n, 'runout') !== false) return 'run_out';
    if (strpos($n, 'stump') !== false) return 'stumped';
    if (strpos($n, 'lbw') !== false) return 'lbw';
    if (strpos($n, 'hit wicket') !== false) return 'hit_wicket';
    if (strpos($n, 'retired') !== false) return strpos($n, 'out') !== false && strpos($n, 'not') === false ? 'retired_out' : 'retired_hurt';
    if (strpos($n, 'obstruct') !== false) return 'obstructing_the_field';
    if (strpos($n, 'bowled') !== false && strpos($n, 'caught') === false && strpos($n, 'catch') === false) return 'bowled';
    if (strpos($n, 'catch') !== false || strpos($n, 'caught') !== false) {
        return ($catcherId && $bowlerId && (int) $catcherId === (int) $bowlerId) || strpos($n, '& b') !== false || strpos($n, 'and bowled') !== false
            ? 'caught_and_bowled' : 'caught';
    }
    return 'unknown';
}

/**
 * One Sportmonks ball -> a raw ball in the shape cricket_parse_ball() reads.
 * $innKey is our innings key for the ball's scoreboard ("a_1"); $tsSec the ball's own posting time.
 */
function sm_ball_raw(array $b, $innKey, $battingTeamKey) {
    $s = (array) ($b['score'] ?? []);
    $runs = (int) ($s['runs'] ?? 0); $bye = (int) ($s['bye'] ?? 0); $lb = (int) ($s['leg_bye'] ?? 0);
    $nb = (int) ($s['noball'] ?? 0); $nbRuns = (int) ($s['noball_runs'] ?? 0);
    $name = strtolower((string) ($s['name'] ?? ''));
    $isWide = strpos($name, 'wide') !== false;
    if ($isWide)        { $type = 'wide';    $bat = 0;                          $extras = $runs + $bye + $lb; }
    elseif ($nb > 0)    { $type = 'no_ball'; $bat = max(0, $runs - $nbRuns);    $extras = $nbRuns + $bye + $lb; }
    elseif ($bye > 0)   { $type = 'bye';     $bat = 0;                          $extras = $bye; }
    elseif ($lb > 0)    { $type = 'leg_bye'; $bat = 0;                          $extras = $lb; }
    else                { $type = 'normal';  $bat = $runs;                      $extras = 0; }
    $parts = explode('.', number_format((float) ($b['ball'] ?? 0), 1, '.', ''));
    $raw = [
        'key' => 'smb_' . (int) $b['id'],
        'innings' => $innKey,
        'overs' => [(int) $parts[0], (int) ($parts[1] ?? 0)],
        'ball_type' => $type,
        'batting_team' => $battingTeamKey,
        'batsman' => ['player_key' => !empty($b['batsman_id']) ? sm_player_key($b['batsman_id']) : null, 'runs' => $bat,
                      'is_four' => !empty($s['four']) && !$isWide, 'is_six' => !empty($s['six']) && !$isWide],
        'bowler' => ['player_key' => !empty($b['bowler_id']) ? sm_player_key($b['bowler_id']) : null],
        'team_score' => ['runs' => $bat + $extras, 'extras' => $extras, 'is_wicket' => !empty($s['is_wicket'])],
        'timestamp' => !empty($b['updated_at']) ? strtotime((string) $b['updated_at']) : null,
    ];
    $ns = null;
    foreach (['batsman_one_on_creeze_id', 'batsman_two_on_creeze_id'] as $f) {
        if (!empty($b[$f]) && (int) $b[$f] !== (int) ($b['batsman_id'] ?? 0)) $ns = sm_player_key($b[$f]);
    }
    if ($ns) $raw['non_striker'] = ['player_key' => $ns];
    if (!empty($s['is_wicket'])) {
        $kind = sm_wicket_kind($s['name'] ?? '', $b['catchstump_id'] ?? null, $b['bowler_id'] ?? null);
        $outId = !empty($b['batsmanout_id']) ? $b['batsmanout_id'] : ($b['batsman_id'] ?? null);
        $fielders = [];
        if (!empty($b['catchstump_id'])) {
            $fielders[] = ['player_key' => sm_player_key($b['catchstump_id']), 'is_catch' => in_array($kind, ['caught', 'caught_and_bowled'], true),
                           'is_stumps' => $kind === 'stumped'];
        }
        if (!empty($b['runout_by_id'])) {
            $fielders[] = ['player_key' => sm_player_key($b['runout_by_id']), 'is_run_out' => true];
        }
        $raw['wicket'] = ['kind' => $kind, 'player_key' => $outId ? sm_player_key($outId) : null];
        $raw['fielders'] = $fielders;
    }
    return $raw;
}

/** One fixture (with SM_LIVE_INCLUDE) -> the Roanuz-shaped snapshot cricket_feed_ingest() takes. */
function sm_snapshot(array $f) {
    $fx = sm_normalise_fixture($f);
    $sideOf = [(int) ($f['localteam_id'] ?? 0) => 'a', (int) ($f['visitorteam_id'] ?? 0) => 'b'];
    $players = []; $squad = ['a' => ['player_keys' => [], 'playing_xi' => []], 'b' => ['player_keys' => [], 'playing_xi' => []]];
    foreach (sm_list($f['lineup'] ?? []) as $p) {
        $side = $sideOf[(int) cricket_pick($p, ['lineup.team_id'], 0)] ?? null;
        if (!$side || empty($p['id'])) continue;
        $k = sm_player_key($p['id']);
        $players[$k] = ['player' => ['key' => $k, 'name' => (string) ($p['fullname'] ?? $k), 'seasonal_role' => (string) cricket_pick($p, ['position.name'], '')]];
        $squad[$side]['player_keys'][] = $k;
        if (empty($p['lineup']['substitution'])) $squad[$side]['playing_xi'][] = $k;
    }
    foreach (['a', 'b'] as $s) if (count($squad[$s]['playing_xi']) < 11) unset($squad[$s]['playing_xi']);

    // Innings: scoreboard S1, S2, ... in order; the batting side is the ball's team. Super overs
    // (Sportmonks scoreboards beyond the regulation innings) are flagged by cricket_parse_ball.
    $balls = sm_list($f['balls'] ?? []);
    usort($balls, function ($x, $y) { return ((int) $x['id']) <=> ((int) $y['id']); });
    $innOf = []; $order = []; $count = ['a' => 0, 'b' => 0]; $related = [];
    foreach ($balls as $b) {
        $sb = (string) ($b['scoreboard'] ?? 'S1');
        $side = $sideOf[(int) ($b['team_id'] ?? 0)] ?? null;
        if (!$side) continue;
        if (!isset($innOf[$sb])) { $count[$side]++; $innOf[$sb] = $side . '_' . $count[$side]; $order[] = $innOf[$sb]; }
        $related[] = sm_ball_raw($b, $innOf[$sb], $fx['teams'][$side]['key']);
    }
    // An innings with no ball yet (a fresh second innings) still belongs in the order, from the runs list.
    foreach (sm_list($f['runs'] ?? []) as $r) {
        $side = $sideOf[(int) ($r['team_id'] ?? 0)] ?? null;
        $sb = 'S' . (int) ($r['inning'] ?? 0);
        if ($side && !isset($innOf[$sb]) && (int) ($r['inning'] ?? 0) === count($order) + 1) { $count[$side]++; $innOf[$sb] = $side . '_' . $count[$side]; $order[] = $innOf[$sb]; }
    }
    // "2nd Innings" before its first ball and before its runs row: in a limited-overs match it is the other side.
    if (count($order) === 1 && stripos((string) ($f['status'] ?? ''), '2nd innings') !== false && $fx['format'] !== 'TEST') {
        $other = $order[0][0] === 'a' ? 'b' : 'a';
        $order[] = $other . '_1';
    }

    $status = $fx['status'];
    $toss = null;
    if (!empty($f['toss_won_team_id']) && isset($sideOf[(int) $f['toss_won_team_id']])) {
        $toss = ['winner' => $fx['teams'][$sideOf[(int) $f['toss_won_team_id']]]['key'],
                 'elected' => stripos((string) ($f['elected'] ?? ''), 'bowl') !== false ? 'bowl' : 'bat'];
    }
    $winner = !empty($f['winner_team_id']) ? ($sideOf[(int) $f['winner_team_id']] ?? null) : null;
    $target = null;
    foreach (sm_list($f['runs'] ?? []) as $r) if ((int) ($r['inning'] ?? 0) === 1 && $fx['format'] !== 'TEST') $target = (int) $r['score'] + 1;
    if (!empty($f['rpc_target'])) $target = (int) $f['rpc_target'];

    return [
        'key' => $fx['key'], 'name' => $fx['name'], 'short_name' => $fx['short_name'],
        'format' => strtolower($fx['format']), 'start_at' => $fx['start_ms'] ? (int) floor($fx['start_ms'] / 1000) : null,
        'status' => in_array($status, ['live', 'innings_break'], true) ? 'started' : ($status === 'completed' ? 'completed' : ($status === 'abandoned' ? 'abandoned' : 'not_started')),
        'play_status' => $status === 'innings_break' ? 'innings_break' : ($status === 'live' ? 'in_play' : ($status === 'completed' ? 'result' : ($status === 'abandoned' ? 'no_result' : ''))),
        'teams' => $fx['teams'], 'squad' => $squad, 'players' => $players, 'toss' => $toss,
        'venue' => $fx['venue'],
        'play' => ['innings_order' => $order, 'target' => count($order) >= 2 ? $target : null,
                   'result' => in_array($status, ['completed', 'abandoned'], true) ? ['msg' => (string) ($f['note'] ?? ''), 'winner' => $winner] : null],
        'related_balls' => $related,
        // Every snapshot from this connector lists the match's complete ball set, so a stored ball it
        // no longer lists was deleted by the provider (a wrong entry) and must go.
        '_balls_complete' => true,
        '_source' => 'sportmonks',
    ];
}

/** One match in full by REST — resync, and the final state of a match that has left /livescores. */
function sm_match_snapshot($matchKey) {
    $res = sm_get('/fixtures/' . sm_match_id($matchKey), ['include' => SM_LIVE_INCLUDE]);
    if (!$res['ok'] || !is_array($res['data']['data'] ?? null)) return ['ok' => false, 'error' => $res['error'] ?: 'no such fixture'];
    return ['ok' => true, 'snapshot' => sm_snapshot($res['data']['data'])];
}

/**
 * One polling pass: every live match in one /livescores call, ingested through the normal path; plus a
 * final /fixtures/{id} read for any match we were following that has just left the live list, so its
 * result is recorded. Returns ['ok', 'live' => n, 'ingested' => n, 'finished' => n, 'error'].
 */
function sm_poll_once($nowMs = null) {
    $res = sm_get('/livescores', ['include' => SM_LIVE_INCLUDE]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'live' => 0, 'ingested' => 0, 'finished' => 0];
    $seen = []; $ingested = 0;
    foreach (sm_list($res['data']['data']) as $f) {
        if (!is_array($f) || empty($f['id'])) continue;
        $snap = sm_snapshot($f);
        $seen[$snap['key']] = true;
        $r = cricket_feed_ingest($snap, 'poll', $nowMs);
        if (!empty($r['ok'])) $ingested++;
    }
    $finished = 0;
    $following = all('SELECT "match_key" FROM "cricket_match_feed" WHERE "match_key" LIKE ? AND "status" IN (?,?)', ['sm\_%', 'live', 'innings_break']);
    foreach ($following as $m) {
        if (isset($seen[$m['match_key']])) continue;
        $one = sm_match_snapshot($m['match_key']);
        if ($one['ok']) { cricket_feed_ingest($one['snapshot'], 'poll', $nowMs); $finished++; }
    }
    return ['ok' => true, 'error' => null, 'live' => count($seen), 'ingested' => $ingested, 'finished' => $finished];
}
