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
    private $settings_group = 'cyber_wakili_settings';

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
            esc_url(admin_url('admin.php?page=cyber-wakili-settings')),
            esc_html__('Settings', 'cyber-wakili-plugin')
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
            __('Cyber Wakili', 'cyber-wakili-plugin'),
            __('Cyber Wakili', 'cyber-wakili-plugin'),
            'manage_options',
            'cyber-wakili-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic',
            80
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings(): void
    {
        register_setting(
            $this->settings_group,
            'cyber_wakili_auth_code',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        register_setting(
            $this->settings_group,
            'cyber_wakili_redirect_uri',
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => ''
            ]
        );

        add_settings_section(
            'cyber_wakili_main_section',
            __('API Configuration', 'cyber-wakili-plugin'),
            [$this, 'render_section_description'],
            $this->settings_group
        );

        add_settings_field(
            'cyber_wakili_auth_code',
            __('Auth Code', 'cyber-wakili-plugin'),
            [$this, 'render_auth_code_field'],
            $this->settings_group,
            'cyber_wakili_main_section'
        );

        add_settings_field(
            'cyber_wakili_redirect_uri',
            __('Redirect URI', 'cyber-wakili-plugin'),
            [$this, 'render_redirect_uri_field'],
            $this->settings_group,
            'cyber_wakili_main_section'
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'cyber-wakili-plugin'),
                __('Access Denied', 'cyber-wakili-plugin'),
                ['response' => 403]
            );
        }
        ?>
<div class="wrap">
    <h1><?php esc_html_e('Cyber Wakili Integration Settings', 'cyber-wakili-plugin'); ?></h1>
    <form method="post" action="options.php">
        <?php
                settings_fields($this->settings_group);
                do_settings_sections($this->settings_group);
                submit_button();
                ?>
    </form>
</div>
<?php
    }

    /**
     * Render section description
     */
    public function render_section_description(): void
    {
        echo '<p>' . esc_html__('Configure your Cyber Wakili API settings below.', 'cyber-wakili-plugin') . '</p>';
    }

    /**
     * Render auth code field
     */
    public function render_auth_code_field(): void
    {
        $value = get_option('cyber_wakili_auth_code', '');
        ?>
<input type="text" name="cyber_wakili_auth_code" id="cyber_wakili_auth_code" value="<?php echo esc_attr($value); ?>"
    class="regular-text">
<p class="description">
    <?php esc_html_e('Enter your authentication code provided by Cyber Wakili.', 'cyber-wakili-plugin'); ?>
</p>
<?php
    }

    /**
     * Render redirect URI field
     */
    public function render_redirect_uri_field(): void
    {
        $value = get_option('cyber_wakili_redirect_uri', '');
        ?>
<input type="url" name="cyber_wakili_redirect_uri" id="cyber_wakili_redirect_uri" value="<?php echo esc_url($value); ?>"
    class="regular-text">
<p class="description">
    <?php esc_html_e('Enter the redirect URI for OAuth authentication.', 'cyber-wakili-plugin'); ?>
</p>
<?php
    }
}