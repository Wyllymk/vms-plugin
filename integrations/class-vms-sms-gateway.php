<?php
/**
 * SMS Gateway abstraction.
 *
 * Provides a unified interface over multiple SMS providers. The active
 * provider is selected via plugin settings; all callers go through
 * VMS_SMS_Gateway::send() without knowing which provider is underneath.
 *
 * Supported providers:
 *   - SMS Leopard (Kenya)    - leopard
 *   - Twilio (Global)        - twilio
 *   - Africa's Talking (KE)  - africas_talking
 *   - Advanta SMS (Kenya)    - advanta
 *   - Celcom Africa (Kenya)  - celcom
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * SMS Gateway.
 */
final class VMS_SMS_Gateway extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Registered provider drivers.
	 *
	 * @var array<string, class-string<VMS_SMS_Provider>>
	 */
	private array $providers = array();

	/**
	 * Active provider instance cache.
	 *
	 * @var VMS_SMS_Provider|null
	 */
	private ?VMS_SMS_Provider $active_provider = null;

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Register built-in providers.
		$this->register_provider( 'leopard', VMS_SMS_Leopard::class );
		$this->register_provider( 'twilio', VMS_SMS_Twilio::class );
		$this->register_provider( 'africas_talking', VMS_SMS_AfricasTalking::class );
		$this->register_provider( 'advanta', VMS_SMS_Advanta::class );
		$this->register_provider( 'celcom', VMS_SMS_Celcom::class );

		/**
		 * Allow third-party providers to register themselves.
		 *
		 * @since 2.0.0
		 * @param VMS_SMS_Gateway $gateway This instance.
		 */
		do_action( 'vms_register_sms_providers', $this );

		// Hooks.
		add_action( 'vms_sms_callback_received', array( $this, 'handle_delivery_callback' ) );
		add_action( VMS_Config::CRON_SMS_BALANCE, array( $this, 'cron_check_balance' ) );
		add_action( VMS_Config::CRON_SMS_DELIVERY, array( $this, 'cron_check_pending' ) );

		// AJAX.
		add_action( 'wp_ajax_vms_test_sms', array( $this, 'ajax_test_sms' ) );
		add_action( 'wp_ajax_vms_sms_balance', array( $this, 'ajax_get_balance' ) );
	}

	/**
	 * Register a provider driver.
	 *
	 * @param string $key        Provider identifier.
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public function register_provider( string $key, string $class_name ): void {
		$this->providers[ sanitize_key( $key ) ] = $class_name;
	}

	/**
	 * Get the active provider instance.
	 *
	 * @return VMS_SMS_Provider
	 * @throws \RuntimeException If provider not configured.
	 */
	public function get_provider(): VMS_SMS_Provider {
		if ( $this->active_provider instanceof VMS_SMS_Provider ) {
			return $this->active_provider;
		}

		$key = VMS_Config::get_option( 'sms_provider', 'leopard' );

		if ( ! isset( $this->providers[ $key ] ) ) {
			throw new \RuntimeException( sprintf( 'Unknown SMS provider: %s', esc_html( $key ) ) );
		}

		$class = $this->providers[ $key ];
		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( sprintf( 'SMS provider class not found: %s', esc_html( $class ) ) );
		}

		$this->active_provider = new $class();
		return $this->active_provider;
	}

	/**
	 * Get all registered providers (for settings dropdown).
	 *
	 * @return array<string, string> Key => display name.
	 */
	public function get_available_providers(): array {
		$list = array();
		foreach ( $this->providers as $key => $class ) {
			if ( class_exists( $class ) ) {
				$list[ $key ] = $class::get_display_name();
			}
		}
		return $list;
	}

	/**
	 * Send an SMS. This is the primary public entry point.
	 *
	 * @param string      $phone          Recipient phone number (any format).
	 * @param string      $message        Message body (max ~1600 chars for concat).
	 * @param int|null    $user_id        WP user ID (for logging association).
	 * @param string|null $recipient_role Role/type label (guest, host, member).
	 * @return array{success: bool, message_id?: string, cost?: float, error?: string}
	 */
	public static function send( string $phone, string $message, ?int $user_id = null, ?string $recipient_role = null ): array {
		// Check SMS enabled globally.
		if ( ! VMS_Config::get_option( 'enable_sms_notifications', true ) ) {
			return array(
				'success' => false,
				'error'   => 'SMS notifications are disabled.',
			);
		}

		// Rate limit: max 10 SMS per phone per hour to prevent runaway loops.
		if ( ! VMS_Security::rate_limit( 'sms_send', 10, HOUR_IN_SECONDS, $phone ) ) {
			self::log_sms( $user_id, $recipient_role, 'rate_limited', $phone, $message, null, VMS_Config::SMS_FAILED, 0, 'Rate limit exceeded' );
			return array(
				'success' => false,
				'error'   => 'SMS rate limit exceeded for this number.',
			);
		}

		$gateway = self::instance();

		try {
			$provider = $gateway->get_provider();
		} catch ( \RuntimeException $e ) {
			self::log_sms( $user_id, $recipient_role, 'none', $phone, $message, null, VMS_Config::SMS_FAILED, 0, $e->getMessage() );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		$clean_phone = self::normalize_phone( $phone );
		if ( null === $clean_phone ) {
			self::log_sms( $user_id, $recipient_role, $provider::get_key(), $phone, $message, null, VMS_Config::SMS_FAILED, 0, 'Invalid phone number' );
			return array(
				'success' => false,
				'error'   => 'Invalid phone number format.',
			);
		}

		// Delegate to provider.
		$result = $provider->send( $clean_phone, $message );

		// Log the result.
		self::log_sms(
			$user_id,
			$recipient_role,
			$provider::get_key(),
			$clean_phone,
			$message,
			$result['message_id'] ?? null,
			$result['success'] ? ( $result['status'] ?? VMS_Config::SMS_SENT ) : VMS_Config::SMS_FAILED,
			$result['cost'] ?? 0,
			$result['error'] ?? null,
			$result['raw'] ?? null
		);

		return $result;
	}

	/**
	 * Send bulk SMS (same message to multiple recipients).
	 *
	 * @param array  $recipients Array of ['phone' => string, 'user_id' => ?int, 'role' => ?string].
	 * @param string $message    Message body.
	 * @return array Results indexed by phone.
	 */
	public static function send_bulk( array $recipients, string $message ): array {
		$results = array();

		foreach ( $recipients as $r ) {
			$phone = is_array( $r ) ? ( $r['phone'] ?? '' ) : $r;
			if ( empty( $phone ) ) {
				continue;
			}

			$results[ $phone ] = self::send(
				$phone,
				$message,
				is_array( $r ) ? ( $r['user_id'] ?? null ) : null,
				is_array( $r ) ? ( $r['role'] ?? null ) : null
			);

			// Small delay between messages to be polite to provider APIs.
			usleep( 100000 ); // 100ms.
		}

		return $results;
	}

	/**
	 * Get account balance from active provider.
	 *
	 * @return array{balance: ?float, currency: ?string, error: ?string}
	 */
	public static function get_balance(): array {
		// Cache balance for 5 minutes to avoid hammering provider APIs.
		return VMS_Cache::cached(
			'sms:balance',
			static function () {
				try {
					return self::instance()->get_provider()->get_balance();
				} catch ( \RuntimeException $e ) {
					return array(
						'balance'  => null,
						'currency' => null,
						'error'    => $e->getMessage(),
					);
				}
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Handle delivery status callback from provider.
	 *
	 * @param array $payload Raw POST payload.
	 * @return void
	 */
	public function handle_delivery_callback( array $payload ): void {
		try {
			$provider = $this->get_provider();
			$parsed   = $provider->parse_callback( $payload );

			if ( empty( $parsed['message_id'] ) ) {
				return;
			}

			self::update_delivery_status(
				$parsed['message_id'],
				$parsed['status'] ?? VMS_Config::SMS_SENT,
				$parsed['delivered_at'] ?? null
			);
		} catch ( \Exception $e ) {
			// Silently fail — callback may be from a different provider.
			self::log( 'SMS callback parse failed: ' . $e->getMessage(), 'warning' );
		}
	}

	/**
	 * Update delivery status for a logged message.
	 *
	 * @param string      $message_id   Provider message ID.
	 * @param string      $status       New status.
	 * @param string|null $delivered_at Delivery timestamp.
	 * @return void
	 */
	private static function update_delivery_status( string $message_id, string $status, ?string $delivered_at = null ): void {
		global $wpdb;

		if ( ! VMS_Config::is_valid_status( $status, 'sms' ) ) {
			return;
		}

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SMS_LOGS );

		$data = array( 'status' => $status );
		if ( $delivered_at ) {
			$data['delivered_at'] = $delivered_at;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			$data,
			array( 'message_id' => $message_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Cron: fetch & cache account balance.
	 *
	 * @return void
	 */
	public function cron_check_balance(): void {
		VMS_Cache::forget( 'sms:balance' );
		$balance = self::get_balance();

		// Alert admin if balance is low.
		if ( isset( $balance['balance'] ) && $balance['balance'] < 100 ) {
			VMS_Notifications::notify_admin(
				__( 'Low SMS Balance', 'vms-plugin' ),
				sprintf(
					/* translators: 1: balance, 2: currency */
					__( 'Your SMS balance is low: %1$s %2$s. Please top up to avoid service interruption.', 'vms-plugin' ),
					$balance['balance'],
					$balance['currency'] ?? ''
				)
			);
		}
	}

	/**
	 * Cron: check delivery status of pending messages.
	 *
	 * @return void
	 */
	public function cron_check_pending(): void {
		global $wpdb;

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SMS_LOGS );

		// Get messages stuck in 'sent' or 'queued' for more than 10 minutes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, message_id FROM `{$table}`
				 WHERE status IN ('sent', 'queued')
				 AND message_id IS NOT NULL
				 AND created_at < %s
				 LIMIT 100",
				gmdate( 'Y-m-d H:i:s', strtotime( '-10 minutes' ) )
			),
			ARRAY_A
		);

		if ( empty( $pending ) ) {
			return;
		}

		try {
			$provider = $this->get_provider();

			foreach ( $pending as $row ) {
				$status = $provider->check_status( $row['message_id'] );
				if ( $status ) {
					self::update_delivery_status( $row['message_id'], $status );
				}
			}
		} catch ( \Exception $e ) {
			self::log( 'Cron delivery check failed: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * AJAX: send a test SMS.
	 *
	 * @return void
	 */
	public function ajax_test_sms(): void {
		self::verify_ajax( 'vms_settings_nonce', VMS_Config::CAP_MANAGE_SETTINGS );

		$phone = self::get_post_text( 'phone' );
		if ( empty( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Phone number required.', 'vms-plugin' ) ) );
		}

		$club_name = VMS_Config::get_option( 'club_name', 'VMS' );
		$result    = self::send(
			$phone,
			sprintf(
				/* translators: %s: club name */
				__( '%s: This is a test message. Your SMS integration is working correctly.', 'vms-plugin' ),
				$club_name
			),
			get_current_user_id(),
			'admin'
		);

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: get current balance.
	 *
	 * @return void
	 */
	public function ajax_get_balance(): void {
		self::verify_ajax( 'vms_settings_nonce', VMS_Config::CAP_MANAGE_SETTINGS );

		// Force refresh.
		VMS_Cache::forget( 'sms:balance' );
		wp_send_json_success( self::get_balance() );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Normalize a phone number to E.164 format.
	 *
	 * Kenya-focused: converts 07xx/01xx → +2547xx/+2541xx.
	 * Accepts already-prefixed international numbers.
	 *
	 * @param string $phone Raw phone input.
	 * @return string|null E.164 number or null if invalid.
	 */
	public static function normalize_phone( string $phone ): ?string {
		// Strip everything except digits and leading +.
		$phone = preg_replace( '/[^\d+]/', '', $phone );

		if ( empty( $phone ) ) {
			return null;
		}

		// Already E.164.
		if ( str_starts_with( $phone, '+' ) ) {
			$digits = substr( $phone, 1 );
			return ( strlen( $digits ) >= 10 && strlen( $digits ) <= 15 ) ? $phone : null;
		}

		// Kenya local format: 07XXXXXXXX or 01XXXXXXXX (10 digits).
		if ( preg_match( '/^0([17]\d{8})$/', $phone, $m ) ) {
			return '+254' . $m[1];
		}

		// Already has 254 prefix without +.
		if ( preg_match( '/^254([17]\d{8})$/', $phone ) ) {
			return '+' . $phone;
		}

		// 9-digit Kenyan without leading 0.
		if ( preg_match( '/^([17]\d{8})$/', $phone ) ) {
			return '+254' . $phone;
		}

		return null;
	}

	/**
	 * Write an SMS log entry.
	 *
	 * @param int|null    $user_id        WP user ID.
	 * @param string|null $recipient_role Role label.
	 * @param string      $provider       Provider key.
	 * @param string      $phone          Phone number.
	 * @param string      $message        Message body.
	 * @param string|null $message_id     Provider message ID.
	 * @param string      $status         Status constant.
	 * @param float       $cost           Cost.
	 * @param string|null $error          Error message.
	 * @param array|null  $response       Raw provider response.
	 * @return void
	 */
	private static function log_sms(
		?int $user_id,
		?string $recipient_role,
		string $provider,
		string $phone,
		string $message,
		?string $message_id,
		string $status,
		float $cost,
		?string $error = null,
		?array $response = null
	): void {
		global $wpdb;

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SMS_LOGS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'        => $user_id,
				'recipient_role' => $recipient_role,
				'provider'       => $provider,
				'phone_number'   => $phone,
				'message'        => $message,
				'message_id'     => $message_id,
				'status'         => $status,
				'cost'           => $cost,
				'error_message'  => $error,
				'response_data'  => $response ? wp_json_encode( $response ) : null,
				'sent_at'        => in_array( $status, array( VMS_Config::SMS_SENT, VMS_Config::SMS_DELIVERED ), true ) ? current_time( 'mysql' ) : null,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
		);
	}
}
