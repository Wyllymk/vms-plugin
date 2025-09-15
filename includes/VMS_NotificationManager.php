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
    }

    /**
     * Setup hooks
     */
    private static function setup_hooks(): void
    {
        add_action('wp_ajax_refresh_sms_balance', [self::class, 'refresh_sms_balance']);
        add_action('wp_ajax_test_sms_connection', [self::class, 'test_sms_connection']);
        add_action('sms_balance_cron', [self::class, 'fetch_and_save_sms_balance']);
        add_action('wp_ajax_nopriv_vms_status_callback', [self::class, 'handle_status_callback']);
        add_action('wp_ajax_vms_status_callback', [self::class, 'handle_status_callback']);
        
        // NEW: Add SMS delivery status check and cleanup
        add_action('check_sms_delivery_status', [self::class, 'check_pending_sms_delivery']);
        add_action('cleanup_old_sms_logs', [self::class, 'cleanup_old_logs']);
    }

    /**
     * Send SMS message - UPDATED to include callback URL automatically
     */
    public static function send_sms(string $phone, string $message, ?int $user_id = null, ?string $recipient_role = null): ?array
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        $sender_id = get_option('vms_sms_sender_id', 'SMS_Leopard');
    
        // Always use our callback URL for status updates
        $callback_url = self::get_callback_url();
        $status_secret = get_option('vms_status_secret', '');
                
        if (empty($api_key) || empty($api_secret)) {
            self::log_sms_message($user_id, $phone, $message, $recipient_role, null, 'failed', 0, null, 'API credentials not configured');
            return null;
        }
        $clean_phone = self::clean_phone_number($phone);
        $auth = base64_encode($api_key . ':' . $api_secret);
        $payload = [
            'source' => $sender_id,
            'message' => $message,
            'destination' => [
                ['number' => $clean_phone]
            ]
        ];
        // Always add callback URL for status updates
        if (!empty($callback_url)) {
            $payload['status_url'] = $callback_url;
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
            self::log_sms_message($user_id, $phone, $message, $recipient_role, null, 'failed', 0, null, $error_message);
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            $error_message = 'Invalid API response: ' . $body;
            error_log($error_message);
            self::log_sms_message($user_id, $phone, $message, $recipient_role, null, 'failed', 0, null, $error_message);
            return null;
        }
        // Process response
        if (isset($data['success']) && $data['success'] === true) {
            $recipient = $data['recipients'][0] ?? [];
            $message_id = $recipient['id'] ?? '';
            $cost = $recipient['cost'] ?? 0;
            $status = $recipient['status'] ?? 'sent';
            self::log_sms_message($user_id, $phone, $message, $recipient_role, $message_id, $status, $cost, $data);
        
            return [
                'success' => true,
                'message_id' => $message_id,
                'cost' => $cost,
                'status' => $status,
                'response' => $data['message'] ?? 'SMS sent successfully'
            ];
        } else {
            $error_message = $data['message'] ?? 'Unknown error occurred';
            self::log_sms_message($user_id, $phone, $message, $recipient_role, null, 'failed', 0, $data, $error_message);
        
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
            
            $result = self::send_sms($phone, $message, $user_id, $role);
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
     * NEW: Send guest status change notification (suspended/banned)
     */
    public static function send_guest_status_notification(array $guest_data, string $old_status, string $new_status): ?array
    {
        $phone = $guest_data['phone_number'] ?? '';
        $name = $guest_data['first_name'] ?? '';
        $receive_messages = $guest_data['receive_messages'] ?? 'no';
        $role = 'guest';
        
        if (empty($phone) || $receive_messages !== 'yes') {
            return null;
        }

        $message = "Dear {$name}, ";
        
        switch ($new_status) {
            case 'suspended':
                if ($old_status === 'active') {
                    $message .= "your guest privileges have been temporarily suspended due to visit limit exceeded. Contact reception for assistance.";
                } else {
                    $message .= "your guest status has been updated to suspended.";
                }
                break;
                
            case 'banned':
                $message .= "your guest privileges have been permanently revoked. Please contact management for clarification.";
                break;
                
            case 'active':
                if (in_array($old_status, ['suspended', 'banned'])) {
                    $message .= "your guest privileges have been restored. You can now make new visit requests.";
                } else {
                    return null; // No need to notify for normal active status
                }
                break;
                
            default:
                return null;
        }
        
        return self::send_sms($phone, $message, $guest_data['user_id'] ?? null, $role);
    }

    /**
     * NEW: Send visit status change notification (approved/unapproved)
     */
    public static function send_visit_status_notification(array $guest_data, array $visit_data, string $old_status, string $new_status): ?array
    {
        $phone = $guest_data['phone_number'] ?? '';
        $name = $guest_data['first_name'] ?? '';
        $receive_messages = $guest_data['receive_messages'] ?? 'no';
        $visit_date = date('F j, Y', strtotime($visit_data['visit_date']));
        $role = 'guest';
        
        if (empty($phone) || $receive_messages !== 'yes' || $old_status === $new_status) {
            return null;
        }

        $message = "Nyeri Club: Dear {$name}, your visit on {$visit_date} ";
        
        switch ($new_status) {
            case 'approved':
                $message .= "has been approved. Please carry a valid ID when you arrive.";
                break;
                
            case 'unapproved':
                $message .= "is currently pending approval due to capacity limits. You will be notified once approved.";
                break;
                
            case 'cancelled':
                $message .= "has been cancelled. Please contact your host for more information.";
                break;
                
            default:
                return null;
        }
        
        return self::send_sms($phone, $message, $guest_data['user_id'] ?? null, $role);
    }

    /**
     * NEW: Send host daily limit notification
     */
    public static function send_host_limit_notification(array $host_data, string $visit_date, int $unapproved_count): ?array
    {
        $phone = $host_data['phone_number'] ?? '';
        $name = $host_data['first_name'] ?? 'Host';
        $receive_messages = get_user_meta($host_data['user_id'], 'receive_messages', true);
        $role = '';
        if (!empty($host_data['user_id'])) {
            $user_data = get_userdata($host_data['user_id']);
            if ($user_data && !empty($user_data->roles)) {
                $role = $user_data->roles[0]; // Gets the first role
            }
        } 
        
        if (empty($phone) || $receive_messages !== 'yes' || $unapproved_count <= 0) {
            return null;
        }

        $formatted_date = date('F j, Y', strtotime($visit_date));
        $message = "Dear {$name}, you have exceeded your daily guest limit (4) for {$formatted_date}. ";
        $message .= "{$unapproved_count} guest(s) are pending approval and will be notified once slots become available.";
        
        return self::send_sms($phone, $message, $host_data['user_id'], $role);
    }

    /**
     * NEW: Send sign-in confirmation SMS
     */
    public static function send_signin_notification(array $guest_data, array $visit_data): ?array
    {
        $phone = $guest_data['phone_number'] ?? '';
        $name = $guest_data['first_name'] ?? '';
        $receive_messages = $guest_data['receive_messages'] ?? 'no';
        $signin_time = date('g:i A', strtotime($visit_data['sign_in_time']));
        $role = 'guest';
        
        if (empty($phone) || $receive_messages !== 'yes') {
            return null;
        }

        $message = "Welcome {$name}! You have successfully signed in at {$signin_time}. Enjoy your visit!";
        
        return self::send_sms($phone, $message, $guest_data['user_id'] ?? null, $role);
    }

    /**
     * NEW: Send sign-out confirmation SMS
     */
    public static function send_signout_notification(array $guest_data, array $visit_data): ?array
    {
        $phone = $guest_data['phone_number'] ?? '';
        $name = $guest_data['first_name'] ?? '';
        $receive_messages = $guest_data['receive_messages'] ?? 'no';
        $signout_time = date('g:i A', strtotime($visit_data['sign_out_time']));
        $role = 'guest';
        
        if (empty($phone) || $receive_messages !== 'yes') {
            return null;
        }

        $message = "Thank you for your visit {$name}! You have successfully signed out at {$signout_time}. Have a great day!";
        
        return self::send_sms($phone, $message, $guest_data['user_id'] ?? null, $role);
    }

    /**
     * Handle SMS delivery callback from SMS Leopard - UPDATED
     */
    public static function handle_sms_delivery_callback(): void
    {
        // Log all incoming data for debugging
        error_log('SMS Callback received: ' . json_encode($_POST));
        
        $status_secret = get_option('vms_status_secret', '');
        
        // Verify secret if configured
        if (!empty($status_secret)) {
            $received_secret = $_POST['status_secret'] ?? '';
            if ($received_secret !== $status_secret) {
                http_response_code(403);
                echo 'Unauthorized';
                error_log('Unauthorized callback attempt');
                exit;
            }
        }

        $message_id = $_POST['id'] ?? '';
        $status     = $_POST['status'] ?? '';
        $reason     = $_POST['reason'] ?? '';
        $time       = $_POST['time'] ?? '';
        
        if (!empty($message_id) && !empty($status)) {
            // Update SMS status in database
            global $wpdb;
            $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
            
            $update_data = [
                'status' => strtolower($status),
                'updated_at' => current_time('mysql')
            ];
            
            // Add additional data if available
            if (!empty($reason)) {
                $existing_data = $wpdb->get_var($wpdb->prepare(
                    "SELECT response_data FROM {$table_name} WHERE message_id = %s",
                    $message_id
                ));
                
                $response_data = $existing_data ? json_decode($existing_data, true) : [];
                $response_data['delivery_reason'] = $reason;
                $response_data['delivery_time'] = $time;
                
                $update_data['response_data'] = json_encode($response_data);
            }
            
            $updated = $wpdb->update(
                $table_name,
                $update_data,
                ['message_id' => $message_id],
                array_fill(0, count($update_data), '%s'),
                ['%s']
            );
            
            if ($updated) {
                error_log("SMS status updated for message {$message_id}: {$status}");
            }
        }
        
        // Respond with 200 OK
        http_response_code(200);
        echo 'OK';
    }

    /**
     * Get callback URL for SMS status updates
     */
    public static function get_callback_url(): string
    {
        return home_url('/vms-sms-callback/');
    }



    /**
     * Get recent SMS logs for dashboard - UPDATED to use new table
     */
    public static function get_recent_sms_logs(int $limit = 10): array
    {
        global $wpdb;
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            ORDER BY created_at DESC 
            LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Get SMS statistics - UPDATED to use new table
     */
    public static function get_sms_statistics(int $days = 30): array
    {
        global $wpdb;
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_sms,
                SUM(CASE WHEN status IN ('sent', 'delivered') THEN 1 ELSE 0 END) as sent_sms,
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
        string $recipient_role,
        ?string $message_id,
        string $status,
        float $cost = 0,
        ?array $response_data = null,
        ?string $error_message = null
    ): bool {
        global $wpdb;
    
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
    
        return (bool) $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'recipient_number' => $phone,
                'recipient_role' => $recipient_role,
                'message' => $message,
                'message_id' => $message_id,
                'status' => $status,
                'cost' => $cost,
                'response_data' => $response_data ? json_encode($response_data) : null,
                'error_message' => $error_message
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s']
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

        $response = wp_remote_get(self::$api_base_url . '/delivery_reports/' . $message_id, [
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
            self::update_sms_status($message_id, $data['status']);
            return $data;
        }

        return null;
    }

    /**
     * Update SMS status in logs
     */
    private static function update_sms_status(string $message_id, string $status): bool
    {
        global $wpdb;
        
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        return (bool) $wpdb->update(
            $table_name,
            ['status' => $status, 'updated_at' => current_time('mysql')],
            ['message_id' => $message_id],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * NEW: Check delivery status for pending SMS
     */
    public static function check_pending_sms_delivery(): void
    {
        global $wpdb;
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        // Get messages sent in the last 24 hours that are still pending
        $pending_messages = $wpdb->get_results(
            "SELECT message_id FROM {$table_name} 
            WHERE status IN ('sent', 'queued') 
            AND message_id IS NOT NULL 
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 50"
        );
        
        foreach ($pending_messages as $message) {
            // Get delivery report
            $delivery_report = self::get_delivery_report($message->message_id);

            if ($delivery_report && isset($delivery_report['status'])) {
                $new_status = strtolower($delivery_report['status']);

                // Skip final states (do not overwrite or recheck)
                if (in_array($new_status, ['delivrd', 'failed'], true)) {
                    self::update_sms_status($message->message_id, $new_status);
                } else {
                    self::update_sms_status($message->message_id, $new_status);
                }
            }

            // Small delay to prevent API rate limiting
            usleep(200000); // 0.2 seconds
        }
    }


    /**
     * Fetch and save SMS balance
     */
    public static function fetch_and_save_sms_balance(): void
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            error_log('SMS API credentials not configured');
            return;
        }

        $auth = base64_encode($api_key . ':' . $api_secret);

        $response = wp_remote_get(self::$api_base_url . '/balance', [
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
    public static function refresh_sms_balance(): void
    {
        check_ajax_referer('refresh_balance_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        self::fetch_and_save_sms_balance();
        
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
    public static function test_sms_connection(): void
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
     * Send test SMS
     */
    public static function send_test_sms(string $phone): ?array
    {
        $message = 'Test message from VMS. Your SMS integration is working correctly!';
        return self::send_sms($phone, $message);
    }

    /**
     * Validate phone number format
     */
    public static function validate_phone_number(string $phone): bool
    {
        $clean_phone = self::clean_phone_number($phone);
        
        // Check if it's a valid Kenyan number (254 + 9 digits)
        return preg_match('/^254[17]\d{8}$/', $clean_phone) === 1;
    }

    /**
     * Handle status callback from SMS Leopard
     */
    public static function handle_status_callback(): void
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
            self::update_sms_status($message_id, strtolower($status));
        }
        
        // Respond with 200 OK
        http_response_code(200);
        echo 'OK';
        exit;
    }

    /**
     * Cleanup old SMS logs - UPDATED to clean logs older than 90 days
     */
    public static function cleanup_old_logs(int $days = 90): int
    {
        global $wpdb;
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $date_threshold
        ));
    }

    /**
     * Send visitor notification SMS
     */
    public static function send_visitor_notification(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $host = $visitor_data['host_name'] ?? '';
        $date = $visitor_data['visit_date'] ?? date('Y-m-d');
        $time = $visitor_data['visit_time'] ?? date('H:i');
        $role = 'guest';
        
        if (empty($phone)) {
            return null;
        }

        $message = "Hi {$name}, your visit to see {$host} on {$date} at {$time} has been scheduled. Please arrive on time. Thank you.";
        
        return self::send_sms($phone, $message, $visitor_data['user_id'] ?? null, $role);
    }

    /**
     * Send host notification SMS
     */
    public static function send_host_notification(array $host_data, array $visitor_data): ?array
    {
        $host_phone = $host_data['phone'] ?? '';
        $visitor_name = $visitor_data['name'] ?? '';
        $visitor_phone = $visitor_data['phone'] ?? '';
        $purpose = $visitor_data['purpose'] ?? 'Meeting';
        $role = 'member'; // Gets the first role
        
        if (empty($host_phone)) {
            return null;
        }

        $message = "Your visitor {$visitor_name} has arrived. Phone: {$visitor_phone}. Purpose: {$purpose}. Please come to reception.";
        
        return self::send_sms($host_phone, $message, $host_data['user_id'] ?? null, $role);
    }

    /**
     * Send check-in confirmation SMS
     */
    public static function send_checkin_confirmation(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $badge_number = $visitor_data['badge_number'] ?? '';
        $time = $visitor_data['checkin_time'] ?? date('H:i');
        $role = 'guest';
        
        if (empty($phone)) {
            return null;
        }

        $message = "Welcome {$name}! You have successfully checked in at {$time}.";
        if (!empty($badge_number)) {
            $message .= " Your badge number is {$badge_number}.";
        }
        $message .= " Enjoy your visit!";
        
        return self::send_sms($phone, $message, $visitor_data['user_id'] ?? null, $role);
    }

    /**
     * Send check-out confirmation SMS
     */
    public static function send_checkout_confirmation(array $visitor_data): ?array
    {
        $phone = $visitor_data['phone'] ?? '';
        $name = $visitor_data['name'] ?? '';
        $checkout_time = $visitor_data['checkout_time'] ?? date('H:i');
        $role = 'guest';
        
        if (empty($phone)) {
            return null;
        }

        $message = "Thank you for your visit {$name}! You have successfully checked out at {$checkout_time}. Have a great day!";
        
        return self::send_sms($phone, $message, $visitor_data['user_id'] ?? null, $role);
    }

    /**
     * Get SMS balance information
     */
    public static function get_balance_info(): array
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
    public static function is_configured(): bool
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        return !empty($api_key) && !empty($api_secret);
    }

    /**
     * Get SMS quota status
     */
    public static function get_quota_status(): array
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