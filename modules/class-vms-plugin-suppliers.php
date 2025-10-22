<?php
/**
 * Suppliers functionality handler for VMS plugin
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

class VMS_Suppliers extends Base
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
        self::setup_suppliers_management_hooks();
    }  

    /**
     * Setup guest management related hooks
     */
    private static function setup_suppliers_management_hooks(): void
    {      
        // Guest
        add_action('wp_ajax_guest_registration', [self::class, 'handle_guest_registration']);
        add_action('wp_ajax_courtesy_guest_registration', [self::class, 'handle_courtesy_guest_registration']);
        add_action('wp_ajax_update_guest', [self::class, 'handle_guest_update']);
        add_action('wp_ajax_delete_guest', [self::class, 'handle_guest_deletion']);
        
        add_action('wp_ajax_register_visit', [self::class, 'handle_visit_registration']);

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
    } 

    /**
     * Handle visit cancellation via AJAX - UPDATED with error logging and safeguards
     */
    public static function handle_visit_cancellation(): void
    {
        try {
            // Verify AJAX nonce and capability
            self::verify_ajax_request();

            error_log("[VMS] handle_visit_cancellation called at " . current_time('mysql'));

            // Sanitize and validate visit ID
            $visit_id = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
            if (!$visit_id) {
                wp_send_json_error(['messages' => ['Invalid visit ID']]);
                return;
            }

            global $wpdb;
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
            $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            // Fetch visit and guest details
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

            // Store old status for later notifications
            $old_status = $visit->status;

            // Update visit record to cancelled
            $updated = $wpdb->update(
                $guest_visits_table,
                ['status' => 'cancelled'],
                ['id' => $visit_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log("[VMS] Visit cancellation failed (DB error) for visit ID: {$visit_id}");
                wp_send_json_error(['messages' => ['Failed to cancel visit']]);
                return;
            }

            // ✅ Recalculate guest visit status and host daily limit after cancellation
            try {
                self::recalculate_guest_visit_statuses($visit->guest_id);
            } catch (Throwable $e) {
                error_log("[VMS] Error recalculating guest visit statuses for guest ID {$visit->guest_id}: " . $e->getMessage());
            }

            if (!empty($visit->host_member_id)) {
                try {
                    self::recalculate_host_daily_limits($visit->host_member_id, $visit->visit_date);
                } catch (Throwable $e) {
                    error_log("[VMS] Error recalculating host limits for host ID {$visit->host_member_id}: " . $e->getMessage());
                }
            }

            // ✅ Prepare guest and visit data for notifications
            $guest_data = [
                'first_name'       => $visit->first_name,
                'phone_number'     => $visit->phone_number,
                'email'            => $visit->email,
                'receive_messages' => $visit->receive_messages,
                'receive_emails'   => $visit->receive_emails,
                'user_id'          => $visit->guest_id
            ];

            $visit_data = [
                'visit_date'      => $visit->visit_date,
                'host_member_id'  => $visit->host_member_id
            ];

            // ✅ Send SMS notification
            try {
                VMS_SMS::get_instance()->send_visit_status_notification(
                    $guest_data,
                    $visit_data,
                    $old_status,
                    'cancelled'
                );
            } catch (Throwable $e) {
                error_log("[VMS] Failed to send SMS cancellation notice for visit ID {$visit_id}: " . $e->getMessage());
            }

            // ✅ Send Email notification
            try {
                self::send_visit_cancellation_email($guest_data, $visit_data);
            } catch (Throwable $e) {
                error_log("[VMS] Failed to send email cancellation notice for visit ID {$visit_id}: " . $e->getMessage());
            }

            // ✅ Return success response
            wp_send_json_success(['messages' => ['Visit cancelled successfully']]);

        } catch (Throwable $e) {
            // Catch all unexpected exceptions and log them
            error_log("[VMS] Unexpected error in handle_visit_cancellation: " . $e->getMessage());
            wp_send_json_error(['messages' => ['An unexpected error occurred while cancelling the visit. Please try again.']]);
        }
    }

    /**
     * Handle guest status update via AJAX - UPDATED with error logging and try/catch
     */
    public static function handle_guest_status_update(): void
    {
        // Always log entry point for visibility
        error_log("[VMS] handle_guest_status_update() called at " . current_time('mysql'));

        try {
            // Verify AJAX request
            self::verify_ajax_request();

            // Sanitize and validate input
            $guest_id   = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
            $new_status = sanitize_text_field($_POST['guest_status'] ?? '');

            // Log the incoming request data
            error_log("[VMS] Guest status update request: guest_id={$guest_id}, new_status={$new_status}");

            if (!$guest_id || !in_array($new_status, ['active', 'suspended', 'banned'], true)) {
                error_log("[VMS] Invalid parameters received for guest status update");
                wp_send_json_error(['messages' => ['Invalid parameters']]);
                return;
            }

            global $wpdb;
            $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            // Fetch current guest data
            $guest = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$guests_table} WHERE id = %d",
                $guest_id
            ));

            if (!$guest) {
                error_log("[VMS] Guest not found for guest_id={$guest_id}");
                wp_send_json_error(['messages' => ['Guest not found']]);
                return;
            }

            $old_status = $guest->guest_status;

            // Log before DB update
            error_log("[VMS] Updating guest ID {$guest_id} from '{$old_status}' to '{$new_status}'");

            // Update guest record
            $updated = $wpdb->update(
                $guests_table,
                [
                    'guest_status' => $new_status,
                    'updated_at'   => current_time('mysql')
                ],
                ['id' => $guest_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log("[VMS] Failed to update guest status for guest_id={$guest_id}. DB error: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to update guest status']]);
                return;
            }

            // ✅ Recalculate guest visit statuses
            try {
                self::recalculate_guest_visit_statuses($guest_id);
            } catch (Throwable $e) {
                error_log("[VMS] Error recalculating guest visit statuses for guest_id={$guest_id}: " . $e->getMessage());
            }

            // Prepare guest data for notifications
            $guest_data = [
                'first_name'       => $guest->first_name,
                'phone_number'     => $guest->phone_number,
                'email'            => $guest->email,
                'receive_messages' => $guest->receive_messages,
                'receive_emails'   => $guest->receive_emails,
                'user_id'          => $guest_id
            ];

            // ✅ Send SMS + Email notifications with independent error handling
            try {
                VMS_SMS::get_instance()->send_guest_status_notification(
                    $guest_data,
                    $old_status,
                    $new_status
                );
                error_log("[VMS] Guest status SMS sent successfully for guest_id={$guest_id}");
            } catch (Throwable $e) {
                error_log("[VMS] Failed to send guest status SMS for guest_id={$guest_id}: " . $e->getMessage());
            }

            try {
                self::send_guest_status_change_email($guest_data, $old_status, $new_status);
                error_log("[VMS] Guest status email sent successfully for guest_id={$guest_id}");
            } catch (Throwable $e) {
                error_log("[VMS] Failed to send guest status email for guest_id={$guest_id}: " . $e->getMessage());
            }

            try {
                self::send_guest_status_change_sms($guest_data, $old_status, $new_status);
                error_log("[VMS] Guest status follow-up SMS sent successfully for guest_id={$guest_id}");
            } catch (Throwable $e) {
                error_log("[VMS] Failed to send follow-up SMS for guest_id={$guest_id}: " . $e->getMessage());
            }

            // ✅ Success
            error_log("[VMS] Guest status updated successfully for guest_id={$guest_id} ({$old_status} → {$new_status})");
            wp_send_json_success(['messages' => ['Guest status updated successfully']]);

        } catch (Throwable $e) {
            // Global error catch
            error_log("[VMS] Unexpected error in handle_guest_status_update: " . $e->getMessage());
            wp_send_json_error(['messages' => ['An unexpected error occurred while updating guest status. Please try again.']]);
        }
    }

    /**
     * Handle guest sign-in via AJAX with strict ID number validation
     * and robust error handling.
     */
    public static function handle_sign_in_guest(): void
    {
        self::verify_ajax_request();

        try {
            // -------------------------------------------------------------
            // 1. Input validation
            // -------------------------------------------------------------
            $visit_id  = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
            $id_number = sanitize_text_field($_POST['id_number'] ?? '');

            error_log("[Guest Sign-In] Received request: visit_id={$visit_id}, id_number={$id_number}");

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
            $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            // -------------------------------------------------------------
            // 2. Fetch visit and guest data
            // -------------------------------------------------------------
            $visit = $wpdb->get_row($wpdb->prepare(
                "SELECT gv.*, g.id_number, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails, g.guest_status
                FROM {$guest_visits_table} gv
                LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
                WHERE gv.id = %d",
                $visit_id
            ));

            if (!$visit) {
                error_log("[Guest Sign-In] No visit found for ID {$visit_id}");
                wp_send_json_error(['messages' => ['Visit not found']]);
                return;
            }

            // -------------------------------------------------------------
            // 3. Validate ID number
            // -------------------------------------------------------------
            if (!empty($visit->id_number)) {
                if ($visit->id_number !== $id_number) {
                    error_log("[Guest Sign-In] Mismatched ID number for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['ID number does not match the registered guest record']]);
                    return;
                }
            } else {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$guests_table} WHERE id_number = %s",
                    $id_number
                ));

                if ($existing > 0) {
                    error_log("[Guest Sign-In] Duplicate ID number {$id_number} detected for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['This ID number is already registered with another guest']]);
                    return;
                }

                // Safe to update guest record
                $updated = $wpdb->update(
                    $guests_table,
                    ['id_number' => $id_number],
                    ['id' => $visit->guest_id],
                    ['%s'],
                    ['%d']
                );

                if ($updated === false) {
                    error_log("[Guest Sign-In] Failed to update ID number for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['Failed to save ID number']]);
                    return;
                }

                $visit->id_number = $id_number;
                error_log("[Guest Sign-In] ID number saved successfully for guest_id={$visit->guest_id}");
            }

            // -------------------------------------------------------------
            // 4. Prevent duplicate sign-ins
            // -------------------------------------------------------------
            if (!empty($visit->sign_in_time)) {
                error_log("[Guest Sign-In] Guest already signed in: guest_id={$visit->guest_id}");
                wp_send_json_error(['messages' => ['Guest already signed in']]);
                return;
            }

            // -------------------------------------------------------------
            // 5. Check guest status
            // -------------------------------------------------------------
            if (in_array($visit->guest_status, ['banned', 'suspended'])) {
                error_log("[Guest Sign-In] Restricted guest: guest_id={$visit->guest_id}, status={$visit->guest_status}");
                wp_send_json_error(['messages' => ['Guest access is restricted due to status: ' . $visit->guest_status]]);
                return;
            }

            // -------------------------------------------------------------
            // 6. Ensure visit date matches today
            // -------------------------------------------------------------
            $current_date = current_time('Y-m-d');
            $visit_date   = date('Y-m-d', strtotime($visit->visit_date));

            if ($visit_date !== $current_date) {
                error_log("[Guest Sign-In] Wrong visit date for guest_id={$visit->guest_id}. Expected {$current_date}, got {$visit_date}");
                wp_send_json_error(['messages' => ['Guest can only sign in on their scheduled visit date']]);
                return;
            }

            // -------------------------------------------------------------
            // 7. Update sign-in timestamp
            // -------------------------------------------------------------
            $signin_time = current_time('mysql');
            $updated     = $wpdb->update(
                $guest_visits_table,
                ['sign_in_time' => $signin_time],
                ['id' => $visit_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log("[Guest Sign-In] Failed to update sign_in_time for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Failed to sign in guest']]);
                return;
            }

            error_log("[Guest Sign-In] Guest sign-in recorded successfully for guest_id={$visit->guest_id}");

            // -------------------------------------------------------------
            // 8. Send notifications (wrapped safely)
            // -------------------------------------------------------------
            try {
                $guest_data = [
                    'first_name'       => $visit->first_name,
                    'phone_number'     => $visit->phone_number,
                    'email'            => $visit->email,
                    'receive_messages' => $visit->receive_messages,
                    'receive_emails'   => $visit->receive_emails,
                    'user_id'          => $visit->guest_id
                ];

                $visit_data = [
                    'sign_in_time' => $signin_time,
                    'visit_date'   => $visit->visit_date
                ];

                error_log("[Guest Sign-In] Sending notifications for guest_id={$visit->guest_id}");
                VMS_SMS::get_instance()->send_signin_notification($guest_data, $visit_data);
                self::send_signin_email_notification($guest_data, $visit_data);
                error_log("[Guest Sign-In] Notifications sent successfully for guest_id={$visit->guest_id}");
            } catch (Throwable $notify_error) {
                error_log("[Guest Sign-In] Notification error for guest_id={$visit->guest_id}: " . $notify_error->getMessage());
                error_log("[Guest Sign-In] Trace: " . $notify_error->getTraceAsString());
                // Continue — notification failure shouldn’t block sign-in
            }

            // -------------------------------------------------------------
            // 9. Prepare and send successful response
            // -------------------------------------------------------------
            $host_member = get_user_by('id', $visit->host_member_id);
            $host_name   = $host_member ? $host_member->display_name : 'N/A';

            $guest_data_response = [
                'id'           => $visit->guest_id,
                'first_name'   => $visit->first_name,
                'last_name'    => $visit->last_name,
                'sign_in_time' => $signin_time,
                'visit_id'     => $visit_id,
                'id_number'    => $visit->id_number
            ];

            error_log("[Guest Sign-In] Success response prepared for guest_id={$visit->guest_id}");
            wp_send_json_success([
                'messages'  => ['Guest signed in successfully'],
                'guestData' => $guest_data_response
            ]);

        } catch (Throwable $e) {
            // -------------------------------------------------------------
            // Global fail-safe for unexpected runtime errors
            // -------------------------------------------------------------
            error_log("[Guest Sign-In] Fatal error: " . $e->getMessage());
            error_log("[Guest Sign-In] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred. Please try again.']]);
        }
    }

    /**
     * Handle guest sign-out via AJAX with logging, validation, and safe notification handling
     */
    public static function handle_sign_out_guest(): void
    {
        self::verify_ajax_request();

        try {
            // -------------------------------------------------------------
            // 1. Validate input
            // -------------------------------------------------------------
            $visit_id = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
            error_log("[Guest Sign-Out] Received request for visit_id={$visit_id}");

            if (!$visit_id) {
                wp_send_json_error(['messages' => ['Invalid visit ID']]);
                return;
            }

            global $wpdb;
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
            $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            // -------------------------------------------------------------
            // 2. Fetch visit and guest details
            // -------------------------------------------------------------
            $visit = $wpdb->get_row($wpdb->prepare(
                "SELECT gv.*, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
                FROM {$guest_visits_table} gv
                LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
                WHERE gv.id = %d",
                $visit_id
            ));

            if (!$visit) {
                error_log("[Guest Sign-Out] Visit not found for ID {$visit_id}");
                wp_send_json_error(['messages' => ['Visit not found']]);
                return;
            }

            // -------------------------------------------------------------
            // 3. Validate sign-in/out state
            // -------------------------------------------------------------
            if (empty($visit->sign_in_time)) {
                error_log("[Guest Sign-Out] Guest not signed in yet for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Guest must be signed in first']]);
                return;
            }

            if (!empty($visit->sign_out_time)) {
                error_log("[Guest Sign-Out] Guest already signed out for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Guest already signed out']]);
                return;
            }

            // -------------------------------------------------------------
            // 4. Update sign-out time
            // -------------------------------------------------------------
            $signout_time = current_time('mysql');
            $updated      = $wpdb->update(
                $guest_visits_table,
                ['sign_out_time' => $signout_time],
                ['id' => $visit_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log("[Guest Sign-Out] Database update failed for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Failed to sign out guest']]);
                return;
            }

            error_log("[Guest Sign-Out] Guest signed out successfully for guest_id={$visit->guest_id}");

            // -------------------------------------------------------------
            // 5. Prepare notification data
            // -------------------------------------------------------------
            $guest_data = [
                'first_name'       => $visit->first_name,
                'phone_number'     => $visit->phone_number,
                'email'            => $visit->email,
                'receive_messages' => $visit->receive_messages,
                'receive_emails'   => $visit->receive_emails,
                'user_id'          => $visit->guest_id
            ];

            $visit_data = [
                'sign_out_time' => $signout_time,
                'sign_in_time'  => $visit->sign_in_time
            ];

            // -------------------------------------------------------------
            // 6. Send notifications safely
            // -------------------------------------------------------------
            try {
                error_log("[Guest Sign-Out] Sending sign-out notifications for guest_id={$visit->guest_id}");
                VMS_SMS::get_instance()->send_signout_notification($guest_data, $visit_data);
                self::send_signout_email_notification($guest_data, $visit_data);
                error_log("[Guest Sign-Out] Notifications sent successfully for guest_id={$visit->guest_id}");
            } catch (Throwable $notify_error) {
                error_log("[Guest Sign-Out] Notification error for guest_id={$visit->guest_id}: " . $notify_error->getMessage());
                error_log("[Guest Sign-Out] Trace: " . $notify_error->getTraceAsString());
            }

            // -------------------------------------------------------------
            // 7. Prepare response
            // -------------------------------------------------------------
            $guest_data_response = [
                'id'            => $visit->guest_id,
                'first_name'    => $visit->first_name,
                'last_name'     => $visit->last_name,
                'sign_in_time'  => $visit->sign_in_time,
                'sign_out_time' => $signout_time,
                'visit_id'      => $visit_id
            ];

            wp_send_json_success([
                'messages'  => ['Guest signed out successfully'],
                'guestData' => $guest_data_response
            ]);

        } catch (Throwable $e) {
            // -------------------------------------------------------------
            // Global fallback
            // -------------------------------------------------------------
            error_log("[Guest Sign-Out] Fatal error: " . $e->getMessage());
            error_log("[Guest Sign-Out] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred during sign-out. Please try again.']]);
        }
    }

    /**
     * Recalculate guest visit statuses and enforce limits (with notifications & logging)
     */
    public static function recalculate_guest_visit_statuses(int $guest_id): void
    {
        global $wpdb;

        try {
            error_log("[Recalculate Status] Starting recalculation for guest_id={$guest_id}");

            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
            $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
            $monthly_limit      = 4;
            $yearly_limit       = 12;

            // -------------------------------------------------------------
            // 1. Get guest data
            // -------------------------------------------------------------
            $guest = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$guests_table} WHERE id = %d",
                $guest_id
            ));

            if (!$guest) {
                error_log("[Recalculate Status] Guest not found for ID {$guest_id}");
                return;
            }

            $old_guest_status = $guest->guest_status;

            // -------------------------------------------------------------
            // 2. Fetch all relevant visits
            // -------------------------------------------------------------
            $visits = $wpdb->get_results($wpdb->prepare(
                "SELECT id, visit_date, sign_in_time, status, host_member_id 
                FROM {$guest_visits_table}
                WHERE guest_id = %d AND status != 'cancelled'
                ORDER BY visit_date ASC",
                $guest_id
            ));

            if (!$visits) {
                error_log("[Recalculate Status] No active visits found for guest_id={$guest_id}");
                return;
            }

            $monthly_scheduled = [];
            $yearly_scheduled  = [];
            $status_changes    = [];

            // -------------------------------------------------------------
            // 3. Process each visit
            // -------------------------------------------------------------
            foreach ($visits as $visit) {
                $month_key = date('Y-m', strtotime($visit->visit_date));
                $year_key  = date('Y', strtotime($visit->visit_date));

                $monthly_scheduled[$month_key] = $monthly_scheduled[$month_key] ?? 0;
                $yearly_scheduled[$year_key]   = $yearly_scheduled[$year_key] ?? 0;

                $visit_date_obj = new \DateTime($visit->visit_date);
                $today          = new \DateTime(current_time('Y-m-d'));
                $is_past        = $visit_date_obj < $today;
                $attended       = $is_past && !empty($visit->sign_in_time);

                // Count valid visits (future or attended)
                if (!$is_past || $attended) {
                    $monthly_scheduled[$month_key]++;
                    $yearly_scheduled[$year_key]++;
                }

                // ---------------------------------------------------------
                // Check host daily limit
                // ---------------------------------------------------------
                $host_daily_count = 0;
                if ($visit->host_member_id) {
                    $host_daily_count = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$guest_visits_table}
                        WHERE host_member_id = %d AND visit_date = %s AND status != 'cancelled'",
                        $visit->host_member_id,
                        $visit->visit_date
                    ));
                }

                // ---------------------------------------------------------
                // Determine new status
                // ---------------------------------------------------------
                $old_status = $visit->status;
                $new_status = 'approved';

                if ($monthly_scheduled[$month_key] > $monthly_limit || $yearly_scheduled[$year_key] > $yearly_limit) {
                    $new_status = 'unapproved';
                }

                if ($host_daily_count > 4) {
                    $new_status = 'unapproved';
                }

                // Missed visit cleanup
                if ($is_past && empty($visit->sign_in_time)) {
                    $monthly_scheduled[$month_key]--;
                    $yearly_scheduled[$year_key]--;
                }

                // ---------------------------------------------------------
                // Update DB if changed
                // ---------------------------------------------------------
                if ($old_status !== $new_status) {
                    $wpdb->update(
                        $guest_visits_table,
                        ['status' => $new_status],
                        ['id' => $visit->id],
                        ['%s'],
                        ['%d']
                    );

                    $status_changes[] = [
                        'visit_id'       => $visit->id,
                        'visit_date'     => $visit->visit_date,
                        'old_status'     => $old_status,
                        'new_status'     => $new_status,
                        'host_member_id' => $visit->host_member_id
                    ];

                    error_log("[Recalculate Status] Visit {$visit->id} status changed from {$old_status} to {$new_status}");
                }
            }

            // -------------------------------------------------------------
            // 4. Check and update guest status
            // -------------------------------------------------------------
            $current_month     = date('Y-m');
            $current_year      = date('Y');
            $new_guest_status  = $guest->guest_status;
            $current_monthly   = $monthly_scheduled[$current_month] ?? 0;
            $current_yearly    = $yearly_scheduled[$current_year] ?? 0;

            if ($guest->guest_status === 'active') {
                if ($current_monthly >= $monthly_limit || $current_yearly >= $yearly_limit) {
                    $new_guest_status = 'suspended';
                    error_log("[Recalculate Status] Guest {$guest_id} automatically suspended (monthly={$current_monthly}, yearly={$current_yearly})");
                }
            }

            if ($new_guest_status !== $old_guest_status) {
                $wpdb->update(
                    $guests_table,
                    ['guest_status' => $new_guest_status, 'updated_at' => current_time('mysql')],
                    ['id' => $guest_id],
                    ['%s', '%s'],
                    ['%d']
                );

                try {
                    $guest_data = [
                        'first_name'       => $guest->first_name,
                        'phone_number'     => $guest->phone_number,
                        'email'            => $guest->email,
                        'receive_messages' => $guest->receive_messages,
                        'receive_emails'   => $guest->receive_emails,
                        'user_id'          => $guest_id
                    ];

                    error_log("[Recalculate Status] Sending guest status change notification for guest_id={$guest_id}");
                    VMS_SMS::get_instance()->send_guest_status_notification($guest_data, $old_guest_status, $new_guest_status);
                } catch (Throwable $notify_error) {
                    error_log("[Recalculate Status] Error sending guest status notification: " . $notify_error->getMessage());
                }
            }

            // -------------------------------------------------------------
            // 5. Send visit status change notifications
            // -------------------------------------------------------------
            foreach ($status_changes as $change) {
                try {
                    $guest_data = [
                        'first_name'       => $guest->first_name,
                        'phone_number'     => $guest->phone_number,
                        'email'            => $guest->email,
                        'receive_messages' => $guest->receive_messages,
                        'receive_emails'   => $guest->receive_emails,
                        'user_id'          => $guest_id
                    ];

                    $visit_data = [
                        'visit_date'     => $change['visit_date'],
                        'host_member_id' => $change['host_member_id']
                    ];

                    VMS_SMS::get_instance()->send_visit_status_notification(
                        $guest_data,
                        $visit_data,
                        $change['old_status'],
                        $change['new_status']
                    );

                    error_log("[Recalculate Status] Visit {$change['visit_id']} notification sent ({$change['old_status']} → {$change['new_status']})");

                } catch (Throwable $notify_error) {
                    error_log("[Recalculate Status] Visit {$change['visit_id']} notification failed: " . $notify_error->getMessage());
                }
            }

            error_log("[Recalculate Status] Completed recalculation for guest_id={$guest_id}");

        } catch (Throwable $e) {
            error_log("[Recalculate Status] Fatal error for guest_id={$guest_id}: " . $e->getMessage());
            error_log("[Recalculate Status] Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Recalculate host daily limits - UPDATED with notifications
     */
    public static function recalculate_host_daily_limits(int $host_member_id, string $visit_date): void
    {
        global $wpdb;

        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

        // Get host data for notifications
        $host_user = get_userdata($host_member_id);
        if (!$host_user) {
            return;
        }

        // Get all visits for this host on this date (excluding cancelled)
        $host_visits = $wpdb->get_results($wpdb->prepare(
            "SELECT gv.id, gv.guest_id, gv.status, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
            FROM {$guest_visits_table} gv
            LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
            WHERE gv.host_member_id = %d 
            AND gv.visit_date = %s 
            AND gv.status != 'cancelled'
            ORDER BY gv.created_at ASC",
            $host_member_id,
            $visit_date
        ));

        if (!$host_visits) {
            return;
        }

        $count            = 0;
        $unapproved_count = 0;
        $status_changes   = [];

        foreach ($host_visits as $visit) {
            $count++;
            $old_status = $visit->status;

            // First 4 visits should be approved
            $new_status = $count <= 4 ? 'approved' : 'unapproved';
            if ($new_status === 'unapproved') {
                $unapproved_count++;
            }

            // Only update if status changed
            if ($old_status !== $new_status) {
                // Double-check guest visit limit before approving
                if ($new_status === 'approved') {
                    $guest_status = self::calculate_preliminary_visit_status($visit->guest_id, $visit_date);
                    $new_status   = $guest_status;
                }

                $wpdb->update(
                    $guest_visits_table,
                    ['status' => $new_status],
                    ['id' => $visit->id],
                    ['%s'],
                    ['%d']
                );

                if ($old_status !== $new_status) {
                    $status_changes[] = [
                        'guest_data' => [
                            'first_name'       => $visit->first_name,
                            'phone_number'     => $visit->phone_number,
                            'email'            => $visit->email,
                            'receive_messages' => $visit->receive_messages,
                            'receive_emails'   => $visit->receive_emails,
                            'user_id'          => $visit->guest_id
                        ],
                        'visit_data' => [
                            'visit_date'      => $visit_date,
                            'host_member_id'  => $host_member_id
                        ],
                        'old_status' => $old_status,
                        'new_status' => $new_status
                    ];
                }
            }
        }

        // Notify host if some visits were unapproved due to daily limit
        if ($unapproved_count > 0) {
            $host_data = [
                'user_id'     => $host_member_id,
                'first_name'  => get_user_meta($host_member_id, 'first_name', true) ?: $host_user->display_name,
                'phone_number'=> get_user_meta($host_member_id, 'phone_number', true)
            ];

            VMS_SMS::get_instance()->send_host_limit_notification(
                $host_data,
                $visit_date,
                $unapproved_count
            );
        }

        // Send visit status change notifications
        foreach ($status_changes as $change) {
            VMS_SMS::get_instance()->send_visit_status_notification(
                $change['guest_data'],
                $change['visit_data'],
                $change['old_status'],
                $change['new_status']
            );
        }
    }

    /**
     * Send visit cancellation email notification
     */
    private static function send_visit_cancellation_email(array $guest_data, array $visit_data): void
    {
        global $wpdb;

        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_id     = isset($guest_data['id']) ? absint($guest_data['id']) : 0;

        if (!$guest_id) {
            return;
        }

        // Verify email preference directly from database (avoid stale data)
        $receive_emails = $wpdb->get_var($wpdb->prepare(
            "SELECT receive_emails FROM {$guests_table} WHERE id = %d",
            $guest_id
        ));

        if ($receive_emails !== 'yes') {
            return;
        }

        $formatted_date = date('F j, Y', strtotime($visit_data['visit_date']));
        $subject        = 'Visit Cancellation - Nyeri Club';

        $message  = "Dear {$guest_data['first_name']},\n\n";
        $message .= "Your visit to Nyeri Club scheduled for {$formatted_date} has been cancelled.\n\n";

        if (!empty($visit_data['host_member_id'])) {
            $host = get_userdata($visit_data['host_member_id']);
            if ($host) {
                $host_first = get_user_meta($visit_data['host_member_id'], 'first_name', true);
                $host_last  = get_user_meta($visit_data['host_member_id'], 'last_name', true);
                $host_name  = trim("{$host_first} {$host_last}");

                if ($host_name !== '') {
                    $message .= "Host: {$host_name}\n\n";
                }
            }
        }

        $message .= "If you have any questions, please contact your host or reception.\n\n";
        $message .= "Best regards,\nNyeri Club Visitor Management System";

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
        VMS_SMS::send_sms($phone, $message, $guest_id, $role);
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
        VMS_SMS::send_sms($phone, $message, $guest_id, $role);
    }

    /**
     * NEW: Send sign-in email notification (safe version with logging and validation)
     */
    private static function send_signin_email_notification(array $guest_data, array $visit_data): void
    {
        try {
            // Log entry point
            error_log("[Guest Sign-In Email] Preparing to send sign-in email...");

            // Check email preference
            if (($guest_data['receive_emails'] ?? 'no') !== 'yes') {
                error_log("[Guest Sign-In Email] Skipped: guest opted out of email notifications.");
                return;
            }

            $email = $guest_data['email'] ?? '';

            // Validate email before sending
            if (empty($email) || !is_email($email)) {
                error_log("[Guest Sign-In Email] Invalid or missing email for guest_id={$guest_data['user_id']}");
                return;
            }

            // Format times and dates safely
            $signin_time = !empty($visit_data['sign_in_time']) 
                ? date('g:i A', strtotime($visit_data['sign_in_time'])) 
                : '[unknown time]';
            $visit_date = !empty($visit_data['visit_date']) 
                ? date('F j, Y', strtotime($visit_data['visit_date'])) 
                : '[unknown date]';

            // Compose subject and message
            $subject = 'Welcome to Nyeri Club - Check-in Confirmation';
            $message = "Dear {$guest_data['first_name']},\n\n";
            $message .= "Welcome to Nyeri Club!\n\n";
            $message .= "You have successfully checked in at {$signin_time} on {$visit_date}.\n\n";
            $message .= "Enjoy your visit!\n\n";
            $message .= "Best regards,\n";
            $message .= "Nyeri Club Visitor Management System";

            // Send email
            $sent = wp_mail($email, $subject, $message);

            if ($sent) {
                error_log("[Guest Sign-In Email] Email sent successfully to {$email}");
            } else {
                error_log("[Guest Sign-In Email] wp_mail() returned false for {$email}");
            }

        } catch (Throwable $e) {
            error_log("[Guest Sign-In Email] Exception: " . $e->getMessage());
            error_log("[Guest Sign-In Email] Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * NEW: Send sign-out email notification (safe version)
     */
    private static function send_signout_email_notification(array $guest_data, array $visit_data): void
    {
        try {
            // Ensure email notifications are enabled
            if (($guest_data['receive_emails'] ?? 'no') !== 'yes') {
                error_log("[Guest Sign-Out Email] Skipped: guest opted out of email notifications.");
                return;
            }

            $email = $guest_data['email'] ?? '';

            // Validate email before sending
            if (empty($email) || !is_email($email)) {
                error_log("[Guest Sign-Out Email] Invalid or missing email for guest_id={$guest_data['user_id']}");
                return;
            }

            $signout_time = date('g:i A', strtotime($visit_data['sign_out_time']));
            $signin_time  = date('g:i A', strtotime($visit_data['sign_in_time']));

            // Calculate duration
            $start = new \DateTime($visit_data['sign_in_time']);
            $end   = new \DateTime($visit_data['sign_out_time']);
            $duration = $start->diff($end)->format('%h hours %i minutes');

            // Compose message
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

            // Send email
            $sent = wp_mail($email, $subject, $message);

            if ($sent) {
                error_log("[Guest Sign-Out Email] Email sent successfully to {$email}");
            } else {
                error_log("[Guest Sign-Out Email] wp_mail() returned false for {$email}");
            }

        } catch (Throwable $e) {
            error_log("[Guest Sign-Out Email] Exception: " . $e->getMessage());
        }
    }


    /**
     * Calculate guest status based on guest_status and visit limits
     */
    private static function calculate_guest_status(int $guest_id, ?int $host_member_id, string $visit_date): string
    {
        global $wpdb;
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
    
        // First, check the guest_status field
        $guest_status = $wpdb->get_var($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));
    
        // If guest_status is not 'active', return the corresponding status
        if ($guest_status !== 'active') {
            switch ($guest_status) {
                case 'suspended':
                    return 'suspended';
                case 'banned':
                    return 'banned';
                default:
                    return 'suspended';
            }
        }
    
        // Only if guest_status is 'active', proceed with limit checks
        
        // Daily limit check for host (skip for courtesy guests)
        if ($host_member_id !== null) {
            $daily_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $guest_visits_table
                WHERE host_member_id = %d AND DATE(visit_date) = %s",
                $host_member_id, $visit_date
            ));
            
            if ($daily_count >= 4) {
                return 'unapproved';
            }
        }
    
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
        if ($monthly_count >= 4 || $yearly_count >= 24) {
            return 'suspended';
        }
    
        return 'approved';
    }

    /**
     * Handle guest registration via AJAX - UPDATED WITH EMAIL NOTIFICATIONS + DEBUG LOGS
     */
    public static function handle_guest_registration(): void
    {
        self::verify_ajax_request();
        error_log('=== Handle guest registration START ===');

        global $wpdb;

        $errors = [];

        // Sanitize input
        $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
        $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
        $host_member_id   = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
        $visit_date       = sanitize_text_field($_POST['visit_date'] ?? '');
        $receive_messages = 'yes';
        $receive_emails   = 'yes';

        error_log("Input received: first_name=$first_name, last_name=$last_name, phone_number=$phone_number, host_member_id=$host_member_id, visit_date=$visit_date");

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
            error_log('Validation errors: ' . print_r($errors, true));
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Tables
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        error_log("Using tables: guests=$guests_table, guest_visits=$guest_visits_table");

        // Check if guest exists
        $existing_guest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, guest_status FROM $guests_table WHERE phone_number = %s",
            $phone_number
        ));
        error_log('Existing guest lookup result: ' . print_r($existing_guest, true));

        if ($existing_guest) {
            $guest_id = $existing_guest->id;
            error_log("Existing guest found with ID: $guest_id");
        } else {
            // Create new guest
            $insert_guest = $wpdb->insert(
                $guests_table,
                [
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'phone_number'     => $phone_number,
                    'receive_emails'   => $receive_emails,
                    'receive_messages' => $receive_messages,
                    'guest_status'     => 'active'
                ],
                ['%s','%s','%s','%s','%s','%s']
            );

            if ($insert_guest === false) {
                error_log('Failed to insert new guest. MySQL error: ' . $wpdb->last_error);
            }

            $guest_id = $wpdb->insert_id;
            error_log("New guest created with ID: $guest_id");
        }

        if (!$guest_id) {
            error_log('Guest ID missing. Aborting.');
            wp_send_json_error(['messages' => ['Failed to create or update guest record']]);
            return;
        }

        // Check duplicate visit
        $existing_visit = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
            $guest_id,
            $visit_date
        ));
        error_log("Existing visit count for guest $guest_id on $visit_date: $existing_visit");

        if ($existing_visit) {
            wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
            return;
        }

        // Calculate visit limits
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

        error_log("Guest visit counts: monthly=$monthly_visits, yearly=$yearly_visits");

        $monthly_limit = 4;
        $yearly_limit  = 12;

        if ($monthly_visits >= $monthly_limit) {
            error_log('Monthly limit reached.');
            wp_send_json_error(['messages' => ['This guest has reached the monthly visit limit']]);
            return;
        }

        if ($yearly_visits >= $yearly_limit) {
            error_log('Yearly limit reached.');
            wp_send_json_error(['messages' => ['This guest has reached the yearly visit limit']]);
            return;
        }

        // Host daily limit
        $host_approved_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guest_visits_table 
            WHERE host_member_id = %d AND visit_date = %s 
            AND (status = 'approved' OR (visit_date < %s AND sign_in_time IS NOT NULL))",
            $host_member_id,
            $visit_date,
            $today
        ));
        error_log("Host $host_member_id approved count for $visit_date: $host_approved_count");

        $preliminary_status = 'approved';
        if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit || ($host_approved_count + 1) > 4) {
            $preliminary_status = 'unapproved';
        }
        error_log("Preliminary status determined: $preliminary_status");

        // Insert visit record
        $visit_result = $wpdb->insert(
            $guest_visits_table,
            [
                'guest_id'       => $guest_id,
                'host_member_id' => $host_member_id,
                'visit_date'     => $visit_date,
                'status'         => $preliminary_status
            ],
            ['%d','%d','%s','%s']
        );

        if ($visit_result === false) {
            error_log('Failed to insert guest visit. MySQL error: ' . $wpdb->last_error);
            wp_send_json_error(['messages' => ['Failed to create visit record']]);
            return;
        }

        $visit_id = $wpdb->insert_id;
        error_log("Visit record created successfully with ID: $visit_id"); 
        error_log("Preparing JSON response with guest ID $guest_id and visit ID $visit_id");       

        // Fetch final guest status
        $final_guest_data = $wpdb->get_row($wpdb->prepare(
            "SELECT guest_status FROM $guests_table WHERE id = %d",
            $guest_id
        ));
        error_log("Final guest status fetched: " . print_r($final_guest_data, true));

        $final_guest_status = is_object($final_guest_data) && isset($final_guest_data->guest_status)
            ? $final_guest_data->guest_status
            : 'active';

        // -------------------------------------------------------------
        // Safely attempt to send SMS notifications (non-blocking failure)
        // -------------------------------------------------------------
        try {
            error_log("[Guest Registration] Attempting to send SMS for guest_id {$guest_id}");
            
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

            error_log("[Guest Registration] SMS process completed successfully for guest_id {$guest_id}");
        } catch (Throwable $e) {
            // Catch ANY fatal, runtime, or unexpected error
            error_log("[Guest Registration] SMS sending failed for guest_id {$guest_id}: " . $e->getMessage());
            error_log("[Guest Registration] Stack trace: " . $e->getTraceAsString());
            // Continue running — do NOT break registration flow
        }

        error_log("SMS sent for guest ID: $guest_id");

        // Prepare final JSON response
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
            'guest_status'    => $final_guest_status,
            'sign_in_time'    => null,
            'sign_out_time'   => null,
            'visit_id'        => $visit_id
        ];

        error_log('Guest registration complete: ' . print_r($guest_data, true));
        error_log('=== Handle guest registration END ===');

        $json_test = json_encode([
            'messages'  => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
        if ($json_test === false) {
            error_log("JSON encoding error: " . json_last_error_msg());
        }


        wp_send_json_success([
            'messages'  => ['Guest registered successfully'],
            'guestData' => $guest_data
        ]);
    }    

    /**
     * Handle courtesy guest registration via AJAX - with enhanced logging and safe error handling.
     */
    public static function handle_courtesy_guest_registration(): void
    {
        self::verify_ajax_request();
        error_log("[Courtesy Guest Registration] === handle_courtesy_guest_registration() START ===");

        global $wpdb;
        $errors = [];

        try {
            // -------------------------------------------------------------
            // Step 1: Sanitize input
            // -------------------------------------------------------------
            $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
            $phone_number = sanitize_text_field($_POST['phone_number'] ?? '');
            $visit_date   = sanitize_text_field($_POST['visit_date'] ?? '');
            $courtesy     = 'Courtesy';

            error_log("[Courtesy Guest Registration] Input received: first_name={$first_name}, last_name={$last_name}, phone={$phone_number}, visit_date={$visit_date}");

            // -------------------------------------------------------------
            // Step 2: Validate input
            // -------------------------------------------------------------
            if (empty($first_name))  $errors[] = 'First name is required';
            if (empty($last_name))   $errors[] = 'Last name is required';
            if (empty($phone_number)) $errors[] = 'Phone number is required';

            // Validate visit date format
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
                error_log("[Courtesy Guest Registration] Validation failed: " . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
                return;
            }

            // -------------------------------------------------------------
            // Step 3: Define table names
            // -------------------------------------------------------------
            $guests_table       = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

            error_log("[Courtesy Guest Registration] Using tables: guests={$guests_table}, guest_visits={$guest_visits_table}");

            // -------------------------------------------------------------
            // Step 4: Check for existing guest
            // -------------------------------------------------------------
            $existing_guest = $wpdb->get_row($wpdb->prepare(
                "SELECT id, guest_status FROM $guests_table WHERE phone_number = %s",
                $phone_number
            ));

            if ($existing_guest) {
                $guest_id = (int) $existing_guest->id;
                error_log("[Courtesy Guest Registration] Existing guest found with ID: {$guest_id}");
            } else {
                error_log("[Courtesy Guest Registration] No existing guest found. Creating new record...");

                $insert_result = $wpdb->insert(
                    $guests_table,
                    [
                        'first_name'       => $first_name,
                        'last_name'        => $last_name,
                        'phone_number'     => $phone_number,
                        'receive_emails'   => 'yes',
                        'receive_messages' => 'yes',
                        'guest_status'     => 'active'
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s']
                );

                if ($insert_result === false) {
                    error_log("[Courtesy Guest Registration] ERROR: Failed to insert guest: " . $wpdb->last_error);
                    wp_send_json_error(['messages' => ['Failed to create guest record']]);
                    return;
                }

                $guest_id = $wpdb->insert_id;
                error_log("[Courtesy Guest Registration] New guest created with ID: {$guest_id}");
            }

            // -------------------------------------------------------------
            // Step 5: Retrieve communication preferences
            // -------------------------------------------------------------
            $receive_emails = $wpdb->get_var($wpdb->prepare(
                "SELECT receive_emails FROM $guests_table WHERE id = %d",
                $guest_id
            ));
            $receive_messages = $wpdb->get_var($wpdb->prepare(
                "SELECT receive_messages FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            error_log("[Courtesy Guest Registration] Communication prefs for guest_id {$guest_id}: emails={$receive_emails}, messages={$receive_messages}");

            // -------------------------------------------------------------
            // Step 6: Prevent duplicate visits on same date
            // -------------------------------------------------------------
            $existing_visit = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $guest_visits_table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
                $guest_id,
                $visit_date
            ));

            if ($existing_visit) {
                error_log("[Courtesy Guest Registration] Duplicate visit detected for guest_id {$guest_id} on {$visit_date}");
                wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
                return;
            }

            // -------------------------------------------------------------
            // Step 7: Insert new visit record
            // -------------------------------------------------------------
            $preliminary_status = 'approved';
            $visit_insert = $wpdb->insert(
                $guest_visits_table,
                [
                    'guest_id'   => $guest_id,
                    'visit_date' => $visit_date,
                    'courtesy'   => $courtesy,
                    'status'     => $preliminary_status
                ],
                ['%d', '%s', '%s', '%s']
            );

            if ($visit_insert === false) {
                error_log("[Courtesy Guest Registration] ERROR: Failed to create visit record: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to create visit record']]);
                return;
            }

            $visit_id = $wpdb->insert_id;
            error_log("[Courtesy Guest Registration] Visit record created successfully with ID: {$visit_id}");

            // -------------------------------------------------------------
            // Step 8: Fetch final guest status
            // -------------------------------------------------------------
            $final_guest_data = $wpdb->get_row($wpdb->prepare(
                "SELECT guest_status FROM $guests_table WHERE id = %d",
                $guest_id
            ));
            $guest_status = $final_guest_data->guest_status ?? 'active';

            // -------------------------------------------------------------
            // Step 9: Send SMS notification (non-blocking)
            // -------------------------------------------------------------
            try {
                error_log("[Courtesy Guest Registration] Attempting to send SMS for guest_id {$guest_id}");
                self::send_courtesy_guest_registration_sms(
                    $guest_id,
                    $first_name,
                    $last_name,
                    $phone_number,
                    $receive_messages,
                    $visit_date,
                    $preliminary_status
                );
                error_log("[Courtesy Guest Registration] SMS process completed successfully for guest_id {$guest_id}");
            } catch (Throwable $e) {
                error_log("[Courtesy Guest Registration] ERROR sending SMS for guest_id {$guest_id}: " . $e->getMessage());
                error_log("[Courtesy Guest Registration] Stack trace: " . $e->getTraceAsString());
                // Continue without interrupting the flow
            }

            // -------------------------------------------------------------
            // Step 10: Return success response
            // -------------------------------------------------------------
            $guest_data = [
                'id'               => $guest_id,
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'phone_number'     => $phone_number,
                'visit_date'       => $visit_date,
                'courtesy'         => $courtesy,
                'receive_emails'   => $receive_emails,
                'receive_messages' => $receive_messages,
                'status'           => $preliminary_status,
                'guest_status'     => $guest_status,
                'sign_in_time'     => null,
                'sign_out_time'    => null,
                'visit_id'         => $visit_id
            ];

            error_log("[Courtesy Guest Registration] Registration completed successfully for guest_id {$guest_id}");
            wp_send_json_success([
                'messages'  => ['Guest registered successfully'],
                'guestData' => $guest_data
            ]);
        } catch (Throwable $e) {
            // Catch any unexpected top-level error
            error_log("[Courtesy Guest Registration] FATAL ERROR: " . $e->getMessage());
            error_log("[Courtesy Guest Registration] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred. Please try again later.']]);
        }

        error_log("[Courtesy Guest Registration] === handle_courtesy_guest_registration() END ===");
    }

    /**
     * Handle visit registration via AJAX - Updated with error logging, comments, and try/catch
     */
    public static function handle_visit_registration()
    {
        global $wpdb;

        try {
            error_log('VMS: Visit registration started.');

            // --- Retrieve & sanitize POST data ---
            $guest_id       = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
            $host_member_id = isset($_POST['host_member_id']) ? absint($_POST['host_member_id']) : null;
            $visit_date     = sanitize_text_field($_POST['visit_date'] ?? '');
            $courtesy       = sanitize_text_field($_POST['courtesy'] ?? '');

            $errors = [];

            // --- Validate guest ---
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

            // --- Validate host ---
            $host_member = null;
            if ($host_member_id) {
                $host_member = get_userdata($host_member_id);
                if (!$host_member) {
                    $errors[] = 'Invalid host member selected';
                }
            }

            // --- Validate visit date ---
            if (empty($visit_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
                $errors[] = 'Valid visit date is required (YYYY-MM-DD)';
            } else {
                $visit_date_obj = \DateTime::createFromFormat('Y-m-d', $visit_date);
                $current_date   = new \DateTime(current_time('Y-m-d'));
                if (!$visit_date_obj || $visit_date_obj < $current_date) {
                    $errors[] = 'Visit date cannot be in the past';
                }
            }

            // --- Return early on validation errors ---
            if (!empty($errors)) {
                error_log('VMS: Validation failed - ' . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
            }

            // --- Define tables ---
            $table         = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
            $guests_table  = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            // --- Fetch guest info ---
            $guest_info = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name, email, phone_number, receive_emails, receive_messages 
                FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            if (!$guest_info) {
                error_log("VMS: Guest info not found for ID $guest_id.");
                wp_send_json_error(['messages' => ['Guest record not found']]);
            }

            // --- Check for duplicate visit (not cancelled) ---
            $existing_visit = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE guest_id = %d AND visit_date = %s",
                $guest_id,
                $visit_date
            ));

            if ($existing_visit && $existing_visit->status !== 'cancelled') {
                error_log("VMS: Duplicate visit found for guest ID $guest_id on $visit_date.");
                wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
            }

            // --- Enforce visit limits ---
            $month_start = date('Y-m-01', strtotime($visit_date));
            $month_end   = date('Y-m-t', strtotime($visit_date));
            $year_start  = date('Y-01-01', strtotime($visit_date));
            $year_end    = date('Y-12-31', strtotime($visit_date));
            $today       = date('Y-m-d');

            // Count monthly visits
            $monthly_visits = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table 
                WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
                AND (
                    (status = 'approved' AND visit_date >= %s) OR
                    (visit_date < %s AND sign_in_time IS NOT NULL)
                )",
                $guest_id, $month_start, $month_end, $today, $today
            ));

            // Count yearly visits
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
                error_log("VMS: Monthly limit reached for guest ID $guest_id.");
                wp_send_json_error(['messages' => ['This guest has reached the monthly visit limit']]);
            }

            if ($yearly_visits >= $yearly_limit) {
                error_log("VMS: Yearly limit reached for guest ID $guest_id.");
                wp_send_json_error(['messages' => ['This guest has reached the yearly visit limit']]);
            }

            // --- Host daily limit ---
            $host_approved_count = 0;
            if ($host_member_id) {
                $host_approved_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table 
                    WHERE host_member_id = %d AND visit_date = %s 
                    AND (status = 'approved' OR (visit_date < %s AND sign_in_time IS NOT NULL))",
                    $host_member_id, $visit_date, $today
                ));
            }

            // --- Determine status ---
            $preliminary_status = 'approved';
            if (($monthly_visits + 1) > $monthly_limit || ($yearly_visits + 1) > $yearly_limit) {
                $preliminary_status = 'unapproved';
            }
            if ($host_member_id && ($host_approved_count + 1) > 4) {
                $preliminary_status = 'unapproved';
            }

            // --- Insert or update visit record ---
            if ($existing_visit && $existing_visit->status === 'cancelled') {
                error_log("VMS: Reusing cancelled visit record ID {$existing_visit->id}.");

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
                    error_log("VMS ERROR: Failed to update cancelled visit for guest ID $guest_id.");
                    wp_send_json_error(['messages' => ['Failed to update cancelled visit']]);
                }

                $visit_id = $existing_visit->id;
            } else {
                error_log("VMS: Inserting new visit record for guest ID $guest_id.");
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
                    error_log("VMS ERROR: DB insert failed for guest ID $guest_id.");
                    wp_send_json_error(['messages' => ['Failed to register visit']]);
                }

                $visit_id = $wpdb->insert_id;
            }

            // --- Send email/SMS notifications ---
            if ($guest_info) {
                if ($courtesy === 'Courtesy') {
                    error_log("VMS: Sending courtesy visit notifications to {$guest_info->email}.");

                    self::send_courtesy_visit_registration_emails(
                        $guest_info->first_name,
                        $guest_info->last_name,
                        $guest_info->email,
                        $guest_info->receive_emails,
                        $visit_date,
                        $preliminary_status
                    );

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
                    error_log("VMS: Sending standard visit notifications to {$guest_info->email}.");

                    self::send_visit_registration_emails(
                        $guest_info->first_name,
                        $guest_info->last_name,
                        $guest_info->email,
                        $guest_info->receive_emails,
                        $host_member,
                        $visit_date,
                        $preliminary_status
                    );

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

            // --- Retrieve the saved visit ---
            $visit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $visit_id));

            // --- Determine host display name ---
            $host_display = 'N/A';
            if (!empty($visit->host_member_id)) {
                $host_user = get_userdata($visit->host_member_id);
                if ($host_user) {
                    $first = get_user_meta($visit->host_member_id, 'first_name', true);
                    $last  = get_user_meta($visit->host_member_id, 'last_name', true);
                    $host_display = (!empty($first) || !empty($last)) ? trim("$first $last") : $host_user->user_login;
                }
            }

            // --- Compute status display fields ---
            $status       = VMS_Core::get_visit_status($visit->visit_date, $visit->sign_in_time, $visit->sign_out_time);
            $status_class = VMS_Core::get_status_class($status);
            $status_text  = VMS_Core::get_status_text($status);

            error_log("VMS: Visit successfully registered (visit ID $visit_id).");

            wp_send_json_success([
                'id'            => $visit->id,
                'host_display'  => $host_display,
                'visit_date'    => VMS_Core::format_date($visit->visit_date),
                'sign_in_time'  => VMS_Core::format_time($visit->sign_in_time),
                'sign_out_time' => VMS_Core::format_time($visit->sign_out_time),
                'duration'      => VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time),
                'status'        => $preliminary_status,
                'status_class'  => $status_class,
                'status_text'   => $status_text,
                'messages'      => ['Visit registered successfully']
            ]);
        } catch (Exception $e) {
            error_log('VMS ERROR: Exception during visit registration - ' . $e->getMessage());
            wp_send_json_error(['messages' => ['An unexpected error occurred during visit registration.']]);
        }
    }  

    /**
     * Send SMS notifications for guest registration with host
     */
    private static function send_guest_registration_sms(
        $guest_id,
        $first_name,
        $last_name,
        $guest_phone,
        $guest_receive_messages,
        $host_member,
        $visit_date,
        $status
    ) 
    {
        error_log("=== send_guest_registration_sms() triggered for guest_id: {$guest_id} ===");

        // Validate visit date
        $timestamp = strtotime($visit_date);
        if (!$timestamp) {
            error_log("Invalid visit_date provided for guest_id {$guest_id}: {$visit_date}");
            $formatted_date = 'unspecified date';
        } else {
            $formatted_date = date('F j, Y', $timestamp);
        }

        // Determine readable status
        $status_text = ($status === 'approved') ? 'Approved' : 'Pending Approval';

        // Validate host_member object
        if (!($host_member instanceof WP_User)) {
            error_log("Invalid or missing host_member object for guest_id {$guest_id}. Skipping host-related SMS.");
            $host_first_name = 'Unknown';
            $host_last_name = '';
        } else {
            $host_first_name = get_user_meta($host_member->ID, 'first_name', true);
            $host_last_name  = get_user_meta($host_member->ID, 'last_name', true);
        }

        // ---------------------------------------------------
        // 1️⃣ Send SMS to Guest
        // ---------------------------------------------------
        if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
            $guest_message = "Dear {$first_name},\nYou have been booked as a visitor at Nyeri Club by {$host_first_name} {$host_last_name}. Your visit registered for {$formatted_date} is {$status_text}.";
            $role = 'guest';

            if ($status === 'approved') {
                $guest_message .= " Please present a valid ID or Passport upon arrival at the Club.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            error_log("Preparing to send SMS to Guest (Phone: {$guest_phone})");

            try {
                VMS_SMS::send_sms($guest_phone, $guest_message, $guest_id, $role);
                error_log("Guest SMS successfully sent to {$guest_phone}");
            } catch (Throwable $e) {
                error_log("Guest SMS failed for guest_id {$guest_id}: " . $e->getMessage());
            }
        } else {
            error_log("Guest opted out of SMS or phone missing for guest_id {$guest_id}");
        }

        // ---------------------------------------------------
        // 2️⃣ Send SMS to Host (if valid host object)
        // ---------------------------------------------------
        if ($host_member instanceof WP_User) {
            $host_receive_messages = get_user_meta($host_member->ID, 'receive_messages', true);
            $host_phone            = get_user_meta($host_member->ID, 'phone_number', true);
            $host_first_name       = get_user_meta($host_member->ID, 'first_name', true);
            $roles                 = $host_member->roles ?? [];
            $role                  = !empty($roles) ? $roles[0] : 'member';

            if ($host_receive_messages === 'yes' && !empty($host_phone)) {
                $host_message = "Dear {$host_first_name},\nYour guest {$first_name} {$last_name} has been registered for {$formatted_date}. Status: {$status_text}.";
                if ($status === 'approved') {
                    $host_message .= " Please be available to receive them.";
                } else {
                    $host_message .= " Pending approval due to limits.";
                }

                error_log("Preparing to send SMS to Host (ID: {$host_member->ID}, Phone: {$host_phone})");

                try {
                    VMS_SMS::send_sms($host_phone, $host_message, $host_member->ID, $role);
                    error_log("Host SMS successfully sent to {$host_phone}");
                } catch (Throwable $e) {
                    error_log("Host SMS failed for guest_id {$guest_id}: " . $e->getMessage());
                }
            } else {
                error_log("Host opted out of SMS or phone missing for host ID {$host_member->ID}");
            }
        }

        error_log("=== send_guest_registration_sms() completed for guest_id: {$guest_id} ===");
    }
    
    /**
     * Send SMS notifications for courtesy guest registration (no host involved)
     *
     * @param int    $guest_id                ID of the courtesy guest record.
     * @param string $first_name              Guest's first name.
     * @param string $last_name               Guest's last name.
     * @param string $guest_phone             Guest's phone number.
     * @param string $guest_receive_messages  'yes' if guest wants SMS notifications.
     * @param string $visit_date              Date of the visit (Y-m-d format).
     * @param string $status                  Visit status ('approved' or 'pending').
     *
     * @return void
     */
    private static function send_courtesy_guest_registration_sms(
        $guest_id,
        $first_name,
        $last_name,
        $guest_phone,
        $guest_receive_messages,
        $visit_date,
        $status
    ): void 
    {
        // Log entry point for debugging
        error_log("[Courtesy Guest SMS] === send_courtesy_guest_registration_sms() triggered for guest_id: {$guest_id} ===");

        try {
            // Format visit date for readability (e.g., October 22, 2025)
            $formatted_date = date('F j, Y', strtotime($visit_date));
            $status_text    = ($status === 'approved') ? 'Approved' : 'Pending Approval';

            // Log SMS eligibility
            error_log("[Courtesy Guest SMS] Checking message preferences for guest_id: {$guest_id}, receive_messages: {$guest_receive_messages}");

            // Only proceed if guest opted in and phone number is valid
            if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
                error_log("[Courtesy Guest SMS] Preparing message for {$first_name} {$last_name} (Phone: {$guest_phone})");

                // Build personalized message
                $guest_message = "Dear {$first_name},\n";
                $guest_message .= "You have been booked as a visitor at Nyeri Club. ";
                $guest_message .= "Your visit registered for {$formatted_date} is {$status_text}.";

                if ($status === 'approved') {
                    $guest_message .= " Please present a valid ID or Passport upon arrival at the Club.";
                } else {
                    $guest_message .= " You will be notified once approved.";
                }

                $role = 'guest';

                // -------------------------------------------------------------
                // Safely attempt to send SMS (with error handling and logging)
                // -------------------------------------------------------------
                try {
                    error_log("[Courtesy Guest SMS] Sending SMS to {$guest_phone} ...");
                    VMS_SMS::send_sms($guest_phone, $guest_message, $guest_id, $role);
                    error_log("[Courtesy Guest SMS] SMS sent successfully to {$guest_phone}");
                } catch (Throwable $e) {
                    error_log("[Courtesy Guest SMS] ERROR sending SMS to {$guest_phone}: " . $e->getMessage());
                    error_log("[Courtesy Guest SMS] Stack trace: " . $e->getTraceAsString());
                }
                
            } else {
                error_log("[Courtesy Guest SMS] Guest opted out or phone missing. No SMS sent for guest_id: {$guest_id}");
            }
        } catch (Throwable $e) {
            // Catch any unexpected exception in this method
            error_log("[Courtesy Guest SMS] FATAL ERROR for guest_id {$guest_id}: " . $e->getMessage());
            error_log("[Courtesy Guest SMS] Stack trace: " . $e->getTraceAsString());
            // Do not rethrow — registration should continue gracefully
        }

        // Log method completion
        error_log("[Courtesy Guest SMS] === SMS process completed for guest_id: {$guest_id} ===");
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

        VMS_SMS::send_sms($guest_phone, $guest_message, $guest_id, $role);        

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

                VMS_SMS::send_sms($host_phone, $host_message, $host_member->ID, $role);
            }
        }
    }

    /**
     * Send email notifications for visit registration (safe + logged)
     */
    private static function send_visit_registration_emails(
        $guest_first_name,
        $guest_last_name,
        $guest_email,
        $guest_receive_emails,
        $host_member,
        $visit_date,
        $status
    ) 
    {
        try {
            error_log("[Visit Registration Email] Starting send_visit_registration_emails()");

            // Safety defaults
            $guest_first_name = $guest_first_name ?: '[Unknown Guest]';
            $guest_last_name  = $guest_last_name ?: '';
            $formatted_date   = !empty($visit_date) ? date('F j, Y', strtotime($visit_date)) : '[Unknown Date]';
            $status_text      = ($status === 'approved') ? 'approved' : 'pending approval';

            $host_first_name  = $host_member ? get_user_meta($host_member->ID, 'first_name', true) : '';
            $host_last_name   = $host_member ? get_user_meta($host_member->ID, 'last_name', true) : '';

            // --- GUEST EMAIL ---
            if ($guest_receive_emails === 'yes' && !empty($guest_email) && is_email($guest_email)) {
                $subject = 'Visit Registration Confirmation - Nyeri Club';
                $message  = "Dear {$guest_first_name},\n\n";
                $message .= "Your visit to Nyeri Club has been registered successfully.\n\n";
                $message .= "Visit Details:\n";
                $message .= "Date: {$formatted_date}\n";
                if ($host_member) {
                    $message .= "Host: {$host_first_name} {$host_last_name}\n";
                }
                $message .= "Status: " . ucfirst($status_text) . "\n\n";
                $message .= ($status === 'approved')
                    ? "Your visit has been approved. Please present a valid ID when you arrive.\n\n"
                    : "Your visit is currently pending approval. You will receive another email once approved.\n\n";
                $message .= "Thank you for choosing Nyeri Club.\n\n";
                $message .= "Best regards,\nNyeri Club Visitor Management System";

                error_log("[Visit Registration Email] Sending guest email to {$guest_email}");
                if (!wp_mail($guest_email, $subject, $message)) {
                    error_log("[Visit Registration Email] wp_mail() returned false for guest_email={$guest_email}");
                } else {
                    error_log("[Visit Registration Email] Guest email sent successfully to {$guest_email}");
                }
            } else {
                error_log("[Visit Registration Email] Skipped guest email (opt-out or invalid email)");
            }

            // --- HOST EMAIL ---
            if ($host_member && !empty($host_member->user_email) && is_email($host_member->user_email)) {
                $host_receive_emails = get_user_meta($host_member->ID, 'receive_emails', true);
                if ($host_receive_emails === 'yes') {
                    $subject = 'New Visit Registration - Nyeri Club';
                    $message  = "Dear {$host_first_name} {$host_last_name},\n\n";
                    $message .= "A visit has been registered with you as the host.\n\n";
                    $message .= "Guest Details:\n";
                    $message .= "Name: {$guest_first_name} {$guest_last_name}\n";
                    $message .= "Visit Date: {$formatted_date}\n";
                    $message .= "Status: " . ucfirst($status_text) . "\n\n";
                    $message .= ($status === 'approved')
                        ? "The visit has been approved. Please ensure you are available to receive your guest.\n\n"
                        : "The visit is pending approval due to capacity limits.\n\n";
                    $message .= "Best regards,\nNyeri Club Visitor Management System";

                    error_log("[Visit Registration Email] Sending host email to {$host_member->user_email}");
                    if (!wp_mail($host_member->user_email, $subject, $message)) {
                        error_log("[Visit Registration Email] wp_mail() returned false for host_email={$host_member->user_email}");
                    } else {
                        error_log("[Visit Registration Email] Host email sent successfully to {$host_member->user_email}");
                    }
                } else {
                    error_log("[Visit Registration Email] Host opted out of email notifications");
                }
            }

        } catch (Throwable $e) {
            error_log("[Visit Registration Email] Exception: " . $e->getMessage());
            error_log("[Visit Registration Email] Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Send email notifications for courtesy visit registration (safe + logged)
     */
    private static function send_courtesy_visit_registration_emails(
        $guest_first_name,
        $guest_last_name,
        $guest_email,
        $guest_receive_emails,
        $visit_date,
        $status
    ) 
    {
        try {
            error_log("[Courtesy Visit Email] Starting send_courtesy_visit_registration_emails()");

            $guest_first_name = $guest_first_name ?: '[Unknown Guest]';
            $guest_last_name  = $guest_last_name ?: '';
            $formatted_date   = !empty($visit_date) ? date('F j, Y', strtotime($visit_date)) : '[Unknown Date]';
            $status_text      = ($status === 'approved') ? 'approved' : 'pending approval';

            // --- GUEST EMAIL ---
            if ($guest_receive_emails === 'yes' && !empty($guest_email) && is_email($guest_email)) {
                $subject = 'Courtesy Visit Registration Confirmation - Nyeri Club';
                $message  = "Dear {$guest_first_name},\n\n";
                $message .= "Your courtesy visit to Nyeri Club has been registered successfully.\n\n";
                $message .= "Visit Details:\n";
                $message .= "Date: {$formatted_date}\n";
                $message .= "Type: Courtesy Visit\n";
                $message .= "Status: " . ucfirst($status_text) . "\n\n";
                $message .= ($status === 'approved')
                    ? "Your visit has been approved. Please present a valid ID when you arrive.\n\n"
                    : "Your visit is currently pending approval. You will receive another email once approved.\n\n";
                $message .= "Thank you for choosing Nyeri Club.\n\n";
                $message .= "Best regards,\nNyeri Club Visitor Management System";

                error_log("[Courtesy Visit Email] Sending guest email to {$guest_email}");
                if (!wp_mail($guest_email, $subject, $message)) {
                    error_log("[Courtesy Visit Email] wp_mail() returned false for guest_email={$guest_email}");
                } else {
                    error_log("[Courtesy Visit Email] Guest email sent successfully to {$guest_email}");
                }
            } else {
                error_log("[Courtesy Visit Email] Skipped guest email (opt-out or invalid email)");
            }

            // --- ADMIN EMAIL ---
            $admin_email = get_option('admin_email');
            if (!empty($admin_email) && is_email($admin_email)) {
                $subject = 'New Courtesy Visit Registration - Nyeri Club';
                $message  = "Hello Admin,\n\n";
                $message .= "A new courtesy visit has been registered:\n\n";
                $message .= "Guest Details:\n";
                $message .= "Name: {$guest_first_name} {$guest_last_name}\n";
                $message .= "Email: {$guest_email}\n";
                $message .= "Visit Date: {$formatted_date}\n";
                $message .= "Type: Courtesy Visit\n";
                $message .= "Status: " . ucfirst($status_text) . "\n\n";
                $message .= "Please review this registration in the system.\n\n";
                $message .= "Nyeri Club Visitor Management System";

                error_log("[Courtesy Visit Email] Sending admin email to {$admin_email}");
                if (!wp_mail($admin_email, $subject, $message)) {
                    error_log("[Courtesy Visit Email] wp_mail() returned false for admin_email={$admin_email}");
                } else {
                    error_log("[Courtesy Visit Email] Admin email sent successfully to {$admin_email}");
                }
            } else {
                error_log("[Courtesy Visit Email] Skipped admin email (invalid admin email)");
            }

        } catch (Throwable $e) {
            error_log("[Courtesy Visit Email] Exception: " . $e->getMessage());
            error_log("[Courtesy Visit Email] Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Calculate the preliminary visit status for a guest.
     * 
     * Rules:
     * - Max 4 visits per month.
     * - Max 12 visits per year.
     * - Approved visits (future) and attended visits (past) both count.
     * 
     * Adds structured logs for visibility.
     */
    private static function calculate_preliminary_visit_status(int $guest_id, string $visit_date): string
    {
        global $wpdb;

        $function = '[calculate_preliminary_visit_status]';
        error_log("$function Starting check for guest_id=$guest_id, visit_date=$visit_date");

        try {
            // Basic validation
            if (empty($guest_id) || empty($visit_date)) {
                error_log("$function Invalid input: guest_id or visit_date is empty");
                return 'unapproved';
            }

            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
            $monthly_limit = 4;
            $yearly_limit = 12;
            $today = date('Y-m-d');

            // --- Date Ranges ---
            $month_start = date('Y-m-01', strtotime($visit_date));
            $month_end   = date('Y-m-t', strtotime($visit_date));
            $year_start  = date('Y-01-01', strtotime($visit_date));
            $year_end    = date('Y-12-31', strtotime($visit_date));

            error_log("$function Month range: $month_start to $month_end");
            error_log("$function Year range: $year_start to $year_end");

            // --- Monthly Visits Count ---
            $monthly_visits = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $guest_visits_table 
                WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
                AND (
                    (status = 'approved' AND visit_date >= %s)
                    OR (visit_date < %s AND sign_in_time IS NOT NULL)
                )",
                $guest_id, $month_start, $month_end, $today, $today
            ));

            // --- Yearly Visits Count ---
            $yearly_visits = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $guest_visits_table 
                WHERE guest_id = %d AND visit_date BETWEEN %s AND %s 
                AND (
                    (status = 'approved' AND visit_date >= %s)
                    OR (visit_date < %s AND sign_in_time IS NOT NULL)
                )",
                $guest_id, $year_start, $year_end, $today, $today
            ));

            // Safety defaults
            $monthly_visits = intval($monthly_visits);
            $yearly_visits  = intval($yearly_visits);

            error_log("$function Monthly visits: $monthly_visits | Yearly visits: $yearly_visits");

            // --- Decision Logic ---
            if (($monthly_visits + 1) > $monthly_limit) {
                error_log("$function Result: UNAPPROVED (monthly limit exceeded)");
                return 'unapproved';
            }

            if (($yearly_visits + 1) > $yearly_limit) {
                error_log("$function Result: UNAPPROVED (yearly limit exceeded)");
                return 'unapproved';
            }

            error_log("$function Result: APPROVED (within limits)");
            return 'approved';

        } catch (Throwable $e) {
            error_log("$function Exception: " . $e->getMessage());
            error_log("$function Trace: " . $e->getTraceAsString());
            return 'unapproved';
        }
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
        $receive_messages = 'yes';
        $receive_emails   = 'yes';

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

    /**
     * Handle guest deletion via AJAX with full error logging and safety checks.
     */
    public static function handle_guest_deletion()
    {
        $function = '[handle_guest_deletion]';
        error_log("$function Function triggered.");

        try {
            // --- Security Check ---
            self::verify_ajax_request();
            error_log("$function AJAX request verified.");

            // --- Validate Guest ID ---
            $guest_id = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;

            if (empty($guest_id)) {
                error_log("$function Missing guest_id in request.");
                wp_send_json_error(['messages' => ['Guest ID is required']]);
                return;
            }

            global $wpdb;
            $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
            $visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);

            error_log("$function Checking existence of guest_id=$guest_id in $guests_table");

            // --- Verify Guest Exists ---
            $existing_guest = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            if (!$existing_guest) {
                error_log("$function Guest not found for guest_id=$guest_id.");
                wp_send_json_error(['messages' => ['Guest not found']]);
                return;
            }

            // --- Delete All Related Guest Visits ---
            error_log("$function Deleting visits for guest_id=$guest_id from $visits_table");

            $deleted_visits = $wpdb->delete(
                $visits_table,
                ['guest_id' => $guest_id],
                ['%d']
            );

            if ($deleted_visits === false) {
                error_log("$function Failed to delete visits for guest_id=$guest_id. MySQL error: " . $wpdb->last_error);
            } else {
                error_log("$function Deleted $deleted_visits visit record(s) for guest_id=$guest_id.");
            }

            // --- Delete Guest Record ---
            error_log("$function Deleting guest_id=$guest_id from $guests_table");

            $deleted_guest = $wpdb->delete(
                $guests_table,
                ['id' => $guest_id],
                ['%d']
            );

            if ($deleted_guest === false) {
                error_log("$function Failed to delete guest_id=$guest_id. MySQL error: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to delete guest record']]);
                return;
            }

            error_log("$function Guest deleted successfully. guest_id=$guest_id");
            wp_send_json_success(['messages' => ['Guest deleted successfully']]);

        } catch (Throwable $e) {
            error_log("$function Exception: " . $e->getMessage());
            error_log("$function Trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred during guest deletion.']]);
        }
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
        error_log('Auto sign out guests');
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        
        // Get yesterday's date
        $yesterday = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
        
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, guest_id, host_member_id, visit_date
            FROM $guest_visits_table
            WHERE sign_in_time IS NOT NULL
            AND sign_out_time IS NULL
            AND DATE(visit_date) = %s",
            $yesterday
        ));
        
        if (empty($visits)) {
            error_log('No guests to sign out');
            return;
        }
        
        foreach ($visits as $visit) {
            // Set sign_out_time to 23:59:59 of visit_date
            $midnight = $visit->visit_date . ' 23:59:59';
            
            $updated = $wpdb->update(
                $guest_visits_table,
                ['sign_out_time' => $midnight],
                ['id' => $visit->id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                error_log("Failed to sign out guest visit ID: {$visit->id}");
            } else {
                error_log("Signed out guest visit ID: {$visit->id}");
            }
            
            // Re-evaluate guest status
            $guest_status = self::calculate_guest_status(
                $visit->guest_id,
                $visit->host_member_id,
                $visit->visit_date
            );
            
            // Update guest status
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
    }
}