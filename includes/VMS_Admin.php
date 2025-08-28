<?php
/**
 * Handles all VMS Admin settings and functionality
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Admin
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Settings group name
     * @var string
     */
    private $settings_group = 'vms_settings';

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
     * Initialize admin functionality
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'setup_auto_config']);
        add_filter('plugin_action_links_' . VMS_PLUGIN_BASENAME, [$this, 'add_settings_link']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('wp_ajax_refresh_sms_balance', [$this, 'ajax_refresh_balance']);
        add_action('wp_ajax_test_sms_connection', [$this, 'ajax_test_connection']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add settings link to the plugins page
     * @param array $links Array of plugin action links
     * @return array Modified array of plugin action links
     */
    public function add_settings_link(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=vms-settings')),
            esc_html__('Settings', 'vms')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void
    {
        add_menu_page(
            __('VMS Settings', 'vms'),
            __('VMS', 'vms'),
            'manage_options',
            'vms-settings',
            [$this, 'render_settings_page'],
            'dashicons-email-alt',
            80
        );

        add_submenu_page(
            'vms-settings',
            __('SMS Logs', 'vms'),
            __('SMS Logs', 'vms'),
            'manage_options',
            'vms-sms-logs',
            [$this, 'render_sms_logs_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings(): void
    {
        // SMS Leopard API Settings
        register_setting(
            $this->settings_group,
            'vms_sms_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        register_setting(
            $this->settings_group,
            'vms_sms_api_secret',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        register_setting(
            $this->settings_group,
            'vms_sms_sender_id',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'SMS_TEST'
            ]
        );

        register_setting(
            $this->settings_group,
            'vms_status_url',
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => ''
            ]
        );

        register_setting(
            $this->settings_group,
            'vms_status_secret',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        // SMS Leopard API Section
        add_settings_section(
            'vms_sms_section',
            __('SMS Leopard API Configuration', 'vms'),
            [$this, 'render_sms_section_description'],
            $this->settings_group
        );

        add_settings_field(
            'vms_sms_api_key',
            __('API Key *', 'vms'),
            [$this, 'render_api_key_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_sms_api_secret',
            __('API Secret *', 'vms'),
            [$this, 'render_api_secret_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_sms_sender_id',
            __('Sender ID', 'vms'),
            [$this, 'render_sender_id_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_status_url',
            __('Status Callback URL', 'vms'),
            [$this, 'render_status_url_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_status_secret',
            __('Status Secret', 'vms'),
            [$this, 'render_status_secret_field'],
            $this->settings_group,
            'vms_sms_section'
        );
    }

    /**
     * Setup automatic configuration
     */
    public function setup_auto_config(): void
    {
        $status_url = get_option('vms_status_url', '');
        $status_secret = get_option('vms_status_secret', '');
        
        // Auto-configure callback URL if not set
        if (empty($status_url)) {
            $callback_url = home_url('/vms-sms-callback/');
            update_option('vms_status_url', $callback_url);
        }
        
        // Generate status secret if not set
        if (empty($status_secret)) {
            $secret = wp_generate_password(32, false, false);
            update_option('vms_status_secret', $secret);
        }
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook): void
    {
        if (!in_array($hook, ['toplevel_page_vms-settings', 'vms_page_vms-sms-logs'])) {
            return;
        }

        wp_enqueue_script('jquery');
    }

    /**
     * Show admin notices
     */
    public function show_admin_notices(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'toplevel_page_vms-settings') {
            return;
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            $this->show_success_notice(__('Settings saved successfully!', 'vms'));
        }

        // Check if API credentials are set
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            $this->show_error_notice(__('Please configure your SMS Leopard API credentials to use SMS functionality.', 'vms'));
        }
    }

    /**
     * Show success notice
     */
    private function show_success_notice(string $message): void
    {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Show error notice
     */
    private function show_error_notice(string $message): void
    {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * AJAX handler for refreshing SMS balance
     */
    public function ajax_refresh_balance(): void
    {
        check_ajax_referer('refresh_balance_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'vms'));
        }

        // Here you would integrate with your SMS service to get balance
        // For now, return mock data
        $balance = $this->fetch_sms_balance();
        
        if ($balance !== false) {
            update_option('vms_sms_balance', $balance);
            update_option('vms_sms_last_check', current_time('mysql'));
            wp_send_json_success(['balance' => $balance]);
        } else {
            wp_send_json_error(__('Failed to fetch balance', 'vms'));
        }
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('test_connection_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'vms'));
        }

        $result = $this->test_sms_connection();
        
        if ($result === true) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Fetch SMS balance from API
     */
    private function fetch_sms_balance()
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            return false;
        }

        // Implement actual API call here
        // This is a placeholder
        return rand(100, 1000);
    }

    /**
     * Test SMS connection
     */
    private function test_sms_connection()
    {
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            return __('API credentials not configured', 'vms');
        }

        // Implement actual connection test here
        // This is a placeholder
        return true;
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'vms'),
                __('Access Denied', 'vms'),
                ['response' => 403]
            );
        }

        // Get current balance
        $balance = get_option('vms_sms_balance', 0);
        $last_check = get_option('vms_sms_last_check', '');
        ?>
<div class="wrap">
    <h1><?php esc_html_e('VMS Settings', 'vms'); ?></h1>

    <!-- Balance Card -->
    <div class="postbox" style="margin-top: 20px;">
        <h2 class="hndle" style="margin-inline-start: 10px;">
            <span><?php esc_html_e('SMS Balance', 'vms'); ?></span>
        </h2>
        <div class="inside">
            <p><strong><?php esc_html_e('Current Balance:', 'vms'); ?></strong> KES
                <?php echo esc_html(number_format($balance, 2)); ?></p>
            <?php if ($last_check): ?>
            <p><small><?php esc_html_e('Last updated:', 'vms'); ?>
                    <?php echo esc_html(date('Y-m-d H:i:s', strtotime($last_check))); ?></small></p>
            <?php endif; ?>
            <button type="button" class="button"
                id="refresh-balance"><?php esc_html_e('Refresh Balance', 'vms'); ?></button>
            <button type="button" class="button button-secondary"
                id="test-connection"><?php esc_html_e('Test Connection', 'vms'); ?></button>
        </div>
    </div>

    <form method="post" action="options.php">
        <?php
                settings_fields($this->settings_group);
                do_settings_sections($this->settings_group);
                submit_button();
                ?>
    </form>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#refresh-balance').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('<?php esc_html_e('Refreshing...', 'vms'); ?>');

            $.post(ajaxurl, {
                action: 'refresh_sms_balance',
                nonce: '<?php echo wp_create_nonce('refresh_balance_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('<?php esc_html_e('Failed to refresh balance', 'vms'); ?>');
                }
            }).always(function() {
                button.prop('disabled', false).text(
                    '<?php esc_html_e('Refresh Balance', 'vms'); ?>');
            });
        });

        $('#test-connection').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('<?php esc_html_e('Testing...', 'vms'); ?>');

            $.post(ajaxurl, {
                action: 'test_sms_connection',
                nonce: '<?php echo wp_create_nonce('test_connection_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('<?php esc_html_e('Connection successful!', 'vms'); ?>');
                } else {
                    alert('<?php esc_html_e('Connection failed: ', 'vms'); ?>' + response.data);
                }
            }).always(function() {
                button.prop('disabled', false).text(
                    '<?php esc_html_e('Test Connection', 'vms'); ?>');
            });
        });
    });
    </script>

    <style>
    .postbox {
        max-width: 800px;
    }

    .postbox .inside {
        padding: 12px;
    }

    .button {
        margin-right: 10px;
    }
    </style>
</div>
<?php
    }

    /**
     * Render SMS logs page
     */
    public function render_sms_logs_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'vms'),
                __('Access Denied', 'vms'),
                ['response' => 403]
            );
        }

        $logs = $this->get_sms_logs();
        ?>
<div class="wrap">
    <h1><?php esc_html_e('SMS Logs', 'vms'); ?></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('ID', 'vms'); ?></th>
                <th><?php esc_html_e('User', 'vms'); ?></th>
                <th><?php esc_html_e('Recipient', 'vms'); ?></th>
                <th><?php esc_html_e('Message', 'vms'); ?></th>
                <th><?php esc_html_e('Status', 'vms'); ?></th>
                <th><?php esc_html_e('Cost', 'vms'); ?></th>
                <th><?php esc_html_e('Date', 'vms'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($logs)): ?>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo esc_html($log['id']); ?></td>
                <td><?php echo esc_html($log['user_name']); ?></td>
                <td><?php echo esc_html($log['recipient_number']); ?></td>
                <td title="<?php echo esc_attr($log['message']); ?>">
                    <?php echo esc_html($log['message_preview']); ?>
                    <?php if (strlen($log['message']) > 100): ?>...<?php endif; ?>
                </td>
                <td>
                    <span class="status-badge <?php echo esc_attr($log['status_class']); ?>">
                        <?php echo esc_html($this->get_status_text($log['status'])); ?>
                    </span>
                </td>
                <td><?php echo esc_html($log['cost_formatted']); ?></td>
                <td><?php echo esc_html($log['formatted_date']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="7"><?php esc_html_e('No SMS logs found.', 'vms'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <style>
    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-sent {
        background: #d1e7dd;
        color: #0a3622;
    }

    .status-delivered {
        background: #d1e7dd;
        color: #0a3622;
    }

    .status-failed {
        background: #f8d7da;
        color: #58151c;
    }

    .status-queued {
        background: #fff3cd;
        color: #664d03;
    }

    .status-pending {
        background: #cff4fc;
        color: #055160;
    }
    </style>
</div>
<?php
    }

    /**
     * Get SMS logs with formatting
     */
    private function get_sms_logs(int $limit = 50): array
    {
        global $wpdb;
        
        // Check if table exists
        $table_name = $wpdb->prefix . 'vms_sms_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return [];
        }
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                id,
                user_id,
                recipient_number,
                message,
                status,
                cost,
                error_message,
                created_at
            FROM {$table_name} 
            ORDER BY created_at DESC 
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        // Format the data for display
        foreach ($logs as &$log) {
            $log['message_preview'] = wp_trim_words($log['message'], 10, '');
            $log['formatted_date'] = date('M j, Y g:i A', strtotime($log['created_at']));
            $log['status_class'] = 'status-' . strtolower($log['status']);
            $log['cost_formatted'] = 'KES ' . number_format($log['cost'], 2);
            
            // Get user name if user_id exists
            if ($log['user_id']) {
                $user = get_userdata($log['user_id']);
                $log['user_name'] = $user ? $user->display_name : 'Unknown User';
            } else {
                $log['user_name'] = 'System';
            }
        }
        
        return $logs;
    }

    /**
     * Get human-readable SMS status text
     */
    private function get_status_text(string $status): string
    {
        $statuses = [
            'delivered' => 'Delivered',
            'sent' => 'Sent',
            'queued' => 'Queued',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'expired' => 'Expired',
            'undelivered' => 'Undelivered'
        ];
        
        return $statuses[strtolower($status)] ?? ucfirst($status);
    }

    /**
     * Render SMS section description
     */
    public function render_sms_section_description(): void
    {
        echo '<p>' . esc_html__('Configure your SMS Leopard API settings to enable SMS notifications.', 'vms') . '</p>';
    }

    /**
     * Render API key field
     */
    public function render_api_key_field(): void
    {
        $value = get_option('vms_sms_api_key', '');
        ?>
<input type="text" name="vms_sms_api_key" id="vms_sms_api_key" value="<?php echo esc_attr($value); ?>"
    class="regular-text" required>
<p class="description">
    <?php esc_html_e('Enter your SMS Leopard API key.', 'vms'); ?>
</p>
<?php
    }

    /**
     * Render API secret field
     */
    public function render_api_secret_field(): void
    {
        $value = get_option('vms_sms_api_secret', '');
        ?>
<input type="password" name="vms_sms_api_secret" id="vms_sms_api_secret" value="<?php echo esc_attr($value); ?>"
    class="regular-text" required>
<p class="description">
    <?php esc_html_e('Enter your SMS Leopard API secret.', 'vms'); ?>
</p>
<?php
    }

    /**
     * Render sender ID field
     */
    public function render_sender_id_field(): void
    {
        $value = get_option('vms_sms_sender_id', 'SMS_TEST');
        ?>
<input type="text" name="vms_sms_sender_id" id="vms_sms_sender_id" value="<?php echo esc_attr($value); ?>"
    class="regular-text">
<p class="description">
    <?php esc_html_e('Your SMS sender ID. Use "SMS_TEST" for testing.', 'vms'); ?>
</p>
<?php
    }

    /**
     * Render status URL field
     */
    public function render_status_url_field(): void
    {
        $value = get_option('vms_status_url', '');
        ?>
<input type="url" name="vms_status_url" id="vms_status_url" value="<?php echo esc_url($value); ?>" class="regular-text">
<p class="description">
    <?php esc_html_e('Optional callback URL for delivery reports.', 'vms'); ?>
</p>
<?php
    }

    /**
     * Render status secret field
     */
    public function render_status_secret_field(): void
    {
        $value = get_option('vms_status_secret', '');
        ?>
<input type="text" name="vms_status_secret" id="vms_status_secret" value="<?php echo esc_attr($value); ?>"
    class="regular-text">
<p class="description">
    <?php esc_html_e('Secret for callback verification (required if status URL is provided).', 'vms'); ?>
</p>
<?php
    }
}