<?php

require_once __DIR__ . '/auth.php';
requireAdmin();

$userId = (int)$_POST['user_id'];
$points = (int)$_POST['points'];

$stmt = $pdo->prepare("
UPDATE users
SET points_balance =
points_balance + ?
WHERE id = ?
");

$stmt->execute([
$points,
$userId
]);

header('Location: users.php');
exit;