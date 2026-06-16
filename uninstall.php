<?php
/**
 * Plugin uninstall handler.
 *
 * Removes every option, per-form meta row, and migration flag this plugin
 * creates. Idempotent — safe to run even if a previous uninstall deleted
 * some of the data.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// New schema.
delete_option( 'fluent_form_chip_settings' );
delete_option( 'fluent_form_chip_migrated' );
delete_option( 'fluent_form_chip_migrated_phase1' );

// Per-form meta rows: _chip_payment_settings, all forms.
// Fluent Forms Pro ships Helper::deleteFormMeta() that handles the
// fluentform_form_meta table, so we first fetch the form ids that have
// our meta row (via wpFluent(), FF Pro's own CRUD layer — PHPCS doesn't
// recognize it as a CRUD helper, hence the targeted phpcs:ignore below)
// and then call the FF Pro helper for each.
if ( class_exists( 'FluentForm\App\Helpers\Helper' ) ) {
	$form_ids = wpFluent()->table( 'fluentform_form_meta' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		->select( 'form_id' )
		->where( 'meta_key', '_chip_payment_settings' )
		->groupBy( 'form_id' )
		->get();

	if ( is_array( $form_ids ) ) {
		foreach ( $form_ids as $row ) {
			$form_id = is_object( $row ) ? (int) $row->form_id : (int) $row['form_id'];
			if ( $form_id > 0 ) {
				\FluentForm\App\Helpers\Helper::deleteFormMeta( $form_id, '_chip_payment_settings' );
			}
		}
	}
}

// Legacy option (defensive — for sites that never went through the migration).
delete_option( 'fluent_form_chip' );
