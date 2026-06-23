<?php
/**
 * Twilio SMS provider driver.
 *
 * Global SMS provider. API docs: https://www.twilio.com/docs/sms/api
 * Uses Basic Auth (Account SID : Auth Token).
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Twilio driver.
 */
final class VMS_SMS_Twilio extends VMS_SMS_Provider {

	/**
	 * API base URL (templated with account SID).
	 */
	private const API_BASE = 'https://api.twilio.com/2010-04-01/Accounts/%s';

	/**
	 * {@inheritDoc}
	 */
	public static function get_key(): string {
		return 'twilio';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_display_name(): string {
		return 'Twilio (Global)';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_settings_fields(): array {
		return array(
			'account_sid' => array(
				'label'       => __( 'Account SID', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Starts with "AC". Found in your Twilio console dashboard.', 'vms-plugin' ),
			),
			'auth_token'  => array(
				'label'       => __( 'Auth Token', 'vms-plugin' ),
				'type'        => 'password',
				'description' => __( 'Found in your Twilio console dashboard.', 'vms-plugin' ),
			),
			'from_number' => array(
				'label'       => __( 'From Number', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Your Twilio phone number in E.164 format (e.g. +15551234567) or Messaging Service SID (starts with MG).', 'vms-plugin' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function send( string $phone, string $message ): array {
		$sid   = $this->get_option( 'account_sid' );
		$token = $this->get_option( 'auth_token' );
		$from  = $this->get_option( 'from_number' );

		if ( empty( $sid ) || empty( $token ) || empty( $from ) ) {
			return $this->error( 'Twilio credentials not configured.' );
		}

		$url = sprintf( self::API_BASE, rawurlencode( $sid ) ) . '/Messages.json';

		$body = array(
			'To'   => $phone,
			'Body' => $message,
		);

		// Messaging Service SID vs. phone number.
		if ( str_starts_with( $from, 'MG' ) ) {
			$body['MessagingServiceSid'] = $from;
		} else {
			$body['From'] = $from;
		}

		// Delivery callback.
		$secret = get_option( 'vms_status_secret', '' );
		if ( $secret ) {
			$body['StatusCallback'] = add_query_arg( 'secret', $secret, VMS_Rewrite::get_sms_callback_url() );
		}

		$response = $this->http_post_form(
			$url,
			$body,
			array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( $response->get_error_message() );
		}

		if ( isset( $response['error_code'] ) && $response['error_code'] ) {
			return $this->error( $response['error_message'] ?? 'Twilio error ' . $response['error_code'] );
		}

		return array(
			'success'    => true,
			'message_id' => $response['sid'] ?? null,
			'status'     => $this->map_status( $response['status'] ?? 'queued' ),
			'cost'       => isset( $response['price'] ) ? abs( (float) $response['price'] ) : 0,
			'raw'        => $response,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_balance(): array {
		$sid   = $this->get_option( 'account_sid' );
		$token = $this->get_option( 'auth_token' );

		if ( empty( $sid ) || empty( $token ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => 'Credentials not configured.',
			);
		}

		$url = sprintf( self::API_BASE, rawurlencode( $sid ) ) . '/Balance.json';

		$response = $this->http_get(
			$url,
			array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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
			'currency' => $response['currency'] ?? 'USD',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function parse_callback( array $payload ): array {
		// Twilio sends: MessageSid, MessageStatus, To, From.
		return array(
			'message_id'   => sanitize_text_field( $payload['MessageSid'] ?? '' ),
			'status'       => $this->map_status( sanitize_text_field( $payload['MessageStatus'] ?? '' ) ),
			'delivered_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_status( string $message_id ): ?string {
		$sid   = $this->get_option( 'account_sid' );
		$token = $this->get_option( 'auth_token' );

		if ( empty( $sid ) || empty( $token ) ) {
			return null;
		}

		$url = sprintf( self::API_BASE, rawurlencode( $sid ) ) . '/Messages/' . rawurlencode( $message_id ) . '.json';

		$response = $this->http_get(
			$url,
			array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		if ( is_wp_error( $response ) || empty( $response['status'] ) ) {
			return null;
		}

		return $this->map_status( $response['status'] );
	}

	/**
	 * Map Twilio status strings to VMS constants.
	 *
	 * @param string $status Twilio status.
	 * @return string
	 */
	private function map_status( string $status ): string {
		$map = array(
			'queued'      => VMS_Config::SMS_QUEUED,
			'accepted'    => VMS_Config::SMS_QUEUED,
			'sending'     => VMS_Config::SMS_QUEUED,
			'sent'        => VMS_Config::SMS_SENT,
			'delivered'   => VMS_Config::SMS_DELIVERED,
			'undelivered' => VMS_Config::SMS_UNDELIVERED,
			'failed'      => VMS_Config::SMS_FAILED,
		);

		return $map[ strtolower( $status ) ] ?? VMS_Config::SMS_SENT;
	}
}
