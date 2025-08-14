<?php
/**
 * Handles all Cyber Wakili REST API functionality
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

use WP_Error;
use WP_REST_Response;
use WP_Post;
use WP_User;
use WP_REST_Request; 

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_RestApiManager
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Case meta field prefix
     * @var string
     */
    private $case_prefix = '_case_';

    /**
     * Task meta field prefix
     * @var string
     */
    private $task_prefix = '_task_';

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
     * Initialize REST API functionality
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_custom_fields']);
        add_filter('rest_prepare_case', [$this, 'filter_case_access'], 10, 3);
        add_filter('rest_prepare_task', [$this, 'filter_task_access'], 10, 3);
    }

    /**
     * Register custom REST API fields
     */
    public function register_custom_fields(): void
    {
        register_rest_field('case', 'case_details', [
            'get_callback' => [$this, 'get_case_details'],
            'schema' => $this->get_case_schema(),
            'update_callback' => null,
            'permission_callback' => null
        ]);

        register_rest_field('task', 'task_details', [
            'get_callback' => [$this, 'get_task_details'],
            'schema' => $this->get_task_schema(),
            'update_callback' => null,
            'permission_callback' => null
        ]);
    }

    /**
     * Get case details for REST API
     */
    public function get_case_details(array $object): array
    {
        $client_id = get_post_meta($object['id'], $this->case_prefix . 'client', true);
        $client = $client_id ? get_userdata($client_id) : null;

        return [
            'number' => get_post_meta($object['id'], $this->case_prefix . 'number', true),
            'reference' => get_post_meta($object['id'], $this->case_prefix . 'reference', true),
            'client' => $client ? $this->format_user_response($client) : null,
            'employees' => $this->get_case_employees($object['id']),
            'status' => get_post_meta($object['id'], $this->case_prefix . 'status', true),
            'filing_date' => get_post_meta($object['id'], $this->case_prefix . 'filing_date', true),
            'hearing_date' => get_post_meta($object['id'], $this->case_prefix . 'hearing_date', true),
            'deadline' => get_post_meta($object['id'], $this->case_prefix . 'deadline', true),
            'notes' => get_post_meta($object['id'], $this->case_prefix . 'notes', true),
        ];
    }

    /**
     * Get task details for REST API
     */
    public function get_task_details(array $object): array
    {
        $case_id = get_post_meta($object['id'], $this->task_prefix . 'case', true);
        $case = $case_id ? get_post($case_id) : null;
        $assignee_id = get_post_meta($object['id'], $this->task_prefix . 'assignee', true);
        $assignee = $assignee_id ? get_userdata($assignee_id) : null;

        return [
            'case' => $case ? $this->format_post_response($case) : null,
            'assignee' => $assignee ? $this->format_user_response($assignee) : null,
            'due_date' => get_post_meta($object['id'], $this->task_prefix . 'due_date', true),
            'priority' => get_post_meta($object['id'], $this->task_prefix . 'priority', true),
            'status' => get_post_meta($object['id'], $this->task_prefix . 'status', true),
            'notes' => get_post_meta($object['id'], $this->task_prefix . 'notes', true),
        ];
    }

    /**
     * Filter case access based on user permissions
     */
    public function filter_case_access(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        
        if (!$user->exists()) {
            return new WP_Error(
                'rest_forbidden',
                __('Authentication required.', 'vms'),
                ['status' => 401]
            );
        }

        $client_id = (int) get_post_meta($post->ID, $this->case_prefix . 'client', true);
        $employee_ids = array_map('intval', (array) get_post_meta($post->ID, $this->case_prefix . 'employees', false));

        if (in_array('client', (array) $user->roles, true)) {
            if ($user->ID !== $client_id) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You do not have access to this case.', 'vms'),
                    ['status' => 403]
                );
            }
            return $response;
        }

        $allowed_roles = ['managing_partner', 'senior_partner', 'advocate', 'pupil'];
        if (array_intersect($allowed_roles, (array) $user->roles)) {
            if (!in_array($user->ID, $employee_ids, true)) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You are not assigned to this case.', 'vms'),
                    ['status' => 403]
                );
            }
        }

        return $response;
    }

    /**
     * Filter task access based on user permissions
     */
    public function filter_task_access(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = wp_get_current_user();
        
        if (!$user->exists()) {
            return new WP_Error(
                'rest_forbidden',
                __('Authentication required.', 'vms'),
                ['status' => 401]
            );
        }

        if (in_array('client', (array) $user->roles, true)) {
            return new WP_Error(
                'rest_forbidden',
                __('Clients cannot access tasks.', 'vms'),
                ['status' => 403]
            );
        }

        $assignee_id = (int) get_post_meta($post->ID, $this->task_prefix . 'assignee', true);
        $restricted_roles = ['advocate', 'pupil'];

        if (array_intersect($restricted_roles, (array) $user->roles)) {
            if ($user->ID !== $assignee_id) {
                return new WP_Error(
                    'rest_forbidden',
                    __('You are not assigned to this task.', 'vms'),
                    ['status' => 403]
                );
            }
        }

        return $response;
    }

    /**
     * Get case employees formatted for response
     */
    private function get_case_employees(int $post_id): array
    {
        $employee_ids = (array) get_post_meta($post_id, $this->case_prefix . 'employees', false);
        $employees = [];

        foreach ($employee_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $employees[] = $this->format_user_response($user);
            }
        }

        return array_filter($employees);
    }

    /**
     * Format user data for API response
     */
    private function format_user_response(WP_User $user): array
    {
        return [
            'id'    => $user->ID,
            'name'  => $user->display_name,
            'email' => $user->user_email,
            'roles' => $user->roles
        ];
    }

    /**
     * Format post data for API response
     */
    private function format_post_response(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'link' => get_permalink($post)
        ];
    }

    /**
     * Get case schema for REST API
     */
    private function get_case_schema(): array
    {
        return [
            'type' => 'object',
            'properties'    => [
                'number'        => ['type' => 'string'],
                'reference'     => ['type' => 'string'],
                'client'        => ['type' => 'object'],
                'employees'     => ['type' => 'array'],
                'status'        => ['type' => 'string'],
                'filing_date'   => ['type' => 'string'],
                'hearing_date'  => ['type' => 'string'],
                'deadline'      => ['type' => 'string'],
                'notes'         => ['type' => 'string']
            ]
        ];
    }

    /**
     * Get task schema for REST API
     */
    private function get_task_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'case'      => ['type' => 'object'],
                'assignee'  => ['type' => 'object'],
                'due_date'  => ['type' => 'string'],
                'priority'  => ['type' => 'string'],
                'status'    => ['type' => 'string'],
                'notes'     => ['type' => 'string']
            ]
        ];
    }
}