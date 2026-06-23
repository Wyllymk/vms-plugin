<?php
/**
 * Singleton base class.
 *
 * Provides a reusable singleton implementation using late static binding
 * so child classes each get their own instance without re-implementing
 * boilerplate.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Singleton.
 */
abstract class Singleton {

	/**
	 * Instance registry keyed by called class name.
	 *
	 * @var array<string, static>
	 */
	private static array $instances = array();

	/**
	 * Prevent direct instantiation.
	 */
	protected function __construct() {}

	/**
	 * Prevent cloning.
	 */
	final protected function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \LogicException Always.
	 */
	final public function __wakeup(): void {
		throw new \LogicException( 'Cannot unserialize a singleton.' );
	}

	/**
	 * Retrieve (or create) the singleton instance.
	 *
	 * @return static
	 */
	final public static function instance(): static {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();

			if ( method_exists( self::$instances[ $class ], 'init' ) ) {
				self::$instances[ $class ]->init();
			}
		}

		return self::$instances[ $class ];
	}

	/**
	 * Reset all instances (testing only).
	 *
	 * @internal
	 * @return void
	 */
	final public static function reset_all_instances(): void {
		if ( ! defined( 'VMS_TESTING' ) || ! VMS_TESTING ) {
			return;
		}
		self::$instances = array();
	}
}
