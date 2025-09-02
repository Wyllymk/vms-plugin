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

        add_rewrite_rule(
            '^vms-sms-callback/?$',
            'index.php?vms_sms_callback=1',
            'top'
        );

        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        self::deactivate_cron_jobs();
        flush_rewrite_rules();
    }

    public static function uninstall(): void
    {
        self::remove_plugin_data();
        flush_rewrite_rules();
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
            ['title' => 'Employee Details', 'template' => 'page-templates/page-employee-details.php'],
            ['title' => 'Details', 'template' => 'page-templates/page-details.php'],
            ['title' => 'Guests', 'template' => 'page-templates/page-guests.php'],
            ['title' => 'Guest Details', 'template' => 'page-templates/page-guest-details.php'],
            ['title' => 'Reciprocating Members', 'template' => 'page-templates/page-reciprocating-members.php'],
            ['title' => 'Reciprocating Member Details', 'template' => 'page-templates/page-reciprocating-member-details.php'],
            ['title' => 'Clubs', 'template' => 'page-templates/page-clubs.php'],
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
        self::create_guest_visits_table();
        self::create_reciprocating_members_table();
        self::create_reciprocating_members_visits_table();
        self::create_reciprocating_clubs_table();        
        self::create_sms_logs_table();
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
            guest_status ENUM('active','suspended','banned') DEFAULT 'active',
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_id_number (id_number),
            KEY email (email),
            KEY phone_number (phone_number),           
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

        // Step 1: Create table without foreign key
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id BIGINT(20) UNSIGNED NOT NULL,
            host_member_id BIGINT(20) UNSIGNED DEFAULT NULL,
            courtesy VARCHAR(255) DEFAULT NULL,
            visit_date DATE NOT NULL,
            status ENUM('approved','unapproved','cancelled','suspended','banned') NOT NULL DEFAULT 'approved',
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
            KEY status (status),
            UNIQUE KEY unique_guest_visit_date (guest_id, host_member_id, visit_date)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Step 2: Add foreign key separately (dbDelta doesn’t handle this)
        $foreign_key_name = 'fk_guest';

        // Check if constraint already exists (avoid duplicates on re-activation)
        $constraint_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            $table_name, $foreign_key_name
        ));

        if (!$constraint_exists) {
            $wpdb->query("
                ALTER TABLE $table_name
                ADD CONSTRAINT $foreign_key_name
                FOREIGN KEY (guest_id) REFERENCES $guests_table(id)
                ON DELETE CASCADE
            ");
        }
    }

    private static function create_reciprocating_members_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY reciprocating_member_number (reciprocating_member_number),
            KEY id_number (id_number),
            KEY reciprocating_club_id (reciprocating_club_id),
            KEY email (email),
            KEY phone_number (phone_number)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add foreign key separately
        $foreign_key_name = 'fk_reciprocating_member_club';
        $constraint_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            $table_name, $foreign_key_name
        ));

        if (!$constraint_exists) {
            $wpdb->query("
                ALTER TABLE $table_name
                ADD CONSTRAINT $foreign_key_name
                FOREIGN KEY (reciprocating_club_id) REFERENCES $clubs_table(id)
                ON DELETE CASCADE
            ");
        }
    }

    private static function create_reciprocating_members_visits_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT(20) UNSIGNED NOT NULL,
            courtesy VARCHAR(255) DEFAULT NULL,
            visit_date DATE NOT NULL,
            status ENUM('approved','unapproved','cancelled','suspended','banned') NOT NULL DEFAULT 'approved',
            sign_in_time DATETIME DEFAULT NULL,
            sign_out_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY member_id (member_id),
            KEY visit_date (visit_date),
            KEY sign_in_time (sign_in_time),
            KEY sign_out_time (sign_out_time),
            KEY status (status),
            UNIQUE KEY unique_member_visit_date (member_id, visit_date)
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add foreign key separately
        $foreign_key_name = 'fk_recip_member_visit';
        $constraint_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND TABLE_SCHEMA = DATABASE()",
            $table_name, $foreign_key_name
        ));

        if (!$constraint_exists) {
            $wpdb->query("
                ALTER TABLE $table_name
                ADD CONSTRAINT $foreign_key_name
                FOREIGN KEY (member_id) REFERENCES $members_table(id)
                ON DELETE CASCADE
            ");
        }
    }

    /**
     * Create reciprocating clubs table
     */
    private static function create_reciprocating_clubs_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            club_name VARCHAR(255) NOT NULL,
            club_email VARCHAR(255) DEFAULT NULL,
            club_phone VARCHAR(20) DEFAULT NULL,
            club_website VARCHAR(255) DEFAULT NULL,
            status ENUM('active','suspended','banned') DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY club_name (club_name),
            KEY club_email (club_email),
            KEY status (status),
        ) ENGINE=InnoDB $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
    * Create SMS logs table
    */
    private static function create_sms_logs_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            recipient_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            status ENUM('sent','failed','queued','delivered','expired','undelivered') NOT NULL DEFAULT 'queued',
            cost DECIMAL(10,2) DEFAULT NULL,
            response_data TEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY recipient_number (recipient_number),
            KEY message_id (message_id),
            KEY status (status),
            KEY created_at (created_at)
        ) ENGINE=InnoDB $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function handle_sms_delivery_callback(): void
    {
        // Log all incoming data for debugging
        error_log('SMS Callback received: ' . json_encode($_POST));
        
        $status_secret = get_option('vms_status_secret', '');
        
        // Verify secret if configured
        if (!empty($status_secret)) {
            $received_secret = $_POST['status_secret'] ?? '';
            if ($received_secret !== $status_secret) {
                http_response_code(403);
                echo 'Unauthorized';
                error_log('Unauthorized callback attempt');
                exit;
            }
        }

        $message_id = $_POST['id'] ?? '';
        $status     = strtolower($_POST['status'] ?? ''); // normalize to lowercase
        $reason     = $_POST['reason'] ?? '';
        $time       = $_POST['time'] ?? '';
        
        if (!empty($message_id) && !empty($status)) {
            // 🚫 Do not process if the incoming status is a final state
            if (in_array($status, ['delivrd', 'failed'], true)) {
                error_log("Skipping SMS {$message_id}, status '{$status}' is final.");
                http_response_code(200);
                echo 'OK';
                return;
            }

            global $wpdb;
            $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);

            $update_data = [
                'status'     => $status,
                'updated_at' => current_time('mysql')
            ];

            if (!empty($reason)) {
                $existing_data = $wpdb->get_var($wpdb->prepare(
                    "SELECT response_data FROM {$table_name} WHERE message_id = %s",
                    $message_id
                ));
                
                $response_data = $existing_data ? json_decode($existing_data, true) : [];
                $response_data['delivery_reason'] = $reason;
                $response_data['delivery_time']   = $time;
                
                $update_data['response_data'] = json_encode($response_data);
            }

            $updated = $wpdb->update(
                $table_name,
                $update_data,
                ['message_id' => $message_id],
                array_fill(0, count($update_data), '%s'),
                ['%s']
            );
            
            if ($updated) {
                error_log("SMS status updated for message {$message_id}: {$status}");
            }
        }
        
        http_response_code(200);
        echo 'OK';
    }
        
    /**
     * Add SMS logs cleanup cron job
     */
    private static function activate_cron_jobs(): void
    {
        // Local midnight according to WP settings
        $midnight_local = strtotime('midnight', current_time('timestamp'));

        // Convert to UTC for cron storage
        $midnight_utc = $midnight_local - (get_option('gmt_offset') * HOUR_IN_SECONDS);

        // Add SMS cleanup job - runs daily at 2 AM
        if (!wp_next_scheduled('cleanup_old_sms_logs')) {
            wp_schedule_event(strtotime('tomorrow 2:00 AM'), 'daily', 'cleanup_old_sms_logs');
        }
        
        // SMS delivery status check - runs every 30 minutes
        if (!wp_next_scheduled('check_sms_delivery_status')) {
            wp_schedule_event(time(), 'hourly', 'check_sms_delivery_status');
        }

        if (!wp_next_scheduled('sms_balance_cron')) {
            wp_schedule_event(time(), 'hourly', 'sms_balance_cron');
        }
        if (!wp_next_scheduled('auto_update_visit_status_at_midnight')) {
            wp_schedule_event($midnight_local, 'daily', 'auto_update_visit_status_at_midnight');
        }
        if (!wp_next_scheduled('auto_sign_out_guests_at_midnight')) {
            wp_schedule_event($midnight_utc, 'daily', 'auto_sign_out_guests_at_midnight');
        }

        // First day of next month at 00:00 local
        $first_next_month_local = strtotime('first day of next month 00:00:00', current_time('timestamp'));
        $first_next_month_utc   = $first_next_month_local - (get_option('gmt_offset') * HOUR_IN_SECONDS);
        if (!wp_next_scheduled('reset_monthly_guest_limits')) {
            wp_schedule_event($first_next_month_utc, 'monthly', 'reset_monthly_guest_limits');
        }

        // Jan 1 next year at 00:00 local
        $jan_first_local = strtotime('January 1 next year 00:00:00', current_time('timestamp'));
        $jan_first_utc   = $jan_first_local - (get_option('gmt_offset') * HOUR_IN_SECONDS);
        if (!wp_next_scheduled('reset_yearly_guest_limits')) {
            wp_schedule_event($jan_first_utc, 'yearly', 'reset_yearly_guest_limits');
        }
    }

    private static function deactivate_cron_jobs(): void
    {
        wp_clear_scheduled_hook('sms_balance_cron');
        wp_clear_scheduled_hook('auto_update_visit_status_at_midnight');
        wp_clear_scheduled_hook('auto_sign_out_guests_at_midnight');
        wp_clear_scheduled_hook('reset_monthly_guest_limits');
        wp_clear_scheduled_hook('reset_yearly_guest_limits');
    }

    private static function remove_plugin_data(): void
    {
        self::drop_database_tables();
        self::remove_created_pages();
    }

    private static function drop_database_tables(): void
    {
        global $wpdb;
        $tables = [  
            VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE),
            VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE), 
            VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE),        
            VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE),          
            VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE), 
            VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE),
            
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
            'details', 'members', 'employee-details',
            'guests', 'guest-details',  'clubs', 'reciprocating-members', 'reciprocating-member-details',
            'settings'
        ];

        foreach ($pages as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_delete_post($page->ID, true);
            }
        }
    }
}