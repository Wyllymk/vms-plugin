<?php
/**
 * Plugin deactivation handler.
 *
 * Cleans up transient state (cron jobs, rewrite rules, caches)
 * WITHOUT touching persistent data. Full data removal happens
 * in uninstall.php only.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivator.
 */
final class VMS_Deactivator {

	/**
	 * Run deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clear all scheduled cron jobs.
		VMS_Cron::unschedule_all();

		// Flush rewrite rules to remove custom endpoints.
		flush_rewrite_rules();

		// Clear all VMS transients & object cache entries.
		if ( class_exists( VMS_Cache::class ) ) {
			VMS_Cache::instance()->flush_all();
		}

		// Record deactivation timestamp.
		update_option( 'vms_deactivated_at', current_time( 'mysql' ), false );

		/**
		 * Fires after VMS deactivation completes.
		 *
		 * @since 2.0.0
		 */
		do_action( 'vms_deactivated' );
	}
}
