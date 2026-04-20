<?php
// config.php - Database configuration
date_default_timezone_set('Asia/Manila'); // SET GLOBALLY FIRST

// Application constants
const SHIFT_START_TIME = '09:00:00';
const GRACE_PERIOD_MINUTES = 15;

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_NAME = getenv('DB_NAME') ?: 'timeclock';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: ''; // change if you use a password
$OPTIONS = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $OPTIONS);
    // Ensure PDO uses the same timezone
    $pdo->exec("SET time_zone = '+08:00'"); // Asia/Manila is UTC+8
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Database connection failed: '.$e->getMessage()]);
    exit;
}
?>