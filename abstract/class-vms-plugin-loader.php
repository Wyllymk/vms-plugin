<?php
/**
 * Class to boot up plugin.
 *
 * This class is responsible for initializing all plugin components,
 * checking system requirements, and loading necessary classes.
 * Uses singleton pattern to ensure single instance.
 *
 * @link       https://wilsondevops.com
 * @since      1.0.0
 *
 * @author     WyllyMK (https://wilsondevops.com)
 * @package    WyllyMk\VMS
 *
 * @copyright  (c) 2025, WilsonDevops (https://wilsondevops.com)
 */

namespace WyllyMk\VMS;

use WyllyMk\VMS\Base;

// If this file is called directly, abort.
defined('WPINC') || die;

/**
 * Loader Class.
 *
 * Main loader class that bootstraps the entire plugin.
 * Handles initialization, requirement checks, and component loading.
 *
 * @since 1.0.0
 */
final class Loader extends Base
{
    /**
     * Settings helper class instance.
     *
     * Stores the settings manager instance for plugin configuration.
     *
     * @since 1.0.0
     * @var object
     */
    public $settings;

    /**
     * Minimum supported PHP version.
     *
     * The plugin requires at least this PHP version to function.
     * Checked during initialization to prevent errors.
     *
     * @since 1.0.0
     * @var string
     */
    public $php_version = '7.4';

    /**
     * Minimum WordPress version.
     *
     * The plugin requires at least this WordPress version.
     * Ensures compatibility with WordPress core features.
     *
     * @since 1.0.0
     * @var string
     */
    public $wp_version = '6.1';

    /**
     * Flag to track initialization status.
     *
     * Prevents multiple initializations during the same request.
     * Set to true after first successful init() call.
     *
     * @since 1.0.0
     * @var bool
     */
    private $initialized = false;

    /**
     * Initialize functionality of the plugin.
     *
     * This is where we kick-start the plugin by defining
     * everything required and registering all hooks.
     * Protected constructor ensures singleton pattern.
     *
     * @since  1.0.0
     * @access protected
     * @return void
     */
    protected function __construct()
    {
        // Check if system meets minimum requirements.
        if (!$this->can_boot()) {
            // Log warning and exit if requirements not met.
            error_log('VMS Plugin cannot boot: System requirements not met');
            return;
        }

        // Initialize all plugin components.
        $this->init();
    }

    /**
     * Main condition that checks if plugin parts should continue loading.
     *
     * Validates that the server environment meets minimum requirements:
     * - PHP version compatibility
     * - WordPress version compatibility
     *
     * @since  1.0.0
     * @access private
     * @return bool True if requirements met, false otherwise.
     */
    private function can_boot()
    {
        global $wp_version;

        /**
         * Check PHP version.
         * Must be greater than or equal to minimum required version.
         */
        $php_compatible = version_compare(PHP_VERSION, $this->php_version, '>=');

        /**
         * Check WordPress version.
         * Must be greater than or equal to minimum required version.
         */
        $wp_compatible = version_compare($wp_version, $this->wp_version, '>=');

        // Both checks must pass.
        return ($php_compatible && $wp_compatible);
    }

    /**
     * Register all actions and filters.
     *
     * This method loads all plugin components and registers WordPress hooks.
     * Uses initialized flag to prevent multiple executions.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function init()
    {
        // Prevent multiple initializations.
        if ($this->initialized) {
            return;
        }

        /**
         * Load core components.
         * These are essential classes needed for plugin operation.
         */
        $this->load_core_components();       

        /**
         * Initialize admin components.
         * Load admin-specific functionality if in admin area.
         */
        if (is_admin()) {
            $this->load_admin_components();
        }

        /**
         * Initialize frontend components.
         * Load public-facing functionality if not in admin.
         */
        if (!is_admin()) {
            $this->load_frontend_components();
        }

        /**
         * Load integrations.
         * Initialize third-party service integrations.
         */
        $this->load_integrations();

        // Mark as initialized.
        $this->initialized = true;        
    }

    /**
     * Load core plugin components.
     *
     * Initializes essential classes that the plugin needs to function.
     * These components are loaded regardless of admin or frontend context.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function load_core_components()
    {
        /**
         * Load guest module.
         * Handles guest functionalities.
         */
        VMS_Guest::get_instance()->init();  

        /**
         * Load accommodation module.
         * Handles accommodation functionalities.
         */
        VMS_Accommodation::get_instance()->init();  

        /**
         * Load suppliers module.
         * Handles suppliers functionalities.
         */
        VMS_Suppliers::get_instance()->init(); 
        
        /**
         * Load member module.
         * Handles member functionalities.
         */
        VMS_Member::get_instance()->init();

        /**
         * Load reciprocation module.
         * Handles reciprocation functionalities.
         */
        VMS_Reciprocation::get_instance()->init();

        /**
         * Load employee module.
         * Handles employee functionalities.
         */
        VMS_Employee::get_instance()->init();
        
        /**
         * Load club module.
         * Handles club functionalities.
         */
        VMS_Clubs::get_instance()->init(); 

        /**
         * Load core module.
         * Handles core functionalities.
         */
        VMS_Core::get_instance()->init(); 

        /**
         * Load sms module.
         * Handles sms functionalities.
         */
        VMS_SMS::get_instance()->init(); 
    }

    /**
     * Load admin-specific components.
     *
     * Initializes classes and functionality needed only in WordPress admin area.
     * This includes admin pages, settings screens, and admin-only features.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function load_admin_components()
    {
        
        /**
         * Load admin pages.
         * Dashboard, settings, and management screens.
         */
        VMS_Admin::get_instance()->init();     

    }

    /**
     * Load frontend-specific components.
     *
     * Initializes classes and functionality needed only on public-facing pages.
     * This includes visitor forms, public displays, and frontend integrations.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function load_frontend_components()
    {
        /**
         * Load visitor registration form.
         * Public form for visitor check-in/check-out.
         */
        // Frontend\Visitor_Form::instance();

        /**
         * Load visitor display.
         * Show visitor lists and information publicly.
         */
        // Frontend\Visitor_Display::instance();

        /**
         * Load QR code generator.
         * Generate QR codes for visitor badges.
         */
        // Frontend\QR_Generator::instance();

        /**
         * Load notification handler.
         * Send real-time notifications to hosts.
         */
        // Frontend\Notifications::instance();
    }

    /**
     * Load third-party integrations.
     *
     * Initializes connections to external services and platforms.
     * This includes email services, SMS providers, and other integrations.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function load_integrations()
    {
        /**
         * Load SMS integration.
         * Send SMS notifications to visitors and hosts.
         */
        // Integrations\SMS::instance();

        /**
         * Load calendar integration.
         * Sync with Google Calendar, Outlook, etc.
         */
        // Integrations\Calendar::instance();       

        /**
         * Load webhook support.
         * Send data to external URLs on events.
         */
        // Integrations\Webhooks::instance();
    }
}