<?php
/**
 * Handles all VMS notification-related functionality
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
     * SMS Leopard API base URL
     * @var string
     */
    private static $api_base_url = 'https://api.smsleopard.com/v1';

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
        self::setup_hooks();
        // Uncomment when ready to use task notifications
        // add_action('save_post_task', [$this, 'handle_task_notification'], 10, 3);
    }

    /**
     * Setup hooks
     */
    private static function setup_hooks(): void
    {
        add_action('wp_ajax_refresh_sms_balance', [self::class, 'refresh_sms_balance']);
        add_action('schedule_sms_balance_cron', [self::class, 'refresh_sms_balance']);
    }


    /**
     * Send SMS message using SMS Leopard API
     */
    public static function send_sms(string $phone, string $message, ?int $user_id = null): ?array
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        $sender_id = get_option('vms_sms_sender_id', 'SMS_Leopard');
        $status_url = get_option('vms_status_url', '');
        $status_secret = get_option('vms_status_secret', '');

        if (empty($api_key) || empty($api_secret)) {
            self::log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, 'API credentials not configured');
            return null;
        }

        $clean_phone = self::clean_phone_number($phone);
        $auth = base64_encode($api_key . ':' . $api_secret);
        $clean_phone = self::clean_phone_number($phone);

        error_log('Sending SMS to: ' . $clean_phone);
        error_log('Message: ' . $message);
        error_log('Sender ID: ' . $sender_id);
        error_log('Status URL: ' . $status_url);
        error_log('Status Secret: ' . $status_secret);


        $payload = [
            'source' => $sender_id,
            'message' => $message,
            'destination' => [
                ['number' => $clean_phone]
            ]
        ];

        // Add optional status callback
        if (!empty($status_url)) {
            $payload['status_url'] = $status_url;
            if (!empty($status_secret)) {
                $payload['status_secret'] = $status_secret;
            }
        }

        $response = wp_remote_post(self::$api_base_url . '/sms/send', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('SMS API Error: ' . $error_message);
            self::log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, $error_message);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            $error_message = 'Invalid API response: ' . $body;
            error_log($error_message);
            self::log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, $error_message);
            return null;
        }

        // Process response
        if (isset($data['success']) && $data['success'] === true) {
            $recipient = $data['recipients'][0] ?? [];
            $message_id = $recipient['id'] ?? '';
            $cost = $recipient['cost'] ?? 0;
            $status = $recipient['status'] ?? 'sent';

            self::log_sms_message($user_id, $phone, $message, $message_id, $status, $cost, $data);
            
            return [
                'success' => true,
                'message_id' => $message_id,
                'cost' => $cost,
                'status' => $status,
                'response' => $data['message'] ?? 'SMS sent successfully'
            ];
        } else {
            $error_message = $data['message'] ?? 'Unknown error occurred';
            self::log_sms_message($user_id, $phone, $message, null, 'failed', 0, $data, $error_message);
            
            return [
                'success' => false,
                'error' => $error_message,
                'response_code' => $data['responseCode'] ?? 'UNKNOWN'
            ];
        }
    }

    /**
     * Send SMS to multiple recipients
     */
    public static function send_bulk_sms(array $recipients, string $message): array
    {
        $results = [];
        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? $recipient;
            $user_id = $recipient['user_id'] ?? null;
            
            $result = self::send_sms($phone, $message, $user_id);
            $results[] = [
                'phone' => $phone,
                'result' => $result
            ];
            
            // Small delay to prevent rate limiting
            usleep(100000); // 0.1 seconds
        }
        
        return $results;
    }

    /**
     * Clean and format phone number for Kenyan numbers
     */
    public static function clean_phone_number(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Handle Kenyan numbers
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }
        
        if (strpos($digits, '254') === 0) {
            return $digits;
        }
        
        // Default: assume Kenyan number without prefix
        if (strlen($digits) === 9) {
            return '254' . $digits;
        }
        
        return $digits;
    }

    /**
     * Log SMS message to database
     */
    private static function log_sms_message(
        ?int $user_id, 
        string $phone, 
        string $message, 
        ?string $message_id, 
        string $status, 
        float $cost = 0, 
        ?array $response_data = null, 
        ?string $error_message = null
    ): bool {
        global $wpdb;
        
        return (bool) $wpdb->insert(
            $wpdb->prefix . 'vms_sms_logs',
            [
                'user_id' => $user_id,
                'recipient_number' => $phone,
                'message' => $message,
                'message_id' => $message_id,
                'status' => $status,
                'cost' => $cost,
                'response_data' => $response_data ? json_encode($response_data) : null,
                'error_message' => $error_message
            ],
            ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s']
        );
    }

    /**
     * Get delivery report for a message
     */
    public static function get_delivery_report(string $message_id): ?array
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');

        if (empty($api_key) || empty($api_secret) || empty($message_id)) {
            return null;
        }

        $auth = base64_encode($api_key . ':' . $api_secret);

        $response = wp_remote_get(self::$api_base_url . '/sms/' . urlencode($message_id) . '/status', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('Delivery Report Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            error_log('Invalid delivery report response: ' . $body);
            return null;
        }

        return $data;
    }

    /**
     * Get SMS Leopard account balance
     */
    public static function refresh_sms_balance(): void
    {
        check_ajax_referer('refresh_balance_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');

        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error('API credentials not configured');
        }

        $auth = base64_encode($api_key . ':' . $api_secret);

        $response = wp_remote_get('https://api.smsleopard.com/v1/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['balance'])) {
            wp_send_json_error('Invalid API response');
        }

        // Save latest balance & timestamp in options
        update_option('vms_sms_balance', $data['balance']);
        update_option('vms_sms_last_check', current_time('mysql'));

        wp_send_json_success([
            'balance'     => $data['balance'],
            'currency'    => $data['currency'] ?? 'KES',
            'last_check'  => current_time('mysql')
        ]);
    }
}