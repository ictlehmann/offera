# Birthday Wishes Cron Script

## Overview
This cron script automatically sends birthday wishes emails to users on their birthday.

## Features
- Automatically detects users with birthdays today
- Sends personalized emails with gender-specific salutations:
  - Female (gender = 'f'): "Liebe [Vorname],"
  - Male (gender = 'm'): "Lieber [Vorname],"
  - Other/Not specified: "Hallo [Vorname],"
- Professional HTML email template with IBC corporate design
- Error handling and logging

## Database Requirements

### Migration
Before using this script, the `users` table must include the `birthday` and `gender` columns. 

Run the migration SQL script:
```bash
mysql -u [username] -p [database_name] < sql/migration_add_birthday_gender.sql
```

Or manually execute the ALTER TABLE statements:
```sql
ALTER TABLE users 
ADD COLUMN birthday DATE DEFAULT NULL 
COMMENT 'User birthday for birthday wishes';

ALTER TABLE users 
ADD COLUMN gender ENUM('m', 'f', 'd') DEFAULT NULL 
COMMENT 'User gender: m=male, f=female, d=diverse';

ALTER TABLE users ADD INDEX idx_birthday (birthday);
```

## Usage

### Manual Execution
Run the script manually to test:
```bash
php cron/send_birthday_wishes.php
```

### Automated Execution (Cron Job)
Add to your crontab to run daily at 9:00 AM:
```
0 9 * * * /usr/bin/php /path/to/offer/cron/send_birthday_wishes.php >> /var/log/birthday_wishes.log 2>&1
```

## Dependencies
- `config/config.php` - Database and SMTP configuration
- `src/Database.php` - Database connection handler
- `src/MailService.php` - Email sending service

## Notes
- The script uses the user's first name from the `alumni_profiles` table
- If no first name is available, it defaults to "Mitglied"
- The script includes a 0.1 second delay between emails to avoid overwhelming the SMTP server
- All emails are sent using the configured SMTP settings from `config/config.php`
