<?php
/**
 * Roanuz Cricket API client — the only file that talks to Roanuz over the network.
 *
 * Endpoints used, and why these and no others (cost is per match, so every call is deliberate):
 *
 *   POST {core}/{project}/auth/                          free  — exchange the API key for a token
 *   GET  {cricket}/{project}/featured-tournaments/        free  — what is on, when no list is configured
 *   GET  {cricket}/{project}/tournament/{t}/fixtures/     free  — the schedule
 *   GET  {cricket}/{project}/tournament/{t}/team/{team}/  free  — a squad
 *   POST {cricket}/{project}/match/{m}/subscribe/         this is what starts the ₹200 Match Via Push
 *   GET  {cricket}/{project}/match/{m}/                   one-off resync of a single match (₹100 tier)
 *
 * Paths and the rs-token header follow Roanuz's v5 docs (core/auth confirmed 2026-08-24 for the
 * Node build). In mock mode every call is answered by cricket-mock.php in the same shape, so the
 * calling code is exercised identically whether or not a key exists.
 */

require_once __DIR__ . '/cricket-feed.php';

function roanuz_conf() {
    return [
        'api_key'     => trim((string) env_get('ROANUZ_API_KEY', '')),
        'project_key' => trim((string) env_get('ROANUZ_PROJECT_KEY', '')),
        'core_base'   => rtrim((string) env_get('ROANUZ_AUTH_BASE_URL', 'https://api.sports.roanuz.com/v5/core'), '/'),
        'base'        => rtrim((string) env_get('ROANUZ_BASE_URL', 'https://api.sports.roanuz.com/v5/cricket'), '/'),
        // Comma-separated tournament keys to cover. Empty = whatever Roanuz features right now.
        'tournaments' => array_values(array_filter(array_map('trim', explode(',', (string) env_get('ROANUZ_TOURNAMENTS', ''))))),
        'webhook_secret' => (string) env_get('ROANUZ_WEBHOOK_SECRET', ''),
    ];
}

/** One HTTP call. Returns ['ok', 'status', 'data', 'error']; never throws. */
function roanuz_http($method, $url, $body = null, array $headers = []) {
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl extension unavailable'];
    $ch = curl_init($url);
    $h = ['Accept: application/json', 'Accept-Encoding: gzip'];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING => '', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $err ?: 'request failed'];
    $data = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => 'HTTP ' . $status . ' ' . substr((string) $raw, 0, 200)];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
}

/** A cached access token, refreshed a few minutes before Roanuz says it expires. */
function roanuz_token($forceRefresh = false) {
    $c = roanuz_conf();
    if ($c['api_key'] === '' || $c['project_key'] === '') return null;
    $cached = state_get('roanuz_token');
    if (!$forceRefresh && is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_ms'] ?? 0) > now_ms() + 300000) {
        return $cached['token'];
    }
    $res = roanuz_http('POST', $c['core_base'] . '/' . rawurlencode($c['project_key']) . '/auth/', ['api_key' => $c['api_key']]);
    if (!$res['ok']) { log_error('roanuz: auth failed', ['error' => $res['error']]); return null; }
    $token = cricket_pick($res['data'], ['data.token', 'token'], null);
    $exp = cricket_pick($res['data'], ['data.expires', 'expires'], null);
    if (!$token) return null;
    $expMs = $exp ? ((int) $exp) * ((int) $exp > 100000000000 ? 1 : 1000) : now_ms() + 3600000;
    state_set('roanuz_token', ['token' => $token, 'expires_ms' => $expMs]);
    return $token;
}

/** Authenticated call against the cricket API. Retries once on an expired token. */
function roanuz_call($method, $path, $body = null) {
    $c = roanuz_conf();
    $token = roanuz_token();
    if (!$token) return ['ok' => false, 'status' => 401, 'data' => null, 'error' => 'no Roanuz token (check ROANUZ_API_KEY / ROANUZ_PROJECT_KEY)'];
    $url = $c['base'] . '/' . rawurlencode($c['project_key']) . $path;
    $res = roanuz_http($method, $url, $body, ['rs-token' => $token]);
    if (!$res['ok'] && in_array($res['status'], [401, 403], true)) {
        $token = roanuz_token(true);
        if ($token) $res = roanuz_http($method, $url, $body, ['rs-token' => $token]);
    }
    return $res;
}

// -------------------------------------------------------------------------------------------------
// The four things the games need
// -------------------------------------------------------------------------------------------------

/**
 * Upcoming and live fixtures, normalised:
 * [['key','name','short_name','format','start_ms','status','teams'=>['a'=>..,'b'=>..],'venue','tournament_key','tournament_name']]
 */
function roanuz_fixtures() {
    if (cricket_source_mode() === 'mock') {
        return ['ok' => true, 'fixtures' => array_map('roanuz_normalise_fixture', array_map(function ($f) {
            return $f + ['_tournament' => $f['tournament']];
        }, cricket_mock_fixtures()))];
    }
    $c = roanuz_conf();
    $tournaments = $c['tournaments'];
    if (!$tournaments) {
        $res = roanuz_call('GET', '/featured-tournaments/');
        if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'fixtures' => []];
        foreach ((array) cricket_pick($res['data'], ['data.tournaments', 'data'], []) as $t) {
            $k = cricket_pick($t, ['key'], null);
            if ($k) $tournaments[] = (string) $k;
        }
    }
    $out = [];
    $errors = [];
    foreach (array_slice($tournaments, 0, 10) as $tk) {
        $res = roanuz_call('GET', '/tournament/' . rawurlencode($tk) . '/fixtures/');
        if (!$res['ok']) { $errors[] = $tk . ': ' . $res['error']; continue; }
        $tname = (string) cricket_pick($res['data'], ['data.tournament.name'], $tk);
        foreach ((array) cricket_pick($res['data'], ['data.matches', 'data.fixtures', 'matches'], []) as $m) {
            if (!is_array($m)) continue;
            $m['_tournament'] = ['key' => $tk, 'name' => $tname];
            $out[] = roanuz_normalise_fixture($m);
        }
    }
    return ['ok' => $out || !$errors, 'error' => $errors ? implode('; ', $errors) : null, 'fixtures' => $out];
}

function roanuz_normalise_fixture(array $m) {
    $start = cricket_pick($m, ['start_at', 'start_date.timestamp'], null);
    $fmt = strtoupper((string) cricket_pick($m, ['format'], 'T20'));
    return [
        'key'        => (string) cricket_pick($m, ['key'], ''),
        'name'       => (string) cricket_pick($m, ['name', 'title'], ''),
        'short_name' => (string) cricket_pick($m, ['short_name'], ''),
        'format'     => fantasy_format_key_light($fmt),
        'start_ms'   => $start !== null ? ((int) $start) * ((int) $start > 100000000000 ? 1 : 1000) : null,
        'status'     => (string) cricket_pick($m, ['status'], 'not_started'),
        'teams'      => [
            'a' => ['key' => (string) cricket_pick($m, ['teams.a.key'], ''), 'name' => (string) cricket_pick($m, ['teams.a.name'], 'Team A'), 'code' => (string) cricket_pick($m, ['teams.a.code', 'teams.a.short_name'], 'A')],
            'b' => ['key' => (string) cricket_pick($m, ['teams.b.key'], ''), 'name' => (string) cricket_pick($m, ['teams.b.name'], 'Team B'), 'code' => (string) cricket_pick($m, ['teams.b.code', 'teams.b.short_name'], 'B')],
        ],
        'venue'      => (string) cricket_pick($m, ['venue.name', 'venue'], ''),
        'tournament_key'  => (string) cricket_pick($m, ['_tournament.key', 'tournament.key'], ''),
        'tournament_name' => (string) cricket_pick($m, ['_tournament.name', 'tournament.name'], ''),
    ];
}

function fantasy_format_key_light($fmt) {
    $f = strtoupper((string) $fmt);
    if (strpos($f, 'TEST') !== false) return 'TEST';
    if (strpos($f, 'ODI') !== false || strpos($f, 'ONE') !== false) return 'ODI';
    if (strpos($f, 'T10') !== false) return 'T10';
    if (strpos($f, 'HUNDRED') !== false || $f === '100_BALL' || $f === 'T100') return 'HUNDRED';
    return 'T20';
}

/**
 * One team's squad for a tournament: [['key','name','role','skill'?], ...].
 */
function roanuz_team_squad($tournamentKey, $teamKey) {
    if (cricket_source_mode() === 'mock') {
        $data = ['data' => cricket_mock_tournament_team($teamKey)];
    } else {
        $res = roanuz_call('GET', '/tournament/' . rawurlencode($tournamentKey) . '/team/' . rawurlencode($teamKey) . '/');
        if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'players' => []];
        $data = $res['data'];
    }
    $players = [];
    $list = cricket_pick($data, ['data.tournament_team.players', 'data.players', 'data.team.players', 'players'], []);
    foreach ((array) $list as $k => $p) {
        if (!is_array($p)) continue;
        $info = isset($p['player']) && is_array($p['player']) ? $p['player'] : $p;
        $key = (string) cricket_pick($info, ['key', 'player_key'], is_string($k) ? $k : '');
        if ($key === '') continue;
        $players[] = [
            'key'   => $key,
            'name'  => (string) cricket_pick($info, ['name', 'jersey_name', 'legal_name'], $key),
            'role'  => cricket_role(cricket_pick($info, ['seasonal_role', 'role', 'playing_role'], '')),
            'skill' => isset($info['skill']) ? (float) $info['skill'] : null,
        ];
    }
    return ['ok' => true, 'error' => null, 'players' => $players];
}

/** Ask Roanuz to start pushing a match to our webhook. Mock: nothing to do. */
function roanuz_subscribe($matchKey) {
    if (cricket_source_mode() === 'mock') return ['ok' => true, 'mock' => true];
    $already = state_get('roanuz_sub_' . $matchKey);
    if (is_array($already) && !empty($already['ok'])) return ['ok' => true, 'already' => true];
    $res = roanuz_call('POST', '/match/' . rawurlencode($matchKey) . '/subscribe/', ['method' => 'web_hook']);
    if ($res['ok']) state_set('roanuz_sub_' . $matchKey, ['ok' => true, 'at' => now_ms()]);
    else log_error('roanuz: subscribe failed', ['match' => $matchKey, 'error' => $res['error']]);
    return ['ok' => $res['ok'], 'error' => $res['error']];
}

/** A full snapshot of one match by REST — the manual resync path. */
function roanuz_match_snapshot($matchKey) {
    if (cricket_source_mode() === 'mock') {
        $s = cricket_mock_snapshot($matchKey);
        return $s ? ['ok' => true, 'snapshot' => $s] : ['ok' => false, 'error' => 'not a mock match'];
    }
    $res = roanuz_call('GET', '/match/' . rawurlencode($matchKey) . '/');
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];
    return ['ok' => true, 'snapshot' => $res['data']];
}

/**
 * Decode a webhook body. Roanuz's own example handler gunzips before parsing, so a gzip body is
 * detected by its magic bytes (not assumed), and a plain JSON body still works.
 */
function roanuz_decode_webhook_body($raw) {
    $raw = (string) $raw;
    if (strlen($raw) >= 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b) {
        $un = @gzdecode($raw);
        if ($un !== false) $raw = $un;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
