<?php
function adminHeader($title = 'Admin') {
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">

<style>
body {
    background:#f4f6fb;
    font-family: Tahoma, Arial, sans-serif;
}
.sidebar {
    width:260px;
    min-height:100vh;
    background:#111827;
    color:white;
    position:fixed;
    right:0;
    top:0;
    padding:24px 16px;
}
.sidebar h4 {
    font-weight:700;
    margin-bottom:28px;
}
.sidebar a {
    display:block;
    color:#d1d5db;
    text-decoration:none;
    padding:12px 14px;
    border-radius:12px;
    margin-bottom:8px;
}
.sidebar a:hover,
.sidebar a.active {
    background:#2563eb;
    color:white;
}
.main {
    margin-right:260px;
    padding:28px;
}
.stat-card {
    border:0;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(15,23,42,.08);
}
.stat-card h6 {
    color:#6b7280;
}
.stat-card h2 {
    font-weight:800;
}
.table-card {
    border:0;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(15,23,42,.08);
}
.table thead th {
    background:#f9fafb;
    color:#374151;
}
.badge-soft {
    background:#eef2ff;
    color:#3730a3;
    padding:8px 12px;
    border-radius:999px;
}
</style>
</head>
<body>

<div class="sidebar">
    <h4>🤖 Trading Bot</h4>
    <a href="dashboard.php">📊 Dashboard</a>
    <a href="users.php">👥 المستخدمون</a>
    <a href="services.php">🧠 الخدمات</a>
    <a href="packages.php">💳 الباقات</a>
    <a href="transactions.php">💰 المدفوعات</a>
    <a href="conversations.php">💬 المحادثات</a>
    <a href="offers.php">🔥 العروض</a>
    <a href="settings.php">⚙️ الإعدادات</a>
    <hr style="border-color:#374151">
    <a href="logout.php">🚪 خروج</a>
</div>

<div class="main">
<?php
}

function adminFooter() {
?>
</div>
</body>
</html>
<?php
}