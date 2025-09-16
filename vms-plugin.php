<?php
/**
 * Plugin Name: Visitor Management System Plugin
 * Plugin URI: https://github.com/Wyllymk/vms-plugin
 * Description: Integrate VMS Functionalities
 * Version: 1.0.4
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
define('VMS_PLUGIN_VERSION', '1.0.4');
define('VMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VMS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load Composer Autoload
require_once VMS_PLUGIN_DIR . 'vendor/autoload.php';

require __DIR__ . '/vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Wyllymk/vms-plugin', // GitHub repo URL
    __FILE__, // Full path to main plugin file
    'vms-plugin' // Plugin slug
);

// Optional: if using GitHub releases/tags
$myUpdateChecker->setBranch('main'); // or 'master' or whichever branch you use
// Tell PUC to use the release asset (vms.zip) instead of auto-generated zips
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

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
        VMS_Roles::get_instance()->init();
        // VMS_RestApiManager::get_instance()->init();
        // VMS_Database::get_instance()->init();
    }
}

// Add this to your main plugin file or init hook
add_action('init', function() {
    // Setup callback URL rewrite rule
    add_rewrite_rule(
        '^vms-sms-callback/?$',
        'index.php?vms_sms_callback=1',
        'top'
    );
});

add_filter('query_vars', function($vars) {
    $vars[] = 'vms_sms_callback';
    return $vars;
});

add_action('template_redirect', function() {
    if (get_query_var('vms_sms_callback')) {
        VMS_NotificationManager::get_instance()->handle_sms_delivery_callback();
        exit;
    }
});

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