# SQLSTATE[42S22] Bug Fix Summary

## Issues Reported

The problem statement mentioned three SQLSTATE[42S22] "Column not found" errors:

1. **Event.php - Cross-database JOIN issue** ✅ FIXED
   - Error: `Unknown column 'ap.first_name' in 'field list'`
   - Location: `/includes/models/Event.php:75` (in `getEventAttendees()` method)

2. **Alumni.php - Missing columns** ⚠️ REQUIRES MIGRATION
   - Error: `Unknown column 'first_name' in 'field list'`
   - Location: `/includes/models/Alumni.php:42`

3. **Member.php - Missing columns** ⚠️ REQUIRES MIGRATION
   - Error: `Unknown column 'ap.first_name' in 'field list'`
   - Location: `/includes/models/Member.php:75`

## What Was Fixed

### 1. Event.php - Cross-database JOIN (CODE FIX) ✅

**Problem:**
The `getEventAttendees()` method in Event.php was attempting to perform a SQL JOIN between two tables in different databases:
- `users` table (in the user database)
- `alumni_profiles` table (in the content database)

This is not supported in MySQL when the tables are in different databases.

**Original Code (BROKEN):**
```php
$stmt = $userDb->prepare("
    SELECT u.id as user_id, u.email, ap.first_name, ap.last_name
    FROM users u
    LEFT JOIN alumni_profiles ap ON u.id = ap.user_id
    WHERE u.id IN ($placeholders)
");
```

**Fixed Code:**
```php
// Step 1: Get user emails from user database
$stmt = $userDb->prepare("
    SELECT u.id as user_id, u.email
    FROM users u
    WHERE u.id IN ($placeholders)
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Step 2: Get alumni profiles from content database
$stmt = $contentDb->prepare("
    SELECT user_id, first_name, last_name
    FROM alumni_profiles
    WHERE user_id IN ($placeholders)
");
$profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Step 3: Merge results in PHP
// (see full implementation in Event.php)
```

**Improvements:**
- ✅ Eliminates cross-database JOIN error
- ✅ Adds validation for missing users (defensive programming)
- ✅ Improves sorting behavior to match original SQL (uses email as fallback)
- ✅ Enhances fallback logic for users without profiles

### 2. Alumni.php & Member.php - Missing Columns (MIGRATION REQUIRED) ⚠️

**Problem:**
The `alumni_profiles` table in the production database is missing several columns:
- `first_name`
- `last_name`
- `mobile_phone`
- `linkedin_url`
- `xing_url`
- And many others...

**Solution:**
The existing migration script `update_database_schema.php` already contains all the necessary ALTER TABLE commands to add these columns.

**Action Required:**
Run the migration script on the production server:

```bash
cd /path/to/project
php update_database_schema.php
```

This will add all missing columns to the `alumni_profiles` table and resolve the errors in Alumni.php and Member.php.

## Verification

### Code Quality
- ✅ All PHP files pass syntax check (`php -l`)
- ✅ No other cross-database JOINs found in codebase
- ✅ Code review completed with all feedback addressed
- ✅ Security checks passed (no vulnerabilities found)

### What You Need to Do

1. **Merge this PR** - Contains the code fix for Event.php

2. **Run the migration script** on production:
   ```bash
   php update_database_schema.php
   ```

3. **Verify the database schema**:
   ```bash
   php verify_database_schema.php
   ```

4. **Test the application** - All SQLSTATE[42S22] errors should be resolved

## Technical Details

### Database Architecture
The application uses a multi-database architecture:
- **User Database** (`dbs15253086`): Contains `users` table
- **Content Database** (`dbs15161271`): Contains `alumni_profiles` table
- **Invoice Database** (`dbs15172292`): Contains invoice-related tables

### Why Cross-database JOINs Don't Work
MySQL does not support JOINs between tables in different databases when:
- The databases are on different hosts
- The databases are accessed through different connections
- The user lacks cross-database privileges

The solution is to:
1. Query each database separately
2. Merge the results in application code (PHP)
3. Maintain the same functionality and performance

## Files Changed
- `includes/models/Event.php` - Fixed cross-database JOIN in `getEventAttendees()` method

## No Code Changes Needed For
- `includes/models/Alumni.php` - Already correct, just needs database migration
- `includes/models/Member.php` - Already correct, just needs database migration

## Conclusion

✅ **One code bug fixed** (Event.php cross-database JOIN)
⚠️ **Two deployment issues** (need to run migration script)

After merging this PR and running the migration script, all SQLSTATE[42S22] errors will be resolved.
