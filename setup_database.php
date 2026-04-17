<?php
// setup_database.php - Create required database schema
date_default_timezone_set('Asia/Manila');
require 'config.php';

echo "=== TimeClock Database Setup ===\n\n";

try {
    // 1. Employees table
    echo "Creating 'employees' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_code VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        department VARCHAR(100),
        branch VARCHAR(100),
        division VARCHAR(100),
        position VARCHAR(150),
        employment_type VARCHAR(50),
        shift VARCHAR(100),
        attendance_status VARCHAR(50) DEFAULT 'Active',
        date_hired DATE,
        photo VARCHAR(255),
        last_status VARCHAR(20),
        last_timestamp DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_id_code (id_code),
        INDEX idx_department (department)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    // 2. Attendance table
    echo "Creating 'attendance' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        id_code VARCHAR(64),
        event_type VARCHAR(10),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        department VARCHAR(100),
        branch VARCHAR(100),
        division VARCHAR(100),
        ip_address VARCHAR(45),
        photo VARCHAR(255),
        INDEX idx_employee (employee_id),
        INDEX idx_date (created_at),
        INDEX idx_event (event_type),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    // 3. Departments table
    echo "Creating 'departments' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    // 4. Leaves table
    echo "Creating 'leaves' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS leaves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        leave_date DATE NOT NULL,
        note VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_leave (employee_id, leave_date),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    // 5. Admins table
    echo "Creating 'admins' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        fullname VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    // 6. Attendance logs table
    echo "Creating 'attendance_logs' table...";
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        emp_id INT NOT NULL,
        action VARCHAR(10),
        photo_path VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45),
        department VARCHAR(100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo " ✅\n";

    echo "\n" . str_repeat("=", 40) . "\n";
    echo "✅ ALL TABLES CREATED SUCCESSFULLY!\n";
    echo str_repeat("=", 40) . "\n";
    echo "\n✏️  Next steps:\n";
    echo "1. Run: php create_default.php\n";
    echo "2. Create admin user via: http://localhost/your-project/create_admin_user.php\n";
    echo "3. Delete create_admin_user.php after creating admin\n";

} catch (Exception $e) {
    echo " ❌\n";
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "❌ ERROR CREATING TABLES\n";
    echo str_repeat("=", 40) . "\n";
    echo "\nError message:\n";
    echo htmlspecialchars($e->getMessage()) . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Check that config.php has correct credentials\n";
    echo "2. Verify MySQL is running\n";
    echo "3. Check database 'timeclock' exists\n";
    exit(1);
}
?>