<?php
/**
 * Football Betting API (Unified Backend)
 */

require_once __DIR__ . '/football_logic.php';

header('Content-Type: application/json; charset=utf-8');

$game   = new FootballBettingGame();
$action = $_REQUEST['action'] ?? 'state';

switch ($action) {
    case 'place_bet':
        $stake = isset($_POST['stake']) ? (float) $_POST['stake'] : 0.0;
        $legs  = [];
        if (isset($_POST['legs'])) {
            $decoded = json_decode($_POST['legs'], true);
            if (is_array($decoded)) $legs = $decoded;
        }
        echo json_encode($game->placeBet($legs, $stake));
        break;

    case 'simulate':
        $matchId = isset($_POST['match_id']) ? (int) $_POST['match_id'] : 0;
        echo json_encode($game->simulateMatch($matchId));
        break;

    case 'reset':
        $game->resetAll();
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;

    case 'state':
    default:
        echo json_encode(['ok' => true, 'state' => $game->getState()]);
        break;
}
