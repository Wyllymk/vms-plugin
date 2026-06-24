<?php
/**
 * Reciprocation management module.
 *
 * Handles the complete reciprocating club lifecycle: club registration,
 * member management, visit scheduling, sign-in/out, and deletion.
 * All writes trigger cache invalidation, audit logging, and
 * notifications automatically.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Reciprocation module.
 */
final class VMS_Reciprocation extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Club CRUD.
		add_action( 'wp_ajax_vms_register_recip_club', array( $this, 'ajax_register_recip_club' ) );
		add_action( 'wp_ajax_vms_update_recip_club', array( $this, 'ajax_update_recip_club' ) );
		add_action( 'wp_ajax_vms_delete_recip_club', array( $this, 'ajax_delete_recip_club' ) );
		add_action( 'wp_ajax_vms_get_recip_clubs', array( $this, 'ajax_get_recip_clubs' ) );

		// Member CRUD.
		add_action( 'wp_ajax_vms_register_recip_member', array( $this, 'ajax_register_recip_member' ) );
		add_action( 'wp_ajax_vms_update_recip_member', array( $this, 'ajax_update_recip_member' ) );
		add_action( 'wp_ajax_vms_get_recip_members', array( $this, 'ajax_get_recip_members' ) );

		// Visit lifecycle.
		add_action( 'wp_ajax_vms_register_recip_visit', array( $this, 'ajax_register_recip_visit' ) );
		add_action( 'wp_ajax_vms_signin_recip', array( $this, 'ajax_signin_recip' ) );
		add_action( 'wp_ajax_vms_signout_recip', array( $this, 'ajax_signout_recip' ) );
		add_action( 'wp_ajax_vms_get_recip_visits', array( $this, 'ajax_get_recip_visits' ) );
	}

	// =====================================================================
	// CLUB CRUD
	// =====================================================================

	/**
	 * Create a reciprocating club record.
	 *
	 * @param array $data Club data.
	 * @return int|\WP_Error Club ID or error.
	 */
	public static function create_club( array $data ) {
		global $wpdb;

		$validated = self::validate_club_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

		// Check for duplicate club name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE club_name = %s LIMIT 1", $validated['club_name'] )
		);
		if ( $existing ) {
			return new \WP_Error(
				'duplicate_club',
				__( 'A reciprocating club with this name already exists.', 'vms-plugin' ),
				array( 'existing_id' => $existing )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'club_name'        => $validated['club_name'],
				'club_email'       => $validated['club_email'],
				'club_phone'       => $validated['club_phone'],
				'club_website'     => $validated['club_website'],
				'club_address'     => $validated['club_address'],
				'country'          => $validated['country'],
				'is_reciprocating' => $validated['is_reciprocating'],
				'club_status'      => VMS_Config::STATUS_ACTIVE,
				'notes'            => $validated['notes'],
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create reciprocating club record.', 'vms-plugin' ) );
		}

		$club_id = $wpdb->insert_id;

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_RECIP, 'recip_club', $club_id, $validated );

		/**
		 * Fires after a reciprocating club is created.
		 *
		 * @since 2.0.0
		 * @param int   $club_id Club ID.
		 * @param array $data    Club data.
		 */
		do_action( 'vms_recip_club_created', $club_id, $validated );

		return $club_id;
	}

	/**
	 * Update a reciprocating club record.
	 *
	 * @param int   $club_id Club ID.
	 * @param array $data    Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_club( int $club_id, array $data ) {
		global $wpdb;

		$old = self::get_club( $club_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Reciprocating club not found.', 'vms-plugin' ) );
		}

		$validated = self::validate_club_data( $data, $club_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only update fields that were provided.
		$update = array_intersect_key( $validated, $data );
		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $club_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update reciprocating club.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_RECIP, 'recip_club', $club_id, $old, $update );

		do_action( 'vms_recip_club_updated', $club_id, $update, $old );

		return true;
	}

	/**
	 * Delete a reciprocating club (cascades to members and visits via FK).
	 *
	 * @param int $club_id Club ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_club( int $club_id ) {
		global $wpdb;

		$club = self::get_club( $club_id );
		if ( ! $club ) {
			return new \WP_Error( 'not_found', __( 'Reciprocating club not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $club_id ), array( '%d' ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_delete_failed', __( 'Failed to delete reciprocating club.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_RECIP, 'recip_club', $club_id, $club );

		do_action( 'vms_recip_club_deleted', $club_id, $club );

		return true;
	}

	/**
	 * Get a single club by ID (cached).
	 *
	 * @param int $club_id Club ID.
	 * @return array|null
	 */
	public static function get_club( int $club_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $club_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all reciprocating clubs (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_clubs( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "recip:clubs_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$table  = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );
				$where  = array( '1=1' );
				$params = array();

				if ( $status && VMS_Config::is_valid_status( $status, 'guest' ) ) {
					$where[]  = 'club_status = %s';
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
				$rows_sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY club_name ASC LIMIT %d OFFSET %d";
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
	// MEMBER CRUD
	// =====================================================================

	/**
	 * Create a reciprocating member record.
	 *
	 * @param array $data Member data.
	 * @return int|\WP_Error Member ID or error.
	 */
	public static function create_member( array $data ) {
		global $wpdb;

		$validated = self::validate_member_data( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Verify club exists.
		$club = self::get_club( (int) $validated['club_id'] );
		if ( ! $club ) {
			return new \WP_Error( 'club_not_found', __( 'Reciprocating club not found.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'club_id'          => $validated['club_id'],
				'first_name'       => $validated['first_name'],
				'last_name'        => $validated['last_name'],
				'email'            => $validated['email'],
				'phone_number'     => $validated['phone_number'],
				'id_number'        => $validated['id_number'],
				'member_number'    => $validated['member_number'],
				'member_status'    => VMS_Config::STATUS_ACTIVE,
				'receive_emails'   => $validated['receive_emails'],
				'receive_messages' => $validated['receive_messages'],
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to create reciprocating member record.', 'vms-plugin' ) );
		}

		$member_id = $wpdb->insert_id;

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_RECIP, 'recip_member', $member_id, $validated );

		/**
		 * Fires after a reciprocating member is created.
		 *
		 * @since 2.0.0
		 * @param int   $member_id Member ID.
		 * @param array $data      Member data.
		 */
		do_action( 'vms_recip_member_created', $member_id, $validated );

		return $member_id;
	}

	/**
	 * Update a reciprocating member record.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $data      Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_member( int $member_id, array $data ) {
		global $wpdb;

		$old = self::get_member( $member_id );
		if ( ! $old ) {
			return new \WP_Error( 'not_found', __( 'Reciprocating member not found.', 'vms-plugin' ) );
		}

		$validated = self::validate_member_data( $data, $member_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only update fields that were provided.
		$update = array_intersect_key( $validated, $data );
		if ( empty( $update ) ) {
			return true; // Nothing to update.
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $member_id ) );

		if ( false === $result ) {
			return new \WP_Error( 'db_update_failed', __( 'Failed to update reciprocating member.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_RECIP, 'recip_member', $member_id, $old, $update );

		do_action( 'vms_recip_member_updated', $member_id, $update, $old );

		return true;
	}

	/**
	 * Get a single member by ID (cached).
	 *
	 * @param int $member_id Member ID.
	 * @return array|null
	 */
	public static function get_member( int $member_id ): ?array {
		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $member_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get members for a club (cached, paginated).
	 *
	 * @param int    $club_id  Club ID.
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_members( int $club_id = 0, int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "recip:members_{$club_id}_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $club_id, $per_page, $page, $status ) {
				global $wpdb;

				$members_table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );
				$clubs_table   = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

				$where  = array( '1=1' );
				$params = array();

				if ( $club_id > 0 ) {
					$where[]  = 'm.club_id = %d';
					$params[] = $club_id;
				}

				if ( $status && VMS_Config::is_valid_status( $status, 'guest' ) ) {
					$where[]  = 'm.member_status = %s';
					$params[] = $status;
				}

				$where_sql = implode( ' AND ', $where );
				$per_page  = max( 1, min( 500, $per_page ) );
				$offset    = max( 0, ( $page - 1 ) * $per_page );

				// Count.
				$count_sql = "SELECT COUNT(*) FROM `{$members_table}` m WHERE {$where_sql}";
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

				// Rows.
				$params[] = $per_page;
				$params[] = $offset;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT m.*, c.club_name
						 FROM `{$members_table}` m
						 INNER JOIN `{$clubs_table}` c ON c.id = m.club_id
						 WHERE {$where_sql}
						 ORDER BY m.last_name ASC, m.first_name ASC
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
	// VISIT LIFECYCLE
	// =====================================================================

	/**
	 * Register a reciprocating member visit.
	 *
	 * @param int    $member_id  Member ID.
	 * @param string $visit_date Visit date (Y-m-d).
	 * @return array|\WP_Error Visit record or error.
	 */
	public static function register_visit( int $member_id, string $visit_date, string $visit_reason = '' ) {
		global $wpdb;

		$member = self::get_member( $member_id );
		if ( ! $member ) {
			return new \WP_Error( 'member_not_found', __( 'Reciprocating member not found.', 'vms-plugin' ) );
		}

		if ( VMS_Config::STATUS_BANNED === $member['member_status'] ) {
			return new \WP_Error( 'member_banned', __( 'This member is banned and cannot be registered for visits.', 'vms-plugin' ) );
		}

		// Validate date.
		$visit_date = gmdate( 'Y-m-d', strtotime( $visit_date ) );
		if ( $visit_date < current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'past_date', __( 'Cannot register a visit for a past date.', 'vms-plugin' ) );
		}

		// Check for duplicate (same member + date).
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$duplicate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE member_id = %d AND visit_date = %s AND status NOT IN ('cancelled') LIMIT 1",
				$member_id,
				$visit_date
			)
		);
		if ( $duplicate ) {
			return new \WP_Error( 'duplicate_visit', __( 'Member already has a visit registered for this date.', 'vms-plugin' ) );
		}

		// Sanitize visit reason.
		$valid_reasons = array( 'casual', 'tournament' );
		$visit_reason  = in_array( strtolower( $visit_reason ), $valid_reasons, true ) ? strtolower( $visit_reason ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'member_id'    => $member_id,
				'visit_date'   => $visit_date,
				'visit_reason' => $visit_reason ?: null,
				'status'       => VMS_Config::VISIT_APPROVED,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to register reciprocating visit.', 'vms-plugin' ) );
		}

		$visit_id = $wpdb->insert_id;
		$visit    = self::get_visit( $visit_id );

		VMS_Cache::bust( 'recip' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_RECIP, 'recip_visit', $visit_id, $visit ?? array() );

		do_action( 'vms_recip_visit_registered', $visit_id, $visit );

		return $visit ?? array( 'id' => $visit_id );
	}

	/**
	 * Sign in a reciprocating member.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function signin( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Reciprocating visit not found.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'already_signed_in', __( 'Member is already signed in.', 'vms-plugin' ) );
		}

		if ( VMS_Config::VISIT_APPROVED !== $visit['status'] ) {
			return new \WP_Error( 'not_approved', __( 'Only approved visits can be signed in.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'sign_in_time' => $now ),
			array( 'id' => $visit_id ),
			array( '%s' ),
			array( '%d' )
		);

		VMS_Cache::bust( 'recip' );

		$visit['sign_in_time'] = $now;

		VMS_Audit_Trail::log(
			'recip_member_signed_in',
			VMS_Audit_Trail::CAT_RECIP,
			'recip_visit',
			$visit_id,
			null,
			array( 'sign_in_time' => $now )
		);

		do_action( 'vms_recip_member_signed_in', $visit_id, $visit );

		return $visit;
	}

	/**
	 * Sign out a reciprocating member.
	 *
	 * @param int $visit_id Visit ID.
	 * @return array|\WP_Error Updated visit or error.
	 */
	public static function signout( int $visit_id ) {
		global $wpdb;

		$visit = self::get_visit( $visit_id );
		if ( ! $visit ) {
			return new \WP_Error( 'not_found', __( 'Reciprocating visit not found.', 'vms-plugin' ) );
		}

		if ( empty( $visit['sign_in_time'] ) ) {
			return new \WP_Error( 'not_signed_in', __( 'Member has not signed in.', 'vms-plugin' ) );
		}

		if ( ! empty( $visit['sign_out_time'] ) ) {
			return new \WP_Error( 'already_signed_out', __( 'Member has already signed out.', 'vms-plugin' ) );
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
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

		VMS_Cache::bust( 'recip' );

		$visit['sign_out_time'] = $now;
		$visit['status']        = VMS_Config::VISIT_COMPLETED;

		VMS_Audit_Trail::log(
			'recip_member_signed_out',
			VMS_Audit_Trail::CAT_RECIP,
			'recip_visit',
			$visit_id,
			null,
			array( 'sign_out_time' => $now )
		);

		do_action( 'vms_recip_member_signed_out', $visit_id, $visit );

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
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $visit_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get reciprocating visits (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_visits( int $per_page = 50, int $page = 1, string $status = '' ): array {
		$cache_key = "recip:visits_{$per_page}_{$page}_{$status}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status ) {
				global $wpdb;

				$visits_table  = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
				$members_table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );
				$clubs_table   = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

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
						"SELECT v.*, v.visit_reason, m.first_name, m.last_name, m.id_number, m.member_number, m.member_status, c.club_name
						 FROM `{$visits_table}` v
						 INNER JOIN `{$members_table}` m ON m.id = v.member_id
						 INNER JOIN `{$clubs_table}` c ON c.id = m.club_id
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
	 * AJAX: register a reciprocating club.
	 *
	 * @return void
	 */
	public function ajax_register_recip_club(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		// Accept either 'club_status' (direct) or 'status' (from the frontend form).
		$status = self::get_post_text( 'club_status' ) ?: self::get_post_text( 'status' ) ?: 'active';

		$result = self::create_club(
			array(
				'club_name'        => self::get_post_text( 'club_name' ),
				'club_email'       => self::get_post_email( 'club_email' ),
				'club_phone'       => self::get_post_text( 'club_phone' ),
				'club_website'     => self::get_post_text( 'club_website' ),
				'club_address'     => self::get_post_text( 'club_address' ),
				'country'          => self::get_post_text( 'country' ),
				'club_status'      => $status,
				'is_reciprocating' => self::get_post_int( 'is_reciprocating' ) ?: 1,
				'notes'            => self::get_post_text( 'notes' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		$club = self::get_club( $result );
		wp_send_json_success( array( 'club' => $club, 'message' => __( 'Reciprocating club registered successfully.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update a reciprocating club.
	 *
	 * @return void
	 */
	public function ajax_update_recip_club(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$club_id = self::get_post_int( 'club_id' );
		$data    = array_filter(
			array(
				'club_name'    => self::get_post_text( 'club_name' ),
				'club_email'   => self::get_post_email( 'club_email' ),
				'club_phone'   => self::get_post_text( 'club_phone' ),
				'club_website' => self::get_post_text( 'club_website' ),
				'club_address' => self::get_post_text( 'club_address' ),
				'country'      => self::get_post_text( 'country' ),
				'notes'        => self::get_post_text( 'notes' ),
			),
			static fn( $v ) => '' !== $v
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['is_reciprocating'] ) ) {
			$data['is_reciprocating'] = self::get_post_int( 'is_reciprocating' );
		}
		if ( isset( $_POST['club_status'] ) ) {
			$data['club_status'] = self::get_post_text( 'club_status' );
		}
		// phpcs:enable

		$result = self::update_club( $club_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'club' => self::get_club( $club_id ), 'message' => __( 'Reciprocating club updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: delete a reciprocating club.
	 *
	 * @return void
	 */
	public function ajax_delete_recip_club(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::delete_club( self::get_post_int( 'club_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Reciprocating club deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get reciprocating clubs (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_recip_clubs(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::get_clubs(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: register a reciprocating member.
	 *
	 * @return void
	 */
	public function ajax_register_recip_member(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		// Accept 'member_status' or 'status' from the frontend form.
		$status = self::get_post_text( 'member_status' ) ?: self::get_post_text( 'status' ) ?: 'active';

		$result = self::create_member(
			array(
				'club_id'          => self::get_post_int( 'club_id' ),
				'first_name'       => self::get_post_text( 'first_name' ),
				'last_name'        => self::get_post_text( 'last_name' ),
				'email'            => self::get_post_email( 'email' ),
				'phone_number'     => self::get_post_text( 'phone_number' ) ?: self::get_post_text( 'phone' ),
				'id_number'        => self::get_post_text( 'id_number' ),
				'member_number'    => self::get_post_text( 'member_number' ),
				'member_status'    => $status,
				'receive_emails'   => self::get_post_int( 'receive_emails' ),
				'receive_messages' => self::get_post_int( 'receive_messages' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		$member = self::get_member( $result );
		wp_send_json_success( array( 'member' => $member, 'message' => __( 'Reciprocating member registered successfully.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update a reciprocating member.
	 *
	 * @return void
	 */
	public function ajax_update_recip_member(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$member_id = self::get_post_int( 'member_id' );
		$data      = array_filter(
			array(
				'first_name'    => self::get_post_text( 'first_name' ),
				'last_name'     => self::get_post_text( 'last_name' ),
				'email'         => self::get_post_email( 'email' ),
				'phone_number'  => self::get_post_text( 'phone_number' ),
				'id_number'     => self::get_post_text( 'id_number' ),
				'member_number' => self::get_post_text( 'member_number' ),
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
		if ( isset( $_POST['member_status'] ) ) {
			$data['member_status'] = self::get_post_text( 'member_status' );
		}
		// phpcs:enable

		$result = self::update_member( $member_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'member' => self::get_member( $member_id ), 'message' => __( 'Reciprocating member updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get reciprocating members (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_recip_members(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::get_members(
			self::get_post_int( 'club_id' ),
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: register a reciprocating visit.
	 *
	 * @return void
	 */
	public function ajax_register_recip_visit(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::register_visit(
			self::get_post_int( 'member_id' ),
			self::get_post_text( 'visit_date' ),
			self::get_post_text( 'visit_reason' )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Reciprocating visit registered.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign in a reciprocating member.
	 *
	 * @return void
	 */
	public function ajax_signin_recip(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::signin( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Member signed in.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: sign out a reciprocating member.
	 *
	 * @return void
	 */
	public function ajax_signout_recip(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

		$result = self::signout( self::get_post_int( 'visit_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'visit' => $result, 'message' => __( 'Member signed out.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get reciprocating visits (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_recip_visits(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_RECIPROCATION );

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
	 * Validate & sanitize club input.
	 *
	 * @param array $data       Raw input.
	 * @param int   $exclude_id Club ID to exclude from uniqueness checks (for updates).
	 * @return array|\WP_Error
	 */
	private static function validate_club_data( array $data, int $exclude_id = 0 ) {
		$out = array();

		if ( isset( $data['club_name'] ) ) {
			$out['club_name'] = sanitize_text_field( $data['club_name'] );
			if ( empty( $out['club_name'] ) ) {
				return new \WP_Error( 'missing_club_name', __( 'Club name is required.', 'vms-plugin' ) );
			}
		}

		if ( isset( $data['club_email'] ) ) {
			$email = sanitize_email( $data['club_email'] );
			$out['club_email'] = $email && is_email( $email ) ? $email : null;
		}

		if ( isset( $data['club_phone'] ) ) {
			$out['club_phone'] = sanitize_text_field( $data['club_phone'] ) ?: null;
		}

		if ( isset( $data['club_website'] ) ) {
			$out['club_website'] = esc_url_raw( $data['club_website'] ) ?: null;
		}

		if ( isset( $data['club_address'] ) ) {
			$out['club_address'] = sanitize_textarea_field( $data['club_address'] ) ?: null;
		}

		if ( isset( $data['country'] ) ) {
			$out['country'] = sanitize_text_field( $data['country'] ) ?: null;
		}

		$out['is_reciprocating'] = ! empty( $data['is_reciprocating'] ) ? 1 : 0;

		if ( isset( $data['notes'] ) ) {
			$out['notes'] = sanitize_textarea_field( $data['notes'] ) ?: null;
		}

		if ( isset( $data['club_status'] ) ) {
			$status = sanitize_text_field( $data['club_status'] );
			if ( VMS_Config::is_valid_status( $status, 'guest' ) ) {
				$out['club_status'] = $status;
			}
		}

		return $out;
	}

	/**
	 * Validate & sanitize member input.
	 *
	 * @param array $data       Raw input.
	 * @param int   $exclude_id Member ID to exclude from uniqueness checks (for updates).
	 * @return array|\WP_Error
	 */
	private static function validate_member_data( array $data, int $exclude_id = 0 ) {
		$out = array();

		if ( isset( $data['club_id'] ) ) {
			$out['club_id'] = absint( $data['club_id'] );
			if ( 0 === $out['club_id'] ) {
				return new \WP_Error( 'missing_club_id', __( 'Club ID is required.', 'vms-plugin' ) );
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

		if ( isset( $data['id_number'] ) ) {
			$id = preg_replace( '/[^A-Za-z0-9]/', '', $data['id_number'] );
			$out['id_number'] = $id ?: null;
		}

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			$out['email'] = $email && is_email( $email ) ? $email : null;
		}

		if ( isset( $data['phone_number'] ) ) {
			$out['phone_number'] = sanitize_text_field( $data['phone_number'] ) ?: null;
		}

		if ( isset( $data['member_number'] ) ) {
			$out['member_number'] = sanitize_text_field( $data['member_number'] ) ?: null;
		}

		$out['receive_emails']   = ! empty( $data['receive_emails'] ) ? 1 : 0;
		$out['receive_messages'] = ! empty( $data['receive_messages'] ) ? 1 : 0;

		if ( isset( $data['member_status'] ) ) {
			$status = sanitize_text_field( $data['member_status'] );
			if ( VMS_Config::is_valid_status( $status, 'guest' ) ) {
				$out['member_status'] = $status;
			}
		}

		return $out;
	}
}