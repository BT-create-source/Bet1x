<?php
/**
 * Live Cricket Predictor API (Unified Backend)
 */

require_once __DIR__ . '/cricket_player_logic.php';
require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$username = $_SESSION['username'] ?? null;
if (!$username) {
    echo json_encode(['error' => 'Aap logged in nahi hain. Khelne ke liye login karein.']);
    exit;
}

$users = db_read('users');
$dbBalance = 0.00;
foreach ($users as $u) {
    if (strtolower($u['username']) === strtolower($username)) {
        $dbBalance = (float)$u['wallet_balance'];
        break;
    }
}
$_SESSION['coins'] = $dbBalance;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'new_match': {
        if ($_SESSION['coins'] < MIN_STAKE) {
            echo json_encode(['error' => 'Balance kam hai Predictor khelne ke liye. Minimum bet 🪙' . MIN_STAKE]);
            exit;
        }
        $match = createNewMatch();
        $_SESSION['match'] = $match;
        echo json_encode(buildResponse($match));
        break;
    }

    case 'place_bet': {
        $match = $_SESSION['match'] ?? null;
        if (!$match || $match['status'] !== 'betting') { errorOut('Ab naye bets nahi le sakte - match lock ho chuka hai.'); break; }

        $marketKey = $_POST['market_key'] ?? '';
        $stake = (float)($_POST['stake'] ?? 0);
        $market = findMarket($match, $marketKey);

        if (!$market) { errorOut('Invalid market.'); break; }
        if ($stake < MIN_STAKE) { errorOut('Minimum stake ' . MIN_STAKE . ' coins hai.'); break; }
        if ($stake > $dbBalance) { errorOut('Itne coins nahi hain aapke paas.'); break; }

        foreach ($match['bets'] as $b) {
            if ($b['market_key'] === $marketKey) { errorOut('Is market par pehle se bet lagi hai.'); break 2; }
        }

        $adj = db_adjust_wallet($username, -$stake, "Cricket Predictor Bet: " . $market['label']);
        if (isset($adj['error'])) {
            errorOut($adj['error']);
            break;
        }
        
        $dbBalance = (float)$adj['new_balance'];
        $_SESSION['coins'] = $dbBalance;

        $match['bets'][] = [
            'market_key' => $marketKey, 'label' => $market['label'], 'odds' => $market['odds'],
            'stake' => $stake, 'settled' => false, 'result' => null, 'payout' => 0,
        ];
        $_SESSION['match'] = $match;
        echo json_encode(buildResponse($match));
        break;
    }

    case 'cancel_bet': {
        $match = $_SESSION['match'] ?? null;
        if (!$match || $match['status'] !== 'betting') { errorOut('Ab bet cancel nahi ho sakti.'); break; }
        $marketKey = $_POST['market_key'] ?? '';
        
        $refunded = false;
        foreach ($match['bets'] as $i => $b) {
            if ($b['market_key'] === $marketKey) {
                $adj = db_adjust_wallet($username, $b['stake'], "Cricket Cancel Bet Refund: " . $b['label']);
                if (isset($adj['error'])) {
                    errorOut($adj['error']);
                    break 2;
                }
                
                $dbBalance = (float)$adj['new_balance'];
                $_SESSION['coins'] = $dbBalance;
                
                array_splice($match['bets'], $i, 1);
                $refunded = true;
                break;
            }
        }
        
        if (!$refunded) {
            errorOut('Bet records not found.');
            break;
        }
        
        $_SESSION['match'] = $match;
        echo json_encode(buildResponse($match));
        break;
    }

    case 'lock_and_start': {
        $match = $_SESSION['match'] ?? null;
        if (!$match || $match['status'] !== 'betting') { errorOut('Match already lock hai.'); break; }
        $match['status'] = 'live';
        $match['cur'] = initInningsState($match, $match['batting_team']);
        addCommentary($match, "Bets lock! Match shuru ho raha hai — {$match['teams'][$match['batting_team']]['name']} batting karegi.");
        $_SESSION['match'] = $match;
        echo json_encode(buildResponse($match));
        break;
    }

    case 'simulate_over': {
        $match = $_SESSION['match'] ?? null;
        if (!$match || $match['status'] !== 'live') { errorOut('Match live nahi hai.'); break; }
        
        $oldBets = $match['bets'];
        simulateOneOver($match);
        
        foreach ($match['bets'] as $i => &$bet) {
            $prevBet = $oldBets[$i] ?? null;
            if ($bet['settled'] && (!$prevBet || !$prevBet['settled'])) {
                if ($bet['payout'] > 0) {
                    $adj = db_adjust_wallet($username, $bet['payout'], "Cricket Predictor Win: " . $bet['label']);
                    if (isset($adj['new_balance'])) {
                        $dbBalance = (float)$adj['new_balance'];
                        $_SESSION['coins'] = $dbBalance;
                    }
                }
            }
        }
        unset($bet);
        
        $_SESSION['match'] = $match;
        echo json_encode(buildResponse($match));
        break;
    }

    case 'state': {
        $match = $_SESSION['match'] ?? null;
        if (!$match) { echo json_encode(['exists' => false, 'coins' => $dbBalance]); break; }
        echo json_encode(buildResponse($match));
        break;
    }

    default:
        errorOut('Unknown action.');
}

function errorOut(string $msg): void {
    global $dbBalance;
    echo json_encode(['error' => $msg, 'coins' => $dbBalance]);
}

function buildResponse(array $match): array {
    global $dbBalance;
    $cur = $match['cur'];
    $curOut = null;
    if ($cur) {
        $strikerName = $cur['batting_order'][$cur['striker_idx']] ?? null;
        $nonStrikerName = $cur['batting_order'][$cur['non_striker_idx']] ?? null;
        $bowlerName = $cur['bowlers_order'][$cur['bowler_cycle'] % count($cur['bowlers_order'])];
        $curOut = [
            'batting_team' => $match['teams'][$cur['batting_team']]['name'],
            'score' => $cur['score'], 'wickets' => $cur['wickets'], 'overs' => $cur['overs_completed'],
            'overs_total' => OVERS_PER_INNINGS,
            'striker' => $strikerName ? ['name' => $strikerName, 'stats' => $cur['batsmen_stats'][$strikerName]] : null,
            'non_striker' => $nonStrikerName ? ['name' => $nonStrikerName, 'stats' => $cur['batsmen_stats'][$nonStrikerName]] : null,
            'bowler' => ['name' => $bowlerName, 'stats' => $cur['bowler_stats'][$bowlerName]],
            'complete' => $cur['complete'],
        ];
    }

    return [
        'exists' => true,
        'status' => $match['status'],
        'coins' => $dbBalance,
        'teams' => array_map(fn($t) => ['key' => $t['key'], 'name' => $t['name']], $match['teams']),
        'toss_winner' => $match['teams'][$match['toss_winner']]['name'],
        'markets' => $match['markets'],
        'bets' => $match['bets'],
        'innings' => $match['innings'],
        'target' => $match['target'],
        'cur' => $curOut,
        'innings1_summary' => $match['innings1'] ? ['score' => $match['innings1']['score'], 'wickets' => $match['innings1']['wickets'], 'team' => $match['teams'][$match['innings1']['batting_team']]['name']] : null,
        'commentary' => array_slice($match['commentary'], -10),
        'winner' => $match['winner'] ? ($match['winner'] === 'tie' ? 'tie' : $match['teams'][$match['winner']]['name']) : null,
    ];
}
