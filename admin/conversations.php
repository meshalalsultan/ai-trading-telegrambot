<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$service = trim($_GET['service'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(c.user_message LIKE ? OR c.bot_response LIKE ? OR u.username LIKE ? OR u.first_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($service !== '') {
    $where[] = "c.service_key = ?";
    $params[] = $service;
}

if ($userId > 0) {
    $where[] = "c.user_id = ?";
    $params[] = $userId;
}

if ($dateFrom !== '') {
    $where[] = "DATE(c.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "DATE(c.created_at) <= ?";
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalConversations = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
$totalPoints = $pdo->query("SELECT COALESCE(SUM(points_used),0) FROM conversations")->fetchColumn();
$totalToday = $pdo->query("SELECT COUNT(*) FROM conversations WHERE DATE(created_at)=CURDATE()")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        c.*,
        u.username,
        u.first_name,
        u.telegram_id,
        s.title AS service_title
    FROM conversations c
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN bot_services s ON s.service_key = c.service_key
    {$whereSql}
    ORDER BY c.id DESC
    LIMIT 200
");
$stmt->execute($params);
$conversations = $stmt->fetchAll();

$services = $pdo->query("
    SELECT service_key, title
    FROM bot_services
    ORDER BY title ASC
")->fetchAll();

adminHeader('المحادثات');
?>

<h2 class="mb-1">💬 المحادثات والتحليلات</h2>
<p class="text-muted mb-4">راجع أسئلة المستخدمين وردود الذكاء الاصطناعي لتحسين الخدمات والبرومبتات.</p>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card stat-card"><div class="card-body">
            <h6>إجمالي المحادثات</h6>
            <h2><?= $totalConversations ?></h2>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card"><div class="card-body">
            <h6>محادثات اليوم</h6>
            <h2><?= $totalToday ?></h2>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card"><div class="card-body">
            <h6>النقاط المستهلكة</h6>
            <h2><?= $totalPoints ?></h2>
        </div></div>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">بحث</label>
                <input type="text" name="search" class="form-control"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="ابحث في السؤال أو الرد">
            </div>

            <div class="col-md-3">
                <label class="form-label">الخدمة</label>
                <select name="service" class="form-control">
                    <option value="">كل الخدمات</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= htmlspecialchars($s['service_key']) ?>"
                            <?= $service === $s['service_key'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" name="date_from" class="form-control"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control"
                       value="<?= htmlspecialchars($dateTo) ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-primary w-100">فلترة</button>
                <a href="conversations.php" class="btn btn-secondary">مسح</a>
            </div>
        </form>
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
                        <th>المستخدم</th>
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

                        <td>
                            <strong><?= htmlspecialchars($c['first_name'] ?? '-') ?></strong><br>
                            <small class="text-muted">
                                @<?= htmlspecialchars($c['username'] ?? '-') ?>
                                | <?= htmlspecialchars($c['telegram_id'] ?? '-') ?>
                            </small>
                        </td>

                        <td>
                            <span class="badge-soft">
                                <?= htmlspecialchars($c['service_title'] ?? $c['service_key'] ?? '-') ?>
                            </span>
                        </td>

                        <td style="max-width:260px;">
                            <div style="white-space:pre-wrap;max-height:90px;overflow:auto;">
                                <?= htmlspecialchars(mb_substr($c['user_message'] ?? '', 0, 300)) ?>
                            </div>
                        </td>

                        <td style="max-width:360px;">
                            <div style="white-space:pre-wrap;max-height:120px;overflow:auto;">
                                <?= htmlspecialchars(mb_substr($c['bot_response'] ?? '', 0, 500)) ?>
                            </div>
                        </td>

                        <td><?= htmlspecialchars($c['points_used'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($c['created_at'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <small class="text-muted">يتم عرض آخر 200 محادثة فقط لحماية سرعة الصفحة.</small>
    </div>
</div>

<?php adminFooter(); ?>