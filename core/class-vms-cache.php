<?php
/**
 * Caching & transients layer.
 *
 * Wraps WP object cache + transients with group-aware invalidation.
 * Prefers object cache (Redis/Memcached) when available, falls back
 * to transients otherwise. Supports pattern-based cache busting so
 * a single write can invalidate all related reads.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Cache manager.
 */
final class VMS_Cache extends Singleton {

	/**
	 * Whether a persistent object cache is available.
	 *
	 * @var bool
	 */
	private bool $has_object_cache = false;

	/**
	 * In-request memory cache (avoids repeat lookups within a single request).
	 *
	 * @var array<string, mixed>
	 */
	private array $runtime = array();

	/**
	 * Maximum size in bytes for data to be cached (5MB default).
	 *
	 * @var int
	 */
	private const MAX_CACHE_SIZE = 5242880;

	/**
	 * Enable debug logging.
	 *
	 * @var bool
	 */
	private const DEBUG = true;

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->log( 'VMS_Cache::init() - Starting initialization' );

		// wp_using_ext_object_cache() can return null when called before the
		// object-cache.php drop-in is loaded (plugins_loaded priority 5 is
		// early). On some hosting stacks the function itself may not exist
		// yet. Store into a local first, then coerce explicitly — directly
		// casting an expression that evaluates to null into a typed bool
		// property triggers a TypeError on PHP 8.1+ strict property checks
		// in certain opcache configurations.
		$using_ext = function_exists( 'wp_using_ext_object_cache' )
			? wp_using_ext_object_cache()
			: null;

		$this->has_object_cache = true === $using_ext;
		$this->log( 'VMS_Cache::init() - Object cache available: ' . ($this->has_object_cache ? 'true' : 'false') );

		// Register our cache group so it can be flushed independently.
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( array( VMS_Config::CACHE_GROUP ) );
			$this->log( 'VMS_Cache::init() - Registered cache group: ' . VMS_Config::CACHE_GROUP );
		} else {
			$this->log( 'VMS_Cache::init() - wp_cache_add_global_groups not available' );
		}

		// Re-evaluate once WordPress is fully loaded so Redis/Memcached
		// drop-ins that register late are detected for subsequent requests.
		add_action( 'wp_loaded', function (): void {
			$this->log( 'VMS_Cache::init() - wp_loaded action triggered' );
			if ( function_exists( 'wp_using_ext_object_cache' ) ) {
				$previous = $this->has_object_cache;
				$this->has_object_cache = true === wp_using_ext_object_cache();
				if ($previous !== $this->has_object_cache) {
					$this->log( 'VMS_Cache::init() - Object cache status changed from ' . ($previous ? 'true' : 'false') . ' to ' . ($this->has_object_cache ? 'true' : 'false') );
				}
			}
		} );

		$this->log( 'VMS_Cache::init() - Initialization complete' );
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false Value or false if not found.
	 */
	public function get( string $key ) {
		$this->log( 'VMS_Cache::get() - Called with key: ' . $key );
		$full_key = $this->build_key( $key );
		$this->log( 'VMS_Cache::get() - Full key: ' . $full_key );

		// L1: in-request memory.
		if ( array_key_exists( $full_key, $this->runtime ) ) {
			$this->log( 'VMS_Cache::get() - Cache HIT (runtime) for key: ' . $full_key );
			return $this->runtime[ $full_key ];
		}
		$this->log( 'VMS_Cache::get() - Cache MISS (runtime) for key: ' . $full_key );

		// L2: object cache / transient.
		if ( $this->has_object_cache ) {
			$this->log( 'VMS_Cache::get() - Attempting object cache lookup' );
			$found = false;
			$value = wp_cache_get( $full_key, VMS_Config::CACHE_GROUP, false, $found );
			if ( $found ) {
				$this->log( 'VMS_Cache::get() - Cache HIT (object cache) for key: ' . $full_key );
				$this->runtime[ $full_key ] = $value;
				return $value;
			}
			$this->log( 'VMS_Cache::get() - Cache MISS (object cache) for key: ' . $full_key );
		} else {
			$this->log( 'VMS_Cache::get() - Attempting transient lookup' );
			$value = get_transient( $full_key );
			if ( false !== $value ) {
				$this->log( 'VMS_Cache::get() - Cache HIT (transient) for key: ' . $full_key );
				$this->runtime[ $full_key ] = $value;
				return $value;
			}
			$this->log( 'VMS_Cache::get() - Cache MISS (transient) for key: ' . $full_key );
		}

		$this->log( 'VMS_Cache::get() - Cache MISS (all levels) for key: ' . $full_key );
		return false;
	}

	/**
	 * Set a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache (must not be false — use null instead).
	 * @param int    $ttl   Time-to-live in seconds. 0 = use default.
	 * @return bool
	 */
	public function set( string $key, $value, int $ttl = 0 ): bool {
		$this->log( 'VMS_Cache::set() - Called with key: ' . $key . ', TTL: ' . $ttl );
		
		// Log value type and approximate size
		$value_type = gettype($value);
		$serialized = maybe_serialize($value);
		$size = strlen($serialized);
		$this->log( 'VMS_Cache::set() - Value type: ' . $value_type . ', size: ' . $size . ' bytes' );

		$full_key = $this->build_key( $key );
		$ttl      = $ttl > 0 ? $ttl : VMS_Config::CACHE_TTL_MEDIUM;
		$this->log( 'VMS_Cache::set() - Full key: ' . $full_key . ', Effective TTL: ' . $ttl . 's' );

		// Check size limit
		if ($size > self::MAX_CACHE_SIZE) {
			$this->log( 'VMS_Cache::set() - WARNING: Value exceeds max cache size (' . $size . ' > ' . self::MAX_CACHE_SIZE . ')' );
		}

		$this->runtime[ $full_key ] = $value;
		$this->log( 'VMS_Cache::set() - Stored in runtime cache' );

		if ( $this->has_object_cache ) {
			$this->log( 'VMS_Cache::set() - Attempting object cache set' );
			$result = wp_cache_set( $full_key, $value, VMS_Config::CACHE_GROUP, $ttl );
			$this->log( 'VMS_Cache::set() - Object cache set result: ' . ($result ? 'SUCCESS' : 'FAILURE') );
			return $result;
		}

		$this->log( 'VMS_Cache::set() - Attempting transient set' );
		$result = set_transient( $full_key, $value, $ttl );
		$this->log( 'VMS_Cache::set() - Transient set result: ' . ($result ? 'SUCCESS' : 'FAILURE') );
		return $result;
	}

	/**
	 * Delete a single cache entry.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		$this->log( 'VMS_Cache::delete() - Called with key: ' . $key );
		$full_key = $this->build_key( $key );
		$this->log( 'VMS_Cache::delete() - Full key: ' . $full_key );

		$runtime_exists = array_key_exists( $full_key, $this->runtime );
		unset( $this->runtime[ $full_key ] );
		$this->log( 'VMS_Cache::delete() - Runtime key ' . ($runtime_exists ? 'existed and was removed' : 'did not exist') );

		if ( $this->has_object_cache ) {
			$this->log( 'VMS_Cache::delete() - Attempting object cache delete' );
			$result = wp_cache_delete( $full_key, VMS_Config::CACHE_GROUP );
			$this->log( 'VMS_Cache::delete() - Object cache delete result: ' . ($result ? 'SUCCESS' : 'FAILURE') );
			return $result;
		}

		$this->log( 'VMS_Cache::delete() - Attempting transient delete' );
		$result = delete_transient( $full_key );
		$this->log( 'VMS_Cache::delete() - Transient delete result: ' . ($result ? 'SUCCESS' : 'FAILURE') );
		return $result;
	}

	/**
	 * Get-or-set pattern: fetch from cache, compute on miss.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Generator callback.
	 * @param int      $ttl      TTL seconds.
	 * @return mixed
	 */
	public function remember( string $key, callable $callback, int $ttl = 0 ) {
		$this->log( 'VMS_Cache::remember() - Called with key: ' . $key . ', TTL: ' . $ttl );
		$cached = $this->get( $key );
		if ( false !== $cached ) {
			$this->log( 'VMS_Cache::remember() - Returned cached value for key: ' . $key );
			return $cached;
		}

		$this->log( 'VMS_Cache::remember() - Cache miss, executing callback for key: ' . $key );
		$start_time = microtime(true);
		$value = $callback();
		$execution_time = microtime(true) - $start_time;
		$this->log( 'VMS_Cache::remember() - Callback executed in ' . round($execution_time, 4) . 's' );

		// Don't cache falsy "miss" results to avoid poisoning.
		if ( false !== $value ) {
			$this->log( 'VMS_Cache::remember() - Caching computed value for key: ' . $key );
			$this->set( $key, $value, $ttl );
		} else {
			$this->log( 'VMS_Cache::remember() - Callback returned false, not caching for key: ' . $key );
		}

		return $value;
	}

	/**
	 * Flush all cache entries matching a group prefix.
	 *
	 * Uses an incrementing version salt so old keys naturally expire
	 * without requiring enumeration (works on object cache too).
	 *
	 * Optimized to prevent memory exhaustion by:
	 * - Using autoload=false for version options (they don't need to be loaded on every page).
	 * - Limiting runtime cache clearing to prevent memory issues.
	 * - Adding error logging for debugging.
	 *
	 * @param string $group Group identifier (e.g. 'guests', 'visits').
	 * @return void
	 */
	public function flush_group( string $group ): void {
		$this->log( 'VMS_Cache::flush_group() - Starting flush for group: ' . $group );
		
		// Log current cache state
		$runtime_keys = array_keys($this->runtime);
		$group_keys = array_filter($runtime_keys, function($key) use ($group) {
			return strpos($key, 'vms_' . $group . '_') === 0;
		});
		$this->log( 'VMS_Cache::flush_group() - Current runtime has ' . count($runtime_keys) . ' keys, ' . count($group_keys) . ' belong to group: ' . $group );

		$version_key = 'vms_cache_v_' . $group;
		$current_version = (int) get_option( $version_key, 1 );
		$this->log( 'VMS_Cache::flush_group() - Current version: ' . $current_version . ' for group: ' . $group );

		// Check memory before potentially expensive operations.
		$memory_usage = memory_get_usage( true );
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_percent = round(($memory_usage / $memory_limit) * 100, 2);
		$this->log( 'VMS_Cache::flush_group() - Memory usage: ' . round($memory_usage / 1024 / 1024, 2) . 'MB / ' . round($memory_limit / 1024 / 1024, 2) . 'MB (' . $memory_percent . '%)' );

		if ( $memory_usage > ( $memory_limit * 0.85 ) ) {
			$this->log( 'VMS_Cache::flush_group() - WARNING: Memory limit approaching (' . $memory_percent . '%), aborting flush for group: ' . $group );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'VMS_Cache: Memory limit approaching in flush_group() for group: ' . $group );
			return;
		}

		$new_version = $version + 1;
		$this->log( 'VMS_Cache::flush_group() - Incrementing version from ' . $current_version . ' to ' . $new_version );
		
		// Use autoload=false for version options to prevent loading on every page.
		$updated = update_option( $version_key, $new_version, false );

		if ( ! $updated ) {
			$this->log( 'VMS_Cache::flush_group() - ERROR: Failed to update version option for group: ' . $group );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'VMS_Cache: Failed to update version option for group: ' . $group );
		} else {
			$this->log( 'VMS_Cache::flush_group() - Version updated successfully for group: ' . $group );
		}

		// Also clear runtime cache for this group.
		$this->log( 'VMS_Cache::flush_group() - Clearing runtime cache for group: ' . $group );
		$cleared = $this->clear_runtime_cache_for_group( $group );
		$this->log( 'VMS_Cache::flush_group() - Runtime cache cleared for ' . $cleared . ' keys in group: ' . $group );
		
		$this->log( 'VMS_Cache::flush_group() - Flush complete for group: ' . $group );
	}

	/**
	 * Flush the entire VMS cache.
	 *
	 * @return void
	 */
	public function flush_all(): void {
		$this->log( 'VMS_Cache::flush_all() - Starting full cache flush' );
		
		// Log current state
		$runtime_count = count($this->runtime);
		$this->log( 'VMS_Cache::flush_all() - Current runtime has ' . $runtime_count . ' keys' );

		// Check memory before flush.
		$memory_usage = memory_get_usage( true );
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_percent = round(($memory_usage / $memory_limit) * 100, 2);
		$this->log( 'VMS_Cache::flush_all() - Memory usage: ' . round($memory_usage / 1024 / 1024, 2) . 'MB / ' . round($memory_limit / 1024 / 1024, 2) . 'MB (' . $memory_percent . '%)' );

		if ( $memory_usage > ( $memory_limit * 0.85 ) ) {
			$this->log( 'VMS_Cache::flush_all() - WARNING: Memory limit approaching (' . $memory_percent . '%), aborting flush' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'VMS_Cache: Memory limit approaching in flush_all()' );
			return;
		}

		$this->runtime = array();
		$this->log( 'VMS_Cache::flush_all() - Runtime cache cleared' );

		if ( $this->has_object_cache ) {
			$this->log( 'VMS_Cache::flush_all() - Using object cache flush' );
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				$this->log( 'VMS_Cache::flush_all() - Using wp_cache_flush_group for group: ' . VMS_Config::CACHE_GROUP );
				wp_cache_flush_group( VMS_Config::CACHE_GROUP );
				$this->log( 'VMS_Cache::flush_all() - wp_cache_flush_group completed' );
			} else {
				$this->log( 'VMS_Cache::flush_all() - wp_cache_flush_group not available, using wp_cache_flush' );
				wp_cache_flush();
				$this->log( 'VMS_Cache::flush_all() - wp_cache_flush completed' );
			}
		} else {
			$this->log( 'VMS_Cache::flush_all() - Using transient flush via database' );
			// Delete all VMS transients.
			global $wpdb;
			$start_time = microtime(true);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					'_transient_vms_%',
					'_transient_timeout_vms_%'
				)
			);
			$execution_time = microtime(true) - $start_time;
			
			if ( ! empty( $wpdb->last_error ) ) {
				$this->log( 'VMS_Cache::flush_all() - ERROR: Database error: ' . $wpdb->last_error );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'VMS_Cache: Database error during flush_all: ' . $wpdb->last_error );
			} else {
				$this->log( 'VMS_Cache::flush_all() - Deleted ' . $result . ' transient entries in ' . round($execution_time, 4) . 's' );
			}
		}

		// Bump all group versions.
		$groups = array( 'guests', 'visits', 'stats', 'reports', 'settings', 'suppliers', 'accom', 'recip' );
		$this->log( 'VMS_Cache::flush_all() - Bumping versions for ' . count($groups) . ' groups: ' . implode(', ', $groups) );
		foreach ( $groups as $g ) {
			$this->flush_group( $g );
		}
		
		$this->log( 'VMS_Cache::flush_all() - Full cache flush complete' );
	}

	/**
	 * Clear runtime cache for a specific group with a limit to prevent memory issues.
	 *
	 * @param string $group Group identifier.
	 * @param int    $limit Maximum number of keys to process.
	 * @return int Number of keys cleared
	 */
	private function clear_runtime_cache_for_group( string $group, int $limit = 1000 ): int {
		$this->log( 'VMS_Cache::clear_runtime_cache_for_group() - Called for group: ' . $group . ', limit: ' . $limit );
		
		$prefix        = "vms_{$group}_";
		$prefix_length = strlen( $prefix );
		$processed     = 0;
		$cleared       = 0;

		// Log current runtime state
		$runtime_keys = array_keys($this->runtime);
		$this->log( 'VMS_Cache::clear_runtime_cache_for_group() - Runtime has ' . count($runtime_keys) . ' total keys' );
		
		$keys_to_clear = array_filter($runtime_keys, function($key) use ($prefix) {
			return strpos($key, $prefix) === 0;
		});
		$this->log( 'VMS_Cache::clear_runtime_cache_for_group() - Found ' . count($keys_to_clear) . ' keys to clear with prefix: ' . $prefix );

		// Use foreach with direct array access to avoid creating a copy of keys.
		foreach ( $this->runtime as $key => $value ) {
			if ( strpos($key, $prefix) === 0 ) {
				unset( $this->runtime[ $key ] );
				$cleared++;
				$processed++;

				if ( $processed >= $limit ) {
					$this->log( 'VMS_Cache::clear_runtime_cache_for_group() - Hit limit (' . $limit . ') for group: ' . $group );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( "VMS_Cache: Runtime cache clearing hit limit ({$limit}) for group: {$group}" );
					break;
				}
			}
		}
		
		$this->log( 'VMS_Cache::clear_runtime_cache_for_group() - Cleared ' . $cleared . ' keys from runtime for group: ' . $group );
		return $cleared;
	}

	/**
	 * Build a versioned cache key.
	 *
	 * Keys follow the format: vms_{group}_{v}_{suffix}
	 * The version component enables cheap group invalidation.
	 *
	 * @param string $key Raw key in format "group:suffix" or plain string.
	 * @return string
	 */
	private function build_key( string $key ): string {
		$this->log( 'VMS_Cache::build_key() - Called with raw key: ' . $key );
		
		// Extract group if key uses "group:suffix" format.
		if ( str_contains( $key, ':' ) ) {
			list( $group, $suffix ) = explode( ':', $key, 2 );
			$version = (int) get_option( 'vms_cache_v_' . $group, 1 );
			$built_key = "vms_{$group}_{$version}_{$suffix}";
			$this->log( 'VMS_Cache::build_key() - Parsed group: ' . $group . ', suffix: ' . $suffix . ', version: ' . $version . ', built key: ' . $built_key );
			return $built_key;
		}

		$built_key = 'vms_' . $key;
		$this->log( 'VMS_Cache::build_key() - No group found, using simple key: ' . $built_key );
		return $built_key;
	}

	/**
	 * Log message if debugging is enabled.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( ! self::DEBUG ) {
			return;
		}
		
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'unknown';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[VMS_Cache DEBUG] [' . $caller . '] ' . $message );
	}

	// ---------------------------------------------------------------------
	// Static Convenience Wrappers
	// ---------------------------------------------------------------------

	/**
	 * Static get shortcut.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function fetch( string $key ) {
		$instance = self::instance();
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::fetch() - Static wrapper called for key: ' . $key );
		}
		return $instance->get( $key );
	}

	/**
	 * Static set shortcut.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL.
	 * @return bool
	 */
	public static function store( string $key, $value, int $ttl = 0 ): bool {
		$instance = self::instance();
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::store() - Static wrapper called for key: ' . $key . ', TTL: ' . $ttl );
		}
		return $instance->set( $key, $value, $ttl );
	}

	/**
	 * Static delete shortcut.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function forget( string $key ): bool {
		$instance = self::instance();
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::forget() - Static wrapper called for key: ' . $key );
		}
		return $instance->delete( $key );
	}

	/**
	 * Static remember shortcut with size limiting.
	 *
	 * Limits the size of data being cached to prevent memory issues.
	 *
	 * @param string   $key      Key.
	 * @param callable $callback Generator.
	 * @param int      $ttl      TTL.
	 * @return mixed
	 */
	public static function cached( string $key, callable $callback, int $ttl = 0 ) {
		$instance = self::instance();
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Static wrapper called for key: ' . $key . ', TTL: ' . $ttl );
		}
		
		$cached = $instance->get( $key );

		if ( false !== $cached ) {
			if (self::DEBUG) {
				$instance->log( 'VMS_Cache::cached() - Returned cached value for key: ' . $key );
			}
			return $cached;
		}

		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Cache miss, executing callback for key: ' . $key );
		}
		$start_time = microtime(true);
		$value = $callback();
		$execution_time = microtime(true) - $start_time;
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Callback executed in ' . round($execution_time, 4) . 's for key: ' . $key );
		}

		// Don't cache falsy "miss" results to avoid poisoning.
		if ( false === $value ) {
			if (self::DEBUG) {
				$instance->log( 'VMS_Cache::cached() - Callback returned false, not caching for key: ' . $key );
			}
			return $value;
		}

		// Check size of data before caching to prevent memory exhaustion.
		$serialized_size = strlen( maybe_serialize( $value ) );
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Serialized size: ' . $serialized_size . ' bytes for key: ' . $key );
		}

		if ( $serialized_size > self::MAX_CACHE_SIZE ) {
			$instance->log( 'VMS_Cache::cached() - WARNING: Data too large to cache for key: ' . $key . ' (' . $serialized_size . ' bytes)' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "VMS_Cache: Data too large to cache for key {$key} ({$serialized_size} bytes)" );
			return $value;
		}

		// Check memory usage before caching.
		$memory_usage = memory_get_usage( true );
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_percent = round(($memory_usage / $memory_limit) * 100, 2);
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Current memory usage: ' . round($memory_usage / 1024 / 1024, 2) . 'MB (' . $memory_percent . '%)' );
		}

		if ( $memory_usage + $serialized_size > ( $memory_limit * 0.90 ) ) {
			$instance->log( 'VMS_Cache::cached() - WARNING: Not enough memory to cache key: ' . $key );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "VMS_Cache: Not enough memory to cache key {$key}" );
			return $value;
		}

		$instance->set( $key, $value, $ttl );
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::cached() - Cached value for key: ' . $key );
		}

		return $value;
	}

	/**
	 * Static group flush shortcut.
	 *
	 * @param string $group Group.
	 * @return void
	 */
	public static function bust( string $group ): void {
		$instance = self::instance();
		if (self::DEBUG) {
			$instance->log( 'VMS_Cache::bust() - Static wrapper called for group: ' . $group );
		}
		$instance->flush_group( $group );
	}
}