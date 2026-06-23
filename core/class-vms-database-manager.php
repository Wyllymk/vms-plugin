<?php
/**
 * Database schema manager.
 *
 * Creates, upgrades, and drops all custom tables. Schema changes are
 * version-tracked: bump DB_VERSION whenever any table definition changes
 * and dbDelta() will reconcile the differences non-destructively.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Database Manager.
 */
final class VMS_Database_Manager {

	/**
	 * Current schema version. Bump on ANY schema change.
	 */
	private const DB_VERSION = '2.1.0';

	/**
	 * Option key storing the installed schema version.
	 */
	private const VERSION_OPTION = 'vms_db_version';

	/**
	 * Compare installed vs. current schema version and upgrade if needed.
	 *
	 * Safe to call on every page load — performs a cheap string compare
	 * and exits immediately when versions match.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION, '0' );

		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::create_all_tables();
		update_option( self::VERSION_OPTION, self::DB_VERSION, true );

		// Bust all caches after schema change.
		if ( class_exists( VMS_Cache::class ) ) {
			VMS_Cache::instance()->flush_all();
		}
	}

	/**
	 * Create or update all plugin tables via dbDelta().
	 *
	 * dbDelta() is additive & non-destructive: it adds new columns/indexes
	 * but never drops existing data. Safe to run repeatedly.
	 *
	 * @return void
	 */
	public static function create_all_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// Order matters: parent tables before children (FK targets first).
		$definitions = array(
			self::get_guests_schema( $charset ),
			self::get_guest_visits_schema( $charset ),
			self::get_accom_guests_schema( $charset ),
			self::get_accom_visits_schema( $charset ),
			self::get_suppliers_schema( $charset ),
			self::get_supplier_visits_schema( $charset ),
			self::get_recip_clubs_schema( $charset ),
			self::get_recip_members_schema( $charset ),
			self::get_recip_visits_schema( $charset ),
			self::get_employees_schema( $charset ),
			self::get_sms_logs_schema( $charset ),
			self::get_audit_logs_schema( $charset ),
		);

		foreach ( $definitions as $sql ) {
			dbDelta( $sql );
		}

		// dbDelta doesn't handle foreign keys — add them separately.
		self::add_foreign_keys();
	}

	/**
	 * Drop all plugin tables. Destructive — only called from uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_all_tables(): void {
		global $wpdb;

		// Drop in reverse dependency order (children before parents).
		$tables = array(
			VMS_Config::TABLE_GUEST_VISITS,
			VMS_Config::TABLE_GUESTS,
			VMS_Config::TABLE_ACCOM_VISITS,
			VMS_Config::TABLE_ACCOM_GUESTS,
			VMS_Config::TABLE_SUPPLIER_VISITS,
			VMS_Config::TABLE_SUPPLIERS,
			VMS_Config::TABLE_RECIP_VISITS,
			VMS_Config::TABLE_RECIP_MEMBERS,
			VMS_Config::TABLE_RECIP_CLUBS,
			VMS_Config::TABLE_EMPLOYEES,
			VMS_Config::TABLE_SMS_LOGS,
			VMS_Config::TABLE_AUDIT_LOGS,
		);

		// Temporarily disable FK checks to allow arbitrary drop order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		foreach ( $tables as $table ) {
			$full = VMS_Config::get_table_name( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$full}`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		delete_option( self::VERSION_OPTION );
	}

	// ---------------------------------------------------------------------
	// Schema Definitions
	// ---------------------------------------------------------------------

	/**
	 * Guests table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_guests_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(191) DEFAULT NULL,
			phone_number VARCHAR(20) NOT NULL,
			id_number VARCHAR(50) DEFAULT NULL,
			guest_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
			receive_emails TINYINT(1) NOT NULL DEFAULT 0,
			receive_messages TINYINT(1) NOT NULL DEFAULT 0,
			notes TEXT DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_phone (phone_number),
			UNIQUE KEY unique_id_number (id_number),
			KEY idx_status (guest_status),
			KEY idx_email (email),
			KEY idx_created_by (created_by)
		) {$charset};";
	}

	/**
	 * Guest visits table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_guest_visits_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			guest_id BIGINT(20) UNSIGNED NOT NULL,
			host_member_id BIGINT(20) UNSIGNED DEFAULT NULL,
			courtesy VARCHAR(191) DEFAULT NULL,
			visit_date DATE NOT NULL,
			status ENUM('approved','unapproved','cancelled','suspended','banned','completed') NOT NULL DEFAULT 'approved',
			sign_in_time DATETIME DEFAULT NULL,
			sign_out_time DATETIME DEFAULT NULL,
			signed_in_by BIGINT(20) UNSIGNED DEFAULT NULL,
			signed_out_by BIGINT(20) UNSIGNED DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_guest_host_date (guest_id, host_member_id, visit_date),
			KEY idx_guest (guest_id),
			KEY idx_host (host_member_id),
			KEY idx_date (visit_date),
			KEY idx_status (status),
			KEY idx_date_status (visit_date, status)
		) {$charset};";
	}

	/**
	 * Accommodation guests table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_accom_guests_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(191) DEFAULT NULL,
			phone_number VARCHAR(20) NOT NULL,
			id_number VARCHAR(50) DEFAULT NULL,
			guest_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
			receive_emails TINYINT(1) NOT NULL DEFAULT 0,
			receive_messages TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_phone (phone_number),
			UNIQUE KEY unique_id_number (id_number),
			KEY idx_status (guest_status)
		) {$charset};";
	}

	/**
	 * Accommodation visits table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_accom_visits_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			guest_id BIGINT(20) UNSIGNED NOT NULL,
			check_in_date DATE NOT NULL,
			check_out_date DATE DEFAULT NULL,
			room_number VARCHAR(20) DEFAULT NULL,
			status ENUM('approved','unapproved','cancelled','suspended','banned','completed') NOT NULL DEFAULT 'approved',
			sign_in_time DATETIME DEFAULT NULL,
			sign_out_time DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_guest (guest_id),
			KEY idx_checkin (check_in_date),
			KEY idx_status (status)
		) {$charset};";
	}

	/**
	 * Suppliers table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_suppliers_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			company_name VARCHAR(191) NOT NULL,
			contact_first_name VARCHAR(100) NOT NULL,
			contact_last_name VARCHAR(100) NOT NULL,
			email VARCHAR(191) DEFAULT NULL,
			phone_number VARCHAR(20) NOT NULL,
			id_number VARCHAR(50) DEFAULT NULL,
			vehicle_reg VARCHAR(20) DEFAULT NULL,
			supplier_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
			receive_emails TINYINT(1) NOT NULL DEFAULT 0,
			receive_messages TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_phone (phone_number),
			KEY idx_company (company_name),
			KEY idx_status (supplier_status)
		) {$charset};";
	}

	/**
	 * Supplier visits table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_supplier_visits_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			supplier_id BIGINT(20) UNSIGNED NOT NULL,
			visit_date DATE NOT NULL,
			purpose VARCHAR(255) DEFAULT NULL,
			status ENUM('approved','unapproved','cancelled','suspended','banned','completed') NOT NULL DEFAULT 'approved',
			sign_in_time DATETIME DEFAULT NULL,
			sign_out_time DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_supplier (supplier_id),
			KEY idx_date (visit_date),
			KEY idx_status (status)
		) {$charset};";
	}

	/**
	 * Reciprocating clubs table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_recip_clubs_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			club_name VARCHAR(191) NOT NULL,
			club_email VARCHAR(191) DEFAULT NULL,
			club_phone VARCHAR(20) DEFAULT NULL,
			club_website VARCHAR(255) DEFAULT NULL,
			club_address TEXT DEFAULT NULL,
			country VARCHAR(100) DEFAULT NULL,
			is_reciprocating TINYINT(1) NOT NULL DEFAULT 1,
			club_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
			notes TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_club_name (club_name),
			KEY idx_status (club_status),
			KEY idx_recip (is_reciprocating)
		) {$charset};";
	}

	/**
	 * Reciprocating members table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_recip_members_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			club_id BIGINT(20) UNSIGNED NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(191) DEFAULT NULL,
			phone_number VARCHAR(20) DEFAULT NULL,
			id_number VARCHAR(50) NOT NULL,
			member_number VARCHAR(50) DEFAULT NULL,
			member_status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
			receive_emails TINYINT(1) NOT NULL DEFAULT 0,
			receive_messages TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_club_member (club_id, member_number),
			KEY idx_club (club_id),
			KEY idx_id_number (id_number),
			KEY idx_status (member_status)
		) {$charset};";
	}

	/**
	 * Reciprocating member visits table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_recip_visits_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			visit_date DATE NOT NULL,
			visit_reason VARCHAR(50) DEFAULT NULL,
			status ENUM('approved','unapproved','cancelled','suspended','banned','completed') NOT NULL DEFAULT 'approved',
			sign_in_time DATETIME DEFAULT NULL,
			sign_out_time DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_member_date (member_id, visit_date),
			KEY idx_member (member_id),
			KEY idx_date (visit_date),
			KEY idx_status (status)
		) {$charset};";
	}

	/**
	 * Employees table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_employees_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_EMPLOYEES );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			employee_number VARCHAR(50) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(191) DEFAULT NULL,
			phone_number VARCHAR(20) DEFAULT NULL,
			id_number VARCHAR(50) DEFAULT NULL,
			department VARCHAR(100) DEFAULT NULL,
			position VARCHAR(100) DEFAULT NULL,
			employee_status ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
			hire_date DATE DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_emp_number (employee_number),
			KEY idx_wp_user (wp_user_id),
			KEY idx_status (employee_status),
			KEY idx_department (department)
		) {$charset};";
	}

	/**
	 * SMS logs table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_sms_logs_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_SMS_LOGS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			recipient_role VARCHAR(50) DEFAULT NULL,
			provider VARCHAR(50) NOT NULL,
			phone_number VARCHAR(20) NOT NULL,
			message TEXT NOT NULL,
			message_id VARCHAR(191) DEFAULT NULL,
			status ENUM('queued','sent','delivered','failed','expired','undelivered') NOT NULL DEFAULT 'queued',
			cost DECIMAL(10,4) NOT NULL DEFAULT 0,
			error_message TEXT DEFAULT NULL,
			response_data LONGTEXT DEFAULT NULL,
			sent_at DATETIME DEFAULT NULL,
			delivered_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_message_id (message_id),
			KEY idx_status (status),
			KEY idx_phone (phone_number),
			KEY idx_provider (provider),
			KEY idx_created (created_at)
		) {$charset};";
	}

	/**
	 * Audit logs table.
	 *
	 * @param string $charset Charset collate clause.
	 * @return string
	 */
	private static function get_audit_logs_schema( string $charset ): string {
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_role VARCHAR(50) DEFAULT NULL,
			action_type VARCHAR(100) NOT NULL,
			action_category VARCHAR(50) NOT NULL,
			entity_type VARCHAR(50) DEFAULT NULL,
			entity_id BIGINT(20) UNSIGNED DEFAULT NULL,
			old_values LONGTEXT DEFAULT NULL,
			new_values LONGTEXT DEFAULT NULL,
			metadata LONGTEXT DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_action (action_type),
			KEY idx_category (action_category),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_created (created_at)
		) {$charset};";
	}

	// ---------------------------------------------------------------------
	// Foreign Keys
	// ---------------------------------------------------------------------

	/**
	 * Add foreign key constraints (dbDelta doesn't support them).
	 *
	 * Each constraint is added idempotently — we check existence first.
	 *
	 * @return void
	 */
	private static function add_foreign_keys(): void {
		$constraints = array(
			array(
				'table'      => VMS_Config::TABLE_GUEST_VISITS,
				'constraint' => 'fk_visit_guest',
				'column'     => 'guest_id',
				'ref_table'  => VMS_Config::TABLE_GUESTS,
				'ref_column' => 'id',
			),
			array(
				'table'      => VMS_Config::TABLE_ACCOM_VISITS,
				'constraint' => 'fk_accom_visit_guest',
				'column'     => 'guest_id',
				'ref_table'  => VMS_Config::TABLE_ACCOM_GUESTS,
				'ref_column' => 'id',
			),
			array(
				'table'      => VMS_Config::TABLE_SUPPLIER_VISITS,
				'constraint' => 'fk_supplier_visit',
				'column'     => 'supplier_id',
				'ref_table'  => VMS_Config::TABLE_SUPPLIERS,
				'ref_column' => 'id',
			),
			array(
				'table'      => VMS_Config::TABLE_RECIP_MEMBERS,
				'constraint' => 'fk_recip_member_club',
				'column'     => 'club_id',
				'ref_table'  => VMS_Config::TABLE_RECIP_CLUBS,
				'ref_column' => 'id',
			),
			array(
				'table'      => VMS_Config::TABLE_RECIP_VISITS,
				'constraint' => 'fk_recip_visit_member',
				'column'     => 'member_id',
				'ref_table'  => VMS_Config::TABLE_RECIP_MEMBERS,
				'ref_column' => 'id',
			),
		);

		foreach ( $constraints as $c ) {
			self::add_fk_if_missing(
				VMS_Config::get_table_name( $c['table'] ),
				$c['constraint'],
				$c['column'],
				VMS_Config::get_table_name( $c['ref_table'] ),
				$c['ref_column']
			);
		}
	}

	/**
	 * Add a single foreign key constraint if it doesn't already exist.
	 *
	 * @param string $table      Full table name.
	 * @param string $constraint Constraint identifier.
	 * @param string $column     Local column.
	 * @param string $ref_table  Referenced table.
	 * @param string $ref_column Referenced column.
	 * @return void
	 */
	private static function add_fk_if_missing( string $table, string $constraint, string $column, string $ref_table, string $ref_column ): void {
		global $wpdb;

		// Check if constraint already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
				 WHERE CONSTRAINT_SCHEMA = %s
				 AND TABLE_NAME = %s
				 AND CONSTRAINT_NAME = %s
				 AND CONSTRAINT_TYPE = %s',
				DB_NAME,
				$table,
				$constraint,
				'FOREIGN KEY'
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// Constraint names, table names & column names cannot be bound as
		// params — they come from our constants, not user input.
		$sql = sprintf(
			'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE CASCADE ON UPDATE CASCADE',
			$table,
			$constraint,
			$column,
			$ref_table,
			$ref_column
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Table constant (without prefix).
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;
		$full = VMS_Config::get_table_name( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
	}
}
