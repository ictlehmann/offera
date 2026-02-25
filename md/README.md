# IBC Intranet

## 🚨 Critical Issue: Dashboard Error Fix

If you're seeing errors on the dashboard such as:

- `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'p.is_active'`
- `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'e.needs_helpers'`
- `Uncaught SyntaxError: Unexpected token 'export'`
- CSS styling issues

### Quick Fix:

1. **Deploy the latest code** (this repository contains the fix)
2. **Run the database update:**
   ```bash
   php update_database_schema.php
   ```
3. **Clear browser cache** (Ctrl+Shift+Delete or Cmd+Shift+Delete)

### Detailed Instructions

- **For database issues**: See [QUICKFIX.md](QUICKFIX.md)
- **For all issues**: See [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- **For deployment**: See [DEPLOYMENT.md](DEPLOYMENT.md)

### Verification

After deployment, you can verify your schema is correct:

```bash
php verify_database_schema.php
```

## Project Structure

```
/api/                  # API endpoints
/assets/              # CSS, images, uploads
/auth/                # Authentication handlers
/config/              # Configuration files
/cron/                # Scheduled tasks
/includes/            # Core libraries and models
  /handlers/          # Authentication, CSRF, etc.
  /models/            # Data models (Event, User, etc.)
  /services/          # External service integrations
  /templates/         # Layout templates
/pages/               # Application pages
  /dashboard/         # Dashboard
  /events/            # Event management
  /inventory/         # Inventory system
  /polls/             # Polls/surveys
  /projects/          # Project management
/sql/                 # Database schemas
/vendor/              # Composer dependencies

## Features

- Event Management with Helper Signups
- Inventory System with EasyVerein Integration
- Project Management
- Poll/Survey System
- Alumni Management
- Invoice Reimbursement System
- Blog/News System
- Microsoft Entra ID (Azure AD) Authentication

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Composer
- Web server (Apache/Nginx)

## Installation

### Fresh Installation

1. Clone the repository
2. Copy `.env.example` to `.env` and configure
3. Install dependencies: `composer install`
4. Import database schemas:
   ```bash
   mysql -u username -p dbs15253086 < sql/dbs15253086.sql
   mysql -u username -p dbs15161271 < sql/dbs15161271.sql
   mysql -u username -p dbs15251284 < sql/dbs15251284.sql
   ```
5. Run schema updates: `php update_database_schema.php`
6. Configure web server to point to the project root

### Updating Existing Installation

1. Pull latest code: `git pull origin main`
2. Run schema updates: `php update_database_schema.php`
3. Clear any caches if applicable

## Configuration

Configuration is stored in `config/config.php` and loaded from environment variables or `.env` file.

Key settings:
- Database connections (user, content, invoice databases)
- Microsoft Graph API credentials
- EasyVerein API credentials
- Email settings
- Session configuration

## Security

- Microsoft Entra ID (Azure AD) authentication
- Role-based access control
- CSRF protection
- 2FA support (Google Authenticator)
- Secure session management
- SQL injection prevention (PDO prepared statements)
- XSS protection
- Security headers

## Documentation

- [DEPLOYMENT.md](DEPLOYMENT.md) - Deployment guide
- [ROLE_CONFIGURATION.md](ROLE_CONFIGURATION.md) - Microsoft Entra ID role configuration and reference
- [md/SQL_CONSOLIDATION_README.md](md/SQL_CONSOLIDATION_README.md) - SQL migration guide
- [includes/services/README.md](includes/services/README.md) - Service integrations

## Support

For issues or questions:
1. Check the error logs in `/logs/`
2. Review the documentation in `/md/`
3. Verify database schema: `php verify_database_schema.php`
4. Contact the development team

## License

Internal use only - IBC (International Business Consultants e.V.)
