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
        self::setup_authentication_hooks();
        self::setup_security_hooks();
        self::setup_guest_management_hooks();
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
     * Setup guest management related hooks
     */
    private static function setup_guest_management_hooks(): void
    {
        // Hook the AJAX actions
        add_action( 'wp_ajax_vms_ajax_test_connection', [self::class, 'vms_ajax_test_connection'] );
        add_action( 'wp_ajax_vms_ajax_save_settings', [self::class, 'vms_ajax_save_settings'] );
        add_action( 'wp_ajax_vms_ajax_refresh_balance', [self::class, 'vms_ajax_refresh_balance'] );

        // User
        add_action('wp_ajax_change_user_password', [self::class, 'handle_change_user_password'] );
        // Club
        add_action('wp_ajax_club_registration', [self::class, 'handle_club_registration'] );
        add_action('wp_ajax_get_club_data', [self::class, 'handle_get_club_data'] );
        add_action('wp_ajax_delete_club', [self::class, 'handle_delete_club'] );
        add_action('wp_ajax_club_update', [self::class, 'handle_club_update'] );

        // Reciprocating Member
        add_action('wp_ajax_reciprocating_member_registration', [self::class, 'handle_reciprocating_registration']);
        add_action('wp_ajax_reciprocating_member_sign_in', [self::class, 'handle_reciprocating_sign_in']);
        add_action('wp_ajax_reciprocating_member_sign_out', [self::class, 'handle_reciprocating_sign_out']);

        // Employee
        add_action('wp_ajax_employee_registration', [self::class, 'handle_employee_registration']);
        // Guest
        add_action('wp_ajax_guest_registration', [self::class, 'handle_guest_registration']);
        add_action('wp_ajax_courtesy_guest_registration', [self::class, 'handle_courtesy_guest_registration']);
        add_action('wp_ajax_update_guest', [self::class, 'handle_guest_update']);
        add_action('wp_ajax_delete_guest', [self::class, 'handle_guest_deletion']);
        add_action('wp_ajax_update_member', [self::class, 'handle_member_update']);
        add_action('wp_ajax_delete_member', [self::class, 'handle_member_deletion']);
        add_action('wp_ajax_register_visit', [self::class, 'handle_visit_registration']);
        add_action('wp_ajax_register_reciprocation_member_visit', [self::class, 'handle_reciprocation_member_visit_registration']);

        add_action('wp_ajax_sign_in_guest', [self::class, 'handle_sign_in_guest']);
        add_action('wp_ajax_sign_out_guest', [self::class, 'handle_sign_out_guest']);
        add_action('auto_update_visit_status_at_midnight', [self::class, 'auto_update_visit_statuses']);
        add_action('auto_sign_out_guests_at_midnight', [self::class, 'auto_sign_out_guests']);
        add_action('reset_monthly_guest_limits', [self::class, 'reset_monthly_limits']);
        add_action('reset_yearly_guest_limits', [self::class, 'reset_yearly_limits']);

        // NEW: Add cancellation handler
        add_action('wp_ajax_cancel_visit', [self::class, 'handle_visit_cancellation']);
        add_action('wp_ajax_update_guest_status', [self::class, 'handle_guest_status_update']);
        add_action('wp_ajax_update_visit_status', [self::class, 'handle_visit_status_update']);

        add_action('admin_init', [self::class, 'handle_status_setup']);
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

            case 'rp':
            case 'resetpass':
                if ( isset($_GET['key'], $_GET['login']) ) {
                    wp_redirect( site_url( '/password-reset/?key=' . urlencode($_GET['key']) . '&login=' . urlencode($_GET['login']) ) );
                    exit;
                }
                break;

            case 'register':
                wp_redirect( site_url( '/register' ) );
                exit;

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
                    __('Your account is pending approval. Please try again later.', 'vms')
                );

            case 'suspended':
                return new WP_Error(
                    'account_suspended',
                    __('Your account has been suspended. Contact support for assistance.', 'vms')
                );

            case 'banned':
                return new WP_Error(
                    'account_banned',
                    __('Your account has been permanently banned. Please contact the administrator if you believe this is an error.', 'vms')
                );

            default:
                return new WP_Error(
                    'account_inactive',
                    __('Your account status is invalid. Please contact support.', 'vms')
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
                'vms'
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

    /**
     * Handle password change via AJAX
     */
    public static function handle_change_user_password() 
    {
        try {
            // Verify nonce for security
            if (!wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed. Please refresh the page and try again.'
                ));
                return;
            }

            // Check if user is logged in
            if (!is_user_logged_in()) {
                wp_send_json_error(array(
                    'message' => 'You must be logged in to change your password.'
                ));
                return;
            }

            // Get current user
            $current_user = wp_get_current_user();
            $user_id = $current_user->ID;

            // Sanitize input data
            $current_password = sanitize_text_field($_POST['current_password']);
            $new_password = sanitize_text_field($_POST['new_password']);
            $confirm_password = sanitize_text_field($_POST['confirm_password']);

            // Validate input
            $validation_result = self::validate_password_change_data($current_password, $new_password, $confirm_password, $current_user);
            
            if (is_wp_error($validation_result)) {
                wp_send_json_error(array(
                    'message' => $validation_result->get_error_message()
                ));
                return;
            }

            // Change the password
            $change_result = self::change_user_password($user_id, $new_password);
            
            if (is_wp_error($change_result)) {
                wp_send_json_error(array(
                    'message' => $change_result->get_error_message()
                ));
                return;
            }

            // Log the password change
            error_log(sprintf('Password changed for user ID: %d, Email: %s', $user_id, $current_user->user_email));

            // Send success response
            wp_send_json_success(array(
                'message' => 'Your password has been changed successfully.'
            ));

        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An unexpected error occurred. Please try again.'
            ));
        }
    }

    /**
     * Validate password change data
     */
    private static function validate_password_change_data($current_password, $new_password, $confirm_password, $user) 
    {
        // Check if all fields are provided
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            return new WP_Error('missing_fields', 'All password fields are required.');
        }

        // Verify current password
        if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
            return new WP_Error('incorrect_password', 'Current password is incorrect.');
        }

        // Check if new passwords match
        if ($new_password !== $confirm_password) {
            return new WP_Error('password_mismatch', 'New passwords do not match.');
        }

        // Check password length
        if (strlen($new_password) < 8) {
            return new WP_Error('password_too_short', 'Password must be at least 8 characters long.');
        }

        // Check password strength
        $strength_check = self::check_password_strength($new_password);
        if (is_wp_error($strength_check)) {
            return $strength_check;
        }

        // Check if new password is different from current
        if (wp_check_password($new_password, $user->user_pass, $user->ID)) {
            return new WP_Error('same_password', 'New password must be different from your current password.');
        }

        return true;
    }

    /**
     * Check password strength
     */
    private static function check_password_strength($password) 
    {
        $errors = array();

        // Check for uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'at least one uppercase letter';
        }

        // Check for lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'at least one lowercase letter';
        }

        // Check for number
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'at least one number';
        }

        // Check for special character
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'at least one special character (!@#$%^&*(),.?":{}|<>)';
        }

        // Check for common weak passwords
        $weak_passwords = array('password', '123456', 'qwerty', 'abc123', 'password123');
        if (in_array(strtolower($password), $weak_passwords)) {
            return new WP_Error('weak_password', 'This password is too common. Please choose a stronger password.');
        }

        if (!empty($errors)) {
            $message = 'Password must contain: ' . implode(', ', $errors) . '.';
            return new WP_Error('password_requirements', $message);
        }

        return true;
    }

    /**
     * Change user password safely
     */
    private static function change_user_password($user_id, $new_password) 
    {
        try {
            // Update user password
            $update_result = wp_update_user(array(
                'ID' => $user_id,
                'user_pass' => $new_password
            ));

            if (is_wp_error($update_result)) {
                return new WP_Error('update_failed', 'Failed to update password in database.');
            }

            // Clear user cache
            clean_user_cache($user_id);

            // Update user meta to track password change
            update_user_meta($user_id, 'last_password_change', current_time('mysql'));

            // Send email notification (optional)
            self::send_password_change_notification($user_id);

            return true;

        } catch (Exception $e) {
            error_log('Password update error: ' . $e->getMessage());
            return new WP_Error('update_error', 'An error occurred while updating your password.');
        }
    }

    /**
     * Send password change notification email (optional)
     */
    private static function send_password_change_notification($user_id) 
    {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }

        $subject = sprintf('[%s] Password Changed', get_bloginfo('name'));
        $message = sprintf(
            "Hello %s,\n\nYour password for %s has been successfully changed.\n\nIf you did not make this change, please contact us immediately.\n\nTime: %s\nIP Address: %s\n\nBest regards,\n%s Team",
            $user->display_name,
            get_bloginfo('name'),
            current_time('mysql'),
            $_SERVER['REMOTE_ADDR'],
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Send SMS notification to user on password change
     *
     * @param int $user_id User ID
     */
    private static function send_password_change_sms($user_id)
    {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        // Check if user has phone number and SMS enabled
        $phone = get_user_meta($user_id, 'phone_number', true);
        $receive_sms = get_user_meta($user_id, 'receive_sms', true);
        
        if (empty($phone) || $receive_sms !== 'yes') {
            return false;
        }
        
        // Debug log
        error_log("SMS Triggered: Password changed for user ID {$user_id}");
        
        // Get user role
        $user_roles = $user->roles;
        $role = !empty($user_roles) ? $user_roles[0] : 'subscriber';
        
        // Build message
        $site_name = get_bloginfo('name');
        $user_name = $user->display_name ?: $user->user_login;
        $current_time = current_time('Y-m-d H:i:s');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $message = sprintf(
            "%s: Dear %s, your password was changed successfully at %s from IP %s. Contact us if you did not make this change.",
            $site_name,
            $user_name,
            $current_time,
            $ip_address
        );
        
        // Send SMS through notification manager
        VMS_NotificationManager::send_sms($phone, $message, $user_id, $role);
        
        return true;
    }

    /**
     * AJAX handler for saving settings
     */
    public static function vms_ajax_save_settings() 
    {
        self::verify_ajax_request();
        
        // Check user permissions
        if (!current_user_can('administrator') && !current_user_can('reception') && 
            !current_user_can('general_manager') && !current_user_can('chairman')) {
            wp_send_json_error(['errors' => ['Insufficient permissions']]);
        }
        
        $errors = [];
        
        // Sanitize and validate inputs
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_secret = sanitize_text_field($_POST['api_secret'] ?? '');
        $sender_id = sanitize_text_field($_POST['sender_id'] ?? 'SMS_TEST');
        $status_url = esc_url_raw($_POST['status_url'] ?? '');
        $status_secret = sanitize_text_field($_POST['status_secret'] ?? '');
        
        // Validate required fields
        if (empty($api_key)) {
            $errors[] = 'API Key is required';
        }
        
        if (empty($api_secret)) {
            $errors[] = 'API Secret is required';
        }
        
        if (!empty($status_url) && empty($status_secret)) {
            $errors[] = 'Status Secret is required when Status URL is provided';
        }
        
        // Validate URL format if provided
        if (!empty($status_url) && !filter_var($status_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid Status Callback URL';
        }
        
        if (!empty($errors)) {
            wp_send_json_error(['errors' => $errors]);
        }
        
        // Save settings
        update_option('vms_sms_api_key', $api_key);
        update_option('vms_sms_api_secret', $api_secret);
        update_option('vms_sms_sender_id', $sender_id);
        update_option('vms_status_url', $status_url);
        update_option('vms_status_secret', $status_secret);
        
        wp_send_json_success(['message' => 'Settings saved successfully']);
    }

    /**
     * AJAX handler for testing connection
     */
    public static function vms_ajax_test_connection() 
    {
        self::verify_ajax_request();
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_secret = sanitize_text_field($_POST['api_secret'] ?? '');
        
        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error(['errors' => ['API Key and API Secret are required']]);
        }
        
        // Test connection by trying to get balance
        $response = wp_remote_get('https://api.smsleopard.com/v1/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['errors' => ['Connection failed: ' . $response->get_error_message()]]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200 && isset($data['balance'])) {
            wp_send_json_success([
                'message' => 'Connection successful! Your API credentials are working properly.',
                'balance' => $data['balance']
            ]);
        } elseif ($status_code === 401) {
            wp_send_json_error(['errors' => ['Invalid API credentials. Please check your API Key and Secret.']]);
        } elseif ($status_code === 403) {
            wp_send_json_error(['errors' => ['Access denied. Please verify your API permissions.']]);
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error occurred';
            wp_send_json_error(['errors' => ['API Error: ' . $error_message]]);
        }
    }

    /**
     * AJAX handler for refreshing SMS balance
     */
    public static function vms_ajax_refresh_balance() 
    {
        self::verify_ajax_request();
        
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error(['errors' => ['Please configure API credentials first']]);
        }
        
        $response = wp_remote_get('https://api.smsleopard.com/v1/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['errors' => ['API connection failed: ' . $response->get_error_message()]]);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['balance'])) {
            update_option('vms_sms_balance', $data['balance']);
            $last_check = current_time('mysql');
            update_option('vms_sms_last_check', $last_check);
            
            wp_send_json_success([
                'message'      => 'Balance refreshed successfully',
                'balance'      => $data['balance'],
                'last_checked' => date('M j, Y g:i A', strtotime($last_check)),
            ]);
        }
        
        wp_send_json_error(['errors' => ['Failed to retrieve balance from API']]);
    }

    /**
     * NEW: Handle visit cancellation via AJAX
     */
    public static function handle_visit_cancellation(): void
    {
        self::verify_ajax_request();
        
        $visit_id = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
        
        if (!$visit_id) {
            wp_send_json_error(['messages' => ['Invalid visit ID']]);
            return;
        }

        global $wpdb;
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get visit and guest data
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT gv.*, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
            FROM {$guest_visits_table} gv
            LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
            WHERE gv.id = %d",
            $visit_id
        ));

        if (!$visit) {
            wp_send_json_error(['messages' => ['Visit not found']]);
            return;
        }

        // Store old status for notification
        $old_status = $visit->status;

        // Update visit status to cancelled
        $updated = $wpdb->update(
            $guest_visits_table,
            ['status' => 'cancelled'],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to cancel visit']]);
            return;
        }

        // Recalculate guest visit statuses
        self::recalculate_guest_visit_statuses($visit->guest_id);

        // Recalculate host daily limits
        if ($visit->host_member_id) {
            self::recalculate_host_daily_limits($visit->host_member_id, $visit->visit_date);
        }

        // Send cancellation notifications
        $guest_data = [
            'first_name' => $visit->first_name,
            'phone_number' => $visit->phone_number,
            'email' => $visit->email,
            'receive_messages' => $visit->receive_messages,
            'receive_emails' => $visit->receive_emails,
            'user_id' => $visit->guest_id
        ];

        $visit_data = [
            'visit_date' => $visit->visit_date,
            'host_member_id' => $visit->host_member_id
        ];

        // Send SMS notification
        VMS_NotificationManager::get_instance()->send_visit_status_notification(
            $guest_data, $visit_data, $old_status, 'cancelled'
        );

        // Send email notification
        self::send_visit_cancellation_email($guest_data, $visit_data);

        wp_send_json_success(['messages' => ['Visit cancelled successfully']]);
    }

    /**
     * NEW: Handle guest status update
     */
    public static function handle_guest_status_update(): void
    {
        self::verify_ajax_request();

        error_log("handle_guest_status_update called");
        
        $guest_id = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
        $new_status = sanitize_text_field($_POST['guest_status'] ?? '');
        
        if (!$guest_id || !in_array($new_status, ['active', 'suspended', 'banned'])) {
            wp_send_json_error(['messages' => ['Invalid parameters']]);
            return;
        }

        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get current guest data
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$guests_table} WHERE id = %d",
            $guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        $old_status = $guest->guest_status;

        // Update guest status
        $updated = $wpdb->update(
            $guests_table,
            ['guest_status' => $new_status, 'updated_at' => current_time('mysql')],
            ['id' => $guest_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to update guest status']]);
            return;
        }

        // Recalculate all visit statuses for this guest
        self::recalculate_guest_visit_statuses($guest_id);

        // Send status change notifications
        $guest_data = [
            'first_name' => $guest->first_name,
            'phone_number' => $guest->phone_number,
            'email' => $guest->email,
            'receive_messages' => $guest->receive_messages,
            'receive_emails' => $guest->receive_emails,
            'user_id' => $guest_id
        ];

        // Send notifications
        VMS_NotificationManager::get_instance()->send_guest_status_notification(
            $guest_data, $old_status, $new_status
        );

        self::send_guest_status_change_email($guest_data, $old_status, $new_status);
        self::send_guest_status_change_sms($guest_data, $old_status, $new_status);

        wp_send_json_success(['messages' => ['Guest status updated successfully']]);
    }

    /**
     * Handle guest sign in via AJAX - UPDATED with notifications
     */
    public static function handle_sign_in_guest(): void
    {    
        self::verify_ajax_request();

        $visit_id  = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');

        if (!$visit_id) {
            wp_send_json_error(['messages' => ['Invalid visit ID']]);
            return;
        }
        if (empty($id_number) || strlen($id_number) < 5) {
            wp_send_json_error(['messages' => ['Valid ID number (min 5 digits) is required']]);
            return;
        }

        global $wpdb;
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get visit and guest data (include id_number check)
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT gv.*, g.id_number, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails, g.guest_status
            FROM {$guest_visits_table} gv
            LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
            WHERE gv.id = %d",
            $visit_id
        ));

        if (!$visit) {
            wp_send_json_error(['messages' => ['Visit not found']]);
            return;
        }

        // Validate ID number matches
        if ($visit->id_number !== $id_number) {
            // Instead of throwing error, update the id_number in guests table
            $updated = $wpdb->update(
                $guests_table,
                ['id_number' => $id_number],
                ['id' => $visit->guest_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['messages' => ['Failed to update ID number']]);
                return;
            }

            // Also update the $visit object so we can use it later in notifications
            $visit->id_number = $id_number;
        }


        if (!empty($visit->sign_in_time)) {
            wp_send_json_error(['messages' => ['Guest already signed in']]);
            return;
        }

        // Check guest status
        if (in_array($visit->guest_status, ['banned', 'suspended'])) {
            wp_send_json_error(['messages' => ['Guest access is restricted due to status: ' . $visit->guest_status]]);
            return;
        }

        // Check visit date
        $current_date = current_time('Y-m-d');
        $visit_date = date('Y-m-d', strtotime($visit->visit_date));
        
        if ($visit_date !== $current_date) {
            wp_send_json_error(['messages' => ['Guest can only sign in on their scheduled visit date']]);
            return;
        }

        $signin_time = current_time('mysql');

        // Update sign-in time
        $updated = $wpdb->update(
            $guest_visits_table,
            ['sign_in_time' => $signin_time],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to sign in guest']]);
            return;
        }

        // Send sign-in notifications
        $guest_data = [
            'first_name' => $visit->first_name,
            'phone_number' => $visit->phone_number,
            'email' => $visit->email,
            'receive_messages' => $visit->receive_messages,
            'receive_emails' => $visit->receive_emails,
            'user_id' => $visit->guest_id
        ];

        $visit_data = [
            'sign_in_time' => $signin_time,
            'visit_date' => $visit->visit_date
        ];

        // Send SMS and email notifications
        VMS_NotificationManager::get_instance()->send_signin_notification($guest_data, $visit_data);
        self::send_signin_email_notification($guest_data, $visit_data);

        // Fetch host member name
        $host_member = get_user_by('id', $visit->host_member_id);
        $host_name = $host_member ? $host_member->display_name : 'N/A';

        // Prepare response data
        $guest_data_response = [
            'id' => $visit->guest_id,
            'first_name' => $visit->first_name,
            'last_name' => $visit->last_name,
            'sign_in_time' => $signin_time,
            'visit_id' => $visit_id,
            'id_number' => $visit->id_number
        ];

        wp_send_json_success([
            'messages' => ['Guest signed in successfully'],
            'guestData' => $guest_data_response
        ]);
    }


    /**
     * Handle guest sign out via AJAX - UPDATED with notifications
     */
    public static function handle_sign_out_guest(): void
    {
        self::verify_ajax_request();

        $visit_id = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;

        if (!$visit_id) {
            wp_send_json_error(['messages' => ['Invalid visit ID']]);
            return;
        }

        global $wpdb;
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get visit and guest data
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT gv.*, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
            FROM {$guest_visits_table} gv
            LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
            WHERE gv.id = %d",
            $visit_id
        ));

        if (!$visit) {
            wp_send_json_error(['messages' => ['Visit not found']]);
            return;
        }

        if (empty($visit->sign_in_time)) {
            wp_send_json_error(['messages' => ['Guest must be signed in first']]);
            return;
        }

        if (!empty($visit->sign_out_time)) {
            wp_send_json_error(['messages' => ['Guest already signed out']]);
            return;
        }

        $signout_time = current_time('mysql');

        // Update sign-out time
        $updated = $wpdb->update(
            $guest_visits_table,
            ['sign_out_time' => $signout_time],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to sign out guest']]);
            return;
        }

        // Send sign-out notifications
        $guest_data = [
            'first_name' => $visit->first_name,
            'phone_number' => $visit->phone_number,
            'email' => $visit->email,
            'receive_messages' => $visit->receive_messages,
            'receive_emails' => $visit->receive_emails,
            'user_id' => $visit->guest_id
        ];

        $visit_data = [
            'sign_out_time' => $signout_time,
            'sign_in_time' => $visit->sign_in_time
        ];

        // Send SMS and email notifications
        VMS_NotificationManager::get_instance()->send_signout_notification($guest_data, $visit_data);
        self::send_signout_email_notification($guest_data, $visit_data);

        // Prepare response data
        $guest_data_response = [
            'id' => $visit->guest_id,
            'first_name' => $visit->first_name,
            'last_name' => $visit->last_name,
            'sign_in_time' => $visit->sign_in_time,
            'sign_out_time' => $signout_time,
            'visit_id' => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Guest signed out successfully'],
            'guestData' => $guest_data_response
        ]);
    }

    /**
     * Recalculate visit statuses for a specific reciprocating member - with notifications
     */
    public static function recalculate_member_visit_statuses(int $member_id): void
    {
        global $wpdb;
        
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $monthly_limit = 4;
        $yearly_limit = 12;
        
        // Get member data for notifications
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$recip_members_table} WHERE id = %d",
            $member_id
        ));

        if (!$member) return;

        $old_member_status = $member->member_status;
        
        // Fetch all non-cancelled visits for this member, ordered by visit_date ASC
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, visit_date, sign_in_time, status 
            FROM $recip_visits_table 
            WHERE member_id = %d AND status != 'cancelled' 
            ORDER BY visit_date ASC",
            $member_id
        ));
        
        if (!$visits) return;
        
        $monthly_scheduled = [];
        $yearly_scheduled = [];
        $status_changes = [];
        
        foreach ($visits as $visit) {
            $month_key = date('Y-m', strtotime($visit->visit_date));
            $year_key = date('Y', strtotime($visit->visit_date));
            
            if (!isset($monthly_scheduled[$month_key])) $monthly_scheduled[$month_key] = 0;
            if (!isset($yearly_scheduled[$year_key])) $yearly_scheduled[$year_key] = 0;
            
            $visit_date_obj = new \DateTime($visit->visit_date);
            $today = new \DateTime(current_time('Y-m-d'));
            $is_past = $visit_date_obj < $today;
            $attended = $is_past && !empty($visit->sign_in_time);
            
            // Increment counts for future visits or past attended visits
            if (!$is_past || $attended) {
                $monthly_scheduled[$month_key]++;
                $yearly_scheduled[$year_key]++;
            }
            
            // Determine status for this visit
            $old_status = $visit->status;
            $new_status = 'approved';
            
            // Check monthly/yearly limits
            if ($monthly_scheduled[$month_key] > $monthly_limit || $yearly_scheduled[$year_key] > $yearly_limit) {
                $new_status = 'unapproved';
            }
            
            // If this is a missed past visit, reduce counts to free slots
            if ($is_past && empty($visit->sign_in_time)) {
                $monthly_scheduled[$month_key]--;
                $yearly_scheduled[$year_key]--;
            }
            
            // Update only if status changed
            if ($visit->status !== $new_status) {
                $wpdb->update(
                    $recip_visits_table,
                    ['status' => $new_status],
                    ['id' => $visit->id],
                    ['%s'],
                    ['%d']
                );
                
                // Track status changes for notifications
                $status_changes[] = [
                    'visit_id' => $visit->id,
                    'visit_date' => $visit->visit_date,
                    'old_status' => $old_status,
                    'new_status' => $new_status
                ];
            }
        }

        // Check if member should be automatically suspended due to limits
        $current_month = date('Y-m');
        $current_year = date('Y');
        $new_member_status = $member->member_status;

        if ($member->member_status === 'active') {
            $current_monthly = $monthly_scheduled[$current_month] ?? 0;
            $current_yearly = $yearly_scheduled[$current_year] ?? 0;

            if ($current_monthly >= $monthly_limit || $current_yearly >= $yearly_limit) {
                $new_member_status = 'suspended';
            }
        }

        // Update member status if changed
        if ($new_member_status !== $old_member_status) {
            $wpdb->update(
                $recip_members_table,
                ['member_status' => $new_member_status, 'updated_at' => current_time('mysql')],
                ['id' => $member_id],
                ['%s', '%s'],
                ['%d']
            );

            // Send member status change notification
            $member_data = [
                'first_name' => $member->first_name,
                'phone_number' => $member->phone_number,
                'email' => $member->email,
                'receive_messages' => $member->receive_messages,
                'receive_emails' => $member->receive_emails,
                'user_id' => $member_id
            ];

            VMS_NotificationManager::get_instance()->send_member_status_notification(
                $member_data, $old_member_status, $new_member_status
            );
        }

        // Send visit status change notifications
        foreach ($status_changes as $change) {
            $member_data = [
                'first_name' => $member->first_name,
                'phone_number' => $member->phone_number,
                'email' => $member->email,
                'receive_messages' => $member->receive_messages,
                'receive_emails' => $member->receive_emails,
                'user_id' => $member_id
            ];

            $visit_data = [
                'visit_date' => $change['visit_date']
            ];

            VMS_NotificationManager::get_instance()->send_member_visit_status_notification(
                $member_data, $visit_data, $change['old_status'], $change['new_status']
            );
        }
    }

    /**
     * Recalculate visit statuses for a specific guest - UPDATED with notifications
     */
    public static function recalculate_guest_visit_statuses(int $guest_id): void
    {
        global $wpdb;
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $monthly_limit = 4;
        $yearly_limit = 12;
        
        // Get guest data for notifications
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$guests_table} WHERE id = %d",
            $guest_id
        ));

        if (!$guest) return;

        $old_guest_status = $guest->guest_status;
        
        // Fetch all non-cancelled visits for this guest, ordered by visit_date ASC
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, visit_date, sign_in_time, status, host_member_id 
            FROM $guest_visits_table 
            WHERE guest_id = %d AND status != 'cancelled' 
            ORDER BY visit_date ASC",
            $guest_id
        ));
        
        if (!$visits) return;
        
        $monthly_scheduled = [];
        $yearly_scheduled = [];
        $status_changes = [];
        
        foreach ($visits as $visit) {
            $month_key = date('Y-m', strtotime($visit->visit_date));
            $year_key = date('Y', strtotime($visit->visit_date));
            
            if (!isset($monthly_scheduled[$month_key])) $monthly_scheduled[$month_key] = 0;
            if (!isset($yearly_scheduled[$year_key])) $yearly_scheduled[$year_key] = 0;
            
            $visit_date_obj = new \DateTime($visit->visit_date);
            $today = new \DateTime(current_time('Y-m-d'));
            $is_past = $visit_date_obj < $today;
            $attended = $is_past && !empty($visit->sign_in_time);
            
            // Increment counts for future visits or past attended visits
            if (!$is_past || $attended) {
                $monthly_scheduled[$month_key]++;
                $yearly_scheduled[$year_key]++;
            }
            
            // Check host daily limit for this visit
            $host_daily_count = 0;
            if ($visit->host_member_id) {
                $host_daily_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $guest_visits_table 
                    WHERE host_member_id = %d AND visit_date = %s AND status != 'cancelled'",
                    $visit->host_member_id,
                    $visit->visit_date
                ));
            }
            
            // Determine status for this visit
            $old_status = $visit->status;
            $new_status = 'approved';
            
            // Check monthly/yearly limits
            if ($monthly_scheduled[$month_key] > $monthly_limit || $yearly_scheduled[$year_key] > $yearly_limit) {
                $new_status = 'unapproved';
            }
            
            // Check host daily limit (4 guests per host per day)
            if ($host_daily_count > 4) {
                $new_status = 'unapproved';
            }
            
            // If this is a missed past visit, reduce counts to free slots
            if ($is_past && empty($visit->sign_in_time)) {
                $monthly_scheduled[$month_key]--;
                $yearly_scheduled[$year_key]--;
            }
            
            // Update only if status changed
            if ($visit->status !== $new_status) {
                $wpdb->update(
                    $guest_visits_table,
                    ['status' => $new_status],
                    ['id' => $visit->id],
                    ['%s'],
                    ['%d']
                );
                
                // Track status changes for notifications
                $status_changes[] = [
                    'visit_id' => $visit->id,
                    'visit_date' => $visit->visit_date,
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'host_member_id' => $visit->host_member_id
                ];
            }
        }

        // Check if guest should be automatically suspended due to limits
        $current_month = date('Y-m');
        $current_year = date('Y');
        $new_guest_status = $guest->guest_status;

        if ($guest->guest_status === 'active') {
            $current_monthly = $monthly_scheduled[$current_month] ?? 0;
            $current_yearly = $yearly_scheduled[$current_year] ?? 0;

            if ($current_monthly >= $monthly_limit || $current_yearly >= $yearly_limit) {
                $new_guest_status = 'suspended';
            }
        }

        // Update guest status if changed
        if ($new_guest_status !== $old_guest_status) {
            $wpdb->update(
                $guests_table,
                ['guest_status' => $new_guest_status, 'updated_at' => current_time('mysql')],
                ['id' => $guest_id],
                ['%s', '%s'],
                ['%d']
            );

            // Send guest status change notification
            $guest_data = [
                'first_name' => $guest->first_name,
                'phone_number' => $guest->phone_number,
                'email' => $guest->email,
                'receive_messages' => $guest->receive_messages,
                'receive_emails' => $guest->receive_emails,
                'user_id' => $guest_id
            ];

            VMS_NotificationManager::get_instance()->send_guest_status_notification(
                $guest_data, $old_guest_status, $new_guest_status
            );
        }

        // Send visit status change notifications
        foreach ($status_changes as $change) {
            $guest_data = [
                'first_name' => $guest->first_name,
                'phone_number' => $guest->phone_number,
                'email' => $guest->email,
                'receive_messages' => $guest->receive_messages,
                'receive_emails' => $guest->receive_emails,
                'user_id' => $guest_id
            ];

            $visit_data = [
                'visit_date' => $change['visit_date'],
                'host_member_id' => $change['host_member_id']
            ];

            VMS_NotificationManager::get_instance()->send_visit_status_notification(
                $guest_data, $visit_data, $change['old_status'], $change['new_status']
            );
        }
    }

    /**
     * Recalculate host daily limits - UPDATED with notifications
     */
    public static function recalculate_host_daily_limits(int $host_member_id, string $visit_date): void
    {
        global $wpdb;
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        
        // Get host data for notifications
        $host_user = get_userdata($host_member_id);
        if (!$host_user) return;

        // Get all visits for this host on this date (excluding cancelled)
        $host_visits = $wpdb->get_results($wpdb->prepare(
            "SELECT gv.id, gv.guest_id, gv.status, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
            FROM $guest_visits_table gv
            LEFT JOIN $guests_table g ON g.id = gv.guest_id
            WHERE gv.host_member_id = %d AND gv.visit_date = %s AND gv.status != 'cancelled' 
            ORDER BY gv.created_at ASC",
            $host_member_id,
            $visit_date
        ));
        
        if (!$host_visits) return;
        
        $count = 0;
        $unapproved_count = 0;
        $status_changes = [];
        
        foreach ($host_visits as $visit) {
            $count++;
            $old_status = $visit->status;
            
            // First 4 visits should be approved (if not restricted by other limits)
            $new_status = $count <= 4 ? 'approved' : 'unapproved';
            
            if ($new_status === 'unapproved') {
                $unapproved_count++;
            }
            
            // Only update if status changed and it's not restricted by guest limits
            if ($visit->status !== $new_status) {
                // Double-check guest limits before approving
                if ($new_status === 'approved') {
                    $guest_status = self::calculate_preliminary_visit_status($visit->guest_id, $visit_date);
                    $new_status = $guest_status;
                }
                
                $wpdb->update(
                    $guest_visits_table,
                    ['status' => $new_status],
                    ['id' => $visit->id],
                    ['%s'],
                    ['%d']
                );

                // Track status changes for notifications
                if ($old_status !== $new_status) {
                    $status_changes[] = [
                        'guest_data' => [
                            'first_name' => $visit->first_name,
                            'phone_number' => $visit->phone_number,
                            'email' => $visit->email,
                            'receive_messages' => $visit->receive_messages,
                            'receive_emails' => $visit->receive_emails,
                            'user_id' => $visit->guest_id
                        ],
                        'visit_data' => [
                            'visit_date' => $visit_date,
                            'host_member_id' => $host_member_id
                        ],
                        'old_status' => $old_status,
                        'new_status' => $new_status
                    ];
                }
            }
        }

        // Send host limit notification if there are unapproved guests
        if ($unapproved_count > 0) {
            $host_data = [
                'user_id' => $host_member_id,
                'first_name' => get_user_meta($host_member_id, 'first_name', true) ?: $host_user->display_name,
                'phone_number' => get_user_meta($host_member_id, 'phone_number', true)
            ];

            VMS_NotificationManager::get_instance()->send_host_limit_notification(
                $host_data, $visit_date, $unapproved_count
            );
        }

        // Send visit status change notifications
        foreach ($status_changes as $change) {
            VMS_NotificationManager::get_instance()->send_visit_status_notification(
                $change['guest_data'], $change['visit_data'], $change['old_status'], $change['new_status']
            );
        }
    }

    /**
     * NEW: Send visit cancellation email notification
     */
    private static function send_visit_cancellation_email(array $guest_data, array $visit_data): void
    {
        global $wpdb;
        $table_name = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Always verify against the database value to avoid stale $guest_data
        $receive_emails = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT receive_emails FROM $table_name WHERE id = %d",
                $guest_data['id'] ?? 0
            )
        );

        if ($receive_emails !== 'yes') {
            return; // Do not send if guest opted out
        }

        $formatted_date = date('F j, Y', strtotime($visit_data['visit_date']));

        $subject = 'Visit Cancellation - Nyeri Club';
        $message = "Dear {$guest_data['first_name']},\n\n";
        $message .= "Your visit to Nyeri Club scheduled for {$formatted_date} has been cancelled.\n\n";

        if (!empty($visit_data['host_member_id'])) {
            $host = get_userdata($visit_data['host_member_id']);
            if ($host) {
                $host_name = get_user_meta($visit_data['host_member_id'], 'first_name', true) . ' ' .
                        get_user_meta($visit_data['host_member_id'], 'last_name', true);
                $message .= "Host: " . trim($host_name) . "\n\n";
            }
        }

        $message .= "If you have any questions, please contact your host or reception.\n\n";
        $message .= "Best regards,\n";
        $message .= "Nyeri Club Visitor Management System";

        wp_mail($guest_data['email'], $subject, $message);
    }

        /**
     * Send SMS notification to guest on visit cancellation
     *
     * @param array $guest_data Guest info (must include: id, first_name, phone_number, receive_messages)
     * @param array $visit_data Visit info (must include: visit_date, host_member_id)
     */
    private static function send_visit_cancellation_sms(array $guest_data, array $visit_data): void
    {
        // Ensure phone number exists and guest has opted in for SMS
        if (empty($guest_data['phone_number']) || ($guest_data['receive_messages'] ?? 'no') !== 'yes') {
            return;
        }

        $guest_id   = $guest_data['id'] ?? 0;
        $first_name = $guest_data['first_name'] ?? 'Guest';
        $phone      = $guest_data['phone_number'];
        $role       = 'guest';

        // Format visit date
        $formatted_date = !empty($visit_data['visit_date'])
            ? date('F j, Y', strtotime($visit_data['visit_date']))
            : 'the scheduled date';

        // Start message
        $message = "Dear {$first_name}, your visit scheduled for {$formatted_date} has been cancelled.";

        // Add host info if available
        if (!empty($visit_data['host_member_id'])) {
            $host_first = get_user_meta($visit_data['host_member_id'], 'first_name', true);
            $host_last  = get_user_meta($visit_data['host_member_id'], 'last_name', true);
            $host_name  = trim($host_first . ' ' . $host_last);

            if (!empty($host_name)) {
                $message .= " Host: {$host_name}.";
            }
        }

        $message .= " For inquiries, please contact your host or reception.";

        // Debug log
        error_log("SMS Triggered: Visit cancellation for guest ID {$guest_id}, Phone: {$phone}");

        // Send SMS through notification manager (handles logging + DB insert)
        VMS_NotificationManager::send_sms($phone, $message, $guest_id, $role);
    }


    /**
     * NEW: Send guest status change email notification
     */
    private static function send_guest_status_change_email(array $guest_data, string $old_status, string $new_status): void
    {
        if ($guest_data['receive_emails'] !== 'yes') {
            return;
        }

        error_log("EMAIL: Guest status changed from {$old_status} to {$new_status} for guest ID {$guest_data['user_id']}");

        $subject = 'Account Status Update - Nyeri Club';
        $message = "Dear {$guest_data['first_name']},\n\n";
        
        switch ($new_status) {
            case 'suspended':
                $message .= "Your guest privileges have been temporarily suspended";
                if ($old_status === 'active') {
                    $message .= " due to visit limit exceeded";
                }
                $message .= ".\n\nPlease contact reception for assistance.\n\n";
                break;
                
            case 'banned':
                $message .= "Your guest privileges have been permanently revoked.\n\n";
                $message .= "Please contact management for clarification.\n\n";
                break;
                
            case 'active':
                if (in_array($old_status, ['suspended', 'banned'])) {
                    $message .= "Your guest privileges have been restored.\n\n";
                    $message .= "You can now make new visit requests.\n\n";
                } else {
                    return; // No need to send email for normal active status
                }
                break;
                
            default:
                return;
        }
        
        $message .= "Best regards,\n";
        $message .= "Nyeri Club Visitor Management System";

        wp_mail($guest_data['email'], $subject, $message);
    }

    /**
     * Send SMS notification to guest on status change
     *
     * @param array  $guest_data  Guest info (must include: user_id, first_name, phone_number, receive_messages)
     * @param string $old_status  Previous status
     * @param string $new_status  New status
     */
    private static function send_guest_status_change_sms(array $guest_data, string $old_status, string $new_status): void
    {
        // Ensure required data exists
        if (empty($guest_data['phone_number']) || ($guest_data['receive_messages'] ?? 'no') !== 'yes') {
            return;
        }

        $guest_id   = $guest_data['user_id'] ?? 0;
        $first_name = $guest_data['first_name'] ?? 'Guest';
        $phone      = $guest_data['phone_number'];
        $role       = 'guest';

        // Debug log
        error_log("SMS Triggered: Guest status changed from {$old_status} to {$new_status} for guest ID {$guest_id}");

        // Base message
        $message = "Dear {$first_name}, ";

        switch ($new_status) {
            case 'suspended':
                $message .= "your guest access has been temporarily suspended";
                if ($old_status === 'active') {
                    $message .= " (visit limit exceeded)";
                }
                $message .= ". Contact reception for help.";
                break;

            case 'banned':
                $message .= "your guest access has been permanently revoked. Please contact management.";
                break;

            case 'active':
                // Only notify if status was previously restricted
                if (in_array($old_status, ['suspended', 'banned'])) {
                    $message .= "your guest access has been restored. You may now request new visits.";
                } else {
                    return; // Skip unnecessary notifications
                }
                break;

            default:
                return; // Unknown status → no notification
        }

        // Send SMS through notification manager (handles logging + DB insert)
        VMS_NotificationManager::send_sms($phone, $message, $guest_id, $role);
    }

    /**
     * NEW: Send sign-in email notification
     */
    private static function send_signin_email_notification(array $guest_data, array $visit_data): void
    {
        if ($guest_data['receive_emails'] !== 'yes') {
            return;
        }

        $signin_time = date('g:i A', strtotime($visit_data['sign_in_time']));
        $visit_date = date('F j, Y', strtotime($visit_data['visit_date']));
        
        $subject = 'Welcome to Nyeri Club - Check-in Confirmation';
        $message = "Dear {$guest_data['first_name']},\n\n";
        $message .= "Welcome to Nyeri Club!\n\n";
        $message .= "You have successfully checked in at {$signin_time} on {$visit_date}.\n\n";
        $message .= "Enjoy your visit!\n\n";
        $message .= "Best regards,\n";
        $message .= "Nyeri Club Visitor Management System";

        wp_mail($guest_data['email'], $subject, $message);
    }

    /**
     * NEW: Send sign-out email notification
     */
    private static function send_signout_email_notification(array $guest_data, array $visit_data): void
    {
        if ($guest_data['receive_emails'] !== 'yes') {
            return;
        }

        $signout_time = date('g:i A', strtotime($visit_data['sign_out_time']));
        $signin_time = date('g:i A', strtotime($visit_data['sign_in_time']));
        
        // Calculate duration
        $start = new \DateTime($visit_data['sign_in_time']);
        $end = new \DateTime($visit_data['sign_out_time']);
        $duration = $start->diff($end)->format('%h hours %i minutes');
        
        $subject = 'Thank You for Visiting - Nyeri Club';
        $message = "Dear {$guest_data['first_name']},\n\n";
        $message .= "Thank you for visiting Nyeri Club!\n\n";
        $message .= "Visit Summary:\n";
        $message .= "Check-in: {$signin_time}\n";
        $message .= "Check-out: {$signout_time}\n";
        $message .= "Duration: {$duration}\n\n";
        $message .= "We hope you enjoyed your visit. We look forward to welcoming you back soon!\n\n";
        $message .= "Best regards,\n";
        $message .= "Nyeri Club Visitor Management System";

        wp_mail($guest_data['email'], $subject, $message);
    }

    /**
     * Calculate guest status based on guest_status and visit limits
     */
    private static function calculate_guest_status(int $guest_id, int $host_member_id, string $visit_date): string
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        
        // First, check the guest_status field - this takes precedence
        $guest_status = $wpdb->get_var($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));
        
        // If guest_status is not 'active', return the corresponding status
        if ($guest_status !== 'active') {
            // Map guest_status to status field
            switch ($guest_status) {
                case 'suspended':
                    return 'suspended';
                case 'banned':
                    return 'banned';
                default:
                    return 'suspended'; // fallback for any non-active status
            }
        }
        
        // Only if guest_status is 'active', proceed with the limit checks
        // Daily limit check for host
        $daily_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table
            WHERE host_member_id = %d AND DATE(visit_date) = %s",
            $host_member_id, $visit_date
        ));
        
        // Monthly limit check for guest
        $monthly_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table
            WHERE guest_id = %d AND MONTH(visit_date) = MONTH(%s) AND YEAR(visit_date) = YEAR(%s)",
            $guest_id, $visit_date, $visit_date
        ));
        
        // Yearly limit check for guest
        $yearly_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table
            WHERE guest_id = %d AND YEAR(visit_date) = YEAR(%s)",
            $guest_id, $visit_date
        ));
        
        // Determine status based on limits (only when guest_status is 'active')
        if ($daily_count >= 4) {
            return 'unapproved';
        } elseif ($monthly_count >= 4 || $yearly_count >= 24) {
            return 'suspended';
        }
        
        return 'approved';
    }

    /**
     * Club Registration AJAX Handler
     */
    public static function handle_club_registration() 
    {
        self::verify_ajax_request(); 
        global $wpdb;

        // Sanitize input data
        $club_name   = sanitize_text_field($_POST['club_name'] ?? '');
        $club_email  = sanitize_email($_POST['club_email'] ?? '');
        $club_phone  = sanitize_text_field($_POST['club_phone'] ?? '');
        $club_website= esc_url_raw($_POST['club_website'] ?? '');        
        $notes       = sanitize_textarea_field($_POST['notes'] ?? '');
        $status      = sanitize_text_field($_POST['status'] ?? 'active');

        // Validation
        $errors = [];
        if (empty($club_name)) {
            $errors[] = 'Club name is required.';
        }
        if (strlen($club_name) > 255) {
            $errors[] = 'Club name must be less than 255 characters.';
        }
        if (!empty($club_email) && !is_email($club_email)) {
            $errors[] = 'Invalid email address.';
        }

        // Check for duplicate club name
        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        $existing_club = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$clubs_table} WHERE club_name = %s",
            $club_name
        ));
        if ($existing_club > 0) {
            $errors[] = 'A club with this name already exists.';
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

        // Prepare data for insertion
        $club_data = [
            'club_name'  => $club_name,
            'club_email' => $club_email,
            'club_phone' => $club_phone,
            'club_website' => $club_website,
            'notes'      => $notes,
            'status'     => in_array($status, ['active','suspended','banned']) ? $status : 'active',
            'created_at' => current_time('mysql'),
        ];

        // Insert into database
        $result = $wpdb->insert(
            $clubs_table,
            $club_data,
            ['%s','%s','%s','%s','%s','%s','%s']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to create club. Please try again.']]);
        }

        $club_id = $wpdb->insert_id;
        $new_club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));

        if (!$new_club) {
            wp_send_json_error(['messages' => ['Club created but failed to retrieve data.']]);
        }

        wp_send_json_success([
            'messages' => ['Club created successfully!'],
            'clubData' => [
                'id'        => $new_club->id,
                'club_name' => $new_club->club_name,
                'club_email'=> $new_club->club_email,
                'club_phone'=> $new_club->club_phone,
                'status'    => $new_club->status,
                'created_at'=> $new_club->created_at,
                'updated_at'=> $new_club->updated_at,
            ]
        ]);
    }

    /**
     * Club Management AJAX Handlers
     */
    // Get Club Data Handler
    public static function handle_get_club_data() 
    {
        self::verify_ajax_request();
        global $wpdb;

        $club_id = intval($_POST['club_id'] ?? 0);
        if ($club_id <= 0) {
            wp_send_json_error(['messages' => ['Invalid club ID.']]);
        }

        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));

        if (!$club) {
            wp_send_json_error(['messages' => ['Club not found.']]);
        }

        wp_send_json_success([
            'clubData' => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_email' => $club->club_email,
                'club_phone' => $club->club_phone,
                'club_website' => $club->club_website,
                'status' => $club->status,
                'notes' => $club->notes,
                'created_at' => $club->created_at,
            ]
        ]);
    }

    // Update Club Handler
    public static function handle_club_update() 
    {
        self::verify_ajax_request();
        global $wpdb;

        $club_id     = intval($_POST['club_id'] ?? 0);
        $club_name   = sanitize_text_field($_POST['club_name'] ?? '');
        $club_email  = sanitize_email($_POST['club_email'] ?? '');
        $club_phone  = sanitize_text_field($_POST['club_phone'] ?? '');
        $club_website = esc_url_raw($_POST['club_website'] ?? '');
        $club_status = sanitize_text_field($_POST['club_status'] ?? 'active');
        $notes       = sanitize_textarea_field($_POST['notes'] ?? '');

        $errors = [];
        if ($club_id <= 0) $errors[] = 'Invalid club ID.';
        if (empty($club_name)) $errors[] = 'Club name is required.';
        if (strlen($club_name) > 255) $errors[] = 'Club name must be less than 255 characters.';
        if (!in_array($club_status, ['active', 'suspended', 'banned'])) {
            $errors[] = 'Invalid status selected.';
        }

        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        // Check duplicate name
        $existing_club = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$clubs_table} WHERE club_name = %s AND id != %d",
            $club_name, $club_id
        ));
        if ($existing_club > 0) $errors[] = 'A club with this name already exists.';

        // Check existence
        $current_club = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));
        if (!$current_club) $errors[] = 'Club not found.';

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

        // Update
        $result = $wpdb->update(
            $clubs_table,
            [
                'club_name'   => $club_name,
                'club_email'  => $club_email,
                'club_phone'  => $club_phone,
                'club_website'=> $club_website,
                'status'      => $club_status,
                'notes'       => $notes,
                'updated_at'  => current_time('mysql')
            ],
            ['id' => $club_id],
            ['%s','%s','%s','%s','%s','%s','%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to update club. Please try again.']]);
        }

        $updated_club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));

        wp_send_json_success([
            'messages' => ['Club updated successfully!'],
            'clubData' => $updated_club
        ]);
    }


    // Delete Club Handler
    public static function handle_delete_club() 
    {
        // Verify nonce
        self::verify_ajax_request();

        global $wpdb;
        
        $club_id = intval($_POST['club_id'] ?? 0);
        
        if ($club_id <= 0) {
            wp_send_json_error([
                'messages' => ['Invalid club ID.']
            ]);
        }
        
        // Check if club exists
        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));
        
        if (!$club) {
            wp_send_json_error([
                'messages' => ['Club not found.']
            ]);
        }
        
        // Optional: Check if club has related records and prevent deletion
        // You can add checks here for related data if needed
        
        // Delete club
        $result = $wpdb->delete(
            $clubs_table,
            ['id' => $club_id],
            ['%d']
        );
        
        if ($result === false) {
            wp_send_json_error([
                'messages' => ['Failed to delete club. Please try again.']
            ]);
        }
        
        if ($result === 0) {
            wp_send_json_error([
                'messages' => ['Club not found or already deleted.']
            ]);
        }
        
        wp_send_json_success([
            'messages' => ['Club deleted successfully!']
        ]);
    }

    /**
     * Helper function to get clubs with pagination
     */
    function get_clubs_paginated($page = 1, $per_page = 25, $search = '') 
    {
        global $wpdb;
        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        
        $offset = ($page - 1) * $per_page;
        $where_clause = '';
        $search_params = [];
        
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clause = " WHERE club_name LIKE %s";
            $search_params[] = $like;
        }
        
        // Count total clubs
        $count_query = "SELECT COUNT(*) FROM {$clubs_table}" . $where_clause;
        if (!empty($search_params)) {
            $total_clubs = $wpdb->get_var($wpdb->prepare($count_query, $search_params));
        } else {
            $total_clubs = $wpdb->get_var($count_query);
        }
        
        // Get clubs
        $query = "SELECT * FROM {$clubs_table}" . $where_clause . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params = array_merge($search_params, [$per_page, $offset]);
        
        $clubs = $wpdb->get_results($wpdb->prepare($query, $params));
        
        return [
            'clubs' => $clubs,
            'total' => $total_clubs,
            'pages' => ceil($total_clubs / $per_page)
        ];
    }

    /**
     * Handle reciprocating member registration via AJAX
     */
    public static function handle_reciprocating_registration(): void
    {
        self::verify_ajax_request();
        error_log('Handle reciprocating member registration');

        global $wpdb;
        $errors = [];

        // Sanitize input
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone_number = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');
        $member_number = sanitize_text_field($_POST['member_number'] ?? '');
        $reciprocating_club_id = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date = sanitize_text_field($_POST['visit_date'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($member_number)) $errors[] = 'Member number is required';
        if (empty($reciprocating_club_id)) $errors[] = 'Reciprocating club is required';

        // Validate visit date format
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
        } else {
            $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
            $current_date = new \DateTime(current_time('Y-m-d'));
            if (!$visit_date_obj || $visit_date_obj < $current_date) {
                $errors[] = 'Visit date cannot be in the past';
            }
        }

        // Validate reciprocating club exists
        $club = null;
        if ($reciprocating_club_id) {
            $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
            $club = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $clubs_table WHERE id = %d",
                $reciprocating_club_id
            ));
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Tables
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);

        // Generate unique reciprocating member number
        $reciprocating_member_number = $member_number; 

        // Check if member already exists by member number
        $existing_member = $wpdb->get_row($wpdb->prepare(
            "SELECT id, member_status FROM $members_table WHERE id_number = %s",
            $member_number
        ));

        if ($existing_member) {
            $member_id = $existing_member->id;
            // Update member info
            $wpdb->update(
                $members_table,
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone_number' => $phone_number,
                    'reciprocating_club_id' => $reciprocating_club_id,
                    'receive_emails' => $receive_emails,
                    'receive_messages' => $receive_messages,
                ],
                ['id' => $member_id],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s'],
                ['%d']
            );
        } else {
            // Create new reciprocating member
            $wpdb->insert(
                $members_table,
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone_number' => $phone_number,
                    'id_number' => $id_number,
                    'reciprocating_member_number' => $reciprocating_member_number,
                    'reciprocating_club_id' => $reciprocating_club_id,
                    'member_status' => 'active',
                    'receive_emails' => $receive_emails,
                    'receive_messages' => $receive_messages,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );
            $member_id = $wpdb->insert_id;
        }

        if (!$member_id) {
            wp_send_json_error(['messages' => ['Failed to create or update reciprocating member record']]);
            return;
        }

        // Prevent duplicate visit on the same date
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table WHERE member_id = %d AND visit_date = %s AND status != 'cancelled'",
            $member_id,
            $visit_date
        ));
        if ($existing_visit) {
            wp_send_json_error(['messages' => ['This reciprocating member already has a visit registered on this date']]);
            return;
        }

        // Check monthly and yearly limits
        $today = date('Y-m-d');
        $month_start = date('Y-m-01', strtotime($visit_date));
        $month_end = date('Y-m-t', strtotime($visit_date));
        $year_start = date('Y-01-01', strtotime($visit_date));
        $year_end = date('Y-12-31', strtotime($visit_date));

        $monthly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table 
            WHERE member_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $member_id, $month_start, $month_end, $today, $today
        ));

        $yearly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table 
            WHERE member_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $member_id, $year_start, $year_end, $today, $today
        ));

        // Check limits
        $monthly_limit = 4;
        $yearly_limit = 24;

        $preliminary_status = 'approved';

        if (($monthly_visits + 1) > $monthly_limit) {
            $preliminary_status = 'suspended';
        } elseif (($yearly_visits + 1) > $yearly_limit) {
            $preliminary_status = 'suspended';
        }

        // Create visit record
        $visit_result = $wpdb->insert(
            $visits_table,
            [
                'member_id' => $member_id,
                'visit_date' => $visit_date,
                'status' => $preliminary_status
            ],
            ['%d', '%s', '%s']
        );

        if ($visit_result === false) {
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        $visit_id = $wpdb->insert_id;

        // Send notifications
        self::send_reciprocating_registration_emails(
            $member_id,
            $first_name,
            $last_name,
            $email,
            $receive_emails,
            $club,
            $visit_date,
            $preliminary_status,
            $reciprocating_member_number
        );
        
        self::send_reciprocating_registration_sms(
            $member_id,
            $first_name,
            $last_name,
            $phone_number,
            $receive_messages,
            $club,
            $visit_date,
            $preliminary_status,
            $reciprocating_member_number
        );

        // Prepare member data for response
        $member_data = [
            'id' => $member_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone_number' => $phone_number,
            'id_number' => $id_number,
            'reciprocating_member_number' => $reciprocating_member_number,
            'reciprocating_club_id' => $reciprocating_club_id,
            'club_name' => $club ? $club->club_name : 'N/A',
            'visit_date' => $visit_date,
            'receive_emails' => $receive_emails,
            'receive_messages' => $receive_messages,
            'status' => $preliminary_status,
            'member_status' => 'active',
            'sign_in_time' => null,
            'sign_out_time' => null,
            'visit_id' => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Reciprocating member registered successfully'],
            'memberData' => $member_data
        ]);
    }

    /**
     * Send registration email notifications
     */
    private static function send_reciprocating_registration_emails($member_id, $first_name, $last_name, $email, $receive_emails, $club, $visit_date, $status, $reciprocating_member_number): void
    {
        if ($receive_emails !== 'yes') {
            return;
        }

        $site_name = get_bloginfo('name');
        $club_name = $club ? $club->club_name : 'N/A';
        $formatted_date = date('M j, Y', strtotime($visit_date));

        $subject = "Reciprocating Member Registration - {$site_name}";
        
        $message = "Dear {$first_name} {$last_name},\n\n";
        $message .= "Your reciprocating membership registration has been processed successfully.\n\n";
        $message .= "Registration Details:\n";
        $message .= "Member Number: {$reciprocating_member_number}\n";
        $message .= "Club: {$club_name}\n";
        $message .= "Visit Date: {$formatted_date}\n";
        $message .= "Status: " . ucfirst($status) . "\n\n";

        if ($status === 'suspended') {
            $message .= "NOTE: Your visit has been suspended due to exceeding monthly or yearly limits.\n\n";
        }

        $message .= "Please present this member number when visiting.\n\n";
        $message .= "Best regards,\n";
        $message .= "{$site_name} Management Team";

        error_log("Sending reciprocating registration email to: {$email}");
        wp_mail($email, $subject, $message);
    }

    /**
     * Send registration SMS notifications
     */
    private static function send_reciprocating_registration_sms($member_id, $first_name, $last_name, $phone_number, $receive_messages, $club, $visit_date, $status, $reciprocating_member_number): void
    {
        if ($receive_messages !== 'yes') {
            return;
        }

        $site_name = get_bloginfo('name');
        $club_name = $club ? $club->club_name : 'Club';
        $formatted_date = date('M j', strtotime($visit_date));

        $sms_message = "{$site_name}: Hello {$first_name}, ";
        $sms_message .= "reciprocating membership registered. ";
        $sms_message .= "Number: {$reciprocating_member_number}. ";
        $sms_message .= "Club: {$club_name}. ";
        $sms_message .= "Visit: {$formatted_date}. ";
        $sms_message .= "Status: " . ucfirst($status) . ".";

        error_log("Sending reciprocating registration SMS to: {$phone_number}");
        
        if (class_exists('VMS_NotificationManager')) {
            VMS_NotificationManager::send_sms(
                $phone_number,
                $sms_message,
                $member_id,
                'reciprocating_member'
            );
        }
    }

    /**
     * Handle reciprocating member sign in
     */
    public static function handle_reciprocating_sign_in(): void
    {
        self::verify_ajax_request();
    
        $visit_id = absint($_POST['visit_id'] ?? 0);
        if (!$visit_id) {
            wp_send_json_error(['message' => 'Invalid visit ID']);
            return;
        }
        
        global $wpdb;
        $visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        
        // Get visit and member details
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, m.first_name, m.last_name, m.phone_number, m.receive_messages, m.member_status
            FROM $visits_table v
            JOIN $members_table m ON v.member_id = m.id
            WHERE v.id = %d",
            $visit_id
        ));
        
        if (!$visit) {
            wp_send_json_error(['message' => 'Visit not found']);
            return;
        }
        
        // Check if member and status allow sign in
        if ($visit->member_status !== 'active' || $visit->status !== 'approved') {
            wp_send_json_error(['message' => 'Cannot sign in - member or visit not active/approved']);
            return;
        }
        
        // Update sign in time
        $result = $wpdb->update(
            $visits_table,
            ['sign_in_time' => current_time('mysql')],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to sign in']);
            return;
        }
        
        // Send sign in SMS
        self::send_reciprocating_sign_in_sms($visit);
        
        // Return success with member data for sign out button
        wp_send_json_success([
            'message' => 'Signed in successfully',
            'memberData' => [
                'id' => $visit->member_id,
                'visit_id' => $visit_id,
                'first_name' => $visit->first_name,
                'last_name' => $visit->last_name
            ]
        ]);
    }

    /**
     * Handle reciprocating member sign out
     */
    public static function handle_reciprocating_sign_out(): void
    {
        self::verify_ajax_request();
        
        $visit_id = absint($_POST['visit_id'] ?? 0);
        if (!$visit_id) {
            wp_send_json_error(['message' => 'Invalid visit ID']);
            return;
        }

        global $wpdb;
        $visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        // Get visit and member details
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, m.first_name, m.last_name, m.phone_number, m.receive_messages
            FROM $visits_table v 
            JOIN $members_table m ON v.member_id = m.id 
            WHERE v.id = %d AND v.sign_in_time IS NOT NULL",
            $visit_id
        ));

        if (!$visit) {
            wp_send_json_error(['message' => 'Visit not found or not signed in']);
            return;
        }

        if (empty($visit->sign_in_time)) {
            wp_send_json_error(['messages' => ['Reciprocating Member must be signed in first']]);
            return;
        }

        if (!empty($visit->sign_out_time)) {
            wp_send_json_error(['messages' => ['Reciprocating Member already signed out']]);
            return;
        }

        $signout_time = current_time('mysql');

        // Update sign out time
        $result = $wpdb->update(
            $visits_table,
            ['sign_out_time' => $signout_time],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to sign out']);
            return;
        }

        // Send sign out SMS
        self::send_reciprocating_sign_out_sms($visit);

        // Prepare response data
        $recip_data_response = [
            'first_name' => $visit->first_name,
            'last_name' => $visit->last_name,
            'sign_in_time' => $visit->sign_in_time,
            'sign_out_time' => $signout_time,
            'visit_id' => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Reciprocating member signed out successfully'],
            'recipData' => $recip_data_response
        ]);
    }

    /**
     * Send sign in SMS notification
     */
    private static function send_reciprocating_sign_in_sms($visit): void
    {
        if ($visit->receive_messages !== 'yes') {
            return;
        }

        $site_name = get_bloginfo('name');
        $sign_in_time = date('g:i A', strtotime(current_time('mysql')));
        
        $sms_message = "{$site_name}: Hello {$visit->first_name}, ";
        $sms_message .= "you have signed in successfully at {$sign_in_time}. ";
        $sms_message .= "Enjoy your visit!";

        if (class_exists('VMS_NotificationManager')) {
            VMS_NotificationManager::send_sms(
                $visit->phone_number,
                $sms_message,
                $visit->member_id,
                'reciprocating_member'
            );
        }
    }

    /**
     * Send sign out SMS notification
     */
    private static function send_reciprocating_sign_out_sms($visit): void
    {
        if ($visit->receive_messages !== 'yes') {
            return;
        }

        $site_name = get_bloginfo('name');
        $sign_out_time = date('g:i A', strtotime(current_time('mysql')));
        
        $sms_message = "{$site_name}: Hello {$visit->first_name}, ";
        $sms_message .= "you have signed out at {$sign_out_time}. ";
        $sms_message .= "Thank you for visiting!";

        if (class_exists('VMS_NotificationManager')) {
            VMS_NotificationManager::send_sms(
                $visit->phone_number,
                $sms_message,
                $visit->member_id,
                'reciprocating_member'
            );
        }
    }


    /**
     * Handle guest registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public static function handle_guest_registration(): void
    {
        self::verify_ajax_request();
        error_log('Handle guest registration');

        global $wpdb;

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $host_member_id   = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($host_member_id)) $errors[] = 'Host member is required';

        // Validate visit date format
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
        } else {
            $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
            $current_date = new \DateTime(current_time('Y-m-d'));
            if (!$visit_date_obj || $visit_date_obj < $current_date) {
                $errors[] = 'Visit date cannot be in the past';
            }
        }

        // Validate host member exists
        $host_member = $host_member_id ? get_user_by('id', $host_member_id) : null;
        if ($host_member_id && !$host_member) {
            $errors[] = 'Invalid host member selected';
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Tables
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Check if guest exists by phone number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_status FROM $guests_table WHERE phone_number = %s",
            $phone_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            // Update guest info (excluding guest_status)
            // $wpdb->update(
            //     $guests_table,
            //     [
            //         'first_name'       => $first_name,
            //         'last_name'        => $last_name,
            //         'email'            => $email,
            //         'phone_number'     => $phone_number,
            //         'receive_emails'   => $receive_emails,
            //         'receive_messages' => $receive_messages,
            //     ],
            //     ['id' => $guest_id],
            //     ['%s', '%s', '%s', '%s', '%s', '%s'],
            //     ['%d']
            // );
        } else {
            // Create new guest
            $wpdb->insert(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,                   
                    'guest_status'     => 'active'
                ],
                ['%s','%s','%s','%s','%s','%s','%s','%s']
            );
            $guest_id = $wpdb->insert_id;
        }

        if (!$guest_id) {
            wp_send_json_error(['messages' => ['Failed to create or update guest record']]);
            return;
        }

        // Prevent duplicate visit on the same date
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
            $guest_id,
            $visit_date
        ));
        if ($existing_visit) {
            wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
            return;
        }

        // Calculate monthly and yearly visits (only counting approved visits and past sign-ins)
        $month_start = date('Y-m-01', strtotime($visit_date));
        $month_end   = date('Y-m-t', strtotime($visit_date));
        $year_start  = date('Y-01-01', strtotime($visit_date));
        $year_end    = date('Y-12-31', strtotime($visit_date));
        $today = date('Y-m-d');

        $monthly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $month_start, $month_end, $today, $today
        ));

        $yearly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $year_start, $year_end, $today, $today
        ));

        // Check monthly/yearly limits
        $monthly_limit = 4;
        $yearly_limit  = 12;

        if ($monthly_visits >= $monthly_limit) {
            wp_send_json_error(['messages' => ['This guest has reached the monthly visit limit']]);
            return;
        }

        if ($yearly_visits >= $yearly_limit) {
            wp_send_json_error(['messages' => ['This guest has reached the yearly visit limit']]);
            return;
        }

        // Check host daily limit (only count approved visits for today and future)
        $host_approved_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE host_member_id = %d AND visit_date = %s 
            AND (status = 'approved' OR (visit_date < %s AND sign_in_time IS NOT NULL))",
            $host_member_id,
            $visit_date,
            $today
        ));

        // Determine preliminary status
        $preliminary_status = 'approved';
        
        // If guest limits would be exceeded, mark as unapproved
        if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit) {
            $preliminary_status = 'unapproved';
        }
        
        // If host daily limit would be exceeded, mark as unapproved
        if (($host_approved_count + 1) > 4) {
            $preliminary_status = 'unapproved';
        }

        $visit_result = $wpdb->insert(
            $guest_visits_table,
            [
                'guest_id'       => $guest_id,
                'host_member_id' => $host_member_id,
                'visit_date'     => $visit_date,
                'status'         => $preliminary_status
            ],
            ['%d','%d','%s','%s','%s']
        );

        if ($visit_result === false) {
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        // Fetch final guest status
        $final_guest_data = $wpdb->get_row($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));
        
        // Send SMS notifications
        self::send_guest_registration_sms(
            $guest_id,
            $first_name,
            $last_name,
            $phone_number,
            $receive_messages,
            $host_member,
            $visit_date,
            $preliminary_status
        );
        // Prepare guest data for JS
        $guest_data = [
            'id'              => $guest_id,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'phone_number'    => $phone_number,
            'host_member_id'  => $host_member_id,
            'host_name'       => $host_member ? $host_member->display_name : 'N/A',
            'visit_date'      => $visit_date,
            'receive_emails'  => $receive_emails,
            'receive_messages'=> $receive_messages,
            'status'          => $preliminary_status,
            'guest_status'    => $final_guest_data->guest_status ?? 'active',
            'sign_in_time'    => null,
            'sign_out_time'   => null,
            'visit_id'        => $wpdb->insert_id
        ];

        wp_send_json_success([
            'messages'  => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
    }

    /**
     * Handle employee registration via AJAX - UPDATED WITH EMAIL & SMS NOTIFICATIONS
     */
    public static function handle_employee_registration(): void
    {
        self::verify_ajax_request();
        
        $errors = [];

        // Sanitize and validate input
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone_number = sanitize_text_field($_POST['phone_number'] ?? '');
        $user_role = sanitize_text_field($_POST['user_role'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($user_role)) $errors[] = 'User role is required';

        // Check if email already exists
        if (email_exists($email)) {
            $errors[] = 'Email address already exists';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Generate user_login as firstname.lastname in lowercase
        $user_login = strtolower($first_name . '.' . $last_name);
        $user_login = sanitize_user($user_login, true);

        // Ensure username is unique
        $original_login = $user_login;
        $counter = 1;
        while (username_exists($user_login)) {
            $user_login = $original_login . $counter;
            $counter++;
        }

        // Generate a strong password
        $password = wp_generate_password(12, true, true);

        // Prepare user data
        $user_data = [
            'user_login' => $user_login,
            'user_email' => $email,
            'user_pass' => $password,
            'role' => $user_role,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'meta_input' => [
                'phone_number' => $phone_number,
                'registration_status' => 'active',
                'receive_emails' => $receive_emails,
                'receive_messages' => $receive_messages,
                'show_admin_bar_front' => 'false'
            ]
        ];

        // Create the user
        $user_id = wp_insert_user(wp_slash($user_data));

        if (is_wp_error($user_id)) {
            $error_code = array_key_first($user_id->errors);
            $error_message = $user_id->errors[$error_code][0];
            wp_send_json_error(['messages' => [$error_message]]);
            return;
        }

        // Send email notification to the new employee
        self::send_employee_welcome_email(
            $email,
            $first_name,
            $last_name,
            $user_login,
            $password
        );

        // Send SMS notification to the new employee
        self::send_employee_welcome_sms(
            $phone_number,
            $first_name,
            $user_login,
            $password,
            $user_id
        );

        // Send notification to admin
        self::send_admin_employee_notification(
            $first_name,
            $last_name,
            $email,
            $user_role
        );

        // Prepare employee data for response
        $employee_data = [
            'id' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone_number' => $phone_number,
            'user_role' => $user_role,
            'user_login' => $user_login,
            'registration_status' => 'active'
        ];

        wp_send_json_success([
            'messages' => ['Employee registered successfully'],
            'employeeData' => $employee_data
        ]);
    }

    /**
     * Send welcome email to new employee
     */
    private static function send_employee_welcome_email($email, $first_name, $last_name, $user_login, $password): void
    {
        $login_url = home_url('/login');
        $site_name = get_bloginfo('name');
        
        $subject = "Welcome to {$site_name} - Your Employee Account";
        
        $message = "Dear {$first_name} {$last_name},\n\n";
        $message .= "Welcome to {$site_name}! Your employee account has been created successfully.\n\n";
        $message .= "Your login credentials are:\n";
        $message .= "Username: {$user_login}\n";
        $message .= "Password: {$password}\n\n";
        $message .= "Login URL: {$login_url}\n\n";
        $message .= "Important Security Notes:\n";
        $message .= "- Please keep these credentials secure and confidential\n";
        $message .= "- We recommend changing your password after your first login\n";
        $message .= "- Never share your login credentials with anyone\n\n";
        $message .= "If you have any questions or need assistance, please contact the administrator.\n\n";
        $message .= "Best regards,\n";
        $message .= "{$site_name} Management Team";

        error_log("Sending welcome email to: {$email}");
        error_log("Email content: {$message}");

        wp_mail($email, $subject, $message);
    }

    /**
     * Send welcome SMS to new employee
     */
    private static function send_employee_welcome_sms($phone_number, $first_name, $user_login, $password, $user_id): void
    {
        $site_name = get_bloginfo('name');
        $login_url = home_url('/login');;
        
        // SMS message needs to be concise due to character limits
        $sms_message = "{$site_name}: Hello {$first_name}, ";
        $sms_message .= "your employee account is ready. ";
        $sms_message .= "Username: {$user_login}, ";
        $sms_message .= "Password: {$password}. ";
        $sms_message .= "Login: {$login_url}. ";
        $sms_message .= "Change password after first login.";

        error_log("Sending SMS to: {$phone_number}");
        error_log("SMS content: {$sms_message}");

        // Use your notification manager to send SMS
        if (class_exists('VMS_NotificationManager')) {
            VMS_NotificationManager::send_sms(
                $phone_number, 
                $sms_message, 
                $user_id, 
                'employee'
            );
        } else {
            error_log("VMS_NotificationManager class not found - SMS not sent");
        }
    }

    /**
     * Send notification to admin about new employee registration
     */
    private static function send_admin_employee_notification($first_name, $last_name, $email, $user_role): void
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        // Convert role key to readable format
        $role_names = [
            'general_manager' => 'General Manager',
            'gate' => 'Gate Officer',
            'reception' => 'Reception Staff'
        ];
        $readable_role = isset($role_names[$user_role]) ? $role_names[$user_role] : ucwords(str_replace('_', ' ', $user_role));
        
        $subject = "New Employee Account Created - {$site_name}";
        
        $message = "Hello Administrator,\n\n";
        $message .= "A new employee account has been successfully created in the system.\n\n";
        $message .= "Employee Details:\n";
        $message .= "Name: {$first_name} {$last_name}\n";
        $message .= "Email: {$email}\n";
        $message .= "Role: {$readable_role}\n";
        $message .= "Status: Active\n";
        $message .= "Registration Date: " . date('F j, Y \a\t g:i A') . "\n\n";
        $message .= "The employee has been automatically sent their login credentials via both email and SMS.\n\n";
        $message .= "You can manage this employee account through the admin dashboard.\n\n";
        $message .= "Best regards,\n";
        $message .= "{$site_name} Visitor Management System";

        error_log("Sending admin notification to: {$admin_email}");
        error_log("Admin notification content: {$message}");

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Handle courtesy guest registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public static function handle_courtesy_guest_registration(): void
    {
        self::verify_ajax_request();
        error_log('Handle courtesy guest registration');

        global $wpdb;

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $courtesy         = 'Courtesy';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';

        // Validate visit date format
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
        } else {
            $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
            $current_date = new \DateTime(current_time('Y-m-d'));
            if (!$visit_date_obj || $visit_date_obj < $current_date) {
                $errors[] = 'Visit date cannot be in the past';
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Tables
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Check if guest exists by phone number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_status FROM $guests_table WHERE phone_number = %s",
            $phone_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            // Update guest info (excluding guest_status)
            // $wpdb->update(
            //     $guests_table,
            //     [
            //         'first_name'       => $first_name,
            //         'last_name'        => $last_name,
            //         'email'            => $email,
            //         'phone_number'     => $phone_number,
            //         'receive_emails'   => $receive_emails,
            //         'receive_messages' => $receive_messages,
            //     ],
            //     ['id' => $guest_id],
            //     ['%s', '%s', '%s', '%s', '%s', '%s'],
            //     ['%d']
            // );
        } else {
            // Create new guest
            $wpdb->insert(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => 'yes',
                    'receive_messages' => 'yes',                   
                    'guest_status'     => 'active'
                ],
                ['%s','%s','%s','%s','%s','%s','%s','%s']
            );
            $guest_id = $wpdb->insert_id;
        }

        if (!$guest_id) {
            wp_send_json_error(['messages' => ['Failed to create or update guest record']]);
            return;
        }

        // Prevent duplicate visit on the same date
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
            $guest_id,
            $visit_date
        ));
        if ($existing_visit) {
            wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
            return;
        }     

        // Determine preliminary status
        $preliminary_status = 'approved';     

        $visit_result = $wpdb->insert(
            $guest_visits_table,
            [
                'guest_id'       => $guest_id,
                'visit_date'     => $visit_date,
                'courtesy'       => $courtesy,
                'status'         => $preliminary_status
            ],
            ['%d','%s','%s','%s']
        );

        if ($visit_result === false) {
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        // Fetch final guest status
        $final_guest_data = $wpdb->get_row($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        // Send SMS notifications
        self::send_courtesy_guest_registration_sms(
            $guest_id,
            $first_name,
            $last_name,
            $phone_number,
            $receive_messages,
            $visit_date,
            $preliminary_status
        );
        
        // Prepare guest data for JS
        $guest_data = [
            'id'              => $guest_id,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'phone_number'    => $phone_number,
            'visit_date'      => $visit_date,
            'courtesy'        => $courtesy,
            'receive_emails'  => $receive_emails,
            'receive_messages'=> $receive_messages,
            'status'          => $preliminary_status,
            'guest_status'    => $final_guest_data->guest_status ?? 'active',
            'sign_in_time'    => null,
            'sign_out_time'   => null,
            'visit_id'        => $wpdb->insert_id
        ];

        wp_send_json_success([
            'messages'  => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
    }

    /**
     * Handle visit registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public static function handle_visit_registration() 
    {
        global $wpdb;

        $guest_id       = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
        $host_member_id = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date     = sanitize_text_field($_POST['visit_date'] ?? '');
        $courtesy       = sanitize_text_field($_POST['courtesy'] ?? '');

        $errors = [];

        // Validate guest
        if ($guest_id <= 0) {
            $errors[] = 'Guest is required';
        } else {
            $guest_exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}vms_guests WHERE id = %d", $guest_id)
            );
            if (!$guest_exists) {
                $errors[] = 'Invalid guest selected';
            }
        }

        // Validate host
        $host_member = null;
        if ($host_member_id) {
            $host_member = get_userdata($host_member_id);
            if (!$host_member) {
                $errors[] = 'Invalid host member selected';
            }
        }

        // Validate visit date
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
        } else {
            $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
            $current_date   = new \DateTime(current_time('Y-m-d'));
            if (!$visit_date_obj || $visit_date_obj < $current_date) {
                $errors[] = 'Visit date cannot be in the past';
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

        $table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get guest info for emails
        $guest_info = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name, email, phone_number, receive_emails, receive_messages 
            FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        // Prevent duplicate visit on same date (except cancelled)
        $existing_visit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d AND visit_date = %s",
            $guest_id,
            $visit_date
        ));

        if ($existing_visit && $existing_visit->status !== 'cancelled') {
            wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
        }

        // Monthly and yearly limits: count only approved visits and past sign-ins
        $month_start = date('Y-m-01', strtotime($visit_date));
        $month_end   = date('Y-m-t', strtotime($visit_date));
        $year_start  = date('Y-01-01', strtotime($visit_date));
        $year_end    = date('Y-12-31', strtotime($visit_date));
        $today = date('Y-m-d');

        $monthly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $month_start, $month_end, $today, $today
        ));

        $yearly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $year_start, $year_end, $today, $today
        ));

        $monthly_limit = 4;
        $yearly_limit  = 12;

        if ($monthly_visits >= $monthly_limit) {
            wp_send_json_error(['messages' => ['This guest has reached the monthly visit limit']]);
        }
        if ($yearly_visits >= $yearly_limit) {
            wp_send_json_error(['messages' => ['This guest has reached the yearly visit limit']]);
        }

        // Check host daily limit (only count approved visits for today and future)
        $host_approved_count = 0;
        if ($host_member_id) {
            $host_approved_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table 
                WHERE host_member_id = %d AND visit_date = %s 
                AND (status = 'approved' OR (visit_date < %s AND sign_in_time IS NOT NULL))",
                $host_member_id,
                $visit_date,
                $today
            ));
        }

        // Determine preliminary status
        $preliminary_status = 'approved';
        
        // If guest limits would be exceeded, mark as unapproved
        if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit) {
            $preliminary_status = 'unapproved';
        }
        
        // If host daily limit would be exceeded, mark as unapproved
        if ($host_member_id && ($host_approved_count + 1) > 4) {
            $preliminary_status = 'unapproved';
        }

        if ($existing_visit && $existing_visit->status === 'cancelled') {
            // Reuse cancelled row
            $updated = $wpdb->update(
                $table,
                [
                    'host_member_id' => $host_member_id,
                    'courtesy'       => $courtesy,
                    'status'         => $preliminary_status,
                    'sign_in_time'   => null,
                    'sign_out_time'  => null,
                ],
                ['id' => $existing_visit->id],
                ['%d','%s','%s','%s','%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['messages' => ['Failed to update cancelled visit']]);
            }

            $visit_id = $existing_visit->id;
        } else {
            // Insert new row
            $inserted = $wpdb->insert(
                $table,
                [
                    'guest_id'       => $guest_id,
                    'host_member_id' => $host_member_id,
                    'visit_date'     => $visit_date,
                    'courtesy'       => $courtesy,
                    'status'         => $preliminary_status
                ],
                ['%d','%d','%s','%s','%s']
            );        

            if (!$inserted) {
                wp_send_json_error(['messages' => ['Failed to register visit']]);
            }

            $visit_id = $wpdb->insert_id;
        }

        // Send notifications
        if ($guest_info) {
            if ($courtesy === 'Courtesy') {
                // Email
                self::send_courtesy_visit_registration_emails(
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->email,
                    $guest_info->receive_emails,
                    $visit_date,
                    $preliminary_status
                );

                // SMS
                self::send_courtesy_visit_registration_sms(
                    $guest_id,
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->phone_number,
                    $guest_info->receive_messages,
                    $visit_date,
                    $preliminary_status
                );

            } else {
                // Email
                self::send_visit_registration_emails(
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->email,
                    $guest_info->receive_emails,
                    $host_member,
                    $visit_date,
                    $preliminary_status
                );

                // SMS
                self::send_visit_registration_sms(
                    $guest_id,
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->phone_number,
                    $guest_info->receive_messages,
                    $host_member,
                    $visit_date,
                    $preliminary_status
                );
            }
        }


        $visit = $wpdb->get_row("SELECT * FROM $table WHERE id = $visit_id");

        // Host display name
        $host_display = 'N/A';
        if (!empty($visit->host_member_id)) {
            $host_user = get_userdata($visit->host_member_id);
            if ($host_user) {
                $first = get_user_meta($visit->host_member_id, 'first_name', true);
                $last  = get_user_meta($visit->host_member_id, 'last_name', true);
                $host_display = (!empty($first) || !empty($last)) ? trim($first . ' ' . $last) : $host_user->user_login;
            }
        }

        // Status fields
        $status       = self::get_visit_status($visit->visit_date, $visit->sign_in_time, $visit->sign_out_time);
        $status_class = self::get_status_class($status);
        $status_text  = self::get_status_text($status);

        wp_send_json_success([
            'id'            => $visit->id,
            'host_display'  => $host_display,
            'visit_date'    => self::format_date($visit->visit_date),
            'sign_in_time'  => self::format_time($visit->sign_in_time),
            'sign_out_time' => self::format_time($visit->sign_out_time),
            'duration'      => self::calculate_duration($visit->sign_in_time, $visit->sign_out_time),
            'status'        => $preliminary_status,
            'status_class'  => $status_class,
            'status_text'   => $status_text,
            'messages'      => ['Visit registered successfully']
        ]);
    }

    /**
     * Handle visit registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public static function handle_reciprocation_member_visit_registration() 
    {
        global $wpdb;

        $member_id  = isset($_POST['member_id']) ? absint($_POST['member_id']) : 0;
        $visit_date = sanitize_text_field($_POST['visit_date'] ?? '');

        $errors = [];

        // Validate member
        if ($member_id <= 0) {
            $errors[] = 'Member is required';
        } else {
            $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
            $member_exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $recip_members_table WHERE id = %d", $member_id)
            );
            if (!$member_exists) {
                $errors[] = 'Invalid member selected';
            }
        }
        
        // Validate visit date
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
        } else {
            $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
            $current_date   = new \DateTime(current_time('Y-m-d'));
            if (!$visit_date_obj || $visit_date_obj < $current_date) {
                $errors[] = 'Visit date cannot be in the past';
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

        $table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        // Get member info for emails
        $member_info = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name, email, phone_number, receive_emails, receive_messages 
            FROM $members_table WHERE id = %d",
            $member_id
        ));

        // Prevent duplicate visit on same date (except cancelled)
        $existing_visit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE member_id = %d AND visit_date = %s",
            $member_id,
            $visit_date
        ));

        if ($existing_visit && $existing_visit->status !== 'cancelled') {
            wp_send_json_error(['messages' => ['This member already has a visit registered on this date']]);
        }

        // Monthly and yearly limits: count only approved visits and past sign-ins
        $month_start = date('Y-m-01', strtotime($visit_date));
        $month_end   = date('Y-m-t', strtotime($visit_date));
        $year_start  = date('Y-01-01', strtotime($visit_date));
        $year_end    = date('Y-12-31', strtotime($visit_date));
        $today = date('Y-m-d');

        $monthly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE member_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $member_id, $month_start, $month_end, $today, $today
        ));

        $yearly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE member_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $member_id, $year_start, $year_end, $today, $today
        ));

        $monthly_limit = 4;
        $yearly_limit  = 12;

        if ($monthly_visits >= $monthly_limit) {
            wp_send_json_error(['messages' => ['This member has reached the monthly visit limit']]);
        }
        if ($yearly_visits >= $yearly_limit) {
            wp_send_json_error(['messages' => ['This member has reached the yearly visit limit']]);
        }

        // Determine preliminary status
        $preliminary_status = 'approved';
        
        // If member limits would be exceeded, mark as unapproved
        if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit) {
            $preliminary_status = 'unapproved';
        }

        if ($existing_visit && $existing_visit->status === 'cancelled') {
            // Reuse cancelled row
            $updated = $wpdb->update(
                $table,
                [
                    'status'         => $preliminary_status,
                    'courtesy'       => $courtesy,
                    'sign_in_time'   => null,
                    'sign_out_time'  => null,
                ],
                ['id' => $existing_visit->id],
                ['%s','%s','%s','%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['messages' => ['Failed to update cancelled visit']]);
            }

            $visit_id = $existing_visit->id;
        } else {
            // Insert new row
            $inserted = $wpdb->insert(
                $table,
                [
                    'member_id'      => $member_id,
                    'courtesy'       => $courtesy,
                    'visit_date'     => $visit_date,
                    'status'         => $preliminary_status
                ],
                ['%d','%s','%s','%s']
            );        

            if (!$inserted) {
                wp_send_json_error(['messages' => ['Failed to register visit']]);
            }

            $visit_id = $wpdb->insert_id;
        }

        // Send notifications
        if ($member_info) {            
            // Email
            self::send_member_visit_registration_emails(
                $member_info->first_name,
                $member_info->last_name,
                $member_info->email,
                $member_info->receive_emails,
                $visit_date,
                $preliminary_status
            );

            // SMS
            self::send_member_visit_registration_sms(
                $member_id,
                $member_info->first_name,
                $member_info->last_name,
                $member_info->phone_number,
                $member_info->receive_messages,
                $visit_date,
                $preliminary_status
            );            
        }

        $visit = $wpdb->get_row("SELECT * FROM $table WHERE id = $visit_id");

        // Status fields
        $status       = self::get_visit_status($visit->visit_date, $visit->sign_in_time, $visit->sign_out_time);
        $status_class = self::get_status_class($status);
        $status_text  = self::get_status_text($status);

        wp_send_json_success([
            'id'            => $visit->id,
            'visit_date'    => self::format_date($visit->visit_date),
            'sign_in_time'  => self::format_time($visit->sign_in_time),
            'sign_out_time' => self::format_time($visit->sign_out_time),
            'duration'      => self::calculate_duration($visit->sign_in_time, $visit->sign_out_time),
            'status'        => $preliminary_status,
            'status_class'  => $status_class,
            'status_text'   => $status_text,
            'messages'      => ['Visit registered successfully']
        ]);
    }

    /**
     * Send SMS notifications for reciprocating member visit registration
     */
    private static function send_member_visit_registration_sms($member_id, $first_name, $last_name, $member_phone, $member_receive_messages, $visit_date, $status) 
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'Approved' : 'Pending Approval';

        // Send SMS to member if opted in
        if ($member_receive_messages === 'yes' && !empty($member_phone)) {
            $member_message = "Dear " . $first_name . ",\nYour reciprocating member visit on $formatted_date is $status_text.";
            $role = 'reciprocating_member';
            
            if ($status === 'approved') {
                $member_message .= " Please carry a valid ID and your reciprocating membership card.";
            } else {
                $member_message .= " You will be notified once approved.";
            }

            error_log("Sending SMS to member ID $member_id at $member_phone: $member_message");

            VMS_NotificationManager::send_sms($member_phone, $member_message, $member_id, $role);
        }

        // Send SMS to admin/management
        $admin_users = get_users(['role' => 'administrator']);
        foreach ($admin_users as $admin) {
            $admin_receive_messages = get_user_meta($admin->ID, 'receive_messages', true);
            $admin_phone = get_user_meta($admin->ID, 'phone_number', true);
            $admin_first_name = get_user_meta($admin->ID, 'first_name', true);

            if ($admin_receive_messages === 'yes' && !empty($admin_phone)) {
                $admin_message = "Dear " . $admin_first_name . ",\nReciprocating member $first_name $last_name has registered for $formatted_date. Status: $status_text.";
                
                if ($status !== 'approved') {
                    $admin_message .= " Requires approval due to limits.";
                }

                error_log("Sending SMS to admin ID {$admin->ID} at $admin_phone: $admin_message");

                VMS_NotificationManager::send_sms($admin_phone, $admin_message, $admin->ID, 'administrator');
            }
        }
    }

    /**
     * Send email notifications for reciprocating member visit registration
     */
    private static function send_member_visit_registration_emails($first_name, $last_name, $member_email, $member_receive_emails, $visit_date, $status)
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'approved' : 'pending approval';

        // Send email to member if they opted in
        if ($member_receive_emails === 'yes' && !empty($member_email)) {
            $member_subject = 'Reciprocating Member Visit Registration - Nyeri Club';
            
            $member_message = "Dear " . $first_name . ",\n\n";
            $member_message .= "Your reciprocating member visit to Nyeri Club has been registered successfully.\n\n";
            $member_message .= "Visit Details:\n";
            $member_message .= "Date: " . $formatted_date . "\n";
            $member_message .= "Status: " . ucfirst($status_text) . "\n\n";
            
            if ($status === 'approved') {
                $member_message .= "Your visit has been approved. Please present a valid ID and your reciprocating membership card when you arrive.\n\n";
            } else {
                $member_message .= "Your visit is currently pending approval. You will receive another email once approved.\n\n";
            }
            
            $member_message .= "Thank you for visiting Nyeri Club.\n\n";
            $member_message .= "Best regards,\n";
            $member_message .= "Nyeri Club Visitor Management System";

            wp_mail($member_email, $member_subject, $member_message);
        }

        // Send email to admin/management
        $admin_users = get_users(['role' => 'administrator']);
        foreach ($admin_users as $admin) {
            $admin_receive_emails = get_user_meta($admin->ID, 'receive_emails', true);
            $admin_first_name = get_user_meta($admin->ID, 'first_name', true);
            $admin_last_name = get_user_meta($admin->ID, 'last_name', true);
            
            if ($admin_receive_emails === 'yes') {
                $admin_subject = 'New Reciprocating Member Visit Registration - Nyeri Club';
                
                $admin_message = "Dear " . $admin_first_name . " " . $admin_last_name . ",\n\n";
                $admin_message .= "A reciprocating member has registered for a visit.\n\n";
                $admin_message .= "Member Details:\n";
                $admin_message .= "Name: " . $first_name . " " . $last_name . "\n";
                $admin_message .= "Visit Date: " . $formatted_date . "\n";
                $admin_message .= "Status: " . ucfirst($status_text) . "\n\n";
                
                if ($status === 'approved') {
                    $admin_message .= "The visit has been approved automatically.\n\n";
                } else {
                    $admin_message .= "The visit is pending approval due to capacity limits. Please review and approve if appropriate.\n\n";
                }
                
                $admin_message .= "Best regards,\n";
                $admin_message .= "Nyeri Club Visitor Management System";

                wp_mail($admin->user_email, $admin_subject, $admin_message);
            }
        }
    }

    /**
     * Send SMS notifications for guest registration with host
     */
    private static function send_guest_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $host_member, $visit_date, $status) 
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'Approved' : 'Pending Approval';
        $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
        $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);

        // Send SMS to guest if opted in
        if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
            $guest_message = "Dear " . $first_name . ",\nYou have been booked as a visitor at Nyeri Club by " . $host_first_name . " " . $host_last_name . ". Your visit registered for $formatted_date is $status_text.";
            $role = 'guest';
            if ($status === 'approved') {
                $guest_message .= " Please present a valid ID or Passport upon arrival at the Club.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id, $role);
        }

        // Send SMS to host if opted in
        if ($host_member) {
            $host_receive_messages = get_user_meta($host_member->ID, 'receive_messages', true);
            $host_phone            = get_user_meta($host_member->ID, 'phone_number', true);
            $host_first_name       = get_user_meta($host_member->ID, 'first_name', true);
            $roles                 = $host_member->roles ?? [];
            $role                  = !empty($roles) ? $roles[0] : 'member';

            if ($host_receive_messages === 'yes' && !empty($host_phone)) {
                $host_message = "Dear " . $host_first_name . ",\nYour guest $first_name $last_name has been registered for $formatted_date. Status: $status_text.";
                if ($status === 'approved') {
                    $host_message .= " Please be available to receive them.";
                } else {
                    $host_message .= " Pending approval due to limits.";
                }

                VMS_NotificationManager::send_sms($host_phone, $host_message, $host_member->ID, $role);
            }
        }
    }
    
    /**
     * Send SMS notifications for courtesy guest registration (no host involved)
     */
    private static function send_courtesy_guest_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $visit_date, $status ): void 
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text    = ($status === 'approved') ? 'Approved' : 'Pending Approval';

        // Send SMS to courtesy guest if opted in       
        if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
            $guest_message = "Dear " . $first_name . ",\nYou have been booked as a visitor at Nyeri Club. Your visit registered for $formatted_date is $status_text.";
            $role = 'guest';
            if ($status === 'approved') {
                $guest_message .= " Please present a valid ID or Passport upon arrival at the Club.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id, $role);
        }
    }


    private static function send_visit_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $host_member, $visit_date, $status ): void 
    {
        if ($guest_receive_messages !== 'yes' || empty($guest_phone)) {
            return;
        }

        $formatted_date  = date('F j, Y', strtotime($visit_date));
        $status_text     = ($status === 'approved') ? 'Approved' : 'Pending Approval';
        $role            = 'guest';
        $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
        $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);

        
        $guest_message = "Dear " . $first_name . ",\nYou have been booked as a visitor at Nyeri Club by " . $host_first_name . " " . $host_last_name . ". Your visit registered for $formatted_date is $status_text.";
        $role = 'guest';
        if ($status === 'approved') {
            $guest_message .= " Please present a valid ID or Passport upon arrival at the Club.";
        } else {
            $guest_message .= " You will be notified once approved.";
        }

        VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id, $role);        

         // Send SMS to host if opted in
        if ($host_member) {
            $host_receive_messages = get_user_meta($host_member->ID, 'receive_messages', true);
            $host_phone            = get_user_meta($host_member->ID, 'phone_number', true);
            $host_first_name       = get_user_meta($host_member->ID, 'first_name', true);
            $roles                 = $host_member->roles ?? [];
            $role                  = !empty($roles) ? $roles[0] : 'member';

            if ($host_receive_messages === 'yes' && !empty($host_phone)) {
                $host_message = "Dear " . $host_first_name . ",\nYour guest $first_name $last_name has been registered for $formatted_date. Status: $status_text.";
                if ($status === 'approved') {
                    $host_message .= " Please be available to receive them.";
                } else {
                    $host_message .= " Pending approval due to limits.";
                }

                VMS_NotificationManager::send_sms($host_phone, $host_message, $host_member->ID, $role);
            }
        }
    }

    /**
     * Send email notifications for visit registration
     */
    private static function send_visit_registration_emails($guest_first_name, $guest_last_name, $guest_email, $guest_receive_emails, $host_member, $visit_date, $status)
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'approved' : 'pending approval';
        $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
        $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);

        // Send email to guest if they opted in
        if ($guest_receive_emails === 'yes') {
            $guest_subject = 'Visit Registration Confirmation - Nyeri Club';
            
            $guest_message = "Dear " . $guest_first_name . ",\n\n";
            $guest_message .= "Your visit to Nyeri Club has been registered successfully.\n\n";
            $guest_message .= "Visit Details:\n";
            $guest_message .= "Date: " . $formatted_date . "\n";
            if ($host_member) {
                $guest_message .= "Host: " . $host_first_name . " " . $host_last_name . "\n";
            }
            $guest_message .= "Status: " . ucfirst($status_text) . "\n\n";
            
            if ($status === 'approved') {
                $guest_message .= "Your visit has been approved. Please present a valid ID when you arrive.\n\n";
            } else {
                $guest_message .= "Your visit is currently pending approval. You will receive another email once approved.\n\n";
            }
            
            $guest_message .= "Thank you for choosing Nyeri Club.\n\n";
            $guest_message .= "Best regards,\n";
            $guest_message .= "Nyeri Club Visitor Management System";

            error_log($guest_message);

            wp_mail($guest_email, $guest_subject, $guest_message);
        }

        // Send email to host if they opted in to receive emails
        if ($host_member) {
            $host_receive_emails = get_user_meta($host_member->ID, 'receive_emails', true);
            
            if ($host_receive_emails === 'yes') {
                $host_subject = 'New Visit Registration - Nyeri Club';
                
                $host_message = "Dear " . $host_first_name . " " . $host_last_name . ",\n\n";
                $host_message .= "A visit has been registered with you as the host.\n\n";
                $host_message .= "Guest Details:\n";
                $host_message .= "Name: " . $guest_first_name . " " . $guest_last_name . "\n";
                $host_message .= "Visit Date: " . $formatted_date . "\n";
                $host_message .= "Status: " . ucfirst($status_text) . "\n\n";
                
                if ($status === 'approved') {
                    $host_message .= "The visit has been approved. Please ensure you are available to receive your guest.\n\n";
                } else {
                    $host_message .= "The visit is pending approval due to capacity limits.\n\n";
                }
                
                $host_message .= "Best regards,\n";
                $host_message .= "Nyeri Club Visitor Management System";

                error_log($host_message);

                wp_mail($host_member->user_email, $host_subject, $host_message);
            }
        }
    }

    /**
     * Send email notifications for courtesy visit registration (no host)
     */
    private static function send_courtesy_visit_registration_emails($guest_first_name, $guest_last_name, $guest_email, $guest_receive_emails, $visit_date, $status)
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'approved' : 'pending approval';

        // Send email to guest if they opted in
        if ($guest_receive_emails === 'yes') {
            $guest_subject = 'Courtesy Visit Registration Confirmation - Nyeri Club';
            
            $guest_message = "Dear " . $guest_first_name . ",\n\n";
            $guest_message .= "Your courtesy visit to Nyeri Club has been registered successfully.\n\n";
            $guest_message .= "Visit Details:\n";
            $guest_message .= "Date: " . $formatted_date . "\n";
            $guest_message .= "Type: Courtesy Visit\n";
            $guest_message .= "Status: " . ucfirst($status_text) . "\n\n";
            
            if ($status === 'approved') {
                $guest_message .= "Your visit has been approved. Please present a valid ID when you arrive.\n\n";
            } else {
                $guest_message .= "Your visit is currently pending approval. You will receive another email once approved.\n\n";
            }
            
            $guest_message .= "Thank you for choosing Nyeri Club.\n\n";
            $guest_message .= "Best regards,\n";
            $guest_message .= "Nyeri Club Visitor Management System";

            wp_mail($guest_email, $guest_subject, $guest_message);
        }

        // Send email to admin for courtesy visits
        $admin_email = get_option('admin_email');
        $admin_subject = 'New Courtesy Visit Registration - Nyeri Club';
        
        $admin_message = "Hello Admin,\n\n";
        $admin_message .= "A new courtesy visit has been registered:\n\n";
        $admin_message .= "Guest Details:\n";
        $admin_message .= "Name: " . $guest_first_name . " " . $guest_last_name . "\n";
        $admin_message .= "Email: " . $guest_email . "\n";
        $admin_message .= "Visit Date: " . $formatted_date . "\n";
        $admin_message .= "Type: Courtesy Visit\n";
        $admin_message .= "Status: " . ucfirst($status_text) . "\n\n";
        $admin_message .= "Please review this registration in the system.\n\n";
        $admin_message .= "Nyeri Club Visitor Management System";

        wp_mail($admin_email, $admin_subject, $admin_message);
    }

    /**
     * Updated calculate_preliminary_visit_status function
     */
    private static function calculate_preliminary_visit_status(int $guest_id, string $visit_date): string
    {
        global $wpdb;
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $monthly_limit = 4;
        $yearly_limit = 12;
        $today = date('Y-m-d');
        
        // Monthly and yearly date ranges
        $month_start = date('Y-m-01', strtotime($visit_date));
        $month_end   = date('Y-m-t', strtotime($visit_date));
        $year_start  = date('Y-01-01', strtotime($visit_date));
        $year_end    = date('Y-12-31', strtotime($visit_date));

        // Count approved visits for future dates and attended visits for past dates
        $monthly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $month_start, $month_end, $today, $today
        ));

        $yearly_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
            AND (
                (status = 'approved' AND visit_date >= %s) OR
                (visit_date < %s AND sign_in_time IS NOT NULL)
            )",
            $guest_id, $year_start, $year_end, $today, $today
        ));

        // If adding this visit would exceed limits, mark as unapproved
        if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit) {
            return 'unapproved';
        }

        return 'approved';
    }

    // Handle member update via AJAX
    public static function handle_member_update() 
    {
        // Verify nonce
        self::verify_ajax_request();

        error_log('Handle member update');

        $errors = [];

        // Sanitize input
        $member_id            = sanitize_text_field($_POST['member_id'] ?? '');
        $first_name           = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name            = sanitize_text_field($_POST['last_name'] ?? '');
        $email                = sanitize_email($_POST['email'] ?? '');
        $phone_number         = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number            = sanitize_text_field($_POST['id_number'] ?? '');
        $reciprocating_member_number = sanitize_text_field($_POST['reciprocating_member_number'] ?? '');
        $member_status        = sanitize_text_field($_POST['member_status'] ?? 'active');
        $receive_messages     = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails       = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($member_id)) $errors[] = 'Member ID is required';
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';
        if (empty($reciprocating_member_number)) $errors[] = 'Member number is required';

        // Validate status
        $valid_statuses = ['active', 'suspended', 'banned'];
        if (!in_array($member_status, $valid_statuses)) {
            $errors[] = 'Invalid member status';
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        global $wpdb;
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        // Fetch existing member before update
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $members_table WHERE id = %d",
            $member_id
        ));

        if (!$member) {
            wp_send_json_error(['messages' => ['Member not found']]);
            return;
        }

        // Save old and new status
        $old_status = $member->member_status;
        $new_status = $member_status;

        // Check if ID number is already used by another member
        $id_number_exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $members_table WHERE id_number = %s AND id != %d",
            $id_number, $member_id
        ));

        if ($id_number_exists) {
            wp_send_json_error(['messages' => ['ID number is already in use by another member']]);
            return;
        }

        // Check if member number is already used by another member
        $member_number_exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $members_table WHERE reciprocating_member_number = %s AND id != %d",
            $reciprocating_member_number, $member_id
        ));

        if ($member_number_exists) {
            wp_send_json_error(['messages' => ['Member number is already in use by another member']]);
            return;
        }

        // Check if email is already used by another member
        $email_exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $members_table WHERE email = %s AND id != %d",
            $email, $member_id
        ));

        if ($email_exists) {
            wp_send_json_error(['messages' => ['Email is already in use by another member']]);
            return;
        }

        // Update member
        $result = $wpdb->update(
            $members_table,
            [
                'first_name'                  => $first_name,
                'last_name'                   => $last_name,
                'email'                       => $email,
                'phone_number'                => $phone_number,
                'id_number'                   => $id_number,
                'reciprocating_member_number' => $reciprocating_member_number,
                'member_status'               => $new_status,
                'receive_messages'            => $receive_messages,
                'receive_emails'              => $receive_emails,
                'updated_at'                  => current_time('mysql')
            ],
            ['id' => $member_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to update member record']]);
            return;
        }

        // Build member data array
        $member_data = [
            'first_name'       => $first_name,
            'last_name'        => $last_name,
            'phone_number'     => $phone_number,
            'email'            => $email,
            'receive_messages' => $receive_messages,
            'receive_emails'   => $receive_emails,
            'user_id'          => $member_id,
            'reciprocating_member_number' => $reciprocating_member_number
        ];

        // Send notifications if status changed
        if ($old_status !== $new_status) {
            self::send_member_status_change_email($member_data, $old_status, $new_status);
            self::send_member_status_change_sms($member_data, $old_status, $new_status);
        }

        wp_send_json_success([
            'message' => 'Member updated successfully'
        ]);
    }

    // Handle member deletion via AJAX
    public static function handle_member_deletion() 
    {
        // Verify nonce
        self::verify_ajax_request();

        $member_id = isset($_POST['member_id']) ? absint($_POST['member_id']) : 0;

        error_log('Delete member ID: ' . $member_id);

        if (empty($member_id)) {
            wp_send_json_error(['messages' => ['Member ID is required']]);
            return;
        }

        global $wpdb;
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);

        // Check if member exists
        $existing_member = $wpdb->get_row($wpdb->prepare(
            "SELECT id, first_name, last_name FROM $members_table WHERE id = %d",
            $member_id
        ));

        if (!$existing_member) {
            wp_send_json_error(['messages' => ['Member not found']]);
            return;
        }

        // Check if member has any visits
        $visit_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table WHERE member_id = %d",
            $member_id
        ));

        if ($visit_count > 0) {
            wp_send_json_error(['messages' => ['Cannot delete member with existing visit records. Please archive the member instead.']]);
            return;
        }

        // Delete the member
        $result = $wpdb->delete(
            $members_table,
            ['id' => $member_id],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to delete member record']]);
            return;
        }

        // Log the deletion
        error_log(sprintf(
            'Member deleted: ID=%d, Name=%s %s',
            $member_id,
            $existing_member->first_name,
            $existing_member->last_name
        ));

        wp_send_json_success([
            'message' => 'Member deleted successfully'
        ]);
    }

    // Helper function to send member status change email
    private static function send_member_status_change_email($member_data, $old_status, $new_status)
    {
        // Only send email if member wants to receive emails
        if ($member_data['receive_emails'] !== 'yes') {
            return;
        }

        $to = $member_data['email'];
        $subject = 'Member Status Update';
        
        $status_messages = [
            'active' => 'Your membership status has been activated. You now have full access to all club facilities and services.',
            'suspended' => 'Your membership has been temporarily suspended. Please contact the club administration for more information.',
            'banned' => 'Your membership has been terminated. Please contact the club administration if you believe this is an error.'
        ];

        $message = sprintf(
            "Dear %s %s,\n\nYour membership status has been updated from %s to %s.\n\n%s\n\nBest regards,\nClub Administration",
            $member_data['first_name'],
            $member_data['last_name'],
            ucfirst($old_status),
            ucfirst($new_status),
            $status_messages[$new_status] ?? 'Please contact the club administration for more information.'
        );

        wp_mail($to, $subject, $message);
    }

    // Helper function to send member status change SMS
    private static function send_member_status_change_sms($member_data, $old_status, $new_status)
    {
        // Only send SMS if member wants to receive messages
        if ($member_data['receive_messages'] !== 'yes') {
            return;
        }

        $phone = $member_data['phone_number'];
        
        $status_messages = [
            'active' => 'Your membership has been activated. Welcome back!',
            'suspended' => 'Your membership has been suspended. Please contact us.',
            'banned' => 'Your membership has been terminated. Please contact administration.'
        ];

        $message = sprintf(
            "Hi %s, your membership status changed to %s. %s",
            $member_data['first_name'],
            ucfirst($new_status),
            $status_messages[$new_status] ?? 'Contact us for details.'
        );

        // Implement your SMS sending logic here
        // This could be integration with SMS service like Twilio, Africa's Talking, etc.
        error_log("SMS to {$phone}: {$message}");
    }

    // Handle guest update via AJAX
    public static function handle_guest_update() 
    {
        // Verify nonce
        self::verify_ajax_request();

        error_log('Handle guest update');

        $errors = [];

        // Sanitize input
        $guest_id         = sanitize_text_field($_POST['guest_id'] ?? '');
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $email            = sanitize_email($_POST['email'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number        = sanitize_text_field($_POST['id_number'] ?? '');
        $courtesy         = sanitize_textarea_field($_POST['courtesy'] ?? '');
        $guest_status     = sanitize_text_field($_POST['guest_status'] ?? 'active');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($guest_id)) $errors[] = 'Guest ID is required';
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        // if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        // if (empty($id_number)) $errors[] = 'ID number is required';

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Fetch existing guest before update
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // Save old and new status
        $old_status = $guest->guest_status;
        $new_status = $guest_status;

        // Check if ID number is already used by another guest
        $id_number_exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $guests_table WHERE id_number = %s AND id != %d",
            $id_number, $guest_id
        ));

        if ($id_number_exists) {
            wp_send_json_error(['messages' => ['ID number is already in use by another guest']]);
            return;
        }

        // Update guest
        $result = $wpdb->update(
            $guests_table,
            [
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'email'            => $email,
                'phone_number'     => $phone_number,
                'id_number'        => $id_number,
                'guest_status'     => $new_status,
                'receive_messages' => $receive_messages,
                'receive_emails'   => $receive_emails,
                'updated_at'       => current_time('mysql')
            ],
            ['id' => $guest_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to update guest record']]);
            return;
        }

        // Build guest data array
        $guest_data = [
            'first_name'       => $first_name,
            'phone_number'     => $phone_number,
            'email'            => $email,
            'receive_messages' => $receive_messages,
            'receive_emails'   => $receive_emails,
            'user_id'          => $guest_id
        ];

        // Send notifications if status changed
        if ($old_status !== $new_status) {
            self::send_guest_status_change_email($guest_data, $old_status, $new_status);
            self::send_guest_status_change_sms($guest_data, $old_status, $new_status);
        }

        wp_send_json_success([
            'message' => 'Guest updated successfully'
        ]);
    }


    // Handle guest deletion via AJAX
    public static function handle_guest_deletion() 
    {
        // Verify nonce
        self::verify_ajax_request();

        $guest_id = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;

        error_log($guest_id);

        if (empty($guest_id)) {
            wp_send_json_error(['messages' => ['Guest ID is required']]);
            return;
        }

        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Check if guest exists
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        if (!$existing_guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // First delete all guest visits
        $wpdb->delete(
            $visits_table,
            ['guest_id' => $guest_id],
            ['%d']
        );

        // Then delete the guest
        $result = $wpdb->delete(
            $guests_table,
            ['id' => $guest_id],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to delete guest record']]);
            return;
        }

        wp_send_json_success([
            'message' => 'Guest deleted successfully'
        ]);
    }

    public static function get_guests_by_host($host_member_id) 
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Join to also fetch last visit date if needed
        $sql = $wpdb->prepare("
            SELECT g.*, gv.visit_date, gv.sign_in_time, gv.sign_out_time
            FROM $guests_table g
            LEFT JOIN $guest_visits_table gv ON gv.guest_id = g.id AND gv.host_member_id = %d
            WHERE gv.host_member_id = %d
            ORDER BY g.created_at DESC
        ", $host_member_id, $host_member_id);

        return $wpdb->get_results($sql);
    }

    /**
     * Get paginated guest visits by host member ID
     */
    public static function get_paginated_guest_visits($host_member_id, $per_page = 10, $offset = 0) 
    {
        global $wpdb;
        $guest_visits_table = $wpdb->prefix . 'vms_guest_visits';
        $guests_table = $wpdb->prefix . 'vms_guests';

        $sql = $wpdb->prepare("
            SELECT gv.*, g.first_name, g.last_name, gv.id as visit_id
            FROM $guest_visits_table gv
            LEFT JOIN $guests_table g ON g.id = gv.guest_id
            WHERE gv.host_member_id = %d
            ORDER BY gv.visit_date DESC, gv.created_at DESC
            LIMIT %d OFFSET %d
        ", $host_member_id, $per_page, $offset);

        return $wpdb->get_results($sql);
    }

    /**
     * Count total guest visits for a host
     */
    public static function count_guest_visits($host_member_id) 
    {
        global $wpdb;
        $guest_visits_table = $wpdb->prefix . 'vms_guest_visits';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table WHERE host_member_id = %d",
            $host_member_id
        ));
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

    /**
     * Updated auto_update_visit_statuses to handle cancelled visits properly
     */
    public static function auto_update_visit_statuses(): void
    {
        global $wpdb;
        error_log('Auto update visit statuses');
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $monthly_limit = 4;
        $yearly_limit = 12;
        
        // Get all active guests
        $guest_ids = $wpdb->get_col("SELECT id FROM $guests_table WHERE guest_status = 'active'");
        
        foreach ($guest_ids as $guest_id) {
            // Use the new recalculate function for consistency
            self::recalculate_guest_visit_statuses($guest_id);
        }
    }

    /**
     * Automatically sign out guests at midnight for the current day
     */
    public static function auto_sign_out_guests()
    {
        global $wpdb;
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get current date
        $current_date = current_time('Y-m-d');

        // Query for visits that are signed in but not signed out for the current day
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date 
            FROM $guest_visits_table 
            WHERE sign_in_time IS NOT NULL 
            AND sign_out_time IS NULL 
            AND DATE(visit_date) = %s",
            $current_date
        ));

        if (empty($visits)) {
            return; // No guests to sign out
        }

        foreach ($visits as $visit) {
            // Set sign_out_time to midnight of the visit date
            $midnight = date('Y-m-d 23:59:59', strtotime($visit->visit_date));

            // Update the visit record
            $wpdb->update(
                $guest_visits_table,
                ['sign_out_time' => $midnight],
                ['id' => $visit->id],
                ['%s'],
                ['%d']
            );

            // Re-evaluate guest status
            $guest_status = self::calculate_guest_status(
                $visit->guest_id,
                $visit->host_member_id,
                $visit->visit_date
            );

            // Update guest status if needed
            $wpdb->update(
                $guests_table,
                ['guest_status' => $guest_status], 
                ['id' => $visit->guest_id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Reset monthly guest limits (only for automatically suspended guests)
     */
    public static function reset_monthly_limits()
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Only reset status for guests who are automatically suspended but have active guest_status
        $wpdb->query(
            "UPDATE $guests_table
            SET status = 'approved'
            WHERE status = 'suspended'
            AND guest_status = 'active'"
        );
    }

    /**
     * Reset yearly guest limits (only for automatically suspended guests)
     */
    public static function reset_yearly_limits()
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Only reset status for guests who are automatically suspended but have active guest_status
        $wpdb->query(
            "UPDATE $guests_table
            SET status = 'approved'
            WHERE status = 'suspended'
            AND guest_status = 'active'"
        );
    }

    /**
     * Verify AJAX request (placeholder, implement as needed)
     */
    private static function verify_ajax_request(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'vms')]);
        }

        // Verify if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to perform this action', 'vms')]);
        }

        // Verify user capability (commented out as in original)
        // if (!current_user_can('manage_options')) {
        //     wp_send_json_error([
        //         'messages' => ['Insufficient permissions'],
        //     ]);
        // }
    }
}