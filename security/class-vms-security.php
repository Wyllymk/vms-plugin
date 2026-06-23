<?php
/**
 * Security hardening module.
 *
 * Implements defense-in-depth measures: rate limiting, security headers,
 * login protection, and request validation. Hooks are kept lightweight
 * so they don't impact front-end performance.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Security manager.
 */
final class VMS_Security extends Singleton {

	/**
	 * Maximum login attempts per IP before lockout.
	 */
	private const MAX_LOGIN_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds.
	 */
	private const LOCKOUT_DURATION = 900; // 15 minutes.

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Security headers on all responses.
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );

		// Login rate limiting.
		add_filter( 'authenticate', array( $this, 'check_login_attempts' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ) );
		add_action( 'wp_login', array( $this, 'clear_login_attempts' ), 10, 2 );

		// Disable XML-RPC (common attack vector).
		add_filter( 'xmlrpc_enabled', '__return_false' );

		// Hide WP version.
		remove_action( 'wp_head', 'wp_generator' );

			// Prevent user enumeration via ?author=N.
			add_action( 'init', array( $this, 'block_author_enumeration' ) );

			// Keep non-administrators out of wp-admin; VMS staff use the frontend app.
			add_action( 'admin_init', array( $this, 'restrict_wp_admin_access' ) );

			// Restrict REST API user endpoint to authenticated users.
			add_filter( 'rest_endpoints', array( $this, 'restrict_rest_user_endpoint' ) );
		}

	/**
	 * Send security HTTP headers.
	 *
	 * @return void
	 */
	public function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-XSS-Protection: 1; mode=block' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );

		// HSTS only over HTTPS.
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}

	/**
	 * Block login attempts if IP is locked out.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authenticated user or error.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function check_login_attempts( $user, $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$ip       = $this->get_client_ip();
		$attempts = (int) get_transient( 'vms_login_attempts_' . md5( $ip ) );

		if ( $attempts >= self::MAX_LOGIN_ATTEMPTS ) {
			return new \WP_Error(
				'vms_login_locked',
				sprintf(
					/* translators: %d: minutes until unlock */
					__( 'Too many failed login attempts. Please try again in %d minutes.', 'vms-plugin' ),
					ceil( self::LOCKOUT_DURATION / 60 )
				)
			);
		}

		return $user;
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username Submitted username.
	 * @return void
	 */
	public function record_failed_login( string $username ): void {
		$ip       = $this->get_client_ip();
		$key      = 'vms_login_attempts_' . md5( $ip );
		$attempts = (int) get_transient( $key );

		set_transient( $key, $attempts + 1, self::LOCKOUT_DURATION );

		// Log to audit trail.
		VMS_Audit_Trail::log(
			'login_failed',
			'security',
			null,
			null,
			null,
			array(
				'username' => $username,
				'attempts' => $attempts + 1,
			)
		);
	}

	/**
	 * Clear login attempts on successful login.
	 *
	 * @param string   $username Username.
	 * @param \WP_User $user     User object.
	 * @return void
	 */
	public function clear_login_attempts( string $username, \WP_User $user ): void {
		$ip = $this->get_client_ip();
		delete_transient( 'vms_login_attempts_' . md5( $ip ) );

		VMS_Audit_Trail::log(
			'login_success',
			'security',
			'user',
			$user->ID
		);
	}

	/**
	 * Block author enumeration via ?author=N URLs.
	 *
	 * @return void
	 */
	public function block_author_enumeration(): void {
		if ( is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['author'] ) && ! current_user_can( 'list_users' ) ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Redirect non-admin users away from wp-admin.
	 *
	 * @return void
	 */
	public function restrict_wp_admin_access(): void {
		if ( wp_doing_ajax() || current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Restrict REST API /wp/v2/users endpoint to authenticated requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array
	 */
	public function restrict_rest_user_endpoint( array $endpoints ): array {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'] );
			unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	/**
	 * Rate-limit an arbitrary action.
	 *
	 * @param string $action     Action identifier.
	 * @param int    $max        Max allowed within window.
	 * @param int    $window     Window duration in seconds.
	 * @param string $identifier Optional identifier (defaults to IP).
	 * @return bool True if within limits, false if rate-limited.
	 */
	public static function rate_limit( string $action, int $max, int $window, string $identifier = '' ): bool {
		$identifier = $identifier ?: self::instance()->get_client_ip();
		$key        = 'vms_rl_' . md5( $action . '_' . $identifier );
		$count      = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Get the client IP address, accounting for proxies.
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'HTTP_X_FORWARDED_FOR',  // Standard proxy header.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( $_SERVER[ $header ] );

			// X-Forwarded-For may contain a comma-separated list.
			$ips = array_map( 'trim', explode( ',', $raw ) );

			foreach ( $ips as $ip ) {
				$ip = filter_var( $ip, FILTER_VALIDATE_IP );
				if ( $ip ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Validate a file upload against allowed types & size.
	 *
	 * @param array $file     $_FILES entry.
	 * @param array $allowed  Allowed MIME types.
	 * @param int   $max_size Max size in bytes.
	 * @return \WP_Error|true
	 */
	public static function validate_upload( array $file, array $allowed, int $max_size ) {
		if ( ! isset( $file['tmp_name'], $file['size'], $file['name'] ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'vms-plugin' ) );
		}

		if ( $file['size'] > $max_size ) {
			return new \WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: max file size */
					__( 'File exceeds maximum size of %s.', 'vms-plugin' ),
					size_format( $max_size )
				)
			);
		}

		// Verify real MIME type (don't trust client-supplied type).
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );

		if ( ! $check['type'] || ! in_array( $check['type'], $allowed, true ) ) {
			return new \WP_Error(
				'invalid_file_type',
				sprintf(
					/* translators: %s: allowed types */
					__( 'File type not allowed. Allowed types: %s', 'vms-plugin' ),
					implode( ', ', $allowed )
				)
			);
		}

		return true;
	}
}
