<?php
/**
 * Database Schema Update Script
 * 
 * This script executes ALTER TABLE commands to add missing columns and tables
 * to fix SQLSTATE[42S22] "Column not found" errors.
 * 
 * Run this script ONCE after deploying the consolidated schema files.
 * 
 * Usage: php update_database_schema.php
 */

require_once __DIR__ . '/includes/database.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "==============================================\n";
echo "Database Schema Update Script\n";
echo "==============================================\n\n";

// Track success/failure
$success_count = 0;
$error_count = 0;
$errors = [];

/**
 * Execute a SQL statement safely
 */
function executeSql($pdo, $sql, $description) {
    global $success_count, $error_count, $errors;
    
    try {
        echo "Executing: $description\n";
        $pdo->exec($sql);
        echo "✓ SUCCESS: $description\n\n";
        $success_count++;
        return true;
    } catch (PDOException $e) {
        // Ignore "Duplicate column", "Table already exists", and "Table doesn't exist" errors
        if (strpos($e->getMessage(), 'Duplicate column') !== false || 
            strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), "doesn't exist") !== false) {
            echo "⚠ SKIPPED: $description (already applied or not applicable)\n\n";
            $success_count++;
            return true;
        }
        
        echo "✗ ERROR: $description\n";
        echo "   Message: " . $e->getMessage() . "\n\n";
        $error_count++;
        $errors[] = [
            'description' => $description,
            'error' => $e->getMessage()
        ];
        return false;
    }
}

try {
    // ============================================
    // USER DATABASE UPDATES (dbs15253086)
    // ============================================
    echo "--- USER DATABASE UPDATES ---\n";
    
    $user_db = Database::getUserDB();
    
    // Add azure_roles column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN azure_roles JSON DEFAULT NULL COMMENT 'Original Microsoft Entra ID roles from Azure AD authentication'",
        "Add azure_roles column to users table"
    );
    
    // Add azure_oid column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN azure_oid VARCHAR(255) DEFAULT NULL COMMENT 'Azure Object Identifier (OID) from Microsoft Entra ID authentication' AFTER azure_roles",
        "Add azure_oid column to users table"
    );
    
    // Add index for azure_oid
    executeSql(
        $user_db,
        "ALTER TABLE users ADD INDEX idx_azure_oid (azure_oid)",
        "Add index for azure_oid column"
    );
    
    // Add deleted_at column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL COMMENT 'Timestamp when the user was soft deleted (NULL = active)'",
        "Add deleted_at column to users table"
    );
    
    // Add index for deleted_at
    executeSql(
        $user_db,
        "ALTER TABLE users ADD INDEX idx_deleted_at (deleted_at)",
        "Add index for deleted_at column"
    );
    
    // Add last_reminder_sent_at column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN last_reminder_sent_at DATETIME DEFAULT NULL COMMENT 'Timestamp when the last profile reminder email was sent to the user'",
        "Add last_reminder_sent_at column to users table"
    );
    
    // Add index for last_reminder_sent_at
    executeSql(
        $user_db,
        "ALTER TABLE users ADD INDEX idx_last_reminder_sent_at (last_reminder_sent_at)",
        "Add index for last_reminder_sent_at column"
    );
    
    // Add show_birthday column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN show_birthday BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether to display birthday publicly on profile' AFTER birthday",
        "Add show_birthday column to users table"
    );
    
    // Add user_type column to users table
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN user_type ENUM('member', 'guest') DEFAULT NULL COMMENT 'Microsoft Entra ID user type: member (internal) or guest (external/invited)' AFTER azure_oid",
        "Add user_type column to users table"
    );
    
    // Add current_session_id column to users table for single-session enforcement
    executeSql(
        $user_db,
        "ALTER TABLE users ADD COLUMN current_session_id VARCHAR(255) DEFAULT NULL COMMENT 'Active session ID for single-session enforcement; NULL if no active session'",
        "Add current_session_id column to users table"
    );

    // Drop invitation_tokens table (token-based registration removed)
    executeSql(
        $user_db,
        "DROP TABLE IF EXISTS invitation_tokens",
        "Drop invitation_tokens table"
    );
    
    // ============================================
    // CONTENT DATABASE UPDATES (dbs15161271)
    // ============================================
    echo "\n--- CONTENT DATABASE UPDATES ---\n";
    
    $content_db = Database::getContentDB();
    
    // Add first_name column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN first_name VARCHAR(100) DEFAULT NULL",
        "Add first_name column to alumni_profiles table"
    );
    
    // Add last_name column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN last_name VARCHAR(100) DEFAULT NULL",
        "Add last_name column to alumni_profiles table"
    );
    
    // Add secondary_email column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN secondary_email VARCHAR(255) DEFAULT NULL COMMENT 'Optional secondary email address for profile display only'",
        "Add secondary_email column to alumni_profiles table"
    );
    
    // Add mobile_phone column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN mobile_phone VARCHAR(50) DEFAULT NULL",
        "Add mobile_phone column to alumni_profiles table"
    );
    
    // Add linkedin_url column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN linkedin_url VARCHAR(255) DEFAULT NULL",
        "Add linkedin_url column to alumni_profiles table"
    );
    
    // Add xing_url column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN xing_url VARCHAR(255) DEFAULT NULL",
        "Add xing_url column to alumni_profiles table"
    );
    
    // Add industry column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN industry VARCHAR(100) DEFAULT NULL",
        "Add industry column to alumni_profiles table"
    );
    
    // Add company column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN company VARCHAR(255) DEFAULT NULL",
        "Add company column to alumni_profiles table"
    );
    
    // Add position column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN position VARCHAR(255) DEFAULT NULL",
        "Add position column to alumni_profiles table"
    );
    
    // Add study_program column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN study_program VARCHAR(255) DEFAULT NULL",
        "Add study_program column to alumni_profiles table"
    );
    
    // Add semester column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN semester VARCHAR(50) DEFAULT NULL",
        "Add semester column to alumni_profiles table"
    );
    
    // Add angestrebter_abschluss column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN angestrebter_abschluss VARCHAR(100) DEFAULT NULL",
        "Add angestrebter_abschluss column to alumni_profiles table"
    );
    
    // Add degree column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN degree VARCHAR(100) DEFAULT NULL",
        "Add degree column to alumni_profiles table"
    );
    
    // Add graduation_year column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN graduation_year INT DEFAULT NULL",
        "Add graduation_year column to alumni_profiles table"
    );
    
    // Add image_path column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN image_path VARCHAR(500) DEFAULT NULL",
        "Add image_path column to alumni_profiles table"
    );
    
    // Add last_verified_at column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN last_verified_at DATETIME DEFAULT NULL",
        "Add last_verified_at column to alumni_profiles table"
    );
    
    // Add last_reminder_sent_at column to alumni_profiles
    executeSql(
        $content_db,
        "ALTER TABLE alumni_profiles ADD COLUMN last_reminder_sent_at DATETIME DEFAULT NULL",
        "Add last_reminder_sent_at column to alumni_profiles table"
    );
    
    // Add microsoft_forms_url column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN microsoft_forms_url TEXT DEFAULT NULL COMMENT 'Microsoft Forms embed URL or direct link for external survey integration'",
        "Add microsoft_forms_url column to polls table"
    );
    
    // Add visible_to_all column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN visible_to_all BOOLEAN NOT NULL DEFAULT 0 COMMENT 'If true, show poll to all users regardless of roles'",
        "Add visible_to_all column to polls table"
    );
    
    // Add is_internal column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN is_internal BOOLEAN NOT NULL DEFAULT 1 COMMENT 'If true, hide poll after user votes. If false (external Forms), show hide button'",
        "Add is_internal column to polls table"
    );
    
    // Add allowed_roles column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN allowed_roles JSON DEFAULT NULL COMMENT 'JSON array of Entra roles that can see this poll (filters against user azure_roles)'",
        "Add allowed_roles column to polls table"
    );
    
    // Add target_groups column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN target_groups JSON DEFAULT NULL COMMENT 'JSON array of target groups (candidate, alumni_board, board, member, head)'",
        "Add target_groups column to polls table"
    );
    
    // Add is_active column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Flag to activate/deactivate poll display'",
        "Add is_active column to polls table"
    );
    
    // Add end_date column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN end_date DATETIME DEFAULT NULL COMMENT 'Poll expiration date'",
        "Add end_date column to polls table"
    );
    
    // Add index for is_active column
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD INDEX idx_is_active (is_active)",
        "Add index for is_active column"
    );
    
    // Add index for end_date column
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD INDEX idx_end_date (end_date)",
        "Add index for end_date column"
    );
    
    // Create poll_hidden_by_user table
    $create_poll_hidden_table = "
    CREATE TABLE IF NOT EXISTS poll_hidden_by_user (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        UNIQUE KEY unique_poll_user (poll_id, user_id),
        INDEX idx_poll_id (poll_id),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Tracks which users have manually hidden which polls'
    ";
    
    executeSql(
        $content_db,
        $create_poll_hidden_table,
        "Create poll_hidden_by_user table"
    );
    
    // Add sellers_data column to event_documentation table
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN sellers_data JSON DEFAULT NULL COMMENT 'JSON array of seller entries with name, items, quantity, and revenue'",
        "Add sellers_data column to event_documentation table"
    );
    
    // Add sales_data column to event_documentation table
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN sales_data JSON DEFAULT NULL COMMENT 'JSON array of sales entries with items and revenue'",
        "Add sales_data column to event_documentation table"
    );
    
    // Add calculations column to event_documentation table
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN calculations TEXT DEFAULT NULL COMMENT 'Calculation notes and formulas'",
        "Add calculations column to event_documentation table"
    );
    
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
    
    // Add created_by and updated_by columns to event_documentation table
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN created_by INT UNSIGNED DEFAULT NULL COMMENT 'User who created the documentation'",
        "Add created_by column to event_documentation table"
    );
    
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL COMMENT 'User who last updated the documentation'",
        "Add updated_by column to event_documentation table"
    );
    
    // Add needs_helpers column to events table
    executeSql(
        $content_db,
        "ALTER TABLE events ADD COLUMN needs_helpers BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag indicating if the event needs helpers'",
        "Add needs_helpers column to events table"
    );
    
    // Add index for needs_helpers column
    executeSql(
        $content_db,
        "ALTER TABLE events ADD INDEX idx_needs_helpers (needs_helpers)",
        "Add index for needs_helpers column"
    );
    
    // Add contact_person column to events table
    executeSql(
        $content_db,
        "ALTER TABLE events ADD COLUMN contact_person VARCHAR(255) NULL COMMENT 'Contact person for the event'",
        "Add contact_person column to events table"
    );
    
    // Create event_financial_stats table
    $create_financial_stats_table = "
    CREATE TABLE IF NOT EXISTS event_financial_stats (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        category ENUM('Verkauf', 'Kalkulation') NOT NULL COMMENT 'Category: Sales or Calculation',
        item_name VARCHAR(255) NOT NULL COMMENT 'Item name, e.g., Brezeln, Äpfel, Grillstand',
        quantity INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity sold or calculated',
        revenue DECIMAL(10, 2) DEFAULT NULL COMMENT 'Revenue in EUR (optional for calculations)',
        record_year YEAR NOT NULL COMMENT 'Year of record for historical comparison',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by INT UNSIGNED NOT NULL COMMENT 'User who created the record',
        
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        
        INDEX idx_event_id (event_id),
        INDEX idx_category (category),
        INDEX idx_record_year (record_year),
        INDEX idx_event_year (event_id, record_year),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Financial statistics for events - tracks sales and calculations with yearly comparison'
    ";
    
    executeSql(
        $content_db,
        $create_financial_stats_table,
        "Create event_financial_stats table"
    );
    
    // Create poll_options table
    $create_poll_options_table = "
    CREATE TABLE IF NOT EXISTS poll_options (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id INT UNSIGNED NOT NULL,
        option_text VARCHAR(500) NOT NULL COMMENT 'Text of the poll option',
        display_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order in which options are displayed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
        
        INDEX idx_poll_id (poll_id),
        INDEX idx_display_order (display_order)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Options/choices for internal polls (not used for Microsoft Forms)'
    ";
    
    executeSql(
        $content_db,
        $create_poll_options_table,
        "Create poll_options table"
    );
    
    // Create poll_votes table
    $create_poll_votes_table = "
    CREATE TABLE IF NOT EXISTS poll_votes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id INT UNSIGNED NOT NULL,
        option_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
        FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
        
        UNIQUE KEY unique_poll_user_vote (poll_id, user_id),
        INDEX idx_poll_id (poll_id),
        INDEX idx_option_id (option_id),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='User votes on poll options (not used for Microsoft Forms)'
    ";
    
    executeSql(
        $content_db,
        $create_poll_votes_table,
        "Create poll_votes table"
    );
    
    // Create event_registrations table
    $create_event_registrations_table = "
    CREATE TABLE IF NOT EXISTS event_registrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        status ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
        registered_at DATETIME NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        
        UNIQUE KEY unique_event_user_registration (event_id, user_id),
        INDEX idx_event_id (event_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Simple event registrations (alternative to event_signups with slots)'
    ";
    
    executeSql(
        $content_db,
        $create_event_registrations_table,
        "Create event_registrations table"
    );
    
    // Create system_logs table
    $create_system_logs_table = "
    CREATE TABLE IF NOT EXISTS system_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL COMMENT 'User who performed the action (0 for system/cron)',
        action VARCHAR(100) NOT NULL COMMENT 'Action type (e.g., login_success, invitation_created)',
        entity_type VARCHAR(100) DEFAULT NULL COMMENT 'Type of entity affected (e.g., user, event, cron)',
        entity_id INT UNSIGNED DEFAULT NULL COMMENT 'ID of affected entity',
        details TEXT DEFAULT NULL COMMENT 'Additional details in text or JSON format',
        ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address of the user',
        user_agent TEXT DEFAULT NULL COMMENT 'User agent string',
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_entity_type (entity_type),
        INDEX idx_entity_id (entity_id),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='System-wide audit log for tracking all user and system actions'
    ";
    
    executeSql(
        $content_db,
        $create_system_logs_table,
        "Create system_logs table"
    );
    
    // Create links table
    $create_links_table = "
    CREATE TABLE IF NOT EXISTS links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        url VARCHAR(500) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        icon VARCHAR(100) DEFAULT 'fas fa-link',
        category VARCHAR(100) DEFAULT NULL COMMENT 'Optional category for grouping links',
        sort_order INT UNSIGNED DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by INT UNSIGNED DEFAULT NULL COMMENT 'User ID who created the link',
        INDEX idx_sort_order (sort_order)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Useful links for quick access to frequently used tools and resources'
    ";
    
    executeSql(
        $content_db,
        $create_links_table,
        "Create links table"
    );
    
    // Add category column to links table
    executeSql(
        $content_db,
        "ALTER TABLE links ADD COLUMN category VARCHAR(100) DEFAULT NULL COMMENT 'Optional category for grouping links'",
        "Add category column to links table"
    );
    
    // ============================================
    // INVENTORY & RENTALS SCHEMA UPDATES
    // ============================================
    echo "\n--- INVENTORY & RENTALS SCHEMA UPDATES ---\n";
    
    // Add quantity_borrowed column to inventory_items
    executeSql(
        $content_db,
        "ALTER TABLE inventory_items ADD COLUMN quantity_borrowed INT NOT NULL DEFAULT 0 COMMENT 'Number of items currently borrowed/checked out' AFTER quantity",
        "Add quantity_borrowed column to inventory_items table"
    );
    
    // Add quantity_rented column to inventory_items
    executeSql(
        $content_db,
        "ALTER TABLE inventory_items ADD COLUMN quantity_rented INT NOT NULL DEFAULT 0 COMMENT 'Number of items currently rented (via rental with return date, awaiting board confirmation)' AFTER quantity_borrowed",
        "Add quantity_rented column to inventory_items table"
    );
    
    // Add loaned_count column to inventory_items
    executeSql(
        $content_db,
        "ALTER TABLE inventory_items ADD COLUMN loaned_count INT DEFAULT 0 COMMENT 'Total number of items currently on loan (borrowed + rented)' AFTER quantity_rented",
        "Add loaned_count column to inventory_items table"
    );
    
    // Initialise loaned_count from existing quantity_borrowed + quantity_rented data
    executeSql(
        $content_db,
        "UPDATE inventory_items SET loaned_count = COALESCE(quantity_borrowed, 0) + COALESCE(quantity_rented, 0) WHERE loaned_count = 0 AND (COALESCE(quantity_borrowed, 0) + COALESCE(quantity_rented, 0)) > 0",
        "Initialise loaned_count from existing quantity_borrowed + quantity_rented values"
    );

    // Add purpose column to rentals (skipped gracefully if table already renamed)
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD COLUMN purpose VARCHAR(255) DEFAULT NULL COMMENT 'Purpose of the rental' AFTER amount",
        "Add purpose column to rentals table"
    );
    
    // Add destination column to rentals
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD COLUMN destination VARCHAR(255) DEFAULT NULL COMMENT 'Destination/location where item is used' AFTER purpose",
        "Add destination column to rentals table"
    );
    
    // Add checkout_date column to rentals
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD COLUMN checkout_date DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date when item was checked out' AFTER destination",
        "Add checkout_date column to rentals table"
    );
    
    // Add status column to rentals with a broad enum that includes all legacy and new values
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD COLUMN status ENUM('active', 'rented', 'returned', 'defective', 'pending_confirmation', 'pending_return', 'overdue') NOT NULL DEFAULT 'active' COMMENT 'Rental status' AFTER actual_return",
        "Add status column to rentals table"
    );

    // Widen existing status enum on rentals to include all legacy and new values before migrating data
    executeSql(
        $content_db,
        "ALTER TABLE rentals MODIFY COLUMN status ENUM('active', 'rented', 'returned', 'defective', 'pending_confirmation', 'pending_return', 'overdue') NOT NULL DEFAULT 'active' COMMENT 'Rental status'",
        "Widen status enum on rentals table to include both legacy and new values"
    );
    
    // Add defect_notes column to rentals
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD COLUMN defect_notes TEXT AFTER notes",
        "Add defect_notes column to rentals table"
    );
    
    // Add index for status in rentals
    executeSql(
        $content_db,
        "ALTER TABLE rentals ADD INDEX idx_status (status)",
        "Add index for status column in rentals table"
    );
    
    // Make expected_return nullable (was NOT NULL before)
    executeSql(
        $content_db,
        "ALTER TABLE rentals MODIFY COLUMN expected_return DATE DEFAULT NULL",
        "Make expected_return nullable in rentals table"
    );

    // Migrate legacy status values on rentals BEFORE renaming or narrowing the enum
    executeSql(
        $content_db,
        "UPDATE rentals SET status = 'rented' WHERE status = 'active'",
        "Migrate status 'active' to 'rented' in rentals table"
    );
    executeSql(
        $content_db,
        "UPDATE rentals SET status = 'pending_return' WHERE status = 'pending_confirmation'",
        "Migrate status 'pending_confirmation' to 'pending_return' in rentals table"
    );
    executeSql(
        $content_db,
        "UPDATE rentals SET status = 'returned' WHERE status = 'defective'",
        "Migrate status 'defective' to 'returned' in rentals table"
    );

    // Narrow the enum to only the new values now that data has been migrated
    executeSql(
        $content_db,
        "ALTER TABLE rentals MODIFY COLUMN status ENUM('rented', 'pending_return', 'returned', 'overdue') NOT NULL DEFAULT 'rented' COMMENT 'Rental status'",
        "Narrow status enum on rentals table to standardised values"
    );

    // Rename rentals table to inventory_rentals
    executeSql(
        $content_db,
        "RENAME TABLE rentals TO inventory_rentals",
        "Rename rentals table to inventory_rentals"
    );

    // Ensure inventory_rentals exists even if the rename above was skipped
    // (i.e. neither 'rentals' nor 'inventory_rentals' existed before this run)
    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `inventory_rentals` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `item_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `amount` INT UNSIGNED NOT NULL DEFAULT 1,
          `purpose` VARCHAR(255) DEFAULT NULL COMMENT 'Purpose of the rental',
          `destination` VARCHAR(255) DEFAULT NULL COMMENT 'Destination/location where item is used',
          `checkout_date` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date when item was checked out',
          `expected_return` DATE DEFAULT NULL,
          `actual_return` DATE DEFAULT NULL,
          `status` ENUM('rented', 'pending_return', 'returned', 'overdue') NOT NULL DEFAULT 'rented' COMMENT 'Rental status',
          `notes` TEXT,
          `defect_notes` TEXT,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
          INDEX `idx_item_id` (`item_id`),
          INDEX `idx_user_id` (`user_id`),
          INDEX `idx_actual_return` (`actual_return`),
          INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Item rentals/loans tracking'",
        "Create inventory_rentals table if it does not exist"
    );

    // Update status enum on inventory_rentals (idempotent – safe to re-run after rename)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals MODIFY COLUMN status ENUM('rented', 'pending_return', 'returned', 'overdue') NOT NULL DEFAULT 'rented' COMMENT 'Rental status'",
        "Update status enum in inventory_rentals table"
    );
    
    // Update inventory_history change_type enum to include checkout/checkin/writeoff
    executeSql(
        $content_db,
        "ALTER TABLE inventory_history MODIFY COLUMN change_type ENUM('add', 'remove', 'adjust', 'sync', 'checkout', 'checkin', 'writeoff') NOT NULL",
        "Update change_type enum in inventory_history table"
    );

    // ============================================
    // ENTRA-ROLE TARGETING & BATCH MAILING
    // ============================================
    echo "\n--- ENTRA-ROLE TARGETING & BATCH MAILING UPDATES ---\n";

    // Add target_roles column to polls table
    executeSql(
        $content_db,
        "ALTER TABLE polls ADD COLUMN target_roles JSON DEFAULT NULL COMMENT 'JSON array of Microsoft Entra roles required to see this poll'",
        "Add target_roles column to polls table"
    );

    // Create mass_mail_jobs table
    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS mass_mail_jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255) NOT NULL COMMENT 'Email subject',
            body_template TEXT NOT NULL COMMENT 'Raw body template with placeholders',
            event_name VARCHAR(255) DEFAULT NULL COMMENT 'Value for {Event_Name} placeholder',
            status ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
            next_run_at DATETIME DEFAULT NULL COMMENT 'When this job should automatically continue',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT UNSIGNED DEFAULT NULL,
            total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_status (status),
            INDEX idx_next_run_at (next_run_at),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Tracks bulk email sending jobs for batch processing'",
        "Create mass_mail_jobs table"
    );

    // Create mass_mail_recipients table
    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS mass_mail_recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            processed_at DATETIME DEFAULT NULL,
            FOREIGN KEY (job_id) REFERENCES mass_mail_jobs(id) ON DELETE CASCADE,
            INDEX idx_job_id (job_id),
            INDEX idx_status (status),
            INDEX idx_job_status (job_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Individual recipients for bulk email jobs'",
        "Create mass_mail_recipients table"
    );

    // ============================================
    // BLOG COMMENT UPDATES
    // ============================================
    echo "\n--- BLOG COMMENT UPDATES ---\n";

    // Add updated_at column to blog_comments
    executeSql(
        $content_db,
        "ALTER TABLE blog_comments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        "Add updated_at column to blog_comments table"
    );

    // Create blog_comment_reactions table
    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `blog_comment_reactions` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `comment_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `reaction` VARCHAR(10) NOT NULL COMMENT 'Emoji reaction',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`comment_id`) REFERENCES `blog_comments`(`id`) ON DELETE CASCADE,
          UNIQUE KEY `unique_comment_user_reaction` (`comment_id`, `user_id`, `reaction`),
          INDEX `idx_comment_id` (`comment_id`),
          INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Emoji reactions on blog comments'",
        "Create blog_comment_reactions table"
    );

    // ============================================
    // INVENTORY RENTAL RETURN APPROVAL COLUMNS
    // ============================================
    echo "\n--- INVENTORY RENTAL RETURN APPROVAL UPDATES ---\n";

    // Add return_approved_by column to inventory_rentals
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN return_approved_by INT UNSIGNED DEFAULT NULL COMMENT 'User ID who approved the return'",
        "Add return_approved_by column to inventory_rentals table"
    );

    // Add return_approved_at column to inventory_rentals
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN return_approved_at DATETIME DEFAULT NULL COMMENT 'Timestamp when return was approved'",
        "Add return_approved_at column to inventory_rentals table"
    );

    // ============================================
    // EVENT_FINANCIAL_STATS: DONATIONS SUPPORT
    // ============================================
    echo "\n--- EVENT_FINANCIAL_STATS DONATIONS UPDATES ---\n";

    // Add 'Spenden' to the category ENUM
    executeSql(
        $content_db,
        "ALTER TABLE event_financial_stats MODIFY COLUMN category ENUM('Verkauf', 'Kalkulation', 'Spenden') NOT NULL COMMENT 'Category: Sales, Calculation, or Donations'",
        "Add Spenden to event_financial_stats category ENUM"
    );

    // Add donations_total column
    executeSql(
        $content_db,
        "ALTER TABLE event_financial_stats ADD COLUMN donations_total DECIMAL(10, 2) DEFAULT NULL COMMENT 'Total donations in EUR (used for Spenden category)'",
        "Add donations_total column to event_financial_stats table"
    );

    // Add condition column to inventory_rentals
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN `condition` ENUM('funktionsfähig', 'beschädigt') NULL DEFAULT NULL COMMENT 'Item condition assessed at return'",
        "Add condition column to inventory_rentals table"
    );

    // ============================================
    // PROJECTS: CREATED_BY COLUMN
    // ============================================
    echo "\n--- PROJECTS CREATED_BY UPDATE ---\n";

    // Add created_by column to projects table
    executeSql(
        $content_db,
        "ALTER TABLE projects ADD COLUMN created_by INT UNSIGNED DEFAULT NULL COMMENT 'User who created the project'",
        "Add created_by column to projects table"
    );

    // Add event_id column to mass_mail_jobs table for event placeholder substitution
    executeSql(
        $content_db,
        "ALTER TABLE mass_mail_jobs ADD COLUMN event_id INT UNSIGNED DEFAULT NULL COMMENT 'ID of the linked event for placeholder substitution'",
        "Add event_id column to mass_mail_jobs table"
    );

    // Add calculation_link column to event_documentation
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN calculation_link VARCHAR(2048) DEFAULT NULL COMMENT 'URL link to external calculation document'",
        "Add calculation_link column to event_documentation table"
    );

    // Add total_costs column to event_documentation
    executeSql(
        $content_db,
        "ALTER TABLE event_documentation ADD COLUMN total_costs DECIMAL(10,2) DEFAULT NULL COMMENT 'Total costs for the event in EUR'",
        "Add total_costs column to event_documentation table"
    );

    // ============================================
    // INVENTORY REQUESTS: EARLY RETURN SUPPORT
    // ============================================
    echo "\n--- INVENTORY REQUESTS EARLY RETURN UPDATES ---\n";

    // Add pending_return to inventory_requests status ENUM so users can request early return
    executeSql(
        $content_db,
        "ALTER TABLE inventory_requests MODIFY COLUMN status ENUM('pending','approved','rejected','returned','pending_return') NOT NULL DEFAULT 'pending' COMMENT 'Approval workflow status'",
        "Add pending_return to inventory_requests status ENUM"
    );

    // ============================================
    // INVENTORY RENTALS: QUANTITY AND EASYVEREIN COLUMNS
    // ============================================
    echo "\n--- INVENTORY RENTALS QUANTITY UPDATES ---\n";

    // Add quantity column to inventory_rentals (used by new EasyVerein-based checkout flow)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of units rented'",
        "Add quantity column to inventory_rentals table"
    );

    // Add easyverein_item_id column to inventory_rentals (used by new EasyVerein-based checkout flow)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN easyverein_item_id VARCHAR(64) DEFAULT NULL COMMENT 'EasyVerein inventory-object ID'",
        "Add easyverein_item_id column to inventory_rentals table"
    );

    // Add rented_at column to inventory_rentals
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN rented_at DATETIME DEFAULT NULL COMMENT 'Timestamp when item was rented'",
        "Add rented_at column to inventory_rentals table"
    );

    // Add returned_at column to inventory_rentals
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN returned_at DATETIME DEFAULT NULL COMMENT 'Timestamp when item was returned'",
        "Add returned_at column to inventory_rentals table"
    );

    // Update status enum on inventory_rentals to include 'active' (used by EasyVerein-based flow)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals MODIFY COLUMN status ENUM('active','rented','pending_return','returned','overdue') NOT NULL DEFAULT 'active' COMMENT 'Rental status'",
        "Add active status to inventory_rentals status ENUM"
    );

    // ============================================
    // INVENTORY QUANTITY COLUMNS (TEILAUSLEIHEN)
    // ============================================
    echo "\n--- INVENTORY QUANTITY COLUMNS ---\n";

    // Add total_quantity column to inventory_items (total stock from EasyVerein data)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_items ADD COLUMN total_quantity INT NOT NULL DEFAULT 1 COMMENT 'Total stock from EasyVerein data'",
        "Add total_quantity column to inventory_items table"
    );

    // Add rented_quantity column to inventory_rentals (units rented per transaction)
    executeSql(
        $content_db,
        "ALTER TABLE inventory_rentals ADD COLUMN rented_quantity INT NOT NULL DEFAULT 1 COMMENT 'Number of units rented in this transaction (partial lending)'",
        "Add rented_quantity column to inventory_rentals table"
    );

    // ============================================
    // SHOP MODULE TABLES
    // ============================================
    echo "\n--- SHOP MODULE TABLES ---\n";

    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `shop_products` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(255)   NOT NULL,
            `description` TEXT,
            `base_price`  DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            `image_path`  VARCHAR(500),
            `active`      TINYINT(1)     NOT NULL DEFAULT 1,
            `created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create shop_products table"
    );

    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `shop_variants` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`     INT UNSIGNED NOT NULL,
            `type`           VARCHAR(100) NOT NULL COMMENT 'z.B. Größe, Farbe',
            `value`          VARCHAR(100) NOT NULL COMMENT 'z.B. XL, Blau',
            `stock_quantity` INT          NOT NULL DEFAULT 0,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `fk_variant_product` (`product_id`),
            CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create shop_variants table"
    );

    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `shop_orders` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`         INT UNSIGNED NOT NULL,
            `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method`  ENUM('paypal','sepa') NOT NULL DEFAULT 'paypal',
            `payment_status`  VARCHAR(50)  NOT NULL DEFAULT 'pending',
            `shipping_status` VARCHAR(50)  NOT NULL DEFAULT 'pending',
            `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create shop_orders table"
    );

    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `shop_order_items` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `order_id`         INT UNSIGNED  NOT NULL,
            `product_id`       INT UNSIGNED  NOT NULL,
            `variant_id`       INT UNSIGNED  DEFAULT NULL,
            `quantity`         INT           NOT NULL DEFAULT 1,
            `price_at_purchase` DECIMAL(10,2) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `fk_item_order`   (`order_id`),
            KEY `fk_item_product` (`product_id`),
            KEY `fk_item_variant` (`variant_id`),
            CONSTRAINT `fk_item_order`   FOREIGN KEY (`order_id`)   REFERENCES `shop_orders`   (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`),
            CONSTRAINT `fk_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `shop_variants` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create shop_order_items table"
    );

    executeSql(
        $content_db,
        "CREATE TABLE IF NOT EXISTS `shop_restock_notifications` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`       INT UNSIGNED NOT NULL,
            `product_id`    INT UNSIGNED NOT NULL,
            `variant_type`  VARCHAR(100) NOT NULL DEFAULT '',
            `variant_value` VARCHAR(100) NOT NULL DEFAULT '',
            `email`         VARCHAR(255) NOT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `notified_at`   DATETIME     DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_variant` (`user_id`, `product_id`, `variant_type`, `variant_value`),
            KEY `fk_restock_product` (`product_id`),
            CONSTRAINT `fk_restock_product` FOREIGN KEY (`product_id`) REFERENCES `shop_products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Create shop_restock_notifications table"
    );

    // ============================================
    // SUMMARY
    // ============================================
    echo "==============================================\n";
    echo "SUMMARY\n";
    echo "==============================================\n";
    echo "Successful operations: $success_count\n";
    echo "Failed operations: $error_count\n";
    
    if ($error_count > 0) {
        echo "\n--- ERRORS ---\n";
        foreach ($errors as $error) {
            echo "- {$error['description']}: {$error['error']}\n";
        }
        echo "\n";
        exit(1);
    } else {
        echo "\n✓ All schema updates completed successfully!\n";
        echo "The database schema is now up to date.\n";
        exit(0);
    }
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
