# RFID Configuration Guide

## Overview
The timeclock system now supports RFID card scanning for automatic employee time-in and time-out.

## How It Works

1. **RFID Scanner Connection**: Connect your RFID scanner to your kiosk computer (USB or keyboard emulation mode)
2. **Card Tap**: When an employee taps their RFID card, the scanner reads the code
3. **Auto-Lookup**: The system looks up the employee ID from the RFID code
4. **Auto-Submit**: The form automatically submits with Time In action selected

## Setup Options

### Option 1: Direct RFID Code as Employee ID (Simple)
If your RFID codes are numeric and correspond directly to employee IDs:

1. No database setup needed
2. The system will accept the RFID code as-is
3. Make sure your employee IDs are numeric

**Example**: 
- Employee ID: `1001`
- RFID Card Code: `1001` (when tapped)
- Result: ✓ Automatic time-in

### Option 2: RFID Code Stored in `id_code` Field (Recommended)
If you have an `id_code` column in the employees table:

1. The system checks the `id_code` field for RFID matches
2. Add RFID codes to employee records: `UPDATE employees SET id_code = '5678901234567890' WHERE id = 1001;`
3. When card is tapped, system finds matching employee

**Example**:
- Employee ID: `1001`
- RFID Card Code: `5678901234567890`
- id_code in DB: `5678901234567890`
- Result: ✓ Automatic time-in as employee 1001

### Option 3: RFID Mapping Table (Most Flexible)
For complex mappings or multiple RFID formats:

1. **Create the mapping table**:
   ```bash
   mysql -u root timeclock < rfid_setup.sql
   ```

2. **Add RFID codes to the mapping**:
   ```sql
   INSERT INTO rfid_mapping (rfid_code, employee_id) 
   VALUES ('5678901234567890', 1001);
   
   INSERT INTO rfid_mapping (rfid_code, employee_id) 
   VALUES ('1234567890123456', 1002);
   ```

3. When a card is tapped, the system looks up the mapping table first

**Lookup Priority**:
1. Check `rfid_mapping` table
2. Check if RFID code IS the employee ID
3. Check `id_code` field in employees table
4. Return "not found" if none match

## RFID Scanner Configuration

### USB Scanner (HID Mode - Recommended)
Most USB RFID scanners work out-of-the-box in HID (keyboard emulation) mode:

1. Connect scanner to kiosk computer
2. The scanner will type the RFID code directly into the hidden input field
3. No additional software needed

### Serial Port Scanner
If using a serial port RFID scanner, you may need additional browser configuration or a local proxy app.

### Mobile/Tablet
Some mobile readers can send RFID codes via local network:
1. Configure the reader to send codes via HTTP POST to your system
2. Modify the JavaScript to accept AJAX input

## Testing the RFID System

1. **Open the timeclock page**: `http://localhost/timeclock/`
2. **Test manual entry first**: Enter an employee ID manually and click GO
3. **Test RFID scanner**: Tap a card with a known RFID code
   - Watch for "Processing RFID..." message
   - Employee ID should auto-populate
   - Form should auto-submit
   - Profile card should show with time recorded

## Troubleshooting

### RFID Code Not Recognized
1. Check the `rfid_lookup.php` output: Open browser developer tools (F12)
2. Check Network tab for the RFID lookup request
3. Verify the RFID code is in the correct database table
4. Try manual entry with the employee ID to confirm the employee exists

### RFID Code Appears in Manual Input Field
This means the RFID input field lost focus. The system should refocus it automatically, but you can:
1. Manually click the RFID input field area (off-screen)
2. Check browser console for errors (F12 → Console)

### Scanner Not Working
1. Verify scanner is connected and powered on
2. Test scanner by opening Notepad and tapping a card (should type the code)
3. Check scanner is in HID/Keyboard mode (not ASCII mode)
4. Try tapping a different card

## Manual Configuration

If you need to change RFID lookup behavior, edit the JavaScript in `attendance.js`:

Look for the `processRFIDData` function and modify the fetch request to `rfid_lookup.php`.

## Advanced Features

### Automatic Time Out
To enable automatic Time Out instead of Time In, modify the button selection in `attendance.js`:

```javascript
// Find this line in processRFIDData:
const inBtn = segBtns.find(btn => btn.getAttribute('data-value') === 'in');

// Change 'in' to 'out' for automatic Time Out:
const outBtn = segBtns.find(btn => btn.getAttribute('data-value') === 'out');
```

### Delay Before Auto-Submit
To add a delay before auto-submitting (e.g., to show employee name), modify this line:

```javascript
setTimeout(() => {
  if (validateState()) {
    if (form) form.dispatchEvent(new Event('submit'));
  }
}, 200); // Change 200 to desired milliseconds
```

### Different RFID Formats
If your RFID codes have a special format (prefixes, checksums, etc.), modify the lookup logic in `rfid_lookup.php` to parse them.

## Database Setup Commands

```bash
# Create the database (if not exists)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS timeclock;"

# Import the RFID mapping table
mysql -u root timeclock < rfid_setup.sql

# Example: Add RFID codes to existing employees
mysql -u root timeclock -e "UPDATE employees SET id_code = '5678901234567890' WHERE id = 1001;"

# View RFID mappings
mysql -u root timeclock -e "SELECT * FROM rfid_mapping;"
```

## Security Notes

- RFID codes are sent in POST requests (not visible in URL)
- The `rfid_lookup.php` endpoint validates against employee records
- Invalid RFID codes are rejected and logged
- Consider HTTPS for production use

## Support

For issues or custom RFID configurations, check:
- Browser console errors (F12)
- `rfid_lookup.php` response in Network tab
- Database for RFID code entries
