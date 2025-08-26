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
        add_action('wp_ajax_guest_registration', [$this, 'handle_guest_registration']);
        add_action('wp_ajax_update_guest', [$this, 'handle_guest_update']);
        add_action('wp_ajax_delete_guest', [$this, 'handle_guest_deletion']);
        add_action('wp_ajax_register_visit', [$this, 'handle_visit_registration']);
        add_action('wp_ajax_nopriv_register_visit', [$this, 'handle_visit_registration']);

        add_action('wp_ajax_sign_in_guest', [$this, 'handle_sign_in_guest']);
        add_action('wp_ajax_sign_out_guest', [$this, 'handle_sign_out_guest']);
        add_action('auto_update_visit_status_at_midnight', [$this, 'auto_update_visit_statuses']);
        add_action('auto_sign_out_guests_at_midnight', [$this, 'auto_sign_out_guests']);
        add_action('reset_monthly_guest_limits', [$this, 'reset_monthly_limits']);
        add_action('reset_yearly_guest_limits', [$this, 'reset_yearly_limits']);
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
     * Recalculate visit statuses for a specific guest
     */
    public static function recalculate_guest_visit_statuses(int $guest_id): void
    {
        global $wpdb;
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $monthly_limit = 4;
        $yearly_limit = 12;
        
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
            }
        }
    }

    /**
     * Recalculate host daily limits for a specific date
     */
    public static function recalculate_host_daily_limits(int $host_member_id, string $visit_date): void
    {
        global $wpdb;
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        
        // Get all visits for this host on this date (excluding cancelled)
        $host_visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, guest_id, status 
            FROM $guest_visits_table 
            WHERE host_member_id = %d AND visit_date = %s AND status != 'cancelled' 
            ORDER BY created_at ASC",
            $host_member_id,
            $visit_date
        ));
        
        if (!$host_visits) return;
        
        $count = 0;
        foreach ($host_visits as $visit) {
            $count++;
            
            // First 4 visits should be approved (if not restricted by other limits)
            $new_status = $count <= 4 ? 'approved' : 'unapproved';
            
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
            }
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
     * Handle guest registration via AJAX - UPDATED
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
                'courtesy'       => $courtesy,
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
     * Handle visit registration via AJAX - UPDATED
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
        if ($host_member_id) {
            $host_user = get_userdata($host_member_id);
            if (!$host_user) {
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

        // Prevent duplicate visit on same date
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
            $guest_id,
            $visit_date
        ));
        if ($existing_visit) {
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
        $host_approved_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
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
        $visit    = $wpdb->get_row("SELECT * FROM $table WHERE id = $visit_id");

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