<?php
/**
 * Plugin activation handler.
 *
 * Runs once when the plugin is activated. Sets up database tables,
 * registers roles, schedules cron jobs, creates required pages,
 * and stores activation metadata.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Activator.
 */
final class VMS_Activator {

	/**
	 * Pages to create on activation.
	 * Key = page slug, value = [title, template].
	 *
	 * @var array<string, array{title: string, template: string}>
	 */
	private const REQUIRED_PAGES = array(
		'vms-dashboard'       => array(
			'title'    => 'Dashboard',
			'template' => 'page-templates/dashboard.php',
		),
		'vms-guests'          => array(
			'title'    => 'Guests',
			'template' => 'page-templates/guests.php',
		),
		'vms-register-guest'  => array(
			'title'    => 'Register Guest',
			'template' => 'page-templates/register-guest.php',
		),
		'vms-sign-in'         => array(
			'title'    => 'Sign In',
			'template' => 'page-templates/sign-in.php',
		),
		'vms-reports'         => array(
			'title'    => 'Reports',
			'template' => 'page-templates/reports.php',
		),
		'vms-suppliers'       => array(
			'title'    => 'Suppliers',
			'template' => 'page-templates/suppliers.php',
		),
		'vms-accommodation'   => array(
			'title'    => 'Accommodation',
			'template' => 'page-templates/accommodation.php',
		),
		'vms-reciprocation'   => array(
			'title'    => 'Reciprocation',
			'template' => 'page-templates/reciprocation.php',
		),
		'vms-profile'         => array(
			'title'    => 'My Profile',
			'template' => 'page-templates/profile.php',
		),
		'vms-reset-password'  => array(
			'title'    => 'Reset Password',
			'template' => 'page-templates/reset-password.php',
		),
		'vms-employees'       => array(
			'title'    => 'Employees',
			'template' => 'page-templates/employees.php',
		),
		'vms-members'         => array(
			'title'    => 'Members',
			'template' => 'page-templates/members.php',
		),
	);

	/**
	 * Run activation routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// 1. Database schema.
		VMS_Database_Manager::create_all_tables();

		// 2. Custom roles & capabilities.
		VMS_Roles::register_roles();

		// 3. Required pages.
		self::create_pages();

		// 4. Cron schedules.
		VMS_Cron::schedule_all();

		// 5. Rewrite rules (flush after registration).
		VMS_Rewrite::register_rules();
		flush_rewrite_rules();

		// 6. Default options (only set if not already present).
		self::seed_default_options();

		// 7. Activation metadata.
		update_option( 'vms_activated_at', current_time( 'mysql' ), false );
		update_option( 'vms_activated_version', VMS_PLUGIN_VERSION, false );

		// 8. Generate status callback secret for SMS webhooks.
		if ( ! get_option( 'vms_status_secret' ) ) {
			update_option( 'vms_status_secret', wp_generate_password( 32, false, false ), false );
		}

		/**
		 * Fires after VMS activation completes.
		 *
		 * @since 2.0.0
		 */
		do_action( 'vms_activated' );
	}

	/**
	 * Create required WordPress pages.
	 *
	 * Stores page IDs in options so we can find them later even if
	 * slugs change.
	 *
	 * @return void
	 */
	private static function create_pages(): void {
		$page_ids = get_option( 'vms_page_ids', array() );

		foreach ( self::REQUIRED_PAGES as $slug => $config ) {
			// Skip if page already tracked & still exists.
			if ( isset( $page_ids[ $slug ] ) && get_post_status( $page_ids[ $slug ] ) ) {
				continue;
			}

			// Check if a page with this slug already exists.
			$existing = get_page_by_path( $slug );
			if ( $existing instanceof \WP_Post ) {
				$page_ids[ $slug ] = $existing->ID;
				update_post_meta( $existing->ID, '_wp_page_template', $config['template'] );
				continue;
			}

			// Create the page.
			$page_id = wp_insert_post(
				array(
					'post_title'   => $config['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '<!-- VMS managed page. Content rendered by template. -->',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_post_meta( $page_id, '_wp_page_template', $config['template'] );
				update_post_meta( $page_id, '_vms_managed', '1' );
				$page_ids[ $slug ] = $page_id;
			}
		}

		update_option( 'vms_page_ids', $page_ids, false );
	}

	/**
	 * Seed default options without overwriting existing values.
	 *
	 * @return void
	 */
	private static function seed_default_options(): void {
		foreach ( VMS_Config::DEFAULTS as $key => $default ) {
			$full_key = VMS_Config::OPTION_PREFIX . $key;
			if ( false === get_option( $full_key, false ) ) {
				add_option( $full_key, $default );
			}
		}
	}
}
