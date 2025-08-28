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
        $this->setup_guest_management_hooks();
    }

    /**
     * Setup authentication related hooks
     */
    private function setup_authentication_hooks(): void
    {
        add_action('login_init', [$this, 'handle_custom_login_redirect']);
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
     * Setup guest management related hooks
     */
    private function setup_guest_management_hooks(): void
    {
        // Hook the AJAX actions
        add_action( 'wp_ajax_vms_ajax_test_connection', [$this, 'vms_ajax_test_connection'] );
        add_action( 'wp_ajax_vms_ajax_save_settings', [$this, 'vms_ajax_save_settings'] );
        add_action('wp_ajax_vms_ajax_refresh_balance', [$this, 'vms_ajax_refresh_balance']);

        add_action('wp_ajax_guest_registration', [$this, 'handle_guest_registration']);
        add_action('wp_ajax_courtesy_guest_registration', [$this, 'handle_courtesy_guest_registration']);
        add_action('wp_ajax_update_guest', [$this, 'handle_guest_update']);
        add_action('wp_ajax_delete_guest', [$this, 'handle_guest_deletion']);
        add_action('wp_ajax_register_visit', [$this, 'handle_visit_registration']);

        add_action('wp_ajax_sign_in_guest', [$this, 'handle_sign_in_guest']);
        add_action('wp_ajax_sign_out_guest', [$this, 'handle_sign_out_guest']);
        add_action('auto_update_visit_status_at_midnight', [$this, 'auto_update_visit_statuses']);
        add_action('auto_sign_out_guests_at_midnight', [$this, 'auto_sign_out_guests']);
        add_action('reset_monthly_guest_limits', [$this, 'reset_monthly_limits']);
        add_action('reset_yearly_guest_limits', [$this, 'reset_yearly_limits']);

        // NEW: Add cancellation handler
        add_action('wp_ajax_cancel_visit', [$this, 'handle_visit_cancellation']);
        add_action('wp_ajax_update_guest_status', [$this, 'handle_guest_status_update']);
        add_action('wp_ajax_update_visit_status', [$this, 'handle_visit_status_update']);

        add_action('admin_init', [$this, 'handle_status_setup']);
    }

    /**
     * Setup automatic status URL in settings if not already configured
     */
    public function handle_status_setup(): void
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
    public function handle_custom_login_redirect(): void
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
    public function validate_user_status($user)
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
    public function custom_password_reset_email(string $message, string $key, string $user_login, WP_User $user_data): string
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
    public function restrict_admin_access(): void
    {
        if (!current_user_can('manage_options') && !(defined('DOING_AJAX') && DOING_AJAX)) {
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
     * AJAX handler for saving settings
     */
    public function vms_ajax_save_settings() {
        $this->verify_ajax_request();
        
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
    public function vms_ajax_test_connection() {
        $this->verify_ajax_request();
        
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
    public function vms_ajax_refresh_balance() {
        $this->verify_ajax_request();
        
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
    public function handle_visit_cancellation(): void
    {
        $this->verify_ajax_request();
        
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
        $this->send_visit_cancellation_email($guest_data, $visit_data);

        wp_send_json_success(['messages' => ['Visit cancelled successfully']]);
    }

    /**
     * NEW: Handle guest status update
     */
    public function handle_guest_status_update(): void
    {
        $this->verify_ajax_request();
        
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

        $this->send_guest_status_change_email($guest_data, $old_status, $new_status);

        wp_send_json_success(['messages' => ['Guest status updated successfully']]);
    }

    /**
     * Handle guest sign in via AJAX - UPDATED with notifications
     */
    public function handle_sign_in_guest(): void
    {
        $this->verify_ajax_request();

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
            "SELECT gv.*, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails, g.guest_status
            FROM {$guest_visits_table} gv
            LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
            WHERE gv.id = %d",
            $visit_id
        ));

        if (!$visit) {
            wp_send_json_error(['messages' => ['Visit not found']]);
            return;
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
        $this->send_signin_email_notification($guest_data, $visit_data);

        // Fetch host member name
        $host_member = get_user_by('id', $visit->host_member_id);
        $host_name = $host_member ? $host_member->display_name : 'N/A';

        // Prepare response data
        $guest_data_response = [
            'id' => $visit->guest_id,
            'first_name' => $visit->first_name,
            'last_name' => $visit->last_name,
            'sign_in_time' => $signin_time,
            'visit_id' => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Guest signed in successfully'],
            'guestData' => $guest_data_response
        ]);
    }

    /**
     * Handle guest sign out via AJAX - UPDATED with notifications
     */
    public function handle_sign_out_guest(): void
    {
        $this->verify_ajax_request();

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
        $this->send_signout_email_notification($guest_data, $visit_data);

        // Prepare response data
        $guest_data_response = [
            'id' => $visit->guest_id,
            'first_name' => $visit->first_name,
            'last_name' => $visit->last_name,
            'sign_out_time' => $signout_time,
            'visit_id' => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Guest signed out successfully'],
            'guestData' => $guest_data_response
        ]);
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
    private function send_visit_cancellation_email(array $guest_data, array $visit_data): void
    {
        if ($guest_data['receive_emails'] !== 'yes') {
            return;
        }

        $formatted_date = date('F j, Y', strtotime($visit_data['visit_date']));
        
        $subject = 'Visit Cancellation - Nyeri Club';
        $message = "Dear {$guest_data['first_name']},\n\n";
        $message .= "Your visit to Nyeri Club scheduled for {$formatted_date} has been cancelled.\n\n";
        
        if ($visit_data['host_member_id']) {
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
     * NEW: Send guest status change email notification
     */
    private function send_guest_status_change_email(array $guest_data, string $old_status, string $new_status): void
    {
        if ($guest_data['receive_emails'] !== 'yes') {
            return;
        }

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
     * NEW: Send sign-in email notification
     */
    private function send_signin_email_notification(array $guest_data, array $visit_data): void
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
    private function send_signout_email_notification(array $guest_data, array $visit_data): void
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
    private function calculate_guest_status(int $guest_id, int $host_member_id, string $visit_date): string
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
     * Handle guest registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public function handle_guest_registration(): void
    {
        $this->verify_ajax_request();
        error_log('Handle guest registration');

        global $wpdb;

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $email            = sanitize_email($_POST['email'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number        = sanitize_text_field($_POST['id_number'] ?? '');
        $host_member_id   = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';
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

        // Check if guest already exists by ID number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_status FROM $guests_table WHERE id_number = %s",
            $id_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            // Update guest info (excluding guest_status)
            $wpdb->update(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,
                ],
                ['id' => $guest_id],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Create new guest
            $wpdb->insert(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'id_number'        => $id_number,
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

        // Send email notifications
        $this->send_guest_registration_emails(
            $guest_id,
            $first_name,
            $last_name,
            $email,
            $receive_emails,
            $host_member,
            $visit_date,
            $preliminary_status
        );
        
        // Send SMS notifications
        $this->send_guest_registration_sms(
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
            'email'           => $email,
            'phone_number'    => $phone_number,
            'id_number'       => $id_number,
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
     * Handle courtesy guest registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS
     */
    public function handle_courtesy_guest_registration(): void
    {
        $this->verify_ajax_request();
        error_log('Handle courtesy guest registration');

        global $wpdb;

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $email            = sanitize_email($_POST['email'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number        = sanitize_text_field($_POST['id_number'] ?? '');
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';
        $courtesy         = 'Courtesy';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';

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

        // Check if guest already exists by ID number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_status FROM $guests_table WHERE id_number = %s",
            $id_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            // Update guest info (excluding guest_status)
            $wpdb->update(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,
                ],
                ['id' => $guest_id],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Create new guest
            $wpdb->insert(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'id_number'        => $id_number,
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

        // Send email notifications for courtesy guest (no host)
        $this->send_courtesy_guest_registration_emails(
            $guest_id,
            $first_name,
            $last_name,
            $email,
            $receive_emails,
            $visit_date,
            $preliminary_status
        );

        // Send SMS notifications
        $this->send_courtesy_guest_registration_sms(
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
            'email'           => $email,
            'phone_number'    => $phone_number,
            'id_number'       => $id_number,
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
    public function handle_visit_registration() 
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
                $this->send_courtesy_visit_registration_emails(
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->email,
                    $guest_info->receive_emails,
                    $visit_date,
                    $preliminary_status
                );

                // SMS
                $this->send_courtesy_visit_registration_sms(
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
                $this->send_visit_registration_emails(
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->email,
                    $guest_info->receive_emails,
                    $host_member,
                    $visit_date,
                    $preliminary_status
                );

                // SMS
                $this->send_visit_registration_sms(
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
     * Send SMS notifications for guest registration with host
     */
    private function send_guest_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $host_member, $visit_date, $status) 
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'Approved' : 'Pending Approval';
        $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
        $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);

        // Send SMS to guest if opted in
        if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
            $guest_message = "Nyeri Club: Dear " . $first_name . ",\nYour visit on $formatted_date with host $host_first_name $host_last_name is $status_text.";
            if ($status === 'approved') {
                $guest_message .= " Please carry a valid ID.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id);
        }

        // Send SMS to host if opted in
        if ($host_member) {
            $host_receive_messages = get_user_meta($host_member->ID, 'receive_messages', true);
            $host_phone            = get_user_meta($host_member->ID, 'phone_number', true);
            $host_first_name       = get_user_meta($host_member->ID, 'first_name', true);

            if ($host_receive_messages === 'yes' && !empty($host_phone)) {
                $host_message = "Nyeri Club: Dear " . $host_first_name . ",\nYour guest $first_name $last_name has been registered for $formatted_date. Status: $status_text.";
                if ($status === 'approved') {
                    $host_message .= " Please be available to receive them.";
                } else {
                    $host_message .= " Pending approval due to limits.";
                }

                VMS_NotificationManager::send_sms($host_phone, $host_message, $host_member->ID);
            }
        }
    }

    /**
     * Send email notifications for guest registration with host
     */
    private function send_guest_registration_emails($guest_id, $first_name, $last_name, $guest_email, $guest_receive_emails, $host_member, $visit_date, $status)
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'approved' : 'pending approval';
        $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
        $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);

        // Send email to guest if they opted in
        if ($guest_receive_emails === 'yes') {
            $guest_subject = 'Visit Registration Confirmation - Nyeri Club';
            
            $guest_message = "Dear " . $first_name . ",\n\n";
            $guest_message .= "Your visit to Nyeri Club has been registered successfully.\n\n";
            $guest_message .= "Visit Details:\n";
            $guest_message .= "Date: " . $formatted_date . "\n";
            $guest_message .= "Host: " . $host_first_name . " " . $host_last_name . "\n";
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
            $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
            $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);            
            
            if ($host_receive_emails === 'yes') {
                $host_subject = 'New Guest Registration - Nyeri Club';
                
                $host_message = "Dear " . $host_first_name . " " . $host_last_name . ",\n\n";
                $host_message .= "A guest has registered for a visit with you as their host.\n\n";
                $host_message .= "Guest Details:\n";
                $host_message .= "Name: " . $first_name . " " . $last_name . "\n";
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
     * Send SMS notifications for courtesy guest registration (no host involved)
     */
    private function send_courtesy_guest_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $visit_date, $status ): void 
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text    = ($status === 'approved') ? 'Approved' : 'Pending Approval';

        // Send SMS to courtesy guest if opted in
        if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
            $guest_message  = "Nyeri Club: Dear {$first_name},\n";
            $guest_message .= "Your courtesy visit on {$formatted_date} is {$status_text}.";

            if ($status === 'approved') {
                $guest_message .= " Please carry a valid ID.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id);
        }
    }


    /**
     * Send email notifications for courtesy guest registration (no host)
     */
    private function send_courtesy_guest_registration_emails($guest_id, $first_name, $last_name, $guest_email, $guest_receive_emails, $visit_date, $status)
    {
        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text = ($status === 'approved') ? 'approved' : 'pending approval';

        // Send email to guest if they opted in
        if ($guest_receive_emails === 'yes') {
            $guest_subject = 'Courtesy Visit Registration Confirmation - Nyeri Club';
            
            $guest_message = "Dear " . $first_name . ",\n\n";
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

            error_log($guest_message);

            wp_mail($guest_email, $guest_subject, $guest_message);
        }

        // Send email to admin for courtesy visits
        $admin_email = get_option('admin_email');
        $admin_subject = 'New Courtesy Guest Registration - Nyeri Club';
        
        $admin_message = "Hello Admin,\n\n";
        $admin_message .= "A new courtesy guest has registered:\n\n";
        $admin_message .= "Guest Details:\n";
        $admin_message .= "Name: " . $first_name . " " . $last_name . "\n";
        $admin_message .= "Email: " . $guest_email . "\n";
        $admin_message .= "Visit Date: " . $formatted_date . "\n";
        $admin_message .= "Type: Courtesy Visit\n";
        $admin_message .= "Status: " . ucfirst($status_text) . "\n\n";
        $admin_message .= "Please review this registration in the system.\n\n";
        $admin_message .= "Nyeri Club Visitor Management System";

        error_log($admin_message);

        wp_mail($admin_email, $admin_subject, $admin_message);
    }

    private function send_visit_registration_sms( $guest_id, $first_name, $last_name, $guest_phone, $guest_receive_messages, $host_member, $visit_date, $status ): void 
    {
        if ($guest_receive_messages !== 'yes' || empty($guest_phone)) {
            return;
        }

        $formatted_date = date('F j, Y', strtotime($visit_date));
        $status_text    = ($status === 'approved') ? 'Approved' : 'Pending Approval';

        $guest_message  = "Nyeri Club: Dear {$first_name},\n";
        $guest_message .= "Your visit on {$formatted_date} is {$status_text}.";

        if ($host_member) {
            $host_name = trim(get_user_meta($host_member->ID, 'first_name', true) . ' ' . get_user_meta($host_member->ID, 'last_name', true));
            if (!$host_name) {
                $host_name = $host_member->user_login;
            }
            $guest_message .= " Hosted by {$host_name}.";
        }

        if ($status === 'approved') {
            $guest_message .= " Please carry a valid ID.";
        } else {
            $guest_message .= " You will be notified once approved.";
        }

        VMS_NotificationManager::send_sms($guest_phone, $guest_message, $guest_id);
    }

    /**
     * Send email notifications for visit registration
     */
    private function send_visit_registration_emails($guest_first_name, $guest_last_name, $guest_email, $guest_receive_emails, $host_member, $visit_date, $status)
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
    private function send_courtesy_visit_registration_emails($guest_first_name, $guest_last_name, $guest_email, $guest_receive_emails, $visit_date, $status)
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

    // Handle guest update via AJAX
    function handle_guest_update() 
    {
        // Verify nonce
        $this->verify_ajax_request();

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
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Check if guest exists
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        if (!$existing_guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

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
                'guest_status'     => $guest_status,
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

        wp_send_json_success([
            'message' => 'Guest updated successfully'
        ]);
    }

    // Handle guest deletion via AJAX
    function handle_guest_deletion() 
    {
        // Verify nonce
        $this->verify_ajax_request();

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
    public function auto_sign_out_guests()
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
            $guest_status = $this->calculate_guest_status(
                $visit->guest_id,
                $visit->host_member_id,
                $visit->visit_date
            );

            // Update guest status if needed
            $wpdb->update(
                $guests_table,
                ['status' => $guest_status],
                ['id' => $visit->guest_id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Reset monthly guest limits (only for automatically suspended guests)
     */
    public function reset_monthly_limits()
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
    public function reset_yearly_limits()
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
    private function verify_ajax_request(): void
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