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
        // Guest
        // add_action('wp_ajax_guest_registration', [self::class, 'handle_guest_registration']);
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
            wp_send_json_error(['message' => __('Security check failed.', 'vms')]);
        }

        // Verify if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to perform this action', 'vms')]);
        }       
    }
}