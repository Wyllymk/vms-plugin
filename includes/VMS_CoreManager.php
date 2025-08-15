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
     * Calculate guest status based on visit limits
     */
    private function calculate_guest_status(int $guest_id, int $host_member_id, string $visit_date): string
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

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

        // Determine status based on limits
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
        // Assume verify_ajax_request is defined elsewhere
        $this->verify_ajax_request();

        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'id_number', 'host_member_id', 'visit_date'];
        $errors = [];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        // Validate email if provided
        if (!empty($_POST['email']) && !is_email($_POST['email'])) {
            $errors[] = 'Invalid email address';
        }

        // Validate host member
        $host_member_id = absint($_POST['host_member_id']);
        if ($host_member_id && !get_user_by('ID', $host_member_id)) {
            $errors[] = 'Invalid host member';
        }

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Sanitize input
        $guest_data = [
            'first_name'       => sanitize_text_field($_POST['first_name']),
            'last_name'        => sanitize_text_field($_POST['last_name']),
            'email'            => !empty($_POST['email']) ? sanitize_email($_POST['email']) : null,
            'phone_number'     => !empty($_POST['phone_number']) ? sanitize_text_field($_POST['phone_number']) : null,
            'id_number'        => sanitize_text_field($_POST['id_number']),
            'host_member_id'   => $host_member_id,
            'courtesy'         => !empty($_POST['courtesy']) ? sanitize_text_field($_POST['courtesy']) : null,
            'receive_emails'   => !empty($_POST['receive_emails']) && $_POST['receive_emails'] === 'yes' ? 'yes' : 'no',
            'receive_messages' => !empty($_POST['receive_messages']) && $_POST['receive_messages'] === 'yes' ? 'yes' : 'no',
        ];

        // Insert guest
        $inserted = $wpdb->insert($guests_table, $guest_data, [
            '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'
        ]);

        if ($inserted === false) {
            wp_send_json_error(['messages' => ['Failed to register guest']]);
            return;
        }

        $guest_id = $wpdb->insert_id;

        // Calculate guest status
        $visit_date = sanitize_text_field($_POST['visit_date']);
        $guest_status = $this->calculate_guest_status($guest_id, $host_member_id, $visit_date);

        // Update guest status
        $wpdb->update(
            $guests_table,
            ['status' => $guest_status],
            ['id' => $guest_id],
            ['%s'],
            ['%d']
        );

        // Insert guest visit
        $visit_data = [
            'guest_id'       => $guest_id,
            'host_member_id' => $host_member_id,
            'visit_date'     => $visit_date,
        ];

        $inserted_visit = $wpdb->insert($guest_visits_table, $visit_data, ['%d', '%d', '%s']);

        if ($inserted_visit === false) {
            // Rollback guest insertion
            $wpdb->delete($guests_table, ['id' => $guest_id], ['%d']);
            wp_send_json_error(['messages' => ['Failed to create guest visit']]);
            return;
        }

        $visit_id = $wpdb->insert_id;

        // Prepare response data
        $host_member = get_user_by('id', $host_member_id);
        $guest_data['id'] = $guest_id;
        $guest_data['status'] = $guest_status;
        $guest_data['host_name'] = $host_member ? $host_member->display_name : 'N/A';
        $guest_data['visit_date'] = $visit_date;
        $guest_data['sign_in_time'] = null;
        $guest_data['sign_out_time'] = null;
        $guest_data['visit_id'] = $visit_id;

        wp_send_json_success([
            'messages' => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
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
        if ($guest_status === 'banned' || $guest_status === 'suspended') {
            $wpdb->update(
                $guests_table,
                ['status' => $guest_status],
                ['id' => $visit->guest_id],
                ['%s'],
                ['%d']
            );
            wp_send_json_error(['messages' => ['Guest access is restricted due to status: ' . $guest_status]]);
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
     * Reset monthly guest limits
     */
    public function reset_monthly_limits()
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $guests_table
                SET status = 'approved'
                WHERE status = 'suspended'
                AND (
                    YEAR(visit_date) < YEAR(CURDATE())
                    OR (YEAR(visit_date) = YEAR(CURDATE()) AND MONTH(visit_date) < MONTH(CURDATE()))
                )"
            )
        );
    }

    /**
     * Reset yearly guest limits
     */
    public function reset_yearly_limits()
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $wpdb->query(
            "UPDATE $guests_table
            SET status = 'approved'
            WHERE status = 'suspended'
            AND visit_date < DATE_FORMAT(CURDATE(), '%Y-01-01')"
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