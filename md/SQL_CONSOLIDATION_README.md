# SQL Schema Consolidation - Migration Guide

## Overview

This document describes the SQL schema consolidation completed to fix "Column not found" errors and clean up the database migration files.

## What Was Done

All small migration files (`add_*.sql`) have been consolidated into the three main definitive schema files:

1. **User Database (`sql/dbs15253086.sql`)** - User authentication and profiles
2. **Content Database (`sql/dbs15161271.sql`)** - Events, projects, blog posts, inventory, etc.
3. **Invoice Database (`sql/dbs15251284.sql`)** - Invoice management (no changes needed)

## Consolidated Changes

### User Database (dbs15253086)

The following columns were added to the `users` table:

- **`azure_roles`** (JSON) - Stores original Microsoft Entra ID roles from Azure AD authentication
- **`show_birthday`** (BOOLEAN) - Controls whether to display birthday publicly on profile
- **`deleted_at`** (DATETIME) - Timestamp for soft deletes (NULL = active user)
- **`last_reminder_sent_at`** (DATETIME) - Timestamp of last profile reminder email

### Content Database (dbs15161271)

The following additions were made:

**New Table:**
- **`event_financial_stats`** - Tracks financial statistics for events with yearly comparison support

**New Columns:**
- **`alumni_profiles.secondary_email`** (VARCHAR) - Optional secondary email for profile display
- **`polls.microsoft_forms_url`** (TEXT) - Microsoft Forms integration URL
- **`event_documentation.sellers_data`** (JSON) - Seller information for events

## How to Deploy

### Step 1: Update Existing Database

Run the update script to add the missing columns and tables to your existing database:

```bash
php update_database_schema.php
```

This script will:
- Add all missing columns to existing tables
- Create the `event_financial_stats` table
- Skip columns/tables that already exist
- Provide detailed output of all operations

### Step 2: For New Installations

For fresh database installations, simply run the three main schema files in order:

```bash
# User database
mysql -u username -p dbs15253086 < sql/dbs15253086.sql

# Content database
mysql -u username -p dbs15161271 < sql/dbs15161271.sql

# Invoice database (if needed)
mysql -u username -p dbs15251284 < sql/dbs15251284.sql
```

## Removed Files

The following migration files have been removed as their changes are now in the main schema files:

- `sql/add_azure_roles_column.sql` → Consolidated into `dbs15253086.sql`
- `sql/add_tfa_secret_column.sql` → Already present in `dbs15253086.sql`
- `sql/add_profile_complete_flag.sql` → Already present in `dbs15253086.sql`
- `sql/add_user_deleted_at_column.sql` → Consolidated into `dbs15253086.sql`
- `sql/add_user_last_reminder_column.sql` → Consolidated into `dbs15253086.sql`
- `sql/add_secondary_email_and_show_birthday.sql` → Consolidated into both schema files
- `sql/add_event_financial_stats_table.sql` → Consolidated into `dbs15161271.sql`
- `sql/add_microsoft_forms_url.sql` → Consolidated into `dbs15161271.sql`
- `sql/add_sellers_data_to_event_documentation.sql` → Consolidated into `dbs15161271.sql`

## Verification

After running the update script, verify the changes:

```sql
-- Check users table
DESCRIBE dbs15253086.users;

-- Check alumni_profiles table
DESCRIBE dbs15161271.alumni_profiles;

-- Check polls table
DESCRIBE dbs15161271.polls;

-- Check event_documentation table
DESCRIBE dbs15161271.event_documentation;

-- Check event_financial_stats table
DESCRIBE dbs15161271.event_financial_stats;
```

## Troubleshooting

### "Duplicate column" errors
These are expected and will be skipped automatically by the update script.

### "Table already exists" errors
These are expected and will be skipped automatically by the update script.

### Foreign key constraint errors
Ensure the referenced tables exist before creating tables with foreign keys. The script creates tables in the correct order.

### Permission errors
Ensure your database user has ALTER TABLE and CREATE TABLE privileges:

```sql
GRANT ALTER, CREATE ON dbs15253086.* TO 'your_user'@'localhost';
GRANT ALTER, CREATE ON dbs15161271.* TO 'your_user'@'localhost';
```

## Benefits of Consolidation

1. **Single Source of Truth** - All schema definitions in three main files
2. **Easier Maintenance** - No need to track multiple migration files
3. **Faster Setup** - New installations can run just three files
4. **Better Documentation** - Schema is self-documenting
5. **Reduced Errors** - Eliminates "Column not found" errors from missing migrations

## Related Documentation

- `EVENT_FINANCIAL_STATS_README.md` - Details on the financial stats feature
- `PROFILE_REMINDERS_README.md` - Information about profile reminders
- `IMPLEMENTATION_SUMMARY.md` - Overall system architecture

## Support

For issues or questions, please consult the existing documentation or contact the development team.
