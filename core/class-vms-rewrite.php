<?php
/**
 * Rewrite rules & custom endpoints.
 *
 * Registers the SMS delivery callback endpoint and any other
 * custom URL structures needed by the plugin.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite manager.
 */
final class VMS_Rewrite extends Singleton {

	/**
	 * Query var used for VMS endpoints.
	 */
	private const QUERY_VAR = 'vms_endpoint';

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_endpoint' ) );
	}

	/**
	 * Register rewrite rules.
	 *
	 * Static so it can be called from the activator before
	 * flush_rewrite_rules().
	 *
	 * @return void
	 */
	public static function register_rules(): void {
		// SMS delivery status callback: /vms-api/sms-callback/
		add_rewrite_rule(
			'^vms-api/sms-callback/?$',
			'index.php?' . self::QUERY_VAR . '=sms_callback',
			'top'
		);

		// Health check: /vms-api/health/
		add_rewrite_rule(
			'^vms-api/health/?$',
			'index.php?' . self::QUERY_VAR . '=health',
			'top'
		);
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Handle custom endpoint requests.
	 *
	 * @return void
	 */
	public function handle_endpoint(): void {
		$endpoint = get_query_var( self::QUERY_VAR );

		if ( empty( $endpoint ) ) {
			return;
		}

		switch ( $endpoint ) {
			case 'sms_callback':
				$this->handle_sms_callback();
				break;

			case 'health':
				$this->handle_health_check();
				break;
		}
	}

	/**
	 * Handle SMS delivery status callback from providers.
	 *
	 * @return void
	 */
	private function handle_sms_callback(): void {
		// Verify secret to prevent spoofed callbacks.
		$expected = get_option( 'vms_status_secret', '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$received = isset( $_POST['status_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['status_secret'] ) ) : '';

		// Also check GET (some providers use GET params).
		if ( empty( $received ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$received = isset( $_GET['secret'] ) ? sanitize_text_field( wp_unslash( $_GET['secret'] ) ) : '';
		}

		if ( empty( $expected ) || ! hash_equals( $expected, $received ) ) {
			status_header( 403 );
			wp_send_json_error( array( 'message' => 'Invalid secret' ), 403 );
		}

		/**
		 * Fires when an SMS delivery callback is received.
		 *
		 * SMS provider classes hook this to process the payload.
		 *
		 * @since 2.0.0
		 * @param array $payload Raw callback payload.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		do_action( 'vms_sms_callback_received', wp_unslash( $_POST ) );

		status_header( 200 );
		wp_send_json_success( array( 'received' => true ) );
	}

	/**
	 * Health check endpoint.
	 *
	 * @return void
	 */
	private function handle_health_check(): void {
		$checks = array(
			'database' => VMS_Database_Manager::table_exists( VMS_Config::TABLE_GUESTS ),
			'cron'     => (bool) wp_next_scheduled( VMS_Config::CRON_MIDNIGHT_TASKS ),
			'version'  => VMS_PLUGIN_VERSION,
			'time'     => current_time( 'c' ),
		);

		$healthy = $checks['database'] && $checks['cron'];

		status_header( $healthy ? 200 : 503 );
		wp_send_json(
			array(
				'status' => $healthy ? 'healthy' : 'degraded',
				'checks' => $checks,
			)
		);
	}

	/**
	 * Get the SMS callback URL for provider configuration.
	 *
	 * @return string
	 */
	public static function get_sms_callback_url(): string {
		return home_url( '/vms-api/sms-callback/' );
	}
}
