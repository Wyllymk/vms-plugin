<?php
/**
 * Unified notification dispatcher.
 *
 * Centralizes all outgoing SMS & email communication. Templates are
 * filterable so themes can override messaging. Respects user opt-in
 * preferences for both channels.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications manager.
 */
final class VMS_Notifications extends Singleton {

	/**
	 * Pending notifications queue (deferred until shutdown).
	 *
	 * Each entry: [ 'method' => string, 'args' => array ]
	 *
	 * @var array<int, array{method: string, args: array}>
	 */
	private static array $queue = array();

	/**
	 * Whether the shutdown flush hook has been registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Customize wp_mail from headers.
		add_filter( 'wp_mail_from', array( $this, 'filter_mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ) );
	}

	// -------------------------------------------------------------------------
	// Async dispatch helpers
	// -------------------------------------------------------------------------

	/**
	 * Queue a notification method to run after the response is sent.
	 *
	 * Registers a single `shutdown` hook the first time a notification is
	 * queued so that all queued work happens in one batch after WordPress
	 * has already flushed the HTTP response to the client.
	 *
	 * @param string $method Static method name on this class.
	 * @param array  $args   Arguments to pass to the method.
	 * @return void
	 */
	private static function enqueue( string $method, array $args ): void {
		self::$queue[] = array(
			'method' => $method,
			'args'   => $args,
		);

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 999 );
		}
	}

	/**
	 * Send all queued notifications.
	 *
	 * Called on the `shutdown` action — after WordPress has sent the HTTP
	 * response — so SMS/email API latency is invisible to the end user.
	 *
	 * @return void
	 */
	public static function flush_queue(): void {
		if ( empty( self::$queue ) ) {
			return;
		}

		// Forcefully close the HTTP connection so the browser stops waiting.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( ! in_array( PHP_SAPI, array( 'cli', 'phpdbg' ), true ) ) {
			// Fallback for non-FPM environments (like Apache mod_php).
			if ( function_exists( 'session_write_close' ) && session_status() === PHP_SESSION_ACTIVE ) {
				session_write_close();
			}
			$level = ob_get_level();
			for ( $i = 0; $i < $level; $i++ ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@ob_end_flush();
			}
			flush();
		}

		// Extend the time limit: notifications may need extra seconds for
		// external HTTP calls, but the browser has already moved on.
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 120 );
		}

		$pending      = self::$queue;
		self::$queue  = array(); // clear before iterating so re-entrant calls don't loop.

		foreach ( $pending as $item ) {
			try {
				call_user_func_array( array( __CLASS__, $item['method'] ), $item['args'] );
			} catch ( \Throwable $e ) {
				// Log but never crash — a notification failure must not affect data.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[VMS][Notifications] Queue flush error in ' . $item['method'] . ': ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Filter wp_mail from address.
	 *
	 * @param string $from Default from address.
	 * @return string
	 */
	public function filter_mail_from( string $from ): string {
		$custom = VMS_Config::get_option( 'email_from_address', '' );
		return $custom ? sanitize_email( $custom ) : $from;
	}

	/**
	 * Filter wp_mail from name.
	 *
	 * @param string $name Default from name.
	 * @return string
	 */
	public function filter_mail_from_name( string $name ): string {
		$custom = VMS_Config::get_option( 'email_from_name', '' );
		return $custom ? sanitize_text_field( $custom ) : VMS_Config::get_option( 'club_name', $name );
	}

	// ---------------------------------------------------------------------
	// Guest Lifecycle Notifications
	// ---------------------------------------------------------------------

	/**
	 * Notify guest & host when a visit is registered.
	 *
	 * @param array $guest Guest record.
	 * @param array $visit Visit record.
	 * @param array $host  Host data (wp user info).
	 * @return void
	 */
	public static function visit_registered( array $guest, array $visit, array $host = array() ): void {
		$club = VMS_Config::get_option( 'club_name' );
		$date = wp_date( get_option( 'date_format' ), strtotime( $visit['visit_date'] ) );

		// --- Guest notification ---
		$guest_msg = sprintf(
			/* translators: 1: club name, 2: guest name, 3: visit date, 4: status */
			__( '%1$s: Dear %2$s, your visit on %3$s has been %4$s. Please carry a valid ID.', 'vms-plugin' ),
			$club,
			$guest['first_name'],
			$date,
			VMS_Config::VISIT_APPROVED === $visit['status'] ? __( 'approved', 'vms-plugin' ) : __( 'registered pending approval', 'vms-plugin' )
		);

		self::send_to_person( $guest, $guest_msg, 'guest', __( 'Visit Registered', 'vms-plugin' ) );

		// --- Host notification ---
		if ( ! empty( $host['phone_number'] ) || ! empty( $host['email'] ) ) {
			$host_msg = sprintf(
				/* translators: 1: club name, 2: guest full name, 3: visit date */
				__( '%1$s: %2$s has been registered as your guest for %3$s.', 'vms-plugin' ),
				$club,
				$guest['first_name'] . ' ' . $guest['last_name'],
				$date
			);

			self::send_to_person( $host, $host_msg, 'host', __( 'Guest Registered', 'vms-plugin' ) );
		}
	}

	/**
	 * Notify guest when their visit status changes.
	 *
	 * @param array  $guest      Guest record.
	 * @param array  $visit      Visit record.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public static function visit_status_changed( array $guest, array $visit, string $old_status, string $new_status ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		$club = VMS_Config::get_option( 'club_name' );
		$date = wp_date( get_option( 'date_format' ), strtotime( $visit['visit_date'] ) );

		$status_messages = array(
			VMS_Config::VISIT_APPROVED   => __( 'has been approved. Please carry a valid ID when you arrive.', 'vms-plugin' ),
			VMS_Config::VISIT_UNAPPROVED => __( 'is pending approval due to capacity limits. You will be notified once approved.', 'vms-plugin' ),
			VMS_Config::VISIT_CANCELLED  => __( 'has been cancelled. Please contact your host for details.', 'vms-plugin' ),
			VMS_Config::VISIT_SUSPENDED  => __( 'has been suspended. Contact reception for assistance.', 'vms-plugin' ),
		);

		if ( ! isset( $status_messages[ $new_status ] ) ) {
			return;
		}

		$msg = sprintf(
			/* translators: 1: club name, 2: guest name, 3: visit date, 4: status detail */
			__( '%1$s: Dear %2$s, your visit on %3$s %4$s', 'vms-plugin' ),
			$club,
			$guest['first_name'],
			$date,
			$status_messages[ $new_status ]
		);

		self::send_to_person( $guest, $msg, 'guest', __( 'Visit Status Update', 'vms-plugin' ) );
	}

	/**
	 * Notify guest when their account status changes.
	 *
	 * @param array  $guest      Guest record.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public static function guest_status_changed( array $guest, string $old_status, string $new_status ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		$club = VMS_Config::get_option( 'club_name' );

		$messages = array(
			VMS_Config::STATUS_SUSPENDED => __( 'your guest privileges have been temporarily suspended due to visit limits. Contact reception for assistance.', 'vms-plugin' ),
			VMS_Config::STATUS_BANNED    => __( 'your guest privileges have been permanently revoked. Please contact management.', 'vms-plugin' ),
			VMS_Config::STATUS_ACTIVE    => __( 'your guest privileges have been restored. You may now make new visit requests.', 'vms-plugin' ),
		);

		if ( ! isset( $messages[ $new_status ] ) ) {
			return;
		}

		// Don't notify about returning to active from active.
		if ( VMS_Config::STATUS_ACTIVE === $new_status && VMS_Config::STATUS_ACTIVE === $old_status ) {
			return;
		}

		$msg = sprintf(
			/* translators: 1: club name, 2: guest name, 3: status detail */
			__( '%1$s: Dear %2$s, %3$s', 'vms-plugin' ),
			$club,
			$guest['first_name'],
			$messages[ $new_status ]
		);

		self::send_to_person( $guest, $msg, 'guest', __( 'Account Status Update', 'vms-plugin' ) );
	}

	/**
	 * Notify guest & host on sign-in.
	 *
	 * @param array $guest Guest record.
	 * @param array $visit Visit record.
	 * @return void
	 */
	public static function guest_signed_in( array $guest, array $visit ): void {
		$club = VMS_Config::get_option( 'club_name' );
		$time = wp_date( get_option( 'time_format' ), strtotime( $visit['sign_in_time'] ) );

		$msg = sprintf(
			/* translators: 1: club name, 2: guest name, 3: time */
			__( '%1$s: Welcome %2$s! You signed in at %3$s. Enjoy your visit.', 'vms-plugin' ),
			$club,
			$guest['first_name'],
			$time
		);

		self::send_to_person( $guest, $msg, 'guest', __( 'Signed In', 'vms-plugin' ) );

		// Notify host too.
		if ( ! empty( $visit['host_member_id'] ) ) {
			$host = self::get_host_contact( (int) $visit['host_member_id'] );
			if ( $host ) {
				$host_msg = sprintf(
					/* translators: 1: club name, 2: guest name, 3: time */
					__( '%1$s: Your guest %2$s has signed in at %3$s.', 'vms-plugin' ),
					$club,
					$guest['first_name'] . ' ' . $guest['last_name'],
					$time
				);
				self::send_to_person( $host, $host_msg, 'host', __( 'Guest Arrived', 'vms-plugin' ) );
			}
		}
	}

	/**
	 * Notify guest on sign-out with duration.
	 *
	 * @param array $guest Guest record.
	 * @param array $visit Visit record.
	 * @return void
	 */
	public static function guest_signed_out( array $guest, array $visit ): void {
		$club = VMS_Config::get_option( 'club_name' );

		$duration = '';
		if ( ! empty( $visit['sign_in_time'] ) && ! empty( $visit['sign_out_time'] ) ) {
			$diff     = strtotime( $visit['sign_out_time'] ) - strtotime( $visit['sign_in_time'] );
			$duration = sprintf(
				/* translators: %s: formatted duration */
				__( ' Duration: %s.', 'vms-plugin' ),
				human_time_diff( 0, $diff )
			);
		}

		$msg = sprintf(
			/* translators: 1: club name, 2: guest name, 3: duration sentence */
			__( '%1$s: Thank you for visiting, %2$s!%3$s We hope to see you again.', 'vms-plugin' ),
			$club,
			$guest['first_name'],
			$duration
		);

		self::send_to_person( $guest, $msg, 'guest', __( 'Signed Out', 'vms-plugin' ) );
	}

	/**
	 * Notify host that their daily guest limit was hit.
	 *
	 * @param int    $host_id          WP user ID of host.
	 * @param string $visit_date       Visit date.
	 * @param int    $unapproved_count Number of pending guests.
	 * @return void
	 */
	public static function host_limit_reached( int $host_id, string $visit_date, int $unapproved_count ): void {
		$host = self::get_host_contact( $host_id );
		if ( ! $host ) {
			return;
		}

		$club  = VMS_Config::get_option( 'club_name' );
		$limit = (int) VMS_Config::get_option( 'max_host_guests_day', 4 );
		$date  = wp_date( get_option( 'date_format' ), strtotime( $visit_date ) );

		$msg = sprintf(
			/* translators: 1: club, 2: name, 3: limit, 4: date, 5: count */
			__( '%1$s: Dear %2$s, you have reached your daily guest limit (%3$d) for %4$s. %5$d guest(s) are pending and will be notified when slots open.', 'vms-plugin' ),
			$club,
			$host['first_name'],
			$limit,
			$date,
			$unapproved_count
		);

		self::send_to_person( $host, $msg, 'host', __( 'Daily Guest Limit Reached', 'vms-plugin' ) );
	}

	/**
	 * Notify site administrator.
	 *
	 * @param string $subject Email subject.
	 * @param string $message Message body.
	 * @return bool
	 */
	public static function notify_admin( string $subject, string $message ): bool {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return false;
		}

		return self::send_email(
			$admin_email,
			'[' . VMS_Config::get_option( 'club_name' ) . '] ' . $subject,
			$message
		);
	}

	// ---------------------------------------------------------------------
	// Channel Dispatch
	// ---------------------------------------------------------------------

	/**
	 * Queue a notification for a person to be sent after the HTTP response.
	 *
	 * Callers (visit_registered, guest_status_changed, etc.) use this so that
	 * AJAX handlers return immediately — SMS API calls and wp_mail() happen
	 * asynchronously on the WordPress shutdown hook instead of blocking the
	 * user's browser for minutes.
	 *
	 * @param array  $person        Person data (phone_number, email, receive_messages, receive_emails).
	 * @param string $message       Plain-text message body.
	 * @param string $role          Recipient role label.
	 * @param string $email_subject Email subject line.
	 * @return void
	 */
	private static function send_to_person( array $person, string $message, string $role, string $email_subject ): void {
		// Apply filters synchronously so filter callbacks run in the request context.
		/**
		 * Filter the notification message before dispatch.
		 *
		 * @since 2.0.0
		 * @param string $message Message body.
		 * @param array  $person  Recipient data.
		 * @param string $role    Recipient role.
		 */
		$message = apply_filters( 'vms_notification_message', $message, $person, $role );

		// Enqueue the actual network-blocking work for after the response.
		self::enqueue( '_dispatch_to_person', array( $person, $message, $role, $email_subject ) );
	}

	/**
	 * Actually dispatch a notification to a person (called from the shutdown queue).
	 *
	 * This is separated from send_to_person() so the slow I/O (SMTP, SMS API)
	 * runs after the browser has already received its AJAX response.
	 *
	 * @param array  $person        Person data.
	 * @param string $message       Message body.
	 * @param string $role          Recipient role label.
	 * @param string $email_subject Email subject line.
	 * @return void
	 */
	private static function _dispatch_to_person( array $person, string $message, string $role, string $email_subject ): void {
		// SMS channel.
		$wants_sms = ! empty( $person['receive_messages'] );
		if ( $wants_sms && ! empty( $person['phone_number'] ) ) {
			VMS_SMS_Gateway::send(
				$person['phone_number'],
				$message,
				$person['user_id'] ?? null,
				$role
			);
		}

		// Email channel.
		$wants_email = ! empty( $person['receive_emails'] );
		if ( $wants_email && ! empty( $person['email'] ) ) {
			self::send_email( $person['email'], $email_subject, $message );
		}
	}

	/**
	 * Send an HTML email via wp_mail().
	 *
	 * @param string $to      Recipient email.
	 * @param string $subject Subject.
	 * @param string $body    Plain-text body (will be wrapped in HTML template).
	 * @return bool
	 */
	public static function send_email( string $to, string $subject, string $body ): bool {
		if ( ! VMS_Config::get_option( 'enable_email_notifications', true ) ) {
			return false;
		}

		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$html = self::wrap_email_template( $subject, $body );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $to, $subject, $html, $headers );

		VMS_Audit_Trail::log(
			$sent ? 'email_sent' : 'email_failed',
			VMS_Audit_Trail::CAT_SYSTEM,
			null,
			null,
			null,
			array(
				'to'      => $to,
				'subject' => $subject,
			)
		);

		return $sent;
	}

	/**
	 * Wrap plain-text body in the branded HTML email template.
	 *
	 * @param string $subject Email subject (shown in header).
	 * @param string $body    Plain-text body.
	 * @return string HTML.
	 */
	private static function wrap_email_template( string $subject, string $body ): string {
		$club_name = VMS_Config::get_option( 'club_name' );
		$primary   = VMS_Config::get_option( 'primary_color', '#0ea5e9' );
		$logo_id   = (int) VMS_Config::get_option( 'club_logo_id', 0 );
		$logo_url  = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		$logo_html = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $club_name ) . '" style="max-height:60px;margin-bottom:16px;">'
			: '';

		$body_html = nl2br( esc_html( $body ) );

		return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 20px;">
<tr><td align="center">
<table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.05);">
<tr><td style="background:' . esc_attr( $primary ) . ';padding:32px;text-align:center;">
' . $logo_html . '
<h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:600;">' . esc_html( $subject ) . '</h1>
</td></tr>
<tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.6;">
' . $body_html . '
</td></tr>
<tr><td style="padding:20px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;color:#9ca3af;font-size:12px;">
&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $club_name ) . '. ' . esc_html__( 'This is an automated message.', 'vms-plugin' ) . '
</td></tr>
</table>
</td></tr>
</table>
</body></html>';
	}

	/**
	 * Get a host's contact details from their WP user record.
	 *
	 * @param int $user_id WP user ID.
	 * @return array|null
	 */
	private static function get_host_contact( int $user_id ): ?array {
		return VMS_Cache::cached(
			"members:host_contact_{$user_id}",
			static function () use ( $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					return null;
				}

				return array(
					'user_id'          => $user_id,
					'first_name'       => $user->first_name ?: $user->display_name,
					'email'            => $user->user_email,
					'phone_number'     => get_user_meta( $user_id, 'vms_phone', true ),
					'receive_messages' => (bool) get_user_meta( $user_id, 'vms_receive_sms', true ),
					'receive_emails'   => (bool) get_user_meta( $user_id, 'vms_receive_email', true ),
				);
			},
			VMS_Config::CACHE_TTL_LONG
		);
	}
}
