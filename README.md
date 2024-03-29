# Email SMTP

This WordPress plugin loads SMTP configuration from `wp-config.php` constants.

Additionnaly, it adds an admin page under *Settings / Email & SMTP* that:

 * shows the loaded configuration
 * alerts if a plugin is conflicting
 * provides an email sending test form

The sending test displays the client - server SMTP exchange output.

## Installation

### Composer

```bash
composer require jerome-rdlv/email-smtp
```

This will install the plugin as a mu-plugin.

You can then load the plugin from another one or from a theme’s `functions.php`.

## Usage

Example configuration:

```php
define('EMAIL_SMTP_HOST', 'smtp.example.com');
define('EMAIL_SMTP_AUTH', true);
define('EMAIL_SMTP_USERNAME', 'username@example.com');
define('EMAIL_SMTP_PASSWORD', 'P@ssW0rd');
define('EMAIL_SMTP_SECURE', 'tls');
define('EMAIL_SMTP_PORT', '587');
define('EMAIL_FROM_EMAIL', 'contact@example.com');
define('EMAIL_FROM_NAME', 'Example');
```

## Info page screenshot

![](screenshot.png)
