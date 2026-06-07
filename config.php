<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kuwait');

$DB_HOST = 'localhost';
$DB_NAME = 'meshjfng_bot';
$DB_USER = 'meshjfng_m_bot';
$DB_PASS = 'MIsh3al10$';

$pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

function setting(string $key, ?string $default = null): ?string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}