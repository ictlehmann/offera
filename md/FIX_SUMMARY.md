# Fix Summary: Missing Column `is_archived_in_easyverein`

## Problem
The EasyVerein synchronization was failing with the error:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'is_archived_in_easyverein' in 'field list'
```

This error occurred because the code was referencing a database column that didn't exist in the `inventory_items` table.

## Root Cause
The `is_archived_in_easyverein` column was being used in multiple places in the code:
- `includes/services/EasyVereinSync.php` (lines 239, 282, 331, 341, 352)
- `pages/inventory/index.php` (lines 331, 333, 361)
- `includes/models/Inventory.php` (SELECT queries)

However, the column was never added to the database schema.

## Solution
Added the missing column to the database schema with the following changes:

### 1. Updated Schema File (`sql/dbs15161271.sql`)
Added the column definition to the `inventory_items` table:
```sql
`is_archived_in_easyverein` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag indicating if item is archived in EasyVerein',
```

Also added an index for better query performance:
```sql
INDEX `idx_is_archived_in_easyverein` (`is_archived_in_easyverein`)
```

### 2. Updated Schema Update Script (`update_database_schema.php`)
Added migration commands to add the column to existing databases:
```php
// Add is_archived_in_easyverein column to inventory_items table
executeSql(
    $content_db,
    "ALTER TABLE inventory_items ADD COLUMN is_archived_in_easyverein BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag indicating if item is archived in EasyVerein'",
    "Add is_archived_in_easyverein column to inventory_items table"
);

// Add index for is_archived_in_easyverein column
executeSql(
    $content_db,
    "ALTER TABLE inventory_items ADD INDEX idx_is_archived_in_easyverein (is_archived_in_easyverein)",
    "Add index for is_archived_in_easyverein column"
);
```

### 3. Updated Inventory Model (`includes/models/Inventory.php`)
Updated the `getAll()` and `getById()` methods to include the new column in SELECT queries:
```php
i.is_archived_in_easyverein,
```

## Deployment Instructions

To apply this fix to your database, run:

```bash
php update_database_schema.php
```

This script is safe to run multiple times - it will skip columns that already exist.

## What This Column Does

The `is_archived_in_easyverein` column is used to track items that:
- Exist locally with an `easyverein_id`
- Are no longer present in the EasyVerein API response
- Should be marked as archived (soft delete)

When set to `1`, the item:
- Appears with reduced opacity in the UI
- Shows an archive badge
- Is filtered from certain views
- Preserves historical checkout/rental data

## Testing

After applying the database update, the EasyVerein sync should work without errors. Items that are no longer in EasyVerein will be properly marked as archived instead of causing database errors.

## Files Modified

1. `sql/dbs15161271.sql` - Schema definition
2. `update_database_schema.php` - Migration script
3. `includes/models/Inventory.php` - Model queries

## Impact

- **Zero breaking changes** - Default value is `0` (not archived)
- **Backward compatible** - Existing items will have `is_archived_in_easyverein = 0`
- **No data loss** - Soft delete preserves all item data
- **Performance optimized** - Indexed for fast queries
