<?php
/**
 * Adds CHIP to the Fluent Forms payment methods.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use FluentForm\Framework\Helpers\ArrayHelper;

/**
 * Registers CHIP as an available Fluent Forms payment method.
 */
class Chip_Fluent_Forms_Register {

	/**
	 * Single instance of the class.
	 *
	 * @var Chip_Fluent_Forms_Register|null
	 */
	private static $_instance;

	/**
	 * Gets the single instance of the class.
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
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'fluentform/available_payment_methods', array( $this, 'push' ) );
	}

	/**
	 * Adds the CHIP payment method to the available payment methods list.
	 *
	 * @param array $methods Registered payment methods.
	 * @return array
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
					'help_text' => 'Additional notes attached to the CHIP purchase. You can use {inputs.<Field Name>} to pull a value from a form field.',
				),
			),
		);

		return $methods;
	}
}

Chip_Fluent_Forms_Register::get_instance();
