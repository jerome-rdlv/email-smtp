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
 * Example configuration:
 *
 *     define('EMAIL_SMTP_HOST', 'smtp.example.com');
 *     define('EMAIL_SMTP_AUTH', true);
 *     define('EMAIL_SMTP_USERNAME', 'username@example.com');
 *     define('EMAIL_SMTP_PASSWORD', 'P@ssW0rd');
 *     define('EMAIL_SMTP_SECURE', 'tls');
 *     define('EMAIL_SMTP_PORT', '587');
 *     define('EMAIL_FROM_EMAIL', 'contact@example.com');
 *     define('EMAIL_FROM_NAME', 'Example');
 */

namespace Rdlv\WordPress\EmailSmtp;

use PHPMailer\PHPMailer\PHPMailer;

new EmailSmtp();

class EmailSmtp
{
    const TEXTDOMAIN = 'email-smtp';

    const SLUG = 'smtp';

    const PHPMAILER_PROPERTIES = [
        'Host'       => 'EMAIL_SMTP_HOST',
        'SMTPAuth'   => 'EMAIL_SMTP_AUTH',
        'Username'   => 'EMAIL_SMTP_USERNAME',
        'Password'   => 'EMAIL_SMTP_PASSWORD',
        'SMTPSecure' => 'EMAIL_SMTP_SECURE',
        'Port'       => 'EMAIL_SMTP_PORT',
    ];

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_text_domain']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_action_email_smtp_test', [$this, 'email_test']);
        add_action('phpmailer_init', [$this, 'phpmailer_init']);

        if (defined('EMAIL_FORCE_TO') && !empty(EMAIL_FORCE_TO)) {
            add_filter('wp_mail', [$this, 'force_email_recipient']);
        }
    }

    /**
     * Load plugin text domain. This function allows the plugin to be
     * installed anywhere, in plugins/ or in mu-plugins/ for example.
     * @return bool
     */
    public function load_text_domain()
    {
        /** This filter is documented in wp-includes/l10n.php */
        $locale = apply_filters('plugin_locale', determine_locale(), self::TEXTDOMAIN);
        $mofile = self::TEXTDOMAIN . '-' . $locale . '.mo';

        // Try to load from the languages directory first.
        if (load_textdomain(self::TEXTDOMAIN, WP_LANG_DIR . '/plugins/' . $mofile)) {
            return true;
        }

        // Load from plugin languages folder.
        return load_textdomain(self::TEXTDOMAIN, __DIR__ . '/languages/' . $mofile);
    }

    /**
     * Display plugin information page
     */
    public function admin_menu()
    {
        add_submenu_page(
            'tools.php',
            __('Email &amp; SMTP', 'email-smtp'),
            __('Email &amp; SMTP', 'email-smtp'),
            'manage_options',
            self::SLUG,
            [$this, 'info_page']
        );
    }

    public function info_page()
    {
        global $title;

        $phpmailer = $this->get_phpmailer();

        echo '<div class="wrap">';
        echo '<h1>' . $title . '</h1>';

        $test_output = get_site_transient('email_smtp_test_output');
        delete_site_transient('email_smtp_test_output');
        if (!empty($test_output)) {
            echo $test_output;
        }

        $config = $this->get_mailer_configuration();

        if (defined('EMAIL_SMTP_HOST') && EMAIL_SMTP_HOST !== null) {
            if (!$this->check_phpmailer_configuration()) {
                echo '<div class="notice notice-error">';
                echo '<p>';
                esc_html_e('A plugin is overriding the configuration with these differences:', 'email-smtp');
                echo '</p>';

                $diff = [];
                foreach ($config as $key => $value) {
                    if (!isset($phpmailer->{$key}) || $phpmailer->{$key} !== $value) {
                        $diff[$key] = $phpmailer->{$key};
                    }
                }
                $this->display_config($diff);

                echo '</div>';
            }

            echo '<p>';
            echo sprintf(
            /* translators: Placeholder is a PHP file */
                __('Parameters loaded from %s:', 'email-smtp'),
                '<code>wp-config.php</code>'
            );
            echo '</p>';

            $this->display_config($config);
        } else {
            echo '<p>';
            echo sprintf(
            /* translators: Placeholder is a PHP file */
                __('No email configuration in %s.', 'email-smtp'),
                '<code>wp-config.php</code>'
            );
            echo '</p>';
            if ($phpmailer->Mailer !== 'smtp') {
                echo '<p>';
                echo __('Current configuration:', 'email-smtp') . ' ';
                echo sprintf(
                /* translators: Placeholder is a PHP function */
                    __('PHP %s function.', 'email-smtp'),
                    '<code>mail()</code>'
                );
                echo '</p>';
            } else {
                echo '<p>';
                echo __('Current configuration:', 'email-smtp');
                echo '</p>';
                $this->display_config(array_filter((array)$phpmailer));
            }
        }

        // Test form
        echo '<hr>';

        echo '<form method="POST" action="' . admin_url('admin.php') . '">';
        echo '<p>' . __('Send a test message:', 'email-smtp') . '</p>';
        echo '<p>';
        echo '<input type="hidden" name="action" value="email_smtp_test">';
        wp_nonce_field('email_smtp_test');
        echo '<label for="email_smtp_to">' . __('Recipient:', 'email-smtp') . '</label>&nbsp;';
        printf(
            '<input type="email" class="regular-text" name="to" id="email_smtp_to" value="%s">&nbsp;',
            isset($_REQUEST['to']) ? esc_attr($_REQUEST['to']) : ''
        );
        echo '</p>';
        echo '<p>';
        echo sprintf(
            '<input type="submit" class="button button-primary" value="%s">',
            __('Send', 'email-smtp')
        );
        echo '</p>';
        echo '</form>';

        echo '</div>';
    }

    public function email_test()
    {
        if (!empty($_REQUEST['to']) && check_admin_referer('email_smtp_test')) {
            // enable error display
            $display_errors = ini_get('display_errors');
            ini_set('display_errors', 1);
            $error_reporting = error_reporting();
            error_reporting(E_ALL);

            ob_start();

            add_action('phpmailer_init', function ($phpmailer) {
                $phpmailer->SMTPDebug = 2;
                $phpmailer->Timeout = 3;
            });

            $wp_mail_output = wp_mail(
                $_REQUEST['to'],
                __('Email / SMTP test message', 'email-smtp'),
                sprintf(
                /* translators: Placeholder is the website URL */
                    __('Email sending from website %s is working.', 'email-smtp'),
                    get_home_url()
                )
            );

            $output = ob_get_clean();

            // disable error display
            ini_set('display_errors', $display_errors);
            error_reporting($error_reporting);

            // Authentication obfuscation
            $output = preg_replace_callback('/(334 .*?\n.*?: )([^\n:]+)/i', function ($m) {
                return $m[1] . str_pad('', strlen($m[2]) * 3, '•');
            }, $output);

            set_site_transient('email_smtp_test_output', sprintf(
                '<div class="notice notice-%s"><p>%s <code>%s</code></p>%s</div>',
                $wp_mail_output ? 'success' : 'error',
                __('Test result:', 'email-smtp'),
                $wp_mail_output ? 'TRUE' : 'FALSE',
                $output ? '<pre style="font-size:12px;white-space:pre-wrap;">' . esc_html($output) . '</pre>' : ''
            ));
        }

        wp_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit();
    }

    /**
     * @param array $config
     */
    private function display_config($config)
    {
        echo '<table>';

        foreach (self::PHPMAILER_PROPERTIES as $key => $constant) {
            if (!isset($config[$key])) {
                continue;
            }

            $value = $config[$key];

            // Password obfuscation
            if (stripos($key, 'PASS') !== false
                || (defined('EMAIL_SMTP_PASSWORD') && EMAIL_SMTP_PASSWORD === $value)) {
                $value = str_pad('', strlen($value) * 3, '•');
            }
            printf(
                '<tr>'
                . '<th scope="row" style="text-align:left;padding:.1em 2em .1em 0;">%s</th>'
                . '<td style="font-family:monospace;">%s</td>'
                . '</tr>',
                strtolower(preg_replace(
                               ['/^EMAIL_/', '/_/'],
                               ['', ' '],
                               self::PHPMAILER_PROPERTIES[$key]
                           )),
                $value
            );
        }
        echo '</table>';
    }

    private function get_phpmailer()
    {
        global $phpmailer;

        // (Re)create it, if it's gone missing
        if (!($phpmailer instanceof PHPMailer)) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            $phpmailer = new PHPMailer(true);
        }

        /**
         * This action is documented in wp-includes/pluggable.php:484
         */
        do_action_ref_array('phpmailer_init', [&$phpmailer]);

        return $phpmailer;
    }

    /**
     * Check that PHPMailer configuration is correctly overwritten
     * @return True if configuration is correct, false otherwise
     */
    private function check_phpmailer_configuration()
    {
        if (!defined('EMAIL_SMTP_HOST') || EMAIL_SMTP_HOST === null) {
            return true;
        }

        $phpmailer = $this->get_phpmailer();

        if ($phpmailer->Mailer !== 'smtp') {
            return false;
        }

        foreach (self::PHPMAILER_PROPERTIES as $property => $constant) {
            if (defined($constant)) {
                if ($phpmailer->{$property} !== constant($constant)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function get_mailer_configuration()
    {
        $config = [];

        if (defined('EMAIL_FROM_EMAIL') && EMAIL_FROM_EMAIL !== null) {
            $config['From'] = EMAIL_FROM_EMAIL;
            $config['Sender'] = EMAIL_FROM_EMAIL;
        }

        if (defined('EMAIL_FROM_NAME') && EMAIL_FROM_NAME !== null) {
            $config['FromName'] = EMAIL_FROM_NAME;
        }

        if (defined('EMAIL_SMTP_HOST') && EMAIL_SMTP_HOST !== null) {
            $config['Mailer'] = 'smtp';

            // set default from email and name if smtp is on
            if (!defined('EMAIL_FROM_EMAIL') || EMAIL_FROM_EMAIL === null) {
                $from_email = get_option('admin_email');
                $config['From'] = $from_email;
                $config['Sender'] = $from_email;
            }

            if (!defined('EMAIL_FROM_NAME') || EMAIL_FROM_NAME === null) {
                $config['FromName'] = get_bloginfo('name');
            }
        }

        /** @var PHPMailer $phpmailer */
        foreach (self::PHPMAILER_PROPERTIES as $property => $constant) {
            if (defined($constant) && constant($constant) !== null) {
                $config[$property] = constant($constant);
            }
        }

        return $config;
    }

    /**
     * Overwrite PHPMailer configuration
     */
    public function phpmailer_init($phpmailer)
    {
        /** @var PHPMailer $phpmailer */
        foreach ($this->get_mailer_configuration() as $property => $value) {
            $phpmailer->{$property} = $value;
        }
    }

    public function force_email_recipient($args)
    {
        if (!defined('EMAIL_FORCE_TO') || empty(EMAIL_FORCE_TO)) {
            return $args;
        }
       
        if (isset($_POST['action']) && $_POST['action'] === 'email_smtp_test') {
            return $args;
        }

        if (!array_key_exists('headers', $args)) {
            $args['headers'] = '';
        }

        // save and replace original recipient
        $args['headers'] = preg_replace(
            '/' . PHP_EOL . '+/',
            PHP_EOL,
            sprintf("%s\nTo: %s", $args['headers'], $args['to'])
        );
        $args['to'] = EMAIL_FORCE_TO;

        // disable To, Cc, Bcc recipients
        $indexes = ['To' => 1, 'Cc' => 1, 'Bcc' => 1];
        $args['headers'] = preg_replace_callback('/^(To|Cc|Bcc):/im', function ($m) use (&$indexes) {
            $index = $indexes[$m[1]]++;
            return sprintf("X-DevRewrite-%s:", $m[1] . ($index > 1 ? $index : ''));
        }, $args['headers']);

        return $args;
    }
}
