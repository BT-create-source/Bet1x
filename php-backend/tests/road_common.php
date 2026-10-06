<?php
/**
 * Shared helpers for the cricket road tests (php-backend/tests/road_*.php).
 *
 * The road tests run against the REAL development database that php-backend/.env points at, through the
 * same functions the HTTP routes call. They only ever touch their own data: users named road_* and mock
 * fixtures in far-future calendar slots, all removed at the end (pass --keep to leave them for
 * inspection). Never point them at a production database.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

putenv('CRICKET_SOURCE=mock');
putenv('FANTASY_SOURCE=feed');
putenv('CRICKET_ENABLED=true');
putenv('FANTASY_ENABLED=true');
if (getenv('CRICKET_MOCK_ABANDON_EVERY') === false) putenv('CRICKET_MOCK_ABANDON_EVERY=0');
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
error_reporting(E_ALL & ~E_DEPRECATED);

define('ROAD_ROOT', dirname(__DIR__));
require_once ROAD_ROOT . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-roanuz', 'bbb', 'exchange', 'fantasy-feed'] as $f) {
    require_once ROAD_ROOT . "/lib/$f.php";
}

$GLOBALS['ROAD'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'section' => '', 'started' => microtime(true), 'log' => []];
$GLOBALS['ROAD_KEEP'] = in_array('--keep', $argv ?? [], true);

function road_section($title) {
    $GLOBALS['ROAD']['section'] = $title;
    $line = "\n== $title ==";
    echo $line, "\n";
    $GLOBALS['ROAD']['log'][] = $line;
}

function road_check($cond, $label, $detail = null) {
    if ($cond) { $GLOBALS['ROAD']['pass']++; $line = "  PASS  $label"; }
    else {
        $GLOBALS['ROAD']['fail']++;
        $GLOBALS['ROAD']['failures'][] = '[' . $GLOBALS['ROAD']['section'] . '] ' . $label . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail)) : '');
        $line = "  FAIL  $label" . ($detail !== null ? "\n        " . (is_string($detail) ? $detail : json_encode($detail)) : '');
    }
    echo $line, "\n";
    $GLOBALS['ROAD']['log'][] = $line;
}

function road_info($msg) { $line = "  info  $msg"; echo $line, "\n"; $GLOBALS['ROAD']['log'][] = $line; }

function road_near($a, $b, $eps = 0.011) { return abs((float) $a - (float) $b) < $eps; }

/** Run the clock at a simulated instant (CLI only; see now_ms()). */
function road_at($ms) { $GLOBALS['BET1X_TEST_NOW_MS'] = (int) $ms; }
function road_clock_off() { unset($GLOBALS['BET1X_TEST_NOW_MS']); }

/** A far-future mock slot nobody else uses, so road tests never collide with the live demo league. */
function road_slot($base) { return $base + random_int(0, 90000) * 3; }

/** Create (or reset) $n users road_<prefix>NN with the given balances. Returns rows keyed by username. */
function road_users($prefix, $n, callable $balanceFor) {
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $name = 'road_' . $prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $row = get_or_create_user($name, true);
        $bal = (float) $balanceFor($i);
        q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$bal, (int) $row['id']]);
        q('DELETE FROM "Transaction" WHERE "user" = ?', [$name]);
        $u = find_user_by_id($row['id']);
        $u['_start'] = $bal;
        $out[$name] = $u;
    }
    return $out;
}

function road_balance($u) { return (float) find_user_by_id($u['id'])['wallet_balance']; }

/** Every ledger row for a user, summed by type. */
function road_ledger($username) {
    $in  = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$username, 'Deposit'], 0);
    $out = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "type" = ?', [$username, 'Withdrawal'], 0);
    return ['in' => $in, 'out' => $out];
}

function road_wipe_match($key) {
    q('DELETE FROM "fantasy_matches" WHERE "feed_key" = ?', [$key]);
    foreach (['mx_bets', 'mx_markets', 'mx_match_settings', 'bbb_rounds', 'cricket_deliveries', 'cricket_feed_raw', 'cricket_match_feed'] as $t) {
        q('DELETE FROM "' . $t . '" WHERE "match_key" = ?', [$key]);
    }
}

function road_wipe_users($prefix) {
    $ids = array_map('intval', array_column(all('SELECT "id" FROM "User" WHERE "username" LIKE ?', ['road_' . $prefix . '%']), 'id'));
    foreach ($ids as $id) {
        q('DELETE FROM "bbb_bets" WHERE "user_id" = ?', [$id]);
        q('DELETE FROM "mx_bets" WHERE "user_id" = ?', [$id]);
    }
    q('DELETE FROM "Transaction" WHERE "user" LIKE ?', ['road_' . $prefix . '%']);
    q('DELETE FROM "User" WHERE "username" LIKE ?', ['road_' . $prefix . '%']);
}

/** A normalised mock fixture for $slot, as the fixtures endpoint would deliver it. */
function road_mock_fixture($slot) {
    $meta = cricket_mock_match_meta($slot);
    return roanuz_normalise_fixture([
        'key' => $meta['key'], 'name' => $meta['title'], 'short_name' => '', 'format' => 't20',
        'start_at' => (int) floor($meta['start_ms'] / 1000), 'status' => 'not_started',
        'teams' => ['a' => $meta['team_a'], 'b' => $meta['team_b']], 'venue' => ['name' => $meta['venue']],
        '_tournament' => ['key' => 'road_league', 'name' => 'Road Test League'],
    ]);
}

/** A random legal XI (shape picked at random among legal ones), or null. */
function road_legal_xi($matchId, $seed) {
    $g = fantasy_players_grouped($matchId);
    $shapes = [[1, 4, 2, 4], [1, 3, 3, 4], [2, 4, 1, 4], [1, 5, 2, 3], [1, 3, 4, 3], [2, 3, 2, 4], [1, 4, 3, 3]];
    mt_srand($seed);
    for ($try = 0; $try < 800; $try++) {
        $s = $shapes[mt_rand(0, count($shapes) - 1)];
        $pick = [];
        foreach (['WK', 'BAT', 'ALL', 'BOWL'] as $i => $role) {
            $pool = $g[$role]; shuffle($pool);
            if (count($pool) < $s[$i]) continue 2;
            $pick = array_merge($pick, array_slice($pool, 0, $s[$i]));
        }
        $cred = array_sum(array_column($pick, 'credits'));
        $sides = array_count_values(array_column($pick, 'team_name'));
        if ($cred <= 100 && max($sides) <= 7) return $pick;
    }
    return null;
}

/**
 * Launch $n worker processes that each run one task at the same instant, and collect their results.
 * Used to throw truly simultaneous requests at the database — joins racing for the last spot, two bets
 * racing for one balance — which a single PHP process can never do.
 */
function road_parallel(array $tasks) {
    $worker = __DIR__ . '/road_worker.php';
    $startAt = (int) round(microtime(true) * 1000) + 1500;
    $procs = [];
    foreach ($tasks as $i => $task) {
        $task['start_at'] = $startAt;
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'road_task_' . getmypid() . '_' . $i . '.json';
        file_put_contents($file, json_encode($task));
        $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=pdo_pgsql ' . escapeshellarg($worker) . ' ' . escapeshellarg($file);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes, $file];
    }
    $results = [];
    foreach ($procs as [$p, $pipes, $file]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        @unlink($file);
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
        $last = $lines ? end($lines) : '';
        $res = json_decode($last, true);
        $results[] = is_array($res) ? $res : ['ok' => false, 'error' => 'worker crashed', 'raw' => substr($out . $err, 0, 400)];
    }
    return $results;
}

/** Print the summary, write a report under tests/reports/, and exit with the right code. */
function road_finish($name) {
    $r = $GLOBALS['ROAD'];
    $secs = round(microtime(true) - $r['started'], 1);
    $summary = "\n------------------------------------------------------------\n$name: {$r['pass']} passed, {$r['fail']} failed ({$secs}s)\n";
    echo $summary;
    if ($r['failures']) { echo "Failures:\n"; foreach ($r['failures'] as $f) echo "  - $f\n"; }
    $dir = __DIR__ . '/reports';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/' . $name . '-' . gmdate('Ymd-His') . '.txt',
        $name . ' run at ' . gmdate('c') . "\n" . implode("\n", $r['log']) . $summary
        . ($r['failures'] ? "Failures:\n  - " . implode("\n  - ", $r['failures']) . "\n" : ''));
    exit($r['fail'] ? 1 : 0);
}
