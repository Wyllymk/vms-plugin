<?php
/**
 * AJAX helper trait.
 *
 * Provides common AJAX utilities (nonce verification, POST sanitization)
 * to any class that handles wp_ajax_* actions. Designed to be used with
 * Singleton-based module classes that need to process AJAX requests.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Base AJAX trait.
 */
trait Base_Ajax_Trait {

	/**
	 * Verify AJAX request (nonce + capability). Dies with JSON error on failure.
	 *
	 * Always responds with HTTP 200 so that server-level 403 error pages
	 * (e.g. Nginx custom error documents) cannot replace our JSON body with
	 * HTML — which produces the "Unexpected token '<'" parse error on the
	 * client. Auth failures are communicated through `success: false` in the
	 * JSON body instead.
	 *
	 * @param string $nonce_action Nonce action to verify.
	 * @param string $capability   Required capability.
	 * @param string $nonce_field  POST field containing the nonce.
	 * @return void
	 */
	protected static function verify_ajax( string $nonce_action, string $capability, string $nonce_field = 'nonce' ): void {
		// Prefer the explicit field, but fall back to WP's own convention
		// (`_ajax_nonce`) so callers that use check_ajax_referer()-style
		// naming don't silently fail. The action string must still match,
		// so this is belt-and-braces — not a hole.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST[ $nonce_field ] ) ? wp_unslash( $_POST[ $nonce_field ] ) : '';
		if ( '' === $nonce && isset( $_POST['_ajax_nonce'] ) ) {
			$nonce = wp_unslash( $_POST['_ajax_nonce'] );
		}
		// phpcs:enable

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			// Use status 200 — 403 causes nginx/Apache error-page interception
			// which replaces our JSON body with HTML (→ "Unexpected token '<'").
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh and try again.', 'vms-plugin' ), 'code' => 'nonce_failed' )
			);
		}

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'vms-plugin' ), 'code' => 'no_permission' )
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

	/**
	 * Get sanitized phone from POST.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	protected static function get_post_phone( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		// Strip everything except digits, +, and spaces.
		return preg_replace( '/[^\d+\s\-()]/', '', $raw );
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
}
