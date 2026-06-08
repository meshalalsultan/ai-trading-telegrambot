<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$packageId = (int)($_GET['package_id'] ?? 0);
$telegramId = (int)($_GET['telegram_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package || !$telegramId) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
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
    echo json_encode(['error' => 'PayPal token error']);
    exit;
}

$orderData = [
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'amount' => [
                'currency_code' => $package['currency'],
                'value' => number_format((float)$package['price'], 2, '.', '')
            ],
            'description' => $package['name'] . ' - ' . $totalPoints . ' points'
        ]
    ],
    'application_context' => [
        'user_action' => 'PAY_NOW',
        'landing_page' => 'BILLING',
        'shipping_preference' => 'NO_SHIPPING',
        'brand_name' => 'AI Trading Inspector'
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
    CURLOPT_POSTFIELDS => json_encode($orderData)
]);

$orderResponse = curl_exec($ch);
curl_close($ch);

$order = json_decode($orderResponse, true);

if (!isset($order['id'])) {
    file_put_contents(__DIR__ . '/paypal_sdk_create_debug.txt', $orderResponse);
    echo json_encode(['error' => 'PayPal order error']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO transactions 
    (user_id, package_id, paypal_order_id, amount, points_added, status, payment_status, package_name, telegram_id)
    SELECT id, ?, ?, ?, ?, 'pending', 'pending', ?, ?
    FROM users
    WHERE telegram_id = ?
");

$stmt->execute([
    $packageId,
    $order['id'],
    $package['price'],
    $totalPoints,
    $package['name'],
    $telegramId,
    $telegramId
]);

echo json_encode([
    'id' => $order['id']
]);