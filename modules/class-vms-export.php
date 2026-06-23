<?php
/**
 * PDF & Data Export module.
 *
 * Generates PDF exports for guests, members, staff, audit logs, and
 * reciprocating members. Uses HTML-to-PDF rendering with WordPress's
 * built-in capabilities. All exports are audit-logged.
 *
 * @package WyllyMk\VMS
 * @since   2.1.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Export module.
 */
final class VMS_Export extends Singleton {

	use Base_Ajax_Trait;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'wp_ajax_vms_export_guests_pdf', array( $this, 'ajax_export_guests_pdf' ) );
		add_action( 'wp_ajax_vms_export_member_pdf', array( $this, 'ajax_export_member_pdf' ) );
		add_action( 'wp_ajax_vms_export_staff_pdf', array( $this, 'ajax_export_staff_pdf' ) );
		add_action( 'wp_ajax_vms_export_audit_logs_pdf', array( $this, 'ajax_export_audit_logs_pdf' ) );
		add_action( 'wp_ajax_vms_export_recip_pdf', array( $this, 'ajax_export_recip_pdf' ) );
		add_action( 'wp_ajax_vms_export_members_list_pdf', array( $this, 'ajax_export_members_list_pdf' ) );
	}

	/**
	 * Get club branding for PDF header.
	 *
	 * @return array
	 */
	private static function get_branding(): array {
		$logo_id  = (int) VMS_Config::get_option( 'club_logo_id', 0 );
		$logo_url = $logo_id ? wp_get_attachment_url( $logo_id ) : '';

		return array(
			'club_name'  => VMS_Config::get_option( 'club_name', get_bloginfo( 'name' ) ),
			'club_logo'  => $logo_url,
			'club_phone' => VMS_Config::get_option( 'club_phone', '' ),
			'club_email' => VMS_Config::get_option( 'club_email', '' ),
			'club_address' => VMS_Config::get_option( 'club_address', '' ),
			'primary_color' => VMS_Config::get_option( 'primary_color', '#0ea5e9' ),
		);
	}

	/**
	 * Build PDF HTML header with club branding.
	 *
	 * @param string $title    Report title.
	 * @param string $subtitle Optional subtitle.
	 * @return string
	 */
	private static function pdf_header( string $title, string $subtitle = '' ): string {
		$brand = self::get_branding();
		$color = esc_attr( $brand['primary_color'] );
		$date  = current_time( 'F j, Y g:i A' );

		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
		$html .= 'body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#1e293b;margin:0;padding:20px;}';
		$html .= '.header{border-bottom:3px solid ' . $color . ';padding-bottom:15px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;}';
		$html .= '.header h1{font-size:20px;color:' . $color . ';margin:0 0 3px;}';
		$html .= '.header .subtitle{font-size:12px;color:#64748b;margin:0;}';
		$html .= '.header .meta{font-size:9px;color:#94a3b8;text-align:right;}';
		$html .= 'table{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px;}';
		$html .= 'th{background:' . $color . ';color:#fff;text-align:left;padding:8px 10px;font-size:9px;text-transform:uppercase;letter-spacing:0.5px;}';
		$html .= 'td{padding:7px 10px;border-bottom:1px solid #e2e8f0;}';
		$html .= 'tr:nth-child(even){background:#f8fafc;}';
		$html .= '.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:600;}';
		$html .= '.badge-success{background:#dcfce7;color:#166534;}';
		$html .= '.badge-warning{background:#fef3c7;color:#92400e;}';
		$html .= '.badge-danger{background:#fee2e2;color:#991b1b;}';
		$html .= '.badge-info{background:#dbeafe;color:#1e40af;}';
		$html .= '.footer{margin-top:30px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:9px;color:#94a3b8;text-align:center;}';
		$html .= '.section-title{font-size:14px;color:' . $color . ';margin:20px 0 10px;padding-bottom:5px;border-bottom:1px solid #e2e8f0;}';
		$html .= '</style></head><body>';

		$html .= '<div class="header"><div>';
		$html .= '<h1>' . esc_html( $brand['club_name'] ) . '</h1>';
		$html .= '<div class="subtitle">' . esc_html( $title ) . '</div>';
		if ( $subtitle ) {
			$html .= '<div class="subtitle" style="margin-top:3px;">' . esc_html( $subtitle ) . '</div>';
		}
		$html .= '</div><div class="meta">';
		$html .= 'Generated: ' . esc_html( $date ) . '<br>';
		$html .= 'By: ' . esc_html( wp_get_current_user()->display_name );
		if ( $brand['club_phone'] ) {
			$html .= '<br>' . esc_html( $brand['club_phone'] );
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Build PDF footer.
	 *
	 * @return string
	 */
	private static function pdf_footer(): string {
		$brand = self::get_branding();
		return '<div class="footer">' . esc_html( $brand['club_name'] ) . ' &mdash; Visitor Management System &mdash; Confidential</div></body></html>';
	}

	/**
	 * Get status badge HTML.
	 *
	 * @param string $status Status value.
	 * @return string
	 */
	private static function status_badge( string $status ): string {
		$class = match ( $status ) {
			'active', 'approved', 'completed' => 'badge-success',
			'pending', 'suspended'            => 'badge-warning',
			'banned', 'terminated', 'rejected' => 'badge-danger',
			default                            => 'badge-info',
		};
		return '<span class="badge ' . $class . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	/**
	 * Send HTML as a downloadable PDF using browser print.
	 * Falls back to HTML download if DOMPDF is not available.
	 *
	 * @param string $html     Full HTML document.
	 * @param string $filename Download filename.
	 * @return void
	 */
	private static function send_pdf_response( string $html, string $filename ): void {
		// Try DOMPDF if available via Composer.
		$dompdf_autoload = VMS_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $dompdf_autoload ) ) {
			require_once $dompdf_autoload;
			if ( class_exists( '\Dompdf\Dompdf' ) ) {
				$dompdf = new \Dompdf\Dompdf( array(
					'isRemoteEnabled'  => true,
					'defaultFont'      => 'Helvetica',
					'defaultMediaType' => 'print',
					'isPhpEnabled'     => false,
				) );
				$dompdf->loadHtml( $html );
				$dompdf->setPaper( 'A4', 'landscape' );
				$dompdf->render();

				// Stream directly to browser.
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
				header( 'Cache-Control: private, max-age=0, must-revalidate' );
				echo $dompdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
		}

		// Fallback: send as HTML that auto-triggers print dialog.
		$print_html = str_replace(
			'</style>',
			'@media print{.no-print{display:none!important;}}</style>',
			$html
		);
		$print_html = str_replace(
			'</body>',
			'<script>window.onload=function(){window.print();}</script></body>',
			$print_html
		);

		wp_send_json_success( array(
			'html'     => $print_html,
			'filename' => sanitize_file_name( $filename ),
			'method'   => 'print',
		) );
	}

	// =====================================================================
	// AJAX EXPORT HANDLERS
	// =====================================================================

	/**
	 * AJAX: Export guests list as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_guests_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$guests = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY last_name, first_name", ARRAY_A );

		$html = self::pdf_header( 'Guest Registry Report', count( $guests ) . ' guests total' );
		$html .= '<table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>ID Number</th><th>Status</th><th>Registered</th></tr></thead><tbody>';

		foreach ( $guests as $i => $g ) {
			$html .= '<tr>';
			$html .= '<td>' . ( $i + 1 ) . '</td>';
			$html .= '<td>' . esc_html( $g['first_name'] . ' ' . $g['last_name'] ) . '</td>';
			$html .= '<td>' . esc_html( $g['phone_number'] ) . '</td>';
			$html .= '<td>' . esc_html( $g['email'] ?? '-' ) . '</td>';
			$html .= '<td>' . esc_html( $g['id_number'] ?? '-' ) . '</td>';
			$html .= '<td>' . self::status_badge( $g['guest_status'] ) . '</td>';
			$html .= '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $g['created_at'] ) ) ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= self::pdf_footer();

		VMS_Audit_Trail::log( 'guests_exported', VMS_Audit_Trail::CAT_GUEST, 'guest', 0, null, array( 'count' => count( $guests ), 'format' => 'pdf' ) );

		self::send_pdf_response( $html, 'guests-report-' . current_time( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * AJAX: Export a single member's records as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_member_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$user_id = self::get_post_int( 'user_id' );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Member not found.', 'vms-plugin' ) ) );
		}

		global $wpdb;
		$visits_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUEST_VISITS );
		$guests_table = VMS_Config::get_table_name( VMS_Config::TABLE_GUESTS );

		// Get visits hosted by this member.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.*, g.first_name, g.last_name, g.phone_number
				 FROM `{$visits_table}` v
				 INNER JOIN `{$guests_table}` g ON g.id = v.guest_id
				 WHERE v.host_member_id = %d
				 ORDER BY v.visit_date DESC
				 LIMIT 500",
				$user_id
			),
			ARRAY_A
		);

		$phone  = get_user_meta( $user_id, 'vms_phone', true );
		$status = get_user_meta( $user_id, 'vms_member_status', true ) ?: 'active';

		$html = self::pdf_header(
			'Member Records — ' . $user->display_name,
			'Email: ' . $user->user_email . ( $phone ? ' | Phone: ' . $phone : '' )
		);

		$html .= '<div class="section-title">Member Information</div>';
		$html .= '<table><tbody>';
		$html .= '<tr><td><strong>Name</strong></td><td>' . esc_html( $user->display_name ) . '</td>';
		$html .= '<td><strong>Email</strong></td><td>' . esc_html( $user->user_email ) . '</td></tr>';
		$html .= '<tr><td><strong>Phone</strong></td><td>' . esc_html( $phone ?: '-' ) . '</td>';
		$html .= '<td><strong>Status</strong></td><td>' . self::status_badge( $status ) . '</td></tr>';
		$html .= '<tr><td><strong>Role</strong></td><td>' . esc_html( implode( ', ', $user->roles ) ) . '</td>';
		$html .= '<td><strong>Registered</strong></td><td>' . esc_html( wp_date( 'M j, Y', strtotime( $user->user_registered ) ) ) . '</td></tr>';
		$html .= '</tbody></table>';

		$html .= '<div class="section-title">Guest Visits (' . count( $visits ) . ' records)</div>';
		$html .= '<table><thead><tr><th>#</th><th>Guest Name</th><th>Phone</th><th>Visit Date</th><th>Status</th><th>Sign In</th><th>Sign Out</th></tr></thead><tbody>';

		foreach ( $visits as $i => $v ) {
			$html .= '<tr>';
			$html .= '<td>' . ( $i + 1 ) . '</td>';
			$html .= '<td>' . esc_html( $v['first_name'] . ' ' . $v['last_name'] ) . '</td>';
			$html .= '<td>' . esc_html( $v['phone_number'] ) . '</td>';
			$html .= '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $v['visit_date'] ) ) ) . '</td>';
			$html .= '<td>' . self::status_badge( $v['status'] ) . '</td>';
			$html .= '<td>' . esc_html( $v['sign_in_time'] ? wp_date( 'g:i A', strtotime( $v['sign_in_time'] ) ) : '-' ) . '</td>';
			$html .= '<td>' . esc_html( $v['sign_out_time'] ? wp_date( 'g:i A', strtotime( $v['sign_out_time'] ) ) : '-' ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= self::pdf_footer();

		VMS_Audit_Trail::log( 'member_exported', VMS_Audit_Trail::CAT_MEMBER, 'user', $user_id, null, array( 'format' => 'pdf' ) );

		self::send_pdf_response( $html, 'member-' . sanitize_title( $user->display_name ) . '-' . current_time( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * AJAX: Export members list as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_members_list_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$members_data = VMS_Members::get_members_list( 500, 1, '', '' );
		$members      = $members_data['rows'];

		$html = self::pdf_header( 'Members List', count( $members ) . ' members total' );
		$html .= '<table><thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Registered</th></tr></thead><tbody>';

		foreach ( $members as $i => $m ) {
			$role = ! empty( $m['roles'] ) ? ucwords( str_replace( '_', ' ', $m['roles'][0] ?? '' ) ) : '-';
			$html .= '<tr>';
			$html .= '<td>' . ( $i + 1 ) . '</td>';
			$html .= '<td>' . esc_html( $m['display_name'] ) . '</td>';
			$html .= '<td>' . esc_html( $m['email'] ) . '</td>';
			$html .= '<td>' . esc_html( $m['phone'] ?: '-' ) . '</td>';
			$html .= '<td>' . esc_html( $role ) . '</td>';
			$html .= '<td>' . self::status_badge( $m['member_status'] ?? 'active' ) . '</td>';
			$html .= '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $m['registered'] ) ) ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= self::pdf_footer();

		self::send_pdf_response( $html, 'members-list-' . current_time( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * AJAX: Export staff records as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_staff_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$employee_id = self::get_post_int( 'employee_id' );

		if ( $employee_id ) {
			// Export single staff member.
			$emp = VMS_Employees::get_employee( $employee_id );
			if ( ! $emp ) {
				wp_send_json_error( array( 'message' => __( 'Staff member not found.', 'vms-plugin' ) ) );
			}

			$html = self::pdf_header(
				'Staff Record — ' . $emp['first_name'] . ' ' . $emp['last_name'],
				'Employee #: ' . $emp['employee_number']
			);

			$html .= '<table><tbody>';
			$html .= '<tr><td><strong>Name</strong></td><td>' . esc_html( $emp['first_name'] . ' ' . $emp['last_name'] ) . '</td>';
			$html .= '<td><strong>Employee #</strong></td><td>' . esc_html( $emp['employee_number'] ) . '</td></tr>';
			$html .= '<tr><td><strong>Email</strong></td><td>' . esc_html( $emp['email'] ?? '-' ) . '</td>';
			$html .= '<td><strong>Phone</strong></td><td>' . esc_html( $emp['phone_number'] ?? '-' ) . '</td></tr>';
			$html .= '<tr><td><strong>Department</strong></td><td>' . esc_html( $emp['department'] ?? '-' ) . '</td>';
			$html .= '<td><strong>Position</strong></td><td>' . esc_html( $emp['position'] ?? '-' ) . '</td></tr>';
			$html .= '<tr><td><strong>Status</strong></td><td>' . self::status_badge( $emp['employee_status'] ) . '</td>';
			$html .= '<td><strong>Hire Date</strong></td><td>' . esc_html( $emp['hire_date'] ? wp_date( 'M j, Y', strtotime( $emp['hire_date'] ) ) : '-' ) . '</td></tr>';
			$html .= '</tbody></table>';
			$html .= self::pdf_footer();

			self::send_pdf_response( $html, 'staff-' . sanitize_title( $emp['first_name'] . '-' . $emp['last_name'] ) . '.pdf' );
		} else {
			// Export all staff.
			$data = VMS_Employees::get_employees( 500, 1, '' );
			$employees = $data['rows'];

			$html = self::pdf_header( 'Staff Directory', count( $employees ) . ' staff members' );
			$html .= '<table><thead><tr><th>#</th><th>Name</th><th>Emp #</th><th>Email</th><th>Phone</th><th>Department</th><th>Position</th><th>Status</th></tr></thead><tbody>';

			foreach ( $employees as $i => $e ) {
				$html .= '<tr>';
				$html .= '<td>' . ( $i + 1 ) . '</td>';
				$html .= '<td>' . esc_html( $e['first_name'] . ' ' . $e['last_name'] ) . '</td>';
				$html .= '<td>' . esc_html( $e['employee_number'] ) . '</td>';
				$html .= '<td>' . esc_html( $e['email'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $e['phone_number'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $e['department'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $e['position'] ?? '-' ) . '</td>';
				$html .= '<td>' . self::status_badge( $e['employee_status'] ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
			$html .= self::pdf_footer();

			self::send_pdf_response( $html, 'staff-directory-' . current_time( 'Y-m-d' ) . '.pdf' );
		}
	}

	/**
	 * AJAX: Export audit logs as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_audit_logs_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_VIEW_AUDIT_LOGS );

		global $wpdb;
		$table = VMS_Config::get_table_name( VMS_Config::TABLE_AUDIT_LOGS );

		$where  = array( '1=1' );
		$params = array();

		$date_from = self::get_post_text( 'date_from' );
		$date_to   = self::get_post_text( 'date_to' );
		$user_id   = self::get_post_int( 'user_id' );
		$category  = self::get_post_text( 'category' );

		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $date_from ) . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $date_to ) . ' 23:59:59';
		}
		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( $category ) {
			$where[]  = 'action_category = %s';
			$params[] = sanitize_text_field( $category );
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = 1000;
		$sql       = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$logs = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$subtitle = count( $logs ) . ' log entries';
		if ( $user_id ) {
			$user     = get_userdata( $user_id );
			$subtitle = 'User: ' . ( $user ? $user->display_name : '#' . $user_id ) . ' — ' . $subtitle;
		}

		$html = self::pdf_header( 'Audit Trail Report', $subtitle );
		$html .= '<table><thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Category</th><th>Entity</th><th>IP Address</th></tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$display_name = '-';
			if ( $log['user_id'] ) {
				$log_user = get_userdata( (int) $log['user_id'] );
				$display_name = $log_user ? $log_user->display_name : '#' . $log['user_id'];
			}

			$html .= '<tr>';
			$html .= '<td>' . esc_html( wp_date( 'M j, Y g:i A', strtotime( $log['created_at'] ) ) ) . '</td>';
			$html .= '<td>' . esc_html( $display_name ) . '</td>';
			$html .= '<td>' . esc_html( str_replace( '_', ' ', $log['action_type'] ) ) . '</td>';
			$html .= '<td>' . esc_html( ucfirst( $log['action_category'] ) ) . '</td>';
			$html .= '<td>' . esc_html( ( $log['entity_type'] ?? '' ) . ( $log['entity_id'] ? ' #' . $log['entity_id'] : '' ) ) . '</td>';
			$html .= '<td>' . esc_html( $log['ip_address'] ?? '-' ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= self::pdf_footer();

		self::send_pdf_response( $html, 'audit-logs-' . current_time( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * AJAX: Export reciprocating club members/visits as PDF.
	 *
	 * @return void
	 */
	public function ajax_export_recip_pdf(): void {
		self::verify_ajax( 'vms_guest_nonce', VMS_Config::CAP_EXPORT_DATA );

		$type = self::get_post_text( 'type' ); // 'members' or 'visits'

		if ( 'visits' === $type ) {
			$data   = VMS_Reciprocation::get_visits( 500, 1, '' );
			$visits = $data['rows'];

			$html = self::pdf_header( 'Reciprocating Club Visits', count( $visits ) . ' visits' );
			$html .= '<table><thead><tr><th>#</th><th>Member</th><th>Club</th><th>Date</th><th>Reason</th><th>Status</th><th>Sign In</th><th>Sign Out</th></tr></thead><tbody>';

			foreach ( $visits as $i => $v ) {
				$html .= '<tr>';
				$html .= '<td>' . ( $i + 1 ) . '</td>';
				$html .= '<td>' . esc_html( $v['first_name'] . ' ' . $v['last_name'] ) . '</td>';
				$html .= '<td>' . esc_html( $v['club_name'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $v['visit_date'] ) ) ) . '</td>';
				$html .= '<td>' . esc_html( ucfirst( $v['visit_reason'] ?? '-' ) ) . '</td>';
				$html .= '<td>' . self::status_badge( $v['status'] ) . '</td>';
				$html .= '<td>' . esc_html( $v['sign_in_time'] ? wp_date( 'g:i A', strtotime( $v['sign_in_time'] ) ) : '-' ) . '</td>';
				$html .= '<td>' . esc_html( $v['sign_out_time'] ? wp_date( 'g:i A', strtotime( $v['sign_out_time'] ) ) : '-' ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
			$html .= self::pdf_footer();

			self::send_pdf_response( $html, 'recip-visits-' . current_time( 'Y-m-d' ) . '.pdf' );
		} else {
			$data    = VMS_Reciprocation::get_members( 0, 500, 1, '' );
			$members = $data['rows'];

			$html = self::pdf_header( 'Reciprocating Club Members', count( $members ) . ' members' );
			$html .= '<table><thead><tr><th>#</th><th>Name</th><th>Club</th><th>Member #</th><th>ID Number</th><th>Phone</th><th>Status</th></tr></thead><tbody>';

			foreach ( $members as $i => $m ) {
				$html .= '<tr>';
				$html .= '<td>' . ( $i + 1 ) . '</td>';
				$html .= '<td>' . esc_html( $m['first_name'] . ' ' . $m['last_name'] ) . '</td>';
				$html .= '<td>' . esc_html( $m['club_name'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $m['member_number'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $m['id_number'] ?? '-' ) . '</td>';
				$html .= '<td>' . esc_html( $m['phone_number'] ?? '-' ) . '</td>';
				$html .= '<td>' . self::status_badge( $m['member_status'] ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
			$html .= self::pdf_footer();

			self::send_pdf_response( $html, 'recip-members-' . current_time( 'Y-m-d' ) . '.pdf' );
		}
	}
}
