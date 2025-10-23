<?php
/**
 * Profile functionality handler for VMS plugin
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

class VMS_Profile extends Base
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
        self::setup_profile_management_hooks();
    }

    /**
     * Setup guest management related hooks
     */
    private static function setup_profile_management_hooks(): void
    {       
        // User
        add_action('wp_ajax_change_user_password', [self::class, 'handle_change_user_password'] );
        add_action('wp_ajax_update_user_profile', [self::class, 'handle_profile_update']);
    }

    /**
     * Handle profile update via AJAX.
     *
     * This method updates user profile data, metadata, and avatar image
     * in a safe and auditable manner with logging for all key operations.
     *
     * @return void
     */
    public static function handle_profile_update(): void
    {
        self::verify_ajax_request();

        $user_id = get_current_user_id();
        $errors = [];
        $success_messages = [];

        error_log("[Profile Update] === START for user_id={$user_id} ===");

        try {
            // Step 1: Basic profile info update
            self::update_basic_profile($user_id, $errors, $success_messages);

            // Step 2: Update meta fields
            self::update_metadata($user_id);

            // Step 3: Handle avatar upload if provided
            self::handle_avatar_upload($user_id, $errors, $success_messages);

        } catch (Throwable $e) {
            error_log("[Profile Update] FATAL ERROR for user_id={$user_id}: " . $e->getMessage());
            error_log("[Profile Update] Stack trace: " . $e->getTraceAsString());
            $errors[] = __('An unexpected error occurred while updating your profile.', 'vms-plugin');
        }

        self::send_ajax_response($errors, $success_messages, $user_id);

        error_log("[Profile Update] === END for user_id={$user_id} ===");
    }


    /**
     * Update basic user profile information.
     *
     * Updates name, display name, and email in the core users table.
     *
     * @param int   $user_id
     * @param array &$errors
     * @param array &$success_messages
     *
     * @return void
     */
    private static function update_basic_profile(int $user_id, array &$errors, array &$success_messages): void
    {
        error_log("[Profile Update] Updating basic profile for user_id={$user_id}");

        try {
            $user_data = [
                'ID'           => $user_id,
                'first_name'   => sanitize_text_field($_POST['first_name'] ?? ''),
                'last_name'    => sanitize_text_field($_POST['last_name'] ?? ''),
                'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
                'user_email'   => sanitize_email($_POST['email'] ?? ''),
            ];

            error_log("[Profile Update] Data to update: " . json_encode($user_data));

            $result = wp_update_user($user_data);

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                error_log("[Profile Update] ERROR updating user_id={$user_id}: {$error_message}");
                $errors[] = $error_message;
            } else {
                error_log("[Profile Update] Basic profile updated successfully for user_id={$user_id}");
                $success_messages[] = __('Profile updated successfully.', 'vms-plugin');
            }

        } catch (Throwable $e) {
            error_log("[Profile Update] Exception while updating basic profile for user_id={$user_id}: " . $e->getMessage());
            $errors[] = __('Failed to update basic profile.', 'vms-plugin');
        }
    }


    /**
     * Update additional user metadata.
     *
     * Handles phone number, description, and communication preferences.
     *
     * @param int $user_id
     * @return void
     */
    private static function update_metadata(int $user_id): void
    {
        error_log("[Profile Update] Updating metadata for user_id={$user_id}");

        try {
            $meta_fields = [
                'phone_number' => 'text',
                'description'  => 'textarea'
            ];

            foreach ($meta_fields as $field => $type) {
                if (isset($_POST[$field])) {
                    $value = $type === 'textarea'
                        ? sanitize_textarea_field($_POST[$field])
                        : sanitize_text_field($_POST[$field]);

                    update_user_meta($user_id, $field, $value);
                    error_log("[Profile Update] Updated meta '{$field}' => '{$value}' for user_id={$user_id}");
                }
            }

            // Handle checkboxes for communication preferences
            $receive_messages = isset($_POST['receive_messages']) ? 'yes' : 'no';
            $receive_emails   = isset($_POST['receive_emails']) ? 'yes' : 'no';

            update_user_meta($user_id, 'receive_messages', $receive_messages);
            update_user_meta($user_id, 'receive_emails', $receive_emails);

            error_log("[Profile Update] Updated communication prefs for user_id={$user_id}: SMS={$receive_messages}, EMAIL={$receive_emails}");

        } catch (Throwable $e) {
            error_log("[Profile Update] Exception while updating metadata for user_id={$user_id}: " . $e->getMessage());
        }
    }

    /**
     * Handle profile picture upload and update.
     *
     * Safely processes file uploads, stores URL in user meta,
     * and links attachment metadata if applicable.
     *
     * @param int   $user_id
     * @param array &$errors
     * @param array &$success_messages
     *
     * @return void
     */
    private static function handle_avatar_upload(int $user_id, array &$errors, array &$success_messages): void
    {
        error_log("[Profile Update] Checking avatar upload for user_id={$user_id}");

        if (empty($_FILES['profile_picture']['name'])) {
            error_log("[Profile Update] No avatar uploaded for user_id={$user_id}");
            return;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $upload = wp_handle_upload($_FILES['profile_picture'], ['test_form' => false]);

            if ($upload && !isset($upload['error'])) {
                $upload_url = esc_url($upload['url']);
                update_user_meta($user_id, 'profile_picture', $upload_url);

                error_log("[Profile Update] Avatar uploaded successfully for user_id={$user_id}: {$upload_url}");

                // Link attachment metadata if available
                $attachment_id = attachment_url_to_postid($upload_url);
                if ($attachment_id) {
                    $meta_data = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
                    update_user_meta($user_id, '_wp_attachment_metadata', $meta_data);
                    error_log("[Profile Update] Linked attachment metadata (ID={$attachment_id}) for user_id={$user_id}");
                }

                $success_messages[] = __('Profile picture updated successfully.', 'vms-plugin');
            } else {
                $error_message = $upload['error'] ?? 'Unknown error';
                error_log("[Profile Update] ERROR uploading avatar for user_id={$user_id}: {$error_message}");
                $errors[] = __('Error uploading profile picture: ', 'vms-plugin') . $error_message;
            }

        } catch (Throwable $e) {
            error_log("[Profile Update] Exception during avatar upload for user_id={$user_id}: " . $e->getMessage());
            error_log("[Profile Update] Stack trace: " . $e->getTraceAsString());
            $errors[] = __('An unexpected error occurred while uploading profile picture.', 'vms-plugin');
        }
    }

    /**
     * Send AJAX response after profile update
     *
     * @param array $errors            Array of collected error messages.
     * @param array $success_messages  Array of success messages.
     * @param int   $user_id           Current user ID.
     *
     * @return void
     */
    private static function send_ajax_response(array $errors, array $success_messages, int $user_id): void
    {
        try {
            // --- Log function entry ---
            error_log("[VMS] send_ajax_response() triggered for user ID: {$user_id}");

            // --- 1. If errors exist, immediately send error response ---
            if (!empty($errors)) {
                error_log("[VMS] Profile update failed for user ID {$user_id}. Errors: " . implode('; ', $errors));
                wp_send_json_error(['messages' => $errors]);
            }

            // --- 2. Get user data and validate ---
            $user_data = get_userdata($user_id);

            if (!$user_data) {
                // If user data not found, log and return error
                error_log("[VMS ERROR] Could not retrieve user data for ID: {$user_id}");
                wp_send_json_error(['messages' => ['Failed to retrieve updated user data. Please try again.']]);
            }

            // --- 3. Prepare sanitized response data ---
            $response_data = array(
                'avatar'           => esc_url(get_avatar_url($user_id)), // Always sanitize URLs
                'display_name'     => esc_html($user_data->display_name),
                'first_name'       => esc_html($user_data->first_name),
                'last_name'        => esc_html($user_data->last_name),
                'email'            => sanitize_email($user_data->user_email),
                'phone_number'     => esc_html(get_user_meta($user_id, 'phone_number', true)),
                'description'      => esc_html(get_user_meta($user_id, 'description', true)),
                'receive_messages' => esc_html(get_user_meta($user_id, 'receive_messages', true)),
                'receive_emails'   => esc_html(get_user_meta($user_id, 'receive_emails', true)),
                'role'             => !empty($user_data->roles) ? esc_html($user_data->roles[0]) : 'guest',
            );

            // --- 4. Log success state before sending ---
            error_log("[VMS] Profile update successful for user ID {$user_id}. Sending JSON response.");

            // --- 5. Send JSON success response ---
            wp_send_json_success([
                'messages' => $success_messages,
                'userData' => $response_data,
            ]);

        } catch (Throwable $e) {
            // --- Catch any runtime errors and log for debugging ---
            error_log("[VMS ERROR] Exception in send_ajax_response for user {$user_id}: " . $e->getMessage());

            // --- Return safe JSON error response ---
            wp_send_json_error(['messages' => ['An unexpected error occurred while processing your request. Please try again.']]);
        }
    }

    
    /**
     * Handle password change via AJAX
     */
    public static function handle_change_user_password() 
    {
        try {
            // Verify nonce for security
            if (!wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed. Please refresh the page and try again.'
                ));
                return;
            }

            // Check if user is logged in
            if (!is_user_logged_in()) {
                wp_send_json_error(array(
                    'message' => 'You must be logged in to change your password.'
                ));
                return;
            }

            // Get current user
            $current_user = wp_get_current_user();
            $user_id = $current_user->ID;

            // Sanitize input data
            $current_password = sanitize_text_field($_POST['current_password']);
            $new_password = sanitize_text_field($_POST['new_password']);
            $confirm_password = sanitize_text_field($_POST['confirm_password']);

            // Validate input
            $validation_result = self::validate_password_change_data($current_password, $new_password, $confirm_password, $current_user);
            
            if (is_wp_error($validation_result)) {
                wp_send_json_error(array(
                    'message' => $validation_result->get_error_message()
                ));
                return;
            }

            // Change the password
            $change_result = self::change_user_password($user_id, $new_password);
            
            if (is_wp_error($change_result)) {
                wp_send_json_error(array(
                    'message' => $change_result->get_error_message()
                ));
                return;
            }

            // Log the password change
            error_log(sprintf('Password changed for user ID: %d, Email: %s', $user_id, $current_user->user_email));

            // Send success response
            wp_send_json_success(array(
                'message' => 'Your password has been changed successfully.'
            ));

        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An unexpected error occurred. Please try again.'
            ));
        }
    }

    /**
     * Validate password change data
     */
    private static function validate_password_change_data($current_password, $new_password, $confirm_password, $user) 
    {
        // Check if all fields are provided
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            return new WP_Error('missing_fields', 'All password fields are required.');
        }

        // Verify current password
        if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
            return new WP_Error('incorrect_password', 'Current password is incorrect.');
        }

        // Check if new passwords match
        if ($new_password !== $confirm_password) {
            return new WP_Error('password_mismatch', 'New passwords do not match.');
        }

        // Check password length
        if (strlen($new_password) < 8) {
            return new WP_Error('password_too_short', 'Password must be at least 8 characters long.');
        }

        // Check password strength
        $strength_check = self::check_password_strength($new_password);
        if (is_wp_error($strength_check)) {
            return $strength_check;
        }

        // Check if new password is different from current
        if (wp_check_password($new_password, $user->user_pass, $user->ID)) {
            return new WP_Error('same_password', 'New password must be different from your current password.');
        }

        return true;
    }

    /**
     * Check password strength
     */
    private static function check_password_strength($password) 
    {
        $errors = array();

        // Check for uppercase letter
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'at least one uppercase letter';
        }

        // Check for lowercase letter
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'at least one lowercase letter';
        }

        // Check for number
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'at least one number';
        }

        // Check for special character
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'at least one special character (!@#$%^&*(),.?":{}|<>)';
        }

        // Check for common weak passwords
        $weak_passwords = array('password', '123456', 'qwerty', 'abc123', 'password123');
        if (in_array(strtolower($password), $weak_passwords)) {
            return new WP_Error('weak_password', 'This password is too common. Please choose a stronger password.');
        }

        if (!empty($errors)) {
            $message = 'Password must contain: ' . implode(', ', $errors) . '.';
            return new WP_Error('password_requirements', $message);
        }

        return true;
    }

    /**
     * Change user password safely
     */
    private static function change_user_password($user_id, $new_password) 
    {
        try {
            // Update user password
            $update_result = wp_update_user(array(
                'ID' => $user_id,
                'user_pass' => $new_password
            ));

            if (is_wp_error($update_result)) {
                return new WP_Error('update_failed', 'Failed to update password in database.');
            }

            // Clear user cache
            clean_user_cache($user_id);

            // Update user meta to track password change
            update_user_meta($user_id, 'last_password_change', current_time('mysql'));

            // Send email notification (optional)
            self::send_password_change_notification($user_id);

            return true;

        } catch (Exception $e) {
            error_log('Password update error: ' . $e->getMessage());
            return new WP_Error('update_error', 'An error occurred while updating your password.');
        }
    }

    /**
     * Send password change notification email (optional)
     */
    private static function send_password_change_notification($user_id) 
    {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }

        $subject = sprintf('[%s] Password Changed', get_bloginfo('name'));
        $message = sprintf(
            "Hello %s,\n\nYour password for %s has been successfully changed.\n\nIf you did not make this change, please contact us immediately.\n\nTime: %s\nIP Address: %s\n\nBest regards,\n%s Team",
            $user->display_name,
            get_bloginfo('name'),
            current_time('mysql'),
            $_SERVER['REMOTE_ADDR'],
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Send SMS notification to user on password change
     *
     * @param int $user_id User ID
     */
    private static function send_password_change_sms($user_id)
    {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return false;
        }
        
        // Check if user has phone number and SMS enabled
        $phone = get_user_meta($user_id, 'phone_number', true);
        $receive_sms = get_user_meta($user_id, 'receive_sms', true);
        
        if (empty($phone) || $receive_sms !== 'yes') {
            return false;
        }
        
        // Debug log
        error_log("SMS Triggered: Password changed for user ID {$user_id}");
        
        // Get user role
        $user_roles = $user->roles;
        $role = !empty($user_roles) ? $user_roles[0] : 'subscriber';
        
        // Build message
        $site_name = get_bloginfo('name');
        $user_name = $user->display_name ?: $user->user_login;
        $current_time = current_time('Y-m-d H:i:s');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $message = sprintf(
            "%s: Dear %s, your password was changed successfully at %s from IP %s. Contact us if you did not make this change.",
            $site_name,
            $user_name,
            $current_time,
            $ip_address
        );
        
        // Send SMS through notification manager
        VMS_SMS::send_sms($phone, $message, $user_id, $role);
        
        return true;
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