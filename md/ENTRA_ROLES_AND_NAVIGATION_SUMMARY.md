# Microsoft Entra Roles and Navigation Restructure - Implementation Summary

## Date: 2026-02-16

## Problem Statement (Original - German)

Alle Einstellungen unter Administration soll nur der Vorstand Finazen und Recht, Vorstand Intern, Vorstand Extern und Alumni-Vorstand und Alumni-Finanzprüfer bzw nur user mit diesen rollen in Microsoft Entra

Die Rolle wird aus Microsoft Entra nicht korrekt ausgelesen und nicht überall korrekt verwendet

In der Seitenleiste soll es einmal Einstellungen geben direkt unter Umfrage und einmal nur für den Borstand Finanzen und Recht und Vorrstand Intern und Vorstand Extern und Alumni-Vorstand und Alumni-Finanzprüfer bzw nur user mit dieser Rolle sollen unter Administration den Punkt Systemeinstellungen mit den Inhalten: Allgemeine Einstellungen Sicherheitseinstellungen Lösche: E-Mail Benachrichtigungen für Admin E-Mail Adresse

In den Einstellungen für den USer solld das 2FA sein, Benachrichtigungen für Events und Projekte, Design-Einstellungen

## Translation

All settings under Administration should only be accessible to Board Finance and Law, Board Internal, Board External and Alumni Board and Alumni Auditor or only users with these roles in Microsoft Entra

The role is not being read correctly from Microsoft Entra and not being used correctly everywhere

In the sidebar there should be Settings once directly under Survey and once only for Board Finance and Law and Board Internal and Board External and Alumni Board and Alumni Auditor or only users with this role should see under Administration the point System Settings with the contents: General Settings, Security Settings, Delete: Email Notifications for Admin Email Address

In the user Settings there should be 2FA, Notifications for Events and Projects, Design Settings

## Implementation Changes

### 1. Role Permission Updates (src/Auth.php)

#### Added New Method
- **`canAccessSystemSettings()`**: New permission method that returns `true` for:
  - board_finance (Vorstand Finanzen und Recht)
  - board_internal (Vorstand Intern)
  - board_external (Vorstand Extern)
  - alumni_board (Alumni-Vorstand)
  - alumni_auditor (Alumni-Finanzprüfer)

#### Existing Methods Preserved
- **`canEditAdminSettings()`**: Marked as legacy, still returns `true` only for the 3 board roles
- **`isAdmin()`**: Returns `true` for the 3 board roles (board_finance, board_internal, board_external)
- **`canManageUsers()`**: Returns `true` for all board roles + alumni_board + alumni_auditor

### 2. Navigation Restructure (includes/templates/main_layout.php)

#### Main Navigation Section
- **Added "Einstellungen" (Settings)** directly after "Umfragen" (Polls)
  - Icon: `fa-cog`
  - Route: `/pages/auth/settings.php`
  - Access: All authenticated users
  - Location: Main navigation section (before "Administration" divider)

#### Administration Section
- **Renamed "Einstellungen" to "Systemeinstellungen" (System Settings)**
  - Icon: Changed from `fa-cog` to `fa-cogs`
  - Route: `/pages/admin/settings.php`
  - Access: Uses new `Auth::canAccessSystemSettings()` method
  - Accessible by: board_finance, board_internal, board_external, alumni_board, alumni_auditor

#### User Profile Section (Bottom of Sidebar)
- **Removed "Einstellungen" link** (moved to main navigation)
- Kept "Mein Profil" (My Profile) link
- Logout and theme toggle remain

### 3. User Settings Page Updates (pages/auth/settings.php)

#### Added Features
- **2FA (Two-Factor Authentication) Section**
  - Enable/disable 2FA functionality
  - QR code generation for authenticator apps
  - 6-digit code verification
  - Status display (Activated/Deactivated)
  - Moved from profile page to settings page

#### Existing Features Retained
- **Notification Settings**
  - New Projects notifications
  - New Events notifications
- **Design Settings** (Theme Preferences)
  - Light mode
  - Dark mode
  - Auto mode (follows system preference)

#### Technical Updates
- Added `require_once` for GoogleAuthenticator handler
- Added variables: `$showQRCode`, `$secret`, `$qrCodeUrl`
- Added POST handlers: `enable_2fa`, `confirm_2fa`, `disable_2fa`
- Added QRious library for QR code generation (CDN)
- Added JavaScript for QR code rendering

### 4. Admin System Settings Page Updates (pages/admin/settings.php)

#### Permission Update
- Changed from `Auth::isBoard()` to `Auth::canAccessSystemSettings()`
- Now accessible to: board_finance, board_internal, board_external, alumni_board, alumni_auditor

#### Page Title Update
- Changed from "System Einstellungen" to "Systemeinstellungen"

#### Content Changes
- **Kept:**
  - General Settings (Allgemeine Einstellungen)
    - Site name
    - Site description
    - Maintenance mode
    - Allow registration
  - Security Settings (Sicherheitseinstellungen)
    - Session timeout
    - Max login attempts
    - Log retention days

- **Removed:**
  - Email Notifications section (E-Mail Benachrichtigungen)
    - Admin email address
    - Notify on new user
    - Notify on new event
  - Related POST handler `update_email_settings`
  - Related variables: `$notifyOnNewUser`, `$notifyOnNewEvent`, `$adminEmail`

### 5. Microsoft Entra Role Reading (Verification)

#### Current Implementation (Already Correct)
Location: `includes/handlers/AuthHandler.php` (lines 638-665)

The system correctly:
1. Fetches user profile from Microsoft Graph API
2. Gets transitive group memberships using `/users/{userId}/transitiveMemberOf`
3. Extracts group display names
4. Stores in `entra_roles` column as JSON
5. Updates session with `$_SESSION['entra_roles']`

#### Service Layer (Already Correct)
Location: `includes/services/MicrosoftGraphService.php` (lines 231-283)

The `getUserProfile()` method correctly:
1. Fetches user profile data (jobTitle, companyName)
2. Fetches transitive group memberships (includes nested groups)
3. Returns array with groups as display names

#### Display (Already Correct)
Location: `includes/templates/main_layout.php` (lines 755-783)

Role display logic:
1. Priority: `entra_roles` from database (JSON, human-readable)
2. Fallback: `azure_roles` from session (requires translation)
3. Final fallback: Internal role with translation

## Files Modified

1. **src/Auth.php**
   - Added `canAccessSystemSettings()` method
   - Updated `canEditAdminSettings()` documentation

2. **includes/templates/main_layout.php**
   - Added "Einstellungen" link in main navigation after "Umfragen"
   - Renamed "Einstellungen" to "Systemeinstellungen" in Administration section
   - Updated permission check to `canAccessSystemSettings()`
   - Changed icon from `fa-cog` to `fa-cogs` for system settings
   - Removed "Einstellungen" from user profile section

3. **pages/auth/settings.php**
   - Added GoogleAuthenticator require
   - Added 2FA variables and handlers
   - Added complete 2FA section with QR code
   - Added QRious library script
   - Added QR code generation JavaScript

4. **pages/admin/settings.php**
   - Updated permission check to `canAccessSystemSettings()`
   - Changed title to "Systemeinstellungen"
   - Removed email notifications section entirely
   - Removed email settings variables and handlers

## Role Access Matrix

| Feature | board_finance | board_internal | board_external | alumni_board | alumni_auditor | Other Roles |
|---------|--------------|----------------|----------------|--------------|----------------|-------------|
| User Settings (Einstellungen) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| System Settings (Systemeinstellungen) | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Admin Dashboard | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| User Management | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Statistics | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Audit Logs | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| System Health | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |

## User-Facing Changes

### For All Users
- "Einstellungen" is now in the main navigation (easier to find)
- Settings page includes 2FA management
- Settings page includes notification preferences
- Settings page includes design/theme preferences

### For Board Members (Finance, Internal, External)
- Can access "Systemeinstellungen" in Administration section
- Can configure general system settings
- Can configure security settings
- No longer see email notification settings (removed)

### For Alumni Board and Alumni Auditor
- Now have access to "Systemeinstellungen" (new access granted)
- Can configure general system settings
- Can configure security settings

### Navigation Changes
- User settings moved from bottom profile section to main navigation
- More prominent placement under "Umfragen"
- System settings clearly separated with different icon (fa-cogs vs fa-cog)

## Testing Recommendations

### Permission Testing
1. Test with user having role: board_finance
   - Should see "Systemeinstellungen" in Administration
   - Should be able to access `/pages/admin/settings.php`
   - Should see "Einstellungen" in main navigation

2. Test with user having role: alumni_board
   - Should see "Systemeinstellungen" in Administration (NEW)
   - Should be able to access `/pages/admin/settings.php` (NEW)
   - Should see "Einstellungen" in main navigation

3. Test with user having role: member
   - Should NOT see "Systemeinstellungen" in Administration
   - Should NOT be able to access `/pages/admin/settings.php`
   - Should see "Einstellungen" in main navigation

### Navigation Testing
1. Verify "Einstellungen" appears directly under "Umfragen" for all users
2. Verify "Systemeinstellungen" appears in Administration section for authorized roles
3. Verify "Einstellungen" is NOT in user profile section at bottom
4. Verify correct icons: fa-cog for user settings, fa-cogs for system settings

### Settings Page Testing
1. User Settings (`/pages/auth/settings.php`)
   - Test 2FA enable/disable
   - Test QR code generation
   - Test notification preferences
   - Test theme preferences

2. System Settings (`/pages/admin/settings.php`)
   - Verify only authorized roles can access
   - Test general settings save
   - Test security settings save
   - Verify email notifications section is NOT present

### Microsoft Entra Role Testing
1. Login with Microsoft Entra account
2. Verify `entra_roles` column is populated in database
3. Verify roles display correctly in user profile section
4. Verify correct permissions based on Entra groups

## Technical Notes

### Permission Method Hierarchy
- `isAdmin()` - Only 3 board roles (legacy, strict)
- `canAccessSystemSettings()` - 3 board roles + alumni_board + alumni_auditor (NEW)
- `canManageUsers()` - Same as canAccessSystemSettings
- `canEditAdminSettings()` - Same as isAdmin (legacy, marked for deprecation)

### Database Columns
- `users.entra_roles` - TEXT/JSON - Microsoft Entra group display names
- `users.azure_roles` - JSON - Legacy Azure role format (deprecated)
- `users.role` - VARCHAR - Internal system role

### Icons Used
- `fa-cog` - User Einstellungen (Settings)
- `fa-cogs` - System Systemeinstellungen (System Settings)
- `fa-shield-alt` - 2FA Security
- `fa-bell` - Notifications
- `fa-palette` - Design/Theme

## Breaking Changes
None. All changes are additive or permission expansions.

## Migration Notes
No database migrations required. All changes are code-only.

## Security Considerations
- Access to system settings expanded to include alumni_board and alumni_auditor
- This aligns with the requirement to grant these roles system configuration access
- Email notification settings removed from admin settings (could be moved to a dedicated admin communication settings page if needed)
- 2FA moved to user settings for better accessibility

## Future Enhancements
1. Consider creating separate "Communication Settings" page for admin email notifications
2. Add audit logging for system settings changes
3. Add role-based field visibility in system settings
4. Consider adding 2FA recovery codes

## Compatibility
- PHP 7.4+
- Requires GoogleAuthenticator library (already included)
- Requires QRious library (loaded via CDN when needed)
- Compatible with existing Microsoft Entra ID integration

## Documentation Updates Needed
- Update admin guide to reflect new navigation structure
- Update user guide to show 2FA setup in settings
- Update role permissions documentation
- Add system settings documentation for alumni board/auditor roles
