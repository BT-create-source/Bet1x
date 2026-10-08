<?php
/**
 * CricLive live-match sampler — the go/no-go test before paying for the API.
 *
 *     php -d extension=curl php-backend/tools/criclive-live-sample.php <match_id> [minutes=60] [every_seconds=60]
 *
 * Polls /match/{id}/commentary (one call per poll; the reply carries both the live miniscore and the
 * last ~20 commentary entries) and records, for every poll:
 *   - how far behind the newest ball's own timestamp our receipt was (the feed delay we would live with)
 *   - the running score/wickets/overs, so the text of each ball can later be checked against the score
 *   - every distinct commentary line ever seen, to learn the wording of wides, no-balls, byes, run-outs
 * Everything goes to php-backend/tests/reports/criclive-live-<match>-<time>/ (git-ignored), with a
 * summary.txt at the end. 60 polls fit inside the free plan's 100 calls a day.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/config.php';
$mid = (int) ($argv[1] ?? 0);
$minutes = max(1, (int) ($argv[2] ?? 60));
$every = max(15, (int) ($argv[3] ?? 60));
$token = trim((string) env_get('CRICLIVE_API_TOKEN', ''));
$base = rtrim((string) env_get('CRICLIVE_BASE_URL', 'https://cricketliveapi.com/api/v1/cricket'), '/');
if (!$mid || $token === '') { fwrite(STDERR, "usage: criclive-live-sample.php <match_id> [minutes] [every]; needs CRICLIVE_API_TOKEN\n"); exit(1); }

$dir = $root . '/tests/reports/criclive-live-' . $mid . '-' . gmdate('Ymd-His');
@mkdir($dir, 0775, true);
$log = fopen($dir . '/polls.csv', 'w');
fputcsv($log, ['poll', 'received_utc', 'http', 'ms', 'state', 'innings', 'score', 'wickets', 'overs', 'newest_ball', 'newest_ts_utc', 'delay_s', 'new_lines']);

function ca_bundle() {
    if (ini_get('curl.cainfo')) return null;
    foreach ([(string) env_get('CA_BUNDLE', ''), 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
              'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt'] as $f) if ($f !== '' && is_file($f)) return $f;
    return null;
}
$seen = []; $lines = []; $delays = []; $fails = 0; $polls = (int) floor($minutes * 60 / $every);
for ($i = 1; $i <= $polls; $i++) {
    $t0 = microtime(true);
    $ch = curl_init($base . '/match/' . $mid . '/commentary');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '',
                            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
    if ($ca = ca_bundle()) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $recvMs = (int) round(microtime(true) * 1000);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $raw = $raw === false ? '' : str_replace($token, '<token>', $raw);
    file_put_contents(sprintf('%s/poll-%03d.json', $dir, $i), $raw);
    $d = json_decode($raw, true)['data'] ?? null;
    if (!is_array($d)) { $fails++; fputcsv($log, [$i, gmdate('H:i:s', (int) ($recvMs / 1000)), $http, $ms, 'ERROR']); }
    else {
        $mini = $d['miniscore'] ?? [];
        $comm = $d['commentary'] ?? [];
        $newest = null; $new = 0;
        foreach ($comm as $c) {
            $k = ($c['innings_id'] ?? '') . '|' . ($c['ball_metric'] ?? '') . '|' . ($c['timestamp'] ?? '') . '|' . md5((string) ($c['text'] ?? ''));
            if (!isset($seen[$k])) { $seen[$k] = true; $new++; $lines[] = $c; }
            if (preg_match('/ to [^,]+,/', (string) ($c['text'] ?? '')) && ($newest === null || (int) $c['timestamp'] > (int) $newest['timestamp'])) $newest = $c;
        }
        $delay = $newest ? round(($recvMs - (int) $newest['timestamp']) / 1000, 1) : null;
        if ($delay !== null) $delays[] = $delay;
        fputcsv($log, [$i, gmdate('H:i:s', (int) ($recvMs / 1000)), $http, $ms, $mini['state'] ?? '', $mini['innings_id'] ?? '',
                       $mini['bat_team_score'] ?? '', $mini['bat_team_wickets'] ?? '', $mini['overs'] ?? '',
                       $newest['ball_metric'] ?? '', $newest ? gmdate('H:i:s', (int) ($newest['timestamp'] / 1000)) : '', $delay, $new]);
    }
    fflush($log);
    if ($i < $polls) sleep(max(1, $every - (int) round(microtime(true) - $t0)));
}
fclose($log);
usort($lines, function ($a, $b) { return ((int) $a['timestamp']) <=> ((int) $b['timestamp']); });
file_put_contents($dir . '/all-lines.txt', implode("\n", array_map(function ($c) {
    return gmdate('H:i:s', (int) ($c['timestamp'] / 1000)) . '  inn ' . $c['innings_id'] . '  ' . $c['ball_metric'] . '  ' . strip_tags((string) $c['text']);
}, $lines)));
sort($delays);
$sum = "CricLive live sample, match $mid, $polls polls every {$every}s\n"
     . "failed polls: $fails\n"
     . 'distinct commentary lines seen: ' . count($lines) . "\n"
     . ($delays ? sprintf("delay from a ball's own timestamp to our receipt: min %.1fs, median %.1fs, max %.1fs\n",
                          $delays[0], $delays[intdiv(count($delays), 2)], end($delays)) : "no ball lines seen\n")
     . "(with 60s polls the minimum is the best estimate of the feed's own delay)\n";
file_put_contents($dir . '/summary.txt', $sum);
echo $sum, "Saved in $dir\n";
