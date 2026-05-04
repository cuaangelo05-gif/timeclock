#!/bin/bash
# RFID Setup Quick Start Script
# Run this to set up RFID mapping in your timeclock database

DB_HOST="127.0.0.1"
DB_NAME="timeclock"
DB_USER="root"
DB_PASS=""

# Create RFID mapping table
echo "Creating RFID mapping table..."
mysql -h $DB_HOST -u $DB_USER $DB_NAME -e "
CREATE TABLE IF NOT EXISTS rfid_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rfid_code VARCHAR(255) UNIQUE NOT NULL,
    employee_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_rfid_code (rfid_code),
    INDEX idx_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"

echo "RFID setup complete!"
echo "Now add RFID codes using:"
echo "mysql -u root timeclock -e \"INSERT INTO rfid_mapping (rfid_code, employee_id) VALUES ('YOUR_RFID_CODE', YOUR_EMPLOYEE_ID);\""
