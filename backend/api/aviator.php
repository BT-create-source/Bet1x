<?php
/**
 * Aviator API Endpoint - Unified Backend
 */

require_once __DIR__ . '/aviator_logic.php';

header('Content-Type: application/json; charset=utf-8');

$game   = new AviatorGame();
$action = $_REQUEST['action'] ?? 'state';

switch ($action) {
    case 'bet':
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
        echo json_encode($game->placeBet($amount));
        break;

    case 'cashout':
        echo json_encode($game->cashOut());
        break;

    case 'reset':
        if (isset($_SESSION['username'])) {
            $username = $_SESSION['username'];
            require_once __DIR__ . '/db.php';
            try {
                db_transaction('users', function (&$users) use ($username) {
                    foreach ($users as &$u) {
                        if (strtolower($u['username']) === strtolower($username)) {
                            $u['wallet_balance'] = STARTING_BALANCE;
                            return true;
                        }
                    }
                    return false;
                });
                db_log_transaction($username, 'Deposit', STARTING_BALANCE, 'Aviator Demo Balance Reset', 'Completed');
            } catch (Exception $e) {
            }
        } else {
            $_SESSION['balance'] = AviatorGame::STARTING_BALANCE;
        }
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;

    case 'state':
    default:
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;
}
