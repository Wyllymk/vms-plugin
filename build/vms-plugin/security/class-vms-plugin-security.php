<?php
/**
 * Security functionality handler for VMS plugin
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

class VMS_Security extends Base
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
        self::setup_authentication_hooks();
        self::setup_security_hooks();
    }

     /**
     * Setup authentication related hooks
     */
    private function setup_authentication_hooks(): void
    {
        add_action( 'login_form_lostpassword', [ self::class, 'handle_custom_login_redirect' ] );
        add_action( 'login_form_rp', [ self::class, 'handle_custom_login_redirect' ] );
        add_action( 'login_form_resetpass', [ self::class, 'handle_custom_login_redirect' ] );
        add_action( 'login_form_register', [ self::class, 'handle_custom_login_redirect' ] );
        add_action( 'login_form_login', [ self::class, 'handle_custom_login_redirect' ] );

        add_filter('login_redirect', [self::class, 'custom_login_redirect'], 10, 3);
        add_filter('wp_authenticate_user', [self::class, 'validate_user_status'], 10, 1);
        add_filter('retrieve_password_message', [self::class, 'custom_password_reset_email'], 10, 4);        

        add_action('admin_init', [self::class, 'handle_status_setup']);
    }

    /**
     * Setup security related hooks
     */
    private static function setup_security_hooks(): void
    {
        add_action('admin_init', [self::class, 'restrict_admin_access']);
        add_action('after_setup_theme', [self::class, 'manage_admin_bar']);
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
     * Handle custom login page redirect
     */
    public static function handle_custom_login_redirect(): void
    {
        if ( is_user_logged_in() || wp_doing_ajax() ) {
            return;
        }

        $action = $_REQUEST['action'] ?? '';

        switch ( $action ) {
            case 'lostpassword':
                wp_redirect( site_url( '/lost-password' ) );
                exit;            

            case 'register':
                wp_redirect( site_url( '/register' ) );
                exit;

            case 'rp':
            case 'resetpass':
                $key   = $_REQUEST['key']   ?? '';
                $login = $_REQUEST['login'] ?? '';

                // Decode HTML entities like &#038; to plain &
                $key   = html_entity_decode( $key );
                $login = html_entity_decode( $login );

                if ( $key && $login ) {
                    $url = site_url( '/password-reset/?key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ) );
                    wp_redirect( $url );
                    exit;
                }
                break;

            case 'login':
            case '':
                wp_redirect( site_url( '/login' ) );
                exit;

            default:
                // Let WP handle anything else (e.g., core plugins adding actions)
                return;
        }
    }


    /**
     * Custom login redirect based on user role
     */
    public static function custom_login_redirect(string $redirect_to, string $request, WP_User $user): string
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
    public static function validate_user_status($user)
    {
        if (is_wp_error($user)) {
            return $user;
        }

        $status = get_user_meta($user->ID, 'registration_status', true);

        // ✅ If no status is set, allow login
        if (empty($status)) {
            return $user;
        }

        switch ($status) {
            case 'active':
                return $user;

            case 'pending':
                return new WP_Error(
                    'account_pending',
                    __('Your account is pending approval. Please try again later.', 'vms-plugin')
                );

            case 'suspended':
                return new WP_Error(
                    'account_suspended',
                    __('Your account has been suspended. Contact support for assistance.', 'vms-plugin')
                );

            case 'banned':
                return new WP_Error(
                    'account_banned',
                    __('Your account has been permanently banned. Please contact the administrator if you believe this is an error.', 'vms-plugin')
                );

            default:
                return new WP_Error(
                    'account_inactive',
                    __('Your account status is invalid. Please contact support.', 'vms-plugin')
                );
        }
    }

    /**
     * Custom password reset email
     */
    public static function custom_password_reset_email(string $message, string $key, string $user_login, WP_User $user_data): string
    {
        $reset_url = add_query_arg([
            'key'   => $key,
            'login' => rawurlencode($user_login),
        ], home_url('/password-reset'));

        $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $user_ip   = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : 'Unknown IP';

        return sprintf(
            __(
                "Someone has requested a password reset for the following account:%s%sSite Name: %s%sUsername: %s%s%sIf this was a mistake, ignore this email and nothing will happen.%s%sTo reset your password, visit the following address:%s%s%s%sThis password reset request originated from the IP address %s.%s",
                'vms-plugin'
            ),
            PHP_EOL, PHP_EOL,
            $site_name, PHP_EOL,
            $user_login, PHP_EOL, PHP_EOL,
            PHP_EOL, PHP_EOL,
            PHP_EOL, esc_url($reset_url), PHP_EOL, PHP_EOL,
            $user_ip, PHP_EOL
        );
    }


    /**
     * Restrict admin access for non-admins
     */
    public static function restrict_admin_access(): void
    {
        if (!current_user_can('manage_options') && !(defined('DOING_AJAX') && DOING_AJAX)) {
            wp_redirect(home_url('/dashboard'));
            exit;
        }
    }

    /**
     * Manage admin bar visibility
     */
    public static function manage_admin_bar(): void
    {
        if (!current_user_can('administrator') && !is_admin()) {
            show_admin_bar(false);
        }
    }    

}