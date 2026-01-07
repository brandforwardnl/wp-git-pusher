<?php
/**
 * Uninstall script.
 *
 * @package Brandforward\GithubPush
 */

// Exit if uninstall not called from WordPress.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete options.
delete_option('github_push_token');
delete_option('github_push_default_branch');
delete_option('github_push_default_strategy');
delete_option('github_push_webhook_secret');
delete_option('github_push_update_interval');
delete_option('github_push_auto_update');

// Drop custom table.
$table_name = $wpdb->prefix . 'github_push_repositories';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Clear scheduled cron events.
wp_clear_scheduled_hook('github_push_check_updates');

// Optionally remove log files (uncomment if desired).
/*
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/github-push-logs';
if (file_exists($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}
*/

