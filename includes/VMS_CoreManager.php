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
     * Setup guest management related hooks
     */
    private function setup_guest_management_hooks(): void
    {
        add_action('wp_ajax_guest_registration', [$this, 'handle_guest_registration']);
        add_action('wp_ajax_update_guest', [$this, 'handle_guest_update']);
        add_action('wp_ajax_delete_guest', [$this, 'handle_guest_deletion']);
        add_action('wp_ajax_register_visit', [$this, 'handle_visit_registration']);
        add_action('wp_ajax_nopriv_register_visit', [$this, 'handle_visit_registration']);

        add_action('wp_ajax_sign_in_guest', [$this, 'handle_sign_in_guest']);
        add_action('wp_ajax_sign_out_guest', [$this, 'handle_sign_out_guest']);
        add_action('auto_sign_out_guests_at_midnight', [$this, 'auto_sign_out_guests']);
        add_action('reset_monthly_guest_limits', [$this, 'reset_monthly_limits']);
        add_action('reset_yearly_guest_limits', [$this, 'reset_yearly_limits']);
    }

    /**
     * Handle custom login page redirect
     */
    public function handle_custom_login_redirect(): void
    {
        if (!is_user_logged_in() && !wp_doing_ajax()) {
            if (isset($_GET['action']) && $_GET['action'] === 'rp' && isset($_GET['key'], $_GET['login'])) {
                wp_redirect(site_url('/password-reset/?key=' . urlencode($_GET['key']) . '&login=' . urlencode($_GET['login'])));
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
    public function validate_user_status($user)
    {
        if (is_wp_error($user)) {
            return $user;
        }

        $status = get_user_meta($user->ID, 'registration_status', true);

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
     * Handle guest registration via AJAX
     */
    public function handle_guest_registration(): void
    {
        $this->verify_ajax_request();

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $email            = sanitize_email($_POST['email'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number        = sanitize_text_field($_POST['id_number'] ?? '');
        $host_member_id   = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $courtesy         = sanitize_textarea_field($_POST['courtesy'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';
        if (empty($host_member_id)) $errors[] = 'Host member is required';

        // Validate date format and ensure it's not in the past
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

        global $wpdb;

        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Check if guest already exists by ID number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, guest_status FROM $guests_table WHERE id_number = %s",
            $id_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;

            // Update existing guest info (but don't change guest_status - that's manual)
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
                    'status'           => 'approved',
                    'guest_status'     => 'active'
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $guest_id = $wpdb->insert_id;
        }

        if (!$guest_id) {
            wp_send_json_error(['messages' => ['Failed to create or update guest record']]);
            return;
        }

        // Check guest limits and determine status
        $visit_date_mysql = date('Y-m-d', strtotime($visit_date));
        $status = $this->calculate_guest_status($guest_id, $host_member_id, $visit_date_mysql);

        // Update guest status
        $wpdb->update(
            $guests_table,
            ['status' => $status],
            ['id' => $guest_id],
            ['%s'],
            ['%d']
        );

        // Check if guest can visit (banned guests cannot visit at all)
        if ($status === 'banned') {
            wp_send_json_error(['messages' => ['This guest is banned and cannot visit']]);
            return;
        }

        // Add visit record
        $visit_result = $wpdb->insert(
            $guest_visits_table,
            [
                'guest_id'       => $guest_id,
                'host_member_id' => $host_member_id,
                'visit_date'     => $visit_date_mysql,
                'courtesy'       => $courtesy
            ],
            ['%d', '%d', '%s', '%s']
        );

        if ($visit_result === false) {
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        // Get the final guest_status for response
        $final_guest_data = $wpdb->get_row($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        // Prepare guest data for response
        $guest_data = [
            'id'              => $guest_id,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'email'           => $email,
            'phone_number'    => $phone_number,
            'id_number'       => $id_number,
            'host_member_id'  => $host_member_id,
            'host_name'       => $host_member ? $host_member->display_name : 'N/A',
            'visit_date'      => $visit_date_mysql,
            'courtesy'        => $courtesy,
            'receive_emails'  => $receive_emails,
            'receive_messages' => $receive_messages,
            'status'          => $status,
            'guest_status'    => $final_guest_data->guest_status ?? 'active',
            'sign_in_time'    => null,
            'sign_out_time'   => null,
            'visit_id'        => $wpdb->insert_id
        ];

        wp_send_json_success([
            'messages' => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
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

    /**
     * Handle visit registration via AJAX
     */
    public function handle_visit_registration() 
    {
        global $wpdb;

        $guest_id           = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
        $host_member_id     = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date         = sanitize_text_field($_POST['visit_date'] ?? '');
        $courtesy           = sanitize_text_field($_POST['courtesy'] ?? '');

        $errors = [];

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

        if (empty($visit_date)) {
            $errors[] = 'Visit date is required';
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => implode(', ', $errors)]);
        }

        $table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $inserted = $wpdb->insert(
            $table,
            [
                'guest_id'      => $guest_id,
                'host_member_id'=> $host_member_id,
                'visit_date'    => $visit_date,
                'courtesy'      => $courtesy,
            ],
            ['%d','%d','%s','%s']
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'Database insert failed']);
        }

        $visit_id = $wpdb->insert_id;
        $visit    = $wpdb->get_row("SELECT * FROM $table WHERE id = $visit_id");

        // --- format fields with your helper functions ---
        $host_display = 'N/A';
        if (!empty($visit->host_member_id)) {
            $host_user = get_userdata($visit->host_member_id);
            if ($host_user) {
                $first = get_user_meta($visit->host_member_id, 'first_name', true);
                $last  = get_user_meta($visit->host_member_id, 'last_name', true);
                $host_display = (!empty($first) || !empty($last)) ? trim($first . ' ' . $last) : $host_user->user_login;
            }
        }

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
            'status_class'  => $status_class,
            'status_text'   => $status_text,
        ]);
    }

    public static function get_guests_by_host($host_member_id) {
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
     * Build URL for pagination links (query-string only)
     */
    public static function build_pagination_url($page) {
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
    public static function build_per_page_url() {
        $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI']));
        // Remove any existing 'per_page' and 'paged' params
        $current_url = remove_query_arg(['per_page', 'paged'], $current_url);
        // Add the placeholder for 'per_page'; the value will be appended by JS onchange
        return add_query_arg(['per_page' => ''], $current_url);
    }

    
    /**
     * Build URL for sorting (if you need sorting functionality)
     */
    public static function build_sort_url($column) {
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
    public static function format_date($date) {
        if (!$date || $date === '0000-00-00') {
            return 'N/A';
        }
        return date('M j, Y', strtotime($date));
    }
    
    /**
     * Format time for display
     */
    public static function format_time($datetime) {
        if (!$datetime || $datetime === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date('g:i A', strtotime($datetime));
    }
    
    /**
     * Calculate duration between two times
     */
    public static function calculate_duration($sign_in_time, $sign_out_time) {
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
    public static function get_visit_status($visit_date, $sign_in_time, $sign_out_time) {
        $today = date('Y-m-d');
        $now = new \DateTime();
        
        if ($visit_date > $today) {
            return 'scheduled';
        } elseif (empty($sign_in_time) && !empty($visit_date) && $visit_date < $today) {
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
    public static function get_status_class($status) {
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
    public static function get_status_text($status) {
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
     * Handle guest sign in via AJAX
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

        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date, sign_in_time FROM $guest_visits_table WHERE id = %d",
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

        // Fetch guest data
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $visit->guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // Re-evaluate guest status
        $guest_status = $this->calculate_guest_status($visit->guest_id, $visit->host_member_id, $visit->visit_date);
        
        // Update the guest status in the database
        $wpdb->update(
            $guests_table,
            ['status' => $guest_status],
            ['id' => $visit->guest_id],
            ['%s'],
            ['%d']
        );

        // Check if guest can sign in (banned or suspended guests cannot sign in)
        if ($guest_status === 'banned' || $guest_status === 'suspended') {
            wp_send_json_error(['messages' => ['Guest access is restricted due to status: ' . $guest_status]]);
            return;
        }

        // Check if visit date is today (guests can only sign in on their visit date)
        $current_date = current_time('Y-m-d');
        $visit_date = date('Y-m-d', strtotime($visit->visit_date));
        
        if ($visit_date !== $current_date) {
            wp_send_json_error(['messages' => ['Guest can only sign in on their scheduled visit date']]);
            return;
        }

        // Update sign-in time
        $updated = $wpdb->update(
            $guest_visits_table,
            ['sign_in_time' => current_time('mysql')],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to sign in guest']]);
            return;
        }

        // Fetch host member name
        $host_member = get_user_by('id', $visit->host_member_id);
        $host_name = $host_member ? $host_member->display_name : 'N/A';

        // Prepare guest data for response
        $guest_data = [
            'id'              => $visit->guest_id,
            'first_name'      => $guest->first_name,
            'last_name'       => $guest->last_name,
            'email'           => $guest->email,
            'phone_number'    => $guest->phone_number,
            'id_number'       => $guest->id_number,
            'host_member_id'  => $visit->host_member_id,
            'host_name'       => $host_name,
            'visit_date'      => $visit->visit_date,
            'courtesy'        => $guest->courtesy,
            'receive_emails'  => $guest->receive_emails,
            'receive_messages' => $guest->receive_messages,
            'status'          => $guest_status,
            'guest_status'    => $guest->guest_status,
            'sign_in_time'    => current_time('mysql'),
            'sign_out_time'   => null,
            'visit_id'        => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Guest signed in successfully'],
            'guestData' => $guest_data
        ]);
    }

    /**
     * Handle guest sign out via AJAX
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

        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date, sign_in_time, sign_out_time FROM $guest_visits_table WHERE id = %d",
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

        // Fetch guest data
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $visit->guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // Update sign-out time
        $updated = $wpdb->update(
            $guest_visits_table,
            ['sign_out_time' => current_time('mysql')],
            ['id' => $visit_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['messages' => ['Failed to sign out guest']]);
            return;
        }

        // Fetch host member name
        $host_member = get_user_by('id', $visit->host_member_id);
        $host_name = $host_member ? $host_member->display_name : 'N/A';

        // Prepare guest data for response
        $guest_data = [
            'id'              => $visit->guest_id,
            'first_name'      => $guest->first_name,
            'last_name'       => $guest->last_name,
            'email'           => $guest->email,
            'phone_number'    => $guest->phone_number,
            'id_number'       => $guest->id_number,
            'host_member_id'  => $visit->host_member_id,
            'host_name'       => $host_name,
            'visit_date'      => $visit->visit_date,
            'courtesy'        => $guest->courtesy,
            'receive_emails'  => $guest->receive_emails,
            'receive_messages' => $guest->receive_messages,
            'status'          => $guest->status,
            'guest_status'    => $guest->guest_status,
            'sign_in_time'    => $visit->sign_in_time,
            'sign_out_time'   => current_time('mysql'),
            'visit_id'        => $visit_id
        ];

        wp_send_json_success([
            'messages' => ['Guest signed out successfully'],
            'guestData' => $guest_data
        ]);
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