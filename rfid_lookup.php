<?php
// rfid_lookup.php - Look up employee ID from RFID code
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');
require 'config.php';

function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Accept RFID code from AJAX request
$rfidCode = trim($_POST['rfid_code'] ?? $_GET['rfid_code'] ?? '');

if (!$rfidCode) {
    json_out(['status' => 'error', 'message' => 'RFID code required'], 400);
}

try {
    // First, try direct lookup in rfid_mapping table if it exists
    try {
        $checkTableStmt = $pdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'rfid_mapping'
        ");
        $checkTableStmt->execute();
        $tableExists = $checkTableStmt->fetchColumn();

        if ($tableExists) {
            $stmt = $pdo->prepare("
                SELECT employee_id FROM rfid_mapping 
                WHERE rfid_code = ? LIMIT 1
            ");
            $stmt->execute([$rfidCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                json_out([
                    'status' => 'ok',
                    'employee_id' => $result['employee_id'],
                    'source' => 'rfid_mapping'
                ]);
            }
        }
    } catch (Exception $e) {
        // Table might not exist, continue with alternative methods
    }

    // Alternative: Check if RFID code itself is an employee ID
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$rfidCode]);
    if ($stmt->fetchColumn()) {
        json_out([
            'status' => 'ok',
            'employee_id' => $rfidCode,
            'source' => 'direct_id'
        ]);
    }

    // Alternative: Check if RFID code exists as id_code field in employees
    $checkIdCodeStmt = $pdo->prepare("
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = DATABASE() AND table_name = 'employees' 
        AND column_name = 'id_code'
    ");
    $checkIdCodeStmt->execute();
    if ($checkIdCodeStmt->fetchColumn()) {
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE id_code = ? LIMIT 1");
        $stmt->execute([$rfidCode]);
        $empId = $stmt->fetchColumn();
        if ($empId) {
            json_out([
                'status' => 'ok',
                'employee_id' => $empId,
                'source' => 'id_code'
            ]);
        }
    }

    // Not found
    json_out(['status' => 'not_found', 'message' => 'RFID code not recognized'], 404);

} catch (Exception $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
?>
