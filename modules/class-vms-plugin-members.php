<?php
/**
 * Member functionality handler for VMS plugin
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

class VMS_Member extends Base
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
        self::setup_member_management_hooks();
    }  

    /**
     * Setup guest management related hooks
     */
    private static function setup_member_management_hooks(): void
    {      
        add_action('wp_ajax_update_member', [self::class, 'handle_member_update']);
        add_action('wp_ajax_delete_member', [self::class, 'handle_member_deletion']); 
    } 

    /**
     * Handle member update via AJAX
     * Processes member profile updates including personal info, status, and preferences
     * 
     * @return void Sends JSON response
     */
    public static function handle_member_update() 
    {
        error_log('=== Member Update Request Started ===');
        
        // Verify nonce for security
        self::verify_ajax_request();
        error_log('Nonce verified successfully');        

        $errors = [];

        // Sanitize and retrieve input data
        $user_id = absint($_POST['user_id'] ?? 0);
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone_number = sanitize_text_field($_POST['pnumber'] ?? '');
        $member_number = sanitize_text_field($_POST['member_number'] ?? '');
        $registration_status = sanitize_text_field($_POST['registration_status'] ?? 'pending');
        $user_role = sanitize_key($_POST['user_role'] ?? '');
        $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
        $receive_emails = isset($_POST['receive_emails']) ? 'yes' : 'no';

        error_log('Processing update for User ID: ' . $user_id);
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
        if (empty($member_number)) {
            $errors[] = 'Member number is required';
            error_log('Validation failed: Missing member number');
        }

        // Validate status
        $valid_statuses = ['pending', 'active', 'suspended', 'banned'];
        if (!in_array($registration_status, $valid_statuses)) {
            $errors[] = 'Invalid account status';
            error_log('Validation failed: Invalid status - ' . $registration_status);
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

        error_log('User found: ' . $user_data->user_login);

        // Store old values for comparison and notifications
        $old_email = $user_data->user_email;
        $old_status = get_user_meta($user_id, 'registration_status', true);
        $old_role = !empty($user_data->roles) ? $user_data->roles[0] : '';

        // Check if email is already used by another user
        if ($email !== $old_email) {
            $email_exists = email_exists($email);
            if ($email_exists && $email_exists != $user_id) {
                error_log('Email already in use: ' . $email);
                wp_send_json_error(['messages' => ['Email is already in use by another member']]);
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

        // Update member number if changed
        $current_member_number = get_user_meta($user_id, 'member_number', true);
        if ($member_number !== $current_member_number) {
            update_user_meta($user_id, 'member_number', $member_number);
            error_log('Member number updated from "' . $current_member_number . '" to "' . $member_number . '"');
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

        error_log('=== Member Update Completed Successfully ===');
        
        wp_send_json_success([
            'message' => 'Member updated successfully'
        ]);
    }

    /**
     * Handle member deletion via AJAX
     * Permanently deletes a member account from the system
     * 
     * @return void Sends JSON response
     */
    public static function handle_member_deletion() 
    {
        error_log('=== Member Delete Request Started ===');
        
        // Verify nonce for security
        self::verify_ajax_request();
        error_log('Nonce verified successfully');

        // Check user permissions
        if (!current_user_can('manage_options')) {
            error_log('Permission denied: User lacks manage_options capability');
            wp_send_json_error(['messages' => ['You do not have permission to perform this action']]);
            return;
        }

        // Get and validate member ID
        $user_id = absint($_POST['user_id'] ?? 0);
        
        if (empty($user_id)) {
            error_log('Validation failed: Missing user ID');
            wp_send_json_error(['messages' => ['User ID is required']]);
            return;
        }

        error_log('Processing deletion for User ID: ' . $user_id);

        // Check if user exists
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            error_log('User not found with ID: ' . $user_id);
            wp_send_json_error(['messages' => ['User not found']]);
            return;
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $user_login = $user_data->user_login;
        error_log('User found: ' . $user_login . ' (' . $first_name . ')');

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
            error_log('User deleted successfully: ' . $user_login . ' (ID: ' . $user_id . ')');
            
            wp_send_json_success([
                'message' => $first_name . "'s account has been deleted permanently"
            ]);
        } else {
            error_log('Failed to delete user: ' . $user_login . ' (ID: ' . $user_id . ')');
            
            wp_send_json_error([
                'messages' => ['Failed to delete member account. Please try again.']
            ]);
        }
        
        error_log('=== Member Delete Request Completed ===');
    }

    // Helper function to send member status change email
    private static function send_member_status_change_email($member_data, $old_status, $new_status)
    {
        // Only send email if member wants to receive emails
        if ($member_data['receive_emails'] !== 'yes') {
            return;
        }

        $to = $member_data['email'];
        $subject = 'Member Status Update';
        
        $status_messages = [
            'active' => 'Your membership status has been activated. You now have full access to all club facilities and services.',
            'suspended' => 'Your membership has been temporarily suspended. Please contact the club administration for more information.',
            'banned' => 'Your membership has been terminated. Please contact the club administration if you believe this is an error.'
        ];

        $message = sprintf(
            "Dear %s %s,\n\nYour membership status has been updated from %s to %s.\n\n%s\n\nBest regards,\nClub Administration",
            $member_data['first_name'],
            $member_data['last_name'],
            ucfirst($old_status),
            ucfirst($new_status),
            $status_messages[$new_status] ?? 'Please contact the club administration for more information.'
        );

        wp_mail($to, $subject, $message);
    }

    // Helper function to send member status change SMS
    private static function send_member_status_change_sms($member_data, $old_status, $new_status)
    {
        // Only send SMS if member wants to receive messages
        if ($member_data['receive_messages'] !== 'yes') {
            return;
        }

        $phone = $member_data['phone_number'];
        
        $status_messages = [
            'active' => 'Your membership has been activated. Welcome back!',
            'suspended' => 'Your membership has been suspended. Please contact us.',
            'banned' => 'Your membership has been terminated. Please contact administration.'
        ];

        $message = sprintf(
            "Hi %s, your membership status changed to %s. %s",
            $member_data['first_name'],
            ucfirst($new_status),
            $status_messages[$new_status] ?? 'Contact us for details.'
        );

        // Implement your SMS sending logic here
        // This could be integration with SMS service like Twilio, Africa's Talking, etc.
        error_log("SMS to {$phone}: {$message}");
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