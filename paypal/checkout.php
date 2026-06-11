<?php
require_once __DIR__ . '/../config.php';

$packageId = (int)($_GET['package_id'] ?? 0);
$telegramId = (int)($_GET['telegram_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1");
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package || !$telegramId) {
    exit('Invalid checkout request');
}

$offerStmt = $pdo->prepare("
    SELECT *
    FROM package_offers
    WHERE package_id = ?
    AND is_active = 1
    AND (start_date IS NULL OR start_date <= NOW())
    AND (end_date IS NULL OR end_date >= NOW())
    ORDER BY id DESC
    LIMIT 1
");
$offerStmt->execute([$packageId]);
$offer = $offerStmt->fetch();

$bonusPoints = $offer ? (int)$offer['bonus_points'] : 0;
$totalPoints = (int)$package['points'] + $bonusPoints;

$mode = setting('paypal_mode', 'sandbox');

if ($mode === 'live') {
    $clientId = setting('paypal_live_client_id');
} else {
    $clientId = setting('paypal_sandbox_client_id');
}

$currency = strtoupper(trim($package['currency'] ?? 'USD'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>إتمام الدفع</title>

<script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode($clientId) ?>&currency=<?= urlencode($currency) ?>&intent=capture&components=buttons"></script>

<style>
body {
    margin: 0;
    background: #f4f6fb;
    font-family: Tahoma, Arial, sans-serif;
}

.container {
    max-width: 520px;
    margin: 40px auto;
    background: #ffffff;
    padding: 28px;
    border-radius: 20px;
    box-shadow: 0 10px 35px rgba(0,0,0,.08);
}

h2 {
    margin-top: 0;
    color: #111827;
}

.package {
    background: #f9fafb;
    padding: 18px;
    border-radius: 16px;
    margin-bottom: 20px;
}

.price {
    font-size: 34px;
    font-weight: bold;
    color: #111827;
}

.points {
    font-size: 18px;
    color: #374151;
    margin-top: 8px;
}

.bonus {
    background: #fff7ed;
    color: #c2410c;
    padding: 10px;
    border-radius: 12px;
    margin-top: 12px;
    font-weight: bold;
}

.note {
    font-size: 14px;
    color: #6b7280;
    margin-top: 18px;
    line-height: 1.8;
}

.success {
    display: none;
    background: #ecfdf5;
    color: #047857;
    padding: 14px;
    border-radius: 12px;
    margin-top: 16px;
    line-height: 1.8;
}

.error {
    display: none;
    background: #fef2f2;
    color: #b91c1c;
    padding: 14px;
    border-radius: 12px;
    margin-top: 16px;
    line-height: 1.8;
}

.loading {
    display: none;
    background: #eff6ff;
    color: #1d4ed8;
    padding: 14px;
    border-radius: 12px;
    margin-top: 16px;
    line-height: 1.8;
}
</style>
</head>

<body>

<div class="container">

    <h2>💳 إتمام شراء النقاط</h2>

    <div class="package">
        <h3><?= htmlspecialchars($package['name']) ?></h3>

        <div class="price">
            <?= htmlspecialchars($currency) ?> <?= number_format((float)$package['price'], 2) ?>
        </div>

        <div class="points">
            النقاط الأساسية: <?= (int)$package['points'] ?> نقطة
        </div>

        <?php if ($bonusPoints > 0): ?>
            <div class="bonus">
                🔥 عرض نشط: +<?= $bonusPoints ?> نقطة هدية<br>
                الإجمالي بعد الدفع: <?= $totalPoints ?> نقطة
            </div>
        <?php else: ?>
            <div class="points">
                الإجمالي بعد الدفع: <?= $totalPoints ?> نقطة
            </div>
        <?php endif; ?>
    </div>

    <div id="paypal-button-container"></div>

    <div id="loadingBox" class="loading">
        ⏳ جاري تأكيد الدفع وإضافة النقاط...
    </div>

    <div id="successBox" class="success">
        ✅ تم الدفع بنجاح وتمت إضافة النقاط إلى حسابك.<br>
        يمكنك الرجوع الآن إلى Telegram.
    </div>

    <div id="errorBox" class="error">
        حدثت مشكلة أثناء الدفع. إذا استمرت المشكلة تواصل معي على Telegram: <b>@Mask_Trader_ai</b>
    </div>

    <div class="note">
        يتم الدفع عبر PayPal Checkout الرسمي.
        <br>
        طرق الدفع التي تظهر تعتمد على PayPal وبلد العميل والبطاقة المتاحة له.
    </div>

</div>

<script>
function showError(message) {
    console.error(message);
    document.getElementById('loadingBox').style.display = 'none';
    document.getElementById('errorBox').style.display = 'block';
}

paypal.Buttons({
    style: {
        layout: 'vertical',
        color: 'blue',
        shape: 'rect',
        label: 'paypal'
    },

    createOrder: function(data, actions) {
        document.getElementById('errorBox').style.display = 'none';

        return fetch('sdk_create_order.php?package_id=<?= (int)$packageId ?>&telegram_id=<?= (int)$telegramId ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(orderData) {
            if (!orderData || !orderData.id) {
                showError(orderData);
                throw new Error('PayPal order id not received');
            }

            return orderData.id;
        });
    },

    onApprove: function(data, actions) {
        document.getElementById('loadingBox').style.display = 'block';
        document.getElementById('errorBox').style.display = 'none';

        return fetch('sdk_capture_order.php?order_id=' + encodeURIComponent(data.orderID), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(details) {
            document.getElementById('loadingBox').style.display = 'none';

            if (details && details.success) {
                document.getElementById('successBox').style.display = 'block';
                document.getElementById('paypal-button-container').style.display = 'none';
            } else {
                showError(details);
            }
        })
        .catch(function(error) {
            showError(error);
        });
    },

    onCancel: function(data) {
        console.log('Payment cancelled', data);
    },

    onError: function(err) {
        showError(err);
    }
}).render('#paypal-button-container');
</script>

</body>
</html>