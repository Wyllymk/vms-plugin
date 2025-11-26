<?php
/**
 * VMS Export Handler
 * Handles PDF exports for guest, member, and reciprocating member details
 *
 * @package Visitor_Management_System
 */
namespace WyllyMk\VMS;
use Dompdf\Dompdf;
use Dompdf\Options;

class VMS_Export_Handler {
    /**
     * Export guest details to PDF
     */
    public static function export_guest_pdf($guest_id)
    {
        global $wpdb;
       
        $guests_table = VMS_Config::get_table_name(VMS_Config::GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
       
        // Get guest details
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $guest_id
        ));
       
        if (!$guest) {
            error_log("VMS Export: Guest not found for PDF export ID {$guest_id}");
            wp_die('Guest not found');
        }
       
        // Get all visits
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $guest_visits_table WHERE guest_id = %d ORDER BY visit_date DESC",
            $guest_id
        ));
       
        if ($wpdb->last_error) {
            error_log("VMS Export: Database error fetching visits for PDF guest {$guest_id}: " . $wpdb->last_error);
            wp_die('Error retrieving visit data');
        }
       
        try {
            // Dompdf options
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
           
            // Create Dompdf instance
            $dompdf = new Dompdf($options);
           
            // Build HTML content
            $html = self::build_guest_pdf_html($guest, $visits);
           
            // Load HTML
            $dompdf->loadHtml($html);
           
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
           
            // Render
            $dompdf->render();
           
            // Output
            $guest_name = strtolower(str_replace(' ', '_', trim($guest->first_name . '_' . $guest->last_name)));
            $date_formatted = date('d_m_Y');
            $filename = $guest_name . '_' . $date_formatted . '.pdf';
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (Exception $e) {
            error_log("VMS Export: Dompdf error generating PDF for guest {$guest_id}: " . $e->getMessage());
            wp_die('PDF generation failed');
        }
       
        exit;
    }

    /**
     * Export member details to PDF
     */
    public static function export_member_pdf($user_id)
    {
        global $wpdb;
       
        // Get member details
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            error_log("VMS Export: Member not found for PDF export ID {$user_id}");
            wp_die('Member not found');
        }
       
        $phone_number = get_user_meta($user_id, 'phone_number', true);
        $member_number = get_user_meta($user_id, 'member_number', true);
        $receive_messages = get_user_meta($user_id, 'receive_messages', true) ?: 'no';
        $receive_emails = get_user_meta($user_id, 'receive_emails', true) ?: 'no';
        $registration_status = get_user_meta($user_id, 'registration_status', true) ?: 'pending';
        $current_role = !empty($user_data->roles) ? $user_data->roles[0] : '';
       
        // Get all hosted visits
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::GUEST_VISITS_TABLE);
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $guest_visits_table WHERE host_member_id = %d ORDER BY visit_date DESC",
            $user_id
        ));
       
        if ($wpdb->last_error) {
            error_log("VMS Export: Database error fetching visits for PDF member {$user_id}: " . $wpdb->last_error);
            wp_die('Error retrieving visit data');
        }
       
        try {
            // Dompdf options
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
           
            // Create Dompdf instance
            $dompdf = new Dompdf($options);
           
            // Build HTML content
            $html = self::build_member_pdf_html($user_data, $phone_number, $member_number, $receive_emails, $receive_messages, $registration_status, $current_role, $visits);
           
            // Load HTML
            $dompdf->loadHtml($html);
           
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
           
            // Render
            $dompdf->render();
           
            // Output
            $member_name = strtolower(str_replace(' ', '_', trim($user_data->first_name . '_' . $user_data->last_name)));
            $date_formatted = date('d_m_Y');
            $filename = $member_name . '_' . $date_formatted . '.pdf';
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (Exception $e) {
            error_log("VMS Export: Dompdf error generating PDF for member {$user_id}: " . $e->getMessage());
            wp_die('PDF generation failed');
        }
       
        exit;
    }

    /**
     * Export accommodation guest details to PDF
     */
    public static function export_a_guest_pdf($guest_id)
    {
        global $wpdb;
        
        $guests_table = VMS_Config::get_table_name(VMS_Config::A_GUESTS_TABLE);
        $guest_visits_table = VMS_Config::get_table_name(VMS_Config::A_GUEST_VISITS_TABLE);
        
        // Get guest details
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $guest_id
        ));
        
        if (!$guest) {
            error_log("VMS Export: Accommodation guest not found for PDF export ID {$guest_id}");
            wp_die('Accommodation guest not found');
        }
        
        // Get all visits
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $guest_visits_table WHERE guest_id = %d ORDER BY visit_date DESC",
            $guest_id
        ));
        
        if ($wpdb->last_error) {
            error_log("VMS Export: Database error fetching visits for PDF accommodation guest {$guest_id}: " . $wpdb->last_error);
            wp_die('Error retrieving visit data');
        }
        
        try {
            // Dompdf options
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
            
            // Create Dompdf instance
            $dompdf = new Dompdf($options);
            
            // Build HTML content
            $html = self::build_a_guest_pdf_html($guest, $visits);
            
            // Load HTML
            $dompdf->loadHtml($html);
            
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
            
            // Render
            $dompdf->render();
            
            // Output
            $guest_name = strtolower(str_replace(' ', '_', trim($guest->first_name . '_' . $guest->last_name)));
            $date_formatted = date('d_m_Y');
            $filename = 'accommodation_' . $guest_name . '_' . $date_formatted . '.pdf';
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (Exception $e) {
            error_log("VMS Export: Dompdf error generating PDF for accommodation guest {$guest_id}: " . $e->getMessage());
            wp_die('PDF generation failed');
        }
        
        exit;
    }

    /**
     * Export supplier details to PDF
     */
    public static function export_supplier_pdf($supplier_id)
    {
        global $wpdb;
        
        $suppliers_table = VMS_Config::get_table_name(VMS_Config::SUPPLIERS_TABLE);
        $supplier_visits_table = VMS_Config::get_table_name(VMS_Config::SUPPLIER_VISITS_TABLE);
        
        // Get supplier details
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $suppliers_table WHERE id = %d",
            $supplier_id
        ));
        
        if (!$supplier) {
            error_log("VMS Export: Supplier not found for PDF export ID {$supplier_id}");
            wp_die('Supplier not found');
        }
        
        // Get all visits
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $supplier_visits_table WHERE guest_id = %d ORDER BY visit_date DESC",
            $supplier_id
        ));
        
        if ($wpdb->last_error) {
            error_log("VMS Export: Database error fetching visits for PDF supplier {$supplier_id}: " . $wpdb->last_error);
            wp_die('Error retrieving visit data');
        }
        
        try {
            // Dompdf options
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
            
            // Create Dompdf instance
            $dompdf = new Dompdf($options);
            
            // Build HTML content
            $html = self::build_supplier_pdf_html($supplier, $visits);
            
            // Load HTML
            $dompdf->loadHtml($html);
            
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
            
            // Render
            $dompdf->render();
            
            // Output
            $supplier_name = strtolower(str_replace(' ', '_', trim($supplier->first_name . '_' . $supplier->last_name)));
            $date_formatted = date('d_m_Y');
            $filename = $supplier_name . '_' . $date_formatted . '.pdf';
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (Exception $e) {
            error_log("VMS Export: Dompdf error generating PDF for supplier {$supplier_id}: " . $e->getMessage());
            wp_die('PDF generation failed');
        }
        
        exit;
    }

    /**
     * Export reciprocating member details to PDF
     */
    public static function export_recip_member_pdf($member_id)
    {
        global $wpdb;
       
        $recip_members_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_TABLE);
        $recip_visits_table = VMS_Config::get_table_name(VMS_Config::RECIP_MEMBERS_VISITS_TABLE);
        $recip_clubs_table = VMS_Config::get_table_name(VMS_Config::RECIP_CLUBS_TABLE);
       
        // Get reciprocating member details
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT rm.*, rc.club_name 
             FROM $recip_members_table rm 
             LEFT JOIN $recip_clubs_table rc ON rm.reciprocating_club_id = rc.id 
             WHERE rm.id = %d",
            $member_id
        ));
       
        if (!$member) {
            error_log("VMS Export: Reciprocating member not found for PDF export ID {$member_id}");
            wp_die('Reciprocating member not found');
        }
       
        // Get all visits
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $recip_visits_table WHERE member_id = %d ORDER BY visit_date DESC",
            $member_id
        ));
       
        if ($wpdb->last_error) {
            error_log("VMS Export: Database error fetching visits for PDF recip member {$member_id}: " . $wpdb->last_error);
            wp_die('Error retrieving visit data');
        }
       
        try {
            // Dompdf options
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('isRemoteEnabled', true);
           
            // Create Dompdf instance
            $dompdf = new Dompdf($options);
           
            // Build HTML content
            $html = self::build_recip_member_pdf_html($member, $visits);
           
            // Load HTML
            $dompdf->loadHtml($html);
           
            // Set paper size and orientation
            $dompdf->setPaper('A4', 'portrait');
           
            // Render
            $dompdf->render();
           
            // Output
            $member_name = strtolower(str_replace(' ', '_', trim($member->first_name . '_' . $member->last_name)));
            $date_formatted = date('d_m_Y');
            $filename = $member_name . '_' . $date_formatted . '.pdf';
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (Exception $e) {
            error_log("VMS Export: Dompdf error generating PDF for recip member {$member_id}: " . $e->getMessage());
            wp_die('PDF generation failed');
        }
       
        exit;
    }

    /**
     * Build HTML content for guest PDF
     */
    private static function build_guest_pdf_html($guest, $visits)
    {
        $export_date = date('F j, Y g:i A');
        $total_visits = count($visits);
       
        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';
        $logo_paths = [
            get_template_directory() . '/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',           
            'home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
            '\home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
        ];
       
        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
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
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for guest {$guest->id}: " . $e->getMessage());
                }
            }
        }
       
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found at paths: " . implode(', ', $logo_paths) . " for guest {$guest->id}");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
       
        // Build personal info table rows
        $personal_rows = '
            <tr>
                <td class="info-label">Guest ID:</td>
                <td class="info-value">' . esc_html($guest->id) . '</td>
            </tr>
            <tr>
                <td class="info-label">Full Name:</td>
                <td class="info-value">' . esc_html($guest->first_name . ' ' . $guest->last_name) . '</td>
            </tr>
            <tr>
                <td class="info-label">Email Address:</td>
                <td class="info-value">' . esc_html($guest->email ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Phone Number:</td>
                <td class="info-value">' . esc_html($guest->phone_number) . '</td>
            </tr>
            <tr>
                <td class="info-label">ID Number:</td>
                <td class="info-value">' . esc_html($guest->id_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Status:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->guest_status)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Registration Date:</td>
                <td class="info-value">' . esc_html(VMS_Core::format_date($guest->created_at)) . '</td>
            </tr>';
       
        // Build communication preferences table rows
        $comm_rows = '
            <tr>
                <td class="info-label">Receive Emails:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->receive_emails)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Receive Messages:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->receive_messages)) . '</td>
            </tr>';
       
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {
                    margin: 25mm 20mm 25mm 20mm;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 12px;
                    color: #1e293b;
                    line-height: 1.6;
                }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 10px;
                    border-bottom: 3px solid #2563eb;
                }
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    color: #1e3a8a;
                    margin: 10px 0;
                    letter-spacing: 1px;
                }
                .report-title {
                    font-size: 20px;
                    color: #475569;
                    margin: 5px 0;
                    font-weight: 600;
                }
                .export-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 15px;
                }
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1e3a8a;
                    background-color: #dbeafe;
                    padding: 10px 18px;
                    margin-bottom: 10px;
                    border-left: 5px solid #2563eb;
                    border-radius: 0 5px 5px 0;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .info-table th,
                .info-table td {
                    padding: 5px 15px;
                    border: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .info-label {
                    font-weight: bold;
                    color: #475569;
                    font-size: 11px;
                    background-color: #f8fafc;
                    width: 35%;
                }
                .info-value {
                    color: #1e293b;
                    font-size: 11px;
                }
                .summary {
                    background-color: #f8fafc;
                    padding: 18px;
                    border-left: 5px solid #2563eb;
                    margin-bottom: 25px;
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e3a8a;
                    border-radius: 0 5px 5px 0;
                }
                .visits-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .visits-table th {
                    background-color: #1e3a8a;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #1e3a8a;
                }
                .visits-table td {
                    padding: 5px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 10px;
                }
                .visits-table tr:nth-child(even) {
                    background-color: #f8fafc;
                }
                .status-badge {
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background-color: #dcfce7; color: #166534; }
                .status-unapproved { background-color: #fef3c7; color: #92400e; }
                .status-cancelled { background-color: #f1f5f9; color: #475569; }
                .status-suspended { background-color: #dbeafe; color: #1e40af; }
                .status-banned { background-color: #fee2e2; color: #991b1b; }
                .footer {
                    margin-top: 60px;
                    padding-top: 25px;
                    border-top: 2px solid #e2e8f0;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    line-height: 1.7;
                }
                .no-visits {
                    text-align: center;
                    color: #64748b;
                    padding: 35px;
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 5px;
                    font-size: 12px;
                    margin-top: 15px;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <div class="company-name">Nyeri Club</div>
                <div class="report-title">Guest Details Report</div>
            </div>
           
            <div class="section">
                <div class="section-title">PERSONAL INFORMATION</div>
                <table class="info-table">
                    <tbody>' . $personal_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">COMMUNICATION PREFERENCES</div>
                <table class="info-table">
                    <tbody>' . $comm_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">VISIT HISTORY</div>
                <div class="summary">Total Visits: ' . esc_html($total_visits) . '</div>';
       
        if (!empty($visits)) {
            $html .= '
                <table class="visits-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 15%;">Visit Date</th>
                            <th style="width: 17%;">Host Member</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 12%;">Sign In</th>
                            <th style="width: 12%;">Sign Out</th>
                            <th style="width: 10%;">Duration</th>
                            <th style="width: 19%;">Created</th>
                        </tr>
                    </thead>
                    <tbody>';
           
            $counter = 1;
            foreach ($visits as $visit) {
                $host_display = 'N/A';
                if (!empty($visit->host_member_id)) {
                    $host_user = get_userdata($visit->host_member_id);
                    if ($host_user) {
                        $first_name = get_user_meta($visit->host_member_id, 'first_name', true);
                        $last_name = get_user_meta($visit->host_member_id, 'last_name', true);
                        $host_display = trim($first_name . ' ' . $last_name) ?: $host_user->user_login;
                    }
                } elseif (!empty($visit->courtesy)) {
                    $host_display = 'Courtesy';
                }
               
                $status_class = 'status-' . $visit->status;
               
                $html .= '
                        <tr>
                            <td>' . esc_html($counter++) . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->visit_date)) . '</td>
                            <td>' . esc_html($host_display) . '</td>
                            <td><span class="status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($visit->status)) . '</span></td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_in_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->created_at)) . '</td>
                        </tr>';
            }
           
            $html .= '
                    </tbody>
                </table>';
        } else {
            $html .= '<div class="no-visits">No visits recorded for this guest.</div>';
        }
       
        $html .= '
            </div>
           
            <div class="footer">
                This report was generated by the Visitor Management System on ' . htmlspecialchars($export_date) . '<br>
                Nyeri Club | Confidential Document
            </div>
        </body>
        </html>';
       
        return $html;
    }

    /**
     * Build HTML content for member PDF
     */
    private static function build_member_pdf_html($user_data, $phone_number, $member_number, $receive_emails, $receive_messages, $registration_status, $current_role, $visits)
    {
        $export_date = date('F j, Y g:i A');
        $total_visits = count($visits);
       
        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';
        $logo_paths = [
            get_template_directory() . '/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',           
            'home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
            '\home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
        ];
       
        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
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
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for member {$user_data->ID}: " . $e->getMessage());
                }
            }
        }
       
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found at paths: " . implode(', ', $logo_paths) . " for member {$user_data->ID}");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
       
        // Build personal info table rows
        $personal_rows = '
            <tr>
                <td class="info-label">User ID:</td>
                <td class="info-value">' . esc_html($user_data->ID) . '</td>
            </tr>
            <tr>
                <td class="info-label">Username:</td>
                <td class="info-value">' . esc_html($user_data->user_login) . '</td>
            </tr>
            <tr>
                <td class="info-label">Full Name:</td>
                <td class="info-value">' . esc_html($user_data->first_name . ' ' . $user_data->last_name) . '</td>
            </tr>
            <tr>
                <td class="info-label">Email Address:</td>
                <td class="info-value">' . esc_html($user_data->user_email ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Phone Number:</td>
                <td class="info-value">' . esc_html($phone_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Member Number:</td>
                <td class="info-value">' . esc_html($member_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Role:</td>
                <td class="info-value">' . esc_html(ucfirst($current_role)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Account Status:</td>
                <td class="info-value">' . esc_html(ucfirst($registration_status)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Registration Date:</td>
                <td class="info-value">' . esc_html(VMS_Core::format_date($user_data->user_registered)) . '</td>
            </tr>';
       
        // Build communication preferences table rows
        $comm_rows = '
            <tr>
                <td class="info-label">Receive Emails:</td>
                <td class="info-value">' . esc_html(ucfirst($receive_emails)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Receive Messages:</td>
                <td class="info-value">' . esc_html(ucfirst($receive_messages)) . '</td>
            </tr>';
       
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {
                    margin: 25mm 20mm 25mm 20mm;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 12px;
                    color: #1e293b;
                    line-height: 1.6;
                }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 10px;
                    border-bottom: 3px solid #2563eb;
                }
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    color: #1e3a8a;
                    margin: 10px 0;
                    letter-spacing: 1px;
                }
                .report-title {
                    font-size: 20px;
                    color: #475569;
                    margin: 5px 0;
                    font-weight: 600;
                }
                .export-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 15px;
                }
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1e3a8a;
                    background-color: #dbeafe;
                    padding: 10px 18px;
                    margin-bottom: 10px;
                    border-left: 5px solid #2563eb;
                    border-radius: 0 5px 5px 0;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .info-table th,
                .info-table td {
                    padding: 5px 15px;
                    border: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .info-label {
                    font-weight: bold;
                    color: #475569;
                    font-size: 11px;
                    background-color: #f8fafc;
                    width: 35%;
                }
                .info-value {
                    color: #1e293b;
                    font-size: 11px;
                }
                .summary {
                    background-color: #f8fafc;
                    padding: 18px;
                    border-left: 5px solid #2563eb;
                    margin-bottom: 25px;
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e3a8a;
                    border-radius: 0 5px 5px 0;
                }
                .visits-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .visits-table th {
                    background-color: #1e3a8a;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #1e3a8a;
                }
                .visits-table td {
                    padding: 5px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 10px;
                }
                .visits-table tr:nth-child(even) {
                    background-color: #f8fafc;
                }
                .status-badge {
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background-color: #dcfce7; color: #166534; }
                .status-unapproved { background-color: #fef3c7; color: #92400e; }
                .status-cancelled { background-color: #f1f5f9; color: #475569; }
                .status-suspended { background-color: #dbeafe; color: #1e40af; }
                .status-banned { background-color: #fee2e2; color: #991b1b; }
                .footer {
                    margin-top: 60px;
                    padding-top: 25px;
                    border-top: 2px solid #e2e8f0;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    line-height: 1.7;
                }
                .no-visits {
                    text-align: center;
                    color: #64748b;
                    padding: 35px;
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 5px;
                    font-size: 12px;
                    margin-top: 15px;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <div class="company-name">Nyeri Club</div>
                <div class="report-title">Member Details Report</div>
            </div>
           
            <div class="section">
                <div class="section-title">PERSONAL INFORMATION</div>
                <table class="info-table">
                    <tbody>' . $personal_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">COMMUNICATION PREFERENCES</div>
                <table class="info-table">
                    <tbody>' . $comm_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">HOSTED VISITS HISTORY</div>
                <div class="summary">Total Hosted Visits: ' . esc_html($total_visits) . '</div>';
       
        if (!empty($visits)) {
            $html .= '
                <table class="visits-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 15%;">Visit Date</th>
                            <th style="width: 17%;">Guest Name</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 12%;">Sign In</th>
                            <th style="width: 12%;">Sign Out</th>
                            <th style="width: 10%;">Duration</th>
                            <th style="width: 19%;">Created</th>
                        </tr>
                    </thead>
                    <tbody>';
           
            $counter = 1;
            foreach ($visits as $visit) {
                $guest_name = trim(($visit->first_name ?: '') . ' ' . ($visit->last_name ?: '')) ?: 'N/A';
               
                $status_class = 'status-' . $visit->status;
               
                $html .= '
                        <tr>
                            <td>' . esc_html($counter++) . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->visit_date)) . '</td>
                            <td>' . esc_html($guest_name) . '</td>
                            <td><span class="status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($visit->status)) . '</span></td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_in_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->created_at)) . '</td>
                        </tr>';
            }
           
            $html .= '
                    </tbody>
                </table>';
        } else {
            $html .= '<div class="no-visits">No hosted visits recorded for this member.</div>';
        }
       
        $html .= '
            </div>
           
            <div class="footer">
                This report was generated by the Visitor Management System on ' . htmlspecialchars($export_date) . '<br>
                Nyeri Club | Confidential Document
            </div>
        </body>
        </html>';
       
        return $html;
    }

    /**
     * Build HTML content for accommodation guest PDF
     */
    private static function build_a_guest_pdf_html($guest, $visits)
    {
        $export_date = date('F j, Y g:i A');
        $total_visits = count($visits);
        
        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';
        $logo_paths = [
            get_template_directory() . '/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',           
            'home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
            '\home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
        ];
        
        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
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
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for accommodation guest {$guest->id}: " . $e->getMessage());
                }
            }
        }
        
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found at paths: " . implode(', ', $logo_paths) . " for accommodation guest {$guest->id}");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
        
        // Build personal info table rows
        $personal_rows = '
            <tr>
                <td class="info-label">Guest ID:</td>
                <td class="info-value">' . esc_html($guest->id) . '</td>
            </tr>
            <tr>
                <td class="info-label">Full Name:</td>
                <td class="info-value">' . esc_html($guest->first_name . ' ' . $guest->last_name) . '</td>
            </tr>
            <tr>
                <td class="info-label">Email Address:</td>
                <td class="info-value">' . esc_html($guest->email ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Phone Number:</td>
                <td class="info-value">' . esc_html($guest->phone_number) . '</td>
            </tr>
            <tr>
                <td class="info-label">ID Number:</td>
                <td class="info-value">' . esc_html($guest->id_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Status:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->guest_status)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Registration Date:</td>
                <td class="info-value">' . esc_html(VMS_Core::format_date($guest->created_at)) . '</td>
            </tr>';
        
        // Build communication preferences table rows
        $comm_rows = '
            <tr>
                <td class="info-label">Receive Emails:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->receive_emails)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Receive Messages:</td>
                <td class="info-value">' . esc_html(ucfirst($guest->receive_messages)) . '</td>
            </tr>';
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {
                    margin: 25mm 20mm 25mm 20mm;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 12px;
                    color: #1e293b;
                    line-height: 1.6;
                }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 10px;
                    border-bottom: 3px solid #2563eb;
                }
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    color: #1e3a8a;
                    margin: 10px 0;
                    letter-spacing: 1px;
                }
                .report-title {
                    font-size: 20px;
                    color: #475569;
                    margin: 5px 0;
                    font-weight: 600;
                }
                .export-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 15px;
                }
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1e3a8a;
                    background-color: #dbeafe;
                    padding: 10px 18px;
                    margin-bottom: 10px;
                    border-left: 5px solid #2563eb;
                    border-radius: 0 5px 5px 0;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .info-table th,
                .info-table td {
                    padding: 5px 15px;
                    border: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .info-label {
                    font-weight: bold;
                    color: #475569;
                    font-size: 11px;
                    background-color: #f8fafc;
                    width: 35%;
                }
                .info-value {
                    color: #1e293b;
                    font-size: 11px;
                }
                .summary {
                    background-color: #f8fafc;
                    padding: 18px;
                    border-left: 5px solid #2563eb;
                    margin-bottom: 25px;
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e3a8a;
                    border-radius: 0 5px 5px 0;
                }
                .visits-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .visits-table th {
                    background-color: #1e3a8a;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #1e3a8a;
                }
                .visits-table td {
                    padding: 5px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 10px;
                }
                .visits-table tr:nth-child(even) {
                    background-color: #f8fafc;
                }
                .status-badge {
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background-color: #dcfce7; color: #166534; }
                .status-unapproved { background-color: #fef3c7; color: #92400e; }
                .status-cancelled { background-color: #f1f5f9; color: #475569; }
                .status-suspended { background-color: #dbeafe; color: #1e40af; }
                .status-banned { background-color: #fee2e2; color: #991b1b; }
                .footer {
                    margin-top: 60px;
                    padding-top: 25px;
                    border-top: 2px solid #e2e8f0;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    line-height: 1.7;
                }
                .no-visits {
                    text-align: center;
                    color: #64748b;
                    padding: 35px;
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 5px;
                    font-size: 12px;
                    margin-top: 15px;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <div class="company-name">Nyeri Club</div>
                <div class="report-title">Accommodation Guest Details Report</div>
            </div>
            
            <div class="section">
                <div class="section-title">PERSONAL INFORMATION</div>
                <table class="info-table">
                    <tbody>' . $personal_rows . '</tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">COMMUNICATION PREFERENCES</div>
                <table class="info-table">
                    <tbody>' . $comm_rows . '</tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">VISIT HISTORY</div>
                <div class="summary">Total Visits: ' . esc_html($total_visits) . '</div>';
        
        if (!empty($visits)) {
            $html .= '
                <table class="visits-table">
                    <thead>
                        <tr>                           
                            <th style="width: 5%;">#</th>
                            <th style="width: 15%;">Visit Date</th>
                            <th style="width: 15%;">Sign In</th>
                            <th style="width: 15%;">Sign Out</th>
                            <th style="width: 10%;">Duration</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 30%;">Created</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            $counter = 1;
            foreach ($visits as $visit) {
                $status_class = 'status-' . $visit->status;
                
                $html .= '
                        <tr>
                            <td>' . esc_html($counter++) . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->visit_date)) . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_in_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time) ?: 'N/A') . '</td>
                            <td><span class="status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($visit->status)) . '</span></td>
                            <td>' . esc_html(VMS_Core::format_date($visit->created_at)) . '</td>
                        </tr>';
            }
            
            $html .= '
                    </tbody>
                </table>';
        } else {
            $html .= '<div class="no-visits">No visits recorded for this guest.</div>';
        }
        
        $html .= '
            </div>
            
            <div class="footer">
                This report was generated by the Visitor Management System on ' . htmlspecialchars($export_date) . '<br>
                Nyeri Club | Confidential Document
            </div>
        </body>
        </html>';
        
        return $html;
    }

    /**
     * Build HTML content for supplier PDF
     */
    private static function build_supplier_pdf_html($supplier, $visits)
    {
        $export_date = date('F j, Y g:i A');
        $total_visits = count($visits);
        
        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';
        $logo_paths = [
            get_template_directory() . '/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',           
            'home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
            '\home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
        ];
        
        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
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
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for supplier {$supplier->id}: " . $e->getMessage());
                }
            }
        }
        
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found at paths: " . implode(', ', $logo_paths) . " for supplier {$supplier->id}");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
        
        // Build personal info table rows
        $personal_rows = '
            <tr>
                <td class="info-label">Supplier ID:</td>
                <td class="info-value">' . esc_html($supplier->id) . '</td>
            </tr>
            <tr>
                <td class="info-label">Full Name:</td>
                <td class="info-value">' . esc_html($supplier->first_name . ' ' . $supplier->last_name) . '</td>
            </tr>
            <tr>
                <td class="info-label">Email Address:</td>
                <td class="info-value">' . esc_html($supplier->email ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Phone Number:</td>
                <td class="info-value">' . esc_html($supplier->phone_number) . '</td>
            </tr>
            <tr>
                <td class="info-label">ID Number:</td>
                <td class="info-value">' . esc_html($supplier->id_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Status:</td>
                <td class="info-value">' . esc_html(ucfirst($supplier->guest_status)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Registration Date:</td>
                <td class="info-value">' . esc_html(VMS_Core::format_date($supplier->created_at)) . '</td>
            </tr>';
        
        // Build communication preferences table rows
        $comm_rows = '
            <tr>
                <td class="info-label">Receive Emails:</td>
                <td class="info-value">' . esc_html(ucfirst($supplier->receive_emails)) . '</td>
            </tr>
            <tr>
                <td class="info-label">Receive Messages:</td>
                <td class="info-value">' . esc_html(ucfirst($supplier->receive_messages)) . '</td>
            </tr>';
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {
                    margin: 25mm 20mm 25mm 20mm;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 12px;
                    color: #1e293b;
                    line-height: 1.6;
                }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 10px;
                    border-bottom: 3px solid #2563eb;
                }
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    color: #1e3a8a;
                    margin: 10px 0;
                    letter-spacing: 1px;
                }
                .report-title {
                    font-size: 20px;
                    color: #475569;
                    margin: 5px 0;
                    font-weight: 600;
                }
                .export-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 15px;
                }
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1e3a8a;
                    background-color: #dbeafe;
                    padding: 10px 18px;
                    margin-bottom: 10px;
                    border-left: 5px solid #2563eb;
                    border-radius: 0 5px 5px 0;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .info-table th,
                .info-table td {
                    padding: 5px 15px;
                    border: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .info-label {
                    font-weight: bold;
                    color: #475569;
                    font-size: 11px;
                    background-color: #f8fafc;
                    width: 35%;
                }
                .info-value {
                    color: #1e293b;
                    font-size: 11px;
                }
                .summary {
                    background-color: #f8fafc;
                    padding: 18px;
                    border-left: 5px solid #2563eb;
                    margin-bottom: 25px;
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e3a8a;
                    border-radius: 0 5px 5px 0;
                }
                .visits-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .visits-table th {
                    background-color: #1e3a8a;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #1e3a8a;
                }
                .visits-table td {
                    padding: 5px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 10px;
                }
                .visits-table tr:nth-child(even) {
                    background-color: #f8fafc;
                }
                .status-badge {
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background-color: #dcfce7; color: #166534; }
                .status-unapproved { background-color: #fef3c7; color: #92400e; }
                .status-cancelled { background-color: #f1f5f9; color: #475569; }
                .status-suspended { background-color: #dbeafe; color: #1e40af; }
                .status-banned { background-color: #fee2e2; color: #991b1b; }
                .footer {
                    margin-top: 60px;
                    padding-top: 25px;
                    border-top: 2px solid #e2e8f0;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    line-height: 1.7;
                }
                .no-visits {
                    text-align: center;
                    color: #64748b;
                    padding: 35px;
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 5px;
                    font-size: 12px;
                    margin-top: 15px;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <div class="company-name">Nyeri Club</div>
                <div class="report-title">Supplier Details Report</div>
            </div>
            
            <div class="section">
                <div class="section-title">PERSONAL INFORMATION</div>
                <table class="info-table">
                    <tbody>' . $personal_rows . '</tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">COMMUNICATION PREFERENCES</div>
                <table class="info-table">
                    <tbody>' . $comm_rows . '</tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-title">VISIT HISTORY</div>
                <div class="summary">Total Visits: ' . esc_html($total_visits) . '</div>';
        
        if (!empty($visits)) {
            $html .= '
                <table class="visits-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 15%;">Visit Date</th>
                            <th style="width: 15%;">Sign In</th>
                            <th style="width: 15%;">Sign Out</th>
                            <th style="width: 10%;">Duration</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 30%;">Created</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            $counter = 1;
            foreach ($visits as $visit) {
                $status_class = 'status-' . $visit->status;
                
                $html .= '
                        <tr>
                            <td>' . esc_html($counter++) . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->visit_date)) . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_in_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time) ?: 'N/A') . '</td>
                            <td><span class="status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($visit->status)) . '</span></td>
                            <td>' . esc_html(VMS_Core::format_date($visit->created_at)) . '</td>
                        </tr>';
            }
            
            $html .= '
                    </tbody>
                </table>';
        } else {
            $html .= '<div class="no-visits">No visits recorded for this supplier.</div>';
        }
        
        $html .= '
            </div>
            
            <div class="footer">
                This report was generated by the Visitor Management System on ' . htmlspecialchars($export_date) . '<br>
                Nyeri Club | Confidential Document
            </div>
        </body>
        </html>';
        
        return $html;
    }

    /**
     * Build HTML content for reciprocating member PDF
     */
    private static function build_recip_member_pdf_html($member, $visits)
    {
        $export_date = date('F j, Y g:i A');
        $total_visits = count($visits);
       
        // Handle logo - embed as base64 to ensure it displays reliably
        $logo_html = '';
        $logo_paths = [
            get_template_directory() . '/assets/logo.png',
            '/home3/nyericlu/vms.nyericlub.co.ke/wp-content/plugins/vms-plugin/assets/logo.png',           
            'home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
            '\home3\nyericlu\vms.nyericlub.co.ke\wp-content\plugins\vms-plugin\assets\logo.png',
        ];
       
        $found_logo = false;
        foreach ($logo_paths as $logo_path) {
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
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("VMS Export: Error processing logo at {$logo_path} for recip member {$member->id}: " . $e->getMessage());
                }
            }
        }
       
        if (!$found_logo) {
            error_log("VMS Export: No readable logo found at paths: " . implode(', ', $logo_paths) . " for recip member {$member->id}");
            $logo_html = '<div style="text-align: center; margin-bottom: 30px;"><span style="font-size: 36px; font-weight: bold; color: #1e3a8a; letter-spacing: 2px;">Nyeri Club</span></div>';
        }
       
        // Build personal info table rows
        $personal_rows = '
            <tr>
                <td class="info-label">Member ID:</td>
                <td class="info-value">' . esc_html($member->id) . '</td>
            </tr>
            <tr>
                <td class="info-label">Full Name:</td>
                <td class="info-value">' . esc_html($member->first_name . ' ' . $member->last_name) . '</td>
            </tr>
            <tr>
                <td class="info-label">Email Address:</td>
                <td class="info-value">' . esc_html($member->email ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Phone Number:</td>
                <td class="info-value">' . esc_html($member->phone_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">ID Number:</td>
                <td class="info-value">' . esc_html($member->id_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Member Number:</td>
                <td class="info-value">' . esc_html($member->reciprocating_member_number ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Club:</td>
                <td class="info-value">' . esc_html($member->club_name ?: 'Not provided') . '</td>
            </tr>
            <tr>
                <td class="info-label">Status:</td>
                <td class="info-value">' . esc_html(ucfirst($member->member_status ?: 'active')) . '</td>
            </tr>
            <tr>
                <td class="info-label">Registration Date:</td>
                <td class="info-value">' . esc_html(VMS_Core::format_date($member->created_at)) . '</td>
            </tr>';
       
        // Build communication preferences table rows
        $comm_rows = '
            <tr>
                <td class="info-label">Receive Emails:</td>
                <td class="info-value">' . esc_html(ucfirst($member->receive_emails ?: 'no')) . '</td>
            </tr>
            <tr>
                <td class="info-label">Receive Messages:</td>
                <td class="info-value">' . esc_html(ucfirst($member->receive_messages ?: 'no')) . '</td>
            </tr>';
       
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page {
                    margin: 25mm 20mm 25mm 20mm;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 12px;
                    color: #1e293b;
                    line-height: 1.6;
                }
                .header {
                    text-align: center;
                    margin-bottom: 10px;
                    padding-bottom: 10px;
                    border-bottom: 3px solid #2563eb;
                }
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    color: #1e3a8a;
                    margin: 10px 0;
                    letter-spacing: 1px;
                }
                .report-title {
                    font-size: 20px;
                    color: #475569;
                    margin: 5px 0;
                    font-weight: 600;
                }
                .export-date {
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 5px;
                }
                .section {
                    margin-bottom: 15px;
                }
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #1e3a8a;
                    background-color: #dbeafe;
                    padding: 10px 18px;
                    margin-bottom: 10px;
                    border-left: 5px solid #2563eb;
                    border-radius: 0 5px 5px 0;
                }
                .info-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .info-table th,
                .info-table td {
                    padding: 5px 15px;
                    border: 1px solid #e2e8f0;
                    vertical-align: top;
                }
                .info-label {
                    font-weight: bold;
                    color: #475569;
                    font-size: 11px;
                    background-color: #f8fafc;
                    width: 35%;
                }
                .info-value {
                    color: #1e293b;
                    font-size: 11px;
                }
                .summary {
                    background-color: #f8fafc;
                    padding: 18px;
                    border-left: 5px solid #2563eb;
                    margin-bottom: 25px;
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e3a8a;
                    border-radius: 0 5px 5px 0;
                }
                .visits-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }
                .visits-table th {
                    background-color: #1e3a8a;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #1e3a8a;
                }
                .visits-table td {
                    padding: 5px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 10px;
                }
                .visits-table tr:nth-child(even) {
                    background-color: #f8fafc;
                }
                .status-badge {
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    display: inline-block;
                }
                .status-approved { background-color: #dcfce7; color: #166534; }
                .status-unapproved { background-color: #fef3c7; color: #92400e; }
                .status-cancelled { background-color: #f1f5f9; color: #475569; }
                .status-suspended { background-color: #dbeafe; color: #1e40af; }
                .status-banned { background-color: #fee2e2; color: #991b1b; }
                .footer {
                    margin-top: 60px;
                    padding-top: 25px;
                    border-top: 2px solid #e2e8f0;
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    line-height: 1.7;
                }
                .no-visits {
                    text-align: center;
                    color: #64748b;
                    padding: 35px;
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 5px;
                    font-size: 12px;
                    margin-top: 15px;
                }
                @media print {
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                ' . $logo_html . '
                <div class="company-name">Nyeri Club</div>
                <div class="report-title">Reciprocating Member Details Report</div>
            </div>
           
            <div class="section">
                <div class="section-title">PERSONAL INFORMATION</div>
                <table class="info-table">
                    <tbody>' . $personal_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">COMMUNICATION PREFERENCES</div>
                <table class="info-table">
                    <tbody>' . $comm_rows . '</tbody>
                </table>
            </div>
           
            <div class="section">
                <div class="section-title">VISIT HISTORY</div>
                <div class="summary">Total Visits: ' . esc_html($total_visits) . '</div>';
       
        if (!empty($visits)) {
            $html .= '
                <table class="visits-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 15%;">Visit Date</th>
                            <th style="width: 15%;">Sign In</th>
                            <th style="width: 15%;">Sign Out</th>
                            <th style="width: 10%;">Duration</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 30%;">Created</th>
                        </tr>
                    </thead>
                    <tbody>';
           
            $counter = 1;
            foreach ($visits as $visit) {
                $status_class = 'status-' . $visit->status;
               
                $html .= '
                        <tr>
                            <td>' . esc_html($counter++) . '</td>
                            <td>' . esc_html(VMS_Core::format_date($visit->visit_date)) . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_in_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::format_time($visit->sign_out_time) ?: 'N/A') . '</td>
                            <td>' . esc_html(VMS_Core::calculate_duration($visit->sign_in_time, $visit->sign_out_time) ?: 'N/A') . '</td>
                            <td><span class="status-badge ' . esc_attr($status_class) . '">' . esc_html(ucfirst($visit->status)) . '</span></td>
                            <td>' . esc_html(VMS_Core::format_date($visit->created_at)) . '</td>
                        </tr>';
            }
           
            $html .= '
                    </tbody>
                </table>';
        } else {
            $html .= '<div class="no-visits">No visits recorded for this member.</div>';
        }
       
        $html .= '
            </div>
           
            <div class="footer">
                This report was generated by the Visitor Management System on ' . htmlspecialchars($export_date) . '<br>
                Nyeri Club | Confidential Document
            </div>
        </body>
        </html>';
       
        return $html;
    }
}