<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 * Handles version checks, database setup, default options, and initial configuration.
 *
 * @link       https://wilsondevops.com
 * @since      1.0.0
 *
 * @author     WyllyMK (https://wilsondevops.com)
 * @package    WyllyMk\VMS
 *
 * @copyright  (c) 2025, WilsonDevops (https://wilsondevops.com)
 */

namespace WyllyMk\VMS;

// Abort if called directly.
defined('WPINC') || die;

/**
 * Activator Class.
 *
 * This class contains all activation logic for the plugin.
 * It runs only once when the plugin is activated.
 *
 * @since 1.0.0
 */
class VMS_Plugin_Activator 
{
    /**
     * Plugin activation handler
     * 
     * Runs when the plugin is activated. Creates all necessary pages,
     * database tables, scheduled jobs, and rewrite rules.
     * 
     * This method is called when the plugin is activated. It performs:
     * - PHP version compatibility check
     * - WordPress version compatibility check
     * - Create WordPress pages
     * - Create database tables
     * - Schedule cron jobs
     * - Add rewrite rules
     * - Flush rewrite rules to apply changes
     * - Store activation metadata
     *
     * @since 1.0.0
     * @return void
     */
    public static function activate(): void
    {
        // Check PHP version compatibility.
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(VMS_PLUGIN_BASENAME);
            wp_die(
                esc_html__('This plugin requires PHP 7.4 or higher.', 'vms-plugin'),
                esc_html__('Plugin Activation Error', 'vms-plugin'),
                array('back_link' => true)
            );
        }

        // Check WordPress version compatibility.
        if (version_compare(get_bloginfo('version'), '6.1', '<')) {
            deactivate_plugins(VMS_PLUGIN_BASENAME);
            wp_die(
                esc_html__('This plugin requires WordPress 6.1 or higher.', 'vms-plugin'),
                esc_html__('Plugin Activation Error', 'vms-plugin'),
                array('back_link' => true)
            );
        }
        
        // Create all essential WordPress pages
        VMS_Page_Manager::create_all_pages();
        
        // Create all database tables with proper relationships
        VMS_Database_Manager::create_all_tables();
        
        // Setup cron schedules for recurring tasks.
        VMS_Core::setup_cron_schedules();
        
        // Schedule all automated cron jobs
        VMS_Cron_Manager::activate_all_jobs();

        // Initialize VMS Roles early.
        VMS_Roles::register_roles_and_capabilities();
        
        // Register custom URL endpoints
        VMS_Rewrite_Manager::add_rewrite_rules();

        // Flush rewrite rules to make new endpoints active
        flush_rewrite_rules();

        // Store activation timestamp for reference
        update_option('vms_plugin_activated', time());
        
        // Store plugin version for future updates
        update_option('vms_plugin_version', '1.0.0');

        // Log successful activation.
        error_log('VMS Plugin activated successfully at ' . current_time('mysql'));
    }
}