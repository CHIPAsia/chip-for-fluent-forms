<?php
/**
 * Per-form CHIP payment settings.
 *
 * Adds a custom `chip` sub-panel to FF Pro's per-form payment settings
 * filter. FF Pro's React form-settings UI does not currently surface
 * these server-side fields, so the panel is invisible in the form
 * editor.
 *
 * **Per-form credential overrides are now edited directly on the
 * per-form `payment_method` field**, alongside Method Label and Notes
 * (see `Chip_Fluent_Forms_Handler::push_payment_method()`). The
 * render filter here is kept for downstream integrations and as a
 * future extension point.
 *
 * Backward compat: for 1.x → 2.0 upgrades, the migration
 * (`class-chip-fluent-forms-migration.php`) creates per-form
 * `_chip_payment_settings` rows. The runtime falls back to these
 * rows when the per-form `payment_method` field has no override.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Form_Settings — per-form Customize panel and save handler.
 *
 * @see file-level docblock at the top of the file.
 */
class Chip_Fluent_Forms_Form_Settings {

	const META_KEY = '_chip_payment_settings';

	/**
	 * Bootstrap: register the per-form UI hooks on FF Pro's payment form
	 * settings pipeline. Falls back gracefully when FF Pro doesn't expose
	 * the per-form settings pipeline (very old Pro).
	 *
	 * Per-form credentials are now edited directly on the per-form
	 * `payment_method` field (see `Chip_Fluent_Forms_Handler::push_payment_method`).
	 * The render-only filter here stays for downstream integrations
	 * that read FF Pro's per-form settings payload and may want to
	 * surface our own customize panel.
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'fluentform/form_payment_settings', array( $this, 'register_per_form_settings' ), 10, 2 );
	}

	/**
	 * Add a "Customize" panel to FF Pro's per-form payment settings tab.
	 *
	 * The actual storage / read path is in Chip_Fluent_Forms_Settings::for_form().
	 *
	 * @param array  $settings The current per-method settings array, indexed by method.
	 * @param object $form     The Fluent Forms form object.
	 * @return array The augmented settings array.
	 */
	public function register_per_form_settings( $settings, $form ) {
		if ( ! is_object( $form ) || empty( $form->id ) ) {
			return $settings;
		}

		$form_settings = Chip_Fluent_Forms_Settings::for_form( (int) $form->id );
		$is_active     = 'yes' === $form_settings['is_active'];

		$fields = array(
			array(
				'key'      => 'is_active',
				'label'    => sprintf(
					/* translators: %d: form id */
					__( 'Customize for form #%d', 'chip-for-fluent-forms' ),
					(int) $form->id
				),
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
			'key'      => 'due_strict',
			'label'    => __( 'Due Strict', 'chip-for-fluent-forms' ),
			'type'     => 'checkbox',
			'template' => 'inputYesNoCheckbox',
			'value'    => $form_settings['due_strict'],
		);
		$fields[] = array(
			'key'   => 'due_strict_timing',
			'label' => __( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ),
			'type'  => 'number',
			'value' => $form_settings['due_strict_timing'],
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
