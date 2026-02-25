# EasyVereinSync Service

The `EasyVereinSync` service provides one-way synchronization from EasyVerein (external system) to the local Intranet inventory management system.

## Location

`includes/services/EasyVereinSync.php`

## Purpose

This service handles the synchronization of inventory items from EasyVerein to the local database, ensuring that:
- New items from EasyVerein are created locally
- Existing items are updated with latest data from EasyVerein
- Items removed from EasyVerein are marked as archived (soft delete)
- Local operational data is preserved during sync

## Key Features

### 1. Master Data Synchronization
The sync overwrites the following master data fields from EasyVerein:
- `name` - Item name
- `description` - Item description
- `current_stock` - Total quantity/stock
- `serial_number` - Serial number

### 2. Local Data Preservation
The sync DOES NOT overwrite local operational fields:
- `location_id` - Physical location (managed locally)
- `category_id` - Category assignment (managed locally)
- Other local-only fields

### 3. Soft Delete (Archival)
Items that exist locally with an `easyverein_id` but are no longer in the EasyVerein response are marked as archived:
- Sets `is_archived_in_easyverein = 1`
- Does NOT hard delete to preserve checkout history
- Updates `last_synced_at` timestamp

### 4. Audit Trail
All synchronization actions are logged in the `inventory_history` table:
- `sync_create` - New item created from EasyVerein
- `sync_update` - Existing item updated from EasyVerein
- `sync_archive` - Item marked as archived (no longer in EasyVerein)

## Methods

### fetchDataFromEasyVerein()

**Mock Implementation**: Returns hardcoded JSON array of inventory items.

```php
public function fetchDataFromEasyVerein(): array
```

**Returns**: Array of items with structure:
```php
[
    'EasyVereinID' => string,    // Unique ID from EasyVerein
    'Name' => string,            // Item name
    'Description' => string,     // Item description
    'TotalQuantity' => int,      // Total stock quantity
    'SerialNumber' => ?string    // Serial number (nullable)
]
```

**Note**: In production, this method should be replaced with actual API calls to EasyVerein.

### sync()

Performs the synchronization operation.

```php
public function sync(?int $userId = null): array
```

**Parameters**:
- `$userId` (optional) - User ID performing the sync. Defaults to 0 (system) if not provided.

**Returns**: Statistics array with keys:
- `created` (int) - Number of items created
- `updated` (int) - Number of items updated
- `archived` (int) - Number of items archived
- `errors` (array) - Array of error messages (empty if successful)

## Usage Example

```php
<?php
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/services/EasyVereinSync.php';

// Create sync service instance
$sync = new EasyVereinSync();

// Perform synchronization
$userId = 1; // ID of user performing the sync
$result = $sync->sync($userId);

// Display results
echo "Created: {$result['created']}\n";
echo "Updated: {$result['updated']}\n";
echo "Archived: {$result['archived']}\n";

if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach ($result['errors'] as $error) {
        echo "  - $error\n";
    }
}
```

## Database Requirements

The following database schema changes are required (applied via `apply_easyverein_migration.php`):

```sql
-- Add easyverein_id column for mapping
ALTER TABLE inventory 
ADD COLUMN easyverein_id VARCHAR(100) DEFAULT NULL;

-- Add unique constraint
ALTER TABLE inventory
ADD UNIQUE INDEX idx_easyverein_id (easyverein_id);

-- Add last_synced_at timestamp
ALTER TABLE inventory 
ADD COLUMN last_synced_at DATETIME DEFAULT NULL;

-- Add archival flag
ALTER TABLE inventory 
ADD COLUMN is_archived_in_easyverein BOOLEAN NOT NULL DEFAULT 0;
```

## Synchronization Logic

### Create New Items
When an item from EasyVerein doesn't exist locally (no matching `easyverein_id`):
1. Insert new record in `inventory` table
2. Set `easyverein_id`, `name`, `description`, `serial_number`, `current_stock`
3. Set `last_synced_at` to current timestamp
4. Log action as `sync_create` in `inventory_history`

### Update Existing Items
When an item from EasyVerein exists locally (matching `easyverein_id`):
1. Update `name`, `description`, `serial_number`, `current_stock` from EasyVerein
2. Set `is_archived_in_easyverein = 0` (un-archive if previously archived)
3. Update `last_synced_at` to current timestamp
4. Preserve `location_id`, `category_id`, and other local fields
5. Log action as `sync_update` in `inventory_history`

### Archive Deleted Items
When a local item has an `easyverein_id` but is NOT in the EasyVerein response:
1. Set `is_archived_in_easyverein = 1`
2. Update `last_synced_at` to current timestamp
3. Do NOT delete the record (preserve for history)
4. Log action as `sync_archive` in `inventory_history`

## Error Handling

The sync method uses comprehensive error handling:
- Individual item errors are caught and logged, allowing sync to continue
- All errors are returned in the `errors` array
- Database transactions ensure data consistency
- Errors include context (item name, EasyVerein ID)

## Testing

Run the test suite:
```bash
php tests/test_easyverein_sync.php
```

Tests include:
- Class instantiation
- Mock data structure validation
- Data type validation
- Sync method execution (with database)
- Local field preservation verification

## Future Enhancements

When integrating with actual EasyVerein API:
1. Replace `fetchDataFromEasyVerein()` mock implementation with real API calls
2. Add authentication/authorization for API access
3. Implement rate limiting and retry logic
4. Add configuration for API endpoints
5. Consider batch processing for large datasets
6. Add scheduled cron job for automatic sync

## Security Considerations

- All database queries use prepared statements (prevents SQL injection)
- Input data is validated before processing
- No raw user input is directly inserted into queries
- Comprehensive error logging for audit trail
- Soft delete preserves data for investigation

## Related Files

- `includes/services/EasyVereinSync.php` - Main service class
- `tests/test_easyverein_sync.php` - Test suite
- `example_easyverein_sync.php` - Usage example
- `sql/migrations/adapt_inventory_for_easyverein.sql` - Database migration
- `apply_easyverein_migration.php` - Migration application script
