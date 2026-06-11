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

$packageId = (int)($_GET['package_id'] ?? 0);
$telegramId = (int)($_GET['telegram_id'] ?? 0);

if (!$packageId || !$telegramId) {
    jsonError('Invalid request');
}

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package) {
    jsonError('Package not found');
}

$userStmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id=?");
$userStmt->execute([$telegramId]);
$user = $userStmt->fetch();

if (!$user) {
    jsonError('Telegram user not found');
}

$offerStmt = $pdo->prepare("
    SELECT *
    FROM package_offers
    WHERE package_id = ?
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= NOW())
    AND (end_date IS NULL OR end_date >= NOW())
    ORDER BY id DESC
    LIMIT 1
");
$offerStmt->execute([$packageId]);
$offer = $offerStmt->fetch();

$bonusPoints = $offer ? (int)$offer['bonus_points'] : 0;
$totalPoints = (int)$package['points'] + $bonusPoints;

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
    file_put_contents(__DIR__ . '/paypal_sdk_create_debug.txt', "TOKEN CURL ERROR:\n" . $tokenError);
    jsonError('PayPal token curl error');
}

$tokenJson = json_decode($tokenResponse, true);
$accessToken = $tokenJson['access_token'] ?? null;

if (!$accessToken) {
    file_put_contents(
        __DIR__ . '/paypal_sdk_create_debug.txt',
        "TOKEN HTTP CODE: {$tokenHttpCode}\n\n{$tokenResponse}"
    );
    jsonError('PayPal token error');
}

$currency = strtoupper(trim($package['currency'] ?? 'USD'));
$amount = number_format((float)$package['price'], 2, '.', '');

$orderData = [
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'reference_id' => 'package_' . $packageId . '_telegram_' . $telegramId,
            'description' => $package['name'] . ' - ' . $totalPoints . ' points',
            'custom_id' => 'telegram_' . $telegramId . '_package_' . $packageId,
            'amount' => [
                'currency_code' => $currency,
                'value' => $amount
            ]
        ]
    ],
    'application_context' => [
        'brand_name' => 'AI Trading Inspector',
        'user_action' => 'PAY_NOW',
        'shipping_preference' => 'NO_SHIPPING'
    ]
];

$ch = curl_init($baseUrl . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ],
    CURLOPT_POSTFIELDS => json_encode($orderData, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60
]);

$orderResponse = curl_exec($ch);
$orderError = curl_error($ch);
$orderHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($orderError) {
    file_put_contents(__DIR__ . '/paypal_sdk_create_debug.txt', "ORDER CURL ERROR:\n" . $orderError);
    jsonError('PayPal order curl error');
}

$order = json_decode($orderResponse, true);

if (!isset($order['id'])) {
    file_put_contents(
        __DIR__ . '/paypal_sdk_create_debug.txt',
        "ORDER HTTP CODE: {$orderHttpCode}\n\n{$orderResponse}"
    );
    jsonError('PayPal order error', [
        'paypal_response' => $order
    ]);
}

$stmt = $pdo->prepare("
    INSERT INTO transactions 
    (
        user_id,
        package_id,
        paypal_order_id,
        amount,
        points_added,
        status,
        payment_status,
        package_name,
        telegram_id
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        'pending',
        'pending',
        ?,
        ?
    )
");

$stmt->execute([
    $user['id'],
    $packageId,
    $order['id'],
    $amount,
    $totalPoints,
    $package['name'],
    $telegramId
]);

echo json_encode([
    'success' => true,
    'id' => $order['id']
], JSON_UNESCAPED_UNICODE);