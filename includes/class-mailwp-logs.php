<?php
/**
 * MailWP Logs Class
 * 
 * @package MailWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class that handles all logging functionality for MailWP
 */
class MailWP_Logs {
    
    /**
     * Table name for logs
     */
    const TABLE_NAME = 'mailwp_logs';
    
    /**
     * Log types
     */
    const TYPE_EMAIL_SENT = 'email_sent';
    const TYPE_EMAIL_ERROR = 'email_error';
    const TYPE_AUTH_SUCCESS = 'auth_success';
    const TYPE_AUTH_ERROR = 'auth_error';
    const TYPE_CONFIG_CHANGE = 'config_change';
    const TYPE_TEST_EMAIL = 'test_email';
    const TYPE_TOKEN_REFRESH = 'token_refresh';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_loaded', [$this, 'maybe_create_table']);
        
        // Schedule cleanup event
        if (!wp_next_scheduled('mailwp_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'mailwp_cleanup_logs');
        }
        add_action('mailwp_cleanup_logs', [$this, 'cleanup_old_logs']);
    }
    
    /**
     * Create the logs table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            $this->create_table();
        }
    }
    
    /**
     * Create the logs table
     */
    private function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            details longtext DEFAULT NULL,
            email_to varchar(255) DEFAULT NULL,
            email_subject varchar(255) DEFAULT NULL,
            mailer_type varchar(50) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY level (level),
            KEY created_at (created_at),
            KEY email_to (email_to),
            KEY mailer_type (mailer_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add a log entry
     * 
     * @param string $type Log type (use class constants)
     * @param string $message Log message
     * @param string $level Log level (info, warning, error, success)
     * @param array $details Additional details to store as JSON
     * @return int|false Log ID on success, false on failure
     */
    public function add_log($type, $message, $level = 'info', $details = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Extract specific fields from details
        $email_to = isset($details['email_to']) ? $details['email_to'] : null;
        $email_subject = isset($details['email_subject']) ? $details['email_subject'] : null;
        $mailer_type = isset($details['mailer_type']) ? $details['mailer_type'] : get_option('mailwp_mailer_type', 'smtp');
        
        // Get current user ID
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            $user_id = null;
        }
        
        $result = $wpdb->insert(
            $table_name,
            [
                'type' => $type,
                'level' => $level,
                'message' => $message,
                'details' => !empty($details) ? json_encode($details) : null,
                'email_to' => $email_to,
                'email_subject' => $email_subject,
                'mailer_type' => $mailer_type,
                'user_id' => $user_id,
                'created_at' => current_time('mysql')
            ],
            [
                '%s', // type
                '%s', // level
                '%s', // message
                '%s', // details
                '%s', // email_to
                '%s', // email_subject
                '%s', // mailer_type
                '%d', // user_id
                '%s'  // created_at
            ]
        );
        
        if ($result === false) {
            error_log('MailWP - Failed to insert log: ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get logs with filtering and pagination
     * 
     * @param array $args Query arguments
     * @return array Array with 'logs' and 'total_count'
     */
    public function get_logs($args = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Default arguments
        $defaults = [
            'type' => '',
            'level' => '',
            'mailer_type' => '',
            'per_page' => 50,
            'page' => 1,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'date_from' => '',
            'date_to' => '',
            'search' => ''
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build WHERE clause
        $where_conditions = [];
        $where_values = [];
        
        if (!empty($args['type'])) {
            $where_conditions[] = 'type = %s';
            $where_values[] = $args['type'];
        }
        
        if (!empty($args['level'])) {
            $where_conditions[] = 'level = %s';
            $where_values[] = $args['level'];
        }
        
        if (!empty($args['mailer_type'])) {
            $where_conditions[] = 'mailer_type = %s';
            $where_values[] = $args['mailer_type'];
        }
        
        if (!empty($args['date_from'])) {
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }
        
        if (!empty($args['search'])) {
            $where_conditions[] = '(message LIKE %s OR email_to LIKE %s OR email_subject LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Build ORDER BY clause
        $allowed_orderby = ['id', 'type', 'level', 'created_at', 'email_to', 'mailer_type'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Calculate offset
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM $table_name $where_clause";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_count = (int) $wpdb->get_var($count_query);
        
        // Get logs
        $logs_query = "SELECT * FROM $table_name $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$args['per_page'], $offset]);
        $logs_query = $wpdb->prepare($logs_query, $query_values);
        
        $logs = $wpdb->get_results($logs_query);
        
        // Parse details JSON
        foreach ($logs as $log) {
            if (!empty($log->details)) {
                $log->details = json_decode($log->details, true);
            } else {
                $log->details = [];
            }
        }
        
        return [
            'logs' => $logs,
            'total_count' => $total_count
        ];
    }
    
    /**
     * Get log statistics
     * 
     * @param array $args Query arguments
     * @return array Statistics
     */
    public function get_stats($args = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $defaults = [
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d')
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where_conditions = [];
        $where_values = [];
        
        if (!empty($args['date_from'])) {
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if (!empty($args['date_to'])) {
            $where_conditions[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Get counts by type and level
        $stats_query = "
            SELECT 
                type,
                level,
                COUNT(*) as count
            FROM $table_name 
            $where_clause
            GROUP BY type, level
            ORDER BY type, level
        ";
        
        if (!empty($where_values)) {
            $stats_query = $wpdb->prepare($stats_query, $where_values);
        }
        
        $results = $wpdb->get_results($stats_query);
        
        $stats = [
            'total' => 0,
            'by_type' => [],
            'by_level' => []
        ];
        
        foreach ($results as $result) {
            $stats['total'] += $result->count;
            
            if (!isset($stats['by_type'][$result->type])) {
                $stats['by_type'][$result->type] = 0;
            }
            $stats['by_type'][$result->type] += $result->count;
            
            if (!isset($stats['by_level'][$result->level])) {
                $stats['by_level'][$result->level] = 0;
            }
            $stats['by_level'][$result->level] += $result->count;
        }
        
        return $stats;
    }
    
    /**
     * Delete a log entry
     * 
     * @param int $log_id Log ID
     * @return bool Success
     */
    public function delete_log($log_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $result = $wpdb->delete(
            $table_name,
            ['id' => $log_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Clear all logs
     * 
     * @return bool Success
     */
    public function clear_all_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        return $result !== false;
    }
    
    /**
     * Cleanup old logs (older than 90 days by default)
     * 
     * @param int $days Number of days to keep logs
     * @return int Number of deleted logs
     */
    public function cleanup_old_logs($days = 90) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < %s",
                $cutoff_date
            )
        );
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * Log email sent successfully
     * 
     * @param array $email_data Email data
     */
    public function log_email_sent($email_data) {
        $details = [
            'email_to' => is_array($email_data['to']) ? implode(', ', $email_data['to']) : $email_data['to'],
            'email_subject' => $email_data['subject'],
            'mailer_type' => get_option('mailwp_mailer_type', 'smtp'),
            'cc' => !empty($email_data['cc']) ? (is_array($email_data['cc']) ? implode(', ', $email_data['cc']) : $email_data['cc']) : '',
            'bcc' => !empty($email_data['bcc']) ? (is_array($email_data['bcc']) ? implode(', ', $email_data['bcc']) : $email_data['bcc']) : '',
            'attachments_count' => !empty($email_data['attachments']) ? count($email_data['attachments']) : 0
        ];
        
        $message = sprintf(
            __('Email sent successfully to %s', 'mailwp'),
            is_array($email_data['to']) ? implode(', ', $email_data['to']) : $email_data['to']
        );
        
        $this->add_log(self::TYPE_EMAIL_SENT, $message, 'success', $details);
    }
    
    /**
     * Log email error
     * 
     * @param string $error_message Error message
     * @param array $email_data Email data (optional)
     */
    public function log_email_error($error_message, $email_data = []) {
        $details = [
            'error_message' => $error_message,
            'mailer_type' => get_option('mailwp_mailer_type', 'smtp')
        ];
        
        if (!empty($email_data)) {
            $details['email_to'] = is_array($email_data['to']) ? implode(', ', $email_data['to']) : $email_data['to'];
            $details['email_subject'] = $email_data['subject'];
        }
        
        $message = sprintf(
            __('Email sending failed: %s', 'mailwp'),
            $error_message
        );
        
        $this->add_log(self::TYPE_EMAIL_ERROR, $message, 'error', $details);
    }
    
    /**
     * Log authentication success
     * 
     * @param string $auth_type Authentication type (e.g., 'microsoft_oauth')
     */
    public function log_auth_success($auth_type) {
        $message = sprintf(
            __('Authentication successful for %s', 'mailwp'),
            $auth_type
        );
        
        $details = [
            'auth_type' => $auth_type
        ];
        
        $this->add_log(self::TYPE_AUTH_SUCCESS, $message, 'success', $details);
    }
    
    /**
     * Log authentication error
     * 
     * @param string $auth_type Authentication type
     * @param string $error_message Error message
     */
    public function log_auth_error($auth_type, $error_message) {
        $message = sprintf(
            __('Authentication failed for %s: %s', 'mailwp'),
            $auth_type,
            $error_message
        );
        
        $details = [
            'auth_type' => $auth_type,
            'error_message' => $error_message
        ];
        
        $this->add_log(self::TYPE_AUTH_ERROR, $message, 'error', $details);
    }
    
    /**
     * Log configuration change
     * 
     * @param string $setting Setting name
     * @param string $old_value Old value
     * @param string $new_value New value
     */
    public function log_config_change($setting, $old_value, $new_value) {
        $message = sprintf(
            __('Configuration changed: %s', 'mailwp'),
            $setting
        );
        
        $details = [
            'setting' => $setting,
            'old_value' => $old_value,
            'new_value' => $new_value
        ];
        
        $this->add_log(self::TYPE_CONFIG_CHANGE, $message, 'info', $details);
    }
    
    /**
     * Log test email
     * 
     * @param string $email_to Recipient email
     * @param bool $success Whether the test was successful
     * @param string $error_message Error message if failed
     */
    public function log_test_email($email_to, $success, $error_message = '') {
        $details = [
            'email_to' => $email_to,
            'mailer_type' => get_option('mailwp_mailer_type', 'smtp')
        ];
        
        if ($success) {
            $message = sprintf(
                __('Test email sent successfully to %s', 'mailwp'),
                $email_to
            );
            $this->add_log(self::TYPE_TEST_EMAIL, $message, 'success', $details);
        } else {
            $details['error_message'] = $error_message;
            $message = sprintf(
                __('Test email failed to %s: %s', 'mailwp'),
                $email_to,
                $error_message
            );
            $this->add_log(self::TYPE_TEST_EMAIL, $message, 'error', $details);
        }
    }
    
    /**
     * Log token refresh
     * 
     * @param bool $success Whether the refresh was successful
     * @param string $error_message Error message if failed
     */
    public function log_token_refresh($success, $error_message = '') {
        $details = [
            'auth_type' => 'microsoft_oauth'
        ];
        
        if ($success) {
            $message = __('Access token refreshed successfully', 'mailwp');
            $this->add_log(self::TYPE_TOKEN_REFRESH, $message, 'success', $details);
        } else {
            $details['error_message'] = $error_message;
            $message = sprintf(
                __('Token refresh failed: %s', 'mailwp'),
                $error_message
            );
            $this->add_log(self::TYPE_TOKEN_REFRESH, $message, 'error', $details);
        }
    }
}
