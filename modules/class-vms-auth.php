<?php
/**
 * Authentication & Password Reset handler.
 *
 * Provides AJAX endpoints for login, logout, password reset request,
 * and password reset execution. All work without page reloads so the
 * frontend can handle auth flows via Alpine.js.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Auth module.
 */
final class VMS_Auth extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// AJAX login (for logged-out users).
		add_action( 'wp_ajax_nopriv_vms_login', array( $this, 'ajax_login' ) );
		add_action( 'wp_ajax_vms_login', array( $this, 'ajax_login' ) );

		// AJAX logout.
		add_action( 'wp_ajax_vms_logout', array( $this, 'ajax_logout' ) );

		// Password reset request (sends email).
		add_action( 'wp_ajax_nopriv_vms_request_password_reset', array( $this, 'ajax_request_password_reset' ) );
		add_action( 'wp_ajax_vms_request_password_reset', array( $this, 'ajax_request_password_reset' ) );

		// Password reset execution (with key + new password).
		add_action( 'wp_ajax_nopriv_vms_reset_password', array( $this, 'ajax_reset_password' ) );
		add_action( 'wp_ajax_vms_reset_password', array( $this, 'ajax_reset_password' ) );

		// Customize the password reset email to point to our custom page.
		add_filter( 'retrieve_password_message', array( $this, 'customize_reset_email' ), 10, 4 );
		add_filter( 'retrieve_password_title', array( $this, 'customize_reset_email_subject' ), 10, 3 );

		// Provide nonces for non-logged-in users.
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_auth_nonces' ), 30 );
	}

	/**
	 * AJAX: Log a user in.
	 *
	 * @return void
	 */
	public function ajax_login(): void {
		// Rate limit login attempts.
		$ip = VMS_Security::instance()->get_client_ip();
		if ( ! VMS_Security::rate_limit( 'ajax_login', 10, 300, $ip ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many login attempts. Please try again later.', 'vms-plugin' ) ),
				429
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vms_auth_nonce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh the page.', 'vms-plugin' ) ),
				403
			);
		}

		$username = self::get_post_text( 'username' );
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Username and password are required.', 'vms-plugin' ) )
			);
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['remember'] ), // phpcs:ignore
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$code    = $user->get_error_code();
			$message = in_array( $code, array( 'vms_pending_approval', 'vms_rejected', 'vms_login_locked' ), true )
				? $user->get_error_message()
				: __( 'Invalid username or password.', 'vms-plugin' );

			wp_send_json_error(
				array( 'message' => $message )
			);
		}

		wp_set_current_user( $user->ID );

		// Determine redirect URL based on role.
		$role = VMS_Roles::get_user_vms_role( $user->ID );

		$redirect_map = array(
			'reception'       => 'vms-sign-in',
			'gate'            => 'vms-sign-in',
			'member'          => 'guests',
			'chairman'        => 'vms-dashboard',
			'general_manager' => 'vms-dashboard',
			'administrator'   => 'vms-dashboard',
		);

		$slug = $redirect_map[ $role ] ?? 'vms-dashboard';
		$page = get_page_by_path( $slug );
		$url  = $page ? get_permalink( $page ) : home_url( '/' );

		wp_send_json_success(
			array(
				'message'  => __( 'Login successful. Redirecting...', 'vms-plugin' ),
				'redirect' => $url,
				'role'     => $role,
			)
		);
	}

	/**
	 * AJAX: Log a user out.
	 *
	 * @return void
	 */
	public function ajax_logout(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vms_auth_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vms-plugin' ) ), 403 );
		}

		wp_logout();

		wp_send_json_success(
			array(
				'message'  => __( 'Logged out successfully.', 'vms-plugin' ),
				'redirect' => home_url( '/' ),
			)
		);
	}

	/**
	 * AJAX: Request password reset email.
	 *
	 * @return void
	 */
	public function ajax_request_password_reset(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vms_auth_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vms-plugin' ) ), 403 );
		}

		$user_login = self::get_post_text( 'user_login' );

		if ( empty( $user_login ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please enter your username or email address.', 'vms-plugin' ) )
			);
		}

		// Rate limit password reset requests.
		if ( ! VMS_Security::rate_limit( 'password_reset_request', 3, 300, $user_login ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many reset requests. Please try again in a few minutes.', 'vms-plugin' ) ),
				429
			);
		}

		// Use WordPress built-in retrieve_password which sends the email.
		$result = retrieve_password( $user_login );

		if ( is_wp_error( $result ) ) {
			// Don't reveal whether user exists - generic message.
			wp_send_json_success(
				array( 'message' => __( 'If an account exists with that email/username, a password reset link has been sent.', 'vms-plugin' ) )
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'If an account exists with that email/username, a password reset link has been sent.', 'vms-plugin' ) )
		);
	}

	/**
	 * AJAX: Execute password reset with key and new password.
	 *
	 * @return void
	 */
	public function ajax_reset_password(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'vms_auth_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vms-plugin' ) ), 403 );
		}

		$login    = self::get_post_text( 'login' );
		$key      = self::get_post_text( 'key' );
		$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore

		if ( empty( $login ) || empty( $key ) || empty( $password ) ) {
			wp_send_json_error(
				array( 'message' => __( 'All fields are required.', 'vms-plugin' ) )
			);
		}

			// Validate password strength.
			if ( strlen( $password ) < 8 || ! preg_match( '/[A-Z]/', $password ) || ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/\d/', $password ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Password must be at least 8 characters and include uppercase, lowercase, and a number.', 'vms-plugin' ) )
				);
			}

		// Verify the reset key.
		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This password reset link is invalid or has expired. Please request a new one.', 'vms-plugin' ) )
			);
		}

		// Reset the password.
		reset_password( $user, $password );

		VMS_Audit_Trail::log(
			'password_reset',
			VMS_Audit_Trail::CAT_SECURITY,
			'user',
			$user->ID
		);

		wp_send_json_success(
			array(
				'message'  => __( 'Your password has been reset successfully. You can now log in.', 'vms-plugin' ),
				'redirect' => home_url( '/' ),
			)
		);
	}

	/**
	 * Customize the password reset email to use our custom reset page.
	 *
	 * @param string  $message    Email message.
	 * @param string  $key        Reset key.
	 * @param string  $user_login User login name.
	 * @param WP_User $user_data  User data.
	 * @return string
	 */
	public function customize_reset_email( $message, $key, $user_login, $user_data ): string {
		$club_name = VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) );

		// Build the reset URL pointing to our custom page or front page with params.
		$reset_page = get_page_by_path( 'vms-reset-password' );
		if ( $reset_page ) {
			$reset_url = add_query_arg(
				array(
					'key'   => $key,
					'login' => rawurlencode( $user_login ),
				),
				get_permalink( $reset_page )
			);
		} else {
			$reset_url = add_query_arg(
				array(
					'action' => 'rp',
					'key'    => $key,
					'login'  => rawurlencode( $user_login ),
				),
				home_url( '/' )
			);
		}

		$message  = sprintf( __( 'Hello %s,', 'vms-plugin' ), $user_login ) . "\r\n\r\n";
		$message .= __( 'Someone has requested a password reset for your account.', 'vms-plugin' ) . "\r\n\r\n";
		$message .= __( 'If this was a mistake, just ignore this email and nothing will happen.', 'vms-plugin' ) . "\r\n\r\n";
		$message .= __( 'To reset your password, click the link below:', 'vms-plugin' ) . "\r\n\r\n";
		$message .= $reset_url . "\r\n\r\n";
		$message .= sprintf( __( 'This link will expire in 24 hours.', 'vms-plugin' ) ) . "\r\n\r\n";
		$message .= sprintf( __( '-- %s', 'vms-plugin' ), $club_name ) . "\r\n";

		return $message;
	}

	/**
	 * Customize password reset email subject.
	 *
	 * @param string $title   Email subject.
	 * @param string $user_login User login name.
	 * @param object $user_data  User data.
	 * @return string
	 */
	public function customize_reset_email_subject( $title, $user_login = '', $user_data = null ): string {
		$club_name = VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) );
		return sprintf(
			/* translators: %s: club name */
			__( '[%s] Password Reset Request', 'vms-plugin' ),
			$club_name
		);
	}

	/**
	 * Localize auth nonces for frontend (available to non-logged-in users too).
	 *
	 * @return void
	 */
	public function localize_auth_nonces(): void {
		wp_localize_script(
			'vms-theme-main',
			'vmsAuth',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'vms_auth_nonce' ),
				'loginUrl'  => home_url( '/' ),
				'isLoggedIn' => is_user_logged_in(),
			)
		);
	}
}
