<?php
/**
 * Plugin Name: CHIP for Fluent Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-fluent-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.1.3
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 * Requires PHP: 7.4
 * Requires at least: 6.1
 *
 * Copyright: © 2025 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	die; } // Cannot access directly.

define( 'FF_CHIP_MODULE_VERSION', 'v1.1.3' );

/**
 * Main plugin class.
 */
class Chip_Fluent_Forms {

	/**
	 * Single instance of the class.
	 *
	 * @var Chip_Fluent_Forms|null
	 */
	private static $_instance;

	/**
	 * Gets the single instance of the class.
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
	 * Sets up the plugin by registering constants, includes, filters and actions.
	 */
	public function __construct() {
		$this->define();
		$this->includes();
		$this->add_filters();
		$this->add_actions();
	}

	/**
	 * Defines the plugin constants.
	 *
	 * @return void
	 */
	public function define() {
		define( 'FF_CHIP_FILE', __FILE__ );
		define( 'FF_CHIP_BASENAME', plugin_basename( FF_CHIP_FILE ) );
		define( 'FF_CHIP_FSLUG', 'fluent_form_chip' );
	}

	/**
	 * Loads the plugin files.
	 *
	 * @return void
	 */
	public function includes() {
		$includes_dir = plugin_dir_path( FF_CHIP_FILE ) . 'includes/';
		include $includes_dir . 'chip-ff-functions.php';
		include $includes_dir . 'class-chip-fluent-forms-api.php';
		include $includes_dir . 'class-chip-ff-settings.php';
		include $includes_dir . 'class-chip-ff-settings-page.php';

		if ( is_admin() ) {
			include $includes_dir . 'admin/global-settings.php';
			include $includes_dir . 'admin/form-settings.php';
			include $includes_dir . 'admin/backup-settings.php';
			include $includes_dir . 'admin/class-chip-fluent-forms-webhook-setup.php';
		}

		include $includes_dir . 'class-chip-fluent-forms-register.php';
		include $includes_dir . 'class-chip-fluent-forms-purchase.php';
	}

	/**
	 * Registers the plugin filters.
	 *
	 * @return void
	 */
	public function add_filters() {
		add_filter( 'plugin_action_links_' . FF_CHIP_BASENAME, array( $this, 'setting_link' ) );
	}

	/**
	 * Registers the plugin actions.
	 *
	 * @return void
	 */
	public function add_actions() {
	}

	/**
	 * Adds the settings link to the plugin action links.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array
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

add_action( 'init', 'load_chip_for_fluent_forms', 0 );
add_action( 'init', array( 'CHIP_FF_Settings', 'init_pages' ), 100 );

/**
 * Boots the plugin once Fluent Forms Pro is available.
 *
 * @return void
 */
function load_chip_for_fluent_forms() {

	if ( ! class_exists( 'FluentFormPro\Payments\PaymentHelper' ) && ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
		return;
	}

	Chip_Fluent_Forms::get_instance();
}
