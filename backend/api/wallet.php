<?php
/**
 * Wallet API - Fetch balance, transaction history, and wagers (Unified Backend)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized. Please login.']);
    exit;
}

$username = $_SESSION['username'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'balance':
        $users = db_read('users');
        $balance = 0.00;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $balance = (float)$u['wallet_balance'];
                break;
            }
        }
        echo json_encode(['balance' => $balance]);
        break;

    case 'adjust':
        $delta = floatval($_POST['delta'] ?? 0);
        $reason = $_POST['reason'] ?? 'Game play';
        
        if ($delta == 0) {
            echo json_encode(['error' => 'Invalid adjustment amount.']);
            exit;
        }
        
        try {
            $result = db_adjust_wallet($username, $delta, $reason);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'transactions':
        $allTxns = db_read('transactions');
        $userTxns = [];
        foreach ($allTxns as $t) {
            if (strtolower($t['user']) === strtolower($username)) {
                $userTxns[] = $t;
            }
        }
        echo json_encode(array_reverse($userTxns));
        break;

    case 'reset_wallet':
        try {
            $newBal = db_transaction('users', function (&$users) use ($username) {
                foreach ($users as &$u) {
                    if (strtolower($u['username']) === strtolower($username)) {
                        $u['wallet_balance'] = STARTING_BALANCE;
                        return STARTING_BALANCE;
                    }
                }
                return false;
            });

            if ($newBal !== false) {
                db_log_transaction($username, 'Deposit', STARTING_BALANCE, 'Wallet Demo Balance Reset', 'Completed');
                echo json_encode(['success' => true, 'balance' => $newBal]);
            } else {
                echo json_encode(['error' => 'User wallet could not be found.']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid wallet action.']);
        break;
}
