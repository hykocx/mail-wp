<?php
/**
 * MailWP admin functionality
 * 
 * @package MailWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class that handles all admin functionality
 */
class MailWP_Admin {
    /**
     * Initialize the admin functionality
     */
    public function __construct() {
        // Add admin menus via admin_menu action
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Add a link to the settings in the plugins list
        add_filter('plugin_action_links_' . plugin_basename(HYMAILWP_PLUGIN_FILE), [$this, 'add_settings_link']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Preserve OAuth tokens during settings save
        add_action('pre_update_option_mailwp_msauth_client_id', [$this, 'preserve_oauth_tokens'], 10, 3);
        add_action('pre_update_option_mailwp_msauth_tenant_id', [$this, 'preserve_oauth_tokens'], 10, 3);
        add_action('pre_update_option_mailwp_msauth_client_secret', [$this, 'preserve_oauth_tokens'], 10, 3);
        
        // Add hook to show stored error messages after redirect
        add_action('admin_notices', [$this, 'show_stored_messages']);
        
        // Setup test routes
        $this->setup_test_routes();
    }
    
    /**
     * Add an admin menu for the plugin
     */
    public function add_admin_menu() {
        add_options_page(
            __('MailWP', 'mailwp'),
            __('MailWP', 'mailwp'), 
            'manage_options', 
            'mailwp-settings', 
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_mailwp-settings') {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Register the plugin settings
     */
    public function register_settings() {
        register_setting(
            'mailwp_settings',
            'mailwp_mailer_type',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'smtp'
            ]
        );

        // SMTP Settings
        register_setting(
            'mailwp_settings',
            'mailwp_smtp_host',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_port',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_username',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_password',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_encryption',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'tls'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_from_name',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_smtp_from_email',
            [
                'sanitize_callback' => 'sanitize_email'
            ]
        );

        // Microsoft Graph OAuth Settings
        register_setting(
            'mailwp_settings',
            'mailwp_msauth_client_id',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_msauth_tenant_id',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_msauth_client_secret',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_msauth_from_email',
            [
                'sanitize_callback' => 'sanitize_email'
            ]
        );

        register_setting(
            'mailwp_settings',
            'mailwp_msauth_from_name',
            [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );

        // Note: Les tokens OAuth ne sont PAS enregistrés comme settings normaux
        // pour éviter qu'ils soient supprimés lors du save du formulaire.
        // Ils sont gérés directement par la classe Microsoft_Graph_OAuth.
    }
    
    /**
     * Preserve OAuth tokens when OAuth config changes
     * 
     * @param mixed $value New value
     * @param mixed $old_value Old value
     * @param string $option Option name
     * @return mixed
     */
    public function preserve_oauth_tokens($value, $old_value, $option) {
        // Si les paramètres OAuth changent, on révoque les tokens existants
        // pour forcer une nouvelle autorisation avec les nouveaux paramètres
        if ($value !== $old_value && !empty($old_value)) {
            delete_option('mailwp_msauth_access_token');
            delete_option('mailwp_msauth_refresh_token');
            delete_option('mailwp_msauth_token_expires');
            
            // Log the configuration change
            global $mailwp_service;
            if ($mailwp_service && $mailwp_service->logs) {
                $option_label = str_replace('mailwp_msauth_', '', $option);
                $mailwp_service->logs->log_config_change("Microsoft OAuth - $option_label", $old_value, $value);
            }
        }
        
        return $value;
    }
    
    /**
     * Add a link to the settings in the plugins list
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=mailwp-settings">' . __('Settings', 'mailwp') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Display the plugin settings page
     */
    public function render_settings_page() {
        // Vérification des autorisations
        if (!current_user_can('manage_options')) {
            wp_die(__('Sorry, you do not have permission to access this page.', 'mailwp'));
        }
        
        // Récupérer l'onglet actif
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'options';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=mailwp-settings&tab=options" class="nav-tab <?php echo $active_tab === 'options' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'mailwp'); ?>
                </a>
                <a href="?page=mailwp-settings&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Testing', 'mailwp'); ?>
                </a>
                <a href="?page=mailwp-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'mailwp'); ?>
                </a>
            </h2>
            
            <?php if ($active_tab === 'options') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('mailwp_settings');
                    do_settings_sections('mailwp_settings');
                    ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Mailer Type', 'mailwp'); ?></th>
                            <td>
                                <select name="mailwp_mailer_type" id="mailwp_mailer_type">
                                    <option value="smtp" <?php selected(get_option('mailwp_mailer_type', 'smtp'), 'smtp'); ?>><?php _e('SMTP', 'mailwp'); ?></option>
                                    <option value="microsoft_graph" <?php selected(get_option('mailwp_mailer_type', 'smtp'), 'microsoft_graph'); ?>><?php _e('Microsoft', 'mailwp'); ?></option>
                                </select>
                                <p class="description"><?php _e('Choose the type of email sending you want to use.', 'mailwp'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div id="smtp_options" style="display: <?php echo get_option('mailwp_mailer_type', 'smtp') === 'smtp' ? 'block' : 'none'; ?>">
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e('SMTP Host', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_smtp_host" value="<?php echo esc_attr(get_option('mailwp_smtp_host')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('SMTP server address (ex: smtp.example.com).', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('SMTP Port', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_smtp_port" value="<?php echo esc_attr(get_option('mailwp_smtp_port', '587')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('SMTP server port (ex: 587 for TLS, 465 for SSL).', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('SMTP Username', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_smtp_username" value="<?php echo esc_attr(get_option('mailwp_smtp_username')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your SMTP username.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('SMTP Password', 'mailwp'); ?></th>
                                <td>
                                    <input type="password" name="mailwp_smtp_password" value="<?php echo esc_attr(get_option('mailwp_smtp_password')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your SMTP password.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Encryption', 'mailwp'); ?></th>
                                <td>
                                    <select name="mailwp_smtp_encryption">
                                        <option value="tls" <?php selected(get_option('mailwp_smtp_encryption', 'tls'), 'tls'); ?>>TLS</option>
                                        <option value="ssl" <?php selected(get_option('mailwp_smtp_encryption', 'tls'), 'ssl'); ?>>SSL</option>
                                        <option value="none" <?php selected(get_option('mailwp_smtp_encryption', 'tls'), 'none'); ?>>None</option>
                                    </select>
                                    <p class="description"><?php _e('Type of encryption to use.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('From Name', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_smtp_from_name" value="<?php echo esc_attr(get_option('mailwp_smtp_from_name', get_bloginfo('name'))); ?>" class="regular-text" />
                                    <p class="description"><?php _e('The name that will appear as the sender of your emails.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('From Email', 'mailwp'); ?></th>
                                <td>
                                    <input type="email" name="mailwp_smtp_from_email" value="<?php echo esc_attr(get_option('mailwp_smtp_from_email')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('The email address that will be used to send emails.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div id="microsoft_graph_options" style="display: <?php echo get_option('mailwp_mailer_type', 'smtp') === 'microsoft_graph' ? 'block' : 'none'; ?>">
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e('Client ID', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_msauth_client_id" value="<?php echo esc_attr(get_option('mailwp_msauth_client_id')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your Azure AD Application Client ID.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Tenant ID', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_msauth_tenant_id" value="<?php echo esc_attr(get_option('mailwp_msauth_tenant_id')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your Azure AD Tenant ID.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Client Secret', 'mailwp'); ?></th>
                                <td>
                                    <input type="password" name="mailwp_msauth_client_secret" value="<?php echo esc_attr(get_option('mailwp_msauth_client_secret')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your Azure AD Application Client Secret.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('From Email', 'mailwp'); ?></th>
                                <td>
                                    <input type="email" name="mailwp_msauth_from_email" value="<?php echo esc_attr(get_option('mailwp_msauth_from_email')); ?>" class="regular-text" />
                                    <p class="description"><?php _e('The Microsoft 365 email address to send emails from.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('From Name', 'mailwp'); ?></th>
                                <td>
                                    <input type="text" name="mailwp_msauth_from_name" value="<?php echo esc_attr(get_option('mailwp_msauth_from_name', get_bloginfo('name'))); ?>" class="regular-text" />
                                    <p class="description"><?php _e('The name that will appear as the sender of your emails.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Authorization Status', 'mailwp'); ?></th>
                                <td>
                                    <?php
                                    $access_token = get_option('mailwp_msauth_access_token');
                                    if (!empty($access_token)): ?>
                                        <p style="color: green;"><strong><?php _e('✓ Authorized', 'mailwp'); ?></strong></p>
                                        <p>
                                            <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=mailwp-settings&action=change_msauth_account'), 'change_msauth_account'); ?>" class="button button-primary">
                                                <?php _e('Change Account', 'mailwp'); ?>
                                            </a>
                                            <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=mailwp-settings&action=revoke_msauth'), 'revoke_msauth'); ?>" class="button button-secondary">
                                                <?php _e('Revoke Authorization', 'mailwp'); ?>
                                            </a>
                                        </p>
                                    <?php else: ?>
                                        <p style="color: red;"><strong><?php _e('✗ Not Authorized', 'mailwp'); ?></strong></p>
                                        <p>
                                            <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=mailwp-settings&action=authorize_msauth'), 'authorize_msauth'); ?>" class="button button-primary">
                                                <?php _e('Authorize with Microsoft', 'mailwp'); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <p class="description"><?php _e('You must authorize the application to send emails on your behalf.', 'mailwp'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <?php submit_button(); ?>
                </form>

                <script>
                jQuery(document).ready(function($) {
                    $('#mailwp_mailer_type').on('change', function() {
                        if ($(this).val() === 'smtp') {
                            $('#smtp_options').show();
                            $('#microsoft_graph_options').hide();
                        } else if ($(this).val() === 'microsoft_graph') {
                            $('#smtp_options').hide();
                            $('#microsoft_graph_options').show();
                        } else {
                            $('#smtp_options').hide();
                            $('#microsoft_graph_options').hide();
                        }
                    });
                });
                </script>
            <?php elseif ($active_tab === 'test') : ?>
                <div id="mailwp-test-result" style="display: none; margin-bottom: 15px;"></div>
                
                <form id="mailwp-custom-test-form" method="post">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Email', 'mailwp'); ?></th>
                            <td>
                                <input type="email" id="mailwp-test-email" name="mailwp_test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required />
                                <p class="description"><?php _e('Enter the email address you want to receive the test email.', 'mailwp'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="mailwp-send-test"><?php _e('Send test email', 'mailwp'); ?></button>
                        <span class="spinner" id="mailwp-test-spinner" style="float: none; margin-top: 0;"></span>
                    </p>
                </form>
                
                <script>
                    jQuery(document).ready(function($) {
                        $('#mailwp-custom-test-form').on('submit', function(e) {
                            e.preventDefault();
                            
                            var email = $('#mailwp-test-email').val();
                            
                            $('#mailwp-send-test').prop('disabled', true);
                            $('#mailwp-test-spinner').addClass('is-active');
                            $('#mailwp-test-result').hide();
                            
                            $.post(ajaxurl, {
                                action: 'mailwp_send_test_email',
                                email: email,
                                subject: "<?php echo esc_js(__('Test email via MailWP', 'mailwp')); ?>",
                                nonce: '<?php echo wp_create_nonce('mailwp_test_nonce'); ?>'
                            }, function(response) {
                                $('#mailwp-test-result').html(response).show();
                                $('#mailwp-send-test').prop('disabled', false);
                                $('#mailwp-test-spinner').removeClass('is-active');
                            }).fail(function() {
                                $('#mailwp-test-result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('An error occurred while sending the test email.', 'mailwp')); ?></p></div>').show();
                                $('#mailwp-send-test').prop('disabled', false);
                                $('#mailwp-test-spinner').removeClass('is-active');
                            });
                        });
                    });
                </script>
            <?php elseif ($active_tab === 'logs') : ?>
                <?php $this->render_logs_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Setup test routes for admin functionality
     */
    private function setup_test_routes() {
        if (is_admin()) {
            // Ajouter les hooks pour les tests via URL
            add_action('admin_init', function() {
                if (isset($_GET['mailwp_test']) && $_GET['mailwp_test'] === '1') {
                    $this->test_email();
                }
            });
            
            // Add AJAX endpoint for custom test email
            add_action('wp_ajax_mailwp_send_test_email', [$this, 'ajax_test_email']);
            
            // Add AJAX endpoint for clearing logs
            add_action('wp_ajax_mailwp_clear_logs', [$this, 'ajax_clear_logs']);
        }
    }
    
    /**
     * AJAX handler for sending test email
     */
    public function ajax_test_email() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mailwp_test_nonce')) {
            wp_send_json_error(__('Security: Invalid nonce.', 'mailwp'));
            wp_die();
        }
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions.', 'mailwp'));
            wp_die();
        }
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : __('Test email via MailWP', 'mailwp');
        
        if (empty($email)) {
            echo '<div class="notice notice-error inline"><p>' . __('Please enter a valid email address.', 'mailwp') . '</p></div>';
            wp_die();
        }
        
        // Check if the selected mailer is configured
        $mailer_type = get_option('mailwp_mailer_type', 'smtp');
        
        if ($mailer_type === 'smtp') {
            $smtp_host = get_option('mailwp_smtp_host', '');
            if (empty($smtp_host)) {
                echo '<div class="notice notice-error inline"><p>' . __('Error: SMTP host not configured. Please configure your SMTP settings first.', 'mailwp') . '</p></div>';
                wp_die();
            }
        } elseif ($mailer_type === 'microsoft_graph') {
            global $mailwp_service;
            if (!$mailwp_service->microsoft_oauth->is_configured()) {
                echo '<div class="notice notice-error inline"><p>' . __('Error: Microsoft Graph OAuth not configured. Please configure your Microsoft Graph settings first.', 'mailwp') . '</p></div>';
                wp_die();
            }
            
            if (!$mailwp_service->microsoft_oauth->is_authorized()) {
                echo '<div class="notice notice-error inline"><p>' . __('Error: Microsoft Graph OAuth not authorized. Please authorize the application first.', 'mailwp') . '</p></div>';
                wp_die();
            }
        }
        
        $message = __('This is a test email sent via MailWP. If you receive this email, the configuration is working correctly. Please verify the sender address to ensure it matches your expected configuration.', 'mailwp');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        add_action('wp_mail_failed', function($wp_error) {
            if (is_wp_error($wp_error)) {
                echo '<div class="notice notice-error inline"><p>' . esc_html($wp_error->get_error_message()) . '</p></div>';
                wp_die();
            }
        });
        
        $result = wp_mail($email, $subject, $message, $headers);
        
        // Log the test email attempt
        global $mailwp_service;
        if ($mailwp_service && $mailwp_service->logs) {
            $mailwp_service->logs->log_test_email($email, $result, $result ? '' : 'Test email failed');
        }
        
        if ($result) {
            echo '<div class="notice notice-success inline"><p>' . sprintf(__('Test email sent successfully to %s!', 'mailwp'), esc_html($email)) . '</p></div>';
        } else {
            echo '<div class="notice notice-error inline"><p>' . __('Failed to send test email. Check configuration and logs for details.', 'mailwp') . '</p></div>';
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for clearing logs
     */
    public function ajax_clear_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mailwp_clear_logs')) {
            wp_send_json_error(__('Security: Invalid nonce.', 'mailwp'));
            wp_die();
        }
        
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions.', 'mailwp'));
            wp_die();
        }
        
        global $mailwp_service;
        
        if (!$mailwp_service || !$mailwp_service->logs) {
            wp_send_json_error(__('Logs functionality not available.', 'mailwp'));
            wp_die();
        }
        
        $result = $mailwp_service->logs->clear_all_logs();
        
        if ($result) {
            wp_send_json_success(__('All logs cleared successfully.', 'mailwp'));
        } else {
            wp_send_json_error(__('Failed to clear logs.', 'mailwp'));
        }
        
        wp_die();
    }
    
    /**
     * Render the logs tab
     */
    public function render_logs_tab() {
        global $mailwp_service;
        
        if (!$mailwp_service || !$mailwp_service->logs) {
            echo '<div class="notice notice-error"><p>' . __('Logs functionality not available.', 'mailwp') . '</p></div>';
            return;
        }
        
        // Get filter parameters
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $search = isset($_GET['log_search']) ? sanitize_text_field($_GET['log_search']) : '';
        
        // Get logs
        $logs_data = $mailwp_service->logs->get_logs([
            'page' => $current_page,
            'per_page' => $per_page,
            'search' => $search
        ]);
        
        $logs = $logs_data['logs'];
        $total_logs = $logs_data['total_count'];
        $total_pages = ceil($total_logs / $per_page);
        ?>
        
        <div class="mailwp-logs-container">
            
            <!-- Page Header -->
            <div style="margin: 20px 0;">
                <h3><?php _e('Email Activity Logs', 'mailwp'); ?></h3>
                <p class="description">
                    <?php _e('Track email sending activity, authentication events, and system errors. Logs are automatically cleaned up after 90 days.', 'mailwp'); ?>
                </p>
            </div>

            <!-- Search and Actions Section -->
            <div class="postbox">
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="log_search"><?php _e('Search Logs', 'mailwp'); ?></label>
                            </th>
                            <td>
                                <form method="get" action="">
                                    <input type="hidden" name="page" value="mailwp-settings">
                                    <input type="hidden" name="tab" value="logs">
                                    
                                    <div style="display: flex; gap: 10px; align-items: center; max-width: 600px;">
                                        <input type="text" name="log_search" id="log_search" value="<?php echo esc_attr($search); ?>" 
                                               placeholder="<?php _e('Search by keywords, email addresses, messages...', 'mailwp'); ?>" 
                                               class="regular-text" style="flex: 1;">
                                        <button type="submit" class="button button-primary"><?php _e('Search', 'mailwp'); ?></button>
                                        <?php if (!empty($search)): ?>
                                            <a href="?page=mailwp-settings&tab=logs" class="button"><?php _e('Clear', 'mailwp'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                                <p class="description">
                                    <?php _e('Search through log messages, email addresses, subjects, and error details.', 'mailwp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Actions', 'mailwp'); ?></th>
                            <td>
                                <button type="button" class="button button-secondary" id="mailwp-clear-logs">
                                    <?php _e('Clear All Logs', 'mailwp'); ?>
                                </button>
                                <span class="spinner" id="mailwp-logs-spinner" style="float: none; margin-left: 10px;"></span>
                                <p class="description">
                                    <?php _e('Permanently delete all log entries. This action cannot be undone.', 'mailwp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Results Info -->
            <?php if (!empty($search)): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php printf(__('Search Results: %d logs found for "%s"', 'mailwp'), $total_logs, esc_html($search)); ?></strong>
                    </p>
                </div>
            <?php elseif ($total_logs > 0): ?>
                <div style="margin: 15px 0;">
                    <p class="description">
                        <?php printf(__('Showing %d total log entries', 'mailwp'), $total_logs); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- Logs Table -->
            <div class="mailwp-logs-table-container">
                <?php if (empty($logs)): ?>
                    <div class="notice notice-info">
                        <p>
                            <?php if (!empty($search)): ?>
                                <?php _e('No logs found matching your search criteria.', 'mailwp'); ?>
                            <?php else: ?>
                                <?php _e('No logs found. Logs will appear here when emails are sent or when authentication events occur.', 'mailwp'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Table Container with WordPress styling -->
                    <div style="background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th width="120"><?php _e('Date/Time', 'mailwp'); ?></th>
                                <th width="80"><?php _e('Level', 'mailwp'); ?></th>
                                <th width="100"><?php _e('Type', 'mailwp'); ?></th>
                                <th><?php _e('Message', 'mailwp'); ?></th>
                                <th width="200"><?php _e('Email/Details', 'mailwp'); ?></th>
                                <th width="80"><?php _e('Mailer', 'mailwp'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <div style="font-size: 12px;">
                                            <?php echo esc_html(mysql2date('Y-m-d', $log->created_at)); ?><br>
                                            <?php echo esc_html(mysql2date('H:i:s', $log->created_at)); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $level_colors = [
                                            'success' => '#00a32a',
                                            'info' => '#0073aa',
                                            'warning' => '#dba617',
                                            'error' => '#d63638'
                                        ];
                                        $color = $level_colors[$log->level] ?? '#666';
                                        ?>
                                        <span style="color: <?php echo $color; ?>; font-weight: bold;">
                                            <?php echo esc_html(ucfirst($log->level)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $type_labels = [
                                            'email_sent' => __('Email Sent', 'mailwp'),
                                            'email_error' => __('Email Error', 'mailwp'),
                                            'auth_success' => __('Auth Success', 'mailwp'),
                                            'auth_error' => __('Auth Error', 'mailwp'),
                                            'test_email' => __('Test Email', 'mailwp'),
                                            'token_refresh' => __('Token Refresh', 'mailwp'),
                                            'config_change' => __('Config Change', 'mailwp')
                                        ];
                                        echo esc_html($type_labels[$log->type] ?? $log->type);
                                        ?>
                                    </td>
                                    <td>
                                        <div style="max-width: 400px; overflow: hidden; text-overflow: ellipsis;">
                                            <?php echo esc_html($log->message); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log->email_to): ?>
                                            <div style="font-size: 11px; margin-bottom: 3px;">
                                                <strong><?php _e('To:', 'mailwp'); ?></strong> <?php echo esc_html($log->email_to); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($log->email_subject): ?>
                                            <div style="font-size: 11px; margin-bottom: 3px;">
                                                <strong><?php _e('Subject:', 'mailwp'); ?></strong> <?php echo esc_html(wp_trim_words($log->email_subject, 5)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($log->details)): ?>
                                            <button type="button" class="button button-small mailwp-show-details" data-details="<?php echo esc_attr(json_encode($log->details)); ?>">
                                                <?php _e('Details', 'mailwp'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log->mailer_type): ?>
                                            <span style="font-size: 11px; padding: 2px 6px; background: #f0f0f1; border-radius: 3px;">
                                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $log->mailer_type))); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav bottom">
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php printf(__('%s items', 'mailwp'), number_format_i18n($total_logs)); ?>
                                </span>
                                
                                <?php
                                $base_url = admin_url('options-general.php?page=mailwp-settings&tab=logs');
                                $query_params = [];
                                if ($search) $query_params['log_search'] = $search;
                                
                                if ($current_page > 1) {
                                    $prev_params = array_merge($query_params, ['paged' => $current_page - 1]);
                                    $prev_url = add_query_arg($prev_params, $base_url);
                                    echo '<a class="prev-page button" href="' . esc_url($prev_url) . '">';
                                    echo '<span class="screen-reader-text">' . __('Previous page') . '</span>';
                                    echo '<span aria-hidden="true">‹</span>';
                                    echo '</a>';
                                } else {
                                    echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
                                }
                                
                                echo '<span class="paging-input">';
                                echo '<label for="current-page-selector" class="screen-reader-text">' . __('Current Page') . '</label>';
                                echo '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' . $current_page . '" size="' . strlen($total_pages) . '" aria-describedby="table-paging">';
                                echo '<span class="tablenav-paging-text">' . sprintf(__(' of %s', 'mailwp'), '<span class="total-pages">' . number_format_i18n($total_pages) . '</span>') . '</span>';
                                echo '</span>';
                                
                                if ($current_page < $total_pages) {
                                    $next_params = array_merge($query_params, ['paged' => $current_page + 1]);
                                    $next_url = add_query_arg($next_params, $base_url);
                                    echo '<a class="next-page button" href="' . esc_url($next_url) . '">';
                                    echo '<span class="screen-reader-text">' . __('Next page') . '</span>';
                                    echo '<span aria-hidden="true">›</span>';
                                    echo '</a>';
                                } else {
                                    echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Details Modal -->
        <div id="mailwp-details-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 4px; max-width: 80%; max-height: 80%; overflow: auto;">
                <h3><?php _e('Log Details', 'mailwp'); ?></h3>
                <div id="mailwp-details-content"></div>
                <div style="margin-top: 15px; text-align: right;">
                    <button type="button" class="button button-primary" id="mailwp-close-details"><?php _e('Close', 'mailwp'); ?></button>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Clear logs functionality
            $('#mailwp-clear-logs').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to clear all logs? This action cannot be undone.', 'mailwp')); ?>')) {
                    return;
                }
                
                $('#mailwp-logs-spinner').addClass('is-active');
                
                $.post(ajaxurl, {
                    action: 'mailwp_clear_logs',
                    nonce: '<?php echo wp_create_nonce('mailwp_clear_logs'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('<?php echo esc_js(__('Error clearing logs.', 'mailwp')); ?>');
                    }
                    $('#mailwp-logs-spinner').removeClass('is-active');
                }).fail(function() {
                    alert('<?php echo esc_js(__('Error clearing logs.', 'mailwp')); ?>');
                    $('#mailwp-logs-spinner').removeClass('is-active');
                });
            });
            
            // Show details modal
            $('.mailwp-show-details').on('click', function() {
                var details = $(this).data('details');
                var content = '<pre style="background: #f8f8f8; padding: 10px; border-radius: 3px; overflow: auto; max-height: 400px;">' + 
                             JSON.stringify(details, null, 2) + '</pre>';
                $('#mailwp-details-content').html(content);
                $('#mailwp-details-modal').show();
            });
            
            // Close details modal
            $('#mailwp-close-details, #mailwp-details-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#mailwp-details-modal').hide();
                }
            });
        });
        </script>
        
        <?php
    }
    
    /**
     * Show stored settings error messages
     */
    public function show_stored_messages() {
        // Only on our settings page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_mailwp-settings') {
            return;
        }
        
        // Check if we have stored messages
        $stored_errors = get_transient('mailwp_settings_errors');
        if ($stored_errors) {
            // Afficher directement les messages stockés
            foreach ($stored_errors as $error) {
                $class = ($error['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
                printf(
                    '<div class="%1$s"><p>%2$s</p></div>',
                    esc_attr($class),
                    esc_html($error['message'])
                );
            }
            
            // Clean up the transient
            delete_transient('mailwp_settings_errors');
        }
    }
    
    /**
     * Function to test email sending
     */
    public function test_email() {
        // Only accessible to administrators
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mailwp'));
        }
        
        // Check if the selected mailer is configured
        $mailer_type = get_option('mailwp_mailer_type', 'smtp');
        
        if ($mailer_type === 'smtp') {
            $smtp_host = get_option('mailwp_smtp_host', '');
            if (empty($smtp_host)) {
                add_settings_error(
                    'mailwp_settings',
                    'mailwp_smtp_host_missing',
                    __('Error: SMTP host not configured. Please configure your SMTP settings first.', 'mailwp'),
                    'error'
                );
                
                // Store the messages so they can be displayed after redirect
                set_transient('mailwp_settings_errors', get_settings_errors('mailwp_settings'), 30);
                
                // Redirect to the settings page
                wp_redirect(admin_url('options-general.php?page=mailwp-settings&settings-updated=true'));
                exit;
            }
        } elseif ($mailer_type === 'microsoft_graph') {
            global $mailwp_service;
            if (!$mailwp_service->microsoft_oauth->is_configured()) {
                add_settings_error(
                    'mailwp_settings',
                    'mailwp_msauth_not_configured',
                    __('Error: Microsoft Graph OAuth not configured. Please configure your Microsoft Graph settings first.', 'mailwp'),
                    'error'
                );
                
                // Store the messages so they can be displayed after redirect
                set_transient('mailwp_settings_errors', get_settings_errors('mailwp_settings'), 30);
                
                // Redirect to the settings page
                wp_redirect(admin_url('options-general.php?page=mailwp-settings&settings-updated=true'));
                exit;
            }
            
            if (!$mailwp_service->microsoft_oauth->is_authorized()) {
                add_settings_error(
                    'mailwp_settings',
                    'mailwp_msauth_not_authorized',
                    __('Error: Microsoft Graph OAuth not authorized. Please authorize the application first.', 'mailwp'),
                    'error'
                );
                
                // Store the messages so they can be displayed after redirect
                set_transient('mailwp_settings_errors', get_settings_errors('mailwp_settings'), 30);
                
                // Redirect to the settings page
                wp_redirect(admin_url('options-general.php?page=mailwp-settings&settings-updated=true'));
                exit;
            }
        }
        
        // Add a hook to capture mail errors
        add_action('wp_mail_failed', [$this, 'capture_test_mail_error']);
        
        // Send a test email
        $to = get_option('admin_email');
        $subject = __('Test email via MailWP', 'mailwp');
        $message = __('This is a test email sent via MailWP. If you receive this email, the configuration is working correctly. Please verify the sender email address to ensure it matches your expected configuration.', 'mailwp');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        // Remove the hook to avoid affecting other emails
        remove_action('wp_mail_failed', [$this, 'capture_test_mail_error']);
        
        // Check if an error was captured
        $mail_error = get_transient('mailwp_test_mail_error');
        delete_transient('mailwp_test_mail_error');
        
        if ($mail_error) {
            // We have a specific error from the mail system
            add_settings_error(
                'mailwp_settings',
                'mailwp_test_error',
                sprintf(
                    __('Failed to send test email: %s', 'mailwp'),
                    $mail_error
                ),
                'error'
            );
        } elseif (!$result) {
            // Generic failure
            add_settings_error(
                'mailwp_settings',
                'mailwp_test_error',
                __('Failed to send test email. Check configuration and logs for details.', 'mailwp'),
                'error'
            );
        } else {
            // Success
            add_settings_error(
                'mailwp_settings',
                'mailwp_test_success',
                sprintf(
                    __('Test email sent successfully to %s! Please check your inbox.', 'mailwp'),
                    esc_html($to)
                ),
                'updated'
            );
        }
        
        // Store the messages so they can be displayed after redirect
        set_transient('mailwp_settings_errors', get_settings_errors('mailwp_settings'), 30);
        
        // Redirect to the settings page
        wp_redirect(admin_url('options-general.php?page=mailwp-settings&settings-updated=true'));
        exit;
    }
    
    /**
     * Capture mail error during test
     * 
     * @param \WP_Error $wp_error WordPress error
     */
    public function capture_test_mail_error($wp_error) {
        // Store the error message in a transient
        set_transient('mailwp_test_mail_error', $wp_error->get_error_message(), 30);
        
        // Also log it
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MailWP - Test email error: ' . $wp_error->get_error_message());
        }
    }
}

/**
 * Initialize admin functionality
 */
function mailwp_admin_init() {
    global $mailwp_admin;
    $mailwp_admin = new MailWP_Admin();
}
// Initialiser l'admin plus tôt pour s'assurer que les menus sont ajoutés correctement
add_action('plugins_loaded', 'mailwp_admin_init'); 