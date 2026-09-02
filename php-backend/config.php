<?php
/**
 * Centralised, validated runtime configuration.
 *
 * Port of backend/config.js. Same variable names, same defaults, same fail-fast behaviour: in
 * production the process refuses to serve when a security-critical value is missing or is still a
 * development placeholder.
 *
 * There is no dotenv package on shared hosting, so .env is parsed here by hand. Values already
 * present in the real environment (cPanel's "Environment Variables", or SetEnv in .htaccess) win
 * over the file, matching how dotenv behaves.
 */

if (defined('BET1X_CONFIG_LOADED')) { return; }
define('BET1X_CONFIG_LOADED', true);

// -------------------------------------------------------------------------------------------------
// .env loading
// -------------------------------------------------------------------------------------------------

/**
 * Parse a .env file into an array. Deliberately minimal — KEY=VALUE, # comments, optional quotes.
 * No variable interpolation, because the Node build's dotenv did not do it either.
 */
function bet1x_parse_env($path) {
    $out = [];
    if (!is_readable($path)) return $out;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2) {
            $first = $val[0]; $last = substr($val, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        if ($key !== '') $out[$key] = $val;
    }
    return $out;
}

$BET1X_ENV_FILE = array_merge(
    bet1x_parse_env(dirname(__DIR__) . '/.env'),   // repo-root fallback, same as config.js
    bet1x_parse_env(__DIR__ . '/.env')             // php-backend/.env wins
);

/** Real environment first, then the .env file, then the supplied default. */
function env_get($name, $default = null) {
    global $BET1X_ENV_FILE;
    $v = getenv($name);
    if ($v === false || $v === '') {
        $v = isset($BET1X_ENV_FILE[$name]) ? $BET1X_ENV_FILE[$name] : null;
    }
    if ($v === null || $v === '') return $default;
    return $v;
}

function env_bool($name, $default) {
    $v = env_get($name, null);
    if ($v === null) return $default;
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function env_num($name, $default) {
    $v = env_get($name, null);
    if ($v === null) return $default;
    return is_numeric($v) ? (float)$v : $default;
}

function env_list($name) {
    $v = env_get($name, '');
    if ($v === null || $v === '') return [];
    $parts = array_map('trim', explode(',', (string)$v));
    return array_values(array_filter($parts, function ($s) { return $s !== ''; }));
}

// -------------------------------------------------------------------------------------------------
// Resolved configuration
// -------------------------------------------------------------------------------------------------

$fatal = [];

$NODE_ENV      = env_get('NODE_ENV', 'development');
$IS_PRODUCTION = ($NODE_ENV === 'production');

// --- Secrets ---
// APP_SECRET is the only thing standing between a forged auth token and someone else's wallet.
//
// PORT NOTE: the Node build generated a random per-boot secret in development. PHP has no "boot" —
// a fresh random value on every request would invalidate every token instantly — so development
// falls back to a FIXED, obviously-fake development secret instead. Production still refuses to
// start without a real one. Set the SAME APP_SECRET the Node build used and every token already
// issued to a player stays valid across the cutover.
$APP_SECRET = (string) env_get('APP_SECRET', '');
if ($APP_SECRET === '') {
    if ($IS_PRODUCTION) {
        $fatal[] = 'APP_SECRET is required when NODE_ENV=production. Generate one with:  php -r "echo bin2hex(random_bytes(48));"';
    }
    $APP_SECRET = 'bet1x-development-only-secret-do-not-use-in-production-0000000000';
} elseif (strlen($APP_SECRET) < 32) {
    $fatal[] = 'APP_SECRET must be at least 32 characters of high-entropy random data.';
}

// --- Admin credentials ---
$ADMIN_USERNAME          = (string) env_get('ADMIN_USERNAME', 'admin');
$ADMIN_PASSWORD_HASH     = (string) env_get('ADMIN_PASSWORD_HASH', '');
$ADMIN_PASSWORD_PLAINTEXT = (string) env_get('ADMIN_PASSWORD', '');

$SUPERADMIN_USERNAME          = (string) env_get('SUPERADMIN_USERNAME', 'superadmin');
$SUPERADMIN_PASSWORD_HASH     = (string) env_get('SUPERADMIN_PASSWORD_HASH', '');
$SUPERADMIN_PASSWORD_PLAINTEXT = (string) env_get('SUPERADMIN_PASSWORD', '');

// An extra, pre-authentication secret that has to appear in the admin console's URL before the
// super-admin entry point is rendered at all (?superadmin_key=...). It is NOT a credential and does
// not replace SUPERADMIN_PASSWORD_HASH — the login still happens afterwards. Its only job is to keep
// the console's existence out of the page for anyone who does not already know the URL, which means
// it must never be shipped to the browser: the client sends its candidate value and the server
// answers yes or no. Blank disables the whole mechanism and hides the entry point unconditionally.
$SUPERADMIN_ACCESS_TOKEN = (string) env_get('SUPERADMIN_ACCESS_TOKEN', '');

if ($IS_PRODUCTION) {
    if ($ADMIN_PASSWORD_HASH === '') {
        $fatal[] = 'ADMIN_PASSWORD_HASH is required when NODE_ENV=production. Generate one with:  php -r "echo password_hash(\'your-password\', PASSWORD_BCRYPT, [\'cost\'=>12]);"';
    }
    if ($ADMIN_PASSWORD_PLAINTEXT !== '') {
        $fatal[] = 'ADMIN_PASSWORD (plaintext) must not be used in production — set ADMIN_PASSWORD_HASH instead.';
    }
    if ($SUPERADMIN_PASSWORD_PLAINTEXT !== '') {
        $fatal[] = 'SUPERADMIN_PASSWORD (plaintext) must not be used in production — set SUPERADMIN_PASSWORD_HASH instead.';
    }
} else {
    if ($ADMIN_PASSWORD_HASH === '' && $ADMIN_PASSWORD_PLAINTEXT === '') {
        $ADMIN_PASSWORD_PLAINTEXT = 'admin123';
    }
    if ($SUPERADMIN_PASSWORD_HASH === '' && $SUPERADMIN_PASSWORD_PLAINTEXT === '') {
        $SUPERADMIN_PASSWORD_PLAINTEXT = 'SuperAdmin@2026!';
    }
}

// --- Database ---
// DATABASE_URL keeps the same name and URL shape the Node build used (postgresql://). Discrete
// DB_* variables are also accepted because cPanel's own PostgreSQL database wizard hands those
// out separately.
$DATABASE_URL = (string) env_get('DATABASE_URL', '');
$DB = [
    'host' => (string) env_get('DB_HOST', '127.0.0.1'),
    'port' => (int) env_num('DB_PORT', 5432),
    'name' => (string) env_get('DB_NAME', ''),
    'user' => (string) env_get('DB_USER', ''),
    'pass' => (string) env_get('DB_PASS', ''),
];
if ($DATABASE_URL !== '') {
    $p = parse_url($DATABASE_URL);
    if ($p !== false) {
        if (!empty($p['host'])) $DB['host'] = $p['host'];
        if (!empty($p['port'])) $DB['port'] = (int)$p['port'];
        if (!empty($p['user'])) $DB['user'] = rawurldecode($p['user']);
        if (isset($p['pass'])) $DB['pass'] = rawurldecode($p['pass']);
        if (!empty($p['path'])) $DB['name'] = ltrim($p['path'], '/');
    }
}
if ($IS_PRODUCTION && $DB['name'] === '') {
    $fatal[] = 'DATABASE_URL (or DB_NAME/DB_USER/DB_PASS) is required when NODE_ENV=production.';
}

$ALLOW_JSON_FALLBACK = env_bool('ALLOW_JSON_FALLBACK', !$IS_PRODUCTION);

// --- HTTP ---
$TRUST_PROXY  = (int) env_num('TRUST_PROXY', 0);
$CORS_ORIGINS = env_list('CORS_ORIGINS');
$FORCE_HTTPS  = env_bool('FORCE_HTTPS', $IS_PRODUCTION);

// --- Product rules ---
$SIGNUP_BONUS            = env_num('SIGNUP_BONUS', $IS_PRODUCTION ? 0 : 2000);
$ALLOW_AUTO_USER_CREATION = env_bool('ALLOW_AUTO_USER_CREATION', false);
$BCRYPT_ROUNDS           = (int) env_num('BCRYPT_ROUNDS', 12);
$SESSION_TTL_MS          = (int) round(env_num('SESSION_TTL_HOURS', 24 * 7) * 3600 * 1000);
$MIN_BET                 = env_num('MIN_BET', 1);
$MAX_BET                 = env_num('MAX_BET', 100000);
$MIN_WITHDRAWAL          = env_num('MIN_WITHDRAWAL', 100);

// --- Testing switches ---
$DISABLE_RATE_LIMITS = env_bool('DISABLE_RATE_LIMITS', false);
if ($IS_PRODUCTION && $DISABLE_RATE_LIMITS) {
    $fatal[] = 'DISABLE_RATE_LIMITS must never be set when NODE_ENV=production — it turns off login brute-force protection.';
}

// --- House-edge engine ---
$AVIATOR_SMART_CRASH    = env_bool('AVIATOR_SMART_CRASH', true);
$AVIATOR_HIGH_STAKE_REF = env_num('AVIATOR_HIGH_STAKE_REF', 1000);

// Teen Patti pads any table short of 4 seats with randomly-named NPC fillers, and runs a background
// "organic traffic" engine that seats simulated players into rooms with nobody real in them at all
// — useful in development so a table looks alive with zero testers, but a real-money launch should
// only ever show players who actually sat down. Defaults to on in development (unchanged demo
// behaviour) and off in production, exactly like ALLOW_JSON_FALLBACK above; a round still starts
// fine with as few as 2 real players once this is off, it just no longer force-pads to 4. This does
// not touch the separate "Admin" house seat used for rigging — see the bot-seat fallback in
// teenpatti.php's tp_start_round().
$TEENPATTI_AUTO_BOT_FILL = env_bool('TEENPATTI_AUTO_BOT_FILL', !$IS_PRODUCTION);

// --- Monitoring ---
// How old the cron heartbeat may get before /api/ready reports the deployment as not-ready.
// The cron runs every minute, so 300s tolerates four consecutive missed runs before alerting —
// loose enough not to page on a single slow tick, tight enough to catch a genuinely dead cron
// while games are still recoverable. Set to 0 to disable the check.
$CRON_STALE_SECONDS = (int) env_num('CRON_STALE_SECONDS', 300);

// --- Risk controls (php-backend/lib/riskcontrols.php) ---
// Registration is unverified — no email confirmation, no phone OTP, no KYC — so these are the only
// automated brakes on bonus farming and runaway cashout velocity. Each defaults OFF (0/false) so
// that an existing deployment and the test suites behave exactly as before; the production .env
// generated by tools/make-production-env.php turns all of them on.
$SIGNUP_MAX_PER_IP_PER_DAY  = (int)   env_num('SIGNUP_MAX_PER_IP_PER_DAY', 0);
$WITHDRAWAL_DAILY_MAX       = (float) env_num('WITHDRAWAL_DAILY_MAX', 0);
$WITHDRAWAL_DAILY_COUNT_MAX = (int)   env_num('WITHDRAWAL_DAILY_COUNT_MAX', 0);
$WITHDRAWAL_REQUIRE_DEPOSIT = env_bool('WITHDRAWAL_REQUIRE_DEPOSIT', false);

// A signup bonus that can be withdrawn without ever depositing is just free money handed to whoever
// scripts the registration form. Refuse to boot on that combination rather than discovering it in
// the payout queue.
if ($IS_PRODUCTION && $SIGNUP_BONUS > 0 && !$WITHDRAWAL_REQUIRE_DEPOSIT) {
    $fatal[] = 'SIGNUP_BONUS is greater than zero while WITHDRAWAL_REQUIRE_DEPOSIT is off. '
             . 'Unverified accounts could register in bulk and cash the bonus straight out. '
             . 'Either set SIGNUP_BONUS=0 or set WITHDRAWAL_REQUIRE_DEPOSIT=true.';
}

// --- Cricket (Your 11 / Boundary Baazi) ---
// Gated off for v1 and NOT ported: with this false, no cricket route exists at all, which is
// exactly what the Node build does with the flag off. When the games are ported for v2 this is the
// switch that turns them on, alongside deleting the [data-feature="cricket"] rule in
// assets/css/style.css. Setting it true today changes nothing, because there is nothing to mount.
$CRICKET_ENABLED = env_bool('CRICKET_ENABLED', false);

$LOG_LEVEL = (string) env_get('LOG_LEVEL', 'info');

// --- Fail fast ---
if (count($fatal)) {
    http_response_code(500);
    header('Content-Type: application/json');
    error_log('[bet1x-backend] Refusing to serve — configuration is not production-safe:');
    foreach ($fatal as $msg) error_log('  x ' . $msg);
    echo json_encode(['error' => 'Server misconfigured.']);
    exit;
}

$CONFIG = [
    'NODE_ENV'                 => $NODE_ENV,
    'IS_PRODUCTION'            => $IS_PRODUCTION,
    'APP_SECRET'               => $APP_SECRET,
    'ADMIN_USERNAME'           => $ADMIN_USERNAME,
    'ADMIN_PASSWORD_HASH'      => $ADMIN_PASSWORD_HASH,
    'ADMIN_PASSWORD_PLAINTEXT' => $ADMIN_PASSWORD_PLAINTEXT,
    'SUPERADMIN_USERNAME'           => $SUPERADMIN_USERNAME,
    'SUPERADMIN_PASSWORD_HASH'      => $SUPERADMIN_PASSWORD_HASH,
    'SUPERADMIN_PASSWORD_PLAINTEXT' => $SUPERADMIN_PASSWORD_PLAINTEXT,
    'SUPERADMIN_ACCESS_TOKEN'       => $SUPERADMIN_ACCESS_TOKEN,
    'DB'                       => $DB,
    'ALLOW_JSON_FALLBACK'      => $ALLOW_JSON_FALLBACK,
    'TRUST_PROXY'              => $TRUST_PROXY,
    'CORS_ORIGINS'             => $CORS_ORIGINS,
    'FORCE_HTTPS'              => $FORCE_HTTPS,
    'SIGNUP_BONUS'             => $SIGNUP_BONUS,
    'ALLOW_AUTO_USER_CREATION' => $ALLOW_AUTO_USER_CREATION,
    'BCRYPT_ROUNDS'            => $BCRYPT_ROUNDS,
    'SESSION_TTL_MS'           => $SESSION_TTL_MS,
    'MIN_BET'                  => $MIN_BET,
    'MAX_BET'                  => $MAX_BET,
    'MIN_WITHDRAWAL'           => $MIN_WITHDRAWAL,
    'DISABLE_RATE_LIMITS'      => $DISABLE_RATE_LIMITS,
    'AVIATOR_SMART_CRASH'      => $AVIATOR_SMART_CRASH,
    'AVIATOR_HIGH_STAKE_REF'   => $AVIATOR_HIGH_STAKE_REF,
    'TEENPATTI_AUTO_BOT_FILL'  => $TEENPATTI_AUTO_BOT_FILL,
    'CRON_STALE_SECONDS'         => $CRON_STALE_SECONDS,
    'SIGNUP_MAX_PER_IP_PER_DAY'  => $SIGNUP_MAX_PER_IP_PER_DAY,
    'WITHDRAWAL_DAILY_MAX'       => $WITHDRAWAL_DAILY_MAX,
    'WITHDRAWAL_DAILY_COUNT_MAX' => $WITHDRAWAL_DAILY_COUNT_MAX,
    'WITHDRAWAL_REQUIRE_DEPOSIT' => $WITHDRAWAL_REQUIRE_DEPOSIT,
    'CRICKET_ENABLED'          => $CRICKET_ENABLED,
    'LOG_LEVEL'                => $LOG_LEVEL,
    'DATA_DIR'                 => dirname(__DIR__) . '/backend/data',
    'STATIC_ROOT'              => dirname(__DIR__),
];

/** Read one configuration value. */
function cfg($key, $default = null) {
    global $CONFIG;
    return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default;
}
