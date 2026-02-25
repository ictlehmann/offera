# Troubleshooting Guide

## Common Issues and Solutions

### 1. Database Column Errors

#### Error: "Column not found: 1054 Unknown column 'p.is_active'"
**Cause**: The database schema is out of date and missing required columns.

**Solution**: Run the database migration script:
```bash
cd /path/to/project
php update_database_schema.php
```

Then verify:
```bash
php verify_database_schema.php
```

**See also**: [QUICKFIX.md](QUICKFIX.md) for detailed step-by-step instructions.

---

### 2. JavaScript "Uncaught SyntaxError: Unexpected token 'export'"

#### Possible Causes:
1. **Browser Cache**: Old JavaScript files cached in browser
2. **Browser Extensions**: Ad blockers or other extensions interfering with scripts
3. **CDN Issues**: Temporary issues with Tailwind CSS CDN (https://cdn.tailwindcss.com)
4. **Network Issues**: Corporate proxy or firewall blocking/modifying CDN content

#### Solutions:

**A. Clear Browser Cache**
- Chrome/Edge: Ctrl+Shift+Delete (Cmd+Shift+Delete on Mac)
- Select "Cached images and files"
- Click "Clear data"

**B. Hard Refresh**
- Windows: Ctrl+F5 or Ctrl+Shift+R
- Mac: Cmd+Shift+R

**C. Try Different Browser**
- Test in Chrome, Firefox, or Edge
- Try incognito/private mode (disables extensions)

**D. Disable Browser Extensions**
- Disable ad blockers and other extensions
- Refresh the page

**E. Check Browser Console**
1. Press F12 to open Developer Tools
2. Go to Console tab
3. Look for specific error with file name
4. If error mentions a specific CDN URL, that CDN may be having issues

**F. Check Network**
- Verify CDN URLs are accessible:
  - https://cdn.tailwindcss.com
  - https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css
- Check if corporate proxy/firewall is blocking or modifying content

**Note**: The codebase itself does NOT contain any ES6 module export statements. This error is typically from external sources.

---

### 3. CSS Not Loading or Displaying Incorrectly

#### Symptoms:
- Page looks unstyled or broken
- Missing colors, layouts, or spacing
- Console errors about CSS files

#### Solutions:

**A. Clear Browser Cache**
Same as JavaScript issue above.

**B. Check CSS Files**
Verify these files exist and are accessible:
```
/assets/css/theme.css
```

**C. Check Asset URLs**
1. Open browser Developer Tools (F12)
2. Go to Network tab
3. Refresh page (Ctrl+R or Cmd+R)
4. Check if CSS files load with 200 status
5. Check if CDN URLs load:
   - Tailwind CSS: https://cdn.tailwindcss.com
   - Font Awesome: https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css

**D. Check .htaccess**
Ensure `.htaccess` file exists and is properly configured.

**E. Check File Permissions**
```bash
# CSS files should be readable
chmod 644 assets/css/theme.css

# Directories should be executable
chmod 755 assets/css/
```

**F. Check BASE_URL Setting**
In `.env` file, verify:
```
BASE_URL=https://intra.business-consulting.de
```
Should match your actual domain.

---

### 4. Dashboard Not Loading

#### Check in order:

1. **Database Connection**
   - Verify `.env` file has correct database credentials
   - Test connection: `php verify_database_schema.php`

2. **Schema Issues**
   - Run: `php update_database_schema.php`
   - Verify: `php verify_database_schema.php`

3. **PHP Errors**
   - Check `/logs/` directory for error logs
   - Enable error display (development only):
     ```php
     error_reporting(E_ALL);
     ini_set('display_errors', 1);
     ```

4. **Missing Files**
   - Verify all files from repository are deployed
   - Check `includes/`, `pages/`, `assets/` directories

---

### 5. After Deployment Checklist

Run these commands after every deployment:

```bash
# 1. Update database schema
php update_database_schema.php

# 2. Verify schema
php verify_database_schema.php

# 3. Check file permissions
find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# 4. Clear any caches
rm -rf /tmp/cache/* 2>/dev/null || true

# 5. Check logs
tail -n 50 logs/error.log
```

---

## Getting Help

If issues persist:

1. **Check Error Logs**
   ```bash
   tail -f logs/error.log
   ```

2. **Check PHP Error Log**
   ```bash
   tail -f /var/log/php_errors.log
   # or
   tail -f /var/log/apache2/error.log
   ```

3. **Run Verification**
   ```bash
   php verify_database_schema.php
   ```

4. **Browser Console**
   - Press F12
   - Check Console tab for JavaScript errors
   - Check Network tab for failed requests

5. **Contact Development Team**
   - Provide error messages
   - Provide steps to reproduce
   - Provide browser/environment details

---

## Quick Reference

| Issue | Fix |
|-------|-----|
| Column not found | Run `php update_database_schema.php` |
| JavaScript export error | Clear browser cache, try different browser |
| CSS not loading | Check .htaccess, clear cache, verify CDN access |
| Page not loading | Check logs, verify database connection |
| After deployment | Run update script, verify schema, check permissions |

---

## Related Documentation

- [QUICKFIX.md](QUICKFIX.md) - Quick fix for specific dashboard/polls errors
- [DEPLOYMENT.md](DEPLOYMENT.md) - Full deployment guide
- [README.md](README.md) - Project overview and setup
