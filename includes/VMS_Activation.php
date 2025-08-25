<?php
/**
 * Handles plugin activation, deactivation, and uninstallation tasks
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Activation
{
    public static function activate(): void
    {
        self::create_essential_pages();
        self::create_database_tables();
        self::activate_cron_jobs();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        self::deactivate_cron_jobs();
        flush_rewrite_rules();
    }

    public static function uninstall(): void
    {
        if (get_option('vms_remove_all_data', false)) {
            self::remove_plugin_data();
        }
    }

    private static function create_essential_pages(): void
    {
        $pages = [
            ['title' => 'Login', 'template' => 'page-templates/page-login.php'],
            ['title' => 'Register', 'template' => 'page-templates/page-register.php'],
            ['title' => 'Lost Password', 'template' => 'page-templates/page-lostpassword.php'],
            ['title' => 'Password Reset', 'template' => 'page-templates/page-password-reset.php'],
            ['title' => 'Terms & Conditions'],
            ['title' => 'Profile', 'template' => 'page-templates/page-profile.php'],
            ['title' => 'Dashboard', 'template' => 'page-templates/page-dashboard.php'],
            ['title' => 'Members', 'template' => 'page-templates/page-members.php'],
            ['title' => 'Employees', 'template' => 'page-templates/page-employees.php'],
            ['title' => 'Details', 'template' => 'page-templates/page-details.php'],
            ['title' => 'Guests', 'template' => 'page-templates/page-guests.php'],
            ['title' => 'Guest Details', 'template' => 'page-templates/page-guest-details.php'],
            ['title' => 'Settings', 'template' => 'page-templates/page-settings.php']
        ];

        foreach ($pages as $page) {
            $slug = sanitize_title($page['title']);
            if (!self::page_exists($slug)) {
                self::create_page($page['title'], $slug, $page['template'] ?? '');
            }
        }
    }

    private static function page_exists(string $slug): bool
    {
        return (bool) get_page_by_path($slug);
    }

    private static function create_page(string $title, string $slug, string $template = ''): ?int
    {
        $page_id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => ''
        ]);

        if (!is_wp_error($page_id) && $template) {
            update_post_meta($page_id, '_wp_page_template', $template);
        }

        return is_wp_error($page_id) ? null : $page_id;
    }

    private static function create_database_tables(): void
    {
        self::create_guests_table();
        self::create_reciprocating_members_table();
        self::create_reciprocating_clubs_table();
        self::create_guest_visits_table();
    }

    private static function create_guests_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            id_number VARCHAR(100) NOT NULL,
            status ENUM('approved','unapproved','suspended','banned') DEFAULT 'approved',
            guest_status ENUM('active','suspended','banned') DEFAULT 'active',
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_id_number (id_number),
            KEY email (email),
            KEY phone_number (phone_number),
            KEY status (status),
            KEY guest_status (guest_status)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_guest_visits_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id BIGINT(20) UNSIGNED NOT NULL,
            host_member_id BIGINT(20) UNSIGNED DEFAULT NULL,
            courtesy VARCHAR(255) DEFAULT NULL,
            visit_date DATE NOT NULL,
            sign_in_time DATETIME DEFAULT NULL,
            sign_out_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY guest_id (guest_id),
            KEY host_member_id (host_member_id),
            KEY visit_date (visit_date),
            KEY sign_in_time (sign_in_time),
            KEY sign_out_time (sign_out_time),
            UNIQUE KEY unique_guest_visit_date (guest_id, host_member_id, visit_date),
            CONSTRAINT fk_guest FOREIGN KEY (guest_id) REFERENCES $guests_table (id) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_reciprocating_members_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            id_number VARCHAR(100) NOT NULL,
            member_status ENUM('active','suspended','banned') DEFAULT 'active',
            reciprocating_member_number VARCHAR(100) NOT NULL,
            reciprocating_club_id BIGINT(20) UNSIGNED NOT NULL,
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            visit_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY reciprocating_member_number (reciprocating_member_number),
            KEY id_number (id_number),
            KEY reciprocating_club_id (reciprocating_club_id),
            KEY visit_date (visit_date),
            KEY email (email),
            KEY phone_number (phone_number)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }


    private static function create_reciprocating_clubs_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            club_name VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY club_name_unique (club_name)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function activate_cron_jobs(): void
    {
        if (!wp_next_scheduled('auto_update_visit_status_at_midnight')) {
            wp_schedule_event(strtotime('midnight'), 'daily', 'auto_update_visit_status_at_midnight');
        }
        if (!wp_next_scheduled('auto_sign_out_guests_at_midnight')) {
            wp_schedule_event(strtotime('midnight'), 'daily', 'auto_sign_out_guests_at_midnight');
        }
        if (!wp_next_scheduled('reset_monthly_guest_limits')) {
            wp_schedule_event(strtotime('first day of next month 00:00:00'), 'monthly', 'reset_monthly_guest_limits');
        }
        if (!wp_next_scheduled('reset_yearly_guest_limits')) {
            wp_schedule_event(strtotime('January 1 next year 00:00:00'), 'yearly', 'reset_yearly_guest_limits');
        }
    }

    private static function deactivate_cron_jobs(): void
    {
        wp_clear_scheduled_hook('auto_update_visit_status_at_midnight');
        wp_clear_scheduled_hook('auto_sign_out_guests_at_midnight');
        wp_clear_scheduled_hook('reset_monthly_guest_limits');
        wp_clear_scheduled_hook('reset_yearly_guest_limits');
    }

    private static function remove_plugin_data(): void
    {
        self::drop_database_tables();
        delete_option('vms_remove_all_data');
        self::remove_created_pages();
    }

    private static function drop_database_tables(): void
    {
        global $wpdb;
        $tables = [
            VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE),
            VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE),
            VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE),
            VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE)
        ];
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }

    private static function remove_created_pages(): void
    {
        $pages = [
            'login', 'register', 'lost-password', 'password-reset',
            'terms-conditions', 'profile', 'dashboard', 'employees',
            'details', 'members', 'member-details',
            'guests', 'guest-details', 'settings'
        ];

        foreach ($pages as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_delete_post($page->ID, true);
            }
        }
    }
}