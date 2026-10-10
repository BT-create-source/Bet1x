<?php
/**
 * Runs every cricket road test in turn and prints one summary.
 *
 *     php -d extension=pdo_pgsql -d extension=curl -d extension=zip php-backend/tests/run_all.php [--quick]
 *     npm run test:cricket:road
 *
 * --quick uses 40 real matches instead of 200 for the Dream11 check. Each suite also writes its own
 * full report to php-backend/tests/reports/. Exit code is non-zero if anything failed.
 */
$quick = in_array('--quick', $argv, true);
$suites = [
    'road_dream11_points.php' => $quick ? ['--matches=40'] : [],
    'road_your11.php'         => [],
    'road_bbb.php'            => [],
    'road_http.php'           => [],
    'road_exchange.php'       => [],
    'road_sportmonks_replay.php' => [],
];
$ext = [];
// Always pass these: a child PHP does not inherit the parent's -d flags (duplicates only warn, and are filtered).
foreach (['pdo_pgsql', 'curl', 'zip'] as $e) { $ext[] = '-d'; $ext[] = 'extension=' . $e; }
$rows = []; $bad = 0; $t0 = microtime(true);
foreach ($suites as $file => $args) {
    echo "\n######## $file ########\n";
    // An argument array runs PHP directly (no cmd.exe / sh in between), so quoting can't break it.
    $cmd = array_merge([PHP_BINARY], $ext, [__DIR__ . '/' . $file], $args);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__, 2));
    if (!is_resource($p)) { $bad++; $rows[] = sprintf('  %-26s %-8s %s', $file, 'FAILED', 'could not start PHP'); continue; }
    fclose($pipes[0]);
    $last = ''; $raw = '';
    while (($line = fgets($pipes[1])) !== false) {
        if (stripos($line, 'already loaded') !== false) continue;
        echo $line; $raw .= $line;
        if (preg_match('/: \d+ passed, \d+ failed/', $line)) $last = trim($line);
    }
    fclose($pipes[1]);
    $code = proc_close($p);
    $ok = $code === 0 && $last !== '';
    if (!$ok) $bad++;
    if ($last === '') echo "  (no summary line; exit code $code; last output: " . trim(substr($raw, -400)) . ")\n";
    $rows[] = sprintf('  %-26s %-8s %s', $file, $ok ? 'OK' : 'FAILED', $last ?: "(crashed, exit $code)");
}
echo "\n================ cricket road tests (" . round(microtime(true) - $t0) . "s) ================\n" . implode("\n", $rows) . "\n";
exit($bad ? 1 : 0);
