<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
require_once __DIR__ . '/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $service_key = trim($_POST['service_key'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $points_cost = (int)($_POST['points_cost'] ?? 0);
        $prompt = trim($_POST['prompt'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($service_key === '' || $title === '' || $prompt === '' || $points_cost <= 0) {
            throw new Exception('تأكد من إدخال اسم الخدمة، المفتاح، البرومبت، وسعر نقاط أكبر من صفر.');
        }

        if (!preg_match('/^[a-z0-9_]+$/', $service_key)) {
            throw new Exception('Service Key يجب أن يكون إنجليزي فقط بدون مسافات. مثال: news_analysis');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE bot_services
                SET service_key=?, title=?, description=?, points_cost=?, prompt=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([$service_key, $title, $description, $points_cost, $prompt, $is_active, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO bot_services
                (service_key, title, description, points_cost, prompt, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$service_key, $title, $description, $points_cost, $prompt, $is_active]);
        }

        header('Location: services.php');
        exit;

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'هذا Service Key موجود مسبقًا. اختر مفتاحًا مختلفًا.';
        } else {
            $error = 'Database Error: ' . $e->getMessage();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM bot_services WHERE id=?");
    $stmt->execute([$id]);
    header('Location: services.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM bot_services WHERE id=?");
    $stmt->execute([$id]);
    $edit = $stmt->fetch();
}

$services = $pdo->query("SELECT * FROM bot_services ORDER BY id DESC")->fetchAll();

adminHeader('الخدمات');
?>

<h2 class="mb-1">🧠 إدارة خدمات البوت</h2>
<p class="text-muted mb-4">عدّل اسم الخدمة، سعر النقاط، والبرومبت بدون تعديل الكود.</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card table-card mb-4">
    <div class="card-body">
        <h5 class="mb-3"><?= $edit ? 'تعديل خدمة' : 'إضافة خدمة جديدة' ?></h5>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($edit['id'] ?? 0) ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Service Key</label>
                    <input type="text" name="service_key" class="form-control" required
                           value="<?= htmlspecialchars($edit['service_key'] ?? '') ?>"
                           placeholder="مثال: check_signal">
                </div>

                <div class="col-md-4">
                    <label class="form-label">اسم الخدمة</label>
                    <input type="text" name="title" class="form-control" required
                           value="<?= htmlspecialchars($edit['title'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">تكلفة النقاط</label>
                    <input type="number" name="points_cost" class="form-control" required
                           value="<?= htmlspecialchars($edit['points_cost'] ?? '') ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">الوصف</label>
                    <input type="text" name="description" class="form-control"
                           value="<?= htmlspecialchars($edit['description'] ?? '') ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">البرومبت</label>
                    <textarea name="prompt" class="form-control" rows="8" required><?= htmlspecialchars($edit['prompt'] ?? '') ?></textarea>
                </div>

                <div class="col-md-12">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input"
                            <?= !isset($edit) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span class="form-check-label">الخدمة مفعّلة</span>
                    </label>
                </div>

                <div class="col-md-12">
                    <button class="btn btn-primary"><?= $edit ? 'حفظ التعديل' : 'إضافة الخدمة' ?></button>
                    <?php if ($edit): ?>
                        <a href="services.php" class="btn btn-secondary">إلغاء</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <h5 class="mb-3">الخدمات الحالية</h5>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الخدمة</th>
                        <th>Key</th>
                        <th>النقاط</th>
                        <th>الحالة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= $s['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($s['title']) ?></strong><br>
                            <small class="text-muted"><?= htmlspecialchars($s['description'] ?? '') ?></small>
                        </td>
                        <td><code><?= htmlspecialchars($s['service_key']) ?></code></td>
                        <td><span class="badge-soft"><?= htmlspecialchars($s['points_cost']) ?> نقطة</span></td>
                        <td>
                            <?= (int)$s['is_active'] === 1
                                ? '<span class="badge bg-success">مفعلة</span>'
                                : '<span class="badge bg-secondary">متوقفة</span>' ?>
                        </td>
                        <td>
                            <a href="services.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-primary">تعديل</a>
                            <a href="services.php?delete=<?= $s['id'] ?>"
                               onclick="return confirm('هل تريد حذف الخدمة؟')"
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