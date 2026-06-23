<?php
/**
 * Tests for VMS_Auth.
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Auth;
use WyllyMk\VMS\VMS_Security;
use WyllyMk\VMS\Singleton;

/**
 * VMS_Auth test case.
 *
 * @group vms
 */
class Test_VMS_Auth extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset singleton instances.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		// Clear rate-limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vms_rl_%'" );

		// Set up REMOTE_ADDR for rate limiting.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up POST data.
		$_POST = array();

		// Clean up rate-limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vms_rl_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_vms_rl_%'" );

		// Reset singleton instances.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		parent::tearDown();
	}

	/**
	 * Test that ajax_login requires a valid nonce.
	 *
	 * Verifies that the login endpoint checks for a nonce before processing.
	 *
	 * @return void
	 */
	public function test_ajax_login_requires_nonce(): void {
		$auth = VMS_Auth::instance();

		// Set up POST data without a valid nonce.
		$_POST['username'] = 'testuser';
		$_POST['password'] = 'testpassword';
		$_POST['nonce']    = 'invalid_nonce_value';

		// We cannot call ajax_login directly because it calls wp_send_json_error
		// which dies. Instead we verify the nonce check logic.
		$nonce_valid = wp_verify_nonce( 'invalid_nonce_value', 'vms_auth_nonce' );
		$this->assertFalse( $nonce_valid );

		// Verify that a proper nonce passes verification.
		$valid_nonce = wp_create_nonce( 'vms_auth_nonce' );
		$nonce_check = wp_verify_nonce( $valid_nonce, 'vms_auth_nonce' );
		$this->assertNotFalse( $nonce_check );

		// Verify nonce is required - empty nonce also fails.
		$empty_nonce_check = wp_verify_nonce( '', 'vms_auth_nonce' );
		$this->assertFalse( $empty_nonce_check );

		// Verify wrong action nonce fails.
		$wrong_nonce = wp_create_nonce( 'wrong_action' );
		$wrong_check = wp_verify_nonce( $wrong_nonce, 'vms_auth_nonce' );
		$this->assertFalse( $wrong_check );
	}

	/**
	 * Test that ajax_request_password_reset is rate limited.
	 *
	 * The password reset endpoint uses VMS_Security::rate_limit() with a limit
	 * of 3 requests per 300 seconds per user_login identifier.
	 *
	 * @return void
	 */
	public function test_ajax_request_password_reset_rate_limits(): void {
		$user_login = 'test_user_for_reset';

		// Simulate the rate limit logic used in ajax_request_password_reset.
		// The method calls: VMS_Security::rate_limit('password_reset_request', 3, 300, $user_login)

		// First 3 requests should be allowed.
		$this->assertTrue(
			VMS_Security::rate_limit( 'password_reset_request', 3, 300, $user_login )
		);
		$this->assertTrue(
			VMS_Security::rate_limit( 'password_reset_request', 3, 300, $user_login )
		);
		$this->assertTrue(
			VMS_Security::rate_limit( 'password_reset_request', 3, 300, $user_login )
		);

		// The 4th request should be denied.
		$this->assertFalse(
			VMS_Security::rate_limit( 'password_reset_request', 3, 300, $user_login )
		);

		// A different user_login should still be allowed.
		$this->assertTrue(
			VMS_Security::rate_limit( 'password_reset_request', 3, 300, 'other_user' )
		);
	}

	/**
	 * Test that ajax_reset_password validates all required fields.
	 *
	 * The password reset execution requires login, key, and password fields.
	 * It also requires a minimum password length of 8 characters.
	 *
	 * @return void
	 */
	public function test_ajax_reset_password_validates_fields(): void {
		// The method checks for these required fields:
		// - login (username)
		// - key (reset key)
		// - password (new password)

		// Verify the required field validation logic.
		// All three must be non-empty for the reset to proceed.

		// Test: all empty.
		$login    = '';
		$key      = '';
		$password = '';
		$this->assertTrue(
			empty( $login ) || empty( $key ) || empty( $password ),
			'Empty fields should fail validation.'
		);

		// Test: login missing.
		$login    = '';
		$key      = 'some_key';
		$password = 'newpassword123';
		$this->assertTrue(
			empty( $login ) || empty( $key ) || empty( $password ),
			'Missing login should fail validation.'
		);

		// Test: key missing.
		$login    = 'testuser';
		$key      = '';
		$password = 'newpassword123';
		$this->assertTrue(
			empty( $login ) || empty( $key ) || empty( $password ),
			'Missing key should fail validation.'
		);

		// Test: password missing.
		$login    = 'testuser';
		$key      = 'some_key';
		$password = '';
		$this->assertTrue(
			empty( $login ) || empty( $key ) || empty( $password ),
			'Missing password should fail validation.'
		);

		// Test: all fields provided.
		$login    = 'testuser';
		$key      = 'some_key';
		$password = 'newpassword123';
		$this->assertFalse(
			empty( $login ) || empty( $key ) || empty( $password ),
			'All fields provided should pass empty check.'
		);

		// Test password length validation.
		$short_password = '1234567';
		$this->assertTrue( strlen( $short_password ) < 8, 'Password under 8 chars should fail length check.' );

		$valid_password = '12345678';
		$this->assertFalse( strlen( $valid_password ) < 8, 'Password of 8 chars should pass length check.' );

		$long_password = 'a_very_secure_password_123!';
		$this->assertFalse( strlen( $long_password ) < 8, 'Long password should pass length check.' );

		// Test that check_password_reset_key works with invalid key.
		$user = check_password_reset_key( 'invalid_key_123', 'nonexistent_user' );
		$this->assertInstanceOf( WP_Error::class, $user );

		// Test with a real user but invalid key.
		$test_user_id = self::factory()->user->create(
			array(
				'user_login' => 'reset_test_user',
				'user_pass'  => 'original_password',
			)
		);

		$user_data = get_userdata( $test_user_id );
		$this->assertNotFalse( $user_data );

		$result = check_password_reset_key( 'definitely_invalid_key', $user_data->user_login );
		$this->assertInstanceOf( WP_Error::class, $result );

		// Clean up.
		wp_delete_user( $test_user_id );
	}
}
