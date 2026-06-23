<?php
/**
 * Audit trail logging system.
 *
 * Records every significant action with full before/after state,
 * user context, and request metadata. Logs are queryable by user,
 * action type, entity, and date range for compliance reporting.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Audit Trail.
 */
final class VMS_Audit_Trail extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Action categories.
	 */
	public const CAT_GUEST    = 'guest';
	public const CAT_VISIT    = 'visit';
	public const CAT_SUPPLIER = 'supplier';
	public const CAT_ACCOM    = 'accommodation';
	public const CAT_RECIP    = 'reciprocation';
	public const CAT_EMPLOYEE = 'employee';
	public const CAT_SETTINGS = 'settings';
	public const CAT_SECURITY = 'security';
	public const CAT_SYSTEM   = 'system';
	public const CAT_MEMBER   = 'member';
	public const CAT_REPORT   = 'report';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// AJAX: fetch audit logs for admin UI.
		add_action( 'wp_ajax_vms_get_audit_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_vms_export_audit_logs', array( $this, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_vms_export_audit_pdf', array( $this, 'ajax_export_pdf' ) );

		// Cron: cleanup old logs.
		add_action( VMS_Config::CRON_CLEANUP_LOGS, array( $this, 'cleanup_old_logs' ) );

		// ─── Authentication events ──────────────────────────────────
		// Track every login/logout/failed attempt so the audit log is a
		// complete record of who accessed the system and when. These fire
		// for ALL auth flows (wp-login.php, AJAX, REST, XML-RPC).
		add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'on_user_logout' ), 10, 1 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 2 );

		// ─── User lifecycle events ──────────────────────────────────
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'on_role_change' ), 10, 3 );
		add_action( 'delete_user', array( $this, 'on_user_delete' ), 10, 1 );
	}

	// =====================================================================
	// AUTH EVENT HANDLERS
	// =====================================================================

	/**
	 * Log successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 * @return void
	 */
	public function on_user_login( string $user_login, $user ): void {
		// Set current user so the log entry records who logged in, not
		// who *was* logged in (usually nobody) when this hook fires.
		wp_set_current_user( $user->ID );

		self::log(
			'user_login',
			self::CAT_SECURITY,
			'user',
			$user->ID,
			null,
			null,
			array(
				'username' => $user_login,
				'role'     => VMS_Roles::get_user_vms_role( $user->ID ),
			)
		);
	}

	/**
	 * Log logout.
	 *
	 * @param int $user_id User ID being logged out.
	 * @return void
	 */
	public function on_user_logout( int $user_id ): void {
		$user = get_userdata( $user_id );

		self::log(
			'user_logout',
			self::CAT_SECURITY,
			'user',
			$user_id,
			null,
			null,
			array( 'username' => $user ? $user->user_login : 'unknown' )
		);
	}

	/**
	 * Log failed login attempt.
	 *
	 * @param string         $username Attempted username.
	 * @param \WP_Error|null $error    Error object (WP 5.4+).
	 * @return void
	 */
	public function on_login_failed( string $username, $error = null ): void {
		self::log(
			'login_failed',
			self::CAT_SECURITY,
			null,
			null,
			null,
			null,
			array(
				'attempted_username' => $username,
				'error_code'         => $error instanceof \WP_Error ? $error->get_error_code() : 'unknown',
			)
		);
	}

	/**
	 * Log new user registration.
	 *
	 * @param int $user_id Newly created user ID.
	 * @return void
	 */
	public function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		self::log(
			'user_registered',
			self::CAT_MEMBER,
			'user',
			$user_id,
			null,
			array(
				'username' => $user->user_login,
				'email'    => $user->user_email,
				'roles'    => $user->roles,
			)
		);
	}

	/**
	 * Log profile update.
	 *
	 * @param int      $user_id       Updated user ID.
	 * @param \WP_User $old_user_data Pre-update snapshot.
	 * @return void
	 */
	public function on_profile_update( int $user_id, $old_user_data ): void {
		$new = get_userdata( $user_id );
		if ( ! $new ) {
			return;
		}

		// Only log if something material changed.
		$changed = array();
		foreach ( array( 'user_email', 'display_name', 'user_login' ) as $field ) {
			if ( $old_user_data->$field !== $new->$field ) {
				$changed[ $field ] = array( 'from' => $old_user_data->$field, 'to' => $new->$field );
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		self::log(
			'profile_updated',
			self::CAT_MEMBER,
			'user',
			$user_id,
			array( 'email' => $old_user_data->user_email, 'display_name' => $old_user_data->display_name ),
			array( 'email' => $new->user_email, 'display_name' => $new->display_name ),
			array( 'changed_fields' => array_keys( $changed ) )
		);
	}

	/**
	 * Log role change.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $new_role  New role slug.
	 * @param string[] $old_roles Previous roles.
	 * @return void
	 */
	public function on_role_change( int $user_id, string $new_role, array $old_roles ): void {
		self::log(
			'role_changed',
			self::CAT_SECURITY,
			'user',
			$user_id,
			array( 'roles' => $old_roles ),
			array( 'roles' => array( $new_role ) )
		);
	}

	/**
	 * Log user deletion.
	 *
	 * @param int $user_id User being deleted.
	 * @return void
	 */
	public function on_user_delete( int $user_id ): void {
		$user = get_userdata( $user_id );

		self::log(
			'user_deleted',
			self::CAT_SECURITY,
			'user',
			$user_id,
			$user ? array( 'username' => $user->user_login, 'email' => $user->user_email ) : null,
			null
		);
	}

	// =====================================================================
	// PDF EXPORT
	// =====================================================================

	/**
	 * AJAX: Export audit logs as raw data for client-side PDF generation.
	 *
	 * Returns branding + log rows; jsPDF on the frontend handles layout.
	 * This keeps the PHP side free of heavyweight PDF libraries while
	 * still producing a fully branded document.
	 *
	 * @return void
	 */
	public function ajax_export_pdf(): void {
		self::verify_ajax( 'vms_audit_nonce', VMS_Config::CAP_VIEW_AUDIT_LOGS );

		$result = self::query(
			array(
				'user_id'         => self::get_post_int( 'user_id' ) ?: 0,
				'action_category' => self::get_post_text( 'category' ) ?: '',
				'date_from'       => self::get_post_text( 'date_from' ) ?: '',
				'date_to'         => self::get_post_text( 'date_to' ) ?: '',
				'per_page'        => 5000,
				'page'            => 1,
			)
		);

		$branding = array(
			'club_name'     => VMS_Config::get_option( 'club_name' ),
			'club_address'  => VMS_Config::get_option( 'club_address' ),
			'club_phone'    => VMS_Config::get_option( 'club_phone' ),
			'club_email'    => VMS_Config::get_option( 'club_email' ),
			'primary_color' => VMS_Config::get_option( 'primary_color' ),
			'club_logo_url' => '',
		);

		$logo_id = (int) VMS_Config::get_option( 'club_logo_id' );
		if ( $logo_id ) {
			$branding['club_logo_url'] = wp_get_attachment_image_url( $logo_id, 'medium' );
		}

		wp_send_json_success(
			array(
				'branding'     => $branding,
				'logs'         => $result['rows'],
				'total'        => $result['total'],
				'generated_at' => current_time( 'mysql' ),
				'generated_by' => wp_get_current_user()->display_name,
			)
		);
	}

	/**
	 * Log an action.
	 *
	 * @param string      $action_type     Action identifier (e.g. 'guest_created').
	 * @param string      $action_category Category constant.
	 * @param string|null $entity_type     Entity type (e.g. 'guest', 'visit').
	 * @param int|null    $entity_id       Entity primary key.
	 * @param array|null  $old_values      Pre-change state.
	 * @param array|null  $new_values      Post-change state.
	 * @param array       $metadata        Extra context.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function log(
		string $action_type,
		string $action_category,
		?string $entity_type = null,
		?int $entity_id = null,
		?array $old_values = null,
		?array $new_values = null,
		array $metadata = array()
	) {
		global $wpdb;

		$user_id   = get_current_user_id();
		$user_role = null;

		if ( $user_id ) {
			$user      = wp_get_current_user();
			$user_role = ! empty( $user->roles ) ? $user->roles[0] : null;
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'user_id'         => $user_id ?: null,
				'user_role'       => $user_role,
				'action_type'     => sanitize_key( $action_type ),
				'action_category' => sanitize_key( $action_category ),
				'entity_type'     => $entity_type ? sanitize_key( $entity_type ) : null,
				'entity_id'       => $entity_id,
				'old_values'      => $old_values ? wp_json_encode( $old_values ) : null,
				'new_values'      => $new_values ? wp_json_encode( $new_values ) : null,
				'metadata'        => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
				'ip_address'      => VMS_Security::instance()->get_client_ip(),
				'user_agent'      => self::get_user_agent(),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Convenience: log an entity creation.
	 *
	 * @param string $category    Category.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param array  $values      Created values.
	 * @return int|false
	 */
	public static function log_create( string $category, string $entity_type, int $entity_id, array $values ) {
		return self::log( "{$entity_type}_created", $category, $entity_type, $entity_id, null, $values );
	}

	/**
	 * Convenience: log an entity update with diff.
	 *
	 * @param string $category    Category.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param array  $old_values  Before state.
	 * @param array  $new_values  After state.
	 * @return int|false
	 */
	public static function log_update( string $category, string $entity_type, int $entity_id, array $old_values, array $new_values ) {
		// Only log fields that actually changed.
		$diff_old = array();
		$diff_new = array();

		foreach ( $new_values as $key => $new_val ) {
			$old_val = $old_values[ $key ] ?? null;
			if ( $old_val !== $new_val ) {
				$diff_old[ $key ] = $old_val;
				$diff_new[ $key ] = $new_val;
			}
		}

		if ( empty( $diff_new ) ) {
			return false; // Nothing changed.
		}

		return self::log( "{$entity_type}_updated", $category, $entity_type, $entity_id, $diff_old, $diff_new );
	}

	/**
	 * Convenience: log an entity deletion.
	 *
	 * @param string $category    Category.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param array  $values      Deleted values (for recovery reference).
	 * @return int|false
	 */
	public static function log_delete( string $category, string $entity_type, int $entity_id, array $values ) {
		return self::log( "{$entity_type}_deleted", $category, $entity_type, $entity_id, $values, null );
	}

	/**
	 * Query audit logs with filters.
	 *
	 * @param array $args {
	 *     @type int    $user_id         Filter by user.
	 *     @type string $action_type     Filter by action.
	 *     @type string $action_category Filter by category.
	 *     @type string $entity_type     Filter by entity type.
	 *     @type int    $entity_id       Filter by entity ID.
	 *     @type string $date_from       ISO date.
	 *     @type string $date_to         ISO date.
	 *     @type int    $per_page        Results per page.
	 *     @type int    $page            Page number (1-indexed).
	 * }
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'user_id'         => 0,
			'action_type'     => '',
			'action_category' => '',
			'entity_type'     => '',
			'entity_id'       => 0,
			'date_from'       => '',
			'date_to'         => '',
			'per_page'        => 50,
			'page'            => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$table  = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );
		$where  = array( '1=1' );
		$params = array();

		if ( $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( $args['action_type'] ) {
			$where[]  = 'action_type = %s';
			$params[] = sanitize_key( $args['action_type'] );
		}
		if ( $args['action_category'] ) {
			$where[]  = 'action_category = %s';
			$params[] = sanitize_key( $args['action_category'] );
		}
		if ( $args['entity_type'] ) {
			$where[]  = 'entity_type = %s';
			$params[] = sanitize_key( $args['entity_type'] );
		}
		if ( $args['entity_id'] ) {
			$where[]  = 'entity_id = %d';
			$params[] = (int) $args['entity_id'];
		}
		if ( $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['date_from'] );
		}
		if ( $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

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

		// Decode JSON fields.
		foreach ( $rows as &$row ) {
			$row['old_values'] = $row['old_values'] ? json_decode( $row['old_values'], true ) : null;
			$row['new_values'] = $row['new_values'] ? json_decode( $row['new_values'], true ) : null;
			$row['metadata']   = $row['metadata'] ? json_decode( $row['metadata'], true ) : null;
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Get history for a specific entity (cached).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param int    $limit       Max rows.
	 * @return array
	 */
	public static function get_entity_history( string $entity_type, int $entity_id, int $limit = 20 ): array {
		$cache_key = "audit:entity_{$entity_type}_{$entity_id}_{$limit}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $entity_type, $entity_id, $limit ) {
				$result = self::query(
					array(
						'entity_type' => $entity_type,
						'entity_id'   => $entity_id,
						'per_page'    => $limit,
					)
				);
				return $result['rows'];
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * AJAX: return paginated audit logs.
	 *
	 * @return void
	 */
	public function ajax_get_logs(): void {
		self::verify_ajax( 'vms_audit_nonce', VMS_Config::CAP_VIEW_AUDIT_LOGS );

		$result = self::query(
			array(
				// phpcs:disable WordPress.Security.NonceVerification.Missing
				'user_id'         => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
				'action_category' => isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '',
				'entity_type'     => isset( $_POST['entity_type'] ) ? sanitize_key( wp_unslash( $_POST['entity_type'] ) ) : '',
				'date_from'       => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
				'date_to'         => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
				'per_page'        => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 50,
				'page'            => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
				// phpcs:enable
			)
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: export audit logs as CSV.
	 *
	 * @return void
	 */
	public function ajax_export_logs(): void {
		self::verify_ajax( 'vms_audit_nonce', VMS_Config::CAP_EXPORT_DATA );

		$result = self::query(
			array(
				// phpcs:disable WordPress.Security.NonceVerification.Missing
				'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
				'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
				'per_page'  => 10000,
				// phpcs:enable
			)
		);

		$filename = 'vms-audit-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		// CSV header.
		fputcsv( $out, array( 'ID', 'Date', 'User ID', 'Role', 'Action', 'Category', 'Entity Type', 'Entity ID', 'IP', 'Old Values', 'New Values' ) );

		foreach ( $result['rows'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['id'],
					$row['created_at'],
					$row['user_id'],
					$row['user_role'],
					$row['action_type'],
					$row['action_category'],
					$row['entity_type'],
					$row['entity_id'],
					$row['ip_address'],
					$row['old_values'] ? wp_json_encode( $row['old_values'] ) : '',
					$row['new_values'] ? wp_json_encode( $row['new_values'] ) : '',
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Cron: delete logs older than retention period.
	 *
	 * @return void
	 */
	public function cleanup_old_logs(): void {
		global $wpdb;

		$days = (int) VMS_Config::get_option( 'audit_log_retention_days', 365 );
		if ( $days <= 0 ) {
			return; // Retention disabled.
		}

		$table  = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s", $cutoff ) );

		if ( $deleted > 0 ) {
			self::log( 'audit_logs_pruned', self::CAT_SYSTEM, null, null, null, null, array( 'deleted' => $deleted, 'cutoff' => $cutoff ) );
		}
	}

	/**
	 * Get truncated user agent string.
	 *
	 * @return string
	 */
	private static function get_user_agent(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return substr( sanitize_text_field( $ua ), 0, 255 );
	}
}
