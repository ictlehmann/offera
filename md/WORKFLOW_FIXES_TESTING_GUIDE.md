# Testing Guide: Workflow Fixes

This document provides instructions for testing the workflow fixes implemented in this PR.

## Prerequisites
1. Apply the SQL migration: `sql/add_registration_link_to_events.sql` to the content database
2. Alternatively, run: `php sql/run_add_registration_link_migration.php` from the project root

## Test Scenarios

### 1. First Login Redirect (Profile Completion)

**Setup:**
1. Create a new test user in the database with `profile_complete = 0`
2. Or manually update an existing user: `UPDATE users SET profile_complete = 0 WHERE email = 'test@example.com'`

**Test Steps:**
1. Log in with the test user (either via standard login or Microsoft OAuth)
2. **Expected:** User should be redirected to `/pages/auth/profile.php`
3. Try to access any other page (e.g., `/pages/dashboard/index.php`, `/pages/events/index.php`)
4. **Expected:** User should be redirected back to `/pages/auth/profile.php`
5. Fill in the profile form with at least `first_name` and `last_name`
6. Click "Profil aktualisieren" (Update Profile)
7. **Expected:** Profile is saved and user is no longer redirected
8. Navigate to dashboard or other pages
9. **Expected:** User can now access all pages normally

**Verification:**
- Check database: `SELECT profile_complete FROM users WHERE id = <user_id>` should be `1`
- Check session: `$_SESSION['profile_incomplete']` should not be set or should be `false`

### 2. Event Registration via External Forms

**Setup:**
1. Log in as a Board member or Head (users with event creation permissions)

**Test Steps:**
1. Navigate to `/pages/events/index.php`
2. Click "Neues Event" button
3. Fill in the event form with required fields:
   - Title
   - Description
   - Start time
   - End time
   - Location (optional)
4. In the "Externe Anmeldung (Microsoft Forms Link)" field, enter a test URL:
   - Example: `https://forms.office.com/Pages/ResponsePage.aspx?id=test123`
5. Click "Speichern" (Save)
6. Navigate to the event's detail page
7. **Expected:** The "Jetzt anmelden" (Register Now) button should open the Microsoft Forms URL in a new tab
8. Click the button
9. **Expected:** The Microsoft Forms URL opens in a new browser tab

**Verification:**
- Check database: `SELECT registration_link FROM events WHERE id = <event_id>` should contain the URL
- Check button behavior: Button should have `target="_blank"` and `rel="noopener noreferrer"`
- Priority check: If `registration_link` is set, it should take precedence over `external_link` and internal registration

### 3. Microsoft Forms Integration (Polls)

**Setup:**
1. Log in as a Board member or Head

**Test Steps:**
1. Navigate to `/pages/polls/create.php`
2. Look for the "Microsoft Forms URL" field
3. **Expected:** Below the input field, you should see two help texts:
   - First: "Fügen Sie die Embed-URL oder die direkte URL zu Ihrem Microsoft Forms ein."
   - Second (in blue): "Hinweis: Microsoft bietet keine API zum automatischen erstellen. Bitte erstellen Sie das Formular manuell auf forms.office.com und fügen Sie hier den Einbettungs-Code ein."

**Verification:**
- Help text should be visible and properly formatted
- Help text should guide users to manually create forms on forms.office.com

### 4. Create Project and Event Buttons

**Setup:**
1. Test with different user roles:
   - Board member (board_finance, board_internal, board_external)
   - Head
   - Alumni Board
   - Regular member (should NOT see buttons)

**Test Steps for Board/Head/Alumni Board:**
1. Navigate to `/pages/projects/index.php`
2. **Expected:** "Neues Projekt" button should be visible in the top-right corner
3. Click the button
4. **Expected:** User is redirected to `/pages/projects/manage.php?new=1`
5. Navigate to `/pages/events/index.php`
6. **Expected:** "Neues Event" button should be visible in the top-right corner
7. Click the button
8. **Expected:** User is redirected to `/pages/events/edit.php` (event creation form)

**Test Steps for Regular Member:**
1. Navigate to `/pages/projects/index.php`
2. **Expected:** "Neues Projekt" button should NOT be visible
3. Navigate to `/pages/events/index.php`
4. **Expected:** "Neues Event" button should NOT be visible

**Verification:**
- Button visibility is controlled by permissions: `Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['head', 'alumni_board'])`
- Regular members should not have these permissions

## Regression Testing

### Areas to Check:
1. **Profile Updates:** Ensure existing users can still update their profiles normally
2. **Event Creation:** Verify that event creation works with and without `registration_link`
3. **Event Registration:** Test internal event registration still works when `registration_link` is NOT set
4. **Session Management:** Verify logout and re-login works correctly
5. **Microsoft OAuth:** Test Microsoft login flow with profile completion

## Database Schema Verification

After applying the migration, verify the events table schema:

```sql
DESCRIBE events;
```

Expected output should include:
```
| registration_link | text | YES  |     | NULL |                                                                              |
```

Position should be after `external_link` column.

## Known Limitations

1. **Database Connection:** The migration script requires a working database connection. It will not work in sandbox/CI environments without database access.
2. **Existing Events:** Events created before the migration will have `registration_link = NULL` by default, which is the expected behavior.
3. **Profile Completion for Existing Users:** Existing users should have `profile_complete = 1` by default to avoid disruption. Only new users or users with incomplete profiles should be redirected.

## Troubleshooting

### Issue: User stuck on profile page
**Solution:** Check if profile is being saved correctly. Verify database update query is executing successfully. Check logs for errors.

### Issue: registration_link not saving
**Solution:** Verify SQL migration was applied successfully. Check database schema. Verify Event model is handling the field correctly.

### Issue: Create buttons not showing
**Solution:** Check user role and permissions. Verify user is logged in. Check browser console for JavaScript errors.
