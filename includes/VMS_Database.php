<?php
/**
 * Database functionality handler for VMS plugin
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

class VMS_Database
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /** @var string */
    private static $guests_table;
    private static $guest_visits_table;
    private static $recip_members_table;
    private static $recip_clubs_table;

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
        global $wpdb;
        self::$guests_table        = $wpdb->prefix . 'vms_guests';
        self::$guest_visits_table  = $wpdb->prefix . 'vms_guest_visits';
        self::$recip_members_table = $wpdb->prefix . 'vms_reciprocating_members';
        self::$recip_clubs_table   = $wpdb->prefix . 'vms_reciprocating_clubs';
        add_action('wp_ajax_guest_registration', [$this, 'handle_guest_registration']);
        add_action('wp_ajax_nopriv_guest_registration', [$this, 'handle_guest_registration']);
        add_action('wp_ajax_sign_in_guest', [$this, 'handle_sign_in_guest']);
        add_action('wp_ajax_sign_out_guest', [$this, 'handle_sign_out_guest']);
    }

    /**
     * Verify AJAX request
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

        // Verify user capability
        // if (!current_user_can('manage_options')) {
        //     wp_send_json_error([
        //         'messages' => ['You do not have permission to perform this action.'],
        //     ]);
        // }
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
        
        // Validate date format
        if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
            $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
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

        // Check if guest already exists by ID number
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM " . self::$guests_table . " WHERE id_number = %s",
            $id_number
        ));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            
            // Update existing guest info
            $wpdb->update(
                self::$guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'host_member_id'   => $host_member_id,
                    'courtesy'         => $courtesy,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,
                ],
                ['id' => $guest_id],
                ['%s','%s','%s','%s','%d','%s','%s','%s'],
                ['%d']
            );
        } else {
            // Create new guest
            $wpdb->insert(
                self::$guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $email,
                    'phone_number'     => $phone_number,
                    'host_member_id'   => $host_member_id,
                    'id_number'        => $id_number,
                    'courtesy'         => $courtesy,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,
                    'status'           => 'approved'
                ],
                ['%s','%s','%s','%s','%d','%s','%s','%s','%s','%s']
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
            self::$guests_table,
            ['status' => $status],
            ['id' => $guest_id],
            ['%s'],
            ['%d']
        );

        // Add visit record
        $visit_result = $wpdb->insert(
            self::$guest_visits_table,
            [
                'guest_id'       => $guest_id,
                'host_member_id' => $host_member_id,
                'visit_date'     => $visit_date_mysql
            ],
            ['%d', '%d', '%s']
        );

        if ($visit_result === false) {
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

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
            'sign_in_time'    => null, // Assuming sign-in time is handled elsewhere
            'sign_out_time'   => null, // Assuming sign-out time is handled elsewhere
            'visit_id'        => $wpdb->insert_id
        ];

        wp_send_json_success([
            'messages' => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
    }

    /**
     * Calculate guest status based on visit limits
     */
    private function calculate_guest_status(int $guest_id, int $host_member_id, string $visit_date): string
    {
        global $wpdb;

        // Daily limit check for host
        $daily_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$guest_visits_table . " 
            WHERE host_member_id = %d AND DATE(visit_date) = %s",
            $host_member_id, $visit_date
        ));

        // Monthly limit check for guest
        $monthly_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$guest_visits_table . " 
            WHERE guest_id = %d AND MONTH(visit_date) = MONTH(%s) AND YEAR(visit_date) = YEAR(%s)",
            $guest_id, $visit_date, $visit_date
        ));

        // Yearly limit check for guest
        $yearly_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$guest_visits_table . " 
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
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date, sign_in_time FROM " . self::$guest_visits_table . " WHERE id = %d",
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
            "SELECT * FROM " . self::$guests_table . " WHERE id = %d",
            $visit->guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // Re-evaluate guest status
        $guest_status = $this->calculate_guest_status($visit->guest_id, $visit->host_member_id, $visit->visit_date);
        if ($guest_status === 'banned' || $guest_status === 'suspended') {
            // Update guest status in database
            $wpdb->update(
                self::$guests_table,
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
            self::$guest_visits_table,
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
        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date, sign_in_time, sign_out_time FROM " . self::$guest_visits_table . " WHERE id = %d",
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
            "SELECT * FROM " . self::$guests_table . " WHERE id = %d",
            $visit->guest_id
        ));

        if (!$guest) {
            wp_send_json_error(['messages' => ['Guest not found']]);
            return;
        }

        // Update sign-out time
        $updated = $wpdb->update(
            self::$guest_visits_table,
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

    /** --------------------
     *  CRUD METHODS
     *  --------------------
     */

    /** Guests CRUD */
    
    /**
     * Add a new guest
     * 
     * @param string $first_name
     * @param string $last_name
     * @param string $id_number
     * @param string $visit_date 
     * @param int|null $host_member_id
     * @param string|null $courtesy
     * @param string|null $email
     * @param string|null $phone_number
     * @param string $receive_emails
     * @param string $receive_messages
     * @param string $status
     * @return int Guest ID or 0 on failure
     */
    public function add_guest(
        string $first_name, 
        string $last_name, 
        string $id_number, 
        ?int $host_member_id = null, 
        ?string $courtesy = null, 
        ?string $email = null, 
        ?string $phone_number = null, 
        string $receive_emails = 'no', 
        string $receive_messages = 'no',
        string $status = 'approved'         
        ): int {
        global $wpdb;

        // Validate required fields
        if (empty($first_name) || empty($last_name) || empty($id_number)) {
            error_log('add_guest() error: Missing required fields.');
            return 0;
        }

        // Validate host_member_id exists if provided
        if ($host_member_id && !get_user_by('id', $host_member_id)) {
            error_log("add_guest() error: Host member with ID {$host_member_id} does not exist.");
            return 0;
        }

        // Normalize and validate yes/no fields
        $receive_emails = strtolower($receive_emails) === 'yes' ? 'yes' : 'no';
        $receive_messages = strtolower($receive_messages) === 'yes' ? 'yes' : 'no';

        // Normalize and validate status
        $allowed_statuses = ['approved', 'unapproved', 'suspended', 'banned'];
        $status = in_array(strtolower($status), $allowed_statuses, true) ? strtolower($status) : 'approved';

        // Sanitize all fields
        $first_name     = sanitize_text_field($first_name);
        $last_name      = sanitize_text_field($last_name);
        $id_number      = sanitize_text_field($id_number);
        $courtesy       = $courtesy ? sanitize_textarea_field($courtesy) : null;
        $email          = $email ? sanitize_email($email) : null;
        $phone_number   = $phone_number ? sanitize_text_field($phone_number) : null;

        // Check if guest with same ID number already exists
        $existing_guest = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::$guests_table . " WHERE id_number = %s",
            $id_number
        ));

        if ($existing_guest) {
            error_log("add_guest() error: Guest with ID number {$id_number} already exists.");
            return 0;
        }

        // Prepare insert data
        $insert_data = [
            'first_name'       => $first_name,
            'last_name'        => $last_name,
            'email'            => $email,
            'phone_number'     => $phone_number,
            'id_number'        => $id_number,
            'host_member_id'   => $host_member_id,
            'courtesy'         => $courtesy,
            'status'           => $status,
            'receive_emails'   => $receive_emails,
            'receive_messages' => $receive_messages,
        ];

        $insert_formats = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'];

        // Insert guest into database
        $result = $wpdb->insert(self::$guests_table, $insert_data, $insert_formats);

        if ($result === false) {
            error_log('add_guest() database insert failed: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get a single guest by ID
     */
    public function get_guest(int $id): ?object
    {
        global $wpdb;
        
        if ($id <= 0) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, 
                    CASE 
                        WHEN g.host_member_id IS NOT NULL THEN CONCAT(u.user_nicename, ' (ID: ', g.host_member_id, ')') 
                        ELSE 'No Host Assigned' 
                    END as host_name
             FROM " . self::$guests_table . " g 
             LEFT JOIN {$wpdb->users} u ON g.host_member_id = u.ID 
             WHERE g.id = %d", 
            $id
        ));
    }

    public function get_all_guests(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, 
                    CASE 
                        WHEN g.host_member_id IS NOT NULL THEN CONCAT(u.user_nicename, ' (ID: ', g.host_member_id, ')') 
                        ELSE 'No Host Assigned' 
                    END as host_name
             FROM " . self::$guests_table . " g 
             LEFT JOIN {$wpdb->users} u ON g.host_member_id = u.ID 
             ORDER BY g.visit_date DESC 
             LIMIT %d OFFSET %d", 
            $limit, 
            $offset
        ));
    }

    public function search_guests(string $search_term, int $limit = 50): array
    {
        global $wpdb;
        
        if (empty(trim($search_term))) {
            return [];
        }
        
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($search_term)) . '%';
        $limit = max(1, min(500, $limit));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, 
                    CASE 
                        WHEN g.host_member_id IS NOT NULL THEN CONCAT(u.user_nicename, ' (ID: ', g.host_member_id, ')') 
                        ELSE 'No Host Assigned' 
                    END as host_name
             FROM " . self::$guests_table . " g 
             LEFT JOIN {$wpdb->users} u ON g.host_member_id = u.ID 
             WHERE g.first_name LIKE %s 
             OR g.last_name LIKE %s 
             OR g.id_number LIKE %s 
             OR g.email LIKE %s 
             OR g.phone_number LIKE %s 
             ORDER BY g.visit_date DESC 
             LIMIT %d", 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $limit
        ));
    }

    public function update_guest(int $id, array $data): bool
    {
        global $wpdb;
        
        if ($id <= 0 || empty($data)) {
            return false;
        }

        $allowed_fields = ['first_name', 'last_name', 'email', 'phone_number', 'id_number', 'host_member_id', 'courtesy', 'receive_emails', 'receive_messages'];
        $filtered_data = [];
        $formats = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields, true)) {
                if (in_array($field, ['first_name', 'last_name', 'id_number', 'courtesy', 'phone_number'], true)) {
                    $filtered_data[$field] = $value ? sanitize_text_field($value) : null;
                    $formats[] = '%s';
                } elseif ($field === 'email') {
                    $filtered_data[$field] = $value ? sanitize_email($value) : null;
                    $formats[] = '%s';
                } elseif ($field === 'host_member_id') {
                    $filtered_data[$field] = $value ? (int) $value : null;
                    $formats[] = '%d';
                } elseif (in_array($field, ['receive_emails', 'receive_messages'], true)) {
                    $filtered_data[$field] = in_array($value, ['yes', 'no']) ? $value : 'no';
                    $formats[] = '%s';
                }
            }
        }

        if (empty($filtered_data)) {
            return false;
        }

        $filtered_data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        return (bool) $wpdb->update(
            self::$guests_table, 
            $filtered_data, 
            ['id' => $id], 
            $formats, 
            ['%d']
        );
    }

    public function delete_guest(int $id): bool
    {
        global $wpdb;
        
        if ($id <= 0) {
            return false;
        }
        
        return (bool) $wpdb->delete(self::$guests_table, ['id' => $id], ['%d']);
    }

    /** Reciprocating Members CRUD */
    public function add_recip_member(
        string $first_name, 
        string $last_name, 
        string $id_number, 
        string $reciprocating_member_number, 
        int $reciprocating_club_id, 
        ?string $email = null, 
        ?string $phone_number = null, 
        string $receive_emails = 'no', 
        string $receive_messages = 'no'
    ): int {
        global $wpdb;
        
        if ($reciprocating_club_id <= 0) {
            error_log('Invalid reciprocating_club_id provided');
            return 0;
        }
        
        $receive_emails = in_array($receive_emails, ['yes', 'no']) ? $receive_emails : 'no';
        $receive_messages = in_array($receive_messages, ['yes', 'no']) ? $receive_messages : 'no';
        
        $result = $wpdb->insert(
            self::$recip_members_table, 
            [
                'first_name'                   => sanitize_text_field($first_name),
                'last_name'                    => sanitize_text_field($last_name),
                'email'                        => $email ? sanitize_email($email) : null,
                'phone_number'                 => $phone_number ? sanitize_text_field($phone_number) : null,
                'id_number'                    => sanitize_text_field($id_number),
                'reciprocating_member_number'  => sanitize_text_field($reciprocating_member_number),
                'reciprocating_club_id'        => $reciprocating_club_id,
                'receive_emails'               => $receive_emails,
                'receive_messages'             => $receive_messages,
                'visit_date'                   => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            error_log('Failed to insert reciprocating member: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function get_recip_member(int $id): ?object
    {
        global $wpdb;
        
        if ($id <= 0) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT rm.*, rc.club_name,
                    CONCAT(rm.first_name, ' ', rm.last_name) as full_name,
                    rm.reciprocating_member_number as recip_id
             FROM " . self::$recip_members_table . " rm 
             LEFT JOIN " . self::$recip_clubs_table . " rc ON rm.reciprocating_club_id = rc.id 
             WHERE rm.id = %d", 
            $id
        ));
    }

    public function get_all_recip_members(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rm.*, rc.club_name,
                    CONCAT(rm.first_name, ' ', rm.last_name) as full_name,
                    rm.reciprocating_member_number as recip_id
             FROM " . self::$recip_members_table . " rm 
             LEFT JOIN " . self::$recip_clubs_table . " rc ON rm.reciprocating_club_id = rc.id 
             ORDER BY rm.visit_date DESC 
             LIMIT %d OFFSET %d", 
            $limit, 
            $offset
        ));
    }

    public function search_recip_members(string $search_term, int $limit = 50): array
    {
        global $wpdb;
        
        if (empty(trim($search_term))) {
            return [];
        }
        
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($search_term)) . '%';
        $limit = max(1, min(500, $limit));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rm.*, rc.club_name,
                    CONCAT(rm.first_name, ' ', rm.last_name) as full_name,
                    rm.reciprocating_member_number as recip_id
             FROM " . self::$recip_members_table . " rm 
             LEFT JOIN " . self::$recip_clubs_table . " rc ON rm.reciprocating_club_id = rc.id 
             WHERE rm.first_name LIKE %s 
             OR rm.last_name LIKE %s 
             OR rm.id_number LIKE %s 
             OR rm.reciprocating_member_number LIKE %s 
             OR rm.email LIKE %s 
             OR rm.phone_number LIKE %s 
             OR rc.club_name LIKE %s 
             ORDER BY rm.visit_date DESC 
             LIMIT %d", 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $search_term, 
            $limit
        ));
    }

    public function update_recip_member(int $id, array $data): bool
    {
        global $wpdb;
        
        if ($id <= 0 || empty($data)) {
            return false;
        }

        $allowed_fields = ['first_name', 'last_name', 'email', 'phone_number', 'id_number', 'reciprocating_member_number', 'reciprocating_club_id', 'receive_emails', 'receive_messages'];
        $filtered_data = [];
        $formats = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields, true)) {
                if (in_array($field, ['first_name', 'last_name', 'id_number', 'reciprocating_member_number', 'phone_number'], true)) {
                    $filtered_data[$field] = $value ? sanitize_text_field($value) : null;
                    $formats[] = '%s';
                } elseif ($field === 'email') {
                    $filtered_data[$field] = $value ? sanitize_email($value) : null;
                    $formats[] = '%s';
                } elseif ($field === 'reciprocating_club_id') {
                    $filtered_data[$field] = (int) $value;
                    $formats[] = '%d';
                } elseif (in_array($field, ['receive_emails', 'receive_messages'], true)) {
                    $filtered_data[$field] = in_array($value, ['yes', 'no']) ? $value : 'no';
                    $formats[] = '%s';
                }
            }
        }

        if (empty($filtered_data)) {
            return false;
        }

        $filtered_data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        return (bool) $wpdb->update(
            self::$recip_members_table, 
            $filtered_data, 
            ['id' => $id], 
            $formats, 
            ['%d']
        );
    }

    public function delete_recip_member(int $id): bool
    {
        global $wpdb;
        
        if ($id <= 0) {
            return false;
        }
        
        return (bool) $wpdb->delete(self::$recip_members_table, ['id' => $id], ['%d']);
    }

    /** Reciprocating Clubs CRUD */
    public function add_recip_club(string $club_name): int
    {
        global $wpdb;
        
        $club_name = sanitize_text_field(trim($club_name));
        
        if (empty($club_name)) {
            error_log('Empty club name provided');
            return 0;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::$recip_clubs_table . " WHERE club_name = %s", 
            $club_name
        ));

        if ($existing) {
            error_log('Club name already exists: ' . $club_name);
            return 0;
        }

        $result = $wpdb->insert(
            self::$recip_clubs_table, 
            ['club_name' => $club_name],
            ['%s']
        );

        if ($result === false) {
            error_log('Failed to insert reciprocating club: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function get_recip_club(int $id): ?object
    {
        global $wpdb;
        
        if ($id <= 0) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$recip_clubs_table . " WHERE id = %d", $id));
    }

    public function get_recip_club_by_name(string $club_name): ?object
    {
        global $wpdb;
        
        $club_name = sanitize_text_field(trim($club_name));
        
        if (empty($club_name)) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$recip_clubs_table . " WHERE club_name = %s", $club_name));
    }

    public function get_all_recip_clubs(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::$recip_clubs_table . " ORDER BY club_name ASC");
    }

    public function update_recip_club(int $id, array $data): bool
    {
        global $wpdb;
        
        if ($id <= 0 || empty($data) || !isset($data['club_name'])) {
            return false;
        }

        $club_name = sanitize_text_field(trim($data['club_name']));
        
        if (empty($club_name)) {
            return false;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::$recip_clubs_table . " WHERE club_name = %s AND id != %d", 
            $club_name, 
            $id
        ));

        if ($existing) {
            error_log('Club name already exists: ' . $club_name);
            return false;
        }

        return (bool) $wpdb->update(
            self::$recip_clubs_table, 
            ['club_name' => $club_name], 
            ['id' => $id], 
            ['%s'], 
            ['%d']
        );
    }

    public function delete_recip_club(int $id): bool
    {
        global $wpdb;
        
        if ($id <= 0) {
            return false;
        }

        $members_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$recip_members_table . " WHERE reciprocating_club_id = %d", 
            $id
        ));

        if ($members_count > 0) {
            error_log('Cannot delete club with existing members');
            return false;
        }
        
        return (bool) $wpdb->delete(self::$recip_clubs_table, ['id' => $id], ['%d']);
    }

    /** Statistics and Reports */
    public function get_guest_count_by_date_range(string $start_date, string $end_date): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$guests_table . " 
             WHERE visit_date BETWEEN %s AND %s", 
            $start_date, 
            $end_date
        ));
    }

    public function get_recip_member_count_by_date_range(string $start_date, string $end_date): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$recip_members_table . " 
             WHERE visit_date BETWEEN %s AND %s", 
            $start_date, 
            $end_date
        ));
    }

    public function get_popular_clubs(int $limit = 10): array
    {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT rc.club_name, COUNT(rm.id) as visit_count 
             FROM " . self::$recip_clubs_table . " rc 
             LEFT JOIN " . self::$recip_members_table . " rm ON rc.id = rm.reciprocating_club_id 
             GROUP BY rc.id, rc.club_name 
             ORDER BY visit_count DESC, rc.club_name ASC 
             LIMIT %d", 
            $limit
        ));
    }

    /** Communication Helper Methods */
    public function get_guests_for_email(): array
    {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT g.*, 
                    CASE 
                        WHEN g.host_member_id IS NOT NULL THEN CONCAT(u.user_nicename, ' (ID: ', g.host_member_id, ')') 
                        ELSE 'No Host Assigned' 
                    END as host_name,
                    CONCAT(g.first_name, ' ', g.last_name) as full_name
             FROM " . self::$guests_table . " g 
             LEFT JOIN {$wpdb->users} u ON g.host_member_id = u.ID 
             WHERE g.receive_emails = 'yes' 
             AND g.email IS NOT NULL 
             AND g.email != '' 
             ORDER BY g.first_name, g.last_name"
        );
    }

    public function get_guests_for_messages(): array
    {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT g.*, 
                    CASE 
                        WHEN g.host_member_id IS NOT NULL THEN CONCAT(u.user_nicename, ' (ID: ', g.host_member_id, ')') 
                        ELSE 'No Host Assigned' 
                    END as host_name,
                    CONCAT(g.first_name, ' ', g.last_name) as full_name
             FROM " . self::$guests_table . " g 
             LEFT JOIN {$wpdb->users} u ON g.host_member_id = u.ID 
             WHERE g.receive_messages = 'yes' 
             AND g.phone_number IS NOT NULL 
             AND g.phone_number != '' 
             ORDER BY g.first_name, g.last_name"
        );
    }

    public function get_recip_members_for_email(): array
    {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT rm.*, rc.club_name,
                    CONCAT(rm.first_name, ' ', rm.last_name) as full_name,
                    rm.reciprocating_member_number as recip_id
             FROM " . self::$recip_members_table . " rm 
             LEFT JOIN " . self::$recip_clubs_table . " rc ON rm.reciprocating_club_id = rc.id 
             WHERE rm.receive_emails = 'yes' 
             AND rm.email IS NOT NULL 
             AND rm.email != '' 
             ORDER BY rm.first_name, rm.last_name"
        );
    }

    public function get_recip_members_for_messages(): array
    {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT rm.*, rc.club_name,
                    CONCAT(rm.first_name, ' ', rm.last_name) as full_name,
                    rm.reciprocating_member_number as recip_id
             FROM " . self::$recip_members_table . " rm 
             LEFT JOIN " . self::$recip_clubs_table . " rc ON rm.reciprocating_club_id = rc.id 
             WHERE rm.receive_messages = 'yes' 
             AND rm.phone_number IS NOT NULL 
             AND rm.phone_number != '' 
             ORDER BY rm.first_name, rm.last_name"
        );
    }

    public function get_member_contact_summary(): object
    {
        global $wpdb;
        
        $guest_emails = $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$guests_table . " 
             WHERE receive_emails = 'yes' AND email IS NOT NULL AND email != ''"
        );
        
        $guest_messages = $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$guests_table . " 
             WHERE receive_messages = 'yes' AND phone_number IS NOT NULL AND phone_number != ''"
        );
        
        $recip_emails = $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$recip_members_table . " 
             WHERE receive_emails = 'yes' AND email IS NOT NULL AND email != ''"
        );
        
        $recip_messages = $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$recip_members_table . " 
             WHERE receive_messages = 'yes' AND phone_number IS NOT NULL AND phone_number != ''"
        );
        
        return (object) [
            'guests_email_count' => (int) $guest_emails,
            'guests_message_count' => (int) $guest_messages,
            'recip_members_email_count' => (int) $recip_emails,
            'recip_members_message_count' => (int) $recip_messages,
            'total_email_contacts' => (int) $guest_emails + (int) $recip_emails,
            'total_message_contacts' => (int) $guest_messages + (int) $recip_messages
        ];
    }
}