<?php
/**
 * Bootstrap the FF Pro handler and register the per-method push filter.
 *
 * Extracted from class-chip-fluent-forms-handler.php into its own file
 * so each file declares only one kind of symbol (class OR function) and
 * satisfies PSR1.Files.SideEffects.
 *
 * @package CHIPForFluentForms
 */

// phpcs:disable PSR1.Files.SideEffects.FoundNonConditionalLogic -- bootstrap helper file intentionally combines the ABSPATH guard with the function declaration + add_action() call.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiate the FF Pro handler and (on older Pro) register the push filter.
 *
 * @return void
 */
function chip_for_fluent_forms_init_handler() {
	if ( ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BasePaymentMethod' ) ) {
		return;
	}
	if ( ! class_exists( 'Chip_Fluent_Forms_Settings' ) ) {
		return;
	}
	if ( ! class_exists( 'Chip_Fluent_Forms_Handler' ) ) {
		return;
	}

	static $instance = null;
	if ( null === $instance ) {
		$instance = new Chip_Fluent_Forms_Handler();

		// Fallback: on older Pro the parent doesn't auto-register the push filter.
		if ( ! has_filter( 'fluentform/available_payment_methods', array( $instance, 'push_payment_method' ) ) ) {
			add_filter( 'fluentform/available_payment_methods', array( $instance, 'push_payment_method' ) );
		}
	}
}
add_action( 'plugins_loaded', 'chip_for_fluent_forms_init_handler', 30 );
// phpcs:enable
