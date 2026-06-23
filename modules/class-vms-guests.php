<?php
/**
 * Guest management module.
 *
 * Handles the complete guest lifecycle: registration, profile updates,
 * visit scheduling, sign-in/out, and deletion. All writes trigger cache
 * invalidation, audit logging, and notifications automatically.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Guests module.
 */
final class VMS_Guests extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Guest CRUD.
		add_action( 'wp_ajax_vms_register_guest', array( $this, 'ajax_register_guest' ) );
		add_action( 'wp_ajax_vms_update_guest', array( $this, 'ajax_update_guest' ) );
		add_action( 'wp_ajax_vms_delete_guest', array( $this, 'ajax_delete_guest' ) );
		add_action( 'wp_ajax_vms_get_guest', array( $this, 'ajax_get_guest' ) );
		add_action( 'wp_ajax_vms_search_guests', array( $this, 'ajax_search_guests' ) );
		add_action( 'wp_ajax_vms_set_guest_status', array( $this, 'ajax_set_guest_status' ) );

		// Visit lifecycle.
		add_action( 'wp_ajax_vms_register_visit', array( $this, 'ajax_register_visit' ) );
		add_action( 'wp_ajax_vms_cancel_visit', array( $this, 'ajax_cancel_visit' ) );
		add_action( 'wp_ajax_vms_signin_guest', array( $this, 'ajax_signin' ) );
		add_action( 'wp_ajax_vms_signout_guest', array( $this, 'ajax_signout' ) );
		add_action( 'wp_ajax_vms_get_visits', array( $this, 'ajax_get_visits' ) );

		// Cron.
		add_action( VMS_Config::CRON_AUTO_SIGNOUT, array( $this, 'cron_auto_signout' ) );
		add_action( VMS_Config::CRON_MONTHLY_RESET, array( $this, 'cron_monthly_reset' ) );
		add_action( 'vms_midnight_maintenance', array( $this, 'cron_update_stale_visits' ) );
	}

	// =====================================================================
	// GUEST CRUD
	// =====================================================================

	/**
	 * Create a guest record.
	 *
	 * @param array $data Guest data.
	 * @return int|\WP_Error Guest ID or error.
	 */
	public static function create_guest( array $data ) {
	global $wpdb;

	error_log(
		sprintf(
			'[VMS] create_guest START | user_id=%d | data=%s',
			get_current_user_id(),
			wp_json_encode(
				array(
					'first_name'   => $data['first_name'] ?? '',
					'last_name'    => $data['last_name'] ?? '',
					'email'        => $data['email'] ?? '',
					'phone_number' => $data['phone_number'] ?? '',
					'id_number'    => $data['id_number'] ?? '',
				)
			)
		)
	);

	// STEP 1 - Validation
	error_log( '[VMS] create_guest STEP 1 - validate_guest_data()' );

	$validated = self::validate_guest_data( $data );

	if ( is_wp_error( $validated ) ) {

		error_log(
			sprintf(
				'[VMS] create_guest VALIDATION FAILED | code=%s | message=%s',
				$validated->get_error_code(),
				$validated->get_error_message()
			)
		);

		return $validated;
	}

	error_log( '[VMS] create_guest STEP 1 SUCCESS' );

	// STEP 2 - Duplicate Phone Check
	error_log(
		sprintf(
			'[VMS] create_guest STEP 2 - find_by_phone(%s)',
			$validated['phone_number']
		)
	);

	$existing = self::find_by_phone(
		$validated['phone_number']
	);

	if ( $existing ) {

		error_log(
			sprintf(
				'[VMS] create_guest DUPLICATE PHONE | existing_guest_id=%d',
				$existing['id']
			)
		);

		return new \WP_Error(
			'duplicate_phone',
			__( 'A guest with this phone number already exists.', 'vms-plugin' ),
			array(
				'existing_id' => $existing['id'],
			)
		);
	}

	error_log( '[VMS] create_guest STEP 2 SUCCESS - no duplicate found' );

	$table = VMS_Config::get_table_name(
		VMS_Config::TABLE_GUESTS
	);

	error_log(
		sprintf(
			'[VMS] create_guest STEP 3 - INSERT INTO %s',
			$table
		)
	);

	// STEP 3 - Insert
	$result = $wpdb->insert(
		$table,
		array(
			'first_name'       => $validated['first_name'],
			'last_name'        => $validated['last_name'],
			'email'            => $validated['email'],
			'phone_number'     => $validated['phone_number'],
			'id_number'        => $validated['id_number'],
			'guest_status'     => VMS_Config::STATUS_ACTIVE,
			'receive_emails'   => $validated['receive_emails'],
			'receive_messages' => $validated['receive_messages'],
			'notes'            => $validated['notes'],
			'created_by'       => get_current_user_id() ?: null,
			'created_at'       => current_time( 'mysql' ),
		),
		array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%s',
			'%d',
			'%s',
		)
	);

	error_log(
		sprintf(
			'[VMS] create_guest INSERT RESULT=%s',
			var_export( $result, true )
		)
	);

	if ( false === $result ) {

		error_log(
			sprintf(
				'[VMS] create_guest DB ERROR | %s',
				$wpdb->last_error
			)
		);

		error_log(
			sprintf(
				'[VMS] create_guest LAST QUERY | %s',
				$wpdb->last_query
			)
		);

		return new \WP_Error(
			'db_insert_failed',
			__( 'Failed to create guest record.', 'vms-plugin' )
		);
	}

	$guest_id = $wpdb->insert_id;

	error_log(
		sprintf(
			'[VMS] create_guest STEP 3 SUCCESS | guest_id=%d',
			$guest_id
		)
	);

	// STEP 4 - Cache
	error_log( '[VMS] create_guest STEP 4 - Cache Bust' );

	VMS_Cache::bust( 'guests' );

	error_log( '[VMS] create_guest STEP 4 SUCCESS' );

	// STEP 5 - Audit Trail
	error_log( '[VMS] create_guest STEP 5 - Audit Log' );

	VMS_Audit_Trail::log_create(
		VMS_Audit_Trail::CAT_GUEST,
		'guest',
		$guest_id,
		$validated
	);

	error_log( '[VMS] create_guest STEP 5 SUCCESS' );

	// STEP 6 - Action Hook
	error_log( '[VMS] create_guest STEP 6 - do_action(vms_guest_created)' );

	do_action(
		'vms_guest_created',
		$guest_id,
		$validated
	);

	error_log( '[VMS] create_guest STEP 6 SUCCESS' );

	error_log(
		sprintf(
			'[VMS] create_guest COMPLETE | guest_id=%d',
			$guest_id
		)
	);

	return $guest_id;
}

	/**
	 * Update a guest record.
	 *
	 * @param int   $guest_id Guest ID.
	 * @param array $data     Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_guest( int $guest_id, array $data ) {
		global $wpdb;

		$old = self::get_guest( $guest_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Guest not found.', 'vms-plugin' ) );
		}

		$validated = self::validate_guest_data( $data, $guest_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only update fields that were provided.
		$update = array_intersect_key( $validated, $data );
		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $guest_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update guest.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'guests' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_GUEST, 'guest', $guest_id, $old, $update );

		do_action( 'vms_guest_updated', $guest_id, $update, $old );

		return true;
	}

	/**
	 * Delete a guest (cascades to visits via FK).
	 *
	 * @param int $guest_id Guest ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_guest( int $guest_id ) {
		global $wpdb;

		$guest = self::get_guest( $guest_id );
		if ( ! $guest ) {
			return new \WP_Error( 'not_found', __( 'Guest not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $guest_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_delete_failed', __( 'Failed to delete guest.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'guests' );
		VMS_Cache::bust( 'visits' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_GUEST, 'guest', $guest_id, $guest );

		do_action( 'vms_guest_deleted', $guest_id, $guest );

		return true;
	}

	/**
	 * Get a single guest by ID (cached).
	 *
	 * @param int $guest_id Guest ID.
	 * @return array|null
	 */
	public static function get_guest( int $guest_id ): ?array {

		error_log(
			sprintf(
				'[VMS] get_guest START | guest_id=%d',
				$guest_id
			)
		);

		return VMS_Cache::cached(
			"guests:id_{$guest_id}",
			static function () use ( $guest_id ) {

				global $wpdb;

				$table = VMS_Config::get_table_name(
					VMS_Config::TABLE_GUESTS
				);

				error_log(
					sprintf(
						'[VMS] get_guest CACHE MISS | guest_id=%d | table=%s',
						$guest_id,
						$table
					)
				);

				$query = $wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE id = %d",
					$guest_id
				);

				error_log(
					sprintf(
						'[VMS] get_guest QUERY | %s',
						$query
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$query,
					ARRAY_A
				);

				if ( ! empty( $wpdb->last_error ) ) {
					error_log(
						sprintf(
							'[VMS] get_guest DB ERROR | %s',
							$wpdb->last_error
						)
					);

					error_log(
						sprintf(
							'[VMS] get_guest LAST QUERY | %s',
							$wpdb->last_query
						)
					);
				}

				error_log(
					sprintf(
						'[VMS] get_guest RESULT | found=%s',
						$row ? 'YES' : 'NO'
					)
				);

				if ( $row ) {
					error_log(
						sprintf(
							'[VMS] get_guest DATA | id=%d | status=%s',
							$row['id'] ?? 0,
							$row['guest_status'] ?? 'unknown'
						)
					);
				}

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Find guest by phone number (cached).
	 *
	 * @param string $phone Phone number (any format).
	 * @return array|null
	 */
	public static function find_by_phone( string $phone ): ?array {
		$normalized = VMS_SMS_Gateway::normalize_phone( $phone );
		if ( ! $normalized ) {
			return null;
		}

		return VMS_Cache::cached(
			'guests:phone_' . md5( $normalized ),
			static function () use ( $normalized ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE phone_number = %s", $normalized ),
					ARRAY_A
				);

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Find guest by ID number.
	 *
	 * @param string $id_number National ID / passport number.
	 * @return array|null
	 */
	public static function find_by_id_number( string $id_number ): ?array {
		$id_number = sanitize_text_field( $id_number );

		return VMS_Cache::cached(
			'guests:idnum_' . md5( $id_number ),
			static function () use ( $id_number ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id_number = %s", $id_number ),
					ARRAY_A
				);

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Set guest status (active/suspended/banned).
	 *
	 * @param int    $guest_id Guest ID.
	 * @param string $status   New status.
	 * @param bool   $notify   Send notification.
	 * @return bool|\WP_Error
	 */
	public static function set_guest_status( int $guest_id, string $status, bool $notify = true ) {
		if ( ! VMS_Config::is_valid_status( $status, 'guest' ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid guest status.', 'vms-plugin' ) );
		}

		$guest = self::get_guest( $guest_id );
		if ( ! $guest ) {
			return new \WP_Error( 'not_found', __( 'Guest not found.', 'vms-plugin' ) );
		}

		$old_status = $guest['guest_status'];
		if ( $old_status === $status ) {
			return true;
		}

		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'guest_status' => $status ),
			array( 'id' => $guest_id ),
			array( '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'guests' );
		VMS_Audit_Trail::log_update(
			VMS_Audit_Trail::CAT_GUEST,
			'guest',
			$guest_id,
			array( 'guest_status' => $old_status ),
			array( 'guest_status' => $status )
		);

		if ( $notify ) {
			VMS_Notifications::guest_status_changed( $guest, $old_status, $status );
		}

		do_action( 'vms_guest_status_changed', $guest_id, $old_status, $status );

		return true;
	}

	// =====================================================================
	// VISIT LIFECYCLE
	// =====================================================================

	/**
	 * Register a visit for a guest.
	 *
	 * @param int         $guest_id   Guest ID.
	 * @param string      $visit_date Visit date (Y-m-d).
	 * @param int|null    $host_id    Host WP user ID (null for courtesy).
	 * @param string|null $courtesy   Courtesy label (e.g. "Chairman's guest").
	 * @return array|\WP_Error Visit record or error.
	 */
	public static function register_visit( int $guest_id, string $visit_date, ?int $host_id = null, ?string $courtesy = null ) {
		global $wpdb;

		error_log(
			sprintf(
				'[VMS] register_visit START | guest_id=%d | host_id=%s | visit_date=%s | courtesy=%s',
				$guest_id,
				$host_id ?: 'null',
				$visit_date,
				$courtesy ?: 'null'
			)
		);

		// STEP 1
		$guest = self::get_guest( $guest_id );

		error_log(
			sprintf(
				'[VMS] STEP 1 - Guest Lookup | found=%s',
				$guest ? 'YES' : 'NO'
			)
		);

		if ( ! $guest ) {
			error_log( '[VMS] EXIT - guest_not_found' );
			return new \WP_Error( 'guest_not_found', __( 'Guest not found.', 'vms-plugin' ) );
		}

		// STEP 2
		error_log(
			sprintf(
				'[VMS] STEP 2 - Guest Status = %s',
				$guest['guest_status']
			)
		);

		if ( VMS_Config::STATUS_BANNED === $guest['guest_status'] ) {
			error_log( '[VMS] EXIT - guest_banned' );
			return new \WP_Error( 'guest_banned', __( 'This guest is banned and cannot be registered for visits.', 'vms-plugin' ) );
		}

		// STEP 3
		$original_date = $visit_date;
		$visit_date    = gmdate( 'Y-m-d', strtotime( $visit_date ) );

		error_log(
			sprintf(
				'[VMS] STEP 3 - Date Validation | original=%s | normalized=%s | today=%s',
				$original_date,
				$visit_date,
				current_time( 'Y-m-d' )
			)
		);

		if ( $visit_date < current_time( 'Y-m-d' ) ) {
			error_log( '[VMS] EXIT - past_date' );
			return new \WP_Error( 'past_date', __( 'Cannot register a visit for a past date.', 'vms-plugin' ) );
		}

		// STEP 4
		error_log(
			sprintf(
				'[VMS] STEP 4 - Host/Courtesy Check | host=%s | courtesy=%s',
				$host_id ?: 'null',
				$courtesy ?: 'null'
			)
		);

		if ( ! $host_id && empty( $courtesy ) ) {
			error_log( '[VMS] EXIT - missing_host' );
			return new \WP_Error( 'missing_host', __( 'Visit must have either a host member or a courtesy designation.', 'vms-plugin' ) );
		}

		// STEP 5
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		error_log( '[VMS] STEP 5 - Checking duplicate visit' );

		$duplicate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE guest_id = %d AND visit_date = %s AND status NOT IN ('cancelled') LIMIT 1",
				$guest_id,
				$visit_date
			)
		);

		error_log(
			sprintf(
				'[VMS] STEP 5 RESULT - duplicate=%s',
				$duplicate ?: 'NO'
			)
		);

		if ( $duplicate ) {
			error_log( '[VMS] EXIT - duplicate_visit' );
			return new \WP_Error( 'duplicate_visit', __( 'Guest already has a visit registered for this date.', 'vms-plugin' ) );
		}

		// STEP 6
		error_log( '[VMS] STEP 6 - Calculating visit limits' );

		$calc = VMS_Visit_Limits::calculate_new_visit_status(
			$guest_id,
			$host_id,
			$visit_date
		);

		error_log(
			sprintf(
				'[VMS] STEP 6 RESULT | status=%s | suspend_guest=%s | reason=%s',
				$calc['visit_status'] ?? 'unknown',
				! empty( $calc['suspend_guest'] ) ? 'YES' : 'NO',
				$calc['reason'] ?? 'none'
			)
		);

		// STEP 7
		error_log( '[VMS] STEP 7 - Inserting visit record' );

		$result = $wpdb->insert(
			$table,
			array(
				'guest_id'       => $guest_id,
				'host_member_id' => $host_id,
				'courtesy'       => $courtesy ? sanitize_text_field( $courtesy ) : null,
				'visit_date'     => $visit_date,
				'status'         => $calc['visit_status'],
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		error_log(
			sprintf(
				'[VMS] STEP 7 RESULT | insert_result=%s | db_error=%s',
				var_export( $result, true ),
				$wpdb->last_error ?: 'none'
			)
		);

		if ( false === $result ) {
			error_log( '[VMS] EXIT - db_insert_failed' );
			return new \WP_Error( 'db_insert_failed', __( 'Failed to register visit.', 'vms-plugin' ) );
		}

		$visit_id = $wpdb->insert_id;

		error_log(
			sprintf(
				'[VMS] STEP 8 - Visit Inserted | visit_id=%d',
				$visit_id
			)
		);

		$visit = self::get_visit( $visit_id );

		error_log( '[VMS] STEP 9 - Visit Retrieved' );

		VMS_Cache::bust( 'visits' );
		VMS_Cache::bust( 'stats' );

		error_log( '[VMS] STEP 10 - Cache Cleared' );

		VMS_Audit_Trail::log_create(
			VMS_Audit_Trail::CAT_VISIT,
			'visit',
			$visit_id,
			$visit
		);

		error_log( '[VMS] STEP 11 - Audit Logged' );

		if ( $calc['suspend_guest'] ) {
			error_log( '[VMS] STEP 12 - Suspending Guest' );

			self::set_guest_status(
				$guest_id,
				VMS_Config::STATUS_SUSPENDED
			);
		}

		$host = $host_id ? self::get_host_data( $host_id ) : array();

		error_log( '[VMS] STEP 13 - Sending Registration Notification' );

		VMS_Notifications::visit_registered(
			$guest,
			$visit,
			$host
		);

		error_log( '[VMS] STEP 14 - Notification Sent' );

		if ( $host_id && VMS_Config::VISIT_UNAPPROVED === $calc['visit_status'] && ! $calc['suspend_guest'] ) {

			error_log( '[VMS] STEP 15 - Checking Host Limit Notification' );

			$pending_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE host_member_id = %d AND visit_date = %s AND status = %s",
					$host_id,
					$visit_date,
					VMS_Config::VISIT_UNAPPROVED
				)
			);

			error_log(
				sprintf(
					'[VMS] STEP 15 RESULT | pending_count=%d',
					$pending_count
				)
			);

			VMS_Notifications::host_limit_reached(
				$host_id,
				$visit_date,
				$pending_count
			);
		}

		error_log( '[VMS] STEP 16 - Triggering Action Hook' );

		do_action(
			'vms_visit_registered',
			$visit_id,
			$visit,
			$calc
		);

		error_log(
			sprintf(
				'[VMS] SUCCESS | visit_id=%d',
				$visit_id
			)
		);

		return array_merge(
			$visit,
			array(
				'limit_reason' => $calc['reason'],
			)
		);
	}

	/**
	 * Cancel a visit.
	 *
	 * @param int $visit_id Visit ID.
	 * @return bool|\WP_Error
	 */
	public static function cancel_visit( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Visit not found.', 'vms-plugin' ) );
		}

		if ( VMS_Config::VISIT_CANCELLED === $visit['status'] ) {
			return true;
		}

		if ( ! empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'already_signed_in', __( 'Cannot cancel a visit that has already been signed in.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'status' => VMS_Config::VISIT_CANCELLED ),
			array( 'id' => $visit_id ),
			array( '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'visits' );
		VMS_Cache::bust( 'stats' );

		VMS_Audit_Trail::log_update(
			VMS_Audit_Trail::CAT_VISIT,
			'visit',
			$visit_id,
			array( 'status' => $visit['status'] ),
			array( 'status' => VMS_Config::VISIT_CANCELLED )
		);

		// Notify guest.
		$guest = self::get_guest( (int) $visit['guest_id'] );
		if ( $guest ) {
			VMS_Notifications::visit_status_changed( $guest, $visit, $visit['status'], VMS_Config::VISIT_CANCELLED );
		}

		// Recalculate: cancellation may free up slots.
		$guest_changes = VMS_Visit_Limits::recalculate_guest_visits( (int) $visit['guest_id'] );
		$host_changes  = array();

		if ( ! empty( $visit['host_member_id'] ) ) {
			$host_changes = VMS_Visit_Limits::recalculate_host_day( (int) $visit['host_member_id'], $visit['visit_date'] );
		}

		// Notify newly-approved visits.
		foreach ( array_merge( $guest_changes, $host_changes ) as $change ) {
			$changed_visit = self::get_visit( $change['visit_id'] );
			$changed_guest = self::get_guest( (int) ( $change['guest_id'] ?? $visit['guest_id'] ) );
			if ( $changed_guest && $changed_visit ) {
				VMS_Notifications::visit_status_changed( $changed_guest, $changed_visit, $change['old_status'], $change['new_status'] );
			}
		}

		// Check if guest should be reactivated.
		if ( $guest && VMS_Config::STATUS_SUSPENDED === $guest['guest_status'] ) {
			if ( VMS_Visit_Limits::should_reactivate_guest( (int) $visit['guest_id'] ) ) {
				self::set_guest_status( (int) $visit['guest_id'], VMS_Config::STATUS_ACTIVE );
			}
		}

		do_action( 'vms_visit_cancelled', $visit_id, $visit );

		return true;
	}

	/**
	 * Sign a guest in.
	 *
	 * @param int    $visit_id  Visit ID.
	 * @param string $id_number ID number to verify against guest record.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function signin( int $visit_id, string $id_number ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Visit not found.', 'vms-plugin' ) );
		}

		if ( VMS_Config::VISIT_APPROVED !== $visit['status'] ) {
			return new \WP_Error( 'not_approved', __( 'Only approved visits can be signed in.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'already_signed_in', __( 'Guest is already signed in.', 'vms-plugin' ) );
		}

		if ( $visit['visit_date'] !== current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'wrong_date', __( 'This visit is not scheduled for today.', 'vms-plugin' ) );
		}

		$guest = self::get_guest( (int) $visit['guest_id'] );
		if ( ! $guest ) {
			return new \WP_Error( 'guest_not_found', __( 'Guest record not found.', 'vms-plugin' ) );
		}

		// ID verification: if guest has an ID on file, it must match.
		$provided = preg_replace( '/[^A-Za-z0-9]/', '', $id_number );
		$stored   = preg_replace( '/[^A-Za-z0-9]/', '', (string) $guest['id_number'] );

		if ( $stored && strcasecmp( $stored, $provided ) !== 0 ) {
			VMS_Audit_Trail::log(
				'signin_id_mismatch',
				VMS_Audit_Trail::CAT_SECURITY,
				'visit',
				$visit_id,
				null,
				null,
				array( 'provided' => substr( $provided, 0, 3 ) . '***' )
			);
			return new \WP_Error( 'id_mismatch', __( 'ID number does not match guest record.', 'vms-plugin' ) );
		}

		// If guest had no ID on file, store it now.
		if ( ! $stored && $provided ) {
			self::update_guest( (int) $visit['guest_id'], array( 'id_number' => $provided ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'sign_in_time' => $now,
				'signed_in_by' => get_current_user_id() ?: null,
			),
			array( 'id' => $visit_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'visits' );

		$visit['sign_in_time'] = $now;

		VMS_Audit_Trail::log( 'guest_signed_in', VMS_Audit_Trail::CAT_VISIT, 'visit', $visit_id, null, array( 'sign_in_time' => $now ) );
		VMS_Notifications::guest_signed_in( $guest, $visit );

		do_action( 'vms_guest_signed_in', $visit_id, $guest, $visit );

		return $visit;
	}

	/**
	 * Sign a guest out.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error
	 */
	public static function signout( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Visit not found.', 'vms-plugin' ) );
		}

		if ( empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'not_signed_in', __( 'Guest has not signed in.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_out_time'] ) ) {
			return new \WP_Error( 'already_signed_out', __( 'Guest has already signed out.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'sign_out_time' => $now,
				'signed_out_by' => get_current_user_id() ?: null,
				'status'        => VMS_Config::VISIT_COMPLETED,
			),
			array( 'id' => $visit_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'visits' );

		$visit['sign_out_time'] = $now;
		$visit['status']        = VMS_Config::VISIT_COMPLETED;

		VMS_Audit_Trail::log( 'guest_signed_out', VMS_Audit_Trail::CAT_VISIT, 'visit', $visit_id, null, array( 'sign_out_time' => $now ) );

		$guest = self::get_guest( (int) $visit['guest_id'] );
		if ( $guest ) {
			VMS_Notifications::guest_signed_out( $guest, $visit );
		}

		do_action( 'vms_guest_signed_out', $visit_id, $guest, $visit );

		return $visit;
	}

	/**
	 * Get a single visit by ID.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|null
	 */
	public static function get_visit( int $visit_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $visit_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get visits for today (cached for dashboard).
	 *
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public static function get_todays_visits( string $status = '' ): array {
		$cache_key = 'visits:today_' . ( $status ?: 'all' );

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $status ) {
				global $wpdb;

				$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

				$where = 'v.visit_date = %s';
				$params = array( current_time( 'Y-m-d' ) );

				if ( $status && VMS_Config::is_valid_status( $status, 'visit' ) ) {
					$where   .= ' AND v.status = %s';
					$params[] = $status;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.*, g.first_name, g.last_name, g.phone_number, g.id_number, g.guest_status
						 FROM `{$visits_table}` v
						 INNER JOIN `{$guests_table}` g ON g.id = v.guest_id
						 WHERE {$where}
						 ORDER BY v.sign_in_time IS NULL DESC, v.sign_in_time ASC, v.created_at ASC",
						$params
					),
					ARRAY_A
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	// =====================================================================
	// CRON TASKS
	// =====================================================================

	/**
	 * Cron: auto-sign-out all guests still signed in.
	 *
	 * @return void
	 */
	public function cron_auto_signout(): void {
		global $wpdb;

		$table    = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$cutoff   = VMS_Config::get_option( 'auto_signout_time', '23:59:00' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM `{$table}`
				 WHERE visit_date <= %s
				 AND sign_in_time IS NOT NULL
				 AND sign_out_time IS NULL",
				$yesterday
			),
			ARRAY_A
		);

		$signout_time = $yesterday . ' ' . $cutoff;

		foreach ( $stale as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'sign_out_time' => $signout_time,
					'status'        => VMS_Config::VISIT_COMPLETED,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		if ( ! empty( $stale ) ) {
			VMS_Cache::bust( 'visits' );
			VMS_Audit_Trail::log(
				'auto_signout_completed',
				VMS_Audit_Trail::CAT_SYSTEM,
				null,
				null,
				null,
				null,
				array( 'count' => count( $stale ) )
			);
		}
	}

	/**
	 * Cron: reactivate suspended guests whose limits have reset.
	 *
	 * @return void
	 */
	public function cron_monthly_reset(): void {
		global $wpdb;

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$suspended = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE guest_status = %s",
				VMS_Config::STATUS_SUSPENDED
			)
		);

		VMS_Cache::bust( 'visits' );

		$reactivated = 0;
		foreach ( $suspended as $guest_id ) {
			if ( VMS_Visit_Limits::should_reactivate_guest( (int) $guest_id ) ) {
				self::set_guest_status( (int) $guest_id, VMS_Config::STATUS_ACTIVE );
				++$reactivated;
			}
		}

		VMS_Audit_Trail::log(
			'monthly_reset_completed',
			VMS_Audit_Trail::CAT_SYSTEM,
			null,
			null,
			null,
			null,
			array( 'reactivated' => $reactivated, 'checked' => count( $suspended ) )
		);
	}

	/**
	 * Cron: mark expired visits (past date, never signed in).
	 *
	 * @return void
	 */
	public function cron_update_stale_visits(): void {
		global $wpdb;

		$table     = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s
				 WHERE visit_date < %s
				 AND sign_in_time IS NULL
				 AND status IN ('approved', 'unapproved')",
				VMS_Config::VISIT_CANCELLED,
				$yesterday
			)
		);

		VMS_Cache::bust( 'visits' );
	}

	// =====================================================================
	// AJAX HANDLERS
	// =====================================================================

	/**
	 * AJAX: register a guest (with optional same-time visit).
	 *
	 * @return void
	 */
	public function ajax_register_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_REGISTER_GUESTS );

		$guest_data = array(
			'first_name'       => self::get_post_text( 'first_name' ),
			'last_name'        => self::get_post_text( 'last_name' ),
			'email'            => self::get_post_email( 'email' ),
			'phone_number'     => self::get_post_text( 'phone_number' ),
			'id_number'        => self::get_post_text( 'id_number' ),
			'receive_emails'   => self::get_post_int( 'receive_emails' ),
			'receive_messages' => self::get_post_int( 'receive_messages' ),
			'notes'            => self::get_post_text( 'notes' ),
		);

		$result = self::create_guest( $guest_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		$guest = self::get_guest( $result );

		wp_send_json_success(
			array(
				'guest'   => $guest,
				'message' => __( 'Guest registered successfully.', 'vms-plugin' ),
			)
		);
	}

	/**
	 * AJAX: update guest.
	 *
	 * @return void
	 */
	public function ajax_update_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_GUESTS );

		$guest_id = self::get_post_int( 'guest_id' );
		$data     = array_filter(
			array(
				'first_name'       => self::get_post_text( 'first_name' ),
				'last_name'        => self::get_post_text( 'last_name' ),
				'email'            => self::get_post_email( 'email' ),
				'phone_number'     => self::get_post_text( 'phone_number' ),
				'id_number'        => self::get_post_text( 'id_number' ),
				'notes'            => self::get_post_text( 'notes' ),
				'guest_status'     => self::get_post_text( 'guest_status' ),
			),
			static fn( $v ) => '' !== $v
		);

		// Checkbox values need special handling (empty = 0).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['receive_emails'] ) ) {
			$data['receive_emails'] = self::get_post_int( 'receive_emails' );
		}
		if ( isset( $_POST['receive_messages'] ) ) {
			$data['receive_messages'] = self::get_post_int( 'receive_messages' );
		}
		// phpcs:enable

		$result = self::update_guest( $guest_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'guest' => self::get_guest( $guest_id ), 'message' => __( 'Guest updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: delete guest.
	 *
	 * @return void
	 */
	public function ajax_delete_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_GUESTS );

		$result = self::delete_guest( self::get_post_int( 'guest_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Guest deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get single guest with visits & usage.
	 *
	 * @return void
	 */
	public function ajax_get_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_REGISTER_GUESTS );

		$guest_id = self::get_post_int( 'guest_id' );
		$guest    = self::get_guest( $guest_id );

		if ( ! $guest ) {
			wp_send_json_error( array( 'message' => __( 'Guest not found.', 'vms-plugin' ) ) );
		}

		wp_send_json_success(
			array(
				'guest' => $guest,
				'usage' => VMS_Visit_Limits::get_guest_usage( $guest_id ),
			)
		);
	}

	/**
	 * AJAX: search guests by name/phone/ID.
	 *
	 * @return void
	 */
	public function ajax_search_guests(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_REGISTER_GUESTS );

		global $wpdb;

		$term     = self::get_post_text( 'term' );
		$list_all = self::get_post_text( 'list_all' );
		$table    = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		if ( $list_all || strlen( trim( $term ) ) < 2 ) {
			// Load all guests when the page requests the full list, otherwise
			// return no partial results for short search terms.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results(
				"SELECT id, first_name, last_name, phone_number, email, id_number, guest_status, created_at
				 FROM `{$table}`
				 ORDER BY first_name ASC",
				ARRAY_A
			);

			wp_send_json_success( array( 'results' => $results ) );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, phone_number, email, id_number, guest_status, created_at
				 FROM `{$table}`
				 WHERE first_name LIKE %s OR last_name LIKE %s OR phone_number LIKE %s OR id_number LIKE %s
				 ORDER BY first_name ASC
				 LIMIT 20",
				$like,
				$like,
				$like,
				$like
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: set guest status.
	 *
	 * @return void
	 */
	public function ajax_set_guest_status(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_GUESTS );

		$result = self::set_guest_status(
			self::get_post_int( 'guest_id' ),
			self::get_post_text( 'status' )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Status updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: register a visit.
	 *
	 * @return void
	 */
	public function ajax_register_visit(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_REGISTER_GUESTS );

		$host_id = self::get_post_int( 'host_id' );

		// Members registering for themselves: force host to current user.
		$current_role = VMS_Roles::get_user_vms_role();
		if ( 'member' === $current_role ) {
			$host_id = get_current_user_id();
		}

		// Courtesy requires special capability.
		$courtesy = self::get_post_text( 'courtesy' );
		if ( $courtesy && ! current_user_can( VMS_Config::CAP_REGISTER_COURTESY ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to register courtesy guests.', 'vms-plugin' ),
				)
			);
		}

		$guest_id   = self::get_post_int( 'guest_id' );
		$visit_date = self::get_post_text( 'visit_date' );

		$result = self::register_visit(
			$guest_id,
			$visit_date,
			$host_id ?: null,
			$courtesy ?: null
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'visit'   => $result,
				'message' => __( 'Visit registered.', 'vms-plugin' ),
			)
		);
	}


	/**
	 * AJAX: cancel a visit.
	 *
	 * @return void
	 */
	public function ajax_cancel_visit(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_CANCEL_VISITS );

		$visit_id = self::get_post_int( 'visit_id' );

		// Members can only cancel their own visits.
		if ( 'member' === VMS_Roles::get_user_vms_role() ) {
			$visit = self::get_visit( $visit_id );
			if ( ! $visit || (int) $visit['host_member_id'] !== get_current_user_id() ) {
				wp_send_json_error( array( 'message' => __( 'You can only cancel your own guest visits.', 'vms-plugin' ) ) );
			}
		}

		$result = self::cancel_visit( $visit_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Visit cancelled.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign in.
	 *
	 * @return void
	 */
	public function ajax_signin(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_SIGNIN_GUESTS );

		$result = self::signin(
			self::get_post_int( 'visit_id' ),
			self::get_post_text( 'id_number' )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Guest signed in.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign out.
	 *
	 * @return void
	 */
	public function ajax_signout(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_SIGNOUT_GUESTS );

		$result = self::signout( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Guest signed out.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get visits (today's, or filtered).
	 *
	 * @return void
	 */
	public function ajax_get_visits(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_REGISTER_GUESTS );

		$visits = self::get_todays_visits( self::get_post_text( 'status' ) );

		wp_send_json_success( array( 'visits' => $visits ) );
	}

	// =====================================================================
	// HELPERS
	// =====================================================================

	/**
	 * Validate & sanitize guest input.
	 *
	 * @param array $data       Raw input.
	 * @param int   $exclude_id Guest ID to exclude from uniqueness checks (for updates).
	 * @return array|\WP_Error
	 */
	private static function validate_guest_data( array $data, int $exclude_id = 0 ) {
		$out = array();

		// Required fields.
		if ( isset( $data['first_name'] ) ) {
			$out['first_name'] = sanitize_text_field( $data['first_name'] );
			if ( empty( $out['first_name'] ) ) {
				return new \WP_Error( 'missing_first_name', __( 'First name is required.', 'vms-plugin' ) );
			}
		}

		if ( isset( $data['last_name'] ) ) {
			$out['last_name'] = sanitize_text_field( $data['last_name'] );
			if ( empty( $out['last_name'] ) ) {
				return new \WP_Error( 'missing_last_name', __( 'Last name is required.', 'vms-plugin' ) );
			}
		}

		if ( isset( $data['phone_number'] ) ) {
			$normalized = VMS_SMS_Gateway::normalize_phone( $data['phone_number'] );
			if ( ! $normalized ) {
				return new \WP_Error( 'invalid_phone', __( 'Invalid phone number format.', 'vms-plugin' ) );
			}
			$out['phone_number'] = $normalized;
		}

		// Optional fields.
		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			$out['email'] = $email && is_email( $email ) ? $email : null;
		}

		if ( isset( $data['id_number'] ) ) {
			$id = preg_replace( '/[^A-Za-z0-9]/', '', $data['id_number'] );
			$out['id_number'] = $id ?: null;
		}

		$out['receive_emails']   = ! empty( $data['receive_emails'] ) ? 1 : 0;
		$out['receive_messages'] = ! empty( $data['receive_messages'] ) ? 1 : 0;
		$out['notes']            = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null;

		if ( isset( $data['guest_status'] ) ) {
			$valid_statuses = array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, VMS_Config::STATUS_BANNED );
			if ( in_array( $data['guest_status'], $valid_statuses, true ) ) {
				$out['guest_status'] = $data['guest_status'];
			}
		}

		return $out;
	}

	/**
	 * Get host contact data for notifications.
	 *
	 * @param int $user_id WP user ID.
	 * @return array
	 */
	private static function get_host_data( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		return array(
			'user_id'          => $user_id,
			'first_name'       => $user->first_name ?: $user->display_name,
			'email'            => $user->user_email,
			'phone_number'     => get_user_meta( $user_id, 'vms_phone', true ),
			'receive_messages' => (bool) get_user_meta( $user_id, 'vms_receive_sms', true ),
			'receive_emails'   => (bool) get_user_meta( $user_id, 'vms_receive_email', true ),
		);
	}
}