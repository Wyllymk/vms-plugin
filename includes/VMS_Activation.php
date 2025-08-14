<?php
/**
 * Handles plugin activation, deactivation, and uninstallation tasks
 */

namespace WyllyMk\VMS;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Activation
{
    /**
     * Activation tasks
     */
    public static function activate(): void
    {
        self::create_essential_pages();
        // self::create_database_tables();
        flush_rewrite_rules();
    }

    /**
     * Deactivation tasks
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Uninstallation tasks
     */
    public static function uninstall(): void
    {
        if (get_option('cyber_wakili_remove_all_data', false)) {
            self::remove_plugin_data();
        }
    }

    /**
     * Create essential pages for the plugin
     */
    private static function create_essential_pages(): void
    {
        $pages = [
            [
                'title' => 'Login',
                'template' => 'page-templates/page-login.php'
            ],
            [
                'title' => 'Register',
                'template' => 'page-templates/page-register.php'
            ],
            [
                'title' => 'Lost Password',
                'template' => 'page-templates/page-lostpassword.php'
            ],
            [
                'title' => 'Password Reset',
                'template' => 'page-templates/page-password-reset.php'
            ],
            [
                'title' => 'Terms & Conditions'
            ],
            [
                'title' => 'Profile',
                'template' => 'page-templates/page-profile.php'
            ],
            [
                'title' => 'Dashboard',
                'template' => 'page-templates/page-dashboard.php'
            ],
            [
                'title' => 'Members',
                'template' => 'page-templates/page-members.php'
            ],
            [
                'title' => 'Employees',
                'template' => 'page-templates/page-employees.php'
            ],
            [
                'title' => 'Employee Details',
                'template' => 'page-templates/page-employee-details.php'
            ],
            [
                'title' => 'Guests',
                'template' => 'page-templates/page-guests.php'
            ],
            [
                'title' => 'Guest Details',
                'template' => 'page-templates/page-guest-details.php'
            ],            
            [
                'title' => 'Settings',
                'template' => 'page-templates/page-settings.php'
            ]
        ];

        foreach ($pages as $page) {
            $slug = sanitize_title($page['title']); // Generate slug from title if not provided
            if (!self::page_exists($slug)) {
                self::create_page($page['title'], $slug, $page['template'] ?? '');
            }
        }
    }

    /**
     * Check if a page exists by slug
     */
    private static function page_exists(string $slug): bool
    {
        return (bool) get_page_by_path($slug);
    }

    /**
     * Create a new page
     */
    private static function create_page(string $title, string $slug, string $template = ''): ?int
    {
        $page_id = wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => ''
        ]);

        if (!is_wp_error($page_id) && $template) {
            update_post_meta($page_id, '_wp_page_template', $template);
        }

        return is_wp_error($page_id) ? null : $page_id;
    }

    /**
     * Create all required database tables
     */
    private static function create_database_tables(): void
    {
        self::create_transactions_table();
        self::create_messages_table();
    }

    /**
     * Clean up plugin data during uninstall
     */
    private static function remove_plugin_data(): void
    {
        // Remove custom tables
        self::drop_database_tables();
        
        // Remove plugin options
        delete_option('cyber_wakili_remove_all_data');
        
        // Remove created pages
        self::remove_created_pages();
    }

    /**
     * Drop custom database tables
     */
    private static function drop_database_tables(): void
    {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'transactions',
            $wpdb->prefix . 'mobilesasa_messages'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * Remove pages created by the plugin
     */
    private static function remove_created_pages(): void
    {
        $pages = [
            'login',
            'register',
            'lost-password',
            'password-reset',
            'terms-conditions',
            'profile',
            'dashboard',
            'employees',
            'employee-details',
            'members',
            'member-details',
            'guests',
            'guest-details',
            'settings',
        ];
        
        foreach ($pages as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_delete_post($page->ID, true);
            }
        }
    }
}