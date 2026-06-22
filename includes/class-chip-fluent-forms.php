<?php
/**
 * Main plugin bootstrap class.
 *
 * Singleton that defines the plugin constants, includes the runtime
 * classes, registers WP action/filter hooks, and provides the
 * `setting_link` row action on the Plugins list table.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms — main plugin bootstrap.
 *
 * Singleton that defines the plugin constants, includes the runtime
 * classes, registers WP action/filter hooks, and provides the
 * `setting_link` row action on the Plugins list table.
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
		$this->add_admin_hooks();
	}

	/**
	 * Define plugin constants.
	 *
	 * `FF_CHIP_FILE` is expected to be defined by the plugin entry point
	 * before this class is loaded (so the constant resolves to the
	 * entry-point path, not to this class file's path). The other
	 * constants are derived from it.
	 *
	 * @return void
	 */
	public function define() {
		if ( ! defined( 'FF_CHIP_FILE' ) ) {
			define( 'FF_CHIP_FILE', __FILE__ );
		}
		define( 'CHIP_FF_BASENAME', plugin_basename( FF_CHIP_FILE ) );
		define( 'CHIP_FF_FSLUG', 'fluent_form_chip' );
	}

	/**
	 * Include all runtime, admin, and migration classes.
	 *
	 * @return void
	 */
	public function includes() {
		$includes_dir = plugin_dir_path( FF_CHIP_FILE ) . 'includes/';

		// Core runtime.
		include $includes_dir . 'class-chip-fluent-forms-api.php';
		include $includes_dir . 'class-chip-fluent-forms-settings.php';
		include $includes_dir . 'class-chip-fluent-forms-purchase.php';

		// One-time migration from the legacy fluent_form_chip option.
		include $includes_dir . 'admin/class-chip-fluent-forms-migration.php';

		// Handler class is loaded unconditionally so its plugins_loaded hook can
		// register before the priority-30 tick fires. The class itself is a no-op
		// when Fluent Forms Pro is not active.
		include $includes_dir . 'admin/class-chip-fluent-forms-handler.php';

		// Helper-function bootstrap is in its own file so each file declares only one kind of symbol (PSR1.Files.SideEffects).
		include $includes_dir . 'admin/chip-for-fluent-forms-handler-bootstrap.php';

		if ( is_admin() ) {
			include $includes_dir . 'admin/class-chip-fluent-forms-settings-page.php';
			include $includes_dir . 'admin/class-chip-fluent-forms-per-form-page.php';
		}
	}

	/**
	 * Register admin-only WP hooks.
	 *
	 * The per-form page registers itself on `admin_menu` priority 20
	 * (later than FF's default priority 10) so that Fluent Forms Pro
	 * has already populated `$admin_page_hooks['fluent_forms']` with
	 * `'toplevel_page_fluent_forms'` before we call add_submenu_page.
	 * Without this ordering, WP computes the submenu hookname as
	 * `'admin_page_chip-form-settings'` at registration time but
	 * `'toplevel_page_chip-form-settings'` at render time, and the
	 * render-time hookname check returns null — the menu link falls
	 * back to the raw slug (`chip-form-settings` instead of
	 * `admin.php?page=chip-form-settings`).
	 *
	 * @return void
	 */
	public function add_admin_hooks() {
		if ( class_exists( 'Chip_Fluent_Forms_Per_Form_Page' ) ) {
			add_action( 'admin_menu', array( $this, 'register_per_form_page' ), 20 );
		}
	}

	/**
	 * Deferred per-form page registration. Runs on admin_menu priority
	 * 20, after FF's parent menu has been registered.
	 *
	 * @return void
	 */
	public function register_per_form_page() {
		if ( class_exists( 'Chip_Fluent_Forms_Per_Form_Page' ) ) {
			( new Chip_Fluent_Forms_Per_Form_Page() )->register();
		}
	}

	/**
	 * Register WP filters.
	 *
	 * @return void
	 */
	public function add_filters() {
		add_filter( 'plugin_action_links_' . CHIP_FF_BASENAME, array( $this, 'setting_link' ) );
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
				admin_url( 'admin.php?page=fluent_forms_settings#/payment_methods' ),
				esc_html__( 'Settings', 'chip-for-fluent-forms' )
			),
		);

		return array_merge( $new_links, $links );
	}
}
