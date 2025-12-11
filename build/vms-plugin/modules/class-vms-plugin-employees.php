<?php
/**
 * Employee functionality handler for VMS plugin
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

class VMS_Employee extends Base
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
        self::setup_employee_management_hooks();
    }


    /**
     * Setup guest management related hooks
     */
    private static function setup_employee_management_hooks(): void
    {
        // Employee
        add_action('wp_ajax_employee_registration', [self::class, 'handle_employee_registration']);
        add_action('wp_ajax_update_employee', [self::class, 'handle_employee_update']);
        add_action('wp_ajax_delete_employee', [self::class, 'handle_employee_deletion']); 
    }

    /**
     * Handle employee update via AJAX
     * Processes employee profile updates including personal info, status, role, and preferences
     * 
     * @return void Sends JSON response
     */
    public static function handle_employee_update() 
    {
        error_log('=== Employee Update Request Started ===');
        
        // Verify nonce for security
        self::verify_ajax_request();
        error_log('Nonce verified successfully');

        // Check user permissions
        if (!current_user_can('manage_options')) {
            error_log('Permission denied: User lacks manage_options capability');
            wp_send_json_error(['messages' => ['You do not have permission to perform this action']]);
            return;
        }

        $errors = [];

        // Sanitize and retrieve input data
        $user_id = absint($_POST['user_id'] ?? 0);
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone_number = sanitize_text_field($_POST['pnumber'] ?? '');
        $registration_status = sanitize_text_field($_POST['registration_status'] ?? 'pending');
        $user_role = sanitize_key($_POST['user_role'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails = isset($_POST['receive_emails']) ? 'yes' : 'no';

        error_log('Processing update for Employee User ID: ' . $user_id);
        error_log('Status change to: ' . $registration_status);
        error_log('Role change to: ' . $user_role);

        // Validate required fields
        if (empty($user_id)) {
            $errors[] = 'User ID is required';
            error_log('Validation failed: Missing user ID');
        }
        if (empty($first_name)) {
            $errors[] = 'First name is required';
            error_log('Validation failed: Missing first name');
        }
        if (empty($last_name)) {
            $errors[] = 'Last name is required';
            error_log('Validation failed: Missing last name');
        }
        if (empty($email) || !is_email($email)) {
            $errors[] = 'Valid email is required';
            error_log('Validation failed: Invalid or missing email');
        }
        if (empty($phone_number)) {
            $errors[] = 'Phone number is required';
            error_log('Validation failed: Missing phone number');
        }

        // Validate status
        $valid_statuses = ['pending', 'active', 'suspended', 'banned'];
        if (!in_array($registration_status, $valid_statuses)) {
            $errors[] = 'Invalid account status';
            error_log('Validation failed: Invalid status - ' . $registration_status);
        }

        // Validate role
        $valid_roles = ['general_manager', 'gate', 'reception'];
        if (!empty($user_role) && !in_array($user_role, $valid_roles)) {
            $errors[] = 'Invalid role selected';
            error_log('Validation failed: Invalid role - ' . $user_role);
        }

        // Return early if validation fails
        if (!empty($errors)) {
            error_log('Validation failed with ' . count($errors) . ' errors');
            wp_send_json_error(['messages' => $errors]);
            return;
        }

        // Get existing user data
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            error_log('User not found with ID: ' . $user_id);
            wp_send_json_error(['messages' => ['User not found']]);
            return;
        }

        error_log('Employee found: ' . $user_data->user_login);

        // Store old values for comparison and notifications
        $old_email = $user_data->user_email;
        $old_status = get_user_meta($user_id, 'registration_status', true);
        $old_role = !empty($user_data->roles) ? $user_data->roles[0] : '';

        // Check if email is already used by another user
        if ($email !== $old_email) {
            $email_exists = email_exists($email);
            if ($email_exists && $email_exists != $user_id) {
                error_log('Email already in use: ' . $email);
                wp_send_json_error(['messages' => ['Email is already in use by another employee']]);
                return;
            }
            error_log('Email will be updated from ' . $old_email . ' to ' . $email);
        }

        // Update user email if changed
        if ($email !== $old_email) {
            $user_data->user_email = $email;
            $update_result = wp_update_user($user_data);
            if (is_wp_error($update_result)) {
                error_log('Email update failed: ' . $update_result->get_error_message());
                wp_send_json_error(['messages' => ['Failed to update email: ' . $update_result->get_error_message()]]);
                return;
            }
            error_log('Email updated successfully');
        }

        // Update first name if changed
        if ($first_name !== $user_data->first_name) {
            update_user_meta($user_id, 'first_name', $first_name);
            error_log('First name updated from "' . $user_data->first_name . '" to "' . $first_name . '"');
        }

        // Update last name if changed
        if ($last_name !== $user_data->last_name) {
            update_user_meta($user_id, 'last_name', $last_name);
            error_log('Last name updated from "' . $user_data->last_name . '" to "' . $last_name . '"');
        }

        // Update phone number if changed
        $current_phone = get_user_meta($user_id, 'phone_number', true);
        if ($phone_number !== $current_phone) {
            update_user_meta($user_id, 'phone_number', $phone_number);
            error_log('Phone number updated from "' . $current_phone . '" to "' . $phone_number . '"');
        }

        // Update registration status and send notifications if changed
        if ($registration_status !== $old_status) {
            error_log('Status changing from "' . $old_status . '" to "' . $registration_status . '"');
            
            update_user_meta($user_id, 'registration_status', $registration_status);

            // Prepare notification messages based on new status
            $subject = '';
            $message = '';
            $sms_message = '';
            $user_login = $user_data->user_login;

            switch ($registration_status) {
                case 'pending':
                    $subject = 'Your account is pending approval';
                    $message = "Hello {$first_name},\n\n";
                    $message .= "Your account status has been changed to Pending Approval. Our team will review it shortly.\n\n";
                    $message .= "You will receive another notification once your account is activated.\n\n";
                    $message .= "Best regards,\nNyeri Club Visitor Management System";

                    $sms_message = "Hello {$first_name}, your account is pending approval. You will be notified once activated. - Nyeri Club";
                    error_log('Pending status notification prepared');
                    break;

                case 'active':
                    $subject = 'Your account has been activated';
                    $message = "Hello {$first_name},\n\n";
                    $message .= "Good news! Your account has been activated.\n\n";
                    $message .= "You can now log in using your username: {$user_login}\n";
                    $message .= "Login here: " . esc_url(home_url('/login')) . "\n\n";
                    $message .= "Welcome aboard!\n\n";
                    $message .= "Best regards,\nNyeri Club Visitor Management System";

                    $sms_message = "Hello {$first_name}, your account is now active. You can log in at " . esc_url(home_url('/login'));
                    error_log('Active status notification prepared');
                    break;

                case 'suspended':
                    $subject = 'Your account has been suspended';
                    $message = "Hello {$first_name},\n\n";
                    $message .= "Your account has been temporarily suspended. You will not be able to log in until reactivated.\n\n";
                    $message .= "If you believe this is an error, please contact support.\n\n";
                    $message .= "Best regards,\nNyeri Club Visitor Management System";

                    $sms_message = "Hello {$first_name}, your account has been suspended. Contact support if you think this is a mistake.";
                    error_log('Suspended status notification prepared');
                    break;

                case 'banned':
                    $subject = 'Your account has been banned';
                    $message = "Hello {$first_name},\n\n";
                    $message .= "We regret to inform you that your account has been permanently banned. You will no longer be able to access our system.\n\n";
                    $message .= "If you have questions, please reach out to our administration.\n\n";
                    $message .= "Best regards,\nNyeri Club Visitor Management System";

                    $sms_message = "Hello {$first_name}, your account has been permanently banned. Contact admin for questions.";
                    error_log('Banned status notification prepared');
                    break;
            }

            // Send email notification if preferences allow
            if ($subject && $message && $receive_emails === 'yes') {
                $email_sent = wp_mail($email, $subject, $message);
                error_log('Email notification sent: ' . ($email_sent ? 'Success' : 'Failed'));
            }

            // Send SMS notification if preferences allow and phone number exists
            if (!empty($phone_number) && !empty($sms_message) && $receive_messages === 'yes') {
                VMS_SMS::send_sms($phone_number, $sms_message, $user_id, 'member');
                error_log('SMS notification sent to ' . $phone_number);               
            }
        }

        // Update user role if changed
        if (!empty($user_role) && $user_role !== $old_role) {
            error_log('Role changing from "' . $old_role . '" to "' . $user_role . '"');
            
            $user = new WP_User($user_id);
            
            // Define custom role labels
            $role_labels = [
                'general_manager' => 'General Manager',
                'reception' => 'Reception',
                'gate' => 'Gate'
            ];
            
            // Remove all existing roles
            $current_roles = (array) $user->roles;
            foreach ($current_roles as $role) {
                $user->remove_role($role);
                error_log('Removed role: ' . $role);
            }

            // Add new role
            $user->add_role($user_role);
            error_log('Added role: ' . $user_role);

            // Clear user cache
            clean_user_cache($user_id);

            // Verify role was set
            $fresh_user = get_userdata($user_id);
            if (!$fresh_user || !in_array($user_role, (array) $fresh_user->roles, true)) {
                error_log('Role update verification failed');
                wp_send_json_error(['messages' => ['Failed to update user role']]);
                return;
            }
            error_log('Role updated and verified successfully');
        }

        // Update message preferences
        $current_receive_messages = get_user_meta($user_id, 'receive_messages', true);
        if ($receive_messages !== $current_receive_messages) {
            update_user_meta($user_id, 'receive_messages', $receive_messages);
            error_log('Message preference updated from "' . $current_receive_messages . '" to "' . $receive_messages . '"');
        }

        // Update email preferences
        $current_receive_emails = get_user_meta($user_id, 'receive_emails', true);
        if ($receive_emails !== $current_receive_emails) {
            update_user_meta($user_id, 'receive_emails', $receive_emails);
            error_log('Email preference updated from "' . $current_receive_emails . '" to "' . $receive_emails . '"');
        }

        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && !empty($_FILES['profile_picture']['name'])) {
            error_log('Profile picture upload detected: ' . $_FILES['profile_picture']['name']);
            
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $uploadedfile = $_FILES['profile_picture'];
            $upload_overrides = ['test_form' => false];
            $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

            if ($movefile && !isset($movefile['error'])) {
                $avatar_url = esc_url($movefile['url']);
                update_user_meta($user_id, 'profile_picture', $avatar_url);
                
                $avatar_id = attachment_url_to_postid($avatar_url);
                if ($avatar_id) {
                    update_user_meta($user_id, '_wp_attachment_metadata', get_post_meta($avatar_id, '_wp_attachment_metadata', true));
                }
                error_log('Profile picture uploaded successfully: ' . $avatar_url);
            } else {
                error_log('Profile picture upload failed: ' . ($movefile['error'] ?? 'Unknown error'));
            }
        }

        error_log('=== Employee Update Completed Successfully ===');
        
        wp_send_json_success([
            'message' => 'Employee updated successfully'
        ]);
    }

    /**
     * Handle employee deletion via AJAX
     * Permanently deletes an employee account from the system
     * 
     * @return void Sends JSON response
     */
    public static function handle_employee_deletion() 
    {
        error_log('=== Employee Delete Request Started ===');
        
        // Verify nonce for security
        self::verify_ajax_request();
        error_log('Nonce verified successfully');

        // Check user permissions
        if (!current_user_can('manage_options')) {
            error_log('Permission denied: User lacks manage_options capability');
            wp_send_json_error(['messages' => ['You do not have permission to perform this action']]);
            return;
        }

        // Get and validate user ID
        $user_id = absint($_POST['user_id'] ?? 0);
        
        if (empty($user_id)) {
            error_log('Validation failed: Missing user ID');
            wp_send_json_error(['messages' => ['User ID is required']]);
            return;
        }

        error_log('Processing deletion for Employee User ID: ' . $user_id);

        // Check if user exists
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            error_log('User not found with ID: ' . $user_id);
            wp_send_json_error(['messages' => ['User not found']]);
            return;
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $user_login = $user_data->user_login;
        error_log('Employee found: ' . $user_login . ' (' . $first_name . ')');

        // Prevent self-deletion via AJAX (safety check)
        $current_user_id = get_current_user_id();
        if ($user_id === $current_user_id) {
            error_log('Attempted self-deletion prevented for user ID: ' . $user_id);
            wp_send_json_error(['messages' => ['You cannot delete your own account via this method']]);
            return;
        }

        // Include required WordPress user functions
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            error_log('WordPress user functions loaded');
        }

        // Attempt to delete the user
        $deletion_result = wp_delete_user($user_id);
        
        if ($deletion_result) {
            error_log('Employee deleted successfully: ' . $user_login . ' (ID: ' . $user_id . ')');
            
            wp_send_json_success([
                'message' => $first_name . "'s account has been deleted permanently"
            ]);
        } else {
            error_log('Failed to delete employee: ' . $user_login . ' (ID: ' . $user_id . ')');
            
            wp_send_json_error([
                'messages' => ['Failed to delete employee account. Please try again.']
            ]);
        }
        
        error_log('=== Employee Delete Request Completed ===');
    }

    /**
     * Handle employee registration via AJAX - UPDATED WITH EMAIL & SMS NOTIFICATIONS + LOGGING & ERROR HANDLING
     */
    public static function handle_employee_registration(): void
    {
        try {
            // ✅ Step 1: Verify AJAX request and security nonce
            self::verify_ajax_request();
            error_log("[VMS] handle_employee_registration() called");

            global $wpdb;
            $errors = [];

            // ✅ Step 2: Sanitize incoming data
            $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
            $email            = sanitize_email($_POST['email'] ?? '');
            $phone_number     = sanitize_text_field($_POST['phone_number'] ?? '');
            $user_role        = sanitize_text_field($_POST['user_role'] ?? '');
            $receive_messages = 'yes';
            $receive_emails   = 'yes';

            // ✅ Step 3: Validate required fields
            if (empty($first_name)) $errors[] = 'First name is required';
            if (empty($last_name)) $errors[] = 'Last name is required';
            if (empty($email) || !is_email($email)) $errors[] = 'Valid email is required';
            if (empty($phone_number)) $errors[] = 'Phone number is required';
            if (empty($user_role)) $errors[] = 'User role is required';

            // ✅ Step 4: Check if email already exists
            if (email_exists($email)) {
                $errors[] = 'Email address already exists';
            }

            // ✅ Return validation errors early
            if (!empty($errors)) {
                error_log("[VMS] Employee registration validation failed: " . implode(', ', $errors));
                wp_send_json_error(['messages' => $errors]);
                return;
            }

            // ✅ Step 5: Generate unique username from first and last name
            $user_login = strtolower($first_name . '.' . $last_name);
            $user_login = sanitize_user($user_login, true);

            $original_login = $user_login;
            $counter = 1;
            while (username_exists($user_login)) {
                $user_login = $original_login . $counter;
                $counter++;
            }

            // ✅ Step 6: Generate a secure random password
            $password = wp_generate_password(12, true, true);

            // ✅ Step 7: Prepare user data for insertion
            $user_data = [
                'user_login' => $user_login,
                'user_email' => $email,
                'user_pass'  => $password,
                'role'       => $user_role,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'meta_input' => [
                    'phone_number'        => $phone_number,
                    'registration_status' => 'active',
                    'receive_emails'      => $receive_emails,
                    'receive_messages'    => $receive_messages,
                    'show_admin_bar_front'=> 'false'
                ]
            ];

            // ✅ Step 8: Attempt to insert new user
            $user_id = wp_insert_user(wp_slash($user_data));

            if (is_wp_error($user_id)) {
                $error_code = array_key_first($user_id->errors);
                $error_message = $user_id->errors[$error_code][0] ?? 'Unknown error';
                error_log("[VMS] Employee registration failed for email {$email}: {$error_message}");
                wp_send_json_error(['messages' => [$error_message]]);
                return;
            }

            error_log("[VMS] Employee user created successfully with ID {$user_id}");

            // ✅ Step 9: Send notifications (email + SMS + admin alert)
            try {
                // Email to employee
                self::send_employee_welcome_email(
                    $email,
                    $first_name,
                    $last_name,
                    $user_login,
                    $password
                );
                error_log("[VMS] Welcome email sent to {$email}");

                // SMS to employee
                self::send_employee_welcome_sms(
                    $phone_number,
                    $first_name,
                    $user_login,
                    $password,
                    $user_id
                );
                error_log("[VMS] Welcome SMS sent to {$phone_number}");

                // Admin notification
                self::send_admin_employee_notification(
                    $first_name,
                    $last_name,
                    $email,
                    $user_role
                );
                error_log("[VMS] Admin notified of new employee registration for {$email}");
            } catch (Exception $e) {
                error_log("[VMS ERROR] Notification sending failed for {$email}: " . $e->getMessage());
            }

            // ✅ Step 10: Prepare response data
            $employee_data = [
                'id'                  => $user_id,
                'first_name'          => $first_name,
                'last_name'           => $last_name,
                'email'               => $email,
                'phone_number'        => $phone_number,
                'user_role'           => $user_role,
                'user_login'          => $user_login,
                'registration_status' => 'active'
            ];

            // ✅ Step 11: Log success and send JSON response
            error_log("[VMS] Employee registration completed successfully for {$email}");
            wp_send_json_success([
                'messages'     => ['Employee registered successfully'],
                'employeeData' => $employee_data
            ]);

        } catch (Exception $e) {
            // ✅ Catch-all for any unexpected error
            error_log("[VMS ERROR] handle_employee_registration() exception: " . $e->getMessage());
            wp_send_json_error(['messages' => ['An unexpected error occurred during registration. Please try again.']]);
        }
    }

    /**
     * Send welcome email to new employee - with logging and exception handling
     */
    private static function send_employee_welcome_email($email, $first_name, $last_name, $user_login, $password): void
    {
        try {
            // ✅ Step 1: Basic validation
            if (empty($email) || !is_email($email)) {
                error_log("[VMS ERROR] Invalid email provided for welcome email: {$email}");
                return;
            }

            $login_url = home_url('/login');
            $site_name = get_bloginfo('name');

            // ✅ Step 2: Build email content
            $subject = "Welcome to {$site_name} - Your Employee Account";
            $message  = "Dear {$first_name} {$last_name},\n\n";
            $message .= "Welcome to {$site_name}! Your employee account has been created successfully.\n\n";
            $message .= "Your login credentials are:\n";
            $message .= "Username: {$user_login}\n";
            $message .= "Password: {$password}\n\n";
            $message .= "Login URL: {$login_url}\n\n";
            $message .= "Important Security Notes:\n";
            $message .= "- Please keep these credentials secure and confidential\n";
            $message .= "- We recommend changing your password after your first login\n";
            $message .= "- Never share your login credentials with anyone\n\n";
            $message .= "If you have any questions or need assistance, please contact the administrator.\n\n";
            $message .= "Best regards,\n";
            $message .= "{$site_name} Management Team";

            // ✅ Step 3: Log and attempt to send email
            error_log("[VMS] Attempting to send welcome email to {$email}");
            $result = wp_mail($email, $subject, $message);

            // ✅ Step 4: Log result
            if ($result) {
                error_log("[VMS] Welcome email successfully sent to {$email}");
            } else {
                error_log("[VMS ERROR] Failed to send welcome email to {$email}");
            }
        } catch (Exception $e) {
            // ✅ Step 5: Catch any unexpected error
            error_log("[VMS EXCEPTION] send_employee_welcome_email() failed for {$email}: " . $e->getMessage());
        }
    }

    /**
     * Send welcome SMS to new employee - with logging and exception handling
     */
    private static function send_employee_welcome_sms($phone_number, $first_name, $user_login, $password, $user_id): void
    {
        try {
            // ✅ Step 1: Basic validation
            if (empty($phone_number)) {
                error_log("[VMS ERROR] Missing phone number for employee SMS notification");
                return;
            }

            $site_name = get_bloginfo('name');
            $login_url = home_url('/login');

            // ✅ Step 2: Build SMS message (short and compliant)
            $sms_message  = "Hello {$first_name}, ";
            $sms_message .= "your employee account is ready. ";
            $sms_message .= "Username: {$user_login}, ";
            $sms_message .= "Password: {$password}. ";
            $sms_message .= "Login: {$login_url}. ";
            $sms_message .= "Change password after first login.";

            // ✅ Step 3: Log and send SMS via notification manager
            error_log("[VMS] Attempting to send welcome SMS to {$phone_number}");

            $result = VMS_SMS::send_sms(
                $phone_number,
                $sms_message,
                $user_id,
                'employee'
            );

            // ✅ Step 4: Log result of SMS operation
            if ($result) {
                error_log("[VMS] Welcome SMS successfully sent to {$phone_number}");
            } else {
                error_log("[VMS ERROR] SMS sending function returned false for {$phone_number}");
            }
            
        } catch (Exception $e) {
            // ✅ Step 5: Catch unexpected exceptions
            error_log("[VMS EXCEPTION] send_employee_welcome_sms() failed for {$phone_number}: " . $e->getMessage());
        }
    }

    /**
     * Send notification to admin about new employee registration - with logging and error handling
     */
    private static function send_admin_employee_notification($first_name, $last_name, $email, $user_role): void
    {
        try {
            // ✅ Step 1: Fetch admin email and basic validation
            $admin_email = get_option('admin_email');
            $site_name   = get_bloginfo('name');

            if (empty($admin_email) || !is_email($admin_email)) {
                error_log("[VMS ERROR] Invalid or missing admin email. Notification not sent.");
                return;
            }

            // ✅ Step 2: Convert user role key to human-readable format
            $role_names = [
                'general_manager' => 'General Manager',
                'gate'            => 'Gate Officer',
                'reception'       => 'Reception Staff',
            ];
            $readable_role = $role_names[$user_role] ?? ucwords(str_replace('_', ' ', $user_role));

            // ✅ Step 3: Build email subject and content
            $subject = "New Employee Account Created - {$site_name}";
            $message  = "Hello Administrator,\n\n";
            $message .= "A new employee account has been successfully created in the system.\n\n";
            $message .= "Employee Details:\n";
            $message .= "Name: {$first_name} {$last_name}\n";
            $message .= "Email: {$email}\n";
            $message .= "Role: {$readable_role}\n";
            $message .= "Status: Active\n";
            $message .= "Registration Date: " . date('F j, Y \a\t g:i A') . "\n\n";
            $message .= "The employee has been automatically sent their login credentials via both email and SMS.\n\n";
            $message .= "You can manage this employee account through the admin dashboard.\n\n";
            $message .= "Best regards,\n";
            $message .= "{$site_name} Visitor Management System";

            // ✅ Step 4: Log sending attempt
            error_log("[VMS] Attempting to send admin notification for new employee ({$email}) to {$admin_email}");

            // ✅ Step 5: Attempt to send the email
            $result = wp_mail($admin_email, $subject, $message);

            // ✅ Step 6: Log result
            if ($result) {
                error_log("[VMS] Admin notification successfully sent to {$admin_email}");
            } else {
                error_log("[VMS ERROR] Failed to send admin notification email to {$admin_email}");
            }

        } catch (Exception $e) {
            // ✅ Step 7: Catch unexpected exceptions
            error_log("[VMS EXCEPTION] send_admin_employee_notification() failed for admin email {$admin_email}: " . $e->getMessage());
        }
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