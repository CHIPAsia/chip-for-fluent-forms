<?php
/**
 * Main plugin bootstrap class.
 *
 * Singleton that defines the plugin constants, includes the
 * codestar framework + the runtime classes, and registers WP
 * hooks for the plugin action link.
 *
 * Storage layer:
 *   - The codestar framework is the single source of truth for
 *     both global and per-form settings. It writes to a single
 *     WordPress option `fluent_form_chip` keyed by field id
 *     (`secret-key`, `brand-id`, `payment-title`, `due-strict`,
 *     etc., with `-{form_id}` suffix for per-form fields).
 *   - The runtime read path (see
 *     `Chip_Fluent_Forms_Purchase::get_settings()`) reads
 *     `get_option('fluent_form_chip')` directly and layers
 *     per-form overrides on top of global values.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms — main plugin bootstrap.
 *
 * Singleton that defines the plugin constants, includes the
 * codestar framework + runtime + admin classes, and registers
 * the `setting_link` row action on the Plugins list table.
 */
class Chip_Fluent_Forms {

	/**
	 * Singleton instance.
	 *
	 * @var Chip_Fluent_Forms|null
	 */
	private static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return Chip_Fluent_Forms
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->define();
		$this->includes();
		$this->add_filters();
	}

	/**
	 * Define plugin constants.
	 *
	 * `FF_CHIP_FILE` is expected to be defined by the plugin entry
	 * point before this class is loaded (so the constant resolves
	 * to the entry-point path, not to this class file's path). The
	 * other constants are derived from it.
	 *
	 * @return void
	 */
	public function define() {
		if ( ! defined( 'FF_CHIP_FILE' ) ) {
			define( 'FF_CHIP_FILE', __FILE__ );
		}
		define( 'FF_CHIP_BASENAME', plugin_basename( FF_CHIP_FILE ) );
		define( 'FF_CHIP_FSLUG', 'fluent_form_chip' );
	}

	/**
	 * Include the codestar framework, the runtime classes, and
	 * the admin files.
	 *
	 * The codestar framework is required at the top of every
	 * request because CSF_Setup::createOptions() registers
	 * admin pages on every load. The admin pages themselves
	 * are gated behind is_admin() since they should not be
	 * loaded on public requests.
	 *
	 * @return void
	 */
	public function includes() {
		$includes_dir = plugin_dir_path( FF_CHIP_FILE ) . 'includes/';

		// Core runtime: API client and purchase handler.
		include $includes_dir . 'class-chip-fluent-forms-api.php';

		// Codestar framework (CSF_Setup class + assets).
		include $includes_dir . 'codestar-framework/classes/setup.class.php';

		// Admin-only: codestar settings pages + webhook setup.
		if ( is_admin() ) {
			include $includes_dir . 'admin/global-settings.php';
			include $includes_dir . 'admin/form-settings.php';
			include $includes_dir . 'admin/backup-settings.php';
			include $includes_dir . 'admin/class-webhook-setup.php';
		}

		// Runtime: register the chip method with FF, handle
		// purchase creation.
		include $includes_dir . 'class-register.php';
		include $includes_dir . 'class-purchase.php';
	}

	/**
	 * Register WP filters.
	 *
	 * @return void
	 */
	public function add_filters() {
		add_filter( 'plugin_action_links_' . FF_CHIP_BASENAME, array( $this, 'setting_link' ) );
	}

	/**
	 * Add a "Settings" row action on the Plugins list table.
	 *
	 * @param array $links The existing row-action links.
	 * @return array The augmented links.
	 */
	public function setting_link( $links ) {
		$new_links = array(
			'settings' => sprintf(
				'<a href="%1$s">%2$s</a>',
				admin_url( 'admin.php?page=chip-for-fluent-forms' ),
				esc_html__( 'Settings', 'chip-for-fluent-forms' )
			),
		);

		return array_merge( $new_links, $links );
	}
}
