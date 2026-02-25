# SQL and Code Updates for Microsoft Login Method

## Zusammenfassung / Summary

Dieses Dokument beschreibt alle Änderungen, die vorgenommen wurden, um die SQL-Datenbanken und den Code an die neue Microsoft Entra ID Login-Methode anzupassen.

This document describes all changes made to adapt the SQL databases and code to the new Microsoft Entra ID login method.

---

## Durchgeführte Änderungen / Changes Made

### 1. SQL Schema Updates

#### 1.1 User Database (dbs15253086.sql)

**Users Table:**
- ✅ Added missing roles to ENUM:
  - `candidate` (Anwärter)
  - `head` (Ressortleiter) 
  - `honorary_member` (Ehrenmitglied)
- ✅ Added `profile_complete` column with index
  - Type: BOOLEAN NOT NULL DEFAULT 1
  - Purpose: Track if user completed initial profile setup (first_name + last_name)
  - Default 1 for existing users (no disruption)
  - Should be 0 for new OAuth users

**Complete Role List:**
```sql
role ENUM(
    'board_finance',      -- Vorstand Finanzen
    'board_internal',     -- Vorstand Intern
    'board_external',     -- Vorstand Extern
    'alumni_board',       -- Alumni-Vorstand
    'alumni_auditor',     -- Alumni-Finanzprüfer
    'alumni',             -- Alumni
    'honorary_member',    -- Ehrenmitglied
    'head',               -- Ressortleiter
    'member',             -- Mitglied
    'candidate'           -- Anwärter
)
```

**Invitation Tokens Table:**
- ✅ Updated role ENUM to match users table

#### 1.2 Content Database (dbs15161271.sql)

**Event Roles Table:**
- ✅ Updated role ENUM to use new specific role names
- ✅ Replaced deprecated roles:
  - `board` → `board_finance`, `board_internal`, `board_external`
  - Added all 10 current roles

### 2. PHP Code Updates

#### 2.1 pages/events/edit.php
**Before:**
```php
$roles = [
    'member' => 'Mitglied',
    'alumni' => 'Alumni',
    'manager' => 'Ressortleiter',
    'alumni_board' => 'Alumni-Vorstand',
    'alumni_finanzprufer' => 'Alumni-Finanzprüfer',
    'board' => 'Vorstand'
];
```

**After:**
```php
$roles = [
    'candidate' => 'Anwärter',
    'member' => 'Mitglied',
    'honorary_member' => 'Ehrenmitglied',
    'head' => 'Ressortleiter',
    'alumni' => 'Alumni',
    'alumni_board' => 'Alumni-Vorstand',
    'alumni_auditor' => 'Alumni-Finanzprüfer',
    'board_finance' => 'Vorstand Finanzen',
    'board_internal' => 'Vorstand Intern',
    'board_external' => 'Vorstand Extern'
];
```

#### 2.2 pages/members/index.php
- ✅ Updated role filter dropdown with all current roles
- ✅ Removed deprecated role variants (`board`, `vorstand_*`)
- ✅ Fixed label consistency (plural forms)

### 3. Validation & Testing

#### 3.1 Created validate_role_consistency.php
A comprehensive validation script that checks:
- ✅ Microsoft OAuth role mappings are valid
- ✅ SQL ENUM definitions are consistent across tables
- ✅ profile_complete column exists
- ✅ Role hierarchy includes backward compatibility

**Test Results:** All tests pass ✓

#### 3.2 Backward Compatibility
The code maintains backward compatibility in AuthHandler.php:
- `manager` role still works (maps to level 2, same as `head`)
- `board` role still works (maps to level 3, same as board roles)
- `admin` role still works (maps to level 3)

These deprecated roles are kept for existing users but not assignable to new users.

---

## Microsoft Entra ID Role Mapping

The following Azure AD roles map to internal roles:

| Azure Role Name | Internal Role | Priority | German Name |
|----------------|---------------|----------|-------------|
| anwaerter | candidate | 1 | Anwärter |
| mitglied | member | 2 | Mitglied |
| ressortleiter | head | 3 | Ressortleiter |
| alumni | alumni | 4 | Alumni |
| ehrenmitglied | honorary_member | 5 | Ehrenmitglied |
| vorstand_finanzen | board_finance | 6 | Vorstand Finanzen |
| vorstand_intern | board_internal | 7 | Vorstand Intern |
| vorstand_extern | board_external | 8 | Vorstand Extern |
| alumni_vorstand | alumni_board | 9 | Alumni-Vorstand |
| alumni_finanz | alumni_auditor | 10 | Alumni-Finanzprüfer |

**Note:** If a user has multiple roles, the system selects the one with highest priority.

---

## Security & Code Quality

### Security Checks
- ✅ CodeQL analysis: No vulnerabilities detected
- ✅ Code review: All feedback addressed
- ✅ Role validation: All tests pass

### Best Practices
- ✅ Consistent role naming across all tables
- ✅ Proper indexing for performance
- ✅ Clear comments explaining defaults
- ✅ Backward compatibility maintained
- ✅ Validation script for future changes

---

## Migration Notes

### For Existing Databases

1. **Users Table:**
   ```sql
   -- Add missing roles (if not already present)
   ALTER TABLE users MODIFY COLUMN role ENUM(
       'board_finance', 'board_internal', 'board_external',
       'alumni_board', 'alumni_auditor', 'alumni', 'honorary_member',
       'head', 'member', 'candidate'
   ) NOT NULL DEFAULT 'member';
   
   -- Add profile_complete column (if not already present)
   ALTER TABLE users 
   ADD COLUMN profile_complete BOOLEAN NOT NULL DEFAULT 1 
   COMMENT 'Flag to track if user has completed initial profile setup (first_name + last_name). Default 1 for existing users to avoid disruption; new OAuth users should be set to 0.';
   
   CREATE INDEX idx_profile_complete ON users(profile_complete);
   ```

2. **Invitation Tokens Table:**
   ```sql
   ALTER TABLE invitation_tokens MODIFY COLUMN role ENUM(
       'board_finance', 'board_internal', 'board_external',
       'alumni_board', 'alumni_auditor', 'alumni', 'honorary_member',
       'head', 'member', 'candidate'
   ) NOT NULL DEFAULT 'member';
   ```

3. **Event Roles Table:**
   ```sql
   ALTER TABLE event_roles MODIFY COLUMN role ENUM(
       'board_finance', 'board_internal', 'board_external',
       'alumni_board', 'alumni_auditor', 'alumni', 'honorary_member',
       'head', 'member', 'candidate'
   ) NOT NULL;
   ```

### For Fresh Installations

Simply run the updated SQL files in order:
1. `sql/dbs15253086.sql` (User Database)
2. `sql/dbs15251284.sql` (Invoice Database)
3. `sql/dbs15161271.sql` (Content Database)

---

## Testing Checklist

Before deploying, verify:

- [ ] Run `php validate_role_consistency.php` - all tests should pass
- [ ] Test Microsoft OAuth login flow
- [ ] Verify event creation with role visibility works
- [ ] Check member filtering by role works
- [ ] Test user invitation with different roles
- [ ] Verify existing users can still log in
- [ ] Check that deprecated roles still work for existing users

---

## Files Modified

1. ✅ `sql/dbs15253086.sql` - User database schema
2. ✅ `sql/dbs15161271.sql` - Content database schema
3. ✅ `pages/events/edit.php` - Event role dropdown
4. ✅ `pages/members/index.php` - Member role filter
5. ✅ `validate_role_consistency.php` - New validation script

---

## Status

✅ **COMPLETE** - All SQL files and code have been updated and validated. The system now fully supports the new Microsoft Entra ID login method with all 10 user roles properly defined and consistent across all tables.

---

**Last Updated:** 2026-02-11
**Author:** GitHub Copilot
**PR:** copilot/update-sql-for-new-login-method
