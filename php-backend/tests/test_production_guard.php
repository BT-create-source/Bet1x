<?php
/**
 * The production guard: simulated cricket must never run on a real-money site.
 *
 *     php php-backend/tests/test_production_guard.php
 *
 * config.php always reads the .env beside it, so each case copies config.php into a temp folder next
 * to its own production-style .env and asks it, in a fresh PHP process, what it decided. No database,
 * no server, and your real .env is never touched.
 */
$pass = 0; $fail = 0;
function check($cond, $label, $detail = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; if ($detail !== null) echo "        " . json_encode($detail) . "\n"; }
}

$prod = [
    'NODE_ENV' => 'production',
    'APP_SECRET' => str_repeat('a1b2c3d4', 12),
    'ADMIN_PASSWORD_HASH' => password_hash('x', PASSWORD_BCRYPT),
    'SUPERADMIN_PASSWORD_HASH' => password_hash('y', PASSWORD_BCRYPT),
    'DB_HOST' => 'localhost', 'DB_NAME' => 'bet1x', 'DB_USER' => 'u', 'DB_PASS' => 'p',
    'DISABLE_RATE_LIMITS' => 'false', 'SIGNUP_BONUS' => '0',
];

function decide(array $env) {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bet1x_guard_' . bin2hex(random_bytes(4));
    mkdir($dir . '/php-backend', 0777, true);
    copy(dirname(__DIR__) . '/config.php', $dir . '/php-backend/config.php');
    $lines = '';
    foreach ($env as $k => $v) $lines .= "$k=$v\n";
    file_put_contents($dir . '/php-backend/.env', $lines);
    $probe = $dir . '/probe.php';
    file_put_contents($probe, '<?php require ' . var_export($dir . '/php-backend/config.php', true) . ';'
        . ' echo json_encode(["env" => cfg("NODE_ENV"), "cricket" => cfg("CRICKET_LIVE"), "fantasy" => cfg("FANTASY_LIVE"),'
        . ' "cb" => cfg("CRICKET_BLOCKED"), "fb" => cfg("FANTASY_BLOCKED")]);');
    // A clean environment, so nothing from the calling shell leaks into the case.
    $p = proc_open([PHP_BINARY, '-d', 'error_log=' . $dir . '/err.log', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['SystemRoot' => getenv('SystemRoot') ?: '', 'PATH' => getenv('PATH') ?: '']);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    $log = (string) @file_get_contents($dir . '/err.log');
    array_map('unlink', glob($dir . '/php-backend/*') ?: []); @rmdir($dir . '/php-backend');
    array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir);
    $r = json_decode(trim($out), true);
    if (!is_array($r)) $r = ['raw' => $out];
    $r['log'] = $log;
    return $r;
}

$keys = ['ROANUZ_API_KEY' => 'k', 'ROANUZ_PROJECT_KEY' => 'p', 'ROANUZ_WEBHOOK_SECRET' => str_repeat('s', 40)];

echo "== Production ==\n";
$r = decide($prod + ['CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed']);
check(($r['env'] ?? null) === 'production', 'the probe really runs in production mode', $r);
check($r['cricket'] === false && $r['fantasy'] === false, 'no Roanuz keys: Ball by Ball, match betting and Your 11 are all held OFF', $r);
check(strpos($r['log'], 'Cricket held OFF') !== false && strpos($r['log'], 'Your 11 held OFF') !== false, 'and the reason is written to the error log');
check(isset($r['cb'][0]) && strpos(implode(' ', $r['cb']), 'ROANUZ_API_KEY') !== false, 'the reason names the missing settings', $r['cb'] ?? null);

$r = decide($prod + $keys + ['CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed']);
check($r['cricket'] === true && $r['fantasy'] === true && !$r['cb'] && !$r['fb'], 'with both keys and the webhook secret: everything runs', $r);

$r = decide($prod + ['CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed', 'CRICKET_SOURCE' => 'sportmonks', 'SPORTMONKS_API_TOKEN' => 'tok']);
check($r['cricket'] === true && $r['fantasy'] === true && !$r['cb'] && !$r['fb'], 'CRICKET_SOURCE=sportmonks with a token: real cricket runs, no Roanuz keys needed', $r);
$r = decide($prod + ['CRICKET_ENABLED' => 'true', 'CRICKET_SOURCE' => 'sportmonks']);
check($r['cricket'] === false && strpos(implode(' ', $r['cb']), 'SPORTMONKS_API_TOKEN') !== false, 'CRICKET_SOURCE=sportmonks without a token: held OFF, and the reason says why', $r['cb'] ?? null);

$r = decide($prod + $keys + ['CRICKET_ENABLED' => 'true', 'CRICKET_SOURCE' => 'mock', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed']);
check($r['cricket'] === false && $r['fantasy'] === false, 'CRICKET_SOURCE=mock forced on: held OFF even with keys present', $r);

$noSecret = $keys; unset($noSecret['ROANUZ_WEBHOOK_SECRET']);
$r = decide($prod + $noSecret + ['CRICKET_ENABLED' => 'true']);
check($r['cricket'] === false && strpos(implode(' ', $r['cb']), 'WEBHOOK_SECRET') !== false, 'keys but no webhook secret: held OFF (no live ball could ever arrive)', $r);

$r = decide($prod + $keys + ['FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'mock']);
check($r['fantasy'] === false, 'FANTASY_SOURCE=mock: Your 11 held OFF', $r);

$r = decide($prod + ['CRICKET_ENABLED' => 'false', 'FANTASY_ENABLED' => 'false']);
check(($r['env'] ?? null) === 'production' && $r['cricket'] === false && !$r['cb'] && $r['log'] === '', 'games switched off: nothing held, nothing logged, and the rest of the site still boots', $r);

echo "== Virtual Cricket (explicit opt-in) ==\n";
$r = decide($prod + ['CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed', 'CRICKET_VIRTUAL' => 'true']);
check($r['cricket'] === true && $r['fantasy'] === true && !$r['cb'] && !$r['fb'], 'CRICKET_VIRTUAL=true, no Roanuz keys: the virtual league runs (no webhook secret needed)', $r);
$r = decide($prod + ['CRICKET_ENABLED' => 'true', 'CRICKET_VIRTUAL' => 'true', 'CRICKET_VIRTUAL_SECRET' => 'short']);
check($r['cricket'] === true, 'a short CRICKET_VIRTUAL_SECRET is fine while APP_SECRET is strong', $r);
$r = decide($prod + $keys + ['CRICKET_ENABLED' => 'true', 'CRICKET_VIRTUAL' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed']);
check($r['cricket'] === true && !$r['cb'], 'Roanuz keys present: real cricket takes over, the virtual flag notwithstanding', $r);
$r = decide($prod + ['FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'mock', 'CRICKET_VIRTUAL' => 'true']);
check($r['fantasy'] === false, 'FANTASY_SOURCE=mock (canned fixtures) stays refused even with CRICKET_VIRTUAL', $r);
$r = decide($prod + ['CRICKET_ENABLED' => 'true']);
check($r['cricket'] === false, 'without CRICKET_VIRTUAL the simulator is still refused in production', $r);

echo "== Development (unchanged) ==\n";
$r = decide(['NODE_ENV' => 'development', 'CRICKET_ENABLED' => 'true', 'FANTASY_ENABLED' => 'true', 'FANTASY_SOURCE' => 'feed']);
check($r['cricket'] === true && $r['fantasy'] === true, 'development keeps the simulated league running without keys', $r);

echo "\n" . "test_production_guard: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
