<?php
/**
 * Employee (Staff) management module.
 *
 * Handles staff profile management using WordPress user accounts and user meta,
 * replacing the legacy custom table approach.
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

	public const META_STAFF_STATUS    = 'vms_staff_status';
	public const META_EMPLOYEE_NUMBER = 'vms_employee_number';
	public const META_ID_NUMBER       = 'vms_id_number';
	public const META_PHONE           = 'vms_phone';
	public const META_HIRE_DATE       = 'vms_hire_date';
	public const META_DEPARTMENT      = 'vms_department';

	public const STAFF_ROLES = array( 'gate', 'reception', 'general_manager' );

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Staff CRUD.
		add_action( 'wp_ajax_vms_create_staff', array( $this, 'ajax_create_staff' ) );
		add_action( 'wp_ajax_vms_update_staff', array( $this, 'ajax_update_staff' ) );
		add_action( 'wp_ajax_vms_delete_staff', array( $this, 'ajax_delete_staff' ) );
		add_action( 'wp_ajax_vms_get_staff_list', array( $this, 'ajax_get_staff_list' ) );
		add_action( 'wp_ajax_vms_get_staff_profile', array( $this, 'ajax_get_staff_profile' ) );
		add_action( 'wp_ajax_vms_search_staff', array( $this, 'ajax_search_staff' ) );

		// Legacy endpoints for backward compatibility.
		add_action( 'wp_ajax_vms_register_employee', array( $this, 'ajax_create_staff' ) );
		add_action( 'wp_ajax_vms_update_employee', array( $this, 'ajax_update_staff' ) );
		add_action( 'wp_ajax_vms_delete_employee', array( $this, 'ajax_delete_staff' ) );
		add_action( 'wp_ajax_vms_get_employees', array( $this, 'ajax_get_staff_list' ) );
		add_action( 'wp_ajax_vms_search_employees', array( $this, 'ajax_search_staff' ) );

		// Block suspended/banned staff from logging in.
		add_filter( 'authenticate', array( $this, 'block_staff_login' ), 30, 3 );
	}

	// =====================================================================
	// STAFF CRUD
	// =====================================================================

	/**
	 * Get staff profile.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null
	 */
	public static function get_staff_profile( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$role = null;
		foreach ( self::STAFF_ROLES as $r ) {
			if ( in_array( $r, $user->roles, true ) ) {
				$role = $r;
				break;
			}
		}

		if ( ! $role && ! in_array( 'administrator', $user->roles, true ) ) {
			return null;
		}

		return array(
			'user_id'         => $user_id,
			'first_name'      => $user->first_name,
			'last_name'       => $user->last_name,
			'email'           => $user->user_email,
			'display_name'    => $user->display_name,
			'position'        => $role ?: 'administrator',
			'employee_status' => get_user_meta( $user_id, self::META_STAFF_STATUS, true ) ?: VMS_Config::STATUS_ACTIVE,
			'employee_number' => get_user_meta( $user_id, self::META_EMPLOYEE_NUMBER, true ) ?: '',
			'id_number'       => get_user_meta( $user_id, self::META_ID_NUMBER, true ) ?: '',
			'phone_number'    => get_user_meta( $user_id, self::META_PHONE, true ) ?: '',
			'hire_date'       => get_user_meta( $user_id, self::META_HIRE_DATE, true ) ?: '',
			'department'      => get_user_meta( $user_id, self::META_DEPARTMENT, true ) ?: '',
			'registered'      => $user->user_registered,
		);
	}

	/**
	 * Get paginated staff list.
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @param string $search   Optional search term.
	 * @param string $role     Optional role filter.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_staff_list( int $per_page = 50, int $page = 1, string $status = '', string $search = '', string $role = '' ): array {
		$cache_key = 'employees:list_' . md5( "{$per_page}_{$page}_{$status}_{$search}_{$role}" );

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status, $search, $role ) {
				$roles = $role ? array( $role ) : self::STAFF_ROLES;

				$args = array(
					'number'   => max( 1, min( 500, $per_page ) ),
					'paged'    => max( 1, $page ),
					'orderby'  => 'display_name',
					'order'    => 'ASC',
					'role__in' => $roles,
				);

				if ( $search ) {
					$args['search']         = '*' . $search . '*';
					$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
				}

				if ( $status ) {
					$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_STAFF_STATUS,
							'value'   => sanitize_text_field( $status ),
							'compare' => '=',
						),
					);
				}

				$user_query = new \WP_User_Query( $args );
				$users      = $user_query->get_results();
				$total      = $user_query->get_total();

				$rows = array();
				foreach ( $users as $user ) {
					$profile = self::get_staff_profile( $user->ID );
					if ( $profile ) {
						$profile['id'] = $user->ID;
						$rows[]        = $profile;
					}
				}

				return array(
					'rows'  => $rows,
					'total' => $total,
					'pages' => (int) ceil( $total / max( 1, $per_page ) ),
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Create a staff user.
	 *
	 * @param array $data Staff data.
	 * @return int|\WP_Error User ID or error.
	 */
	public static function create_staff( array $data ) {
		$first_name = sanitize_text_field( $data['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $data['last_name'] ?? '' );
		$email      = sanitize_email( $data['email'] ?? '' );
		$role       = sanitize_text_field( $data['role'] ?? '' );

		if ( ! $first_name || ! $last_name || ! $email || ! $role ) {
			return new \WP_Error( 'missing_fields', __( 'First name, last name, email, and role are required.', 'vms-plugin' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid email address.', 'vms-plugin' ) );
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error( 'email_exists', __( 'This email is already in use.', 'vms-plugin' ) );
		}

		if ( ! in_array( $role, self::STAFF_ROLES, true ) ) {
			return new \WP_Error( 'invalid_role', __( 'Invalid staff role.', 'vms-plugin' ) );
		}

		// Only admins/GMs can create GMs.
		if ( 'general_manager' === $role && ! current_user_can( 'manage_options' ) && 'general_manager' !== VMS_Roles::get_user_vms_role() ) {
			return new \WP_Error( 'forbidden_role', __( 'You cannot assign the General Manager role.', 'vms-plugin' ) );
		}

		$emp_number = sanitize_text_field( $data['employee_number'] ?? '' );
		if ( $emp_number ) {
			// Check for dupes by emp number meta.
			$dupe = get_users(
				array(
					'meta_key'   => self::META_EMPLOYEE_NUMBER,
					'meta_value' => $emp_number,
					'number'     => 1,
					'fields'     => 'ids',
				)
			);
			if ( $dupe ) {
				return new \WP_Error( 'duplicate_emp_num', __( 'An employee with this number already exists.', 'vms-plugin' ) );
			}
		}

		$base     = sanitize_user( strtolower( strtok( $email, '@' ) ), true ) ?: 'staff';
		$username = $base;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $suffix++;
		}

		$password = isset( $data['password'] ) && $data['password'] ? $data['password'] : wp_generate_password();

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::META_STAFF_STATUS, VMS_Config::STATUS_ACTIVE );
		update_user_meta( $user_id, self::META_EMPLOYEE_NUMBER, $emp_number );
		update_user_meta( $user_id, self::META_ID_NUMBER, sanitize_text_field( $data['id_number'] ?? '' ) );
		update_user_meta( $user_id, self::META_PHONE, sanitize_text_field( $data['phone_number'] ?? '' ) );
		update_user_meta( $user_id, self::META_DEPARTMENT, sanitize_text_field( $data['department'] ?? '' ) );
		
		$hire_date = sanitize_text_field( $data['hire_date'] ?? '' );
		if ( $hire_date ) {
			update_user_meta( $user_id, self::META_HIRE_DATE, gmdate( 'Y-m-d', strtotime( $hire_date ) ) );
		}

		VMS_Cache::bust( 'employees' );
		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_EMPLOYEE, 'user', $user_id, $data );

		do_action( 'vms_staff_created', $user_id, $data );

		return $user_id;
	}

	/**
	 * Update staff.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Updated data.
	 * @return bool|\WP_Error
	 */
	public static function update_staff( int $user_id, array $data ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', __( 'Staff member not found.', 'vms-plugin' ) );
		}

		$old_profile = self::get_staff_profile( $user_id );
		if ( ! $old_profile ) {
			return new \WP_Error( 'invalid_user', __( 'Not a valid staff member.', 'vms-plugin' ) );
		}

		$wp_data = array( 'ID' => $user_id );
		$changed = false;

		if ( isset( $data['first_name'] ) ) {
			$wp_data['first_name'] = sanitize_text_field( $data['first_name'] );
			$changed               = true;
		}
		if ( isset( $data['last_name'] ) ) {
			$wp_data['last_name'] = sanitize_text_field( $data['last_name'] );
			$changed              = true;
		}
		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			if ( $email && is_email( $email ) ) {
				$wp_data['user_email'] = $email;
				$changed               = true;
			}
		}
		
		if ( isset( $data['role'] ) ) {
			$role = sanitize_text_field( $data['role'] );
			if ( in_array( $role, self::STAFF_ROLES, true ) ) {
				if ( 'general_manager' === $role && ! current_user_can( 'manage_options' ) && 'general_manager' !== VMS_Roles::get_user_vms_role() ) {
					return new \WP_Error( 'forbidden_role', __( 'You cannot assign the General Manager role.', 'vms-plugin' ) );
				}
				$wp_data['role'] = $role;
				$changed         = true;
			}
		}

		if ( $changed ) {
			$result = wp_update_user( $wp_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $data['employee_status'] ) ) {
			$status = sanitize_text_field( $data['employee_status'] );
			if ( in_array( $status, array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, VMS_Config::STATUS_BANNED ), true ) ) {
				update_user_meta( $user_id, self::META_STAFF_STATUS, $status );
			}
		}

		if ( isset( $data['employee_number'] ) ) {
			$emp_num = sanitize_text_field( $data['employee_number'] );
			// Check dupes
			if ( $emp_num !== $old_profile['employee_number'] ) {
				$dupe = get_users( array( 'meta_key' => self::META_EMPLOYEE_NUMBER, 'meta_value' => $emp_num, 'exclude' => array( $user_id ), 'fields' => 'ids' ) );
				if ( $dupe ) {
					return new \WP_Error( 'duplicate_emp_num', __( 'An employee with this number already exists.', 'vms-plugin' ) );
				}
			}
			update_user_meta( $user_id, self::META_EMPLOYEE_NUMBER, $emp_num );
		}

		if ( isset( $data['id_number'] ) ) {
			update_user_meta( $user_id, self::META_ID_NUMBER, sanitize_text_field( $data['id_number'] ) );
		}
		if ( isset( $data['phone_number'] ) ) {
			update_user_meta( $user_id, self::META_PHONE, sanitize_text_field( $data['phone_number'] ) );
		}
		if ( isset( $data['department'] ) ) {
			update_user_meta( $user_id, self::META_DEPARTMENT, sanitize_text_field( $data['department'] ) );
		}
		if ( isset( $data['hire_date'] ) ) {
			$hire_date = sanitize_text_field( $data['hire_date'] );
			update_user_meta( $user_id, self::META_HIRE_DATE, $hire_date ? gmdate( 'Y-m-d', strtotime( $hire_date ) ) : '' );
		}
		
		if ( isset( $data['password'] ) && $data['password'] ) {
			wp_set_password( wp_unslash( $data['password'] ), $user_id );
		}

		VMS_Cache::bust( 'employees' );
		$new_profile = self::get_staff_profile( $user_id );
		VMS_Audit_Trail::log_update( VMS_Audit_Trail::CAT_EMPLOYEE, 'user', $user_id, $old_profile, $new_profile ?: array() );

		do_action( 'vms_staff_updated', $user_id, $data, $old_profile );

		return true;
	}

	/**
	 * Delete staff.
	 *
	 * @param int $user_id User ID.
	 * @return bool|\WP_Error
	 */
	public static function delete_staff( int $user_id ) {
		$profile = self::get_staff_profile( $user_id );
		if ( ! $profile ) {
			return new \WP_Error( 'not_found', __( 'Staff member not found.', 'vms-plugin' ) );
		}
		
		if ( $user_id === get_current_user_id() ) {
			return new \WP_Error( 'delete_self', __( 'You cannot delete yourself.', 'vms-plugin' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $user_id ) ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete user.', 'vms-plugin' ) );
		}

		VMS_Cache::bust( 'employees' );
		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_EMPLOYEE, 'user', $user_id, $profile );

		do_action( 'vms_staff_deleted', $user_id, $profile );

		return true;
	}

	/**
	 * Block login for users with suspended or banned status.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authenticated user or error.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error
	 */
	public function block_staff_login( $user, $username, $password ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		$is_staff = false;
		foreach ( self::STAFF_ROLES as $r ) {
			if ( in_array( $r, $user->roles, true ) ) {
				$is_staff = true;
				break;
			}
		}

		if ( ! $is_staff ) {
			return $user;
		}

		$status = get_user_meta( $user->ID, self::META_STAFF_STATUS, true ) ?: VMS_Config::STATUS_ACTIVE;

		if ( VMS_Config::STATUS_SUSPENDED === $status ) {
			return new \WP_Error(
				'vms_staff_suspended',
				__( 'Your account has been suspended. Please contact the administrator.', 'vms-plugin' )
			);
		}

		if ( VMS_Config::STATUS_BANNED === $status ) {
			return new \WP_Error(
				'vms_staff_banned',
				__( 'Your account has been permanently banned. Please contact the administrator.', 'vms-plugin' )
			);
		}

		return $user;
	}

	// =====================================================================
	// AJAX HANDLERS
	// =====================================================================

	public function ajax_create_staff(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$result = self::create_staff(
			array(
				'first_name'      => self::get_post_text( 'first_name' ),
				'last_name'       => self::get_post_text( 'last_name' ),
				'email'           => self::get_post_email( 'email' ),
				'role'            => self::get_post_text( 'role' ) ?: self::get_post_text( 'position' ),
				'employee_number' => self::get_post_text( 'employee_number' ),
				'id_number'       => self::get_post_text( 'id_number' ),
				'phone_number'    => self::get_post_text( 'phone_number' ),
				'department'      => self::get_post_text( 'department' ),
				'hire_date'       => self::get_post_text( 'hire_date' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				'password'        => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) );
		}

		wp_send_json_success( array( 'employee' => self::get_staff_profile( $result ), 'message' => __( 'Staff member created successfully.', 'vms-plugin' ) ) );
	}

	public function ajax_update_staff(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$user_id = self::get_post_int( 'employee_id' ) ?: self::get_post_int( 'user_id' );
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing user ID.', 'vms-plugin' ) ) );
		}

		$data = array_filter(
			array(
				'first_name'      => self::get_post_text( 'first_name' ),
				'last_name'       => self::get_post_text( 'last_name' ),
				'email'           => self::get_post_email( 'email' ),
				'role'            => self::get_post_text( 'role' ) ?: self::get_post_text( 'position' ),
				'employee_number' => self::get_post_text( 'employee_number' ),
				'id_number'       => self::get_post_text( 'id_number' ),
				'phone_number'    => self::get_post_text( 'phone_number' ),
				'department'      => self::get_post_text( 'department' ),
				'hire_date'       => self::get_post_text( 'hire_date' ),
			),
			static fn( $v ) => '' !== $v
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['employee_status'] ) ) {
			$data['employee_status'] = self::get_post_text( 'employee_status' );
		}
		if ( isset( $_POST['password'] ) && $_POST['password'] ) {
			$data['password'] = wp_unslash( $_POST['password'] );
		}
		// phpcs:enable

		$result = self::update_staff( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'employee' => self::get_staff_profile( $user_id ), 'message' => __( 'Staff member updated.', 'vms-plugin' ) ) );
	}

	public function ajax_delete_staff(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$user_id = self::get_post_int( 'employee_id' ) ?: self::get_post_int( 'user_id' );
		$result  = self::delete_staff( $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Staff member deleted.', 'vms-plugin' ) ) );
	}

	public function ajax_get_staff_list(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$result = self::get_staff_list(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' ),
			self::get_post_text( 'search' ),
			self::get_post_text( 'role' )
		);

		wp_send_json_success( $result );
	}
	
	public function ajax_get_staff_profile(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$user_id = self::get_post_int( 'user_id' );
		$profile = self::get_staff_profile( $user_id );

		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Staff member not found.', 'vms-plugin' ) ) );
		}

		wp_send_json_success( array( 'profile' => $profile ) );
	}

	public function ajax_search_staff(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_MANAGE_EMPLOYEES );

		$term = self::get_post_text( 'term' );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$result = self::get_staff_list( 20, 1, '', $term );
		wp_send_json_success( array( 'results' => $result['rows'] ) );
	}
}
