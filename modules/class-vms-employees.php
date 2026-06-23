<?php
/**
 * Employee management module.
 *
 * Handles the complete employee lifecycle: registration, profile updates,
 * search, and deletion. All writes trigger cache invalidation, audit
 * logging, and notifications automatically.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Employees module.
 */
final class VMS_Employees extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Employee CRUD.
		add_action( 'wp_ajax_vms_register_employee', array( $this, 'ajax_register_employee' ) );
		add_action( 'wp_ajax_vms_update_employee', array( $this, 'ajax_update_employee' ) );
		add_action( 'wp_ajax_vms_delete_employee', array( $this, 'ajax_delete_employee' ) );
		add_action( 'wp_ajax_vms_get_employees', array( $this, 'ajax_get_employees' ) );
		add_action( 'wp_ajax_vms_search_employees', array( $this, 'ajax_search_employees' ) );
	}

	// =====================================================================
	// EMPLOYEE CRUD
	// =====================================================================

	/**
	 * Create an employee record.
	 *
	 * @param array $data Employee data.
	 * @return int|\WP_Error Employee ID or error.
	 */
	public static function create_employee( array $data ) {
		global $wpdb;

		$validated = self::validate_employee_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Check for duplicate employee number.
		$existing = self::find_by_employee_number( $validated['employee_number'] );
		if ( $existing ) {
			return new \WP_Error(
				'duplicate_employee_number',
				__( 'An employee with this employee number already exists.', 'vms-plugin' ),
				array( 'existing_id' => $existing['id'] )
			);
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'wp_user_id'      => $validated['wp_user_id'],
				'employee_number' => $validated['employee_number'],
				'first_name'      => $validated['first_name'],
				'last_name'       => $validated['last_name'],
				'email'           => $validated['email'],
				'phone_number'    => $validated['phone_number'],
				'id_number'       => $validated['id_number'],
				'department'      => $validated['department'],
				'position'        => $validated['position'],
				'employee_status' => VMS_Config::STATUS_ACTIVE,
				'hire_date'       => $validated['hire_date'],
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create employee record.', 'vms-plugin' ) );
		}

		$employee_id = $wpdb->insert_id;

		VMS_Cache::bust( 'employees' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_EMPLOYEE, 'employee', $employee_id, $validated );

		/**
		 * Fires after an employee is created.
		 *
		 * @since 2.0.0
		 * @param int   $employee_id Employee ID.
		 * @param array $data        Employee data.
		 */
		do_action( 'vms_employee_created', $employee_id, $validated );

		return $employee_id;
	}

	/**
	 * Update an employee record.
	 *
	 * @param int   $employee_id Employee ID.
	 * @param array $data        Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_employee( int $employee_id, array $data ) {
		global $wpdb;

		$old = self::get_employee( $employee_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Employee not found.', 'vms-plugin' ) );
		}

		$validated = self::validate_employee_data( $data, $employee_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only update fields that were provided.
		$update = array_intersect_key( $validated, $data );
		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $employee_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update employee.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'employees' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_EMPLOYEE, 'employee', $employee_id, $old, $update );

		do_action( 'vms_employee_updated', $employee_id, $update, $old );

		return true;
	}

	/**
	 * Delete an employee.
	 *
	 * @param int $employee_id Employee ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_employee( int $employee_id ) {
		global $wpdb;

		$employee = self::get_employee( $employee_id );
		if ( ! $employee ) {
			return new \WP_Error( 'not_found', __( 'Employee not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $employee_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_delete_failed', __( 'Failed to delete employee.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'employees' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_EMPLOYEE, 'employee', $employee_id, $employee );

		do_action( 'vms_employee_deleted', $employee_id, $employee );

		return true;
	}

	/**
	 * Get a single employee by ID (cached).
	 *
	 * @param int $employee_id Employee ID.
	 * @return array|null
	 */
	public static function get_employee( int $employee_id ): ?array {
		return VMS_Cache::cached(
			"employees:id_{$employee_id}",
			static function () use ( $employee_id ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $employee_id ),
					ARRAY_A
				);

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Find employee by employee number (cached).
	 *
	 * @param string $employee_number Employee number.
	 * @return array|null
	 */
	public static function find_by_employee_number( string $employee_number ): ?array {
		$employee_number = sanitize_text_field( $employee_number );

		return VMS_Cache::cached(
			'employees:empnum_' . md5( $employee_number ),
			static function () use ( $employee_number ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE employee_number = %s", $employee_number ),
					ARRAY_A
				);

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Find employee by WordPress user ID (cached).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|null
	 */
	public static function find_by_wp_user( int $wp_user_id ): ?array {
		return VMS_Cache::cached(
			"employees:wp_user_{$wp_user_id}",
			static function () use ( $wp_user_id ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE wp_user_id = %d", $wp_user_id ),
					ARRAY_A
				);

				return $row ?: null;
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Get all employees (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_employees( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "employees:list_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$table  = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );
				$where  = array( '1=1' );
				$params = array();

				if ( $status ) {
					$where[]  = 'employee_status = %s';
					$params[] = sanitize_text_field( $status );
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
				$rows_sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY last_name ASC, first_name ASC LIMIT %d OFFSET %d";
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
	// AJAX HANDLERS
	// =====================================================================

	/**
	 * AJAX: register an employee.
	 *
	 * @return void
	 */
	public function ajax_register_employee(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$result = self::create_employee(
			array(
				'wp_user_id'      => self::get_post_int( 'wp_user_id' ),
				'employee_number' => self::get_post_text( 'employee_number' ),
				'first_name'      => self::get_post_text( 'first_name' ),
				'last_name'       => self::get_post_text( 'last_name' ),
				'email'           => self::get_post_email( 'email' ),
				'phone_number'    => self::get_post_text( 'phone_number' ),
				'id_number'       => self::get_post_text( 'id_number' ),
				'department'      => self::get_post_text( 'department' ),
				'position'        => self::get_post_text( 'position' ),
				'hire_date'       => self::get_post_text( 'hire_date' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		$employee = self::get_employee( $result );
		wp_send_json_success( array( 'employee' => $employee, 'message' => __( 'Employee registered successfully.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update an employee.
	 *
	 * @return void
	 */
	public function ajax_update_employee(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$employee_id = self::get_post_int( 'employee_id' );
		$data        = array_filter(
			array(
				'employee_number' => self::get_post_text( 'employee_number' ),
				'first_name'      => self::get_post_text( 'first_name' ),
				'last_name'       => self::get_post_text( 'last_name' ),
				'email'           => self::get_post_email( 'email' ),
				'phone_number'    => self::get_post_text( 'phone_number' ),
				'id_number'       => self::get_post_text( 'id_number' ),
				'department'      => self::get_post_text( 'department' ),
				'position'        => self::get_post_text( 'position' ),
				'hire_date'       => self::get_post_text( 'hire_date' ),
			),
			static fn( $v ) => '' !== $v
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['wp_user_id'] ) ) {
			$data['wp_user_id'] = self::get_post_int( 'wp_user_id' );
		}
		if ( isset( $_POST['employee_status'] ) ) {
			$data['employee_status'] = self::get_post_text( 'employee_status' );
		}
		// phpcs:enable

		$result = self::update_employee( $employee_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'employee' => self::get_employee( $employee_id ), 'message' => __( 'Employee updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: delete an employee.
	 *
	 * @return void
	 */
	public function ajax_delete_employee(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$result = self::delete_employee( self::get_post_int( 'employee_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Employee deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get employees (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_employees(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$result = self::get_employees(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: search employees by name/employee number/department.
	 *
	 * @return void
	 */
	public function ajax_search_employees(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		global $wpdb;

		$term  = self::get_post_text( 'term' );
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, employee_number, first_name, last_name, email, department, position, employee_status
				 FROM `{$table}`
				 WHERE first_name LIKE %s OR last_name LIKE %s OR employee_number LIKE %s OR department LIKE %s OR id_number LIKE %s
				 ORDER BY last_name ASC, first_name ASC
				 LIMIT 20",
				$like,
				$like,
				$like,
				$like,
				$like
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'results' => $results ) );
	}

	// =====================================================================
	// HELPERS
	// =====================================================================

	/**
	 * Validate & sanitize employee input.
	 *
	 * @param array $data       Raw input.
	 * @param int   $exclude_id Employee ID to exclude from uniqueness checks (for updates).
	 * @return array|\WP_Error
	 */
	private static function validate_employee_data( array $data, int $exclude_id = 0 ) {
		$out = array();

		// Required fields.
		if ( isset( $data['employee_number'] ) ) {
			$out['employee_number'] = sanitize_text_field( $data['employee_number'] );
			if ( empty( $out['employee_number'] ) ) {
				return new \WP_Error( 'missing_employee_number', __( 'Employee number is required.', 'vms-plugin' ) );
			}
		}

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

		// Optional fields.
		if ( isset( $data['wp_user_id'] ) ) {
			$out['wp_user_id'] = absint( $data['wp_user_id'] ) ?: null;
		}

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			$out['email'] = $email && is_email( $email ) ? $email : null;
		}

		if ( isset( $data['phone_number'] ) ) {
			$out['phone_number'] = sanitize_text_field( $data['phone_number'] ) ?: null;
		}

		if ( isset( $data['id_number'] ) ) {
			$id = preg_replace( '/[^A-Za-z0-9]/', '', $data['id_number'] );
			$out['id_number'] = $id ?: null;
		}

		if ( isset( $data['department'] ) ) {
			$out['department'] = sanitize_text_field( $data['department'] ) ?: null;
		}

		if ( isset( $data['position'] ) ) {
			$out['position'] = sanitize_text_field( $data['position'] ) ?: null;
		}

		if ( isset( $data['employee_status'] ) ) {
			$status = sanitize_text_field( $data['employee_status'] );
			$valid  = array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, 'terminated' );
			if ( in_array( $status, $valid, true ) ) {
				$out['employee_status'] = $status;
			}
		}

		if ( isset( $data['hire_date'] ) ) {
			$hire_date = sanitize_text_field( $data['hire_date'] );
			$out['hire_date'] = $hire_date ? gmdate( 'Y-m-d', strtotime( $hire_date ) ) : null;
		}

		return $out;
	}
}
