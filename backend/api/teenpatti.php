<?php
/**
 * Teen Patti API (Unified Backend)
 */

require_once __DIR__ . '/teenpatti_logic.php';
require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$username = $_SESSION['username'] ?? null;
if (!$username) {
    echo json_encode(['error' => 'Aap logged in nahi hain. Khelne ke liye login karein.']);
    exit;
}

function load_teenpatti_game(string $username): ?array {
    $key = 'teenpatti_ongoing';
    $data = db_api_request('GET', '/api/db/state/' . $key);
    return (is_array($data) && isset($data[$username])) ? $data[$username] : null;
}

function save_teenpatti_game(string $username, array $game): void {
    $key = 'teenpatti_ongoing';
    $data = db_api_request('GET', '/api/db/state/' . $key);
    if (!is_array($data)) $data = [];
    $data[$username] = $game;
    db_api_request('POST', '/api/db/state/' . $key, ['data' => $data]);
}

$users = db_read('users');
$dbBalance = 0.00;
foreach ($users as $u) {
    if (strtolower($u['username']) === strtolower($username)) {
        $dbBalance = (float)$u['wallet_balance'];
        break;
    }
}

$game = load_teenpatti_game($username);

if ($game && $game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
    $now = time();
    if (!isset($game['turn_start'])) {
        $game['turn_start'] = $now;
    }
    $elapsed = $now - $game['turn_start'];
    if ($elapsed >= 15) {
        playerFold($game, 'human');
        addLog($game, "Aapka time out ho gaya! Pack ho gaye.");
        if (!checkGameEnd($game)) {
            nextTurn($game);
            processBotTurns($game);
            if ($game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
                $game['turn_start'] = time();
            }
        }
        save_teenpatti_game($username, $game);
    }
}

switch ($action) {
    case 'new_game': {
        if ($dbBalance < BOOT_AMOUNT) {
            echo json_encode(['error' => 'Insufficient wallet balance. Minimum Boot is ₹' . BOOT_AMOUNT]);
            exit;
        }
        
        $key = 'teenpatti_ongoing';
        $all_tp = db_api_request('GET', '/api/db/state/' . $key);
        if (!is_array($all_tp)) {
            $all_tp = [];
        }
        $override_winner = $all_tp[$username]['admin_override_winner'] ?? null;
        $override_preset = $all_tp[$username]['admin_preset_hand'] ?? null;
        $override_rig_type = $all_tp[$username]['admin_rig_type'] ?? null;
        
        $hands = createRiggedHands($override_winner, $override_preset, $override_rig_type);
        
        $game = newGameState($dbBalance);
        foreach ($hands as $k => $h) {
            if (isset($game['players'][$k])) {
                $game['players'][$k]['cards'] = $h;
            }
        }
        
        // Clean up one-time overrides
        if (isset($all_tp[$username]['admin_override_winner'])) unset($all_tp[$username]['admin_override_winner']);
        if (isset($all_tp[$username]['admin_preset_hand'])) unset($all_tp[$username]['admin_preset_hand']);
        if (isset($all_tp[$username]['admin_rig_type'])) unset($all_tp[$username]['admin_rig_type']);
        db_api_request('POST', '/api/db/state/' . $key, ['data' => $all_tp]);
        
        $game['turn_start'] = time();
        db_adjust_wallet($username, -BOOT_AMOUNT, 'Teen Patti: ante boot');
        save_teenpatti_game($username, $game);
        echo json_encode(buildResponse($game));
        break;
    }

    case 'chaal': {
        if (!$game || $game['status'] !== 'playing') { errorOut('Game active nahi hai.'); break; }
        $key = currentPlayerKey($game);
        if ($key !== 'human') { errorOut('Aapki baari nahi hai.'); break; }
        if ($game['players']['human']['balance'] < $game['current_stake']) {
            errorOut('Balance kam hai chaal khelne ke liye.'); break;
        }
        
        $oldBalance = $game['players']['human']['balance'];
        playerChaal($game, 'human');
        $newBalance = $game['players']['human']['balance'];
        
        $delta = $newBalance - $oldBalance;
        if ($delta < 0) {
            db_adjust_wallet($username, $delta, 'Teen Patti: chaal bet');
        }
        
        if (!checkGameEnd($game)) {
            nextTurn($game);
            processBotTurns($game);
            
            if ($game['status'] === 'finished' && $game['winner'] === 'human') {
                db_adjust_wallet($username, $game['pot'], 'Teen Patti: won pot');
            } else if ($game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
                $game['turn_start'] = time();
            }
        } else {
            if ($game['status'] === 'finished' && $game['winner'] === 'human') {
                db_adjust_wallet($username, $game['pot'], 'Teen Patti: won pot');
            }
        }
        
        saveAndRespond($game);
        break;
    }

    case 'fold': {
        if (!$game || $game['status'] !== 'playing') { errorOut('Game active nahi hai.'); break; }
        $key = currentPlayerKey($game);
        if ($key !== 'human') { errorOut('Aapki baari nahi hai.'); break; }
        
        playerFold($game, 'human');
        
        if (!checkGameEnd($game)) {
            nextTurn($game);
            processBotTurns($game);
            if ($game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
                $game['turn_start'] = time();
            }
        }
        
        saveAndRespond($game);
        break;
    }

    case 'show': {
        if (!$game || $game['status'] !== 'playing') { errorOut('Game active nahi hai.'); break; }
        $active = activePlayers($game);
        if (count($active) !== 2) { errorOut('Show sirf 2 players bachne par ho sakta hai.'); break; }
        if ($game['players']['human']['balance'] < $game['current_stake']) {
            errorOut('Balance kam hai show ke liye.'); break;
        }
        
        $game['players']['human']['balance'] -= $game['current_stake'];
        $game['pot'] += $game['current_stake'];
        db_adjust_wallet($username, -$game['current_stake'], 'Teen Patti: show cost');

        if (!empty($game['admin_override_winner'])) {
            $winnerKey = $game['admin_override_winner'];
            unset($game['admin_override_winner']);
        } else {
            $keys = array_keys($active);
            $h1 = evaluateHand($game['players'][$keys[0]]['cards']);
            $h2 = evaluateHand($game['players'][$keys[1]]['cards']);
            $winnerKey = handWins($h1, $h2) ? $keys[0] : $keys[1];
        }
        
        endGame($game, $winnerKey, true);
        
        if ($game['winner'] === 'human') {
            db_adjust_wallet($username, $game['pot'], 'Teen Patti: won pot');
        }
        
        saveAndRespond($game);
        break;
    }

    case 'state': {
        if (!$game) { echo json_encode(['exists' => false, 'balance' => $dbBalance]); break; }
        $game['players']['human']['balance'] = $dbBalance;
        echo json_encode(buildResponse($game));
        break;
    }

    default:
        errorOut('Unknown action.');
}

function saveAndRespond(array $game): void {
    global $username;
    save_teenpatti_game($username, $game);
    echo json_encode(buildResponse($game));
}

function errorOut(string $msg): void {
    echo json_encode(['error' => $msg]);
}

function buildResponse(array $game): array {
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
    
    $now = time();
    $time_left = 15;
    if ($game['status'] === 'playing' && currentPlayerKey($game) === 'human') {
        $time_left = max(0, 15 - ($now - ($game['turn_start'] ?? $now)));
    }

    return [
        'exists' => true,
        'players' => $players,
        'order' => $game['order'],
        'turn' => currentPlayerKey($game),
        'pot' => $game['pot'],
        'current_stake' => $game['current_stake'],
        'status' => $game['status'],
        'winner' => $game['winner'],
        'time_left' => $time_left,
        'log' => array_slice($game['log'], -8),
    ];
}

function formatCards(array $cards): array {
    return array_map(fn($c) => [
        'label' => rankLabel($c['r']),
        'suit' => $c['s'],
        'symbol' => suitSymbol($c['s']),
        'red' => in_array($c['s'], ['H', 'D']),
    ], $cards);
}
