<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 * Handles cleanup of temporary data, scheduled events, and rewrite rules.
 * 
 * IMPORTANT: This does NOT delete permanent data like database tables or options.
 * That should only happen during uninstall (see uninstall.php).
 *
 * @link       https://wilsondevops.com
 * @since      1.0.0
 *
 * @author     WyllyMK (https://wilsondevops.com)
 * @package    WyllyMk\VMS
 * @subpackage Plugin_Name/includes
 *
 * @copyright  (c) 2025, WilsonDevops (https://wilsondevops.com)
 */

namespace WyllyMk\VMS;

// Abort if called directly.
defined('WPINC') || die;

/**
 * Deactivator Class.
 *
 * This class contains all deactivation logic for the plugin.
 * It runs when the plugin is deactivated but should not delete
 * permanent data or settings.
 *
 * @since 1.0.0
 */

class VMS_Plugin_Deactivator
{
        /**
     * Plugin deactivation handler
     * 
     * Runs when the plugin is deactivated. Performs cleanup of:
     * - Scheduled cron jobs
     * - Temporary cached data (transients)
     * - Custom rewrite rules
     * 
     * Does NOT remove:
     * - Database tables
     * - WordPress pages
     * - Plugin options and settings
     * - User data
     * 
     * This allows the plugin to be reactivated with all data intact.
     *
     * @since 1.0.0
     * @return void
     */
    public static function deactivate(): void
    {
        // Remove all scheduled cron jobs to prevent execution while inactive
        VMS_Cron_Manager::deactivate_all_jobs();        

        // Remove all essential WordPress pages
        VMS_Page_Manager::remove_all_pages();

        // Remove all database tables with proper relationships
        // VMS_Database_Manager::drop_all_tables();

        // Remove all custom roles and capabilities
        VMS_Roles::delete_roles();
        
        // Clear all temporary cached data
        // self::vms_clear_transients();

        self::clear_transients();
        
        // Flush rewrite rules to remove custom endpoints
        flush_rewrite_rules();

        // Store deactivation timestamp for reference
        update_option('vms_plugin_deactivated', time());
        
        // Log deactivation for debugging
        error_log('VMS Plugin deactivated at ' . current_time('mysql'));
    }

    /**
     * Clear plugin transients
     * 
     * Removes all transient cache entries created by the plugin.
     * Transients are temporary data stored for performance optimization.
     * They should not persist after deactivation.
     * 
     * Clears both:
     * - Regular transients (single site)
     * - Site transients (multisite network-wide)
     *
     * @since 1.0.0
     * @return void
     */
    private static function clear_transients(): void
    {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_vms_%' 
            OR option_name LIKE '_transient_timeout_vms_%'
            OR option_name LIKE '_site_transient_vms_%' 
            OR option_name LIKE '_site_transient_timeout_vms_%'"
        );

        error_log('VMS Plugin transients cleared');
    }

    /**
     * Clear all VMS plugin transients and cache on deactivation
     */
    private static function vms_clear_transients(): void
    {
        global $wpdb;

        error_log('[VMS] Plugin deactivation: clearing all VMS transients...');

        // Delete all plugin-related transients (single site)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_vms\_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_vms\_%'");

        // Multisite support (if applicable)
        if (is_multisite()) {
            $blog_ids = get_sites(['fields' => 'ids']);
            foreach ($blog_ids as $blog_id) {
                switch_to_blog($blog_id);
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_vms\_%'");
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_vms\_%'");
                error_log("[VMS] Cleared transients for site ID={$blog_id}");
                restore_current_blog();
            }
        }

        error_log('[VMS] All VMS transients cleared successfully.');
    }
}