<?php
/**
 * Codestar backup/restore sub-page.
 *
 * Renders the codestar framework's built-in backup section for
 * the `fluent_form_chip` option. Imports and exports the full
 * settings payload as a base64-encoded JSON blob.
 *
 * Restored verbatim from the 1.x plugin (commit 2435b25^).
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slug = FF_CHIP_FSLUG;

CSF_Setup::createSection(
	$slug,
	array(
		'id'          => 'backup-restore',
		'title'       => __( 'Backup and Restore', 'chip-for-fluent-forms' ),
		'icon'        => 'fa fa-copy',
		'description' => __( 'Backup and Restore your configuration.', 'chip-for-fluent-forms' ),
		'fields'      => array(
			array(
				'id'   => 'backup',
				'type' => 'backup',
			),
		),
	)
);
