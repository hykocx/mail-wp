<?php
/**
 * Plugin Name: MailWP
 * Description: Replace WordPress email function with SMTP or other email providers.
 * Version: 1.0.1
 * Author: Hyko
 * Author URI: https://hyko.cx
 * Text Domain: mailwp
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('HYMAILWP_PLUGIN_FILE', __FILE__);

// Translations
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain('mailwp', false, dirname(plugin_basename(__FILE__)) . '/languages/');
});

// Include Updater if not already included
require_once plugin_dir_path(__FILE__) . '/includes/plugin-update-checker.php';

// Include admin functionality
require_once plugin_dir_path(__FILE__) . '/admin/admin.php';

// Import PHPMailer namespace
use PHPMailer\PHPMailer\PHPMailer;

class Hy_MailWP_Service {
    /**
     * Constructor - initializes the plugin
     */
    public function __construct() {
        // Configure PHPMailer for SMTP
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        
        // Log email failures
        add_action('wp_mail_failed', [$this, 'log_email_error']);
    }

    /**
     * Configure PHPMailer for SMTP
     * 
     * @param PHPMailer $phpmailer The PHPMailer instance
     */
    public function configure_smtp($phpmailer) {
        $phpmailer->isSMTP();
        $phpmailer->Host = get_option('mailwp_smtp_host', '');
        $phpmailer->Port = get_option('mailwp_smtp_port', '587');
        $phpmailer->Username = get_option('mailwp_smtp_username', '');
        $phpmailer->Password = get_option('mailwp_smtp_password', '');
        
        $encryption = get_option('mailwp_smtp_encryption', 'tls');
        if ($encryption === 'tls') {
            $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $phpmailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $phpmailer->SMTPSecure = '';
        }
        
        $phpmailer->SMTPAuth = true;

        // Set the sender name and email
        $sender_name = get_option('mailwp_smtp_from_name', get_bloginfo('name'));
        $sender_email = get_option('mailwp_smtp_from_email', '');
        
        if (!empty($sender_name)) {
            $phpmailer->FromName = $sender_name;
        }
        
        if (!empty($sender_email)) {
            $phpmailer->From = $sender_email;
        }
    }

    /**
     * Log email errors
     *
     * @param \WP_Error $wp_error WordPress error
     */
    public function log_email_error($wp_error) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MailWP - Email sending error: ' . $wp_error->get_error_message());
        }
    }

    /**
     * Log a message to WordPress error log
     * 
     * @param string $level Log level (info, warning, error)
     * @param string $message Message to log
     */
    private function log_message($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MailWP [' . strtoupper($level) . ']: ' . $message);
        }
    }
}

/**
 * Initialize the service
 */
function mailwp_init() {
    global $mailwp_service;
    $mailwp_service = new Hy_MailWP_Service();
}
add_action('plugins_loaded', 'mailwp_init');

/**
 * Handle update check requests
 */
function mailwp_handle_update_check() {
    if (
        isset($_GET['action']) && $_GET['action'] === 'mailwp_check_update' &&
        isset($_GET['plugin']) && $_GET['plugin'] === plugin_basename(HYMAILWP_PLUGIN_FILE) &&
        check_admin_referer('mailwp-check-update')
    ) {
        global $mailwp_service;
        
        // Initialize a temporary updater instance
        if (class_exists('MailWP_GitHub_Updater')) {
            $debug = defined('WP_DEBUG') && WP_DEBUG;
            $updater = new MailWP_GitHub_Updater(
                HYMAILWP_PLUGIN_FILE, 
                MAILWP_GITHUB_REPO, 
                'MailWP', 
                '', 
                $debug
            );
            
            // First test update functionality - includes connectivity test
            $updater->test_update_functionality();
            
            // If connection is successful, force update check
            $updater->force_update_check();
        }
        
        // Redirect to the plugins page
        wp_redirect(admin_url('plugins.php?plugin_status=all&settings-updated=true'));
        exit;
    }
}
add_action('admin_init', 'mailwp_handle_update_check');

/**
 * Display update messages on the plugins page
 */
function mailwp_display_update_messages() {
    if (class_exists('SiteMail_GitHub_Updater_Messages')) {
        SiteMail_GitHub_Updater_Messages::display_messages();
    }
}
add_action('admin_notices', 'mailwp_display_update_messages');

/**
 * Activation and deactivation functions for the plugin
 */
function mailwp_activate() {
    // Nothing to do for now
}
register_activation_hook(__FILE__, 'mailwp_activate');

function mailwp_deactivate() {
    // Nothing to do for now
}
register_deactivation_hook(__FILE__, 'mailwp_deactivate');

