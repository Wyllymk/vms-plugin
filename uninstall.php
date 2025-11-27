<?php
/**
 * Uninstall Script - Complete cleanup on plugin deletion
 *
 * This file is executed automatically when the VMS Plugin is permanently deleted
 * through the WordPress admin interface.
 *
 * It performs a full cleanup of all plugin data, including:
 * - Database tables
 * - WordPress pages
 * - Options and settings
 * - Transients (cached data)
 * - User metadata
 * - Capabilities
 * - Scheduled cron jobs
 * - Uploaded and temporary files
 * - Custom post types and taxonomies
 * - Comment metadata
 *
 * WARNING: This process is irreversible.
 * Once executed, all plugin-related data will be permanently deleted.
 *
 * @package    WyllyMk\VMS
 * @author     WyllyMK
 * @link       https://wilsondevops.com
 * @since      1.0.0
 */

// -----------------------------------------------------------------------------
// Security Check - Ensure uninstall is called from WordPress only
// -----------------------------------------------------------------------------
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// -----------------------------------------------------------------------------
// Helper Function: Recursively delete directory and all contents
// -----------------------------------------------------------------------------
if (!function_exists('vms_delete_directory_recursive')) {
    /**
     * Recursively delete a directory and all its contents.
     *
     * @param string $dir Directory path.
     * @return bool True on success, false on failure.
     */
    function vms_delete_directory_recursive($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                vms_delete_directory_recursive($path);
            } else {
                wp_delete_file($path);
            }
        }

        return rmdir($dir);
    }
}

// -----------------------------------------------------------------------------
// Step 1: Clear all scheduled cron events
// -----------------------------------------------------------------------------
$cron_hooks = array(
    'vms_daily_cleanup',
    'vms_hourly_check',
    'vms_weekly_report',
    'vms_auto_checkout',
    'vms_send_notifications',
    'vms_backup_data',
    'vms_sync_external',
);

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

error_log('VMS Plugin scheduled events cleared during uninstall');

// -----------------------------------------------------------------------------
// Step 2: Drop all custom database tables with proper escaping
// -----------------------------------------------------------------------------
$wpdb->query($wpdb->prepare(
    "DROP TABLE IF EXISTS %s",
    $wpdb->prefix . 'vms_visitors'
));
$wpdb->query($wpdb->prepare(
    "DROP TABLE IF EXISTS %s",
    $wpdb->prefix . 'vms_visitor_logs'
));

error_log('VMS Plugin database tables deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 3: Delete all plugin options and settings
// -----------------------------------------------------------------------------
$plugin_options = array(
    'vms_plugin_version',
    'vms_plugin_activated',
    'vms_plugin_deactivated',
    'vms_plugin_settings',
    'vms_plugin_email_settings',
    'vms_api_key',
    'vms_license_key',
    'vms_last_sync',
);

foreach ($plugin_options as $option) {
    delete_option($option);
}

// Delete all remaining options starting with vms_ using prepared statement
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    'vms_%'
));

error_log('VMS Plugin options deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 4: Delete all transients (regular and site-wide) with prepared statements
// -----------------------------------------------------------------------------
$transient_patterns = [
    '_transient_vms_%',
    '_transient_timeout_vms_%',
    '_site_transient_vms_%',
    '_site_transient_timeout_vms_%'
];

$where_clause = implode(' OR ', array_fill(0, count($transient_patterns), 'option_name LIKE %s'));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE " . $where_clause,
    ...$transient_patterns
));

error_log('VMS Plugin transients deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 5: Delete all user metadata created by the plugin with prepared statement
// -----------------------------------------------------------------------------
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    'vms_%'
));

error_log('VMS Plugin user meta deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 6: Remove plugin capabilities from roles
// -----------------------------------------------------------------------------
$capabilities = array(
    'manage_vms_visitors',
    'view_vms_reports',
    'edit_vms_settings',
    'approve_vms_visitors',
);

$roles = array('administrator', 'editor', 'author');

foreach ($roles as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        foreach ($capabilities as $cap) {
            $role->remove_cap($cap);
        }
    }
}

error_log('VMS Plugin capabilities removed during uninstall');

// -----------------------------------------------------------------------------
// Step 7: Delete uploaded and temporary files
// -----------------------------------------------------------------------------
$upload_dir = wp_upload_dir();
$vms_upload_dir = $upload_dir['basedir'] . '/vms-uploads/';
$vms_temp_dir   = $upload_dir['basedir'] . '/vms-temp/';

if (is_dir($vms_upload_dir)) {
    vms_delete_directory_recursive($vms_upload_dir);
}

if (is_dir($vms_temp_dir)) {
    vms_delete_directory_recursive($vms_temp_dir);
}

error_log('VMS Plugin uploaded files and directories deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 8: Delete custom post types and their metadata
// -----------------------------------------------------------------------------
$post_types = array('vms_visitor', 'vms_appointment', 'vms_badge');

foreach ($post_types as $post_type) {
    $posts = $wpdb->get_results(
        $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type)
    );

    foreach ($posts as $post) {
        $wpdb->delete($wpdb->postmeta, array('post_id' => $post->ID), array('%d'));
        $wpdb->delete($wpdb->posts, array('ID' => $post->ID), array('%d'));
    }
}

error_log('VMS Plugin custom post types deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 9: Delete custom taxonomies and terms
// -----------------------------------------------------------------------------
$taxonomies = array('vms_visitor_type', 'vms_department', 'vms_location');

foreach ($taxonomies as $taxonomy) {
    $terms = $wpdb->get_results(
        $wpdb->prepare("
            SELECT t.term_id, tt.term_taxonomy_id 
            FROM {$wpdb->terms} AS t
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s
        ", $taxonomy)
    );

    foreach ($terms as $term) {
        $wpdb->delete($wpdb->term_relationships, array('term_taxonomy_id' => $term->term_taxonomy_id), array('%d'));
        $wpdb->delete($wpdb->term_taxonomy, array('term_taxonomy_id' => $term->term_taxonomy_id), array('%d'));
        $wpdb->delete($wpdb->termmeta, array('term_id' => $term->term_id), array('%d'));
        $wpdb->delete($wpdb->terms, array('term_id' => $term->term_id), array('%d'));
    }
}

error_log('VMS Plugin custom taxonomies deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 10: Delete comment meta created by the plugin with prepared statement
// -----------------------------------------------------------------------------
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s",
    'vms_%'
));

error_log('VMS Plugin comment meta deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 11: Delete plugin-created WordPress pages (if any) with prepared statement
// -----------------------------------------------------------------------------
$pages = $wpdb->get_results($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name LIKE %s",
    'page',
    'vms_%'
));

foreach ($pages as $page) {
    wp_delete_post($page->ID, true);
}

error_log('VMS Plugin pages deleted during uninstall');

// -----------------------------------------------------------------------------
// Step 12: Flush rewrite rules to remove custom endpoints
// -----------------------------------------------------------------------------
flush_rewrite_rules();

error_log('VMS Plugin rewrite rules flushed during uninstall');

// -----------------------------------------------------------------------------
// Step 13: Optional - Notify admin of successful uninstall
// -----------------------------------------------------------------------------
wp_mail(
    get_option('admin_email'),
    'VMS Plugin Uninstalled',
    'The Visitor Management System (VMS) Plugin has been completely uninstalled. All plugin data has been permanently removed from your WordPress site.',
    array('Content-Type: text/html; charset=UTF-8')
);

// -----------------------------------------------------------------------------
// Final Log Entry
// -----------------------------------------------------------------------------
error_log('✅ VMS Plugin completely uninstalled at ' . current_time('mysql'));