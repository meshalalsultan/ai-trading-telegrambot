<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $package_id = (int)($_POST['package_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $bonus_points = (int)($_POST['bonus_points'] ?? 0);
        $start_date = $_POST['start_date'] ?: null;
        $end_date = $_POST['end_date'] ?: null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($package_id <= 0 || $title === '') {
            throw new Exception('اختر الباقة واكتب عنوان العرض.');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE package_offers
                SET package_id=?, title=?, bonus_points=?, start_date=?, end_date=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$package_id, $title, $bonus_points, $start_date, $end_date, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO package_offers
                (package_id, title, bonus_points, start_date, end_date, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$package_id, $title, $bonus_points, $start_date, $end_date, $is_active]);
        }

        header('Location: offers.php');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM package_offers WHERE id=?");
    $stmt->execute([$id]);
    header('Location: offers.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM package_offers WHERE id=?");
    $stmt->execute([$id]);
    $edit = $stmt->fetch();
}

$packages = $pdo->query("
    SELECT id, name, points, price
    FROM packages
    WHERE is_active=1
    ORDER BY display_order ASC, id ASC
")->fetchAll();

$offers = $pdo->query("
    SELECT o.*, p.name AS package_name, p.points AS base_points, p.price
    FROM package_offers o
    LEFT JOIN packages p ON p.id = o.package_id
    ORDER BY o.id DESC
")->fetchAll();

adminHeader('العروض');
?>

<h2 class="mb-1">🔥 إدارة العروض</h2>
<p class="text-muted mb-4">اربط أي باقة ببونص نقاط مؤقت بدون تعديل الباقة الأصلية.</p>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3"><?= $edit ? 'تعديل عرض' : 'إضافة عرض جديد' ?></h5>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($edit['id'] ?? 0) ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">عنوان العرض</label>
                    <input type="text" name="title" class="form-control" required
                           value="<?= htmlspecialchars($edit['title'] ?? '') ?>"
                           placeholder="مثال: عرض نهاية الأسبوع">
                </div>

                <div class="col-md-4">
                    <label class="form-label">الباقة</label>
                    <select name="package_id" class="form-control" required>
                        <option value="">اختر الباقة</option>
                        <?php foreach ($packages as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= (int)($edit['package_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?> - <?= $p['points'] ?> نقطة - $<?= $p['price'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">نقاط البونص</label>
                    <input type="number" name="bonus_points" class="form-control"
                           value="<?= htmlspecialchars($edit['bonus_points'] ?? 0) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">بداية العرض</label>
                    <input type="datetime-local" name="start_date" class="form-control"
                           value="<?= isset($edit['start_date']) && $edit['start_date'] ? date('Y-m-d\TH:i', strtotime($edit['start_date'])) : '' ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">نهاية العرض</label>
                    <input type="datetime-local" name="end_date" class="form-control"
                           value="<?= isset($edit['end_date']) && $edit['end_date'] ? date('Y-m-d\TH:i', strtotime($edit['end_date'])) : '' ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input"
                            <?= !isset($edit) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="form-check-label">العرض مفعّل</span>
                    </label>
                </div>

                <div class="col-md-12">
                    <button class="btn btn-primary"><?= $edit ? 'حفظ التعديل' : 'إضافة العرض' ?></button>
                    <?php if ($edit): ?>
                        <a href="offers.php" class="btn btn-secondary">إلغاء</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">العروض الحالية</h5>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>العرض</th>
                        <th>الباقة</th>
                        <th>النقاط</th>
                        <th>البونص</th>
                        <th>الإجمالي</th>
                        <th>الحالة</th>
                        <th>المدة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($offers as $o): ?>
                    <tr>
                        <td><?= $o['id'] ?></td>
                        <td><?= htmlspecialchars($o['title']) ?></td>
                        <td><?= htmlspecialchars($o['package_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($o['base_points'] ?? 0) ?></td>
                        <td><span class="badge-soft">+<?= htmlspecialchars($o['bonus_points']) ?></span></td>
                        <td><?= (int)($o['base_points'] ?? 0) + (int)$o['bonus_points'] ?></td>
                        <td>
                            <?= (int)$o['is_active'] === 1
                                ? '<span class="badge bg-success">مفعل</span>'
                                : '<span class="badge bg-secondary">متوقف</span>' ?>
                        </td>
                        <td>
                            <small>
                                <?= htmlspecialchars($o['start_date'] ?? '-') ?><br>
                                <?= htmlspecialchars($o['end_date'] ?? '-') ?>
                            </small>
                        </td>
                        <td>
                            <a href="offers.php?edit=<?= $o['id'] ?>" class="btn btn-sm btn-primary">تعديل</a>
                            <a href="offers.php?delete=<?= $o['id'] ?>"
                               onclick="return confirm('حذف العرض؟')"
                               class="btn btn-sm btn-danger">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php adminFooter(); ?>