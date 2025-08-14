<?php
/**
 * Handles all Cyber Wakili notification-related functionality
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_NotificationManager
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * MobileSASA API base URL
     * @var string
     */
    private $api_base_url = 'https://api.mobilesasa.com/v1';

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
     * Initialize notification functionality
     */
    public function init(): void
    {
        $this->setup_sms_balance_cron();
        // Uncomment when ready to use task notifications
        // add_action('save_post_task', [$this, 'handle_task_notification'], 10, 3);
    }

    /**
     * Setup SMS balance cron job
     */
    private function setup_sms_balance_cron(): void
    {
        add_action('mobilesasa_sms_balance_cron', [$this, 'fetch_and_save_sms_balance']);
        add_action('init', [$this, 'schedule_sms_balance_cron']);
    }

    /**
     * Send task assignment notification
     */
    public function handle_task_notification(int $post_id, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $assignee_id = get_post_meta($post_id, '_task_assignee', true);
        if (!$assignee_id) {
            return;
        }

        $assignee = get_userdata($assignee_id);
        if (!$assignee) {
            return;
        }

        $subject = sprintf(
            __('New Task Assigned: %s', 'vms'),
            get_the_title($post_id)
        );

        $message = sprintf(
            __("Dear %s,\n\nYou have been assigned a new task: %s.\n\nDue Date: %s\n\nView it here: %s\n\nBest regards,\nCyber Wakili", 'vms'),
            $assignee->display_name,
            get_the_title($post_id),
            get_post_meta($post_id, '_task_due_date', true),
            get_permalink($post_id)
        );

        wp_mail($assignee->user_email, $subject, $message);
    }

    /**
     * Send SMS message
     */
    public function send_sms(string $phone, string $message): ?array
    {
        global $wpdb;

        $sender_id = get_option('mobilesasa_sender_id', '');
        $api_token = get_option('mobilesasa_api_token', '');

        $clean_phone = $this->clean_phone_number($phone);
        $endpoint = $this->api_base_url . '/send/message';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'senderID' => $sender_id,
                'message' => $message,
                'phone' => $clean_phone,
                'api_token' => $api_token,
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('SMS API Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['status'])) {
            error_log('Invalid SMS API response: ' . $body);
            return null;
        }

        $status = $data['status'] ? 'Sent' : 'Failed';
        $user_id = $this->get_user_id_by_phone($phone);

        $this->log_sms_message(
            $user_id,
            $phone,
            $message,
            $data['messageId'] ?? '',
            $status
        );

        return [
            'status' => $data['status'],
            'responseCode' => $data['responseCode'] ?? '',
            'message' => $data['message'] ?? '',
            'messageId' => $data['messageId'] ?? ''
        ];
    }

    /**
     * Clean and format phone number
     */
    public function clean_phone_number(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($digits, '0') === 0) {
            return '254' . substr($digits, 1);
        }
        if (strpos($digits, '254') !== 0) {
            return '254' . substr($digits, -9);
        }
        return $digits;
    }

    /**
     * Get user ID by phone number
     */
    private function get_user_id_by_phone(string $phone): ?int
    {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'phone_number' AND meta_value = %s LIMIT 1",
                $phone
            )
        );
    }

    /**
     * Log SMS message to database
     */
    private function log_sms_message(?int $user_id, string $phone, string $message, string $message_id, string $status): bool
    {
        global $wpdb;
        return (bool) $wpdb->insert(
            $wpdb->prefix . 'mobilesasa_messages',
            [
                'user_id' => $user_id,
                'phone_number' => $phone,
                'message' => $message,
                'message_id' => $message_id,
                'sent_at' => current_time('mysql'),
                'status' => $status
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Fetch and save SMS balance
     */
    public function fetch_and_save_sms_balance(): void
    {
        $api_token = get_option('mobilesasa_api_token', '');
        if (empty($api_token)) {
            error_log('MobileSASA API token not configured');
            return;
        }

        $response = wp_remote_get($this->api_base_url . '/get-balance', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Accept' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('Error fetching SMS balance: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['status']) && $data['status'] === true) {
            update_option('mobilesasa_sms_balance', $data['balance']);
            update_option('mobilesasa_last_check', current_time('mysql'));
        } else {
            error_log('Failed to retrieve SMS balance. Response: ' . $body);
        }
    }

    /**
     * Schedule SMS balance cron job
     */
    public function schedule_sms_balance_cron(): void
    {
        if (!wp_next_scheduled('mobilesasa_sms_balance_cron')) {
            wp_schedule_event(time(), 'hourly', 'mobilesasa_sms_balance_cron');
        }
    }

    /**
     * Clear SMS balance cron job
     */
    public function clear_sms_balance_cron(): void
    {
        $timestamp = wp_next_scheduled('mobilesasa_sms_balance_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mobilesasa_sms_balance_cron');
        }
    }
}