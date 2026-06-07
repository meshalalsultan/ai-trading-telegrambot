<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalConversations = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
$totalPointsUsed = $pdo->query("SELECT COALESCE(SUM(points_used),0) FROM conversations")->fetchColumn();
$totalSales = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE payment_status='completed'")->fetchColumn();

$todaySales = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM transactions 
    WHERE payment_status='completed' 
    AND DATE(created_at)=CURDATE()
")->fetchColumn();

$monthSales = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM transactions 
    WHERE payment_status='completed' 
    AND MONTH(created_at)=MONTH(CURDATE())
    AND YEAR(created_at)=YEAR(CURDATE())
")->fetchColumn();

$latestTransactions = $pdo->query("
    SELECT t.*, u.username, u.telegram_id
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    ORDER BY t.id DESC
    LIMIT 10
")->fetchAll();

adminHeader('Dashboard');
?>

<h2 class="mb-1">📊 لوحة التحكم</h2>
<p class="text-muted mb-4">نظرة عامة على أداء البوت والمبيعات والاستخدام.</p>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card stat-card"><div class="card-body">
            <h6>المستخدمون</h6>
            <h2><?= $totalUsers ?></h2>
        </div></div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card"><div class="card-body">
            <h6>التحليلات</h6>
            <h2><?= $totalConversations ?></h2>
        </div></div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card"><div class="card-body">
            <h6>النقاط المستهلكة</h6>
            <h2><?= $totalPointsUsed ?></h2>
        </div></div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card"><div class="card-body">
            <h6>إجمالي المبيعات</h6>
            <h2>$<?= number_format((float)$totalSales, 2) ?></h2>
        </div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card stat-card"><div class="card-body">
            <h6>دخل اليوم</h6>
            <h2>$<?= number_format((float)$todaySales, 2) ?></h2>
        </div></div>
    </div>

    <div class="col-md-6">
        <div class="card stat-card"><div class="card-body">
            <h6>دخل الشهر</h6>
            <h2>$<?= number_format((float)$monthSales, 2) ?></h2>
        </div></div>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">آخر عمليات الدفع</h5>

        <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>المستخدم</th>
                    <th>الباقة</th>
                    <th>المبلغ</th>
                    <th>النقاط</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($latestTransactions as $t): ?>
                <tr>
                    <td><?= $t['id'] ?></td>
                    <td><?= htmlspecialchars($t['username'] ?? $t['telegram_id'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($t['package_name'] ?? '-') ?></td>
                    <td>$<?= htmlspecialchars($t['amount'] ?? '0') ?></td>
                    <td><span class="badge-soft"><?= htmlspecialchars($t['points_added'] ?? '0') ?></span></td>
                    <td><?= htmlspecialchars($t['payment_status'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($t['created_at'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
