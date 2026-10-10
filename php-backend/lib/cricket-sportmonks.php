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

const SM_LIVE_INCLUDE = 'localteam,visitorteam,lineup,balls.score,runs,venue,league,manofmatch';

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
function sm_status($status, $live = false, $note = '', $noResult = null) {
    $s = strtolower(trim((string) $status));
    foreach (['aban', 'cancl', 'cancel', 'no result', 'postp'] as $n) if (strpos($s, $n) !== false) return 'abandoned';
    // "Finished" can still mean no result (rain): every such match must refund, never settle.
    if (strpos($s, 'finish') !== false && (!empty($noResult) && stripos((string) $noResult, 'draw') === false
        || preg_match('/no result|abandon|cancel/i', (string) $note))) return 'abandoned';
    if ($s === 'finished' || strpos($s, 'finish') !== false) return 'completed';
    if (strpos($s, 'innings break') !== false || $s === 'break') return 'innings_break';
    // Only the status string decides. Sportmonks' "live" flag means live COVERAGE is available — it is
    // already true on fixtures days before they start — so it must never make a match count as in play.
    if (strpos($s, 'innings') !== false || strpos($s, 'stump') !== false || strpos($s, 'int.') !== false
        || strpos($s, 'tea') !== false || strpos($s, 'lunch') !== false || strpos($s, 'dinner') !== false) return 'live';
    return 'not_started';
}

function sm_team(array $t = null, $fallbackId = 0) {
    $id = (int) ($t['id'] ?? $fallbackId);
    $logo = (string) ($t['image_path'] ?? '');
    if ($logo === '') $logo = sm_image_url('teams', $id);
    elseif (strpos($logo, 'placeholder') !== false) $logo = null;   // Sportmonks has no crest: show the team code
    return ['key' => sm_team_key($id), 'name' => (string) ($t['name'] ?? 'Team ' . $id), 'code' => (string) ($t['code'] ?? substr((string) ($t['name'] ?? 'T'), 0, 3)),
            'logo' => $logo];
}

/**
 * Sportmonks' CDN path for a team crest or player photo: /images/cricket/{kind}/{id % 32}/{id}.png
 * (checked against every image_path in the 9 Oct replies). One with no photo 404s, and the pages
 * fall back to initials / the team code.
 */
function sm_image_url($kind, $id) {
    $id = (int) $id;
    return $id > 0 ? 'https://cdn.sportmonks.com/images/cricket/' . $kind . '/' . ($id % 32) . '/' . $id . '.png' : null;
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
        'status'     => sm_status($f['status'] ?? '', !empty($f['live']), $f['note'] ?? '', $f['draw_noresult'] ?? null),
        'teams'      => ['a' => $a, 'b' => $b],
        'venue'      => trim((string) cricket_pick($f, ['venue.name'], '') . (cricket_pick($f, ['venue.city'], '') ? ', ' . cricket_pick($f, ['venue.city'], '') : ''), ', '),
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
    // "Finished" can arrive a moment before winner_team_id is filled in. Match betting reads a completed
    // match with no winner as a TIE and voids every bet, so it stays in play until the winner is known
    // or the note says it really was a tie.
    if ($status === 'completed' && empty($f['winner_team_id']) && !preg_match('/\btie|tied|draw/i', (string) ($f['note'] ?? ''))) $status = 'live';
    $resultHeld = null;
    if ($status === 'completed') {
        $resultHeld = sm_result_disagreement($f, $fx);
        if ($resultHeld) { $status = 'live'; log_warn('sportmonks: result held back', ['match' => $fx['key'], 'why' => $resultHeld]); }
    }
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
        'play_status' => $resultHeld ? 'awaiting confirmed result' : ($status === 'innings_break' ? 'innings_break' : ($status === 'live' ? 'in_play' : ($status === 'completed' ? 'result' : ($status === 'abandoned' ? 'no_result' : '')))),
        'teams' => $fx['teams'], 'squad' => $squad, 'players' => $players, 'toss' => $toss,
        'venue' => $fx['venue'],
        'play' => ['innings_order' => $order, 'target' => count($order) >= 2 ? $target : null,
                   'result' => in_array($status, ['completed', 'abandoned'], true)
                       ? ['msg' => trim((string) ($f['note'] ?? '') . (!empty($f['manofmatch']['fullname']) ? ' · Player of the Match: ' . $f['manofmatch']['fullname'] : '')), 'winner' => $winner]
                       : null],
        'related_balls' => $related,
        // Every snapshot from this connector lists the match's complete ball set, so a stored ball it
        // no longer lists was deleted by the provider (a wrong entry) and must go.
        '_balls_complete' => true,
        '_source' => 'sportmonks',
    ];
}

/**
 * Why a "Finished" fixture's result cannot be trusted yet, or null when it can. Seen live on 9 Oct 2026:
 * India v West Indies came back Finished with winner_team_id = India and no note, although West Indies
 * had chased 252/4 against 249/5. Money settles on the winner, so it must agree with the result note
 * AND (in a limited-overs match with no rain target) with the runs; until all three agree the match
 * stays in play and nothing settles. A wrong field corrected later then settles correctly.
 */
function sm_result_disagreement(array $f, array $fx) {
    if (empty($f['winner_team_id'])) return null;   // ties / no result are handled by the status rules
    $sideOf = [(int) ($f['localteam_id'] ?? 0) => 'a', (int) ($f['visitorteam_id'] ?? 0) => 'b'];
    $w = $sideOf[(int) $f['winner_team_id']] ?? null;
    if (!$w) return 'winner is neither team';
    $note = strtolower((string) ($f['note'] ?? ''));
    $team = $fx['teams'][$w];
    if ($note === '' || strpos($note, 'won') === false
        || (strpos($note, strtolower($team['name'])) === false && strpos($note, strtolower($team['code'])) === false)) {
        return 'result note does not name the winner yet';
    }
    if ($fx['format'] !== 'TEST' && empty($f['rpc_target'])) {
        $runs = ['a' => null, 'b' => null];
        foreach (sm_list($f['runs'] ?? []) as $r) {
            $s = $sideOf[(int) ($r['team_id'] ?? 0)] ?? null;
            if ($s && (int) ($r['inning'] ?? 0) <= 2) $runs[$s] = (int) $r['score'];
        }
        if ($runs['a'] !== null && $runs['b'] !== null && $runs['a'] !== $runs['b']) {
            $more = $runs['a'] > $runs['b'] ? 'a' : 'b';
            if ($more !== $w && empty($f['super_over'])) return 'winner contradicts the scores';
        }
    }
    return null;
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
        // Once per match: anyone in the named XI missing from the Your 11 list is added.
        if (!empty($snap['squad']['a']['playing_xi']) && !state_get('sm_xi_' . $snap['key'])) {
            sm_fantasy_add_xi_players($snap);
            state_set('sm_xi_' . $snap['key'], ['ms' => now_ms()]);
        }
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

// =================================================================================================
// What else Sportmonks gives us, used the way the established sites use it
// =================================================================================================

/**
 * The playing XI and the toss arrive ~30 minutes before the start, while the fixture is still "NS" and
 * not yet in /livescores. Every fixture due within 75 minutes (or overdue up to 3 hours: delayed starts)
 * is read in full every 2 minutes, so Your 11 shows Playing / Not playing before its deadline, as Dream11
 * does. Returns how many fixtures were read.
 */
function sm_poll_prematch($nowMs = null) {
    $now = $nowMs ?? now_ms();
    $rows = all('SELECT "match_key" FROM "cricket_match_feed" WHERE "match_key" LIKE ? AND "status" = ? AND "start_time" BETWEEN ? AND ?',
                ['sm\_%', 'not_started', ms_to_sql($now - 3 * 3600000), ms_to_sql($now + 75 * 60000)]);
    $n = 0;
    foreach ($rows as $r) {
        $k = 'sm_prematch_' . $r['match_key'];
        $last = state_get($k);
        if (is_array($last) && $now - (int) ($last['ms'] ?? 0) < 120000) continue;
        state_set($k, ['ms' => $now]);
        $one = sm_match_snapshot($r['match_key']);
        if (!$one['ok']) continue;
        cricket_feed_ingest($one['snapshot'], 'poll', $now);
        sm_fantasy_add_xi_players($one['snapshot']);
        $n++;
    }
    return $n;
}

/**
 * A named XI can include someone missing from the season squad (a late call-up or a debutant). Add them
 * to the Your 11 player list, priced like everyone else, so every player who takes the field can be
 * picked and scores. Returns how many were added.
 */
function sm_fantasy_add_xi_players(array $snap) {
    if (!function_exists('fantasy_upsert_player') || !function_exists('fantasy_feed_credits')) return 0;
    $m = one('SELECT "id","format" FROM "fantasy_matches" WHERE "feed_key" = ?', [(string) ($snap['key'] ?? '')]);
    if (!$m) return 0;
    $known = array_flip(array_column(all('SELECT "external_key" FROM "fantasy_players" WHERE "match_id" = ?', [(int) $m['id']]), 'external_key'));
    $added = 0;
    foreach (['a', 'b'] as $s) {
        foreach ((array) ($snap['squad'][$s]['playing_xi'] ?? []) as $k) {
            if (isset($known[$k])) continue;
            $p = $snap['players'][$k]['player'] ?? ['name' => $k, 'seasonal_role' => ''];
            $role = cricket_role($p['seasonal_role'] ?? '');
            fantasy_upsert_player((int) $m['id'], [
                'external_key' => $k, 'name' => $p['name'], 'full_name' => $p['name'], 'team_name' => $snap['teams'][$s]['name'], 'role' => $role,
                'credits' => fantasy_feed_credits($k, $role, sm_player_skill((int) preg_replace('/^smp_/', '', $k), $m['format']), $m['format']),
            ]);
            $added++;
        }
    }
    if ($added) log_info('sportmonks: added XI players missing from the squad', ['match' => $snap['key'], 'added' => $added]);
    return $added;
}

/**
 * A player's form as the "skill" fantasy_feed_credits() prices from, out of Sportmonks career figures
 * for the format (T20 = domestic T20 + T20I; ODI = ODI + List A; Test = Test + first class): expected
 * Dream11 points per match from runs, boundaries, strike rate, wickets and economy, mapped so the skill
 * gives the same credits as the points-history formula (6 + points/12): ~30 points -> 8.5, ~50 -> 10.
 * Fewer than 5 matches -> null (role default). Cached 14 days per player; failures are not cached.
 */
function sm_player_skill($playerId, $format = 'T20') {
    $playerId = (int) $playerId;
    if ($playerId <= 0) return null;
    $fmt = sm_skill_format($format);
    $ck = 'sm_skill_' . $playerId . '_' . $fmt;
    $c = state_get($ck);
    if (is_array($c) && now_ms() - (int) ($c['ms'] ?? 0) < 14 * 86400000) return $c['skill'];
    $res = sm_get('/players/' . $playerId, ['include' => 'career']);
    if (!$res['ok']) return null;
    $skill = sm_skill_from_career((array) cricket_pick($res['data'], ['data.career'], []), $fmt);
    state_set($ck, ['ms' => now_ms(), 'skill' => $skill]);
    return $skill;
}

function sm_skill_format($format) {
    $f = strtoupper((string) $format);
    return $f === 'ODI' ? 'ODI' : ($f === 'TEST' ? 'TEST' : 'T20');
}

/** Pure: skill from career rows (see sm_player_skill). */
function sm_skill_from_career(array $career, $fmt = 'T20') {
    $want = ['T20' => ['t20', 't20i'], 'ODI' => ['odi', 'list a', 'list-a'], 'TEST' => ['test', 'test/5day', '4day', 'first class']][$fmt] ?? ['t20', 't20i'];
    $bm = 0; $runs = 0; $balls = 0; $fours = 0; $sixes = 0; $wm = 0; $wk = 0; $overs = 0.0; $conceded = 0;
    foreach (sm_list($career) as $row) {
        if (!in_array(strtolower((string) ($row['type'] ?? '')), $want, true)) continue;
        $b = (array) ($row['batting'] ?? []); $w = (array) ($row['bowling'] ?? []);
        $bm += (int) ($b['matches'] ?? 0); $runs += (int) ($b['runs_scored'] ?? 0); $balls += (int) ($b['balls_faced'] ?? 0);
        $fours += (int) ($b['four_x'] ?? 0); $sixes += (int) ($b['six_x'] ?? 0);
        $wm += (int) ($w['matches'] ?? 0); $wk += (int) ($w['wickets'] ?? 0);
        $ov = (float) ($w['overs'] ?? 0); $overs += floor($ov) + round(($ov - floor($ov)) * 10) / 6; $conceded += (int) ($w['runs'] ?? 0);
    }
    $m = max($bm, $wm);
    if ($m < 5) return null;
    $pts = 4 + 3;                                                   // in the XI + an average share of fielding points
    $pts += ($runs + $fours + 2 * $sixes) / $m;                     // runs and boundary bonuses
    if ($balls > 0 && $runs / $m >= 10) $pts += max(-4, min(6, (100 * $runs / $balls - 130) / 8));   // strike rate
    $pts += 28 * $wk / $m;                                          // wickets, incl. an average bowled/lbw bonus
    if ($overs > 0 && $overs / $m >= 2) $pts += max(-4, min(4, (8.5 - $conceded / $overs) * 1.5));   // economy
    // credits = 5.5 + 5*skill (fantasy_feed_credits) should equal 6 + pts/12 (the history formula).
    return round((0.5 + $pts / 12) / 5, 4);
}

/**
 * Re-price the credits of upcoming Your 11 fixtures from career form, before anyone has built a team on
 * them (a price never changes under an existing team). At most $budget uncached player lookups per call,
 * so a long fixture list is priced over a few runs. Returns players re-priced.
 */
function sm_reprice_credits($budget = 120) {
    if (!function_exists('fantasy_feed_credits')) return 0;
    $done = 0;
    $matches = all('SELECT m."id", m."format" FROM "fantasy_matches" m WHERE m."feed_key" LIKE ? AND m."status" = ? AND m."squads_ready" = 1 '
                 . 'AND NOT EXISTS (SELECT 1 FROM "fantasy_user_teams" t WHERE t."match_id" = m."id") ORDER BY m."start_time" ASC', ['sm\_%', 'UPCOMING']);
    foreach ($matches as $m) {
        if (state_get('sm_priced_' . $m['id'])) continue;
        $players = all('SELECT "id","external_key","role","credits" FROM "fantasy_players" WHERE "match_id" = ?', [(int) $m['id']]);
        // 1. Every player's form, within the lookup budget. A lookup that fails (nothing cached) leaves the
        //    whole match for the next run rather than pricing it from half the information.
        $complete = true; $skills = [];
        foreach ($players as $p) {
            if (!preg_match('/^smp_(\d+)$/', $p['external_key'], $mm)) { $skills[$p['id']] = null; continue; }
            $ck = 'sm_skill_' . (int) $mm[1] . '_' . sm_skill_format($m['format']);
            if (!is_array(state_get($ck))) {
                if ($budget <= 0) { $complete = false; break; }
                $budget--;
            }
            $skills[$p['id']] = sm_player_skill((int) $mm[1], $m['format']);
            if (!is_array(state_get($ck))) $complete = false;
        }
        if (!$complete) break;
        // 2. Raw credits, then one shift for the whole squad so the likely XI (the 22 dearest) averages
        //    about 9.3, so a team of the obvious stars costs more than 100 and choices have to be made, as on Dream11.
        $raw = [];
        foreach ($players as $p) {
            $sk = $skills[$p['id']];
            $raw[$p['id']] = $sk !== null ? 5.5 + 5.0 * $sk : (float) fantasy_feed_credits($p['external_key'], $p['role'], null, $m['format']);
        }
        $top = $raw; rsort($top); $top = array_slice($top, 0, 22);
        $shift = $top ? max(0.0, array_sum($top) / count($top) - 9.3) : 0.0;
        foreach ($players as $p) {
            $c = fantasy_clamp_credits(round(($raw[$p['id']] - $shift) * 2) / 2);
            if (abs($c - (float) $p['credits']) > 0.01) { q('UPDATE "fantasy_players" SET "credits" = ? WHERE "id" = ?', [$c, (int) $p['id']]); $done++; }
        }
        state_set('sm_priced_' . $m['id'], ['ms' => now_ms(), 'shift' => round($shift, 2)]);
    }
    return $done;
}

/** ICC team rankings (team id -> ["T20I|men" => rating, ...]), cached 12 hours. */
function sm_rankings() {
    $c = state_get('sm_rankings');
    if (is_array($c) && now_ms() - (int) ($c['ms'] ?? 0) < 12 * 3600000) return (array) $c['by_team'];
    $res = sm_get('/team-rankings');
    if (!$res['ok']) return is_array($c) ? (array) $c['by_team'] : [];
    $by = [];
    foreach (sm_list($res['data']['data']) as $list) {
        $type = strtoupper((string) ($list['type'] ?? '')) . '|' . strtolower((string) ($list['gender'] ?? ''));
        foreach (sm_list($list['team'] ?? []) as $t) {
            $r = cricket_pick($t, ['ranking.rating'], null);
            if ($r !== null && !empty($t['id'])) $by[(int) $t['id']][$type] = (float) $r;
        }
    }
    state_set('sm_rankings', ['ms' => now_ms(), 'by_team' => $by]);
    return $by;
}

/** A season's standings (team id -> row), cached 30 minutes. */
function sm_standings($seasonId) {
    $seasonId = (int) $seasonId;
    if ($seasonId <= 0) return [];
    $c = state_get('sm_standings_' . $seasonId);
    if (is_array($c) && now_ms() - (int) ($c['ms'] ?? 0) < 1800000) return (array) $c['by_team'];
    $res = sm_get('/standings/season/' . $seasonId);
    $by = [];
    if ($res['ok']) foreach (sm_list($res['data']['data']) as $row) if (!empty($row['team_id'])) $by[(int) $row['team_id']] = $row;
    state_set('sm_standings_' . $seasonId, ['ms' => now_ms(), 'by_team' => $by]);
    return $by;
}

/**
 * Pure: the chance team A wins before a ball is bowled, or null when there is nothing sound to go on.
 *   - both teams ICC-ranked in the same list (internationals): logistic in the rating gap, 40 rating
 *     points = 1 in log-odds (269 v 229 ~ 73%), capped 25-75% because T20 is volatile;
 *   - else both in the season's standings: win rate and net run rate, capped 35-65% (40-60% under 2 games);
 *   - else an even match (0.5).
 */
function sm_prematch_prob($teamA, $teamB, $format, array $rankings, array $standings) {
    $list = ['ODI' => 'ODI', 'TEST' => 'TEST'][strtoupper((string) $format)] ?? 'T20I';
    foreach (['men', 'women'] as $g) {
        $ra = $rankings[(int) $teamA][$list . '|' . $g] ?? null; $rb = $rankings[(int) $teamB][$list . '|' . $g] ?? null;
        if ($ra !== null && $rb !== null && $ra > 0 && $rb > 0) return max(0.25, min(0.75, 1 / (1 + exp(-($ra - $rb) / 40))));
    }
    $a = $standings[(int) $teamA] ?? null; $b = $standings[(int) $teamB] ?? null;
    if ($a && $b && (int) $a['played'] >= 1 && (int) $b['played'] >= 1) {
        $wr = function ($r) { $p = max(1, (int) $r['played'] - (int) ($r['noresult'] ?? 0)); return ((int) $r['won'] + 0.5 * (int) ($r['draw'] ?? 0)) / $p; };
        $x = 2.0 * ($wr($a) - $wr($b)) + 0.25 * ((float) ($a['netto_run_rate'] ?? 0) - (float) ($b['netto_run_rate'] ?? 0));
        // A table with only a game or two each says less: the cap tightens until both have played twice.
        $cap = ((int) $a['played'] >= 2 && (int) $b['played'] >= 2) ? 0.65 : 0.60;
        return max(1 - $cap, min($cap, 1 / (1 + exp(-$x))));
    }
    // Nothing to go on: an even match (both ~1.96 after margin), so every fixture can be bet pre-match;
    // live pricing takes over from the first ball.
    return 0.5;
}

/**
 * Give every upcoming Sportmonks fixture a pre-match favourite price from rankings / standings, so Match
 * Odds and Bookmaker open before the toss instead of waiting for the first ball. Never touches a price an
 * operator set, and never re-prices once play has started. Returns fixtures priced.
 */
function sm_sync_prematch_prices($nowMs = null) {
    if (!function_exists('mx_settings_save')) return 0;
    $now = $nowMs ?? now_ms();
    $fx = sm_fixtures($now);
    if (!$fx['ok']) return 0;
    $rankings = sm_rankings();
    $n = 0;
    foreach ($fx['fixtures'] as $f) {
        if ($f['status'] !== 'not_started' || !$f['start_ms'] || $f['start_ms'] <= $now) continue;
        $row = one('SELECT "source","fav_side","fav_price" FROM "mx_match_settings" WHERE "match_key" = ?', [$f['key']]);
        if ($row && $row['source'] === 'operator') continue;
        $ta = (int) preg_replace('/^smt_/', '', $f['teams']['a']['key']); $tb = (int) preg_replace('/^smt_/', '', $f['teams']['b']['key']);
        $p = sm_prematch_prob($ta, $tb, $f['format'], $rankings, sm_standings($f['tournament_key']));
        if ($p === null) continue;
        $fav = $p >= 0.5 ? 'a' : 'b';
        $price = round(1 / max($p, 1 - $p), 2);
        if ($row && $row['fav_side'] === $fav && abs((float) $row['fav_price'] - $price) < 0.015) continue;
        cricket_feed_placeholder($f);
        mx_settings_save($f['key'], $fav, $price, 'sportmonks');
        $n++;
    }
    return $n;
}
