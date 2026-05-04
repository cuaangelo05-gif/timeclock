<?php
require_once __DIR__ . '/admin_auth.php';
require 'config.php';
$feedback = '';

// Available departments - change/add as needed (fallback)
$defaultDepartments = ['CamFinLending','CashManagement','MAGtech'];

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Helper to safely retrieve POST
$post = function($k, $d = '') {
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
};

$ensureAdminColumn = function($col, $type = 'VARCHAR(255) NULL') use ($pdo) {
    try {
        $pdo->query("SELECT `$col` FROM admins LIMIT 1");
        return true;
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `$col` $type");
            return true;
        } catch (Exception $ex) {
            return false;
        }
    }
};

// ============ SECTION PERSISTENCE ============
// Get target section from POST (or default to current)
$targetSection = $_POST['section'] ?? null;

// --------------------
// Department handlers (unchanged)
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // Accept previous action names for compatibility
    if (in_array($act, ['add_department','add_branch','add_division'], true)) {
        $newDept = trim($_POST['department_name'] ?? $_POST['branch_name'] ?? $_POST['division_name'] ?? '');
        if ($newDept === '') {
            $feedback = "Department name is required.";
        } elseif (!preg_match('/^[A-Za-z0-9 &\-\_\.]{2,100}$/', $newDept)) {
            $feedback = "Department name must be 2-100 chars (letters, numbers, space, & - _ . allowed).";
        } else {
            try {
                // Ensure table exists (best to run SQL manually if DB user cannot CREATE)
                $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $ins = $pdo->prepare('INSERT INTO departments (name) VALUES (?)');
                $ins->execute([$newDept]);
                $feedback = "Department added: " . htmlspecialchars($newDept);
                
                // ✅ REDIRECT TO DEPARTMENTS SECTION
                if (!$targetSection) $targetSection = 'departments';
            } catch (Exception $e) {
                if (stripos($e->getMessage(), 'duplicate') !== false) {
                    $feedback = "That department already exists.";
                } else {
                    $feedback = "Could not add department: " . $e->getMessage();
                }
            }
        }
    }

    // ============ WRITE RFID ACTION ============
    if ($act === 'write_rfid') {
        $rfidCode = trim($_POST['rfid_code'] ?? '');
        $empId = trim($_POST['employee_id'] ?? '');
        
        // Check if handling JSON response (API call from JS)
        $isJson = isset($_POST['rfid_code']) && empty($_POST['employee_id']) ? false : 
                 (!empty($_POST['employee_id']) && !empty($_POST['rfid_code']));
        
        if (!$rfidCode || !$empId) {
            if ($isJson) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'RFID code and employee ID required']);
            } else {
                $feedback = "RFID code and employee ID required.";
            }
        } else {
            try {
                // Verify employee exists
                $empStmt = $pdo->prepare("SELECT id, name FROM employees WHERE id = ?");
                $empStmt->execute([$empId]);
                $emp = $empStmt->fetch();
                
                if (!$emp) {
                    if ($isJson) {
                        header('Content-Type: application/json');
                        http_response_code(404);
                        echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
                    } else {
                        $feedback = "Employee not found.";
                    }
                } else {
                    // Ensure rfid_mapping table exists
                    $checkStmt = $pdo->prepare("
                        SELECT 1 FROM information_schema.tables 
                        WHERE table_schema = DATABASE() AND table_name = 'rfid_mapping'
                    ");
                    $checkStmt->execute();
                    $tableExists = $checkStmt->fetchColumn();
                    
                    if (!$tableExists) {
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
                    
                    // TODO: Integrate with actual RFID writer hardware here
                    // Example: $writer = new RFIDWriter('/dev/ttyUSB0'); $writer->writeTag($rfidCode);
                    
                    // Insert or update mapping
                    $stmt = $pdo->prepare("
                        INSERT INTO rfid_mapping (rfid_code, employee_id) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE employee_id = ?
                    ");
                    $stmt->execute([$rfidCode, $empId, $empId]);
                    
                    if ($isJson) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'status' => 'ok',
                            'message' => 'RFID card write simulated for ' . htmlspecialchars($emp['name']) . '. Code: ' . htmlspecialchars($rfidCode) . ' (Connect hardware for actual writing)',
                            'employee_name' => $emp['name']
                        ]);
                    } else {
                        $feedback = "RFID write simulated for " . htmlspecialchars($emp['name']);
                        if (!$targetSection) $targetSection = 'rfid';
                    }
                }
            } catch (Exception $e) {
                if ($isJson) {
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                } else {
                    $feedback = "Error: " . $e->getMessage();
                }
            }
        }
        exit;
    }

    if (in_array($act, ['delete_department','delete_branch','delete_division'], true)) {
        $delName = trim($_POST['department_name'] ?? $_POST['branch_name'] ?? $_POST['division_name'] ?? '');
        if ($delName === '') {
            $feedback = "Department name required to delete.";
        } else {
            try {
                // Check which columns exist in employees table
                $empCols = ['department', 'branch', 'division'];
                $existingCols = [];
                foreach ($empCols as $col) {
                    try {
                        $pdo->query("SELECT `$col` FROM employees LIMIT 1");
                        $existingCols[] = $col;
                    } catch (Exception $e) {
                        // Column doesn't exist, skip it
                    }
                }
                
                // Build query to check if employees are assigned
                $conditions = [];
                $params = [];
                foreach ($existingCols as $col) {
                    $conditions[] = "$col = ?";
                    $params[] = $delName;
                }
                
                if (!empty($conditions)) {
                    $sql = 'SELECT COUNT(*) AS c FROM employees WHERE ' . implode(' OR ', $conditions);
                    $c = $pdo->prepare($sql);
                    $c->execute($params);
                    $count = (int)($c->fetchColumn() ?? 0);
                } else {
                    $count = 0; // No columns to check
                }
                
                if ($count > 0) {
                    $feedback = "Cannot delete department '$delName' because $count employee(s) are assigned. Reassign or remove them first.";
                } else {
                    $del = $pdo->prepare('DELETE FROM departments WHERE name = ?');
                    $del->execute([$delName]);
                    if ($del->rowCount() > 0) {
                        $feedback = "Department deleted: " . htmlspecialchars($delName);
                        // ✅ REDIRECT TO DEPARTMENTS SECTION
                        if (!$targetSection) $targetSection = 'departments';
                    } else {
                        $feedback = "Department not found or already deleted.";
                    }
                }
            } catch (Exception $e) {
                $feedback = "Error deleting department: " . $e->getMessage();
            }
        }
    }

    if ($act === 'add_admin_user') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $photoFilename = null;
        $adminPhotoDir = __DIR__ . '/uploads/admins';
        if (!is_dir($adminPhotoDir)) {
            mkdir($adminPhotoDir, 0755, true);
        }

        if ($username === '' || $password === '' || $full_name === '') {
            $feedback = "Username, password, and full name are required.";
        } elseif (strlen($password) < 6) {
            $feedback = "Password must be at least 6 characters.";
        } elseif (!preg_match('/^[A-Za-z0-9_\-\.]{3,100}$/', $username)) {
            $feedback = "Username may contain letters, numbers, underscore, dash, or dot (3-100 chars).";
        } else {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    fullname VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $ensureAdminColumn('position', 'VARCHAR(100) NULL');
                $ensureAdminColumn('password_plain', 'VARCHAR(255) NULL');
                $ensureAdminColumn('photo', 'VARCHAR(255) NULL');

                $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $feedback = "That admin username is already registered.";
                } else {
                    if (!empty($_FILES['photo']['name'])) {
                        $f = $_FILES['photo'];
                        $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];
                        if ($f['error'] !== UPLOAD_ERR_OK) {
                            $feedback = "Photo upload error (code {$f['error']}).";
                        } elseif ($f['size'] > 2 * 1024 * 1024) {
                            $feedback = "Photo too large (max 2MB).";
                        } else {
                            $detected = @getimagesize($f['tmp_name']);
                            $mime = $detected['mime'] ?? '';
                            if (!in_array($mime, $allowedTypes, true)) {
                                $feedback = "Unsupported photo type. Use JPG, PNG, GIF, or WEBP.";
                            } else {
                                $ext = image_type_to_extension($detected[2], false);
                                $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $username);
                                $photoFilename = $safe . '_' . time() . '.' . $ext;
                                $dest = $adminPhotoDir . '/' . $photoFilename;
                                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                                    $feedback = "Failed to save admin photo.";
                                    $photoFilename = null;
                                }
                            }
                        }
                    }

                    if ($feedback === '') {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare('INSERT INTO admins (username, password_hash, password_plain, fullname, position, photo) VALUES (?, ?, ?, ?, ?, ?)');
                        $ins->execute([$username, $hashed, $password, $full_name, $position, $photoFilename]);
                        $feedback = "Sub admin created: " . htmlspecialchars($full_name) . ".";
                        if (!$targetSection) $targetSection = 'settings';
                    }
                }
            } catch (Exception $e) {
                $feedback = "Could not create sub admin: " . $e->getMessage();
            }
        }
    }

    if ($act === 'delete_admin_user') {
        $adminId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;
        if ($adminId <= 0) {
            $feedback = "Invalid admin selected for deletion.";
        } else {
            try {
                $stmt = $pdo->prepare('SELECT username, photo FROM admins WHERE id = ? LIMIT 1');
                $stmt->execute([$adminId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $feedback = "Admin user not found.";
                } elseif (!empty($_SESSION['admin_user']) && $row['username'] === $_SESSION['admin_user']) {
                    $feedback = "You cannot delete the account you are currently signed in with.";
                } else {
                    $del = $pdo->prepare('DELETE FROM admins WHERE id = ?');
                    $del->execute([$adminId]);
                    if ($row['photo']) {
                        @unlink(__DIR__ . '/uploads/admins/' . $row['photo']);
                    }
                    $feedback = "Admin deleted.";
                    if (!$targetSection) $targetSection = 'settings';
                }
            } catch (Exception $e) {
                $feedback = "Could not delete admin: " . $e->getMessage();
            }
        }
    }
}

// --------------------
// Employee add / delete
// --------------------

// Handle add employee (server-side)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_employee') {
  if (!$targetSection) $targetSection = 'employees'; 
    // Required fields (server-side)
    $id_code = $post('id_code');
    $last_name = $post('last_name');
    $first_name = $post('first_name');
    $middle_initial = $post('middle_initial');
    $department = $post('department', 'General');
    $position = $post('position');
    $employment_type = $post('employment_type', 'Full-time');
    $shift = $post('shift', '');
    $attendance_status = $post('attendance_status', 'Active');
    $date_hired = $post('date_hired', date('Y-m-d'));

    // Basic server-side validation
    if ($id_code === '' || $last_name === '' || $first_name === '' || $department === '' || $position === '' || $employment_type === '' || $shift === '' || $attendance_status === '' || $date_hired === '') {
        $feedback = "All fields are required. Please fill in all fields.";
    } elseif (!preg_match('/^[A-Za-z0-9\-_]{2,64}$/', $id_code)) {
        $feedback = "Employee ID Code may contain letters, numbers, - or _ (2-64 chars).";
    } else {
        // validate date format (YYYY-MM-DD)
        $dobj = DateTime::createFromFormat('Y-m-d', $date_hired);
        if (!$dobj || $dobj->format('Y-m-d') !== $date_hired) {
            $feedback = "Date Hired must be a valid date (YYYY-MM-DD).";
        } else {
            try {
                // check unique id_code
                $stmt = $pdo->prepare('SELECT id FROM employees WHERE id_code = ? LIMIT 1');
                $stmt->execute([$id_code]);
                if ($stmt->fetch()) {
                    $feedback = "That Employee ID Code is already registered.";
                } else {
                    // Handle file upload (photo) - now required
                    $photoFilename = null;
                    if (empty($_FILES['photo']['name'])) {
                        $feedback = "Profile Photo is required.";
                    } else {
                        $f = $_FILES['photo'];
                        // Basic checks
                        $maxBytes = 2 * 1024 * 1024; // 2MB
                        $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];

                        if ($f['error'] !== UPLOAD_ERR_OK) {
                            $feedback = "Photo upload error (code {$f['error']}).";
                        } elseif ($f['size'] > $maxBytes) {
                            $feedback = "Photo too large (max 2MB).";
                        } else {
                            $detected = @getimagesize($f['tmp_name']);
                            $mime = $detected['mime'] ?? '';
                            if (!in_array($mime, $allowedTypes, true)) {
                                $feedback = "Unsupported image type. Use JPG, PNG, GIF or WEBP.";
                            } else {
                                // Generate safe unique filename
                                $ext = image_type_to_extension($detected[2], false); // e.g. "jpeg", "png"
                                $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id_code);
                                $photoFilename = $safe . '_' . time() . '.' . $ext;
                                $dest = $uploadDir . '/' . $photoFilename;
                                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                                    $feedback = "Failed to move uploaded photo.";
                                    $photoFilename = null;
                                }
                            }
                        }
                    }
                    // Proceed only if no photo/file errors
                    if ($feedback === '') {
                        $last_name = trim($_POST['last_name'] ?? '');
                        $first_name = trim($_POST['first_name'] ?? '');
                        $middle_initial = strtoupper(trim($_POST['middle_initial'] ?? ''));
                        $middle_initial = preg_replace('/[^A-Za-z]/', '', $middle_initial);
                        $middle_initial = $middle_initial !== '' ? strtoupper(substr($middle_initial, 0, 1)) : '';

                        if ($last_name === '' || $first_name === '') {
                            $feedback = "First name and last name are required.";
                        }

                        $name = '';
                        if ($feedback === '') {
                            $name = $last_name . ', ' . $first_name;
                            if ($middle_initial !== '') {
                                $name .= ' ' . $middle_initial . '.';
                            }
                        }

                        $sss_number = trim($_POST['sss_number'] ?? '');
                        $philhealth_number = trim($_POST['philhealth_number'] ?? '');
                        $tin_number = trim($_POST['tin_number'] ?? '');
                        $nbi_number = trim($_POST['nbi_number'] ?? '');
                        $pagibig_number = trim($_POST['pagibig_number'] ?? '');

                        // Best-effort: ensure columns exist (non-fatal)
                        $ensureCol = function($col, $type = 'VARCHAR(255) NULL') use ($pdo) {
                            try {
                                $pdo->query("SELECT `$col` FROM employees LIMIT 1");
                                return true;
                            } catch (Exception $e) {
                                try {
                                    $pdo->exec("ALTER TABLE employees ADD COLUMN `$col` $type");
                                    return true;
                                } catch (Exception $ex) {
                                    return false;
                                }
                            }
                        };

                        // Attempt to ensure columns we will use
                        $ensureCol('position', "VARCHAR(150) NULL");
                        $ensureCol('employment_type', "VARCHAR(50) NULL");
                        $ensureCol('shift', "VARCHAR(100) NULL");
                        $ensureCol('attendance_status', "VARCHAR(50) NULL");
                        $ensureCol('date_hired', "DATE NULL");
                        $ensureCol('standard_hours_month', "INT NULL DEFAULT 160");
                        $ensureCol('sss_number', "VARCHAR(64) NULL");
                        $ensureCol('philhealth_number', "VARCHAR(64) NULL");
                        $ensureCol('tin_number', "VARCHAR(64) NULL");
                        $ensureCol('nbi_number', "VARCHAR(64) NULL");
                        $ensureCol('pagibig_number', "VARCHAR(64) NULL");

                        // Insert employee; attempt a multi-column insert
                        try {
                            $ins = $pdo->prepare('INSERT INTO employees (id_code, name, position, employment_type, shift, division, branch, department, last_status, attendance_status, date_hired, photo, sss_number, philhealth_number, tin_number, nbi_number, pagibig_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $ins->execute([
                                $id_code,
                                $name,
                                $position,
                                $employment_type,
                                $shift,
                                $department,
                                $department,
                                $department,
                                'out',
                                $attendance_status,
                                $date_hired,
                                $photoFilename,
                                $sss_number,
                                $philhealth_number,
                                $tin_number,
                                $nbi_number,
                                $pagibig_number
                            ]);
                        } catch (Exception $e) {
                            // Fallback: minimal insert then try to update columns
                            try {
                                $ins2 = $pdo->prepare('INSERT INTO employees (id_code, name, last_status, photo) VALUES (?, ?, ?, ?)');
                                $ins2->execute([$id_code, $name, 'out', $photoFilename]);
                                $lastId = $pdo->lastInsertId();
                                if ($lastId) {
                                    // try to set other columns via UPDATE (best-effort)
                                    $updates = [];
                                    $params = [];
                                    if ($position !== '') { $updates[] = 'position = ?'; $params[] = $position; }
                                    if ($employment_type !== '') { $updates[] = 'employment_type = ?'; $params[] = $employment_type; }
                                    if ($shift !== '') { $updates[] = 'shift = ?'; $params[] = $shift; }
                                    if ($department !== '') { $updates[] = 'department = ?'; $params[] = $department; }
                                    if ($attendance_status !== '') { $updates[] = 'attendance_status = ?'; $params[] = $attendance_status; }
                                    if ($date_hired !== '') { $updates[] = 'date_hired = ?'; $params[] = $date_hired; }
                                    if ($sss_number !== '') { $updates[] = 'sss_number = ?'; $params[] = $sss_number; }
                                    if ($philhealth_number !== '') { $updates[] = 'philhealth_number = ?'; $params[] = $philhealth_number; }
                                    if ($tin_number !== '') { $updates[] = 'tin_number = ?'; $params[] = $tin_number; }
                                    if ($nbi_number !== '') { $updates[] = 'nbi_number = ?'; $params[] = $nbi_number; }
                                    if ($pagibig_number !== '') { $updates[] = 'pagibig_number = ?'; $params[] = $pagibig_number; }
                                    if (!empty($updates)) {
                                        $params[] = $lastId;
                                        $pdo->prepare('UPDATE employees SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
                                    }
                                }
                            } catch (Exception $ex) {
                                // ignore
                            }
                        }

                        $feedback = "Employee added: " . htmlspecialchars($name) . " (" . htmlspecialchars($id_code) . ")";
                        
                        // ✅ REDIRECT TO EMPLOYEES SECTION
                        if (!$targetSection) $targetSection = 'employees';
                    }
                }
            } catch (Exception $e) {
                $feedback = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Handle delete employee (unchanged)
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    try {
        // Get photo filename before deleting
        $s = $pdo->prepare('SELECT photo FROM employees WHERE id = ? LIMIT 1');
        $s->execute([$delId]);
        $row = $s->fetch();
        if ($row && !empty($row['photo'])) {
            $file = __DIR__ . '/uploads/' . $row['photo'];
            if (file_exists($file)) @unlink($file);
        }

        $del = $pdo->prepare('DELETE FROM employees WHERE id = ?');
        $del->execute([$delId]);
        $feedback = "Employee deleted (ID: $delId). Attendance rows removed.";
        
        // ✅ REDIRECT TO EMPLOYEES SECTION (for GET requests)
        header("Location: admin.php#employees");
        exit;
    } catch (Exception $e) {
        $feedback = "Delete error: " . $e->getMessage();
    }
}
// ============ SECTION PERSISTENCE: REDIRECT ============
// If we have a target section and this is a POST request, redirect with hash
if ($targetSection && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Redirect preserves the section hash
    header("Location: admin.php#$targetSection");
    exit;
}

// --------------------
// Summaries and data fetch (existing code)
// --------------------
try {
    $totalsStmt = $pdo->query("SELECT COUNT(*) AS total, SUM(last_status='in') AS in_count, SUM(last_status='out') AS out_count FROM employees");
    $totals = $totalsStmt->fetch();
    $totalEmployees = (int)($totals['total'] ?? 0);
    $inCount = (int)($totals['in_count'] ?? 0);
    $outCount = (int)($totals['out_count'] ?? 0);
} catch (Exception $e) {
    $totalEmployees = $inCount = $outCount = 0;
    $feedback = "Could not fetch summary: " . $e->getMessage();
}

// Fetch departments from departments table if present, else fallback
try {
    $departments = [];
    $stmt = $pdo->query("SELECT name FROM departments ORDER BY name ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rows)) {
        $departments = $rows;
    } else {
        $departments = $defaultDepartments;
    }
} catch (Exception $e) {
    // fallback
    $departments = $defaultDepartments;
}

// ---------- Robust fetch of employees + attendance (avoid Unknown column errors) ----------
try {
    // Helper: check which of the provided columns exist on a table
    $checkCols = function($table, array $cols) use ($pdo) {
        if (empty($cols)) return [];
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ($placeholders)";
        $params = array_merge([$table], $cols);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $empCols = $checkCols('employees', ['department', 'branch', 'division']);
    $attCols = $checkCols('attendance', ['department', 'branch', 'division', 'ip_address']);

    // Build COALESCE expression for employees.department alias using only existing columns
    $empCoalesceParts = [];
    foreach (['department', 'branch', 'division'] as $c) {
        if (in_array($c, $empCols, true)) $empCoalesceParts[] = $c;
    }
    if (!empty($empCoalesceParts)) {
        $empDeptExpr = 'COALESCE(' . implode(', ', $empCoalesceParts) . ') AS department';
    } else {
        $empDeptExpr = "'' AS department";
    }

    // Fetch employees using defensive expression
// First, check if position column exists
$positionCol = '';
try {
    $pdo->query("SELECT position FROM employees LIMIT 1");
    $positionCol = ', position';
} catch (Exception $e) {
    // position column doesn't exist yet
    $positionCol = '';
}

$sql = "SELECT id, id_code, name, {$empDeptExpr}, last_status, last_timestamp, photo{$positionCol} FROM employees ORDER BY id DESC";
$list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $list = [];
    $feedback = "Could not load employees: " . $e->getMessage();
}

// Admin list and settings data
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        fullname VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ensureAdminColumn('position', 'VARCHAR(100) NULL');
    $ensureAdminColumn('password_plain', 'VARCHAR(255) NULL');
    $ensureAdminColumn('photo', 'VARCHAR(255) NULL');

    $adminStmt = $pdo->query('SELECT id, username, fullname, position, photo, password_plain, created_at FROM admins ORDER BY id ASC');
    $adminList = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $adminList = [];
    $feedback = $feedback ?: "Could not load admin settings: " . $e->getMessage();
}

// Today's attendance summary (as before)...
$today = date('Y-m-d');
$shiftStart = '08:30:00';
$graceMinutes = 15;
$minWorkSeconds = 4 * 3600;

$attendanceIn = [];   // structure: [ employee_id => ['first_in' => '...', 'ip' => '...', 'department' => '...'] ]
$attendanceOut = [];  // structure: [ employee_id => ['last_out' => '...', 'ip' => '...', 'department' => '...'] ]
$leavesByEmployee = [];

try {
    // Determine available attendance columns again (defensive)
    $attCols = $checkCols('attendance', ['department', 'branch', 'division', 'ip_address']);

    // Build SELECT parts depending on available columns
    $attCoalesceParts = [];
    foreach (['department', 'branch', 'division'] as $c) {
        if (in_array($c, $attCols, true)) $attCoalesceParts[] = "a.{$c}";
    }
    if (!empty($attCoalesceParts)) {
        $attDeptExpr = 'COALESCE(' . implode(', ', $attCoalesceParts) . ') AS department';
    } else {
        $attDeptExpr = 'NULL AS department';
    }

    $ipSelect = in_array('ip_address', $attCols, true) ? 'a.ip_address' : 'NULL AS ip_address';

    // first_in
    $stmtInSql = "
        SELECT b.employee_id, b.first_in, {$ipSelect}, {$attDeptExpr}
        FROM (
            SELECT employee_id, MIN(created_at) AS first_in
            FROM attendance
            WHERE event_type = 'in' AND DATE(created_at) = ?
            GROUP BY employee_id
        ) b
        LEFT JOIN attendance a ON a.employee_id = b.employee_id AND a.event_type = 'in' AND a.created_at = b.first_in
    ";
    $stmtIn = $pdo->prepare($stmtInSql);
    $stmtIn->execute([$today]);
    while ($r = $stmtIn->fetch(PDO::FETCH_ASSOC)) {
        $attendanceIn[(int)$r['employee_id']] = ['first_in' => $r['first_in'], 'ip' => $r['ip_address'] ?? null, 'department' => $r['department'] ?? null];
    }

    // last_out
    $stmtOutSql = "
        SELECT b.employee_id, b.last_out, {$ipSelect}, {$attDeptExpr}
        FROM (
            SELECT employee_id, MAX(created_at) AS last_out
            FROM attendance
            WHERE event_type = 'out' AND DATE(created_at) = ?
            GROUP BY employee_id
        ) b
        LEFT JOIN attendance a ON a.employee_id = b.employee_id AND a.event_type = 'out' AND a.created_at = b.last_out
    ";
    $stmtOut = $pdo->prepare($stmtOutSql);
    $stmtOut->execute([$today]);
    while ($r = $stmtOut->fetch(PDO::FETCH_ASSOC)) {
        $attendanceOut[(int)$r['employee_id']] = ['last_out' => $r['last_out'], 'ip' => $r['ip_address'] ?? null, 'department' => $r['department'] ?? null];
    }

    // leaves
    $stmtLeaves = $pdo->prepare("SELECT employee_id, note FROM leaves WHERE leave_date = ?");
    $stmtLeaves->execute([$today]);
    while ($r = $stmtLeaves->fetch(PDO::FETCH_ASSOC)) $leavesByEmployee[(int)$r['employee_id']] = $r['note'] ?? '';
} catch (Exception $e) {
    // non-fatal
}

$absentCount = $halfDayCount = $lateCount = $leaveCount = 0;
$todayStatusByEmployee = [];

foreach ($list as $empRow) {
    $empId = (int)$empRow['id'];
    $firstIn = $attendanceIn[$empId]['first_in'] ?? null;
    $firstInIp = $attendanceIn[$empId]['ip'] ?? null;
    $lastOut = $attendanceOut[$empId]['last_out'] ?? null;
    $lastOutIp = $attendanceOut[$empId]['ip'] ?? null;
    $isOnLeave = array_key_exists($empId, $leavesByEmployee);

    $statusLabel = 'Present';
    $badgeClass = 'neutral';

    if ($isOnLeave) {
        $statusLabel = 'On Leave';
        $badgeClass = 'leave';
        $leaveCount++;
    } else if (!$firstIn && !$lastOut) {
        $statusLabel = 'Absent';
        $badgeClass = 'absent';
        $absentCount++;
    } else {
        if (!$firstIn || !$lastOut) {
            $statusLabel = 'Half-day';
            $badgeClass = 'half';
            $halfDayCount++;
        } else {
            $firstInTs = strtotime($firstIn);
            $lastOutTs = strtotime($lastOut);
            $worked = max(0, $lastOutTs - $firstInTs);
            $shiftDT = strtotime($today . ' ' . $shiftStart);
            $lateThreshold = $shiftDT + ($graceMinutes * 60);
            if ($firstInTs > $lateThreshold) {
                $statusLabel = 'Late';
                $badgeClass = 'late';
                $lateCount++;
            } else {
                $statusLabel = 'On time';
                $badgeClass = 'on';
            }

            if ($worked < $minWorkSeconds) {
                if (isset($firstInTs) && $firstInTs > $lateThreshold) {
                    if ($lateCount > 0) $lateCount--;
                }
                $statusLabel = 'Half-day';
                $badgeClass = 'half';
                $halfDayCount++;
            }
        }
    }

    $todayStatusByEmployee[$empId] = [
        'label' => $statusLabel,
        'class' => $badgeClass,
        'first_in' => $firstIn,
        'first_in_ip' => $firstInIp,
        'last_out' => $lastOut,
        'last_out_ip' => $lastOutIp,
        'leave_note' => $leavesByEmployee[$empId] ?? null,
    ];
}

// ... rest of AJAX handlers and HTML remain the same ...

// --------------------
// HEREEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE

/* ---------------------------
   Server: AJAX endpoints
   --------------------------- */
/* --------------------
   Consolidated / fixed AJAX handlers
   Replace the existing AJAX-handling section with the code below.
   This block expects $pdo and $checkCols (defined earlier) to be present.
-------------------- */
/* ---------------------------
   Server: AJAX endpoints (consolidated and fixed)
   --------------------------- */
/* ---------------------------
   Server: AJAX endpoints (consolidated, defensive)
   Replace the old AJAX blocks with this single handler
--------------------------- */
/* ---------------------------
   Server: AJAX endpoints (consolidated, defensive)
   Replace the old AJAX blocks with this single handler
--------------------------- */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    // simple date validator
    $validateDate = function($d) {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    };

    // helper to resolve uploads -> 'uploads/filename' if file present
    $resolveUpload = function($val) {
        if (empty($val)) return null;
        $name = basename($val);
        $file = __DIR__ . '/uploads/' . $name;
        if (file_exists($file)) return 'uploads/' . rawurlencode($name);
        return null;
    };

    try {
        // ----------------
        // departments
        // ----------------
        if ($ajax === 'get_departments') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $names = [];
                try {
                    $stmt = $pdo->query("SELECT name FROM departments WHERE active = 1 ORDER BY name ASC");
                    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($rows)) $names = $rows;
                } catch (Exception $e) {
                    $stmt = $pdo->query("SELECT name FROM departments ORDER BY name ASC");
                    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                if (empty($names)) $names = ['CamFinLending','CashManagement','MAGtech'];
                echo json_encode(['departments' => array_values($names)]);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Could not load departments']);
            }
            exit;
        }

        // ----------------
        // attendance_compact (JSON) and download_attendance (CSV)
        // ----------------
        if ($ajax === 'attendance_compact' || $ajax === 'download_attendance') {
            $date = $_GET['date'] ?? '';
            $department = $_GET['department'] ?? '';

            // validate inputs
            if (!$validateDate($date) || trim($department) === '') {
                if ($ajax === 'attendance_compact') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'Invalid date or department']);
                } else {
                    http_response_code(400);
                    echo 'Invalid date or department';
                }
                exit;
            }

            // Use $checkCols defined earlier in the file to detect columns
            // Fallback if $checkCols not defined (defensive)
            if (!isset($checkCols) || !is_callable($checkCols)) {
                $checkCols = function($table, array $cols) use ($pdo) {
                    if (empty($cols)) return [];
                    $placeholders = implode(',', array_fill(0, count($cols), '?'));
                    $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ($placeholders)";
                    $params = array_merge([$table], $cols);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetchAll(PDO::FETCH_COLUMN);
                };
            }

            // Which employee dept-like columns exist? Build COALESCE safely.
            $empCols = $checkCols('employees', ['department','branch','division']);
            $empDeptParts = [];
            foreach (['department','branch','division'] as $c) {
                if (in_array($c, $empCols, true)) $empDeptParts[] = "e.{$c}";
            }
            if (!empty($empDeptParts)) {
                $empDeptSelect = 'COALESCE(' . implode(', ', $empDeptParts) . ") AS department";
                $whereDept = 'COALESCE(' . implode(', ', $empDeptParts) . ') = ?';
                $deptParamNeeded = true;
            } else {
                $empDeptSelect = "'' AS department";
                $whereDept = '1=1';
                $deptParamNeeded = false;
            }

            // Does attendance.photo exist?
            $attCols = $checkCols('attendance', ['photo']);
            $hasAttPhoto = in_array('photo', $attCols, true);

            // Optional joins/selects for attendance photos
            $inPhotoSelect = $hasAttPhoto ? 'ai.photo AS in_photo' : "NULL AS in_photo";
            $outPhotoSelect = $hasAttPhoto ? 'ao.photo AS out_photo' : "NULL AS out_photo";
            $inJoin = $hasAttPhoto ? "LEFT JOIN attendance ai ON ai.employee_id = fin.employee_id AND ai.event_type = 'in' AND ai.created_at = fin.first_in" : "";
            $outJoin = $hasAttPhoto ? "LEFT JOIN attendance ao ON ao.employee_id = fout.employee_id AND ao.event_type = 'out' AND ao.created_at = fout.last_out" : "";

            // Build safe SQL: only references columns we checked above
           $sql = "
    SELECT e.id AS employee_id,
           e.id_code,
           e.name,
           {$empDeptSelect},
           e.photo AS emp_photo,
           DATE_FORMAT(fin.first_in, '%Y-%m-%dT%H:%i:%s+08:00') AS first_in,
           {$inPhotoSelect},
           DATE_FORMAT(fout.last_out, '%Y-%m-%dT%H:%i:%s+08:00') AS last_out,
           {$outPhotoSelect}
    FROM employees e
    LEFT JOIN (
        SELECT employee_id, MIN(created_at) AS first_in
        FROM attendance
        WHERE event_type = 'in' AND DATE(created_at) = ?
        GROUP BY employee_id
    ) fin ON fin.employee_id = e.id
    {$inJoin}
    LEFT JOIN (
        SELECT employee_id, MAX(created_at) AS last_out
        FROM attendance
        WHERE event_type = 'out' AND DATE(created_at) = ?
        GROUP BY employee_id
    ) fout ON fout.employee_id = e.id
    {$outJoin}
    WHERE {$whereDept}
    ORDER BY e.name ASC
";

            $params = [$date, $date];
            if ($deptParamNeeded) $params[] = $department;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // collect results
            $rows = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // resolve employee photo
                $empPhoto = 'uploads/default.png';
                if (!empty($r['emp_photo'])) {
                    $maybe = $resolveUpload($r['emp_photo']);
                    if ($maybe) $empPhoto = $maybe;
                }

                // resolve attendance photos (may be NULL if table doesn't have column)
                $inPhoto = $resolveUpload($r['in_photo'] ?? null);
                $outPhoto = $resolveUpload($r['out_photo'] ?? null);

                // fallback to employee photo when attendance photos not available
                if (!$inPhoto) $inPhoto = $empPhoto;
                if (!$outPhoto) $outPhoto = $empPhoto;

                $rows[] = [
                    'employee_id' => (int)($r['employee_id'] ?? 0),
                    'id_code'     => $r['id_code'] ?? '',
                    'name'        => $r['name'] ?? '',
                    'department'  => $r['department'] ?? '',
                    'emp_photo'   => $empPhoto,
                    'first_in'    => $r['first_in'] ?: null,
                    'in_photo'    => $inPhoto,
                    'last_out'    => $r['last_out'] ?: null,
                    'out_photo'   => $outPhoto,
                ];
            }

            // respond JSON or CSV
            if ($ajax === 'attendance_compact') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['date' => $date, 'department' => $department, 'records' => $rows]);
                exit;
            }

            // CSV download
            if ($ajax === 'download_attendance') {
                $filename = 'attendance_' . str_replace('/', '-', $department) . '_' . $date . '.csv';
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');

                $out = fopen('php://output', 'w');
                fputcsv($out, ['Employee ID', 'Full name', 'Department', 'Status', 'Time In', 'Time In Photo', 'Time Out', 'Time Out Photo']);

                // local status helper
                $compute_status_server = function($firstIn, $lastOut, $shiftStart = '08:30:00', $graceMinutes = 5, $minWorkSeconds = 21600) {
                    $parse = function($dt) {
                        if (!$dt) return null;
                        $m = preg_split('/[- :T]/', $dt);
                        if (count($m) < 6) return strtotime($dt);
                        return gmmktime((int)$m[3], (int)$m[4], (int)$m[5], (int)$m[1], (int)$m[2], (int)$m[0]);
                    };
                    $firstTs = $parse($firstIn);
                    $lastTs = $parse($lastOut);
                    $date = $firstIn ? substr($firstIn,0,10) : ($lastOut ? substr($lastOut,0,10) : gmdate('Y-m-d'));
                    list($h,$m,$s) = explode(':', $shiftStart);
                    $shiftTs = gmmktime((int)$h,(int)$m,(int)$s, (int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4));
                    $lateThreshold = $shiftTs + ($graceMinutes * 60);
                    $absentThreshold = gmmktime(13, 0, 0, (int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4));

                    if (!$firstTs && !$lastTs) return 'Absent';
                    if ($firstTs && $firstTs >= $absentThreshold) return 'Absent';
                    if (!$firstTs || !$lastTs) {
                        return 'Half-day';
                    }
                    $worked = max(0, $lastTs - $firstTs);
                    if ($firstTs > $lateThreshold) {
                        if ($worked < $minWorkSeconds) return 'Half-day | Late';
                        return 'Late';
                    } else {
                        if ($worked < $minWorkSeconds) return 'Half-day';
                        return 'Regular';
                    }
                };

                foreach ($rows as $r) {
                    $status = $compute_status_server($r['first_in'], $r['last_out']);
                    fputcsv($out, [
                        $r['id_code'],
                        $r['name'],
                        $r['department'],
                        $status,
                        $r['first_in'] ?: '',
                        $r['in_photo'] ?: '',
                        $r['last_out'] ?: '',
                        $r['out_photo'] ?: ''
                    ]);
                }
                fclose($out);
                exit;
            }
        } // end attendance handlers

        // unknown ajax
        http_response_code(400);
        echo 'Unknown action';
        exit;

    } catch (Exception $e) {
        // General failure response
        if ($ajax === 'attendance_compact') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        } else {
            http_response_code(500);
            echo 'Server error';
        }
        exit;
    }
} // end if isset ajax
// ---------- end consolidated handlers ----------

/* ---------------------------
   Client: Compact Attendance UI
   --------------------------- */



?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>TimeClock — Admin Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    /* Minimal professional MVP dashboard styling (keeps external admin.css for baseline) */
    :root{
      --bg:#f6f8fb;
      --card:#ffffff;
      --muted:#6b7280;
      --accent:#2563eb;
      --danger:#ef4444;
      --success:#10b981;
      --surface-2:#eef2f7;
      --radius:10px;
      --shadow:0 6px 18px rgba(16,24,40,0.06);
      --max-width:1200px;
      --sidebar-width:260px;
      --gap:18px;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    html,body{height:100%}
    body{margin:0;background:var(--bg);color:#111827;-webkit-font-smoothing:antialiased}
    .app {
      display: grid;
      grid-template-columns: var(--sidebar-width) 1fr;
      min-height:100vh;
      gap:0;
      max-width: 1400px;
      margin: 18px auto;
      width: calc(100% - 36px);
    }

    /* Sidebar */
    .sidebar {
      background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
      color: #fff;
      padding: 22px;
      border-radius: 12px;
      box-shadow: var(--shadow);
      position: sticky;
      top: 18px;
      height: calc(100vh - 36px);
      display:flex;
      flex-direction:column;
      gap:16px;
    }
    .brand {
      display:flex;
      align-items:center;
      gap:12px;
    }
    .brand .logo {
      width:44px;height:44px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#0ea5e9);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:18px;
    }
    .brand h1{font-size:16px;margin:0}
    .brand p{margin:0;font-size:12px;color:rgba(255,255,255,0.75)}

    .nav {margin-top:8px;display:flex;flex-direction:column;gap:6px}
    .nav a{display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;color:rgba(255,255,255,0.9);text-decoration:none;font-size:14px}
    .nav a .count{margin-left:auto;background:rgba(255,255,255,0.06);padding:4px 8px;border-radius:999px;font-size:12px}

    /* Active/highlight */
    .nav a.active{background:rgba(255,255,255,0.08);box-shadow:inset 3px 0 0 var(--accent);font-weight:600}
    button.metric-card { display:block; text-align:left; border:none; background:inherit; width:100%; cursor:pointer; padding:14px; font:inherit; }
    button.metric-card:hover, button.metric-card:focus { outline:none; box-shadow:0 10px 25px rgba(37,99,235,0.08); }
    .badge-status { display:inline-flex; align-items:center; justify-content:center; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; }
    .badge-status.on { background: rgba(16,185,129,0.12); color:#059669; }
    .badge-status.late { background: rgba(239,68,68,0.12); color:#dc2626; }
    .badge-status.half { background: rgba(249,115,22,0.12); color:#d97706; }
    .badge-status.absent { background: rgba(239,68,68,0.12); color:#dc2626; }
    .badge-status.leave { background: rgba(99,102,241,0.12); color:#4f46e5; }
    .badge-status.neutral { background: rgba(148,163,184,0.12); color:#475569; }
    .employee-table tbody tr[data-employee-id] { cursor:pointer; }
    .nav a:hover{background:rgba(255,255,255,0.06)}

    .sidebar .section-title{font-size:12px;color:rgba(255,255,255,0.65);margin-top:10px}

    .sidebar .footer{margin-top:auto;font-size:12px;color:rgba(255,255,255,0.6)}

    /* Main content */
    .main {
      padding: 22px;
      display:flex;
      flex-direction:column;
      gap:var(--gap);
    }

    .topbar {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:6px;
    }
    .topbar .title{
      font-size:20px;font-weight:700;
    }
    .top-actions{display:flex;gap:8px;align-items:center}
    .searchbar{display:flex;gap:8px;align-items:center;background:var(--card);padding:8px;border-radius:10px;box-shadow:var(--shadow)}
    .searchbar input{border:0;outline:0;background:transparent;width:220px;padding:6px 8px}

    /* Grid of cards + sections */
    .grid {
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap:18px;
      align-items:start;
    }

    .card {
      background:var(--card);
      padding:16px;
      border-radius:var(--radius);
      box-shadow:var(--shadow);
    }
    .kpi {
      grid-column: span 3;
      display:flex;
      flex-direction:column;
      gap:8px;
    }
    .kpi .label{font-size:13px;color:var(--muted)}
    .kpi .value{font-size:28px;font-weight:700}

    .wide {
      grid-column: span 6;
    }
    .full {
      grid-column: 1 / -1;
    }

    .small {font-size:13px;color:var(--muted)}
    .muted{color:var(--muted)}

    /* Add employee form layout & responsiveness */
    .add-form { width:100%; }
    .add-form-grid {
      display:grid;
      grid-template-columns: repeat(12, 1fr);
      gap:12px;
      align-items:start;
      width:100%;
      box-sizing:border-box;
    }
    .group-identity { grid-column: span 4; min-width:0; }
    .group-work { grid-column: span 4; min-width:0; }
    .group-photo { grid-column: span 4; min-width:0; display:flex;flex-direction:column;gap:8px;align-items:flex-start; }

    .field { display:flex;flex-direction:column; gap:6px; }
    label.field-label { font-size:13px;color:var(--muted); }
    input[type="text"], input[type="number"], input[type="date"], select, input[type="file"]{
      padding:10px;border-radius:8px;border:1px solid var(--surface-2);background:transparent;font-size:14px;width:100%;box-sizing:border-box;
    }
    .photo-preview { width:100%;height:120px;border-radius:8px;background:#f8fafc;border:1px dashed #e6eef8;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;overflow:hidden;object-fit:cover; }

    .form-actions { display:flex;gap:10px;align-items:center;margin-top:6px; }
    .btn.primary{background:var(--accent);color:#fff;padding:10px 14px;border-radius:8px;font-size:14px;}
    .btn.ghost{background:transparent;border:1px solid var(--surface-2);color:var(--muted);padding:8px 12px;border-radius:8px;font-size:14px;}

    .validation-error { color: var(--danger); font-size:13px; margin-top:6px; display:none; }


    .data-table { width:100%; border-collapse:collapse; font-size:14px; margin-top:12px; }
    .data-table th, .data-table td { padding:12px 14px; text-align:left; border-bottom:1px solid #eff2f7; }
    .data-table thead th { background:rgba(248,250,252,0.9); font-weight:700; color:var(--muted); font-size:13px; }
    .data-table tbody tr:hover { background:rgba(37,99,235,0.05); }
    .data-table tfoot th { padding-top:14px; border-top:1px solid #dbeafe; }
    .data-table tfoot th[colspan] { text-align:left; }

    /* On smaller screens stack vertically */
    @media (max-width: 980px){
      .app{grid-template-columns: 1fr; padding: 12px}
      .sidebar{position:relative;height:auto;order:2}
      .main{order:1}
      .group-identity, .group-work, .group-photo { grid-column: 1 / -1; }
      .photo-preview { height:160px; width:100%; }
    }

    table.data-table{width:100%;border-collapse:collapse}
    table.data-table thead th{font-size:13px;text-align:left;padding:10px;background:transparent;color:var(--muted)}
    table.data-table tbody td{padding:10px;border-top:1px solid #f1f5f9;vertical-align:middle}
    .thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid #eef2f7}

    .badge{display:inline-block;padding:6px 8px;border-radius:999px;font-size:12px}
    .badge.on{background:rgba(16,185,129,0.12);color:var(--success)}
    .badge.neutral{background:rgba(15,23,42,0.04);color:var(--muted)}
    .badge.absent{background:rgba(239,68,68,0.08);color:var(--danger)}
    .badge.half{background:rgba(249,115,22,0.08);color:#f97316}
    .badge.leave{background:rgba(99,102,241,0.08);color:#6366f1}
    .badge.late{background:rgba(245,158,11,0.08);color:#f59e0b}

    .row-actions{display:flex;gap:8px;align-items:center}
    .row-actions a, .row-actions button{font-size:13px}

    /* Section visibility helper */
    .section { display: none; }
    .section.active { display: block; }
  </style>
</head>
<body>
  <div class="app">
    <!-- Sidebar -->
    <aside class="sidebar" aria-label="Admin navigation">
      <div class="brand" role="heading" aria-level="1">
        <div class="logo">TC</div>
        <div>
          <h1>TimeClock</h1>
          <p>Admin Dashboard</p>
        </div>
      </div>

      <nav class="nav" aria-label="Primary">
        <a href="#dashboard" data-section="dashboard" class="active" aria-current="page">Dashboard <span class="count"><?php echo $totalEmployees; ?></span></a>
        <a href="#employees" data-section="employees">Employees <span class="count"><?php echo $totalEmployees; ?></span></a>
        <a href="#attendance" >Attendance</a>
        <a href="#departments" data-section="departments">Departments <span class="count"><?php echo count($departments); ?></span></a>
        <a href="#reports" data-section="reports">Reports</a>
        <a href="#settings" data-section="settings">Settings</a>
        <a href="#rfid" data-section="rfid">RFID Writer</a>
      </nav>

      <div class="section-title">Quick actions</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a class="btn primary" href="#employees" style="text-align:center;text-decoration:none;color:#fff">Add / View employees</a>
        <a class="btn ghost" href="employee_calendar.php" style="text-align:center;text-decoration:none;color:var(--muted)">View calendar</a>
      </div>

      <div class="footer">
        Signed in as <strong><?php echo htmlspecialchars($_SESSION['admin_user'] ?? 'admin'); ?></strong>
      </div>
    </aside>

    <!-- Main area -->
    <main class="main" role="main">
      <header class="topbar">
        <div>
          <div class="title">Admin</div>
          <div class="small muted">Manage employees, photos, departments, and attendance — MVP dashboard</div>
        </div>

        <div class="top-actions">
          <div class="searchbar" role="search" aria-label="Global search">
            
          </div>
          <a class="btn ghost" href="logout.php">Sign out</a>
        </div>
      </header>

     <?php if ($feedback): ?>
  <div class="card" role="status" aria-live="polite">
    <strong>Status:</strong> <?php echo htmlspecialchars($feedback); ?>
  </div>
<?php endif; ?>

      <!-- Sections: only one visible at a time via .section.active -->
      <!-- Dashboard section -->
      <section id="section-dashboard" class="section active" aria-labelledby="dashboard-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="dashboard-h" style="margin:0">Dashboard</h2>
          <div class="small muted">Overview & key metrics</div>
        </div>

        <section class="grid" aria-label="Key metrics">
          <div class="card kpi">
            <div class="label">Total employees</div>
            <div class="value"><?php echo $totalEmployees; ?></div>
            <div class="small muted">All registered staff</div>
          </div>

          <button type="button" class="card kpi metric-card" data-target-section="employees" data-status-filter="in">
            <div class="label">Currently IN</div>
            <div class="value" style="color:var(--success)"><?php echo $inCount; ?></div>
            <div class="small muted">Checked in now</div>
          </button>

          <button type="button" class="card kpi metric-card" data-target-section="employees" data-status-filter="out">
            <div class="label">Currently OUT</div>
            <div class="value"><?php echo $outCount; ?></div>
            <div class="small muted">Checked out now</div>
          </button>

          <button type="button" class="card kpi metric-card" data-target-section="employees" data-status-filter="absent">
            <div class="label">Absent (today)</div>
            <div class="value" style="color:var(--danger)"><?php echo $absentCount; ?></div>
            <div class="small muted">Not recorded today</div>
          </button>

          <button type="button" class="card kpi metric-card" data-target-section="employees" data-status-filter="half-day">
            <div class="label">Half-day (today)</div>
            <div class="value"><?php echo $halfDayCount; ?></div>
            <div class="small muted">Incomplete day</div>
          </button>

          <button type="button" class="card kpi metric-card" data-target-section="employees" data-status-filter="late">
            <div class="label">Late (today)</div>
            <div class="value"><?php echo $lateCount; ?></div>
            <div class="small muted">Arrived after grace</div>
          </button>

          <div class="card full" style="margin-top:8px">
            <h3 style="margin-top:0">Notes</h3>
            <p class="small muted">Tap a card to jump to employees filtered by today&apos;s status. Then tap any employee row to view their profile and attendance history.</p>
          </div>
        </section>
      </section>

      <!-- Employees section with improved Add Employee form -->
      <section id="section-employees" class="section" aria-labelledby="employees-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="employees-h" style="margin:0">Employees</h2>
          
        </div>

        <div class="grid" style="align-items:start">
          <!-- Add employee card (refactored, compact & responsive) -->
         <!-- Add employee card (FIXED) -->
<div class="card full" id="add-employee" aria-labelledby="add-employee-h" style="overflow:hidden">
  <h3 id="add-employee-h" style="margin-top:0">Add employee</h3>

  <form method="post" action="admin.php" enctype="multipart/form-data" class="add-form" novalidate id="empAddForm">
    <input type="hidden" name="action" value="add_employee">
    <input type="hidden" name="section" value="employees">
    
    <div class="add-form-grid" role="group" aria-labelledby="add-employee-h">
      <!-- Identity group -->
      <div class="group-identity">
        <div class="field">
          <label class="field-label" for="id_code">Employee ID Code</label>
          <input id="id_code" name="id_code" type="text" placeholder="Unique ID (e.g. 3001)" required maxlength="64" pattern="[A-Za-z0-9\-_]{2,64}" title="Letters, numbers, - or _ (2-64 chars)">
        </div>

        <div class="field" style="display:flex;gap:8px">
          <div class="field" style="flex:1">
            <label class="field-label" for="last_name">Last Name</label>
            <input id="last_name" name="last_name" type="text" placeholder="Last name" required maxlength="255">
          </div>
          <div class="field" style="flex:1">
            <label class="field-label" for="first_name">First Name</label>
            <input id="first_name" name="first_name" type="text" placeholder="First name" required maxlength="255">
          </div>
          <div class="field" style="flex:0 0 120px">
            <label class="field-label" for="middle_initial">Middle Initial</label>
            <input id="middle_initial" name="middle_initial" type="text" placeholder="M" maxlength="1">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="date_hired">Date Hired</label>
          <input id="date_hired" name="date_hired" type="date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
      </div>

      <!-- Work info group -->
      <div class="group-work">
        <div class="field">
          <label class="field-label" for="department">Department</label>
          <select id="department" name="department" required>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="position">Position / Role</label>
          <input id="position" name="position" type="text" placeholder="e.g. Sales Associate, Engineer" required maxlength="150">
        </div>

        <div class="field" style="display:flex;gap:8px">
          <div style="flex:1">
            <label class="field-label" for="employment_type">Employment Type</label>
            <select id="employment_type" name="employment_type" required>
              <option value="Full-time">Full-time</option>
              <option value="Part-time">Part-time</option>
              <option value="Contract">Contract</option>
            </select>
          </div>

          <div style="flex:1">
            <label class="field-label" for="shift">Work Schedule / Shift</label>
            <input id="shift" name="shift" type="text" placeholder="e.g. 08:30-17:30" required maxlength="100">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="attendance_status">Attendance Status</label>
          <select id="attendance_status" name="attendance_status" required>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Suspended">Suspended</option>
          </select>
        </div>
      </div>

      <!-- Compliance & Photo group -->
      <div class="group-photo">
        <div class="field">
          <label class="field-label" for="sss_number">SSS Number</label>
          <input id="sss_number" name="sss_number" type="text" maxlength="64" placeholder="e.g. 01-2345678-9">
        </div>

        <div class="field">
          <label class="field-label" for="philhealth_number">PhilHealth Number</label>
          <input id="philhealth_number" name="philhealth_number" type="text" maxlength="64" placeholder="e.g. 12-345678901-2">
        </div>

        <div class="field">
          <label class="field-label" for="tin_number">TIN / Tax ID</label>
          <input id="tin_number" name="tin_number" type="text" maxlength="64" placeholder="e.g. 123-456-789">
        </div>

        <div class="field">
          <label class="field-label" for="nbi_number">NBI Clearance No.</label>
          <input id="nbi_number" name="nbi_number" type="text" maxlength="64" placeholder="NBI clearance number">
        </div>

        <div class="field">
          <label class="field-label" for="pagibig_number">Pag-IBIG Number</label>
          <input id="pagibig_number" name="pagibig_number" type="text" maxlength="64" placeholder="Pag-IBIG ID number">
        </div>

        <div class="field" style="width:100%">
          <label class="field-label" for="photo">Profile Photo *</label>
          <div class="photo-preview" id="photoPreview">No image selected</div>
          <input id="photo" name="photo" type="file" accept="image/*" required>
        </div>

        <div class="form-actions" style="width:100%">
          <button type="submit" class="btn primary" id="addEmployeeBtn">Add Employee</button>
          <button type="button" class="btn ghost" id="resetEmpForm">Reset</button>
        </div>
        <span class="validation-error" id="addFormFeedback" role="alert"></span>
      </div>
    </div>
  </form>
</div>

          <!-- Employee table (unchanged) -->
          <div class="card full" style="padding-bottom:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">
              <div class="small muted">Employee records</div>
              <div style="display:flex;gap:8px;align-items:center">
            <input id="searchInputEmployees" type="search" placeholder="Search by name or ID code" style="padding:8px;border-radius:8px;border:1px solid var(--surface-2)">
            <select id="filterDepartmentEmployees" aria-label="Filter by department" style="padding:8px;border-radius:8px;border:1px solid var(--surface-2)">
              <option value="">All departments</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
              <?php endforeach; ?>
            </select>
            <select id="filterStatusEmployees" aria-label="Filter by status" style="padding:8px;border-radius:8px;border:1px solid var(--surface-2)">
              <option value="">All statuses</option>
              <option value="in">Currently IN</option>
              <option value="out">Currently OUT</option>
              <option value="on time">On time</option>
              <option value="late">Late</option>
              <option value="half-day">Half-day</option>
              <option value="absent">Absent</option>
              <option value="on leave">On Leave</option>
            </select>
          </div>
              <div style="display:flex;gap:8px">
                <a class="btn ghost" href="export.php">Export CSV</a>
              </div>
            </div>

            <style>
  /* Scoped styles for the employee records table */
  .employee-table-wrap { overflow: hidden; width: 100%; }
  table.employee-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.employee-table thead th {
    text-align: left;
    padding: 10px 12px;
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
    vertical-align: middle;
  }
  table.employee-table tbody td {
    padding: 10px 12px;
    vertical-align: middle;
    font-size: 14px;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Column widths (fixed layout to avoid horizontal scrolling) */
  table.employee-table col.col-photo { width: 72px; }
  table.employee-table col.col-id    { width: 110px; }
  table.employee-table col.col-name  { width: 1fr; } /* flexible */
  table.employee-table col.col-dept  { width: 160px; }
  table.employee-table col.col-pos   { width: 160px; }
  table.employee-table col.col-cal   { width: 64px; }
  table.employee-table col.col-act   { width: 120px; }

  /* Thumbnail */
  .emp-thumb {
    width: 52px;
    height: 52px;
    border-radius: 8px;
    object-fit: cover;
    display:block;
    border: 1px solid #eef2f7;
    background: #f8fafc;
  }

  /* Row spacing & consistent height */
  table.employee-table tbody tr { height: 72px; }
  table.employee-table tbody tr + tr { border-top: 1px solid #f1f5f9; }

  /* Zebra striping subtle */
  table.employee-table tbody tr:nth-child(odd) { background: rgba(15,23,42,0.02); }

  /* Compact action group */
  .action-group { display:flex; gap:8px; align-items:center; justify-content:flex-end; }
  .action-btn {
    background: transparent;
    border: 0;
    padding:6px;
    border-radius:6px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    color: var(--muted);
  }
  .action-btn:hover { background: rgba(15,23,42,0.04); color: #111827; }

  .action-btn.destructive { color: var(--danger); }
  .action-btn.primary { color: var(--accent); }

  /* Small icon sizes */
  .action-btn svg { width:16px; height:16px; display:block; }

  /* Make table responsive: allow the container to scroll vertically but avoid horizontal scroll */
  @media (max-width: 720px) {
    table.employee-table thead th { font-size:12px; padding:8px; }
    table.employee-table tbody td { padding:8px; font-size:13px; }
    .emp-thumb { width:44px; height:44px; }
    table.employee-table tbody tr { height:64px; }
  }
</style>

<div class="employee-table-wrap card" style="padding:0;">
  <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
    <div style="font-weight:600">Employee records</div>
    <div class="small muted">Tap actions to edit, mark leave or view calendar</div>
  </div>

  <div style="overflow:auto;">
    <table class="employee-table" role="table" aria-label="Employee records">
      <colgroup>
        <col class="col-photo">
        <col class="col-id">
        <col class="col-name">
        <col class="col-dept">
        <col class="col-pos">
        <col class="col-status">
        <col class="col-cal">
        <col class="col-act">
      </colgroup>

      <thead>
        <tr>
          <th scope="col">Photo</th>
          <th scope="col">Employee ID</th>
          <th scope="col">Full name</th>
          <th scope="col">Department</th>
          <th scope="col">Position</th>
          <th scope="col">Status</th>
          <th scope="col" aria-label="Calendar"></th>
          <th scope="col">Actions</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($list)): ?>
          <tr><td colspan="7" class="small muted" style="padding:18px">No employees found.</td></tr>
        <?php else: ?>
          <?php foreach ($list as $row):
            // Photo
            $photoPath = (!empty($row['photo']) && file_exists(__DIR__.'/uploads/'.$row['photo']))
              ? 'uploads/' . rawurlencode($row['photo'])
              : 'uploads/default.png';
            $empId = (int)$row['id'];
            $empIdCode = htmlspecialchars($row['id_code'] ?? '');
            $empName = htmlspecialchars($row['name'] ?? '');
            $dept = htmlspecialchars($row['department'] ?? '');
            // Position may not exist; fallback to empty string
            $position = htmlspecialchars($row['position'] ?? '');
            $hasLeave = !empty($todayStatusByEmployee[$empId]['leave_note']);
          ?>
            <tr id="emp-row-<?php echo $empId; ?>" data-employee-id="<?php echo $empId; ?>" data-employee-status="<?php echo htmlspecialchars(strtolower($todayStatusByEmployee[$empId]['label'] ?? 'unknown')); ?>" data-employee-last-status="<?php echo htmlspecialchars(strtolower($row['last_status'] ?? '')); ?>">
              <!-- Photo -->
              <td>
                <img alt="<?php echo $empName; ?> photo" src="<?php echo $photoPath; ?>" class="emp-thumb" loading="lazy">
              </td>

              <!-- Employee ID -->
              <td title="<?php echo $empIdCode; ?>"><?php echo $empIdCode; ?></td>

              <!-- Full name -->
              <td title="<?php echo $empName; ?>" style="font-weight:600"><?php echo $empName; ?></td>

              <!-- Department -->
              <td title="<?php echo $dept; ?>"><?php echo $dept ?: '—'; ?></td>

              <!-- Position -->
              <td title="<?php echo $position; ?>"><?php echo $position ?: '—'; ?></td>

              <!-- Status -->
              <td>
                <span class="badge-status <?php echo htmlspecialchars($todayStatusByEmployee[$empId]['class'] ?? 'neutral'); ?>"><?php echo htmlspecialchars($todayStatusByEmployee[$empId]['label'] ?? 'Unknown'); ?></span>
              </td>

              <!-- Calendar button -->
              <td style="text-align:center">
                <a href="employee_calendar.php?id=<?php echo $empId; ?>" class="action-btn" title="View attendance calendar" aria-label="View calendar for <?php echo $empName; ?>">
                  <!-- calendar icon -->
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 11h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/></svg>
                </a>
              </td>

              <!-- Actions: Edit | Mark Leave/Status | Delete -->
              <td>
                <div class="action-group" role="group" aria-label="Actions for <?php echo $empName; ?>">
                  <!-- Edit -->
                  <a href="edit_employee.php?id=<?php echo $empId; ?>" class="action-btn" title="Edit employee" aria-label="Edit <?php echo $empName; ?>">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 21l3-1 11-11 1-3-3 1-11 11-1 3z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>

                  <!-- Mark Leave / Toggle -->
                  <form method="post" action="mark_leave.php" style="display:inline;margin:0">
                    <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                    <button type="submit" class="action-btn<?php echo $hasLeave ? ' primary' : ''; ?>" title="<?php echo $hasLeave ? 'Unmark leave' : 'Mark leave'; ?>" aria-pressed="<?php echo $hasLeave ? 'true' : 'false'; ?>">
                      <?php if ($hasLeave): ?>
                        <!-- Leave icon (active) -->
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2v10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 12a8 8 0 11-16 0 8 8 0 0116 0z" stroke="currentColor" stroke-width="1.2"/></svg>
                      <?php else: ?>
                        <!-- Leave icon (inactive) -->
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2v10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.2"/></svg>
                      <?php endif; ?>
                    </button>
                  </form>

                  <!-- Delete (destructive) -->
                  <a href="admin.php?delete=<?php echo $empId; ?>" class="action-btn destructive" title="Delete employee" onclick="return confirm('Delete <?php echo addslashes($empName); ?> and all attendance records?');" aria-label="Delete <?php echo $empName; ?>">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 6h18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M8 6v13a2 2 0 002 2h4a2 2 0 002-2V6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

            <p class="small muted" style="margin-top:8px">Note: Deleting an employee will remove their attendance records (ON DELETE CASCADE) and delete the uploaded photo if any.</p>
          </div>
        </div>
      </section>

      <!-- Attendance section (placeholder / summary) -->
      <!-- Compact Attendance Section -->
<section id="section-attendance" class="section" aria-labelledby="attendance-h">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <h2 id="attendance-h" style="margin:0">Attendance</h2>
    <div class="small muted">Compact view — filter by date and department, then download.</div>
  </div>

  <div class="card" style="padding:12px;display:grid;grid-template-columns:260px 1fr;gap:12px;align-items:start">
    <!-- LEFT: compact calendar + controls -->
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600">Date</div>
        <div style="font-size:12px;color:var(--muted)" id="compactDateLabel"></div>
      </div>

      <div id="compactCalendar" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;background:transparent;padding:6px;border-radius:8px"></div>

      <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
        <button id="compactPrev" type="button" class="btn ghost" title="Previous month">◀</button>
        <div id="compactMonth" style="flex:1;text-align:center;font-weight:600"></div>
        <button id="compactNext" type="button" class="btn ghost" title="Next month">▶</button>
      </div>

      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px">
        <label class="small muted" for="compactDept">Department (required)</label>
        <select id="compactDept" aria-required="true" style="padding:8px;border-radius:8px;border:1px solid var(--surface-2);">
          <option value="">Select department</option>
        </select>

        <button id="downloadAttendance" class="btn primary" disabled title="Download attendance for selected date & department">Download Attendance</button>
      </div>
    </div>

    <!-- RIGHT: results -->
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div style="font-weight:600">Results</div>
        <div class="small muted" id="compactSummary">No filters applied</div>
      </div>

      <div id="compactResults" style="min-height:120px">
        <div class="small muted">Select a date and department to view records.</div>
      </div>
    </div>
  </div>

  <style>
    /* Compact attendance tweaks */
    #compactCalendar button { padding:6px;border-radius:6px;border:1px solid transparent;background:transparent;cursor:pointer;min-height:48px; display:flex;flex-direction:column;justify-content:flex-start;align-items:flex-start; }
    #compactCalendar button.selected { background:rgba(37,99,235,0.08); border-color:rgba(37,99,235,0.14); }
    #compactCalendar div.weekday { font-size:12px;color:var(--muted);text-align:center;padding:6px 0;font-weight:700; }
    /* compact table */
    .compact-table { width:100%; border-collapse:collapse; font-size:13px; }
    .compact-table thead th { text-align:left;color:var(--muted);padding:8px;border-bottom:1px solid #f1f5f9; font-weight:600; }
    .compact-table tbody td { padding:8px;border-bottom:1px solid #f8fafc;vertical-align:middle; }
    .compact-thumb { width:44px;height:44px;border-radius:6px;object-fit:cover;border:1px solid #eef2f7; }
    .compact-badge { padding:4px 8px;border-radius:999px;font-size:12px;display:inline-block; }
    /* reuse earlier badge classes (on/late/half/absent/leave) */
    .compact-row { display:contents; } /* keep table layout */
    /* responsive: single column stack for very small screens */
    @media (max-width:720px){
      .compact-table thead { display:none; }
      .compact-table tbody td { display:block; width:100%; }
      .compact-table tbody tr { display:block; margin-bottom:10px; border-bottom:1px solid #f1f5f9; padding-bottom:8px; }
    }
  </style>

  <script>
  (function(){
    // Config (mirror server)
    const SHIFT_START = '08:30:00';
    const GRACE_MINUTES = 5;
    const MIN_WORK_SECONDS = 6 * 3600;

    // Elements
    const calEl = document.getElementById('compactCalendar');
    const monthLabel = document.getElementById('compactMonth');
    const prevBtn = document.getElementById('compactPrev');
    const nextBtn = document.getElementById('compactNext');
    const dateLabel = document.getElementById('compactDateLabel');
    const deptSelect = document.getElementById('compactDept');
    const downloadBtn = document.getElementById('downloadAttendance');
    const resultsEl = document.getElementById('compactResults');
    const summaryEl = document.getElementById('compactSummary');

    // state
    let viewDate = new Date(); // month shown
    let selectedDate = new Date(); // selected day
    // initialize
    function isoYMD(d){ 
  return d.getFullYear() + '-' + 
    String(d.getMonth()+1).padStart(2,'0') + '-' + 
    String(d.getDate()).padStart(2,'0'); 
}

    // fetch departments
    async function loadDepartments(){
      try {
        const res = await fetch('admin.php?ajax=get_departments', { credentials: 'same-origin' });
        const json = await res.json();
        if (json.departments && Array.isArray(json.departments)) {
          deptSelect.innerHTML = '<option value="">Select department</option>';
          json.departments.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            deptSelect.appendChild(opt);
          });
        }
      } catch (e) {
        // ignore
      }
    }

    // render small calendar
    function renderCalendar(){
      calEl.innerHTML = '';
      const year = viewDate.getFullYear();
      const month = viewDate.getMonth();
      const first = new Date(year, month, 1);
      const last = new Date(year, month + 1, 0);
      const startWeekday = first.getDay();
      const daysInMonth = last.getDate();

      monthLabel.textContent = first.toLocaleString(undefined, { month: 'short', year: 'numeric' });

      // weekday headers
      const weekdays = ['S','M','T','W','T','F','S'];
      weekdays.forEach(w => {
        const el = document.createElement('div');
        el.className = 'weekday';
        el.textContent = w;
        calEl.appendChild(el);
      });

      // blanks
      for (let i=0;i<startWeekday;i++){
        const blank = document.createElement('div');
        calEl.appendChild(blank);
      }
      // days
      for (let d=1; d<=daysInMonth; d++){
        const dt = new Date(year, month, d);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = d;
        const iso = isoYMD(dt);
        if (iso === isoYMD(selectedDate)) btn.classList.add('selected');
        btn.addEventListener('click', function(){
          selectedDate = dt;
          updateSelectedDate();
          renderCalendar();
          tryLoadRecords();
        });
        calEl.appendChild(btn);
      }
    }

    prevBtn.addEventListener('click', function(){ viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth()-1, 1); renderCalendar(); });
    nextBtn.addEventListener('click', function(){ viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth()+1, 1); renderCalendar(); });

    function updateSelectedDate(){
      dateLabel.textContent = isoYMD(selectedDate);
    }

    // enable download when both selected
    function updateControls(){
      const dept = deptSelect.value;
      const ok = dept !== '' && selectedDate;
      downloadBtn.disabled = !ok;
      if (!ok) summaryEl.textContent = 'No filters applied';
    }

    deptSelect.addEventListener('change', function(){
      updateControls();
      // only attempt to load once both are set
      tryLoadRecords();
    });

    // fetch & render records if both filters present
    async function tryLoadRecords(){
      const dept = deptSelect.value;
      if (!dept) {
        resultsEl.innerHTML = '<div class="small muted">Please select a department.</div>';
        return;
      }
      if (!selectedDate) {
        resultsEl.innerHTML = '<div class="small muted">Please select a date.</div>';
        return;
      }
      // fetch
      const dateStr = isoYMD(selectedDate);
      resultsEl.innerHTML = '<div class="small muted">Loading…</div>';
      try {
        const res = await fetch('admin.php?ajax=attendance_compact&date=' + encodeURIComponent(dateStr) + '&department=' + encodeURIComponent(dept), { credentials:'same-origin' });
        const json = await res.json();
        if (json.error) {
          resultsEl.innerHTML = '<div class="small muted">Error: ' + json.error + '</div>';
          return;
        }
        renderResultsTable(json.records || []);
        summaryEl.textContent = (json.records || []).length + ' record(s) — ' + dateStr + ' / ' + dept;
      } catch (e) {
        resultsEl.innerHTML = '<div class="small muted">Unable to load records.</div>';
      }
    }

    // compute status (same rules as backend)
function parseYMDHMS(dt) {
  if (!dt) return null;
  // Handle both '2025-02-12 10:18:00' and '2025-02-12T10:18:00+08:00' formats
  const m = dt.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
  if (!m) return Date.parse(dt) || null;
  
  // Parse as local time (NOT UTC)
  const year = parseInt(m[1]);
  const month = parseInt(m[2]) - 1;
  const day = parseInt(m[3]);
  const hour = parseInt(m[4]);
  const minute = parseInt(m[5]);
  const second = parseInt(m[6]);
  
  return new Date(year, month, day, hour, minute, second).getTime();
}
function computeStatus(firstIn, lastOut) {
  const [sh, sm, ss] = SHIFT_START.split(':').map(Number);
  const year = selectedDate.getFullYear();
  const month = selectedDate.getMonth();
  const date = selectedDate.getDate();
  const shiftStartTs = new Date(year, month, date, sh, sm, ss).getTime();
  const graceEndTs = new Date(year, month, date, sh, sm + GRACE_MINUTES, ss).getTime();
  const absentAfterTs = new Date(year, month, date, 13, 0, 0).getTime();

  const fi = parseYMDHMS(firstIn);
  const lo = parseYMDHMS(lastOut);

  if (!fi && !lo) {
    return { label: 'Absent', cls: 'absent' };
  }

  if (fi && fi >= absentAfterTs) {
    return { label: 'Absent', cls: 'absent' };
  }

  if (fi && !lo) {
    return { label: 'Half-day', cls: 'half' };
  }

  if (!fi && lo) {
    return { label: 'Half-day', cls: 'half' };
  }

  const worked = Math.max(0, Math.floor((lo - fi) / 1000));
  const isLate = fi > graceEndTs;
  const isFullDay = worked >= MIN_WORK_SECONDS;

  if (!isFullDay) {
    if (isLate) {
      return { label: 'Under time | Late', cls: 'half' };
    }
    return { label: 'Under time', cls: 'half' };
  }

  if (isLate) {
    return { label: 'Late', cls: 'late' };
  }

  return { label: 'Regular', cls: 'on' };
}
  


    // render results table
    // render results table
function renderResultsTable(records){
  if (!records || records.length === 0) {
    resultsEl.innerHTML = '<div class="small muted">No employees or no attendance records for this selection.</div>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'compact-table';
  const thead = document.createElement('thead');
  thead.innerHTML = '<tr><th>Employee Photo</th><th>Employee ID</th><th>Full name</th><th>Department</th><th>Status</th><th>Time In</th><th>In Photo</th><th>Time Out</th><th>Out Photo</th></tr>';
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  records.forEach(rec => {
    const tr = document.createElement('tr');

    // ========== COLUMN 1: EMPLOYEE PHOTO (from emp_photo - always shown) ==========
    const tdEmpPhoto = document.createElement('td');
    const empImg = document.createElement('img');
    empImg.src = rec.emp_photo || 'uploads/default.png';
    empImg.alt = rec.name + ' profile photo';
    empImg.className = 'compact-thumb';
    tdEmpPhoto.appendChild(empImg);
    tr.appendChild(tdEmpPhoto);

    // COLUMN 2: EMPLOYEE ID
    const tdId = document.createElement('td'); 
    tdId.textContent = rec.id_code || ''; 
    tr.appendChild(tdId);

    // COLUMN 3: FULL NAME
    const tdName = document.createElement('td'); 
    tdName.textContent = rec.name || ''; 
    tr.appendChild(tdName);

    // COLUMN 4: DEPARTMENT
    const tdDept = document.createElement('td'); 
    tdDept.textContent = rec.department || ''; 
    tr.appendChild(tdDept);

    // COLUMN 5: STATUS
    const status = computeStatus(rec.first_in, rec.last_out);
    const tdStatus = document.createElement('td');
    const span = document.createElement('span');
    span.className = 'compact-badge ' + status.cls;
    span.textContent = status.label;
    tdStatus.appendChild(span);
    tr.appendChild(tdStatus);

// COLUMN 6: TIME IN
const tdIn = document.createElement('td');
tdIn.textContent = rec.first_in ? new Date(rec.first_in).toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit'}) : '—';
tr.appendChild(tdIn);

    // COLUMN 7: TIME IN PHOTO (attendance photo, blank if no time in)
    const tdInPhoto = document.createElement('td');
    if (rec.first_in && rec.in_photo) {
      // ✅ ONLY show photo if there's a time_in AND in_photo exists
      const inImg = document.createElement('img');
      inImg.src = rec.in_photo;
      inImg.alt = 'In photo';
      inImg.style.width = '64px';
      inImg.style.height = '44px';
      inImg.style.objectFit = 'cover';
      inImg.style.borderRadius = '6px';
      inImg.style.border = '1px solid #eef2f7';
      tdInPhoto.appendChild(inImg);
    } else {
      // ✅ BLANK if no time in
      tdInPhoto.textContent = '—';
    }
    tr.appendChild(tdInPhoto);


// COLUMN 8: TIME OUT
const tdOut = document.createElement('td');
tdOut.textContent = rec.last_out ? new Date(rec.last_out).toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit'}) : '—';
tr.appendChild(tdOut);

    // COLUMN 9: TIME OUT PHOTO (attendance photo, blank if no time out)
    const tdOutPhoto = document.createElement('td');
    if (rec.last_out && rec.out_photo) {
      // ✅ ONLY show photo if there's a time_out AND out_photo exists
      const outImg = document.createElement('img');
      outImg.src = rec.out_photo;
      outImg.alt = 'Out photo';
      outImg.style.width = '64px';
      outImg.style.height = '44px';
      outImg.style.objectFit = 'cover';
      outImg.style.borderRadius = '6px';
      outImg.style.border = '1px solid #eef2f7';
      tdOutPhoto.appendChild(outImg);
    } else {
      // ✅ BLANK if no time out
      tdOutPhoto.textContent = '—';
    }
    tr.appendChild(tdOutPhoto);

    tbody.appendChild(tr);
  });

  table.appendChild(tbody);
  resultsEl.innerHTML = '';
  resultsEl.appendChild(table);
}

    // Download CSV via fetch and force-download (no page reload)
    downloadBtn.addEventListener('click', async function(){
      const dept = deptSelect.value;
      if (!dept) return;
      const dateStr = isoYMD(selectedDate);
      downloadBtn.disabled = true;
      downloadBtn.textContent = 'Preparing…';
      try {
        const res = await fetch('admin.php?ajax=download_attendance&date=' + encodeURIComponent(dateStr) + '&department=' + encodeURIComponent(dept), { credentials:'same-origin' });
        if (!res.ok) throw new Error('Download failed');
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        // try to get filename from header
        const cd = res.headers.get('Content-Disposition');
        let filename = 'attendance_' + dept + '_' + dateStr + '.csv';
        if (cd) {
          const m = /filename="?([^"]+)"?/.exec(cd);
          if (m) filename = m[1];
        }
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
      } catch (e) {
        alert('Could not download attendance.');
      } finally {
        downloadBtn.disabled = false;
        downloadBtn.textContent = 'Download Attendance';
      }
    });

    // initial
    (function init(){
  const syncedNow = window.getSyncedTime ? window.getSyncedTime() : new Date();
  selectedDate = syncedNow;
  viewDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
  updateSelectedDate();
  renderCalendar();
  loadDepartments();
  updateControls();
})();

    // expose updateControls to calendar changes
    function updateControlsAndMaybeFetch(){
      updateControls();
      tryLoadRecords();
    }

    // wire date update to button handlers (month nav)
    prevBtn.addEventListener('click', function(){ renderCalendar(); updateControls(); });
    nextBtn.addEventListener('click', function(){ renderCalendar(); updateControls(); });
  })();
  </script>
</section>

      <!-- Departments section -->
      <section id="section-departments" class="section" aria-labelledby="departments-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="departments-h" style="margin:0">Departments</h2>
          <div class="small muted">Create and remove departments</div>
        </div>

        <div class="card">
          <p class="small muted">Deleting a department is prevented if employees are assigned.</p>

          <form method="post" action="admin.php" class="small-form" style="margin-bottom:12px;">
            <input type="hidden" name="action" value="add_department">
            <input type="text" name="department_name" placeholder="New department name" aria-label="New department name" required>
            <button type="submit" class="btn primary">Add</button>
          </form>

          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($departments as $d): ?>
              <div style="background:#fff;border:1px solid #eef2f7;padding:8px 10px;border-radius:999px;display:flex;align-items:center;gap:8px">
                <span><?php echo htmlspecialchars($d); ?></span>
                <form method="post" action="admin.php" onsubmit="return confirm('Delete department <?php echo htmlspecialchars(addslashes($d)); ?>?');">
                  <input type="hidden" name="action" value="delete_department">
                  <input type="hidden" name="department_name" value="<?php echo htmlspecialchars($d); ?>">
                  <button type="submit" title="Delete department" style="background:transparent;border:0;color:var(--danger);font-weight:700;cursor:pointer">×</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- Reports section (placeholder) -->
      <section id="section-reports" class="section" aria-labelledby="reports-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="reports-h" style="margin:0">Reports</h2>
          <div class="small muted">Export and view reports</div>
        </div>

        <div class="card">
          <p class="small muted">Generate attendance reports, export CSVs, and view historical data. Use the Export CSV action in the Employees or Attendance sections, or visit the <a href="reports.php">Reports page</a>.</p>
        </div>
      </section>

      <!-- Settings section (placeholder) -->
      <section id="section-settings" class="section" aria-labelledby="settings-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="settings-h" style="margin:0">Settings</h2>
          <div class="small muted">Admin settings & preferences</div>
        </div>

        <div class="card">
          <p class="small muted">Manage application settings, create sub-admin accounts, and view admin access details.</p>

          <form method="post" action="admin.php" enctype="multipart/form-data" class="add-form" style="margin-bottom:16px;" novalidate>
            <input type="hidden" name="action" value="add_admin_user">
            <input type="hidden" name="section" value="settings">

            <div class="form-row">
              <div class="field">
                <label class="field-label" for="username">Username</label>
                <input id="username" name="username" type="text" placeholder="admin username" required>
              </div>
              <div class="field">
                <label class="field-label" for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="password" required>
              </div>
            </div>

            <div class="form-row">
              <div class="field">
                <label class="field-label" for="full_name">Full Name</label>
                <input id="full_name" name="full_name" type="text" placeholder="John Doe" required>
              </div>
              <div class="field">
                <label class="field-label" for="position">Position</label>
                <input id="position" name="position" type="text" placeholder="HR Manager, Sub-admin, etc.">
              </div>
            </div>

            <div class="form-row">
              <div class="field" style="flex:1">
                <label class="field-label" for="photo">Photo (optional)</label>
                <input id="photo" name="photo" type="file" accept="image/*">
              </div>
            </div>

            <button type="submit" class="btn primary">Add Sub Admin</button>
          </form>

          <div style="overflow-x:auto;">
            <table class="employee-table" role="table" aria-label="Admin accounts">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Username</th>
                  <th>Name</th>
                  <th>Position</th>
                  <th>Password</th>
                  <th>Photo</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($adminList)): ?>
                  <tr><td colspan="7" class="small muted">No admin accounts found.</td></tr>
                <?php else: ?>
                  <?php foreach ($adminList as $admin): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($admin['id']); ?></td>
                      <td><?php echo htmlspecialchars($admin['username']); ?></td>
                      <td><?php echo htmlspecialchars($admin['fullname'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($admin['position'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($admin['password_plain'] ?? ''); ?></td>
                      <td>
                        <?php if (!empty($admin['photo'])): ?>
                          <img src="uploads/admins/<?php echo htmlspecialchars($admin['photo']); ?>" alt="<?php echo htmlspecialchars($admin['fullname'] ?? $admin['username']); ?>" width="48" height="48" style="border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                          <span class="small muted">No photo</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($_SESSION['admin_user']) && $admin['username'] === $_SESSION['admin_user']): ?>
                          <span class="small muted">Signed in</span>
                        <?php else: ?>
                          <form method="post" action="admin.php" onsubmit="return confirm('Delete admin <?php echo htmlspecialchars(addslashes($admin['username'])); ?>?');">
                            <input type="hidden" name="action" value="delete_admin_user">
                            <input type="hidden" name="section" value="settings">
                            <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin['id']); ?>">
                            <button type="submit" class="btn ghost" style="color:var(--danger);">Delete</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- RFID Writer section -->
      <section id="section-rfid" class="section" aria-labelledby="rfid-h">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 id="rfid-h" style="margin:0">RFID Writer</h2>
          <div class="small muted">Write new RFID tags</div>
        </div>

        <div id="rfidMessage" class="card" style="margin-bottom:12px; padding:10px; border-radius:4px; display:none;">
          <p style="margin:0; font-size:14px;"></p>
        </div>

        <div class="card">
          <p class="small muted" style="margin-bottom:16px;">Write RFID codes to cards and assign them to employees. Connect your RFID writer hardware for actual writing capability.</p>

          <form id="rfidWriteForm" class="add-form" novalidate>
            <input type="hidden" name="action" value="write_rfid">

            <div class="form-row">
              <div class="field">
                <label class="field-label" for="rfidCodeWrite">RFID Code to Write</label>
                <input id="rfidCodeWrite" name="rfid_code" type="text" placeholder="Enter RFID code (e.g., 00030972690003097269)" required>
              </div>
              <div class="field">
                <label class="field-label" for="rfidEmployeeWrite">Assign to Employee</label>
                <select id="rfidEmployeeWrite" name="employee_id" required>
                  <option value="">-- Select Employee --</option>
                  <?php 
                    try {
                      $empStmt = $pdo->query("SELECT id, name FROM employees ORDER BY name");
                      $rfidEmployees = $empStmt->fetchAll();
                      foreach ($rfidEmployees as $emp):
                  ?>
                    <option value="<?php echo htmlspecialchars($emp['id']); ?>">
                      <?php echo htmlspecialchars($emp['id'] . ' - ' . $emp['name']); ?>
                    </option>
                  <?php 
                      endforeach;
                    } catch (Exception $e) {}
                  ?>
                </select>
              </div>
            </div>

            <button type="submit" class="btn primary">Write RFID Card</button>
          </form>

          <div style="margin-top:20px; padding-top:16px; border-top:1px solid #e0e0e0;">
            <div class="small muted" style="margin-bottom:12px;">ℹ️ <strong>Hardware Status:</strong> Awaiting RFID writer device connection</div>
            <div class="small muted" style="font-size:12px; color:#999;">
              This interface supports various RFID writers: EM4100, PN532, ACR122U, RDM880, etc. 
              <br>Once hardware is connected to your server, this form will write directly to physical cards.
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
  

  <!-- Small client-side router to show/hide sections and highlight nav + form behavior -->
  <script>
    (function(){
      const navLinks = Array.from(document.querySelectorAll('.nav a[data-section]'));
      const sections = Array.from(document.querySelectorAll('.section'));
      const sectionByName = {};
      sections.forEach(s => {
        if (!s.id) return;
        const name = s.id.startsWith('section-') ? s.id.slice(8) : s.id;
        sectionByName[name] = s;
      });

      function setActiveSection(name, pushState = false) {
        sections.forEach(s => s.classList.remove('active'));
        navLinks.forEach(a => {
          a.classList.remove('active');
          a.removeAttribute('aria-current');
        });

        let target = sectionByName[name];
        if (!target) {
          name = 'dashboard';
          target = sectionByName[name];
        }

        if (target) {
          target.classList.add('active');
        }

        const link = navLinks.find(a => a.dataset.section === name);
        if (link) {
          link.classList.add('active');
          link.setAttribute('aria-current','page');
        }

        const newHash = '#' + name;
        if (pushState) {
          history.pushState({section: name}, '', newHash);
        } else {
          history.replaceState({section: name}, '', newHash);
        }
      }

      navLinks.forEach(a => {
        a.addEventListener('click', function(e){
          e.preventDefault();
          const sec = this.dataset.section;
          setActiveSection(sec, true);
        });
      });

      function initialFromHash() {
        const hash = (location.hash || '').replace('#','');
        if (hash && sectionByName[hash]) {
          return hash;
        }
        return 'dashboard';
      }

      const initial = initialFromHash();
      setActiveSection(initial, false);

      window.addEventListener('popstate', function(e){
        const sec = (e.state && e.state.section) ? e.state.section : initialFromHash();
        setActiveSection(sec, false);
      });

     // ===== EMPLOYEE FILTERING (IMPROVED) =====
(function(){
  const empSearch = document.getElementById('searchInputEmployees');
  const empFilterDept = document.getElementById('filterDepartmentEmployees');
  const empFilterStatus = document.getElementById('filterStatusEmployees');
  
  function applyEmployeeFilters() {
    if (!empSearch || !empFilterDept || !empFilterStatus) {
      console.warn('Employee filter elements not found');
      return;
    }
    
    const searchQuery = empSearch.value.trim().toLowerCase();
    const deptFilter = empFilterDept.value.trim().toLowerCase();
    const statusFilter = empFilterStatus.value.trim().toLowerCase();
    
    // Target the actual employee table
    const tbody = document.querySelector('table.employee-table tbody');
    if (!tbody) {
      console.warn('Employee table tbody not found');
      return;
    }
    
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let visibleCount = 0;
    
    rows.forEach(row => {
      // Skip the "no employees found" row if it exists
      if (row.querySelector('.small.muted')) {
        row.style.display = 'none';
        return;
      }
      
      const cells = row.querySelectorAll('td');
      if (cells.length < 8) return;
      
      const idCode = (cells[1]?.textContent || '').trim().toLowerCase();
      const empName = (cells[2]?.textContent || '').trim().toLowerCase();
      const dept = (cells[3]?.textContent || '').trim().toLowerCase();
      const rowStatus = (row.dataset.employeeStatus || '').trim().toLowerCase();
      const rowLastStatus = (row.dataset.employeeLastStatus || '').trim().toLowerCase();
      
      const matchesSearch = 
        searchQuery === '' ||
        idCode.includes(searchQuery) ||
        empName.includes(searchQuery);
      const matchesDept = deptFilter === '' || dept === deptFilter;
      const matchesStatus = statusFilter === '' || rowStatus === statusFilter || rowLastStatus === statusFilter;
      
      const shouldShow = matchesSearch && matchesDept && matchesStatus;
      row.style.display = shouldShow ? '' : 'none';
      
      if (shouldShow) visibleCount++;
    });
    
  }
  
  // Wire up listeners
  if (empSearch) {
    empSearch.addEventListener('input', applyEmployeeFilters);
  }
  
  if (empFilterDept) {
    empFilterDept.addEventListener('change', applyEmployeeFilters);
  }
  
  if (empFilterStatus) {
    empFilterStatus.addEventListener('change', applyEmployeeFilters);
  }

  // When switching to employees, ensure filters are applied
  navLinks.forEach(a => {
    a.addEventListener('click', function(){
      if (this.dataset.section === 'employees') {
        applyEmployeeFilters();
      }
    });
  });

  // Filter employees from dashboard cards and allow row click navigation
  const metricCards = Array.from(document.querySelectorAll('button.metric-card'));
  const employeeTableBody = document.querySelector('table.employee-table tbody');

  if (metricCards.length && empFilterStatus && empFilterDept && empSearch) {
    metricCards.forEach(card => {
      card.addEventListener('click', () => {
        const filter = card.dataset.statusFilter || '';
        setActiveSection('employees', true);
        empSearch.value = '';
        empFilterDept.value = '';
        empFilterStatus.value = filter;
        applyEmployeeFilters();
        if (employeeTableBody) {
          employeeTableBody.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
  }

  if (employeeTableBody) {
    employeeTableBody.addEventListener('click', function(event) {
      if (event.target.closest('a, button, input, select, form')) return;
      const row = event.target.closest('tr[data-employee-id]');
      if (!row) return;
      const empId = row.dataset.employeeId;
      if (!empId) return;
      window.location.href = 'employee_calendar.php?id=' + encodeURIComponent(empId);
    });
  }
})();

      // Accessibility: make main focusable
      const main = document.querySelector('main');
      if (main) {
        main.setAttribute('tabindex','-1');
      }

      // -----------------------
      // Add Employee form JS
      // -----------------------
      const empForm = document.getElementById('empAddForm');
      const photoInput = document.getElementById('photo');
      const photoPreview = document.getElementById('photoPreview');
      const feedbackEl = document.getElementById('addFormFeedback');
      const resetBtn = document.getElementById('resetEmpForm');

      // Render simple preview for chosen image
      if (photoInput) {
        photoInput.addEventListener('change', function(){
          const f = this.files && this.files[0];
          if (!f) {
            photoPreview.textContent = 'No image selected';
            photoPreview.style.backgroundImage = '';
            return;
          }
          if (!f.type.startsWith('image/')) {
            photoPreview.textContent = 'Unsupported file';
            return;
          }
          const reader = new FileReader();
          reader.onload = function(e) {
            photoPreview.style.backgroundImage = 'url('+e.target.result+')';
            photoPreview.style.backgroundSize = 'cover';
            photoPreview.style.backgroundPosition = 'center';
            photoPreview.textContent = '';
          };
          reader.readAsDataURL(f);
        });
      }

      // Client-side validation prior to submit (enhances HTML required)
      if (empForm) {
        empForm.addEventListener('submit', function(e){
          feedbackEl.style.display = 'none';
          feedbackEl.textContent = '';

          // Use built-in validity checks
          if (!empForm.checkValidity()) {
            e.preventDefault();
            // find first invalid element and focus + show message
            const firstInvalid = empForm.querySelector(':invalid');
            if (firstInvalid) {
              firstInvalid.focus();
              feedbackEl.textContent = firstInvalid.validationMessage || 'Please complete required fields.';
              feedbackEl.style.display = 'inline';
            }
            return;
          }

          // Additional client-side checks (date format)
          const dateField = document.getElementById('date_hired');
          if (dateField) {
            const v = dateField.value;
            if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) {
              e.preventDefault();
              feedbackEl.textContent = 'Date Hired must be a valid date (YYYY-MM-DD).';
              feedbackEl.style.display = 'inline';
              dateField.focus();
              return;
            }
          }

          // allow submit to proceed (server will do final validation)
        });
      }

      // Reset button: clear form & preview
      if (resetBtn) {
        resetBtn.addEventListener('click', function(){
          if (empForm) empForm.reset();
          if (photoPreview) {
            photoPreview.style.backgroundImage = '';
            photoPreview.textContent = 'No image selected';
          }
          feedbackEl.style.display = 'none';
          feedbackEl.textContent = '';
        });
      }

      // -----------------------
      // RFID Writer form JS
      // -----------------------
      const rfidForm = document.getElementById('rfidWriteForm');
      const rfidMessageEl = document.getElementById('rfidMessage');

      if (rfidForm) {
        rfidForm.addEventListener('submit', async function(e) {
          e.preventDefault();
          
          const rfidCode = document.getElementById('rfidCodeWrite').value.trim();
          const empId = document.getElementById('rfidEmployeeWrite').value;
          
          if (!rfidCode || !empId) {
            showRFIDMessage('Please fill in all fields', 'error');
            return;
          }
          
          try {
            const formData = new FormData();
            formData.append('action', 'write_rfid');
            formData.append('rfid_code', rfidCode);
            formData.append('employee_id', empId);
            
            const response = await fetch('admin.php', {
              method: 'POST',
              body: formData
            });
            
            const data = await response.json();
            
            if (response.ok && data.status === 'ok') {
              showRFIDMessage(data.message, 'success');
              rfidForm.reset();
            } else {
              showRFIDMessage(data.message || 'Error writing RFID card', 'error');
            }
          } catch (error) {
            showRFIDMessage('Network error: ' + error.message, 'error');
          }
        });
      }

      function showRFIDMessage(msg, type) {
        if (!rfidMessageEl) return;
        rfidMessageEl.style.display = 'block';
        rfidMessageEl.className = 'card ' + (type === 'success' ? 'success-msg' : 'error-msg');
        rfidMessageEl.querySelector('p').textContent = msg;
        rfidMessageEl.style.backgroundColor = type === 'success' ? '#d4edda' : '#f8d7da';
        rfidMessageEl.style.borderColor = type === 'success' ? '#c3e6cb' : '#f5c6cb';
        rfidMessageEl.style.color = type === 'success' ? '#155724' : '#721c24';
        
        if (type === 'success') {
          setTimeout(() => {
            rfidMessageEl.style.display = 'none';
          }, 3000);
        }
      }
      

    })();
  </script>
  <script>
// =============== SYNC ADMIN ATTENDANCE CLOCK WITH SERVER TIME ===============
let serverOffsetMs = 0;

// Fetch server time and calculate offset
fetch('server_time.php', { cache: 'no-store' })
  .then(r => {
    if (!r.ok) throw new Error('fetch failed');
    return r.json();
  })
  .then(data => {
    if (data && data.server_ts_ms) {
      serverOffsetMs = Number(data.server_ts_ms) - Date.now();
    }
  })
  .catch(err => {
    console.warn('[time-sync] Could not sync server time, using client time:', err);
    serverOffsetMs = 0;
  });

// Helper function to get synced time
function getSyncedTime() {
  return new Date(Date.now() + serverOffsetMs);
}

// Make it globally available
window.getSyncedTime = getSyncedTime;
</script>
</body>
</html>