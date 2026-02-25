# Deployment Guide

## Quick Fix for "Column not found: needs_helpers" Error

If you're seeing this error:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'e.needs_helpers' in 'where clause'
```

**Solution:** Run the database schema update script:

```bash
php update_database_schema.php
```

This will add all missing columns and tables to your database.

## Full Deployment Steps

### 1. Pull Latest Code

```bash
git pull origin main
```

### 2. Update Database Schema

**IMPORTANT:** Always run this after pulling new code to ensure your database schema is up to date:

```bash
php update_database_schema.php
```

The script will:
- ✓ Add any missing columns to existing tables
- ✓ Create any missing tables
- ✓ Skip columns/tables that already exist (safe to run multiple times)
- ✓ Provide detailed output of all operations

### 3. Clear Cache (if applicable)

```bash
# Clear PHP opcode cache if using OPcache
# This varies by hosting environment
```

### 4. Verify Deployment

Visit your dashboard to confirm everything works:
- No SQL errors should appear
- All features should function correctly

## For New Installations

For a fresh database setup, run the schema files directly:

```bash
# User database
mysql -u username -p dbs15253086 < sql/dbs15253086.sql

# Content database
mysql -u username -p dbs15161271 < sql/dbs15161271.sql

# Invoice database
mysql -u username -p dbs15251284 < sql/dbs15251284.sql
```

Then run the update script to ensure all recent additions are included:

```bash
php update_database_schema.php
```

## Common Issues

### "Permission denied" when running update script

Ensure your database user has the necessary privileges:

```sql
GRANT ALTER, CREATE, INDEX ON dbs15253086.* TO 'your_user'@'localhost';
GRANT ALTER, CREATE, INDEX ON dbs15161271.* TO 'your_user'@'localhost';
GRANT ALTER, CREATE, INDEX ON dbs15251284.* TO 'your_user'@'localhost';
```

### "Duplicate column" or "Table already exists" errors

These are normal and will be automatically skipped by the update script. They indicate the column or table was already added in a previous run.

### Fatal errors on dashboard after deployment

1. First, run the update script: `php update_database_schema.php`
2. Clear your browser cache
3. Check error logs for specific issues

## Automated Deployment

For automated deployments, include the schema update in your deployment script:

```bash
#!/bin/bash
set -e

echo "Pulling latest code..."
git pull origin main

echo "Updating database schema..."
php update_database_schema.php

echo "Deployment complete!"
```

## Support

For issues or questions:
1. Check the error logs at `/logs/`
2. Review the documentation in `/md/`
3. Contact the development team

## Related Documentation

- `md/SQL_CONSOLIDATION_README.md` - SQL migration guide
- `md/EVENT_FINANCIAL_STATS_README.md` - Financial stats feature
- `md/PROFILE_REMINDERS_README.md` - Profile reminders feature
