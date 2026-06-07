<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$userId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die('User not found');
}

$totalConversations = $pdo->prepare("SELECT COUNT(*) FROM conversations WHERE user_id=?");
$totalConversations->execute([$userId]);
$totalConversations = $totalConversations->fetchColumn();

$totalPointsUsed = $pdo->prepare("SELECT COALESCE(SUM(points_used),0) FROM conversations WHERE user_id=?");
$totalPointsUsed->execute([$userId]);
$totalPointsUsed = $totalPointsUsed->fetchColumn();

$transactions = $pdo->prepare("
    SELECT *
    FROM transactions
    WHERE user_id=?
    ORDER BY id DESC
    LIMIT 20
");
$transactions->execute([$userId]);
$transactions = $transactions->fetchAll();

$conversations = $pdo->prepare("
    SELECT c.*, s.title AS service_title
    FROM conversations c
    LEFT JOIN bot_services s ON s.service_key = c.service_key
    WHERE c.user_id=?
    ORDER BY c.id DESC
    LIMIT 20
");
$conversations->execute([$userId]);
$conversations = $conversations->fetchAll();

adminHeader('ملف المستخدم');
?>

<h2 class="mb-1">👤 ملف المستخدم</h2>
<p class="text-muted mb-4">عرض نشاط المستخدم الكامل داخل البوت.</p>

<div class="row g-4 mb-4">
    <div class="col-md-3"><div class="card stat-card"><div class="card-body">
        <h6>الرصيد الحالي</h6>
        <h2><?= $user['points_balance'] ?> نقطة</h2>
    </div></div></div>

    <div class="col-md-3"><div class="card stat-card"><div class="card-body">
        <h6>إجمالي الإنفاق</h6>
        <h2>$<?= number_format((float)$user['total_spent'], 2) ?></h2>
    </div></div></div>

    <div class="col-md-3"><div class="card stat-card"><div class="card-body">
        <h6>عدد التحليلات</h6>
        <h2><?= $totalConversations ?></h2>
    </div></div></div>

    <div class="col-md-3"><div class="card stat-card"><div class="card-body">
        <h6>النقاط المستهلكة</h6>
        <h2><?= $totalPointsUsed ?></h2>
    </div></div></div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5>بيانات المستخدم</h5>
        <p><strong>الاسم:</strong> <?= htmlspecialchars($user['first_name'] ?? '-') ?></p>
        <p><strong>Username:</strong> @<?= htmlspecialchars($user['username'] ?? '-') ?></p>
        <p><strong>Telegram ID:</strong> <?= htmlspecialchars($user['telegram_id']) ?></p>
        <p><strong>تاريخ التسجيل:</strong> <?= htmlspecialchars($user['created_at']) ?></p>
        <p><strong>آخر نشاط:</strong> <?= htmlspecialchars($user['last_active_at'] ?? '-') ?></p>

        <form action="user_points.php" method="post" class="d-flex gap-2 mt-3" style="max-width:420px;">
            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
            <input type="number" name="points" class="form-control" placeholder="مثال: 100 أو -50" required>
            <button class="btn btn-primary">تعديل الرصيد</button>
        </form>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3">آخر عمليات الشراء</h5>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الباقة</th>
                        <th>المبلغ</th>
                        <th>النقاط</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= $t['id'] ?></td>
                        <td><?= htmlspecialchars($t['package_name'] ?? '-') ?></td>
                        <td>$<?= number_format((float)$t['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($t['points_added']) ?></td>
                        <td><?= htmlspecialchars($t['payment_status']) ?></td>
                        <td><?= htmlspecialchars($t['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">آخر المحادثات</h5>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الخدمة</th>
                        <th>السؤال</th>
                        <th>الرد</th>
                        <th>النقاط</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($conversations as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['service_title'] ?? $c['service_key']) ?></td>
                        <td style="max-width:260px;white-space:pre-wrap;"><?= htmlspecialchars(mb_substr($c['user_message'] ?? '', 0, 250)) ?></td>
                        <td style="max-width:360px;white-space:pre-wrap;"><?= htmlspecialchars(mb_substr($c['bot_response'] ?? '', 0, 350)) ?></td>
                        <td><?= htmlspecialchars($c['points_used']) ?></td>
                        <td><?= htmlspecialchars($c['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>