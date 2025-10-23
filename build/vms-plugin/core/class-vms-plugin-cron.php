<?php
/**
 * Cron Manager - Handles all scheduled events for the VMS Plugin.
 *
 * Provides:
 * - Per-cron lifecycle logging
 * - Custom recurring intervals (monthly, yearly)
 * - Auto-scheduling during activation
 * - Cleanup during deactivation
 *
 * @package WyllyMk\VMS
 * @since 1.0.2
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit;
}

class VMS_Cron_Manager
{
    /**
     * Define all plugin cron hooks and their recurrence.
     *
     * @var array<string, string>
     */
    private static array $cron_jobs = [
        'cleanup_old_sms_logs'                                  => 'yearly',
        'check_sms_delivery_status'                             => 'hourly',
        'sms_balance_cron'                                      => 'hourly',
        'auto_update_visit_status_at_midnight'                  => 'daily',
        'auto_sign_out_accommodation_guests_at_midnight'        => 'daily',
        'auto_sign_out_guests_at_midnight'                      => 'daily',
        'auto_sign_out_suppliers_at_midnight'                      => 'daily',
        'auto_sign_out_recip_members_at_midnight'               => 'daily',
        'reset_monthly_guest_limits'                            => 'monthly',
        'reset_yearly_guest_limits'                             => 'yearly',
    ];

    /**
     * Activate all cron jobs (called on plugin activation).
     * Ensures custom intervals are available before scheduling.
     *
     * @return void
     */
    public static function activate_all_jobs(): void
    {        
        // 1️⃣ Schedule each defined job.
        foreach (self::$cron_jobs as $hook => $interval) {
            self::schedule_event($hook, $interval);
        }

        self::log_event('✅ All cron jobs activated successfully.');
    }

    /**
     * Schedule a single cron event safely.
     * If an event exists but is non-repeating, it’s rescheduled correctly.
     *
     * @param string $hook Cron hook name.
     * @param string $interval WP-recognized recurrence key.
     * @return void
     */
    private static function schedule_event(string $hook, string $interval): void
    {
        $schedules = wp_get_schedules();

        if (!isset($schedules[$interval])) {
            self::log_event("❌ Interval '{$interval}' not found. Skipping '{$hook}'.");
            return;
        }

        $next = wp_next_scheduled($hook);

        // Check if the existing event is non-repeating and reschedule if needed
        if ($next) {
            $existing = _get_cron_array();
            foreach ($existing as $timestamp => $crons) {
                if (isset($crons[$hook])) {
                    $recurrence = key($crons[$hook]);
                    if ($recurrence === false) {
                        wp_clear_scheduled_hook($hook);
                        self::log_event("♻️ Rescheduling non-repeating cron '{$hook}' as recurring ({$interval}).");
                        break;
                    }
                }
            }
        }

        if (!wp_next_scheduled($hook)) {
            $time = self::get_schedule_start_time($hook);
            if (wp_schedule_event($time, $interval, $hook)) {
                self::log_event("🕑 Scheduled cron '{$hook}' ({$interval}) at " . gmdate('Y-m-d H:i:s', $time) . ' UTC.');
            } else {
                self::log_event("❌ Failed to schedule cron '{$hook}' ({$interval}).");
            }
        } else {
            self::log_event("⚠️ Cron '{$hook}' already scheduled.");
        }

        // Attach runtime logging when the cron runs
        add_action($hook, fn() => self::handle_cron_run($hook));
    }

    /**
     * Determine the first scheduled run time for each cron job.
     *
     * @param string $hook Cron hook.
     * @return int Timestamp for first run.
     */
    private static function get_schedule_start_time(string $hook): int
    {
        $now = current_time('timestamp');

        return match ($hook) {
            'auto_update_visit_status_at_midnight',
            'auto_sign_out_accommodation_guests_at_midnight',
            'auto_sign_out_guests_at_midnight', 
            'auto_sign_out_suppliers_at_midnight',
            'auto_sign_out_recip_members_at_midnight' =>
                strtotime('tomorrow midnight', $now),

            'reset_monthly_guest_limits' =>
                strtotime('first day of next month midnight', $now),

            'reset_yearly_guest_limits',
            'cleanup_old_sms_logs' =>
                strtotime('first day of January next year midnight', $now),

            default => $now,
        };
    }

    /**
     * Handle the actual runtime of a cron.
     * Logs start, completion, and error if any.
     *
     * @param string $hook Cron hook name.
     * @return void
     */
    public static function handle_cron_run(string $hook): void
    {
        $start_time = microtime(true);
        self::log_event("🚀 Cron '{$hook}' started at " . current_time('mysql'));

        try {
            // Allow other parts of the plugin to hook into execution.
            do_action("vms_execute_{$hook}");

            $duration = round(microtime(true) - $start_time, 3);
            self::log_event("✅ Cron '{$hook}' completed successfully in {$duration}s.");
        } catch (\Throwable $e) {
            self::log_event("❌ ERROR in cron '{$hook}': " . $e->getMessage());
        }
    }

    /**
     * Deactivate all scheduled cron events (called on plugin deactivation).
     *
     * @return void
     */
    public static function deactivate_all_jobs(): void
    {
        foreach (self::$cron_jobs as $hook => $_interval) {
            $cleared = wp_clear_scheduled_hook($hook);

            if ($cleared) {
                self::log_event("🧹 Cleared scheduled cron '{$hook}'.");
            } elseif (wp_next_scheduled($hook) === false) {
                self::log_event("⚠️ Cron '{$hook}' not found or already cleared.");
            } else {
                self::log_event("⚠️ Unexpected issue clearing cron '{$hook}'.");
            }
        }

        self::log_event('🛑 All cron jobs deactivated.');
    }

    /**
     * Attach runtime handlers for all cron jobs on plugin load.
     *
     * @return void
     */
    public static function init_runtime_logging(): void
    {
        foreach (array_keys(self::$cron_jobs) as $hook) {
            add_action($hook, fn() => self::handle_cron_run($hook));
        }
    }

    /**
     * Log a message to the PHP error log with consistent tagging.
     *
     * @param string $message Log message.
     * @return void
     */
    private static function log_event(string $message): void
    {
        error_log('[VMS CRON] ' . sanitize_text_field($message));
    }
}