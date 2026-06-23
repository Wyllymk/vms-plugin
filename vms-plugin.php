<?php
/**
 * Plugin Name:       Visitor Management System Original
 * Plugin URI:        https://github.com/Wyllymk/vms-plugin
 * Description:       Complete visitor management system for clubs & organizations. Track guests, enforce visit limits, manage suppliers, reciprocating clubs with SMS/Email notifications and comprehensive audit trails.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            WyllyMk
 * Author URI:        https://wilsondevops.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vms-plugin
 * Domain Path:       /languages
 *
 * @package WyllyMk\VMS
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Plugin Constants
// ---------------------------------------------------------------------------

if ( ! defined( 'VMS_PLUGIN_VERSION' ) ) {
	define( 'VMS_PLUGIN_VERSION', '2.0.0' );
}

if ( ! defined( 'VMS_PLUGIN_FILE' ) ) {
	define( 'VMS_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'VMS_PLUGIN_DIR' ) ) {
	define( 'VMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'VMS_PLUGIN_URL' ) ) {
	define( 'VMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'VMS_PLUGIN_BASENAME' ) ) {
	define( 'VMS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'VMS_MIN_PHP' ) ) {
	define( 'VMS_MIN_PHP', '8.0' );
}

if ( ! defined( 'VMS_MIN_WP' ) ) {
	define( 'VMS_MIN_WP', '6.4' );
}

// ---------------------------------------------------------------------------
// Compatibility Check
// ---------------------------------------------------------------------------

/**
 * Verify PHP & WordPress version requirements before loading.
 *
 * @return bool True if environment is compatible.
 */
function vms_plugin_is_compatible(): bool {
	if ( version_compare( PHP_VERSION, VMS_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: required PHP version, 2: current PHP version */
							__( 'VMS Plugin requires PHP %1$s or higher. You are running %2$s.', 'vms-plugin' ),
							VMS_MIN_PHP,
							PHP_VERSION
						)
					)
				);
			}
		);
		return false;
	}

	if ( version_compare( get_bloginfo( 'version' ), VMS_MIN_WP, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: required WP version, 2: current WP version */
							__( 'VMS Plugin requires WordPress %1$s or higher. You are running %2$s.', 'vms-plugin' ),
							VMS_MIN_WP,
							get_bloginfo( 'version' )
						)
					)
				);
			}
		);
		return false;
	}

	return true;
}

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------

$vms_autoload = VMS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $vms_autoload ) ) {
	require_once $vms_autoload;
}

// Fallback PSR-4 autoloader for development (when vendor not installed).
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WyllyMk\\VMS\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );

		// Convert CamelCase to kebab-case filename: VMS_Guests -> class-vms-guests.php.
		$class_lower = strtolower( str_replace( '_', '-', $class ) );

		// Support both classes and traits. For traits named like Base_Ajax_Trait
		// the conventional file is trait-base-ajax.php (suffix stripped), so
		// probe that form as well as the literal kebab-case form.
		$filenames = array(
			'class-' . $class_lower . '.php',
			'trait-' . $class_lower . '.php',
		);

		if ( str_ends_with( $class_lower, '-trait' ) ) {
			$stripped = substr( $class_lower, 0, -6 ); // drop trailing "-trait".
			$filenames[] = 'trait-' . $stripped . '.php';
		}

		$search_dirs = array(
			'abstract',
			'core',
			'security',
			'modules',
			'admin',
			'integrations',
			'integrations/sms-providers',
		);

		foreach ( $filenames as $filename ) {
			foreach ( $search_dirs as $dir ) {
				$path = VMS_PLUGIN_DIR . $dir . '/' . $filename;
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	}
);

// ---------------------------------------------------------------------------
// Lifecycle Hooks
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! vms_plugin_is_compatible() ) {
			deactivate_plugins( VMS_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'VMS Plugin cannot be activated: environment requirements not met.', 'vms-plugin' ),
				esc_html__( 'Plugin Activation Error', 'vms-plugin' ),
				array( 'back_link' => true )
			);
		}
		\WyllyMk\VMS\VMS_Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		\WyllyMk\VMS\VMS_Deactivator::deactivate();
	}
);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action(
	'plugins_loaded',
	static function () {
		if ( ! vms_plugin_is_compatible() ) {
			return;
		}

		// Load text domain for i18n.
		load_plugin_textdomain(
			'vms-plugin',
			false,
			dirname( VMS_PLUGIN_BASENAME ) . '/languages'
		);

		// Boot the plugin.
		\WyllyMk\VMS\Loader::instance()->boot();
	},
	5
);

// ---------------------------------------------------------------------------
// Update Checker (GitHub releases)
// ---------------------------------------------------------------------------

add_action(
	'init',
	static function () {
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/Wyllymk/vms-plugin/',
			__FILE__,
			'vms-plugin'
		);

		$update_checker->setBranch( 'main' );
		$update_checker->getVcsApi()->enableReleaseAssets();
	}
);
