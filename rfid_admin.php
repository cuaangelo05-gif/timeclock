<?php
// rfid_admin.php - Admin panel for managing RFID cards
session_start();
require 'config.php';

// Simple authentication (you should replace this with proper auth)
$IS_ADMIN = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) 
         || (isset($_GET['auth']) && $_GET['auth'] === 'admin123'); // Change 'admin123' to a real password!

if (!$IS_ADMIN) {
    http_response_code(403);
    echo '<h1>Unauthorized</h1><p>Admin access required. <a href="?auth=admin123">Login</a></p>';
    exit;
}

if ($IS_ADMIN && isset($_GET['auth'])) {
    $_SESSION['is_admin'] = true;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    try {
        // Check if rfid_mapping table exists
        $checkStmt = $pdo->prepare("
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'rfid_mapping'
        ");
        $checkStmt->execute();
        $tableExists = $checkStmt->fetchColumn();
        
        if (!$tableExists) {
            // Create table if it doesn't exist
            $pdo->exec("
                CREATE TABLE rfid_mapping (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rfid_code VARCHAR(255) UNIQUE NOT NULL,
                    employee_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
                    INDEX idx_rfid_code (rfid_code),
                    INDEX idx_employee_id (employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        if ($action === 'add') {
            $rfidCode = trim($_POST['rfid_code'] ?? '');
            $empId = trim($_POST['employee_id'] ?? '');
            
            if (!$rfidCode || !$empId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'RFID code and employee ID required']);
                exit;
            }
            
            // Check employee exists
            $empStmt = $pdo->prepare("SELECT id, name FROM employees WHERE id = ?");
            $empStmt->execute([$empId]);
            $emp = $empStmt->fetch();
            
            if (!$emp) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
                exit;
            }
            
            // Insert or update mapping
            $stmt = $pdo->prepare("
                INSERT INTO rfid_mapping (rfid_code, employee_id) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE employee_id = ?
            ");
            $stmt->execute([$rfidCode, $empId, $empId]);
            
            echo json_encode([
                'status' => 'ok',
                'message' => 'RFID card added for ' . $emp['name'],
                'employee_name' => $emp['name']
            ]);
            
        } elseif ($action === 'delete') {
            $rfidCode = trim($_POST['rfid_code'] ?? '');
            
            if (!$rfidCode) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'RFID code required']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM rfid_mapping WHERE rfid_code = ?");
            $stmt->execute([$rfidCode]);
            
            echo json_encode(['status' => 'ok', 'message' => 'RFID card removed']);
        } elseif ($action === 'edit') {
            $oldRfidCode = trim($_POST['old_rfid_code'] ?? '');
            $newRfidCode = trim($_POST['new_rfid_code'] ?? '');
            $empId = trim($_POST['employee_id'] ?? '');
            
            if (!$oldRfidCode || !$newRfidCode || !$empId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Current code, new RFID code and employee ID are required']);
                exit;
            }
            
            $empStmt = $pdo->prepare("SELECT id, name FROM employees WHERE id = ?");
            $empStmt->execute([$empId]);
            $emp = $empStmt->fetch();
            
            if (!$emp) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE rfid_mapping SET rfid_code = ?, employee_id = ? WHERE rfid_code = ?");
            $stmt->execute([$newRfidCode, $empId, $oldRfidCode]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'RFID mapping not found']);
                exit;
            }
            
            echo json_encode([
                'status' => 'ok',
                'message' => 'RFID card updated for ' . $emp['name'],
                'employee_name' => $emp['name']
            ]);
        } elseif ($action === 'write') {
            $rfidCode = trim($_POST['rfid_code'] ?? '');
            $empId = trim($_POST['employee_id'] ?? '');
            
            if (!$rfidCode || !$empId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'RFID code and employee ID required']);
                exit;
            }
            
            // Check employee exists
            $empStmt = $pdo->prepare("SELECT id, name FROM employees WHERE id = ?");
            $empStmt->execute([$empId]);
            $emp = $empStmt->fetch();
            
            if (!$emp) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
                exit;
            }
            
            // TODO: Integrate with actual RFID writer hardware here
            // Example pseudocode:
            // $writer = new RFIDWriter('/dev/ttyUSB0');
            // $writer->writeTag($rfidCode);
            // $writer->close();
            
            // For now, just simulate the write and add to mapping
            $stmt = $pdo->prepare("
                INSERT INTO rfid_mapping (rfid_code, employee_id) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE employee_id = ?
            ");
            $stmt->execute([$rfidCode, $empId, $empId]);
            
            echo json_encode([
                'status' => 'ok',
                'message' => 'RFID card write simulated for ' . $emp['name'] . '. Code: ' . $rfidCode . ' (Connect hardware to enable actual writing)',
                'employee_name' => $emp['name']
            ]);
            
        } elseif ($action === 'list') {
            $stmt = $pdo->query("
                SELECT r.rfid_code, r.employee_id, e.name 
                FROM rfid_mapping r
                LEFT JOIN employees e ON r.employee_id = e.id
                ORDER BY r.created_at DESC
            ");
            $mappings = $stmt->fetchAll();
            echo json_encode(['status' => 'ok', 'data' => $mappings]);
            
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get employees list
try {
    $empStmt = $pdo->query("SELECT id, name FROM employees ORDER BY name");
    $employees = $empStmt->fetchAll();
} catch (Exception $e) {
    $employees = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Card Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .subtitle {
            color: #666;
            font-size: 14px;
        }
        
        .card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.1);
        }
        
        button {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #45a049;
        }
        
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .btn-danger {
            background: #f44336;
        }
        
        .btn-danger:hover {
            background: #da190b;
        }
        
        .message {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th {
            background: #f9f9f9;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #ddd;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f9f9f9;
        }
        
        .code-cell {
            font-family: 'Courier New', monospace;
            color: #666;
            word-break: break-all;
        }
        
        .loading {
            text-align: center;
            color: #999;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>RFID Card Management</h1>
            <p class="subtitle">Manage RFID card to employee ID mappings</p>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 15px;">Add New RFID Card</h2>
            <div id="message"></div>
            
            <form id="addForm" onsubmit="handleAddRFID(event)">
                <div class="form-group">
                    <label for="rfidCode">RFID Card Code</label>
                    <input type="text" id="rfidCode" placeholder="Scan card or paste code" required>
                </div>
                
                <div class="form-group">
                    <label for="employeeSelect">Employee</label>
                    <select id="employeeSelect" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['id']) ?>">
                                <?= htmlspecialchars($emp['id'] . ' - ' . $emp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit">Add RFID Card</button>
            </form>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 15px;">Write RFID Card (Sample)</h2>
            <div id="writeMessage"></div>
            
            <form id="writeForm" onsubmit="handleWriteRFID(event)">
                <div class="form-group">
                    <label for="rfidCodeToWrite">RFID Code to Write</label>
                    <input type="text" id="rfidCodeToWrite" placeholder="Enter code to write to card" required>
                </div>
                
                <div class="form-group">
                    <label for="employeeSelectWrite">Employee</label>
                    <select id="employeeSelectWrite" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['id']) ?>">
                                <?= htmlspecialchars($emp['id'] . ' - ' . $emp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="background: #f0f0f0; padding: 12px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; color: #666;">
                    <strong>ℹ️ Note:</strong> This is a sample writer interface. Connect your RFID writer hardware to your server to enable actual writing. Once connected, this form will communicate with the writer device.
                </div>
                
                <button type="submit">Write RFID Card</button>
            </form>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 15px;">Existing RFID Cards</h2>
            <div id="tableContainer" class="loading">
                <p>Loading RFID cards...</p>
            </div>
        </div>
    </div>
    
    <script>
        const employeesData = <?php echo json_encode($employees); ?>;

        async function handleAddRFID(event) {
            event.preventDefault();
            
            const rfidCode = document.getElementById('rfidCode').value.trim();
            const empId = document.getElementById('employeeSelect').value;
            
            if (!rfidCode || !empId) {
                showMessage('Please fill in all fields', 'error');
                return;
            }
            
            try {
                const response = await fetch('rfid_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=add&rfid_code=${encodeURIComponent(rfidCode)}&employee_id=${encodeURIComponent(empId)}`
                });
                
                const data = await response.json();
                
                if (response.ok && data.status === 'ok') {
                    showMessage(data.message, 'success');
                    document.getElementById('rfidCode').value = '';
                    document.getElementById('employeeSelect').value = '';
                    loadRFIDCards();
                } else {
                    showMessage(data.message || 'Error adding RFID card', 'error');
                }
            } catch (error) {
                showMessage('Network error: ' + error.message, 'error');
            }
        }
        
        async function handleWriteRFID(event) {
            event.preventDefault();
            
            const rfidCode = document.getElementById('rfidCodeToWrite').value.trim();
            const empId = document.getElementById('employeeSelectWrite').value;
            
            if (!rfidCode || !empId) {
                showWriteMessage('Please fill in all fields', 'error');
                return;
            }
            
            try {
                const response = await fetch('rfid_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=write&rfid_code=${encodeURIComponent(rfidCode)}&employee_id=${encodeURIComponent(empId)}`
                });
                
                const data = await response.json();
                
                if (response.ok && data.status === 'ok') {
                    showWriteMessage(data.message, 'success');
                    document.getElementById('rfidCodeToWrite').value = '';
                    document.getElementById('employeeSelectWrite').value = '';
                    loadRFIDCards();
                } else {
                    showWriteMessage(data.message || 'Error writing RFID card', 'error');
                }
            } catch (error) {
                showWriteMessage('Network error: ' + error.message, 'error');
            }
        }
        
        async function deleteRFID(rfidCode) {
            if (!confirm('Delete this RFID card?')) return;
            
            try {
                const response = await fetch('rfid_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete&rfid_code=${encodeURIComponent(rfidCode)}`
                });
                
                const data = await response.json();
                
                if (response.ok && data.status === 'ok') {
                    showMessage(data.message, 'success');
                    loadRFIDCards();
                } else {
                    showMessage(data.message || 'Error deleting RFID card', 'error');
                }
            } catch (error) {
                showMessage('Network error: ' + error.message, 'error');
            }
        }

        async function editRFID(rfidCode, currentEmployeeId) {
            const row = document.getElementById(`rfid-row-${cssSafeId(rfidCode)}`);
            if (!row) return;

            const selectHtml = createEmployeeSelect(currentEmployeeId, rfidCode);
            row.innerHTML = `
                <td class="code-cell"><input type="text" id="row-code-${cssSafeId(rfidCode)}" value="${escapeHtml(rfidCode)}" style="width: 100%; padding: 8px; box-sizing: border-box;"></td>
                <td>${selectHtml}</td>
                <td>${getEmployeeName(currentEmployeeId) || '(Unknown)'}</td>
                <td>
                    <button onclick="saveRFIDEdit('${rfidCode.replace(/'/g, "\\'")}')">Save</button>
                    <button class="btn-danger" onclick="cancelRFIDEdit()">Cancel</button>
                </td>
            `;
        }

        async function saveRFIDEdit(rfidCode) {
            const codeInput = document.querySelector(`#row-code-${cssSafeId(rfidCode)}`);
            const select = document.querySelector(`#row-select-${cssSafeId(rfidCode)}`);
            if (!codeInput || !select) return;

            const newRfidCode = codeInput.value.trim();
            const empId = select.value;

            if (!newRfidCode || !empId) {
                showMessage('Please provide both RFID code and employee', 'error');
                return;
            }

            try {
                const response = await fetch('rfid_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=edit&old_rfid_code=${encodeURIComponent(rfidCode)}&new_rfid_code=${encodeURIComponent(newRfidCode)}&employee_id=${encodeURIComponent(empId)}`
                });

                const data = await response.json();

                if (response.ok && data.status === 'ok') {
                    showMessage(data.message, 'success');
                    loadRFIDCards();
                } else {
                    showMessage(data.message || 'Error updating RFID card', 'error');
                }
            } catch (error) {
                showMessage('Network error: ' + error.message, 'error');
            }
        }

        function cancelRFIDEdit() {
            loadRFIDCards();
        }

        function getEmployeeName(employeeId) {
            const employee = employeesData.find(emp => String(emp.id) === String(employeeId));
            return employee ? employee.name : '';
        }

        function createEmployeeSelect(selectedId, rowKey) {
            let options = '<option value="">-- Select Employee --</option>';
            employeesData.forEach(emp => {
                const selected = String(emp.id) === String(selectedId) ? 'selected' : '';
                options += `<option value="${emp.id}" ${selected}>${emp.id} - ${emp.name}</option>`;
            });
            return `<select id="row-select-${cssSafeId(rowKey)}">${options}</select>`;
        }

        function escapeHtml(str) {
            return str.replace(/[&<>"]+/g, (match) => {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
                return map[match];
            });
        }

        function cssSafeId(value) {
            return String(value).replace(/[^a-zA-Z0-9_-]/g, '_');
        }

        async function loadRFIDCards() {
            const container = document.getElementById('tableContainer');
            
            try {
                const response = await fetch('rfid_admin.php?action=list', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=list'
                });
                
                const data = await response.json();
                
                if (data.status === 'ok' && data.data.length > 0) {
                    let html = '<table><thead><tr><th>RFID Code</th><th>Employee ID</th><th>Employee Name</th><th>Action</th></tr></thead><tbody>';
                    
                    data.data.forEach(item => {
                        const rowId = `rfid-row-${cssSafeId(item.rfid_code)}`;
                        html += `<tr id="${rowId}">
                            <td class="code-cell">${item.rfid_code}</td>
                            <td>${item.employee_id || ''}</td>
                            <td>${item.name || '(Unknown)'}</td>
                            <td>
                                <button onclick="editRFID('${item.rfid_code.replace(/'/g, "\\'")}','${item.employee_id || ''}')">Edit</button>
                                <button class="btn-danger" onclick="deleteRFID('${item.rfid_code.replace(/'/g, "\\'")}')">Delete</button>
                            </td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">No RFID cards registered yet</p>';
                }
            } catch (error) {
                container.innerHTML = '<p style="color: red;">Error loading RFID cards: ' + error.message + '</p>';
            }
        }
        
        function showMessage(msg, type) {
            const messageEl = document.getElementById('message');
            messageEl.className = `message ${type}`;
            messageEl.textContent = msg;
            
            if (type === 'success') {
                setTimeout(() => {
                    messageEl.textContent = '';
                    messageEl.className = '';
                }, 3000);
            }
        }
        
        function showWriteMessage(msg, type) {
            const messageEl = document.getElementById('writeMessage');
            messageEl.className = `message ${type}`;
            messageEl.textContent = msg;
            
            if (type === 'success') {
                setTimeout(() => {
                    messageEl.textContent = '';
                    messageEl.className = '';
                }, 3000);
            }
        }
        
        // Load RFID cards on page load
        window.addEventListener('load', loadRFIDCards);
        
        // Focus on RFID input for scanning
        document.getElementById('rfidCode').focus();
    </script>
</body>
</html>
