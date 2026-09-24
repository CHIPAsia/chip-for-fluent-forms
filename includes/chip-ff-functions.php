<?php
/**
 * Shared helper functions for the CHIP settings framework.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'chip_ff_validate_numeric' ) ) {
	/**
	 * Validates that a submitted value is numeric.
	 *
	 * Returning a message means the field keeps its previously stored value;
	 * returning nothing means the value is acceptable.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|void
	 */
	function chip_ff_validate_numeric( $value ) {

		if ( ! is_numeric( $value ) ) {
			return __( 'Please enter a valid number.', 'chip-for-fluent-forms' );
		}
	}
}
