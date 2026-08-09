<?php
/**
 * Game Sync API - Synchronizes game states, countdowns, wagers, and admin overrides (Unified Backend)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/teenpatti_logic.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$username = $_SESSION['username'] ?? 'DemoUser';

// Shared state file paths
define('COLOR_STATE_FILE', DATA_DIR . '/color_guess_ongoing.json');
define('AVIATOR_STATE_FILE', DATA_DIR . '/aviator_ongoing.json');
define('TEENPATTI_STATE_FILE', DATA_DIR . '/teenpatti_ongoing.json');

// --- Helper: Read/Write Shared Game States Safely ---
function load_sync_state(string $file, array $default): array {
    $key = basename($file, '.json');
    $data = db_api_request('GET', '/api/db/state/' . $key);
    if ($data === null) {
        db_api_request('POST', '/api/db/state/' . $key, ['data' => $default]);
        return $default;
    }
    return is_array($data) ? $data : $default;
}

function save_sync_state(string $file, array $data): void {
    $key = basename($file, '.json');
    db_api_request('POST', '/api/db/state/' . $key, ['data' => $data]);
}

$colorDurations = ['sapre' => 30, 'becone' => 60, 'emred' => 180, 'vip' => 300];

// --- Helper: Ensure Dummy Bets for Color Guess ---
function ensure_dummy_bets(string $room, string $round_id, array &$state): void {
    if (!isset($state[$room]['bets'][$round_id])) {
        $state[$room]['bets'][$round_id] = [];
    }
}

// --- Helper: Ensure Dummy Bets for Aviator ---
function ensure_aviator_dummy_bets(array &$state): void {
    if (!isset($state['bets']) || !is_array($state['bets'])) {
        $state['bets'] = [];
    }
}

function progress_aviator_state(array &$state, float $now): bool {
    $elapsed = $now - $state['phase_start'];
    $state_changed = false;

    if ($state['phase'] === 'waiting') {
        ensure_aviator_dummy_bets($state);
        
        if ($elapsed >= $state['duration']) {
            $state['phase'] = 'running';
            $state['phase_start'] = $now;
            
            if (!empty($state['admin_override']) && floatval($state['admin_override']) >= 1.0) {
                $state['crash_point'] = floatval($state['admin_override']);
                unset($state['admin_override']);
            } else {
                $p = mt_rand() / mt_getrandmax();
                if (rand(1, 33) === 1) {
                    $state['crash_point'] = 1.00;
                } else {
                    $crash = (100 - $p) / (1 - $p);
                    $state['crash_point'] = max(1.00, floor($crash * 100) / 100);
                }
            }
            $state_changed = true;
        }
    } elseif ($state['phase'] === 'running') {
        $current_multiplier = exp(0.06 * $elapsed);
        
        foreach ($state['bets'] as &$b) {
            if (!empty($b['is_dummy']) && $b['status'] === 'pending') {
                if ($current_multiplier >= $b['target_mult'] && $b['target_mult'] < $state['crash_point']) {
                    $b['status'] = 'won';
                    $b['cashed_multiplier'] = $b['target_mult'];
                    $state_changed = true;
                }
            }
        }

        if ($current_multiplier >= $state['crash_point']) {
            $state['phase'] = 'crashed';
            $state['phase_start'] = $now;
            
            foreach ($state['bets'] as &$b) {
                if ($b['status'] === 'pending') {
                    $b['status'] = 'lost';
                }
            }
            unset($b);
            
            $state['history'][] = $state['crash_point'];
            if (count($state['history']) > 15) {
                array_shift($state['history']);
            }
            $state_changed = true;
        }
    } elseif ($state['phase'] === 'crashed') {
        if ($elapsed >= 3.0) {
            $state['phase'] = 'waiting';
            $state['phase_start'] = $now;
            $state['round_id']++;
            $state['bets'] = [];
            $state_changed = true;
        }
    }
    return $state_changed;
}

function get_color_round_id(string $room, int $timestamp): string {
    global $colorDurations;
    $duration = $colorDurations[$room] ?? 30;
    $round_start = floor($timestamp / $duration) * $duration;
    return date('YmdH', $round_start) . str_pad(floor(($round_start % 3600) / $duration), 3, '0', STR_PAD_LEFT);
}

function resolve_color_number(int $num): array {
    if ($num === 0) return ['color' => 'Red-Violet', 'dotClass' => 'violet', 'size' => 'Small'];
    if ($num === 5) return ['color' => 'Green-Violet', 'dotClass' => 'violet', 'size' => 'Big'];
    if (in_array($num, [1, 3, 7, 9])) return ['color' => 'Green', 'dotClass' => 'green', 'size' => $num >= 5 ? 'Big' : 'Small'];
    return ['color' => 'Red', 'dotClass' => 'red', 'size' => $num >= 5 ? 'Big' : 'Small'];
}

function calculate_color_payout(array $bets, int $num): float {
    $resolved = resolve_color_number($num);
    $total = 0;
    foreach ($bets as $b) {
        $won = false;
        $mult = 0;
        if ($b['category'] === 'number') { $won = intval($b['value']) === $num; $mult = 9; }
        elseif ($b['category'] === 'size') { $won = $b['value'] === $resolved['size']; $mult = 2; }
        elseif ($b['category'] === 'color') {
            $bc = strtolower($b['value']);
            if ($bc === 'violet') { $won = ($num === 0 || $num === 5); $mult = 4.5; }
            else {
                if ($num === 0 && $bc === 'red') { $won = true; $mult = 1.5; }
                elseif ($num === 5 && $bc === 'green') { $won = true; $mult = 1.5; }
                elseif ($bc === 'green' && in_array($num, [1, 3, 7, 9])) { $won = true; $mult = 2; }
                elseif ($bc === 'red' && in_array($num, [2, 4, 6, 8])) { $won = true; $mult = 2; }
            }
        }
        if ($won) $total += $b['amount'] * $mult;
    }
    return $total;
}

function settle_color_room(string $room, string $target_round, array &$state): void {
    $bets = $state[$room]['bets'][$target_round] ?? [];
    $override = $state[$room]['overrides'] ?? [];
    $num = null;

    if (isset($override['number']) && $override['number'] !== '') {
        $num = intval($override['number']);
    } else {
        $possible = range(0, 9);
        if (!empty($override['color'])) {
            $c = $override['color'];
            if ($c === 'Green') $possible = array_intersect($possible, [1, 3, 5, 7, 9]);
            elseif ($c === 'Red') $possible = array_intersect($possible, [0, 2, 4, 6, 8]);
            elseif ($c === 'Violet') $possible = array_intersect($possible, [0, 5]);
        }
        if (!empty($override['size'])) {
            $sz = $override['size'];
            if ($sz === 'Small') $possible = array_intersect($possible, [0, 1, 2, 3, 4]);
            elseif ($sz === 'Big') $possible = array_intersect($possible, [5, 6, 7, 8, 9]);
        }
        $possible = array_values($possible);
        if (!empty($possible)) {
            $num = $possible[array_rand($possible)];
        } elseif (!empty($override['rig_type']) && count($bets) > 0) {
            $rig = $override['rig_type'];
            $allNums = range(0, 9);
            $bestNum = 0;
            if ($rig === 'platform_profit') {
                $minPayout = 999999999;
                foreach ($allNums as $n) {
                    $payout = calculate_color_payout($bets, $n);
                    if ($payout < $minPayout) {
                        $minPayout = $payout;
                        $bestNum = $n;
                    }
                }
                $num = $bestNum;
            } elseif ($rig === 'user_win') {
                $maxPayout = -1;
                foreach ($allNums as $n) {
                    $payout = calculate_color_payout($bets, $n);
                    if ($payout > $maxPayout) {
                        $maxPayout = $payout;
                        $bestNum = $n;
                    }
                }
                $num = $bestNum;
            }
        }
    }

    if ($num === null) {
        $num = rand(0, 9);
    }

    $resolved = resolve_color_number($num);

    foreach ($bets as $b) {
        $won = false;
        $mult = 0;
        if ($b['category'] === 'number') {
            $won = intval($b['value']) === $num;
            $mult = 9;
        } elseif ($b['category'] === 'size') {
            $won = $b['value'] === $resolved['size'];
            $mult = 2;
        } elseif ($b['category'] === 'color') {
            $bc = strtolower($b['value']);
            if ($bc === 'violet') {
                $won = ($num === 0 || $num === 5);
                $mult = 4.5;
            } else {
                if ($num === 0 && $bc === 'red') { $won = true; $mult = 1.5; }
                elseif ($num === 5 && $bc === 'green') { $won = true; $mult = 1.5; }
                elseif ($bc === 'green' && in_array($num, [1, 3, 7, 9])) { $won = true; $mult = 2; }
                elseif ($bc === 'red' && in_array($num, [2, 4, 6, 8])) { $won = true; $mult = 2; }
            }
        }

        if ($won) {
            $payout = $b['amount'] * $mult;
            if (empty($b['is_dummy'])) {
                db_adjust_wallet($b['username'], $payout, "Color Guess Win: Room " . ucfirst($room) . " Round #$target_round");
                db_log_transaction($b['username'], 'Deposit', $payout, "Color Room Win: $room #$target_round", 'Completed');
            }
        }
    }

    $was_rigged = !empty($override['number']) || !empty($override['color']) || !empty($override['size']) || !empty($override['rig_type']);
    $rig_desc = '';
    if (!empty($override['number'])) $rig_desc .= "Number Fixed: {$override['number']} ";
    if (!empty($override['color'])) $rig_desc .= "Color Fixed: {$override['color']} ";
    if (!empty($override['size'])) $rig_desc .= "Size Fixed: {$override['size']} ";
    if (!empty($override['rig_type'])) $rig_desc .= "Auto-Rig: {$override['rig_type']} ";

    $result_entry = [
        'roundNumber' => $target_round,
        'number' => $num,
        'color' => $resolved['color'],
        'dotClass' => $resolved['dotClass'],
        'size' => $resolved['size'],
        'is_rigged' => $was_rigged,
        'rig_desc' => trim($rig_desc),
        'timestamp' => date('H:i:s')
    ];
    $state[$room]['history'][] = $result_entry;
    $state[$room]['last_result'] = $result_entry;

    // Save to PostgreSQL via Express API
    db_api_request('POST', '/api/db/recent-results', [
        'room' => $room,
        'roundNumber' => $target_round,
        'number' => $num,
        'color' => $resolved['color'],
        'dotClass' => $resolved['dotClass'],
        'size' => $resolved['size']
    ]);

    if (count($state[$room]['history']) > 20) {
        array_shift($state[$room]['history']);
    }

    unset($state[$room]['bets'][$target_round]);
    $state[$room]['overrides'] = ['color' => '', 'number' => '', 'size' => '', 'rig_type' => ''];
    $state[$room]['last_settled_round'] = $target_round;
}

switch ($action) {
    case 'color_get_state': {
        $room = $_GET['room'] ?? 'sapre';
        $duration = $colorDurations[$room] ?? 30;
        
        $state = load_sync_state(COLOR_STATE_FILE, [
            'sapre' => ['last_settled_round' => '', 'bets' => [], 'overrides' => [], 'history' => []],
            'becone' => ['last_settled_round' => '', 'bets' => [], 'overrides' => [], 'history' => []],
            'emred' => ['last_settled_round' => '', 'bets' => [], 'overrides' => [], 'history' => []],
            'vip' => ['last_settled_round' => '', 'bets' => [], 'overrides' => [], 'history' => []]
        ]);

        $now = time();
        $current_round_id = get_color_round_id($room, $now);
        $time_left = $duration - ($now % $duration);

        ensure_dummy_bets($room, $current_round_id, $state);

        $last_settled = $state[$room]['last_settled_round'] ?? '';
        $prev_round_time = floor($now / $duration) * $duration - $duration;
        $prev_round_id = get_color_round_id($room, $prev_round_time);

        if ($last_settled !== $prev_round_id) {
            settle_color_room($room, $prev_round_id, $state);
        }
        
        save_sync_state(COLOR_STATE_FILE, $state);

        $raw_bets = $state[$room]['bets'][$current_round_id] ?? [];
        $elapsed = $duration - $time_left;
        
        $active_bets = [];
        foreach ($raw_bets as $b) {
            if (!empty($b['is_dummy'])) {
                if ($elapsed >= ($b['reveal_second'] ?? 0)) {
                    $active_bets[] = $b;
                }
            } else {
                $active_bets[] = $b;
            }
        }
        
        $aggs = [
            'Green' => ['count' => 0, 'amount' => 0],
            'Red' => ['count' => 0, 'amount' => 0],
            'Violet' => ['count' => 0, 'amount' => 0],
            'Big' => ['count' => 0, 'amount' => 0],
            'Small' => ['count' => 0, 'amount' => 0],
            'num' => array_fill(0, 10, ['count' => 0, 'amount' => 0])
        ];
        foreach ($active_bets as $b) {
            $cat = $b['category'];
            $val = $b['value'];
            $amt = $b['amount'];
            if ($cat === 'color') {
                if (in_array($val, ['Green', 'Red', 'Violet'])) {
                    $aggs[$val]['count']++;
                    $aggs[$val]['amount'] += $amt;
                }
            } elseif ($cat === 'size') {
                if (in_array($val, ['Big', 'Small'])) {
                    $aggs[$val]['count']++;
                    $aggs[$val]['amount'] += $amt;
                }
            } elseif ($cat === 'number') {
                $n = intval($val);
                if ($n >= 0 && $n <= 9) {
                    $aggs['num'][$n]['count']++;
                    $aggs['num'][$n]['amount'] += $amt;
                }
            }
        }

        $my_bets = [];
        foreach ($active_bets as $b) {
            if (strtolower($b['username']) === strtolower($username)) {
                $my_bets[] = $b;
            }
        }

        $users = db_read('users');
        $bal = 0.00;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $bal = (float)$u['wallet_balance'];
                break;
            }
        }

        // Fetch history from PostgreSQL database
        $db_history = db_api_request('GET', '/api/db/recent-results?room=' . $room);
        if (is_array($db_history) && !empty($db_history)) {
            $state[$room]['history'] = array_reverse($db_history);
        }

        echo json_encode([
            'round_id' => $current_round_id,
            'time_left' => $time_left,
            'bets_count' => count($active_bets),
            'history' => array_values($state[$room]['history']),
            'last_result' => $state[$room]['last_result'] ?? (count($state[$room]['history']) > 0 ? end($state[$room]['history']) : null),
            'my_bets' => $my_bets,
            'wallet_balance' => $bal,
            'aggregates' => $aggs,
            'overrides' => $state[$room]['overrides'] ?? []
        ]);
        break;
    }

    case 'color_place_bet': {
        $room = $_POST['room'] ?? 'sapre';
        $category = $_POST['category'] ?? '';
        $value = $_POST['value'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);

        if (empty($category) || $value === '' || $amount <= 0) {
            echo json_encode(['error' => 'Invalid bet details.']);
            exit;
        }

        $users = db_read('users');
        $bal = 0.00;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $bal = (float)$u['wallet_balance'];
                break;
            }
        }

        if ($bal < $amount) {
            echo json_encode(['error' => 'Insufficient wallet balance.']);
            exit;
        }

        $adj = db_adjust_wallet($username, -$amount, "Color Guess Wager: Room " . ucfirst($room));
        if (isset($adj['error'])) {
            echo json_encode(['error' => $adj['error']]);
            exit;
        }

        $state = load_sync_state(COLOR_STATE_FILE, []);
        $now = time();
        $current_round_id = get_color_round_id($room, $now);

        if (!isset($state[$room]['bets'][$current_round_id])) {
            $state[$room]['bets'][$current_round_id] = [];
        }

        $state[$room]['bets'][$current_round_id][] = [
            'username' => $username,
            'category' => $category,
            'value' => $value,
            'amount' => $amount,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        save_sync_state(COLOR_STATE_FILE, $state);

        echo json_encode(['success' => true, 'new_balance' => $adj['new_balance']]);
        break;
    }

    case 'aviator_get_state': {
        $state = load_sync_state(AVIATOR_STATE_FILE, [
            'round_id' => 10001,
            'phase' => 'waiting',
            'phase_start' => microtime(true),
            'duration' => 8.0,
            'crash_point' => 1.85,
            'bets' => [],
            'history' => [1.25, 4.80, 1.05, 2.10, 1.62]
        ]);

        $now = microtime(true);
        $state_changed = progress_aviator_state($state, $now);

        if ($state_changed || $state['phase'] === 'waiting') {
            save_sync_state(AVIATOR_STATE_FILE, $state);
        }

        $elapsed = $now - $state['phase_start'];

        $users = db_read('users');
        $bal = 0.00;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $bal = (float)$u['wallet_balance'];
                break;
            }
        }

        $current_mult = 1.00;
        if ($state['phase'] === 'running') {
            $current_mult = exp(0.06 * ($now - $state['phase_start']));
        } elseif ($state['phase'] === 'crashed') {
            $current_mult = $state['crash_point'];
        }

        $my_wagers = [];
        foreach ($state['bets'] as $b) {
            if (strtolower($b['username']) === strtolower($username)) {
                $my_wagers[] = $b;
            }
        }

        $filtered_av_bets = [];
        foreach ($state['bets'] as $b) {
            if (!empty($b['is_dummy'])) {
                if ($state['phase'] === 'waiting') {
                    if ($elapsed >= ($b['reveal_second'] ?? 0)) {
                        $filtered_av_bets[] = $b;
                    }
                } else {
                    $filtered_av_bets[] = $b;
                }
            } else {
                $filtered_av_bets[] = $b;
            }
        }

        echo json_encode([
            'round_id' => $state['round_id'],
            'phase' => $state['phase'],
            'time_elapsed' => $elapsed,
            'time_left' => max(0, $state['duration'] - $elapsed),
            'current_multiplier' => $current_mult,
            'crash_point' => $state['crash_point'],
            'history' => array_values($state['history']),
            'bets' => array_values($filtered_av_bets),
            'my_wagers' => $my_wagers,
            'wallet_balance' => $bal
        ]);
        break;
    }

    case 'aviator_place_bet': {
        $amount = floatval($_POST['amount'] ?? 0);
        $consoleId = intval($_POST['console_id'] ?? 1);
        if ($amount <= 0) {
            echo json_encode(['error' => 'Invalid wager amount.']);
            exit;
        }

        $state = load_sync_state(AVIATOR_STATE_FILE, []);
        if ($state['phase'] !== 'waiting') {
            echo json_encode(['error' => 'Bets can only be placed during the taking off waiting phase.']);
            exit;
        }

        $users = db_read('users');
        $bal = 0.00;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $bal = (float)$u['wallet_balance'];
                break;
            }
        }

        if ($bal < $amount) {
            echo json_encode(['error' => 'Insufficient wallet balance.']);
            exit;
        }

        $adj = db_adjust_wallet($username, -$amount, "Aviator Wager Round #" . $state['round_id']);
        if (isset($adj['error'])) {
            echo json_encode(['error' => $adj['error']]);
            exit;
        }

        $state['bets'][] = [
            'username' => $username,
            'amount' => $amount,
            'status' => 'pending',
            'cashed_multiplier' => 0,
            'console_id' => $consoleId
        ];
        save_sync_state(AVIATOR_STATE_FILE, $state);

        echo json_encode(['success' => true, 'new_balance' => $adj['new_balance']]);
        break;
    }

    case 'aviator_cashout': {
        $consoleId = intval($_POST['console_id'] ?? $_GET['console_id'] ?? 1);
        $state = load_sync_state(AVIATOR_STATE_FILE, []);
        if ($state['phase'] !== 'running') {
            echo json_encode(['error' => 'Aviator crash game is not running.']);
            exit;
        }

        $now = microtime(true);
        $elapsed = $now - $state['phase_start'];
        $current_multiplier = exp(0.06 * $elapsed);

        if ($current_multiplier >= $state['crash_point']) {
            echo json_encode(['error' => 'Busted! Plane already crashed.']);
            exit;
        }

        $found = false;
        foreach ($state['bets'] as &$b) {
            if (strtolower($b['username']) === strtolower($username) && $b['status'] === 'pending' && intval($b['console_id'] ?? 1) === $consoleId) {
                $b['status'] = 'won';
                $b['cashed_multiplier'] = $current_multiplier;
                
                $payout = $b['amount'] * $current_multiplier;
                db_adjust_wallet($username, $payout, "Aviator Cashout Payout @ " . round($current_multiplier, 2) . "x");
                db_log_transaction($username, 'Deposit', $payout, "Aviator Cashout Win @" . round($current_multiplier, 2) . "x", 'Completed');
                
                $found = $payout;
                break;
            }
        }

        if ($found === false) {
            echo json_encode(['error' => 'No active wagers found for cashout.']);
            exit;
        }

        save_sync_state(AVIATOR_STATE_FILE, $state);
        echo json_encode(['success' => true, 'payout' => $found, 'multiplier' => $current_multiplier]);
        break;
    }

    case 'teenpatti_get_state': {
        $all_tp = load_sync_state(TEENPATTI_STATE_FILE, []);
        
        if (!isset($all_tp[$username])) {
            $game = $_SESSION['game'] ?? null;
            if ($game) {
                $all_tp[$username] = $game;
                save_sync_state(TEENPATTI_STATE_FILE, $all_tp);
            } else {
                echo json_encode(['exists' => false]);
                exit;
            }
        }

        $game = &$all_tp[$username];
        $now = time();

        if ($game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
            if (!isset($game['turn_start'])) {
                $game['turn_start'] = $now;
            }
            $elapsed = $now - $game['turn_start'];
            $time_left = max(0, 15 - $elapsed);
            
            if ($elapsed >= 15) {
                playerFold($game, 'human');
                addLog($game, "Aapka time out ho gaya! Pack ho gaye.");
                
                if (!checkGameEnd($game)) {
                    nextTurn($game);
                    processBotTurns($game);
                }
                
                $game['turn_start'] = time();
                save_sync_state(TEENPATTI_STATE_FILE, $all_tp);
            }
        } else {
            $time_left = 15;
        }

        $revealAll = ($game['status'] === 'finished');
        $players = [];
        foreach ($game['players'] as $key => $p) {
            $players[$key] = [
                'name' => $p['name'],
                'is_bot' => $p['is_bot'],
                'folded' => $p['folded'],
                'balance' => $p['balance'],
                'cards' => ($key === 'human' || $revealAll) ? formatCards($p['cards']) : null,
                'hand_label' => ($key === 'human' || $revealAll) ? handLabel(evaluateHand($p['cards'])[0]) : null,
            ];
        }

        echo json_encode([
            'exists' => true,
            'players' => $players,
            'order' => $game['order'],
            'turn' => currentPlayerKey($game),
            'pot' => $game['pot'],
            'current_stake' => $game['current_stake'],
            'status' => $game['status'],
            'winner' => $game['winner'],
            'time_left' => $time_left,
            'log' => array_slice($game['log'], -8)
        ]);
        break;
    }

    case 'admin_get_games': {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            echo json_encode(['error' => 'Unauthorized admin access.']);
            exit;
        }

        $colors = load_sync_state(COLOR_STATE_FILE, []);
        $colorData = [];
        $now = time();
        $colors_changed = false;
        foreach ($colorDurations as $room => $dur) {
            $r_id = get_color_round_id($room, $now);
            $t_left = $dur - ($now % $dur);
            
            $last_settled = $colors[$room]['last_settled_round'] ?? '';
            $prev_round_time = floor($now / $dur) * $dur - $dur;
            $prev_round_id = get_color_round_id($room, $prev_round_time);

            if ($last_settled !== $prev_round_id) {
                settle_color_room($room, $prev_round_id, $colors);
                $colors_changed = true;
            }

            if (!isset($colors[$room]['bets'][$r_id])) {
                ensure_dummy_bets($room, $r_id, $colors);
                $colors_changed = true;
            }
            
            $raw_bets = $colors[$room]['bets'][$r_id] ?? [];
            $elapsed = $dur - $t_left;
            $bets = [];
            foreach ($raw_bets as $b) {
                if (!empty($b['is_dummy'])) {
                    if ($elapsed >= ($b['reveal_second'] ?? 0)) {
                        $bets[] = $b;
                    }
                } else {
                    $bets[] = $b;
                }
            }
            
            $total_stake = 0;
            foreach ($bets as $b) { $total_stake += $b['amount']; }

            $colorData[$room] = [
                'round_id' => $r_id,
                'time_left' => $t_left,
                'duration' => $dur,
                'bets' => $bets,
                'total_stake' => $total_stake,
                'overrides' => $colors[$room]['overrides'] ?? []
            ];
        }
        if ($colors_changed) {
            save_sync_state(COLOR_STATE_FILE, $colors);
        }

        $aviator = load_sync_state(AVIATOR_STATE_FILE, [
            'round_id' => 10001,
            'phase' => 'waiting',
            'phase_start' => microtime(true),
            'duration' => 8.0,
            'crash_point' => 1.85,
            'bets' => []
        ]);
        $av_now = microtime(true);
        $aviator_changed = progress_aviator_state($aviator, $av_now);
        if ($aviator_changed) {
            save_sync_state(AVIATOR_STATE_FILE, $aviator);
        }
        $av_elapsed = $av_now - $aviator['phase_start'];
        $av_mult = 1.00;
        if ($aviator['phase'] === 'running') {
            $av_mult = exp(0.06 * $av_elapsed);
        } elseif ($aviator['phase'] === 'crashed') {
            $av_mult = $aviator['crash_point'];
        }

        $tp_ongoing = load_sync_state(TEENPATTI_STATE_FILE, []);
        $tpData = [];
        $tp_now = time();
        foreach ($tp_ongoing as $pname => $g) {
            if ($g['status'] === 'playing') {
                $t_left = 15;
                if (currentPlayerKey($g) === 'human') {
                    $t_left = max(0, 15 - ($tp_now - ($g['turn_start'] ?? $tp_now)));
                }
                $tpData[] = [
                    'username' => $pname,
                    'pot' => $g['pot'],
                    'turn' => currentPlayerKey($g),
                    'players' => $g['players'],
                    'time_left' => $t_left
                ];
            }
        }

        echo json_encode([
            'color_guess' => $colorData,
            'aviator' => [
                'round_id' => $aviator['round_id'],
                'phase' => $aviator['phase'],
                'time_left' => max(0, $aviator['duration'] - $av_elapsed),
                'current_multiplier' => $av_mult,
                'bets' => (function() use ($aviator, $av_elapsed) {
                    $res = [];
                    foreach ($aviator['bets'] as $b) {
                        if (!empty($b['is_dummy'])) {
                            if ($aviator['phase'] === 'waiting') {
                                if ($av_elapsed >= ($b['reveal_second'] ?? 0)) {
                                    $res[] = $b;
                                }
                            } else {
                                $res[] = $b;
                            }
                        } else {
                            $res[] = $b;
                        }
                    }
                    return $res;
                })(),
                'override' => $aviator['admin_override'] ?? ''
            ],
            'teen_patti' => $tpData
        ]);
        break;
    }

    case 'admin_set_override': {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            echo json_encode(['error' => 'Unauthorized admin access.']);
            exit;
        }

        $game = $_POST['game'] ?? '';
        if (empty($game)) {
            echo json_encode(['error' => 'Target game is required.']);
            exit;
        }

        if ($game === 'color_guess') {
            $room = $_POST['room'] ?? '';
            $color = $_POST['color'] ?? '';
            $number = $_POST['number'] ?? '';
            $size = $_POST['size'] ?? '';
            $rig_type = $_POST['rig_type'] ?? '';

            if (empty($room)) {
                echo json_encode(['error' => 'Target room is required.']);
                exit;
            }

            $state = load_sync_state(COLOR_STATE_FILE, []);
            $state[$room]['overrides'] = [
                'color' => $color,
                'number' => $number,
                'size' => $size,
                'rig_type' => $rig_type
            ];
            save_sync_state(COLOR_STATE_FILE, $state);
            echo json_encode(['success' => true]);
        } elseif ($game === 'aviator') {
            $crash = floatval($_POST['crash_point'] ?? 0);
            $instant = ($_POST['instant_crash'] ?? '') === 'true';
            $state = load_sync_state(AVIATOR_STATE_FILE, []);

            if ($instant || $crash >= 1.0) {
                if ($instant) {
                    $now = microtime(true);
                    $elapsed = $now - ($state['phase_start'] ?? $now);
                    $current_multiplier = ($state['phase'] === 'running') ? exp(0.06 * $elapsed) : 1.00;

                    $state['phase'] = 'crashed';
                    $state['phase_start'] = $now;
                    $state['crash_point'] = round(max(1.00, $current_multiplier), 2);

                    foreach ($state['bets'] as &$b) {
                        if ($b['status'] === 'pending') {
                            $b['status'] = 'lost';
                        }
                    }
                    unset($b);
                    $state['history'][] = $state['crash_point'];
                    if (count($state['history']) > 15) {
                        array_shift($state['history']);
                    }
                } else {
                    $state['admin_override'] = $crash;
                }
            } else {
                unset($state['admin_override']);
            }
            save_sync_state(AVIATOR_STATE_FILE, $state);
            echo json_encode(['success' => true]);
        } elseif ($game === 'teenpatti') {
            $target_user = $_POST['target_user'] ?? '';
            $winner = $_POST['winner'] ?? '';
            
            if (empty($target_user)) {
                echo json_encode(['error' => 'Target Teen Patti player is required.']);
                exit;
            }

            $state = load_sync_state(TEENPATTI_STATE_FILE, []);
            if (isset($state[$target_user])) {
                $state[$target_user]['admin_override_winner'] = $winner;
                
                if (isset($_POST['edit_cards'])) {
                    $player_key = $_POST['player_key'] ?? 'human';
                    $state[$target_user]['players'][$player_key]['cards'] = [
                        ['r' => 14, 's' => 'S'],
                        ['r' => 14, 's' => 'H'],
                        ['r' => 14, 's' => 'C']
                    ];
                    $state[$target_user]['log'][] = "Admin has modified cards of " . $state[$target_user]['players'][$player_key]['name'] . " live!";
                }
                
                save_sync_state(TEENPATTI_STATE_FILE, $state);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'No active game found for this user.']);
            }
        } else {
            echo json_encode(['error' => 'Invalid game type for overrides.']);
        }
        break;
    }

    default:
        echo json_encode(['error' => 'Invalid sync action.']);
        break;
}
