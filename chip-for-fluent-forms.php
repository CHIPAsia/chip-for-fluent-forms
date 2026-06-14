<?php
/**
 * Plugin Name: CHIP for Fluent Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-fluent-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.2.0
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 * Requires PHP: 7.4
 * Requires at least: 6.1
 *
 * Copyright: © 2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FF_CHIP_MODULE_VERSION', 'v1.2.0' );

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
	private static $_instance;

	/**
	 * Singleton accessor.
	 *
	 * @return Chip_Fluent_Forms
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
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
		$this->add_actions();
	}

	/**
	 * Define plugin constants.
	 *
	 * @return void
	 */
	public function define() {
		define( 'FF_CHIP_FILE', __FILE__ );
		define( 'FF_CHIP_BASENAME', plugin_basename( FF_CHIP_FILE ) );
		define( 'FF_CHIP_FSLUG', 'fluent_form_chip' );
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

		// Webhook lookup is needed on the public IPN side too (refund signature
		// verification runs during the public POST), so this is loaded
		// unconditionally alongside the runtime classes.
		include $includes_dir . 'admin/class-chip-fluent-forms-webhook-setup.php';

		// One-time migration from the legacy fluent_form_chip option.
		include $includes_dir . 'admin/class-chip-fluent-forms-migration.php';

		// Handler class is loaded unconditionally so its plugins_loaded hook can
		// register before the priority-30 tick fires. The class itself is a no-op
		// when Fluent Forms Pro is not active.
		include $includes_dir . 'admin/class-chip-fluent-forms-handler.php';

		if ( is_admin() ) {
			include $includes_dir . 'admin/class-chip-fluent-forms-settings-page.php';
			include $includes_dir . 'admin/class-chip-fluent-forms-form-settings.php';
		}
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
	 * Register WP actions (webhook setup hooks).
	 *
	 * @return void
	 */
	public function add_actions() {
		// Trigger webhook setup right after a global save.
		add_action( 'update_option_fluent_form_chip_settings', array( $this, 'after_global_settings_save' ), 10, 2 );
		add_action( 'add_option_fluent_form_chip_settings', array( $this, 'after_global_settings_added' ), 10, 1 );

		// Trigger webhook setup right after a per-form save.
		add_action( 'ff_chip_form_settings_saved', array( $this, 'after_form_settings_saved' ), 10, 2 );
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

	/**
	 * Hooked on update_option_{option}: re-create the CHIP refund webhook
	 * when global refund-sync is on.
	 *
	 * @param mixed $old_value The previous option value.
	 * @param mixed $new_value The new option value.
	 * @return void
	 */
	public function after_global_settings_save( $old_value, $new_value ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Webhook_Setup' ) ) {
			return;
		}
		Chip_Fluent_Forms_Webhook_Setup::setup_for_global_settings( $new_value );
	}

	/**
	 * Hooked on add_option_{option}: same as after_global_settings_save but
	 * for the first-write path (WP doesn't fire update_option for new
	 * options).
	 *
	 * @param mixed $option The newly-added option value.
	 * @return void
	 */
	public function after_global_settings_added( $option ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Webhook_Setup' ) ) {
			return;
		}
		Chip_Fluent_Forms_Webhook_Setup::setup_for_global_settings( $option );
	}

	/**
	 * Hooked on ff_chip_form_settings_saved: re-create the per-form CHIP
	 * refund webhook when the per-form refund-sync toggle is on.
	 *
	 * @param int   $form_id  Fluent Forms form id.
	 * @param array $settings The sanitized per-form settings.
	 * @return void
	 */
	public function after_form_settings_saved( $form_id, $settings ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Webhook_Setup' ) ) {
			return;
		}
		Chip_Fluent_Forms_Webhook_Setup::setup_for_form_settings( (int) $form_id, $settings );
	}
}

add_action( 'plugins_loaded', 'chip_for_fluent_forms_load_textdomain' );

/**
 * Load the plugin text domain for translations.
 *
 * @return void
 */
function chip_for_fluent_forms_load_textdomain() { // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbol -- plugin entry-point file intentionally defines helper functions after the bootstrap class.
	load_plugin_textdomain( 'chip-for-fluent-forms', false, dirname( FF_CHIP_BASENAME ) . '/languages/' );
}

add_action( 'init', 'load_chip_for_fluent_forms', 0 );

/**
 * Plugin entry point.
 *
 * Gates on Fluent Forms Pro's PaymentHelper or BaseProcessor class being
 * available; without either, the plugin short-circuits as a no-op.
 *
 * @return void
 */
function load_chip_for_fluent_forms() {

	if ( ! class_exists( 'FluentFormPro\Payments\PaymentHelper' ) && ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
		return;
	}

	Chip_Fluent_Forms::get_instance();
}
