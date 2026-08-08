<?php
/**
 * Mining / Mines API (Unified Backend)
 */

require_once __DIR__ . '/mining_logic.php';

header('Content-Type: application/json; charset=utf-8');

$game   = new MinesGame();
$action = $_REQUEST['action'] ?? 'state';

switch ($action) {
    case 'start':
        $bet   = isset($_POST['bet_amount']) ? (float) $_POST['bet_amount'] : 0.0;
        $mines = isset($_POST['mines_count']) ? (int) $_POST['mines_count'] : 3;
        echo json_encode($game->startGame($bet, $mines));
        break;

    case 'reveal':
        $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
        echo json_encode($game->revealTile($index));
        break;

    case 'cashout':
        echo json_encode($game->cashOut());
        break;

    case 'reset':
        $_SESSION['balance']    = MinesGame::STARTING_BALANCE;
        $_SESSION['mines_game'] = ['status' => 'idle'];
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;

    case 'state':
    default:
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;
}
