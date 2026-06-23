<?php
/**
 * Reports and analytics module.
 *
 * Provides summary data, monthly statistics, guest/supplier analytics,
 * and CSV export capabilities for all VMS modules. All reads are cached
 * for performance.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Reports module.
 */
final class VMS_Reports extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_ajax_vms_get_report_data', array( $this, 'ajax_get_report_data' ) );
		add_action( 'wp_ajax_vms_export_report', array( $this, 'ajax_export_report' ) );
	}

	// =====================================================================
	// REPORT DATA METHODS
	// =====================================================================

	/**
	 * Get visit summary across all modules.
	 *
	 * @param string $date_from Start date (Y-m-d).
	 * @param string $date_to   End date (Y-m-d).
	 * @return array
	 */
	public static function get_visit_summary( string $date_from = '', string $date_to = '' ): array {
		$date_from = $date_from ?: gmdate( 'Y-m-01' );
		$date_to   = $date_to ?: current_time( 'Y-m-d' );

		$cache_key = 'reports:visit_summary_' . md5( $date_from . $date_to );

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $date_from, $date_to ) {
				global $wpdb;

				$summary = array(
					'date_from' => $date_from,
					'date_to'   => $date_to,
				);

				// Guest visits.
				$guest_visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$guest_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(*) as total_visits,
							SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_visits,
							SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_visits,
							SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as pending_visits,
							SUM(CASE WHEN sign_in_time IS NOT NULL AND sign_out_time IS NULL THEN 1 ELSE 0 END) as currently_signed_in
						 FROM `{$guest_visits_table}`
						 WHERE visit_date BETWEEN %s AND %s",
						$date_from,
						$date_to
					),
					ARRAY_A
				);
				$summary['guest_visits'] = $guest_row ?: array();

				// Accommodation visits.
				$accom_visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$accom_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(*) as total_visits,
							SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_visits,
							SUM(CASE WHEN sign_in_time IS NOT NULL AND sign_out_time IS NULL THEN 1 ELSE 0 END) as currently_checked_in
						 FROM `{$accom_visits_table}`
						 WHERE check_in_date BETWEEN %s AND %s",
						$date_from,
						$date_to
					),
					ARRAY_A
				);
				$summary['accom_visits'] = $accom_row ?: array();

				// Supplier visits.
				$supplier_visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$supplier_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(*) as total_visits,
							SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_visits,
							SUM(CASE WHEN sign_in_time IS NOT NULL AND sign_out_time IS NULL THEN 1 ELSE 0 END) as currently_signed_in
						 FROM `{$supplier_visits_table}`
						 WHERE visit_date BETWEEN %s AND %s",
						$date_from,
						$date_to
					),
					ARRAY_A
				);
				$summary['supplier_visits'] = $supplier_row ?: array();

				// Reciprocating visits.
				$recip_visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$recip_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(*) as total_visits,
							SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_visits,
							SUM(CASE WHEN sign_in_time IS NOT NULL AND sign_out_time IS NULL THEN 1 ELSE 0 END) as currently_signed_in
						 FROM `{$recip_visits_table}`
						 WHERE visit_date BETWEEN %s AND %s",
						$date_from,
						$date_to
					),
					ARRAY_A
				);
				$summary['recip_visits'] = $recip_row ?: array();

				return $summary;
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Get monthly statistics for a given year.
	 *
	 * @param int $year Year (default: current year).
	 * @return array
	 */
	public static function get_monthly_stats( int $year = 0 ): array {
		$year      = $year ?: (int) current_time( 'Y' );
		$cache_key = "reports:monthly_stats_{$year}";

		return VMS_Cache::cached(
			$cache_key,
			static function () use ( $year ) {
				global $wpdb;

				$guest_visits_table    = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				$supplier_visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
				$accom_visits_table    = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
				$recip_visits_table    = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );

				$months = array();

				for ( $m = 1; $m <= 12; $m++ ) {
					$date_from = sprintf( '%04d-%02d-01', $year, $m );
					$date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$guest_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM `{$guest_visits_table}` WHERE visit_date BETWEEN %s AND %s",
							$date_from,
							$date_to
						)
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$supplier_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM `{$supplier_visits_table}` WHERE visit_date BETWEEN %s AND %s",
							$date_from,
							$date_to
						)
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$accom_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM `{$accom_visits_table}` WHERE check_in_date BETWEEN %s AND %s",
							$date_from,
							$date_to
						)
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$recip_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM `{$recip_visits_table}` WHERE visit_date BETWEEN %s AND %s",
							$date_from,
							$date_to
						)
					);

					$months[] = array(
						'month'          => $m,
						'label'          => gmdate( 'F', mktime( 0, 0, 0, $m, 1, $year ) ),
						'guest_visits'   => $guest_count,
						'supplier_visits' => $supplier_count,
						'accom_visits'   => $accom_count,
						'recip_visits'   => $recip_count,
						'total'          => $guest_count + $supplier_count + $accom_count + $recip_count,
					);
				}

				return array(
					'year'   => $year,
					'months' => $months,
				);
			},
			VMS_Config::CACHE_TTL_MEDIUM
		);
	}

	/**
	 * Get guest statistics.
	 *
	 * @return array
	 */
	public static function get_guest_stats(): array {
		return VMS_Cache::cached(
			'reports:guest_stats',
			static function () {
				global $wpdb;

				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stats = $wpdb->get_row(
					"SELECT
						COUNT(*) as total_guests,
						SUM(CASE WHEN guest_status = 'active' THEN 1 ELSE 0 END) as active_guests,
						SUM(CASE WHEN guest_status = 'suspended' THEN 1 ELSE 0 END) as suspended_guests,
						SUM(CASE WHEN guest_status = 'banned' THEN 1 ELSE 0 END) as banned_guests
					 FROM `{$guests_table}`",
					ARRAY_A
				);

				// Accommodation guests.
				$accom_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$accom_stats = $wpdb->get_row(
					"SELECT
						COUNT(*) as total_accom_guests,
						SUM(CASE WHEN guest_status = 'active' THEN 1 ELSE 0 END) as active_accom_guests
					 FROM `{$accom_table}`",
					ARRAY_A
				);

				return array_merge(
					$stats ?: array(),
					$accom_stats ?: array()
				);
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	/**
	 * Get supplier statistics.
	 *
	 * @return array
	 */
	public static function get_supplier_stats(): array {
		return VMS_Cache::cached(
			'reports:supplier_stats',
			static function () {
				global $wpdb;

				$suppliers_table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stats = $wpdb->get_row(
					"SELECT
						COUNT(*) as total_suppliers,
						SUM(CASE WHEN supplier_status = 'active' THEN 1 ELSE 0 END) as active_suppliers,
						SUM(CASE WHEN supplier_status = 'suspended' THEN 1 ELSE 0 END) as suspended_suppliers,
						SUM(CASE WHEN supplier_status = 'banned' THEN 1 ELSE 0 END) as banned_suppliers
					 FROM `{$suppliers_table}`",
					ARRAY_A
				);

				return $stats ?: array();
			},
			VMS_Config::CACHE_TTL_SHORT
		);
	}

	// =====================================================================
	// AJAX HANDLERS
	// =====================================================================

	/**
	 * AJAX: get report data.
	 *
	 * @return void
	 */
	public function ajax_get_report_data(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_VIEW_REPORTS );

		$report_type = self::get_post_text( 'report_type' );
		$date_from   = self::get_post_text( 'date_from' );
		$date_to     = self::get_post_text( 'date_to' );

		$data = match ( $report_type ) {
			'visit_summary'  => self::get_visit_summary( $date_from, $date_to ),
			'monthly_stats'  => self::get_monthly_stats( self::get_post_int( 'year' ) ),
			'guest_stats'    => self::get_guest_stats(),
			'supplier_stats' => self::get_supplier_stats(),
			default          => array(
				'visit_summary'  => self::get_visit_summary( $date_from, $date_to ),
				'guest_stats'    => self::get_guest_stats(),
				'supplier_stats' => self::get_supplier_stats(),
			),
		};

		wp_send_json_success( array( 'report' => $data ) );
	}

	/**
	 * AJAX: export report as CSV.
	 *
	 * @return void
	 */
	public function ajax_export_report(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$report_type = self::get_post_text( 'report_type' );
		$date_from   = self::get_post_text( 'date_from' );
		$date_to     = self::get_post_text( 'date_to' );

		$rows     = self::get_export_rows( $report_type, $date_from, $date_to );
		$filename = 'vms-' . sanitize_file_name( $report_type ) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		if ( ! empty( $rows ) ) {
			// CSV header from first row keys.
			fputcsv( $out, array_keys( $rows[0] ) );

			foreach ( $rows as $row ) {
				fputcsv( $out, array_values( $row ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	// =====================================================================
	// HELPERS
	// =====================================================================

	/**
	 * Get export rows based on report type.
	 *
	 * @param string $report_type Report type identifier.
	 * @param string $date_from   Start date (Y-m-d).
	 * @param string $date_to     End date (Y-m-d).
	 * @return array
	 */
	private static function get_export_rows( string $report_type, string $date_from = '', string $date_to = '' ): array {
		global $wpdb;

		$date_from = $date_from ?: gmdate( 'Y-m-01' );
		$date_to   = $date_to ?: current_time( 'Y-m-d' );

		switch ( $report_type ) {
			case 'guest_visits':
				$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.id, g.first_name, g.last_name, g.phone_number, v.visit_date, v.status, v.sign_in_time, v.sign_out_time
						 FROM `{$visits_table}` v
						 INNER JOIN `{$guests_table}` g ON g.id = v.guest_id
						 WHERE v.visit_date BETWEEN %s AND %s
						 ORDER BY v.visit_date DESC",
						$date_from,
						$date_to
					),
					ARRAY_A
				) ?: array();

			case 'supplier_visits':
				$visits_table    = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIER_VISITS );
				$suppliers_table = VMS_Config::get_table_name( VMS_Config::TABLE_SUPPLIERS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.id, s.company_name, s.contact_first_name, s.contact_last_name, v.visit_date, v.purpose, v.status, v.sign_in_time, v.sign_out_time
						 FROM `{$visits_table}` v
						 INNER JOIN `{$suppliers_table}` s ON s.id = v.supplier_id
						 WHERE v.visit_date BETWEEN %s AND %s
						 ORDER BY v.visit_date DESC",
						$date_from,
						$date_to
					),
					ARRAY_A
				) ?: array();

			case 'accom_visits':
				$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_VISITS );
				$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_ACCOM_GUESTS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.id, g.first_name, g.last_name, g.phone_number, v.check_in_date, v.check_out_date, v.room_number, v.status, v.sign_in_time, v.sign_out_time
						 FROM `{$visits_table}` v
						 INNER JOIN `{$guests_table}` g ON g.id = v.guest_id
						 WHERE v.check_in_date BETWEEN %s AND %s
						 ORDER BY v.check_in_date DESC",
						$date_from,
						$date_to
					),
					ARRAY_A
				) ?: array();

			case 'recip_visits':
				$visits_table  = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_VISITS );
				$members_table = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_MEMBERS );
				$clubs_table   = VMS_Config::get_table_name( VMS_Config::TABLE_RECIP_CLUBS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.id, c.club_name, m.first_name, m.last_name, m.member_number, v.visit_date, v.status, v.sign_in_time, v.sign_out_time
						 FROM `{$visits_table}` v
						 INNER JOIN `{$members_table}` m ON m.id = v.member_id
						 INNER JOIN `{$clubs_table}` c ON c.id = m.club_id
						 WHERE v.visit_date BETWEEN %s AND %s
						 ORDER BY v.visit_date DESC",
						$date_from,
						$date_to
					),
					ARRAY_A
				) ?: array();

			case 'monthly_stats':
				$stats = self::get_monthly_stats( (int) gmdate( 'Y', strtotime( $date_from ) ) );
				return $stats['months'] ?? array();

			default:
				return array();
		}
	}
}
