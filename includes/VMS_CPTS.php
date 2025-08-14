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
        $this->register_task_cpt();
    }

    /**
     * Register Case custom post type
     */
    private function register_case_cpt(): void
    {
        $labels = [
            'name'                  => _x('Cases', 'Post type general name', 'cyber-wakili-plugin'),
            'singular_name'         => _x('Case', 'Post type singular name', 'cyber-wakili-plugin'),
            'menu_name'             => _x('Cases', 'Admin Menu text', 'cyber-wakili-plugin'),
            'name_admin_bar'        => _x('Case', 'Add New on Toolbar', 'cyber-wakili-plugin'),
            'add_new'               => __('Add New', 'cyber-wakili-plugin'),
            'add_new_item'          => __('Add New Case', 'cyber-wakili-plugin'),
            'new_item'              => __('New Case', 'cyber-wakili-plugin'),
            'edit_item'             => __('Edit Case', 'cyber-wakili-plugin'),
            'view_item'             => __('View Case', 'cyber-wakili-plugin'),
            'all_items'             => __('All Cases', 'cyber-wakili-plugin'),
            'search_items'          => __('Search Cases', 'cyber-wakili-plugin'),
            'not_found'             => __('No cases found.', 'cyber-wakili-plugin'),
            'not_found_in_trash'    => __('No cases found in Trash.', 'cyber-wakili-plugin'),
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
     * Register Task custom post type
     */
    private function register_task_cpt(): void
    {
        $labels = [
            'name'                  => _x('Tasks', 'Post type general name', 'cyber-wakili-plugin'),
            'singular_name'         => _x('Task', 'Post type singular name', 'cyber-wakili-plugin'),
            'menu_name'             => _x('Tasks', 'Admin Menu text', 'cyber-wakili-plugin'),
            'name_admin_bar'        => _x('Task', 'Add New on Toolbar', 'cyber-wakili-plugin'),
            'add_new'               => __('Add New', 'cyber-wakili-plugin'),
            'add_new_item'          => __('Add New Task', 'cyber-wakili-plugin'),
            'new_item'              => __('New Task', 'cyber-wakili-plugin'),
            'edit_item'             => __('Edit Task', 'cyber-wakili-plugin'),
            'view_item'             => __('View Task', 'cyber-wakili-plugin'),
            'all_items'             => __('All Tasks', 'cyber-wakili-plugin'),
            'search_items'          => __('Search Tasks', 'cyber-wakili-plugin'),
            'not_found'             => __('No tasks found.', 'cyber-wakili-plugin'),
            'not_found_in_trash'    => __('No tasks found in Trash.', 'cyber-wakili-plugin'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-list-view',
            'rest_base'           => 'tasks',
            'supports'            => ['title', 'editor', 'custom-fields'],
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts'       => 'create_tasks',
                'edit_post'          => 'edit_task',
                'edit_posts'         => 'edit_tasks',
                'edit_others_posts'  => 'edit_others_tasks',
                'publish_posts'      => 'publish_tasks',
                'read_post'          => 'read_task',
                'read_private_posts' => 'read_private_tasks',
                'delete_post'        => 'delete_task',
            ],
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'rewrite'             => false,
        ];

        register_post_type('task', $args);
    }

    /**
     * Get all registered custom post types
     * @return array
     */
    public function get_registered_post_types(): array
    {
        return ['case', 'task'];
    }
}