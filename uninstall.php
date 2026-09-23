<?php
/**
 * Uninstall routine for CHIP for Fluent Forms.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

delete_option( 'fluent_form_chip' );
delete_option( 'fluent_form_chip_public_key' );
