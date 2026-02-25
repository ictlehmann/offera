# Database Schema Completion - Documentation

## Overview

This document describes the completion of the database schema by adding all missing tables that were being used in the code but were not defined in the SQL schema files.

**Date:** 2026-02-15  
**Issue:** Check and complete all database tables across the 3 databases  
**Status:** ✅ COMPLETE

---

## Problem Statement

The codebase referenced several database tables that were not defined in the main SQL schema files:
- `sql/dbs15253086.sql` (User Database)
- `sql/dbs15161271.sql` (Content Database)  
- `sql/dbs15251284.sql` (Invoice Database)

This caused potential issues during fresh installations and made the schema incomplete.

---

## Missing Tables Identified

### User Database (dbs15253086)

| Table | Status | Purpose |
|-------|--------|---------|
| `invitation_tokens` | ✅ ADDED | Manages invitation tokens for user registration |

### Content Database (dbs15161271)

| Table | Status | Purpose |
|-------|--------|---------|
| `poll_options` | ✅ ADDED | Options/choices for internal polls |
| `poll_votes` | ✅ ADDED | User votes on poll options |
| `event_registrations` | ✅ ADDED | Simple event registrations (alternative to event_signups) |
| `system_logs` | ✅ ADDED | System-wide audit log for all actions |

### Analysis Note

- `inventory_checkouts` - Investigated and confirmed this table is NOT needed. The codebase uses the `rentals` table instead, which provides the same functionality.

---

## Tables Added

### 1. invitation_tokens (User DB)

**Purpose:** Manages invitation tokens for user registration with role-based access.

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS `invitation_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(255) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL,
  `role` ENUM('board_finance', 'board_internal', 'board_external', 
              'alumni_board', 'alumni_auditor', 'alumni', 'honorary_member', 
              'head', 'member', 'candidate') NOT NULL DEFAULT 'member',
  `created_by` INT UNSIGNED NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `used_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  
  INDEX `idx_token` (`token`),
  INDEX `idx_email` (`email`),
  INDEX `idx_created_by` (`created_by`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Invitation tokens for user registration';
```

**Used in:**
- `includes/handlers/AuthHandler.php`
- `src/Auth.php`
- `api/send_invitation.php`
- `api/get_invitations.php`
- `api/import_invitations.php`
- `api/delete_invitation.php`
- `pages/auth/register.php`

---

### 2. poll_options (Content DB)

**Purpose:** Stores poll options/choices for internal polls (not used for Microsoft Forms integration).

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_text` VARCHAR(500) NOT NULL COMMENT 'Text of the poll option',
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order in which options are displayed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
  
  INDEX `idx_poll_id` (`poll_id`),
  INDEX `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Options/choices for internal polls (not used for Microsoft Forms)';
```

**Used in:**
- `pages/polls/view.php`
- `pages/polls/index.php`

---

### 3. poll_votes (Content DB)

**Purpose:** Tracks user votes on poll options for internal polls.

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`option_id`) REFERENCES `poll_options`(`id`) ON DELETE CASCADE,
  
  UNIQUE KEY `unique_poll_user_vote` (`poll_id`, `user_id`),
  INDEX `idx_poll_id` (`poll_id`),
  INDEX `idx_option_id` (`option_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User votes on poll options (not used for Microsoft Forms)';
```

**Used in:**
- `pages/polls/view.php`
- `pages/polls/index.php`

---

### 4. event_registrations (Content DB)

**Purpose:** Simple event registration system (alternative to the more complex event_signups with time slots).

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
  `registered_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  
  UNIQUE KEY `unique_event_user_registration` (`event_id`, `user_id`),
  INDEX `idx_event_id` (`event_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Simple event registrations (alternative to event_signups with slots)';
```

**Used in:**
- `api/event_signup.php`
- `api/event_signup_simple.php`

**Note:** The application supports two event registration systems:
1. **Simple Registration:** Uses `event_registrations` - just yes/no attendance
2. **Slot-based Registration:** Uses `event_signups` + `event_slots` - sign up for specific time slots

---

### 5. system_logs (Content DB)

**Purpose:** System-wide audit log for tracking all user and system actions.

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'User who performed the action (0 for system/cron)',
  `action` VARCHAR(100) NOT NULL COMMENT 'Action type (e.g., login_success, invitation_created)',
  `entity_type` VARCHAR(100) DEFAULT NULL COMMENT 'Type of entity affected (e.g., user, event, cron)',
  `entity_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID of affected entity',
  `details` TEXT DEFAULT NULL COMMENT 'Additional details in text or JSON format',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP address of the user',
  `user_agent` TEXT DEFAULT NULL COMMENT 'User agent string',
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_entity_type` (`entity_type`),
  INDEX `idx_entity_id` (`entity_id`),
  INDEX `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System-wide audit log for tracking all user and system actions';
```

**Used in:**
- `includes/handlers/AuthHandler.php`
- `pages/admin/settings.php`
- `pages/admin/audit.php`
- `pages/admin/db_maintenance.php`
- `cron/sync_easyverein.php`
- `cron/send_alumni_reminders.php`
- `cron/send_profile_reminders.php`
- `cron/send_birthday_wishes.php`
- And many more files...

---

## Database Distribution

The 3-database architecture is optimally organized:

### User Database (dbs15253086) - 6 tables
**Purpose:** User authentication, sessions, and account management

- `users` - User accounts and profiles
- `user_sessions` - Active user sessions
- `login_attempts` - Track login attempts for security
- `password_resets` - Password reset tokens
- `email_change_requests` - Email change verification
- `invitation_tokens` - User invitation system

### Content Database (dbs15161271) - 27 tables
**Purpose:** Application content and features

**Events (9 tables):**
- `events`, `event_documentation`, `event_financial_stats`
- `event_roles`, `event_helper_types`, `event_slots`
- `event_signups`, `event_registrations`, `event_history`

**Projects (3 tables):**
- `projects`, `project_applications`, `project_assignments`

**Blog (3 tables):**
- `blog_posts`, `blog_likes`, `blog_comments`

**Polls (4 tables):**
- `polls`, `poll_options`, `poll_votes`, `poll_hidden_by_user`

**Inventory (5 tables):**
- `inventory_items`, `categories`, `locations`, `rentals`, `inventory_history`

**Other (3 tables):**
- `alumni_profiles`, `system_settings`, `system_logs`

### Invoice Database (dbs15251284) - 1 table
**Purpose:** Financial and billing management

- `invoices` - Invoice management and expense reimbursement

---

## Changes Made

### 1. Updated SQL Schema Files

**File: `sql/dbs15253086.sql`**
- Added `invitation_tokens` table definition

**File: `sql/dbs15161271.sql`**
- Added `poll_options` table definition
- Added `poll_votes` table definition
- Added `event_registrations` table definition
- Added `system_logs` table definition

**File: `sql/dbs15251284.sql`**
- No changes needed (invoice schema was already complete)

### 2. Updated Migration Script

**File: `update_database_schema.php`**
- Added creation of `invitation_tokens` table for User DB
- Added creation of `poll_options` table for Content DB
- Added creation of `poll_votes` table for Content DB
- Added creation of `event_registrations` table for Content DB
- Added creation of `system_logs` table for Content DB

All tables are created with `IF NOT EXISTS` to ensure idempotent execution.

---

## Deployment Instructions

### For Existing Databases

Run the update script to add missing tables:

```bash
php update_database_schema.php
```

This script will:
- Check if tables already exist
- Create missing tables with proper structure
- Skip tables that are already present
- Provide detailed output of all operations

### For Fresh Installations

Simply run the three main schema files in order:

```bash
# User database
mysql -u username -p dbs15253086 < sql/dbs15253086.sql

# Content database  
mysql -u username -p dbs15161271 < sql/dbs15161271.sql

# Invoice database
mysql -u username -p dbs15251284 < sql/dbs15251284.sql
```

---

## Verification

After deployment, verify all tables exist:

```sql
-- User Database
SHOW TABLES FROM dbs15253086;
DESCRIBE dbs15253086.invitation_tokens;

-- Content Database
SHOW TABLES FROM dbs15161271;
DESCRIBE dbs15161271.poll_options;
DESCRIBE dbs15161271.poll_votes;
DESCRIBE dbs15161271.event_registrations;
DESCRIBE dbs15161271.system_logs;

-- Invoice Database
SHOW TABLES FROM dbs15251284;
```

---

## Benefits

1. **Complete Schema** - All tables used in code are now defined in SQL files
2. **Fresh Install Support** - New installations can run just 3 SQL files
3. **Proper Documentation** - Each table is documented with purpose and relationships
4. **Optimal Distribution** - Tables are logically grouped across 3 databases
5. **Foreign Key Integrity** - All relationships properly defined with constraints
6. **Indexed Performance** - All necessary indexes are in place

---

## Related Documentation

- `SQL_CONSOLIDATION_README.md` - Previous schema consolidation work
- `SQL_UPDATE_SUMMARY.md` - Microsoft Entra ID login updates
- `EVENT_FINANCIAL_STATS_README.md` - Event financial statistics
- `PROFILE_REMINDERS_README.md` - Profile reminder system

---

## Support

For issues or questions, consult the existing documentation or contact the development team.

---

**Status:** ✅ COMPLETE  
**Last Updated:** 2026-02-15  
**Author:** GitHub Copilot  
**PR:** copilot/check-and-update-sql-schemas
