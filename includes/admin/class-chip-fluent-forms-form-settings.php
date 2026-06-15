<?php
/**
 * Per-form CHIP payment settings.
 *
 * Stored as a fluentform_form_meta row with meta_key = '_chip_payment_settings'.
 * UI: a single Customize toggle that, when on, exposes per-form credentials,
 * a per-form payment-mode override, and a per-form payment-method whitelist.
 *
 * Also exposes a per-form sanitize hook (`ff_chip_form_settings_saved`) as a
 * public extension point for downstream integrations.
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
	 * @return void
	 */
	public function __construct() {
		// Render side: a server-side filter that returns a `$settings['chip']`
		// panel. Note that the FF Pro React UI does not currently render these
		// server-side fields, so the display is a no-op until FF Pro's React
		// form-settings UI grows a way to surface per-method subkeys. The
		// save side (below) is what actually wires up per-form persistence.
		add_filter( 'fluentform/form_payment_settings', array( $this, 'register_per_form_settings' ), 10, 2 );

		// Save side: FF Pro's saveFormSettings() runs over the entire
		// fluentform_form_meta `_payment_settings` row and then fires
		// fluentform/after_save_form_settings with the full payload. We
		// pick the `chip` subkey, sanitize it, and persist as our own
		// `_chip_payment_settings` row so reads via Chip_Fluent_Forms_Settings::for_form()
		// stay consistent.
		add_action( 'fluentform/after_save_form_settings', array( $this, 'save_per_form_settings' ), 10, 2 );
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

	/**
	 * Persist the per-form `chip` subkey from the FF Pro payment settings save
	 * payload into our own `_chip_payment_settings` meta row.
	 *
	 * Hooked on fluentform/after_save_form_settings. Receives the full per-form
	 * payment settings array; we extract the `chip` subkey, sanitize, and save.
	 *
	 * @param int   $form_id      Fluent Forms form id.
	 * @param array $all_settings The full per-form payment settings payload.
	 * @return void
	 */
	public function save_per_form_settings( $form_id, $all_settings ) {
		if ( ! is_array( $all_settings ) || empty( $all_settings['chip'] ) || ! is_array( $all_settings['chip'] ) ) {
			return;
		}

		$chip_settings = $all_settings['chip'];

		// Normalize: the React form-settings UI sends booleans for switchers
		// ('true'/'false' strings, not '1'/'0' as our schema expects). Coerce.
		$normalized = array();
		foreach ( $chip_settings as $key => $value ) {
			if ( is_string( $value ) && 'true' === $value ) {
				$normalized[ $key ] = '1';
			} elseif ( is_string( $value ) && 'false' === $value ) {
				$normalized[ $key ] = '0';
			} else {
				$normalized[ $key ] = $value;
			}
		}

		Chip_Fluent_Forms_Settings::save_form( (int) $form_id, $normalized );
	}
}
