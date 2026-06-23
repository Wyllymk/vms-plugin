<?php
/**
 * Plugin uninstall handler.
 *
 * Fired when the user deletes the plugin via the WP admin.
 * Removes ALL plugin data: tables, options, user meta, roles,
 * uploaded files, and cron jobs.
 *
 * IMPORTANT: This file is executed in a minimal WP environment
 * (plugin code is NOT loaded). We must manually load what we need.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

// Exit if not called by WordPress uninstall system.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the "keep data" setting — allow users to uninstall
// without losing data (e.g. before reinstalling a new version).
if ( get_option( 'vms_keep_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;

// ---------------------------------------------------------------------------
// 1. Drop custom tables
// ---------------------------------------------------------------------------

$vms_tables = array(
	'vms_guest_visits',
	'vms_guests',
	'vms_accom_visits',
	'vms_accom_guests',
	'vms_supplier_visits',
	'vms_suppliers',
	'vms_recip_visits',
	'vms_recip_members',
	'vms_recip_clubs',
	'vms_employees',
	'vms_sms_logs',
	'vms_audit_logs',
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

foreach ( $vms_tables as $vms_table ) {
	$vms_full = $wpdb->prefix . $vms_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$vms_full}`" );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

// ---------------------------------------------------------------------------
// 2. Delete all plugin options (prefixed with vms_)
// ---------------------------------------------------------------------------

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'vms\_%'
	)
);

// ---------------------------------------------------------------------------
// 3. Delete all plugin transients
// ---------------------------------------------------------------------------

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'\_transient\_vms\_%',
		'\_transient\_timeout\_vms\_%'
	)
);

// Site transients (multisite).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'\_site\_transient\_vms\_%',
		'\_site\_transient\_timeout\_vms\_%'
	)
);

// ---------------------------------------------------------------------------
// 4. Delete user meta
// ---------------------------------------------------------------------------

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		'vms\_%'
	)
);

// ---------------------------------------------------------------------------
// 5. Remove custom roles & capabilities
// ---------------------------------------------------------------------------

$vms_roles = array( 'member', 'chairman', 'general_manager', 'reception', 'gate' );

foreach ( $vms_roles as $vms_role ) {
	remove_role( $vms_role );
}

// Remove custom caps from administrator.
$vms_admin = get_role( 'administrator' );
if ( $vms_admin ) {
	$vms_caps = array(
		'vms_register_guests',
		'vms_register_courtesy_guests',
		'vms_manage_guests',
		'vms_cancel_visits',
		'vms_signin_guests',
		'vms_signout_guests',
		'vms_manage_accommodation',
		'vms_manage_suppliers',
		'vms_manage_reciprocation',
		'vms_manage_employees',
		'vms_view_reports',
		'vms_export_data',
		'vms_view_audit_logs',
		'vms_manage_settings',
		'vms_approve_members',
	);
	foreach ( $vms_caps as $vms_cap ) {
		$vms_admin->remove_cap( $vms_cap );
	}
}

// ---------------------------------------------------------------------------
// 6. Clear scheduled cron jobs
// ---------------------------------------------------------------------------

$vms_cron_hooks = array(
	'vms_midnight_tasks',
	'vms_auto_signout',
	'vms_monthly_reset',
	'vms_yearly_reset',
	'vms_check_sms_balance',
	'vms_check_sms_delivery',
	'vms_cleanup_old_logs',
);

foreach ( $vms_cron_hooks as $vms_hook ) {
	wp_clear_scheduled_hook( $vms_hook );
}

// ---------------------------------------------------------------------------
// 7. Delete VMS-managed pages
// ---------------------------------------------------------------------------

$vms_page_ids = get_option( 'vms_page_ids', array() );
if ( is_array( $vms_page_ids ) ) {
	foreach ( $vms_page_ids as $vms_page_id ) {
		if ( get_post_meta( $vms_page_id, '_vms_managed', true ) ) {
			wp_delete_post( $vms_page_id, true );
		}
	}
}

// ---------------------------------------------------------------------------
// 8. Remove uploaded files (logo, exports, etc.)
// ---------------------------------------------------------------------------

$vms_upload_dir = wp_upload_dir();
$vms_plugin_dir = trailingslashit( $vms_upload_dir['basedir'] ) . 'vms';

if ( is_dir( $vms_plugin_dir ) ) {
	// Recursive delete.
	$vms_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vms_plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $vms_iterator as $vms_file ) {
		if ( $vms_file->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $vms_file->getRealPath() );
		} else {
			wp_delete_file( $vms_file->getRealPath() );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $vms_plugin_dir );
}

// ---------------------------------------------------------------------------
// 9. Flush object cache & rewrite rules
// ---------------------------------------------------------------------------

wp_cache_flush();
flush_rewrite_rules();
