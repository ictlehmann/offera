# Profile Reminder Cronjob Implementation

## Overview
This document describes the implementation of the profile reminder cronjob and related improvements to logging and statistics.

## Changes Made

### 1. Profile Reminder Cronjob (`cron/send_profile_reminders.php`)

A new cronjob that sends reminder emails to users whose profiles haven't been updated in over 1 year.

**Features:**
- Queries users with `updated_at` older than 12 months
- Implements idempotency: only sends reminders if `last_reminder_sent_at` is NULL or older than 13 months
- Uses PHPMailer via MailService for sending emails
- Updates `last_reminder_sent_at` timestamp after successful email sending
- Logs all execution details to `system_logs` table
- 100ms delay between emails to avoid overwhelming SMTP server

**Database Changes Required:**
- Run migration: `sql/add_user_last_reminder_column.sql`
  - Adds `last_reminder_sent_at` column to users table
  - Adds index for performance

**Usage:**
```bash
php cron/send_profile_reminders.php
```

**Scheduling (Crontab):**
```bash
# Run once per week on Monday at 9:00 AM
0 9 * * 1 php /path/to/offer/cron/send_profile_reminders.php
```

### 2. Audit Logging for Login Attempts (`src/Auth.php`)

Enhanced the login function to log ALL login attempts to the `system_logs` table.

**What is logged:**
- **Successful logins**: timestamp, IP address, user agent, user ID, email
- **Failed password attempts**: timestamp, IP address, user agent, email, reason
- **Failed 2FA attempts**: timestamp, IP address, user agent, user ID, email, reason

**Log Actions:**
- `login_success` - Successful login
- `login_failed` - Failed password or account locked
- `login_failed_2fa` - Failed 2FA verification

**Database Table:**
Uses existing `system_logs` table in content database with columns:
- `user_id` - User ID (NULL for failed login before user identification)
- `action` - Login action type
- `entity_type` - Set to 'login'
- `details` - Email, status, and additional details
- `ip_address` - Client IP address
- `user_agent` - Browser user agent string
- `timestamp` - Automatic timestamp

### 3. Statistics Correction (`pages/admin/stats.php`)

Updated all user COUNT queries to exclude deleted users.

**Database Changes Required:**
- Run migration: `sql/add_user_deleted_at_column.sql`
  - Adds `deleted_at` column to users table for soft deletes
  - Adds index for performance

**Queries Updated:**
1. Active Users (7 Days) - Added `WHERE deleted_at IS NULL`
2. Active Users Trend - Added `WHERE deleted_at IS NULL`
3. Total User Count - Added `WHERE deleted_at IS NULL`
4. New Users - Added `WHERE deleted_at IS NULL`
5. Recent Activity - Added `WHERE deleted_at IS NULL`
6. User Info Lookup - Added `WHERE deleted_at IS NULL`

**Soft Delete Implementation:**
- `deleted_at = NULL` means the user is active
- `deleted_at = timestamp` means the user is soft deleted
- Soft deleted users are excluded from all statistics

## Installation & Setup

### Step 1: Run Database Migrations

Execute the following SQL files on your user database:

```bash
mysql -u username -p dbs15253086 < sql/add_user_last_reminder_column.sql
mysql -u username -p dbs15253086 < sql/add_user_deleted_at_column.sql
```

### Step 2: Verify Columns

Check that the columns were added:

```sql
SHOW COLUMNS FROM users LIKE 'last_reminder_sent_at';
SHOW COLUMNS FROM users LIKE 'deleted_at';
```

### Step 3: Test the Cronjob

Run the cronjob manually to test:

```bash
php cron/send_profile_reminders.php
```

### Step 4: Schedule the Cronjob

Add to your crontab:

```bash
# Profile reminders - Weekly on Monday at 9:00 AM
0 9 * * 1 cd /path/to/offer && php cron/send_profile_reminders.php >> logs/cron_profile_reminders.log 2>&1
```

### Step 5: Test Login Logging

1. Attempt a successful login
2. Attempt a failed login
3. Check the `system_logs` table for entries:

```sql
SELECT * FROM system_logs WHERE action LIKE 'login_%' ORDER BY timestamp DESC LIMIT 10;
```

## Testing

### Test Profile Reminder Cronjob

1. Manually set a user's `updated_at` to 13 months ago
2. Ensure `last_reminder_sent_at` is NULL
3. Run the cronjob
4. Verify email was sent
5. Check that `last_reminder_sent_at` is now set

### Test Login Logging

1. **Test failed login:**
   - Try logging in with wrong password
   - Check system_logs for `login_failed` entry
   - Verify IP and user agent are logged

2. **Test successful login:**
   - Log in with correct credentials
   - Check system_logs for `login_success` entry
   - Verify all details are logged

3. **Test failed 2FA:**
   - Enable 2FA on a test account
   - Enter wrong 2FA code
   - Check system_logs for `login_failed_2fa` entry

### Test Statistics

1. Create a test user with `deleted_at = NOW()`
2. Verify the user is NOT counted in stats
3. Set `deleted_at = NULL`
4. Verify the user IS counted in stats

## Security Considerations

1. **Email Rate Limiting**: The cronjob includes a 100ms delay between emails to prevent SMTP abuse
2. **Spam Protection**: Reminders are only sent once per 13 months minimum
3. **Login Logging**: All login attempts are logged for security auditing
4. **IP Tracking**: IP addresses are logged for failed login attempts to detect brute force attacks
5. **Soft Deletes**: Deleted users are excluded from statistics but data is retained for audit purposes

## Monitoring

Check the `system_logs` table regularly for:
- Cronjob execution logs (`cron_profile_reminders`)
- Failed login attempts (`login_failed`)
- Failed 2FA attempts (`login_failed_2fa`)
- Successful logins (`login_success`)

Query example:
```sql
-- Last 24 hours of login activity
SELECT user_id, action, details, ip_address, timestamp 
FROM system_logs 
WHERE action LIKE 'login_%' 
AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY timestamp DESC;

-- Profile reminder cron execution
SELECT * FROM system_logs 
WHERE action = 'cron_profile_reminders' 
ORDER BY timestamp DESC 
LIMIT 10;
```

## Troubleshooting

### Cronjob Not Sending Emails

1. Check SMTP configuration in `.env`
2. Check cronjob logs for errors
3. Verify database connection
4. Check that users meet the criteria (updated_at > 12 months, last_reminder_sent_at NULL or > 13 months)

### Login Logs Not Appearing

1. Verify `system_logs` table exists in content database
2. Check error logs for database connection issues
3. Verify Database::getContentDB() is working

### Statistics Still Showing Deleted Users

1. Verify `deleted_at` column exists
2. Check that soft delete is being used (not hard delete)
3. Clear any caches

## Files Modified

- `src/Auth.php` - Added login audit logging
- `pages/admin/stats.php` - Added deleted_at filters to all user queries
- `cron/send_profile_reminders.php` - New cronjob (created)
- `sql/add_user_last_reminder_column.sql` - New migration (created)
- `sql/add_user_deleted_at_column.sql` - New migration (created)

## Author

Implementation completed as per requirements in issue description.
