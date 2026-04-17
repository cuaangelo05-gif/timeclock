<?php
// server_time.php - returns current server ISO timestamp (Asia/Manila) and epoch ms
header('Content-Type: application/json; charset=utf-8');

// Ensure timezone is set BEFORE calling date functions
date_default_timezone_set('Asia/Manila');

try {
    // Method 1: Using DateTimeImmutable (recommended)
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $iso = $dt->format(DateTime::ATOM); // ISO 8601: 2026-02-11T14:30:45+08:00
    $ms = (int) round(microtime(true) * 1000);
    
    // Also return PHP's date format for debugging
    $phpDate = date('Y-m-d H:i:s'); // Asia/Manila time
    
    echo json_encode([
        'status' => 'ok',
        'server_ts' => $iso,           // ISO 8601 with timezone
        'server_ts_ms' => $ms,          // Milliseconds since epoch
        'server_date' => $dt->format('Y-m-d'),
        'server_time' => $dt->format('H:i:s'),
        'server_day' => $dt->format('l'),
        'timezone' => 'Asia/Manila',
        'php_date' => $phpDate          // For debugging
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'time_error',
        'detail' => $e->getMessage()
    ]);
}
exit;
?>