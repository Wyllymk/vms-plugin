<?php
/**
 * Centralized configuration registry.
 *
 * Single source of truth for table names, option keys, status enums,
 * capability identifiers, and default settings. All components MUST
 * reference this class rather than hard-coding strings.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration class.
 */
final class VMS_Config {

	// ---------------------------------------------------------------------
	// Plugin Meta
	// ---------------------------------------------------------------------

	public const VERSION     = '2.0.0';
	public const TEXT_DOMAIN = 'vms-plugin';
	public const OPTION_PREFIX = 'vms_';

	// ---------------------------------------------------------------------
	// Database Tables (without WP prefix — use get_table_name())
	// ---------------------------------------------------------------------

	public const TABLE_GUESTS               = 'vms_guests';
	public const TABLE_GUEST_VISITS         = 'vms_guest_visits';
	public const TABLE_ACCOM_GUESTS         = 'vms_accom_guests';
	public const TABLE_ACCOM_VISITS         = 'vms_accom_visits';
	public const TABLE_SUPPLIERS            = 'vms_suppliers';
	public const TABLE_SUPPLIER_VISITS      = 'vms_supplier_visits';
	public const TABLE_RECIP_CLUBS          = 'vms_recip_clubs';
	public const TABLE_RECIP_MEMBERS        = 'vms_recip_members';
	public const TABLE_RECIP_VISITS         = 'vms_recip_visits';
	public const TABLE_EMPLOYEES            = 'vms_employees';
	public const TABLE_SMS_LOGS             = 'vms_sms_logs';
	public const TABLE_AUDIT_LOGS           = 'vms_audit_logs';

	// ---------------------------------------------------------------------
	// Guest / Member Status
	// ---------------------------------------------------------------------

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_BANNED    = 'banned';

	// ---------------------------------------------------------------------
	// Visit Status
	// ---------------------------------------------------------------------

	public const VISIT_APPROVED   = 'approved';
	public const VISIT_UNAPPROVED = 'unapproved';
	public const VISIT_CANCELLED  = 'cancelled';
	public const VISIT_SUSPENDED  = 'suspended';
	public const VISIT_BANNED     = 'banned';
	public const VISIT_COMPLETED  = 'completed';

	// ---------------------------------------------------------------------
	// SMS Delivery Status
	// ---------------------------------------------------------------------

	public const SMS_QUEUED      = 'queued';
	public const SMS_SENT        = 'sent';
	public const SMS_DELIVERED   = 'delivered';
	public const SMS_FAILED      = 'failed';
	public const SMS_EXPIRED     = 'expired';
	public const SMS_UNDELIVERED = 'undelivered';

	// ---------------------------------------------------------------------
	// Custom Capabilities
	// ---------------------------------------------------------------------

	public const CAP_REGISTER_GUESTS       = 'vms_register_guests';
	public const CAP_REGISTER_COURTESY     = 'vms_register_courtesy_guests';
	public const CAP_MANAGE_GUESTS         = 'vms_manage_guests';
	public const CAP_CANCEL_VISITS         = 'vms_cancel_visits';
	public const CAP_SIGNIN_GUESTS         = 'vms_signin_guests';
	public const CAP_SIGNOUT_GUESTS        = 'vms_signout_guests';
	public const CAP_MANAGE_ACCOMMODATION  = 'vms_manage_accommodation';
	public const CAP_MANAGE_SUPPLIERS      = 'vms_manage_suppliers';
	public const CAP_MANAGE_RECIPROCATION  = 'vms_manage_reciprocation';
	public const CAP_MANAGE_EMPLOYEES      = 'vms_manage_employees';
	public const CAP_VIEW_REPORTS          = 'vms_view_reports';
	public const CAP_EXPORT_DATA           = 'vms_export_data';
	public const CAP_VIEW_AUDIT_LOGS       = 'vms_view_audit_logs';
	public const CAP_MANAGE_SETTINGS       = 'vms_manage_settings';
	public const CAP_APPROVE_MEMBERS       = 'vms_approve_members';

	// ---------------------------------------------------------------------
	// Cron Hooks
	// ---------------------------------------------------------------------

	public const CRON_MIDNIGHT_TASKS  = 'vms_midnight_tasks';
	public const CRON_AUTO_SIGNOUT    = 'vms_auto_signout';
	public const CRON_MONTHLY_RESET   = 'vms_monthly_reset';
	public const CRON_YEARLY_RESET    = 'vms_yearly_reset';
	public const CRON_SMS_BALANCE     = 'vms_check_sms_balance';
	public const CRON_SMS_DELIVERY    = 'vms_check_sms_delivery';
	public const CRON_CLEANUP_LOGS    = 'vms_cleanup_old_logs';

	// ---------------------------------------------------------------------
	// Cache / Transient Groups
	// ---------------------------------------------------------------------

	public const CACHE_GROUP           = 'vms';
	public const CACHE_TTL_SHORT       = 300;    // 5 minutes.
	public const CACHE_TTL_MEDIUM      = 1800;   // 30 minutes.
	public const CACHE_TTL_LONG        = 3600;   // 1 hour.
	public const CACHE_TTL_DAY         = 86400;  // 24 hours.

	// ---------------------------------------------------------------------
	// Default Settings (used when option is not yet saved)
	// ---------------------------------------------------------------------

	public const DEFAULTS = array(
		// Branding.
		'club_name'                  => 'My Golf Club',
		'club_logo_id'               => 0,
		'club_address'               => '',
		'club_phone'                 => '',
		'club_email'                 => '',
		'primary_color'              => '#0ea5e9',
		'secondary_color'            => '#8b5cf6',

		// Visit Limits.
		'max_guest_visits_month'     => 4,
		'max_guest_visits_year'      => 24,
		'max_host_guests_day'        => 4,
		'auto_signout_time'          => '23:59:00',

		// Module Toggles.
		'module_guests'              => true,
		'module_accommodation'       => true,
		'module_suppliers'           => true,
		'module_reciprocation'       => true,
		'module_employees'           => true,
		'module_reports'             => true,
		'module_members'             => true,

		// Notifications.
		'enable_email_notifications' => true,
		'enable_sms_notifications'   => true,
		'email_from_name'            => '',
		'email_from_address'         => '',

		// SMS.
		'sms_provider'               => 'leopard',
		'sms_sender_id'              => '',

		// Data Retention.
		'audit_log_retention_days'   => 365,
		'sms_log_retention_days'     => 90,
	);

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Get fully-prefixed table name.
	 *
	 * @param string $table One of the TABLE_* constants.
	 * @return string
	 */
	public static function get_table_name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Retrieve a plugin option with automatic prefix + default fallback.
	 *
	 * @param string $key     Option key (without prefix).
	 * @param mixed  $default Fallback if not set & not in DEFAULTS.
	 * @return mixed
	 */
	public static function get_option( string $key, $default = null ) {
		// Use a sentinel that WordPress can never store so we can reliably
		// tell "option does not exist" apart from "option exists with a falsy
		// value". WordPress stores boolean false as '' and get_option() will
		// happily return that, which previously poisoned the DEFAULTS fallback.
		$sentinel = '__vms_opt_unset__';
		$value    = get_option( self::OPTION_PREFIX . $key, $sentinel );

		if ( $sentinel === $value ) {
			return self::DEFAULTS[ $key ] ?? $default;
		}

		// If the stored value is an empty string AND the declared default is a
		// boolean, this is almost certainly a checkbox that was never submitted
		// (unchecked checkboxes aren't posted) and got serialized as ''. Treat
		// it as "unset" so module toggles respect their true-by-default config.
		if ( '' === $value && isset( self::DEFAULTS[ $key ] ) && is_bool( self::DEFAULTS[ $key ] ) ) {
			return self::DEFAULTS[ $key ];
		}

		return $value;
	}

	/**
	 * Update a plugin option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function update_option( string $key, $value ): bool {
		return update_option( self::OPTION_PREFIX . $key, $value );
	}

	/**
	 * Delete a plugin option.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function delete_option( string $key ): bool {
		return delete_option( self::OPTION_PREFIX . $key );
	}

	/**
	 * All valid guest statuses.
	 *
	 * @return array<int, string>
	 */
	public static function get_guest_statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_BANNED );
	}

	/**
	 * All valid visit statuses.
	 *
	 * @return array<int, string>
	 */
	public static function get_visit_statuses(): array {
		return array(
			self::VISIT_APPROVED,
			self::VISIT_UNAPPROVED,
			self::VISIT_CANCELLED,
			self::VISIT_SUSPENDED,
			self::VISIT_BANNED,
			self::VISIT_COMPLETED,
		);
	}

	/**
	 * All valid SMS statuses.
	 *
	 * @return array<int, string>
	 */
	public static function get_sms_statuses(): array {
		return array(
			self::SMS_QUEUED,
			self::SMS_SENT,
			self::SMS_DELIVERED,
			self::SMS_FAILED,
			self::SMS_EXPIRED,
			self::SMS_UNDELIVERED,
		);
	}

	/**
	 * Validate a status value.
	 *
	 * @param string $status Status to check.
	 * @param string $type   One of: guest|visit|sms.
	 * @return bool
	 */
	public static function is_valid_status( string $status, string $type = 'guest' ): bool {
		$valid = match ( $type ) {
			'visit' => self::get_visit_statuses(),
			'sms'   => self::get_sms_statuses(),
			default => self::get_guest_statuses(),
		};

		return in_array( $status, $valid, true );
	}

	/**
	 * Get all custom capability identifiers.
	 *
	 * @return array<int, string>
	 */
	public static function get_all_capabilities(): array {
		return array(
			self::CAP_REGISTER_GUESTS,
			self::CAP_REGISTER_COURTESY,
			self::CAP_MANAGE_GUESTS,
			self::CAP_CANCEL_VISITS,
			self::CAP_SIGNIN_GUESTS,
			self::CAP_SIGNOUT_GUESTS,
			self::CAP_MANAGE_ACCOMMODATION,
			self::CAP_MANAGE_SUPPLIERS,
			self::CAP_MANAGE_RECIPROCATION,
			self::CAP_MANAGE_EMPLOYEES,
			self::CAP_VIEW_REPORTS,
			self::CAP_EXPORT_DATA,
			self::CAP_VIEW_AUDIT_LOGS,
			self::CAP_MANAGE_SETTINGS,
			self::CAP_APPROVE_MEMBERS,
		);
	}

	/**
	 * Get all cron hook identifiers.
	 *
	 * @return array<int, string>
	 */
	public static function get_all_cron_hooks(): array {
		return array(
			self::CRON_MIDNIGHT_TASKS,
			self::CRON_AUTO_SIGNOUT,
			self::CRON_MONTHLY_RESET,
			self::CRON_YEARLY_RESET,
			self::CRON_SMS_BALANCE,
			self::CRON_SMS_DELIVERY,
			self::CRON_CLEANUP_LOGS,
		);
	}
}
