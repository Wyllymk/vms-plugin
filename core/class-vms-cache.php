<?php
/**
 * Simple cache helpers — transients with explicit group-prefix keys.
 *
 * Key format stored in wp_options: _transient_vms_{group}_{specifics}
 * e.g. vms_guests_phone_abc123, vms_visits_today_all
 *
 * This means bust( 'guests' ) can delete _transient_vms_guests_* with a
 * single SQL LIKE and actually hit the right rows — which the old md5 scheme
 * made impossible because the group prefix was obfuscated.
 *
 * No version counters, no recursion risk, no debug logging.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Cache helper.
 */
class VMS_Cache extends Singleton {

	// TTLs.
	const TTL_SHORT  = 300;   // 5 min  — guest/visit lists, today's data.
	const TTL_MEDIUM = 1800;  // 30 min — reports, stats.
	const TTL_LONG   = 3600;  // 1 hr   — branding, plugin settings.

	/**
	 * Convert a logical cache key to a safe transient name.
	 *
	 * Replaces ':' and '/' separators with '_' so the group prefix survives
	 * intact and bust() can LIKE-delete the right rows.
	 *
	 * @param string $key Logical key (e.g. "guests:phone_abc123").
	 * @return string Transient key (e.g. "vms_guests_phone_abc123").
	 */
	private static function key( string $key ): string {
		return 'vms_' . str_replace( array( ':', '/' ), '_', $key );
	}

	/**
	 * Get or compute a cached value.
	 *
	 * The callback may return `false` as a "not found" sentinel — that value
	 * is NOT cached, so the next call will re-run the callback. Return `null`
	 * to also skip caching (e.g. on DB error).
	 *
	 * @param string   $key      Logical cache key.
	 * @param callable $callback Produces the value on cache miss.
	 * @param int      $ttl      Seconds until expiry.
	 * @return mixed Cached or freshly-computed value.
	 */
	public static function cached( string $key, callable $callback, int $ttl = self::TTL_SHORT ) {
		$transient_key = self::key( $key );

		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();

		// Cache only real results — skip null/false (not-found sentinels).
		if ( null !== $value && false !== $value ) {
			set_transient( $transient_key, $value, $ttl );
		}

		return $value;
	}

	/**
	 * Delete one specific cache entry.
	 *
	 * Use the same logical key you passed to cached().
	 *
	 * @param string $key Logical cache key.
	 * @return void
	 */
	public static function forget( string $key ): void {
		delete_transient( self::key( $key ) );
	}

	/**
	 * Delete all cache entries whose key starts with vms_{group}_.
	 *
	 * Call this on writes that affect a whole group — e.g. after creating
	 * a guest you bust('guests') to clear all paginated list pages and
	 * all phone/id-number lookup caches at once.
	 *
	 * This works because the key scheme preserves the group prefix in plain
	 * text (no md5), so the LIKE pattern reliably matches.
	 *
	 * @param string $group Group prefix (e.g. 'guests', 'visits', 'employees').
	 * @return void
	 */
	public static function bust( string $group ): void {
		global $wpdb;

		// Guard against recursive calls (e.g. bust triggered by a hook that
		// itself calls bust during cleanup).
		static $busting = array();
		if ( ! empty( $busting[ $group ] ) ) {
			return;
		}
		$busting[ $group ] = true;

		$prefix = 'vms_' . $group . '_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);

		unset( $busting[ $group ] );
	}
}