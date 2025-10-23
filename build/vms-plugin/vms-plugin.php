<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://wilsondevops.com
 * @since             1.0.0
 * @package           VMS_PLUGIN
 *
 * @wordpress-plugin
 * Plugin Name:       Visitor Management System Plugin
 * Plugin URI:        https://github.com/Wyllymk/vms-plugin
 * Description:       Integrate VMS Functionalities
 * Version:           1.0.0
 * Author:            Wyllymk
 * Author URI:        https://wilsondevops.com
 * Requires PHP:      7.4
 * Requires at least: 6.8
 * License:           GNU General Public License v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       vms-plugin
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define('VMS_PLUGIN_VERSION', '1.0.4');

/**
 * Plugin directory path.
 * Use this constant to reference files within the plugin directory.
 */
define('VMS_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Plugin directory URL.
 * Use this constant to reference assets (CSS, JS, images) within the plugin.
 */
define('VMS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Plugin basename.
 * Used for activation, deactivation, and uninstall hooks.
 */
define('VMS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Load Composer autoloader.
 * This allows automatic loading of all classes following PSR-4 standards.
 */
require_once VMS_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Main plugin class.
 * 
 * This class implements the singleton pattern to ensure only one instance
 * of the plugin runs at any given time. This prevents conflicts and
 * duplicate initializations.
 *
 * @since 1.0.0
 */
final class VMS_Plugin
{
    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var VMS_Plugin|null
     */
    private static $instance = null;

    /**
     * Flag to track if plugin has been loaded.
     * Prevents multiple initializations during the same request.
     *
     * @since 1.0.0
     * @var bool
     */
    private $loaded = false;

    /**
     * Get singleton instance.
     * 
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance. This ensures only one instance runs.
     *
     * @since 1.0.0
     * @return VMS_Plugin The singleton instance.
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * 
     * This is part of the singleton pattern. Use get_instance() instead.
     *
     * @since 1.0.0
     */
    private function __construct()
    {
        // Private constructor to prevent direct instantiation.
    }

    /**
     * Class initializer.
     * 
     * Loads the plugin text domain for internationalization and
     * initializes the main loader class. The loaded flag prevents
     * this method from running more than once per request.
     *
     * @since 1.0.0
     * @return void
     */
    public function load()
    {
        // Prevent multiple loads during the same request.
        if ($this->loaded) {
            return;
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
                VMS_SMS::get_instance()->handle_sms_delivery_callback();
                exit;
            }
        });

        // Load plugin translations.
        load_plugin_textdomain(
            'vms-plugin',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        // Initialize the main loader class.
        WyllyMk\VMS\Loader::instance();

        // Mark plugin as loaded.
        $this->loaded = true;
    }
}

/**
 * Plugin activation hook callback.
 * 
 * Runs when the plugin is activated. Handles:
 * - Version compatibility checks
 * - Database table creation
 * - Default options setup
 * - Rewrite rules flush
 *
 * @since 1.0.0
 * @return void
 */
function vms_plugin_activate()
{
    require_once VMS_PLUGIN_DIR . 'core/class-vms-plugin-activator.php';
    WyllyMk\VMS\VMS_Plugin_Activator::activate();
}

/**
 * Plugin deactivation hook callback.
 * 
 * Runs when the plugin is deactivated. Handles:
 * - Clearing scheduled events
 * - Flushing rewrite rules
 * - Cleanup tasks
 * 
 * Note: This does NOT delete data. Use uninstall.php for that.
 *
 * @since 1.0.0
 * @return void
 */
function vms_plugin_deactivate()
{
    require_once VMS_PLUGIN_DIR . 'core/class-vms-plugin-deactivator.php';
    WyllyMk\VMS\VMS_Plugin_Deactivator::deactivate();
}

/**
 * Register activation hook.
 * Runs the activation function when plugin is activated via admin panel.
 */
register_activation_hook(__FILE__, 'vms_plugin_activate');

/**
 * Register deactivation hook.
 * Runs the deactivation function when plugin is deactivated via admin panel.
 */
register_deactivation_hook(__FILE__, 'vms_plugin_deactivate');

/**
 * Begin execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * kicking off the plugin from this point in the file does not
 * affect the page life cycle. We use plugins_loaded to ensure
 * WordPress core is fully loaded before initializing.
 *
 * Priority 10 is the default, ensuring we load after WordPress core
 * but before most other plugin functionality.
 *
 * @since 1.0.0
 */
add_action('plugins_loaded', function () {
    VMS_Plugin::get_instance()->load();
}, 10);