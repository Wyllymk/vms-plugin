<?php
/**
 * Tests for VMS_Security and VMS_SMS_Gateway (normalize_phone).
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Security;
use WyllyMk\VMS\VMS_SMS_Gateway;
use WyllyMk\VMS\Singleton;

/**
 * VMS_Security test case.
 *
 * @group vms
 */
class Test_VMS_Security extends WP_UnitTestCase {

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset singleton instances to prevent state leaking.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		// Clear any rate-limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vms_rl_%'" );
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
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
	 * Test that normalize_phone correctly converts Kenyan local format (07xx).
	 *
	 * @return void
	 */
	public function test_normalize_phone_kenyan_local(): void {
		// Standard Kenyan mobile number starting with 07.
		$result = VMS_SMS_Gateway::normalize_phone( '0712345678' );
		$this->assertSame( '+254712345678', $result );

		// Kenyan mobile number starting with 01.
		$result_01 = VMS_SMS_Gateway::normalize_phone( '0112345678' );
		$this->assertSame( '+254112345678', $result_01 );

		// With spaces and dashes (should be stripped).
		$result_formatted = VMS_SMS_Gateway::normalize_phone( '0712 345 678' );
		$this->assertSame( '+254712345678', $result_formatted );

		$result_dashes = VMS_SMS_Gateway::normalize_phone( '0712-345-678' );
		$this->assertSame( '+254712345678', $result_dashes );

		// With parentheses and other characters.
		$result_parens = VMS_SMS_Gateway::normalize_phone( '(071) 234-5678' );
		$this->assertSame( '+254712345678', $result_parens );

		// 9-digit format without leading zero.
		$result_no_zero = VMS_SMS_Gateway::normalize_phone( '712345678' );
		$this->assertSame( '+254712345678', $result_no_zero );

		// Already has 254 prefix without +.
		$result_254 = VMS_SMS_Gateway::normalize_phone( '254712345678' );
		$this->assertSame( '+254712345678', $result_254 );
	}

	/**
	 * Test that normalize_phone handles international format.
	 *
	 * @return void
	 */
	public function test_normalize_phone_international(): void {
		// Already in E.164 format.
		$result = VMS_SMS_Gateway::normalize_phone( '+254712345678' );
		$this->assertSame( '+254712345678', $result );

		// International number with + prefix.
		$result_us = VMS_SMS_Gateway::normalize_phone( '+12025551234' );
		$this->assertSame( '+12025551234', $result_us );

		// UK number with + prefix.
		$result_uk = VMS_SMS_Gateway::normalize_phone( '+447911123456' );
		$this->assertSame( '+447911123456', $result_uk );

		// With spaces (international format).
		$result_spaced = VMS_SMS_Gateway::normalize_phone( '+254 712 345 678' );
		$this->assertSame( '+254712345678', $result_spaced );
	}

	/**
	 * Test that normalize_phone returns null for invalid numbers.
	 *
	 * @return void
	 */
	public function test_normalize_phone_invalid(): void {
		// Empty string.
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( '' ) );

		// Too short.
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( '12345' ) );

		// Only letters.
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( 'abcdefghij' ) );

		// Too short with + prefix.
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( '+123' ) );

		// Too long with + prefix (over 15 digits).
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( '+1234567890123456' ) );

		// Kenyan format but wrong starting digit (not 0 or 7 or 1).
		$this->assertNull( VMS_SMS_Gateway::normalize_phone( '0512345678' ) );
	}

	/**
	 * Test that rate_limit allows requests within the limit.
	 *
	 * @return void
	 */
	public function test_rate_limit_allows_within_limit(): void {
		$action     = 'test_action_' . wp_generate_password( 8, false );
		$max        = 3;
		$window     = 300;
		$identifier = 'test_user_123';

		// First request should be allowed.
		$result1 = VMS_Security::rate_limit( $action, $max, $window, $identifier );
		$this->assertTrue( $result1 );

		// Second request should be allowed.
		$result2 = VMS_Security::rate_limit( $action, $max, $window, $identifier );
		$this->assertTrue( $result2 );

		// Third request should be allowed (at limit).
		$result3 = VMS_Security::rate_limit( $action, $max, $window, $identifier );
		$this->assertTrue( $result3 );

		// Fourth request should be denied (over limit).
		$result4 = VMS_Security::rate_limit( $action, $max, $window, $identifier );
		$this->assertFalse( $result4 );

		// Different identifier should still be allowed.
		$result_diff = VMS_Security::rate_limit( $action, $max, $window, 'different_user' );
		$this->assertTrue( $result_diff );

		// Different action should still be allowed.
		$result_diff_action = VMS_Security::rate_limit( 'other_action', $max, $window, $identifier );
		$this->assertTrue( $result_diff_action );
	}

	/**
	 * Test that validate_upload rejects oversized files.
	 *
	 * @return void
	 */
	public function test_validate_upload_rejects_oversize(): void {
		$max_size = 1024 * 1024; // 1 MB.

		// Create a mock file array that exceeds the max size.
		$file = array(
			'tmp_name' => '/tmp/test_file.jpg',
			'size'     => 2 * 1024 * 1024, // 2 MB - exceeds 1 MB limit.
			'name'     => 'test_file.jpg',
			'type'     => 'image/jpeg',
			'error'    => 0,
		);

		$allowed = array( 'image/jpeg', 'image/png', 'image/gif' );

		$result = VMS_Security::validate_upload( $file, $allowed, $max_size );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
		$this->assertStringContainsString( 'maximum size', $result->get_error_message() );

		// Test with missing required fields.
		$invalid_file = array(
			'name' => 'test.jpg',
		);

		$result_invalid = VMS_Security::validate_upload( $invalid_file, $allowed, $max_size );
		$this->assertInstanceOf( WP_Error::class, $result_invalid );
		$this->assertSame( 'invalid_upload', $result_invalid->get_error_code() );

		// Test with a file within the size limit (will still fail on MIME type
		// since tmp_name doesn't exist, but that's a different error code).
		$small_file = array(
			'tmp_name' => '/tmp/nonexistent.jpg',
			'size'     => 500, // 500 bytes - well within limit.
			'name'     => 'small_file.jpg',
			'type'     => 'image/jpeg',
			'error'    => 0,
		);

		$result_small = VMS_Security::validate_upload( $small_file, $allowed, $max_size );
		// This should NOT be 'file_too_large' - it might fail on MIME check
		// since the file doesn't actually exist, but the size check passes.
		if ( is_wp_error( $result_small ) ) {
			$this->assertNotSame( 'file_too_large', $result_small->get_error_code() );
		}
	}
}
