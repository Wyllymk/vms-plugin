<?php
/**
 * Database Manager - Handles all database table creation, updates, and deletion
 *
 * This class defines, creates, and manages all custom database tables
 * required by the VMS plugin. It ensures schema integrity, supports
 * version-based upgrades, and provides utility methods for maintaining
 * relationships and cleanup.
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit;
}

class VMS_Database_Manager
{
    /**
     * Current database schema version.
     *
     * Increment this version whenever the schema of any table changes.
     *
     * @since 1.0.0
     * @var string
     */
    private const DB_VERSION = '1.0.0';

    /**
     * Check and upgrade the database schema if needed.
     *
     * Compares the installed database version with the defined DB_VERSION.
     * If versions differ, all plugin tables are recreated or updated,
     * and the version is stored in the WordPress options table.
     *
     * @since 1.0.0
     * @return void
     */
    public static function maybe_upgrade(): void
    {
        $installed_version = get_option('vms_db_version');

        if ($installed_version !== self::DB_VERSION) {
            self::create_all_tables();
            update_option('vms_db_version', self::DB_VERSION);
            error_log('[VMS] Database schema updated to version ' . self::DB_VERSION);
        }
    }

    /**
     * Create all plugin tables safely within a database transaction.
     *
     * Runs all table creation methods in a controlled transaction block.
     * If any creation fails, all changes are rolled back to maintain data integrity.
     *
     * @since 1.0.0
     * @return void
     */
    public static function create_all_tables(): void
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            self::create_guests_table();
            self::create_guest_visits_table();
            self::create_a_guests_table();
            self::create_a_guest_visits_table();
            self::create_suppliers_table();
            self::create_supplier_visits_table();
            self::create_reciprocating_clubs_table();
            self::create_reciprocating_members_table();
            self::create_reciprocating_members_visits_table();
            self::create_sms_logs_table();

            $wpdb->query('COMMIT');
            error_log('[VMS] ✅ All tables created successfully');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[VMS ERROR] ❌ Table creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Drop all plugin-related database tables.
     *
     * This function is used during uninstall or full plugin reset.
     * It safely removes all custom tables and deletes the stored database version.
     *
     * @since 1.0.0
     * @return void
     */
    public static function drop_all_tables(): void
    {
        global $wpdb;

        $tables = [
            VMS_Config::RECIP_MEMBERS_VISITS_TABLE,
            VMS_Config::RECIP_MEMBERS_TABLE,
            VMS_Config::RECIP_CLUBS_TABLE,
            VMS_Config::SUPPLIER_VISITS_TABLE,
            VMS_Config::SUPPLIERS_TABLE,
            VMS_Config::A_GUEST_VISITS_TABLE,
            VMS_Config::A_GUESTS_TABLE,
            VMS_Config::GUEST_VISITS_TABLE,
            VMS_Config::GUESTS_TABLE,
            VMS_Config::SMS_LOGS_TABLE,
        ];

        foreach ($tables as $slug) {
            $table_name = VMS_Config::get_table_name($slug);
            $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
            error_log("[VMS] Dropped table: $table_name");
        }

        delete_option('vms_db_version');
        error_log('[VMS] Database cleanup complete.');
    }

    /* ===============================================================
     * ============= TABLE CREATION METHODS ===========================
     * =============================================================== */

    /**
     * Create the Guests table.
     *
     * Stores information about all guest users including names, contacts,
     * preferences, and status.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_guests_table(): void
    {
        $sql = "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) NOT NULL,
            id_number VARCHAR(100) DEFAULT NULL,
            guest_status ENUM('active','suspended','banned') DEFAULT 'active',
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_phone_number (phone_number),
            UNIQUE KEY unique_id_number (id_number),
            KEY email (email),
            KEY guest_status (guest_status)
        ";

        self::create_table(VMS_Config::GUESTS_TABLE, $sql);
    }

    /**
     * Create the Guest Visits table.
     *
     * Records each guest’s visits to the facility, including the date, host, and status.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_guest_visits_table(): void
    {
        $sql = "
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
            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY visit_date (visit_date),
            UNIQUE KEY unique_guest_visit_date (guest_id, host_member_id, visit_date)
        ";

        $table = self::create_table(VMS_Config::GUEST_VISITS_TABLE, $sql);

        self::add_foreign_key_if_not_exists(
            $table,
            'fk_guest_visit_guest',
            'guest_id',
            VMS_Config::GUESTS_TABLE,
            'id'
        );
    }

    /**
     * Create the Accommodation Guests table.
     *
     * Contains guests who stay overnight or longer at the facility.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_a_guests_table(): void
    {
        self::create_table(VMS_Config::A_GUESTS_TABLE, "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) NOT NULL,
            id_number VARCHAR(100) DEFAULT NULL,
            guest_status ENUM('active','suspended','banned') DEFAULT 'active',
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_phone_number (phone_number),
            UNIQUE KEY unique_id_number (id_number),
            KEY email (email),
            KEY guest_status (guest_status)
        ");
    }

    /**
     * Create the Accommodation Guest Visits table.
     *
     * Tracks visits of accommodation guests and enforces unique daily entries.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_a_guest_visits_table(): void
    {
        $sql = "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id BIGINT(20) UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            status ENUM('approved','unapproved','cancelled','suspended','banned') NOT NULL DEFAULT 'approved',
            sign_in_time DATETIME DEFAULT NULL,
            sign_out_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY visit_date (visit_date),
            UNIQUE KEY unique_guest_visit_date (guest_id, visit_date)
        ";

        $table = self::create_table(VMS_Config::A_GUEST_VISITS_TABLE, $sql);
        self::add_foreign_key_if_not_exists(
            $table,
            'fk_a_guest_visit_guest',
            'guest_id',
            VMS_Config::A_GUESTS_TABLE,
            'id'
        );
    }

    /**
     * Create the Suppliers table.
     *
     * Manages supplier contact details, identification, and communication preferences.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_suppliers_table(): void
    {
        self::create_table(VMS_Config::SUPPLIERS_TABLE, "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) NOT NULL,
            id_number VARCHAR(100) DEFAULT NULL,
            guest_status ENUM('active','suspended','banned') DEFAULT 'active',
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_phone_number (phone_number),
            UNIQUE KEY unique_id_number (id_number),
            KEY email (email),
            KEY guest_status (guest_status)
        ");
    }

    /**
     * Create the Supplier Visits table.
     *
     * Logs visits of suppliers and links them to supplier records.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_supplier_visits_table(): void
    {
        $sql = "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id BIGINT(20) UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            status ENUM('approved','unapproved','cancelled','suspended','banned') NOT NULL DEFAULT 'approved',
            sign_in_time DATETIME DEFAULT NULL,
            sign_out_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY visit_date (visit_date),
            UNIQUE KEY unique_guest_visit_date (guest_id, visit_date)
        ";

        $table = self::create_table(VMS_Config::SUPPLIER_VISITS_TABLE, $sql);
        self::add_foreign_key_if_not_exists(
            $table,
            'fk_supplier_visit_supplier',
            'guest_id',
            VMS_Config::SUPPLIERS_TABLE,
            'id'
        );
    }

    /**
     * Create the Reciprocating Clubs table.
     *
     * Stores data about clubs with reciprocal membership arrangements.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_reciprocating_clubs_table(): void
    {
        self::create_table(VMS_Config::RECIP_CLUBS_TABLE, "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            club_name VARCHAR(255) NOT NULL,
            club_email VARCHAR(255) DEFAULT NULL,
            club_phone VARCHAR(20) DEFAULT NULL,
            club_website VARCHAR(255) DEFAULT NULL,
            status ENUM('active','suspended','banned') DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY club_name (club_name),
            KEY status (status)
        ");
    }

    /**
     * Create the Reciprocating Members table.
     *
     * Contains members from reciprocating clubs and their personal data.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_reciprocating_members_table(): void
    {
        $sql = "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            id_number VARCHAR(100) NOT NULL,
            member_status ENUM('active','suspended','banned') DEFAULT 'active',
            reciprocating_member_number VARCHAR(100) DEFAULT NULL,
            reciprocating_club_id BIGINT(20) UNSIGNED DEFAULT NULL,
            receive_emails ENUM('yes','no') DEFAULT 'no',
            receive_messages ENUM('yes','no') DEFAULT 'no',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY reciprocating_member_number (reciprocating_member_number),
            KEY id_number (id_number),
            KEY reciprocating_club_id (reciprocating_club_id)
        ";
        $table = self::create_table(VMS_Config::RECIP_MEMBERS_TABLE, $sql);
        self::add_foreign_key_if_not_exists(
            $table,
            'fk_recip_member_club',
            'reciprocating_club_id',
            VMS_Config::RECIP_CLUBS_TABLE,
            'id'
        );
    }

    /**
     * Create the Reciprocating Member Visits table.
     *
     * Records visit logs for members from reciprocating clubs.
     *
     * @since 1.0.0
     * @return void
     */    
    private static function create_reciprocating_members_visits_table(): void
    {
        $sql = "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT(20) UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            visit_purpose ENUM('golf_tournament','casual_visit') DEFAULT NULL,
            status ENUM('approved','unapproved','cancelled','suspended','banned') NOT NULL DEFAULT 'approved',
            sign_in_time DATETIME DEFAULT NULL,
            sign_out_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY visit_date (visit_date),
            UNIQUE KEY unique_member_visit_date (member_id, visit_date)
        ";

        $table = self::create_table(VMS_Config::RECIP_MEMBERS_VISITS_TABLE, $sql);
        self::add_foreign_key_if_not_exists(
            $table,
            'fk_recip_member_visit',
            'member_id',
            VMS_Config::RECIP_MEMBERS_TABLE,
            'id'
        );
    }

    /**
     * Create the SMS Logs table.
     *
     * Stores all SMS transactions including delivery status, cost, and error data.
     *
     * @since 1.0.0
     * @return void
     */
    private static function create_sms_logs_table(): void
    {
        self::create_table(VMS_Config::SMS_LOGS_TABLE, "
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            recipient_number VARCHAR(20) NOT NULL,
            recipient_role VARCHAR(50) DEFAULT NULL,
            message TEXT NOT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'unknown',
            cost DECIMAL(10,2) DEFAULT NULL,
            response_data TEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY recipient_number (recipient_number),
            KEY message_id (message_id),
            KEY status (status)
        ");
    }

    /* ===============================================================
     * ============= INTERNAL UTILITIES ===============================
     * =============================================================== */

    /**
     * Generic helper for creating or updating a database table.
     *
     * Uses dbDelta() for version-safe schema updates.
     *
     * @param string $slug    Table slug from VMS_Config.
     * @param string $columns Column definitions for CREATE TABLE.
     *
     * @since 1.0.0
     * @return string Fully qualified table name.
     */
    private static function create_table(string $slug, string $columns): string
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = esc_sql(VMS_Config::get_table_name($slug));

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table_name ($columns) ENGINE=InnoDB $charset_collate;");

        error_log("[VMS] Created or verified table: $table_name");
        return $table_name;
    }

    /**
     * Add a foreign key constraint to a table if it doesn't already exist.
     *
     * Ensures referential integrity between related tables.
     *
     * @param string $table_name       The table to modify.
     * @param string $constraint_name  Name of the foreign key constraint.
     * @param string $column_name      The column in the table to link.
     * @param string $reference_slug   Table slug of the referenced table.
     * @param string $reference_column Column in the referenced table.
     *
     * @since 1.0.0
     * @return void
     */
    private static function add_foreign_key_if_not_exists(
        string $table_name,
        string $constraint_name,
        string $column_name,
        string $reference_slug,
        string $reference_column
    ): void {
        global $wpdb;

        $reference_table = VMS_Config::get_table_name($reference_slug);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_NAME = %s
             AND CONSTRAINT_NAME = %s
             AND TABLE_SCHEMA = DATABASE()",
            $table_name,
            $constraint_name
        ));

        if (!$exists) {
            $wpdb->query("
                ALTER TABLE `$table_name`
                ADD CONSTRAINT `$constraint_name`
                FOREIGN KEY (`$column_name`) REFERENCES `$reference_table`(`$reference_column`)
                ON DELETE CASCADE
            ");
            error_log("[VMS] Added foreign key '$constraint_name' on $table_name.$column_name → $reference_table.$reference_column");
        }
    }
}