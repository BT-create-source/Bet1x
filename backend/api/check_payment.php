<?php
/**
 * Polling API - Check status of a deposit order (Unified Backend)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$orderId = $_GET['order_id'] ?? '';
if (empty($orderId)) {
    echo json_encode(['error' => 'Order ID is required.']);
    exit;
}

$deposits = db_read('deposits');
$found = null;
foreach ($deposits as $dep) {
    if ($dep['order_id'] === $orderId) {
        $found = $dep;
        break;
    }
}

if ($found) {
    echo json_encode([
        'success' => true,
        'status' => $found['status'],
        'amount' => (float)$found['amount']
    ]);
} else {
    echo json_encode(['error' => 'Deposit order not found.']);
}
