<?php
/**
 * Base utility class with magic getter/setter and common helpers.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Base class providing container-style property access and logging.
 */
abstract class Base {

	/**
	 * Dynamic property container.
	 *
	 * @var array<string, mixed>
	 */
	protected array $container = array();

	/**
	 * Magic getter.
	 *
	 * @param string $key Property key.
	 * @return mixed|null
	 */
	public function __get( string $key ) {
		return $this->container[ $key ] ?? null;
	}

	/**
	 * Magic setter.
	 *
	 * @param string $key   Property key.
	 * @param mixed  $value Property value.
	 * @return void
	 */
	public function __set( string $key, $value ): void {
		$this->container[ $key ] = $value;
	}

	/**
	 * Magic isset.
	 *
	 * @param string $key Property key.
	 * @return bool
	 */
	public function __isset( string $key ): bool {
		return isset( $this->container[ $key ] );
	}

	/**
	 * Magic unset.
	 *
	 * @param string $key Property key.
	 * @return void
	 */
	public function __unset( string $key ): void {
		unset( $this->container[ $key ] );
	}

	/**
	 * Debug logger. Writes to debug.log only when WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level (info|warning|error).
	 * @return void
	 */
	protected static function log( string $message, string $level = 'info' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$class = static::class;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[VMS][%s][%s] %s', strtoupper( $level ), $class, $message ) );
	}

	/**
	 * Verify AJAX request (nonce + capability). Dies with JSON error on failure.
	 *
	 * @param string $nonce_action Nonce action to verify.
	 * @param string $capability   Required capability.
	 * @param string $nonce_field  POST field containing the nonce.
	 * @return void
	 */
	protected static function verify_ajax( string $nonce_action, string $capability, string $nonce_field = 'nonce' ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST[ $nonce_field ] ) ? wp_unslash( $_POST[ $nonce_field ] ) : '';

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh and try again.', 'vms-plugin' ) ),
				403
			);
		}

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'vms-plugin' ) ),
				403
			);
		}
	}

	/**
	 * Get sanitized text field from POST.
	 *
	 * @param string $key     POST key.
	 * @param string $default Default value.
	 * @return string
	 */
	protected static function get_post_text( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Get sanitized integer from POST.
	 *
	 * @param string $key     POST key.
	 * @param int    $default Default value.
	 * @return int
	 */
	protected static function get_post_int( string $key, int $default = 0 ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Get sanitized email from POST.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	protected static function get_post_email( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? sanitize_email( wp_unslash( $_POST[ $key ] ) ) : '';
	}
}
