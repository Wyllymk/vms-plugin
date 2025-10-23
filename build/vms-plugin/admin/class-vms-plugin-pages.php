<?php
/**
 * Page Manager - Handles all WordPress page creation
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit;
}

class VMS_Page_Manager
{
    private static array $pages = [
        ['title' => 'Login', 'template' => 'page-templates/page-login.php'],
        ['title' => 'Register', 'template' => 'page-templates/page-register.php'],
        ['title' => 'Lost Password', 'template' => 'page-templates/page-lostpassword.php'],
        ['title' => 'Password Reset', 'template' => 'page-templates/page-password-reset.php'],
        ['title' => 'Terms & Conditions'],
        ['title' => 'Profile', 'template' => 'page-templates/page-profile.php'],
        ['title' => 'Dashboard', 'template' => 'page-templates/page-dashboard.php'],
        ['title' => 'Members', 'template' => 'page-templates/page-members.php'],
        ['title' => 'Employees', 'template' => 'page-templates/page-employees.php'],
        ['title' => 'Employee Details', 'template' => 'page-templates/page-employee-details.php'],
        ['title' => 'Details', 'template' => 'page-templates/page-details.php'],
        ['title' => 'Guests', 'template' => 'page-templates/page-guests.php'],
        ['title' => 'Guest Details', 'template' => 'page-templates/page-guest-details.php'],
        ['title' => 'Suppliers', 'template' => 'page-templates/page-suppliers.php'],
        ['title' => 'Supplier Details', 'template' => 'page-templates/page-supplier-details.php'],
        ['title' => 'Accommodation', 'template' => 'page-templates/page-accommodation.php'],
        ['title' => 'Accommodation Details', 'template' => 'page-templates/page-accommodation-details.php'],
        ['title' => 'Reciprocating Members', 'template' => 'page-templates/page-reciprocating-members.php'],
        ['title' => 'Reciprocating Member Details', 'template' => 'page-templates/page-reciprocating-member-details.php'],
        ['title' => 'Clubs', 'template' => 'page-templates/page-clubs.php'],
        ['title' => 'Settings', 'template' => 'page-templates/page-settings.php']
    ];

    public static function create_all_pages(): void
    {
        foreach (self::$pages as $page) {
            $slug = sanitize_title($page['title']);
            if (!self::page_exists($slug)) {
                self::create_page($page['title'], $slug, $page['template'] ?? '');
            }
        }
    }

    private static function page_exists(string $slug): bool
    {
        return (bool) get_page_by_path($slug);
    }

    private static function create_page(string $title, string $slug, string $template = ''): ?int
    {
        $page_id = wp_insert_post([
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => ''
        ]);

        if (!is_wp_error($page_id) && $template) {
            update_post_meta($page_id, '_wp_page_template', $template);
            error_log("Created page: $title");
        } else {
            error_log("Failed to create page: $title");
        }

        return is_wp_error($page_id) ? null : $page_id;
    }

    public static function remove_all_pages(): void
    {
        $slugs = [
            'login', 'register', 'lost-password', 'password-reset',
            'terms-conditions', 'profile', 'dashboard', 'employees',
            'details', 'members', 'employee-details', 'guests',
            'guest-details', 'suppliers', 'supplier-details',
            'accommodation', 'accommodation-details', 'clubs',
            'reciprocating-members', 'reciprocating-member-details',
            'settings'
        ];

        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_delete_post($page->ID, true);
                error_log("Removed page: $slug");
            }
        }
    }
}