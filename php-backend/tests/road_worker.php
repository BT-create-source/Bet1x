<?php
/**
 * One parallel worker for the road tests: reads a task file, waits for the shared start instant, runs
 * the task through the same function the HTTP route calls, and prints one JSON line with the result.
 * Spawned many at a time by road_parallel() so the database sees genuinely simultaneous requests.
 */
if (PHP_SAPI !== 'cli') exit(1);
putenv('CRICKET_SOURCE=mock');
putenv('FANTASY_SOURCE=feed');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
$root = dirname(__DIR__);
require_once $root . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-roanuz', 'bbb', 'fantasy-feed'] as $f) require_once "$root/lib/$f.php";

$task = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($task)) { echo json_encode(['ok' => false, 'error' => 'bad task']), "\n"; exit(0); }
db();   // connect before the barrier so every worker fires at the same moment
while ((int) round(microtime(true) * 1000) < (int) $task['start_at']) usleep(2000);

try {
    $user = isset($task['user_id']) ? find_user_by_id((int) $task['user_id']) : null;
    switch ($task['action']) {
        case 'join':
            $r = fantasy_join_contest((int) $user['id'], $user['username'], (int) $task['contest_id'], (int) $task['team_id']);
            break;
        case 'bbb_bet':
            $r = bbb_place_bet($user, (int) $task['round_id'], (string) $task['outcome'], $task['stake']);
            break;
        case 'settle_contest':
            $r = fantasy_settle_contest((int) $task['contest_id'], false);
            break;
        case 'bbb_settle':
            $r = bbb_process_match($task['match_key'], (int) $task['now']);
            break;
        case 'ingest':
            // The same push delivered by several processes at once — duplicate webhooks racing to settle.
            $r = cricket_feed_ingest(cricket_mock_snapshot($task['match_key'], (int) $task['at']), 'push', (int) $task['at']);
            break;
        default:
            $r = ['ok' => false, 'error' => 'unknown action'];
    }
} catch (Throwable $e) {
    $r = ['ok' => false, 'error' => 'exception: ' . $e->getMessage()];
}
echo json_encode($r), "\n";
