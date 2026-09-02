<?php
/**
 * ONE-TIME database setup, run over HTTPS instead of a shell, because this host is reachable only
 * over 80/443 from the environment that built this deployment (no SSH/psql access available there).
 *
 * Applies sql/schema-postgres.sql, then sql/migration-002-risk-controls-postgres.sql, statement by
 * statement, and reports what happened as JSON. Safe to run more than once: every statement in both
 * files is idempotent (CREATE TABLE/INDEX ... IF NOT EXISTS, ON CONFLICT ... for the seed rows).
 *
 * DELETE THIS FILE FROM THE SERVER AS SOON AS IT REPORTS SUCCESS. It requires a secret token, but a
 * script that can run arbitrary schema SQL against the production database has no reason to still
 * exist once the database is set up.
 */

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Run this over HTTP with ?token=..., not from the CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Rotate by regenerating and re-uploading the file if it is ever exposed; this only gates against
// a stranger finding the URL before you delete the file, not against a determined attacker who
// already has server access.
const MIGRATE_TOKEN = 'ca0b4840d0ba3eb9476961b16dc76b347db33f9215e9140b';

$token = $_GET['token'] ?? '';
if (!hash_equals(MIGRATE_TOKEN, (string)$token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Pass ?token=... (see the deploy notes).']);
    exit;
}

$c = cfg('DB');
try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['name']),
        $c['user'],
        $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to the database', 'message' => $e->getMessage()]);
    exit;
}

/**
 * Split a .sql file into individual statements on semicolons that are not inside a string literal,
 * a $$-quoted plpgsql function body, or a -- comment. Good enough for the two fixed files this
 * script is given; not a general-purpose SQL parser.
 */
function split_sql_statements($sql) {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDollar = false;
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        if (!$inSingle && !$inDollar && $ch === '-' && ($i + 1) < $len && $sql[$i + 1] === '-') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) { $i = $len; break; }
            $current .= substr($sql, $i, $nl - $i + 1);
            $i = $nl + 1;
            continue;
        }
        if (!$inSingle && substr($sql, $i, 2) === '$$') {
            $inDollar = !$inDollar;
            $current .= '$$';
            $i += 2;
            continue;
        }
        if (!$inDollar && $ch === "'") {
            $inSingle = !$inSingle;
            $current .= $ch;
            $i++;
            continue;
        }
        if (!$inSingle && !$inDollar && $ch === ';') {
            $current .= ';';
            $trimmed = trim($current);
            if ($trimmed !== ';') $statements[] = $trimmed;
            $current = '';
            $i++;
            continue;
        }
        $current .= $ch;
        $i++;
    }
    $tail = trim($current);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
}

function run_sql_file($pdo, $path) {
    $sql = file_get_contents($path);
    if ($sql === false) {
        return ['file' => basename($path), 'error' => 'file not found'];
    }
    $statements = split_sql_statements($sql);
    $ran = 0;
    $errors = [];
    foreach ($statements as $stmt) {
        if (trim($stmt) === '') continue;
        try {
            $pdo->exec($stmt);
            $ran++;
        } catch (Throwable $e) {
            $errors[] = ['statement' => substr($stmt, 0, 120), 'message' => $e->getMessage()];
        }
    }
    return ['file' => basename($path), 'statements_run' => $ran, 'errors' => $errors];
}

$results = [];
$results[] = run_sql_file($pdo, __DIR__ . '/../sql/schema-postgres.sql');
$results[] = run_sql_file($pdo, __DIR__ . '/../sql/migration-002-risk-controls-postgres.sql');

$hadErrors = false;
foreach ($results as $r) {
    if (!empty($r['errors'])) $hadErrors = true;
}

echo json_encode([
    'success' => !$hadErrors,
    'results' => $results,
    'reminder' => 'Delete php-backend/tools/web-migrate.php now.',
], JSON_PRETTY_PRINT);
