<?php
/**
 * Generate a production-ready .env for the PHP backend.
 *
 * =================================================================================================
 * WHY THIS EXISTS
 * =================================================================================================
 * config.php already refuses to boot in production when a security-critical value is missing or is
 * still a development placeholder. Those guards are correct — but they only ever run when
 * NODE_ENV=production, and the development .env sets NODE_ENV=development. So the whole safety net
 * sits switched off, and the single most likely way to go live insecurely is to upload the dev file.
 *
 * This script removes the hand-editing step entirely. It produces a file that satisfies every guard
 * on the first boot: real APP_SECRET entropy, bcrypt password hashes instead of plaintext, HTTPS
 * forced, rate limits on, JSON fallback off, and the signup bonus closed.
 *
 * It never touches the existing .env. It writes .env.production and prints the credentials once.
 *
 * USAGE
 *   php php-backend/tools/make-production-env.php \
 *       --domain=https://yourdomain.com \
 *       --db-name=cpaneluser_bet1x --db-user=cpaneluser_bet1x --db-pass='...' \
 *       [--admin-pass='...'] [--superadmin-pass='...'] [--out=/path/to/.env.production]
 *
 * Passwords are generated for you when omitted. They are printed exactly once — save them to a
 * password manager immediately, because only the bcrypt hash is written to disk and a bcrypt hash
 * cannot be reversed.
 * =================================================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

// ---------------------------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------------------------
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

function arg($name, $default = null) {
    global $args;
    return array_key_exists($name, $args) ? $args[$name] : $default;
}

function fail($msg) {
    fwrite(STDERR, "\n  ERROR: {$msg}\n\n");
    exit(1);
}

/**
 * A password that survives being copied through a shell, a cPanel form and a chat message.
 * Deliberately excludes quotes, backslashes and backticks — characters that break .env parsing or
 * get mangled by a shell — while keeping enough entropy (~95 bits at length 20) to be unguessable.
 */
function strong_password($length = 20) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*_-+=';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Required inputs
// ---------------------------------------------------------------------------------------------
$domain = (string) arg('domain', '');
if ($domain === '') {
    fail("--domain is required, e.g. --domain=https://yourdomain.com\n"
       . "         It becomes CORS_ORIGINS, which is what stops another site's JavaScript\n"
       . "         from calling this API with a logged-in player's credentials.");
}
$domain = rtrim(trim($domain), '/');
if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?$#i', $domain)) {
    fail("--domain must be a bare https origin with no path, e.g. https://yourdomain.com\n"
       . "         (got: {$domain})");
}

$dbName = (string) arg('db-name', '');
$dbUser = (string) arg('db-user', '');
$dbPass = (string) arg('db-pass', '');
if ($dbName === '' || $dbUser === '') {
    fail('--db-name and --db-user are required (create them in cPanel -> MySQL Databases first).');
}

$dbHost = (string) arg('db-host', '127.0.0.1');
$dbPort = (string) arg('db-port', '3306');

// ---------------------------------------------------------------------------------------------
// Secrets
// ---------------------------------------------------------------------------------------------
// 48 bytes -> 96 hex chars, comfortably past config.php's 32-character minimum.
$appSecret = bin2hex(random_bytes(48));

$adminPass      = (string) arg('admin-pass', '') ?: strong_password(20);
$superadminPass = (string) arg('superadmin-pass', '') ?: strong_password(24);

if (strlen($adminPass) < 12) {
    fail('--admin-pass must be at least 12 characters. Omit it to have a strong one generated.');
}
if (strlen($superadminPass) < 12) {
    fail('--superadmin-pass must be at least 12 characters. Omit it to have a strong one generated.');
}

// cost 12 rather than the app's default 10: these two hashes guard the money controls, are verified
// at most a handful of times a day, and the extra ~4x work factor is irrelevant at that rate.
$adminHash      = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
$superadminHash = password_hash($superadminPass, PASSWORD_BCRYPT, ['cost' => 12]);

if ($adminHash === false || $superadminHash === false) {
    fail('password_hash() failed — check that the bcrypt driver is available in this PHP build.');
}

$outPath = (string) arg('out', dirname(__DIR__) . '/.env.production');

if (file_exists($outPath) && !arg('force')) {
    fail("{$outPath} already exists. Pass --force to overwrite it, or --out=<path> to write elsewhere.\n"
       . "         Overwriting regenerates APP_SECRET, which signs out every logged-in player.");
}

// ---------------------------------------------------------------------------------------------
// Compose the file
// ---------------------------------------------------------------------------------------------
$generatedAt = gmdate('Y-m-d H:i:s') . ' UTC';

$env = <<<ENV
# =================================================================================================
# bet1x — PRODUCTION configuration
# Generated {$generatedAt} by php-backend/tools/make-production-env.php
#
# This file contains live secrets. It must never be committed, emailed, or pasted into a chat.
# .gitignore already excludes it. On the server it should be chmod 600 and owned by the web user.
#
# Regenerating this file changes APP_SECRET, which invalidates every session token in existence
# and signs out every logged-in player. That is safe, just disruptive — do it deliberately.
# =================================================================================================

# --- Environment ---------------------------------------------------------------------------------
# This single value arms every fail-fast guard in config.php. With it set to production the app
# refuses to boot on a missing secret, a plaintext admin password, or disabled rate limits.
NODE_ENV=production

# --- Secrets -------------------------------------------------------------------------------------
# Signs session tokens (HMAC-SHA256). 48 bytes of CSPRNG entropy.
APP_SECRET={$appSecret}

# --- Operator credentials ------------------------------------------------------------------------
# Only the bcrypt hashes are stored. The plaintext ADMIN_PASSWORD / SUPERADMIN_PASSWORD variables
# are a hard boot failure in production and are deliberately absent from this file.
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH={$adminHash}

SUPERADMIN_USERNAME=superadmin
SUPERADMIN_PASSWORD_HASH={$superadminHash}

# --- Database ------------------------------------------------------------------------------------
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}

# The flat-file fallback must stay off in production. If MySQL is unreachable the correct behaviour
# is a loud failure, not a silent switch to a second source of truth for balances that nothing
# backs up and that never reconciles with the database when it returns.
ALLOW_JSON_FALLBACK=false

# --- Transport security --------------------------------------------------------------------------
FORCE_HTTPS=true

# Exact allowlist. Never a wildcard: session tokens travel in an Authorization header, so a
# permissive value lets any origin drive this API as a logged-in player.
CORS_ORIGINS={$domain}

# Set to 1 only if the site sits behind Cloudflare or another reverse proxy, so that rate limiting
# keys on the real client IP from X-Forwarded-For rather than the proxy's own address.
TRUST_PROXY=0

# --- Money and abuse limits ----------------------------------------------------------------------
# No free credits on signup. Registration is unverified (no email, phone or KYC step), so any
# non-zero value here is withdrawable cash available once per throwaway account.
SIGNUP_BONUS=0

# Never create a wallet just because a name appeared in a request.
ALLOW_AUTO_USER_CREATION=false

BCRYPT_ROUNDS=12
SESSION_TTL_HOURS=24

MIN_BET=1
MAX_BET=100000
MIN_WITHDRAWAL=100

# --- Withdrawal and abuse controls ---------------------------------------------------------------
# See php-backend/lib/riskcontrols.php. Zero (or false) disables an individual check.
#
# WITHDRAWAL_REQUIRE_DEPOSIT is the one that closes bonus farming: an account that has never had a
# deposit approved cannot cash out, so registering accounts in bulk yields nothing withdrawable.
# It has no false-positive risk — a real paying customer has a completed deposit by definition.
WITHDRAWAL_REQUIRE_DEPOSIT=true
WITHDRAWAL_DAILY_MAX=50000
WITHDRAWAL_DAILY_COUNT_MAX=5

# Per-IP registration cap. Left OFF deliberately.
#
# Indian mobile networks make heavy use of carrier-grade NAT, so thousands of unrelated customers
# routinely share one public IPv4 address. A per-IP cap therefore blocks genuine signups, and the
# people it is aimed at rotate IPs cheaply. Turn it on only as an incident response to observed
# farming, and prefer a generous number (25+) over a tight one.
# SIGNUP_MAX_PER_IP_PER_DAY=0

# --- Operations ----------------------------------------------------------------------------------
LOG_LEVEL=error
APP_TIMEZONE=Asia/Kolkata

# /api/ready reports the deployment as not-ready (HTTP 503) once the one-minute cron heartbeat is
# older than this. Point an uptime monitor at /api/ready — a stopped cron does not break page loads,
# so nothing else surfaces it: idle colour rounds stop settling and Aviator freezes mid-phase.
CRON_STALE_SECONDS=300

# Nightly mysqldump (php-backend/cron/backup.php). BACKUP_DIR must sit OUTSIDE the document root —
# these files contain every password hash on the platform.
BACKUP_DIR=/home/CPANELUSER/backups
BACKUP_KEEP_DAYS=14
# Set if mysqldump is not on cron's PATH, which is common on shared hosting:
# MYSQLDUMP_PATH=/usr/bin/mysqldump

# DISABLE_RATE_LIMITS is intentionally absent. Setting it at all in production is a boot failure.

# --- Game engine ---------------------------------------------------------------------------------
AVIATOR_SMART_CRASH=true
AVIATOR_HIGH_STAKE_REF=1000
TEENPATTI_AUTO_BOT_FILL=false

# --- Deferred features ---------------------------------------------------------------------------
CRICKET_ENABLED=false

ENV;

if (@file_put_contents($outPath, $env) === false) {
    fail("Could not write {$outPath} — check the directory exists and is writable.");
}
@chmod($outPath, 0600);

// ---------------------------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------------------------
$line = str_repeat('=', 78);
echo "\n{$line}\n";
echo "  PRODUCTION ENV WRITTEN\n";
echo "{$line}\n\n";
echo "  File:   {$outPath}\n";
echo "  Mode:   0600 (owner read/write only)\n";
echo "  Origin: {$domain}\n\n";
echo "{$line}\n";
echo "  CREDENTIALS — SHOWN ONCE. SAVE THEM NOW.\n";
echo "{$line}\n\n";
echo "  admin       {$adminPass}\n";
echo "  superadmin  {$superadminPass}\n\n";
echo "  Only the bcrypt hashes were written to disk. These plaintext values cannot be\n";
echo "  recovered from the file — if you lose them, re-run this script with --force.\n\n";
echo "{$line}\n";
echo "  NEXT STEPS\n";
echo "{$line}\n\n";
echo "  1. Save both passwords to a password manager.\n";
echo "  2. Upload as php-backend/.env on the server (NOT .env.production):\n";
echo "         mv .env.production .env && chmod 600 .env\n";
echo "  3. Verify the guards actually armed:\n";
echo "         curl -s https://yourdomain.com/api/health\n";
echo "     A 200 with \"env\":\"production\" means every check above passed.\n";
echo "     A 500 means a guard fired — the reason is in the PHP error log.\n";
echo "  4. Confirm the old credentials are dead:\n";
echo "         curl -s -X POST https://yourdomain.com/api/admin.php \\\n";
echo "              -d 'action=login&username=admin&password=admin123'\n";
echo "     This must return 401.\n\n";
