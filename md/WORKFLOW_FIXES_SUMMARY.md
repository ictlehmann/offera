# Workflow Fixes Implementation Summary

This document summarizes the changes made to fix workflows related to first login, event creation, and project creation.

## Changes Overview

### 1. First Login Redirect (Profile Completion)

**Problem:** Users could access all pages immediately after login, even if they hadn't completed their profile with basic information like first_name and last_name.

**Solution:** Implemented a profile completion check that:
- Checks `profile_complete` flag in the users table after login
- Sets a session flag `profile_incomplete` if the profile is incomplete
- Redirects users to the profile page until they complete their profile
- Marks the profile as complete when saved with first_name and last_name

**Files Modified:**
- `includes/handlers/AuthHandler.php`:
  - Added `$_SESSION['profile_incomplete']` flag in `login()` method (line 133)
  - Added `$_SESSION['profile_incomplete']` flag in `handleMicrosoftCallback()` method (line 680)
- `index.php`:
  - Added redirect logic to profile page for incomplete profiles (line 50-52)
- `includes/templates/main_layout.php`:
  - Added guard to prevent access to other pages when profile is incomplete (line 7-16)
- `pages/auth/profile.php`:
  - Added logic to mark profile as complete in database (line 193-195)
  - Clear `profile_incomplete` session flag with `unset()` (line 197)

**Database Schema:**
- Uses existing `profile_complete` column in `users` table (already present in schema)
- Default value is `1` for existing users to avoid disruption
- New users or those with incomplete data should have `0`

---

### 2. Event Registration via External Forms

**Problem:** Events didn't support external registration links (e.g., Microsoft Forms). All registration had to go through the internal system.

**Solution:** Added `registration_link` field to events table that:
- Allows event organizers to specify an external registration URL
- Takes priority over internal registration when set
- Opens in a new tab for security (target="_blank", rel="noopener noreferrer")

**Files Modified:**
- `sql/add_registration_link_to_events.sql`:
  - New SQL migration file to add `registration_link` column to events table
- `sql/run_add_registration_link_migration.php`:
  - PHP script to run the migration (checks if column exists first)
- `includes/models/Event.php`:
  - Added `registration_link` to INSERT statement in `create()` method (line 230, 246)
  - UPDATE method already handles it dynamically via foreach loop
- `pages/events/edit.php`:
  - Added `registration_link` to form data array (line 78)
  - Added form field "Externe Anmeldung (Microsoft Forms Link)" (line 523-536)
- `pages/events/view.php`:
  - Updated registration button logic to prioritize `registration_link` (line 281-309)
  - If `registration_link` is set, button opens external URL instead of internal signup

**Database Schema Change:**
```sql
ALTER TABLE events 
ADD COLUMN registration_link TEXT DEFAULT NULL 
COMMENT 'External registration link (e.g., Microsoft Forms URL) for event registration' 
AFTER external_link;
```

**Priority Logic:**
1. If `registration_link` is set → Open external URL in new tab
2. Else if `is_external` and `external_link` is set → Open external link
3. Else → Use internal registration system

---

### 3. Microsoft Forms Integration (Polls)

**Problem:** Users didn't understand that Microsoft Forms must be created manually on forms.office.com since there's no API for automatic creation.

**Solution:** Added informative help text to the polls creation form.

**Files Modified:**
- `pages/polls/create.php`:
  - Added blue help text below Microsoft Forms URL field (line 138-141)
  - Text: "Hinweis: Microsoft bietet keine API zum automatischen erstellen. Bitte erstellen Sie das Formular manuell auf forms.office.com und fügen Sie hier den Einbettungs-Code ein."

---

### 4. Create Project and Event Buttons

**Problem:** User reported that "Create Project" and "Create Event" buttons were not showing forms.

**Investigation Results:**
- Buttons are working correctly
- They link to the appropriate pages:
  - Projects: `/pages/projects/manage.php?new=1`
  - Events: `/pages/events/edit.php`
- Permissions are correctly configured for Board/Head/Alumni Board roles

**Files Verified:**
- `pages/projects/index.php` (line 78-82): Button with correct permissions
- `pages/events/index.php` (line 81-86): Button with correct permissions

**Permission Check:**
```php
Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['head', 'alumni_board'])
```

**Conclusion:** No code changes needed. Buttons work as designed.

---

## Code Quality Improvements

### Code Review Feedback Addressed:
1. **Simplified boolean checks:** Changed `$user['profile_complete'] == 0 || $user['profile_complete'] === false` to `!$user['profile_complete']`
2. **Session handling:** Used `unset($_SESSION['profile_incomplete'])` to completely remove the flag instead of setting to false
3. **German capitalization:** Kept "erstellen" lowercase as it's a verb in the middle of a sentence

### Security Considerations:
1. **External links:** All external registration links use `target="_blank"` and `rel="noopener noreferrer"` to prevent security issues
2. **Input validation:** All URLs are sanitized with `htmlspecialchars()` before output
3. **Session checks:** Profile completion check runs on every page load via main_layout.php
4. **Database updates:** Using prepared statements for all database operations

---

## Testing Checklist

- [ ] Apply SQL migration for `registration_link` column
- [ ] Test first login redirect with new user (profile_complete = 0)
- [ ] Test profile completion and flag clearing
- [ ] Test event creation with external registration link
- [ ] Test event registration button behavior with external link
- [ ] Test polls creation form shows help text
- [ ] Verify create buttons are visible for authorized users
- [ ] Verify create buttons are hidden for regular members
- [ ] Test Microsoft OAuth login with profile completion

See `WORKFLOW_FIXES_TESTING_GUIDE.md` for detailed testing instructions.

---

## Deployment Instructions

1. **Backup Database:**
   ```bash
   mysqldump -u username -p content_db > backup_before_migration.sql
   ```

2. **Apply Migration:**
   ```bash
   cd sql
   php run_add_registration_link_migration.php
   ```
   
   Or manually:
   ```bash
   mysql -u username -p content_db < add_registration_link_to_events.sql
   ```

3. **Verify Migration:**
   ```sql
   DESCRIBE events;
   -- Should show registration_link column after external_link
   ```

4. **Deploy Code:**
   - Pull latest changes from branch
   - No additional configuration needed

5. **Verify Deployment:**
   - Test login redirect for incomplete profiles
   - Test event creation with external registration link
   - Verify all create buttons are visible

---

## Rollback Plan

If issues arise:

1. **Database Rollback:**
   ```sql
   ALTER TABLE events DROP COLUMN registration_link;
   ```

2. **Code Rollback:**
   - Revert to previous commit
   - Remove session checks in main_layout.php
   - Users may need to clear browser sessions

3. **Session Cleanup:**
   ```php
   // Run this script to clear profile_incomplete flags
   session_start();
   unset($_SESSION['profile_incomplete']);
   ```

---

## Future Improvements

1. **Profile Completion Progress:** Add a progress bar showing what's missing from the profile
2. **Bulk Migration:** Create a script to update `profile_complete` flag for all users based on existing data
3. **Admin Override:** Allow admins to bypass profile completion for specific users
4. **Event Templates:** Add ability to save registration link templates for recurring events
5. **Analytics:** Track how many users use external vs internal registration

---

## Support

For issues or questions:
1. Check `WORKFLOW_FIXES_TESTING_GUIDE.md` for testing instructions
2. Review error logs in `/logs` directory
3. Verify database schema matches expected structure
4. Check session variables in browser developer tools
5. Contact: [Your contact information]
