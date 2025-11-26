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

class VMS_Reciprocation extends Base
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
        self::setup_recip_management_hooks();
    }

    /**
     * Setup guest management related hooks
     */
    private static function setup_recip_management_hooks(): void
    {
        // Reciprocating Member
        add_action('wp_ajax_reciprocating_member_registration', [self::class, 'handle_reciprocating_registration']);
        add_action('wp_ajax_reciprocating_member_sign_in', [self::class, 'handle_reciprocating_sign_in']);
        add_action('wp_ajax_get_reciprocating_clubs', [self::class, 'get_reciprocating_clubs']);
        add_action('wp_ajax_reciprocating_member_sign_out', [self::class, 'handle_reciprocating_sign_out']);
        add_action('wp_ajax_register_reciprocation_member_visit', [self::class, 'handle_reciprocation_member_visit_registration']);
        add_action('auto_sign_out_recip_members_at_midnight', [self::class, 'auto_sign_out_recip_members']); 
        add_action('wp_ajax_update_recip_member', [self::class, 'handle_member_update']);
        add_action('wp_ajax_delete_recip_member', [self::class, 'handle_member_deletion']); 
        add_action('reset_yearly_recip_limits', [self::class, 'reset_yearly_limits']);     
    }


    // Handle member update via AJAX
    public static function handle_member_update() 
    {
        // Verify nonce
        self::verify_ajax_request();

        error_log('Handle recip member update');

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

        error_log('Delete recip member ID: ' . $member_id);

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

    /**
     * Recalculate visit statuses for a specific reciprocating member - with notifications.
     * Only casual visits count towards the yearly limit of 24.
     * Golf tournament visits have no limit.
     */
    public static function recalculate_member_visit_statuses(int $member_id): void
    {
        global $wpdb;
        error_log('Handle recalculate member visit statuses');

        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        // Yearly limit for casual visits only
        $yearly_limit = 24;

        // Get member data for notifications
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$recip_members_table} WHERE id = %d",
            $member_id
        ));

        if (!$member) {
            return;
        }

        $old_member_status = $member->member_status;

        // Fetch all non-cancelled visits for this member, ordered by visit_date ASC
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, visit_date, sign_in_time, status, visit_purpose
            FROM $recip_visits_table
            WHERE member_id = %d AND status != 'cancelled'
            ORDER BY visit_date ASC",
            $member_id
        ));

        if (!$visits) {
            return;
        }

        $yearly_scheduled = [];
        $status_changes = [];

        foreach ($visits as $visit) {
            $year_key = date('Y', strtotime($visit->visit_date));

            if (!isset($yearly_scheduled[$year_key])) {
                $yearly_scheduled[$year_key] = 0;
            }

            $visit_date_obj = new \DateTime($visit->visit_date);
            $today = new \DateTime(current_time('Y-m-d'));
            $is_past = $visit_date_obj < $today;
            $attended = $is_past && !empty($visit->sign_in_time);
            $is_casual = ($visit->visit_purpose === 'casual_visit');

            // Increment yearly count only for casual visits if future or attended
            if ($is_casual && (!$is_past || $attended)) {
                $yearly_scheduled[$year_key]++;
            }

            // Determine status for this visit
            $old_status = $visit->status;
            $new_status = 'approved';

            // Check yearly limit only for casual visits
            if ($is_casual && $yearly_scheduled[$year_key] > $yearly_limit) {
                $new_status = 'unapproved';
            }

            // If this is a missed past casual visit, reduce counts to free slots
            if ($is_casual && $is_past && empty($visit->sign_in_time)) {
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

        // Check if member should be automatically suspended due to casual visit limits
        $current_year = date('Y');
        $new_member_status = $member->member_status;

        if ($member->member_status === 'active') {
            $current_yearly_casual = $yearly_scheduled[$current_year] ?? 0;

            if ($current_yearly_casual >= $yearly_limit) {
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

            VMS_SMS::get_instance()->send_member_status_notification(
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

            VMS_SMS::get_instance()->send_member_visit_status_notification(
                $member_data, $visit_data, $change['old_status'], $change['new_status']
            );
        }
    }

    /**
     * Handle reciprocating member registration via AJAX
     */
    public static function handle_reciprocating_registration(): void
    {
        self::verify_ajax_request();
        error_log('[VMS] === Handle reciprocating member registration triggered ===');

        global $wpdb;
        $errors = [];

        // Sanitize input
        $first_name     = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name      = sanitize_text_field($_POST['last_name'] ?? '');
        $phone_number   = sanitize_text_field($_POST['phone_number'] ?? '');
        $id_number      = sanitize_text_field($_POST['id_number'] ?? '');
        $receive_emails = 'yes';
        $receive_messages = 'yes';
        $visit_date     = current_time('Y-m-d');

        // Validate required fields
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($phone_number)) $errors[] = 'Phone number is required';
        if (empty($id_number)) $errors[] = 'ID number is required';

        if (!empty($errors)) {
            error_log('[VMS] Validation failed: ' . implode(', ', $errors));
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Tables
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $visits_table  = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $clubs_table   = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        $member_cache_key = 'vms_member_' . md5($id_number);
        $visit_count_key  = 'vms_visit_counts_' . md5($id_number);

        // === Retrieve member from DB (bypass cache for accuracy) ===
        $existing_member = $wpdb->get_row($wpdb->prepare(
            "SELECT id, member_status, reciprocating_member_number, reciprocating_club_id, email
            FROM $members_table
            WHERE id_number = %s",
            $id_number
        ));

        // === Create or update member ===
        if ($existing_member) {
            $member_id = $existing_member->id;
            $wpdb->update(
                $members_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages
                ],
                ['id' => $member_id],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            error_log("[VMS] Existing member updated: ID=$member_id");

            // Use existing member data
            $reciprocating_member_number = $existing_member->reciprocating_member_number;
            $club_id = $existing_member->reciprocating_club_id;
            $email = $existing_member->email ?? null;

            // Update transient with refreshed data
            $existing_member->first_name = $first_name;
            $existing_member->last_name = $last_name;
            $existing_member->phone_number = $phone_number;
            $existing_member->receive_emails = $receive_emails;
            $existing_member->receive_messages = $receive_messages;
            set_transient($member_cache_key, $existing_member, MONTH_IN_SECONDS);
        } else {
            // Create a new member
            $result = $wpdb->insert(
                $members_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'phone_number'     => $phone_number,
                    'id_number'        => $id_number,
                    'member_status'    => 'active',
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $member_id = $wpdb->insert_id;
            error_log("[VMS] New member created: ID=$member_id, result=$result");

            $reciprocating_member_number = null;
            $club_id = null;
            $email = null;

            // Cache the new member
            $new_member = (object)[
                'id' => $member_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone_number' => $phone_number,
                'id_number' => $id_number,
                'member_status' => 'active',
                'reciprocating_member_number' => null,
                'reciprocating_club_id' => null,
                'email' => null
            ];
            set_transient($member_cache_key, $new_member, MONTH_IN_SECONDS);
        }

        if (!$member_id) {
            error_log('[VMS] Failed to create or update member record');
            wp_send_json_error(['messages' => ['Failed to create or update member record']]);
            return;
        }

        // === Get club name if club_id exists ===
        $club_name = null;
        if ($club_id) {
            $club_name = $wpdb->get_var($wpdb->prepare(
                "SELECT club_name FROM $clubs_table WHERE id = %d",
                $club_id
            ));
            error_log("[VMS] Club lookup: club_id=$club_id, club_name=" . ($club_name ?: 'NULL'));
        }

        // === Prevent duplicate visit on same day ===
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table WHERE member_id = %d AND visit_date = %s AND status != 'cancelled'",
            $member_id,
            $visit_date
        ));
        if ($existing_visit) {
            error_log("[VMS] Duplicate visit detected for member ID=$member_id on $visit_date");
            wp_send_json_error(['messages' => ['This member already has a visit registered today']]);
            return;
        }

        // === Get visit counts (with transient cache) ===
        // Only count casual visits towards yearly limit
        $visit_counts = get_transient($visit_count_key);
        if ($visit_counts === false) {
            error_log('[VMS] Cache miss: fetching visit counts from DB');

            $today = date('Y-m-d');
            $year_start = date('Y-01-01', strtotime($visit_date));
            $year_end = date('Y-12-31', strtotime($visit_date));

            $yearly_casual_visits = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $visits_table
                WHERE member_id = %d AND visit_date BETWEEN %s AND %s
                AND (visit_purpose IS NULL OR visit_purpose = 'casual_visit')
                AND (
                    (status = 'approved' AND visit_date >= %s) OR
                    (visit_date < %s AND sign_in_time IS NOT NULL)
                )",
                $member_id, $year_start, $year_end, $today, $today
            ));

            $visit_counts = ['yearly' => $yearly_casual_visits];
            set_transient($visit_count_key, $visit_counts, MONTH_IN_SECONDS);
        } else {
            error_log('[VMS] Cache hit: using cached visit counts');
        }

        // === Check limits ===
        $yearly_limit = 24;
        $preliminary_status = 'approved';

        if (($visit_counts['yearly'] + 1) > $yearly_limit) {
            $preliminary_status = 'suspended';
        }

        // === Add new visit record ===
        $visit_inserted = $wpdb->insert(
            $visits_table,
            [
                'member_id'  => $member_id,
                'visit_date' => $visit_date,
                'status'     => $preliminary_status
            ],
            ['%d', '%s', '%s']
        );

        if ($visit_inserted === false) {
            error_log("[VMS] Failed to create visit record for member ID=$member_id");
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        error_log("[VMS] Visit created successfully for member ID=$member_id");

        // === Update cached visit counts ===
        $visit_counts['yearly']++;
        set_transient($visit_count_key, $visit_counts, MONTH_IN_SECONDS);

        // === Send notifications (safe) ===
        // ==================== EMAIL NOTIFICATION ====================
        try {
            error_log("[VMS] Email lookup for member ID={$member_id}: " . ($email ? $email : 'NULL or empty'));

            if (empty($email)) {
                error_log("[VMS] No email found for member ID={$member_id}. Skipping email notification.");
            } else {
                $subject = 'New Visit Recorded';
                $message = "Dear member,\n\nYour visit has been successfully recorded.\n\nThank you!";
                $headers = ['Content-Type: text/plain; charset=UTF-8'];

                error_log("[VMS] Attempting to send email to {$email}...");

                $mail_result = wp_mail($email, $subject, $message, $headers);

                if ($mail_result) {
                    error_log("[VMS] Email sent successfully to {$email}");
                } else {
                    error_log("[VMS] wp_mail() returned false. Email not sent to {$email}");
                }
            }
        } catch (Throwable $e) {
            error_log("[VMS] ERROR during email send for member ID={$member_id}: " . $e->getMessage());
        }

        // ==================== SMS NOTIFICATION ====================
        try {
            error_log("[VMS] Sending SMS notification for member ID=$member_id");
            self::send_reciprocating_registration_sms(
                $member_id,
                $first_name,
                $last_name,
                $phone_number,
                $receive_messages,
                $club_name,
                $visit_date,
                $preliminary_status,
                $reciprocating_member_number
            );
            error_log("[VMS] SMS notification sent successfully for member ID=$member_id");
        } catch (Throwable $e) {
            error_log("[VMS] SMS notification failed: " . $e->getMessage());
        }

        // === Final confirmation ===
        error_log("[VMS] Successfully completed registration for member ID=$member_id");
        error_log("[VMS] Response data: club_id=$club_id, club_name=" . ($club_name ?: 'Not Set') . ", recip_number=$reciprocating_member_number");

        wp_send_json_success([
            'messages' => ['Reciprocating member registered and visit recorded successfully'],
            'memberData' => [
                'id' => $member_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone_number' => $phone_number,
                'id_number' => $id_number,
                'receive_emails' => $receive_emails,
                'receive_messages' => $receive_messages,
                'member_status' => 'active',
                'club_id' => $club_id,
                'club_name' => $club_name ?: 'Not Set',
                'reciprocating_member_number' => $reciprocating_member_number,
                'visit_date' => $visit_date,
                'visit_status' => $preliminary_status
            ]
        ]);
    }

    /**
     * Send registration SMS notifications
     */
    private static function send_reciprocating_registration_sms(
        $member_id,
        $first_name,
        $last_name,
        $phone_number,
        $receive_messages,
        $club,
        $visit_date,
        $status,
        $reciprocating_member_number
    ): void 
    {
        // --- Log function start ---
        error_log("[VMS][SMS] send_reciprocating_registration_sms() called.");
        error_log("[VMS][SMS] Params => member_id: {$member_id}, first_name: {$first_name}, last_name: {$last_name}, phone_number: {$phone_number}, receive_messages: {$receive_messages}, visit_date: {$visit_date}, status: {$status}, member_number: {$reciprocating_member_number}");

        // --- Check message preferences ---
        if ($receive_messages !== 'yes') {
            error_log("[VMS][SMS] Member has opted out of SMS notifications. Skipping SMS send.");
            return;
        }

        // --- Validate phone number ---
        if (empty($phone_number)) {
            error_log("[VMS][SMS][Error] Missing phone number for member ID {$member_id}. SMS not sent.");
            return;
        }

        $site_name = get_bloginfo('name');
        $club_name = !empty($club->club_name) ? $club->club_name : '';
        $formatted_date = date('M j', strtotime($visit_date));

        $sms_message = "{$site_name}: Hello {$first_name}, ";
        $sms_message .= "reciprocating membership registered. ";

        if (!empty($reciprocating_member_number)) {
            $sms_message .= "Member Number: {$reciprocating_member_number}. ";
        }

        if (!empty($club_name)) {
            $sms_message .= "Club: {$club_name}. ";
        }

        $sms_message .= "Visit: {$formatted_date}. ";
        $sms_message .= "Status: " . ucfirst($status) . ".";


        // --- Log constructed message ---
        error_log("[VMS][SMS] Prepared SMS message: {$sms_message}");

        // --- Attempt to send ---
        try {
            $send_result = VMS_SMS::send_sms(
                $phone_number,
                $sms_message,
                $member_id,
                'reciprocating_member'
            );

            if ($send_result) {
                error_log("[VMS][SMS] SMS successfully sent to {$phone_number} for member ID {$member_id}.");
            } else {
                error_log("[VMS][SMS][Warning] SMS sending function returned false for {$phone_number}.");
            }
        } catch (\Throwable $e) {
            error_log("[VMS][SMS][Exception] Failed to send SMS: " . $e->getMessage());
        }

        // --- Log function end ---
        error_log("[VMS][SMS] send_reciprocating_registration_sms() completed.");
    }

    /**
     * Handle reciprocating member sign in
     */
    public static function handle_reciprocating_sign_in(): void
    {
        self::verify_ajax_request();
        global $wpdb;
        $errors = [];

        // Log request start
        error_log('--- Reciprocating Sign In Request Start ---');
        error_log('POST Data: ' . print_r($_POST, true));

        $member_id = absint($_POST['member_id'] ?? 0);
        $member_number = sanitize_text_field($_POST['member_number'] ?? '');
        $club_id = absint($_POST['club_id'] ?? 0);
        $visit_purpose = sanitize_text_field($_POST['visit_purpose'] ?? '');

        if (!$member_id) $errors[] = 'Invalid member ID';
        if (!in_array($visit_purpose, ['golf_tournament', 'casual_visit'])) {
            $errors[] = 'Invalid visit purpose';
        }
        if (!empty($errors)) {
            error_log('Validation Errors: ' . implode(', ', $errors));
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        $visits_table  = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $clubs_table   = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        // --- Get Member -------------------------------------------------------------
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $members_table WHERE id = %d",
            $member_id
        ));

        if (!$member) {
            error_log("Member not found: ID {$member_id}");
            wp_send_json_error(['messages' => ['Member not found']]);
            return;
        }

        error_log("Member found: {$member->first_name} {$member->last_name}, Status: {$member->member_status}");

        // Validate status
        if ($member->member_status !== 'active') {
            error_log("Inactive member ID {$member_id}");
            wp_send_json_error(['messages' => ['Member is not active']]);
            return;
        }

        // --- Handle Club and Member Number ------------------------------------------
        $updates = [];
        $formats = [];

        // Handle club
        if (!$member->reciprocating_club_id) {
            if (!$club_id) {
                error_log("Missing club ID for member {$member_id}");
                wp_send_json_error(['messages' => ['Reciprocating club is required']]);
                return;
            }
            $updates['reciprocating_club_id'] = $club_id;
            $formats[] = '%d';
            $member->reciprocating_club_id = $club_id;
            error_log("Club ID set to {$club_id} for member {$member_id}");
        }

        // Handle member number
        if (empty($member->reciprocating_member_number)) {
            if (empty($member_number)) {
                error_log("Missing member number for member {$member_id}");
                wp_send_json_error(['messages' => ['Member number is required']]);
                return;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $members_table WHERE reciprocating_member_number = %s",
                $member_number
            ));
            error_log("Checking if member number {$member_number} exists: {$exists}");

            if ($exists) {
                error_log("Duplicate member number {$member_number}");
                wp_send_json_error(['messages' => ['This member number already exists']]);
                return;
            }

            $updates['reciprocating_member_number'] = $member_number;
            $formats[] = '%s';
            $member->reciprocating_member_number = $member_number;
            error_log("Member number set to {$member_number} for member {$member_id}");
        } else {
            if ($member->reciprocating_member_number != $member_number) {
                error_log("Invalid member number: expected {$member->reciprocating_member_number}, got {$member_number}");
                wp_send_json_error(['messages' => ['Invalid member number']]);
                return;
            }
        }

        // Execute single update if needed
        if (!empty($updates)) {
            $wpdb->update(
                $members_table,
                $updates,
                ['id' => $member_id],
                $formats,
                ['%d']
            );
            error_log("Member {$member_id} updated in DB with: " . json_encode($updates));
        }        

        // --- Check Visit for Today ---------------------------------------------------
        $visit_date = date('Y-m-d');
        error_log("Checking approved visit for member {$member_id} on {$visit_date}");

        $visit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $visits_table WHERE member_id = %d AND visit_date = %s AND status = 'approved'",
            $member_id, $visit_date
        ));

        if (!$visit) {
            error_log("No approved visit found for member {$member_id} on {$visit_date}");
            wp_send_json_error(['messages' => ['No approved visit found for today']]);
            return;
        }

        // Check if already signed in
        if (!empty($visit->sign_in_time)) {
            error_log("Member {$member_id} already signed in at {$visit->sign_in_time}");
            wp_send_json_error(['messages' => ['Member already signed in']]);
            return;
        }

        // --- Update Sign In Time -----------------------------------------------------        
        $result = $wpdb->update(
            $visits_table,
            [
                'sign_in_time' => current_time('mysql'),
                'visit_purpose' => $visit_purpose
            ],
            ['id' => $visit->id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            error_log("Failed to update sign_in_time for visit ID {$visit->id}");
            wp_send_json_error(['messages' => ['Failed to sign in']]);
            return;
        }

        error_log("Sign-in time updated successfully for visit ID {$visit->id}");

        // --- Fetch Club Name ---------------------------------------------------------
        $club_name = $wpdb->get_var($wpdb->prepare(
            "SELECT club_name FROM $clubs_table WHERE id = %d",
            $member->reciprocating_club_id
        ));

        error_log("Fetched club name: " . ($club_name ?: 'Unknown Club'));

        // --- Fetch Full Visit Details ------------------------------------------------
        $visit_with_details = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, m.first_name, m.last_name, m.phone_number, m.receive_messages
            FROM $visits_table v
            JOIN $members_table m ON v.member_id = m.id
            WHERE v.id = %d",
            $visit->id
        ));

        error_log("Visit details: " . print_r($visit_with_details, true));

        // Check if this is the 24th casual visit for the year
        $year_start = date('Y-01-01');
        $year_end = date('Y-12-31');
        $count_casual_signed_in = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $visits_table
            WHERE member_id = %d AND visit_purpose = 'casual_visit' AND DATE(visit_date) BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
            $member_id, $year_start, $year_end
        ));
        $is_final = ($count_casual_signed_in == 24);

        self::send_reciprocating_sign_in_sms($visit_with_details, $is_final);
        error_log("Sent reciprocating sign-in SMS for member {$member_id}");

        // --- Response ---------------------------------------------------------------
        error_log("--- Reciprocating Sign In Completed Successfully for Member ID {$member_id} ---");

        wp_send_json_success([
            'messages' => ['Signed in successfully'],
            'memberData' => [
                'id'                          => $member_id,
                'visit_id'                    => $visit->id,
                'first_name'                  => $member->first_name,
                'last_name'                   => $member->last_name,
                'phone_number'                => $member->phone_number,
                'id_number'                   => $member->id_number,
                'visit_date'                  => $visit_date,
                'visit_purpose'               => $visit_purpose,
                'status'                      => 'approved',
                'member_status'               => $member->member_status,
                'club_id'                     => $member->reciprocating_club_id,
                'club_name'                   => $club_name ?: 'Unknown Club',
                'reciprocating_member_number' => $member->reciprocating_member_number,
                'sign_in_time'                => current_time('mysql')
            ]
        ]);
    }

    /**
     * Get Reciprocating Clubs via AJAX
     * - Uses transient cache for speed
     * - Rebuilds transient automatically if missing or expired
     */
    public static function get_reciprocating_clubs(): void 
    {
        self::verify_ajax_request();
        global $wpdb;

        $transient_key  = 'vms_reciprocating_clubs_cache';
        $cache_duration = HOUR_IN_SECONDS * 6;
        $clubs_table    = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);

        // --- Try serving from transient ---
        $cached_clubs = get_transient($transient_key);
        if ($cached_clubs !== false && !empty($cached_clubs)) {
            error_log('[VMS] [Clubs] Cache hit — serving from transient.');
            wp_send_json_success(['clubs' => $cached_clubs]);
            return;
        }

        // --- Cache miss, rebuild from DB ---
        error_log('[VMS] [Clubs] Cache miss — rebuilding transient...');

        $clubs = $wpdb->get_results("
            SELECT id, club_name 
            FROM {$clubs_table} 
            WHERE status = 'active' 
            ORDER BY club_name ASC
        ", ARRAY_A);

        if ($wpdb->last_error) {
            error_log('[VMS] [Clubs] DB Error while fetching: ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Database error while fetching clubs.']);
            return;
        }

        if (empty($clubs)) {
            error_log('[VMS] [Clubs] No active clubs found.');
            wp_send_json_error(['message' => 'No active clubs found.']);
            return;
        }

        // --- Save to transient for next time ---
        $set_result = set_transient($transient_key, $clubs, $cache_duration);

        if ($set_result) {
            error_log('[VMS] [Clubs] Transient created successfully with ' . count($clubs) . ' clubs.');
        } else {
            error_log('[VMS] [Clubs] Failed to set transient.');
        }

        wp_send_json_success(['clubs' => $clubs]);
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
     * Send sign-in SMS notification
     */
    private static function send_reciprocating_sign_in_sms($visit, bool $is_final = false): void
    {
        // --- Initial Log ---
        error_log('[VMS_SMS] Preparing to send reciprocating sign-in SMS...');

        if (empty($visit)) {
            error_log('[VMS_SMS] Error: $visit object is empty or null.');
            return;
        }

        error_log('[VMS_SMS] Visit Data: ' . print_r($visit, true));

        // --- Message Opt-out ---
        if ($visit->receive_messages !== 'yes') {
            error_log("[VMS_SMS] Member opted out of SMS notifications. Skipping SMS for Member ID: {$visit->member_id}");
            return;
        }

        try {
            $site_name = get_bloginfo('name');
            $sign_in_time = date('g:i A', strtotime(current_time('mysql')));

            $sms_message = "{$site_name}: Hello {$visit->first_name}, ";
            $sms_message .= "you have signed in successfully at {$sign_in_time}. ";
            $sms_message .= "Enjoy your visit!";

            // Add alert for final casual visit
            if ($is_final) {
                $sms_message .= " Note: This is your final casual visit for the year. For the rest of the year, you will only be allowed to enter for golf tournaments only.";
            }

            error_log("[VMS_SMS] Sending sign-in SMS to: {$visit->phone_number}");
            error_log("[VMS_SMS] SMS Message: {$sms_message}");

            // --- SMS Send Logic ---
            $result = VMS_SMS::send_sms(
                $visit->phone_number,
                $sms_message,
                $visit->member_id,
                'reciprocating_member'
            );

            error_log("[VMS_SMS] SMS send result: " . print_r($result, true));

        } catch (Throwable $e) {
            error_log('[VMS_SMS] Exception while sending sign-in SMS: ' . $e->getMessage());
            error_log('[VMS_SMS] Stack trace: ' . $e->getTraceAsString());
        }

        error_log('[VMS_SMS] Sign-in SMS process completed.');
    }

    /**
     * Send sign-out SMS notification
     */
    private static function send_reciprocating_sign_out_sms($visit): void
    {
        // --- Initial Log ---
        error_log('[VMS_SMS] Preparing to send reciprocating sign-out SMS...');

        if (empty($visit)) {
            error_log('[VMS_SMS] Error: $visit object is empty or null.');
            return;
        }

        error_log('[VMS_SMS] Visit Data: ' . print_r($visit, true));

        // --- Message Opt-out ---
        if ($visit->receive_messages !== 'yes') {
            error_log("[VMS_SMS] Member opted out of SMS notifications. Skipping sign-out SMS for Member ID: {$visit->member_id}");
            return;
        }

        try {
            $site_name = get_bloginfo('name');
            $sign_out_time = date('g:i A', strtotime(current_time('mysql')));

            $sms_message = "{$site_name}: Hello {$visit->first_name}, ";
            $sms_message .= "you have signed out at {$sign_out_time}. ";
            $sms_message .= "Thank you for visiting!";

            error_log("[VMS_SMS] Sending sign-out SMS to: {$visit->phone_number}");
            error_log("[VMS_SMS] SMS Message: {$sms_message}");

            // --- SMS Send Logic ---
            $result = VMS_SMS::send_sms(
                $visit->phone_number,
                $sms_message,
                $visit->member_id,
                'reciprocating_member'
            );

            error_log("[VMS_SMS] SMS send result: " . print_r($result, true));
            
        } catch (Throwable $e) {
            error_log('[VMS_SMS] Exception while sending sign-out SMS: ' . $e->getMessage());
            error_log('[VMS_SMS] Stack trace: ' . $e->getTraceAsString());
        }

        error_log('[VMS_SMS] Sign-out SMS process completed.');
    }

   /**
     * Handle visit registration via AJAX - with detailed error logs
     */
    public static function handle_reciprocation_member_visit_registration()
    {
        global $wpdb;

        error_log('--- START handle_reciprocation_member_visit_registration ---');

        try {
            $member_id  = isset($_POST['member_id']) ? absint($_POST['member_id']) : 0;
            $visit_date = sanitize_text_field($_POST['visit_date'] ?? '');
            error_log("Received member_id={$member_id}, visit_date={$visit_date}");

            $errors = [];

            // Validate member
            if ($member_id <= 0) {
                $errors[] = 'Member is required';
                error_log('Error: member_id missing or invalid');
            } else {
                $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
                $member_exists = $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM $recip_members_table WHERE id = %d", $member_id)
                );
                error_log("Member existence check: {$member_exists}");
                if (!$member_exists) {
                    $errors[] = 'Invalid member selected';
                }
            }

            // Validate visit date
            if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
                $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
                error_log('Error: invalid date format');
            } else {
                $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
                $current_date   = new \DateTime(current_time('Y-m-d'));
                if (!$visit_date_obj || $visit_date_obj < $current_date) {
                    $errors[] = 'Visit date cannot be in the past';
                    error_log('Error: visit date is in the past');
                }
            }

            if (!empty($errors)) {
                error_log('Validation errors: ' . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
            }

            $table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
            $members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

            // Get member info
            $member_info = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name, email, phone_number, receive_emails, receive_messages 
                FROM $members_table WHERE id = %d",
                $member_id
            ));
            error_log('Fetched member info: ' . print_r($member_info, true));

            // Check duplicate
            $existing_visit = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE member_id = %d AND visit_date = %s",
                $member_id, $visit_date
            ));
            error_log('Existing visit: ' . print_r($existing_visit, true));

            if ($existing_visit && $existing_visit->status !== 'cancelled') {
                error_log('Duplicate visit detected.');
                wp_send_json_error(['messages' => ['This member already has a visit registered on this date']]);
            }

            // Limits - Only check yearly casual visits
            $year_start  = date('Y-01-01', strtotime($visit_date));
            $year_end    = date('Y-12-31', strtotime($visit_date));
            $today = date('Y-m-d');

            $yearly_casual_visits = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                WHERE member_id = %d AND visit_date BETWEEN %s AND %s
                AND (visit_purpose IS NULL OR visit_purpose = 'casual_visit')
                AND ((status = 'approved' AND visit_date >= %s) OR (visit_date < %s AND sign_in_time IS NOT NULL))",
                $member_id, $year_start, $year_end, $today, $today
            ));
            error_log("Yearly casual visits count: {$yearly_casual_visits}");

            $yearly_limit  = 24;

            if ($yearly_casual_visits >= $yearly_limit) {
                error_log('Yearly casual limit reached.');
                wp_send_json_error(['messages' => ['This member has reached the yearly casual visit limit']]);
            }

            $preliminary_status = 'approved';
            if (($yearly_casual_visits + 1) > $yearly_limit) {
                $preliminary_status = 'unapproved';
            }
            error_log("Preliminary status set to: {$preliminary_status}");

            // Insert or reuse cancelled visit
            if ($existing_visit && $existing_visit->status === 'cancelled') {
                error_log('Reusing cancelled visit row...');
                $updated = $wpdb->update(
                    $table,
                    [
                        'status'        => $preliminary_status,
                        'sign_in_time'  => null,
                        'sign_out_time' => null,
                    ],
                    ['id' => $existing_visit->id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                error_log('Update result: ' . var_export($updated, true));

                if ($updated === false) {
                    wp_send_json_error(['messages' => ['Failed to update cancelled visit']]);
                }

                $visit_id = $existing_visit->id;
            } else {
                error_log('Inserting new visit...');
                $inserted = $wpdb->insert(
                    $table,
                    [
                        'member_id'  => $member_id,
                        'visit_date' => $visit_date,
                        'status'     => $preliminary_status,
                    ],
                    ['%d', '%s', '%s']
                );
                error_log('Insert result: ' . var_export($inserted, true));

                if (!$inserted) {
                    error_log('Insert failed. DB Error: ' . $wpdb->last_error);
                    wp_send_json_error(['messages' => ['Failed to register visit']]);
                }

                $visit_id = $wpdb->insert_id;
            }

            // Send notifications
            if ($member_info) {
                error_log("Sending notifications for visit ID {$visit_id}");
                if (!empty($member_info->email) && is_email($member_info->email)) {
                    error_log("Sending email to {$member_info->email}");
                    self::send_member_visit_registration_emails(
                        $member_info->first_name,
                        $member_info->last_name,
                        $member_info->email,
                        $member_info->receive_emails,
                        $visit_date,
                        $preliminary_status
                    );
                }

                error_log("Sending SMS to {$member_info->phone_number}");
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
            error_log('Final visit data: ' . print_r($visit, true));

            $status       = VMS_Core::get_visit_status($visit->visit_date, $visit->sign_in_time, $visit->sign_out_time);
            $status_class = VMS_Core::get_status_class($status);
            $status_text  = VMS_Core::get_status_text($status);

            error_log('Visit successfully registered. Returning JSON success.');

            wp_send_json_success([
                'id'            => $visit->id,
                'visit_date'    => VMS_Core::format_date($visit->visit_date),
                'sign_in_time'  => VMS_Core::format_time($visit->sign_in_time),
                'sign_out_time' => VMS_Core::format_time($visit->sign_out_time),
                'duration'      => VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time),
                'status'        => $preliminary_status,
                'status_class'  => $status_class,
                'status_text'   => $status_text,
                'messages'      => ['Visit registered successfully']
            ]);

        } catch (Throwable $e) {
            error_log('ERROR in handle_reciprocation_member_visit_registration: ' . $e->getMessage());
            error_log('Trace: ' . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['Server error: ' . $e->getMessage()]]);
        }

        error_log('--- END handle_reciprocation_member_visit_registration ---');
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

            VMS_SMS::send_sms($member_phone, $member_message, $member_id, $role);
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

                VMS_SMS::send_sms($admin_phone, $admin_message, $admin->ID, 'administrator');
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
     * Automatically sign out reciprocating members at midnight
     */
    public static function auto_sign_out_recip_members()
    {
        global $wpdb;
        error_log('Auto sign out recip members');
        
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        
        // Get yesterday's date (day that just ended)
        $yesterday = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
        
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, member_id, visit_date
            FROM $recip_visits_table
            WHERE sign_in_time IS NOT NULL
            AND sign_out_time IS NULL
            AND DATE(visit_date) = %s",
            $yesterday
        ));
        
        if (empty($visits)) {
            error_log('No recip members to sign out');
            return;
        }
        
        foreach ($visits as $visit) {
            // Set sign_out_time to 23:59:59 of visit_date
            $midnight = $visit->visit_date . ' 23:59:59';
            
            $updated = $wpdb->update(
                $recip_visits_table,
                ['sign_out_time' => $midnight],
                ['id' => $visit->id],
                ['%s'],
                ['%d']
            );
            
            if ($updated === false) {
                error_log("Failed to sign out recip member visit ID: {$visit->id}");
            } else {
                error_log("Signed out recip member visit ID: {$visit->id}");
            }
        }
    }  

    /**
     * Reset yearly guest limits (only for automatically suspended guests)
     */
    public static function reset_yearly_limits()
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);

        // Only reset status for guests who are automatically suspended but have active guest_status
        $wpdb->query(
            "UPDATE $guests_table
            SET status = 'approved'
            WHERE status = 'suspended'
            AND member_status = 'active'"
        );
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