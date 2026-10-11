<?php
/**
 * Seed the account the browser tests (e2e/, Playwright) sign in with. Development / CI databases only:
 * it refuses to run when NODE_ENV=production.
 *
 *     php -d extension=pdo_pgsql php-backend/tools/e2e-seed.php
 *
 * Creates (or resets) user E2E_USER (default "e2e_player") with password E2E_PASSWORD (default
 * "e2e-Test-Pass-1") and a ₹100,000 balance, so every run starts from the same place.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("command line only\n"); }
$root = dirname(__DIR__);
require $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers'] as $f) require_once "$root/lib/$f.php";
if (cfg('IS_PRODUCTION')) { fwrite(STDERR, "Refusing to seed test users on a production database.\n"); exit(1); }
if (!db_ready()) { fwrite(STDERR, "Database unreachable.\n"); exit(1); }

$user = (string) (getenv('E2E_USER') ?: 'e2e_player');
$pass = (string) (getenv('E2E_PASSWORD') ?: 'e2e-Test-Pass-1');
$row = get_or_create_user($user, true);
if (!$row) { fwrite(STDERR, "Could not create $user.\n"); exit(1); }
q('UPDATE "User" SET "password" = ?, "wallet_balance" = ? WHERE "id" = ?', [hash_password($pass), 100000, (int) $row['id']]);
echo "Seeded $user (id {$row['id']}) with a 100000 balance.\n";
