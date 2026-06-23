<?php
/**
 * Settings module.
 *
 * Registers all admin settings using the WordPress Settings API so
 * values are saved through wp-admin/options.php with built-in nonce
 * protection. Provides branding, module toggles, visit limits, and
 * SMS provider configuration — everything needed to white-label the
 * plugin for any golf club.
 *
 * @package WyllyMk\VMS
 * @since   2.0.0
 */

namespace WyllyMk\VMS;

defined( 'ABSPATH' ) || exit;

/**
 * Settings manager.
 */
final class VMS_Settings extends Singleton {

	/**
	 * Settings page slug.
	 */
	private const PAGE_SLUG = 'vms-settings';

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	protected function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Bust settings cache whenever any VMS option updates.
		add_action( 'updated_option', array( $this, 'maybe_bust_cache' ), 10, 1 );
	}

	/**
	 * Register all settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$this->register_branding_settings();
		$this->register_module_settings();
		$this->register_limit_settings();
		$this->register_notification_settings();
		$this->register_sms_settings();
		$this->register_data_settings();
	}

	// ---------------------------------------------------------------------
	// Section: Branding
	// ---------------------------------------------------------------------

	/**
	 * Register branding settings.
	 *
	 * @return void
	 */
	private function register_branding_settings(): void {
		$section = 'vms_branding';

		add_settings_section(
			$section,
			__( 'Club Branding', 'vms-plugin' ),
			static function () {
				echo '<p>' . esc_html__( 'Customize the appearance of VMS for your club. These settings affect emails, SMS, and the dashboard UI.', 'vms-plugin' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_field( $section, 'club_name', __( 'Club Name', 'vms-plugin' ), 'text', array(
			'description' => __( 'Displayed in all notifications and page headers.', 'vms-plugin' ),
		) );

		$this->add_field( $section, 'club_logo_id', __( 'Club Logo', 'vms-plugin' ), 'media', array(
			'description' => __( 'Used in emails and the dashboard header. Recommended: PNG with transparent background.', 'vms-plugin' ),
		) );

		$this->add_field( $section, 'club_address', __( 'Address', 'vms-plugin' ), 'textarea' );
		$this->add_field( $section, 'club_phone', __( 'Phone', 'vms-plugin' ), 'text' );
		$this->add_field( $section, 'club_email', __( 'Email', 'vms-plugin' ), 'email' );

		$this->add_field( $section, 'primary_color', __( 'Primary Color', 'vms-plugin' ), 'color', array(
			'description' => __( 'Main accent color for buttons and headers.', 'vms-plugin' ),
		) );

		$this->add_field( $section, 'secondary_color', __( 'Secondary Color', 'vms-plugin' ), 'color' );
	}

	// ---------------------------------------------------------------------
	// Section: Module Toggles
	// ---------------------------------------------------------------------

	/**
	 * Register module toggle settings.
	 *
	 * @return void
	 */
	private function register_module_settings(): void {
		$section = 'vms_modules';

		add_settings_section(
			$section,
			__( 'Modules', 'vms-plugin' ),
			static function () {
				echo '<p>' . esc_html__( 'Enable or disable individual features. Disabled modules are completely unloaded to keep the system lean.', 'vms-plugin' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$modules = array(
			'module_guests'        => __( 'Guests', 'vms-plugin' ),
			'module_accommodation' => __( 'Accommodation', 'vms-plugin' ),
			'module_suppliers'     => __( 'Suppliers', 'vms-plugin' ),
			'module_reciprocation' => __( 'Reciprocating Clubs', 'vms-plugin' ),
			'module_employees'     => __( 'Employees', 'vms-plugin' ),
			'module_reports'       => __( 'Reports & Analytics', 'vms-plugin' ),
			'module_members'       => __( 'Member Profiles', 'vms-plugin' ),
		);

		foreach ( $modules as $key => $label ) {
			$this->add_field( $section, $key, $label, 'checkbox' );
		}
	}

	// ---------------------------------------------------------------------
	// Section: Visit Limits
	// ---------------------------------------------------------------------

	/**
	 * Register visit limit settings.
	 *
	 * @return void
	 */
	private function register_limit_settings(): void {
		$section = 'vms_limits';

		add_settings_section(
			$section,
			__( 'Visit Limits', 'vms-plugin' ),
			static function () {
				echo '<p>' . esc_html__( 'Control how often guests can visit and how many guests a host may bring.', 'vms-plugin' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_field( $section, 'max_guest_visits_month', __( 'Max Visits Per Guest / Month', 'vms-plugin' ), 'number', array(
			'min'         => 1,
			'max'         => 31,
			'description' => __( 'Guests exceeding this are auto-suspended until next month.', 'vms-plugin' ),
		) );

		$this->add_field( $section, 'max_guest_visits_year', __( 'Max Visits Per Guest / Year', 'vms-plugin' ), 'number', array(
			'min' => 1,
			'max' => 365,
		) );

		$this->add_field( $section, 'max_host_guests_day', __( 'Max Guests Per Host / Day', 'vms-plugin' ), 'number', array(
			'min' => 1,
			'max' => 50,
		) );

		$this->add_field( $section, 'auto_signout_time', __( 'Auto Sign-Out Time', 'vms-plugin' ), 'time', array(
			'description' => __( 'Guests still signed in at this time are automatically signed out.', 'vms-plugin' ),
		) );
	}

	// ---------------------------------------------------------------------
	// Section: Notifications
	// ---------------------------------------------------------------------

	/**
	 * Register notification settings.
	 *
	 * @return void
	 */
	private function register_notification_settings(): void {
		$section = 'vms_notifications';

		add_settings_section(
			$section,
			__( 'Notifications', 'vms-plugin' ),
			null,
			self::PAGE_SLUG
		);

		$this->add_field( $section, 'enable_email_notifications', __( 'Enable Email Notifications', 'vms-plugin' ), 'checkbox' );
		$this->add_field( $section, 'enable_sms_notifications', __( 'Enable SMS Notifications', 'vms-plugin' ), 'checkbox' );
		$this->add_field( $section, 'email_from_name', __( 'Email From Name', 'vms-plugin' ), 'text', array(
			'description' => __( 'Leave blank to use the club name.', 'vms-plugin' ),
		) );
		$this->add_field( $section, 'email_from_address', __( 'Email From Address', 'vms-plugin' ), 'email' );
	}

	// ---------------------------------------------------------------------
	// Section: SMS
	// ---------------------------------------------------------------------

	/**
	 * Register SMS provider settings.
	 *
	 * @return void
	 */
	private function register_sms_settings(): void {
		$section = 'vms_sms';

		add_settings_section(
			$section,
			__( 'SMS Gateway', 'vms-plugin' ),
			static function () {
				$url = VMS_Rewrite::get_sms_callback_url();
				echo '<p>' . esc_html__( 'Configure your SMS provider. Only the selected provider\'s fields are used.', 'vms-plugin' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Delivery Callback URL:', 'vms-plugin' ) . '</strong> <code>' . esc_url( $url ) . '</code></p>';
			},
			self::PAGE_SLUG
		);

		// Provider selector.
		$providers = VMS_SMS_Gateway::instance()->get_available_providers();
		$this->add_field( $section, 'sms_provider', __( 'Active Provider', 'vms-plugin' ), 'select', array(
			'options' => $providers,
		) );

		// Render fields for each provider (shown/hidden via JS based on selection).
		foreach ( $providers as $provider_key => $provider_name ) {
			$class  = VMS_SMS_Gateway::instance()->get_available_providers();
			$driver = 'WyllyMk\\VMS\\VMS_SMS_' . str_replace( ' ', '', ucwords( str_replace( '_', ' ', $provider_key ) ) );

			// Resolve actual class name from registry.
			$reflection = new \ReflectionClass( VMS_SMS_Gateway::instance() );
			$prop       = $reflection->getProperty( 'providers' );
			$prop->setAccessible( true );
			$registry = $prop->getValue( VMS_SMS_Gateway::instance() );

			if ( ! isset( $registry[ $provider_key ] ) || ! class_exists( $registry[ $provider_key ] ) ) {
				continue;
			}

			$fields = $registry[ $provider_key ]::get_settings_fields();

			foreach ( $fields as $field_key => $field_config ) {
				$option_key = "sms_{$provider_key}_{$field_key}";

				register_setting(
					self::PAGE_SLUG,
					'vms_' . $option_key,
					array(
						'type'              => 'string',
						'sanitize_callback' => 'password' === $field_config['type'] ? 'sanitize_text_field' : 'sanitize_text_field',
						'default'           => '',
					)
				);

				add_settings_field(
					'vms_' . $option_key,
					sprintf( '%s — %s', $provider_name, $field_config['label'] ),
					array( $this, 'render_field' ),
					self::PAGE_SLUG,
					$section,
					array(
						'key'         => $option_key,
						'type'        => $field_config['type'],
						'description' => $field_config['description'] ?? '',
						'class'       => 'vms-provider-field vms-provider-' . esc_attr( $provider_key ),
					)
				);
			}
		}
	}

	// ---------------------------------------------------------------------
	// Section: Data Retention
	// ---------------------------------------------------------------------

	/**
	 * Register data retention settings.
	 *
	 * @return void
	 */
	private function register_data_settings(): void {
		$section = 'vms_data';

		add_settings_section(
			$section,
			__( 'Data Retention', 'vms-plugin' ),
			null,
			self::PAGE_SLUG
		);

		$this->add_field( $section, 'audit_log_retention_days', __( 'Audit Log Retention (days)', 'vms-plugin' ), 'number', array(
			'min'         => 0,
			'description' => __( 'Logs older than this are deleted weekly. Set to 0 to keep forever.', 'vms-plugin' ),
		) );

		$this->add_field( $section, 'sms_log_retention_days', __( 'SMS Log Retention (days)', 'vms-plugin' ), 'number', array(
			'min' => 0,
		) );

		$this->add_field( $section, 'keep_data_on_uninstall', __( 'Keep Data On Uninstall', 'vms-plugin' ), 'checkbox', array(
			'description' => __( 'If checked, plugin data is preserved when the plugin is deleted. Useful before upgrading.', 'vms-plugin' ),
		) );
	}

	// ---------------------------------------------------------------------
	// Field Registration Helper
	// ---------------------------------------------------------------------

	/**
	 * Register a setting + field in one call.
	 *
	 * @param string $section Section ID.
	 * @param string $key     Option key (without prefix).
	 * @param string $label   Field label.
	 * @param string $type    Field type.
	 * @param array  $args    Extra args.
	 * @return void
	 */
	private function add_field( string $section, string $key, string $label, string $type, array $args = array() ): void {
		$full_key = VMS_Config::OPTION_PREFIX . $key;

		// Pick sanitization callback based on type.
		// NOTE: checkboxes must return '1'/'0' (strings) not bool — WordPress
		// stores boolean false as '' (empty string), which VMS_Config::get_option()
		// then returns directly (it's non-null) causing (bool)'' → false even when
		// the user never intentionally disabled the module. Storing '1'/'0' keeps
		// the value unambiguous and lets the null-check in get_option() correctly
		// fall back to DEFAULTS on a fresh install.
		$sanitize = match ( $type ) {
			'checkbox' => static fn( $v ) => ! empty( $v ) ? '1' : '0',
			'number'   => 'absint',
			'email'    => 'sanitize_email',
			'color'    => 'sanitize_hex_color',
			'textarea' => 'sanitize_textarea_field',
			'media'    => 'absint',
			default    => 'sanitize_text_field',
		};

		register_setting(
			self::PAGE_SLUG,
			$full_key,
			array(
				'type'              => 'checkbox' === $type ? 'boolean' : 'string',
				'sanitize_callback' => $sanitize,
				'default'           => VMS_Config::DEFAULTS[ $key ] ?? '',
			)
		);

		add_settings_field(
			$full_key,
			$label,
			array( $this, 'render_field' ),
			self::PAGE_SLUG,
			$section,
			array_merge( $args, array( 'key' => $key, 'type' => $type ) )
		);
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_field( array $args ): void {
		$key   = $args['key'];
		$type  = $args['type'] ?? 'text';
		$name  = 'vms_' . $key;
		$value = VMS_Config::get_option( $key );

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( $args['description'] ?? __( 'Enable', 'vms-plugin' ) )
				);
				return;

			case 'number':
				printf(
					'<input type="number" name="%s" value="%s" min="%d" max="%d" class="small-text">',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $args['min'] ?? 0 ),
					esc_attr( $args['max'] ?? 9999 )
				);
				break;

			case 'select':
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( ( $args['options'] ?? array() ) as $opt_val => $opt_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt_val ),
						selected( $value, $opt_val, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea name="%s" rows="3" class="large-text">%s</textarea>',
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'color':
				printf(
					'<input type="text" name="%s" value="%s" class="vms-color-picker" data-default-color="%s">',
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( VMS_Config::DEFAULTS[ $key ] ?? '#000000' )
				);
				break;

			case 'media':
				$img_url = $value ? wp_get_attachment_image_url( (int) $value, 'thumbnail' ) : '';
				?>
				<div class="vms-media-field">
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="vms-media-id">
					<div class="vms-media-preview" style="margin-bottom:8px;">
						<?php if ( $img_url ) : ?>
							<img src="<?php echo esc_url( $img_url ); ?>" style="max-height:80px;">
						<?php endif; ?>
					</div>
					<button type="button" class="button vms-media-select"><?php esc_html_e( 'Select Image', 'vms-plugin' ); ?></button>
					<button type="button" class="button vms-media-remove" <?php echo $value ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'vms-plugin' ); ?></button>
				</div>
				<?php
				break;

			case 'password':
				printf(
					'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="new-password">',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'time':
				printf(
					'<input type="time" name="%s" value="%s" step="60">',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="%s" name="%s" value="%s" class="regular-text">',
					esc_attr( $type ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}

		if ( ! empty( $args['description'] ) && 'checkbox' !== $type ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	// ---------------------------------------------------------------------
	// Admin Page
	// ---------------------------------------------------------------------

	/**
	 * Register the settings page in the admin menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'vms-dashboard',
			__( 'VMS Settings', 'vms-plugin' ),
			__( 'Settings', 'vms-plugin' ),
			VMS_Config::CAP_MANAGE_SETTINGS,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( VMS_Config::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vms-plugin' ) );
		}
		?>
		<div class="wrap vms-settings-wrap">
			<h1><?php esc_html_e( 'VMS Settings', 'vms-plugin' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'SMS Test', 'vms-plugin' ); ?></h2>
			<p>
				<input type="tel" id="vms-test-phone" placeholder="+254712345678" class="regular-text">
				<button type="button" class="button button-secondary" id="vms-test-sms-btn">
					<?php esc_html_e( 'Send Test SMS', 'vms-plugin' ); ?>
				</button>
				<span id="vms-test-result"></span>
			</p>
			<?php wp_nonce_field( 'vms_settings_nonce', 'vms_settings_nonce_field' ); ?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets for the settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_script(
			'vms-settings',
			VMS_PLUGIN_URL . 'assets/js/settings.js',
			array( 'jquery', 'wp-color-picker' ),
			VMS_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'vms-settings',
			'vmsSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vms_settings_nonce' ),
				'i18n'    => array(
					'selectImage' => __( 'Select Logo', 'vms-plugin' ),
					'useImage'    => __( 'Use this image', 'vms-plugin' ),
					'sending'     => __( 'Sending…', 'vms-plugin' ),
					'success'     => __( 'Test SMS sent successfully!', 'vms-plugin' ),
					'error'       => __( 'Error:', 'vms-plugin' ),
				),
			)
		);
	}

	/**
	 * Bust settings cache when any VMS option changes.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public function maybe_bust_cache( string $option ): void {
		if ( str_starts_with( $option, VMS_Config::OPTION_PREFIX ) ) {
			VMS_Cache::bust( 'settings' );
		}
	}

	// ---------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------

	/**
	 * Check if a module is enabled.
	 *
	 * @param string $module Module key (e.g. 'guests', 'suppliers').
	 * @return bool
	 */
	public static function is_module_enabled( string $module ): bool {
		$value = VMS_Config::get_option( 'module_' . $module, true );

		// Handle every representation WordPress might have stored: true, '1',
		// 1, 'on', 'yes' → enabled; false, '0', 0, '', 'off', 'no' → disabled.
		// Previously we cast with (bool) which meant an empty-string option
		// (WordPress's serialization of boolean false) was treated as disabled
		// even though the user never unchecked the box — this is why
		// Accommodation showed as "not activated" after saving any other
		// setting on the same page.
		if ( is_bool( $value ) ) {
			return $value;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true;
	}

	/**
	 * Get branding settings as an array (cached).
	 *
	 * @return array
	 */
	public static function get_branding(): array {
		return VMS_Cache::cached(
			'settings:branding',
			static function () {
				$logo_id = (int) VMS_Config::get_option( 'club_logo_id', 0 );

				return array(
					'club_name'       => VMS_Config::get_option( 'club_name' ),
					'club_logo_id'    => $logo_id,
					'club_logo_url'   => $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '',
					'club_address'    => VMS_Config::get_option( 'club_address' ),
					'club_phone'      => VMS_Config::get_option( 'club_phone' ),
					'club_email'      => VMS_Config::get_option( 'club_email' ),
					'primary_color'   => VMS_Config::get_option( 'primary_color' ),
					'secondary_color' => VMS_Config::get_option( 'secondary_color' ),
				);
			},
			VMS_Config::CACHE_TTL_LONG
		);
	}
}
