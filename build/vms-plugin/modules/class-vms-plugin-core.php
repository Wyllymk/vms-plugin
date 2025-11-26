<?php
/**
 * Core functionality handler for VMS plugin
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

use WP_User;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Core extends Base
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * @return self
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize core functionality
     */
    public function init(): void
    {
        self::setup_core_management_hooks();
        self::setup_cron_schedules();
    }

    /**
     * Setup guest management related hooks
     * 
     * @since 1.0.0
     */
    private static function setup_core_management_hooks(): void
    {       
        add_action('admin_init', [self::class, 'handle_status_setup']);
        // Register rewrite rule and query var for SMS callback
        add_action('init', [self::class, 'register_sms_callback_route']);
        // Register query var
        add_filter('query_vars', [self::class, 'register_sms_query_var']);
        // Handle SMS callback via template_redirect
        add_action('template_redirect', [self::class, 'handle_sms_callback']);
    }

    /**
     * Setup cron schedules for recurring tasks.
     *
     * @since 1.0.0
     */
    public static function setup_cron_schedules(): void
    {       
        add_filter('cron_schedules', [self::class, 'register_custom_intervals']);        
    }

     /**
     * Register the rewrite rule and query var for SMS callback.
     */
    public static function register_sms_callback_route(): void
    {
        // error_log('[VMS] Registering SMS callback route...');
        try {
            // Add rewrite rule
            add_rewrite_rule(
                '^vms-sms-callback/?$',
                'index.php?vms_sms_callback=1',
                'top'
            );

            // Add query var
            add_filter('query_vars', function ($vars) {
                $vars[] = 'vms_sms_callback';
                return $vars;
            });

            // error_log('[VMS] SMS callback rewrite rule and query var registered.');
        } catch (Exception $e) {
            error_log('[VMS ERROR] Failed to register SMS callback route: ' . $e->getMessage());
        }
    }

     /**
     * Register the custom query var 'vms_sms_callback'.
     */
    public static function register_sms_query_var($vars): array
    {
        // error_log('[VMS] Registering query var "vms_sms_callback"...');
        try {
            $vars[] = 'vms_sms_callback';
            // error_log('[VMS] Query var "vms_sms_callback" registered.');
        } catch (Exception $e) {
            error_log('[VMS ERROR] Failed to register query var: ' . $e->getMessage());
        }

        return $vars;
    }
    
    /**
     * Handle SMS callback when query var 'vms_sms_callback' is present.
     */
    public static function handle_sms_callback(): void
    {
        try {
            $query_var = get_query_var('vms_sms_callback');

            if ($query_var) {
                error_log('[VMS] SMS callback detected with query var: ' . sanitize_text_field($query_var));

                $instance = VMS_SMS::get_instance();
                if (method_exists($instance, 'handle_sms_delivery_callback')) {
                    $instance->handle_sms_delivery_callback();
                    error_log('[VMS] SMS delivery callback handled successfully.');
                } else {
                    error_log('[VMS ERROR] VMS_SMS::handle_sms_delivery_callback() method not found.');
                }

                exit;
            }
        } catch (Exception $e) {
            error_log('[VMS ERROR] Exception during SMS callback handling: ' . $e->getMessage());
        }
    }
    
    /**
     * Register custom recurring schedules (monthly, yearly).
     *
     * @param array $schedules Existing WP schedules.
     * @return array Modified schedules.
     */
    public static function register_custom_intervals(array $schedules): array
    {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => MONTH_IN_SECONDS, // ~30 days
                'display'  => __('Once Monthly', 'vms-plugin'),
            ];
        }

        if (!isset($schedules['yearly'])) {
            $schedules['yearly'] = [
                'interval' => YEAR_IN_SECONDS, // ~365 days
                'display'  => __('Once Yearly', 'vms-plugin'),
            ];
        }

        return $schedules;
    }

     /**
     * Setup automatic status URL in settings if not already configured
     */
    public static function handle_status_setup(): void
    {
        
        $status_url = get_option('vms_status_url', '');
        $status_secret = get_option('vms_status_secret', '');
        
        // Auto-configure callback URL if not set
        if (empty($status_url)) {
            $callback_url = home_url('/vms-sms-callback/');
            update_option('vms_status_url', $callback_url);
        }
        
        // Generate status secret if not set
        if (empty($status_secret)) {
            $secret = wp_generate_password(32, false, false);
            update_option('vms_status_secret', $secret);
        }
    }

    /**
     * Build URL for pagination links (query-string only)
     */
    public static function build_pagination_url($page) 
    {
        // Always start from the current request URI (query args only)
        $current_url = add_query_arg([], $_SERVER['REQUEST_URI']);

        // Forcefully strip any trailing /page/{num}/ if present
        $current_url = preg_replace('#/page/\d+/#', '/', $current_url);

        // Remove any existing 'paged' param
        $current_url = remove_query_arg('paged', $current_url);

        // Add back the new paged value
        return add_query_arg(['paged' => $page], $current_url);
    }
    
    /**
     * Build URL for per-page selection
     */
    public static function build_per_page_url() 
    {
        $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI']));
        // Remove any existing 'per_page' and 'paged' params
        $current_url = remove_query_arg(['per_page', 'paged'], $current_url);
        // Add the placeholder for 'per_page'; the value will be appended by JS onchange
        return add_query_arg(['per_page' => ''], $current_url);
    }

     /**
     * Build URL for sorting (if you need sorting functionality)
     */
    public static function build_sort_url($column) 
    {
        $current_sort = isset($_GET['sort_column']) ? $_GET['sort_column'] : '';
        $current_direction = isset($_GET['sort_direction']) ? $_GET['sort_direction'] : 'asc';
        
        // Toggle direction if same column, otherwise default to asc
        $new_direction = ($current_sort === $column && $current_direction === 'asc') ? 'desc' : 'asc';
        
        $current_url = remove_query_arg(['sort_column', 'sort_direction', 'paged'], $_SERVER['REQUEST_URI']);
        return add_query_arg([
            'sort_column' => $column,
            'sort_direction' => $new_direction,
            'paged' => 1 // Reset to page 1 when sorting
        ], $current_url);
    }

    /**
     * Format date for display
     */
    public static function format_date($date) 
    {
        if (!$date || $date === '0000-00-00') {
            return 'N/A';
        }
        return date('M j, Y', strtotime($date));
    }
    
    /**
     * Format time for display
     */
    public static function format_time($datetime) 
    {
        if (!$datetime || $datetime === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('g:i A', strtotime($datetime));
    }

     /**
     * Calculate duration between two times
     */
    public static function calculate_duration($sign_in_time, $sign_out_time) 
    {
        if (!$sign_in_time || !$sign_out_time || 
            $sign_in_time === '0000-00-00 00:00:00' || 
            $sign_out_time === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        
        $start = new \DateTime($sign_in_time);
        $end = new \DateTime($sign_out_time);
        $interval = $start->diff($end);
        
        if ($interval->days > 0) {
            return $interval->format('%d day(s) %h:%i');
        } else {
            return $interval->format('%h:%i');
        }
    }

    /**
     * Get visit status based on dates and times
     */
    public static function get_visit_status($visit_date, $sign_in_time, $sign_out_time, $visit_status_db = 'approved') 
    {
        // If visit is not approved, suspended, or banned, return that directly
        if (in_array($visit_status_db, ['unapproved', 'suspended', 'banned'])) {
            error_log($visit_status_db);
            return $visit_status_db;
        }

        // Only approved visits proceed to check actual timing/status
        $today = date('Y-m-d');

        if ($visit_date > $today) {
            return 'scheduled';
        } elseif (empty($sign_in_time) && $visit_date < $today) {
            return 'missed';
        } elseif ($visit_date < $today) {
            return 'completed';        
        } else { // Today
            if (!$sign_in_time || $sign_in_time === '0000-00-00 00:00:00') {
                return 'pending';
            } elseif (!$sign_out_time || $sign_out_time === '0000-00-00 00:00:00') {
                return 'active';
            } else {
                return 'completed';
            }
        }
    }

    
    /**
     * Get CSS class for status
     */
    public static function get_status_class($status) 
    {
        switch ($status) {
            case 'active':
                return 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500';
            case 'missed':
                return 'bg-error-50text-error-600 dark:bg-error-500/15 dark:text-error-500';
            case 'completed':
                return 'bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400';
            case 'scheduled':
                return 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400';
            case 'pending':
                return 'bg-blue-light-50 text-blue-light-500 dark:bg-blue-light-500/15 dark:text-blue-light-500';
            default:
                return 'bg-gray-500 text-white dark:bg-white/5 dark:text-white';
        }
    }
    
    /**
     * Get human-readable status text
     */
    public static function get_status_text($status) 
    {
        switch ($status) {
            case 'active':
                return 'Active';
            case 'missed':
                return 'Missed';
            case 'completed':
                return 'Completed';
            case 'scheduled':
                return 'Scheduled';
            case 'pending':
                return 'Pending';
            default:
                return 'Unknown';
        }
    }

}