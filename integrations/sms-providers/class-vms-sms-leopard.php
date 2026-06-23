<?php
/**
 * SMS Leopard provider driver.
 *
 * Kenyan SMS aggregator. API docs: https://docs.smsleopard.com/
 * Uses Basic Auth (api_key:api_secret). Supports delivery callbacks.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * SMS Leopard driver.
 */
final class VMS_SMS_Leopard extends VMS_SMS_Provider {

	/**
	 * API base URL.
	 */
	private const API_BASE = 'https://api.smsleopard.com/v1';

	/**
	 * {@inheritDoc}
	 */
	public static function get_key(): string {
		return 'leopard';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_display_name(): string {
		return 'SMS Leopard (Kenya)';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_settings_fields(): array {
		return array(
			'api_key'    => array(
				'label'       => __( 'API Key', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Found in your SMS Leopard dashboard under API settings.', 'vms-plugin' ),
			),
			'api_secret' => array(
				'label'       => __( 'API Secret', 'vms-plugin' ),
				'type'        => 'password',
				'description' => __( 'Keep this secret — it authenticates your account.', 'vms-plugin' ),
			),
			'sender_id'  => array(
				'label'       => __( 'Sender ID', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Approved alphanumeric sender name (e.g. MYCLUB). Use SMS_TEST for sandbox.', 'vms-plugin' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function send( string $phone, string $message ): array {
		$api_key    = $this->get_option( 'api_key' );
		$api_secret = $this->get_option( 'api_secret' );
		$sender_id  = $this->get_option( 'sender_id', 'SMS_TEST' );

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			return $this->error( 'SMS Leopard credentials not configured.' );
		}

		$payload = array(
			'source'      => $sender_id,
			'message'     => $message,
			'destination' => array(
				array( 'number' => ltrim( $phone, '+' ) ),
			),
		);

		// Attach delivery callback.
		$secret = get_option( 'vms_status_secret', '' );
		if ( $secret ) {
			$payload['status_url']    = VMS_Rewrite::get_sms_callback_url();
			$payload['status_secret'] = $secret;
		}

		$response = $this->http_post_json(
			self::API_BASE . '/sms/send',
			$payload,
			array( 'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( $response->get_error_message() );
		}

		if ( empty( $response['success'] ) ) {
			return $this->error( $response['message'] ?? 'Unknown Leopard API error.' );
		}

		$recipient = $response['recipients'][0] ?? array();

		return array(
			'success'    => true,
			'message_id' => $recipient['id'] ?? null,
			'status'     => $this->map_status( $recipient['status'] ?? 'sent' ),
			'cost'       => (float) ( $recipient['cost'] ?? 0 ),
			'raw'        => $response,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_balance(): array {
		$api_key    = $this->get_option( 'api_key' );
		$api_secret = $this->get_option( 'api_secret' );

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => 'Credentials not configured.',
			);
		}

		$response = $this->http_get(
			self::API_BASE . '/balance',
			array( 'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => $response->get_error_message(),
			);
		}

		return array(
			'balance'  => isset( $response['balance'] ) ? (float) $response['balance'] : null,
			'currency' => $response['currency'] ?? 'KES',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function parse_callback( array $payload ): array {
		// Leopard sends: id, status, cost, number.
		return array(
			'message_id'   => sanitize_text_field( $payload['id'] ?? '' ),
			'status'       => $this->map_status( sanitize_text_field( $payload['status'] ?? '' ) ),
			'delivered_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Map Leopard status strings to VMS constants.
	 *
	 * @param string $status Leopard status.
	 * @return string
	 */
	private function map_status( string $status ): string {
		$map = array(
			'Success'     => VMS_Config::SMS_DELIVERED,
			'Sent'        => VMS_Config::SMS_SENT,
			'Queued'      => VMS_Config::SMS_QUEUED,
			'Failed'      => VMS_Config::SMS_FAILED,
			'Expired'     => VMS_Config::SMS_EXPIRED,
			'Undelivered' => VMS_Config::SMS_UNDELIVERED,
		);

		return $map[ $status ] ?? VMS_Config::SMS_SENT;
	}
}
