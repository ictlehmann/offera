# Administration Section - User Guide

## Overview

The IBC Intranet Administration section provides comprehensive tools for managing users, monitoring system health, viewing statistics, auditing system activity, and configuring system settings. This guide covers all administration features available to board members.

## Access Requirements

- **Admin Dashboard**: Board members only (`Auth::isAdmin()`)
- **User Management**: Board members with user management permissions (`Auth::canManageUsers()`)
- **Statistics**: Board members only
- **Audit Logs**: Board members only
- **System Health**: Board members only
- **Settings**: Board members only

## Features

### 1. Admin Dashboard (`/pages/admin/index.php`)

The central hub for all administration activities, providing:

#### Key Metrics
- **Total Users**: Total number of registered users in the system
- **Active Users (7 days)**: Users who logged in within the last 7 days
- **System Errors (24h)**: Count of error events in the last 24 hours
- **Database Size**: Total size of user and content databases in MB

#### Quick Actions
Direct links to all admin sections:
- User Management
- Statistics
- Audit Logs
- System Health & Maintenance
- System Settings

#### System Activity
- Top 5 most frequent system actions in the last 24 hours
- Visual representation of action frequency

#### Security Alerts
Automatic warnings when:
- Failed login attempts exceed 10 in 24 hours
- System errors exceed 20 in 24 hours

#### Additional Metrics
- Open invitation count
- Activity log count (24h)
- Failed login attempts (24h)

### 2. User Management (`/pages/admin/users.php`)

Comprehensive user administration interface with:

#### Features
- **User List**: View all registered users with profiles
- **Search & Filter**: 
  - Search by email or ID
  - Filter by role
  - Sort options
- **Role Management**: Change user roles (requires board permissions)
- **Alumni Validation**: Approve or revoke alumni profile status
- **User Invitations**: Create invitation tokens with custom expiry
- **Bulk Operations**: Mass invitation functionality
- **User Deletion**: Remove users from the system

#### User Statistics
- Total user count
- Active users today (logged in within 24 hours)
- Quick navigation to bulk invite feature

### 3. Statistics (`/pages/admin/stats.php`)

System-wide statistics dashboard showing:

#### User Metrics
- Active users (7-day period)
- Total user count with trends
- New users in the last 7 days
- Recent user activity (last 10 logins)

#### Invitation Metrics
- Open invitations count
- Invitation trends

#### Content Metrics
- Project statistics
- Event statistics
- Inventory statistics
- Write-off statistics

### 4. Audit Logs (`/pages/admin/audit.php`)

Complete system activity logging with:

#### Features
- **Comprehensive Logging**: All system actions are logged
- **Filtering Options**:
  - Filter by action type (login, create, update, delete, etc.)
  - Filter by user ID
- **Detailed Information**:
  - Timestamp
  - User ID
  - Action type (color-coded)
  - Entity type and ID
  - Action details
  - IP address
- **Pagination**: Browse through logs efficiently (100 per page)

#### Action Types
- `login` / `logout` - User authentication events
- `login_failed` - Failed login attempts
- `create` / `update` / `delete` - CRUD operations
- `invitation` - User invitation events
- Custom action types based on system modules

### 5. System Health & Maintenance (`/pages/admin/db_maintenance.php`)

Comprehensive system monitoring and maintenance tools:

#### System Health Dashboard
Real-time monitoring of:
- **Database Status**: Connection health for both databases
- **Error Count (24h)**: Recent system errors with warning thresholds
- **Security Status**: Failed login attempts monitoring
- **System Activity**: Recent login activity
- **Active Sessions**: Current active user sessions
- **Database Size**: Storage usage monitoring
- **Uptime**: Estimated system uptime

#### Health Status Indicators
- ✓ **Healthy**: Green indicators, system operating normally
- ⚠ **Warning**: Yellow indicators, elevated activity detected
- ✗ **Error**: Red indicators, issues requiring attention

#### Database Overview
- User database size and table breakdown
- Content database size and table breakdown
- Individual table statistics (rows and size)

#### Maintenance Actions
1. **Clean Logs**:
   - Deletes user sessions older than 30 days
   - Removes system logs older than 1 year
   - Removes inventory history older than 1 year
   - Removes event history older than 1 year
   
2. **Clear Cache**:
   - Removes all cache files
   - Frees up disk space
   - Does not affect database data

#### Safety Features
- Confirmation dialogs for all destructive actions
- Detailed feedback on maintenance results
- Action logging for audit trail

### 6. System Settings (`/pages/admin/settings.php`)

Configuration management for system-wide settings:

#### General Settings
- **Site Name**: Display name for the intranet
- **Site Description**: Brief description of the site
- **Maintenance Mode**: Enable/disable to restrict access during maintenance
- **Allow Registration**: Toggle user registration (when not using invitation-only)

#### Email Notification Settings
- **Admin Email Address**: Primary contact for system notifications
- **New User Notifications**: Receive alerts when new users register
- **New Event Notifications**: Receive alerts when events are created

#### Security Settings
- **Session Timeout**: Duration before user sessions expire (300-86400 seconds)
  - Default: 3600 seconds (1 hour)
- **Max Login Attempts**: Failed login limit before lockout (3-10 attempts)
  - Default: 5 attempts
- **Log Retention Days**: How long to keep audit logs (30-730 days)
  - Default: 365 days (1 year)

#### Settings Storage
- All settings stored in `system_settings` table
- Automatically creates table if not exists
- Settings changes are logged to audit logs
- Each setting tracks the last user who updated it

## Navigation

All admin features are accessible via:
1. **Sidebar Navigation**: Under "Administration" section
2. **Admin Dashboard**: Quick action buttons
3. **Direct URLs**: `/pages/admin/[page].php`

### Navigation Menu Structure
```
Administration
├── Dashboard (index.php)
├── Benutzer (users.php)
├── Statistiken (stats.php)
├── Audit Logs (audit.php)
├── System Health (db_maintenance.php)
└── Einstellungen (settings.php)
```

## Best Practices

### Security
1. Regularly review audit logs for suspicious activity
2. Monitor failed login attempts
3. Keep session timeout reasonable (not too long)
4. Review and adjust log retention based on compliance needs

### Maintenance
1. Clean logs periodically to manage database size
2. Monitor database growth trends
3. Clear cache when performance issues occur
4. Review system health dashboard weekly

### User Management
1. Use role-based access control appropriately
2. Validate alumni profiles before approval
3. Set appropriate invitation expiry times
4. Use bulk invite for new member cohorts

### Settings
1. Document any changes to system settings
2. Test maintenance mode before using in production
3. Keep admin email address up to date
4. Adjust security settings based on threat assessment

## Technical Details

### Database Tables Used
- `users` - User accounts (in user database)
- `invitation_tokens` - User invitations (in user database)
- `user_sessions` - Active sessions (in user database)
- `system_logs` - Audit trail (in content database)
- `system_settings` - Configuration (in content database)

### Permission Checks
- `Auth::isBoard()` - Board member access
- `Auth::isAdmin()` - Admin-level access
- `Auth::canManageUsers()` - User management permissions
- `Auth::canViewAdminStats()` - Statistics viewing permissions

### Logging
All administrative actions are automatically logged to `system_logs` with:
- User ID
- Action type
- Entity type and ID
- Detailed description
- IP address
- User agent
- Timestamp

## Troubleshooting

### Cannot Access Admin Pages
- Verify user has board member role
- Check session is active
- Confirm user has appropriate permissions

### Settings Not Saving
- Check database connection
- Verify `system_settings` table exists
- Review error logs for SQL errors

### System Health Shows Errors
- Review audit logs for error details
- Check database connectivity
- Verify file permissions for cache directory

### High Failed Login Count
- Review audit logs for suspicious IPs
- Consider adjusting max login attempts
- Investigate potential brute force attempts

## Support

For technical issues or questions about the administration features, please contact the system administrators or refer to the main project documentation.

## Changelog

### Version 1.0 (February 2026)
- Initial implementation of admin dashboard
- Enhanced system health monitoring
- Implemented system settings page
- Fixed navigation for admin sections
- Added comprehensive metrics and alerts
- Integrated dark mode support
