<?php
/**
 * Plugin Name: MailWP
 * Description: Replace WordPress email function with SMTP or other email providers.
 * Version: 1.0.5
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

// Include Microsoft Graph OAuth functionality
require_once plugin_dir_path(__FILE__) . '/includes/class-microsoft-graph-oauth.php';

// Include logs functionality
require_once plugin_dir_path(__FILE__) . '/includes/class-mailwp-logs.php';

// Include encryption functionality
require_once plugin_dir_path(__FILE__) . '/includes/class-mailwp-encryption.php';

// Import PHPMailer namespace
use PHPMailer\PHPMailer\PHPMailer;

class Hy_MailWP_Service {
    
    /**
     * Microsoft Graph OAuth instance
     * @var MailWP_Microsoft_Graph_OAuth
     */
    public $microsoft_oauth;
    
    /**
     * Logs instance
     * @var MailWP_Logs
     */
    public $logs;
    
    /**
     * Constructor - initializes the plugin
     */
    public function __construct() {
        // Initialize logs
        $this->logs = new MailWP_Logs();
        
        // Initialize Microsoft Graph OAuth
        $this->microsoft_oauth = new MailWP_Microsoft_Graph_OAuth();
        
        // Hook into wp_mail to handle different mailer types
        add_filter('pre_wp_mail', [$this, 'handle_wp_mail'], 10, 2);
        
        // Configure PHPMailer for SMTP when needed
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        
        // Log email failures
        add_action('wp_mail_failed', [$this, 'log_email_error']);
        
        // Log successful SMTP emails
        add_filter('wp_mail', [$this, 'log_smtp_email_success'], 999, 1);
        
        // Setup encryption hooks
        $this->setup_encryption_hooks();
    }

    /**
     * Setup encryption hooks for sensitive data
     */
    private function setup_encryption_hooks() {
        $sensitive_options = MailWP_Encryption::get_sensitive_options();
        
        foreach ($sensitive_options as $option_name) {
            // Hook into option updates to encrypt before saving
            add_filter("pre_update_option_{$option_name}", [$this, 'encrypt_option_value'], 10, 3);
            
            // Hook into option retrieval to decrypt after loading
            add_filter("option_{$option_name}", [$this, 'decrypt_option_value'], 10, 2);
        }
    }
    
    /**
     * Encrypt option value before saving to database
     * 
     * @param mixed $value New value
     * @param mixed $old_value Old value
     * @param string $option Option name
     * @return mixed
     */
    public function encrypt_option_value($value, $old_value, $option) {
        return MailWP_Encryption::maybe_encrypt_option($option, $value);
    }
    
    /**
     * Decrypt option value after loading from database
     * 
     * @param mixed $value Option value
     * @param string $option Option name
     * @return mixed
     */
    public function decrypt_option_value($value, $option) {
        return MailWP_Encryption::maybe_decrypt_option($option, $value);
    }

    /**
     * Handle wp_mail and route to appropriate mailer
     * 
     * @param null|bool $return Short-circuit return value
     * @param array $atts wp_mail() arguments
     * @return null|bool
     */
    public function handle_wp_mail($return, $atts) {
        $mailer_type = get_option('mailwp_mailer_type', 'smtp');
        
        // If not using Microsoft Graph OAuth, let WordPress handle normally
        if ($mailer_type !== 'microsoft_graph') {
            return $return;
        }
        
        // Check if Microsoft Graph OAuth is configured and authorized
        if (!$this->microsoft_oauth->is_configured() || !$this->microsoft_oauth->is_authorized()) {
            $error_message = 'Microsoft Graph OAuth not properly configured or authorized';
            $this->log_message('error', $error_message);
            $this->logs->log_email_error($error_message, $atts);
            return new WP_Error('mailwp_not_configured', $error_message);
        }
        
        // Extract arguments
        $to = $atts['to'] ?? '';
        $subject = $atts['subject'] ?? '';
        $message = $atts['message'] ?? '';
        $headers = $atts['headers'] ?? [];
        $attachments = $atts['attachments'] ?? [];
        
        // Parse headers for CC, BCC
        $cc = [];
        $bcc = [];
        $parsed_headers = [];
        
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (is_string($header)) {
                    if (stripos($header, 'cc:') === 0) {
                        $cc[] = trim(substr($header, 3));
                    } elseif (stripos($header, 'bcc:') === 0) {
                        $bcc[] = trim(substr($header, 4));
                    } else {
                        $parsed_headers[] = $header;
                    }
                }
            }
        }
        
        // Prepare email data
        $email_data = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $parsed_headers,
            'cc' => $cc,
            'bcc' => $bcc,
            'attachments' => $attachments
        ];
        
        // Send via Microsoft Graph API
        $result = $this->microsoft_oauth->send_email($email_data);
        
        if (is_wp_error($result)) {
            $error_message = 'Microsoft Graph API error: ' . $result->get_error_message();
            $this->log_message('error', $error_message);
            $this->logs->log_email_error($result->get_error_message(), $email_data);
            return $result;
        }
        
        $this->log_message('info', 'Email sent successfully via Microsoft Graph API');
        $this->logs->log_email_sent($email_data);
        return true; // Short-circuit wp_mail() - email was handled
    }

    /**
     * Configure PHPMailer for SMTP
     * 
     * @param PHPMailer $phpmailer The PHPMailer instance
     */
    public function configure_smtp($phpmailer) {
        // Only configure if we're using SMTP
        $mailer_type = get_option('mailwp_mailer_type', 'smtp');
        if ($mailer_type !== 'smtp') {
            return;
        }
        
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
        $error_message = $wp_error->get_error_message();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MailWP - Email sending error: ' . $error_message);
        }
        
        // Log to database
        $this->logs->log_email_error($error_message);
    }

    /**
     * Log successful SMTP emails
     * 
     * @param array $mail_data Array of mail arguments
     * @return array Unchanged mail data
     */
    public function log_smtp_email_success($mail_data) {
        $mailer_type = get_option('mailwp_mailer_type', 'smtp');
        
        // Only log for SMTP (not Microsoft Graph, as that's handled separately)
        if ($mailer_type === 'smtp') {
            $email_data = [
                'to' => $mail_data['to'] ?? '',
                'subject' => $mail_data['subject'] ?? '',
                'message' => $mail_data['message'] ?? '',
                'headers' => $mail_data['headers'] ?? [],
                'attachments' => $mail_data['attachments'] ?? []
            ];
            
            // Schedule logging after the email is actually sent
            add_action('shutdown', function() use ($email_data) {
                // Check if there was an error during sending
                if (!did_action('wp_mail_failed')) {
                    $this->logs->log_email_sent($email_data);
                }
            });
        }
        
        return $mail_data;
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

