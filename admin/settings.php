<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$success = '';
$error = '';

$keys = [
    'telegram_bot_token',
    'openai_api_key',
    'openai_model',
    'paypal_mode',
    'paypal_sandbox_client_id',
    'paypal_sandbox_client_secret',
    'paypal_live_client_id',
    'paypal_live_client_secret',
    'welcome_bonus_points',
    'welcome_message'
];

function getSettingValue($key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn() ?: '';
}

function updateSettingValue($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                updateSettingValue($key, trim($_POST[$key]));
            }
        }

        $success = 'تم حفظ الإعدادات بنجاح ✅';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$settings = [];
foreach ($keys as $key) {
    $settings[$key] = getSettingValue($key);
}

adminHeader('الإعدادات');
?>

<h2 class="mb-1">⚙️ إعدادات النظام</h2>
<p class="text-muted mb-4">إدارة مفاتيح OpenAI وTelegram وPayPal من لوحة الإدارة.</p>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post">

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">🤖 Telegram</h5>

        <div class="mb-3">
            <label class="form-label">Telegram Bot Token</label>
            <input type="password" name="telegram_bot_token" class="form-control"
                   value="<?= htmlspecialchars($settings['telegram_bot_token']) ?>">
        </div>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">🧠 OpenAI</h5>

        <div class="mb-3">
            <label class="form-label">OpenAI API Key</label>
            <input type="password" name="openai_api_key" class="form-control"
                   value="<?= htmlspecialchars($settings['openai_api_key']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">OpenAI Model</label>
            <input type="text" name="openai_model" class="form-control"
                   value="<?= htmlspecialchars($settings['openai_model']) ?>">
            <small class="text-muted">مثال: gpt-4.1-mini</small>
        </div>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">💳 PayPal</h5>

        <div class="mb-3">
            <label class="form-label">PayPal Mode</label>
            <select name="paypal_mode" class="form-control">
                <option value="sandbox" <?= $settings['paypal_mode'] === 'sandbox' ? 'selected' : '' ?>>
                    Sandbox - تجريبي
                </option>
                <option value="live" <?= $settings['paypal_mode'] === 'live' ? 'selected' : '' ?>>
                    Live - حقيقي
                </option>
            </select>
        </div>

        <hr>

        <h6>Sandbox</h6>

        <div class="mb-3">
            <label class="form-label">Sandbox Client ID</label>
            <input type="password" name="paypal_sandbox_client_id" class="form-control"
                   value="<?= htmlspecialchars($settings['paypal_sandbox_client_id']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Sandbox Secret</label>
            <input type="password" name="paypal_sandbox_client_secret" class="form-control"
                   value="<?= htmlspecialchars($settings['paypal_sandbox_client_secret']) ?>">
        </div>

        <hr>

        <h6>Live</h6>

        <div class="mb-3">
            <label class="form-label">Live Client ID</label>
            <input type="password" name="paypal_live_client_id" class="form-control"
                   value="<?= htmlspecialchars($settings['paypal_live_client_id']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Live Secret</label>
            <input type="password" name="paypal_live_client_secret" class="form-control"
                   value="<?= htmlspecialchars($settings['paypal_live_client_secret']) ?>">
        </div>
    </div>
</div>
<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">👋 رسالة الترحيب</h5>

        <div class="mb-3">
            <label class="form-label">النقاط المجانية للمستخدم الجديد</label>
            <input type="number" name="welcome_bonus_points" class="form-control"
                   value="<?= htmlspecialchars($settings['welcome_bonus_points'] ?? '100') ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">رسالة الترحيب</label>
            <textarea name="welcome_message" class="form-control" rows="8"><?= htmlspecialchars($settings['welcome_message'] ?? '') ?></textarea>
            <small class="text-muted">استخدم {BONUS} ليتم استبدالها بعدد النقاط المجانية تلقائيًا.</small>
        </div>
    </div>
</div>

<button class="btn btn-primary btn-lg">حفظ الإعدادات</button>

</form>

<?php adminFooter(); ?>