<?php
/**
 * Triggered when the plugin is uninstalled
 *
 * @package MailWP
 */

// If this file is called directly, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
delete_option('mailwp_api_key');
delete_option('mailwp_mailer_type');
delete_option('mailwp_sender_name');
delete_option('mailwp_smtp_host');
delete_option('mailwp_smtp_port');
delete_option('mailwp_smtp_username');
delete_option('mailwp_smtp_password');
delete_option('mailwp_smtp_encryption');
delete_option('mailwp_smtp_from_email'); 