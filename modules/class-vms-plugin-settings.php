<?php
/**
 * Settings functionality handler for VMS plugin
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

class VMS_Settings extends Base
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
        self::setup_settings_management_hooks();
    }

    /**
     * Setup guest management related hooks
     */
    private static function setup_settings_management_hooks(): void
    {       
        // Hook the AJAX actions
        add_action( 'wp_ajax_vms_ajax_test_connection', [self::class, 'vms_ajax_test_connection'] );
        add_action( 'wp_ajax_vms_ajax_save_settings', [self::class, 'vms_ajax_save_settings'] );
        add_action( 'wp_ajax_vms_ajax_refresh_balance', [self::class, 'vms_ajax_refresh_balance'] );
    }
    
     /**
     * AJAX handler for saving settings
     */
    public static function vms_ajax_save_settings() 
    {
        self::verify_ajax_request();
        
        // Check user permissions
        if (!current_user_can('administrator') && !current_user_can('reception') && 
            !current_user_can('general_manager') && !current_user_can('chairman')) {
            wp_send_json_error(['errors' => ['Insufficient permissions']]);
        }
        
        $errors = [];
        
        // Sanitize and validate inputs
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_secret = sanitize_text_field($_POST['api_secret'] ?? '');
        $sender_id = sanitize_text_field($_POST['sender_id'] ?? 'SMS_TEST');
        $status_url = esc_url_raw($_POST['status_url'] ?? '');
        $status_secret = sanitize_text_field($_POST['status_secret'] ?? '');
        
        // Validate required fields
        if (empty($api_key)) {
            $errors[] = 'API Key is required';
        }
        
        if (empty($api_secret)) {
            $errors[] = 'API Secret is required';
        }
        
        if (!empty($status_url) && empty($status_secret)) {
            $errors[] = 'Status Secret is required when Status URL is provided';
        }
        
        // Validate URL format if provided
        if (!empty($status_url) && !filter_var($status_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid Status Callback URL';
        }
        
        if (!empty($errors)) {
            wp_send_json_error(['errors' => $errors]);
        }
        
        // Save settings
        update_option('vms_sms_api_key', $api_key);
        update_option('vms_sms_api_secret', $api_secret);
        update_option('vms_sms_sender_id', $sender_id);
        update_option('vms_status_url', $status_url);
        update_option('vms_status_secret', $status_secret);
        
        wp_send_json_success(['message' => 'Settings saved successfully']);
    }

    /**
     * AJAX handler for testing connection
     */
    public static function vms_ajax_test_connection() 
    {
        self::verify_ajax_request();
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_secret = sanitize_text_field($_POST['api_secret'] ?? '');
        
        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error(['errors' => ['API Key and API Secret are required']]);
        }
        
        // Test connection by trying to get balance
        $response = wp_remote_get('https://api.smsleopard.com/v1/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['errors' => ['Connection failed: ' . $response->get_error_message()]]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200 && isset($data['balance'])) {
            wp_send_json_success([
                'message' => 'Connection successful! Your API credentials are working properly.',
                'balance' => $data['balance']
            ]);
        } elseif ($status_code === 401) {
            wp_send_json_error(['errors' => ['Invalid API credentials. Please check your API Key and Secret.']]);
        } elseif ($status_code === 403) {
            wp_send_json_error(['errors' => ['Access denied. Please verify your API permissions.']]);
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error occurred';
            wp_send_json_error(['errors' => ['API Error: ' . $error_message]]);
        }
    }

    /**
     * AJAX handler for refreshing SMS balance
     */
    public static function vms_ajax_refresh_balance() 
    {
        self::verify_ajax_request();
        
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error(['errors' => ['Please configure API credentials first']]);
        }
        
        $response = wp_remote_get('https://api.smsleopard.com/v1/balance', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['errors' => ['API connection failed: ' . $response->get_error_message()]]);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['balance'])) {
            update_option('vms_sms_balance', $data['balance']);
            $last_check = current_time('mysql');
            update_option('vms_sms_last_check', $last_check);
            
            wp_send_json_success([
                'message'      => 'Balance refreshed successfully',
                'balance'      => $data['balance'],
                'last_checked' => date('M j, Y g:i A', strtotime($last_check)),
            ]);
        }
        
        wp_send_json_error(['errors' => ['Failed to retrieve balance from API']]);
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