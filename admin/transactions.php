<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$status = trim($_GET['status'] ?? '');

$where = '';
$params = [];

if ($status !== '') {
    $where = "WHERE t.payment_status = ?";
    $params[] = $status;
}

$stmt = $pdo->prepare("
    SELECT
        t.*,
        u.username,
        u.first_name,
        u.telegram_id
    FROM transactions t
    LEFT JOIN users u
        ON u.id = t.user_id
    {$where}
    ORDER BY t.id DESC
    LIMIT 500
");

$stmt->execute($params);
$transactions = $stmt->fetchAll();

$totalRevenue = $pdo->query("
    SELECT COALESCE(SUM(amount),0)
    FROM transactions
    WHERE payment_status='completed'
")->fetchColumn();

$totalOrders = $pdo->query("
    SELECT COUNT(*)
    FROM transactions
")->fetchColumn();

$totalCompleted = $pdo->query("
    SELECT COUNT(*)
    FROM transactions
    WHERE payment_status='completed'
")->fetchColumn();

$totalPending = $pdo->query("
    SELECT COUNT(*)
    FROM transactions
    WHERE payment_status='pending'
")->fetchColumn();

adminHeader('المدفوعات');
?>

<h2 class="mb-1">💰 المدفوعات والمعاملات</h2>
<p class="text-muted mb-4">متابعة عمليات الشراء والاشتراكات.</p>

<div class="row g-4 mb-4">

<div class="col-md-3">
<div class="card stat-card">
<div class="card-body">
<h6>إجمالي الدخل</h6>
<h2>$<?= number_format($totalRevenue,2) ?></h2>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
<div class="card-body">
<h6>إجمالي العمليات</h6>
<h2><?= $totalOrders ?></h2>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
<div class="card-body">
<h6>مكتملة</h6>
<h2><?= $totalCompleted ?></h2>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
<div class="card-body">
<h6>معلقة</h6>
<h2><?= $totalPending ?></h2>
</div>
</div>
</div>

</div>

<div class="card table-card mb-4">
<div class="card-body">

<form method="get" class="row g-3">

<div class="col-md-3">
<label class="form-label">الحالة</label>

<select name="status" class="form-control">
<option value="">الكل</option>

<option value="completed"
<?= $status=='completed'?'selected':'' ?>>
مكتملة
</option>

<option value="pending"
<?= $status=='pending'?'selected':'' ?>>
معلقة
</option>

</select>

</div>

<div class="col-md-2 d-flex align-items-end">
<button class="btn btn-primary w-100">
فلترة
</button>
</div>

</form>

</div>
</div>

<div class="card table-card">

<div class="card-body">

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
<th>Order ID</th>
<th>Capture ID</th>
<th>التاريخ</th>
</tr>
</thead>

<tbody>

<?php foreach($transactions as $t): ?>

<tr>

<td><?= $t['id'] ?></td>

<td>
<strong>
<?= htmlspecialchars($t['first_name'] ?? '-') ?>
</strong>
<br>

<small>
@<?= htmlspecialchars($t['username'] ?? '-') ?>
</small>
</td>

<td>
<?= htmlspecialchars($t['package_name'] ?? '-') ?>
</td>

<td>
$<?= number_format($t['amount'],2) ?>
</td>

<td>
<span class="badge-soft">
<?= $t['points_added'] ?>
</span>
</td>

<td>

<?php if($t['payment_status']=='completed'): ?>

<span class="badge bg-success">
Completed
</span>

<?php else: ?>

<span class="badge bg-warning text-dark">
Pending
</span>

<?php endif; ?>

</td>

<td style="max-width:180px;">
<small>
<?= htmlspecialchars($t['paypal_order_id'] ?? '-') ?>
</small>
</td>

<td style="max-width:180px;">
<small>
<?= htmlspecialchars($t['paypal_capture_id'] ?? '-') ?>
</small>
</td>

<td>
<?= htmlspecialchars($t['created_at']) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</div>

<?php adminFooter(); ?>