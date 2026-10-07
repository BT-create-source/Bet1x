<?php
/**
 * Free Roanuz connection check — proves the keys work before a single rupee is spent.
 *
 *     php -d extension=pdo_pgsql -d extension=curl php-backend/tools/roanuz-check.php
 *
 * Reads ROANUZ_API_KEY and ROANUZ_PROJECT_KEY from php-backend/.env (put them in your LOCAL .env, not
 * the server's), then calls ONLY Roanuz's free endpoints:
 *
 *   1. auth                       — are the keys valid?
 *   2. featured tournaments       — what Roanuz has on right now
 *   3. fixtures of a tournament   — the schedule your lobbies would show
 *   4. one team's squad           — players and roles, which Your 11 builds teams from
 *
 * It never calls /subscribe/ (the paid Match Via Push) or /match/ (the paid REST pull) — a guard below
 * refuses them outright. The raw responses are saved, with the token redacted, to a folder printed at
 * the end, so the field mapping in lib/cricket-feed.php can be checked against Roanuz's real shapes.
 * Keys and tokens are never printed.
 */
if (PHP_SAPI !== 'cli') exit(1);
putenv('CRICKET_SOURCE=roanuz');          // talk to the real API, not the simulator
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-roanuz'] as $f) require_once "$root/lib/$f.php";

$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roanuz-check-' . gmdate('Ymd-His');
@mkdir($out, 0775, true);
$ok = true;
function step($n, $title) { echo "\n[$n] $title\n"; }
function good($m) { echo "    OK    $m\n"; }
function bad($m) { global $ok; $ok = false; echo "    FAIL  $m\n"; }
function save($name, $data) {
    global $out;
    $j = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $j = preg_replace('/"(token|api_key|rs-token)"\s*:\s*"[^"]*"/', '"$1": "<redacted>"', (string) $j);
    file_put_contents($out . DIRECTORY_SEPARATOR . $name . '.json', $j);
}
/** Free endpoints only. Anything that would start billing is refused before it leaves this machine. */
function free_call($path) {
    if (preg_match('#/(subscribe|match)/#', $path)) { bad("refused to call a paid endpoint: $path"); return ['ok' => false]; }
    return roanuz_call('GET', $path);
}

$c = roanuz_conf();
step(1, 'Keys and sign-in');
if ($c['api_key'] === '' || $c['project_key'] === '') {
    bad('ROANUZ_API_KEY and/or ROANUZ_PROJECT_KEY are empty in php-backend/.env');
    exit(1);
}
good('both keys are set (project key ' . substr($c['project_key'], 0, 6) . '…)');
state_set('roanuz_token', null);   // force a fresh sign-in with these keys
$auth = roanuz_http('POST', $c['core_base'] . '/' . rawurlencode($c['project_key']) . '/auth/', ['api_key' => $c['api_key']]);
save('1-auth', $auth['data']);
$token = $auth['ok'] ? cricket_pick($auth['data'], ['data.token', 'token'], null) : null;
if (!$token) {
    bad('sign-in refused: ' . ($auth['error'] ?? 'no token in the reply'));
    echo "\n    Check the keys are copied exactly, the email is verified, and a plan (the free Standard licence) is active.\n";
    exit(1);
}
good('Roanuz accepted the keys and issued a token');
roanuz_token(true);   // cache it the way the site does

step(2, 'Featured tournaments (free)');
$tour = free_call('/featured-tournaments/');
save('2-featured-tournaments', $tour['data'] ?? null);
$tlist = $tour['ok'] ? (array) cricket_pick($tour['data'], ['data.tournaments', 'data'], []) : [];
if (!$tour['ok']) bad('could not list tournaments: ' . ($tour['error'] ?? ''));
elseif (!$tlist) bad('Roanuz returned no featured tournaments');
else {
    good(count($tlist) . ' tournaments');
    foreach (array_slice($tlist, 0, 12) as $t) echo '          - ' . cricket_pick($t, ['name'], '?') . '   [' . cricket_pick($t, ['key'], '?') . "]\n";
}

step(3, 'Fixtures (free)');
$picked = null; $fixtures = [];
foreach (array_slice($tlist, 0, 4) as $t) {
    $tk = (string) cricket_pick($t, ['key'], '');
    if ($tk === '') continue;
    $fx = free_call('/tournament/' . rawurlencode($tk) . '/fixtures/');
    save('3-fixtures-' . preg_replace('/\W+/', '_', $tk), $fx['data'] ?? null);
    $ms = $fx['ok'] ? (array) cricket_pick($fx['data'], ['data.matches', 'data.fixtures', 'matches'], []) : [];
    if ($ms) { $picked = $t; $fixtures = $ms; break; }
}
if (!$fixtures) bad('no fixtures found in the first few tournaments');
else {
    good(count($fixtures) . ' fixtures in ' . cricket_pick($picked, ['name'], '?'));
    $upcoming = array_values(array_filter($fixtures, function ($m) { return (string) cricket_pick($m, ['status'], '') !== 'completed'; }));
    foreach (array_slice($upcoming ?: $fixtures, 0, 6) as $m) {
        $n = roanuz_normalise_fixture($m);
        echo sprintf("          - %-48s %-5s %s  [%s]\n", $n['name'] ?: ($n['teams']['a']['name'] . ' vs ' . $n['teams']['b']['name']), $n['format'],
                     $n['start_ms'] ? gmdate('d M H:i', (int) ($n['start_ms'] / 1000)) . ' UTC' : '?', $n['status']);
    }
    $n0 = roanuz_normalise_fixture($fixtures[0]);
    if ($n0['key'] === '' || $n0['teams']['a']['key'] === '' || !$n0['start_ms']) bad('a fixture is missing its key, team keys or start time — the field mapping needs adjusting (see saved files)');
    else good('fixtures parse into what the lobbies need (match key, teams, start time, format)');
}

step(4, 'One squad (free)');
if ($fixtures) {
    $n0 = roanuz_normalise_fixture($fixtures[0]);
    $sq = free_call('/tournament/' . rawurlencode((string) cricket_pick($picked, ['key'], '')) . '/team/' . rawurlencode($n0['teams']['a']['key']) . '/');
    save('4-squad', $sq['data'] ?? null);
    $parsed = roanuz_team_squad((string) cricket_pick($picked, ['key'], ''), $n0['teams']['a']['key']);
    if (!$parsed['ok'] || !$parsed['players']) bad('squad for ' . $n0['teams']['a']['name'] . ' did not parse: ' . ($parsed['error'] ?? 'no players found'));
    else {
        $roles = array_count_values(array_column($parsed['players'], 'role'));
        good(count($parsed['players']) . ' players for ' . $n0['teams']['a']['name'] . ' — roles ' . json_encode($roles));
        foreach (array_slice($parsed['players'], 0, 5) as $p) echo "          - {$p['name']} ({$p['role']})\n";
        if (count($roles) < 3) bad('fewer than 3 distinct roles came through — role mapping needs checking (see saved files)');
    }
}

echo "\n" . ($ok ? 'ALL FREE CHECKS PASSED — the keys work and the free data parses. Nothing was billed.'
                 : 'SOME CHECKS FAILED — see above. Nothing was billed.') . "\n";
echo "Raw replies (token redacted) saved in:\n  $out\n";
exit($ok ? 0 : 1);
