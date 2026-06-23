<?php
/**
 * Cron job scheduler.
 *
 * Registers custom intervals and schedules all background tasks.
 * Actual task callbacks live in their respective modules — this
 * class only manages scheduling.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Cron manager.
 */
final class VMS_Cron extends Singleton {

	/**
	 * Job schedule definitions.
	 *
	 * @var array<string, array{recurrence: string, first_run: string}>
	 */
	private const JOBS = array(
		VMS_Config::CRON_MIDNIGHT_TASKS => array(
			'recurrence' => 'daily',
			'first_run'  => 'tomorrow 00:01:00',
		),
		VMS_Config::CRON_AUTO_SIGNOUT   => array(
			'recurrence' => 'daily',
			'first_run'  => 'tomorrow 00:00:00',
		),
		VMS_Config::CRON_MONTHLY_RESET  => array(
			'recurrence' => 'vms_monthly',
			'first_run'  => 'first day of next month 00:05:00',
		),
		VMS_Config::CRON_YEARLY_RESET   => array(
			'recurrence' => 'vms_yearly',
			'first_run'  => 'first day of january next year 00:10:00',
		),
		VMS_Config::CRON_SMS_BALANCE    => array(
			'recurrence' => 'twicedaily',
			'first_run'  => '+1 hour',
		),
		VMS_Config::CRON_SMS_DELIVERY   => array(
			'recurrence' => 'vms_every_15_min',
			'first_run'  => '+15 minutes',
		),
		VMS_Config::CRON_CLEANUP_LOGS   => array(
			'recurrence' => 'weekly',
			'first_run'  => 'next sunday 03:00:00',
		),
	);

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Register custom intervals.
		add_filter( 'cron_schedules', array( $this, 'register_intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval

		// Wire the midnight master task.
		add_action( VMS_Config::CRON_MIDNIGHT_TASKS, array( $this, 'run_midnight_tasks' ) );

		// Self-heal missing schedules. Activation schedules everything but
		// if the cron array was manually cleared (or the site was migrated
		// without preserving wp_options) jobs silently disappear. Rechecking
		// on admin_init is cheap (7 wp_next_scheduled calls) and guarantees
		// the admin dashboard never shows "Not scheduled" indefinitely.
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );

		// AJAX handler for the "Reschedule All" button in VMS Admin.
		add_action( 'wp_ajax_vms_reschedule_cron', array( $this, 'ajax_reschedule' ) );
	}

	/**
	 * Register custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_intervals( array $schedules ): array {
		$schedules['vms_every_15_min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes (VMS)', 'vms-plugin' ),
		);

		$schedules['vms_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly (VMS)', 'vms-plugin' ),
		);

		$schedules['vms_yearly'] = array(
			'interval' => YEAR_IN_SECONDS,
			'display'  => __( 'Yearly (VMS)', 'vms-plugin' ),
		);

		return $schedules;
	}

	/**
	 * Schedule all cron jobs. Idempotent — skips already-scheduled jobs.
	 *
	 * @return void
	 */
	public static function schedule_all(): void {
		// Custom intervals must be registered before wp_schedule_event()
		// validates them. During activation this class hasn't been
		// instantiated yet so the cron_schedules filter isn't hooked —
		// register them inline here as a belt-and-braces measure.
		add_filter( 'cron_schedules', array( self::instance(), 'register_intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval

		foreach ( self::JOBS as $hook => $config ) {
			if ( wp_next_scheduled( $hook ) ) {
				continue;
			}

			$timestamp = strtotime( $config['first_run'] );
			if ( false === $timestamp ) {
				$timestamp = time() + HOUR_IN_SECONDS;
			}

			wp_schedule_event( $timestamp, $config['recurrence'], $hook );
		}
	}

	/**
	 * Reschedule any missing jobs. Safe to call repeatedly.
	 *
	 * Runs on admin_init so the admin dashboard always reflects a healthy
	 * cron state without requiring deactivate/reactivate.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		foreach ( self::JOBS as $hook => $config ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				self::schedule_all();
				return; // schedule_all handles everything.
			}
		}
	}

	/**
	 * AJAX: Force-reschedule all cron jobs.
	 *
	 * @return void
	 */
	public function ajax_reschedule(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vms-plugin' ) ), 403 );
		}

		check_ajax_referer( 'vms_admin_nonce', 'nonce' );

		self::unschedule_all();
		self::schedule_all();

		wp_send_json_success(
			array(
				'message' => __( 'All cron jobs rescheduled.', 'vms-plugin' ),
				'status'  => self::get_job_status(),
			)
		);
	}

	/**
	 * Unschedule all cron jobs.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		foreach ( VMS_Config::get_all_cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Master midnight task dispatcher.
	 *
	 * Fires sub-actions so modules can hook in without this class
	 * needing to know about them.
	 *
	 * @return void
	 */
	public function run_midnight_tasks(): void {
		/**
		 * Fires during the nightly maintenance window.
		 *
		 * Modules should hook this to:
		 * - Update stale visit statuses
		 * - Recalculate limits
		 * - Clean up temporary data
		 *
		 * @since 2.0.0
		 */
		do_action( 'vms_midnight_maintenance' );

		// Bust stats caches so dashboards show fresh data in the morning.
		VMS_Cache::bust( 'stats' );
		VMS_Cache::bust( 'reports' );

		VMS_Audit_Trail::log( 'midnight_tasks_completed', VMS_Audit_Trail::CAT_SYSTEM );
	}

	/**
	 * Get status of all scheduled jobs (for admin UI).
	 *
	 * @return array<string, array{scheduled: bool, next_run: ?string, recurrence: string}>
	 */
	public static function get_job_status(): array {
		$status = array();

		foreach ( self::JOBS as $hook => $config ) {
			$next = wp_next_scheduled( $hook );

			$status[ $hook ] = array(
				'scheduled'  => (bool) $next,
				'next_run'   => $next ? wp_date( 'Y-m-d H:i:s', $next ) : null,
				'recurrence' => $config['recurrence'],
			);
		}

		return $status;
	}
}
