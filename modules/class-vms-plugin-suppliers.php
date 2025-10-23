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
     * Setup Supplier management related hooks
     */
    private static function setup_suppliers_management_hooks(): void
    {      
        // Supplier
        add_action('wp_ajax_suppliers_registration', [self::class, 'handle_suppliers_registration']);
        add_action('wp_ajax_update_suppliers', [self::class, 'handle_suppliers_update']);
        add_action('wp_ajax_delete_suppliers', [self::class, 'handle_suppliers_deletion']);
        
        add_action('wp_ajax_register_supplier_visit', [self::class, 'handle_visit_registration']);

        add_action('wp_ajax_sign_in_suppliers', [self::class, 'handle_sign_in_suppliers']);
        add_action('wp_ajax_sign_out_suppliers', [self::class, 'handle_sign_out_suppliers']);
        add_action('auto_sign_out_suppliers_at_midnight', [self::class, 'auto_sign_out_suppliers']);
    }   

    /**
     * Handle Supplier sign-in via AJAX with strict ID number validation
     * and robust error handling.
     */
    public static function handle_sign_in_suppliers(): void
    {
        self::verify_ajax_request();

        try {
            // -------------------------------------------------------------
            // 1. Input validation
            // -------------------------------------------------------------
            $visit_id  = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
            $id_number = sanitize_text_field($_POST['id_number'] ?? '');

            error_log("[Supplier Sign-In] Received request: visit_id={$visit_id}, id_number={$id_number}");

            if (!$visit_id) {
                wp_send_json_error(['messages' => ['Invalid visit ID']]);
                return;
            }

            if (empty($id_number) || strlen($id_number) < 5) {
                wp_send_json_error(['messages' => ['Valid ID number (min 5 digits) is required']]);
                return;
            }

            global $wpdb;
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
            $guests_table       = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

            // -------------------------------------------------------------
            // 2. Fetch visit and Supplier data
            // -------------------------------------------------------------
            $visit = $wpdb->get_row($wpdb->prepare(
                "SELECT gv.*, g.id_number, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails, g.guest_status
                FROM {$guest_visits_table} gv
                LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
                WHERE gv.id = %d",
                $visit_id
            ));

            if (!$visit) {
                error_log("[Supplier Sign-In] No visit found for ID {$visit_id}");
                wp_send_json_error(['messages' => ['Visit not found']]);
                return;
            }

            // -------------------------------------------------------------
            // 3. Validate ID number
            // -------------------------------------------------------------
            if (!empty($visit->id_number)) {
                if ($visit->id_number !== $id_number) {
                    error_log("[Supplier Sign-In] Mismatched ID number for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['ID number does not match the registered supplier record']]);
                    return;
                }
            } else {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$guests_table} WHERE id_number = %s",
                    $id_number
                ));

                if ($existing > 0) {
                    error_log("[Supplier Sign-In] Duplicate ID number {$id_number} detected for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['This ID number is already registered with another supplier']]);
                    return;
                }

                // Safe to update Supplier record
                $updated = $wpdb->update(
                    $guests_table,
                    ['id_number' => $id_number],
                    ['id' => $visit->guest_id],
                    ['%s'],
                    ['%d']
                );

                if ($updated === false) {
                    error_log("[Supplier Sign-In] Failed to update ID number for guest_id={$visit->guest_id}");
                    wp_send_json_error(['messages' => ['Failed to save ID number']]);
                    return;
                }

                $visit->id_number = $id_number;
                error_log("[Supplier Sign-In] ID number saved successfully for guest_id={$visit->guest_id}");
            }

            // -------------------------------------------------------------
            // 4. Prevent duplicate sign-ins
            // -------------------------------------------------------------
            if (!empty($visit->sign_in_time)) {
                error_log("[Supplier Sign-In] Supplier already signed in: guest_id={$visit->guest_id}");
                wp_send_json_error(['messages' => ['Supplier already signed in']]);
                return;
            }

            // -------------------------------------------------------------
            // 5. Check Supplier status
            // -------------------------------------------------------------
            if (in_array($visit->guest_status, ['banned', 'suspended'])) {
                error_log("[Supplier Sign-In] Restricted Supplier: guest_id={$visit->guest_id}, status={$visit->guest_status}");
                wp_send_json_error(['messages' => ['Supplier access is restricted due to status: ' . $visit->guest_status]]);
                return;
            }

            // -------------------------------------------------------------
            // 6. Ensure visit date matches today
            // -------------------------------------------------------------
            $current_date = current_time('Y-m-d');
            $visit_date   = date('Y-m-d', strtotime($visit->visit_date));

            if ($visit_date !== $current_date) {
                error_log("[Supplier Sign-In] Wrong visit date for guest_id={$visit->guest_id}. Expected {$current_date}, got {$visit_date}");
                wp_send_json_error(['messages' => ['Supplier can only sign in on their scheduled visit date']]);
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
                error_log("[Supplier Sign-In] Failed to update sign_in_time for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Failed to sign in supplier']]);
                return;
            }

            error_log("[Supplier Sign-In] Supplier sign-in recorded successfully for guest_id={$visit->guest_id}");

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

                error_log("[Supplier Sign-In] Sending notifications for guest_id={$visit->guest_id}");
                VMS_SMS::get_instance()->send_signin_notification($guest_data, $visit_data);
                self::send_signin_email_notification($guest_data, $visit_data);
                error_log("[Supplier Sign-In] Notifications sent successfully for guest_id={$visit->guest_id}");
            } catch (Throwable $notify_error) {
                error_log("[Supplier Sign-In] Notification error for guest_id={$visit->guest_id}: " . $notify_error->getMessage());
                error_log("[Supplier Sign-In] Trace: " . $notify_error->getTraceAsString());
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

            error_log("[Supplier Sign-In] Success response prepared for guest_id={$visit->guest_id}");
            wp_send_json_success([
                'messages'  => ['Supplier signed in successfully'],
                'guestData' => $guest_data_response
            ]);

        } catch (Throwable $e) {
            // -------------------------------------------------------------
            // Global fail-safe for unexpected runtime errors
            // -------------------------------------------------------------
            error_log("[Supplier Sign-In] Fatal error: " . $e->getMessage());
            error_log("[Supplier Sign-In] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred. Please try again.']]);
        }
    }

    /**
     * Handle Supplier sign-out via AJAX with logging, validation, and safe notification handling
     */
    public static function handle_sign_out_suppliers(): void
    {
        self::verify_ajax_request();

        try {
            // -------------------------------------------------------------
            // 1. Validate input
            // -------------------------------------------------------------
            $visit_id = isset($_POST['visit_id']) ? absint($_POST['visit_id']) : 0;
            error_log("[Supplier Sign-Out] Received request for visit_id={$visit_id}");

            if (!$visit_id) {
                wp_send_json_error(['messages' => ['Invalid visit ID']]);
                return;
            }

            global $wpdb;
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
            $guests_table       = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

            // -------------------------------------------------------------
            // 2. Fetch visit and Supplier details
            // -------------------------------------------------------------
            $visit = $wpdb->get_row($wpdb->prepare(
                "SELECT gv.*, g.first_name, g.last_name, g.phone_number, g.email, g.receive_messages, g.receive_emails
                FROM {$guest_visits_table} gv
                LEFT JOIN {$guests_table} g ON g.id = gv.guest_id
                WHERE gv.id = %d",
                $visit_id
            ));

            if (!$visit) {
                error_log("[Supplier Sign-Out] Visit not found for ID {$visit_id}");
                wp_send_json_error(['messages' => ['Visit not found']]);
                return;
            }

            // -------------------------------------------------------------
            // 3. Validate sign-in/out state
            // -------------------------------------------------------------
            if (empty($visit->sign_in_time)) {
                error_log("[Supplier Sign-Out] Supplier not signed in yet for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Supplier must be signed in first']]);
                return;
            }

            if (!empty($visit->sign_out_time)) {
                error_log("[Supplier Sign-Out] Supplier already signed out for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Supplier already signed out']]);
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
                error_log("[Supplier Sign-Out] Database update failed for visit_id={$visit_id}");
                wp_send_json_error(['messages' => ['Failed to sign out supplier']]);
                return;
            }

            error_log("[Supplier Sign-Out] Supplier signed out successfully for guest_id={$visit->guest_id}");

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
                error_log("[Supplier Sign-Out] Sending sign-out notifications for guest_id={$visit->guest_id}");
                VMS_SMS::get_instance()->send_signout_notification($guest_data, $visit_data);
                self::send_signout_email_notification($guest_data, $visit_data);
                error_log("[Supplier Sign-Out] Notifications sent successfully for guest_id={$visit->guest_id}");
            } catch (Throwable $notify_error) {
                error_log("[Supplier Sign-Out] Notification error for guest_id={$visit->guest_id}: " . $notify_error->getMessage());
                error_log("[Supplier Sign-Out] Trace: " . $notify_error->getTraceAsString());
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
                'messages'  => ['Supplier signed out successfully'],
                'guestData' => $guest_data_response
            ]);

        } catch (Throwable $e) {
            // -------------------------------------------------------------
            // Global fallback
            // -------------------------------------------------------------
            error_log("[Supplier Sign-Out] Fatal error: " . $e->getMessage());
            error_log("[Supplier Sign-Out] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred during sign-out. Please try again.']]);
        }
    }

    /**
     * Send visit cancellation email notification
     */
    private static function send_visit_cancellation_email(array $guest_data, array $visit_data): void
    {
        global $wpdb;

        $guests_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
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
     * Handle Suppliers registration via AJAX - with enhanced logging and safe error handling.
     */
    public static function handle_suppliers_registration(): void
    {
        self::verify_ajax_request();
        error_log("[Suppliers Registration] === handle_suppliers_registration() START ===");

        global $wpdb;
        $errors = [];

        try {
            // -------------------------------------------------------------
            // Step 1: Sanitize input
            // -------------------------------------------------------------
            $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
            $phone_number = sanitize_text_field($_POST['phone_number'] ?? '');
            $visit_date   = current_time('Y-m-d');

            error_log("[Suppliers Registration] Input received: first_name={$first_name}, last_name={$last_name}, phone={$phone_number}, visit_date={$visit_date}");

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
                error_log("[Suppliers Registration] Validation failed: " . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
                return;
            }

            // -------------------------------------------------------------
            // Step 3: Define table names
            // -------------------------------------------------------------
            $guests_table       = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
            $guest_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);

            error_log("[Suppliers Registration] Using tables: guests={$guests_table}, guest_visits={$guest_visits_table}");

            // -------------------------------------------------------------
            // Step 4: Check for existing guest
            // -------------------------------------------------------------
            $existing_guest = $wpdb->get_row($wpdb->prepare(
                "SELECT id, receive_emails, receive_messages, guest_status FROM $guests_table WHERE phone_number = %s",
                $phone_number
            ));

            if ($existing_guest) {
                $guest_id         = (int) $existing_guest->id;
                $receive_emails   = $existing_guest->receive_emails;
                $receive_messages = $existing_guest->receive_messages;
                error_log("[Suppliers Registration] Existing supplier found with ID: {$guest_id}");
            } else {
                error_log("[Suppliers Registration] No existing supplier found. Creating new record...");

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
                    error_log("[Suppliers Registration] ERROR: Failed to insert supplier: " . $wpdb->last_error);
                    wp_send_json_error(['messages' => ['Failed to create supplier record']]);
                    return;
                }

                $guest_id         = $wpdb->insert_id;
                $receive_emails   = 'yes';
                $receive_messages = 'yes';
                error_log("[Suppliers Registration] New supplier created with ID: {$guest_id}");
            }

            // -------------------------------------------------------------
            // Step 5: Prevent duplicate visits on same date
            // -------------------------------------------------------------
            $existing_visit = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $guest_visits_table WHERE guest_id = %d AND visit_date = %s AND status != 'cancelled'",
                $guest_id,
                $visit_date
            ));

            if ($existing_visit) {
                error_log("[Suppliers Registration] Duplicate visit detected for guest_id {$guest_id} on {$visit_date}");
                wp_send_json_error(['messages' => ['This supplier already has a visit registered on this date']]);
                return;
            }

            // -------------------------------------------------------------
            // Step 6: Insert new visit record
            // -------------------------------------------------------------
            $preliminary_status = 'approved';
            $visit_insert = $wpdb->insert(
                $guest_visits_table,
                [
                    'guest_id'   => $guest_id,
                    'visit_date' => $visit_date,
                    'status'     => $preliminary_status
                ],
                ['%d', '%s', '%s']
            );

            if ($visit_insert === false) {
                error_log("[Suppliers Registration] ERROR: Failed to create visit record: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to create visit record']]);
                return;
            }

            $visit_id = $wpdb->insert_id;
            error_log("[Suppliers Registration] Visit record created successfully with ID: {$visit_id}");

            // -------------------------------------------------------------
            // Step 7: Send SMS notification (non-blocking)
            // -------------------------------------------------------------
            try {
                error_log("[Suppliers Registration] Attempting to send SMS for guest_id {$guest_id}");
                self::send_supplier_registration_sms(
                    $guest_id,
                    $first_name,
                    $last_name,
                    $phone_number,
                    $receive_messages,
                    $visit_date,
                    $preliminary_status
                );
                error_log("[Suppliers Registration] SMS process completed successfully for guest_id {$guest_id}");
            } catch (Throwable $e) {
                error_log("[Suppliers Registration] ERROR sending SMS for guest_id {$guest_id}: " . $e->getMessage());
                error_log("[Suppliers Registration] Stack trace: " . $e->getTraceAsString());
            }

            // -------------------------------------------------------------
            // Step 8: Return success response
            // -------------------------------------------------------------
            $guest_data = [
                'id'               => $guest_id,
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'phone_number'     => $phone_number,
                'visit_date'       => $visit_date,
                'receive_emails'   => $receive_emails,
                'receive_messages' => $receive_messages,
                'status'           => $preliminary_status,
                'guest_status'     => $existing_guest->guest_status ?? 'active',
                'sign_in_time'     => null,
                'sign_out_time'    => null,
                'visit_id'         => $visit_id
            ];

            error_log("[Suppliers Registration] Registration completed successfully for guest_id {$guest_id}");
            wp_send_json_success([
                'messages'  => ['Supplier registered successfully'],
                'guestData' => $guest_data
            ]);

        } catch (Throwable $e) {
            error_log("[Suppliers Registration] FATAL ERROR: " . $e->getMessage());
            error_log("[Suppliers Registration] Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred. Please try again later.']]);
        }

        error_log("[Suppliers Registration] === handle_suppliers_registration() END ===");
    }

    /**
     * Handle visit registration via AJAX - Updated with error logging, comments, and try/catch
     */
    public static function handle_visit_registration()
    {
        global $wpdb;

        try {
            error_log('[Suppliers Visit Registration] Visit registration started.');

            // --- Retrieve & sanitize POST data ---
            $guest_id       = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
            $visit_date     = sanitize_text_field($_POST['visit_date'] ?? '');

            $errors = [];

            $guests_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

            // --- Validate guest ---
            if ($guest_id <= 0) {
                $errors[] = 'Suppliers is required';
            } else {
                $guest_exists = $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$guests_table} WHERE id = %d", $guest_id)
                );
                if (!$guest_exists) {
                    $errors[] = 'Invalid supplier selected';
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
                error_log('[Suppliers Visit Registration] Validation failed - ' . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
            }

            // --- Define tables ---
            $table         = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
            $guests_table  = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

            // --- Fetch guest info ---
            $guest_info = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name, email, phone_number, receive_emails, receive_messages 
                FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            if (!$guest_info) {
                error_log("[Suppliers Visit Registration] Suppliers info not found for ID $guest_id.");
                wp_send_json_error(['messages' => ['Suppliers record not found']]);
            }

            // --- Check for duplicate visit (not cancelled) ---
            $existing_visit = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE guest_id = %d AND visit_date = %s",
                $guest_id,
                $visit_date
            ));

            if ($existing_visit && $existing_visit->status !== 'cancelled') {
                error_log("[Suppliers Visit Registration] Duplicate visit found for guest ID $guest_id on $visit_date.");
                wp_send_json_error(['messages' => ['This guest already has a visit registered on this date']]);
            }

            // --- Enforce visit limits ---
            $month_start = date('Y-m-01', strtotime($visit_date));
            $month_end   = date('Y-m-t', strtotime($visit_date));
            $year_start  = date('Y-01-01', strtotime($visit_date));
            $year_end    = date('Y-12-31', strtotime($visit_date));
            $today       = date('Y-m-d');            

            // --- Determine status ---
            $preliminary_status = 'approved';            

            // --- Insert or update visit record ---
            if ($existing_visit && $existing_visit->status === 'cancelled') {
                error_log("[Suppliers Visit Registration] Reusing cancelled visit record ID {$existing_visit->id}.");

                $updated = $wpdb->update(
                    $table,
                    [
                        'status'         => $preliminary_status,
                        'sign_in_time'   => null,
                        'sign_out_time'  => null,
                    ],
                    ['id' => $existing_visit->id],
                    ['%s','%s','%s'],
                    ['%d']
                );

                if ($updated === false) {
                    error_log("[Suppliers Visit Registration] ERROR: Failed to update cancelled visit for guest ID $guest_id.");
                    wp_send_json_error(['messages' => ['Failed to update cancelled visit']]);
                }

                $visit_id = $existing_visit->id;
            } else {
                error_log("[Suppliers Visit Registration] Inserting new visit record for guest ID $guest_id.");
                $inserted = $wpdb->insert(
                    $table,
                    [
                        'guest_id'       => $guest_id,
                        'visit_date'     => $visit_date,
                        'status'         => $preliminary_status
                    ],
                    ['%d','%s','%s']
                );

                if (!$inserted) {
                    error_log("[Suppliers Visit Registration] ERROR: DB insert failed for guest ID $guest_id.");
                    wp_send_json_error(['messages' => ['Failed to register visit']]);
                }

                $visit_id = $wpdb->insert_id;
            }

            // --- Send email/SMS notifications ---
            if ($guest_info) {
                
                error_log("[Suppliers Visit Registration] Sending standard visit notifications to {$guest_info->email}.");

                self::send_visit_registration_emails(
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->email,
                    $guest_info->receive_emails,
                    $visit_date,
                    $preliminary_status
                );

                self::send_visit_registration_sms(
                    $guest_id,
                    $guest_info->first_name,
                    $guest_info->last_name,
                    $guest_info->phone_number,
                    $guest_info->receive_messages,
                    $visit_date,
                    $preliminary_status
                );
                
            }

            // --- Retrieve the saved visit ---
            $visit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $visit_id));
           
            // --- Compute status display fields ---
            $status       = VMS_Core::get_visit_status($visit->visit_date, $visit->sign_in_time, $visit->sign_out_time);
            $status_class = VMS_Core::get_status_class($status);
            $status_text  = VMS_Core::get_status_text($status);

            error_log("[Suppliers Visit Registration] Visit successfully registered (visit ID $visit_id).");

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
        } catch (Exception $e) {
            error_log('[Suppliers Visit Registration] ERROR: Exception during visit registration - ' . $e->getMessage());
            wp_send_json_error(['messages' => ['An unexpected error occurred during visit registration.']]);
        }
    }  
    
    
    /**
     * Send SMS notifications for supplier registration (no host involved)
     *
     * @param int    $guest_id                ID of the supplier record.
     * @param string $first_name              Guest's first name.
     * @param string $last_name               Guest's last name.
     * @param string $guest_phone             Guest's phone number.
     * @param string $guest_receive_messages  'yes' if guest wants SMS notifications.
     * @param string $visit_date              Date of the visit (Y-m-d format).
     * @param string $status                  Visit status ('approved' or 'pending').
     *
     * @return void
     */
    private static function send_supplier_registration_sms(
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
        error_log("[Supplier SMS] === send_supplier_registration_sms() triggered for guest_id: {$guest_id} ===");

        try {
            // Format visit date for readability (e.g., October 22, 2025)
            $formatted_date = date('F j, Y', strtotime($visit_date));
            $status_text    = ($status === 'approved') ? 'Approved' : 'Pending Approval';

            // Log SMS eligibility
            error_log("[Supplier SMS] Checking message preferences for guest_id: {$guest_id}, receive_messages: {$guest_receive_messages}");

            // Only proceed if guest opted in and phone number is valid
            if ($guest_receive_messages === 'yes' && !empty($guest_phone)) {
                error_log("[Supplier SMS] Preparing message for {$first_name} {$last_name} (Phone: {$guest_phone})");

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
                    error_log("[Supplier SMS] Sending SMS to {$guest_phone} ...");
                    VMS_SMS::send_sms($guest_phone, $guest_message, $guest_id, $role);
                    error_log("[Supplier SMS] SMS sent successfully to {$guest_phone}");
                } catch (Throwable $e) {
                    error_log("[Supplier SMS] ERROR sending SMS to {$guest_phone}: " . $e->getMessage());
                    error_log("[Supplier SMS] Stack trace: " . $e->getTraceAsString());
                }
                
            } else {
                error_log("[Supplier SMS] Guest opted out or phone missing. No SMS sent for guest_id: {$guest_id}");
            }
        } catch (Throwable $e) {
            // Catch any unexpected exception in this method
            error_log("[Supplier SMS] FATAL ERROR for guest_id {$guest_id}: " . $e->getMessage());
            error_log("[Supplier SMS] Stack trace: " . $e->getTraceAsString());
            // Do not rethrow — registration should continue gracefully
        }

        // Log method completion
        error_log("[Supplier SMS] === SMS process completed for guest_id: {$guest_id} ===");
    }

    /**
     * Send SMS notification to SUPPLIER upon visit registration
     *
     * This method notifies a SUPPLIER via SMS when a visit is registered.
     * It only sends the message if the SUPPLIER has opted in for SMS notifications.
     *
     * @param int    $guest_id              The ID of the SUPPLIER
     * @param string $first_name            SUPPLIER's first name
     * @param string $last_name             SUPPLIER's last name
     * @param string $guest_phone           SUPPLIER's phone number
     * @param string $guest_receive_messages Whether the SUPPLIER wants to receive SMS ('yes' or 'no')
     * @param string $visit_date            The visit date (Y-m-d)
     * @param string $status                The visit approval status ('approved' or 'pending')
     *
     * @return void
     */
    private static function send_visit_registration_sms(
        $guest_id,
        $first_name,
        $last_name,
        $guest_phone,
        $guest_receive_messages,
        $visit_date,
        $status
    ): void 
    {
        try {
            error_log("Preparing to send visit registration SMS for SUPPLIER ID {$guest_id}");

            // 1. Validate input and skip if SMS should not be sent
            if ($guest_receive_messages !== 'yes') {
                error_log("SUPPLIER {$guest_id} has opted out of SMS notifications. Skipping SMS send.");
                return;
            }

            if (empty($guest_phone)) {
                error_log("SUPPLIER {$guest_id} has no valid phone number. Cannot send SMS.");
                return;
            }

            // 2. Format visit date and determine status
            $formatted_date = date('F j, Y', strtotime($visit_date));
            $status_text = ($status === 'approved') ? 'Approved' : 'Pending Approval';

            // 3. Build message content
            $guest_message = "Dear {$first_name},\n";
            $guest_message .= "You have been booked as a visitor at Nyeri Club. ";
            $guest_message .= "Your visit registered for {$formatted_date} is {$status_text}.";

            if ($status === 'approved') {
                $guest_message .= " Please present a valid ID or Passport upon arrival at the Club Reception.";
            } else {
                $guest_message .= " You will be notified once approved.";
            }

            $role = 'guest';

            // 4. Log message before sending
            error_log("Sending SMS to {$guest_phone} for SUPPLIER ID {$guest_id}");
            error_log("SMS content: {$guest_message}");

            // 5. Send the SMS using the notification handler            
            VMS_SMS::send_sms($guest_phone, $guest_message, $guest_id, $role);
            error_log("SMS successfully dispatched to {$guest_phone} for SUPPLIER {$guest_id}");            

        } catch (Exception $e) {
            // 6. Handle unexpected exceptions safely
            error_log("Exception in send_visit_registration_sms for SUPPLIER {$guest_id}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
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
        $visit_date,
        $status
    ) 
    {
        try {
            error_log("[Visit Registration Email] Starting send_visit_registration_emails()");

            // Safety defaults
            $guest_first_name = $guest_first_name ?: '[Unknown Supplier]';
            $guest_last_name  = $guest_last_name ?: '';
            $formatted_date   = !empty($visit_date) ? date('F j, Y', strtotime($visit_date)) : '[Unknown Date]';
            $status_text      = ($status === 'approved') ? 'approved' : 'pending approval';            

            // --- SUPPLIER EMAIL ---
            if ($guest_receive_emails === 'yes' && !empty($guest_email) && is_email($guest_email)) {
                $subject = 'Visit Registration Confirmation - Nyeri Club';
                $message  = "Dear {$guest_first_name},\n\n";
                $message .= "Your visit to Nyeri Club has been registered successfully.\n\n";
                $message .= "Visit Details:\n";
                $message .= "Date: {$formatted_date}\n";    
                $message .= "Status: " . ucfirst($status_text) . "\n\n";
                $message .= ($status === 'approved')
                    ? "Your visit has been approved. Please present a valid ID when you arrive at reception.\n\n"
                    : "Your visit is currently pending approval. You will receive another email once approved.\n\n";
                $message .= "Thank you for choosing Nyeri Club.\n\n";
                $message .= "Best regards,\nNyeri Club Visitor Management System";

                error_log("[Visit Registration Email] Sending supplier email to {$guest_email}");
                if (!wp_mail($guest_email, $subject, $message)) {
                    error_log("[Visit Registration Email] wp_mail() returned false for guest_email={$guest_email}");
                } else {
                    error_log("[Visit Registration Email] Supplier email sent successfully to {$guest_email}");
                }
            } else {
                error_log("[Visit Registration Email] Skipped supplier email (opt-out or invalid email)");
            }           

        } catch (Throwable $e) {
            error_log("[Visit Registration Email] Exception: " . $e->getMessage());
            error_log("[Visit Registration Email] Trace: " . $e->getTraceAsString());
        }
    }    

    // Handle suppliers update via AJAX
    public static function handle_suppliers_update()
    {
        // Verify AJAX request (nonce and permissions)
        self::verify_ajax_request();

        error_log('---[Supplier Update Handler Triggered]---');

        $errors = [];

        try {
            // ----------------------------
            // 1. SANITIZE AND VALIDATE INPUT
            // ----------------------------
            $guest_id         = sanitize_text_field($_POST['guest_id'] ?? '');
            $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
            $email            = sanitize_email($_POST['email'] ?? '');
            $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
            $id_number        = sanitize_text_field($_POST['id_number'] ?? '');
            $guest_status     = sanitize_text_field($_POST['guest_status'] ?? 'active');
            $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
            $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

            // Log sanitized input
            error_log('Sanitized input: ' . print_r([
                'guest_id'         => $guest_id,
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'email'            => $email,
                'phone_number'     => $phone_number,
                'id_number'        => $id_number,
                'guest_status'     => $guest_status,
                'receive_messages' => $receive_messages,
                'receive_emails'   => $receive_emails
            ], true));

            // Validate required fields
            if (empty($guest_id)) $errors[] = 'Supplier ID is required';
            if (empty($first_name)) $errors[] = 'First name is required';
            if (empty($last_name)) $errors[] = 'Last name is required';
            if (empty($phone_number)) $errors[] = 'Phone number is required';

            if (!empty($errors)) {
                error_log('Validation failed: ' . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
            }

            global $wpdb;
            $guests_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);

            // ----------------------------
            // 2. FETCH EXISTING Supplier
            // ----------------------------
            $guest = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            if (!$guest) {
                error_log("Supplier not found: ID {$guest_id}");
                wp_send_json_error(['messages' => ['Supplier not found']]);
            }

            $old_status = $guest->guest_status;
            $new_status = $guest_status;

            error_log("Existing Supplier found: {$guest->first_name} {$guest->last_name} (Status: {$old_status})");

            // ----------------------------
            // 3. CHECK FOR DUPLICATE ID NUMBER (NULL-SAFE)
            // ----------------------------
            if ($id_number !== '') {
                // Check only if ID number is not empty and not NULL
                $id_number_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $guests_table 
                    WHERE id_number IS NOT NULL 
                    AND id_number = %s 
                    AND id != %d",
                    $id_number,
                    $guest_id
                ));

                if ($id_number_exists) {
                    error_log("Duplicate ID number detected for supplier {$guest_id} (ID Number: {$id_number})");
                    wp_send_json_error(['messages' => ['ID number is already in use by another supplier']]);
                }
            } else {
                // Log case where ID number is NULL or empty
                error_log("Supplier {$guest_id} updated with NULL or empty ID number — allowed.");
            }

            // ----------------------------
            // 4. UPDATE Supplier RECORD
            // ----------------------------
            $update_data = [
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'email'            => $email,
                'phone_number'     => $phone_number,
                'id_number'        => $id_number,
                'guest_status'     => $new_status,
                'receive_messages' => $receive_messages,
                'receive_emails'   => $receive_emails,
                'updated_at'       => current_time('mysql'),
            ];

            $update_formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

            $result = $wpdb->update(
                $guests_table,
                $update_data,
                ['id' => $guest_id],
                $update_formats,
                ['%d']
            );

            if ($result === false) {
                error_log("Database update failed for supplier {$guest_id}: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to update supplier record']]);
            }

            error_log("Supplier updated successfully in DB: ID {$guest_id}");

            // ----------------------------
            // 5. HANDLE STATUS CHANGE NOTIFICATIONS
            // ----------------------------
            $guest_data = [
                'first_name'       => $first_name,
                'phone_number'     => $phone_number,
                'email'            => $email,
                'receive_messages' => $receive_messages,
                'receive_emails'   => $receive_emails,
                'user_id'          => $guest_id,
            ];

            if ($old_status !== $new_status) {
                error_log("Status changed for supplier {$guest_id}: {$old_status} → {$new_status}");

                try {
                    VMS_Guest::send_guest_status_change_email($guest_data, $old_status, $new_status);
                    VMS_Guest::send_guest_status_change_sms($guest_data, $old_status, $new_status);
                    error_log("Status change notifications sent for supplier {$guest_id}");
                } catch (Exception $e) {
                    error_log("Error sending notifications for supplier {$guest_id}: " . $e->getMessage());
                }
            } else {
                error_log("No status change detected for supplier {$guest_id}");
            }

            // ----------------------------
            // 6. SUCCESS RESPONSE
            // ----------------------------
            wp_send_json_success([
                'message' => 'Supplier updated successfully'
            ]);

        } catch (Exception $e) {
            // Catch any unexpected errors
            error_log("Exception in handle_supplier_update: " . $e->getMessage());
            wp_send_json_error(['messages' => ['Unexpected error occurred. Please try again later.']]);
        }
    }


    /**
     * Handle suppliers deletion via AJAX with full error logging and safety checks.
     */
    public static function handle_suppliers_deletion()
    {
        $function = '[handle_accommodation_supplier_deletion]';
        error_log("$function Function triggered.");

        try {
            // --- Security Check ---
            self::verify_ajax_request();
            error_log("$function AJAX request verified.");

            // --- Validate Supplier ID ---
            $guest_id = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;

            if (empty($guest_id)) {
                error_log("$function Missing guest_id in request.");
                wp_send_json_error(['messages' => ['Supplier ID is required']]);
                return;
            }

            global $wpdb;
            $guests_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
            $visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);

            error_log("$function Checking existence of guest_id=$guest_id in $guests_table");

            // --- Verify Supplier Exists ---
            $existing_guest = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $guests_table WHERE id = %d",
                $guest_id
            ));

            if (!$existing_guest) {
                error_log("$function Supplier not found for guest_id=$guest_id.");
                wp_send_json_error(['messages' => ['Supplier not found']]);
                return;
            }

            // --- Delete All Related Supplier Visits ---
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

            // --- Delete Supplier Record ---
            error_log("$function Deleting guest_id=$guest_id from $guests_table");

            $deleted_guest = $wpdb->delete(
                $guests_table,
                ['id' => $guest_id],
                ['%d']
            );

            if ($deleted_guest === false) {
                error_log("$function Failed to delete guest_id=$guest_id. MySQL error: " . $wpdb->last_error);
                wp_send_json_error(['messages' => ['Failed to delete supplier record']]);
                return;
            }

            error_log("$function Supplier deleted successfully. guest_id=$guest_id");
            wp_send_json_success(['messages' => ['Supplier deleted successfully']]);

        } catch (Throwable $e) {
            error_log("$function Exception: " . $e->getMessage());
            error_log("$function Trace: " . $e->getTraceAsString());
            wp_send_json_error(['messages' => ['An unexpected error occurred during supplier deletion.']]);
        }
    }
   
    /**
     * Automatically sign out suppliers at midnight for the current day
     */
    public static function auto_sign_out_suppliers()
    {
        global $wpdb;
        error_log('Auto sign out Suppliers');
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
        
        // Get yesterday's date
        $yesterday = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
        
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, guest_id, visit_date
            FROM $guest_visits_table
            WHERE sign_in_time IS NOT NULL
            AND sign_out_time IS NULL
            AND DATE(visit_date) = %s",
            $yesterday
        ));
        
        if (empty($visits)) {
            error_log('No suppliers to sign out');
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
                error_log("Failed to sign out supplier visit ID: {$visit->id}");
            } else {
                error_log("Signed out supplier visit ID: {$visit->id}");
            }          
            
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