<?php
/**
 * Deposit API & Order Creation (Razorpay / UPI) - Unified Backend
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized. Please login.']);
    exit;
}

$username = $_SESSION['username'];
$amount = floatval($_POST['amount'] ?? $_GET['amount'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'submit_upi_deposit') {
    $utr = trim($_POST['utr'] ?? '');
    $qrType = $_POST['qr_type'] ?? 'default';
    $customQrData = $_POST['custom_qr_data'] ?? '';

    if ($amount < 100) {
        echo json_encode(['error' => 'Minimum deposit amount is INR 100.']);
        exit;
    }
    if (empty($utr) || strlen($utr) < 6) {
        echo json_encode(['error' => 'Please enter a valid 12-digit UTR reference number.']);
        exit;
    }

    $depId = 'DEP_' . mt_rand(100000, 999999);
    $created = date('Y-m-d H:i:s');

    try {
        db_transaction('deposits', function (&$deposits) use ($depId, $username, $amount, $utr, $qrType, $customQrData, $created) {
            $deposits[] = [
                'deposit_id' => $depId,
                'order_id' => 'UPI_' . $utr,
                'username' => $username,
                'amount' => $amount,
                'utr' => $utr,
                'qr_type' => $qrType,
                'custom_qr_data' => $customQrData,
                'status' => 'Completed',
                'gateway' => 'UPI QR',
                'created_at' => $created,
                'updated_at' => $created
            ];
        });

        db_adjust_wallet($username, $amount, "UPI Deposit: UTR #$utr - ID: $depId");

        echo json_encode([
            'success' => true,
            'deposit_id' => $depId,
            'amount' => $amount,
            'utr' => $utr,
            'message' => 'Deposit processed successfully! Coins have been credited.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($amount < 100) {
    echo json_encode(['error' => 'Minimum deposit amount is INR 100.']);
    exit;
}

$amountInPaise = $amount * 100;
$receiptId = 'REC_' . mt_rand(100000, 999999);

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => $amountInPaise,
    'currency' => 'INR',
    'receipt' => $receiptId,
    'payment_capture' => 1
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $orderId = 'order_MOCK_' . mt_rand(100000, 999999) . 'tst';
} else {
    $resData = json_decode($response, true);
    $orderId = $resData['id'] ?? ('order_MOCK_' . mt_rand(100000, 999999) . 'tst');
}

try {
    db_transaction('deposits', function (&$deposits) use ($orderId, $username, $amount, $receiptId) {
        $deposits[] = [
            'deposit_id' => 'DEP_' . mt_rand(100000, 999999),
            'order_id' => $orderId,
            'username' => $username,
            'amount' => $amount,
            'receipt' => $receiptId,
            'status' => 'Pending',
            'gateway' => 'Razorpay',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    });

    db_log_transaction($username, 'Deposit', $amount, "Razorpay Order: $orderId", 'Pending');

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'amount' => $amount,
        'key_id' => RAZORPAY_KEY_ID,
        'username' => $username,
        'email' => $_SESSION['email'] ?? ($username . '@bet1x.com')
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Database storage error: ' . $e->getMessage()]);
}
