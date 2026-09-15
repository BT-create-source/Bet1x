<?php
/**
 * One-shot runner for migration-005 (referral system).
 *
 * Unlike apply-otp-migration.php (which opens its own PDO connection from credentials passed on
 * the command line, for a host where phpPgAdmin's SSO logs into the wrong Postgres role), this
 * script just reuses the application's OWN already-configured connection — config.php + lib/db.php
 * reading php-backend/.env, the exact same credentials the live site already runs on. No DB
 * password is accepted as an argument, an environment variable, or a query-string token here, and
 * nothing about it self-deletes; it is a plain idempotent utility in the same spirit as the .sql
 * files under sql/ themselves (every statement in migration-005 is IF NOT EXISTS, so re-running
 * this is harmless and it is fine to leave it in the repo).
 *
 * Run once via SSH/cPanel Terminal or a cron job — CLI only, deliberately not reachable over HTTP:
 *   php php-backend/tools/run-migration-005.php
 *
 * If your cron's `php` resolves to a php-cgi wrapper rather than a true CLI build (PHP_SAPI ends up
 * "cgi-fcgi" and the script fails oddly), point the cron command at the CLI binary directly instead,
 * e.g. /opt/cpanel/ea-phpXX/root/usr/bin/php (match XX to your account's selected PHP version in
 * cPanel's MultiPHP Manager).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This tool runs from the command line only.\n";
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$pdo = db();
if (!$pdo) {
    echo "FATAL: could not connect to the database — " . (string) $GLOBALS['BET1X_DB_ERROR'] . "\n";
    echo "Check php-backend/.env (DATABASE_URL or DB_HOST/DB_NAME/DB_USER/DB_PASS) — the same\n" .
         "values the live site already connects with.\n";
    exit(1);
}
echo "Connected via php-backend/.env.\n\n";

$sqlPath = __DIR__ . '/../sql/migration-005-referral-system-postgres.sql';
if (!is_file($sqlPath)) {
    echo "Cannot find $sqlPath\n";
    exit(1);
}

$sql = file_get_contents($sqlPath);
// Strip line comments and split on statement-terminating semicolons. Safe for THIS file because it
// contains no semicolons inside string literals or dollar-quoted bodies — not a general-purpose SQL
// splitter, and not safe to reuse against an arbitrary .sql file without checking that assumption.
$sql = preg_replace('/--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

$ok = true;
foreach ($statements as $stmt) {
    $label = substr(preg_replace('/\s+/', ' ', $stmt), 0, 78);
    try {
        $pdo->exec($stmt);
        echo "[OK]   $label\n";
    } catch (Throwable $e) {
        // Every statement in migration-005 is IF NOT EXISTS, so re-running an already-applied
        // migration should print all [OK] with nothing actually changing, not fail here.
        echo "[FAIL] $label\n       -> " . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo "\n" . ($ok ? "Migration applied successfully." : "One or more statements FAILED — see above.") . "\n";
if ($ok) {
    echo "Verify with psql/phpPgAdmin: \\d \"User\" should show referral_code / referred_by /\n" .
         "referral_balance columns, and \\d \"ReferralCommission\" should exist.\n";
}
