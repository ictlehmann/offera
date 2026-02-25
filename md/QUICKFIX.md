# Quick Fix Guide: Dashboard & Polls Errors

## Problems

### Error 1: Dashboard Events Error
```
Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'e.needs_helpers' in 'where clause'
```

### Error 2: Dashboard Polls Error
```
Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'p.is_active' in 'where clause' in /homepages/34/d795569457/htdocs/intra/pages/dashboard/index.php:382
```

### Error 3: Alumni Profile Error
```
Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'first_name' in 'field list' in /homepages/34/d795569457/htdocs/intra/includes/models/Alumni.php:42
```

### Error 4: Content Security Policy (CSP) Violations
```
Loading the stylesheet 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap' violates the following Content Security Policy directive: "style-src 'self' 'unsafe-inline'"
Loading the script 'https://cdn.tailwindcss.com/' violates the following Content Security Policy directive: "script-src 'self' 'unsafe-inline'"
Loading the stylesheet 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' violates the following Content Security Policy directive: "style-src 'self' 'unsafe-inline'"
```

### Error 5: JavaScript Export Error
```
Uncaught SyntaxError: Unexpected token 'export'
```

### Error 6: CSS Issues
CSS styles not loading or displaying incorrectly.

## Solution (4 steps)

### Step 1: Deploy Latest Code
Pull and deploy the latest code from this repository to production.

### Step 2: Run Database Update
On the production server, run:
```bash
cd /path/to/project
php update_database_schema.php
```

You should see output like:
```
Executing: Add needs_helpers column to events table
✓ SUCCESS: Add needs_helpers column to events table

Executing: Add first_name column to alumni_profiles table
✓ SUCCESS: Add first_name column to alumni_profiles table

Executing: Add last_name column to alumni_profiles table
✓ SUCCESS: Add last_name column to alumni_profiles table

... (more alumni_profiles columns)

Executing: Add target_groups column to polls table
✓ SUCCESS: Add target_groups column to polls table

Executing: Add is_active column to polls table
✓ SUCCESS: Add is_active column to polls table

Executing: Add end_date column to polls table
✓ SUCCESS: Add end_date column to polls table
```

### Step 3: Verify
Run the verification script:
```bash
php verify_database_schema.php
```

You should see:
```
✓ All schema checks passed!
Your database schema is up to date.
```

## Step 4: Clear Browser Cache (If JavaScript or CSS Issues Persist)

If you see "Uncaught SyntaxError: Unexpected token 'export'" or CSS styling issues:

1. **Clear Browser Cache**:
   - Chrome/Edge: Press `Ctrl+Shift+Delete` (Windows) or `Cmd+Shift+Delete` (Mac)
   - Select "Cached images and files"
   - Click "Clear data"

2. **Hard Refresh**:
   - Windows: `Ctrl+F5` or `Ctrl+Shift+R`
   - Mac: `Cmd+Shift+R`

3. **Try Incognito/Private Mode**:
   - This disables extensions and uses fresh cache
   - If it works here, the issue is browser cache or extensions

4. **Disable Browser Extensions**:
   - Temporarily disable ad blockers and other extensions
   - Refresh the page

**Note**: The "export" error is typically caused by browser cache or extensions, not the codebase itself.

## Done!
After completing all steps, refresh the dashboard in your browser. All errors should be resolved.

## If You Need Help
1. Check the full troubleshooting guide: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
2. Check the deployment guide: [DEPLOYMENT.md](DEPLOYMENT.md)
3. Check error logs in the `/logs/` directory
4. Contact the development team

## Technical Details

### Events Table Fix
- The `needs_helpers` column was added to the events table schema
- The error handling code already exists (from PR #535)

### Polls Table Fix
- Three missing columns were added to the polls table schema:
  - `target_groups` - JSON array for audience filtering
  - `is_active` - Boolean flag to show/hide polls (default: 1)
  - `end_date` - DATETIME for poll expiration
- Indexes added for `is_active` and `end_date` for query performance
- Fixes dashboard, polls list, poll view, and poll creation pages

### Alumni Profiles Table Fix
- 16 missing columns were added to the alumni_profiles table schema:
  - `first_name`, `last_name` - Basic name fields
  - `mobile_phone` - Contact information
  - `linkedin_url`, `xing_url` - Social/professional profiles
  - `industry`, `company`, `position` - Professional information
  - `study_program`, `semester`, `angestrebter_abschluss`, `degree`, `graduation_year` - Academic information
  - `image_path` - Profile picture storage
  - `last_verified_at`, `last_reminder_sent_at` - Tracking fields
- Fixes Alumni.php model errors and dashboard profile display

### Content Security Policy (CSP) Fix
- Updated CSP headers in `includes/security_headers.php` to allow trusted CDN resources:
  - Google Fonts (`fonts.googleapis.com`, `fonts.gstatic.com`)
  - Tailwind CSS CDN (`cdn.tailwindcss.com`)
  - Font Awesome CDN (`cdnjs.cloudflare.com`)
- Fixes CSS and JavaScript loading issues from CDNs
- Resolves "violates Content Security Policy directive" errors

### General Notes
- Running `update_database_schema.php` adds all missing columns
- The script is safe to run multiple times (skips existing columns)
- All changes are backward compatible
