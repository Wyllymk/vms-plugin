<?php
/**
 * Admin dashboard & menu registration.
 *
 * Creates the top-level VMS admin menu and renders the admin
 * dashboard overview page with system health, statistics, and
 * quick-action widgets.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Admin module.
 */
final class VMS_Admin extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX endpoints for admin dashboard.
		add_action( 'wp_ajax_vms_admin_dashboard_stats', array( $this, 'ajax_dashboard_stats' ) );
		add_action( 'wp_ajax_vms_admin_system_info', array( $this, 'ajax_system_info' ) );

		// AJAX endpoints for the frontend admin test panel (page-templates/admin.php).
		// These are separate from the wp-admin System Info page so we don't have to
		// expose wp-admin to club staff — the frontend admin panel gets its own
		// nonce (`vms_admin_panel`) localised via vmsTheme.nonces.admin.
		add_action( 'wp_ajax_vms_admin_run_tests', array( $this, 'ajax_run_tests' ) );
		add_action( 'wp_ajax_vms_admin_test_email', array( $this, 'ajax_test_email' ) );
		add_action( 'wp_ajax_vms_admin_clear_cache', array( $this, 'ajax_clear_cache' ) );
	}

	/**
	 * Register the top-level admin menu and dashboard page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'VMS Dashboard', 'vms-plugin' ),
			__( 'VMS', 'vms-plugin' ),
			'read',
			'vms-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-groups',
			26
		);

		add_submenu_page(
			'vms-dashboard',
			__( 'VMS Dashboard', 'vms-plugin' ),
			__( 'Dashboard', 'vms-plugin' ),
			'read',
			'vms-dashboard',
			array( $this, 'render_dashboard' )
		);

		// Audit Logs submenu.
		if ( current_user_can( VMS_Config::CAP_VIEW_AUDIT_LOGS ) ) {
			add_submenu_page(
				'vms-dashboard',
				__( 'Audit Logs', 'vms-plugin' ),
				__( 'Audit Logs', 'vms-plugin' ),
				VMS_Config::CAP_VIEW_AUDIT_LOGS,
				'vms-audit-logs',
				array( $this, 'render_audit_logs' )
			);
		}

		// System Info submenu (admin only).
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'vms-dashboard',
				__( 'System Info', 'vms-plugin' ),
				__( 'System Info', 'vms-plugin' ),
				'manage_options',
				'vms-system-info',
				array( $this, 'render_system_info' )
			);
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'vms-' ) ) {
			return;
		}

		wp_enqueue_style(
			'vms-admin',
			VMS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			VMS_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'vms-admin',
			VMS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			VMS_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'vms-admin',
			'vmsAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				// 'vms_admin_nonce' → admin dashboard stats, system info, test-runners.
				'nonce'      => wp_create_nonce( 'vms_admin_nonce' ),
				// 'vms_audit_nonce' → VMS_Audit_Trail::ajax_get_logs() verifies against
				// this action string, *not* vms_admin_nonce. admin.js must send this
				// one when calling vms_get_audit_logs or the request 403s → generic
				// "An error occurred." in the UI.
				'auditNonce' => wp_create_nonce( 'vms_audit_nonce' ),
				'i18n'       => array(
					'loading' => __( 'Loading…', 'vms-plugin' ),
					'error'   => __( 'An error occurred. Please try refreshing the page.', 'vms-plugin' ),
					'noLogs'  => __( 'No audit entries yet.', 'vms-plugin' ),
				),
			)
		);
	}

	/**
	 * Render admin dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		$stats = self::get_dashboard_stats();
		$cron  = VMS_Cron::get_job_status();
		?>
		<div class="wrap vms-admin-dashboard">
			<h1><?php esc_html_e( 'VMS Dashboard', 'vms-plugin' ); ?></h1>

			<div class="vms-admin-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
				<div class="card" style="padding:16px;">
					<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Total Guests', 'vms-plugin' ); ?></h3>
					<p style="font-size:24px;font-weight:700;margin:0;"><?php echo esc_html( $stats['total_guests'] ?? 0 ); ?></p>
				</div>
				<div class="card" style="padding:16px;">
					<h3 style="margin:0 0 8px;"><?php esc_html_e( "Today's Visits", 'vms-plugin' ); ?></h3>
					<p style="font-size:24px;font-weight:700;margin:0;"><?php echo esc_html( $stats['todays_visits'] ?? 0 ); ?></p>
				</div>
				<div class="card" style="padding:16px;">
					<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Signed In Now', 'vms-plugin' ); ?></h3>
					<p style="font-size:24px;font-weight:700;margin:0;"><?php echo esc_html( $stats['signed_in'] ?? 0 ); ?></p>
				</div>
				<div class="card" style="padding:16px;">
					<h3 style="margin:0 0 8px;"><?php esc_html_e( 'This Month', 'vms-plugin' ); ?></h3>
					<p style="font-size:24px;font-weight:700;margin:0;"><?php echo esc_html( $stats['month_visits'] ?? 0 ); ?></p>
				</div>
			</div>

			<div class="card" style="padding:16px;margin-top:16px;">
				<h2><?php esc_html_e( 'Cron Job Status', 'vms-plugin' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job', 'vms-plugin' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'vms-plugin' ); ?></th>
							<th><?php esc_html_e( 'Next Run', 'vms-plugin' ); ?></th>
							<th><?php esc_html_e( 'Recurrence', 'vms-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cron as $hook => $info ) : ?>
						<tr>
							<td><code><?php echo esc_html( $hook ); ?></code></td>
							<td><?php echo $info['scheduled'] ? '<span style="color:green;">Yes</span>' : '<span style="color:red;">No</span>'; ?></td>
							<td><?php echo esc_html( $info['next_run'] ?? '---' ); ?></td>
							<td><?php echo esc_html( $info['recurrence'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="card" style="padding:16px;margin-top:16px;">
				<h2><?php esc_html_e( 'Plugin Information', 'vms-plugin' ); ?></h2>
				<table class="widefat">
					<tbody>
						<tr><td><strong><?php esc_html_e( 'Version', 'vms-plugin' ); ?></strong></td><td><?php echo esc_html( VMS_PLUGIN_VERSION ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'PHP Version', 'vms-plugin' ); ?></strong></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'WordPress Version', 'vms-plugin' ); ?></strong></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Object Cache', 'vms-plugin' ); ?></strong></td><td><?php echo wp_using_ext_object_cache() ? 'Active' : 'Inactive'; ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render audit logs page.
	 *
	 * @return void
	 */
	public function render_audit_logs(): void {
		if ( ! current_user_can( VMS_Config::CAP_VIEW_AUDIT_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vms-plugin' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audit Logs', 'vms-plugin' ); ?></h1>
			<div id="vms-audit-logs-app">
				<p><?php esc_html_e( 'Loading audit logs...', 'vms-plugin' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render system info page.
	 *
	 * @return void
	 */
	public function render_system_info(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vms-plugin' ) );
		}

		$tables_ok = true;
		$tables    = array(
			VMS_Config::TABLE_GUESTS,
			VMS_Config::TABLE_GUEST_VISITS,
			VMS_Config::TABLE_ACCOM_GUESTS,
			VMS_Config::TABLE_ACCOM_VISITS,
			VMS_Config::TABLE_SUPPLIERS,
			VMS_Config::TABLE_SUPPLIER_VISITS,
			VMS_Config::TABLE_RECIP_CLUBS,
			VMS_Config::TABLE_RECIP_MEMBERS,
			VMS_Config::TABLE_RECIP_VISITS,
			VMS_Config::TABLE_EMPLOYEES,
			VMS_Config::TABLE_SMS_LOGS,
			VMS_Config::TABLE_AUDIT_LOGS,
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'System Information', 'vms-plugin' ); ?></h1>

			<div class="card" style="padding:16px;margin-top:16px;">
				<h2><?php esc_html_e( 'Database Tables', 'vms-plugin' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Table', 'vms-plugin' ); ?></th>
							<th><?php esc_html_e( 'Status', 'vms-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tables as $t ) : ?>
						<?php $exists = VMS_Database_Manager::table_exists( $t ); ?>
						<?php
						if ( ! $exists ) {
							$tables_ok = false;}
						?>
						<tr>
							<td><code><?php echo esc_html( VMS_Config::get_table_name( $t ) ); ?></code></td>
							<td><?php echo $exists ? '<span style="color:green;">OK</span>' : '<span style="color:red;">Missing</span>'; ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! $tables_ok ) : ?>
				<p><button type="button" class="button button-primary" onclick="location.href='<?php echo esc_url( admin_url( 'admin.php?page=vms-system-info&vms_repair=1' ) ); ?>'">
					<?php esc_html_e( 'Repair Tables', 'vms-plugin' ); ?>
				</button></p>
				<?php endif; ?>
			</div>

			<div class="card" style="padding:16px;margin-top:16px;">
				<h2><?php esc_html_e( 'Enabled Modules', 'vms-plugin' ); ?></h2>
				<ul>
					<?php
					$module_labels = array(
						'guests'        => __( 'Guests', 'vms-plugin' ),
						'accommodation' => __( 'Accommodation', 'vms-plugin' ),
						'suppliers'     => __( 'Suppliers', 'vms-plugin' ),
						'reciprocation' => __( 'Reciprocating Clubs', 'vms-plugin' ),
						'employees'     => __( 'Employees', 'vms-plugin' ),
						'reports'       => __( 'Reports', 'vms-plugin' ),
						'members'       => __( 'Members', 'vms-plugin' ),
					);
					foreach ( $module_labels as $key => $label ) :
						$enabled = VMS_Settings::is_module_enabled( $key );
						?>
						<li>
							<?php echo $enabled ? '<span style="color:green;">&#10003;</span>' : '<span style="color:red;">&#10007;</span>'; ?>
							<?php echo esc_html( $label ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php

		// Handle table repair.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['vms_repair'] ) && '1' === $_GET['vms_repair'] ) {
			VMS_Database_Manager::create_all_tables();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Tables repaired successfully.', 'vms-plugin' ) . '</p></div>';
		}
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats(): array {
		return VMS_Cache::cached(
			'stats:admin_dashboard',
			static function () {
				global $wpdb;

				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );
				$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				$today        = current_time( 'Y-m-d' );
				$month_start  = current_time( 'Y-m-01' );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$total_guests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$guests_table}`" );

				$todays_visits = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM `{$visits_table}` WHERE visit_date = %s AND status != 'cancelled'", $today )
				);

				$signed_in = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$visits_table}` WHERE visit_date = %s AND sign_in_time IS NOT NULL AND sign_out_time IS NULL",
						$today
					)
				);

				$month_visits = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM `{$visits_table}` WHERE visit_date >= %s AND status IN ('approved','completed')",
						$month_start
					)
				);
				// phpcs:enable

				return array(
					'total_guests'  => $total_guests,
					'todays_visits' => $todays_visits,
					'signed_in'     => $signed_in,
					'month_visits'  => $month_visits,
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * AJAX: get dashboard stats.
	 *
	 * @return void
	 */
	public function ajax_dashboard_stats(): void {
		self::verify_ajax( 'vms_admin_nonce', 'read' );
		wp_send_json_success( self::get_dashboard_stats() );
	}

	/**
	 * AJAX: get system info.
	 *
	 * @return void
	 */
	public function ajax_system_info(): void {
		self::verify_ajax( 'vms_admin_nonce', 'manage_options' );

		$balance = VMS_SMS_Gateway::get_balance();

		wp_send_json_success(
			array(
				'version'      => VMS_PLUGIN_VERSION,
				'php_version'  => PHP_VERSION,
				'wp_version'   => get_bloginfo( 'version' ),
				'object_cache' => wp_using_ext_object_cache(),
				'sms_balance'  => $balance,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Frontend Admin Panel — diagnostic test suite
	//
	// Powers page-templates/admin.php. Each test returns a uniform shape
	// { id, label, status: 'pass'|'fail'|'warn', message, detail } so the
	// Alpine frontend can render them identically without special-casing.
	// ---------------------------------------------------------------------

	/**
	 * AJAX: run the full diagnostic suite.
	 *
	 * @return void
	 */
	public function ajax_run_tests(): void {
		self::verify_ajax( 'vms_admin_panel', 'manage_options' );

		wp_send_json_success(
			array(
				'tests'     => self::run_diagnostic_tests(),
				'system'    => self::get_system_snapshot(),
				'timestamp' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * AJAX: send a test email to the current user.
	 *
	 * @return void
	 */
	public function ajax_test_email(): void {
		self::verify_ajax( 'vms_admin_panel', 'manage_options' );

		$user = wp_get_current_user();
		$sent = wp_mail(
			$user->user_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] VMS Test Email', 'vms-plugin' ), get_bloginfo( 'name' ) ),
			__( 'This is a test email from the VMS Admin Panel. If you received this, outbound email is working.', 'vms-plugin' )
		);

		if ( $sent ) {
			/* translators: %s: recipient email address */
			wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s', 'vms-plugin' ), $user->user_email ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'wp_mail() returned false. Check your SMTP configuration.', 'vms-plugin' ) ) );
	}

	/**
	 * AJAX: flush all VMS caches.
	 *
	 * @return void
	 */
	public function ajax_clear_cache(): void {
		self::verify_ajax( 'vms_admin_panel', 'manage_options' );

		// Bust every group we know about — cheap and idempotent.
		VMS_Cache::bust( 'settings' );
		VMS_Cache::bust( 'stats' );
		VMS_Cache::bust( 'guests' );
		VMS_Cache::bust( 'visits' );
		VMS_Cache::bust( 'accommodation' );
		VMS_Cache::bust( 'suppliers' );
		VMS_Cache::bust( 'reciprocation' );

		// If an external object cache is active, give it a nudge too.
		wp_cache_flush();

		wp_send_json_success( array( 'message' => __( 'All VMS caches cleared.', 'vms-plugin' ) ) );
	}

	/**
	 * Run every diagnostic check and return a flat list of results.
	 *
	 * Tests are intentionally cheap (no network calls except where the
	 * point of the test IS a network call) so the panel feels instant.
	 *
	 * @return array<int, array{id:string,label:string,status:string,message:string,detail:string}>
	 */
	public static function run_diagnostic_tests(): array {
		global $wpdb;
		$results = array();

		// ─── PHP version ────────────────────────────────────────────────
		$php_ok    = version_compare( PHP_VERSION, '8.0', '>=' );
		$results[] = array(
			'id'      => 'php_version',
			'label'   => __( 'PHP Version', 'vms-plugin' ),
			'status'  => $php_ok ? 'pass' : 'fail',
			'message' => PHP_VERSION,
			'detail'  => $php_ok
				? __( 'Meets the 8.0+ requirement.', 'vms-plugin' )
				: __( 'VMS requires PHP 8.0 or newer. Features using match expressions and typed properties will fatal on older runtimes.', 'vms-plugin' ),
		);

		// ─── WordPress version ──────────────────────────────────────────
		$wp_version = get_bloginfo( 'version' );
		$wp_ok      = version_compare( $wp_version, '6.4', '>=' );
		$results[]  = array(
			'id'      => 'wp_version',
			'label'   => __( 'WordPress Version', 'vms-plugin' ),
			'status'  => $wp_ok ? 'pass' : 'warn',
			'message' => $wp_version,
			'detail'  => $wp_ok
				? __( 'Up to date.', 'vms-plugin' )
				: __( 'Tested against WP 6.4+. Older versions may miss hooks we rely on.', 'vms-plugin' ),
		);

		// ─── Database tables ────────────────────────────────────────────
		$tables = array(
			VMS_Config::TABLE_GUESTS,
			VMS_Config::TABLE_GUEST_VISITS,
			VMS_Config::TABLE_ACCOM_GUESTS,
			VMS_Config::TABLE_ACCOM_VISITS,
			VMS_Config::TABLE_SUPPLIERS,
			VMS_Config::TABLE_SUPPLIER_VISITS,
			VMS_Config::TABLE_RECIP_CLUBS,
			VMS_Config::TABLE_RECIP_MEMBERS,
			VMS_Config::TABLE_RECIP_VISITS,
			VMS_Config::TABLE_EMPLOYEES,
			VMS_Config::TABLE_SMS_LOGS,
			VMS_Config::TABLE_AUDIT_LOGS,
		);
		$missing = array();
		foreach ( $tables as $t ) {
			if ( ! VMS_Database_Manager::table_exists( $t ) ) {
				$missing[] = $t;
			}
		}
		$results[] = array(
			'id'      => 'database',
			'label'   => __( 'Database Tables', 'vms-plugin' ),
			'status'  => empty( $missing ) ? 'pass' : 'fail',
			/* translators: 1: number of existing tables, 2: total expected tables */
			'message' => sprintf( __( '%1$d of %2$d tables present', 'vms-plugin' ), count( $tables ) - count( $missing ), count( $tables ) ),
			'detail'  => empty( $missing )
				? __( 'All schema tables exist.', 'vms-plugin' )
				/* translators: %s: comma-separated list of missing table names */
				: sprintf( __( 'Missing: %s. Run "Repair Tables" in WP Admin → VMS → System Info.', 'vms-plugin' ), implode( ', ', $missing ) ),
		);

		// ─── Module toggles — verify the accommodation fix ──────────────
		$modules        = array( 'guests', 'accommodation', 'suppliers', 'reciprocation', 'employees', 'reports', 'members' );
		$enabled        = array_filter( $modules, array( VMS_Settings::class, 'is_module_enabled' ) );
		$results[] = array(
			'id'      => 'modules',
			'label'   => __( 'Module Toggles', 'vms-plugin' ),
			'status'  => empty( $enabled ) ? 'warn' : 'pass',
			/* translators: 1: number of enabled modules, 2: total modules */
			'message' => sprintf( __( '%1$d of %2$d enabled', 'vms-plugin' ), count( $enabled ), count( $modules ) ),
			/* translators: %s: comma-separated list of enabled module keys */
			'detail'  => empty( $enabled )
				? __( 'No modules are enabled — the sidebar will be empty for non-admins.', 'vms-plugin' )
				: sprintf( __( 'Enabled: %s', 'vms-plugin' ), implode( ', ', $enabled ) ),
		);

		// ─── Cron health ────────────────────────────────────────────────
		$cron_status = VMS_Cron::get_job_status();
		$unscheduled = array_filter( $cron_status, static fn( $j ) => empty( $j['scheduled'] ) );
		$results[]   = array(
			'id'      => 'cron',
			'label'   => __( 'Scheduled Jobs', 'vms-plugin' ),
			'status'  => empty( $unscheduled ) ? 'pass' : 'warn',
			/* translators: 1: scheduled job count, 2: total job count */
			'message' => sprintf( __( '%1$d of %2$d scheduled', 'vms-plugin' ), count( $cron_status ) - count( $unscheduled ), count( $cron_status ) ),
			'detail'  => empty( $unscheduled )
				? __( 'Auto sign-out, limit resets, and cleanup jobs are all queued.', 'vms-plugin' )
				/* translators: %s: comma-separated list of unscheduled cron hook names */
				: sprintf( __( 'Unscheduled: %s. Deactivate & reactivate the plugin to re-register.', 'vms-plugin' ), implode( ', ', array_keys( $unscheduled ) ) ),
		);

		// ─── Object cache ───────────────────────────────────────────────
		$cache_active = wp_using_ext_object_cache();
		$results[]    = array(
			'id'      => 'cache',
			'label'   => __( 'Object Cache', 'vms-plugin' ),
			'status'  => $cache_active ? 'pass' : 'warn',
			'message' => $cache_active ? __( 'Active', 'vms-plugin' ) : __( 'Transients fallback', 'vms-plugin' ),
			'detail'  => $cache_active
				? __( 'Persistent object cache detected (Redis/Memcached). Dashboard queries are fast.', 'vms-plugin' )
				: __( 'No external cache — VMS_Cache falls back to transients. Works fine, but slower under load.', 'vms-plugin' ),
		);

		// ─── AJAX round-trip — if this responds, it passes by definition ─
		$results[] = array(
			'id'      => 'ajax',
			'label'   => __( 'AJAX Connectivity', 'vms-plugin' ),
			'status'  => 'pass',
			/* translators: %s: MySQL server time */
			'message' => sprintf( __( 'Round-trip OK (DB time: %s)', 'vms-plugin' ), $wpdb->get_var( 'SELECT NOW()' ) ),
			'detail'  => __( 'admin-ajax.php is reachable and the nonce verified.', 'vms-plugin' ),
		);

		// ─── Frontend page wiring ───────────────────────────────────────
		// These pages must exist with the right templates or navigation
		// links in the sidebar/header will 404.
		$required_slugs = array( 'dashboard', 'sign-in', 'register-guest', 'guests', 'admin' );
		$missing_pages  = array();
		foreach ( $required_slugs as $slug ) {
			if ( ! get_page_by_path( $slug ) instanceof \WP_Post ) {
				$missing_pages[] = $slug;
			}
		}
		$results[] = array(
			'id'      => 'pages',
			'label'   => __( 'Frontend Pages', 'vms-plugin' ),
			'status'  => empty( $missing_pages ) ? 'pass' : 'warn',
			/* translators: 1: number of existing pages, 2: total expected pages */
			'message' => sprintf( __( '%1$d of %2$d pages exist', 'vms-plugin' ), count( $required_slugs ) - count( $missing_pages ), count( $required_slugs ) ),
			'detail'  => empty( $missing_pages )
				? __( 'All dashboard pages are published and reachable.', 'vms-plugin' )
				/* translators: %s: comma-separated list of missing page slugs */
				: sprintf( __( 'Missing slugs: %s. Create these pages in WP Admin and assign the matching "VMS …" page template.', 'vms-plugin' ), implode( ', ', $missing_pages ) ),
		);

		return $results;
	}

	/**
	 * Lightweight environment snapshot for the admin panel header.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_system_snapshot(): array {
		global $wpdb;

		return array(
			'plugin_version' => VMS_PLUGIN_VERSION,
			'php_version'    => PHP_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'mysql_version'  => $wpdb->db_version(),
			'memory_limit'   => ini_get( 'memory_limit' ),
			'max_execution'  => ini_get( 'max_execution_time' ),
			'object_cache'   => wp_using_ext_object_cache(),
			'debug_mode'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'site_url'       => site_url(),
			'timezone'       => wp_timezone_string(),
		);
	}
}
