<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $points = (int)($_POST['points'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'USD');
        $description = trim($_POST['description'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 1);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $points <= 0 || $price <= 0) {
            throw new Exception('تأكد من إدخال اسم الباقة، عدد نقاط صحيح، وسعر أكبر من صفر.');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE packages
                SET name=?, points=?, price=?, currency=?, description=?, display_order=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$name, $points, $price, $currency, $description, $display_order, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO packages
                (name, points, price, currency, description, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $points, $price, $currency, $description, $display_order, $is_active]);
        }

        header('Location: packages.php');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM packages WHERE id=?");
    $stmt->execute([$id]);
    header('Location: packages.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id=?");
    $stmt->execute([$id]);
    $edit = $stmt->fetch();
}

$packages = $pdo->query("SELECT * FROM packages ORDER BY display_order ASC, id ASC")->fetchAll();

adminHeader('الباقات');
?>

<h2 class="mb-1">💳 إدارة الباقات</h2>
<p class="text-muted mb-4">تحكم في أسعار النقاط والباقات التي تظهر داخل البوت عند شراء النقاط.</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3"><?= $edit ? 'تعديل باقة' : 'إضافة باقة جديدة' ?></h5>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($edit['id'] ?? 0) ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">اسم الباقة</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= htmlspecialchars($edit['name'] ?? '') ?>"
                           placeholder="مثال: Trader Pack">
                </div>

                <div class="col-md-2">
                    <label class="form-label">النقاط</label>
                    <input type="number" name="points" class="form-control" required
                           value="<?= htmlspecialchars($edit['points'] ?? '') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">السعر</label>
                    <input type="number" step="0.01" name="price" class="form-control" required
                           value="<?= htmlspecialchars($edit['price'] ?? '') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">العملة</label>
                    <input type="text" name="currency" class="form-control"
                           value="<?= htmlspecialchars($edit['currency'] ?? 'USD') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">الترتيب</label>
                    <input type="number" name="display_order" class="form-control"
                           value="<?= htmlspecialchars($edit['display_order'] ?? 1) ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">الوصف</label>
                    <input type="text" name="description" class="form-control"
                           value="<?= htmlspecialchars($edit['description'] ?? '') ?>"
                           placeholder="مثال: الأكثر شعبية">
                </div>

                <div class="col-md-12">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input"
                            <?= !isset($edit) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="form-check-label">الباقة مفعّلة</span>
                    </label>
                </div>

                <div class="col-md-12">
                    <button class="btn btn-primary"><?= $edit ? 'حفظ التعديل' : 'إضافة الباقة' ?></button>
                    <?php if ($edit): ?>
                        <a href="packages.php" class="btn btn-secondary">إلغاء</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">الباقات الحالية</h5>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الباقة</th>
                        <th>النقاط</th>
                        <th>السعر</th>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $p): ?>
                    <tr>
                        <td><?= $p['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($p['name']) ?></strong><br>
                            <small class="text-muted"><?= htmlspecialchars($p['description'] ?? '') ?></small>
                        </td>
                        <td><span class="badge-soft"><?= htmlspecialchars($p['points']) ?> نقطة</span></td>
                        <td><?= htmlspecialchars($p['currency']) ?> <?= number_format((float)$p['price'], 2) ?></td>
                        <td><?= htmlspecialchars($p['display_order'] ?? 1) ?></td>
                        <td>
                            <?= (int)$p['is_active'] === 1
                                ? '<span class="badge bg-success">مفعلة</span>'
                                : '<span class="badge bg-secondary">متوقفة</span>' ?>
                        </td>
                        <td>
                            <a href="packages.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-primary">تعديل</a>
                            <a href="packages.php?delete=<?= $p['id'] ?>"
                               onclick="return confirm('هل تريد حذف الباقة؟')"
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