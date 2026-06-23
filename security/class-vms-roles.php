<?php
/**
 * Custom roles & capabilities manager.
 *
 * Defines six custom roles with granular, purpose-built capabilities.
 * Capabilities are VMS-specific so they cannot collide with other plugins
 * and can be checked independently of WP's native content caps.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Roles manager.
 */
final class VMS_Roles {

	/**
	 * Role definitions: slug => [display_name, capability_grants].
	 *
	 * @var array<string, array{name: string, caps: array<string, bool>}>
	 */
	private const ROLES = array(

		'member' => array(
			'name' => 'Member',
			'caps' => array(
				'read'                      => true,
				VMS_Config::CAP_REGISTER_GUESTS => true,
				VMS_Config::CAP_CANCEL_VISITS   => true,
			),
		),

		'chairman' => array(
			'name' => 'Chairman',
			'caps' => array(
				'read'                              => true,
				VMS_Config::CAP_REGISTER_GUESTS     => true,
				VMS_Config::CAP_REGISTER_COURTESY   => true,
				VMS_Config::CAP_CANCEL_VISITS       => true,
				VMS_Config::CAP_MANAGE_GUESTS       => true,
				VMS_Config::CAP_SIGNIN_GUESTS       => true,
				VMS_Config::CAP_SIGNOUT_GUESTS      => true,
				VMS_Config::CAP_MANAGE_SUPPLIERS    => true,
				VMS_Config::CAP_MANAGE_ACCOMMODATION => true,
				VMS_Config::CAP_MANAGE_RECIPROCATION => true,
				VMS_Config::CAP_MANAGE_EMPLOYEES    => true,
				VMS_Config::CAP_VIEW_REPORTS        => true,
				VMS_Config::CAP_VIEW_AUDIT_LOGS     => true,
				VMS_Config::CAP_EXPORT_DATA         => true,
				VMS_Config::CAP_APPROVE_MEMBERS     => true,
			),
		),

		'general_manager' => array(
			'name' => 'General Manager',
			'caps' => array(
				'read'                              => true,
				VMS_Config::CAP_REGISTER_GUESTS     => true,
				VMS_Config::CAP_REGISTER_COURTESY   => true,
				VMS_Config::CAP_CANCEL_VISITS       => true,
				VMS_Config::CAP_MANAGE_GUESTS       => true,
				VMS_Config::CAP_SIGNIN_GUESTS       => true,
				VMS_Config::CAP_SIGNOUT_GUESTS      => true,
				VMS_Config::CAP_MANAGE_SUPPLIERS    => true,
				VMS_Config::CAP_MANAGE_ACCOMMODATION => true,
				VMS_Config::CAP_MANAGE_RECIPROCATION => true,
				VMS_Config::CAP_MANAGE_EMPLOYEES    => true,
				VMS_Config::CAP_VIEW_REPORTS        => true,
				VMS_Config::CAP_VIEW_AUDIT_LOGS     => true,
				VMS_Config::CAP_EXPORT_DATA         => true,
				VMS_Config::CAP_APPROVE_MEMBERS     => true,
			),
		),

		'reception' => array(
			'name' => 'Reception',
			'caps' => array(
				'read'                          => true,
				VMS_Config::CAP_REGISTER_GUESTS => true,
				VMS_Config::CAP_MANAGE_GUESTS   => true,
				VMS_Config::CAP_CANCEL_VISITS   => true,
				VMS_Config::CAP_SIGNIN_GUESTS   => true,
				VMS_Config::CAP_SIGNOUT_GUESTS  => true,
				VMS_Config::CAP_APPROVE_MEMBERS => true,
			),
		),

		'gate' => array(
			'name' => 'Gate',
			'caps' => array(
				'read'                               => true,
				// Gate can view today's expected guests and sign them in/out
				// at the physical entrance, but cannot register new visits —
				// that requires a host (member) or reception desk.
				VMS_Config::CAP_SIGNIN_GUESTS        => true,
				VMS_Config::CAP_SIGNOUT_GUESTS       => true,
				VMS_Config::CAP_MANAGE_ACCOMMODATION => true,
				VMS_Config::CAP_MANAGE_SUPPLIERS     => true,
				VMS_Config::CAP_MANAGE_RECIPROCATION => true,
			),
		),
	);

	/**
	 * Register all custom roles. Idempotent.
	 *
	 * @return void
	 */
	public static function register_roles(): void {
		foreach ( self::ROLES as $slug => $config ) {
			// Remove existing role first so capability changes take effect
			// (add_role is a no-op if role already exists).
			if ( get_role( $slug ) ) {
				remove_role( $slug );
			}

			add_role(
				sanitize_key( $slug ),
				$config['name'],
				$config['caps']
			);
		}

		self::grant_admin_capabilities();
	}

	/**
	 * Grant all VMS capabilities to the administrator role.
	 *
	 * @return void
	 */
	private static function grant_admin_capabilities(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		foreach ( VMS_Config::get_all_capabilities() as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove all custom roles & strip caps from administrator.
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		foreach ( array_keys( self::ROLES ) as $slug ) {
			remove_role( $slug );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( VMS_Config::get_all_capabilities() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Get all custom role slugs.
	 *
	 * @return array<int, string>
	 */
	public static function get_role_slugs(): array {
		return array_keys( self::ROLES );
	}

	/**
	 * Get role definitions (for settings UI).
	 *
	 * @return array<string, array>
	 */
	public static function get_role_definitions(): array {
		return self::ROLES;
	}

	/**
	 * Check if a user has ANY of the given capabilities.
	 *
	 * @param array<string> $caps     Capabilities to check.
	 * @param int|null      $user_id  User ID (defaults to current user).
	 * @return bool
	 */
	public static function user_can_any( array $caps, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		foreach ( $caps as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a user's primary VMS role.
	 *
	 * @param int|null $user_id User ID.
	 * @return string|null Role slug or null if none.
	 */
	public static function get_user_vms_role( ?int $user_id = null ): ?string {
		$user_id = $user_id ?? get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		// Check for administrator first.
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return 'administrator';
		}

		// Check custom roles.
		foreach ( self::get_role_slugs() as $slug ) {
			if ( in_array( $slug, $user->roles, true ) ) {
				return $slug;
			}
		}

		return null;
	}
}
