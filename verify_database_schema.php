<?php
/**
 * Database Schema Verification Script
 * 
 * Checks if all required columns and tables exist in the database.
 * Run this to verify your database schema is up to date.
 * 
 * Usage: php verify_database_schema.php
 */

require_once __DIR__ . '/includes/database.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "==============================================\n";
echo "Database Schema Verification\n";
echo "==============================================\n\n";

$allChecks = [];
$missingItems = [];

/**
 * Check if a column exists in a table
 */
function checkColumn($pdo, $table, $column, $description) {
    global $allChecks, $missingItems;
    
    try {
        // Use information_schema for fully parameterized query
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $exists = $stmt->rowCount() > 0;
        
        $allChecks[] = [
            'type' => 'column',
            'description' => $description,
            'exists' => $exists
        ];
        
        if (!$exists) {
            $missingItems[] = "Column `$table`.`$column`";
        }
        
        return $exists;
    } catch (PDOException $e) {
        $allChecks[] = [
            'type' => 'column',
            'description' => $description,
            'exists' => false,
            'error' => $e->getMessage()
        ];
        $missingItems[] = "Column `$table`.`$column` (table may not exist)";
        return false;
    }
}

/**
 * Check if a table exists
 */
function checkTable($pdo, $table, $description) {
    global $allChecks, $missingItems;
    
    try {
        // Use information_schema for fully parameterized query
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        $exists = $stmt->rowCount() > 0;
        
        $allChecks[] = [
            'type' => 'table',
            'description' => $description,
            'exists' => $exists
        ];
        
        if (!$exists) {
            $missingItems[] = "Table `$table`";
        }
        
        return $exists;
    } catch (PDOException $e) {
        $allChecks[] = [
            'type' => 'table',
            'description' => $description,
            'exists' => false,
            'error' => $e->getMessage()
        ];
        $missingItems[] = "Table `$table`";
        return false;
    }
}

try {
    // ============================================
    // USER DATABASE CHECKS
    // ============================================
    echo "--- Checking User Database ---\n";
    
    $user_db = Database::getUserDB();
    
    checkColumn($user_db, 'users', 'azure_roles', 'users.azure_roles column');
    checkColumn($user_db, 'users', 'azure_oid', 'users.azure_oid column');
    checkColumn($user_db, 'users', 'deleted_at', 'users.deleted_at column');
    checkColumn($user_db, 'users', 'last_reminder_sent_at', 'users.last_reminder_sent_at column');
    checkColumn($user_db, 'users', 'show_birthday', 'users.show_birthday column');
    
    // ============================================
    // CONTENT DATABASE CHECKS
    // ============================================
    echo "--- Checking Content Database ---\n";
    
    $content_db = Database::getContentDB();
    
    checkColumn($content_db, 'alumni_profiles', 'secondary_email', 'alumni_profiles.secondary_email column');
    checkColumn($content_db, 'polls', 'microsoft_forms_url', 'polls.microsoft_forms_url column');
    checkColumn($content_db, 'polls', 'visible_to_all', 'polls.visible_to_all column');
    checkColumn($content_db, 'polls', 'is_internal', 'polls.is_internal column');
    checkColumn($content_db, 'polls', 'allowed_roles', 'polls.allowed_roles column');
    checkTable($content_db, 'poll_hidden_by_user', 'poll_hidden_by_user table');
    checkColumn($content_db, 'event_documentation', 'sellers_data', 'event_documentation.sellers_data column');
    
    // CRITICAL: Check needs_helpers column
    $hasNeedsHelpers = checkColumn($content_db, 'events', 'needs_helpers', 'events.needs_helpers column');
    
    checkTable($content_db, 'event_financial_stats', 'event_financial_stats table');
    checkTable($content_db, 'poll_options', 'poll_options table');
    checkTable($content_db, 'poll_votes', 'poll_votes table');
    checkTable($content_db, 'event_registrations', 'event_registrations table');
    checkTable($content_db, 'system_logs', 'system_logs table');

    // CRITICAL: Check inventory_rentals table
    $hasInventoryRentals = checkTable($content_db, 'inventory_rentals', 'inventory_rentals table');

    // CRITICAL: Check inventory_requests.return_notes column
    $hasReturnNotes = checkColumn($content_db, 'inventory_requests', 'return_notes', 'inventory_requests.return_notes column');

    // CRITICAL: Check inventory_requests.returned_at column
    $hasReturnedAt = checkColumn($content_db, 'inventory_requests', 'returned_at', 'inventory_requests.returned_at column');

    // CRITICAL: Check blog_comments.updated_at column
    $hasBlogCommentsUpdatedAt = checkColumn($content_db, 'blog_comments', 'updated_at', 'blog_comments.updated_at column');
    checkTable($content_db, 'blog_comment_reactions', 'blog_comment_reactions table');

    // ============================================
    // SUMMARY
    // ============================================
    echo "\n==============================================\n";
    echo "VERIFICATION SUMMARY\n";
    echo "==============================================\n";
    
    $totalChecks = count($allChecks);
    $passedChecks = count(array_filter($allChecks, function($check) {
        return $check['exists'];
    }));
    $failedChecks = $totalChecks - $passedChecks;
    
    echo "Total checks: $totalChecks\n";
    echo "Passed: $passedChecks\n";
    echo "Failed: $failedChecks\n\n";
    
    if ($failedChecks > 0) {
        echo "❌ SCHEMA IS OUT OF DATE\n\n";
        echo "Missing items:\n";
        foreach ($missingItems as $item) {
            echo "  - $item\n";
        }
        echo "\n";
        echo "⚠️  ACTION REQUIRED:\n";
        echo "Run the database update script to fix these issues:\n";
        echo "  php update_database_schema.php\n";
        echo "\n";
        
        // Special message for needs_helpers column
        if (!$hasNeedsHelpers) {
            echo "⚠️  CRITICAL: The 'needs_helpers' column is missing!\n";
            echo "This will cause errors on the dashboard.\n";
            echo "Run 'php update_database_schema.php' immediately.\n";
            echo "\n";
        }
        
        // Special message for inventory_rentals table
        if (!$hasInventoryRentals) {
            echo "⚠️  CRITICAL: The 'inventory_rentals' table is missing!\n";
            echo "This will cause a fatal PDOException (SQLSTATE[42S02]) on the dashboard and inventory pages.\n";
            echo "Run 'php update_database_schema.php' immediately.\n";
            echo "\n";
        }

        // Special message for inventory_requests.return_notes column
        if (!$hasReturnNotes) {
            echo "⚠️  CRITICAL: The 'return_notes' column is missing from 'inventory_requests'!\n";
            echo "This will cause a PDOException (SQLSTATE[42S22]) when verifying a rental return.\n";
            echo "Run 'php update_database_schema.php' immediately.\n";
            echo "\n";
        }

        // Special message for inventory_requests.returned_at column
        if (!$hasReturnedAt) {
            echo "⚠️  CRITICAL: The 'returned_at' column is missing from 'inventory_requests'!\n";
            echo "This will cause a PDOException (SQLSTATE[42S22]) when verifying a rental return (Gemeldete Rückgaben).\n";
            echo "Run 'php update_database_schema.php' immediately.\n";
            echo "\n";
        }

        // Special message for blog_comments.updated_at column
        if (!$hasBlogCommentsUpdatedAt) {
            echo "⚠️  CRITICAL: The 'updated_at' column is missing from 'blog_comments'!\n";
            echo "This will cause a PDOException (SQLSTATE[42S22]) when viewing blog posts.\n";
            echo "Run 'php update_database_schema.php' immediately.\n";
            echo "\n";
        }
        
        exit(1);
    } else {
        echo "✓ All schema checks passed!\n";
        echo "Your database schema is up to date.\n";
        exit(0);
    }
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
