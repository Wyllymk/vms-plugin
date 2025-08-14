<?php
/**
 * Handles all Cyber Wakili Google logins.
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Google
{
    private static $instance = null;

    private $client_id     = '';
    private $client_secret = '';
    private $redirect_uri  = 'https://vms.nyericlub.co.ke/google-login-callback/';

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_action('init', [$this, 'handle_google_oauth_callback']);
        add_shortcode('cw_google_login_button', [$this, 'render_google_login_button']);
    }

    /**
     * You can use [cw_google_login_button] in your template OR
     * just call get_google_login_url() to link a custom button manually.
     */
    public function render_google_login_button(): string
    {
        $auth_url = $this->get_google_auth_url();
        return '<a href="' . esc_url($auth_url) . '" class="google-login-btn">Sign in with Google</a>';
    }

    public function get_google_auth_url(): string
    {
        $params = [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'offline',
            'prompt'        => 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function handle_google_oauth_callback(): void
    {
        if (!isset($_GET['code']) || strpos($_SERVER['REQUEST_URI'], 'google-login-callback') === false) {
            return;
        }

        $token = $this->get_access_token($_GET['code']);
        if (!isset($token['access_token'])) {
            wp_die('Google login failed. Please try again.');
        }

        $user_info = $this->get_google_user_info($token['access_token']);

        if (empty($user_info['email'])) {
            wp_die('Failed to retrieve Google account email.');
        }

        $user = get_user_by('email', $user_info['email']);

        if (!$user) {
            // Generate username from email if full name is too risky
            $username = sanitize_user(current(explode('@', $user_info['email'])));

            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => sanitize_email($user_info['email']),
                'user_pass'  => wp_generate_password(12, false),
                'role'       => 'subscriber', // Default role, can be changed
                'first_name' => $user_info['given_name'] ?? '',
                'last_name'  => $user_info['family_name'] ?? '',
            ]);

            if (is_wp_error($user_id)) {
                wp_die('User creation failed: ' . $user_id->get_error_message());
            }

            $user = get_user_by('ID', $user_id);
        }

        wp_set_auth_cookie($user->ID, true);
        wp_redirect(home_url('/dashboard/'));
        exit;
    }

    private function get_access_token(string $code): array
    {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function get_google_user_info(string $access_token): array
    {
        $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}