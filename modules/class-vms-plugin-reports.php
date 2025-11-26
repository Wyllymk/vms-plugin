<?php
/**
 * Reports Handler - Processes AJAX requests for reports and analytics
 *
 * This class handles all report generation, data aggregation, and export
 * functionality for the VMS reports dashboard.
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */
namespace WyllyMk\VMS;
use Dompdf\Dompdf;
use Dompdf\Options;
if (!defined('ABSPATH')) {
    exit;
}
class VMS_Reports_Handler extends Base
{
    /** 
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     * @return self
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize the reports handler
     *
     * @since 1.0.0
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_vms_get_reports_data', [self::class, 'get_reports_data']);
        add_action('wp_ajax_vms_export_report_pdf', [self::class, 'export_report_pdf']);
        add_action('wp_ajax_vms_export_section_pdf', [self::class, 'export_section_pdf']);
    }
    
    /**
     * Get comprehensive reports data
     *
     * @since 1.0.0
     * @return void
     */
    public static function get_reports_data(): void
    {
        error_log('[VMS Reports] === Starting get_reports_data ===');
         
        // Security Check
        self::verify_ajax_request();
        error_log('[VMS Reports] Security check passed');
         
        // Permission Check
        if (!current_user_can('administrator') && !current_user_can('reception') &&
        !current_user_can('general_manager') && !current_user_can('chairman')) {
            error_log('[VMS Reports] Permission denied for user: ' . get_current_user_id());
            wp_send_json_error(['message' => 'Unauthorized access']);
        }
        
        // Get and validate dates
        $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : date('Y-m-d', strtotime('-7 days'));
        $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : date('Y-m-d');
        
        if (!self::validate_date($from_date) || !self::validate_date($to_date)) {
            error_log("[VMS Reports] Invalid date format - From: $from_date, To: $to_date");
            wp_send_json_error(['message' => 'Invalid date format']);
        }
        
        error_log("[VMS Reports] Generating reports for date range: $from_date to $to_date");
        
        try {
            // Gather all report data
            $data = [
                'stats' => self::get_statistics($from_date, $to_date),
                'trends' => self::get_trends_data($from_date, $to_date),
                'distribution' => self::get_distribution_data($from_date, $to_date),
                'guests' => self::get_guests_report($from_date, $to_date),
                'accommodation' => self::get_accommodation_report($from_date, $to_date),
                'suppliers' => self::get_suppliers_report($from_date, $to_date),
                'reciprocating' => self::get_reciprocating_report($from_date, $to_date),
            ];
            
            error_log('[VMS Reports] Data generated successfully');
            error_log('[VMS Reports] Stats: ' . json_encode($data['stats']));
            
            wp_send_json_success($data);
        } catch (\Exception $e) {
            error_log('[VMS Reports Error] Exception in get_reports_data: ' . $e->getMessage());
            error_log('[VMS Reports Error] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => 'Failed to generate report data: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Get statistics for all visitor types
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Statistics data
     */
    private static function get_statistics(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting statistics for $from_date to $to_date");
        
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $a_guests_table = VMS_Config::get_table_name(VMS_Config::A_GUESTS_TABLE);
        $a_guest_visits_table = VMS_Config::get_table_name(VMS_Config::A_GUEST_VISITS_TABLE);
        $suppliers_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
        $supplier_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        
        return [
            'guests' => [
                'total' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT g.id) FROM {$guests_table} g
                     INNER JOIN {$guest_visits_table} gv ON g.id = gv.guest_id
                     WHERE gv.visit_date BETWEEN %s AND %s",
                    $from_date, $to_date
                )),
                'visited' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$guest_visits_table}
                     WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                    $from_date, $to_date
                ))
            ],
            'accommodation' => [
                'total' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT ag.id) FROM {$a_guests_table} ag
                     INNER JOIN {$a_guest_visits_table} agv ON ag.id = agv.guest_id
                     WHERE agv.visit_date BETWEEN %s AND %s",
                    $from_date, $to_date
                )),
                'visited' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$a_guest_visits_table}
                     WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                    $from_date, $to_date
                ))
            ],
            'suppliers' => [
                'total' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT s.id) FROM {$suppliers_table} s
                     INNER JOIN {$supplier_visits_table} sv ON s.id = sv.guest_id
                     WHERE sv.visit_date BETWEEN %s AND %s",
                    $from_date, $to_date
                )),
                'visited' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$supplier_visits_table}
                     WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                    $from_date, $to_date
                ))
            ],
            'reciprocating' => [
                'total' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT rm.id) FROM {$recip_members_table} rm
                     INNER JOIN {$recip_visits_table} rv ON rm.id = rv.member_id
                     WHERE rv.visit_date BETWEEN %s AND %s",
                    $from_date, $to_date
                )),
                'visited' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$recip_visits_table}
                     WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                    $from_date, $to_date
                ))
            ]
        ];
    }
    
    /**
     * Get trends data for chart visualization
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Trends data
     */
    private static function get_trends_data(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting trends data");
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $a_guest_visits_table = VMS_Config::get_table_name(VMS_Config::A_GUEST_VISITS_TABLE);
        $supplier_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        
        // Generate date range
        $dates = self::get_date_range($from_date, $to_date);
        $labels = array_map(function($date) {
            return date('M d', strtotime($date));
        }, $dates);
        
        // Get data for each category
        $guests_data = self::get_daily_counts($guest_visits_table, $from_date, $to_date, $dates);
        $accommodation_data = self::get_daily_counts($a_guest_visits_table, $from_date, $to_date, $dates);
        $suppliers_data = self::get_daily_counts($supplier_visits_table, $from_date, $to_date, $dates);
        $reciprocating_data = self::get_daily_counts($recip_visits_table, $from_date, $to_date, $dates, 'member_id');
        
        return [
            'labels' => $labels,
            'guests' => $guests_data,
            'accommodation' => $accommodation_data,
            'suppliers' => $suppliers_data,
            'reciprocating' => $reciprocating_data
        ];
    }
    
    /**
     * Get distribution data for pie chart
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Distribution data
     */
    private static function get_distribution_data(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting distribution data");
        
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $a_guest_visits_table = VMS_Config::get_table_name(VMS_Config::A_GUEST_VISITS_TABLE);
        $supplier_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        
        return [
            'guests' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$guest_visits_table}
                 WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                $from_date, $to_date
            )),
            'accommodation' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$a_guest_visits_table}
                 WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                $from_date, $to_date
            )),
            'suppliers' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$supplier_visits_table}
                 WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                $from_date, $to_date
            )),
            'reciprocating' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$recip_visits_table}
                 WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL",
                $from_date, $to_date
            ))
        ];
    }
    
    /**
     * Get guests report data
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Guests report
     */
    private static function get_guests_report(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting guests report");
        
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                CONCAT(g.first_name, ' ', g.last_name) as name,
                g.phone_number as phone,
                gv.visit_date,
                gv.sign_in_time,
                gv.sign_out_time,
                gv.status
             FROM {$guests_table} g
             INNER JOIN {$guest_visits_table} gv ON g.id = gv.guest_id
             WHERE gv.visit_date BETWEEN %s AND %s
             ORDER BY gv.visit_date DESC, gv.sign_in_time DESC",
            $from_date, $to_date
        ), ARRAY_A);
        
        error_log("[VMS Reports] Found " . count($results) . " guest records");
        
        return $results ?: [];
    }
    
    /**
     * Get accommodation guests report data
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Accommodation report
     */
    private static function get_accommodation_report(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting accommodation report");
        
        $a_guests_table = VMS_Config::get_table_name(VMS_Config::A_GUESTS_TABLE);
        $a_guest_visits_table = VMS_Config::get_table_name(VMS_Config::A_GUEST_VISITS_TABLE);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                CONCAT(ag.first_name, ' ', ag.last_name) as name,
                ag.phone_number as phone,
                agv.visit_date,
                agv.sign_in_time,
                agv.sign_out_time,
                agv.status
             FROM {$a_guests_table} ag
             INNER JOIN {$a_guest_visits_table} agv ON ag.id = agv.guest_id
             WHERE agv.visit_date BETWEEN %s AND %s
             ORDER BY agv.visit_date DESC, agv.sign_in_time DESC",
            $from_date, $to_date
        ), ARRAY_A);
        
        error_log("[VMS Reports] Found " . count($results) . " accommodation records");
        
        return $results ?: [];
    }
    
    /**
     * Get suppliers report data
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Suppliers report
     */
    private static function get_suppliers_report(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting suppliers report");
        
        $suppliers_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
        $supplier_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                CONCAT(s.first_name, ' ', s.last_name) as name,
                s.phone_number as phone,
                sv.visit_date,
                sv.sign_in_time,
                sv.sign_out_time,
                sv.status
             FROM {$suppliers_table} s
             INNER JOIN {$supplier_visits_table} sv ON s.id = sv.guest_id
             WHERE sv.visit_date BETWEEN %s AND %s
             ORDER BY sv.visit_date DESC, sv.sign_in_time DESC",
            $from_date, $to_date
        ), ARRAY_A);
        
        error_log("[VMS Reports] Found " . count($results) . " supplier records");
        
        return $results ?: [];
    }
    
    /**
     * Get reciprocating members report data
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Reciprocating members report
     */
    private static function get_reciprocating_report(string $from_date, string $to_date): array
    {
        global $wpdb;
        
        error_log("[VMS Reports] Getting reciprocating members report");
        
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $recip_clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                CONCAT(rm.first_name, ' ', rm.last_name) as name,
                rc.club_name as club,
                rm.phone_number as phone,
                rv.visit_date,
                rv.sign_in_time,
                rv.sign_out_time,
                rv.status
             FROM {$recip_members_table} rm
             INNER JOIN {$recip_visits_table} rv ON rm.id = rv.member_id
             LEFT JOIN {$recip_clubs_table} rc ON rm.reciprocating_club_id = rc.id
             WHERE rv.visit_date BETWEEN %s AND %s
             ORDER BY rv.visit_date DESC, rv.sign_in_time DESC",
            $from_date, $to_date
        ), ARRAY_A);
        
        error_log("[VMS Reports] Found " . count($results) . " reciprocating member records");
        
        return $results ?: [];
    }
    
    /**
     * Export comprehensive PDF report (all sections combined)
     *
     * @since 1.0.0
     * @return void
     */
    public static function export_report_pdf(): void
    {
        error_log('[VMS PDF Export] === Starting FULL report PDF export ===');
        
        try {
            // Security Check
            self::verify_ajax_request();
            error_log('[VMS PDF Export] Security check passed');
            
            // Permission Check
            if (!current_user_can('administrator') && !current_user_can('reception') &&
                !current_user_can('general_manager') && !current_user_can('chairman')) {
                error_log('[VMS PDF Export] Permission denied');
                self::send_pdf_error('Unauthorized access');
                return;
            }
            
            // Get and validate dates
            $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : date('Y-m-d', strtotime('-7 days'));
            $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : date('Y-m-d');
            
            if (!self::validate_date($from_date) || !self::validate_date($to_date)) {
                error_log("[VMS PDF Export] Invalid dates - From: $from_date, To: $to_date");
                self::send_pdf_error('Invalid date format');
                return;
            }
            
            error_log("[VMS PDF Export] Generating full report for $from_date to $to_date");
            
            // Gather all required data
            $stats = self::get_statistics($from_date, $to_date);
            $guests = self::get_guests_report($from_date, $to_date);
            $accommodation = self::get_accommodation_report($from_date, $to_date);
            $suppliers = self::get_suppliers_report($from_date, $to_date);
            $reciprocating = self::get_reciprocating_report($from_date, $to_date);
            
            error_log('[VMS PDF Export] Data gathered successfully');
            
            // Generate PDF
            $pdf = self::generate_pdf(
                $from_date,
                $to_date,
                'full',
                compact('stats', 'guests', 'accommodation', 'suppliers', 'reciprocating')
            );
            
            // Send PDF to browser
            $filename = "vms-report-{$from_date}-to-{$to_date}.pdf";
            self::send_pdf_download($pdf, $filename);
            
            error_log("[VMS PDF Export] Full report PDF sent successfully: $filename");
            
        } catch (\Exception $e) {
            error_log('[VMS PDF Export Error] Exception: ' . $e->getMessage());
            error_log('[VMS PDF Export Error] Stack trace: ' . $e->getTraceAsString());
            self::send_pdf_error('Failed to generate PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Export section-specific PDF report (single category)
     *
     * @since 1.0.0
     * @return void
     */
    public static function export_section_pdf(): void
    {
        error_log('[VMS PDF Export] === Starting SECTION PDF export ===');
        
        try {
            // Security Check
            self::verify_ajax_request();
            error_log('[VMS PDF Export] Security check passed');
            
            // Permission Check
            if (!current_user_can('administrator') && !current_user_can('reception') &&
                !current_user_can('general_manager') && !current_user_can('chairman')) {
                error_log('[VMS PDF Export] Permission denied');
                self::send_pdf_error('Unauthorized access');
                return;
            }
            
            // Get and validate section
            $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : '';
            if (empty($section)) {
                error_log('[VMS PDF Export] No section specified');
                self::send_pdf_error('Section parameter is required');
                return;
            }
            
            // Get and validate dates
            $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : date('Y-m-d', strtotime('-7 days'));
            $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : date('Y-m-d');         
            
            if (!self::validate_date($from_date) || !self::validate_date($to_date)) {
                error_log("[VMS PDF Export] Invalid dates - From: $from_date, To: $to_date");
                self::send_pdf_error('Invalid date format');
                return;
            }
            
            error_log("[VMS PDF Export] Generating section '$section' report for $from_date to $to_date");
            
            // Get section data and metadata
            $section_info = self::get_section_data($section, $from_date, $to_date);
            
            if (!$section_info) {
                error_log("[VMS PDF Export] Invalid section: $section");
                self::send_pdf_error('Invalid section specified');
                return;
            }
            
            error_log("[VMS PDF Export] Section data gathered: {$section_info['title']}");
            
            // Generate PDF
            $pdf = self::generate_pdf(
                $from_date,
                $to_date,
                'section',
                $section_info
            );
            
            // Send PDF to browser
            $filename = strtolower(str_replace(' ', '-', $section_info['title'])) . "-{$from_date}-to-{$to_date}.pdf";
            self::send_pdf_download($pdf, $filename);
            
            error_log("[VMS PDF Export] Section PDF sent successfully: $filename");
            
        } catch (\Exception $e) {
            error_log('[VMS PDF Export Error] Exception: ' . $e->getMessage());
            error_log('[VMS PDF Export Error] Stack trace: ' . $e->getTraceAsString());
            self::send_pdf_error('Failed to generate PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Get section data based on section type
     *
     * @param string $section Section identifier
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array|false Section info or false if invalid
     */
    private static function get_section_data(string $section, string $from_date, string $to_date)
    {
        $sections = [
            'guests' => [
                'title' => 'Guests Report',
                'data' => self::get_guests_report($from_date, $to_date),
                'show_club' => false
            ],
            'accommodation' => [
                'title' => 'Accommodation Guests Report',
                'data' => self::get_accommodation_report($from_date, $to_date),
                'show_club' => false
            ],
            'suppliers' => [
                'title' => 'Suppliers Report',
                'data' => self::get_suppliers_report($from_date, $to_date),
                'show_club' => false
            ],
            'reciprocating' => [
                'title' => 'Reciprocating Members Report',
                'data' => self::get_reciprocating_report($from_date, $to_date),
                'show_club' => true
            ]
        ];
        
        return $sections[$section] ?? false;
    }
    
    /**
     * Generate PDF using Dompdf
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @param string $type Report type ('full' or 'section')
     * @param array $data Report data
     * @return string PDF content
     */
    private static function generate_pdf(string $from_date, string $to_date, string $type, array $data): string
    {
        error_log("[VMS PDF] Generating $type PDF");
        
        // Generate HTML based on type
        ob_start();
        if ($type === 'full') {
            self::generate_full_pdf_html($from_date, $to_date, $data);
        } else {
            self::generate_section_pdf_html($from_date, $to_date, $data);
        }
        $html = ob_get_clean();
        
        error_log("[VMS PDF] HTML generated, length: " . strlen($html));
        
        // Configure Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', false); // Security: disable remote resources
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        
        // Create Dompdf instance
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        
        error_log("[VMS PDF] Rendering PDF...");
        $dompdf->render();
        
        $output = $dompdf->output();
        error_log("[VMS PDF] PDF rendered successfully, size: " . strlen($output) . " bytes");
        
        return $output;
    }
    
    /**
     * Send PDF download to browser
     *
     * @param string $pdf_content PDF binary content
     * @param string $filename Download filename
     * @return void
     */
    private static function send_pdf_download(string $pdf_content, string $filename): void
    {
        error_log("[VMS PDF] Preparing to send PDF: $filename");
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Verify PDF content
        if (empty($pdf_content)) {
            error_log('[VMS PDF Error] PDF content is empty');
            self::send_pdf_error('Generated PDF is empty');
            return;
        }
        
        // Verify PDF signature
        if (substr($pdf_content, 0, 4) !== '%PDF') {
            error_log('[VMS PDF Error] Invalid PDF signature');
            self::send_pdf_error('Generated file is not a valid PDF');
            return;
        }
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('X-PDF-Status: success'); // Custom header for JavaScript detection
        
        error_log("[VMS PDF] Headers set, sending " . strlen($pdf_content) . " bytes");
        
        // Output PDF
        echo $pdf_content;
        exit;
    }
    
    /**
     * Send PDF error response
     *
     * @param string $message Error message
     * @return void
     */
    private static function send_pdf_error(string $message): void
    {
        error_log("[VMS PDF Error] Sending error response: $message");
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Send JSON error instead of wp_die for better AJAX handling
        header('Content-Type: application/json');
        header('X-PDF-Status: error'); // Custom header for JavaScript detection
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
    
    
    /**
     * Generate HTML for full PDF report (all sections)
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @param array $data Report data containing stats and all section data
     * @return void
     */
    private static function generate_full_pdf_html(string $from_date, string $to_date, array $data): void
    {
        $stats = $data['stats'];
        $guests = $data['guests'];
        $accommodation = $data['accommodation'];
        $suppliers = $data['suppliers'];
        $reciprocating = $data['reciprocating'];

        // Format dates for display
        $from_date = date('F j, Y', strtotime($from_date));
        $to_date = date('F j, Y', strtotime($to_date));

        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';       
        $logo_paths = [
            // File system paths only - remove the URL path
            ABSPATH . 'wp-content/plugins/vms-plugin/assets/logo.png',
            '/home/wylly/dev/vms/wp-content/plugins/vms-plugin/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',
            // Remove the Windows-style paths as they're likely not needed
        ];

        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
            // Normalize path for consistent checking
            $logo_path = str_replace(['\\', '//'], ['/', '/'], $logo_path);
            
            if (file_exists($logo_path) && is_readable($logo_path)) {
                try {
                    $image_data = file_get_contents($logo_path);
                    if ($image_data !== false) {
                        $image_info = getimagesize($logo_path);
                        if ($image_info) {
                            $mime_type = $image_info['mime'];
                            $base64_image = base64_encode($image_data);
                            $logo_html = '<img src="data:' . $mime_type . ';base64,' . $base64_image . '" alt="Nyeri Club" style="width: 120px; height: auto; display: block; margin: 0 auto 10px auto;">';
                            $found_logo = true;
                            error_log("VMS Export: Successfully loaded logo from: " . $logo_path);
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for guest {$guest->id}: " . $e->getMessage());
                }
            } else {
                error_log("VMS Export: Logo not found or not readable: " . $logo_path);
            }
        }
    
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found for guest {$guest->id}. Using fallback text logo.");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
        
        ?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>VMS Report - <?php echo esc_html($from_date); ?> to <?php echo esc_html($to_date); ?></title>
    <style>
    body {
        font-family: Arial, sans-serif;
        font-size: 12px;
        margin: 20px;
        color: #333;
    }

    .logo {
        text-align: center;
    }

    h1 {
        text-align: center;
        color: #2c3e50;
        border-bottom: 2px solid #3498db;
        padding-bottom: 10px;
    }

    h2 {
        color: #34495e;
        border-left: 4px solid #3498db;
        padding-left: 10px;
        margin-top: 30px;
    }

    .report-info {
        text-align: center;
        margin-bottom: 30px;
        font-style: italic;
        color: #7f8c8d;
    }

    .stats {
        display: flex;
        justify-content: space-around;
        margin: 30px 0;
        flex-wrap: wrap;
    }

    .stat-box {
        text-align: center;
        padding: 20px;
        border: 2px solid #3498db;
        border-radius: 8px;
        margin: 10px;
        background: linear-gradient(to bottom, #ffffff, #f8f9fa);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        min-width: 150px;
    }

    .stat-box h3 {
        font-size: 24px;
        color: #3498db;
        margin: 0 0 10px 0;
    }

    .stat-box p {
        margin: 0 0 5px 0;
        font-weight: bold;
    }

    .stat-box small {
        color: #7f8c8d;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    th,
    td {
        border: 1px solid #bdc3c7;
        padding: 12px 8px;
        text-align: left;
        vertical-align: top;
    }

    th {
        background-color: #3498db;
        color: white;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 11px;
    }

    tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    .no-records {
        text-align: center;
        font-style: italic;
        color: #95a5a6;
    }

    @page {
        margin: 20mm;
    }
    </style>
</head>

<body>
    <div class="logo">
        <?php  echo $logo_html ;?>
    </div>
    <h1>Visitor Management System Report</h1>
    <p class="report-info">Report Period: <?php echo esc_html($from_date); ?> to <?php echo esc_html($to_date); ?></p>

    <!-- Statistics Section -->
    <h2>Summary Statistics</h2>
    <div class="stats">
        <div class="stat-box">
            <h3><?php echo esc_html($stats['guests']['total']); ?></h3>
            <p>Total Guests</p>
            <small><?php echo esc_html($stats['guests']['visited']); ?> visited</small>
        </div>
        <div class="stat-box">
            <h3><?php echo esc_html($stats['accommodation']['total']); ?></h3>
            <p>Accommodation Guests</p>
            <small><?php echo esc_html($stats['accommodation']['visited']); ?> visited</small>
        </div>
        <div class="stat-box">
            <h3><?php echo esc_html($stats['suppliers']['total']); ?></h3>
            <p>Suppliers</p>
            <small><?php echo esc_html($stats['suppliers']['visited']); ?> visited</small>
        </div>
        <div class="stat-box">
            <h3><?php echo esc_html($stats['reciprocating']['total']); ?></h3>
            <p>Reciprocating Members</p>
            <small><?php echo esc_html($stats['reciprocating']['visited']); ?> visited</small>
        </div>
    </div>

    <!-- Detailed Reports -->
    <?php self::render_pdf_table('Guests Report', $guests); ?>
    <?php self::render_pdf_table('Accommodation Guests Report', $accommodation); ?>
    <?php self::render_pdf_table('Suppliers Report', $suppliers); ?>
    <?php self::render_pdf_table('Reciprocating Members Report', $reciprocating, true); ?>
</body>

</html>
<?php
    }
    
    /**
     * Generate HTML for section PDF report
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @param array $section_info Section information (title, data, show_club)
     * @return void
     */
    private static function generate_section_pdf_html(string $from_date, string $to_date, array $section_info): void
    {
        $title = $section_info['title'];
        $data = $section_info['data'];
        $show_club = $section_info['show_club'];

        // Format dates for display
        $from_date = date('F j, Y', strtotime($from_date));
        $to_date = date('F j, Y', strtotime($to_date));

        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';       
        $logo_paths = [
            // File system paths only - remove the URL path
            ABSPATH . 'wp-content/plugins/vms-plugin/assets/logo.png',
            '/home/wylly/dev/vms/wp-content/plugins/vms-plugin/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',
            // Remove the Windows-style paths as they're likely not needed
        ];

        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
            // Normalize path for consistent checking
            $logo_path = str_replace(['\\', '//'], ['/', '/'], $logo_path);
            
            if (file_exists($logo_path) && is_readable($logo_path)) {
                try {
                    $image_data = file_get_contents($logo_path);
                    if ($image_data !== false) {
                        $image_info = getimagesize($logo_path);
                        if ($image_info) {
                            $mime_type = $image_info['mime'];
                            $base64_image = base64_encode($image_data);
                            $logo_html = '<img src="data:' . $mime_type . ';base64,' . $base64_image . '" alt="Nyeri Club" style="width: 120px; height: auto; display: block; margin: 0 auto 10px auto;">';
                            $found_logo = true;
                            error_log("VMS Export: Successfully loaded logo from: " . $logo_path);
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for guest {$guest->id}: " . $e->getMessage());
                }
            } else {
                error_log("VMS Export: Logo not found or not readable: " . $logo_path);
            }
        }
    
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found for guest {$guest->id}. Using fallback text logo.");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
        ?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($title); ?> - <?php echo esc_html($from_date); ?> to <?php echo esc_html($to_date); ?>
    </title>
    <style>
    body {
        font-family: Arial, sans-serif;
        font-size: 12px;
        margin: 20px;
        color: #333;
    }

    .logo {
        text-align: center;
    }

    h1 {
        text-align: center;
        color: #2c3e50;
        border-bottom: 2px solid #3498db;
        padding-bottom: 10px;
    }

    .report-info {
        text-align: center;
        margin-bottom: 30px;
        font-style: italic;
        color: #7f8c8d;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    th,
    td {
        border: 1px solid #bdc3c7;
        padding: 12px 8px;
        text-align: left;
        vertical-align: top;
    }

    th {
        background-color: #3498db;
        color: white;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 11px;
    }

    tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    .no-records {
        text-align: center;
        font-style: italic;
        color: #95a5a6;
    }

    @page {
        margin: 20mm;
    }
    </style>
</head>

<body>
    <div class="logo">
        <?php  echo $logo_html ;?>
    </div>
    <h1><?php echo esc_html($title); ?></h1>
    <p class="report-info">Report Period: <?php echo esc_html($from_date); ?> to <?php echo esc_html($to_date); ?></p>
    <?php self::render_pdf_table($title, $data, $show_club); ?>
</body>

</html>
<?php
    }
    
    /**
     * Render table for PDF
     *
     * @param string $title Table title
     * @param array $data Table data
     * @param bool $show_club Show club column
     * @return void
     */
    private static function render_pdf_table(string $title, array $data, bool $show_club = false): void
    {
        ?>
<h2><?php echo esc_html($title); ?></h2>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <?php if ($show_club): ?>
            <th>Club</th>
            <?php endif; ?>
            <th>Phone</th>
            <th>Visit Date</th>
            <th>Sign In</th>
            <th>Sign Out</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($data)): ?>
        <tr>
            <td colspan="<?php echo $show_club ? 7 : 6; ?>" class="no-records">No records found</td>
        </tr>
        <?php else: ?>
        <?php foreach ($data as $row): ?>
        <tr>
            <td><?php echo esc_html($row['name']); ?></td>
            <?php if ($show_club): ?>
            <td><?php echo esc_html($row['club'] ?? '-'); ?></td>
            <?php endif; ?>
            <td><?php echo esc_html($row['phone']); ?></td>
            <td><?php echo esc_html($row['visit_date']); ?></td>
            <td><?php echo esc_html($row['sign_in_time'] ? date('H:i', strtotime($row['sign_in_time'])) : '-'); ?></td>
            <td><?php echo esc_html($row['sign_out_time'] ? date('H:i', strtotime($row['sign_out_time'])) : '-'); ?>
            </td>
            <td><?php echo esc_html(ucfirst($row['status'])); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php
    }
    
    /**
     * Helper: Get daily counts for a visit table
     *
     * @param string $table Table name
     * @param string $from_date Start date
     * @param string $to_date End date
     * @param array $dates Date range array
     * @param string $id_column ID column name
     * @return array Daily counts
     */
    private static function get_daily_counts(
        string $table,
        string $from_date,
        string $to_date,
        array $dates,
        string $id_column = 'guest_id'
    ): array {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(visit_date) as date, COUNT(*) as count
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s AND sign_in_time IS NOT NULL
             GROUP BY DATE(visit_date)",
            $from_date, $to_date
        ), OBJECT_K);
        
        // Fill in missing dates with 0
        $counts = [];
        foreach ($dates as $date) {
            $counts[] = isset($results[$date]) ? (int) $results[$date]->count : 0;
        }
        
        return $counts;
    }
    
    /**
     * Helper: Generate date range array
     *
     * @param string $from_date Start date
     * @param string $to_date End date
     * @return array Date range
     */
    private static function get_date_range(string $from_date, string $to_date): array
    {
        $dates = [];
        $current = strtotime($from_date);
        $end = strtotime($to_date);
        
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }
        
        return $dates;
    }
    
    /**
     * Helper: Validate date format
     *
     * @param string $date Date string
     * @return bool Valid or not
     */
    private static function validate_date(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Verify AJAX request security
     *
     * @return void
     */
    private static function verify_ajax_request(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vms_script_ajax_nonce')) {
            error_log('[VMS Security] Nonce verification failed');
            wp_send_json_error(['message' => __('Security check failed.', 'vms-plugin')]);
        }
        
        // Verify if user is logged in
        if (!is_user_logged_in()) {
            error_log('[VMS Security] User not logged in');
            wp_send_json_error(['message' => __('You must be logged in to perform this action', 'vms-plugin')]);
        }
        
        error_log('[VMS Security] AJAX request verified successfully');
    }
}   