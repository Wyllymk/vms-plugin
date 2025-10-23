<?php
/**
 * Handles creation, deletion, and management of VMS custom roles and capabilities.
 *
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

defined('ABSPATH') || exit;

/**
 * Class VMS_Roles
 *
 * Registers custom roles and adds capabilities.
 * Uses static-only methods (no Singleton needed).
 */
class VMS_Roles extends Base{

    /**
     * Custom roles definition.
     *
     * @var array<string, array>
     */
    private static array $roles = [
        'reception'        => ['name' => 'Reception', 'capabilities' => ['read' => true]],
        'chairman'         => ['name' => 'Chairman', 'capabilities' => ['read' => true]],
        'general_manager'  => ['name' => 'General Manager', 'capabilities' => ['read' => true]],
        'member'           => ['name' => 'Member', 'capabilities' => ['read' => true]],
        'gate'             => ['name' => 'Gate', 'capabilities' => ['read' => true]],
    ];

    /**
     * Capabilities to assign to administrator users.
     *
     * @var string[]
     */
    private static array $admin_capabilities = [
        'create_cases', 'edit_cases', 'edit_others_cases', 'publish_cases',
        'read_case', 'read_private_cases', 'delete_case',
        'create_tasks', 'edit_tasks', 'edit_others_tasks', 'publish_tasks',
        'read_task', 'read_private_tasks', 'delete_task',
    ];

    /**
     * Register custom roles and add capabilities to Administrator.
     *
     * @return void
     */
    public static function register_roles_and_capabilities(): void {
        foreach (self::$roles as $slug => $data) {
            if (!get_role($slug)) {
                $result = add_role(
                    sanitize_key($slug),
                    esc_html__($data['name'], 'vms-plugin'),
                    array_map('boolval', $data['capabilities'])
                );

                if ($result) {
                    self::log("✅ Role '{$data['name']}' ({$slug}) created successfully.");
                } else {
                    self::log("⚠️ Failed to create role '{$data['name']}' ({$slug}).");
                }
            } else {
                self::log("ℹ️ Role '{$data['name']}' ({$slug}) already exists. Skipped creation.");
            }
        }

        self::add_admin_capabilities();
    }

    /**
     * Add all plugin capabilities to Administrator.
     *
     * @return void
     */
    private static function add_admin_capabilities(): void {
        $admin = get_role('administrator');

        if (!$admin) {
            self::log('❌ Administrator role not found. Cannot assign capabilities.');
            return;
        }

        foreach (self::$admin_capabilities as $cap) {
            if (!$admin->has_cap($cap)) {
                $admin->add_cap($cap);
            }
        }

        self::log('✅ Custom capabilities added to Administrator role.');
    }

    /**
     * Delete all custom plugin roles.
     *
     * @return void
     */
    public static function delete_roles(): void {
        foreach (self::$roles as $slug => $data) {
            remove_role($slug);
            self::log("🗑️ Role '{$data['name']}' ({$slug}) deleted.");
        }
    }

    /**
     * Get all custom roles.
     *
     * @return array<string, array>
     */
    public static function get_roles(): array {
        return self::$roles;
    }

    /**
     * Log helper for debugging.
     *
     * @param string $message Log message.
     * @return void
     */
    private static function log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[VMS_Roles] ' . $message);
        }
    }
}