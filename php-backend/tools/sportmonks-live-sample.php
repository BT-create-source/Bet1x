<?php
/**
 * Sportmonks live-match sampler — the go/no-go test before relying on the feed for Ball by Ball.
 *
 *     php -d extension=curl php-backend/tools/sportmonks-live-sample.php [minutes=20] [every_seconds=5]
 *
 * One /livescores?include=balls call per poll covers every live match. For each match and poll it records
 * the newest ball, how far its own updated_at lags our receipt, whether the feed ever went backwards, and
 * whether a ball already seen was later changed (a correction). Output: php-backend/tests/reports/
 * sportmonks-live-<time>/ (git-ignored): polls.csv, balls.csv (first sighting of every ball),
 * changes.txt (corrections), summary.txt. The token is never written out.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/config.php';
$minutes = max(1, (int) ($argv[1] ?? 20));
$every = max(2, (int) ($argv[2] ?? 5));
$token = trim((string) env_get('SPORTMONKS_API_TOKEN', ''));
if ($token === '') { fwrite(STDERR, "SPORTMONKS_API_TOKEN is empty in php-backend/.env\n"); exit(1); }

$dir = $root . '/tests/reports/sportmonks-live-' . gmdate('Ymd-His');
@mkdir($dir, 0775, true);
$pl = fopen("$dir/polls.csv", 'w'); fputcsv($pl, ['poll', 'recv_utc', 'http', 'ms', 'fixture', 'status', 'balls', 'newest_ball', 'newest_updated_utc', 'lag_s', 'went_back']);
$bl = fopen("$dir/balls.csv", 'w'); fputcsv($bl, ['fixture', 'ball_id', 'over_ball', 'scoreboard', 'score_name', 'runs', 'wicket', 'updated_utc', 'first_seen_utc', 'seen_after_s']);
$ch_log = fopen("$dir/changes.txt", 'w');

function ca() {
    if (ini_get('curl.cainfo')) return null;
    foreach ([(string) env_get('CA_BUNDLE', ''), 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt'] as $f) if ($f !== '' && is_file($f)) return $f;
    return null;
}
function ts($s) { $t = strtotime((string) $s); return $t ? $t : null; }
function sig($b) { $s = $b['score'] ?? []; return json_encode([$b['ball'] ?? null, $b['batsman_id'] ?? null, $b['bowler_id'] ?? null, $s['name'] ?? null, $s['runs'] ?? null, $s['is_wicket'] ?? null, $b['scoreboard'] ?? null]); }

$seen = []; $maxCount = []; $lags = []; $firstLags = []; $fails = 0; $backs = 0; $changes = 0; $polls = (int) floor($minutes * 60 / $every);
for ($i = 1; $i <= $polls; $i++) {
    $t0 = microtime(true);
    $c = curl_init('https://cricket.sportmonks.com/api/v2.0/livescores?' . http_build_query(['api_token' => $token, 'include' => 'balls.score']));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_ENCODING => '']);
    if ($ca = ca()) curl_setopt($c, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($c); $http = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    $now = microtime(true); $ms = (int) round(($now - $t0) * 1000); $recv = gmdate('H:i:s', (int) $now);
    $j = $raw ? json_decode($raw, true) : null;
    if (!is_array($j['data'] ?? null)) { $fails++; fputcsv($pl, [$i, $recv, $http, $ms, 'ERROR']); }
    else {
        if ($i % 60 === 1) file_put_contents(sprintf('%s/raw-%04d.json', $dir, $i), $raw);
        foreach ($j['data'] as $f) {
            $fid = $f['id']; $balls = $f['balls']['data'] ?? $f['balls'] ?? [];
            $n = count($balls); $newest = null;
            foreach ($balls as $b) {
                $id = $b['id']; $sg = sig($b); $up = ts($b['updated_at'] ?? null);
                if (!isset($seen[$id])) {
                    $seen[$id] = $sg;
                    $after = $up ? round($now - $up, 1) : '';
                    if ($i > 1 && $up) $firstLags[] = $after;   // poll 1 sees history, not live arrivals
                    fputcsv($bl, [$fid, $id, $b['ball'] ?? '', $b['scoreboard'] ?? '', $b['score']['name'] ?? '', $b['score']['runs'] ?? '',
                                  !empty($b['score']['is_wicket']) ? 1 : 0, $up ? gmdate('H:i:s', $up) : '', $recv, $after]);
                } elseif ($seen[$id] !== $sg) {
                    $changes++; fwrite($ch_log, "$recv fixture $fid ball $id changed\n   was $seen[$id]\n   now $sg\n"); $seen[$id] = $sg;
                }
                if ($newest === null || $id > $newest['id']) $newest = $b;
            }
            $back = isset($maxCount[$fid]) && $n < $maxCount[$fid]; if ($back) $backs++;
            $maxCount[$fid] = max($maxCount[$fid] ?? 0, $n);
            $up = $newest ? ts($newest['updated_at'] ?? null) : null; $lag = $up ? round($now - $up, 1) : '';
            if ($lag !== '') $lags[] = $lag;
            fputcsv($pl, [$i, $recv, $http, $ms, $fid, $f['status'] ?? '', $n, $newest['ball'] ?? '', $up ? gmdate('H:i:s', $up) : '', $lag, $back ? 1 : 0]);
        }
    }
    fflush($pl); fflush($bl); fflush($ch_log);
    if ($i < $polls) usleep((int) max(200000, ($every - (microtime(true) - $t0)) * 1e6));
}
function stats($a) { if (!$a) return 'none'; sort($a); return sprintf('min %.1fs, median %.1fs, p90 %.1fs, max %.1fs (n=%d)', $a[0], $a[intdiv(count($a), 2)], $a[(int) floor(count($a) * .9)], end($a), count($a)); }
$sum = "Sportmonks live sample, $polls polls every {$every}s\nfailed polls: $fails\nballs seen: " . count($seen)
     . "\nnew ball first seen after its own updated_at: " . stats($firstLags)
     . "\n(our {$every}s poll interval adds up to {$every}s; subtract ~" . ($every / 2) . "s for the feed's own delay)"
     . "\ntimes a match's ball count went backwards: $backs\nballs changed after first seen (corrections): $changes\n";
file_put_contents("$dir/summary.txt", $sum);
echo $sum, "Saved in $dir\n";
