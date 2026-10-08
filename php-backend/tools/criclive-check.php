<?php
/**
 * CricLive API (cricketliveapi.com) free-plan check — saves every raw reply so the real shapes can be read.
 *
 *     php -d extension=curl php-backend/tools/criclive-check.php                 # /live only
 *     php -d extension=curl php-backend/tools/criclive-check.php /some/path ...  # plus any documented paths
 *
 * Paths are relative to https://cricketliveapi.com/api/v1/cricket ; use {id} and it is replaced with the
 * first live match id found, e.g.  /match/{id}/scorecard . Put the token in your LOCAL php-backend/.env
 * as  CRICLIVE_API_TOKEN=...  (never the server's, never in chat). The free plan allows 100 calls a day;
 * each path is one call. The token is never printed or saved.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/config.php';

$token = trim((string) env_get('CRICLIVE_API_TOKEN', ''));
$base = rtrim((string) env_get('CRICLIVE_BASE_URL', 'https://cricketliveapi.com/api/v1/cricket'), '/');
if ($token === '') { fwrite(STDERR, "CRICLIVE_API_TOKEN is empty in php-backend/.env\n"); exit(1); }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP curl extension is missing (run with -d extension=curl)\n"); exit(1); }
$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'criclive-check-' . gmdate('Ymd-His');
@mkdir($out, 0775, true);

/** Windows PHP often lacks a CA list; use php.ini's, else CA_BUNDLE, else Git for Windows' bundle. Verification stays on. */
function cl_ca() {
    if (ini_get('curl.cainfo')) return null;
    foreach ([(string) env_get('CA_BUNDLE', ''), 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
              'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt'] as $f) if ($f !== '' && is_file($f)) return $f;
    return null;
}
function cl_get($path) {
    global $token, $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
    if ($ca = cl_ca()) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $raw = $raw === false ? '' : str_replace($token, '<token>', $raw);
    return ['status' => $status, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'raw' => $raw, 'error' => $err, 'data' => json_decode($raw, true)];
}
function cl_save($name, $r) {
    global $out;
    file_put_contents($out . DIRECTORY_SEPARATOR . preg_replace('/[^\w.-]+/', '_', $name) . '.json', $r['data'] !== null
        ? json_encode($r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['raw']);
}
function cl_report($label, $r) {
    $ok = $r['status'] >= 200 && $r['status'] < 300 && is_array($r['data']);
    $keys = is_array($r['data']) ? implode(', ', array_slice(array_keys($r['data']), 0, 8)) : '';
    echo sprintf("  %-5s %-34s HTTP %d  %5d ms  %7d bytes  %s\n", $ok ? 'OK' : 'FAIL', $label, $r['status'], $r['ms'], strlen($r['raw']),
                 $ok ? "top-level: $keys" : ($r['error'] ?: substr($r['raw'], 0, 160)));
    return $ok;
}
/** First match id anywhere in a reply. */
function cl_find_id($d) {
    if (!is_array($d)) return null;
    foreach ($d as $k => $v) {
        if (in_array((string) $k, ['match_id', 'matchId', 'id'], true) && (is_int($v) || (is_string($v) && $v !== ''))) return (string) $v;
        $f = cl_find_id($v);
        if ($f !== null) return $f;
    }
    return null;
}

echo "CricLive API check\n\n";
$live = cl_get('/live'); cl_save('1-live', $live); cl_report('/live', $live);
$id = cl_find_id($live['data']);
echo $id !== null ? "  first match id in /live: $id\n" : "  no match id found in /live (nothing live, or an unexpected shape)\n";
foreach (array_slice($argv, 1) as $i => $p) {
    $path = '/' . ltrim(str_replace('{id}', (string) $id, $p), '/');
    $r = cl_get($path); cl_save(($i + 2) . '-' . $path, $r); cl_report($path, $r);
}
echo "\nCalls used: " . (1 + count(array_slice($argv, 1))) . " of the free plan's 100 per day\nRaw replies saved in:\n  $out\n";
