<?php
/**
 * Core functionality handler for Cyber Wakili plugin
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
        $this->setup_file_upload_hooks();
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
     * Setup file upload related hooks
     */
    private function setup_file_upload_hooks(): void
    {
        add_filter('wp_handle_upload_prefilter', [$this, 'validate_file_upload']);
        add_filter('wp_handle_upload', [$this, 'link_upload_to_case']);
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
                wp_redirect(site_url('/lost-password/'));
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
            return esc_url(home_url('/dashboard/'));
        }
        return $redirect_to;
    }

    /**
     * Validate user status during authentication
     */
    public function validate_user_status(WP_User $user): WP_User
    {
        if (get_user_meta($user->ID, 'registration_status', true) === 'inactive') {
            remove_action('wp_authenticate_user', 'wp_authenticate_username_password', 20);
            $user = new WP_Error('inactive', __('Your account is pending approval. Please try again later.', 'cyber-wakili-plugin'));
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
        ], home_url('/password-reset/'));

        $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $user_ip = $_SERVER['REMOTE_ADDR'];

        return sprintf(
            __("Someone has requested a password reset for the following account:\r\n\r\nSite Name: %s\r\nUsername: %s\r\n\r\nIf this was a mistake, ignore this email and nothing will happen.\r\n\r\nTo reset your password, visit the following address:\r\n\r\n%s\r\n\r\nThis password reset request originated from the IP address %s.\r\n", 'cyber-wakili-plugin'),
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

    /**
     * Validate file uploads
     */
    public function validate_file_upload(array $file): array
    {
        // Only validate on front-end (not admin or AJAX uploads)
        if (is_admin() && !wp_doing_ajax()) {
            return $file;
        }

        // Optional: check for a hidden field or nonce to ensure it's your plugin's form
        if (empty($_POST['cw_plugin_upload_nonce']) || !wp_verify_nonce($_POST['cw_plugin_upload_nonce'], 'cw_file_upload')) {
            return $file;
        }

        // Validate file type
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_types, true)) {
            $file['error'] = __('Invalid file type. Allowed types: PDF, DOC, DOCX, TXT, JPG, PNG.', 'cyber-wakili-plugin');
        }

        return $file;

        //<input type="hidden" name="cw_plugin_upload_nonce" value="<?php echo esc_attr(wp_create_nonce('cw_file_upload')); ?/>">

}


/**
* Link uploaded files to cases
*/
public function link_upload_to_case(array $upload): array
{
    if (!empty($_POST['case_id']) && is_numeric($_POST['case_id'])) {
        $case_id = absint($_POST['case_id']);
        $attachment_id = attachment_url_to_postid($upload['url']);

        if ($attachment_id) {
            update_post_meta($attachment_id, '_case_id', $case_id);
            update_post_meta($attachment_id, '_uploader_id', get_current_user_id());
        }
    }

    return $upload;
}

/**
* Get MPESA transaction details for a user
*/
public function get_mpesa_transaction_details(int $user_id): array
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'mpesa_transactions';
    $result = $wpdb->get_row(
    $wpdb->prepare(
    "SELECT status, amount FROM $table_name WHERE user_id = %d ORDER BY transaction_date DESC LIMIT 1",
    $user_id
    ),
    ARRAY_A
    );

    return $result ?: ['status' => null, 'amount' => null];
}
}