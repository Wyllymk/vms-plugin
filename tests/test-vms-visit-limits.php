<?php
/**
 * Tests for VMS_Visit_Limits.
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Visit_Limits;
use WyllyMk\VMS\VMS_Config;
use WyllyMk\VMS\VMS_Cache;
use WyllyMk\VMS\Singleton;

/**
 * VMS_Visit_Limits test case.
 *
 * @group vms
 */
class Test_VMS_Visit_Limits extends WP_UnitTestCase {

	/**
	 * Whether the guest visits table exists.
	 *
	 * @var bool
	 */
	private bool $table_exists = false;

	/**
	 * The fully-qualified guest visits table name.
	 *
	 * @var string
	 */
	private string $visits_table = '';

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;

		// Reset singleton instances so cached values don't leak between tests.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		$this->visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		// Check if the visits table exists; if not, create a minimal version for testing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_check = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->visits_table )
		);

		if ( $table_check === $this->visits_table ) {
			$this->table_exists = true;
		} else {
			// Create a minimal table for testing.
			$charset = $wpdb->get_charset_collate();
			$sql     = "CREATE TABLE {$this->visits_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				guest_id BIGINT(20) UNSIGNED NOT NULL,
				host_member_id BIGINT(20) UNSIGNED DEFAULT NULL,
				visit_date DATE NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'approved',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY guest_id (guest_id),
				KEY host_member_id (host_member_id),
				KEY visit_date (visit_date)
			) {$charset};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			$this->table_exists = true;
		}

		// Clean VMS options.
		delete_option( 'vms_max_guest_visits_month' );
		delete_option( 'vms_max_guest_visits_year' );
		delete_option( 'vms_max_host_guests_day' );
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		if ( $this->table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "TRUNCATE TABLE {$this->visits_table}" );
		}

		// Clean up options.
		delete_option( 'vms_max_guest_visits_month' );
		delete_option( 'vms_max_guest_visits_year' );
		delete_option( 'vms_max_host_guests_day' );

		// Reset singleton instances.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		parent::tearDown();
	}

	/**
	 * Insert a test visit record into the guest visits table.
	 *
	 * @param int         $guest_id   Guest ID.
	 * @param int|null    $host_id    Host member ID.
	 * @param string      $date       Visit date (Y-m-d).
	 * @param string      $status     Visit status.
	 * @return int|false Insert ID or false.
	 */
	private function insert_visit( int $guest_id, ?int $host_id, string $date, string $status = 'approved' ) {
		global $wpdb;

		return $wpdb->insert(
			$this->visits_table,
			array(
				'guest_id'       => $guest_id,
				'host_member_id' => $host_id,
				'visit_date'     => $date,
				'status'         => $status,
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Test that a guest under the limit gets an approved status.
	 *
	 * @return void
	 */
	public function test_calculate_new_visit_status_under_limit_returns_approved(): void {
		if ( ! $this->table_exists ) {
			$this->markTestSkipped( 'Guest visits table does not exist.' );
		}

		$guest_id   = 1;
		$host_id    = 100;
		$visit_date = gmdate( 'Y-m-d' );

		// With no visits recorded, the guest should be under the limit.
		$result = VMS_Visit_Limits::calculate_new_visit_status( $guest_id, $host_id, $visit_date );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'visit_status', $result );
		$this->assertArrayHasKey( 'suspend_guest', $result );
		$this->assertArrayHasKey( 'reason', $result );
		$this->assertSame( VMS_Config::VISIT_APPROVED, $result['visit_status'] );
		$this->assertFalse( $result['suspend_guest'] );
		$this->assertNull( $result['reason'] );
	}

	/**
	 * Test that a guest at the monthly limit gets an unapproved status.
	 *
	 * @return void
	 */
	public function test_calculate_new_visit_status_at_monthly_limit_returns_unapproved(): void {
		if ( ! $this->table_exists ) {
			$this->markTestSkipped( 'Guest visits table does not exist.' );
		}

		$guest_id   = 2;
		$host_id    = 100;
		$visit_date = gmdate( 'Y-m-d' );

		// Set a low monthly limit for testing.
		update_option( 'vms_max_guest_visits_month', 2 );

		// Insert visits up to the monthly limit.
		$day1 = gmdate( 'Y-m-01' );
		$day2 = gmdate( 'Y-m-02' );
		$this->insert_visit( $guest_id, $host_id, $day1, 'approved' );
		$this->insert_visit( $guest_id, $host_id, $day2, 'approved' );

		// Reset singleton cache to get fresh counts.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		$result = VMS_Visit_Limits::calculate_new_visit_status( $guest_id, $host_id, $visit_date );

		$this->assertSame( VMS_Config::VISIT_UNAPPROVED, $result['visit_status'] );
		$this->assertTrue( $result['suspend_guest'] );
		$this->assertNotNull( $result['reason'] );
		$this->assertStringContainsString( 'monthly limit', $result['reason'] );
	}

	/**
	 * Test that a guest at the yearly limit gets an unapproved status.
	 *
	 * @return void
	 */
	public function test_calculate_new_visit_status_at_yearly_limit_returns_unapproved(): void {
		if ( ! $this->table_exists ) {
			$this->markTestSkipped( 'Guest visits table does not exist.' );
		}

		$guest_id   = 3;
		$host_id    = 100;
		$visit_date = gmdate( 'Y-m-d' );

		// Set a low yearly limit but high monthly limit so yearly triggers first.
		update_option( 'vms_max_guest_visits_year', 3 );
		update_option( 'vms_max_guest_visits_month', 100 );

		// Insert visits across different months to reach yearly limit.
		$year   = gmdate( 'Y' );
		$visits = array(
			"{$year}-01-15",
			"{$year}-02-15",
			"{$year}-03-15",
		);

		foreach ( $visits as $date ) {
			$this->insert_visit( $guest_id, $host_id, $date, 'approved' );
		}

		// Reset singleton cache.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		$result = VMS_Visit_Limits::calculate_new_visit_status( $guest_id, $host_id, $visit_date );

		$this->assertSame( VMS_Config::VISIT_UNAPPROVED, $result['visit_status'] );
		$this->assertTrue( $result['suspend_guest'] );
		$this->assertNotNull( $result['reason'] );
		$this->assertStringContainsString( 'yearly limit', $result['reason'] );
	}

	/**
	 * Test that get_guest_usage returns the correct structure.
	 *
	 * @return void
	 */
	public function test_get_guest_usage_returns_correct_structure(): void {
		if ( ! $this->table_exists ) {
			$this->markTestSkipped( 'Guest visits table does not exist.' );
		}

		$guest_id = 4;

		// Insert one visit this month.
		$today = gmdate( 'Y-m-d' );
		$this->insert_visit( $guest_id, 100, $today, 'approved' );

		// Reset singleton cache.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		$usage = VMS_Visit_Limits::get_guest_usage( $guest_id );

		$this->assertIsArray( $usage );
		$this->assertArrayHasKey( 'month_used', $usage );
		$this->assertArrayHasKey( 'month_limit', $usage );
		$this->assertArrayHasKey( 'year_used', $usage );
		$this->assertArrayHasKey( 'year_limit', $usage );

		// Verify types.
		$this->assertIsInt( $usage['month_used'] );
		$this->assertIsInt( $usage['month_limit'] );
		$this->assertIsInt( $usage['year_used'] );
		$this->assertIsInt( $usage['year_limit'] );

		// The month and year usage should be at least 1 (we inserted a visit).
		$this->assertGreaterThanOrEqual( 1, $usage['month_used'] );
		$this->assertGreaterThanOrEqual( 1, $usage['year_used'] );

		// Limits should match defaults.
		$this->assertSame( 4, $usage['month_limit'] );
		$this->assertSame( 24, $usage['year_limit'] );
	}

	/**
	 * Test that should_reactivate_guest returns true when under limits.
	 *
	 * @return void
	 */
	public function test_should_reactivate_guest_when_under_limits(): void {
		if ( ! $this->table_exists ) {
			$this->markTestSkipped( 'Guest visits table does not exist.' );
		}

		$guest_id = 5;

		// With no visits, the guest should be reactivatable.
		$result = VMS_Visit_Limits::should_reactivate_guest( $guest_id );
		$this->assertTrue( $result );

		// Now fill up the monthly limit.
		update_option( 'vms_max_guest_visits_month', 2 );

		$day1 = gmdate( 'Y-m-01' );
		$day2 = gmdate( 'Y-m-02' );
		$this->insert_visit( $guest_id, 100, $day1, 'approved' );
		$this->insert_visit( $guest_id, 100, $day2, 'approved' );

		// Reset singleton cache.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		// Guest is now at the monthly limit; should NOT be reactivated.
		$result_at_limit = VMS_Visit_Limits::should_reactivate_guest( $guest_id );
		$this->assertFalse( $result_at_limit );
	}
}
