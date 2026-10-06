<?php
/**
 * The virtual league's match generator: same secret -> same match in every process; a different
 * secret -> a different match. Without the server's secret, the balls cannot be reproduced.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/test_virtual_rng.php
 */
$pass = 0; $fail = 0;
function check($c, $l) { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function run_with($secret, $slot) {
    $code = 'putenv("CRICKET_MOCK_ABANDON_EVERY=0"); putenv("CRICKET_VIRTUAL_SECRET=' . $secret . '"); require "config.php";'
          . ' foreach (["json","logger","db","http","auth","helpers","cricket-feed","cricket-mock"] as $f) require_once "lib/$f.php";'
          . ' $s = cricket_mock_simulate(' . (int) $slot . '); echo md5(json_encode(array_map(function ($b) { return [$b["batsman"]["runs"], $b["ball_type"], !empty($b["wicket"])]; }, $s["balls"])));';
    $p = proc_open([PHP_BINARY, '-d', 'extension=pdo_pgsql', '-d', 'display_errors=0', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    $out = trim(stream_get_contents($pipes[1])); stream_get_contents($pipes[2]); proc_close($p);
    return preg_match('/[0-9a-f]{32}$/', $out, $m) ? $m[0] : 'error:' . $out;
}
$a1 = run_with(str_repeat('k', 40), 4200001);
$a2 = run_with(str_repeat('k', 40), 4200001);
$b  = run_with(str_repeat('z', 40), 4200001);
check(strpos($a1, 'error') === false, 'the simulator runs in a fresh process');
check($a1 === $a2, 'same secret: two separate processes generate the identical match (every server request agrees)');
check($a1 !== $b, 'different secret: a different match, so the code alone cannot predict the balls');
echo "\ntest_virtual_rng: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
