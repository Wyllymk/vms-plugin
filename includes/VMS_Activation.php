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
        self::create_database_tables();
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
                'title' => 'Register Employee',
                'template' => 'page-templates/page-register-employee.php'
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
                'title' => 'Terms and Conditions'
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
                'title' => 'Employees',
                'template' => 'page-templates/page-employees.php'
            ],
            [
                'title' => 'Employee Details',
                'template' => 'page-templates/page-employee-details.php'
            ],
            [
                'title' => 'Clients',
                'template' => 'page-templates/page-clients.php'
            ],
            [
                'title' => 'Client Details',
                'template' => 'page-templates/page-client-details.php'
            ],
            [
                'title' => 'Tasks',
                'template' => 'page-templates/page-tasks.php'
            ],
            [
                'title' => 'Cases',
                'template' => 'page-templates/page-cases.php'
            ],
            [
                'title' => 'Files',
                'template' => 'page-templates/page-files.php'
            ],
            [
                'title' => 'Payments',
                'template' => 'page-templates/page-payments.php'
            ],
            [
                'title' => 'Messages',
                'template' => 'page-templates/page-messages.php'
            ],
            [
                'title' => 'Settings',
                'template' => 'page-templates/page-settings.php'
            ],
            [
                'title' => 'Calendar',
                'template' => 'page-templates/page-calendar.php'
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
     * Create transactions table
     */
    private static function create_transactions_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'transactions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            phone_number VARCHAR(15) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE SET NULL,
            INDEX (user_id)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create messages table
     */
    private static function create_messages_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mobilesasa_messages';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED,
            phone_number VARCHAR(15) NOT NULL,
            message TEXT NOT NULL,
            message_id VARCHAR(36) NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('Pending', 'Sent', 'Failed') DEFAULT 'Pending',
            INDEX (user_id),
            INDEX (phone_number),
            INDEX (message_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
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
            'register-advocate',
            'lost-password',
            'password-reset',
            'terms-of-service',
            'profile',
            'dashboard',
            'employees',
            'advocate-details',
            'clients',
            'client-details',
            'tasks',
            'payments',
            'messages',
            'settings',
            'calendar'
        ];
        
        foreach ($pages as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_delete_post($page->ID, true);
            }
        }
    }
}