<?php

/**
 * Plugin Name: Email SMTP
 * Plugin URI: https://rue-de-la-vieille.fr
 * Author: Jérôme Mulsant
 * Author URI: https://rue-de-la-vieille.fr
 * Description: Send emails using wp-config.php SMTP settings
 * Text Domain: email-smtp
 * Domain Path: /languages
 * Version: GIT
 *
 * Example configuration with env vars:
 *
 *     EMAIL_SMTP_HOST='smtp.example.com'
 *     EMAIL_SMTP_AUTH=true
 *     EMAIL_SMTP_USERNAME='username@example.com'
 *     EMAIL_SMTP_PASSWORD='P@ssW0rd'
 *     EMAIL_SMTP_SECURE='tls'
 *     EMAIL_SMTP_PORT='587'
 *     EMAIL_FROM_EMAIL='contact@example.com'
 *     EMAIL_FROM_NAME='Example'
 */

use Rdlv\WordPress\EmailSmtp\EmailSmtp;

// Prevent direct execution.
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(EmailSmtp::class)) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        error_log('You need to install dependencies with `composer install`.');
        return;
    }
}

new EmailSmtp(
    array_map(
        function ($var) {
            // get env var, and fallback on constant if defined
            return getenv($var) ?? (defined($var) ? constant($var) : null);
        }, [
               'host' => 'EMAIL_SMTP_HOST',
               'auth' => 'EMAIL_SMTP_AUTH',
               'username' => 'EMAIL_SMTP_USERNAME',
               'password' => 'EMAIL_SMTP_PASSWORD',
               'secure' => 'EMAIL_SMTP_SECURE',
               'port' => 'EMAIL_SMTP_PORT',
               'fromEmail' => 'EMAIL_FROM_EMAIL',
               'fromName' => 'EMAIL_FROM_NAME',
               'emailForceTo' => 'EMAIL_FORCE_TO',
           ]
    )
);
