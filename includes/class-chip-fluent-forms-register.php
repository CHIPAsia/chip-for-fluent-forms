<?php
/**
 * FF Pro registration for the CHIP payment method.
 *
 * Restored from the 1.x plugin (commit 2435b25^). Adds `chip` to
 * the `fluentform/available_payment_methods` filter so the form
 * editor and renderer show the method. Reads the `payment-title`
 * field from the codestar option for the display label.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\Framework\Helpers\ArrayHelper;

/**
 * Pushes CHIP into the FF payment-methods registry.
 *
 * Singleton — auto-instantiated at the bottom of this file.
 */
class Chip_Fluent_Forms_Register {

	/**
	 * Singleton instance.
	 *
	 * @var Chip_Fluent_Forms_Register|null
	 */
	private static $_instance;

	/**
	 * Singleton accessor.
	 *
	 * @return Chip_Fluent_Forms_Register
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor: register the push filter.
	 */
	public function __construct() {
		add_filter( 'fluentform/available_payment_methods', array( $this, 'push' ) );
	}

	/**
	 * Add CHIP to the list of available FF payment methods.
	 *
	 * @param array $methods The current list of payment methods (indexed by slug).
	 * @return array The augmented list with `chip` added.
	 */
	public function push( $methods ) {
		$options       = get_option( FF_CHIP_FSLUG );
		$payment_title = ArrayHelper::get( $options, 'payment-title', 'CHIP' );

		$methods['chip'] = array(
			'title'        => $payment_title,
			'enabled'      => 'yes',
			'method_value' => 'chip',
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => 'Pay with CHIP',
					'label'    => 'Method Label',
				),
				'notes'        => array(
					'type'      => 'text',
					'template'  => 'inputText',
					'value'     => '',
					'label'     => 'Notes',
					'help_text' => 'Add payment notes. You can use {inputs.<Name Attribute>} for dynamic values from form fields.',
				),
			),
		);

		return $methods;
	}
}

Chip_Fluent_Forms_Register::get_instance();
