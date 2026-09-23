<?php
/**
 * PHPUnit bootstrap for CHIP for Fluent Forms.
 *
 * Fluent Forms Pro is a paid dependency and is not installed in CI, so this
 * bootstrap cannot load the real plugin. It loads the two files that hold the
 * logic under test and stubs the WordPress and Fluent Forms symbols they touch
 * at load time.
 *
 * @package CHIPForFluentForms
 */

define( 'FF_CHIP_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
define( 'FF_CHIP_MODULE_VERSION', 'v1.1.3' );

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress function stubs.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value to cast.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $value Value to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : '';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records registered hooks so tests can assert on them.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 * @return bool
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['ff_test_actions'][] = array( $hook, $callback, $priority, $args );

		return true;
	}
}

// ---------------------------------------------------------------------------
// Fluent Forms / Fluent Forms Pro stubs.
//
// The purchase class extends BaseProcessor and calls PaymentHelper, neither of
// which ships in CI. Declaring them here is what makes the class loadable.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
	eval(
		'namespace FluentFormPro\Payments\PaymentMethods;
		class BaseProcessor {
			public function __construct() {}
			protected function add_action() {}
		}'
	);
}

if ( ! class_exists( 'FluentFormPro\Payments\PaymentHelper' ) ) {
	eval(
		'namespace FluentFormPro\Payments;
		class PaymentHelper {
			public static function getCustomerEmail( $submission, $form ) { return "donor@example.com"; }
			public static function getCustomerName( $submission, $form ) { return "Test Donor"; }
		}'
	);
}

// ---------------------------------------------------------------------------
// Load the code under test.
// ---------------------------------------------------------------------------

require_once FF_CHIP_PLUGIN_PATH . 'includes/class-api.php';
require_once FF_CHIP_PLUGIN_PATH . 'includes/class-purchase.php';
