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

        // Handle delete action
        if (isset($_POST['delete_log']) && isset($_POST['log_id']) && wp_verify_nonce($_POST['_wpnonce'], 'delete_sms_log')) {
            $this->delete_sms_log((int)$_POST['log_id']);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('SMS log deleted successfully.', 'vms') . '</p></div>';
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
    <h1><?php esc_html_e('SMS Logs', 'vms'); ?></h1>

    <!-- Summary Info -->
    <div class="logs-summary">
        <p><strong><?php esc_html_e('Total SMS Logs:', 'vms'); ?></strong> <?php echo number_format($total_logs); ?></p>
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
                <th class="column-number"><?php esc_html_e('#', 'vms'); ?></th>
                <th class="column-user"><?php esc_html_e('Recipient Name', 'vms'); ?></th>
                <th class="column-phone"><?php esc_html_e('Phone', 'vms'); ?></th>
                <th class="column-role"><?php esc_html_e('Role', 'vms'); ?></th>
                <th class="column-message"><?php esc_html_e('Message', 'vms'); ?></th>
                <th class="column-status"><?php esc_html_e('Status', 'vms'); ?></th>
                <th class="column-cost"><?php esc_html_e('Cost', 'vms'); ?></th>
                <th class="column-date"><?php esc_html_e('Date', 'vms'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'vms'); ?></th>
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
                        <?php echo esc_html($log['message_preview']); ?>
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
                    <form method="post" style="display:inline;"
                        onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this SMS log?', 'vms'); ?>');">
                        <?php wp_nonce_field('delete_sms_log'); ?>
                        <input type="hidden" name="log_id" value="<?php echo esc_attr($log['id']); ?>">
                        <button type="submit" name="delete_log" class="button-link delete-button"
                            title="<?php esc_attr_e('Delete Log', 'vms'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="9" class="no-logs"><?php esc_html_e('No SMS logs found.', 'vms'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav">
        <div class="tablenav-pages">
            <span
                class="displaying-num"><?php printf(_n('%s item', '%s items', $total_logs, 'vms'), number_format_i18n($total_logs)); ?></span>
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
        width: 60px;
        text-align: center;
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
    function toggleMessage(element) {
        var messageDiv = element.parentNode.parentNode;
        var preview = element.getAttribute('data-preview');
        var full = element.getAttribute('data-full');
        var currentText = messageDiv.querySelector('.message-preview').firstChild.textContent;

        if (currentText === preview) {
            messageDiv.querySelector('.message-preview').firstChild.textContent = full;
            element.textContent = 'Show less';
        } else {
            messageDiv.querySelector('.message-preview').firstChild.textContent = preview;
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
            
            // Get recipient name based on role
            $log['recipient_name'] = $this->get_recipient_name($log['recipient_number'], $log['recipient_role']);
            
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
     * Get recipient name based on phone number and role
     */
    private function get_recipient_name(string $phone, string $role): string
    {
        global $wpdb;
        
        if (in_array($role, ['member', 'chairman', 'admin'])) {
            // Search in WordPress users
            $user_query = new \WP_User_Query([
                'meta_key' => 'phone_number',
                'meta_value' => $phone,
                'number' => 1
            ]);
            
            if (!empty($user_query->results)) {
                $user = $user_query->results[0];
                return $user->first_name . ' ' . $user->last_name;
            }
        } elseif ($role === 'guest') {
            // Search in guests table
            $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
            $guest = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM $guests_table WHERE phone_number = %s LIMIT 1",
                $phone
            ));
            
            if ($guest) {
                return $guest->first_name . ' ' . $guest->last_name;
            }
        }
        
        // Fallback to phone number if name not found
        return $phone;
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