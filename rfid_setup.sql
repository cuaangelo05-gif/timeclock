-- RFID Mapping Table Setup
-- This table stores the mapping between RFID card codes and employee IDs
-- Useful if RFID codes don't directly correspond to employee IDs

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

-- Example insert (replace with actual data):
-- INSERT INTO rfid_mapping (rfid_code, employee_id) VALUES ('5678901234567890', 1001);
-- INSERT INTO rfid_mapping (rfid_code, employee_id) VALUES ('1234567890123456', 1002);
