<?php
/**
 * Plugin text-domain loader and entry-point function.
 *
 * Extracted from the main plugin bootstrap file so each PHP file
 * declares only one kind of symbol (class OR function) and
 * satisfies PSR1.Files.SideEffects.
 *
 * @package CHIPForFluentForms
 */

// phpcs:disable PSR1.Files.SideEffects.FoundNonConditionalLogic -- bootstrap helper file intentionally combines the ABSPATH guard with the function declarations + add_action() calls.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'chip_for_fluent_forms_load_textdomain' );

/**
 * Load the plugin text domain for translations.
 *
 * @return void
 */
function chip_for_fluent_forms_load_textdomain() {
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
