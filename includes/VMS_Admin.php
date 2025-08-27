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
        add_filter('plugin_action_links_' . VMS_PLUGIN_BASENAME, [$this, 'add_settings_link']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('wp_ajax_test_sms_connection', [$this, 'test_sms_connection']);
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
     * Show admin notices
     */
    public function show_admin_notices(): void
    {
        $screen = get_current_screen();
        if ($screen->id !== 'toplevel_page_vms-settings') {
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
        <h2 class="hndle"><span><?php esc_html_e('SMS Balance', 'vms'); ?></span></h2>
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

        global $wpdb;
        $table_name = $wpdb->prefix . 'vms_sms_logs';
        
        // Get logs with pagination
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));

        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_logs / $per_page);
        ?>
<div class="wrap">
    <h1><?php esc_html_e('SMS Logs', 'vms'); ?></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('ID', 'vms'); ?></th>
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
                <td><?php echo esc_html($log->id); ?></td>
                <td><?php echo esc_html($log->recipient_number); ?></td>
                <td><?php echo esc_html(wp_trim_words($log->message, 10)); ?></td>
                <td>
                    <span class="status-<?php echo esc_attr(strtolower($log->status)); ?>">
                        <?php echo esc_html($log->status); ?>
                    </span>
                </td>
                <td>KES <?php echo esc_html(number_format($log->cost, 2)); ?></td>
                <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log->created_at))); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
                <td colspan="6"><?php esc_html_e('No SMS logs found.', 'vms'); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ]);
                        ?>
        </div>
    </div>
    <?php endif; ?>

    <style>
    .status-sent {
        color: #46b450;
        font-weight: bold;
    }

    .status-failed {
        color: #dc3232;
        font-weight: bold;
    }

    .status-queued {
        color: #ffb900;
        font-weight: bold;
    }
    </style>
</div>
<?php
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
}