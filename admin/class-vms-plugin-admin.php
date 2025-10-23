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

class VMS_Admin extends Base
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
        add_action('wp_ajax_resend_sms', [$this, 'ajax_resend_sms']);
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
            esc_html__('Settings', 'vms-plugin')
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
            __('VMS Settings', 'vms-plugin'),
            __('VMS', 'vms-plugin'),
            'manage_options',
            'vms-settings',
            [$this, 'render_settings_page'],
            'dashicons-email-alt',
            80
        );

        add_submenu_page(
            'vms-settings',
            __('SMS Logs', 'vms-plugin'),
            __('SMS Logs', 'vms-plugin'),
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
            __('SMS Leopard API Configuration', 'vms-plugin'),
            [$this, 'render_sms_section_description'],
            $this->settings_group
        );

        add_settings_field(
            'vms_sms_api_key',
            __('API Key *', 'vms-plugin'),
            [$this, 'render_api_key_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_sms_api_secret',
            __('API Secret *', 'vms-plugin'),
            [$this, 'render_api_secret_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_sms_sender_id',
            __('Sender ID', 'vms-plugin'),
            [$this, 'render_sender_id_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_status_url',
            __('Status Callback URL', 'vms-plugin'),
            [$this, 'render_status_url_field'],
            $this->settings_group,
            'vms_sms_section'
        );

        add_settings_field(
            'vms_status_secret',
            __('Status Secret', 'vms-plugin'),
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
            $this->show_success_notice(__('Settings saved successfully!', 'vms-plugin'));
        }

        // Check if API credentials are set
        $api_key = get_option('vms_sms_api_key', '');
        $api_secret = get_option('vms_sms_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            $this->show_error_notice(__('Please configure SMS Leopard API credentials to use SMS functionality.', 'vms-plugin'));
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
            wp_die(__('Insufficient permissions.', 'vms-plugin'));
        }

        // Here you would integrate with SMS service to get balance
        // For now, return mock data
        $balance = VMS_SMS::refresh_sms_balance();
        
        if ($balance !== false) {
            update_option('vms_sms_balance', $balance);
            update_option('vms_sms_last_check', current_time('mysql'));
            wp_send_json_success(['balance' => $balance]);
        } else {
            wp_send_json_error(__('Failed to fetch balance', 'vms-plugin'));
        }
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('test_connection_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'vms-plugin'));
        }

        $result = VMS_SMS::test_sms_connection();        
        
        if ($result === true) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for resending SMS
     */
    public function ajax_resend_sms(): void
    {
        check_ajax_referer('resend_sms_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'vms-plugin'));
        }

        $log_id = isset($_POST['log_id']) ? (int)$_POST['log_id'] : 0;
        
        if (!$log_id) {
            wp_send_json_error(__('Invalid SMS log ID.', 'vms-plugin'));
        }

        // Get the original SMS data
        $sms_data = $this->get_sms_log_data($log_id);
        
        if (!$sms_data) {
            wp_send_json_error(__('SMS log not found.', 'vms-plugin'));
        }

        // Resend the SMS using VMS_SMS
        $result = VMS_SMS::send_sms(
            $sms_data['recipient_number'],
            $sms_data['message'],
            $sms_data['user_id'],
            $sms_data['recipient_role']
        );

        if (is_array($result) && isset($result['success']) && $result['success'] === true) {
            wp_send_json_success(__('SMS resent successfully.', 'vms-plugin'));
        } else {
            $error_message = is_array($result) && isset($result['response'])
                ? $result['response']
                : (is_string($result) ? $result : __('Failed to resend SMS.', 'vms-plugin'));

            wp_send_json_error($error_message);
        }
    }

    /**
     * Get SMS log data by ID
     */
    private function get_sms_log_data(int $log_id): ?array
    {
        global $wpdb;
        
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, recipient_number, recipient_role, message 
             FROM {$table_name} 
             WHERE id = %d LIMIT 1",
            $log_id
        ), ARRAY_A);
        
        return $log ?: null;
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'vms-plugin'),
                __('Access Denied', 'vms-plugin'),
                ['response' => 403]
            );
        }

        // Get current balance
        $balance = get_option('vms_sms_balance', 0);
        $last_check = get_option('vms_sms_last_check', '');
        ?>
<div class="wrap">
    <h1><?php esc_html_e('VMS Settings', 'vms-plugin'); ?></h1>

    <!-- Balance Card -->
    <div class="postbox" style="margin-top: 20px;">
        <h2 class="hndle" style="margin-inline-start: 10px;">
            <span><?php esc_html_e('SMS Balance', 'vms-plugin'); ?></span>
        </h2>
        <div class="inside">
            <p><strong><?php esc_html_e('Current Balance:', 'vms-plugin'); ?></strong> KES
                <?php echo esc_html($balance, 2); ?></p>
            <?php if ($last_check): ?>
            <p><small><?php esc_html_e('Last updated:', 'vms-plugin'); ?>
                    <?php echo esc_html(date('Y-m-d H:i:s', strtotime($last_check))); ?></small></p>
            <?php endif; ?>
            <button type="button" class="button"
                id="refresh-balance"><?php esc_html_e('Refresh Balance', 'vms-plugin'); ?></button>
            <button type="button" class="button button-secondary"
                id="test-connection"><?php esc_html_e('Test Connection', 'vms-plugin'); ?></button>
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
            button.prop('disabled', true).text('<?php esc_html_e('Refreshing...', 'vms-plugin'); ?>');

            $.post(ajaxurl, {
                action: 'refresh_sms_balance',
                nonce: '<?php echo wp_create_nonce('refresh_balance_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('<?php esc_html_e('Failed to refresh balance', 'vms-plugin'); ?>');
                }
            }).always(function() {
                button.prop('disabled', false).text(
                    '<?php esc_html_e('Refresh Balance', 'vms-plugin'); ?>');
            });
        });

        $('#test-connection').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('<?php esc_html_e('Testing...', 'vms-plugin'); ?>');

            $.post(ajaxurl, {
                action: 'test_sms_connection',
                nonce: '<?php echo wp_create_nonce('test_connection_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('<?php esc_html_e('Connection successful!', 'vms-plugin'); ?>');
                } else {
                    alert('<?php esc_html_e('Connection failed: ', 'vms-plugin'); ?>' + response
                        .data);
                }
            }).always(function() {
                button.prop('disabled', false).text(
                    '<?php esc_html_e('Test Connection', 'vms-plugin'); ?>');
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
                __('You do not have sufficient permissions to access this page.', 'vms-plugin'),
                __('Access Denied', 'vms-plugin'),
                ['response' => 403]
            );
        }

        // Handle delete action
        if (isset($_POST['delete_log']) && isset($_POST['log_id']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_sms_log')) {
            $this->delete_sms_log((int)$_POST['log_id']);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('SMS log deleted successfully.', 'vms-plugin') . '</p></div>';
        }

        // Get pagination parameters
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 25;
        $offset = ($current_page - 1) * $per_page;

        // Get logs and total count
        $logs_data = $this->get_sms_logs_paginated($per_page, $offset);
        $logs = $logs_data['logs'];
        $total_logs = $logs_data['total'];
        $total_pages = ceil($total_logs / $per_page);

        // Calculate log numbers
        $start_number = $total_logs - $offset;
        ?>
<div class="wrap">
    <h1><?php esc_html_e('SMS Logs', 'vms-plugin'); ?></h1>

    <!-- Summary Info -->
    <div class="logs-summary">
        <p><strong><?php esc_html_e('Total SMS Logs:', 'vms-plugin'); ?></strong>
            <?php echo number_format($total_logs); ?></p>
        <?php if ($total_logs > 0): ?>
        <p><?php printf(__('Showing logs %d to %d of %d'), 
            $offset + 1, 
            min($offset + $per_page, $total_logs), 
            $total_logs
        ); ?></p>
        <?php endif; ?>
    </div>

    <!-- Logs Table -->
    <table class="wp-list-table widefat fixed striped sms-logs-table">
        <thead>
            <tr>
                <th class="column-number"><?php esc_html_e('#', 'vms-plugin'); ?></th>
                <th class="column-user"><?php esc_html_e('Recipient Name', 'vms-plugin'); ?></th>
                <th class="column-phone"><?php esc_html_e('Phone', 'vms-plugin'); ?></th>
                <th class="column-role"><?php esc_html_e('Role', 'vms-plugin'); ?></th>
                <th class="column-message"><?php esc_html_e('Message', 'vms-plugin'); ?></th>
                <th class="column-status"><?php esc_html_e('Status', 'vms-plugin'); ?></th>
                <th class="column-cost"><?php esc_html_e('Cost', 'vms-plugin'); ?></th>
                <th class="column-date"><?php esc_html_e('Date', 'vms-plugin'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'vms-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($logs)): ?>
            <?php foreach ($logs as $index => $log): ?>
            <tr>
                <td class="column-number"><strong><?php echo esc_html($start_number - $index); ?></strong></td>
                <td class="column-user">
                    <?php echo esc_html($log['recipient_name']); ?>
                    <?php if ($log['sender_name']): ?>
                    <br><small
                        class="text-muted"><?php printf(__('Sent by: %s'), esc_html($log['sender_name'])); ?></small>
                    <?php endif; ?>
                </td>
                <td class="column-phone"><?php echo esc_html($log['recipient_number']); ?></td>
                <td class="column-role">
                    <span class="role-badge role-<?php echo esc_attr($log['recipient_role']); ?>">
                        <?php echo esc_html(ucfirst($log['recipient_role'])); ?>
                    </span>
                </td>
                <td class="column-message" title="<?php echo esc_attr($log['message']); ?>">
                    <div class="message-preview">
                        <span class="message-text"><?php echo esc_html($log['message_preview']); ?></span>
                        <?php if (strlen($log['message']) > 50): ?>
                        <span class="message-toggle" data-full="<?php echo esc_attr($log['message']); ?>"
                            data-preview="<?php echo esc_attr($log['message_preview']); ?>">
                            <a href="#" onclick="toggleMessage(this); return false;">Show more</a>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>

                <td class="column-status">
                    <span class="status-badge <?php echo esc_attr($log['status_class']); ?>">
                        <?php echo esc_html($this->get_status_text($log['status'])); ?>
                    </span>
                    <?php if ($log['error_message']): ?>
                    <br><small class="error-message" title="<?php echo esc_attr($log['error_message']); ?>">
                        <?php echo esc_html(wp_trim_words($log['error_message'], 5)); ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td class="column-cost"><?php echo esc_html($log['cost_formatted']); ?></td>
                <td class="column-date">
                    <span title="<?php echo esc_attr(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?>">
                        <?php echo esc_html($log['formatted_date']); ?>
                    </span>
                </td>
                <td class="column-actions">
                    <div class="action-buttons">
                        <!-- Resend Button -->
                        <button type="button" class="button-link resend-button"
                            data-log-id="<?php echo esc_attr($log['id']); ?>"
                            title="<?php esc_attr_e('Resend SMS', 'vms-plugin'); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>

                        <!-- Delete Button -->
                        <form method="post" style="display:inline;" class="delete-form"
                            onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this SMS log?', 'vms-plugin'); ?>');">
                            <?php wp_nonce_field('delete_sms_log'); ?>
                            <input type="hidden" name="log_id" value="<?php echo esc_attr($log['id']); ?>">
                            <button type="submit" name="delete_log" class="button-link delete-button"
                                title="<?php esc_attr_e('Delete Log', 'vms-plugin'); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="9" class="no-logs"><?php esc_html_e('No SMS logs found.', 'vms-plugin'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav">
        <div class="tablenav-pages">
            <span
                class="displaying-num"><?php printf(_n('%s item', '%s items', $total_logs, 'vms-plugin'), number_format_i18n($total_logs)); ?></span>
            <?php
            $pagination_args = [
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo; ' . __('Previous'),
                'next_text' => __('Next') . ' &raquo;',
                'total' => $total_pages,
                'current' => $current_page,
                'show_all' => false,
                'end_size' => 1,
                'mid_size' => 2,
                'type' => 'plain',
            ];
            echo paginate_links($pagination_args);
            ?>
        </div>
    </div>
    <?php endif; ?>

    <style>
    .logs-summary {
        background: #f1f1f1;
        padding: 15px;
        margin: 20px 0;
        border-left: 4px solid #0073aa;
    }

    .logs-summary p {
        margin: 5px 0;
    }

    .sms-logs-table {
        margin-top: 20px;
    }

    .sms-logs-table th,
    .sms-logs-table td {
        padding: 12px 8px;
    }

    .column-number {
        width: 50px;
        text-align: center;
    }

    .column-user {
        width: 150px;
    }

    .column-phone {
        width: 120px;
    }

    .column-role {
        width: 80px;
    }

    .column-message {
        width: 200px;
    }

    .column-status {
        width: 100px;
    }

    .column-cost {
        width: 80px;
    }

    .column-date {
        width: 130px;
    }

    .column-actions {
        width: 80px;
        text-align: center;
    }

    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: center;
        align-items: center;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }

    .status-sent {
        background: #d1e7dd;
        color: #0a3622;
    }

    .status-delivrd {
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

    .status-expired {
        background: #f8d7da;
        color: #58151c;
    }

    .status-undelivered {
        background: #f8d7da;
        color: #58151c;
    }

    .role-badge {
        padding: 3px 6px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }

    .role-guest {
        background: #e3f2fd;
        color: #1565c0;
    }

    .role-member {
        background: #e8f5e8;
        color: #2e7d32;
    }

    .role-chairman {
        background: #fff3e0;
        color: #ef6c00;
    }

    .role-admin {
        background: #fce4ec;
        color: #c2185b;
    }

    .text-muted {
        color: #666;
    }

    .error-message {
        color: #dc3232;
        font-style: italic;
    }

    .message-preview {
        max-width: 200px;
        word-wrap: break-word;
    }

    .message-toggle a {
        color: #0073aa;
        text-decoration: none;
        font-size: 11px;
    }

    .resend-button {
        color: #0073aa;
        border: none;
        background: none;
        cursor: pointer;
        padding: 5px;
    }

    .resend-button:hover {
        color: #005a87;
    }

    .resend-button:disabled {
        color: #ccc;
        cursor: not-allowed;
    }

    .delete-button {
        color: #dc3232;
        border: none;
        background: none;
        cursor: pointer;
        padding: 5px;
    }

    .delete-button:hover {
        color: #a00;
    }

    .no-logs {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }

    .tablenav {
        margin-top: 20px;
        padding: 10px 0;
    }

    .tablenav-pages {
        float: right;
    }

    .tablenav-pages .page-numbers {
        display: inline-block;
        padding: 8px 12px;
        margin: 0 2px;
        text-decoration: none;
        border: 1px solid #ddd;
        color: #0073aa;
    }

    .tablenav-pages .page-numbers.current {
        background: #0073aa;
        color: white;
        border-color: #0073aa;
    }

    .tablenav-pages .page-numbers:hover {
        background: #f1f1f1;
    }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Handle resend button clicks
        $('.resend-button').on('click', function() {
            var button = $(this);
            var logId = button.data('log-id');
            var originalHtml = button.html();

            if (button.prop('disabled')) {
                return;
            }

            if (!confirm('Are you sure you want to resend this SMS?')) {
                return;
            }

            // Disable button and show loading state
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

            $.post(ajaxurl, {
                action: 'resend_sms',
                log_id: logId,
                nonce: '<?php echo wp_create_nonce('resend_sms_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    console.log('Resend SMS response:', response);
                    // Show success message
                    var successMessage = $(
                        '<div class="notice notice-success is-dismissible inline-notice"><p>' +
                        response.data + '</p></div>');
                    button.closest('tr').after('<tr class="success-row"><td colspan="9">' +
                        successMessage.prop('outerHTML') + '</td></tr>');

                    // Remove success message after 3 seconds
                    setTimeout(function() {
                        $('.success-row').fadeOut(function() {
                            $(this).remove();
                        });
                        window.location.reload();
                    }, 3000);
                } else {
                    console.log('Resend SMS error response:', response);
                    // Show error message
                    var errorMessage = response.data ||
                        'Failed to resend SMS.';
                    var errorNotice = $(
                        '<div class="notice notice-error is-dismissible inline-notice"><p>' +
                        errorMessage + '</p></div>');
                    button.closest('tr').after('<tr class="error-row"><td colspan="9">' +
                        errorNotice.prop('outerHTML') + '</td></tr>');

                    // Remove error message after 5 seconds
                    setTimeout(function() {
                        $('.error-row').fadeOut(function() {
                            $(this).remove();
                        });
                        window.location.reload();
                    }, 5000);
                }
            }).fail(function() {
                alert('Network error occurred. Please try again.');
            }).always(function() {
                // Re-enable button and restore original state
                button.prop('disabled', false).html(originalHtml);
            });
        });
    });

    function toggleMessage(element) {
        var messageDiv = element.closest('.message-preview');
        var messageText = messageDiv.querySelector('.message-text');
        var toggle = element.parentNode;
        var preview = toggle.getAttribute('data-preview');
        var full = toggle.getAttribute('data-full');

        if (messageText.textContent === preview) {
            messageText.textContent = full;
            element.textContent = 'Show less';
        } else {
            messageText.textContent = preview;
            element.textContent = 'Show more';
        }
    }
    </script>
</div>
<?php
    }

    /**
     * Get SMS logs with pagination
     */
    private function get_sms_logs_paginated(int $per_page = 25, int $offset = 0): array
    {
        global $wpdb;
        
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        
        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return ['logs' => [], 'total' => 0];
        }
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        // Get logs with limit and offset
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                id,
                user_id,
                recipient_number,
                recipient_role,
                message,
                message_id,
                status,
                cost,
                error_message,
                created_at
            FROM {$table_name} 
            ORDER BY created_at DESC, id DESC
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);
        
        // Format the data for display
        foreach ($logs as &$log) {
            $log['message_preview'] = wp_trim_words($log['message'], 8, '');
            $log['formatted_date'] = date('M j, Y g:i A', strtotime($log['created_at']));
            $log['status_class'] = 'status-' . strtolower($log['status']);
            $log['cost_formatted'] = 'KES ' . number_format($log['cost'], 2);
            
            // Get recipient name based on role & user_id
            $log['recipient_name'] = $this->get_recipient_name( (int)$log['user_id'], $log['recipient_role'], $log['recipient_number'] );
            
            // Get sender name if user_id exists
            if ($log['user_id']) {
                $user = get_userdata($log['user_id']);
                $log['sender_name'] = $user ? $user->first_name . ' ' . $user->last_name : 'Unknown User';
            } else {
                $log['sender_name'] = '';
            }
        }
        
        return [
            'logs' => $logs,
            'total' => (int)$total
        ];
    }


    /**
     * Get recipient name based on user_id and role
     */
    private function get_recipient_name(?int $user_id, string $role, ?string $phone = null): string
    {
        global $wpdb;

        if (in_array($role, ['member', 'chairman', 'admin'])) {
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    $first_name = $user->first_name ?? '';
                    $last_name  = $user->last_name ?? '';
                    return trim($first_name . ' ' . $last_name) ?: $user->display_name;
                }
            }
        } elseif ($role === 'guest') {
            $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);

            if ($user_id) {
                // Look up by guest ID
                $guest = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT first_name, last_name FROM $guests_table WHERE id = %d LIMIT 1",
                        $user_id
                    )
                );
            } elseif ($phone) {
                // Fallback: look up by phone number
                $guest = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT first_name, last_name FROM $guests_table WHERE phone_number = %s LIMIT 1",
                        $phone
                    )
                );
            }

            if (!empty($guest)) {
                return trim($guest->first_name . ' ' . $guest->last_name);
            }
        }

        // Fallbacks
        if ($phone) {
            return $phone;
        }

        return ucfirst($role);
    }

    /**
     * Delete SMS log
     */
    private function delete_sms_log(int $log_id): bool
    {
        global $wpdb;
        
        $table_name = VMS_Config::get_table_name(VMS_Config::SMS_LOGS_TABLE);
        
        return (bool)$wpdb->delete(
            $table_name,
            ['id' => $log_id],
            ['%d']
        );
    }    

    /**
     * Get human-readable SMS status text
     */
    private function get_status_text(string $status): string
    {
        $statuses = [
            'Delivrd' => 'Delivered',
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
        echo '<p>' . esc_html__('Configure SMS Leopard API settings to enable SMS notifications.', 'vms-plugin') . '</p>';
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
    <?php esc_html_e('Enter SMS Leopard API key.', 'vms-plugin'); ?>
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
    <?php esc_html_e('Enter SMS Leopard API secret.', 'vms-plugin'); ?>
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
    <?php esc_html_e('SMS sender ID. Use "SMS_TEST" for testing.', 'vms-plugin'); ?>
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
    <?php esc_html_e('Optional callback URL for delivery reports.', 'vms-plugin'); ?>
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
    <?php esc_html_e('Secret for callback verification (required if status URL is provided).', 'vms-plugin'); ?>
</p>
<?php
    }
}