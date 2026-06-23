<?php
/**
 * Member profile management module.
 *
 * Manages WordPress users with VMS roles. Handles member profile
 * viewing, updating, approval, and status changes. Stores additional
 * VMS-specific data in user meta (phone, SMS opt-in, email opt-in).
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Members module.
 */
final class VMS_Members extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * User meta keys for VMS-specific data.
	 */
	private const META_PHONE         = 'vms_phone';
	private const META_RECEIVE_SMS   = 'vms_receive_sms';
	private const META_RECEIVE_EMAIL = 'vms_receive_email';
	private const META_VMS_STATUS    = 'vms_member_status';
	// Club-assigned membership number. Entered at self-registration,
	// verified by an approver before activation. Must be unique — two
	// applicants claiming the same number indicates either a duplicate
	// registration or an impostor, both of which we want to catch early.
	private const META_MEMBER_NUMBER = 'vms_member_number';

	/**
	 * Status values for pending-approval workflow.
	 */
	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_ajax_vms_get_member_profile', array( $this, 'ajax_get_member_profile' ) );
		add_action( 'wp_ajax_vms_update_member_profile', array( $this, 'ajax_update_member_profile' ) );
		add_action( 'wp_ajax_vms_change_password', array( $this, 'ajax_change_password' ) );
		add_action( 'wp_ajax_vms_get_members_list', array( $this, 'ajax_get_members_list' ) );
		add_action( 'wp_ajax_vms_approve_member', array( $this, 'ajax_approve_member' ) );
		add_action( 'wp_ajax_vms_reject_member', array( $this, 'ajax_reject_member' ) );
		add_action( 'wp_ajax_vms_update_member_status', array( $this, 'ajax_update_member_status' ) );
		add_action( 'wp_ajax_vms_get_pending_members', array( $this, 'ajax_get_pending_members' ) );

		// Public registration endpoint — no login required.
		add_action( 'wp_ajax_nopriv_vms_register_member', array( $this, 'ajax_register_member' ) );
		add_action( 'wp_ajax_vms_register_member', array( $this, 'ajax_register_member' ) );

		// Block pending members from logging in until approved.
		add_filter( 'authenticate', array( $this, 'block_pending_login' ), 30, 3 );
	}

	// =====================================================================
	// PUBLIC REGISTRATION
	// =====================================================================

	/**
	 * AJAX: Public member self-registration.
	 *
	 * Creates a WP user with `member` role and `pending` status. The user
	 * cannot log in until a receptionist/admin/chairman approves them.
	 *
	 * @return void
	 */
	public function ajax_register_member(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vms_member_register' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page.', 'vms-plugin' ) ), 403 );
		}

		// Rate-limit by IP to deter spam registrations.
		$ip = VMS_Security::instance()->get_client_ip();
		if ( ! VMS_Security::rate_limit( 'member_register', 5, 3600, $ip ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many registration attempts. Please try again later.', 'vms-plugin' ) ), 429 );
		}

		$first_name    = self::get_post_text( 'first_name' );
		$last_name     = self::get_post_text( 'last_name' );
		$email         = self::get_post_email( 'email' );
		$phone         = self::get_post_phone( 'phone' );
		$member_number = self::get_post_text( 'member_number' );
		$password      = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore

		// Everything is compulsory. Report the first missing field by name
		// rather than the generic "all fields required" so the applicant
		// knows exactly what to fix.
		$missing = array();
		if ( '' === $first_name )    { $missing[] = __( 'first name', 'vms-plugin' ); }
		if ( '' === $last_name )     { $missing[] = __( 'last name', 'vms-plugin' ); }
		if ( '' === $email )         { $missing[] = __( 'email address', 'vms-plugin' ); }
		if ( '' === $phone )         { $missing[] = __( 'phone number', 'vms-plugin' ); }
		if ( '' === $member_number ) { $missing[] = __( 'member number', 'vms-plugin' ); }
		if ( '' === $password )      { $missing[] = __( 'password', 'vms-plugin' ); }
		if ( $missing ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: comma-separated list of missing field names */
						__( 'Please provide your %s.', 'vms-plugin' ),
						wp_sprintf_l( '%l', $missing )
					),
				)
			);
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'vms-plugin' ) ) );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'vms-plugin' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'An account with this email already exists.', 'vms-plugin' ) ) );
		}

		// Member-number uniqueness. Case-insensitive comparison so
		// "MSC-001" and "msc-001" collide. Strip surrounding whitespace
		// but preserve internal formatting (some clubs use slashes/dashes).
		$member_number = strtoupper( trim( $member_number ) );
		$dupe = get_users(
			array(
				'meta_key'   => self::META_MEMBER_NUMBER,
				'meta_value' => $member_number,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);
		if ( $dupe ) {
			wp_send_json_error(
				array(
					'message' => __( 'This member number is already registered. If you believe this is a mistake, please contact the club office.', 'vms-plugin' ),
				)
			);
		}

		// Generate a unique username from the email local-part.
		$base     = sanitize_user( strtolower( strtok( $email, '@' ) ), true );
		$username = $base ?: 'member';
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $suffix++;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => 'member',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// Mark as pending — blocks login until approved.
		update_user_meta( $user_id, self::META_VMS_STATUS, self::STATUS_PENDING );
		update_user_meta( $user_id, self::META_MEMBER_NUMBER, $member_number );
		update_user_meta( $user_id, self::META_PHONE, $phone );
		update_user_meta( $user_id, self::META_RECEIVE_EMAIL, 1 );
		update_user_meta( $user_id, self::META_RECEIVE_SMS, 1 );
		update_user_meta( $user_id, 'vms_registered_ip', $ip );
		update_user_meta( $user_id, 'vms_registered_at', current_time( 'mysql' ) );

		VMS_Cache::bust( 'members' );

		VMS_Audit_Trail::log(
			'member_self_registered',
			VMS_Audit_Trail::CAT_MEMBER,
			'user',
			$user_id,
			null,
			array( 'email' => $email, 'name' => $first_name . ' ' . $last_name, 'status' => self::STATUS_PENDING )
		);

		// Notify approvers. Fire-and-forget — don't block the response.
		self::notify_approvers_of_pending( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Application received. Your account is pending approval — you will not be able to log in yet. A club administrator will verify your member number and email you once your account is activated.', 'vms-plugin' ),
			)
		);
	}

	/**
	 * Look up the club membership number for a user.
	 *
	 * @param int $user_id WP user ID.
	 * @return string Empty string if not set.
	 */
	public static function get_member_number( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_MEMBER_NUMBER, true );
	}

	/**
	 * Block login for users with pending or rejected status.
	 *
	 * Runs at priority 30 so WordPress has already validated credentials.
	 * If the credentials are valid ($user is WP_User) but status is pending,
	 * replace with an explanatory WP_Error.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authenticated user or error.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error
	 */
	public function block_pending_login( $user, $username, $password ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		// Only gate users with the `member` role — staff accounts created
		// directly in wp-admin have no status meta and should pass through.
		if ( ! in_array( 'member', $user->roles, true ) ) {
			return $user;
		}

		$status = get_user_meta( $user->ID, self::META_VMS_STATUS, true );

		if ( self::STATUS_PENDING === $status ) {
			return new \WP_Error(
				'vms_pending_approval',
				__( 'Your membership application is still pending approval. You will receive an email once it has been reviewed.', 'vms-plugin' )
			);
		}

		if ( self::STATUS_REJECTED === $status ) {
			return new \WP_Error(
				'vms_rejected',
				__( 'Your membership application was not approved. Please contact the club for more information.', 'vms-plugin' )
			);
		}

		return $user;
	}

	/**
	 * Email all users with CAP_APPROVE_MEMBERS about a new pending registration.
	 *
	 * @param int $user_id Pending user ID.
	 * @return void
	 */
	private static function notify_approvers_of_pending( int $user_id ): void {
		$pending = get_userdata( $user_id );
		if ( ! $pending ) {
			return;
		}

		$club = VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) );

		// Find all users who can approve (reception, chairman, gm, admin).
		$approvers = get_users(
			array(
				//'role__in' => array( 'administrator', 'chairman', 'general_manager', 'reception' ),
				'role__in' => array( 'reception' ),
				'fields'   => array( 'user_email', 'display_name' ),
			)
		);

		if ( empty( $approvers ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: club name */
			__( '[%s] New Member Registration Pending Approval', 'vms-plugin' ),
			$club
		);

		$body  = sprintf( __( 'A new member has registered and requires approval:', 'vms-plugin' ) ) . "\n\n";
		$body .= __( 'Name:', 'vms-plugin' ) . ' ' . $pending->display_name . "\n";
		$body .= __( 'Email:', 'vms-plugin' ) . ' ' . $pending->user_email . "\n";
		$body .= __( 'Phone:', 'vms-plugin' ) . ' ' . get_user_meta( $user_id, self::META_PHONE, true ) . "\n";
		$body .= __( 'Registered:', 'vms-plugin' ) . ' ' . current_time( 'mysql' ) . "\n\n";
		$body .= __( 'Please log in to review this application.', 'vms-plugin' ) . "\n";
		$body .= home_url( '/members/' );

		foreach ( $approvers as $approver ) {
			wp_mail( $approver->user_email, $subject, $body );
		}
	}

	/**
	 * AJAX: Get list of members pending approval.
	 *
	 * @return void
	 */
	public function ajax_get_pending_members(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_APPROVE_MEMBERS );

		$query = new \WP_User_Query(
			array(
				'role'       => 'member',
				'meta_key'   => self::META_VMS_STATUS,
				'meta_value' => self::STATUS_PENDING,
				'number'     => 100,
				'orderby'    => 'registered',
				'order'      => 'DESC',
			)
		);

		$members = array();
		foreach ( $query->get_results() as $user ) {
			$members[] = array(
				'id'            => $user->ID,
				'display_name'  => $user->display_name,
				'email'         => $user->user_email,
				'phone'         => get_user_meta( $user->ID, self::META_PHONE, true ),
				// Surface the member number so the approver can cross-check
				// against club records before hitting Approve.
				'member_number' => get_user_meta( $user->ID, self::META_MEMBER_NUMBER, true ),
				'registered'    => $user->user_registered,
			);
		}

		wp_send_json_success( array( 'members' => $members, 'count' => count( $members ) ) );
	}

	/**
	 * AJAX: Reject a pending member.
	 *
	 * @return void
	 */
	public function ajax_reject_member(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_APPROVE_MEMBERS );

		$user_id = self::get_post_int( 'user_id' );
		$reason  = self::get_post_text( 'reason' );

		$user = self::get_manageable_member_user( $user_id );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ), 403 );
		}

		update_user_meta( $user_id, self::META_VMS_STATUS, self::STATUS_REJECTED );
		if ( $reason ) {
			update_user_meta( $user_id, 'vms_rejection_reason', $reason );
		}

		VMS_Cache::bust( 'members' );

		VMS_Audit_Trail::log(
			'member_rejected',
			VMS_Audit_Trail::CAT_MEMBER,
			'user',
			$user_id,
			array( 'status' => self::STATUS_PENDING ),
			array( 'status' => self::STATUS_REJECTED, 'reason' => $reason )
		);

		// Email the applicant.
		$club = VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) );
		wp_mail(
			$user->user_email,
			sprintf( __( '[%s] Membership Application Update', 'vms-plugin' ), $club ),
			__( 'We regret to inform you that your membership application was not approved at this time.', 'vms-plugin' )
				. ( $reason ? "\n\n" . __( 'Reason:', 'vms-plugin' ) . ' ' . $reason : '' )
				. "\n\n-- " . $club
		);

		wp_send_json_success( array( 'message' => __( 'Member rejected.', 'vms-plugin' ) ) );
	}

	// =====================================================================
	// MEMBER DATA
	// =====================================================================

	/**
	 * Get member profile data (cached).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null
	 */
	public static function get_member_profile( int $user_id ): ?array {
		return VMS_Cache::cached(
			"members:profile_{$user_id}",
			static function () use ( $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					return null;
				}

				return array(
					'user_id'       => $user_id,
					'first_name'    => $user->first_name,
					'last_name'     => $user->last_name,
					'email'         => $user->user_email,
					'display_name'  => $user->display_name,
					'phone'         => get_user_meta( $user_id, self::META_PHONE, true ) ?: '',
					'member_number' => get_user_meta( $user_id, self::META_MEMBER_NUMBER, true ) ?: '',
					'receive_sms'   => (bool) get_user_meta( $user_id, self::META_RECEIVE_SMS, true ),
					'receive_email' => (bool) get_user_meta( $user_id, self::META_RECEIVE_EMAIL, true ),
					'member_status' => get_user_meta( $user_id, self::META_VMS_STATUS, true ) ?: VMS_Config::STATUS_ACTIVE,
					'roles'         => $user->roles,
					'registered'    => $user->user_registered,
				);
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Validate that a user-management action targets a member account.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return \WP_User|\WP_Error
	 */
	private static function get_manageable_member_user( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'vms-plugin' ) );
		}

		if ( user_can( $user, 'manage_options' ) || ! in_array( 'member', (array) $user->roles, true ) ) {
			return new \WP_Error( 'forbidden_target', __( 'This action is only allowed for member accounts.', 'vms-plugin' ) );
		}

		return $user;
	}

	/**
	 * Update member profile data.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $data    Profile data.
	 * @return bool|\WP_Error
	 */
	public static function update_member_profile( int $user_id, array $data ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'vms-plugin' ) );
		}

		$old = self::get_member_profile( $user_id );

		// Update WordPress user fields.
		$wp_data = array( 'ID' => $user_id );
		$changed = false;

		if ( isset( $data['first_name'] ) ) {
			$first_name = sanitize_text_field( $data['first_name'] );
			if ( $first_name ) {
				$wp_data['first_name'] = $first_name;
				$changed               = true;
			}
		}

		if ( isset( $data['last_name'] ) ) {
			$last_name = sanitize_text_field( $data['last_name'] );
			if ( $last_name ) {
				$wp_data['last_name'] = $last_name;
				$changed              = true;
			}
		}

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			if ( $email && is_email( $email ) ) {
				$wp_data['user_email'] = $email;
				$changed               = true;
			}
		}

		if ( $changed ) {
			$result = wp_update_user( $wp_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update VMS user meta.
		if ( isset( $data['phone'] ) ) {
			$phone = sanitize_text_field( $data['phone'] );
			update_user_meta( $user_id, self::META_PHONE, $phone );
		}

		if ( isset( $data['receive_sms'] ) ) {
			update_user_meta( $user_id, self::META_RECEIVE_SMS, absint( $data['receive_sms'] ) ? 1 : 0 );
		}

		if ( isset( $data['receive_email'] ) ) {
			update_user_meta( $user_id, self::META_RECEIVE_EMAIL, absint( $data['receive_email'] ) ? 1 : 0 );
		}

		if ( isset( $data['member_status'] ) ) {
			$valid_statuses = array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, VMS_Config::STATUS_BANNED, 'pending' );
			if ( in_array( $data['member_status'], $valid_statuses, true ) ) {
				update_user_meta( $user_id, self::META_VMS_STATUS, $data['member_status'] );
			}
		}

		if ( isset( $data['member_number'] ) ) {
			update_user_meta( $user_id, self::META_MEMBER_NUMBER, sanitize_text_field( $data['member_number'] ) );
		}

		VMS_Cache::bust( 'members' );

		$new = self::get_member_profile( $user_id );

		VMS_Audit_Trail::log_update(
			VMS_Audit_Trail::CAT_GUEST,
			'member',
			$user_id,
			$old ?? array(),
			$new ?? array()
		);

		do_action( 'vms_member_profile_updated', $user_id, $data );

		return true;
	}

	/**
	 * Approve a member.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool|\WP_Error
	 */
	public static function approve_member( int $user_id ) {
		$user = self::get_manageable_member_user( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$old_status = get_user_meta( $user_id, self::META_VMS_STATUS, true ) ?: 'pending';

		if ( VMS_Config::STATUS_ACTIVE === $old_status ) {
			return true; // Already approved.
		}

		update_user_meta( $user_id, self::META_VMS_STATUS, VMS_Config::STATUS_ACTIVE );
		update_user_meta( $user_id, 'vms_approved_at', current_time( 'mysql' ) );
		update_user_meta( $user_id, 'vms_approved_by', get_current_user_id() );

		VMS_Cache::bust( 'members' );

		VMS_Audit_Trail::log(
			'member_approved',
			VMS_Audit_Trail::CAT_MEMBER,
			'user',
			$user_id,
			array( 'member_status' => $old_status ),
			array( 'member_status' => VMS_Config::STATUS_ACTIVE )
		);

		// Welcome email.
		$club = VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) );
		wp_mail(
			$user->user_email,
			sprintf( __( '[%s] Welcome — Membership Approved', 'vms-plugin' ), $club ),
			sprintf( __( 'Dear %s,', 'vms-plugin' ), $user->display_name ) . "\n\n"
				. __( 'Great news! Your membership application has been approved.', 'vms-plugin' ) . "\n\n"
				. __( 'You can now log in using your email and the password you chose during registration:', 'vms-plugin' ) . "\n"
				. home_url( '/' ) . "\n\n"
				. '-- ' . $club
		);

		do_action( 'vms_member_approved', $user_id, $old_status );

		return true;
	}

	/**
	 * Update member status.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  New status.
	 * @return bool|\WP_Error
	 */
	public static function update_member_status( int $user_id, string $status ) {
		$valid_statuses = array( VMS_Config::STATUS_ACTIVE, VMS_Config::STATUS_SUSPENDED, VMS_Config::STATUS_BANNED, 'pending' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid member status.', 'vms-plugin' ) );
		}

		$user = self::get_manageable_member_user( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$old_status = get_user_meta( $user_id, self::META_VMS_STATUS, true ) ?: VMS_Config::STATUS_ACTIVE;

		if ( $old_status === $status ) {
			return true; // No change.
		}

		update_user_meta( $user_id, self::META_VMS_STATUS, $status );

		VMS_Cache::bust( 'members' );

		VMS_Audit_Trail::log_update(
			VMS_Audit_Trail::CAT_GUEST,
			'member',
			$user_id,
			array( 'member_status' => $old_status ),
			array( 'member_status' => $status )
		);

		do_action( 'vms_member_status_changed', $user_id, $old_status, $status );

		return true;
	}

	/**
	 * Get members list (cached, paginated).
	 *
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number (1-indexed).
	 * @param string $status   Optional status filter.
	 * @param string $search   Optional search term.
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function get_members_list( int $per_page = 50, int $page = 1, string $status = '', string $search = '' ): array {
		$cache_key = 'members:list_' . md5( "{$per_page}_{$page}_{$status}_{$search}" );

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $per_page, $page, $status, $search ) {
					$args = array(
						'number'  => max( 1, min( 500, $per_page ) ),
						'paged'   => max( 1, $page ),
						'orderby' => 'display_name',
						'order'   => 'ASC',
						'role__in' => array( 'member' ),
					);

				if ( $search ) {
					$args['search']         = '*' . $search . '*';
					$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
				}

				// Filter by VMS status via meta query.
				if ( $status ) {
					$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_VMS_STATUS,
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
					$rows[] = array(
						'id'            => $user->ID, // alias so the host-picker's generic {id,label} shape works
						'user_id'       => $user->ID,
						'first_name'    => $user->first_name,
						'last_name'     => $user->last_name,
						'email'         => $user->user_email,
						'display_name'  => $user->display_name,
						'phone'         => get_user_meta( $user->ID, self::META_PHONE, true ) ?: '',
						'member_number' => get_user_meta( $user->ID, self::META_MEMBER_NUMBER, true ) ?: '',
						'receive_sms'   => (bool) get_user_meta( $user->ID, self::META_RECEIVE_SMS, true ),
						'receive_email' => (bool) get_user_meta( $user->ID, self::META_RECEIVE_EMAIL, true ),
						'member_status' => get_user_meta( $user->ID, self::META_VMS_STATUS, true ) ?: VMS_Config::STATUS_ACTIVE,
						'roles'         => $user->roles,
						'registered'    => $user->user_registered,
					);
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

	// =====================================================================
	// AJAX HANDLERS
	// =====================================================================

	/**
	 * AJAX: get member profile.
	 *
	 * @return void
	 */
	public function ajax_get_member_profile(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$user_id = self::get_post_int( 'user_id' );

		// Users can only view their own profile unless they have CAP_APPROVE_MEMBERS.
		if ( $user_id !== get_current_user_id() && ! current_user_can( VMS_Config::CAP_APPROVE_MEMBERS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this profile.', 'vms-plugin' ) ), 403 );
		}

		if ( $user_id !== get_current_user_id() && is_wp_error( self::get_manageable_member_user( $user_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This profile is not available through member management.', 'vms-plugin' ) ), 403 );
		}

		$profile = self::get_member_profile( $user_id );

		if ( ! $profile ) {
			wp_send_json_error( array( 'message' => __( 'Member not found.', 'vms-plugin' ) ) );
		}

		wp_send_json_success( array( 'profile' => $profile ) );
	}

	/**
	 * AJAX: update member profile.
	 *
	 * @return void
	 */
	public function ajax_update_member_profile(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$user_id = self::get_post_int( 'user_id' );

		// Users can only update their own profile unless they have CAP_APPROVE_MEMBERS.
		if ( $user_id !== get_current_user_id() && ! current_user_can( VMS_Config::CAP_APPROVE_MEMBERS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update this profile.', 'vms-plugin' ) ), 403 );
		}

		if ( $user_id !== get_current_user_id() ) {
			$target = self::get_manageable_member_user( $user_id );
			if ( is_wp_error( $target ) ) {
				wp_send_json_error( array( 'message' => $target->get_error_message() ), 403 );
			}
		}

		$data = array_filter(
			array(
				'first_name' => self::get_post_text( 'first_name' ),
				'last_name'  => self::get_post_text( 'last_name' ),
				'email'      => self::get_post_email( 'email' ),
				'phone'      => self::get_post_text( 'phone' ),
			),
			static fn( $v ) => '' !== $v
		);

		// Checkbox values need special handling (empty = 0).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['receive_sms'] ) ) {
			$data['receive_sms'] = self::get_post_int( 'receive_sms' );
		}
		if ( isset( $_POST['receive_email'] ) ) {
			$data['receive_email'] = self::get_post_int( 'receive_email' );
		}
		// phpcs:enable

		// Admin-only fields.
		if ( current_user_can( VMS_Config::CAP_APPROVE_MEMBERS ) ) {
			$status = self::get_post_text( 'member_status' );
			if ( $status ) {
				$data['member_status'] = $status;
			}
			$member_number = self::get_post_text( 'member_number' );
			if ( $member_number ) {
				$data['member_number'] = $member_number;
			}
		}

		$result = self::update_member_profile( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'profile' => self::get_member_profile( $user_id ), 'message' => __( 'Profile updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: change the current user's password.
	 *
	 * Always operates on the *current* user — there's no user_id param,
	 * so a compromised session can't be used to change someone else's
	 * password even if the attacker can forge the nonce.
	 *
	 * @return void
	 */
	public function ajax_change_password(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'vms-plugin' ) ), 401 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above
		$current = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : '';
		$new     = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : '';
		// phpcs:enable

		if ( '' === $current || '' === $new ) {
			wp_send_json_error( array( 'message' => __( 'Both current and new password are required.', 'vms-plugin' ) ) );
		}

		// Re-verify the existing password before rotating. wp_check_password()
		// handles rehash-on-verify for legacy hashes, so no need to re-save.
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			wp_send_json_error( array( 'message' => __( 'Your current password is incorrect.', 'vms-plugin' ) ) );
		}

		// Enforce the same rules the profile UI checklist shows. Keep these
		// two in lockstep — the test in tests/test-vms-members-registration.php
		// exercises both sides.
		$rules = array(
			'length' => strlen( $new ) >= 8,
			'upper'  => (bool) preg_match( '/[A-Z]/', $new ),
			'lower'  => (bool) preg_match( '/[a-z]/', $new ),
			'number' => (bool) preg_match( '/\d/',   $new ),
		);
		if ( in_array( false, $rules, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'New password must be at least 8 characters and contain an uppercase letter, a lowercase letter, and a number.', 'vms-plugin' ),
					'rules'   => $rules,
				)
			);
		}

		if ( $current === $new ) {
			wp_send_json_error( array( 'message' => __( 'New password must be different from the current one.', 'vms-plugin' ) ) );
		}

		// wp_set_password() invalidates *all* sessions. We want the current
		// session to survive, so re-sign the user in silently afterwards.
		wp_set_password( $new, $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );

		VMS_Audit_Trail::log(
			'password_changed',
			VMS_Audit_Trail::CAT_SECURITY,
			'user',
			$user->ID,
			null,
			null,
			array( 'self_service' => true )
		);

		wp_send_json_success( array( 'message' => __( 'Password changed. Other devices have been signed out.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: get members list (paginated).
	 *
	 * @return void
	 */
	public function ajax_get_members_list(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_APPROVE_MEMBERS );

		$result = self::get_members_list(
			self::get_post_int( 'per_page' ) ?: 50,
			self::get_post_int( 'page' ) ?: 1,
			self::get_post_text( 'status' ),
			self::get_post_text( 'search' )
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: approve a member.
	 *
	 * @return void
	 */
	public function ajax_approve_member(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_APPROVE_MEMBERS );

		$result = self::approve_member( self::get_post_int( 'user_id' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Member approved.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: update member status.
	 *
	 * @return void
	 */
	public function ajax_update_member_status(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_APPROVE_MEMBERS );

		$result = self::update_member_status(
			self::get_post_int( 'user_id' ),
			self::get_post_text( 'status' )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Member status updated.', 'vms-plugin' ) ) );
	}
}
