<?php
/**
 * Configuration class for VMS plugin
 * 
 * This class provides centralized configuration for the entire plugin.
 * Contains constants for table names, option keys, default values,
 * and helper methods for consistent data access.
 * 
 * All plugin components should use this class for configuration
 * to ensure consistency and easy maintenance.
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

class VMS_Config
{
    /**
     * Database table name constants
     * 
     * These constants define the names of custom database tables.
     * Table names do NOT include the WordPress prefix.
     * Use get_table_name() method to get the full table name with prefix.
     */
    
    // Guests tables
    public const GUESTS_TABLE = 'vms_guests';
    public const GUEST_VISITS_TABLE = 'vms_guest_visits';

    // Accomodation Guests tables
    public const A_GUESTS_TABLE = 'vms_a_guests';
    public const A_GUEST_VISITS_TABLE = 'vms_a_guest_visits';

    // Suppliers tables
    public const SUPPLIERS_TABLE = 'vms_suppliers';
    public const SUPPLIER_VISITS_TABLE = 'vms_supplier_visits';
    
    // Reciprocating members tables
    public const RECIP_MEMBERS_TABLE = 'vms_reciprocating_members';
    public const RECIP_CLUBS_TABLE = 'vms_reciprocating_clubs';
    public const RECIP_MEMBERS_VISITS_TABLE = 'vms_recip_members_visits';
    
    // Utility tables
    public const SMS_LOGS_TABLE = 'vms_sms_logs';

    /**
     * Plugin version constant
     * 
     * Used for database migrations and compatibility checks.
     * Update this when making breaking changes.
     */
    public const VERSION = '1.0.0';

    /**
     * Plugin text domain for translations
     * 
     * Used for internationalization (i18n) and localization.
     */
    public const TEXT_DOMAIN = 'vms-plugin';

    /**
     * Default plugin settings
     * 
     * These are the default values for plugin options.
     * Used when options are not yet set in the database.
     */
    public const DEFAULTS = [
        'sms_provider' => '',
        'sms_api_key' => '',
        'sms_sender_id' => '',
        'enable_email_notifications' => true,
        'enable_sms_notifications' => true,
        'max_guest_visits_per_month' => 4,
        'max_guest_visits_per_year' => 48,
        'auto_signout_time' => '23:59:59',
        'require_host_approval' => true,
    ];

    /**
     * Status values for guests and members
     * 
     * Valid status values used throughout the plugin.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BANNED = 'banned';

    /**
     * Visit status values
     * 
     * Valid status values for visits.
     */
    public const VISIT_STATUS_APPROVED = 'approved';
    public const VISIT_STATUS_UNAPPROVED = 'unapproved';
    public const VISIT_STATUS_CANCELLED = 'cancelled';
    public const VISIT_STATUS_SUSPENDED = 'suspended';
    public const VISIT_STATUS_BANNED = 'banned';

    /**
     * SMS status values
     * 
     * Valid status values for SMS logs.
     */
    public const SMS_STATUS_SENT = 'sent';
    public const SMS_STATUS_FAILED = 'failed';
    public const SMS_STATUS_QUEUED = 'queued';
    public const SMS_STATUS_DELIVERED = 'delivered';
    public const SMS_STATUS_EXPIRED = 'expired';
    public const SMS_STATUS_UNDELIVERED = 'undelivered';

    /**
     * User capability constants
     * 
     * Custom capabilities for different user roles.
     */
    public const CAP_MANAGE_GUESTS = 'vms_manage_guests';
    public const CAP_MANAGE_MEMBERS = 'vms_manage_members';
    public const CAP_MANAGE_SETTINGS = 'vms_manage_settings';
    public const CAP_VIEW_REPORTS = 'vms_view_reports';

    /**
     * Get table name with WordPress prefix
     * 
     * Returns the full table name including the WordPress database prefix.
     * Always use this method instead of accessing table names directly.
     *
     * @since 1.0.0
     * @param string $table_name Table name constant (without prefix)
     * @return string Full table name with WordPress prefix
     */
    public static function get_table_name(string $table_name): string
    {
        global $wpdb;
        return $wpdb->prefix . $table_name;
    }

    /**
     * Get plugin option with default fallback
     * 
     * Retrieves a plugin option from the database. If the option does not
     * exist, returns the default value from DEFAULTS array or provided default.
     *
     * @since 1.0.0
     * @param string $option_name Name of the option (without vms_ prefix)
     * @param mixed $default Optional default value if not in DEFAULTS array
     * @return mixed Option value or default
     */
    public static function get_option(string $option_name, $default = null)
    {
        $full_option_name = 'vms_' . $option_name;
        $value = get_option($full_option_name, null);
        
        if ($value === null) {
            $value = self::DEFAULTS[$option_name] ?? $default;
        }
        
        return $value;
    }

    /**
     * Update plugin option
     * 
     * Saves a plugin option to the database. Automatically adds vms_ prefix.
     *
     * @since 1.0.0
     * @param string $option_name Name of the option (without vms_ prefix)
     * @param mixed $value Value to save
     * @return bool True if successful, false otherwise
     */
    public static function update_option(string $option_name, $value): bool
    {
        $full_option_name = 'vms_' . $option_name;
        return update_option($full_option_name, $value);
    }

    /**
     * Delete plugin option
     * 
     * Removes a plugin option from the database.
     *
     * @since 1.0.0
     * @param string $option_name Name of the option (without vms_ prefix)
     * @return bool True if successful, false otherwise
     */
    public static function delete_option(string $option_name): bool
    {
        $full_option_name = 'vms_' . $option_name;
        return delete_option($full_option_name);
    }

    /**
     * Get all valid status values
     * 
     * Returns an array of all valid status values for guests and members.
     *
     * @since 1.0.0
     * @return array Array of valid status values
     */
    public static function get_valid_statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_SUSPENDED,
            self::STATUS_BANNED
        ];
    }

    /**
     * Get all valid visit status values
     * 
     * Returns an array of all valid visit status values.
     *
     * @since 1.0.0
     * @return array Array of valid visit status values
     */
    public static function get_valid_visit_statuses(): array
    {
        return [
            self::VISIT_STATUS_APPROVED,
            self::VISIT_STATUS_UNAPPROVED,
            self::VISIT_STATUS_CANCELLED,
            self::VISIT_STATUS_SUSPENDED,
            self::VISIT_STATUS_BANNED
        ];
    }

    /**
     * Get all valid SMS status values
     * 
     * Returns an array of all valid SMS status values.
     *
     * @since 1.0.0
     * @return array Array of valid SMS status values
     */
    public static function get_valid_sms_statuses(): array
    {
        return [
            self::SMS_STATUS_SENT,
            self::SMS_STATUS_FAILED,
            self::SMS_STATUS_QUEUED,
            self::SMS_STATUS_DELIVERED,
            self::SMS_STATUS_EXPIRED,
            self::SMS_STATUS_UNDELIVERED
        ];
    }

    /**
     * Check if status is valid
     * 
     * Validates a status value against allowed values.
     *
     * @since 1.0.0
     * @param string $status Status value to check
     * @param string $type Type of status (status, visit, sms)
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_status(string $status, string $type = 'status'): bool
    {
        $valid_statuses = match($type) {
            'visit' => self::get_valid_visit_statuses(),
            'sms' => self::get_valid_sms_statuses(),
            default => self::get_valid_statuses()
        };
        
        return in_array($status, $valid_statuses, true);
    }
}