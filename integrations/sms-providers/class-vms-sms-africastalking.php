<?php
/**
 * Africa's Talking SMS provider driver.
 *
 * Pan-African SMS provider popular in Kenya.
 * API docs: https://developers.africastalking.com/docs/sms/overview
 * Uses API Key header authentication.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Africa's Talking driver.
 */
final class VMS_SMS_AfricasTalking extends VMS_SMS_Provider {

	/**
	 * Production API base.
	 */
	private const API_LIVE = 'https://api.africastalking.com/version1';

	/**
	 * Sandbox API base.
	 */
	private const API_SANDBOX = 'https://api.sandbox.africastalking.com/version1';

	/**
	 * {@inheritDoc}
	 */
	public static function get_key(): string {
		return 'africas_talking';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_display_name(): string {
		return "Africa's Talking";
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_settings_fields(): array {
		return array(
			'username' => array(
				'label'       => __( 'Username', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Your Africa\'s Talking application username. Use "sandbox" for testing.', 'vms-plugin' ),
			),
			'api_key'  => array(
				'label'       => __( 'API Key', 'vms-plugin' ),
				'type'        => 'password',
				'description' => __( 'Generated in your Africa\'s Talking dashboard under Settings → API Key.', 'vms-plugin' ),
			),
			'sender_id' => array(
				'label'       => __( 'Sender ID / Shortcode', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave blank to use the default shared shortcode.', 'vms-plugin' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function send( string $phone, string $message ): array {
		$username = $this->get_option( 'username' );
		$api_key  = $this->get_option( 'api_key' );
		$sender   = $this->get_option( 'sender_id' );

		if ( empty( $username ) || empty( $api_key ) ) {
			return $this->error( "Africa's Talking credentials not configured." );
		}

		$body = array(
			'username' => $username,
			'to'       => $phone,
			'message'  => $message,
		);

		if ( ! empty( $sender ) ) {
			$body['from'] = $sender;
		}

		$response = $this->http_post_form(
			$this->get_api_base() . '/messaging',
			$body,
			array(
				'apiKey' => $api_key,
				'Accept' => 'application/json',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( $response->get_error_message() );
		}

		$data = $response['SMSMessageData'] ?? array();
		$recp = $data['Recipients'][0] ?? array();

		// Status code 101 = success on AT.
		$success = isset( $recp['statusCode'] ) && 101 === (int) $recp['statusCode'];

		if ( ! $success ) {
			return $this->error( $recp['status'] ?? $data['Message'] ?? 'Unknown AT error' );
		}

		// Cost comes as "KES 0.8000".
		$cost = 0.0;
		if ( isset( $recp['cost'] ) && preg_match( '/[\d.]+/', $recp['cost'], $m ) ) {
			$cost = (float) $m[0];
		}

		return array(
			'success'    => true,
			'message_id' => $recp['messageId'] ?? null,
			'status'     => VMS_Config::SMS_SENT,
			'cost'       => $cost,
			'raw'        => $response,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_balance(): array {
		$username = $this->get_option( 'username' );
		$api_key  = $this->get_option( 'api_key' );

		if ( empty( $username ) || empty( $api_key ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => 'Credentials not configured.',
			);
		}

		$url = $this->get_api_base() . '/user?username=' . rawurlencode( $username );

		$response = $this->http_get(
			$url,
			array(
				'apiKey' => $api_key,
				'Accept' => 'application/json',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => $response->get_error_message(),
			);
		}

		// Balance comes as "KES 1234.5678".
		$raw = $response['UserData']['balance'] ?? '';
		$parts = explode( ' ', trim( $raw ), 2 );

		return array(
			'balance'  => isset( $parts[1] ) ? (float) $parts[1] : null,
			'currency' => $parts[0] ?? 'KES',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function parse_callback( array $payload ): array {
		// AT sends: id, status, phoneNumber, networkCode, failureReason.
		return array(
			'message_id'   => sanitize_text_field( $payload['id'] ?? '' ),
			'status'       => $this->map_status( sanitize_text_field( $payload['status'] ?? '' ) ),
			'delivered_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Get the appropriate API base URL.
	 *
	 * @return string
	 */
	private function get_api_base(): string {
		return 'sandbox' === $this->get_option( 'username' ) ? self::API_SANDBOX : self::API_LIVE;
	}

	/**
	 * Map AT status strings to VMS constants.
	 *
	 * @param string $status AT status.
	 * @return string
	 */
	private function map_status( string $status ): string {
		$map = array(
			'Success'  => VMS_Config::SMS_DELIVERED,
			'Sent'     => VMS_Config::SMS_SENT,
			'Buffered' => VMS_Config::SMS_QUEUED,
			'Rejected' => VMS_Config::SMS_FAILED,
			'Failed'   => VMS_Config::SMS_FAILED,
		);

		return $map[ $status ] ?? VMS_Config::SMS_SENT;
	}
}
