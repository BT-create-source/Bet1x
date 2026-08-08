<?php
/**
 * Razorpay Payment Webhook Verification Endpoint (Unified Backend)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$payload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

db_transaction('payment_logs', function (&$logs) use ($payload, $signatureHeader) {
    $logs[] = [
        'id' => 'LOG_' . mt_rand(100000, 999999),
        'payload' => $payload,
        'signature' => $signatureHeader,
        'timestamp' => date('Y-m-d H:i:s')
    ];
});

if (empty($payload) || empty($signatureHeader)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload or signature.']);
    exit;
}

$expectedSignature = hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET);

if (!hash_equals($expectedSignature, $signatureHeader)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature.']);
    exit;
}

$data = json_decode($payload, true);
$event = $data['event'] ?? '';

if ($event === 'payment.captured' || $event === 'order.paid') {
    $paymentEntity = $data['payload']['payment']['entity'] ?? [];
    $orderId = $paymentEntity['order_id'] ?? '';
    $paymentId = $paymentEntity['id'] ?? '';
    
    if (empty($orderId)) {
        echo json_encode(['success' => true, 'message' => 'No order ID in payment entity.']);
        exit;
    }

    try {
        $settled = db_transaction('deposits', function (&$deposits) use ($orderId, $paymentId) {
            foreach ($deposits as &$dep) {
                if ($dep['order_id'] === $orderId) {
                    if ($dep['status'] === 'Completed') {
                        return ['already_processed' => true];
                    }
                    
                    $dep['status'] = 'Completed';
                    $dep['gateway_id'] = $paymentId;
                    $dep['updated_at'] = date('Y-m-d H:i:s');
                    
                    $username = $dep['username'];
                    $amount = (float)$dep['amount'];
                    
                    db_adjust_wallet($username, $amount, "Razorpay Deposit: $paymentId");
                    
                    db_transaction('transactions', function (&$txns) use ($orderId) {
                        foreach ($txns as &$t) {
                            if (strpos($t['details'], $orderId) !== false && $t['status'] === 'Pending') {
                                $t['status'] = 'Completed';
                            }
                        }
                    });
                    
                    return ['success' => true, 'username' => $username, 'amount' => $amount];
                }
            }
            return ['error' => 'Matching deposit record not found.'];
        });

        echo json_encode($settled);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error while updating wallet: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => true, 'message' => 'Ignored event: ' . $event]);
}
