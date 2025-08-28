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
    private $api_base_url = 'https://api.smsleopard.com/v1';

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
        $this->setup_hooks();
    }

    /**
     * Setup hooks
     */
    private function setup_hooks(): void
    {
        add_action('wp_ajax_refresh_sms_balance', [$this, 'refresh_sms_balance']);
        add_action('wp_ajax_test_sms_connection', [$this, 'test_sms_connection']);
        add_action('sms_balance_cron', [$this, 'fetch_and_save_sms_balance']);
        add_action('wp_ajax_nopriv_vms_status_callback', [$this, 'handle_status_callback']);
        add_action('wp_ajax_vms_status_callback', [$this, 'handle_status_callback']);
    }

    /**
     * Send SMS message using SMS Leopard API
     */
    public function send_sms(string $phone, string $message, ?int $user_id = null): ?array
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        $sender_id = get_option('vms_sms_sender_id', 'SMS_Leopard');
        $status_url = get_option('vms_status_url', '');
        $status_secret = get_option('vms_status_secret', '');

        if (empty($api_key) || empty($api_secret)) {
            $this->log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, 'API credentials not configured');
            return null;
        }

        $clean_phone = $this->clean_phone_number($phone);
        $auth = base64_encode($api_key . ':' . $api_secret);

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

        $response = wp_remote_post($this->api_base_url . '/sms/send', [
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
            $this->log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, $error_message);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            $error_message = 'Invalid API response: ' . $body;
            error_log($error_message);
            $this->log_sms_message($user_id, $phone, $message, null, 'failed', 0, null, $error_message);
            return null;
        }

        // Process response
        if (isset($data['success']) && $data['success'] === true) {
            $recipient = $data['recipients'][0] ?? [];
            $message_id = $recipient['id'] ?? '';
            $cost = $recipient['cost'] ?? 0;
            $status = $recipient['status'] ?? 'sent';

            $this->log_sms_message($user_id, $phone, $message, $message_id, $status, $cost, $data);
            
            return [
                'success' => true,
                'message_id' => $message_id,
                'cost' => $cost,
                'status' => $status,
                'response' => $data['message'] ?? 'SMS sent successfully'
            ];
        } else {
            $error_message = $data['message'] ?? 'Unknown error occurred';
            $this->log_sms_message($user_id, $phone, $message, null, 'failed', 0, $data, $error_message);
            
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
    public function send_bulk_sms(array $recipients, string $message): array
    {
        $results = [];
        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'] ?? $recipient;
            $user_id = $recipient['user_id'] ?? null;
            
            $result = $this->send_sms($phone, $message, $user_id);
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
    public function clean_phone_number(string $phone): string
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
    private function log_sms_message(
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
    public function get_delivery_report(string $message_id): ?array
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');

        if (empty($api_key) || empty($api_secret) || empty($message_id)) {
            return null;
        }

        $auth = base64_encode($api_key . ':' . $api_secret);

        $response = wp_remote_get($this->api_base_url . '/delivery_reports/' . $message_id, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('Delivery report error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($data && isset($data['status'])) {
            // Update local log with delivery status
            $this->update_sms_status($message_id, $data['status']);
            return $data;
        }

        return null;
    }

    /**
     * Update SMS status in logs
     */
    private function update_sms_status(string $message_id, string $status): bool
    {
        global $wpdb;
        
        return (bool) $wpdb->update(
            $wpdb->prefix . 'vms_sms_logs',
            ['status' => $status],
            ['message_id' => $message_id],
            ['%s'],
            ['%s']
        );
    }

    /**
     * Fetch and save SMS balance
     */
    public function fetch_and_save_sms_balance(): void
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            error_log('SMS API credentials not configured');
            return;
        }

        $auth = base64_encode($api_key . ':' . $api_secret);

        $response = wp_remote_get($this->api_base_url . '/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('Error fetching SMS balance: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['balance'])) {
            update_option('vms_sms_balance', $data['balance']);
            update_option('vms_sms_last_check', current_time('mysql'));
            
            // Store additional balance info
            if (isset($data['converted_balance'])) {
                update_option('vms_sms_converted_balance', $data['converted_balance']);
            }
            if (isset($data['currency'])) {
                update_option('vms_sms_currency', $data['currency']);
            }
        } else {
            error_log('Failed to retrieve SMS balance. Response: ' . $body);
        }
    }

    /**
     * AJAX handler to refresh SMS balance
     */
    public function refresh_sms_balance(): void
    {
        check_ajax_referer('refresh_balance_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $this->fetch_and_save_sms_balance();
        
        $balance = get_option('vms_sms_balance', 0);
        if ($balance > 0) {
            wp_send_json_success(['balance' => $balance]);
        } else {
            wp_send_json_error('Failed to fetch balance');
        }
    }

    /**
     * Test SMS connection
     */
    public function test_sms_connection(): void
    {
        check_ajax_referer('test_connection_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
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
                'Content-Type' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['balance'])) {
            update_option('vms_sms_balance', $data['balance']);
            update_option('vms_sms_last_check', current_time('mysql'));
            wp_send_json_success('Connection successful! Balance: KES ' . number_format($data['balance'], 2));
        } else {
            wp_send_json_error('Invalid API response');
        }
    }

    /**
     * Get SMS statistics
     */
    public function get_sms_statistics(int $days = 30): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vms_sms_logs';
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_sms,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_sms,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_sms,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_sms,
                SUM(cost) as total_cost
            FROM {$table_name} 
            WHERE created_at >= %s",
            $date_from
        ), ARRAY_A);

        return [
            'total_sms' => (int) ($stats['total_sms'] ?? 0),
            'sent_sms' => (int) ($stats['sent_sms'] ?? 0),
            'failed_sms' => (int) ($stats['failed_sms'] ?? 0),
            'delivered_sms' => (int) ($stats['delivered_sms'] ?? 0),
            'total_cost' => (float) ($stats['total_cost'] ?? 0),
            'success_rate' => $stats['total_sms'] > 0 ? 
                round(($stats['sent_sms'] / $stats['total_sms']) * 100, 2) : 0,
            'period_days' => $days
        ];
    }

    /**
     * Send test SMS
     */
    public function send_test_sms(string $phone): ?array
    {
        $message = 'Test message from VMS. Your SMS integration is working correctly!';
        return $this->send_sms($phone, $message);
    }

    /**
     * Validate phone number format
     */
    public function validate_phone_number(string $phone): bool
    {
        $clean_phone = $this->clean_phone_number($phone);
        
        // Check if it's a valid Kenyan number (254 + 9 digits)
        return preg_match('/^254[17]\d{8}$/', $clean_phone) === 1;
    }

    /**
     * Get recent SMS logs for dashboard
     */
    public function get_recent_sms_logs(int $limit = 10): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vms_sms_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            ORDER BY created_at DESC 
            LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Handle status callback from SMS Leopard
     */
    public function handle_status_callback(): void
    {
        $status_secret = get_option('vms_status_secret', '');
        
        if (!empty($status_secret)) {
            $received_secret = $_POST['status_secret'] ?? '';
            if ($received_secret !== $status_secret) {
                wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
            }
        }

        $message_id = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? '';
        
        if (!empty($message_id) && !empty($status)) {
            $this->update_sms_status($message_id, strtolower($status));
        }
        
        // Respond with 200 OK
        http_response_code(200);
        echo 'OK';
        exit;
    }

    /**
     * Clean up old SMS logs
     */
    public function cleanup_old_logs(int $days = 90): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vms_sms_logs';
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $date_threshold
        ));
    }

    /**
     * Send visitor notification SMS
     */
    public function send_visitor_notification(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $host = $visitor_data['host_name'] ?? '';
        $date = $visitor_data['visit_date'] ?? date('Y-m-d');
        $time = $visitor_data['visit_time'] ?? date('H:i');
        
        if (empty($phone)) {
            return null;
        }

        $message = "Hi {$name}, your visit to see {$host} on {$date} at {$time} has been scheduled. Please arrive on time. Thank you.";
        
        return $this->send_sms($phone, $message, $visitor_data['user_id'] ?? null);
    }

    /**
     * Send host notification SMS
     */
    public function send_host_notification(array $host_data, array $visitor_data): ?array
    {
        $host_phone = $host_data['phone'] ?? '';
        $visitor_name = $visitor_data['name'] ?? '';
        $visitor_phone = $visitor_data['phone'] ?? '';
        $purpose = $visitor_data['purpose'] ?? 'Meeting';
        
        if (empty($host_phone)) {
            return null;
        }

        $message = "Your visitor {$visitor_name} has arrived. Phone: {$visitor_phone}. Purpose: {$purpose}. Please come to reception.";
        
        return $this->send_sms($host_phone, $message, $host_data['user_id'] ?? null);
    }

    /**
     * Send check-in confirmation SMS
     */
    public function send_checkin_confirmation(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $badge_number = $visitor_data['badge_number'] ?? '';
        $time = $visitor_data['checkin_time'] ?? date('H:i');
        
        if (empty($phone)) {
            return null;
        }

        $message = "Welcome {$name}! You have successfully checked in at {$time}.";
        if (!empty($badge_number)) {
            $message .= " Your badge number is {$badge_number}.";
        }
        $message .= " Enjoy your visit!";
        
        return $this->send_sms($phone, $message, $visitor_data['user_id'] ?? null);
    }

    /**
     * Send check-out confirmation SMS
     */
    public function send_checkout_confirmation(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $checkout_time = $visitor_data['checkout_time'] ?? date('H:i');
        
        if (empty($phone)) {
            return null;
        }

        $message = "Thank you for your visit {$name}! You have successfully checked out at {$checkout_time}. Have a great day!";
        
        return $this->send_sms($phone, $message, $visitor_data['user_id'] ?? null);
    }

    /**
     * Get SMS balance information
     */
    public function get_balance_info(): array
    {
        return [
            'balance' => get_option('vms_sms_balance', 0),
            'converted_balance' => get_option('vms_sms_converted_balance', 0),
            'currency' => get_option('vms_sms_currency', 'USD'),
            'last_check' => get_option('vms_sms_last_check', ''),
            'formatted_balance' => 'KES ' . number_format(get_option('vms_sms_balance', 0), 2)
        ];
    }

    /**
     * Check if SMS service is configured
     */
    public function is_configured(): bool
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        return !empty($api_key) && !empty($api_secret);
    }

    /**
     * Get SMS quota status
     */
    public function get_quota_status(): array
    {
        $balance = get_option('vms_sms_balance', 0);
        $low_balance_threshold = 50; // KES
        
        return [
            'balance' => $balance,
            'is_low' => $balance < $low_balance_threshold,
            'is_empty' => $balance <= 0,
            'status' => $balance <= 0 ? 'empty' : ($balance < $low_balance_threshold ? 'low' : 'good')
        ];
    }
}