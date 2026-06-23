<?php
/**
 * Tests for VMS_Module_Builder.
 *
 * @package WyllyMk\VMS
 * @group   vms
 */

use WyllyMk\VMS\VMS_Module_Builder;
use WyllyMk\VMS\Singleton;

/**
 * VMS_Module_Builder test case.
 *
 * @group vms
 */
class Test_VMS_Module_Builder extends WP_UnitTestCase {

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

		// Clear any stored custom modules.
		delete_option( 'vms_custom_modules' );

		// Create a user with manage_options to satisfy capability checks in audit trail.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up custom modules option.
		delete_option( 'vms_custom_modules' );

		// Drop any test module tables that may have been created.
		global $wpdb;
		$test_tables = array(
			$wpdb->prefix . 'vms_mod_test_module',
			$wpdb->prefix . 'vms_mod_testmodule',
			$wpdb->prefix . 'vms_mod_test-module',
			$wpdb->prefix . 'vms_mod_another_module',
		);

		foreach ( $test_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		// Reset singleton instances.
		if ( defined( 'VMS_TESTING' ) && VMS_TESTING ) {
			Singleton::reset_all_instances();
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Test that save_module creates a module definition successfully.
	 *
	 * @return void
	 */
	public function test_save_module_creates_definition(): void {
		$module_data = array(
			'name'        => 'Test Module',
			'slug'        => 'test_module',
			'description' => 'A module for testing.',
			'icon'        => 'dashicons-admin-tools',
			'fields'      => array(
				array(
					'name'     => 'full_name',
					'label'    => 'Full Name',
					'type'     => 'text',
					'required' => true,
				),
				array(
					'name'     => 'email_address',
					'label'    => 'Email Address',
					'type'     => 'email',
					'required' => false,
				),
				array(
					'name'  => 'visit_count',
					'label' => 'Visit Count',
					'type'  => 'number',
				),
			),
		);

		$result = VMS_Module_Builder::save_module( $module_data );

		// Should return the module slug.
		$this->assertIsString( $result );
		$this->assertSame( 'test_module', $result );

		// Verify the module is stored.
		$modules = VMS_Module_Builder::get_modules();
		$this->assertArrayHasKey( 'test_module', $modules );

		$saved_module = $modules['test_module'];
		$this->assertSame( 'Test Module', $saved_module['name'] );
		$this->assertSame( 'test_module', $saved_module['slug'] );
		$this->assertSame( 'A module for testing.', $saved_module['description'] );
		$this->assertSame( 'dashicons-admin-tools', $saved_module['icon'] );
		$this->assertCount( 3, $saved_module['fields'] );

		// Verify field details.
		$this->assertSame( 'full_name', $saved_module['fields'][0]['name'] );
		$this->assertSame( 'text', $saved_module['fields'][0]['type'] );
		$this->assertTrue( $saved_module['fields'][0]['required'] );

		$this->assertSame( 'email_address', $saved_module['fields'][1]['name'] );
		$this->assertSame( 'email', $saved_module['fields'][1]['type'] );
		$this->assertFalse( $saved_module['fields'][1]['required'] );

		// Verify timestamps are set.
		$this->assertArrayHasKey( 'created_at', $saved_module );
		$this->assertArrayHasKey( 'updated_at', $saved_module );
	}

	/**
	 * Test that save_module requires a module name.
	 *
	 * @return void
	 */
	public function test_save_module_requires_name(): void {
		// No name provided.
		$module_data = array(
			'fields' => array(
				array(
					'name'  => 'some_field',
					'label' => 'Some Field',
					'type'  => 'text',
				),
			),
		);

		$result = VMS_Module_Builder::save_module( $module_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );

		// Empty name.
		$module_data_empty = array(
			'name'   => '',
			'fields' => array(
				array(
					'name'  => 'some_field',
					'label' => 'Some Field',
					'type'  => 'text',
				),
			),
		);

		$result_empty = VMS_Module_Builder::save_module( $module_data_empty );
		$this->assertInstanceOf( WP_Error::class, $result_empty );
		$this->assertSame( 'missing_name', $result_empty->get_error_code() );
	}

	/**
	 * Test that save_module requires at least one field.
	 *
	 * @return void
	 */
	public function test_save_module_requires_fields(): void {
		// No fields provided.
		$module_data = array(
			'name' => 'Test Module',
		);

		$result = VMS_Module_Builder::save_module( $module_data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_fields', $result->get_error_code() );

		// Empty fields array.
		$module_data_empty = array(
			'name'   => 'Test Module',
			'fields' => array(),
		);

		$result_empty = VMS_Module_Builder::save_module( $module_data_empty );
		$this->assertInstanceOf( WP_Error::class, $result_empty );
		$this->assertSame( 'no_fields', $result_empty->get_error_code() );

		// Fields array with entries that have no name (they get filtered out).
		$module_data_no_name = array(
			'name'   => 'Test Module',
			'fields' => array(
				array(
					'label' => 'No Name Field',
					'type'  => 'text',
				),
			),
		);

		$result_no_name = VMS_Module_Builder::save_module( $module_data_no_name );
		$this->assertInstanceOf( WP_Error::class, $result_no_name );
		$this->assertSame( 'no_fields', $result_no_name->get_error_code() );
	}

	/**
	 * Test that get_modules returns an array.
	 *
	 * @return void
	 */
	public function test_get_modules_returns_array(): void {
		// Initially should be empty.
		$modules = VMS_Module_Builder::get_modules();
		$this->assertIsArray( $modules );
		$this->assertEmpty( $modules );

		// Add a module.
		VMS_Module_Builder::save_module(
			array(
				'name'   => 'Module One',
				'slug'   => 'module_one',
				'fields' => array(
					array(
						'name'  => 'field_a',
						'label' => 'Field A',
						'type'  => 'text',
					),
				),
			)
		);

		// Add another module.
		VMS_Module_Builder::save_module(
			array(
				'name'   => 'Module Two',
				'slug'   => 'another_module',
				'fields' => array(
					array(
						'name'  => 'field_b',
						'label' => 'Field B',
						'type'  => 'number',
					),
				),
			)
		);

		$modules_after = VMS_Module_Builder::get_modules();
		$this->assertIsArray( $modules_after );
		$this->assertCount( 2, $modules_after );
		$this->assertArrayHasKey( 'module_one', $modules_after );
		$this->assertArrayHasKey( 'another_module', $modules_after );

		// Verify individual module can be retrieved.
		$single = VMS_Module_Builder::get_module( 'module_one' );
		$this->assertIsArray( $single );
		$this->assertSame( 'Module One', $single['name'] );

		// Non-existent module returns null.
		$this->assertNull( VMS_Module_Builder::get_module( 'nonexistent' ) );
	}

	/**
	 * Test that delete_module removes a module definition.
	 *
	 * @return void
	 */
	public function test_delete_module_removes_definition(): void {
		// Create a module first.
		VMS_Module_Builder::save_module(
			array(
				'name'   => 'Deletable Module',
				'slug'   => 'test_module',
				'fields' => array(
					array(
						'name'  => 'field_x',
						'label' => 'Field X',
						'type'  => 'text',
					),
				),
			)
		);

		// Verify it exists.
		$this->assertNotNull( VMS_Module_Builder::get_module( 'test_module' ) );

		// Delete it.
		$result = VMS_Module_Builder::delete_module( 'test_module' );
		$this->assertTrue( $result );

		// Verify it no longer exists.
		$this->assertNull( VMS_Module_Builder::get_module( 'test_module' ) );

		// Deleting a non-existent module should return false.
		$result_nonexistent = VMS_Module_Builder::delete_module( 'nonexistent_module' );
		$this->assertFalse( $result_nonexistent );

		// Verify the modules list is now empty.
		$modules = VMS_Module_Builder::get_modules();
		$this->assertEmpty( $modules );
	}
}
