<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$users = $pdo->query("
SELECT
u.*,
COUNT(c.id) as total_conversations
FROM users u
LEFT JOIN conversations c ON c.user_id = u.id
GROUP BY u.id
ORDER BY u.id DESC
")->fetchAll();

adminHeader('المستخدمون');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">👥 المستخدمون</h2>
        <p class="text-muted mb-0">إدارة أرصدة المستخدمين ومتابعة نشاطهم.</p>
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
                    <th>Telegram ID</th>
                    <th>الرصيد</th>
                    <th>الإنفاق</th>
                    <th>التحليلات</th>
                    <th>آخر نشاط</th>
                    <th>تعديل الرصيد</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($user['first_name'] ?? '-') ?></strong><br>
                        <small class="text-muted">@<?= htmlspecialchars($user['username'] ?? '-') ?></small>
                    </td>
                    <td><?= htmlspecialchars($user['telegram_id']) ?></td>
                    <td><span class="badge-soft"><?= htmlspecialchars($user['points_balance']) ?> نقطة</span></td>
                    <td>$<?= number_format((float)$user['total_spent'], 2) ?></td>
                    <td><?= $user['total_conversations'] ?></td>
                    <td><?= htmlspecialchars($user['last_active_at'] ?? '-') ?></td>
                    <td style="min-width:260px;">
                        <a href="user_view.php?id=<?= $user['id'] ?>" class="btn btn-dark">عرض</a>
                        <form action="user_points.php" method="post" class="d-flex gap-2">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="number" name="points" class="form-control" placeholder="مثال: 100 أو -50" required>
                            <button class="btn btn-primary">حفظ</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>