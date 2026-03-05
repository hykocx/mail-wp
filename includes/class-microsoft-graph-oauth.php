<?php
/**
 * Microsoft Graph OAuth Mailer
 * 
 * @package MailWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class that handles Microsoft Graph OAuth authentication and email sending
 */
class MailWP_Microsoft_Graph_OAuth {
    
    /**
     * Microsoft OAuth endpoints
     */
    const AUTHORITY_URL = 'https://login.microsoftonline.com';
    const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';
    
    /**
     * OAuth scopes required for sending emails
     */
    const REQUIRED_SCOPES = [
        'https://graph.microsoft.com/Mail.Send',
        'offline_access'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_init', [$this, 'handle_oauth_actions']);
    }
    
    /**
     * Get the authorization URL for Microsoft OAuth
     * 
     * @return string Authorization URL
     */
    public function get_authorization_url() {
        $client_id = get_option('mailwp_msauth_client_id');
        $tenant_mode = get_option('mailwp_msauth_tenant_mode', 'single');
        $tenant_id = get_option('mailwp_msauth_tenant_id');
        
        if (empty($client_id)) {
            return '';
        }
        
        // For single-tenant mode, tenant_id is required
        if ($tenant_mode === 'single' && empty($tenant_id)) {
            return '';
        }
        
        $redirect_uri = $this->get_redirect_uri();
        
        // Générer le state avec l'URL du site client si URL personnalisée
        $custom_redirect_uri = get_option('mailwp_msauth_custom_redirect_uri', '');
        if (!empty($custom_redirect_uri)) {
            $state_data = [
                'nonce' => wp_generate_password(32, false),
                'client_site' => home_url()
            ];
            $state = base64_encode(json_encode($state_data));
        } else {
            $state = wp_generate_password(32, false);
        }
        
        // Store state for verification
        set_transient('mailwp_oauth_state', $state, 600); // 10 minutes
        
        $params = [
            'client_id' => $client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirect_uri,
            'scope' => implode(' ', self::REQUIRED_SCOPES),
            'state' => $state,
            'response_mode' => 'query',
            'prompt' => 'select_account'  // Force account selection
        ];
        
        // Use either specific tenant ID or "common" based on tenant mode
        $endpoint_tenant = ($tenant_mode === 'multi') ? 'common' : $tenant_id;
        
        return self::AUTHORITY_URL . '/' . $endpoint_tenant . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }
    
    /**
     * Get the redirect URI for OAuth callback
     * 
     * @return string Redirect URI
     */
    public function get_redirect_uri() {
        $custom_redirect_uri = get_option('mailwp_msauth_custom_redirect_uri', '');
        
        if (!empty($custom_redirect_uri)) {
            // Pour une URL personnalisée, retourner l'URL exacte (sans paramètres)
            // L'URL du site client sera passée dans le paramètre 'state'
            return $custom_redirect_uri;
        }
        
        return admin_url('options-general.php?page=mailwp-settings&oauth_callback=1');
    }
    
    /**
     * Check if current request is using a custom redirect URI
     * 
     * @return bool True if using custom redirect URI
     */
    public function is_using_custom_redirect_uri() {
        $custom_redirect_uri = get_option('mailwp_msauth_custom_redirect_uri', '');
        return !empty($custom_redirect_uri);
    }
    
    /**
     * Get the internal callback URL (always points to this WordPress site)
     * 
     * @return string Internal callback URL
     */
    public function get_internal_callback_uri() {
        return admin_url('options-general.php?page=mailwp-settings&oauth_callback=1');
    }
    
    
    /**
     * Handle OAuth callback from Microsoft
     */
    public function handle_oauth_callback() {
        // For custom redirect URIs, we need to handle the callback differently
        // The custom page should redirect back to our default callback URL with the auth code
        if ($this->is_using_custom_redirect_uri()) {
            // Check if we're receiving a redirected callback from custom URI
            if (!isset($_GET['oauth_callback']) || $_GET['oauth_callback'] !== '1') {
                return;
            }
        } else {
            // Standard callback handling
            if (!isset($_GET['oauth_callback']) || $_GET['oauth_callback'] !== '1') {
                return;
            }
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'mailwp'));
        }
        
        // Check for error
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            $error_description = isset($_GET['error_description']) ? sanitize_text_field($_GET['error_description']) : '';
            
            $this->redirect_with_message(
                sprintf(__('Authorization failed: %s - %s', 'mailwp'), $error, $error_description),
                'error'
            );
            return;
        }
        
        // Check state parameter
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $stored_state = get_transient('mailwp_oauth_state');
        delete_transient('mailwp_oauth_state');
        
        if (empty($state) || $state !== $stored_state) {
            $this->redirect_with_message(__('Invalid state parameter. Please try again.', 'mailwp'), 'error');
            return;
        }
        
        // Get authorization code
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        if (empty($code)) {
            $this->redirect_with_message(__('No authorization code received.', 'mailwp'), 'error');
            return;
        }
        
        // Exchange code for tokens
        $result = $this->exchange_code_for_tokens($code);
        
        if (is_wp_error($result)) {
            $this->redirect_with_message(
                sprintf(__('Token exchange failed: %s', 'mailwp'), $result->get_error_message()),
                'error'
            );
            return;
        }
        
        // Log successful authorization
        global $mailwp_service;
        if ($mailwp_service && $mailwp_service->logs) {
            $mailwp_service->logs->log_auth_success('Microsoft OAuth');
        }
        
        $this->redirect_with_message(__('Authorization successful!', 'mailwp'), 'success');
    }
    
    /**
     * Exchange authorization code for access and refresh tokens
     * 
     * @param string $code Authorization code
     * @return array|WP_Error Token response or error
     */
    private function exchange_code_for_tokens($code) {
        $client_id = get_option('mailwp_msauth_client_id');
        $client_secret = get_option('mailwp_msauth_client_secret');
        $tenant_mode = get_option('mailwp_msauth_tenant_mode', 'single');
        $tenant_id = get_option('mailwp_msauth_tenant_id');
        
        if (empty($client_id) || empty($client_secret)) {
            return new WP_Error('missing_config', 'Missing OAuth configuration.');
        }
        
        // For single-tenant mode, tenant_id is required
        if ($tenant_mode === 'single' && empty($tenant_id)) {
            return new WP_Error('missing_config', 'Tenant ID is required for single-tenant mode.');
        }
        
        $body = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->get_redirect_uri(),
            'scope' => implode(' ', self::REQUIRED_SCOPES)
        ];
        
        // Use either specific tenant ID or "common" based on tenant mode  
        $endpoint_tenant = ($tenant_mode === 'multi') ? 'common' : $tenant_id;
        
        $response = wp_remote_post(
            self::AUTHORITY_URL . '/' . $endpoint_tenant . '/oauth2/v2.0/token',
            [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'timeout' => 30
            ]
        );
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON response from Microsoft.');
        }
        
        if (isset($data['error'])) {
            return new WP_Error('oauth_error', $data['error_description'] ?? $data['error']);
        }
        
        if (!isset($data['access_token'])) {
            return new WP_Error('no_token', 'No access token received.');
        }
        
        // Store tokens
        update_option('mailwp_msauth_access_token', $data['access_token']);
        
        if (isset($data['refresh_token'])) {
            update_option('mailwp_msauth_refresh_token', $data['refresh_token']);
        }
        
        if (isset($data['expires_in'])) {
            $expires_at = time() + intval($data['expires_in']) - 300; // 5 minutes buffer
            update_option('mailwp_msauth_token_expires', $expires_at);
        }
        
        return $data;
    }
    
    /**
     * Refresh the access token using the refresh token
     * 
     * @return bool True if successful, false otherwise
     */
    public function refresh_access_token() {
        $client_id = get_option('mailwp_msauth_client_id');
        $client_secret = get_option('mailwp_msauth_client_secret');
        $tenant_mode = get_option('mailwp_msauth_tenant_mode', 'single');
        $tenant_id = get_option('mailwp_msauth_tenant_id');
        $refresh_token = get_option('mailwp_msauth_refresh_token');
        
        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return false;
        }
        
        // For single-tenant mode, tenant_id is required
        if ($tenant_mode === 'single' && empty($tenant_id)) {
            return false;
        }
        
        $body = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', self::REQUIRED_SCOPES)
        ];
        
        // Use either specific tenant ID or "common" based on tenant mode  
        $endpoint_tenant = ($tenant_mode === 'multi') ? 'common' : $tenant_id;
        
        $response = wp_remote_post(
            self::AUTHORITY_URL . '/' . $endpoint_tenant . '/oauth2/v2.0/token',
            [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'timeout' => 30
            ]
        );
        
        if (is_wp_error($response)) {
            $error_message = 'Token refresh failed: ' . $response->get_error_message();
            error_log('MailWP - ' . $error_message);
            
            // Log token refresh failure
            global $mailwp_service;
            if ($mailwp_service && $mailwp_service->logs) {
                $mailwp_service->logs->log_token_refresh(false, $response->get_error_message());
            }
            
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || isset($data['error'])) {
            $error_message = $data['error_description'] ?? 'Invalid JSON';
            error_log('MailWP - Token refresh error: ' . $error_message);
            
            // Log token refresh failure
            global $mailwp_service;
            if ($mailwp_service && $mailwp_service->logs) {
                $mailwp_service->logs->log_token_refresh(false, $error_message);
            }
            
            return false;
        }
        
        if (!isset($data['access_token'])) {
            return false;
        }
        
        // Update tokens
        update_option('mailwp_msauth_access_token', $data['access_token']);
        
        if (isset($data['refresh_token'])) {
            update_option('mailwp_msauth_refresh_token', $data['refresh_token']);
        }
        
        if (isset($data['expires_in'])) {
            $expires_at = time() + intval($data['expires_in']) - 300; // 5 minutes buffer
            update_option('mailwp_msauth_token_expires', $expires_at);
        }
        
        // Log successful token refresh
        global $mailwp_service;
        if ($mailwp_service && $mailwp_service->logs) {
            $mailwp_service->logs->log_token_refresh(true);
        }
        
        return true;
    }
    
    /**
     * Get a valid access token, refreshing if necessary
     * 
     * @return string|false Access token or false if unavailable
     */
    public function get_valid_access_token() {
        $access_token = get_option('mailwp_msauth_access_token');
        $expires_at = get_option('mailwp_msauth_token_expires', 0);
        
        if (empty($access_token)) {
            return false;
        }
        
        // Check if token is expired
        if ($expires_at > 0 && time() >= $expires_at) {
            if (!$this->refresh_access_token()) {
                return false;
            }
            $access_token = get_option('mailwp_msauth_access_token');
        }
        
        return $access_token;
    }
    
    /**
     * Send email using Microsoft Graph API
     * 
     * @param array $email_data Email data including to, subject, message, etc.
     * @return bool|WP_Error True if successful, WP_Error otherwise
     */
    public function send_email($email_data) {
        $access_token = $this->get_valid_access_token();
        
        if (!$access_token) {
            return new WP_Error('no_token', 'No valid access token available.');
        }
        
        $from_email = get_option('mailwp_msauth_from_email');
        $from_name = get_option('mailwp_msauth_from_name', get_bloginfo('name'));
        
        if (empty($from_email)) {
            return new WP_Error('no_from_email', 'From email address not configured.');
        }
        
        // Prepare recipients
        $to_recipients = [];
        if (is_array($email_data['to'])) {
            foreach ($email_data['to'] as $email) {
                $to_recipients[] = ['emailAddress' => ['address' => $email]];
            }
        } else {
            $to_recipients[] = ['emailAddress' => ['address' => $email_data['to']]];
        }
        
        // Prepare CC recipients
        $cc_recipients = [];
        if (!empty($email_data['cc'])) {
            if (is_array($email_data['cc'])) {
                foreach ($email_data['cc'] as $email) {
                    $cc_recipients[] = ['emailAddress' => ['address' => $email]];
                }
            } else {
                $cc_recipients[] = ['emailAddress' => ['address' => $email_data['cc']]];
            }
        }
        
        // Prepare BCC recipients
        $bcc_recipients = [];
        if (!empty($email_data['bcc'])) {
            if (is_array($email_data['bcc'])) {
                foreach ($email_data['bcc'] as $email) {
                    $bcc_recipients[] = ['emailAddress' => ['address' => $email]];
                }
            } else {
                $bcc_recipients[] = ['emailAddress' => ['address' => $email_data['bcc']]];
            }
        }
        
        // Determine content type
        $content_type = 'text';
        $message_content = $email_data['message'];
        
        if (!empty($email_data['headers'])) {
            foreach ($email_data['headers'] as $header) {
                if (stripos($header, 'content-type') !== false && stripos($header, 'html') !== false) {
                    $content_type = 'html';
                    break;
                }
            }
        }
        
        if ($content_type === 'text') {
            if (preg_match('/<\s*\/?(?:br|p|div|span|strong|b|i|em|u|h[1-6]|ul|ol|li|a|img|style|html|body|head)\s*[^>]*>/i', $message_content)) {
                $content_type = 'html';
            }
        }
        
        // Build message structure
        $message = [
            'subject' => $email_data['subject'],
            'body' => [
                'contentType' => $content_type,
                'content'     => $message_content
            ],
            'from' => [
                'emailAddress' => [
                    'address' => $from_email,
                    'name'    => $from_name
                ]
            ],
            'toRecipients' => $to_recipients
        ];
        
        if (!empty($cc_recipients)) {
            $message['ccRecipients'] = $cc_recipients;
        }
        
        if (!empty($bcc_recipients)) {
            $message['bccRecipients'] = $bcc_recipients;
        }

        // Preserve Reply-To header
        if (!empty($email_data['reply_to'])) {
            $reply_to_recipients = [];
            $reply_to_list = is_array($email_data['reply_to']) ? $email_data['reply_to'] : [$email_data['reply_to']];
            foreach ($reply_to_list as $reply_to_entry) {
                $parsed = $this->parse_email_address($reply_to_entry);
                if ($parsed) {
                    $reply_to_recipients[] = ['emailAddress' => $parsed];
                }
            }
            if (!empty($reply_to_recipients)) {
                $message['replyTo'] = $reply_to_recipients;
            }
        }
        
        // Attach files inline (base64). Files >= 3 MB are silently skipped:
        // the Microsoft Graph API /me/sendMail endpoint does not support payloads
        // larger than 4 MB, and the alternative (createUploadSession) requires
        // Mail.ReadWrite which is a broader permission we deliberately avoid.
        $size_limit = 3 * 1024 * 1024; // 3 MB
        
        if (!empty($email_data['attachments'])) {
            $message['attachments'] = [];
            foreach ($email_data['attachments'] as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }
                if (filesize($path) >= $size_limit) {
                    error_log(sprintf(
                        'MailWP: attachment "%s" skipped — file exceeds the 3 MB limit supported by Microsoft Graph (Mail.Send scope only).',
                        basename($path)
                    ));
                    continue;
                }
                $file_data = file_get_contents($path);
                if ($file_data === false) {
                    continue;
                }
                $message['attachments'][] = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => basename($path),
                    'contentType'  => $this->get_mime_type($path),
                    'contentBytes' => base64_encode($file_data)
                ];
            }
            if (empty($message['attachments'])) {
                unset($message['attachments']);
            }
        }
        
        // Standard path: send directly via /me/sendMail
        $response = wp_remote_post(
            self::GRAPH_API_URL . '/me/sendMail',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json'
                ],
                'body'    => json_encode(['message' => $message]),
                'timeout' => 30
            ]
        );
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 202) {
            return true;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $error_message = $data['error']['message'] ?? $data['error']['code'] ?? 'Unknown error';
        
        return new WP_Error('graph_api_error', "Graph API error (HTTP $response_code): $error_message");
    }
    
    /**
     * Parse an email address string into a Graph API emailAddress object.
     * Handles both "email@example.com" and "Name <email@example.com>" formats.
     *
     * @param string $entry Raw email address string
     * @return array|null Associative array with 'address' (and optionally 'name'), or null if invalid
     */
    private function parse_email_address($entry) {
        $entry = trim($entry);
        if (empty($entry)) {
            return null;
        }

        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/', $entry, $matches)) {
            $email = trim($matches[2]);
            $name  = trim(trim($matches[1]), '"\'');
            if (!is_email($email)) {
                return null;
            }
            $result = ['address' => $email];
            if (!empty($name)) {
                $result['name'] = $name;
            }
            return $result;
        }

        if (is_email($entry)) {
            return ['address' => $entry];
        }

        return null;
    }

    /**
     * Resolve the MIME type of a file, falling back to application/octet-stream.
     *
     * @param string $file_path Absolute path to the file
     * @return string MIME type string
     */
    private function get_mime_type($file_path) {
        $mime = '';
        
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($file_path);
        }
        
        if (empty($mime)) {
            $file_info = wp_check_filetype(basename($file_path));
            $mime      = $file_info['type'] ?? '';
        }
        
        return $mime ?: 'application/octet-stream';
    }
    
    /**
     * Handle OAuth-related actions
     */
    public function handle_oauth_actions() {
        if (!isset($_GET['action']) || !current_user_can('manage_options')) {
            return;
        }
        
        $action = sanitize_text_field($_GET['action']);
        
        if ($action === 'authorize_msauth') {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'authorize_msauth')) {
                wp_die(__('Security check failed.', 'mailwp'));
            }
            
            $auth_url = $this->get_authorization_url();
            if (empty($auth_url)) {
                $this->redirect_with_message(__('Please configure Client ID and Tenant ID first.', 'mailwp'), 'error');
                return;
            }
            
            wp_redirect($auth_url);
            exit;
        }
        
        if ($action === 'change_msauth_account') {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'change_msauth_account')) {
                wp_die(__('Security check failed.', 'mailwp'));
            }
            
            // Revoke current tokens first
            delete_option('mailwp_msauth_access_token');
            delete_option('mailwp_msauth_refresh_token');
            delete_option('mailwp_msauth_token_expires');
            
            // Then redirect to authorization with forced account selection
            $auth_url = $this->get_authorization_url();
            if (empty($auth_url)) {
                $this->redirect_with_message(__('Please configure Client ID and Tenant ID first.', 'mailwp'), 'error');
                return;
            }
            
            wp_redirect($auth_url);
            exit;
        }
        
        if ($action === 'revoke_msauth') {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'revoke_msauth')) {
                wp_die(__('Security check failed.', 'mailwp'));
            }
            
            delete_option('mailwp_msauth_access_token');
            delete_option('mailwp_msauth_refresh_token');
            delete_option('mailwp_msauth_token_expires');
            
            $this->redirect_with_message(__('Authorization revoked successfully.', 'mailwp'), 'success');
        }
    }
    
    /**
     * Redirect with a message
     * 
     * @param string $message Message to display
     * @param string $type Message type (success, error)
     */
    private function redirect_with_message($message, $type = 'success') {
        $messages = [
            [
                'message' => $message,
                'type' => $type
            ]
        ];
        
        set_transient('mailwp_settings_errors', $messages, 30);
        
        wp_redirect(admin_url('options-general.php?page=mailwp-settings&settings-updated=true'));
        exit;
    }
    
    /**
     * Check if Microsoft Graph OAuth is properly configured
     * 
     * @return bool True if configured, false otherwise
     */
    public function is_configured() {
        $client_id = get_option('mailwp_msauth_client_id');
        $tenant_mode = get_option('mailwp_msauth_tenant_mode', 'single');
        $tenant_id = get_option('mailwp_msauth_tenant_id');
        $client_secret = get_option('mailwp_msauth_client_secret');
        $from_email = get_option('mailwp_msauth_from_email');
        
        // Basic requirements
        $basic_config = !empty($client_id) && !empty($client_secret) && !empty($from_email);
        
        // For single-tenant mode, tenant_id is also required
        if ($tenant_mode === 'single') {
            return $basic_config && !empty($tenant_id);
        }
        
        // For multi-tenant mode, tenant_id is optional
        return $basic_config;
    }
    
    /**
     * Check if Microsoft Graph OAuth is authorized
     * 
     * @return bool True if authorized, false otherwise
     */
    public function is_authorized() {
        $access_token = get_option('mailwp_msauth_access_token');
        return !empty($access_token);
    }
}
