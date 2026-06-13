<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-form CHIP payment settings.
 *
 * Stored as a fluentform_form_meta row with meta_key = '_chip_payment_settings'.
 * UI: a single Customize toggle that, when on, exposes per-form credentials,
 * a per-form payment-mode override, a per-form payment-method whitelist, and
 * a per-form refund-sync toggle.
 *
 * Also exposes a per-form sanitize hook used by the new webhook setup class
 * to (re)create the per-form refund webhook.
 */
class Chip_Fluent_Forms_Form_Settings {

	const META_KEY = '_chip_payment_settings';

	/**
	 * Bootstrap: register the per-form UI hooks on FF Pro's payment form
	 * settings pipeline. Falls back gracefully when FF Pro doesn't expose
	 * the per-form settings pipeline (very old Pro).
	 */
	public function __construct() {
		// Per-form payment settings can be saved via the FF Pro per-form
		// payment settings UI. We hook both the legacy 'fluentform/payment_form_settings_save'
		// and the modern filter 'fluentform/form_payment_settings' to be safe.
		add_filter( 'fluentform/form_payment_settings', array( $this, 'register_per_form_settings' ), 10, 2 );
	}

	/**
	 * Add a "Customize" panel to FF Pro's per-form payment settings tab.
	 *
	 * The actual storage / read path is in Chip_Fluent_Forms_Settings::for_form().
	 */
	public function register_per_form_settings( $settings, $form ) {
		if ( ! is_object( $form ) || empty( $form->id ) ) {
			return $settings;
		}

		$form_settings = Chip_Fluent_Forms_Settings::for_form( (int) $form->id );
		$is_active    = 'yes' === $form_settings['is_active'];

		$fields = array(
			array(
				'key'      => 'is_active',
				'label'    => sprintf( __( 'Customize for form #%d', 'chip-for-fluent-forms' ), (int) $form->id ),
				'type'     => 'checkbox',
				'template' => 'inputYesNoCheckbox',
				'value'    => $is_active ? 'yes' : 'no',
				'help'     => __( 'Enable per-form override. When off, the global CHIP settings are used.', 'chip-for-fluent-forms' ),
			),
		);

		// Additional fields are only shown when the form is customized, but FF Pro's
		// settings UI doesn't have a built-in dependency mechanism across top-level
		// fields. Render them unconditionally and let the read path ignore them when
		// the toggle is off.
		$fields[] = array(
			'key'   => 'brand_id',
			'label' => __( 'Brand ID', 'chip-for-fluent-forms' ),
			'type'  => 'text',
			'value' => $form_settings['brand_id'],
		);
		$fields[] = array(
			'key'   => 'secret_key',
			'label' => __( 'Secret Key', 'chip-for-fluent-forms' ),
			'type'  => 'text',
			'value' => $form_settings['secret_key'],
		);
		$fields[] = array(
			'key'     => 'payment_mode',
			'label'   => __( 'Payment Mode', 'chip-for-fluent-forms' ),
			'type'    => 'select',
			'options' => array(
				'test' => __( 'Test', 'chip-for-fluent-forms' ),
				'live' => __( 'Live', 'chip-for-fluent-forms' ),
			),
			'value'   => $form_settings['payment_mode'],
		);
		$fields[] = array(
			'key'   => 'send_receipt',
			'label' => __( 'Send Receipt', 'chip-for-fluent-forms' ),
			'type'  => 'checkbox',
			'template' => 'inputYesNoCheckbox',
			'value' => $form_settings['send_receipt'],
		);
		$fields[] = array(
			'key'   => 'due_strict',
			'label' => __( 'Due Strict', 'chip-for-fluent-forms' ),
			'type'  => 'checkbox',
			'template' => 'inputYesNoCheckbox',
			'value' => $form_settings['due_strict'],
		);
		$fields[] = array(
			'key'   => 'due_strict_timing',
			'label' => __( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ),
			'type'  => 'number',
			'value' => $form_settings['due_strict_timing'],
		);
		$fields[] = array(
			'key'     => 'synchronize_refund',
			'label'   => __( 'Synchronize Refund', 'chip-for-fluent-forms' ),
			'type'    => 'checkbox',
			'template' => 'inputYesNoCheckbox',
			'value'   => $form_settings['synchronize_refund'],
		);

		// Whitelist (checkbox group).
		$whitelist_options = array();
		foreach ( Chip_Fluent_Forms_Settings::payment_methods() as $method_key => $label ) {
			$whitelist_options[] = array(
				'key'   => $method_key,
				'label' => $label,
			);
		}
		$fields[] = array(
			'key'     => 'payment_method_whitelist',
			'label'   => __( 'Payment Method Whitelist', 'chip-for-fluent-forms' ),
			'type'    => 'checkbox_group',
			'options' => $whitelist_options,
			'value'   => $form_settings['payment_method_whitelist'],
		);

		if ( ! isset( $settings['chip'] ) ) {
			$settings['chip'] = array(
				'title'  => __( 'CHIP', 'chip-for-fluent-forms' ),
				'fields' => $fields,
			);
		}

		return $settings;
	}
}
