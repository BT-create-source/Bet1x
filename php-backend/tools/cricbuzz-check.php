<?php
/**
 * Cricbuzz API (via API.market) trial check — spends about 8 units, saves every raw reply.
 *
 *     php -d extension=curl php-backend/tools/cricbuzz-check.php [match_id]
 *
 * Put the key in your LOCAL php-backend/.env as  CRICBUZZ_API_KEY=...  (never the server's, never in
 * chat). The API documents every reply only as "an object", so this exists to see the real shapes —
 * above all what one ball looks like (runs, extras, wicket type, fielders) — before a connector is
 * written against them. Calls, in order:
 *
 *   /health                         (free)   the service is up
 *   /matches/live                   (1 unit)  what is being played now
 *   /matches/upcoming               (1 unit)  the schedule
 *   then for one match (the id you pass, else the first live one, else the most recent):
 *   /matches/get-info               (1)       toss, venue, status
 *   /matches/get-team               (1)       playing XI / squads
 *   /matches/get-ball-by-ball       (1)       every delivery — the key one
 *   /matches/get-commentaries       (1)       the commentary stream
 *   /matches/get-scorecard          (1)       batting/bowling cards, to cross-check points
 *   /matches/recent                 (1, only if nothing is live and no id was given)
 *
 * Replies go to a folder printed at the end; the key is never printed or saved.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/config.php';

$key = trim((string) env_get('CRICBUZZ_API_KEY', ''));
$base = rtrim((string) env_get('CRICBUZZ_BASE_URL', 'https://prod.api.market/api/v1/veer-hanuman-1/cricbuzz'), '/');
if ($key === '') { fwrite(STDERR, "CRICBUZZ_API_KEY is empty in php-backend/.env\n"); exit(1); }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP curl extension is missing (run with -d extension=curl)\n"); exit(1); }

$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cricbuzz-check-' . gmdate('Ymd-His');
@mkdir($out, 0775, true);
$used = 0;

function cb_get($path, array $q = []) {
    global $key, $base, $used;
    $url = $base . $path . ($q ? '?' . http_build_query($q) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'x-api-market-key: ' . $key]]);
    if ($ca = cb_ca_bundle()) curl_setopt($ch, CURLOPT_CAINFO, $ca);   // verify TLS; never switched off
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($path !== '/health' && $status > 0) $used++;   // a request that never reached the server costs nothing
    return ['status' => $status, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'raw' => $raw === false ? '' : $raw, 'error' => $err,
            'data' => $raw === false ? null : json_decode($raw, true)];
}
/**
 * Windows PHP builds often ship without a list of trusted certificates, so HTTPS fails with "unable to
 * get local issuer certificate". Use php.ini's curl.cainfo if set, else CA_BUNDLE from .env, else the
 * bundle that Git for Windows installs. Verification stays on in every case.
 */
function cb_ca_bundle() {
    if (ini_get('curl.cainfo')) return null;
    foreach ([(string) env_get('CA_BUNDLE', ''), 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
              'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt'] as $f) if ($f !== '' && is_file($f)) return $f;
    return null;
}
function cb_save($name, $r) {
    global $out;
    file_put_contents($out . DIRECTORY_SEPARATOR . $name . '.json', $r['data'] !== null
        ? json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['raw']);
}
function cb_report($label, $r) {
    $ok = $r['status'] >= 200 && $r['status'] < 300 && $r['data'] !== null;
    $size = strlen($r['raw']);
    $keys = is_array($r['data']) ? implode(', ', array_slice(array_keys($r['data']), 0, 8)) : '';
    echo sprintf("  %-5s %-26s HTTP %d  %5d ms  %6d bytes  %s\n", $ok ? 'OK' : 'FAIL', $label, $r['status'], $r['ms'], $size,
                 $ok ? "top-level: $keys" : ($r['error'] ?: substr($r['raw'], 0, 160)));
    return $ok;
}
/** Find the first value under any of the given key names, anywhere in a nested reply. */
function cb_find($data, array $names) {
    if (!is_array($data)) return null;
    foreach ($data as $k => $v) {
        if (in_array((string) $k, $names, true) && (is_int($v) || (is_string($v) && ctype_digit($v)))) return (int) $v;
        $f = cb_find($v, $names);
        if ($f) return $f;
    }
    return null;
}

echo "Cricbuzz API trial check\n\n";
// Balls-only mode (2 units):  cricbuzz-check.php <match_id> balls  — a quick look at live deliveries.
if (isset($argv[1], $argv[2]) && $argv[2] === 'balls') {
    $mid = (int) $argv[1];
    foreach (['get-ball-by-ball' => '6-ball-by-ball', 'get-scorecard' => '8-scorecard'] as $ep => $file) {
        $r = cb_get('/matches/' . $ep, ['match_id' => $mid]); cb_save($file, $r); cb_report('matches/' . $ep, $r);
    }
    echo "\nUnits used: about $used\nRaw replies saved in:\n  $out\n";
    exit(0);
}
$h = cb_get('/health'); cb_save('0-health', $h); cb_report('health', $h);
$live = cb_get('/matches/live'); cb_save('1-live', $live); $liveOk = cb_report('matches/live', $live);
$up = cb_get('/matches/upcoming'); cb_save('2-upcoming', $up); cb_report('matches/upcoming', $up);
if (!$liveOk && $live['status'] >= 400) { echo "\nThe key was refused or the plan has no units — check the API.market dashboard.\n"; }

$mid = isset($argv[1]) ? (int) $argv[1] : cb_find($live['data'], ['matchId', 'match_id', 'id']);
$src = $mid ? (isset($argv[1]) ? 'given on the command line' : 'first live match') : null;
if (!$mid) {
    $rec = cb_get('/matches/recent'); cb_save('3-recent', $rec); cb_report('matches/recent', $rec);
    $mid = cb_find($rec['data'], ['matchId', 'match_id', 'id']);
    $src = 'most recent match';
}
if (!$mid) { echo "\nNo match id found in the replies — send me the saved files and I will read the shape.\n"; }
else {
    echo "\nMatch $mid ($src):\n";
    foreach (['get-info' => '4-info', 'get-team' => '5-team', 'get-ball-by-ball' => '6-ball-by-ball',
              'get-commentaries' => '7-commentaries', 'get-scorecard' => '8-scorecard'] as $ep => $file) {
        $r = cb_get('/matches/' . $ep, ['match_id' => $mid]);
        cb_save($file, $r);
        cb_report('matches/' . $ep, $r);
    }
}
echo "\nUnits used: about $used (check the exact count on your API.market dashboard).\n";
echo "Raw replies saved in:\n  $out\n";
echo "Tell me that folder path (or zip it) so I can read the real field shapes.\n";
