<?php
/**
 * SMS Provider contract.
 *
 * All provider drivers must extend this abstract class. Keeps the
 * gateway decoupled from provider-specific HTTP details.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract SMS provider.
 */
abstract class VMS_SMS_Provider {

	/**
	 * HTTP request timeout (seconds).
	 */
	protected const TIMEOUT = 30;

	/**
	 * Get the provider's machine-readable key.
	 *
	 * @return string
	 */
	abstract public static function get_key(): string;

	/**
	 * Get the provider's human-readable name.
	 *
	 * @return string
	 */
	abstract public static function get_display_name(): string;

	/**
	 * Get the settings fields this provider needs (for admin UI).
	 *
	 * @return array<string, array{label: string, type: string, description?: string}>
	 */
	abstract public static function get_settings_fields(): array;

	/**
	 * Send a single SMS.
	 *
	 * @param string $phone   E.164 phone number.
	 * @param string $message Message body.
	 * @return array{success: bool, message_id?: string, status?: string, cost?: float, error?: string, raw?: array}
	 */
	abstract public function send( string $phone, string $message ): array;

	/**
	 * Fetch account balance.
	 *
	 * @return array{balance: ?float, currency: ?string, error?: string}
	 */
	abstract public function get_balance(): array;

	/**
	 * Parse a delivery status callback payload.
	 *
	 * @param array $payload Raw POST data from provider.
	 * @return array{message_id?: string, status?: string, delivered_at?: string}
	 */
	public function parse_callback( array $payload ): array {
		return array(); // Override in providers that support callbacks.
	}

	/**
	 * Poll for delivery status of a specific message.
	 *
	 * @param string $message_id Provider message ID.
	 * @return string|null Status constant or null if unknown.
	 */
	public function check_status( string $message_id ): ?string {
		return null; // Override in providers that support status polling.
	}

	/**
	 * Retrieve a provider-specific option.
	 *
	 * Options are stored as vms_sms_{provider_key}_{option_key}.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function get_option( string $key, $default = '' ) {
		return get_option( 'vms_sms_' . static::get_key() . '_' . $key, $default );
	}

	/**
	 * Make an HTTP POST request with JSON body.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $body    Request body.
	 * @param array  $headers Extra headers.
	 * @return array|\WP_Error Decoded response or WP_Error.
	 */
	protected function http_post_json( string $url, array $body, array $headers = array() ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'     => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					$headers
				),
				'body'        => wp_json_encode( $body ),
				'timeout'     => static::TIMEOUT,
				'sslverify'   => true,
				'data_format' => 'body',
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Make an HTTP POST request with form-encoded body.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $body    Request body.
	 * @param array  $headers Extra headers.
	 * @return array|\WP_Error
	 */
	protected function http_post_form( string $url, array $body, array $headers = array() ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'   => array_merge(
					array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					$headers
				),
				'body'      => $body,
				'timeout'   => static::TIMEOUT,
				'sslverify' => true,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Make an HTTP GET request.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $headers Extra headers.
	 * @return array|\WP_Error
	 */
	protected function http_get( string $url, array $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers'   => $headers,
				'timeout'   => static::TIMEOUT,
				'sslverify' => true,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Parse wp_remote_* response into array or error.
	 *
	 * @param array|\WP_Error $response Raw response.
	 * @return array|\WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return new \WP_Error(
				'http_error_' . $code,
				sprintf( 'HTTP %d: %s', $code, is_array( $data ) ? ( $data['message'] ?? $body ) : $body ),
				$data
			);
		}

		if ( null === $data && ! empty( $body ) ) {
			return new \WP_Error( 'json_decode_failed', 'Invalid JSON response: ' . substr( $body, 0, 200 ) );
		}

		return $data ?? array();
	}

	/**
	 * Build a standardized error response.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	protected function error( string $message ): array {
		return array(
			'success' => false,
			'error'   => $message,
		);
	}
}
