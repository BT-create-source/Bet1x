<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/otp.php';
require_once __DIR__ . '/../lib/helpers.php';
set_exception_handler(function ($e) { fwrite(STDERR, $e->getMessage()); exit(1); });
if (cfg('IS_PRODUCTION')) throw new RuntimeException('Refusing SMS tests against production configuration');
if (!db_ready()) throw new RuntimeException('Development database unavailable');
if (($argv[1] ?? '') === 'unverify') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^smstest_[a-z0-9_]+$/', $name)) throw new RuntimeException('Invalid test account');
    q('UPDATE "User" SET "phone_verified" = 0 WHERE "username" = ?', [$name]);
} elseif (($argv[1] ?? '') === 'cleanup') {
    foreach (array_slice($argv, 2) as $name) {
        if (!preg_match('/^smstest_[a-z0-9_]+$/', $name)) throw new RuntimeException('Invalid test account');
        $u = find_user_ci($name);
        if (!$u) continue;
        if (!empty($u['phone'])) q('DELETE FROM "PhoneOtp" WHERE "phone" IN (?,?)', [$u['phone'], 'reset:' . $u['phone']]);
        q('DELETE FROM "Transaction" WHERE "user" = ?', [$name]);
        q('DELETE FROM "User" WHERE "username" = ?', [$name]);
    }
} else {
    one('SELECT * FROM "PhoneOtp" LIMIT 1');
    echo 'ready';
}
