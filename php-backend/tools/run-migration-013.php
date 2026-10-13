<?php
/**
 * One-shot runner for migration-013 (Tower Rush), using the site's own connection (php-backend/.env).
 * CLI only. The file contains a PL/pgSQL function body, so it is sent to Postgres whole rather than
 * split on semicolons. Every statement is idempotent: re-running is harmless.
 *
 *   php php-backend/tools/run-migration-013.php
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
    exit(1);
}
echo "Connected via php-backend/.env.\n";

$sqlPath = __DIR__ . '/../sql/migration-013-towerrush-postgres.sql';
try {
    $pdo->exec(file_get_contents($sqlPath));
    $n = $pdo->query("SELECT COUNT(*) FROM \"TowerRushSession\"")->fetchColumn();
    echo "[OK] migration 013 applied: TowerRushSession exists ($n rows).\n";
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
