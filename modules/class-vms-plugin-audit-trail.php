<?php
/**
 * Audit Trail functionality for VMS Plugin
 *
 * Tracks all user actions across the system including:
 * - User logins/logouts
 * - Employee management actions
 * - Guest management actions
 * - Visit management actions
 * - Status changes
 * - Data modifications
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Audit_Trail extends Base
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
     * Initialize audit trail functionality
     */
    public function init(): void
    {
        $this->setup_hooks();
    }   

    /**
     * Setup all audit trail hooks
     */
    private function setup_hooks(): void
    {
        // User authentication actions
        add_action('wp_login', [$this, 'log_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_user_logout']);

        // Employee actions
        add_action('wp_ajax_employee_registration', [$this, 'log_employee_registration'], 1);
        add_action('wp_ajax_update_employee', [$this, 'log_employee_update'], 1);
        add_action('wp_ajax_delete_employee', [$this, 'log_employee_deletion'], 1);

        // Guest actions
        add_action('wp_ajax_guest_registration', [$this, 'log_guest_registration'], 1);
        add_action('wp_ajax_courtesy_guest_registration', [$this, 'log_courtesy_guest_registration'], 1);
        add_action('wp_ajax_update_guest', [$this, 'log_guest_update'], 1);
        add_action('wp_ajax_delete_guest', [$this, 'log_guest_deletion'], 1);

        // Visit actions
        add_action('wp_ajax_register_visit', [$this, 'log_visit_registration'], 1);
        add_action('wp_ajax_sign_in_guest', [$this, 'log_guest_sign_in'], 1);
        add_action('wp_ajax_sign_out_guest', [$this, 'log_guest_sign_out'], 1);
        add_action('wp_ajax_cancel_visit', [$this, 'log_visit_cancellation'], 1);

        // Status update actions
        add_action('wp_ajax_update_guest_status', [$this, 'log_guest_status_update'], 1);
        add_action('wp_ajax_update_visit_status', [$this, 'log_visit_status_update'], 1);

        // Supplier actions
        add_action('wp_ajax_suppliers_registration', [$this, 'log_supplier_registration'], 1);
        add_action('wp_ajax_update_suppliers', [$this, 'log_supplier_update'], 1);
        add_action('wp_ajax_delete_suppliers', [$this, 'log_supplier_deletion'], 1);
        add_action('wp_ajax_register_supplier_visit', [$this, 'log_supplier_visit_registration'], 1);
        add_action('wp_ajax_sign_in_suppliers', [$this, 'log_supplier_sign_in'], 1);
        add_action('wp_ajax_sign_out_suppliers', [$this, 'log_supplier_sign_out'], 1);

        // Accommodation guest actions
        add_action('wp_ajax_accommodation_guest_registration', [$this, 'log_accommodation_guest_registration'], 1);
        add_action('wp_ajax_update_accommodation_guest', [$this, 'log_accommodation_guest_update'], 1);
        add_action('wp_ajax_delete_accommodation_guest', [$this, 'log_accommodation_guest_deletion'], 1);
        add_action('wp_ajax_register_accommodation_visit', [$this, 'log_accommodation_visit_registration'], 1);
        add_action('wp_ajax_sign_in_accommodation_guest', [$this, 'log_accommodation_guest_sign_in'], 1);
        add_action('wp_ajax_sign_out_accommodation_guest', [$this, 'log_accommodation_guest_sign_out'], 1);

        // Reciprocating member actions
        add_action('wp_ajax_reciprocating_member_registration', [$this, 'log_reciprocating_member_registration'], 1);
        add_action('wp_ajax_update_recip_member', [$this, 'log_reciprocating_member_update'], 1);
        add_action('wp_ajax_delete_recip_member', [$this, 'log_reciprocating_member_deletion'], 1);
        add_action('wp_ajax_register_reciprocation_member_visit', [$this, 'log_reciprocating_member_visit_registration'], 1);
        add_action('wp_ajax_reciprocating_member_sign_in', [$this, 'log_reciprocating_member_sign_in'], 1);
        add_action('wp_ajax_reciprocating_member_sign_out', [$this, 'log_reciprocating_member_sign_out'], 1);
    }

    /**
     * Log user login action
     */
    public function log_user_login(string $user_login, \WP_User $user): void
    {
        $this->log_action([
            'action_type' => 'user_login',
            'action_category' => 'authentication',
            'entity_type' => 'user',
            'entity_id' => $user->ID,
            'metadata' => [
                'user_login' => $user_login,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'roles' => $user->roles
            ]
        ]);
    }

    /**
     * Log user logout action
     */
    public function log_user_logout(): void
    {
        $user = wp_get_current_user();
        if ($user->ID) {
            $this->log_action([
                'action_type' => 'user_logout',
                'action_category' => 'authentication',
                'entity_type' => 'user',
                'entity_id' => $user->ID,
                'metadata' => [
                    'user_login' => $user->user_login,
                    'display_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'roles' => $user->roles
                ]
            ]);
        }
    }

    /**
     * Log employee registration
     */
    public function log_employee_registration(): void
    {
        if (!isset($_POST['first_name'], $_POST['last_name'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'employee_registration',
            'action_category' => 'employee',
            'entity_type' => 'employee',
            'new_values' => [
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'user_role' => sanitize_text_field($_POST['user_role'])
            ],
            'metadata' => [
                'registration_type' => 'new_employee'
            ]
        ]);
    }

    /**
     * Log employee update
     */
    public function log_employee_update(): void
    {
        if (!isset($_POST['user_id'])) {
            return;
        }

        $user_id = absint($_POST['user_id']);
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Get old values
        $old_values = [
            'first_name' => get_user_meta($user_id, 'first_name', true),
            'last_name' => get_user_meta($user_id, 'last_name', true),
            'user_email' => $user->user_email,
            'phone_number' => get_user_meta($user_id, 'phone_number', true),
            'roles' => $user->roles,
            'registration_status' => get_user_meta($user_id, 'registration_status', true)
        ];

        $new_values = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'user_email' => sanitize_email($_POST['email'] ?? ''),
            'phone_number' => sanitize_text_field($_POST['pnumber'] ?? ''),
            'user_role' => sanitize_key($_POST['user_role'] ?? ''),
            'registration_status' => sanitize_text_field($_POST['registration_status'] ?? '')
        ];

        $this->log_action([
            'action_type' => 'employee_update',
            'action_category' => 'employee',
            'entity_type' => 'employee',
            'entity_id' => $user_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'metadata' => [
                'update_type' => 'profile_update'
            ]
        ]);
    }

    /**
     * Log employee deletion
     */
    public function log_employee_deletion(): void
    {
        if (!isset($_POST['user_id'])) {
            return;
        }

        $user_id = absint($_POST['user_id']);
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $this->log_action([
            'action_type' => 'employee_deletion',
            'action_category' => 'employee',
            'entity_type' => 'employee',
            'entity_id' => $user_id,
            'old_values' => [
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name,
                'roles' => $user->roles
            ],
            'metadata' => [
                'deletion_type' => 'permanent_deletion'
            ]
        ]);
    }



    /**
     * Add performance optimizations for audit trail
     *
     * @since 1.0.0
     * @return void
     */
    private function add_performance_optimizations(): void
    {
        // Add action to defer audit logging for high-traffic operations
        add_action('shutdown', [$this, 'process_deferred_audit_logs'], 999);

        // Add filter to check if audit logging should be skipped for performance
        add_filter('vms_skip_audit_logging', [$this, 'should_skip_audit_logging'], 10, 2);
    }

    /**
     * Log courtesy guest registration
     */
    public function log_courtesy_guest_registration(): void
    {
        if (!isset($_POST['first_name'], $_POST['last_name'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'courtesy_guest_registration',
            'action_category' => 'guest',
            'entity_type' => 'guest',
            'new_values' => [
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'visit_date' => sanitize_text_field($_POST['visit_date']),
                'courtesy' => 'Courtesy'
            ],
            'metadata' => [
                'registration_type' => 'courtesy_guest'
            ]
        ]);
    }

    /**
     * Log guest update
     */
    public function log_guest_update(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        global $wpdb;
        $guest_id = absint($_POST['guest_id']);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$guests_table} WHERE id = %d", $guest_id));
        if (!$guest) {
            return;
        }

        $old_values = [
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'email' => $guest->email,
            'phone_number' => $guest->phone_number,
            'id_number' => $guest->id_number,
            'guest_status' => $guest->guest_status
        ];

        $new_values = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone_number' => sanitize_text_field($_POST['phone_number'] ?? ''),
            'id_number' => sanitize_text_field($_POST['id_number'] ?? ''),
            'guest_status' => sanitize_text_field($_POST['guest_status'] ?? '')
        ];

        $this->log_action([
            'action_type' => 'guest_update',
            'action_category' => 'guest',
            'entity_type' => 'guest',
            'entity_id' => $guest_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'metadata' => [
                'update_type' => 'profile_update'
            ]
        ]);
    }

    /**
     * Log guest deletion
     */
    public function log_guest_deletion(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        global $wpdb;
        $guest_id = absint($_POST['guest_id']);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$guests_table} WHERE id = %d", $guest_id));
        if (!$guest) {
            return;
        }

        $this->log_action([
            'action_type' => 'guest_deletion',
            'action_category' => 'guest',
            'entity_type' => 'guest',
            'entity_id' => $guest_id,
            'old_values' => [
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'phone_number' => $guest->phone_number
            ],
            'metadata' => [
                'deletion_type' => 'permanent_deletion'
            ]
        ]);
    }

    /**
     * Log visit registration
     */
    public function log_visit_registration(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'visit_registration',
            'action_category' => 'visit',
            'entity_type' => 'visit',
            'new_values' => [
                'guest_id' => absint($_POST['guest_id'] ?? 0),
                'host_member_id' => absint($_POST['host_member_id'] ?? 0),
                'visit_date' => sanitize_text_field($_POST['visit_date'] ?? ''),
                'courtesy' => sanitize_text_field($_POST['courtesy'] ?? '')
            ],
            'metadata' => [
                'registration_type' => 'new_visit'
            ]
        ]);
    }

    /**
     * Log guest sign-in
     */
    public function log_guest_sign_in(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');

        $this->log_action([
            'action_type' => 'guest_sign_in',
            'action_category' => 'visit',
            'entity_type' => 'visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'id_number_used' => $id_number,
                'sign_in_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log guest sign-out
     */
    public function log_guest_sign_out(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);

        $this->log_action([
            'action_type' => 'guest_sign_out',
            'action_category' => 'visit',
            'entity_type' => 'visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'sign_out_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log visit cancellation
     */
    public function log_visit_cancellation(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);

        $this->log_action([
            'action_type' => 'visit_cancellation',
            'action_category' => 'visit',
            'entity_type' => 'visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'cancellation_reason' => 'user_cancelled',
                'cancelled_at' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log guest status update
     */
    public function log_guest_status_update(): void
    {
        if (!isset($_POST['guest_id'], $_POST['guest_status'])) {
            return;
        }

        global $wpdb;
        $guest_id = absint($_POST['guest_id']);
        $new_status = sanitize_text_field($_POST['guest_status']);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        $guest = $wpdb->get_row($wpdb->prepare("SELECT guest_status FROM {$guests_table} WHERE id = %d", $guest_id));
        $old_status = $guest ? $guest->guest_status : 'unknown';

        $this->log_action([
            'action_type' => 'guest_status_update',
            'action_category' => 'status',
            'entity_type' => 'guest',
            'entity_id' => $guest_id,
            'old_values' => ['guest_status' => $old_status],
            'new_values' => ['guest_status' => $new_status],
            'metadata' => [
                'status_change_type' => 'manual_update'
            ]
        ]);
    }

    /**
     * Log visit status update
     */
    public function log_visit_status_update(): void
    {
        if (!isset($_POST['visit_id'], $_POST['visit_status'])) {
            return;
        }

        global $wpdb;
        $visit_id = absint($_POST['visit_id']);
        $new_status = sanitize_text_field($_POST['visit_status']);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

        $visit = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$guest_visits_table} WHERE id = %d", $visit_id));
        $old_status = $visit ? $visit->status : 'unknown';

        $this->log_action([
            'action_type' => 'visit_status_update',
            'action_category' => 'status',
            'entity_type' => 'visit',
            'entity_id' => $visit_id,
            'old_values' => ['status' => $old_status],
            'new_values' => ['status' => $new_status],
            'metadata' => [
                'status_change_type' => 'manual_update'
            ]
        ]);
    }

    /**
     * Generic method to log any action
     *
     * @param array $data Action data
     */
    private function log_action(array $data): void
    {
        global $wpdb;

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID ?: null;
        $user_role = !empty($current_user->roles) ? $current_user->roles[0] : null;

        $log_data = [
            'user_id' => $user_id,
            'user_role' => $user_role,
            'action_type' => $data['action_type'] ?? 'unknown',
            'action_category' => $data['action_category'] ?? 'general',
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'old_values' => !empty($data['old_values']) ? wp_json_encode($data['old_values']) : null,
            'new_values' => !empty($data['new_values']) ? wp_json_encode($data['new_values']) : null,
            'metadata' => !empty($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => current_time('mysql')
        ];

        $table_name = $wpdb->prefix . 'vms_audit_trail';
        $result = $wpdb->insert($table_name, $log_data);

        if ($result === false) {
            error_log('[VMS Audit Trail] Failed to log action: ' . $wpdb->last_error);
        } else {
            error_log('[VMS Audit Trail] Action logged: ' . $data['action_type']);
        }
    }

    /**
     * Get client IP address
     *
     * @return string|null
     */
    private function get_client_ip(): ?string
    {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (like X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                $ip = filter_var($ip, FILTER_VALIDATE_IP);
                if ($ip) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Get audit trail logs with pagination and filtering
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function get_logs(array $args = []): array
    {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'user_id' => null,
            'action_category' => null,
            'action_type' => null,
            'entity_type' => null,
            'entity_id' => null,
            'date_from' => null,
            'date_to' => null,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . 'vms_audit_trail';

        // Build WHERE clause
        $where = ['1=1'];
        $where_values = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if (!empty($args['action_category'])) {
            $where[] = 'action_category = %s';
            $where_values[] = $args['action_category'];
        }

        if (!empty($args['action_type'])) {
            $where[] = 'action_type = %s';
            $where_values[] = $args['action_type'];
        }

        if (!empty($args['entity_type'])) {
            $where[] = 'entity_type = %s';
            $where_values[] = $args['entity_type'];
        }

        if (!empty($args['entity_id'])) {
            $where[] = 'entity_id = %d';
            $where_values[] = $args['entity_id'];
        }

        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        // Build ORDER BY
        $orderby = in_array($args['orderby'], ['created_at', 'action_type', 'user_id']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Calculate offset
        $offset = ($args['page'] - 1) * $args['per_page'];

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$table_name} WHERE " . implode(' AND ', $where);
        if (!empty($where_values)) {
            $total_count = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        } else {
            $total_count = $wpdb->get_var($count_query);
        }

        // Get logs
        $query = "SELECT * FROM {$table_name} WHERE " . implode(' AND ', $where) .
                " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        $where_values[] = $args['per_page'];
        $where_values[] = $offset;

        $logs = $wpdb->get_results($wpdb->prepare($query, $where_values));

        // Decode JSON fields
        foreach ($logs as &$log) {
            $log->old_values = !empty($log->old_values) ? json_decode($log->old_values, true) : null;
            $log->new_values = !empty($log->new_values) ? json_decode($log->new_values, true) : null;
            $log->metadata = !empty($log->metadata) ? json_decode($log->metadata, true) : null;
        }

        return [
            'logs' => $logs,
            'total_count' => (int) $total_count,
            'total_pages' => ceil($total_count / $args['per_page']),
            'current_page' => $args['page']
        ];
    }

    /* ===============================================================
     * ============= SUPPLIER AUDIT TRAIL METHODS ===================
     * =============================================================== */

    /**
     * Log supplier registration
     */
    public function log_supplier_registration(): void
    {
        if (!isset($_POST['first_name'], $_POST['last_name'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'supplier_registration',
            'action_category' => 'supplier',
            'entity_type' => 'supplier',
            'new_values' => [
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'id_number' => sanitize_text_field($_POST['id_number'])
            ],
            'metadata' => [
                'registration_type' => 'new_supplier'
            ]
        ]);
    }

    /**
     * Log supplier update
     */
    public function log_supplier_update(): void
    {
        if (!isset($_POST['supplier_id'])) {
            return;
        }

        global $wpdb;
        $supplier_id = absint($_POST['supplier_id']);
        $suppliers_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

        $supplier = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$suppliers_table} WHERE id = %d", $supplier_id));
        if (!$supplier) {
            return;
        }

        $old_values = [
            'first_name' => $supplier->first_name,
            'last_name' => $supplier->last_name,
            'email' => $supplier->email,
            'phone_number' => $supplier->phone_number,
            'id_number' => $supplier->id_number,
            'guest_status' => $supplier->guest_status
        ];

        $new_values = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone_number' => sanitize_text_field($_POST['phone_number'] ?? ''),
            'id_number' => sanitize_text_field($_POST['id_number'] ?? ''),
            'guest_status' => sanitize_text_field($_POST['guest_status'] ?? '')
        ];

        $this->log_action([
            'action_type' => 'supplier_update',
            'action_category' => 'supplier',
            'entity_type' => 'supplier',
            'entity_id' => $supplier_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'metadata' => [
                'update_type' => 'profile_update'
            ]
        ]);
    }

    /**
     * Log supplier deletion
     */
    public function log_supplier_deletion(): void
    {
        if (!isset($_POST['supplier_id'])) {
            return;
        }

        global $wpdb;
        $supplier_id = absint($_POST['supplier_id']);
        $suppliers_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

        $supplier = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$suppliers_table} WHERE id = %d", $supplier_id));
        if (!$supplier) {
            return;
        }

        $this->log_action([
            'action_type' => 'supplier_deletion',
            'action_category' => 'supplier',
            'entity_type' => 'supplier',
            'entity_id' => $supplier_id,
            'old_values' => [
                'first_name' => $supplier->first_name,
                'last_name' => $supplier->last_name,
                'email' => $supplier->email,
                'phone_number' => $supplier->phone_number
            ],
            'metadata' => [
                'deletion_type' => 'permanent_deletion'
            ]
        ]);
    }

    /**
     * Log supplier visit registration
     */
    public function log_supplier_visit_registration(): void
    {
        if (!isset($_POST['supplier_id'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'supplier_visit_registration',
            'action_category' => 'supplier_visit',
            'entity_type' => 'supplier_visit',
            'new_values' => [
                'guest_id' => absint($_POST['supplier_id'] ?? 0),
                'visit_date' => sanitize_text_field($_POST['visit_date'] ?? '')
            ],
            'metadata' => [
                'registration_type' => 'new_supplier_visit'
            ]
        ]);
    }

    /**
     * Log supplier sign-in
     */
    public function log_supplier_sign_in(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');

        $this->log_action([
            'action_type' => 'supplier_sign_in',
            'action_category' => 'supplier_visit',
            'entity_type' => 'supplier_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'id_number_used' => $id_number,
                'sign_in_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log supplier sign-out
     */
    public function log_supplier_sign_out(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);

        $this->log_action([
            'action_type' => 'supplier_sign_out',
            'action_category' => 'supplier_visit',
            'entity_type' => 'supplier_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'sign_out_time' => current_time('mysql')
            ]
        ]);
    }

    /* ===============================================================
     * ============= ACCOMMODATION GUEST AUDIT TRAIL METHODS =========
     * =============================================================== */

    /**
     * Log accommodation guest registration
     */
    public function log_accommodation_guest_registration(): void
    {
        if (!isset($_POST['first_name'], $_POST['last_name'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'accommodation_guest_registration',
            'action_category' => 'accommodation_guest',
            'entity_type' => 'accommodation_guest',
            'new_values' => [
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'id_number' => sanitize_text_field($_POST['id_number'])
            ],
            'metadata' => [
                'registration_type' => 'new_accommodation_guest'
            ]
        ]);
    }

    /**
     * Log accommodation guest update
     */
    public function log_accommodation_guest_update(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        global $wpdb;
        $guest_id = absint($_POST['guest_id']);
        $a_guests_table = VMS_Config::get_table_name(VMS_Config::A_GUESTS_TABLE);

        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$a_guests_table} WHERE id = %d", $guest_id));
        if (!$guest) {
            return;
        }

        $old_values = [
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'email' => $guest->email,
            'phone_number' => $guest->phone_number,
            'id_number' => $guest->id_number,
            'guest_status' => $guest->guest_status
        ];

        $new_values = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone_number' => sanitize_text_field($_POST['phone_number'] ?? ''),
            'id_number' => sanitize_text_field($_POST['id_number'] ?? ''),
            'guest_status' => sanitize_text_field($_POST['guest_status'] ?? '')
        ];

        $this->log_action([
            'action_type' => 'accommodation_guest_update',
            'action_category' => 'accommodation_guest',
            'entity_type' => 'accommodation_guest',
            'entity_id' => $guest_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'metadata' => [
                'update_type' => 'profile_update'
            ]
        ]);
    }

    /**
     * Log accommodation guest deletion
     */
    public function log_accommodation_guest_deletion(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        global $wpdb;
        $guest_id = absint($_POST['guest_id']);
        $a_guests_table = VMS_Config::get_table_name(VMS_Config::A_GUESTS_TABLE);

        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$a_guests_table} WHERE id = %d", $guest_id));
        if (!$guest) {
            return;
        }

        $this->log_action([
            'action_type' => 'accommodation_guest_deletion',
            'action_category' => 'accommodation_guest',
            'entity_type' => 'accommodation_guest',
            'entity_id' => $guest_id,
            'old_values' => [
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'email' => $guest->email,
                'phone_number' => $guest->phone_number
            ],
            'metadata' => [
                'deletion_type' => 'permanent_deletion'
            ]
        ]);
    }

    /**
     * Log accommodation visit registration
     */
    public function log_accommodation_visit_registration(): void
    {
        if (!isset($_POST['guest_id'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'accommodation_visit_registration',
            'action_category' => 'accommodation_visit',
            'entity_type' => 'accommodation_visit',
            'new_values' => [
                'guest_id' => absint($_POST['guest_id'] ?? 0),
                'visit_date' => sanitize_text_field($_POST['visit_date'] ?? '')
            ],
            'metadata' => [
                'registration_type' => 'new_accommodation_visit'
            ]
        ]);
    }

    /**
     * Log accommodation guest sign-in
     */
    public function log_accommodation_guest_sign_in(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');

        $this->log_action([
            'action_type' => 'accommodation_guest_sign_in',
            'action_category' => 'accommodation_visit',
            'entity_type' => 'accommodation_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'id_number_used' => $id_number,
                'sign_in_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log accommodation guest sign-out
     */
    public function log_accommodation_guest_sign_out(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);

        $this->log_action([
            'action_type' => 'accommodation_guest_sign_out',
            'action_category' => 'accommodation_visit',
            'entity_type' => 'accommodation_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'sign_out_time' => current_time('mysql')
            ]
        ]);
    }

    /* ===============================================================
     * ============= RECIPROCATING MEMBER AUDIT TRAIL METHODS ========
     * =============================================================== */

    /**
     * Log reciprocating member registration
     */
    public function log_reciprocating_member_registration(): void
    {
        if (!isset($_POST['first_name'], $_POST['last_name'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'reciprocating_member_registration',
            'action_category' => 'reciprocating_member',
            'entity_type' => 'reciprocating_member',
            'new_values' => [
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'email' => sanitize_email($_POST['email']),
                'phone_number' => sanitize_text_field($_POST['phone_number']),
                'id_number' => sanitize_text_field($_POST['id_number']),
                'reciprocating_member_number' => sanitize_text_field($_POST['reciprocating_member_number']),
                'reciprocating_club_id' => absint($_POST['reciprocating_club_id'] ?? 0)
            ],
            'metadata' => [
                'registration_type' => 'new_reciprocating_member'
            ]
        ]);
    }

    /**
     * Log reciprocating member update
     */
    public function log_reciprocating_member_update(): void
    {
        if (!isset($_POST['member_id'])) {
            return;
        }

        global $wpdb;
        $member_id = absint($_POST['member_id']);
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$recip_members_table} WHERE id = %d", $member_id));
        if (!$member) {
            return;
        }

        $old_values = [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'email' => $member->email,
            'phone_number' => $member->phone_number,
            'id_number' => $member->id_number,
            'reciprocating_member_number' => $member->reciprocating_member_number,
            'member_status' => $member->member_status,
            'reciprocating_club_id' => $member->reciprocating_club_id
        ];

        $new_values = [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone_number' => sanitize_text_field($_POST['phone_number'] ?? ''),
            'id_number' => sanitize_text_field($_POST['id_number'] ?? ''),
            'reciprocating_member_number' => sanitize_text_field($_POST['reciprocating_member_number'] ?? ''),
            'member_status' => sanitize_text_field($_POST['member_status'] ?? ''),
            'reciprocating_club_id' => absint($_POST['reciprocating_club_id'] ?? 0)
        ];

        $this->log_action([
            'action_type' => 'reciprocating_member_update',
            'action_category' => 'reciprocating_member',
            'entity_type' => 'reciprocating_member',
            'entity_id' => $member_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'metadata' => [
                'update_type' => 'profile_update'
            ]
        ]);
    }

    /**
     * Log reciprocating member deletion
     */
    public function log_reciprocating_member_deletion(): void
    {
        if (!isset($_POST['member_id'])) {
            return;
        }

        global $wpdb;
        $member_id = absint($_POST['member_id']);
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$recip_members_table} WHERE id = %d", $member_id));
        if (!$member) {
            return;
        }

        $this->log_action([
            'action_type' => 'reciprocating_member_deletion',
            'action_category' => 'reciprocating_member',
            'entity_type' => 'reciprocating_member',
            'entity_id' => $member_id,
            'old_values' => [
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'phone_number' => $member->phone_number,
                'id_number' => $member->id_number,
                'reciprocating_member_number' => $member->reciprocating_member_number
            ],
            'metadata' => [
                'deletion_type' => 'permanent_deletion'
            ]
        ]);
    }

    /**
     * Log reciprocating member visit registration
     */
    public function log_reciprocating_member_visit_registration(): void
    {
        if (!isset($_POST['member_id'])) {
            return;
        }

        $this->log_action([
            'action_type' => 'reciprocating_member_visit_registration',
            'action_category' => 'reciprocating_member_visit',
            'entity_type' => 'reciprocating_member_visit',
            'new_values' => [
                'member_id' => absint($_POST['member_id'] ?? 0),
                'visit_date' => sanitize_text_field($_POST['visit_date'] ?? ''),
                'visit_purpose' => sanitize_text_field($_POST['visit_purpose'] ?? '')
            ],
            'metadata' => [
                'registration_type' => 'new_reciprocating_member_visit'
            ]
        ]);
    }

    /**
     * Log reciprocating member sign-in
     */
    public function log_reciprocating_member_sign_in(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);
        $id_number = sanitize_text_field($_POST['id_number'] ?? '');

        $this->log_action([
            'action_type' => 'reciprocating_member_sign_in',
            'action_category' => 'reciprocating_member_visit',
            'entity_type' => 'reciprocating_member_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'id_number_used' => $id_number,
                'sign_in_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Log reciprocating member sign-out
     */
    public function log_reciprocating_member_sign_out(): void
    {
        if (!isset($_POST['visit_id'])) {
            return;
        }

        $visit_id = absint($_POST['visit_id']);

        $this->log_action([
            'action_type' => 'reciprocating_member_sign_out',
            'action_category' => 'reciprocating_member_visit',
            'entity_type' => 'reciprocating_member_visit',
            'entity_id' => $visit_id,
            'metadata' => [
                'sign_out_time' => current_time('mysql')
            ]
        ]);
    }

    /**
     * Process deferred audit logs (for performance optimization)
     * This method runs on shutdown to handle any audit logs that were deferred
     */
    public function process_deferred_audit_logs(): void
    {
        // Check if there are any deferred logs in a transient
        $deferred_logs = get_transient('vms_deferred_audit_logs');

        if (empty($deferred_logs) || !is_array($deferred_logs)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vms_audit_trail';

        // Process logs in batches for better performance
        $batch_size = 10;
        $batches = array_chunk($deferred_logs, $batch_size);

        foreach ($batches as $batch) {
            $values = [];
            $placeholders = [];

            foreach ($batch as $log_data) {
                $values = array_merge($values, array_values($log_data));
                $placeholders[] = '(%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)';
            }

            $query = "INSERT INTO {$table_name} (user_id, user_role, action_type, action_category, entity_type, entity_id, old_values, new_values, metadata, ip_address, user_agent, created_at) VALUES " . implode(', ', $placeholders);

            $wpdb->query($wpdb->prepare($query, $values));
        }

        // Clear the deferred logs transient
        delete_transient('vms_deferred_audit_logs');

        error_log("[VMS Audit Trail] Processed " . count($deferred_logs) . " deferred audit logs");
    }

    /**
     * Check if audit logging should be skipped for performance reasons
     *
     * @param bool $skip Default skip value
     * @param string $action_type The action type being logged
     * @return bool Whether to skip logging
     */
    public function should_skip_audit_logging(bool $skip, string $action_type): bool
    {
        // Skip logging for certain high-frequency actions during peak times
        $current_hour = (int) current_time('H');

        // During peak hours (9 AM - 5 PM), skip detailed logging for frequent actions
        if ($current_hour >= 9 && $current_hour <= 17) {
            $high_frequency_actions = [
                'guest_sign_in',
                'guest_sign_out',
                'supplier_sign_in',
                'supplier_sign_out',
                'accommodation_guest_sign_in',
                'accommodation_guest_sign_out',
                'reciprocating_member_sign_in',
                'reciprocating_member_sign_out'
            ];

            if (in_array($action_type, $high_frequency_actions)) {
                // Only log 50% of these actions during peak hours
                return rand(1, 100) > 50;
            }
        }

        return $skip;
    }

    /**
     * Add audit trail hooks to the system (called from init method)
     */
    private function hook_user_actions(): void
    {
        // This method is kept for backward compatibility
        // All hooks are now set up in setup_hooks() method
    }

    /**
     * Add admin menu for audit trail
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'vms-dashboard',
            __('Audit Trail', 'vms-plugin'),
            __('Audit Trail', 'vms-plugin'),
            'manage_options',
            'vms-audit-trail',
            [$this, 'admin_page_callback']
        );
    }

    /**
     * Admin page callback for audit trail
     */
    public function admin_page_callback(): void
    {
        ?>
<div class="wrap">
    <h1><?php _e('VMS Audit Trail', 'vms-plugin'); ?></h1>

    <div id="audit-trail-app">
        <div class="audit-trail-controls">
            <div class="filters">
                <select id="action-category-filter">
                    <option value=""><?php _e('All Categories', 'vms-plugin'); ?></option>
                    <option value="authentication"><?php _e('Authentication', 'vms-plugin'); ?></option>
                    <option value="employee"><?php _e('Employee', 'vms-plugin'); ?></option>
                    <option value="guest"><?php _e('Guest', 'vms-plugin'); ?></option>
                    <option value="supplier"><?php _e('Supplier', 'vms-plugin'); ?></option>
                    <option value="accommodation_guest"><?php _e('Accommodation Guest', 'vms-plugin'); ?></option>
                    <option value="reciprocating_member"><?php _e('Reciprocating Member', 'vms-plugin'); ?></option>
                    <option value="visit"><?php _e('Visit', 'vms-plugin'); ?></option>
                    <option value="status"><?php _e('Status Updates', 'vms-plugin'); ?></option>
                </select>

                <input type="date" id="date-from" placeholder="<?php _e('From Date', 'vms-plugin'); ?>">
                <input type="date" id="date-to" placeholder="<?php _e('To Date', 'vms-plugin'); ?>">

                <button id="filter-btn" class="button"><?php _e('Filter', 'vms-plugin'); ?></button>
                <button id="export-btn" class="button"><?php _e('Export', 'vms-plugin'); ?></button>
            </div>
        </div>

        <div class="audit-trail-table-container">
            <table class="wp-list-table widefat fixed striped" id="audit-trail-table">
                <thead>
                    <tr>
                        <th><?php _e('Date/Time', 'vms-plugin'); ?></th>
                        <th><?php _e('User', 'vms-plugin'); ?></th>
                        <th><?php _e('Action', 'vms-plugin'); ?></th>
                        <th><?php _e('Entity', 'vms-plugin'); ?></th>
                        <th><?php _e('Details', 'vms-plugin'); ?></th>
                        <th><?php _e('IP Address', 'vms-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody id="audit-logs-body">
                    <!-- Logs will be loaded here via AJAX -->
                </tbody>
            </table>

            <div class="pagination" id="audit-pagination">
                <!-- Pagination will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    let currentPage = 1;
    const perPage = 50;

    function loadAuditLogs(page = 1, filters = {}) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_audit_logs',
                page: page,
                per_page: perPage,
                ...filters,
                nonce: '<?php echo wp_create_nonce("get_audit_logs_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    renderAuditLogs(response.data.logs);
                    renderPagination(response.data);
                }
            }
        });
    }

    function renderAuditLogs(logs) {
        const tbody = $('#audit-logs-body');
        tbody.empty();

        logs.forEach(function(log) {
            const row = `
                        <tr>
                            <td>${log.created_at}</td>
                            <td>${log.user_role || 'Unknown'} (ID: ${log.user_id || 'N/A'})</td>
                            <td>${log.action_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</td>
                            <td>${log.entity_type || 'N/A'} ${log.entity_id ? '(#' + log.entity_id + ')' : ''}</td>
                            <td>${formatLogDetails(log)}</td>
                            <td>${log.ip_address || 'N/A'}</td>
                        </tr>
                    `;
            tbody.append(row);
        });
    }

    function formatLogDetails(log) {
        let details = '';

        if (log.new_values) {
            details += '<strong>New:</strong> ' + JSON.stringify(log.new_values, null, 2);
        }

        if (log.old_values) {
            if (details) details += '<br>';
            details += '<strong>Old:</strong> ' + JSON.stringify(log.old_values, null, 2);
        }

        if (log.metadata) {
            if (details) details += '<br>';
            details += '<strong>Meta:</strong> ' + JSON.stringify(log.metadata, null, 2);
        }

        return details || '-';
    }

    function renderPagination(data) {
        const pagination = $('#audit-pagination');
        pagination.empty();

        if (data.total_pages > 1) {
            const prevBtn = data.current_page > 1 ?
                `<button class="button page-btn" data-page="${data.current_page - 1}">« Previous</button>` : '';

            const nextBtn = data.current_page < data.total_pages ?
                `<button class="button page-btn" data-page="${data.current_page + 1}">Next »</button>` : '';

            pagination.html(`${prevBtn} Page ${data.current_page} of ${data.total_pages} ${nextBtn}`);
        }
    }

    // Event handlers
    $('#filter-btn').on('click', function() {
        const filters = {
            action_category: $('#action-category-filter').val(),
            date_from: $('#date-from').val(),
            date_to: $('#date-to').val()
        };
        loadAuditLogs(1, filters);
    });

    $('#export-btn').on('click', function() {
        const filters = {
            action_category: $('#action-category-filter').val(),
            date_from: $('#date-from').val(),
            date_to: $('#date-to').val(),
            export: 1
        };

        const params = new URLSearchParams(filters);
        window.open(ajaxurl + '?action=export_audit_logs&' + params.toString(), '_blank');
    });

    $(document).on('click', '.page-btn', function() {
        const page = $(this).data('page');
        const filters = {
            action_category: $('#action-category-filter').val(),
            date_from: $('#date-from').val(),
            date_to: $('#date-to').val()
        };
        loadAuditLogs(page, filters);
    });

    // Load initial logs
    loadAuditLogs();
});
</script>
<?php
    }

    /**
     * AJAX handler for getting audit logs
     */
    public function ajax_get_audit_logs(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'get_audit_logs_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $args = [
            'page' => absint($_POST['page'] ?? 1),
            'per_page' => absint($_POST['per_page'] ?? 50),
            'action_category' => sanitize_text_field($_POST['action_category'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        $logs = self::get_logs($args);

        wp_send_json_success($logs);
    }

    /**
     * AJAX handler for exporting audit logs
     */
    public function ajax_export_audit_logs(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'export_audit_logs_nonce')) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $args = [
            'action_category' => sanitize_text_field($_GET['action_category'] ?? ''),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'per_page' => 10000, // Large number for export
        ];

        $logs = self::get_logs($args);

        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="vms-audit-trail-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // CSV headers
        fputcsv($output, [
            'Date/Time',
            'User ID',
            'User Role',
            'Action Type',
            'Action Category',
            'Entity Type',
            'Entity ID',
            'Old Values',
            'New Values',
            'Metadata',
            'IP Address',
            'User Agent'
        ]);

        // CSV data
        foreach ($logs['logs'] as $log) {
            fputcsv($output, [
                $log->created_at,
                $log->user_id,
                $log->user_role,
                $log->action_type,
                $log->action_category,
                $log->entity_type,
                $log->entity_id,
                is_array($log->old_values) ? json_encode($log->old_values) : $log->old_values,
                is_array($log->new_values) ? json_encode($log->new_values) : $log->new_values,
                is_array($log->metadata) ? json_encode($log->metadata) : $log->metadata,
                $log->ip_address,
                $log->user_agent
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Clean up old audit logs (keep last 90 days by default)
     *
     * @param int $days_to_keep Number of days to keep logs
     */
    public static function cleanup_old_logs(int $days_to_keep = 90): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'vms_audit_trail';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ));

        if ($deleted !== false) {
            error_log("[VMS Audit Trail] Cleaned up {$deleted} old audit log entries (older than {$cutoff_date})");
        }
    }
}