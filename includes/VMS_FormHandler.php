<?php
/**
 * Handles all VMS form processing and submissions
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
        // add_action('template_redirect', [$this, 'handle_settings_form']);
        // add_action('wp_ajax_update_user_profile', [$this, 'handle_profile_update']);
        // add_action('wp_ajax_nopriv_update_user_profile', [$this, 'handle_profile_update']);

    }

    /**
     * Handle settings form submission
     */


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
    
}