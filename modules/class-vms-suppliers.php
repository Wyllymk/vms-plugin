<?php
/**
 * Supplier management module.
 *
 * Handles the complete supplier lifecycle: registration, profile updates,
 * visit scheduling, sign-in/out, and deletion. All writes trigger cache
 * invalidation, audit logging, and notifications automatically.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Suppliers module.
 */
final class VMS_Suppliers extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Supplier CRUD.
		add_action( 'wp_ajax_vms_register_supplier', array( $this, 'ajax_register_supplier' ) );
		add_action( 'wp_ajax_vms_update_supplier', array( $this, 'ajax_update_supplier' ) );
		add_action( 'wp_ajax_vms_delete_supplier', array( $this, 'ajax_delete_supplier' ) );
		add_action( 'wp_ajax_vms_get_suppliers', array( $this, 'ajax_get_suppliers' ) );
		add_action( 'wp_ajax_vms_search_suppliers', array( $this, 'ajax_search_suppliers' ) );

		// Visit lifecycle.
		add_action( 'wp_ajax_vms_register_supplier_visit', array( $this, 'ajax_register_supplier_visit' ) );
		add_action( 'wp_ajax_vms_signin_supplier', array( $this, 'ajax_signin_supplier' ) );
		add_action( 'wp_ajax_vms_signout_supplier', array( $this, 'ajax_signout_supplier' ) );
		add_action( 'wp_ajax_vms_get_supplier_visits', array( $this, 'ajax_get_supplier_visits' ) );
	}

	// =====================================================================
	// SUPPLIER CRUD
	// =====================================================================

	/**
	 * Create a supplier record.
	 *
	 * @param array $data Supplier data.
	 * @return int|\WP_Error Supplier ID or error.
	 */
	public static function create_supplier( array $data ) {
		global $wpdb;

		$validated = self::validate_supplier_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Check for duplicate phone.
		$existing = self::find_by_phone( $validated['phone_number'] );
		if ( $existing ) {
			return new \WP_Error(
				'duplicate_phone',
				__( 'A supplier with this phone number already exists.', 'vms-plugin' ),
				array( 'existing_id' => $existing['id'] )
			);
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'company_name'       => $validated['company_name'],
				'contact_first_name' => $validated['contact_first_name'],
				'contact_last_name'  => $validated['contact_last_name'],
				'email'              => $validated['email'],
				'phone_number'       => $validated['phone_number'],
				'id_number'          => $validated['id_number'],
				'vehicle_reg'        => $validated['vehicle_reg'],
				'supplier_status'    => VMS_Config::STATUS_ACTIVE,
				'receive_emails'     => $validated['receive_emails'],
				'receive_messages'   => $validated['receive_messages'],
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create supplier record.', 'vms-plugin' ) );
		}

		$supplier_id = $wpdb->insert_id;

		VMS_Cache::bust( 'suppliers' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_SUPPLIER, 'supplier', $supplier_id, $validated );

		/**
		 * Fires after a supplier is created.
		 *
		 * @since 2.0.0
		 * @param int   $supplier_id Supplier ID.
		 * @param array $data        Supplier data.
		 */
		do_action( 'vms_supplier_created', $supplier_id, $validated );

		return $supplier_id;
	}

	/**
	 * Update a supplier record.
	 *
	 * @param int   $supplier_id Supplier ID.
	 * @param array $data        Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_supplier( int $supplier_id, array $data ) {
		global $wpdb;

		$old = self::get_supplier( $supplier_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Supplier not found.', 'vms-plugin' ) );
		}

		$validated = self::validate_supplier_data( $data, $supplier_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only update fields that were provided.
		$update = array_intersect_key( $validated, $data );
		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $supplier_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update supplier.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'suppliers' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_SUPPLIER, 'supplier', $supplier_id, $old, $update );

		do_action( 'vms_supplier_updated', $supplier_id, $update, $old );

		return true;
	}

	/**
	 * Delete a supplier (cascades to visits via FK).
	 *
	 * @param int $supplier_id Supplier ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_supplier( int $supplier_id ) {
		global $wpdb;

		$supplier = self::get_supplier( $supplier_id );
		if ( ! $supplier ) {
			return new \WP_Error( 'not_found', __( 'Supplier not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $supplier_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_delete_failed', __( 'Failed to delete supplier.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'suppliers' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_SUPPLIER, 'supplier', $supplier_id, $supplier );

		do_action( 'vms_supplier_deleted', $supplier_id, $supplier );

		return true;
	}

	/**
	 * Get a single supplier by ID (cached).
	 *
	 * @param int $supplier_id Supplier ID.
	 * @return array|null
	 */
	public static function get_supplier( int $supplier_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $supplier_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find supplier by phone number (cached).
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
			'suppliers:phone_' . md5( $normalized ),
			static function () use ( $normalized ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

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
	 * Get all suppliers (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_suppliers( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "suppliers:list_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$table  = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );
				$where  = array( '1=1' );
				$params = array();

				if ( $status && VMS_Config::is_valid_status( $status, 'guest' ) ) {
					$where[]  = 'supplier_status = %s';
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
				$rows_sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY company_name ASC LIMIT %d OFFSET %d";
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
	 * Register a supplier visit.
	 *
	 * @param int         $supplier_id Supplier ID.
	 * @param string      $visit_date  Visit date (Y-m-d).
	 * @param string|null $purpose     Visit purpose.
	 * @return array|\WP_Error Visit record or error.
	 */
	public static function register_visit( int $supplier_id, string $visit_date, ?string $purpose = null ) {
		global $wpdb;

		$supplier = self::get_supplier( $supplier_id );
		if ( ! $supplier ) {
			return new \WP_Error( 'supplier_not_found', __( 'Supplier not found.', 'vms-plugin' ) );
		}

		if ( VMS_Config::STATUS_BANNED === $supplier['supplier_status'] ) {
			return new \WP_Error( 'supplier_banned', __( 'This supplier is banned and cannot be registered for visits.', 'vms-plugin' ) );
		}

		// Validate date.
		$visit_date = gmdate( 'Y-m-d', strtotime( $visit_date ) );
		if ( $visit_date < current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'past_date', __( 'Cannot register a visit for a past date.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'supplier_id' => $supplier_id,
				'visit_date'  => $visit_date,
				'purpose'     => $purpose ? sanitize_text_field( $purpose ) : null,
				'status'      => VMS_Config::VISIT_APPROVED,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to register supplier visit.', 'vms-plugin' ) );
		}

		$visit_id = $wpdb->insert_id;
		$visit    = self::get_visit( $visit_id );

		VMS_Cache::bust( 'suppliers' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_SUPPLIER, 'supplier_visit', $visit_id, $visit ?? array() );

		do_action( 'vms_supplier_visit_registered', $visit_id, $visit );

		return $visit ?? array( 'id' => $visit_id );
	}

	/**
	 * Sign in a supplier.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function signin( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Supplier visit not found.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'already_signed_in', __( 'Supplier is already signed in.', 'vms-plugin' ) );
		}

		if ( VMS_Config::VISIT_APPROVED !== $visit['status'] ) {
			return new \WP_Error( 'not_approved', __( 'Only approved visits can be signed in.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'sign_in_time' => $now ),
			array( 'id' => $visit_id ),
			array( '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'suppliers' );

		$visit['sign_in_time'] = $now;

		VMS_Audit_Trail::log(
			'supplier_signed_in',
			VMS_Audit_Trail::CAT_SUPPLIER,
			'supplier_visit',
			$visit_id,
			null,
			array( 'sign_in_time' => $now )
		);

		do_action( 'vms_supplier_signed_in', $visit_id, $visit );

		return $visit;
	}

	/**
	 * Sign out a supplier.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function signout( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Supplier visit not found.', 'vms-plugin' ) );
		}

		if ( empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'not_signed_in', __( 'Supplier has not signed in.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_out_time'] ) ) {
			return new \WP_Error( 'already_signed_out', __( 'Supplier has already signed out.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
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

		VMS_Cache::bust( 'suppliers' );

		$visit['sign_out_time'] = $now;
		$visit['status']        = VMS_Config::VISIT_COMPLETED;

		VMS_Audit_Trail::log(
			'supplier_signed_out',
			VMS_Audit_Trail::CAT_SUPPLIER,
			'supplier_visit',
			$visit_id,
			null,
			array( 'sign_out_time' => $now )
		);

		do_action( 'vms_supplier_signed_out', $visit_id, $visit );

		return $visit;
	}

	/**
	 * Get a single supplier visit by ID.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|null
	 */
	public static function get_visit( int $visit_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $visit_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get supplier visits (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_visits( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "suppliers:visits_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$visits_table    = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
				$suppliers_table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

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
						"SELECT v.*, s.company_name, s.contact_first_name, s.contact_last_name, s.phone_number, s.supplier_status
						 FROM `{$visits_table}` v
						 INNER JOIN `{$suppliers_table}` s ON s.id = v.supplier_id
						 WHERE {$where_sql}
						 ORDER BY v.visit_date DESC, v.created_at DESC
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
	 * AJAX: register a supplier.
	 *
	 * @return void
	 */
	public function ajax_register_supplier(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::create_supplier(
			array(
				'company_name'       => self::get_post_text( 'company_name' ),
				'contact_first_name' => self::get_post_text( 'contact_first_name' ),
				'contact_last_name'  => self::get_post_text( 'contact_last_name' ),
				'email'              => self::get_post_email( 'email' ),
				'phone_number'       => self::get_post_text( 'phone_number' ),
				'id_number'          => self::get_post_text( 'id_number' ),
				'vehicle_reg'        => self::get_post_text( 'vehicle_reg' ),
				'receive_emails'     => self::get_post_int( 'receive_emails' ),
				'receive_messages'   => self::get_post_int( 'receive_messages' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		$supplier = self::get_supplier( $result );
		wp_send_json_success( array( 'supplier' => $supplier, 'message' => __( 'Supplier registered successfully.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update a supplier.
	 *
	 * @return void
	 */
	public function ajax_update_supplier(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$supplier_id = self::get_post_int( 'supplier_id' );
		$data        = array_filter(
			array(
				'company_name'       => self::get_post_text( 'company_name' ),
				'contact_first_name' => self::get_post_text( 'contact_first_name' ),
				'contact_last_name'  => self::get_post_text( 'contact_last_name' ),
				'email'              => self::get_post_email( 'email' ),
				'phone_number'       => self::get_post_text( 'phone_number' ),
				'id_number'          => self::get_post_text( 'id_number' ),
				'vehicle_reg'        => self::get_post_text( 'vehicle_reg' ),
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
		if ( isset( $_POST['supplier_status'] ) ) {
			$data['supplier_status'] = self::get_post_text( 'supplier_status' );
		}
		// phpcs:enable

		$result = self::update_supplier( $supplier_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'supplier' => self::get_supplier( $supplier_id ), 'message' => __( 'Supplier updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: delete a supplier.
	 *
	 * @return void
	 */
	public function ajax_delete_supplier(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::delete_supplier( self::get_post_int( 'supplier_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Supplier deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get suppliers (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_suppliers(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::get_suppliers(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: search suppliers by company name/contact name/phone.
	 *
	 * @return void
	 */
	public function ajax_search_suppliers(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		global $wpdb;

		$term  = self::get_post_text( 'term' );
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, company_name, contact_first_name, contact_last_name, phone_number, supplier_status
				 FROM `{$table}`
				 WHERE company_name LIKE %s OR contact_first_name LIKE %s OR contact_last_name LIKE %s OR phone_number LIKE %s
				 ORDER BY company_name ASC
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
	 * AJAX: register a supplier visit.
	 *
	 * @return void
	 */
	public function ajax_register_supplier_visit(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::register_visit(
			self::get_post_int( 'supplier_id' ),
			self::get_post_text( 'visit_date' ),
			self::get_post_text( 'purpose' ) ?: null
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Supplier visit registered.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign in a supplier.
	 *
	 * @return void
	 */
	public function ajax_signin_supplier(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::signin( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Supplier signed in.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign out a supplier.
	 *
	 * @return void
	 */
	public function ajax_signout_supplier(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

		$result = self::signout( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Supplier signed out.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get supplier visits (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_supplier_visits(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_SUPPLIERS );

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
	 * Validate & sanitize supplier input.
	 *
	 * @param array $data       Raw input.
	 * @param int   $exclude_id Supplier ID to exclude from uniqueness checks (for updates).
	 * @return array|\WP_Error
	 */
	private static function validate_supplier_data( array $data, int $exclude_id = 0 ) {
		$out = array();

		// Required fields.
		if ( isset( $data['company_name'] ) ) {
			$out['company_name'] = sanitize_text_field( $data['company_name'] );
			if ( empty( $out['company_name'] ) ) {
				return new \WP_Error( 'missing_company_name', __( 'Company name is required.', 'vms-plugin' ) );
			}
		}

		if ( isset( $data['contact_first_name'] ) ) {
			$out['contact_first_name'] = sanitize_text_field( $data['contact_first_name'] );
			if ( empty( $out['contact_first_name'] ) ) {
				return new \WP_Error( 'missing_contact_first_name', __( 'Contact first name is required.', 'vms-plugin' ) );
			}
		}

		if ( isset( $data['contact_last_name'] ) ) {
			$out['contact_last_name'] = sanitize_text_field( $data['contact_last_name'] );
			if ( empty( $out['contact_last_name'] ) ) {
				return new \WP_Error( 'missing_contact_last_name', __( 'Contact last name is required.', 'vms-plugin' ) );
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

		if ( isset( $data['vehicle_reg'] ) ) {
			$out['vehicle_reg'] = sanitize_text_field( $data['vehicle_reg'] ) ?: null;
		}

		if ( isset( $data['supplier_status'] ) ) {
			$status = sanitize_text_field( $data['supplier_status'] );
			if ( VMS_Config::is_valid_status( $status, 'guest' ) ) {
				$out['supplier_status'] = $status;
			}
		}

		$out['receive_emails']   = ! empty( $data['receive_emails'] ) ? 1 : 0;
		$out['receive_messages'] = ! empty( $data['receive_messages'] ) ? 1 : 0;

		return $out;
	}
}
