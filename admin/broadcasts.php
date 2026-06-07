<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$error = '';
$success = '';

function sendTelegramBroadcast($chatId, string $text, ?string $imageUrl = null, ?array $keyboard = null) {
    $token = setting('telegram_bot_token');

    if ($imageUrl) {
        $url = "https://api.telegram.org/bot{$token}/sendPhoto";

        $data = [
            'chat_id' => $chatId,
            'photo' => $imageUrl,
            'caption' => $text,
            'parse_mode' => 'HTML',
        ];
    } else {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
    }

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 25,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');

        if (!empty($_FILES['image_file']['name'])) {
            $uploadDir = __DIR__ . '/../uploads/broadcasts';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array(strtolower($ext), $allowed)) {
                throw new Exception('صيغة الصورة غير مدعومة. استخدم jpg أو png أو webp.');
            }

            $fileName = 'broadcast_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $fileName;

            if (!move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
                throw new Exception('فشل رفع الصورة.');
            }

            $imageUrl = 'https://tradewithai.xyz/bot/uploads/broadcasts/' . $fileName;
        }

        $buttonText = trim($_POST['button_text'] ?? '');
        $buttonUrl = trim($_POST['button_url'] ?? '');

        if ($title === '' || $message === '') {
            throw new Exception('العنوان والرسالة مطلوبان.');
        }

        $keyboard = null;

        if ($buttonText !== '' && $buttonUrl !== '') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => $buttonText,
                            'url' => $buttonUrl
                        ]
                    ]
                ]
            ];
        }

        $users = $pdo->query("
            SELECT telegram_id
            FROM users
            WHERE telegram_id IS NOT NULL
        ")->fetchAll();

        $sent = 0;

        foreach ($users as $user) {
            $finalKeyboard = $keyboard;

            if (!empty($_POST['package_id'])) {
                $packageId = (int)$_POST['package_id'];

                $payUrl = "https://tradewithai.xyz/bot/paypal/create_order.php?package_id={$packageId}&telegram_id={$user['telegram_id']}";

                $finalKeyboard = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => $buttonText ?: 'شراء العرض الآن',
                                'url' => $payUrl
                            ]
                        ]
                    ]
                ];
            }

            $res = sendTelegramBroadcast(
                $user['telegram_id'],
                $message,
                $imageUrl ?: null,
                $finalKeyboard ?? $keyboard
            );

            if (($res['ok'] ?? false) === true) {
                $sent++;
            }

            usleep(120000);
        }

        $stmt = $pdo->prepare("
            INSERT INTO broadcasts
            (title, message, image_url, button_text, button_url, sent_count)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $message,
            $imageUrl,
            $buttonText,
            $buttonUrl,
            $sent
        ]);

        $success = "تم إرسال الحملة إلى {$sent} مستخدم ✅";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$broadcasts = $pdo->query("
    SELECT *
    FROM broadcasts
    ORDER BY id DESC
    LIMIT 50
")->fetchAll();

$packages = $pdo->query("
    SELECT id, name, points, price
    FROM packages
    WHERE is_active = 1
    ORDER BY display_order ASC, id ASC
")->fetchAll();

adminHeader('الرسائل الجماعية');
?>

<h2 class="mb-1">📢 الرسائل الجماعية</h2>
<p class="text-muted mb-4">أرسل عروض نصية أو عروض بصورة وزر شراء لكل مستخدمي البوت.</p>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">إرسال حملة جديدة</h5>

        <form method="post" enctype="multipart/form-data">
            <div class="row g-3">

                <div class="col-md-12">
                    <label class="form-label">عنوان داخلي للحملة</label>
                    <input type="text" name="title" class="form-control" required
                           placeholder="مثال: عرض باقة المتداول">
                </div>

                <div class="col-md-12">
                    <label class="form-label">نص الرسالة / الكابشن</label>
                    <textarea name="message" rows="7" class="form-control" required
placeholder="🔥 عرض خاص

احصل على نقاط إضافية اليوم واستخدمها في تحليل الشارتات وفحص التوصيات."></textarea>
                </div>

                <div class="col-md-12">
                    <label class="form-label">رابط الصورة اختياري</label>
                    <input type="url" name="image_url" class="form-control"
                           placeholder="اختياري: https://example.com/offer.jpg">
                    <div class="mt-3">
                        <label class="form-label">أو ارفع صورة من جهازك</label>
                        <input type="file" name="image_file" class="form-control" accept="image/*">
                    </div>
                    <small class="text-muted">يجب أن يكون رابط الصورة مباشرًا وينتهي غالبًا بـ jpg أو png.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label">نص الزر اختياري</label>
                    <input type="text" name="button_text" class="form-control"
                           placeholder="مثال: شراء العرض">
                </div>

                <div class="col-md-6">
                    <label class="form-label">رابط الزر اختياري</label>
                    <input type="url" name="button_url" class="form-control"
                           placeholder="https://tradewithai.xyz/bot/paypal/create_order.php?...">
                </div>

                <div class="col-md-12">
                    <label class="form-label">ربط العرض بباقة شراء</label>
                    <select name="package_id" class="form-control">
                        <option value="">بدون باقة - استخدم رابط يدوي</option>
                        <?php foreach ($packages as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name']) ?> -
                                <?= htmlspecialchars($p['points']) ?> نقطة -
                                $<?= htmlspecialchars($p['price']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">إذا اخترت باقة، سيتم إنشاء رابط دفع خاص لكل مستخدم تلقائيًا.</small>
                </div>

                <div class="col-md-12">
                    <button class="btn btn-primary"
                            onclick="return confirm('هل تريد إرسال هذه الحملة لجميع المستخدمين؟')">
                        إرسال الحملة
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">آخر الحملات</h5>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>العنوان</th>
                        <th>الصورة</th>
                        <th>الرسالة</th>
                        <th>الزر</th>
                        <th>تم الإرسال</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($broadcasts as $b): ?>
                    <tr>
                        <td><?= $b['id'] ?></td>
                        <td><?= htmlspecialchars($b['title']) ?></td>
                        <td>
                            <?php if (!empty($b['image_url'])): ?>
                                <a href="<?= htmlspecialchars($b['image_url']) ?>" target="_blank">عرض</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="max-width:360px;">
                            <div style="white-space:pre-wrap;max-height:100px;overflow:auto;">
                                <?= htmlspecialchars($b['message']) ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($b['button_url'])): ?>
                                <a href="<?= htmlspecialchars($b['button_url']) ?>" target="_blank">
                                    <?= htmlspecialchars($b['button_text'] ?: 'الرابط') ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><span class="badge-soft"><?= htmlspecialchars($b['sent_count']) ?></span></td>
                        <td><?= htmlspecialchars($b['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php adminFooter(); ?>