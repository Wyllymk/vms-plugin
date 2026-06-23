<?php
/**
 * Tests for VMS_Roles.
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Roles;
use WyllyMk\VMS\VMS_Config;

/**
 * VMS_Roles test case.
 *
 * @group vms
 */
class Test_VMS_Roles extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any previously registered roles to ensure a fresh state.
		VMS_Roles::remove_roles();
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Remove all VMS roles after each test to prevent leaking state.
		VMS_Roles::remove_roles();

		parent::tearDown();
	}

	/**
	 * Test that register_roles creates all expected custom roles.
	 *
	 * @return void
	 */
	public function test_register_roles_creates_all_roles(): void {
		// Register the roles.
		VMS_Roles::register_roles();

		$expected_roles = array( 'member', 'chairman', 'general_manager', 'reception', 'gate' );

		foreach ( $expected_roles as $role_slug ) {
			$role = get_role( $role_slug );
			$this->assertNotNull( $role, "Role '{$role_slug}' should exist after registration." );
		}

		// Verify specific capabilities for each role.
		$member = get_role( 'member' );
		$this->assertNotNull( $member );
		$this->assertTrue( $member->has_cap( 'read' ) );
		$this->assertTrue( $member->has_cap( VMS_Config::CAP_REGISTER_GUESTS ) );
		$this->assertTrue( $member->has_cap( VMS_Config::CAP_CANCEL_VISITS ) );
		$this->assertFalse( $member->has_cap( VMS_Config::CAP_MANAGE_SETTINGS ) );

		$chairman = get_role( 'chairman' );
		$this->assertNotNull( $chairman );
		$this->assertTrue( $chairman->has_cap( 'read' ) );
		$this->assertTrue( $chairman->has_cap( VMS_Config::CAP_REGISTER_GUESTS ) );
		$this->assertTrue( $chairman->has_cap( VMS_Config::CAP_REGISTER_COURTESY ) );
		$this->assertTrue( $chairman->has_cap( VMS_Config::CAP_VIEW_REPORTS ) );
		$this->assertTrue( $chairman->has_cap( VMS_Config::CAP_EXPORT_DATA ) );
		$this->assertTrue( $chairman->has_cap( VMS_Config::CAP_APPROVE_MEMBERS ) );

		$reception = get_role( 'reception' );
		$this->assertNotNull( $reception );
		$this->assertTrue( $reception->has_cap( VMS_Config::CAP_SIGNIN_GUESTS ) );
		$this->assertTrue( $reception->has_cap( VMS_Config::CAP_SIGNOUT_GUESTS ) );
		$this->assertTrue( $reception->has_cap( VMS_Config::CAP_MANAGE_GUESTS ) );

		$gate = get_role( 'gate' );
		$this->assertNotNull( $gate );
		$this->assertTrue( $gate->has_cap( VMS_Config::CAP_MANAGE_ACCOMMODATION ) );
		$this->assertTrue( $gate->has_cap( VMS_Config::CAP_MANAGE_SUPPLIERS ) );
		$this->assertTrue( $gate->has_cap( VMS_Config::CAP_MANAGE_RECIPROCATION ) );

		// Verify administrator gets all VMS capabilities.
		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin );
		foreach ( VMS_Config::get_all_capabilities() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), "Administrator should have capability '{$cap}'." );
		}
	}

	/**
	 * Test that remove_roles removes all custom roles.
	 *
	 * @return void
	 */
	public function test_remove_roles_removes_all_roles(): void {
		// First register roles.
		VMS_Roles::register_roles();

		// Verify they exist.
		$expected_roles = array( 'member', 'chairman', 'general_manager', 'reception', 'gate' );
		foreach ( $expected_roles as $role_slug ) {
			$this->assertNotNull( get_role( $role_slug ), "Role '{$role_slug}' should exist before removal." );
		}

		// Now remove them.
		VMS_Roles::remove_roles();

		// Verify all roles are removed.
		foreach ( $expected_roles as $role_slug ) {
			$this->assertNull( get_role( $role_slug ), "Role '{$role_slug}' should be null after removal." );
		}

		// Verify administrator VMS capabilities are removed.
		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin );
		foreach ( VMS_Config::get_all_capabilities() as $cap ) {
			$this->assertFalse( $admin->has_cap( $cap ), "Administrator should NOT have capability '{$cap}' after removal." );
		}
	}

	/**
	 * Test that get_role_slugs returns all expected role slugs.
	 *
	 * @return void
	 */
	public function test_get_role_slugs_returns_expected(): void {
		$slugs = VMS_Roles::get_role_slugs();

		$this->assertIsArray( $slugs );
		$this->assertNotEmpty( $slugs );

		$expected = array( 'member', 'chairman', 'general_manager', 'reception', 'gate' );

		// Verify all expected slugs are present.
		foreach ( $expected as $slug ) {
			$this->assertContains( $slug, $slugs, "Role slug '{$slug}' should be in the list." );
		}

		// Verify the count matches.
		$this->assertCount( count( $expected ), $slugs );

		// Verify all are strings.
		foreach ( $slugs as $slug ) {
			$this->assertIsString( $slug );
		}
	}

	/**
	 * Test that user_can_any checks capabilities correctly.
	 *
	 * @return void
	 */
	public function test_user_can_any_checks_capabilities(): void {
		// Register roles so capabilities exist.
		VMS_Roles::register_roles();

		// Create a user with the 'member' role.
		$user_id = self::factory()->user->create( array( 'role' => 'member' ) );

		// Member should have register_guests capability.
		$result = VMS_Roles::user_can_any(
			array( VMS_Config::CAP_REGISTER_GUESTS ),
			$user_id
		);
		$this->assertTrue( $result );

		// Member should NOT have manage_settings capability.
		$result_no = VMS_Roles::user_can_any(
			array( VMS_Config::CAP_MANAGE_SETTINGS ),
			$user_id
		);
		$this->assertFalse( $result_no );

		// Check with multiple capabilities where at least one matches.
		$result_any = VMS_Roles::user_can_any(
			array( VMS_Config::CAP_MANAGE_SETTINGS, VMS_Config::CAP_REGISTER_GUESTS ),
			$user_id
		);
		$this->assertTrue( $result_any );

		// Check with all capabilities that the user does not have.
		$result_none = VMS_Roles::user_can_any(
			array( VMS_Config::CAP_MANAGE_SETTINGS, VMS_Config::CAP_VIEW_REPORTS, VMS_Config::CAP_EXPORT_DATA ),
			$user_id
		);
		$this->assertFalse( $result_none );

		// Check with an empty array.
		$result_empty = VMS_Roles::user_can_any( array(), $user_id );
		$this->assertFalse( $result_empty );

		// Clean up.
		wp_delete_user( $user_id );
	}

	/**
	 * Test that get_user_vms_role returns the correct role for a user.
	 *
	 * @return void
	 */
	public function test_get_user_vms_role_returns_correct_role(): void {
		// Register roles.
		VMS_Roles::register_roles();

		// Create users with different VMS roles.
		$member_id    = self::factory()->user->create( array( 'role' => 'member' ) );
		$chairman_id  = self::factory()->user->create( array( 'role' => 'chairman' ) );
		$reception_id = self::factory()->user->create( array( 'role' => 'reception' ) );
		$admin_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Verify each role is correctly identified.
		$this->assertSame( 'member', VMS_Roles::get_user_vms_role( $member_id ) );
		$this->assertSame( 'chairman', VMS_Roles::get_user_vms_role( $chairman_id ) );
		$this->assertSame( 'reception', VMS_Roles::get_user_vms_role( $reception_id ) );
		$this->assertSame( 'administrator', VMS_Roles::get_user_vms_role( $admin_id ) );

		// Subscriber is not a VMS role, should return null.
		$this->assertNull( VMS_Roles::get_user_vms_role( $subscriber_id ) );

		// Non-existent user should return null.
		$this->assertNull( VMS_Roles::get_user_vms_role( 999999 ) );

		// Clean up.
		wp_delete_user( $member_id );
		wp_delete_user( $chairman_id );
		wp_delete_user( $reception_id );
		wp_delete_user( $admin_id );
		wp_delete_user( $subscriber_id );
	}
}
