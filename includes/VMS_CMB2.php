<?php
/**
 * Handles all Cyber Wakili CMB2 custom fields and metaboxes
 * 
 * @package WyllyMk\VMS
 * @since 1.0.0
 */

namespace WyllyMk\VMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VMS_CMB2
{
    /**
     * Singleton instance
     * @var self|null
     */
    private static $instance = null;

    /**
     * Prefix for all meta fields
     * @var string
     */
    private $case_prefix = '_case_';
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
     * Initialize metabox functionality
     */
    public function init(): void
    {
        add_action('cmb2_admin_init', [$this, 'register_case_metaboxes']);
        add_action('cmb2_admin_init', [$this, 'register_task_metaboxes']);
        add_filter('cmb2_sanitize_text', [$this, 'sanitize_text_field'], 10, 5);
    }

    /**
     * Sanitize text fields
     */
    public function sanitize_text_field($null, $value, $object_id, $args, $field): string
    {
        return sanitize_text_field($value);
    }

    /**
     * Register metaboxes for Case post type
     */
    public function register_case_metaboxes(): void
    {
        $cmb = new_cmb2_box([
            'id'            => $this->case_prefix . 'metabox',
            'title'         => __('Case Details', 'cyber-wakili-plugin'),
            'object_types'  => ['case'],
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true,
        ]);

        // Case Number (auto-generated)
        $cmb->add_field([
            'name'       => __('Case Number', 'cyber-wakili-plugin'),
            'id'         => $this->case_prefix . 'number',
            'type'       => 'text',
            'attributes' => ['readonly' => 'readonly'],
            'default_cb' => [$this, 'generate_case_number'],
        ]);

        // Reference Number
        $cmb->add_field([
            'name' => __('Reference Number', 'cyber-wakili-plugin'),
            'id'   => $this->case_prefix . 'reference',
            'type' => 'text',
            'desc' => __('Unique reference for external use.', 'cyber-wakili-plugin'),
        ]);

        // Client (WP User)
        $cmb->add_field([
            'name'       => __('Client', 'cyber-wakili-plugin'),
            'id'         => $this->case_prefix . 'client',
            'type'       => 'user',
            'desc'       => __('Select the client associated with this case.', 'cyber-wakili-plugin'),
            'query_args' => ['role__in' => ['client']],
        ]);

        // Assigned Employees (WP Users)
        $cmb->add_field([
            'name'       => __('Assigned Employees', 'cyber-wakili-plugin'),
            'id'         => $this->case_prefix . 'employees',
            'type'       => 'user',
            'desc'       => __('Select employees assigned to this case.', 'cyber-wakili-plugin'),
            'repeatable' => true,
            'query_args' => ['role__in' => ['managing_partner', 'senior_partner', 'advocate', 'pupil']],
        ]);

        // Status
        $cmb->add_field([
            'name'    => __('Status', 'cyber-wakili-plugin'),
            'id'      => $this->case_prefix . 'status',
            'type'    => 'select',
            'options' => [
                'open'       => __('Open', 'cyber-wakili-plugin'),
                'in_progress' => __('In Progress', 'cyber-wakili-plugin'),
                'closed'     => __('Closed', 'cyber-wakili-plugin'),
            ],
            'default' => 'open',
        ]);

        // Key Dates
        $cmb->add_field([
            'name'        => __('Filing Date', 'cyber-wakili-plugin'),
            'id'          => $this->case_prefix . 'filing_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
        ]);

        $cmb->add_field([
            'name'        => __('Hearing Date', 'cyber-wakili-plugin'),
            'id'          => $this->case_prefix . 'hearing_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
        ]);

        $cmb->add_field([
            'name'        => __('Deadline', 'cyber-wakili-plugin'),
            'id'          => $this->case_prefix . 'deadline',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
        ]);

        // Notes
        $cmb->add_field([
            'name'    => __('Notes', 'cyber-wakili-plugin'),
            'id'      => $this->case_prefix . 'notes',
            'type'    => 'wysiwyg',
            'options' => ['textarea_rows' => 5],
        ]);
    }

    /**
     * Register metaboxes for Task post type
     */
    public function register_task_metaboxes(): void
    {
        $cmb = new_cmb2_box([
            'id'            => $this->task_prefix . 'metabox',
            'title'         => __('Task Details', 'cyber-wakili-plugin'),
            'object_types'  => ['task'],
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true,
        ]);

        // Linked Case
        $cmb->add_field([
            'name'       => __('Linked Case', 'cyber-wakili-plugin'),
            'id'         => $this->task_prefix . 'case',
            'type'       => 'post_search_text',
            'desc'       => __('Select the case this task is associated with (optional).', 'cyber-wakili-plugin'),
            'post_type'  => 'case',
            'select_type' => 'select',
            'select_behavior' => 'replace',
        ]);

        // Assignee
        $cmb->add_field([
            'name'       => __('Assignee', 'cyber-wakili-plugin'),
            'id'         => $this->task_prefix . 'assignee',
            'type'       => 'user',
            'desc'       => __('Select the employee assigned to this task.', 'cyber-wakili-plugin'),
            'query_args' => ['role__in' => ['managing_partner', 'senior_partner', 'advocate', 'pupil']],
        ]);

        // Due Date
        $cmb->add_field([
            'name'        => __('Due Date', 'cyber-wakili-plugin'),
            'id'          => $this->task_prefix . 'due_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
        ]);

        // Priority
        $cmb->add_field([
            'name'    => __('Priority', 'cyber-wakili-plugin'),
            'id'      => $this->task_prefix . 'priority',
            'type'    => 'select',
            'options' => [
                'low'    => __('Low', 'cyber-wakili-plugin'),
                'medium' => __('Medium', 'cyber-wakili-plugin'),
                'high'   => __('High', 'cyber-wakili-plugin'),
            ],
            'default' => 'medium',
        ]);

        // Status
        $cmb->add_field([
            'name'    => __('Status', 'cyber-wakili-plugin'),
            'id'      => $this->task_prefix . 'status',
            'type'    => 'select',
            'options' => [
                'pending'     => __('Pending', 'cyber-wakili-plugin'),
                'in_progress' => __('In Progress', 'cyber-wakili-plugin'),
                'completed'  => __('Completed', 'cyber-wakili-plugin'),
            ],
            'default' => 'pending',
        ]);

        // Notes
        $cmb->add_field([
            'name'    => __('Notes', 'cyber-wakili-plugin'),
            'id'      => $this->task_prefix . 'notes',
            'type'    => 'wysiwyg',
            'options' => ['textarea_rows' => 5],
        ]);
    }

    /**
     * Auto-generate case number (e.g., CASE-2025-001)
     */
    public function generate_case_number($field_args, $field): string
    {
        $year = date('Y');
        $count = wp_count_posts('case')->publish + 1;
        return sprintf('CASE-%s-%03d', $year, $count);
    }

    /**
     * Get all case meta fields
     * @return array
     */
    public function get_case_meta_fields(): array
    {
        return [
            $this->case_prefix . 'number',
            $this->case_prefix . 'reference',
            $this->case_prefix . 'client',
            $this->case_prefix . 'employees',
            $this->case_prefix . 'status',
            $this->case_prefix . 'filing_date',
            $this->case_prefix . 'hearing_date',
            $this->case_prefix . 'deadline',
            $this->case_prefix . 'notes'
        ];
    }

    /**
     * Get all task meta fields
     * @return array
     */
    public function get_task_meta_fields(): array
    {
        return [
            $this->task_prefix . 'case',
            $this->task_prefix . 'assignee',
            $this->task_prefix . 'due_date',
            $this->task_prefix . 'priority',
            $this->task_prefix . 'status',
            $this->task_prefix . 'description'
        ];
    }
}