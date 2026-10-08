<?php
/**
 * Sportmonks Cricket API trial check — which leagues the plan covers, and what a live ball looks like.
 *
 *     php -d extension=curl php-backend/tools/sportmonks-check.php [fixture_id]
 *
 * Put the trial token in your LOCAL php-backend/.env as  SPORTMONKS_API_TOKEN=...  (never the server's,
 * never in chat). Sportmonks bills by plan, not per call (limit: 2,000 calls/hour per endpoint), so a
 * handful of calls costs nothing. Calls:
 *
 *   /leagues                         every league this plan can see -> answers "is IPL / SMAT / WPL in?"
 *   /livescores?include=...          what is live now, with teams, lineups and balls
 *   /fixtures/{id}?include=...       one match in full: balls, lineup, batting, bowling, toss, venue
 *                                    (the id you pass, else the first live match, else the latest IPL match)
 *
 * Raw replies are saved to a folder printed at the end; the token is never printed or saved.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/config.php';

$token = trim((string) env_get('SPORTMONKS_API_TOKEN', ''));
$base = 'https://cricket.sportmonks.com/api/v2.0';
if ($token === '') { fwrite(STDERR, "SPORTMONKS_API_TOKEN is empty in php-backend/.env\n"); exit(1); }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP curl extension is missing (run with -d extension=curl)\n"); exit(1); }
$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sportmonks-check-' . gmdate('Ymd-His');
@mkdir($out, 0775, true);

/** Windows PHP often lacks a CA list; use php.ini's, else CA_BUNDLE, else Git for Windows' bundle. Verification stays on. */
function sm_ca() {
    if (ini_get('curl.cainfo')) return null;
    foreach ([(string) env_get('CA_BUNDLE', ''), 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
              'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt'] as $f) if ($f !== '' && is_file($f)) return $f;
    return null;
}
function sm_get($path, array $q = []) {
    global $token, $base;
    $q['api_token'] = $token;
    $ch = curl_init($base . $path . '?' . http_build_query($q));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    if ($ca = sm_ca()) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $raw = $raw === false ? '' : str_replace($token, '<token>', $raw);
    return ['status' => $status, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'raw' => $raw, 'error' => $err, 'data' => json_decode($raw, true)];
}
function sm_save($name, $r) {
    global $out;
    file_put_contents($out . DIRECTORY_SEPARATOR . $name . '.json', $r['data'] !== null
        ? json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['raw']);
}
function sm_report($label, $r) {
    $ok = $r['status'] >= 200 && $r['status'] < 300 && is_array($r['data']);
    $msg = $ok ? '' : ($r['error'] ?: (is_array($r['data']) ? json_encode($r['data']['error'] ?? $r['data']) : substr($r['raw'], 0, 200)));
    echo sprintf("  %-5s %-24s HTTP %d  %5d ms  %7d bytes  %s\n", $ok ? 'OK' : 'FAIL', $label, $r['status'], $r['ms'], strlen($r['raw']), $msg);
    return $ok;
}

echo "Sportmonks Cricket API trial check\n\n";
$inc = 'localteam,visitorteam,league,venue,tosswon,lineup,runs,balls,batting,bowling';

// 1. Which leagues does this plan see?
$lg = sm_get('/leagues'); sm_save('1-leagues', $lg);
if (!sm_report('leagues', $lg)) { echo "\nThe token was refused — check it is copied exactly and the trial is active.\n"; exit(1); }
$leagues = $lg['data']['data'] ?? [];
echo "\n  This plan covers " . count($leagues) . " leagues. Indian and key ones:\n";
$want = ['indian premier', 'ipl', "women's premier", 'syed mushtaq', 'vijay hazare', 'ranji', 'duleep', 'deodhar',
         'tamil nadu', 'karnataka', 'maharaja', 'mumbai', 'bengal', 'andhra', 'odisha', 'jharkhand', 'delhi', 'world cup', 'india'];
$found = 0;
foreach ($leagues as $l) {
    $n = strtolower((string) ($l['name'] ?? ''));
    foreach ($want as $w) if (strpos($n, $w) !== false) { echo "    - {$l['name']}  [id {$l['id']}]\n"; $found++; break; }
}
if (!$found) echo "    (none of IPL / WPL / SMAT / Ranji / state leagues is in this plan)\n";
$ipl = null; foreach ($leagues as $l) if (stripos((string) $l['name'], 'indian premier') !== false) $ipl = (int) $l['id'];
echo "  All leagues saved in 1-leagues.json\n\n";

// 2. What is live?
$live = sm_get('/livescores', ['include' => $inc]); sm_save('2-livescores', $live); sm_report('livescores', $live);
$liveList = $live['data']['data'] ?? [];
foreach ($liveList as $f) echo "    live: " . ($f['localteam']['name'] ?? '?') . ' vs ' . ($f['visitorteam']['name'] ?? '?') . ' — ' . ($f['league']['name'] ?? '?')
    . ' — ' . ($f['status'] ?? '?') . ' — ' . count($f['balls'] ?? []) . " balls so far  [fixture {$f['id']}]\n";
if (!$liveList) echo "    (no match live in this plan's leagues right now)\n";

// 3. One match in full.
$fid = isset($argv[1]) ? (int) $argv[1] : ($liveList[0]['id'] ?? null);
if (!$fid && $ipl) {
    $fx = sm_get('/fixtures', ['filter[league_id]' => $ipl, 'filter[status]' => 'Finished', 'sort' => '-starting_at']);
    sm_save('3-ipl-fixtures', $fx); sm_report('fixtures (latest IPL)', $fx);
    $fid = $fx['data']['data'][0]['id'] ?? null;
}
if (!$fid) { echo "\nNo fixture to inspect. Run again during a live match, or pass a fixture id.\n"; }
else {
    $m = sm_get('/fixtures/' . $fid, ['include' => $inc]); sm_save('4-fixture-' . $fid, $m); sm_report('fixture ' . $fid, $m);
    $d = $m['data']['data'] ?? [];
    $balls = $d['balls'] ?? [];
    echo "\n  " . ($d['localteam']['name'] ?? '?') . ' vs ' . ($d['visitorteam']['name'] ?? '?') . ' (' . ($d['type'] ?? '?') . ', ' . ($d['status'] ?? '?') . ")\n";
    echo '  toss: ' . ($d['tosswon']['name'] ?? '—') . ' chose ' . ($d['elected'] ?? '—') . "\n";
    $lu = $d['lineup'] ?? [];
    $caps = count(array_filter($lu, function ($p) { return !empty($p['lineup']['captain']); }));
    $wks = count(array_filter($lu, function ($p) { return !empty($p['lineup']['wicketkeeper']); }));
    echo '  lineup: ' . count($lu) . " players, $caps captain(s), $wks keeper(s)\n";
    echo '  balls: ' . count($balls) . "\n";
    if ($balls) {
        $b = $balls[count($balls) - 1];
        echo "  fields on one ball: " . implode(', ', array_keys($b)) . "\n";
        if (isset($b['score']) && is_array($b['score'])) echo "  fields on its score: " . implode(', ', array_keys($b['score'])) . "\n";
        $w = null; foreach ($balls as $x) if (!empty($x['score']['is_wicket'])) { $w = $x; break; }
        if ($w) echo "  a wicket ball: " . json_encode(array_intersect_key($w, array_flip(['ball', 'batsman_id', 'bowler_id', 'batsmanout_id', 'catchstump_id', 'runout_by_id', 'score']))) . "\n";
        $times = array_values(array_filter(array_map(function ($x) { return $x['updated_at'] ?? null; }, $balls)));
        if ($times) echo '  first/last ball updated_at: ' . $times[0] . ' / ' . end($times) . "\n";
    }
}
echo "\nRaw replies saved in:\n  $out\n";
