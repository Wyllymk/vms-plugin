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
            $sms_message  = "{$site_name}: Hello {$first_name}, ";
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
            wp_send_json_error(['message' => __('Security check failed.', 'vms')]);
        }

        // Verify if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to perform this action', 'vms')]);
        }        
    }
}