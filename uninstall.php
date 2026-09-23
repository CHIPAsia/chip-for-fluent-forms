<?php
/**
 * Uninstall routine for CHIP for Fluent Forms.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

/**
 * Deletes the plugin options for the current site.
 *
 * The plugin is not loaded during uninstall, so the option names are written
 * out literally rather than read from FF_CHIP_FSLUG.
 *
 * @return void
 */
function ff_chip_uninstall_site() {
	delete_option( 'fluent_form_chip' );
	delete_option( 'fluent_form_chip_public_key' );
}

if ( is_multisite() ) {
	$ff_chip_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ff_chip_site_ids as $ff_chip_site_id ) {
		switch_to_blog( $ff_chip_site_id );
		ff_chip_uninstall_site();
		restore_current_blog();
	}
} else {
	ff_chip_uninstall_site();
}
