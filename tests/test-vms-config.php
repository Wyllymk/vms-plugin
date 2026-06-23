<?php
/**
 * Tests for VMS_Config.
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Config;

/**
 * VMS_Config test case.
 *
 * @group vms
 */
class Test_VMS_Config extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any VMS options that may have been set by previous tests.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vms_%'" );
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up VMS options.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vms_%'" );

		parent::tearDown();
	}

	/**
	 * Test that get_table_name returns a properly prefixed table name.
	 *
	 * @return void
	 */
	public function test_get_table_name_returns_prefixed_name(): void {
		global $wpdb;

		$result = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		$this->assertStringStartsWith( $wpdb->prefix, $result );
		$this->assertSame( $wpdb->prefix . 'vms_guests', $result );

		// Test with another table constant.
		$result_visits = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$this->assertSame( $wpdb->prefix . 'vms_guest_visits', $result_visits );

		// Test with audit logs table.
		$result_audit = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );
		$this->assertSame( $wpdb->prefix . 'vms_audit_logs', $result_audit );
	}

	/**
	 * Test that get_option returns the default value from DEFAULTS constant
	 * when no option has been saved.
	 *
	 * @return void
	 */
	public function test_get_option_returns_default(): void {
		// Ensure no option is stored.
		delete_option( 'vms_max_guest_visits_month' );

		// Should return the default from VMS_Config::DEFAULTS.
		$result = VMS_Config::get_option( 'max_guest_visits_month' );
		$this->assertSame( 4, $result );

		// Test a string default.
		delete_option( 'vms_club_name' );
		$result_club = VMS_Config::get_option( 'club_name' );
		$this->assertSame( 'My Golf Club', $result_club );

		// Test a boolean default.
		delete_option( 'vms_module_guests' );
		$result_module = VMS_Config::get_option( 'module_guests' );
		$this->assertTrue( $result_module );

		// Test fallback default when key is not in DEFAULTS array.
		$result_unknown = VMS_Config::get_option( 'nonexistent_key', 'fallback_value' );
		$this->assertSame( 'fallback_value', $result_unknown );

		// Test that null is returned when key is not in DEFAULTS and no fallback given.
		$result_null = VMS_Config::get_option( 'nonexistent_key' );
		$this->assertNull( $result_null );

		// Test that a stored option overrides the default.
		update_option( 'vms_max_guest_visits_month', 10 );
		$result_stored = VMS_Config::get_option( 'max_guest_visits_month' );
		$this->assertEquals( 10, $result_stored );
	}

	/**
	 * Test that get_all_capabilities returns an array of capability strings.
	 *
	 * @return void
	 */
	public function test_get_all_capabilities_returns_array(): void {
		$caps = VMS_Config::get_all_capabilities();

		$this->assertIsArray( $caps );
		$this->assertNotEmpty( $caps );

		// Verify specific capabilities are present.
		$this->assertContains( 'vms_register_guests', $caps );
		$this->assertContains( 'vms_manage_guests', $caps );
		$this->assertContains( 'vms_manage_settings', $caps );
		$this->assertContains( 'vms_view_reports', $caps );
		$this->assertContains( 'vms_export_data', $caps );
		$this->assertContains( 'vms_view_audit_logs', $caps );
		$this->assertContains( 'vms_manage_accommodation', $caps );
		$this->assertContains( 'vms_manage_suppliers', $caps );
		$this->assertContains( 'vms_manage_reciprocation', $caps );
		$this->assertContains( 'vms_manage_employees', $caps );
		$this->assertContains( 'vms_approve_members', $caps );
		$this->assertContains( 'vms_register_courtesy_guests', $caps );
		$this->assertContains( 'vms_cancel_visits', $caps );
		$this->assertContains( 'vms_signin_guests', $caps );
		$this->assertContains( 'vms_signout_guests', $caps );

		// Verify the count matches expected number of capabilities.
		$this->assertCount( 15, $caps );

		// All values should be strings.
		foreach ( $caps as $cap ) {
			$this->assertIsString( $cap );
			$this->assertStringStartsWith( 'vms_', $cap );
		}
	}

	/**
	 * Test that get_all_cron_hooks returns an array of cron hook strings.
	 *
	 * @return void
	 */
	public function test_get_all_cron_hooks_returns_array(): void {
		$hooks = VMS_Config::get_all_cron_hooks();

		$this->assertIsArray( $hooks );
		$this->assertNotEmpty( $hooks );

		// Verify specific hooks are present.
		$this->assertContains( 'vms_midnight_tasks', $hooks );
		$this->assertContains( 'vms_auto_signout', $hooks );
		$this->assertContains( 'vms_monthly_reset', $hooks );
		$this->assertContains( 'vms_yearly_reset', $hooks );
		$this->assertContains( 'vms_check_sms_balance', $hooks );
		$this->assertContains( 'vms_check_sms_delivery', $hooks );
		$this->assertContains( 'vms_cleanup_old_logs', $hooks );

		// Verify the count matches expected number of cron hooks.
		$this->assertCount( 7, $hooks );

		// All values should be strings.
		foreach ( $hooks as $hook ) {
			$this->assertIsString( $hook );
			$this->assertStringStartsWith( 'vms_', $hook );
		}
	}

	/**
	 * Test that is_valid_status correctly validates status values.
	 *
	 * @return void
	 */
	public function test_is_valid_status_validates_correctly(): void {
		// --- Guest statuses (default type) ---
		$this->assertTrue( VMS_Config::is_valid_status( 'active' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'suspended' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'banned' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'approved' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'invalid_status' ) );
		$this->assertFalse( VMS_Config::is_valid_status( '' ) );

		// --- Visit statuses ---
		$this->assertTrue( VMS_Config::is_valid_status( 'approved', 'visit' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'unapproved', 'visit' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'cancelled', 'visit' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'suspended', 'visit' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'banned', 'visit' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'completed', 'visit' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'active', 'visit' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'invalid', 'visit' ) );

		// --- SMS statuses ---
		$this->assertTrue( VMS_Config::is_valid_status( 'queued', 'sms' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'sent', 'sms' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'delivered', 'sms' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'failed', 'sms' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'expired', 'sms' ) );
		$this->assertTrue( VMS_Config::is_valid_status( 'undelivered', 'sms' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'active', 'sms' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'approved', 'sms' ) );

		// --- Unknown type defaults to guest statuses ---
		$this->assertTrue( VMS_Config::is_valid_status( 'active', 'unknown_type' ) );
		$this->assertFalse( VMS_Config::is_valid_status( 'approved', 'unknown_type' ) );
	}
}
