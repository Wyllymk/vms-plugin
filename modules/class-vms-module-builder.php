<?php
/**
 * Module Builder — create custom data modules via settings.
 *
 * Allows administrators to define custom data-entry modules without
 * writing code. Each module consists of a name, fields definition,
 * and a dynamically-created database table. Users can then create,
 * read, update, and delete records through auto-generated forms.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Module Builder.
 */
final class VMS_Module_Builder extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Option key for storing custom module definitions.
	 */
	private const MODULES_OPTION = 'vms_custom_modules';

	/**
	 * Allowed field types.
	 */
	private const FIELD_TYPES = array( 'text', 'number', 'email', 'tel', 'date', 'time', 'datetime', 'textarea', 'select', 'checkbox', 'url' );

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Admin AJAX for module management.
		add_action( 'wp_ajax_vms_save_custom_module', array( $this, 'ajax_save_module' ) );
		add_action( 'wp_ajax_vms_delete_custom_module', array( $this, 'ajax_delete_module' ) );
		add_action( 'wp_ajax_vms_get_custom_modules', array( $this, 'ajax_get_modules' ) );

		// CRUD AJAX for custom module records.
		add_action( 'wp_ajax_vms_custom_module_create', array( $this, 'ajax_create_record' ) );
		add_action( 'wp_ajax_vms_custom_module_read', array( $this, 'ajax_read_records' ) );
		add_action( 'wp_ajax_vms_custom_module_update', array( $this, 'ajax_update_record' ) );
		add_action( 'wp_ajax_vms_custom_module_delete', array( $this, 'ajax_delete_record' ) );
		add_action( 'wp_ajax_vms_custom_module_export', array( $this, 'ajax_export_records' ) );
	}

	/**
	 * Get all custom module definitions.
	 *
	 * @return array
	 */
	public static function get_modules(): array {
		$modules = get_option( self::MODULES_OPTION, array() );
		return is_array( $modules ) ? $modules : array();
	}

	/**
	 * Get a single module definition by slug.
	 *
	 * @param string $slug Module slug.
	 * @return array|null
	 */
	public static function get_module( string $slug ): ?array {
		$modules = self::get_modules();
		return $modules[ $slug ] ?? null;
	}

	/**
	 * Normalize custom-module capabilities to VMS/admin capabilities only.
	 *
	 * @param string $capability Requested capability.
	 * @return string
	 */
	private static function normalize_module_capability( string $capability ): string {
		$capability = sanitize_key( $capability );
		$allowed    = array_merge( VMS_Config::get_all_capabilities(), array( 'manage_options' ) );

		return in_array( $capability, $allowed, true ) ? $capability : VMS_Config::CAP_MANAGE_SETTINGS;
	}

	/**
	 * Enforce access to a custom module's records.
	 *
	 * @param array $module Module definition.
	 * @return void
	 */
	private static function verify_module_access( array $module ): void {
		$capability = self::normalize_module_capability( (string) ( $module['capability'] ?? '' ) );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vms-plugin' ) ), 403 );
		}
	}

	/**
	 * Save a custom module definition.
	 *
	 * @param array $module_data Module configuration.
	 * @return string|\WP_Error Module slug or error.
	 */
	public static function save_module( array $module_data ) {
		$name = sanitize_text_field( $module_data['name'] ?? '' );
		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'Module name is required.', 'vms-plugin' ) );
		}

		$slug = sanitize_key( $module_data['slug'] ?? sanitize_title( $name ) );
		if ( empty( $slug ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Invalid module slug.', 'vms-plugin' ) );
		}

		// Validate fields.
		$fields = array();
		if ( ! empty( $module_data['fields'] ) && is_array( $module_data['fields'] ) ) {
			foreach ( $module_data['fields'] as $field ) {
				$field_name = sanitize_key( $field['name'] ?? '' );
				$field_type = sanitize_key( $field['type'] ?? 'text' );

				if ( empty( $field_name ) ) {
					continue;
				}

				if ( ! in_array( $field_type, self::FIELD_TYPES, true ) ) {
					$field_type = 'text';
				}

				$fields[] = array(
					'name'        => $field_name,
					'label'       => sanitize_text_field( $field['label'] ?? ucfirst( $field_name ) ),
					'type'        => $field_type,
					'required'    => ! empty( $field['required'] ),
					'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
					'options'     => isset( $field['options'] ) ? array_map( 'sanitize_text_field', (array) $field['options'] ) : array(),
					'description' => sanitize_text_field( $field['description'] ?? '' ),
				);
			}
		}

		if ( empty( $fields ) ) {
			return new \WP_Error( 'no_fields', __( 'At least one field is required.', 'vms-plugin' ) );
		}

		$module = array(
			'name'        => $name,
			'slug'        => $slug,
				'description' => sanitize_textarea_field( $module_data['description'] ?? '' ),
				'icon'        => sanitize_text_field( $module_data['icon'] ?? 'dashicons-list-view' ),
				'fields'      => $fields,
				'capability'  => self::normalize_module_capability( (string) ( $module_data['capability'] ?? '' ) ),
				'created_at'  => $module_data['created_at'] ?? current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			);

		$modules          = self::get_modules();
		$modules[ $slug ] = $module;

		update_option( self::MODULES_OPTION, $modules, false );

		// Create the database table for this module.
		self::create_module_table( $slug, $fields );

		VMS_Audit_Trail::log(
			'custom_module_saved',
			VMS_Audit_Trail::CAT_SETTINGS,
			'custom_module',
			null,
			null,
			array( 'slug' => $slug, 'name' => $name, 'field_count' => count( $fields ) )
		);

		return $slug;
	}

	/**
	 * Delete a custom module.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function delete_module( string $slug ): bool {
		$modules = self::get_modules();

		if ( ! isset( $modules[ $slug ] ) ) {
			return false;
		}

		$module_name = $modules[ $slug ]['name'] ?? $slug;
		unset( $modules[ $slug ] );

		update_option( self::MODULES_OPTION, $modules, false );

		// Note: We do NOT drop the table to prevent data loss.
		// Admin can manually drop it from System Info if needed.

		VMS_Audit_Trail::log(
			'custom_module_deleted',
			VMS_Audit_Trail::CAT_SETTINGS,
			'custom_module',
			null,
			array( 'slug' => $slug, 'name' => $module_name ),
			null
		);

		return true;
	}

	/**
	 * Create a database table for a custom module.
	 *
	 * @param string $slug   Module slug.
	 * @param array  $fields Field definitions.
	 * @return void
	 */
	private static function create_module_table( string $slug, array $fields ): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'vms_mod_' . $slug;
		$charset = $wpdb->get_charset_collate();

		$columns = array(
			'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
		);

		foreach ( $fields as $field ) {
			$col_type = match ( $field['type'] ) {
				'number'   => 'DECIMAL(12,2) DEFAULT NULL',
				'checkbox' => 'TINYINT(1) NOT NULL DEFAULT 0',
				'date'     => 'DATE DEFAULT NULL',
				'time'     => 'TIME DEFAULT NULL',
				'datetime' => 'DATETIME DEFAULT NULL',
				'textarea' => 'TEXT DEFAULT NULL',
				default    => 'VARCHAR(255) DEFAULT NULL',
			};

			$columns[] = $field['name'] . ' ' . $col_type;
		}

		$columns[] = 'created_by BIGINT(20) UNSIGNED DEFAULT NULL';
		$columns[] = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
		$columns[] = 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
		$columns[] = 'PRIMARY KEY  (id)';

		$sql = sprintf(
			'CREATE TABLE %s (%s) %s;',
			$table,
			implode( ",\n", $columns ),
			$charset
		);

		dbDelta( $sql );
	}

	/**
	 * Get the table name for a custom module.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	private static function get_module_table( string $slug ): string {
		global $wpdb;
		return $wpdb->prefix . 'vms_mod_' . sanitize_key( $slug );
	}

	// =====================================================================
	// AJAX: Module Management
	// =====================================================================

	/**
	 * AJAX: Save a custom module definition.
	 *
	 * @return void
	 */
	public function ajax_save_module(): void {
		self::verify_ajax( 'vms_settings_nonce', VMS_Config::CAP_MANAGE_SETTINGS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['module'] ) ? wp_unslash( $_POST['module'] ) : '';

		if ( is_string( $raw ) ) {
			$module_data = json_decode( $raw, true );
		} else {
			$module_data = (array) $raw;
		}

		if ( empty( $module_data ) || ! is_array( $module_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid module data.', 'vms-plugin' ) ) );
		}

		$result = self::save_module( $module_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'slug'    => $result,
				'module'  => self::get_module( $result ),
				'message' => __( 'Module saved successfully.', 'vms-plugin' ),
			)
		);
	}

	/**
	 * AJAX: Delete a custom module.
	 *
	 * @return void
	 */
	public function ajax_delete_module(): void {
		self::verify_ajax( 'vms_settings_nonce', VMS_Config::CAP_MANAGE_SETTINGS );

		$slug = self::get_post_text( 'slug' );

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Module slug required.', 'vms-plugin' ) ) );
		}

		if ( self::delete_module( $slug ) ) {
			wp_send_json_success( array( 'message' => __( 'Module deleted.', 'vms-plugin' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}
	}

	/**
	 * AJAX: Get all custom modules.
	 *
	 * @return void
	 */
	public function ajax_get_modules(): void {
		self::verify_ajax( 'vms_settings_nonce', VMS_Config::CAP_MANAGE_SETTINGS );

		wp_send_json_success( array( 'modules' => self::get_modules() ) );
	}

	// =====================================================================
	// AJAX: Record CRUD
	// =====================================================================

	/**
	 * AJAX: Create a record in a custom module.
	 *
	 * @return void
	 */
	public function ajax_create_record(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$slug   = self::get_post_text( 'module_slug' );
		$module = self::get_module( $slug );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}

		self::verify_module_access( $module );

		global $wpdb;
		$table = self::get_module_table( $slug );

		$data   = array( 'created_by' => get_current_user_id() ?: null );
		$format = array( '%d' );

		foreach ( $module['fields'] as $field ) {
			$value = self::get_post_text( $field['name'] );

			if ( $field['required'] && empty( $value ) && '0' !== $value ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: field label */
							__( '%s is required.', 'vms-plugin' ),
							$field['label']
						),
					)
				);
			}

			$data[ $field['name'] ] = $value ?: null;
			$format[]               = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create record.', 'vms-plugin' ) ) );
		}

		VMS_Audit_Trail::log_create( VMS_Audit_Trail::CAT_SYSTEM, 'custom_' . $slug, $wpdb->insert_id, $data );

		wp_send_json_success(
			array(
				'id'      => $wpdb->insert_id,
				'message' => __( 'Record created.', 'vms-plugin' ),
			)
		);
	}

	/**
	 * AJAX: Read records from a custom module.
	 *
	 * @return void
	 */
	public function ajax_read_records(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$slug   = self::get_post_text( 'module_slug' );
		$module = self::get_module( $slug );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}

		self::verify_module_access( $module );

		global $wpdb;
		$table    = self::get_module_table( $slug );
		$page     = max( 1, self::get_post_int( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, self::get_post_int( 'per_page' ) ?: 25 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = self::get_post_text( 'search' );

		$where  = '1=1';
		$params = array();

		if ( $search && strlen( $search ) >= 2 ) {
			$like        = '%' . $wpdb->esc_like( $search ) . '%';
			$search_cols = array();

			foreach ( $module['fields'] as $field ) {
				if ( in_array( $field['type'], array( 'text', 'email', 'tel', 'url' ), true ) ) {
					$search_cols[] = $field['name'] . ' LIKE %s';
					$params[]      = $like;
				}
			}

			if ( ! empty( $search_cols ) ) {
				$where = '(' . implode( ' OR ', $search_cols ) . ')';
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$params[] = $per_page;
		$params[] = $offset;
		$rows_sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A );
		// phpcs:enable

		wp_send_json_success(
			array(
				'rows'  => $rows ?: array(),
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
				'page'  => $page,
			)
		);
	}

	/**
	 * AJAX: Update a record in a custom module.
	 *
	 * @return void
	 */
	public function ajax_update_record(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$slug      = self::get_post_text( 'module_slug' );
		$record_id = self::get_post_int( 'record_id' );
		$module    = self::get_module( $slug );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}

		self::verify_module_access( $module );

		global $wpdb;
		$table = self::get_module_table( $slug );

		$data   = array();
		$format = array();

		foreach ( $module['fields'] as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $field['name'] ] ) ) {
				$data[ $field['name'] ] = self::get_post_text( $field['name'] ) ?: null;
				$format[]               = '%s';
			}
		}

		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'No data to update.', 'vms-plugin' ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $data, array( 'id' => $record_id ), $format, array( '%d' ) );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update record.', 'vms-plugin' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Record updated.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: Delete a record from a custom module.
	 *
	 * @return void
	 */
	public function ajax_delete_record(): void {
		self::verify_ajax( 'vms_guest_nonce', 'read' );

		$slug      = self::get_post_text( 'module_slug' );
		$record_id = self::get_post_int( 'record_id' );
		$module    = self::get_module( $slug );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}

		self::verify_module_access( $module );

		global $wpdb;
		$table = self::get_module_table( $slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, array( 'id' => $record_id ), array( '%d' ) );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete record.', 'vms-plugin' ) ) );
		}

		VMS_Audit_Trail::log_delete( VMS_Audit_Trail::CAT_SYSTEM, 'custom_' . $slug, $record_id, array( 'id' => $record_id ) );

		wp_send_json_success( array( 'message' => __( 'Record deleted.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: Export records from a custom module as CSV.
	 *
	 * @return void
	 */
	public function ajax_export_records(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$slug   = self::get_post_text( 'module_slug' );
		$module = self::get_module( $slug );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Module not found.', 'vms-plugin' ) ) );
		}

		self::verify_module_access( $module );

		global $wpdb;
		$table = self::get_module_table( $slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 10000", ARRAY_A );

		$filename = 'vms-' . $slug . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		// Header row.
		$headers = array( 'ID' );
		foreach ( $module['fields'] as $field ) {
			$headers[] = $field['label'];
		}
		$headers[] = 'Created By';
		$headers[] = 'Created At';
		fputcsv( $out, $headers );

		// Data rows.
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$csv_row = array( $row['id'] ?? '' );
				foreach ( $module['fields'] as $field ) {
					$csv_row[] = $row[ $field['name'] ] ?? '';
				}
				$csv_row[] = $row['created_by'] ?? '';
				$csv_row[] = $row['created_at'] ?? '';
				fputcsv( $out, $csv_row );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}
