<?php
/**
 * Handles all Cyber Wakili custom roles and capabilities
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_Roles
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Custom roles configuration
     * @var array
     */
    private $roles = [
        'reception' => [
            'name' => 'Reception',
            'capabilities' => [
                'read' => true,
            ]
        ],
        'chairman' => [
            'name' => 'Chairman',
            'capabilities' => [
                'read' => true,
            ]
        ],
        'general_manager' => [
            'name' => 'General Manager',
            'capabilities' => [
                'read' => true,
            ]
        ],
        'member' => [
            'name' => 'Member',
            'capabilities' => [
                'read' => true                
            ]
        ],       
        'gate' => [
            'name' => 'Gate',
            'capabilities' => [
                'read' => true,               
            ]
        ],
        'guest' => [
            'name' => 'Guest',
            'capabilities' => [
                'read' => true,
            ]
        ]
    ];

    /**
     * Custom capabilities to add to administrators
     * @var array
     */
    private $admin_capabilities = [
        'create_cases',
        'edit_cases',
        'edit_others_cases',
        'publish_cases',
        'read_case',
        'read_private_cases',
        'delete_case',
        'create_tasks',
        'edit_tasks',
        'edit_others_tasks',
        'publish_tasks',
        'read_task',
        'read_private_tasks',
        'delete_task'
    ];

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
     * Initialize role management
     */
    public function init(): void
    {
        add_action('init', [$this, 'register_roles_and_capabilities']);
    }

    /**
     * Register custom roles and capabilities
     */
    public function register_roles_and_capabilities(): void
    {
        // Register all custom roles
        foreach ($this->roles as $role_slug => $role_data) {
            add_role(
                $role_slug,
                __($role_data['name'], 'vms'),
                $role_data['capabilities']
            );
        }

        // Add custom capabilities to administrators
        $this->add_admin_capabilities();
    }

    /**
     * Add custom capabilities to administrator role
     */
    private function add_admin_capabilities(): void
    {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($this->admin_capabilities as $capability) {
                $admin_role->add_cap($capability);
            }
        }
    }

    /**
     * Get all custom roles
     * @return array
     */
    public function get_custom_roles(): array
    {
        return $this->roles;
    }

    /**
     * Get role capabilities
     * @param string $role_slug
     * @return array|null
     */
    public function get_role_capabilities(string $role_slug): ?array
    {
        return $this->roles[$role_slug]['capabilities'] ?? null;
    }
}