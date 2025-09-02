<?php
/**
 * Configuration class for VMS plugin
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Config
{
    public const GUESTS_TABLE = 'vms_guests';
    public const GUEST_VISITS_TABLE = 'vms_guest_visits';
    public const RECIP_MEMBERS_TABLE = 'vms_reciprocating_members';
    public const RECIP_CLUBS_TABLE = 'vms_reciprocating_clubs';
    public const RECIP_MEMBERS_VISITS_TABLE = 'vms_recip_members_visits';
    public const SMS_LOGS_TABLE = 'vms_sms_logs';


    /**
     * Get table name with WordPress prefix
     *
     * @param string $table_name
     * @return string
     */
    public static function get_table_name(string $table_name): string
    {
        global $wpdb;
        return $wpdb->prefix . $table_name;
    }
}