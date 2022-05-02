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

    private const PARENT_SLUG = 'tools.php';
    private const PAGE_SLUG = 'smtp';

    const PROP_FROM = 'From';
    const PROP_FROM_NAME = 'FromName';
    const PROP_SENDER = 'Sender';
    const PROP_MAILER = 'Mailer';
    const PROP_HOST = 'Host';
    const PROP_SMTP_AUTH = 'SMTPAuth';
    const PROP_USER = 'Username';
    const PROP_PASS = 'Password';
    const PROP_SMTP_SECURE = 'SMTPSecure';
    const PROP_PORT = 'Port';

    const PHPMAILER_PROPERTIES = [
        self::PROP_FROM_NAME   => 'EMAIL_FROM_NAME',
        self::PROP_FROM        => 'EMAIL_FROM_EMAIL',
        self::PROP_SENDER      => 'EMAIL_FROM_EMAIL',
        self::PROP_HOST        => 'EMAIL_SMTP_HOST',
        self::PROP_MAILER      => null,
        self::PROP_SMTP_AUTH   => 'EMAIL_SMTP_AUTH',
        self::PROP_USER        => 'EMAIL_SMTP_USERNAME',
        self::PROP_PASS        => 'EMAIL_SMTP_PASSWORD',
        self::PROP_SMTP_SECURE => 'EMAIL_SMTP_SECURE',
        self::PROP_PORT        => 'EMAIL_SMTP_PORT',
    ];

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'load_text_domain']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_action_email_smtp_test', [$this, 'email_test']);
        add_action('phpmailer_init', [$this, 'phpmailer_init']);
        add_filter('debug_information', [$this, 'debug_info']);

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
            self::PARENT_SLUG,
            __('Email &amp; SMTP', 'email-smtp'),
            __('Email &amp; SMTP', 'email-smtp'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'info_page']
        );
    }

    public function info_page()
    {
        global $title;

        echo '<div class="wrap">';
        echo '<h1>' . $title . '</h1>';

        $test_output = get_site_transient('email_smtp_test_output');
        delete_site_transient('email_smtp_test_output');
        if (!empty($test_output)) {
            echo $test_output;
        }

        if (defined('EMAIL_FORCE_TO') && !empty(EMAIL_FORCE_TO)) {
            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                sprintf(
                // translators: %1$s is the defined constant, %2$s is the email address.
                    esc_html__(
                        '%1$s is defined: all outgoing messages except test messages will be send to %2$s with no other recipients, nor Cc or Bcc.',
                        'email-smtp'
                    ),
                    '<code>EMAIL_FORCE_TO</code>',
                    '<code>' . EMAIL_FORCE_TO . '</code>'
                )
            );
        }

        if ($this->get_config_diffs()) {
            echo '<div class="notice notice-error">';
            echo '<p>';
            esc_html_e('A plugin is overriding the environment configuration.', 'email-smtp');
            echo '</p>';
            echo '</div>';
        }

        echo '<p>';
        echo __('Current configuration:', 'email-smtp');
        echo '</p>';

        echo $this->display_config();

        // Test form

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

            add_action('phpmailer_init', function (PHPMailer $phpmailer) {
                $phpmailer->SMTPDebug = 4;
                $phpmailer->Timeout = 3;
            });

            $wp_mail_output = wp_mail(
                $_REQUEST['to'],
                __('Email / SMTP test message', 'email-smtp'),
                sprintf(
                    '<p>%s</p>',
                    sprintf(
                    /* translators: Placeholder is the website URL */
                        __('Email sending from website %s is working.', 'email-smtp'),
                        sprintf(
                            '<a href="%s">%s</a>',
                            admin_url(sprintf('%s?page=%s', self::PARENT_SLUG, self::PAGE_SLUG)),
                            get_home_url()
                        )
                    )
                ),
                [
                    'Content-Type: text/html',
                ]
            );

            $output = ob_get_clean();

            // disable error display
            ini_set('display_errors', $display_errors);
            error_reporting($error_reporting);

            // Authentication obfuscation
            $output = preg_replace_callback(
                '/(334 .*?\n.*?: )([^\n:]+)/i',
                function ($m) {
                    return $m[1] . $this->obfuscate_pass($m[2]);
                },
                $output
            );

            set_site_transient('email_smtp_test_output', sprintf(
                '<div class="notice notice-%s"><p>%s <code>%s</code></p>%s</div>',
                $wp_mail_output ? 'success' : 'error',
                __('Test result:', 'email-smtp'),
                $wp_mail_output ? 'TRUE' : 'FALSE',
                $output ? '<pre style="font-size:12px;white-space:pre-wrap;">' . esc_html($output) . '</pre>' : ''
            ));
        }

        wp_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit();
    }

    private function obfuscate_pass(string $pass)
    {
        return str_pad('', strlen($pass) * 3, '•');
    }

    private function obfuscate_config(array &$config)
    {
        if (empty($config[self::PROP_PASS])) {
            return;
        }
        $config[self::PROP_PASS] = $this->obfuscate_pass($config[self::PROP_PASS]);
    }

    /**
     * @return string
     */
    private function display_config()
    {
        $mailer = $this->get_mailer_config();
        $env = $this->get_env_config();

        // password obfuscation
        foreach ([&$env, &$mailer] as &$config) {
            $this->obfuscate_config($config);
        }

        $rows = array_filter(
            array_map(function ($property) use ($env, $mailer) {
                $values = [
                    $env[$property] ?? null,
                    $mailer[$property] ?? null,
                ];
                // do not display row if no value at all
                return array_filter($values)
                    ? [
                        'property' => $property,
                        'values'   => $values,
                    ]
                    : false;
            }, array_keys(array_merge($env, $mailer)))
        );

        $headers = ['property', 'env', 'phpmailer'];
        !$env && array_splice($headers, 1, 1);

        return sprintf(
            '<table class="widefat striped">%s<tbody>%s</tbody></table>',
            sprintf(
                '<thead><tr>%s</tr></thead>',
                implode('', array_map(function ($header) {
                    return sprintf('<th scope="col">%s</th>', $header);
                }, $headers))
            ),
            implode('', array_map(function ($row) use ($env) {
                !$env && array_splice($row['values'], 0, 1);
                return sprintf(
                    '<tr><th scope="row">%s</th>%s</tr>',
                    $row['property'],
                    implode('', array_map(function ($value) {
                        return sprintf('<td>%s</td>', $value ? sprintf('<code>%s</code>', $value) : '');
                    }, $row['values']))
                );
            }, $rows))
        );
    }

    private function get_phpmailer()
    {
        global $phpmailer;

        // (Re)create it, if it's gone missing
        if (!($phpmailer instanceof PHPMailer)) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            $phpmailer = new PHPMailer(true);
        }

        /**
         * This action is documented in wp-includes/pluggable.php:484
         */
        do_action_ref_array('phpmailer_init', [&$phpmailer]);

        return $phpmailer;
    }

    /**
     * @return array Properties that are different in env to PhpMailer configs
     */
    private function get_config_diffs(): array
    {
        $env = $this->get_env_config();
        $mailer = $this->get_mailer_config();
        return array_filter(array_keys($env), function ($property) use ($env, $mailer) {
            return array_key_exists($property, $mailer) && $mailer[$property] === $env[$property] ? false : $property;
        });
    }

    /**
     * @return array
     */
    private function get_mailer_config(): array
    {
        $phpmailer = $this->get_phpmailer();
        $config = [];
        foreach (array_keys(self::PHPMAILER_PROPERTIES) as $property) {
            $config[$property] = $phpmailer->{$property} ?? null;
        }
        return $config;
    }

    private function get_env_config()
    {
        $config = [];

        if (defined('EMAIL_SMTP_HOST') && !empty(EMAIL_SMTP_HOST)) {
            $config['Mailer'] = 'smtp';
        }

        /** @var PHPMailer $phpmailer */
        foreach (self::PHPMAILER_PROPERTIES as $property => $constant) {
            if ($constant && defined($constant) && ($value = constant($constant)) !== null) {
                $config[$property] = $value;
            }
        }

        return $config;
    }

    /**
     * Overwrite PHPMailer configuration
     */
    public function phpmailer_init(PHPMailer $phpmailer)
    {
        /** @var PHPMailer $phpmailer */
        foreach ($this->get_env_config() as $property => $value) {
            $phpmailer->{$property} = $value;
        }

        $phpmailer->Hostname = 'cpe-formations.fr';
    }

    public function force_email_recipient($args)
    {
        if (!defined('EMAIL_FORCE_TO') || empty(EMAIL_FORCE_TO)) {
            return $args;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'email_smtp_test') {
            return $args;
        }

        if (!array_key_exists('to', $args)) {
            $args['to'] = [];
        }
        if (!is_array($args['to'])) {
            $args['to'] = explode(',', $args['to']);
        }

        if (!array_key_exists('headers', $args)) {
            $args['headers'] = [];
        }
        if (!is_array($args['headers'])) {
            $args['headers'] = explode("\n", str_replace("\r\n", "\n", $args['headers']));
        }

        // move original recipients to headers and replace them
        foreach ($args['to'] as $to) {
            $args['headers'][] = 'To: ' . $to;
        }
        $args['to'] = EMAIL_FORCE_TO;

        // disable To, Cc, Bcc recipients in headers
        $indexes = ['To' => 1, 'Cc' => 1, 'Bcc' => 1];
        $args['headers'] = array_map(function ($header) use (&$indexes) {
            return preg_replace_callback('/^.*(To|Cc|Bcc):(.*)$/i', function ($m) use (&$indexes) {
                $index = $indexes[$m[1]]++;
                return sprintf("X-DevRewrite-%s:%s", $m[1] . ($index > 1 ? $index : ''), $m[2]);
            },                           $header);
        }, $args['headers']);

        return $args;
    }

    public function debug_info($debug_info)
    {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        $config = array_merge(['Version' => PHPMailer::VERSION], $this->get_mailer_config());
        $this->obfuscate_config($config);

        foreach ($config as $key => $value) {
            $config[$key] = [
                'label' => $key,
                'value' => $value,
            ];
            if ($key === 'Password') {
                $config[$key]['private'] = true;
            }
        }

        $debug_info['email-smtp'] = [
            'label'  => __('PHPMailer', 'email-smtp'),
            'fields' => $config,
        ];
        return $debug_info;
    }
}
