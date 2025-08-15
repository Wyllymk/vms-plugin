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

class VMS_CoreManager
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
        $this->setup_authentication_hooks();
        $this->setup_security_hooks();
        // Add other core functionality hooks here
        // Add action hooks
        add_action('reset_monthly_guest_limits', [$this, 'reset_monthly_limits']);
        add_action('reset_yearly_guest_limits', [$this, 'reset_yearly_limits']);
    }

    /**
     * Setup authentication related hooks
     */
    private function setup_authentication_hooks(): void
    {
        add_action('login_init', [$this, 'handle_custom_login_redirect']);
        add_action('login_init', [$this, 'handle_lost_password_redirect']);
        add_filter('login_redirect', [$this, 'custom_login_redirect'], 10, 3);
        add_filter('wp_authenticate_user', [$this, 'validate_user_status'], 10, 1);
        add_filter('retrieve_password_message', [$this, 'custom_password_reset_email'], 10, 4);
    }

    /**
     * Setup security related hooks
     */
    private function setup_security_hooks(): void
    {
        add_action('admin_init', [$this, 'restrict_admin_access']);
        add_action('after_setup_theme', [$this, 'manage_admin_bar']);
    }

    /**
     * Handle custom login page redirect
     */
    public function handle_custom_login_redirect(): void
    {
        if (!is_user_logged_in() && !wp_doing_ajax()) {
            if (isset($_GET['action']) && $_GET['action'] === 'rp' && isset($_GET['key'], $_GET['login'])) {
                wp_redirect( site_url('/password-reset/?key=' . urlencode($_GET['key']) . '&login=' . urlencode($_GET['login'])) );
                exit;
            }
            wp_redirect(site_url('/login/'));
            exit;
        }
    }

    /**
     * Handle lost password redirect
     */
    public function handle_lost_password_redirect(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'lostpassword') {
            if (!is_user_logged_in()) {
                wp_redirect(site_url('/lost-password'));
                exit;
            }
        }
    }

    /**
     * Custom login redirect based on user role
     */
    public function custom_login_redirect(string $redirect_to, string $request, WP_User $user): string
    {
        if (isset($user->roles) && is_array($user->roles)) {
            return esc_url(home_url('/dashboard'));
        }
        return $redirect_to;
    }

    /**
     * Validate user status during authentication
     *
     * @param WP_User|WP_Error $user
     * @return WP_User|WP_Error
     */
    public function validate_user_status($user) {
        // If it's already an error, just return it
        if (is_wp_error($user)) {
            return $user;
        }

        if (get_user_meta($user->ID, 'registration_status', true) !== 'active') {
            return new WP_Error(
                'inactive',
                __('Your account is pending approval. Please try again later.', 'vms')
            );
        }

        return $user;
    }


    /**
     * Custom password reset email
     */
    public function custom_password_reset_email(string $message, string $key, string $user_login, WP_User $user_data): string
    {
        $reset_url = add_query_arg([
            'key' => $key,
            'login' => rawurlencode($user_login)
        ], home_url('/password-reset'));

        $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $user_ip = $_SERVER['REMOTE_ADDR'];

        return sprintf(
            __("Someone has requested a password reset for the following account:\r\n\r\nSite Name: %s\r\nUsername: %s\r\n\r\nIf this was a mistake, ignore this email and nothing will happen.\r\n\r\nTo reset your password, visit the following address:\r\n\r\n%s\r\n\r\nThis password reset request originated from the IP address %s.\r\n", 'vms'),
            $site_name,
            $user_login,
            $reset_url,
            $user_ip
        );
    }

    /**
     * Restrict admin access for non-admins
     */
    public function restrict_admin_access(): void
    {
        if ( !current_user_can('manage_options') && !(defined('DOING_AJAX') && DOING_AJAX) )
        {
            wp_redirect(home_url('/dashboard'));
            exit;
        }
    }

    /**
     * Manage admin bar visibility
     */
    public function manage_admin_bar(): void
    {
        if (!current_user_can('administrator') && !is_admin()) {
            show_admin_bar(false);
        }
    }

    public function reset_monthly_limits() {
        global $wpdb;
        $table = self::$guests_table;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                SET status = 'approved'
                WHERE status = 'suspended'
                AND (
                    YEAR(visit_date) < YEAR(CURDATE())
                    OR (YEAR(visit_date) = YEAR(CURDATE()) AND MONTH(visit_date) < MONTH(CURDATE()))
                )"
            )
        );
    }


    public function reset_yearly_limits() {
        global $wpdb;
        $table = self::$guests_table;
        $wpdb->query(
            "UPDATE {$table}
            SET status = 'approved'
            WHERE status = 'suspended'
            AND visit_date < DATE_FORMAT(CURDATE(), '%Y-01-01')"
        );
    }

}