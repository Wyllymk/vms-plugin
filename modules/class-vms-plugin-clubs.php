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

class VMS_Clubs extends Base
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
        self::setup_club_management_hooks();
    }

    /**
     * Setup guest management related hooks
     */
    private static function setup_club_management_hooks(): void
    {
        // Club
        add_action('wp_ajax_club_registration', [self::class, 'handle_club_registration'] );
        add_action('wp_ajax_get_club_data', [self::class, 'handle_get_club_data'] );
        add_action('wp_ajax_delete_club', [self::class, 'handle_delete_club'] );
        add_action('wp_ajax_club_update', [self::class, 'handle_club_update'] );       
    }

    /**
     * Club Registration AJAX Handler
     */
    public static function handle_club_registration() 
    {
        self::verify_ajax_request(); 
        global $wpdb;

        error_log('[VMS] [Club Registration] AJAX request received.');

        $club_name    = sanitize_text_field($_POST['club_name'] ?? '');
        $club_email   = sanitize_email($_POST['club_email'] ?? '');
        $club_phone   = sanitize_text_field($_POST['club_phone'] ?? '');
        $club_website = esc_url_raw($_POST['club_website'] ?? '');        
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
        $status       = sanitize_text_field($_POST['status'] ?? 'active');

        $errors = [];

        // --- Validation ---
        if (empty($club_name)) $errors[] = 'Club name is required.';
        if (strlen($club_name) > 255) $errors[] = 'Club name must be less than 255 characters.';
        if (!empty($club_email) && !is_email($club_email)) $errors[] = 'Invalid email address.';

        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        error_log("[VMS] [Club Registration] Checking duplicates for club: {$club_name}");

        $existing_club = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$clubs_table} WHERE club_name = %s",
            $club_name
        ));

        if ($existing_club > 0) {
            $errors[] = 'A club with this name already exists.';
            error_log("[VMS] [Club Registration] Duplicate club found: {$club_name}");
        }

        if (!empty($errors)) {
            error_log('[VMS] [Club Registration] Validation failed: ' . implode(' | ', $errors));
            wp_send_json_error(['messages' => $errors]);
        }

        // --- Insert Data ---
        $club_data = [
            'club_name'   => $club_name,
            'club_email'  => $club_email,
            'club_phone'  => $club_phone,
            'club_website'=> $club_website,
            'notes'       => $notes,
            'status'      => in_array($status, ['active','suspended','banned']) ? $status : 'active',
            'created_at'  => current_time('mysql'),
        ];

        error_log("[VMS] [Club Registration] Inserting new club: {$club_name}");

        $result = $wpdb->insert(
            $clubs_table,
            $club_data,
            ['%s','%s','%s','%s','%s','%s','%s']
        );

        if ($result === false) {
            error_log("[VMS] [Club Registration] DB Insert Failed: {$wpdb->last_error}");
            wp_send_json_error(['messages' => ['Failed to create club. Please try again.']]);
        }

        $club_id = $wpdb->insert_id;
        error_log("[VMS] [Club Registration] Insert successful. New club ID: {$club_id}");
        
        $new_club = $wpdb->get_row($wpdb->prepare(
            "SELECT id, club_name, status, created_at, updated_at FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));


        if (!$new_club) {
            error_log("[VMS] [Club Registration] Failed to fetch new club record for ID: {$club_id}");
        }

        // --- Update transient cache ---
        $transient_key = 'vms_reciprocating_clubs_cache';
        $cached_clubs = get_transient($transient_key);

        if ($cached_clubs && is_array($cached_clubs)) {
            error_log('[VMS] [Club Registration] Updating existing cache with new club.');
            $cached_clubs[] = [
                'id' => $new_club->id,
                'club_name' => $new_club->club_name,
            ];
            $cache_saved = set_transient($transient_key, $cached_clubs, MONTH_IN_SECONDS);
            if ($cache_saved) {
                error_log('[VMS] [Club Registration] Transient cache updated successfully.');
            } else {
                error_log('[VMS] [Club Registration] Failed to update transient cache.');
            }
        } else {
            error_log('[VMS] [Club Registration] No existing cache found — refreshing full cache.');
            self::refresh_reciprocating_clubs_cache();
        }

        wp_send_json_success([
            'messages' => ['Club created successfully!'],
            'clubData' => $new_club
        ]);
        error_log('[VMS] [Club Registration] Club creation completed successfully.');
    }

    /**
     * Club Update Handler
     */
    public static function handle_club_update() 
    {
        self::verify_ajax_request();
        global $wpdb;

        $club_id     = intval($_POST['club_id'] ?? 0);
        $club_name   = sanitize_text_field($_POST['club_name'] ?? '');
        $club_email  = sanitize_email($_POST['club_email'] ?? '');
        $club_phone  = sanitize_text_field($_POST['club_phone'] ?? '');
        $club_website= esc_url_raw($_POST['club_website'] ?? '');
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

        $existing_club = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$clubs_table} WHERE club_name = %s AND id != %d",
            $club_name, $club_id
        ));
        if ($existing_club > 0) $errors[] = 'A club with this name already exists.';

        $current_club = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));
        if (!$current_club) $errors[] = 'Club not found.';

        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

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

        // 🔄 Refresh transient cache to ensure accuracy
        self::refresh_reciprocating_clubs_cache();

        $updated_club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));

        wp_send_json_success([
            'messages' => ['Club updated successfully!'],
            'clubData' => $updated_club
        ]);
    }

    /**
     * Club Delete Handler
     */
    public static function handle_delete_club() 
    {
        self::verify_ajax_request();
        global $wpdb;
        
        $club_id = intval($_POST['club_id'] ?? 0);
        if ($club_id <= 0) {
            wp_send_json_error(['messages' => ['Invalid club ID.']]);
        }

        $clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$clubs_table} WHERE id = %d",
            $club_id
        ));
        if (!$club) {
            wp_send_json_error(['messages' => ['Club not found.']]);
        }

        $result = $wpdb->delete($clubs_table, ['id' => $club_id], ['%d']);
        if ($result === false) {
            wp_send_json_error(['messages' => ['Failed to delete club. Please try again.']]);
        }

        // 🔄 Update transient cache
        $cached_clubs = get_transient('vms_reciprocating_clubs_cache');
        if ($cached_clubs && is_array($cached_clubs)) {
            $updated_cache = array_filter($cached_clubs, fn($c) => intval($c['id']) !== $club_id);
            set_transient('vms_reciprocating_clubs_cache', $updated_cache, HOUR_IN_SECONDS * 6);
        } else {
            self::refresh_reciprocating_clubs_cache();
        }

        wp_send_json_success(['messages' => ['Club deleted successfully!']]);
    }

    /**
     * Refresh Reciprocating Clubs Cache
     *
     * - Caches active reciprocating clubs for one month.
     * - Logs database and transient operations for debugging.
     */
    private static function refresh_reciprocating_clubs_cache(): void
    {
        global $wpdb;
        $transient_key  = 'vms_reciprocating_clubs_cache';
        $cache_duration = MONTH_IN_SECONDS; // ≈ 30 days
        $clubs_table    = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        error_log('[VMS] [Clubs] === Starting monthly reciprocating clubs cache refresh ===');

        // Fetch active clubs from the database
        $clubs = $wpdb->get_results("
            SELECT id, club_name 
            FROM {$clubs_table} 
            WHERE status = 'active'
            ORDER BY club_name ASC
        ", ARRAY_A);

        // Handle DB errors
        if ($wpdb->last_error) {
            error_log('[VMS] [Clubs] DB Error during monthly cache refresh: ' . $wpdb->last_error);
            return;
        }

        // Handle empty results
        if (empty($clubs)) {
            error_log('[VMS] [Clubs] No active clubs found during monthly cache refresh. Deleting old cache.');
            delete_transient($transient_key);
            return;
        }

        // Attempt to set transient
        $set_result = set_transient($transient_key, $clubs, $cache_duration);

        if ($set_result) {
            error_log(sprintf(
                '[VMS] [Clubs] Monthly cache refreshed successfully. %d clubs cached for 30 days.',
                count($clubs)
            ));
        } else {
            error_log('[VMS] [Clubs] Failed to set monthly transient cache.');
        }

        error_log('[VMS] [Clubs] === Monthly cache refresh complete ===');
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
     * Verify AJAX request (placeholder, implement as needed)
     */
    private static function verify_ajax_request(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'vms-plugin')]);
        }

        // Verify if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to perform this action', 'vms-plugin')]);
        }
       
    }
}