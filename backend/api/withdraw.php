<?php
/**
 * Create Withdrawal Request API (Unified Backend)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized. Please login.']);
    exit;
}

$username = $_SESSION['username'];
$amount = floatval($_POST['amount'] ?? 0);
$method = $_POST['method'] ?? '';

if ($amount < 200 || $amount > 50000) {
    echo json_encode(['error' => 'Withdrawal must be between INR 200 and INR 50,000.']);
    exit;
}

$details = '';
if ($method === 'upi') {
    $upiId = trim($_POST['upi_id'] ?? '');
    if (empty($upiId) || strpos($upiId, '@') === false) {
        echo json_encode(['error' => 'A valid UPI ID is required.']);
        exit;
    }
    $details = "UPI ID: $upiId";
} elseif ($method === 'bank') {
    $bankName = trim($_POST['bank_name'] ?? '');
    $accName = trim($_POST['bank_acc_name'] ?? '');
    $accNum = trim($_POST['bank_acc_num'] ?? '');
    $ifsc = trim($_POST['bank_ifsc'] ?? '');

    if (empty($bankName) || empty($accName) || empty($accNum) || empty($ifsc)) {
        echo json_encode(['error' => 'All bank details are required.']);
        exit;
    }
    $details = "Bank: $bankName | A/C Name: $accName | A/C Num: $accNum | IFSC: $ifsc";
} else {
    echo json_encode(['error' => 'Invalid withdrawal method.']);
    exit;
}

try {
    $deductResult = db_adjust_wallet($username, -$amount, "Withdrawal Request: $details");

    if (isset($deductResult['error'])) {
        echo json_encode(['error' => $deductResult['error']]);
        exit;
    }

    $withdrawalId = 'WTH_' . mt_rand(100000, 999999);

    db_transaction('withdrawals', function (&$withdrawals) use ($withdrawalId, $username, $amount, $method, $details) {
        $withdrawals[] = [
            'withdrawal_id' => $withdrawalId,
            'username' => $username,
            'amount' => $amount,
            'method' => $method,
            'details' => $details,
            'status' => 'Pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    });

    db_transaction('transactions', function (&$txns) use ($username, $details) {
        for ($i = count($txns) - 1; $i >= 0; $i--) {
            if ($txns[$i]['user'] === $username && $txns[$i]['type'] === 'Withdrawal' && strpos($txns[$i]['details'], $details) !== false) {
                $txns[$i]['status'] = 'Pending';
                break;
            }
        }
    });

    echo json_encode([
        'success' => true,
        'message' => 'Withdrawal request submitted successfully.',
        'withdrawal_id' => $withdrawalId,
        'new_balance' => $deductResult['new_balance']
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
