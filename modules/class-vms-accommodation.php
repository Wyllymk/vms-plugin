<?php
/**
 * Accommodation management module.
 *
 * Handles the complete accommodation guest lifecycle: registration,
 * profile updates, visit scheduling, check-in/out, and deletion.
 * All writes trigger cache invalidation, audit logging, and
 * notifications automatically.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Accommodation module.
 */
final class VMS_Accommodation extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Guest CRUD.
		add_action( 'wp_ajax_vms_register_accom_guest', array( $this, 'ajax_register_accom_guest' ) );
		add_action( 'wp_ajax_vms_update_accom_guest', array( $this, 'ajax_update_accom_guest' ) );
		add_action( 'wp_ajax_vms_delete_accom_guest', array( $this, 'ajax_delete_accom_guest' ) );
		add_action( 'wp_ajax_vms_get_accom_guests', array( $this, 'ajax_get_accom_guests' ) );
		add_action( 'wp_ajax_vms_search_accom_guests', array( $this, 'ajax_search_accom_guests' ) );

		// Visit lifecycle.
		add_action( 'wp_ajax_vms_register_accom_visit', array( $this, 'ajax_register_accom_visit' ) );
		add_action( 'wp_ajax_vms_checkin_accom', array( $this, 'ajax_checkin_accom' ) );
		add_action( 'wp_ajax_vms_checkout_accom', array( $this, 'ajax_checkout_accom' ) );
		add_action( 'wp_ajax_vms_get_accom_visits', array( $this, 'ajax_get_accom_visits' ) );
	}

	// =====================================================================
	// GUEST CRUD
	// =====================================================================

	/**
	 * Create an accommodation guest record.
	 *
	 * @param array $data Guest data.
	 * @return int|\WP_Error Guest ID or error.
	 */
	public static function create_guest( array $data ) {
		global $wpdb;

		$validated = self::validate_guest_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Check for duplicate phone.
		$existing = self::find_by_phone( $validated['phone_number'] );
		if ( $existing ) {
			return new \WP_Error(
				'duplicate_phone',
				__( 'An accommodation guest with this phone number already exists.', 'vms-plugin' ),
				array( 'existing_id' => $existing['id'] )
			);
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				'created_by'       => get_current_user_id() ?: null,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create accommodation guest record.', 'vms-plugin' ) );
		}

		$guest_id = $wpdb->insert_id;

		VMS_Cache::bust( 'accom' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_ACCOM, 'accom_guest', $guest_id, $validated );

		/**
		 * Fires after an accommodation guest is created.
		 *
		 * @since 2.0.0
		 * @param int   $guest_id Guest ID.
		 * @param array $data     Guest data.
		 */
		do_action( 'vms_accom_guest_created', $guest_id, $validated );

		return $guest_id;
	}

	/**
	 * Update an accommodation guest record.
	 *
	 * @param int   $guest_id Guest ID.
	 * @param array $data     Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_guest( int $guest_id, array $data ) {
		global $wpdb;

		$old = self::get_guest( $guest_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Accommodation guest not found.', 'vms-plugin' ) );
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

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $guest_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update accommodation guest.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'accom' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_ACCOM, 'accom_guest', $guest_id, $old, $update );

		do_action( 'vms_accom_guest_updated', $guest_id, $update, $old );

		return true;
	}

	/**
	 * Delete an accommodation guest (cascades to visits via FK).
	 *
	 * @param int $guest_id Guest ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_guest( int $guest_id ) {
		global $wpdb;

		$guest = self::get_guest( $guest_id );
		if ( ! $guest ) {
			return new \WP_Error( 'not_found', __( 'Accommodation guest not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $guest_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_delete_failed', __( 'Failed to delete accommodation guest.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'accom' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_ACCOM, 'accom_guest', $guest_id, $guest );

		do_action( 'vms_accom_guest_deleted', $guest_id, $guest );

		return true;
	}

	/**
	 * Get a single accommodation guest by ID (cached).
	 *
	 * @param int $guest_id Guest ID.
	 * @return array|null
	 */
	public static function get_guest( int $guest_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $guest_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find accommodation guest by phone number (cached).
	 *
	 * @param string $phone Phone number (any format).
	 * @return array|null
	 */
	public static function find_by_phone( string $phone ): ?array {
		$normalized = VMS_SMS_Gateway::normalize_phone( $phone );
		if ( ! $normalized ) {
			return null;
		}

		$result = VMS_Cache::cached(
			'accom:phone_' . md5( $normalized ),
			static function () use ( $normalized ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE phone_number = %s", $normalized ),
					ARRAY_A
				);

				return $row ?: false; // false = not found, won't be cached
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);

		return $result ?: null;
	}

	/**
	 * Get all accommodation guests (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_guests( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "accom:guests_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$table  = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );
				$where  = array( '1=1' );
				$params = array();

				if ( $status && VMS_Config::is_valid_status( $status, 'guest' ) ) {
					$where[]  = 'guest_status = %s';
					$params[] = $status;
				}

				$where_sql = implode( ' AND ', $where );
				$per_page  = max( 1, min( 500, $per_page ) );
				$offset    = max( 0, ( $page - 1 ) * $per_page );

				// Count.
				$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

				// Rows.
				$params[] = $per_page;
				$params[] = $offset;
				$rows_sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A );

				return array(
					'rows'  => $rows,
					'total' => $total,
					'pages' => (int) ceil( $total / $per_page ),
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	// =====================================================================
	// VISIT LIFECYCLE
	// =====================================================================

	/**
	 * Register an accommodation visit.
	 *
	 * @param int         $guest_id       Guest ID.
	 * @param string      $check_in_date  Check-in date (Y-m-d).
	 * @param string|null $check_out_date Check-out date (Y-m-d).
	 * @param string|null $room_number    Room number.
	 * @return array|\WP_Error Visit record or error.
	 */
	public static function register_visit( int $guest_id, string $check_in_date, ?string $check_out_date = null, ?string $room_number = null ) {
		global $wpdb;

		$guest = self::get_guest( $guest_id );
		if ( ! $guest ) {
			return new \WP_Error( 'guest_not_found', __( 'Accommodation guest not found.', 'vms-plugin' ) );
		}

		if ( VMS_Config::STATUS_BANNED === $guest['guest_status'] ) {
			return new \WP_Error( 'guest_banned', __( 'This guest is banned and cannot be registered for accommodation.', 'vms-plugin' ) );
		}

		// Validate check-in date.
		$check_in_date = gmdate( 'Y-m-d', strtotime( $check_in_date ) );
		if ( $check_in_date < current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'past_date', __( 'Cannot register accommodation for a past date.', 'vms-plugin' ) );
		}

		// Validate check-out date if provided.
		if ( $check_out_date ) {
			$check_out_date = gmdate( 'Y-m-d', strtotime( $check_out_date ) );
			if ( $check_out_date <= $check_in_date ) {
				return new \WP_Error( 'invalid_checkout', __( 'Check-out date must be after check-in date.', 'vms-plugin' ) );
			}
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'guest_id'       => $guest_id,
				'check_in_date'  => $check_in_date,
				'check_out_date' => $check_out_date,
				'room_number'    => $room_number ? sanitize_text_field( $room_number ) : null,
				'status'         => VMS_Config::VISIT_APPROVED,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to register accommodation visit.', 'vms-plugin' ) );
		}

		$visit_id = $wpdb->insert_id;
		$visit    = self::get_visit( $visit_id );

		VMS_Cache::bust( 'accom' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_ACCOM, 'accom_visit', $visit_id, $visit ?? array() );

		do_action( 'vms_accom_visit_registered', $visit_id, $visit );

		return $visit ?? array( 'id' => $visit_id );
	}

	/**
	 * Check in an accommodation guest.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function checkin( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Accommodation visit not found.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'already_checked_in', __( 'Guest is already checked in.', 'vms-plugin' ) );
		}

		if ( VMS_Config::VISIT_APPROVED !== $visit['status'] ) {
			return new \WP_Error( 'not_approved', __( 'Only approved visits can be checked in.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'sign_in_time' => $now ),
			array( 'id' => $visit_id ),
			array( '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'accom' );

		$visit['sign_in_time'] = $now;

		VMS_Audit_Trail::log(
			'accom_guest_checked_in',
			VMS_Audit_Trail::CAT_ACCOM,
			'accom_visit',
			$visit_id,
			null,
			array( 'sign_in_time' => $now )
		);

		do_action( 'vms_accom_guest_checked_in', $visit_id, $visit );

		return $visit;
	}

	/**
	 * Check out an accommodation guest.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function checkout( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Accommodation visit not found.', 'vms-plugin' ) );
		}

		if ( empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'not_checked_in', __( 'Guest has not checked in.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_out_time'] ) ) {
			return new \WP_Error( 'already_checked_out', __( 'Guest has already checked out.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'sign_out_time' => $now,
				'status'        => VMS_Config::VISIT_COMPLETED,
			),
			array( 'id' => $visit_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'accom' );

		$visit['sign_out_time'] = $now;
		$visit['status']        = VMS_Config::VISIT_COMPLETED;

		VMS_Audit_Trail::log(
			'accom_guest_checked_out',
			VMS_Audit_Trail::CAT_ACCOM,
			'accom_visit',
			$visit_id,
			null,
			array( 'sign_out_time' => $now )
		);

		do_action( 'vms_accom_guest_checked_out', $visit_id, $visit );

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
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $visit_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get accommodation visits (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_visits( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "accom:visits_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

				$where  = array( '1=1' );
				$params = array();

				if ( $status && VMS_Config::is_valid_status( $status, 'visit' ) ) {
					$where[]  = 'v.status = %s';
					$params[] = $status;
				}

				$where_sql = implode( ' AND ', $where );
				$per_page  = max( 1, min( 500, $per_page ) );
				$offset    = max( 0, ( $page - 1 ) * $per_page );

				// Count.
				$count_sql = "SELECT COUNT(*) FROM `{$visits_table}` v WHERE {$where_sql}";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

				// Rows.
				$params[] = $per_page;
				$params[] = $offset;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.*, g.first_name, g.last_name, g.phone_number, g.id_number, g.guest_status
						 FROM `{$visits_table}` v
						 INNER JOIN `{$guests_table}` g ON g.id = v.guest_id
						 WHERE {$where_sql}
						 ORDER BY v.check_in_date DESC, v.created_at DESC
						 LIMIT %d OFFSET %d",
						$params
					),
					ARRAY_A
				);

				return array(
					'rows'  => $rows,
					'total' => $total,
					'pages' => (int) ceil( $total / $per_page ),
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	// =====================================================================
	// AJAX HANDLERS
	// =====================================================================

	/**
	 * AJAX: register an accommodation guest.
	 *
	 * @return void
	 */
	public function ajax_register_accom_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::create_guest(
			array(
				'first_name'       => self::get_post_text( 'first_name' ),
				'last_name'        => self::get_post_text( 'last_name' ),
				'email'            => self::get_post_email( 'email' ),
				'phone_number'     => self::get_post_text( 'phone_number' ),
				'id_number'        => self::get_post_text( 'id_number' ),
				'receive_emails'   => self::get_post_int( 'receive_emails' ),
				'receive_messages' => self::get_post_int( 'receive_messages' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		$guest = self::get_guest( $result );
		wp_send_json_success( array( 'guest' => $guest, 'message' => __( 'Accommodation guest registered successfully.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update an accommodation guest.
	 *
	 * @return void
	 */
	public function ajax_update_accom_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$guest_id = self::get_post_int( 'guest_id' );
		$data     = array_filter(
			array(
				'first_name'   => self::get_post_text( 'first_name' ),
				'last_name'    => self::get_post_text( 'last_name' ),
				'email'        => self::get_post_email( 'email' ),
				'phone_number' => self::get_post_text( 'phone_number' ),
				'id_number'    => self::get_post_text( 'id_number' ),
				'guest_status' => self::get_post_text( 'guest_status' ),
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

		wp_send_json_success( array( 'guest' => self::get_guest( $guest_id ), 'message' => __( 'Accommodation guest updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: delete an accommodation guest.
	 *
	 * @return void
	 */
	public function ajax_delete_accom_guest(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::delete_guest( self::get_post_int( 'guest_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Accommodation guest deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get accommodation guests (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_accom_guests(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::get_guests(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: search accommodation guests by name/phone/ID.
	 *
	 * @return void
	 */
	public function ajax_search_accom_guests(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		global $wpdb;

		$term  = self::get_post_text( 'term' );
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, phone_number, id_number, guest_status
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
	 * AJAX: register an accommodation visit.
	 *
	 * @return void
	 */
	public function ajax_register_accom_visit(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::register_visit(
			self::get_post_int( 'guest_id' ),
			self::get_post_text( 'check_in_date' ),
			self::get_post_text( 'check_out_date' ) ?: null,
			self::get_post_text( 'room_number' ) ?: null
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Accommodation visit registered.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: check in.
	 *
	 * @return void
	 */
	public function ajax_checkin_accom(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::checkin( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Guest checked in.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: check out.
	 *
	 * @return void
	 */
	public function ajax_checkout_accom(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::checkout( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Guest checked out.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get accommodation visits (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_accom_visits(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_ACCOMMODATION );

		$result = self::get_visits(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	// =====================================================================
	// HELPERS
	// =====================================================================

	/**
	 * Validate & sanitize accommodation guest input.
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

		if ( isset( $data['guest_status'] ) ) {
			$valid_statuses = array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, VMS_Config::STATUS_BANNED );
			if ( in_array( $data['guest_status'], $valid_statuses, true ) ) {
				$out['guest_status'] = $data['guest_status'];
			}
		}

		return $out;
	}
}
