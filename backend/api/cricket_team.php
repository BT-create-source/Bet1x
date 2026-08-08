<?php
/**
 * Fantasy Cricket API (Unified Backend)
 */

require_once __DIR__ . '/cricket_team_logic.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'players': {
        echo json_encode(['players' => getPlayerPool(), 'total_credits' => TOTAL_CREDITS, 'squad_size' => SQUAD_SIZE]);
        break;
    }

    case 'submit_team': {
        $raw = $_POST['player_ids'] ?? '';
        $ids = array_map('intval', array_filter(explode(',', $raw), 'strlen'));
        $captainId = (int)($_POST['captain_id'] ?? 0);
        $viceId = (int)($_POST['vice_id'] ?? 0);

        $validation = validateTeam($ids, $captainId, $viceId);
        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'errors' => $validation['errors']]);
            break;
        }

        $simResults = simulateFullMatch();

        $breakdown = [];
        $teamTotal = 0;
        foreach ($ids as $pid) {
            $player = getPlayerById($pid);
            $stats = $simResults[$pid];
            $multiplier = 1.0;
            if ($pid === $captainId) $multiplier = 2.0;
            elseif ($pid === $viceId) $multiplier = 1.5;
            $finalPoints = $stats['points'] * $multiplier;
            $teamTotal += $finalPoints;

            $breakdown[] = [
                'id' => $pid, 'name' => $player['name'], 'team' => $player['team'], 'role' => $player['role'],
                'stats' => $stats, 'base_points' => $stats['points'],
                'multiplier' => $multiplier, 'final_points' => $finalPoints,
                'is_captain' => $pid === $captainId, 'is_vice' => $pid === $viceId,
            ];
        }
        usort($breakdown, fn($a, $b) => $b['final_points'] <=> $a['final_points']);

        $_SESSION['fantasy'] = [
            'status' => 'result', 'ids' => $ids, 'captain_id' => $captainId, 'vice_id' => $viceId,
            'breakdown' => $breakdown, 'team_total' => $teamTotal,
        ];

        echo json_encode(['success' => true, 'breakdown' => $breakdown, 'team_total' => $teamTotal]);
        break;
    }

    case 'state': {
        $fantasy = $_SESSION['fantasy'] ?? null;
        if (!$fantasy) { echo json_encode(['exists' => false]); break; }
        echo json_encode([
            'exists' => true, 'status' => $fantasy['status'],
            'breakdown' => $fantasy['breakdown'] ?? null, 'team_total' => $fantasy['team_total'] ?? null,
        ]);
        break;
    }

    case 'new_match': {
        unset($_SESSION['fantasy']);
        echo json_encode(['success' => true]);
        break;
    }

    default:
        echo json_encode(['error' => 'Unknown action.']);
}
