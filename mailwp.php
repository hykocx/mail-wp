<?php
/**
 * Plugin Name: MailWP
 * Description: Replace WordPress email function with SMTP or other email providers.
 * Version: 1.0.0
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
     * Resend API key
     * @var string
     */
    private $api_key;

    /**
     * Resend API URL
     * @var string
     */
    private $api_url = 'https://api.resend.com/emails';

    /**
     * Constructor - initializes the plugin
     */
    public function __construct() {
        // Define the API key (replace with your actual key)
        $this->api_key = defined('RESEND_API_KEY') ? RESEND_API_KEY : get_option('mailwp_api_key', '');
        
        // Check if the API key is defined
        if (empty($this->api_key) && get_option('mailwp_mailer_type', 'resend') === 'resend') {
            add_action('admin_notices', [$this, 'display_api_key_notice']);
        }

        // Replace WordPress mail function
        add_filter('pre_wp_mail', [$this, 'intercept_wp_mail'], 10, 2);
        
        // Log email failures
        add_action('wp_mail_failed', [$this, 'log_email_error']);

        // Configure PHPMailer for SMTP if needed
        if (get_option('mailwp_mailer_type', 'resend') === 'smtp') {
            add_action('phpmailer_init', [$this, 'configure_smtp']);
        }
    }

    /**
     * Configure PHPMailer for SMTP
     * 
     * @param PHPMailer $phpmailer The PHPMailer instance
     */
    public function configure_smtp($phpmailer) {
        if (get_option('mailwp_mailer_type', 'resend') !== 'smtp') {
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
        $sender_name = get_option('mailwp_sender_name', get_bloginfo('name'));
        $sender_email = get_option('mailwp_smtp_from_email', '');
        
        if (!empty($sender_name)) {
            $phpmailer->FromName = $sender_name;
        }
        
        if (!empty($sender_email)) {
            $phpmailer->From = $sender_email;
        }
    }

    /**
     * Display a notice if the API key is not configured
     */
    public function display_api_key_notice() {
        $class = 'notice notice-error';
        $message = sprintf(
            __('MailWP: Please <a href="%s">configure your Resend API key</a> to enable email sending features or change the email sending method to SMTP.', 'mailwp'),
            admin_url('options-general.php?page=mailwp-settings')
        );
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    /**
     * Intercept wp_mail before it gets processed by PHPMailer
     * 
     * @param null|bool $return Short-circuit return value
     * @param array $atts Array of the `wp_mail()` arguments
     * @return bool|null
     */
    public function intercept_wp_mail($return, $atts) {
        // If SMTP is selected, let WordPress handle the email
        if (get_option('mailwp_mailer_type', 'resend') === 'smtp') {
            return null;
        }

        // Extract email data
        $to = $atts['to'];
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'];
        $attachments = $atts['attachments'];
        
        $this->log_message('debug', 'Intercepting wp_mail: ' . json_encode([
            'to' => $to,
            'subject' => $subject
        ]));
        
        // Handle multiple recipients
        if (!is_array($to)) {
            $to = explode(',', $to);
        }
        
        // Parse headers
        $cc = [];
        $bcc = [];
        $reply_to = '';
        $content_type = '';
        $from_email = '';
        $from_name = '';
        
        if (!is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }
        
        foreach ($headers as $header) {
            if (strpos($header, ':') === false) {
                continue;
            }
            
            list($name, $content) = explode(':', trim($header), 2);
            $name = trim($name);
            $content = trim($content);
            
            switch (strtolower($name)) {
                case 'content-type':
                    $content_type = $content;
                    break;
                case 'cc':
                    $cc = array_merge($cc, explode(',', $content));
                    break;
                case 'bcc':
                    $bcc = array_merge($bcc, explode(',', $content));
                    break;
                case 'reply-to':
                    $reply_to = $content;
                    break;
                case 'from':
                    if (preg_match('/(.*)<(.*)>/', $content, $matches)) {
                        $from_name = trim($matches[1]);
                        $from_email = trim($matches[2]);
                    } else {
                        $from_email = trim($content);
                    }
                    break;
            }
        }
        
        // If content type is not set, default to HTML
        if (empty($content_type)) {
            $content_type = 'text/html';
        }
        
        // Default from email and name if not set
        if (empty($from_email)) {
            $from_email = get_option('mailwp_from_email', 'wordpress@' . parse_url(site_url(), PHP_URL_HOST));
        }
        
        if (empty($from_name)) {
            $from_name = get_option('mailwp_from_name', get_bloginfo('name'));
        }
        
        // Handle file attachments (Resend supports attachments but with different format)
        $api_attachments = [];
        if (!empty($attachments)) {
            if (!is_array($attachments)) {
                $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
            }
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $filename = basename($attachment);
                    $file_content = base64_encode(file_get_contents($attachment));
                    
                    // Get MIME type
                    $file_info = wp_check_filetype($filename);
                    $content_type_att = !empty($file_info['type']) ? $file_info['type'] : 'application/octet-stream';
                    
                    $api_attachments[] = [
                        'filename' => $filename,
                        'content' => $file_content,
                        'contentType' => $content_type_att
                    ];
                } else {
                    $this->log_message('warning', 'Attachment not found: ' . $attachment);
                }
            }
        }
        
        // Build recipient list
        $all_recipients = array_map('trim', $to);
        $all_recipients = array_filter($all_recipients);
        
        if (empty($all_recipients)) {
            $this->log_message('error', 'No valid recipient specified');
            
            // Create WordPress error
            $error = new \WP_Error('wp_mail_failed', __('No valid recipient', 'mailwp'));
            do_action('wp_mail_failed', $error);
            
            return false;
        }
        
        // Format message based on content type
        if (strpos($content_type, 'text/html') === false) {
            // If not HTML, convert newlines to <br>
            $message = nl2br($message);
        }
        
        // Build the payload for Resend API
        $payload = [
            'from' => $from_name . ' <' . $from_email . '>',
            'to' => $all_recipients,
            'subject' => $subject,
            'html' => $message
        ];
        
        // Add CC if present
        if (!empty($cc)) {
            $cc = array_map('trim', $cc);
            $cc = array_filter($cc);
            if (!empty($cc)) {
                $payload['cc'] = $cc;
            }
        }
        
        // Add BCC if present
        if (!empty($bcc)) {
            $bcc = array_map('trim', $bcc);
            $bcc = array_filter($bcc);
            if (!empty($bcc)) {
                $payload['bcc'] = $bcc;
            }
        }
        
        // Add reply-to if present
        if (!empty($reply_to)) {
            $this->log_message('debug', 'Reply-to before processing: ' . $reply_to);
            
            // Ignore the WordPress automatic reply-to
            $site_domain = parse_url(site_url(), PHP_URL_HOST);
            if (strpos($reply_to, 'wordpress@' . $site_domain) === 0) {
                $this->log_message('debug', 'Reply-to WordPress ignored: ' . $reply_to);
            } else {
                // Parse the reply-to to extract the email address
                if (preg_match('/(.*)<(.*)>/', $reply_to, $matches)) {
                    $payload['reply_to'] = [trim($matches[2])]; // Resend expects an array
                    $this->log_message('debug', 'Reply-to extracted: ' . $payload['reply_to'][0]);
                } else {
                    $payload['reply_to'] = [trim($reply_to)];
                    $this->log_message('debug', 'Reply-to used as is: ' . $payload['reply_to'][0]);
                }
            }
        }

        // Add attachments if present
        if (!empty($api_attachments)) {
            $payload['attachments'] = $api_attachments;
        }

        $this->log_message('debug', 'Complete payload: ' . json_encode($payload));
        
        $this->log_message('info', 'Sending email via Resend API: ' . json_encode([
            'to' => implode(', ', $all_recipients),
            'subject' => $subject,
            'from' => $from_name . ' <' . $from_email . '>',
        ]));
        
        // Send the request to the API
        $response = $this->send_api_request($payload);
        
        // Handle the response
        if (isset($response['status']) && $response['status'] === 200) {
            // Success
            $this->log_message('info', 'Email sent successfully via Resend API');
            return true;
        } else {
            // Failure
            $error_message = isset($response['message']) ? $response['message'] : __('Unknown error', 'mailwp');
            
            // Log the detailed error
            $error_details = '';
            if (isset($response['data']) && is_array($response['data'])) {
                $error_details = json_encode($response['data']);
            }
            
            $this->log_message('error', 'Failed to send email via Resend API: ' . $error_message . ' - Details: ' . $error_details);
            
            // Create WordPress error
            $error = new \WP_Error('wp_mail_failed', $error_message);
            do_action('wp_mail_failed', $error);
            
            return false;
        }
    }

    /**
     * Send a request to the Resend API
     *
     * @param array $payload Data to send to the API
     * @return array API response
     */
    private function send_api_request($payload) {
        // Validate API URL
        if (empty($this->api_url)) {
            $this->log_message('error', 'Resend API URL not configured');
            return [
                'status' => 500,
                'message' => __('Resend API URL not configured', 'mailwp')
            ];
        }

        // Validate API key
        if (empty($this->api_key)) {
            $this->log_message('error', 'Resend API key not configured');
            return [
                'status' => 500,
                'message' => __('Resend API key not configured', 'mailwp')
            ];
        }

        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true
        ];
        
        // Log request attempt (without sensitive data)
        $log_payload = $payload;
        
        $this->log_message('debug', 'Request to Resend API: ' . $this->api_url . ' - Payload: ' . json_encode($log_payload));
        
        // Perform the HTTP POST request
        $response = wp_remote_post($this->api_url, $args);
        
        // Handle the response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_message('error', 'Connection error: ' . $error_message);
            
            return [
                'status' => 500,
                'message' => sprintf(__('Connection error: %s', 'mailwp'), $error_message)
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        // Log the raw response for debugging
        $this->log_message('debug', 'Response from Resend API: Code ' . $status . ' - ' . $body);
        
        // Try to decode the JSON response
        $decoded_body = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_message('error', 'Invalid API response: ' . json_last_error_msg());
            return [
                'status' => 500,
                'message' => sprintf(__('Invalid API response: %s', 'mailwp'), json_last_error_msg())
            ];
        }
        
        return [
            'status' => $status,
            'message' => isset($decoded_body['message']) ? $decoded_body['message'] : '',
            'data' => $decoded_body
        ];
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
     * Get the API key
     *
     * @return string The API key
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Get the API URL
     *
     * @return string The API URL
     */
    public function get_api_url() {
        return $this->api_url;
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

