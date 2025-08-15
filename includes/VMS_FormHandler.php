<?php
/**
 * Handles all Cyber Wakili form processing and submissions
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

use WP_Error;
use wpdb;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_FormHandler
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
     * Initialize form handling functionality
     */
    public function init(): void
    {
        $this->start_session();
        $this->register_form_handlers();
    }

    /**
     * Start session if not already started
     */
    private function start_session(): void
    {
        if (!session_id()) {
            session_start();
        }
    }

    /**
     * Register all form handlers
     */
    private function register_form_handlers(): void
    {
        add_action('template_redirect', [$this, 'handle_settings_form']);
        add_action('wp_ajax_update_user_profile', [$this, 'handle_profile_update']);
        add_action('wp_ajax_nopriv_update_user_profile', [$this, 'handle_profile_update']);

    }

    /**
     * Handle settings form submission
     */
    public function handle_settings_form(): void
    {
        if (!is_page('settings') || !isset($_POST['update_details'])) {
            return;
        }

        check_admin_referer('update_account_data', '_wpnonce_update_account_data');

        $success_messages = [];
        $sender_id = sanitize_text_field($_POST['sender_id'] ?? '');
        $api_token = sanitize_text_field($_POST['api_token'] ?? '');

        if ($sender_id !== get_option('mobilesasa_sender_id')) {
            update_option('mobilesasa_sender_id', $sender_id);
            $success_messages[] = __('Sender ID updated successfully.', 'vms');
        }

        if ($api_token !== get_option('mobilesasa_api_token')) {
            update_option('mobilesasa_api_token', $api_token);
            $success_messages[] = __('API Token updated successfully.', 'vms');
        }

        if (!empty($success_messages)) {
            $_SESSION['settings_success'] = $success_messages;
        }

        wp_redirect(site_url('/settings/'));
        exit;
    }

    /**
     * Process SMS submission
     */
    private function process_sms_submission(wpdb $wpdb): void
    {
        check_admin_referer('send_message', '_wpnonce_send_message');

        $errors = [];
        $success_messages = [];
        $phone_number = sanitize_text_field($_POST['phone_number'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        $user_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'phone_number' AND meta_value = %s LIMIT 1",
                $phone_number
            )
        );

        if (!$user_id) {
            $errors[] = sprintf(__('No user found with this phone number: %s.', 'vms'), $phone_number);
        }

        if (empty($errors)) {
            $sms_sent = send_sms($phone_number, $message);

            if (is_array($sms_sent) && isset($sms_sent['status'], $sms_sent['responseCode'])) {
                if ($sms_sent['status'] === true) {
                    $success_messages[] = __('Message successfully sent!', 'vms');
                } else {
                    $errors[] = __('SMS Failed: ', 'vms') . ($sms_sent['message'] ?? '');
                    if ($sms_sent['responseCode'] === '0422') {
                        $errors[] = __('The Sender ID is Invalid!', 'vms');
                    }
                }
            }
        }

        $this->store_messages_and_redirect($errors, $success_messages, '/messages/');
    }

    /**
     * Process message deletion
     */
    private function process_message_deletion(wpdb $wpdb): void
    {
        if (!isset($_POST['_wpnonce_delete_message']) || !wp_verify_nonce($_POST['_wpnonce_delete_message'], 'delete_message')) {
            wp_die(__('Nonce verification failed.', 'vms'));
        }

        $errors = [];
        $success_messages = [];
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_die(__('Invalid message ID.', 'vms'));
        }

        $table_name = $wpdb->prefix . 'mobilesasa_messages';
        $message = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if (!$message) {
            $errors[] = __('Message not found or already deleted.', 'vms');
        } else {
            $wpdb->delete($table_name, ['id' => $id]);
            $success_messages[] = __('Message successfully deleted!', 'vms');
        }

        $this->store_messages_and_redirect($errors, $success_messages, '/messages/');
    }

    /**
     * Handle profile update via AJAX
     */
    public function handle_profile_update(): void
    {
        $this->verify_ajax_request();

        $user_id = get_current_user_id();
        $errors = [];
        $success_messages = [];

        $this->update_basic_profile($user_id, $errors, $success_messages);
        $this->update_metadata($user_id);
        $this->handle_avatar_upload($user_id, $errors, $success_messages);

        $this->send_ajax_response($errors, $success_messages, $user_id);
    }

    /**
     * Verify AJAX request
     */
    private function verify_ajax_request(): void
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'vms')]);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to update your profile.', 'vms')]);
        }
    }

    /**
     * Update basic profile information
     */
    private function update_basic_profile(int $user_id, array &$errors, array &$success_messages): void
    {
        $user_data = [
            'ID' => $user_id,
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
            'user_email' => sanitize_email($_POST['email'] ?? ''),
        ];

        $result = wp_update_user($user_data);
        
        if (is_wp_error($result)) {
            $errors[] = $result->get_error_message();
        } else {
            $success_messages[] = __('Profile updated successfully.', 'vms');
        }
    }

    /**
     * Update user metadata
     */
    private function update_metadata(int $user_id): void
    {
        $meta_fields = [
            'phone_number' => 'text',
            'description' => 'textarea'
        ];


        foreach ($meta_fields as $field => $type) {
            if (isset($_POST[$field])) {
                $value = $type === 'textarea' 
                    ? sanitize_textarea_field($_POST[$field])
                    : sanitize_text_field($_POST[$field]);
                update_user_meta($user_id, $field, $value);
            }
        }

        // Handle checkboxes
        update_user_meta($user_id, 'receive_messages', isset($_POST['receive_messages']) ? 'yes' : 'no');
        update_user_meta($user_id, 'receive_emails', isset($_POST['receive_emails']) ? 'yes' : 'no');
    }

    /**
     * Handle avatar upload
     */
    private function handle_avatar_upload(int $user_id, array &$errors, array &$success_messages): void
    {
        if (empty($_FILES['profile_picture']['name'])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload($_FILES['profile_picture'], ['test_form' => false]);

        if ($upload && !isset($upload['error'])) {
            update_user_meta($user_id, 'profile_picture', esc_url($upload['url']));
            $attachment_id = attachment_url_to_postid($upload['url']);
            if ($attachment_id) {
                update_user_meta($user_id, '_wp_attachment_metadata', get_post_meta($attachment_id, '_wp_attachment_metadata', true));
            }
            $success_messages[] = __('Profile picture updated successfully.', 'vms');
        } else {
            $errors[] = __('Error uploading profile picture: ', 'vms') . ($upload['error'] ?? '');
        }
    }

    /**
     * Store messages in session and redirect
     */
    private function store_messages_and_redirect(array $errors, array $success_messages, string $redirect_url): void
    {
        if (!empty($errors)) {
            $_SESSION['messages_error'] = $errors;
        }
        if (!empty($success_messages)) {
            $_SESSION['messages_success'] = $success_messages;
        }
        wp_redirect(site_url($redirect_url));
        exit;
    }

    /**
     * Send AJAX response
     */
    private function send_ajax_response(array $errors, array $success_messages, int $user_id): void
    {
        if (!empty($errors)) {
            wp_send_json_error(['messages' => $errors]);
        }

        $user_data = get_userdata($user_id); // Make sure this is declared before using it
        wp_send_json_success(array(
            'messages' => $success_messages,
            'userData'  => array(
                'avatar'           => get_avatar_url($user_id),
                'display_name'     => $user_data->display_name,
                'first_name'       => $user_data->first_name,
                'last_name'        => $user_data->last_name,
                'email'            => $user_data->user_email,
                'phone_number'     => get_user_meta($user_id, 'phone_number', true),
                'description'      => get_user_meta($user_id, 'description', true),
                'receive_messages' => get_user_meta($user_id, 'receive_messages', true),
                'receive_emails'   => get_user_meta($user_id, 'receive_emails', true),
                'role'             => !empty($user_data->roles) ? $user_data->roles[0] : 'guest',
            )
        ));
    }      
    
}