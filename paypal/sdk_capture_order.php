<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function jsonError(string $message, array $extra = []): void {
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = $_GET['order_id'] ?? '';

if (!$orderId) {
    jsonError('Missing order id');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM transactions
    WHERE paypal_order_id = ?
    AND payment_status = 'pending'
    LIMIT 1
");
$stmt->execute([$orderId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    jsonError('Transaction not found or already processed');
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

if (!$clientId || !$secret) {
    jsonError('Missing PayPal credentials');
}

$ch = curl_init($baseUrl . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $clientId . ':' . $secret,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Accept-Language: en_US'
    ],
    CURLOPT_TIMEOUT => 60
]);

$tokenResponse = curl_exec($ch);
$tokenError = curl_error($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenError) {
    file_put_contents(__DIR__ . '/paypal_sdk_capture_debug.txt', "TOKEN CURL ERROR:\n" . $tokenError);
    jsonError('PayPal token curl error');
}

$tokenJson = json_decode($tokenResponse, true);
$accessToken = $tokenJson['access_token'] ?? null;

if (!$accessToken) {
    file_put_contents(
        __DIR__ . '/paypal_sdk_capture_debug.txt',
        "TOKEN HTTP CODE: {$tokenHttpCode}\n\n{$tokenResponse}"
    );
    jsonError('PayPal token error');
}

$ch = curl_init($baseUrl . "/v2/checkout/orders/" . urlencode($orderId) . "/capture");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ],
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_TIMEOUT => 60
]);

$captureResponse = curl_exec($ch);
$captureError = curl_error($ch);
$captureHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($captureError) {
    file_put_contents(__DIR__ . '/paypal_sdk_capture_debug.txt', "CAPTURE CURL ERROR:\n" . $captureError);
    jsonError('PayPal capture curl error');
}

$capture = json_decode($captureResponse, true);

if (($capture['status'] ?? '') !== 'COMPLETED') {
    file_put_contents(
        __DIR__ . '/paypal_sdk_capture_debug.txt',
        "CAPTURE HTTP CODE: {$captureHttpCode}\n\n{$captureResponse}"
    );

    jsonError('Payment not completed', [
        'paypal_response' => $capture
    ]);
}

$captureId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
$captureAmount = $capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? null;
$captureCurrency = $capture['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE users
        SET points_balance = points_balance + ?,
            total_spent = total_spent + ?
        WHERE id = ?
    ");

    $stmt->execute([
        $transaction['points_added'],
        $transaction['amount'],
        $transaction['user_id']
    ]);

    $stmt = $pdo->prepare("
        UPDATE transactions
        SET payment_status = 'completed',
            status = 'completed',
            paypal_capture_id = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $captureId,
        $transaction['id']
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'points_added' => (int)$transaction['points_added'],
        'capture_id' => $captureId,
        'amount' => $captureAmount,
        'currency' => $captureCurrency
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    file_put_contents(
        __DIR__ . '/paypal_sdk_capture_debug.txt',
        "DATABASE ERROR:\n" . $e->getMessage() . "\n\nCAPTURE RESPONSE:\n" . $captureResponse
    );

    jsonError('Database error after payment capture');
}