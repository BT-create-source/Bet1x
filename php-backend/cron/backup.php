<?php
/**
 * Nightly database backup.
 *
 * =================================================================================================
 * WHY THIS EXISTS
 * =================================================================================================
 * Every number that represents money — wallet balances, the transaction ledger, deposits and
 * withdrawals — lives in one PostgreSQL database on one shared host. Before this file there was no
 * automated copy of it anywhere. A dropped table, a bad migration, a compromised account or a
 * hosting failure would have been unrecoverable, and on a real-money platform "unrecoverable"
 * means every customer balance is simply gone with no way to prove what it should have been.
 *
 * This writes a compressed pg_dump, prunes old ones, and refuses to report success on a dump that
 * is obviously truncated — a backup nobody verifies is a backup that quietly stops working.
 *
 * INSTALL (cPanel -> Cron Jobs), once a day, offset from the minute tick:
 *
 *     17 3 * * * /usr/local/bin/php /home/USER/public_html/php-backend/cron/backup.php >/dev/null 2>&1
 *
 * RESTORING (the part people discover too late):
 *
 *     gunzip -c backups/bet1x-2026-09-01-0317.sql.gz | psql -U DBUSER -d DBNAME
 *
 * Test that command against a scratch database BEFORE you need it. An untested restore is a guess.
 * =================================================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';

date_default_timezone_set((string) env_get('APP_TIMEZONE', 'UTC'));

$db = cfg('DB');

// Kept outside the document root by default. The root .htaccess also denies *.sql and *.gz, but a
// directory the web server cannot reach at all is a stronger guarantee than a rewrite rule that a
// future edit could break — and these files contain every password hash on the platform.
$backupDir = (string) env_get('BACKUP_DIR', dirname(__DIR__, 2) . '/backups');
$keepDays  = (int) env_num('BACKUP_KEEP_DAYS', 14);

if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        log_error('backup: cannot create backup directory', ['dir' => $backupDir]);
        exit(1);
    }
}
@chmod($backupDir, 0700);

$stamp  = date('Y-m-d-Hi');
$target = rtrim($backupDir, '/\\') . "/bet1x-{$stamp}.sql.gz";

// --- Locate pg_dump ---------------------------------------------------------------------------------
// Shared hosts put it in varying places and often do not have it on PATH for cron.
$dumpBin = (string) env_get('PG_DUMP_PATH', '');
if ($dumpBin === '') {
    foreach (['/usr/bin/pg_dump', '/usr/local/bin/pg_dump', '/usr/local/pgsql/bin/pg_dump', 'pg_dump'] as $candidate) {
        if ($candidate === 'pg_dump' || is_executable($candidate)) { $dumpBin = $candidate; break; }
    }
}

// --- Credentials via a temp .pgpass file, never on the command line ---------------------------------
// Anything passed as --password=... (or PGPASSWORD in a shell command) is visible to every user on
// the box in `ps`. On shared hosting that is a real exposure, so the password goes into a 0600
// .pgpass-format file that is deleted immediately after, referenced via PGPASSFILE.
$cnf = tempnam(sys_get_temp_dir(), 'bet1xdb');
if ($cnf === false) {
    log_error('backup: cannot create temporary credentials file');
    exit(1);
}
@chmod($cnf, 0600);
// hostname:port:database:username:password — colons and backslashes in the password must be escaped.
$escapePgpassField = function ($v) {
    return str_replace([':', '\\'], ['\\:', '\\\\'], (string)$v);
};
file_put_contents($cnf,
    $escapePgpassField($db['host']) . ':' . $escapePgpassField($db['port']) . ':'
    . $escapePgpassField($db['name']) . ':' . $escapePgpassField($db['user']) . ':'
    . $escapePgpassField($db['pass']) . "\n");
putenv('PGPASSFILE=' . $cnf);

$cmd = escapeshellarg($dumpBin)
     . ' -h ' . escapeshellarg($db['host'])
     . ' -p ' . escapeshellarg((string)$db['port'])
     . ' -U ' . escapeshellarg($db['user'])
     // single-transaction gives a consistent snapshot without locking players out mid-bet.
     . ' --single-transaction --no-password '
     . escapeshellarg($db['name'])
     . ' | gzip -9 > ' . escapeshellarg($target);

$startedAt = microtime(true);
exec($cmd . ' 2>&1', $output, $exitCode);

@unlink($cnf);
putenv('PGPASSFILE');

$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
$size = is_file($target) ? (int) filesize($target) : 0;

// --- Verify, don't assume -------------------------------------------------------------------------
// A failed dump still leaves a small gzip file behind, so "the file exists" proves nothing. The
// schema alone is comfortably over 2 KB compressed; anything under that is a truncated or empty
// dump and must be reported as a failure rather than silently kept as the newest "backup".
$MIN_PLAUSIBLE_BYTES = 2048;

if ($exitCode !== 0 || $size < $MIN_PLAUSIBLE_BYTES) {
    log_error('backup: FAILED', [
        'exit'   => $exitCode,
        'bytes'  => $size,
        'target' => $target,
        'output' => implode(' ', array_slice($output, 0, 5)),
    ]);
    // Remove the unusable artefact so it cannot be mistaken for a good backup later.
    if (is_file($target) && $size < $MIN_PLAUSIBLE_BYTES) @unlink($target);
    exit(1);
}

@chmod($target, 0600);

// --- Prune ----------------------------------------------------------------------------------------
$pruned = 0;
if ($keepDays > 0) {
    $cutoff = time() - ($keepDays * 86400);
    foreach ((array) glob(rtrim($backupDir, '/\\') . '/bet1x-*.sql.gz') as $old) {
        if (is_file($old) && filemtime($old) < $cutoff) {
            if (@unlink($old)) $pruned++;
        }
    }
}

log_info('backup: ok', [
    'file'   => basename($target),
    'bytes'  => $size,
    'ms'     => $durationMs,
    'pruned' => $pruned,
]);

// Record for /api/ready-style monitoring, so a backup that stops running is detectable the same way
// a dead cron is, rather than being discovered on the day it is needed.
try {
    require_once __DIR__ . '/../lib/db.php';
    state_set('backup_last_run', ['at' => now_ms(), 'bytes' => $size, 'file' => basename($target)]);
} catch (Throwable $e) {
    log_error('backup: could not record heartbeat', ['message' => $e->getMessage()]);
}

echo "Backup written: {$target} ({$size} bytes, {$durationMs} ms, pruned {$pruned})\n";
