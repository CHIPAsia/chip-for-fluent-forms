<?php
/**
 * Registers the backup and restore settings section.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
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
