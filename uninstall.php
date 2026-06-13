<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

// New schema.
delete_option( 'fluent_form_chip_settings' );
delete_option( 'fluent_form_chip_migrated' );

// Per-form meta rows: _chip_payment_settings, all forms.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}fluentform_form_meta WHERE meta_key = %s",
		'_chip_payment_settings'
	)
);

// Legacy options (defensive — for sites that never went through the migration).
delete_option( 'fluent_form_chip' );
delete_option( 'fluent_form_chip_public_key' );
