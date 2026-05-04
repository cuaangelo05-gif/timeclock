# RFID Timeclock Integration - Complete Setup Guide

## What's New

Your timeclock system now includes full RFID card support! Here's what has been added:

### New Files Created:
1. **rfid_lookup.php** - Backend API to look up employee IDs from RFID codes
2. **rfid_admin.php** - Web-based admin panel to manage RFID card mappings
3. **rfid_setup.sql** - Database table schema for RFID mappings
4. **rfid_setup.bat** - Windows setup script
5. **rfid_setup.sh** - Linux/Mac setup script
6. **RFID_SETUP.md** - Detailed configuration documentation

### Modified Files:
1. **index.php** - Added hidden RFID input field
2. **attendance.js** - Added RFID card detection and automatic processing

---

## Quick Start (5 Minutes)

### Step 1: Connect Your RFID Scanner
- Connect USB RFID scanner to your kiosk computer
- The scanner should work in keyboard emulation (HID) mode
- Test by opening Notepad and tapping a card (it should type the card code)

### Step 2: Choose Your Setup Option

#### Option A: Direct Mapping (Simplest)
If RFID codes ARE your employee IDs:
1. Tap a card at the timeclock
2. The employee ID should auto-populate
3. Done! ✓

#### Option B: Use Web Admin Panel (Recommended)
This is the easiest way to add RFID cards:

1. Open: `http://localhost/timeclock/rfid_admin.php?auth=admin123`
2. On the "Add New RFID Card" form:
   - Scan/paste the RFID code in the "RFID Card Code" field
   - Select the employee from dropdown
   - Click "Add RFID Card"
3. Repeat for each employee
4. The table will show all registered cards

**⚠️ SECURITY**: Change the password from 'admin123' to something secure:
- Edit `rfid_admin.php` line 9
- Change `'admin123'` to your password

#### Option C: Database Table (Advanced)
For complex setups, create the RFID mapping table:

```bash
# Windows (PowerShell as Admin):
cd C:\xampp\htdocs\timeclock
.\rfid_setup.bat

# Linux/Mac:
chmod +x rfid_setup.sh
./rfid_setup.sh

# Or manually in MySQL:
mysql -u root timeclock < rfid_setup.sql
```

Then add RFID codes:
```sql
INSERT INTO rfid_mapping (rfid_code, employee_id) 
VALUES ('5678901234567890', 1001);
```

---

## How It Works

### When an Employee Taps Their RFID Card:

1. ✓ RFID scanner sends the card code
2. ✓ Hidden input captures the code
3. ✓ System looks up the employee ID using `rfid_lookup.php`
4. ✓ Employee ID auto-populates in the form
5. ✓ "Time In" is automatically selected
6. ✓ Form auto-submits with camera capture
7. ✓ Employee profile card shows with time recorded
8. ✓ System auto-resets for next employee after 5 seconds

---

## System Lookup Priority

The system checks these sources IN ORDER to find the employee ID:

1. **RFID Mapping Table** - If you've added the card via admin panel
2. **Direct ID Match** - If RFID code equals an employee ID
3. **id_code Field** - If you store RFID codes in employee records
4. **Not Found** - Error message if none of the above match

This means you have flexible options for different RFID formats!

---

## Configuration

### Common Settings in attendance.js:

#### Change to "Time Out" instead of "Time In":
Find this line in `processRFIDData` function (around line 210):
```javascript
// Change this:
const inBtn = segBtns.find(btn => btn.getAttribute('data-value') === 'in');

// To this:
const outBtn = segBtns.find(btn => btn.getAttribute('data-value') === 'out');
```

#### Change Auto-Submit Delay:
Find this line in `processRFIDData` function (around line 230):
```javascript
// Change 200 to milliseconds (1000 = 1 second):
}, 200); // Currently 200ms
```

### Database Setup:

Add RFID codes to employee records:
```sql
-- Option 1: Store in id_code field
UPDATE employees SET id_code = '5678901234567890' WHERE id = 1001;

-- Option 2: Use RFID mapping table
INSERT INTO rfid_mapping (rfid_code, employee_id) 
VALUES ('5678901234567890', 1001);
```

---

## Testing

### Test 1: Manual Entry
1. Open: `http://localhost/timeclock/`
2. Click "Time In"
3. Enter an employee ID manually
4. Click GO
5. Should show employee profile ✓

### Test 2: RFID Scanning
1. Click "Time In"
2. Tap RFID card near scanner
3. Employee ID should auto-populate ✓
4. Form should auto-submit ✓
5. Should show employee profile ✓

### Test 3: Admin Panel
1. Open: `http://localhost/timeclock/rfid_admin.php?auth=admin123`
2. Scan card in the input field
3. Select employee from dropdown
4. Click "Add RFID Card"
5. Should appear in the table below ✓

---

## Troubleshooting

### Problem: RFID Code Appears in Manual Input Field
**Solution**: The RFID input field lost focus. The system auto-focuses it, but try:
- Restart the browser tab
- Check browser console (F12 → Console) for errors

### Problem: "RFID Code Not Recognized"
**Solution**: 
1. Check you've added the RFID code via admin panel
2. Verify the employee exists
3. Try the rfid_lookup.php endpoint directly:
   ```
   http://localhost/timeclock/rfid_lookup.php?rfid_code=YOUR_CODE_HERE
   ```

### Problem: Scanner Not Working
**Solution**:
1. Test in Notepad - tap card, does it type the code?
2. If yes: Scanner is fine, check browser console (F12)
3. If no: Check scanner is powered on and in HID/Keyboard mode

### Problem: Can't Access Admin Panel
**Solution**:
1. Make sure to add the auth parameter: `?auth=admin123`
2. Change 'admin123' to your configured password
3. Check you're accessing from the correct URL

### Problem: Form Not Auto-Submitting
**Solution**:
1. Check browser console (F12) for JavaScript errors
2. Make sure Employee ID is numeric only
3. Try clicking "Time In" button first, then tapping card
4. Verify employee exists in database

---

## Security Notes

⚠️ **Important for Production:**

1. **Change Admin Password**: Edit `rfid_admin.php` line 9
   ```php
   || (isset($_GET['auth']) && $_GET['auth'] === 'YOUR_SECURE_PASSWORD')
   ```

2. **Use HTTPS**: For production, use HTTPS instead of HTTP
   - Updates the camera requirement notice automatically

3. **Database**: Ensure database user has appropriate permissions
   - Needs SELECT, INSERT, UPDATE, DELETE on rfid_mapping table

4. **RFID Code Privacy**: RFID codes are sent via POST (not in URL)
   - Browser DevTools can still see them, so use HTTPS

---

## File Reference

| File | Purpose |
|------|---------|
| `rfid_lookup.php` | API endpoint to look up employee IDs from RFID codes |
| `rfid_admin.php` | Web panel for managing RFID mappings |
| `rfid_setup.sql` | SQL to create rfid_mapping table |
| `rfid_setup.bat` | Windows setup script |
| `rfid_setup.sh` | Linux/Mac setup script |
| `RFID_SETUP.md` | Detailed technical documentation |
| `README_RFID.txt` | This file |

---

## Next Steps

1. **Connect your RFID scanner**
2. **Choose your setup option** (A, B, or C above)
3. **Add RFID cards** via the admin panel
4. **Test with one employee**
5. **Deploy to all kiosks**

---

## Need Help?

Check:
- Browser console: F12 → Console
- Network tab: F12 → Network (check rfid_lookup.php requests)
- Database: Verify rfid_mapping table exists and has your cards
- RFID_SETUP.md for advanced configuration

Good luck! 🎉
