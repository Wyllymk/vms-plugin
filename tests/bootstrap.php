<?php
/**
 * PHPUnit bootstrap file for the VMS Plugin test suite.
 *
 * @package WyllyMk\VMS
 */

// Define testing constant so Singleton::reset_all_instances() works.
define( 'VMS_TESTING', true );

// Determine the WordPress test suite directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Ensure the WP test suite is available.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Have you run bin/install-wp-tests.sh?" . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	// Define plugin constants if not already defined.
	if ( ! defined( 'VMS_PLUGIN_FILE' ) ) {
		define( 'VMS_PLUGIN_FILE', dirname( __DIR__ ) . '/vms-plugin.php' );
	}

	if ( ! defined( 'VMS_PLUGIN_DIR' ) ) {
		define( 'VMS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}

	if ( ! defined( 'VMS_PLUGIN_URL' ) ) {
		define( 'VMS_PLUGIN_URL', 'http://example.org/wp-content/plugins/vms-plugin/' );
	}

	if ( ! defined( 'VMS_PLUGIN_BASENAME' ) ) {
		define( 'VMS_PLUGIN_BASENAME', 'vms-plugin/vms-plugin.php' );
	}

	if ( ! defined( 'VMS_PLUGIN_VERSION' ) ) {
		define( 'VMS_PLUGIN_VERSION', '2.0.0' );
	}

	if ( ! defined( 'VMS_MIN_PHP' ) ) {
		define( 'VMS_MIN_PHP', '8.0' );
	}

	if ( ! defined( 'VMS_MIN_WP' ) ) {
		define( 'VMS_MIN_WP', '6.4' );
	}

	// Load the plugin main file.
	require dirname( __DIR__ ) . '/vms-plugin.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
