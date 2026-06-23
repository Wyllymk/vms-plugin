<?php
/**
 * Visit limits calculation engine.
 *
 * Pure-logic module for enforcing guest visit quotas and host daily
 * limits. Kept stateless & side-effect-free to make it easy to unit
 * test — all DB queries are wrapped and heavily cached.
 *
 * Rules:
 *   - Guest: max N visits/month, M visits/year (configurable)
 *   - Host:  max K guests/day (configurable)
 *   - When limits exceeded: visit → unapproved, guest → suspended
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Visit Limits.
 */
final class VMS_Visit_Limits {

	/**
	 * Count approved visits for a guest in a given month.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param string $date     Any date within the target month (Y-m-d).
	 * @return int
	 */
	public static function count_guest_visits_month( int $guest_id, string $date ): int {
		$month_key = gmdate( 'Y-m', strtotime( $date ) );

		return (int) VMS_Cache::cached(
			"visits:guest_{$guest_id}_month_{$month_key}",
			static function () use ( $guest_id, $date ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				$start = gmdate( 'Y-m-01', strtotime( $date ) );
				$end   = gmdate( 'Y-m-t', strtotime( $date ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$table}`
						 WHERE guest_id = %d
						 AND visit_date BETWEEN %s AND %s
						 AND status IN ('approved', 'completed')",
						$guest_id,
						$start,
						$end
					)
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Count approved visits for a guest in a given year.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param string $date     Any date within the target year.
	 * @return int
	 */
	public static function count_guest_visits_year( int $guest_id, string $date ): int {
		$year = gmdate( 'Y', strtotime( $date ) );

		return (int) VMS_Cache::cached(
			"visits:guest_{$guest_id}_year_{$year}",
			static function () use ( $guest_id, $year ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$table}`
						 WHERE guest_id = %d
						 AND YEAR(visit_date) = %d
						 AND status IN ('approved', 'completed')",
						$guest_id,
						$year
					)
				);
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Count approved guests for a host on a specific day.
	 *
	 * @param int    $host_id Host WP user ID.
	 * @param string $date    Visit date (Y-m-d).
	 * @return int
	 */
	public static function count_host_guests_day( int $host_id, string $date ): int {
		$date = gmdate( 'Y-m-d', strtotime( $date ) );

		return (int) VMS_Cache::cached(
			"visits:host_{$host_id}_day_{$date}",
			static function () use ( $host_id, $date ) {
				global $wpdb;
				$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$table}`
						 WHERE host_member_id = %d
						 AND visit_date = %s
						 AND status IN ('approved', 'completed')",
						$host_id,
						$date
					)
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Determine the appropriate status for a NEW visit before insertion.
	 *
	 * Returns both the visit status and whether the guest should be
	 * suspended as a result of this visit.
	 *
	 * @param int      $guest_id   Guest ID.
	 * @param int|null $host_id    Host user ID (null for courtesy).
	 * @param string   $visit_date Visit date.
	 * @return array{visit_status: string, suspend_guest: bool, reason: ?string}
	 */
	public static function calculate_new_visit_status( int $guest_id, ?int $host_id, string $visit_date ): array {
		$max_month = (int) VMS_Config::get_option( 'max_guest_visits_month', 4 );
		$max_year  = (int) VMS_Config::get_option( 'max_guest_visits_year', 24 );
		$max_host  = (int) VMS_Config::get_option( 'max_host_guests_day', 4 );

		// --- Guest yearly limit (most restrictive, check first) ---
		$year_count = self::count_guest_visits_year( $guest_id, $visit_date );
		if ( $year_count >= $max_year ) {
			return array(
				'visit_status'  => VMS_Config::VISIT_UNAPPROVED,
				'suspend_guest' => true,
				'reason'        => sprintf(
					/* translators: 1: count, 2: limit */
					__( 'Guest has reached yearly limit (%1$d / %2$d).', 'vms-plugin' ),
					$year_count,
					$max_year
				),
			);
		}

		// --- Guest monthly limit ---
		$month_count = self::count_guest_visits_month( $guest_id, $visit_date );
		if ( $month_count >= $max_month ) {
			return array(
				'visit_status'  => VMS_Config::VISIT_UNAPPROVED,
				'suspend_guest' => true,
				'reason'        => sprintf(
					/* translators: 1: count, 2: limit */
					__( 'Guest has reached monthly limit (%1$d / %2$d).', 'vms-plugin' ),
					$month_count,
					$max_month
				),
			);
		}

		// --- Host daily limit (only for hosted visits) ---
		if ( $host_id ) {
			$host_count = self::count_host_guests_day( $host_id, $visit_date );
			if ( $host_count >= $max_host ) {
				return array(
					'visit_status'  => VMS_Config::VISIT_UNAPPROVED,
					'suspend_guest' => false,
					'reason'        => sprintf(
						/* translators: 1: count, 2: limit */
						__( 'Host has reached daily limit (%1$d / %2$d).', 'vms-plugin' ),
						$host_count,
						$max_host
					),
				);
			}
		}

		return array(
			'visit_status'  => VMS_Config::VISIT_APPROVED,
			'suspend_guest' => false,
			'reason'        => null,
		);
	}

	/**
	 * Recalculate all pending/unapproved visits for a guest.
	 *
	 * Called after a visit is cancelled — freed-up slots may allow
	 * previously-unapproved visits to now be approved.
	 *
	 * @param int $guest_id Guest ID.
	 * @return array<int, array{visit_id: int, old_status: string, new_status: string}>
	 */
	public static function recalculate_guest_visits( int $guest_id ): array {
		global $wpdb;

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		// Bust the count caches so we get fresh numbers.
		VMS_Cache::bust( 'visits' );

		// Get all future unapproved visits, oldest first (FIFO approval).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, host_member_id, visit_date, status FROM `{$table}`
				 WHERE guest_id = %d
				 AND status = %s
				 AND visit_date >= %s
				 ORDER BY visit_date ASC, created_at ASC",
				$guest_id,
				VMS_Config::VISIT_UNAPPROVED,
				current_time( 'Y-m-d' )
			),
			ARRAY_A
		);

		$changes = array();

		foreach ( $pending as $visit ) {
			$calc = self::calculate_new_visit_status(
				$guest_id,
				$visit['host_member_id'] ? (int) $visit['host_member_id'] : null,
				$visit['visit_date']
			);

			if ( VMS_Config::VISIT_APPROVED === $calc['visit_status'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$table,
					array( 'status' => VMS_Config::VISIT_APPROVED ),
					array( 'id' => (int) $visit['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				$changes[] = array(
					'visit_id'   => (int) $visit['id'],
					'old_status' => $visit['status'],
					'new_status' => VMS_Config::VISIT_APPROVED,
				);

				// Re-bust so next iteration sees this approval counted.
				VMS_Cache::bust( 'visits' );
			}
		}

		return $changes;
	}

	/**
	 * Recalculate all unapproved visits for a host on a specific day.
	 *
	 * Called when a host's guest cancels — the freed slot may allow
	 * another pending guest to be approved.
	 *
	 * @param int    $host_id Host user ID.
	 * @param string $date    Visit date.
	 * @return array<int, array>
	 */
	public static function recalculate_host_day( int $host_id, string $date ): array {
		global $wpdb;

		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$date  = gmdate( 'Y-m-d', strtotime( $date ) );

		VMS_Cache::bust( 'visits' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, guest_id, visit_date, status FROM `{$table}`
				 WHERE host_member_id = %d
				 AND visit_date = %s
				 AND status = %s
				 ORDER BY created_at ASC",
				$host_id,
				$date,
				VMS_Config::VISIT_UNAPPROVED
			),
			ARRAY_A
		);

		$changes = array();

		foreach ( $pending as $visit ) {
			$calc = self::calculate_new_visit_status( (int) $visit['guest_id'], $host_id, $date );

			if ( VMS_Config::VISIT_APPROVED === $calc['visit_status'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$table,
					array( 'status' => VMS_Config::VISIT_APPROVED ),
					array( 'id' => (int) $visit['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				$changes[] = array(
					'visit_id'   => (int) $visit['id'],
					'guest_id'   => (int) $visit['guest_id'],
					'old_status' => $visit['status'],
					'new_status' => VMS_Config::VISIT_APPROVED,
				);

				VMS_Cache::bust( 'visits' );
			} else {
				// Host still at limit — no point checking further.
				break;
			}
		}

		return $changes;
	}

	/**
	 * Check if a guest should be un-suspended.
	 *
	 * Called at month/year boundaries — if the guest was suspended
	 * due to limits and those limits have now reset, reactivate them.
	 *
	 * @param int $guest_id Guest ID.
	 * @return bool True if guest should be reactivated.
	 */
	public static function should_reactivate_guest( int $guest_id ): bool {
		$max_month = (int) VMS_Config::get_option( 'max_guest_visits_month', 4 );
		$max_year  = (int) VMS_Config::get_option( 'max_guest_visits_year', 24 );
		$today     = current_time( 'Y-m-d' );

		$month_count = self::count_guest_visits_month( $guest_id, $today );
		$year_count  = self::count_guest_visits_year( $guest_id, $today );

		return $month_count < $max_month && $year_count < $max_year;
	}

	/**
	 * Get usage summary for a guest (for dashboard display).
	 *
	 * @param int $guest_id Guest ID.
	 * @return array{month_used: int, month_limit: int, year_used: int, year_limit: int}
	 */
	public static function get_guest_usage( int $guest_id ): array {
		$today = current_time( 'Y-m-d' );

		return array(
			'month_used'  => self::count_guest_visits_month( $guest_id, $today ),
			'month_limit' => (int) VMS_Config::get_option( 'max_guest_visits_month', 4 ),
			'year_used'   => self::count_guest_visits_year( $guest_id, $today ),
			'year_limit'  => (int) VMS_Config::get_option( 'max_guest_visits_year', 24 ),
		);
	}
}
