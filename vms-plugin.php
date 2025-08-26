<?php
/**
 * Plugin Name: Visitor Management System Plugin
 * Plugin URI: https://github.com/Wyllymk/vms-plugin
 * Description: Integrate VMS Functionalities
 * Version: 1.0.0
 * Author: Wyllymk
 * Author URI: https://wilsondevops.com
 * Text Domain: VMS
 * License: GNU General Public License v2 or later
 * License URI: LICENSE
 * Requires PHP: 7.4
 * Requires at least: 6.8
 */

namespace WyllyMk\VMS;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VMS_PLUGIN_VERSION', '1.0.0');
define('VMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VMS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load Composer Autoload
require_once VMS_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Main plugin class
 */
final class VMS_Plugin
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Private constructor to prevent direct instantiation
    }

    /**
     * Initialize the plugin
     */
    public function init(): void
    {
        // Load text domain for translations
        load_plugin_textdomain(
            'vms',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        // Initialize all components
        $this->initialize_components();
    }

    /**
     * Initialize all plugin components
     */
    private function initialize_components(): void
    {
        // Initialize classes
        VMS_Admin::get_instance()->init();
        VMS_CoreManager::get_instance()->init();
        VMS_NotificationManager::get_instance()->init();
        VMS_FormHandler::get_instance()->init();
        VMS_CPTS::get_instance()->init();
        VMS_Roles::get_instance()->init();
        VMS_RestApiManager::get_instance()->init();
        VMS_Database::get_instance()->init();
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    VMS_Plugin::get_instance()->init();
});

// Register activation and deactivation hooks
register_activation_hook(
    __FILE__, 
    [VMS_Activation::class, 'activate']
);
register_deactivation_hook(
    __FILE__, 
    [VMS_Activation::class, 'deactivate']
);
register_uninstall_hook(
    __FILE__, 
    [VMS_Activation::class, 'uninstall']
);