<?php
/**
 * Main plugin loader / bootstrap.
 *
 * Instantiates and wires all plugin components in the correct order.
 * Respects module enable/disable settings so site owners can selectively
 * turn features on or off.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin loader.
 */
final class Loader extends Singleton {

	/**
	 * Track whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Boot the plugin: load all components.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Check for database upgrades on every load (cheap version comparison).
		VMS_Database_Manager::maybe_upgrade();

		// --- Always-on core components -----------------------------------
		VMS_Security::instance();
		VMS_Cache::instance();
		VMS_Audit_Trail::instance();
		VMS_Cron::instance();
		VMS_Rewrite::instance();
		VMS_Notifications::instance();
		VMS_Auth::instance();
		VMS_Module_Builder::instance();
		VMS_Export::instance();

		// --- Admin-only components ---------------------------------------
		if ( is_admin() ) {
			VMS_Admin::instance();
			VMS_Settings::instance();
		}

		// --- Optional modules (respect settings toggles) -----------------
		$this->load_modules();

		/**
		 * Fires after all VMS components have booted.
		 *
		 * @since 2.0.0
		 */
		do_action( 'vms_loaded' );
	}

	/**
	 * Load feature modules based on settings.
	 *
	 * @return void
	 */
	private function load_modules(): void {
		$modules = array(
			'guests'        => VMS_Guests::class,
			'accommodation' => VMS_Accommodation::class,
			'suppliers'     => VMS_Suppliers::class,
			'reciprocation' => VMS_Reciprocation::class,
			'employees'     => VMS_Employees::class,
			'reports'       => VMS_Reports::class,
			'members'       => VMS_Members::class,
		);

		foreach ( $modules as $key => $class ) {
			if ( ! VMS_Settings::is_module_enabled( $key ) ) {
				continue;
			}

			if ( class_exists( $class ) ) {
				$class::instance();
			}
		}

		/**
		 * Fires after optional modules have been loaded.
		 * Allows 3rd-party extensions to hook in.
		 *
		 * @since 2.0.0
		 * @param array $modules Map of module key => class name.
		 */
		do_action( 'vms_modules_loaded', $modules );
	}
}
