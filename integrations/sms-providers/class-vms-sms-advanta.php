<?php
/**
 * Advanta SMS provider driver.
 *
 * Kenyan bulk SMS provider (advantasms.com).
 * Uses API key + partner ID authentication.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Advanta SMS driver.
 */
final class VMS_SMS_Advanta extends VMS_SMS_Provider {

	/**
	 * API base URL.
	 */
	private const API_BASE = 'https://quicksms.advantasms.com/api/services';

	/**
	 * {@inheritDoc}
	 */
	public static function get_key(): string {
		return 'advanta';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_display_name(): string {
		return 'Advanta SMS (Kenya)';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_settings_fields(): array {
		return array(
			'api_key'    => array(
				'label'       => __( 'API Key', 'vms-plugin' ),
				'type'        => 'password',
				'description' => __( 'Your Advanta API key.', 'vms-plugin' ),
			),
			'partner_id' => array(
				'label'       => __( 'Partner ID', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Your Advanta partner ID.', 'vms-plugin' ),
			),
			'shortcode'  => array(
				'label'       => __( 'Shortcode / Sender ID', 'vms-plugin' ),
				'type'        => 'text',
				'description' => __( 'Approved sender ID.', 'vms-plugin' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function send( string $phone, string $message ): array {
		$api_key    = $this->get_option( 'api_key' );
		$partner_id = $this->get_option( 'partner_id' );
		$shortcode  = $this->get_option( 'shortcode' );

		if ( empty( $api_key ) || empty( $partner_id ) || empty( $shortcode ) ) {
			return $this->error( 'Advanta credentials not configured.' );
		}

		$response = $this->http_post_json(
			self::API_BASE . '/sendsms/',
			array(
				'apikey'    => $api_key,
				'partnerID' => $partner_id,
				'message'   => $message,
				'shortcode' => $shortcode,
				'mobile'    => ltrim( $phone, '+' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( $response->get_error_message() );
		}

		$resp = $response['responses'][0] ?? $response;
		$code = (int) ( $resp['response-code'] ?? 0 );

		// Advanta: 200 = success.
		if ( 200 !== $code ) {
			return $this->error( $resp['response-description'] ?? 'Advanta error code ' . $code );
		}

		return array(
			'success'    => true,
			'message_id' => isset( $resp['messageid'] ) ? (string) $resp['messageid'] : null,
			'status'     => VMS_Config::SMS_SENT,
			'cost'       => 0.0, // Advanta doesn't return cost per message.
			'raw'        => $response,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_balance(): array {
		$api_key    = $this->get_option( 'api_key' );
		$partner_id = $this->get_option( 'partner_id' );

		if ( empty( $api_key ) || empty( $partner_id ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => 'Credentials not configured.',
			);
		}

		$response = $this->http_post_json(
			self::API_BASE . '/getbalance/',
			array(
				'apikey'    => $api_key,
				'partnerID' => $partner_id,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'balance'  => null,
				'currency' => null,
				'error'    => $response->get_error_message(),
			);
		}

		return array(
			'balance'  => isset( $response['credit'] ) ? (float) $response['credit'] : null,
			'currency' => 'Credits',
		);
	}
}
