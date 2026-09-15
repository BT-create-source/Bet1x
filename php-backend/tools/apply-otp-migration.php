<?php
/**
 * One-shot runner for migration-003 (phone + OTP verification), for hosts where phpPgAdmin's
 * cPanel single-sign-on always logs in as the wrong Postgres role and the UI gives no way to pick
 * a different one.
 *
 * =================================================================================================
 * WHY THIS EXISTS
 * =================================================================================================
 * migration-003 does ALTER TABLE "User" and CREATE UNIQUE INDEX ON "User". In PostgreSQL both
 * require being the OWNER of that table (or a superuser) — a plain GRANT ALL PRIVILEGES on the
 * database, which is all cPanel's "PostgreSQL Databases" page can hand out through its checkbox UI,
 * does not confer that. On a lot of cPanel setups the owner is a role like `betxbiz_dbuser` that is
 * NOT the account phpPgAdmin's SSO logs into (typically the bare cPanel username, e.g. `betxbiz`),
 * and phpPgAdmin's login screen is skipped entirely by that SSO — there is no field to type a
 * different role into.
 *
 * This script sidesteps phpPgAdmin altogether: it opens its own PDO connection with whatever
 * credentials you give it, so it authenticates as the actual table owner directly against
 * PostgreSQL, the same way `psql -U betxbiz_dbuser ...` would if you had a shell.
 *
 * =================================================================================================
 * SELF-DESTRUCTS ON SUCCESS
 * =================================================================================================
 * A script that opens a database connection with a password baked in (or passed in a URL) must not
 * outlive the one job it exists to do. Once every statement in the migration has applied cleanly,
 * this file deletes itself — so forgetting to remove it by hand does not leave a live credential
 * (and, over HTTP, a re-runnable endpoint) sitting in a web-reachable directory indefinitely.
 *
 * =================================================================================================
 * USAGE — SSH or a cPanel "Terminal" app
 * =================================================================================================
 *   php php-backend/tools/apply-otp-migration.php \
 *       --host=localhost --port=5432 --dbname=DBNAME --user=betxbiz_dbuser --password='...'
 *
 * =================================================================================================
 * USAGE — no shell at all, only File Manager / a browser
 * =================================================================================================
 *   1. Edit the constants just below — $DBNAME, $DBUSER, $DBPASS, and $TOKEN (make TOKEN long and
 *      random; it is the only thing stopping anyone else who finds this URL from running it too).
 *   2. This file's normal home (php-backend/tools/) sits behind the repo's .htaccess, which denies
 *      everything under php-backend/ except index.php — so it is NOT reachable at its normal path.
 *      Upload this one file to the document root instead (e.g. as /run-otp-migration.php),
 *      alongside index.html.
 *   3. Visit it over HTTPS only (the token and, briefly, the DB password travel in the query
 *      string): https://yourdomain.com/run-otp-migration.php?token=YOUR_TOKEN
 *   4. Read the page. It tells you whether it deleted itself. If it says it could NOT delete
 *      itself, remove /run-otp-migration.php by hand immediately — do not leave that page waiting.
 *
 * Either way, run it once, confirm the output, and it is gone.
 */

// --- Configure these before uploading for the browser path, or pass them as CLI flags instead. --
$HOST   = getenv('MIGRATION_DB_HOST')     ?: 'localhost';
$PORT   = getenv('MIGRATION_DB_PORT')     ?: '5432';
$DBNAME = getenv('MIGRATION_DB_NAME')     ?: '';
$DBUSER = getenv('MIGRATION_DB_USER')     ?: '';   // the TABLE OWNER role, e.g. betxbiz_dbuser
$DBPASS = getenv('MIGRATION_DB_PASSWORD') ?: '';
// Browser path only. Set this to something long and random before uploading; it is compared with
// hash_equals() below so a mistyped guess cannot be timed against it.
$TOKEN  = getenv('MIGRATION_TOKEN') ?: 'CHANGE-ME-BEFORE-UPLOADING';

$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--(host|port|dbname|user|password)=(.*)$/', $arg, $m)) {
            switch ($m[1]) {
                case 'host':     $HOST   = $m[2]; break;
                case 'port':     $PORT   = $m[2]; break;
                case 'dbname':   $DBNAME = $m[2]; break;
                case 'user':     $DBUSER = $m[2]; break;
                case 'password': $DBPASS = $m[2]; break;
            }
        }
    }
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $suppliedToken = $_GET['token'] ?? '';
    if ($TOKEN === 'CHANGE-ME-BEFORE-UPLOADING' || $suppliedToken === '' ||
        !hash_equals($TOKEN, (string) $suppliedToken)) {
        http_response_code(403);
        echo "Set \$TOKEN in this file (or the MIGRATION_TOKEN env var) to something private\n" .
             "before uploading, then pass it back as ?token=...\n";
        exit;
    }
}

if ($DBNAME === '' || $DBUSER === '') {
    echo "Missing database name / user. Pass --dbname= --user= on the command line, or set\n" .
         "MIGRATION_DB_NAME / MIGRATION_DB_USER (or edit the constants at the top of this file).\n";
    exit(1);
}

$sqlPath = __DIR__ . '/../sql/migration-003-phone-otp-postgres.sql';
if (!is_file($sqlPath)) {
    // Most likely cause when running from the document-root copy described above: this file was
    // moved on its own without the sql/ directory alongside it. Copy that one file over too, or
    // point $sqlPath at wherever it actually landed.
    echo "Cannot find $sqlPath\n";
    exit(1);
}

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $HOST, (int) $PORT, $DBNAME);
try {
    $pdo = new PDO($dsn, $DBUSER, $DBPASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "Connection failed: " . $e->getMessage() . "\n\n";
    echo "This talks to PostgreSQL directly and never touches phpPgAdmin or cPanel's login, so a\n" .
         "\"role ... does not exist\" or \"password authentication failed\" here is about the\n" .
         "--user/--password given above, not the original phpPgAdmin problem.\n";
    exit(1);
}

echo "Connected to $DBNAME as $DBUSER.\n\n";

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
        // Every statement in migration-003 is IF NOT EXISTS, so re-running an already-applied
        // migration should print all [OK] with nothing actually changing, not fail here.
        echo "[FAIL] $label\n       -> " . $e->getMessage() . "\n";
        $ok = false;
    }
}

echo "\n" . ($ok ? "Migration applied successfully." : "One or more statements FAILED — see above.") . "\n\n";

if ($ok) {
    echo "Deleting this script...\n";
    if (@unlink(__FILE__)) {
        echo "Done — this file is gone. Nothing else to clean up.\n";
    } else {
        echo "COULD NOT DELETE ITSELF. Remove " . __FILE__ . " by hand RIGHT NOW — it holds a\n" .
             "database password and, if this is the browser copy, is re-runnable by anyone who has\n" .
             "the URL and token.\n";
    }
} else {
    echo "Left in place on failure so you can retry after fixing the error above. Delete it\n" .
         "yourself once the migration is confirmed applied (check via phpPgAdmin or psql: \\d \"User\"\n" .
         "should show phone and phone_verified columns, and \\d \"PhoneOtp\" should exist).\n";
}
