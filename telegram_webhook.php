<?php
require_once __DIR__ . '/config.php';

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$updateId = $update['update_id'];

$stmt = $pdo->prepare(
    "SELECT update_id FROM processed_updates WHERE update_id=?"
);

$stmt->execute([$updateId]);

if ($stmt->fetch()) {
    exit;
}

$stmt = $pdo->prepare(
    "INSERT INTO processed_updates(update_id) VALUES(?)"
);

$stmt->execute([$updateId]);

$token = setting('telegram_bot_token');

function tgRequest(string $method, array $data = []) {
    global $token;

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}

function sendMessage($chatId, string $text, ?array $keyboard = null) {
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    tgRequest('sendMessage', $data);
}

function getOrCreateUser(array $from): array {
    global $pdo;

    $telegramId = $from['id'];
    $username = $from['username'] ?? null;
    $firstName = $from['first_name'] ?? null;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $pdo->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user['is_new'] = false;
        return $user;
    }

    $bonusPoints = (int) setting('welcome_bonus_points', 100);

    $stmt = $pdo->prepare("
        INSERT INTO users 
        (telegram_id, username, first_name, points_balance, created_at, last_active_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $telegramId,
        $username,
        $firstName,
        $bonusPoints
    ]);

    $id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $newUser = $stmt->fetch();

    $newUser['is_new'] = true;
    return $newUser;
}

function mainKeyboard(): array {
    global $pdo;

    $stmt = $pdo->query("
        SELECT service_key, title
        FROM bot_services
        WHERE is_active = 1
        ORDER BY id ASC
    ");

    $services = $stmt->fetchAll();

    $buttons = [];

    foreach ($services as $service) {
        $buttons[] = [
            [
                'text' => '🧠 ' . $service['title'],
                'callback_data' => 'service:' . $service['service_key']
            ]
        ];
    }

    $buttons[] = [
        ['text' => '💰 رصيدي', 'callback_data' => 'balance'],
        ['text' => '🛒 شراء نقاط', 'callback_data' => 'buy_points']
    ];

    return [
        'inline_keyboard' => $buttons
    ];
}

function setUserState(int $userId, ?string $serviceKey): void {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO user_states (user_id, current_service)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_service = VALUES(current_service)
    ");
    $stmt->execute([$userId, $serviceKey]);
}

function getUserState(int $userId): ?string {
    global $pdo;

    $stmt = $pdo->prepare("SELECT current_service FROM user_states WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row['current_service'] ?? null;
}

function getService(string $serviceKey): ?array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM bot_services WHERE service_key = ? AND is_active = 1");
    $stmt->execute([$serviceKey]);

    return $stmt->fetch() ?: null;
}

function deductPoints(int $userId, int $points): bool {
    global $pdo;

    $stmt = $pdo->prepare("SELECT points_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['points_balance'] < $points) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE users SET points_balance = points_balance - ? WHERE id = ?");
    $stmt->execute([$points, $userId]);

    return true;
}

function chatgptAsk(string $prompt, string $message): string {
    $apiKey = setting('openai_api_key');
    $model = setting('openai_model', 'gpt-4.1-mini');

    $url = "https://api.openai.com/v1/responses";

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => "أنت مساعد تداول احترافي. لا تقدم وعود ربح ولا توصيات قطعية. ركز على تقييم القرار والمخاطر والتعليم."
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $prompt . "\n\nرسالة المستخدم:\n" . $message
                    ]
                ]
            ]
        ],
        'temperature' => 0.4,
        'max_output_tokens' => 900
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(__DIR__ . '/openai_debug.txt', "HTTP CODE: {$httpCode}\n\n{$response}");

    if ($error) {
        return "__ERROR__ CURL Error: " . $error;
    }

    $json = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $json['error']['message'] ?? 'خطأ غير معروف من OpenAI';
        return "__ERROR__ OpenAI Error: " . $msg;
    }

    $text = $json['output_text'] ?? null;

    if (!$text && isset($json['output'][0]['content'][0]['text'])) {
        $text = $json['output'][0]['content'][0]['text'];
    }

    if (!$text) {
        return "__ERROR__ OpenAI لم يرجع نص واضح. راجع openai_debug.txt";
    }

    return $text;
}

function saveConversation(int $userId, string $serviceKey, string $userMessage, string $botResponse, int $pointsUsed): void {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO conversations (user_id, service_key, user_message, bot_response, points_used)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $serviceKey, $userMessage, $botResponse, $pointsUsed]);
}

function saveAiUsage(
    int $userId,
    string $serviceKey,
    int $pointsUsed,
    int $tokensUsed = 0
): void {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO ai_usage
        (
            user_id,
            service_key,
            points_used,
            tokens_used
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $serviceKey,
        $pointsUsed,
        $tokensUsed
    ]);
}

function getTelegramFileUrl(string $fileId): ?string {
    global $token;

    $result = tgRequest('getFile', [
        'file_id' => $fileId
    ]);

    file_put_contents(__DIR__ . '/telegram_getfile_debug.txt', $result);

    $json = json_decode($result, true);

    if (!isset($json['ok']) || $json['ok'] !== true) {
        return null;
    }

    if (empty($json['result']['file_path'])) {
        return null;
    }

    return "https://api.telegram.org/file/bot{$token}/" . $json['result']['file_path'];
}

function downloadTelegramImage(string $fileId): ?string {
    $fileUrl = getTelegramFileUrl($fileId);

    if (!$fileUrl) {
        file_put_contents(__DIR__ . '/image_download_debug.txt', "FAILED: getTelegramFileUrl returned null\nFILE_ID: {$fileId}");
        return null;
    }

    $dir = __DIR__ . '/uploads/charts';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    if (!is_dir($dir)) {
        file_put_contents(__DIR__ . '/image_download_debug.txt', "FAILED: directory not created\nDIR: {$dir}");
        return null;
    }

    if (!is_writable($dir)) {
        chmod($dir, 0775);
    }

    $filename = 'chart_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.jpg';
    $path = $dir . '/' . $filename;

    $ch = curl_init($fileUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);

    $imageData = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $bytes = is_string($imageData) ? strlen($imageData) : 0;

    file_put_contents(
        __DIR__ . '/image_download_debug.txt',
        "URL: {$fileUrl}\nHTTP CODE: {$httpCode}\nERROR: {$error}\nBYTES: {$bytes}\nPATH: {$path}\nDIR_WRITABLE: " . (is_writable($dir) ? 'YES' : 'NO')
    );

    if ($error || $httpCode !== 200 || $bytes < 100) {
        return null;
    }

    $written = file_put_contents($path, $imageData);
    clearstatcache(true, $path);

    if ($written === false) {
        file_put_contents(__DIR__ . '/image_download_debug.txt', "\nFAILED: file_put_contents returned false", FILE_APPEND);
        return null;
    }

    if (!file_exists($path)) {
        file_put_contents(__DIR__ . '/image_download_debug.txt', "\nFAILED: file does not exist after write", FILE_APPEND);
        return null;
    }

    $size = filesize($path);

    file_put_contents(
        __DIR__ . '/image_download_debug.txt',
        "\nWRITTEN: {$written}\nFINAL_SIZE: {$size}",
        FILE_APPEND
    );

    if ($size < 100) {
        return null;
    }

    return $path;
}

function chatgptAskWithImage(string $prompt, string $imagePath): string {
    $apiKey = setting('openai_api_key');
    $model = setting('openai_model', 'gpt-4.1-mini');

    if (!file_exists($imagePath)) {
        return "__ERROR__ الصورة غير موجودة: {$imagePath}";
    }

    clearstatcache(true, $imagePath);

    $size = filesize($imagePath);
    if ($size < 100) {
        return "__ERROR__ الصورة فارغة أو تالفة. الحجم: {$size}";
    }

    $rawImage = file_get_contents($imagePath);

    if ($rawImage === false || strlen($rawImage) < 100) {
        return "__ERROR__ فشل قراءة الصورة من السيرفر.";
    }

    $base64 = base64_encode($rawImage);

    if (empty($base64)) {
        return "__ERROR__ فشل تحويل الصورة إلى Base64.";
    }

    $mimeType = mime_content_type($imagePath);
    if (!$mimeType || !str_starts_with($mimeType, 'image/')) {
        $mimeType = 'image/jpeg';
    }

    $imageUrl = "data:{$mimeType};base64,{$base64}";

    file_put_contents(
        __DIR__ . '/image_payload_debug.txt',
        "PATH: {$imagePath}\nSIZE: {$size}\nMIME: {$mimeType}\nBASE64_LENGTH: " . strlen($base64) . "\nSTART: " . substr($imageUrl, 0, 80)
    );

    $url = "https://api.openai.com/v1/responses";

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'أنت محلل تداول محترف. حلل الشارت بصريًا ولا تقدم وعود ربح أو توصية قطعية.'
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $prompt
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => $imageUrl
                    ]
                ]
            ]
        ],
        'temperature' => 0.3,
        'max_output_tokens' => 1000
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(__DIR__ . '/openai_image_debug.txt', "HTTP CODE: {$httpCode}\n\n{$response}");

    if ($error) {
        return "__ERROR__ CURL Error: " . $error;
    }

    $json = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $json['error']['message'] ?? 'خطأ غير معروف من OpenAI';
        return "__ERROR__ OpenAI Image Error: " . $msg;
    }

    $text = $json['output_text'] ?? null;

    if (!$text && isset($json['output'][0]['content'][0]['text'])) {
        $text = $json['output'][0]['content'][0]['text'];
    }

    if (!$text) {
        return "__ERROR__ OpenAI لم يرجع تحليل للصورة.";
    }

    return $text;
}

function saveUserImage(int $userId, string $fileId, string $path): void {
    global $pdo;

    $relativePath = str_replace(__DIR__ . '/', '', $path);

    $stmt = $pdo->prepare("
        INSERT INTO user_images (user_id, telegram_file_id, image_path)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([$userId, $fileId, $relativePath]);
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $from = $callback['from'];
    $data = $callback['data'];

    $user = getOrCreateUser($from);

    if ($data === 'balance') {
        sendMessage($chatId, "رصيدك الحالي: <b>{$user['points_balance']}</b> نقطة");
        exit;
    }
    
    if ($data === 'buy_points') {
        global $pdo;
    
        $stmt = $pdo->query("
            SELECT *
            FROM packages
            WHERE is_active = 1
            ORDER BY display_order ASC, id ASC
        ");
    
        $packages = $stmt->fetchAll();
    
        if (!$packages) {
            sendMessage($chatId, "لا توجد باقات متاحة حاليًا.");
            exit;
        }
    
        $buttons = [];
    
        foreach ($packages as $package) {
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
    
            $offerStmt->execute([$package['id']]);
            $offer = $offerStmt->fetch();
    
            $bonus = $offer ? (int)$offer['bonus_points'] : 0;
            $totalPoints = (int)$package['points'] + $bonus;
    
            if ($bonus > 0) {
                $buttonText = "🔥 {$package['name']} | {$package['points']} + {$bonus} هدية = {$totalPoints} نقطة | $" . $package['price'];
            } else {
                $buttonText = "{$package['name']} | {$package['points']} نقطة | $" . $package['price'];
            }
    
            $buttons[] = [
                [
                    'text' => $buttonText,
                    'callback_data' => 'package:' . $package['id']
                ]
            ];
        }
    
        sendMessage(
            $chatId,
            "💰 اختر باقة النقاط:\n\n🔥 الباقات التي عليها عرض تظهر معها نقاط هدية تلقائيًا.",
            [
                'inline_keyboard' => $buttons
            ]
        );
    
        exit;
    }

    if (str_starts_with($data, 'package:')) {

        $packageId = (int) str_replace('package:', '', $data);
    
        $payUrl = "https://tradewithai.xyz/bot/paypal/checkout.php?package_id={$packageId}&telegram_id={$from['id']}";    
        sendMessage(
            $chatId,
            "💳 اضغط لإتمام الدفع بأمان عبر PayPal.\n\n" .
            "{$payUrl}\n\n" .
    
            "إذا ظهر لك خيار الدفع بالبطاقة، يمكنك إتمام الدفع بدون إنشاء حساب.\n\n" .
    
            "إذا واجهت أي مشكلة في الدفع، تواصل معي مباشرة:\n" .
            "@Mask_Trader_ai\n\n" .
    
            "وسأرسل لك رابط دفع مناسب خلال دقائق ✅"
        );
    
        exit;
    }

    if (str_starts_with($data, 'service:')) {
        $serviceKey = str_replace('service:', '', $data);
        $service = getService($serviceKey);

        if (!$service) {
            sendMessage($chatId, "هذه الخدمة غير متاحة حاليًا.");
            exit;
        }

        setUserState((int)$user['id'], $serviceKey);

        sendMessage(
            $chatId,
            "اخترت: <b>{$service['title']}</b>\nالتكلفة: <b>{$service['points_cost']}</b> نقطة\n\nأرسل الآن النص أو التوصية التي تريد تحليلها."
        );
        exit;
    }
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $from = $message['from'];
    $text = trim($message['text'] ?? '');
    $hasPhoto = isset($message['photo']);

    $user = getOrCreateUser($from);

    if ($text === '/start') {

        if (!empty($user['is_new'])) {
            $bonusPoints = (int) setting('welcome_bonus_points', 100);
    
            $welcomeMessage = setting('welcome_message',
                "🤖 AI Trading Inspector\n\nلا تثق بأي توصية قبل فحصها.\n\n✅ فحص التوصيات\n✅ تحليل الشارتات\n✅ تقييم قرارات التداول\n\n🎁 حصلت على {BONUS} نقطة مجانية.\n\nاختر الخدمة:"
            );
    
            $welcomeMessage = str_replace('{BONUS}', (string)$bonusPoints, $welcomeMessage);
    
            sendMessage($chatId, $welcomeMessage, mainKeyboard());
            exit;
        }
    
        sendMessage(
            $chatId,
            "مرحباً {$user['first_name']} 👋\n\nرصيدك الحالي: <b>{$user['points_balance']}</b> نقطة\n\nاختر الخدمة المطلوبة:",
            mainKeyboard()
        );
        exit;
    }

    if ($text === '/balance') {
        sendMessage($chatId, "رصيدك الحالي: <b>{$user['points_balance']}</b> نقطة");
        exit;
    }

    if ($hasPhoto) {
        $serviceKey = getUserState((int)$user['id']);

        if ($serviceKey !== 'chart_analysis') {
            sendMessage($chatId, "لتحليل الشارت، اضغط أولًا على زر: 📸 تحليل شارت ثم أرسل الصورة.");
            exit;
        }

        $service = getService($serviceKey);

        if (!$service) {
            sendMessage($chatId, "خدمة تحليل الشارت غير متاحة حاليًا.");
            exit;
        }

        $cost = (int)$service['points_cost'];

        if ((int)$user['points_balance'] < $cost) {
            sendMessage(
                $chatId,
                "رصيدك غير كافٍ.\n\nتحليل الشارت يحتاج <b>{$cost}</b> نقطة.\nرصيدك الحالي: <b>{$user['points_balance']}</b> نقطة."
            );
            exit;
        }

        sendMessage($chatId, "تم استلام الشارت 📸\nجاري التحليل الآن... ⏳");

        $photos = $message['photo'];
        $bestPhoto = end($photos);
        $fileId = $bestPhoto['file_id'];

        $imagePath = downloadTelegramImage($fileId);

        if (!$imagePath) {
            sendMessage($chatId, "لم أستطع تحميل الصورة من Telegram. حاول إرسالها مرة أخرى.");
            exit;
        }

        saveUserImage((int)$user['id'], $fileId, $imagePath);

        $response = chatgptAskWithImage($service['prompt'], $imagePath);

        if (str_contains($response, 'NOT_A_CHART')) {
            sendMessage(
                $chatId,
                "⚠️ لم أكتشف شارت تداول واضح داخل الصورة."
            );

            exit;
        }

        if (str_starts_with($response, '__ERROR__')) {
            sendMessage($chatId, str_replace('__ERROR__', '⚠️', $response));
            exit;
        }

        $success = deductPoints((int)$user['id'], $cost);

        if (!$success) {
            sendMessage($chatId, "حدثت مشكلة في خصم النقاط. حاول مرة أخرى.");
            exit;
        }

        saveAiUsage(
            (int)$user['id'],
            $serviceKey,
            $cost,
            0
        );

        saveConversation(
            (int)$user['id'],
            $serviceKey,
            '[IMAGE_CHART] ' . $imagePath,
            $response,
            $cost
        );

        setUserState((int)$user['id'], null);

        sendMessage(
            $chatId,
            $response . "\n\nتم خصم <b>{$cost}</b> نقطة ✅",
            mainKeyboard()
        );

        exit;
    }

    $serviceKey = getUserState((int)$user['id']);

    if (!$serviceKey) {
        sendMessage($chatId, "اختر الخدمة أولًا من القائمة:", mainKeyboard());
        exit;
    }

    $service = getService($serviceKey);

    if (!$service) {
        sendMessage($chatId, "الخدمة غير متاحة حاليًا.");
        exit;
    }

    $cost = (int)$service['points_cost'];

    if ((int)$user['points_balance'] < $cost) {
        sendMessage(
            $chatId,
            "رصيدك غير كافٍ.\n\nالخدمة تحتاج <b>{$cost}</b> نقطة.\nرصيدك الحالي: <b>{$user['points_balance']}</b> نقطة."
        );
        exit;
    }

    sendMessage($chatId, "جاري تحليل طلبك... ⏳");

    $response = chatgptAsk($service['prompt'], $text);

    if (str_starts_with($response, '__ERROR__')) {
        sendMessage($chatId, str_replace('__ERROR__', '⚠️', $response));
        exit;
    }

    $success = deductPoints((int)$user['id'], $cost);

    if (!$success) {
        sendMessage($chatId, "حدثت مشكلة في خصم النقاط. حاول مرة أخرى.");
        exit;
    }

    saveAiUsage(
        (int)$user['id'],
        $serviceKey,
        $cost,
        0
    );

    saveConversation((int)$user['id'], $serviceKey, $text, $response, $cost);

    setUserState((int)$user['id'], null);

    sendMessage(
        $chatId,
        $response . "\n\nتم خصم <b>{$cost}</b> نقطة ✅",
        mainKeyboard()
    );
}