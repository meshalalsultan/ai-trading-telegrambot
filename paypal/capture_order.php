<?php
require_once __DIR__ . '/../config.php';

$orderId = $_GET['token'] ?? '';

if (!$orderId) {
    exit('Missing order token');
}

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE paypal_order_id=? AND payment_status='pending'");
$stmt->execute([$orderId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    exit('Transaction not found or already processed');
}

$mode = setting('paypal_mode', 'sandbox');

if ($mode === 'live') {
    $clientId = setting('paypal_live_client_id');
    $secret = setting('paypal_live_client_secret');
    $baseUrl = 'https://api-m.paypal.com';
} else {
    $clientId = setting('paypal_sandbox_client_id');
    $secret = setting('paypal_sandbox_client_secret');
    $baseUrl = 'https://api-m.sandbox.paypal.com';
}

$ch = curl_init($baseUrl . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $clientId . ':' . $secret,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials'
]);

$tokenResponse = curl_exec($ch);
curl_close($ch);

$accessToken = json_decode($tokenResponse, true)['access_token'] ?? null;

if (!$accessToken) {
    exit('PayPal token error');
}

$ch = curl_init($baseUrl . "/v2/checkout/orders/{$orderId}/capture");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]
]);

$captureResponse = curl_exec($ch);
curl_close($ch);

$capture = json_decode($captureResponse, true);

if (($capture['status'] ?? '') !== 'COMPLETED') {
    file_put_contents(__DIR__ . '/paypal_capture_debug.txt', $captureResponse);
    exit('Payment not completed');
}

$captureId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

$pdo->beginTransaction();

$stmt = $pdo->prepare("
    UPDATE users
    SET points_balance = points_balance + ?, total_spent = total_spent + ?
    WHERE id = ?
");
$stmt->execute([
    $transaction['points_added'],
    $transaction['amount'],
    $transaction['user_id']
]);

$stmt = $pdo->prepare("
    UPDATE transactions
    SET payment_status='completed',
        status='completed',
        paypal_capture_id=?
    WHERE id=?
");
$stmt->execute([$captureId, $transaction['id']]);

$pdo->commit();

echo "✅ تم الدفع بنجاح وتمت إضافة النقاط إلى حسابك. يمكنك الرجوع إلى Telegram.";
