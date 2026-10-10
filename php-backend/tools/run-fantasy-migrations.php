<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$pdo = db();
if (!$pdo) {
    echo "DB connect failed: " . ($GLOBALS['BET1X_DB_ERROR'] ?? 'unknown') . "\n";
    exit(1);
}

$files = [
    'migration-006-fantasy-postgres.sql',
    'migration-007-fantasy-entry-guard-postgres.sql',
    'migration-008-fantasy-source-state-postgres.sql',
    'migration-009-fantasy-min-entries-postgres.sql',
    'migration-010-cricket-feed-postgres.sql',
    'migration-011-cricket-exchange-postgres.sql'
];
// Name files on the command line to apply only those, e.g.:
//   php tools/run-fantasy-migrations.php migration-012-chickenroad-astronaut-postgres.sql
$only = array_values(array_filter(array_slice($argv ?? [], 1), function ($f) { return preg_match('/^migration-\d{3}-[a-z0-9-]+\.sql$/', $f); }));
if ($only) $files = $only;

foreach ($files as $file) {
    echo "Applying $file...\n";
    $path = __DIR__ . '/../sql/' . $file;
    if (!file_exists($path)) {
        echo "Missing $file\n";
        continue;
    }
    $sql = file_get_contents($path);
    try {
        $pdo->exec($sql);
        echo "Done $file\n";
    } catch (Exception $e) {
        echo "Error in $file: " . $e->getMessage() . "\n";
    }
}
echo "All migrations finished.\n";
