<?php
/**
 * Handles all Cyber Wakili custom post type registrations
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_CPTS
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
     * Initialize custom post types
     */
    public function init(): void
    {
        add_action('init', [$this, 'register_custom_post_types']);
    }

    /**
     * Register all custom post types
     */
    public function register_custom_post_types(): void
    {
        $this->register_case_cpt();
    }

    /**
     * Register Case custom post type
     */
    private function register_case_cpt(): void
    {
        $labels = [
            'name'                  => _x('Cases', 'Post type general name', 'vms'),
            'singular_name'         => _x('Case', 'Post type singular name', 'vms'),
            'menu_name'             => _x('Cases', 'Admin Menu text', 'vms'),
            'name_admin_bar'        => _x('Case', 'Add New on Toolbar', 'vms'),
            'add_new'               => __('Add New', 'vms'),
            'add_new_item'          => __('Add New Case', 'vms'),
            'new_item'              => __('New Case', 'vms'),
            'edit_item'             => __('Edit Case', 'vms'),
            'view_item'             => __('View Case', 'vms'),
            'all_items'             => __('All Cases', 'vms'),
            'search_items'          => __('Search Cases', 'vms'),
            'not_found'             => __('No cases found.', 'vms'),
            'not_found_in_trash'    => __('No cases found in Trash.', 'vms'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-portfolio',
            'rest_base'           => 'cases',
            'supports'            => ['title', 'editor', 'custom-fields'],
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts'       => 'create_cases',
                'edit_post'          => 'edit_case',
                'edit_posts'         => 'edit_cases',
                'edit_others_posts'  => 'edit_others_cases',
                'publish_posts'      => 'publish_cases',
                'read_post'          => 'read_case',
                'read_private_posts' => 'read_private_cases',
                'delete_post'        => 'delete_case',
            ],
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'rewrite'             => false,
        ];

        register_post_type('case', $args);
    }

    /**
     * Get all registered custom post types
     * @return array
     */
    public function get_registered_post_types(): array
    {
        return ['case'];
    }
}