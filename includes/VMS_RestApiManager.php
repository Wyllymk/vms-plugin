<?php
/**
 * Handles all VMS REST API functionality
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
        // add_action('rest_api_init', [$this, 'register_custom_fields']);
        // add_filter('rest_prepare_case', [$this, 'filter_case_access'], 10, 3);
        // add_filter('rest_prepare_task', [$this, 'filter_task_access'], 10, 3);
    }


}