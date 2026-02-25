# Verification Checklist for Microsoft Entra Roles & Navigation Changes

## Pre-Deployment Verification (Completed ✓)

- [x] PHP syntax check on all modified files
  - Auth.php: ✓ No errors
  - main_layout.php: ✓ No errors
  - pages/auth/settings.php: ✓ No errors
  - pages/admin/settings.php: ✓ No errors

- [x] Code review completed
  - Initial review: ✓ Passed with minor style issues
  - Style fixes applied: ✓ Completed
  - Final review: ✓ All issues resolved

- [x] Security scan (CodeQL)
  - ✓ No vulnerabilities detected

## Post-Deployment Verification (User Testing Required)

### 1. Navigation Structure Testing

#### For All Users
- [ ] Login as a regular user (member, candidate, or honorary_member)
- [ ] Verify "Einstellungen" appears in main navigation under "Umfragen"
- [ ] Click "Einstellungen" - should navigate to `/pages/auth/settings.php`
- [ ] Verify "Einstellungen" is NOT in user profile section at bottom of sidebar
- [ ] Verify NO "Systemeinstellungen" appears in Administration section
- [ ] Screenshot: main-nav-regular-user.png

#### For Board Members (Finance, Internal, External)
- [ ] Login as board_finance user
- [ ] Verify "Einstellungen" appears in main navigation under "Umfragen"
- [ ] Verify "Systemeinstellungen" appears in Administration section
- [ ] Click "Systemeinstellungen" - should navigate to `/pages/admin/settings.php`
- [ ] Verify icon is fa-cogs (double gear) for system settings
- [ ] Screenshot: admin-nav-board-member.png

#### For Alumni Board & Alumni Auditor (NEW ACCESS)
- [ ] Login as alumni_board user
- [ ] Verify "Einstellungen" appears in main navigation
- [ ] Verify "Systemeinstellungen" appears in Administration section (THIS IS NEW)
- [ ] Click "Systemeinstellungen" - should successfully load page
- [ ] Verify can view and modify settings
- [ ] Screenshot: admin-nav-alumni-board.png

### 2. User Settings Page Testing (/pages/auth/settings.php)

#### Access Testing
- [ ] Access as regular user - should load successfully
- [ ] Access as board member - should load successfully
- [ ] Access as alumni board - should load successfully

#### Page Content
- [ ] Verify page title is "Einstellungen"
- [ ] Verify Microsoft notice is displayed
- [ ] Verify current profile section (read-only) shows email and role
- [ ] Screenshot: user-settings-overview.png

#### 2FA Section
- [ ] Verify "Zwei-Faktor-Authentifizierung (2FA)" section is present
- [ ] If 2FA is disabled:
  - [ ] Verify status shows "Deaktiviert" badge
  - [ ] Verify "2FA aktivieren" button is present
  - [ ] Click "2FA aktivieren"
  - [ ] Verify QR code is generated and displayed
  - [ ] Verify secret key is shown
  - [ ] Scan QR code with authenticator app
  - [ ] Enter 6-digit code
  - [ ] Click "Bestätigen"
  - [ ] Verify success message "2FA erfolgreich aktiviert"
  - [ ] Screenshot: 2fa-qr-setup.png
- [ ] If 2FA is enabled:
  - [ ] Verify status shows "Aktiviert" badge (green)
  - [ ] Verify "2FA deaktivieren" button is present
  - [ ] Click "2FA deaktivieren"
  - [ ] Confirm in dialog
  - [ ] Verify success message "2FA erfolgreich deaktiviert"
  - [ ] Screenshot: 2fa-enabled-status.png

#### Notifications Section
- [ ] Verify "Benachrichtigungen" section is present
- [ ] Verify "Neue Projekte" checkbox
- [ ] Toggle checkbox on/off
- [ ] Click "Benachrichtigungseinstellungen speichern"
- [ ] Verify success message
- [ ] Verify "Neue Events" checkbox
- [ ] Toggle checkbox on/off
- [ ] Click "Benachrichtigungseinstellungen speichern"
- [ ] Verify success message
- [ ] Screenshot: notification-settings.png

#### Design Settings Section
- [ ] Verify "Design-Einstellungen" section is present
- [ ] Verify three theme options: Hellmodus, Dunkelmodus, Automatisch
- [ ] Click each theme option
- [ ] Verify visual selection highlight changes
- [ ] Click "Design-Einstellungen speichern"
- [ ] Verify success message
- [ ] Verify theme actually changes in UI
- [ ] Screenshot: theme-settings.png

### 3. System Settings Page Testing (/pages/admin/settings.php)

#### Access Control Testing
- [ ] Login as regular user (member)
  - [ ] Try to directly access `/pages/admin/settings.php`
  - [ ] Verify redirect to `/index.php` (access denied)
- [ ] Login as board_finance
  - [ ] Access `/pages/admin/settings.php`
  - [ ] Verify page loads successfully
- [ ] Login as alumni_board (NEW)
  - [ ] Access `/pages/admin/settings.php`
  - [ ] Verify page loads successfully (THIS IS NEW ACCESS)
- [ ] Login as alumni_auditor (NEW)
  - [ ] Access `/pages/admin/settings.php`
  - [ ] Verify page loads successfully (THIS IS NEW ACCESS)

#### Page Content
- [ ] Verify page title is "Systemeinstellungen" (not "System Einstellungen")
- [ ] Verify breadcrumb or heading shows "Systemeinstellungen"
- [ ] Screenshot: system-settings-title.png

#### General Settings Section
- [ ] Verify "Allgemeine Einstellungen" section is present
- [ ] Verify fields:
  - [ ] Website-Name (text input)
  - [ ] Website-Beschreibung (textarea)
  - [ ] Wartungsmodus aktivieren (checkbox)
  - [ ] Registrierung erlauben (checkbox)
- [ ] Modify values
- [ ] Click "Einstellungen speichern"
- [ ] Verify success message "Einstellungen erfolgreich gespeichert"
- [ ] Reload page and verify values are saved
- [ ] Screenshot: general-settings.png

#### Security Settings Section
- [ ] Verify "Sicherheitseinstellungen" section is present
- [ ] Verify fields:
  - [ ] Session Timeout (number input, 300-86400)
  - [ ] Max. Login-Versuche (number input, 3-10)
  - [ ] Log-Aufbewahrung (number input, 30-730)
- [ ] Modify values
- [ ] Click "Sicherheitseinstellungen speichern"
- [ ] Verify success message "Sicherheitseinstellungen erfolgreich gespeichert"
- [ ] Reload page and verify values are saved
- [ ] Screenshot: security-settings.png

#### Email Notifications Section (REMOVED)
- [ ] Verify "E-Mail Benachrichtigungen" section is NOT present
- [ ] Verify NO fields for:
  - Admin E-Mail Adresse
  - Bei neuen Benutzern benachrichtigen
  - Bei neuen Events benachrichtigen
- [ ] Screenshot: system-settings-no-email.png

### 4. Microsoft Entra Role Testing

#### Role Syncing
- [ ] Login with Microsoft Entra account
- [ ] Check database `users` table for user:
  - [ ] Verify `entra_roles` column is populated with JSON array
  - [ ] Example: `["Vorstand Finanzen und Recht", "IBC Mitglied"]`
- [ ] Check user profile section in sidebar:
  - [ ] Verify Entra roles are displayed as badges
  - [ ] Verify roles are human-readable (German names)
  - [ ] Screenshot: entra-roles-display.png

#### Permission Verification
- [ ] User with Entra group "Vorstand Finanzen und Recht"
  - [ ] Should have `board_finance` internal role
  - [ ] Should see "Systemeinstellungen" in Administration
  - [ ] Should be able to access system settings
- [ ] User with Entra group "Alumni-Vorstand"
  - [ ] Should have `alumni_board` internal role
  - [ ] Should see "Systemeinstellungen" in Administration (NEW)
  - [ ] Should be able to access system settings (NEW)
- [ ] User with Entra group "Alumni-Finanzprüfer"
  - [ ] Should have `alumni_auditor` internal role
  - [ ] Should see "Systemeinstellungen" in Administration (NEW)
  - [ ] Should be able to access system settings (NEW)

### 5. Cross-Browser Testing

#### Desktop Browsers
- [ ] Chrome/Edge (latest)
  - [ ] Navigation appears correctly
  - [ ] Settings pages function correctly
  - [ ] 2FA QR code displays correctly
- [ ] Firefox (latest)
  - [ ] Navigation appears correctly
  - [ ] Settings pages function correctly
  - [ ] 2FA QR code displays correctly
- [ ] Safari (latest, if available)
  - [ ] Navigation appears correctly
  - [ ] Settings pages function correctly
  - [ ] 2FA QR code displays correctly

#### Mobile Browsers
- [ ] iOS Safari
  - [ ] Navigation hamburger menu works
  - [ ] Settings pages are responsive
  - [ ] Touch interactions work
- [ ] Android Chrome
  - [ ] Navigation hamburger menu works
  - [ ] Settings pages are responsive
  - [ ] Touch interactions work

### 6. Dark Mode Testing
- [ ] Switch to Dark Mode
- [ ] Verify all settings pages render correctly in dark mode
- [ ] Verify proper contrast and readability
- [ ] Verify QR code is visible in dark mode
- [ ] Screenshot: dark-mode-user-settings.png
- [ ] Screenshot: dark-mode-system-settings.png

### 7. Database Verification

#### Check System Settings Table
```sql
-- Verify system_settings table exists
SHOW TABLES LIKE 'system_settings';

-- Check current settings
SELECT * FROM system_settings;

-- Verify no email notification settings exist
SELECT * FROM system_settings WHERE setting_key IN ('admin_email', 'notify_on_new_user', 'notify_on_new_event');
-- Should return 0 rows or NULL values
```

#### Check Users Table
```sql
-- Verify entra_roles column exists
DESCRIBE users;

-- Check a user's roles
SELECT id, email, role, entra_roles FROM users WHERE email = 'test@example.com';

-- Verify entra_roles is valid JSON
SELECT id, email, JSON_VALID(entra_roles) as is_valid_json FROM users WHERE entra_roles IS NOT NULL;
```

### 8. Regression Testing

#### Ensure Existing Features Work
- [ ] User management page still works
- [ ] Admin dashboard still works
- [ ] Statistics page still works
- [ ] Audit logs page still works
- [ ] System health page still works
- [ ] User profile page still works (2FA section should still be there for backward compatibility)

#### Check Other Settings
- [ ] Theme switching still works from bottom of sidebar
- [ ] Logout button still works
- [ ] Profile link still works

## Issues Found

### Critical Issues
_List any critical issues that prevent core functionality_

### Non-Critical Issues
_List any cosmetic or minor functional issues_

### Recommendations
_List any recommendations for improvements_

## Sign-Off

- [ ] All critical issues resolved
- [ ] All functionality tested and working
- [ ] Screenshots collected
- [ ] Database verified
- [ ] Ready for production

**Tester Name:** ___________________

**Date:** ___________________

**Signature:** ___________________
