# PHPMailer Setup

## Installation

PHPMailer is managed via Composer. To install dependencies:

```bash
composer install
```

## Usage

### From Any PHP File

PHPMailer is available via Composer's autoloader. Include it at the beginning of your PHP file:

```php
// From root directory
require_once __DIR__ . '/vendor/autoload.php';

// From subdirectory (e.g., api/, pages/admin/)
require_once __DIR__ . '/../vendor/autoload.php';

// From nested subdirectory (e.g., pages/admin/reports/)
require_once __DIR__ . '/../../vendor/autoload.php';

// Then use PHPMailer
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
```

### Using MailService

The project includes a `MailService` class (in `src/MailService.php`) that wraps PHPMailer with the project's email configuration. When you include MailService, it automatically defines the `MAIL_SERVICE_VENDOR_AVAILABLE` constant to indicate whether PHPMailer is properly installed:

```php
require_once __DIR__ . '/../src/MailService.php';

// The MAIL_SERVICE_VENDOR_AVAILABLE constant is defined by MailService
// It's true if PHPMailer\PHPMailer\PHPMailer class exists
if (defined('MAIL_SERVICE_VENDOR_AVAILABLE') && MAIL_SERVICE_VENDOR_AVAILABLE) {
    // Send email using MailService methods
    MailService::sendInvitation($email, $token, $role);
} else {
    // Handle case where PHPMailer is not available
    error_log('PHPMailer not available');
}
```

## Important Notes

1. **Dependencies are NOT committed to Git**: The `vendor/` directory contains installed packages and is excluded from version control via `.gitignore`.

2. **Always run `composer install`**: After cloning the repository or pulling changes, run `composer install` to ensure all dependencies are installed.

3. **No manual PHPMailer installation**: Do not manually download and place PHPMailer in the project. Always use Composer.

4. **Relative paths**: When requiring the autoloader from subdirectories, use the correct relative path based on your file's location.

## Troubleshooting

### "PHPMailer class not found"

If you encounter this error:

1. Make sure you've run `composer install`
2. Check that `vendor/phpmailer/phpmailer/` directory exists and contains files
3. Verify you're using the correct relative path to `vendor/autoload.php`

### "Composer not installed"

Install Composer from https://getcomposer.org/

### Verifying Installation

Run this command to verify PHPMailer is installed:

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'PHPMailer installed' : 'PHPMailer missing';"
```
