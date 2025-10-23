<?php
/**
 * Rewrite Manager - Handles custom rewrite rules
 * 
 * This class manages custom URL rewrite rules for the VMS plugin.
 * Currently handles SMS callback endpoints for receiving delivery
 * status updates from SMS providers.
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

class VMS_Rewrite_Manager extends Base
{
    /**
     * Add custom rewrite rules
     * 
     * Registers custom URL patterns that WordPress should recognize.
     * Currently adds:
     * - vms-sms-callback: Endpoint for SMS delivery status webhooks
     * 
     * Rules are added with 'top' priority to take precedence over
     * default WordPress rules.
     *
     * @since 1.0.0
     * @return void
     */
    public static function add_rewrite_rules(): void
    {
        // Add SMS callback endpoint
        // Maps yourdomain.com/vms-sms-callback/ to query var
        add_rewrite_rule(
            '^vms-sms-callback/?$',        // URL pattern to match
            'index.php?vms_sms_callback=1', // Internal WordPress query
            'top'                           // Priority (top = highest)
        );
    }

    /**
     * Add custom query variables
     * 
     * Registers custom query variables that WordPress should recognize.
     * These variables are available in the global $wp_query object.
     * 
     * @since 1.0.0
     * @param array $vars Existing query variables
     * @return array Modified query variables array
     */
    public static function add_query_vars(array $vars): array
    {
        // Add our custom query var to the array
        $vars[] = 'vms_sms_callback';
        return $vars;
    }
}