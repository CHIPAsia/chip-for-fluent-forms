<?php
/**
 * Owns registration of the CHIP payment method with Fluent Forms Pro.
 *
 * - On modern Pro (which ships BasePaymentMethod), the parent's __construct()
 *   wires fluentform/payment_methods_global_settings and fluentform/payment_settings_chip
 *   filters automatically.
 * - On older Pro, we register those same filters manually so behavior is identical.
 *
 * Also boots the global settings page, the per-form settings page, and the
 * processor. Sits at the center of the plugin's runtime graph.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

/**
 * Chip_Fluent_Forms_Handler — see file-level docblock above. Extends
 * BasePaymentMethod so modern Pro wires the global settings filters
 * automatically; this class adds the per-method push, the per-form
 * Customize UI hook, and the processor boot.
 */
class Chip_Fluent_Forms_Handler extends BasePaymentMethod {

	const KEY = 'chip';

	/**
	 * Settings page instance.
	 *
	 * Lazily instantiated in get_settings_page() — the Settings_Page class
	 * is admin-only (loaded behind is_admin()), so the constructor can't
	 * safely instantiate it on every request.
	 *
	 * @var Chip_Fluent_Forms_Settings_Page|null
	 */
	private $settings_page;

	/**
	 * Per-form settings instance.
	 *
	 * Lazily instantiated in get_form_settings() for the same reason as
	 * `$settings_page`.
	 *
	 * @var Chip_Fluent_Forms_Form_Settings|null
	 */
	private $form_settings;

	/**
	 * Processor instance.
	 *
	 * @var Chip_Fluent_Forms_Purchase
	 */
	private $processor;

	/**
	 * Constructor wires the FF Pro settings filters and schedules the
	 * processor boot on plugins_loaded. Settings page and per-form
	 * settings objects are deferred to first use — both are admin-only
	 * and would fatal on non-admin requests if instantiated here.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->key         = self::KEY;
		$this->settingsKey = 'fluent_form_chip_settings';

		// BasePaymentMethod::__construct() registers the global settings + payment
		// settings filters. We hook the remaining pieces afterwards.
		parent::__construct( self::KEY );

		add_action( 'plugins_loaded', array( $this, 'boot_processor' ), 30 );

		// Bridge the FF Pro save flow into our canonical option. FF Pro's
		// savePaymentMethodSettings() writes the settings array to
		// `fluentform_payment_settings_chip` after running
		// `fluentform/payment_method_settings_save_chip`; we mirror the
		// same payload into `fluent_form_chip_settings` so the purchase
		// processor (which reads from our option) sees the latest values.
		// Default save still runs in parallel; nothing in FF Pro consumes
		// `fluentform_payment_settings_chip` for our method, so the
		// duplicate write is harmless.
		add_filter( 'fluentform/payment_method_settings_save_chip', array( $this, 'mirror_settings_to_canonical_option' ), 10, 1 );
	}

	/**
	 * Lazy-instantiate and return the settings page object.
	 *
	 * The Settings_Page class is only loaded when is_admin() is true; this
	 * method is only ever called from FF Pro's admin UI hooks, so the
	 * class will be available.
	 *
	 * @return Chip_Fluent_Forms_Settings_Page|null
	 */
	private function get_settings_page() {
		if ( null === $this->settings_page && class_exists( 'Chip_Fluent_Forms_Settings_Page' ) ) {
			$this->settings_page = new Chip_Fluent_Forms_Settings_Page();
		}
		return $this->settings_page;
	}

	/**
	 * Lazy-instantiate and return the per-form settings object.
	 *
	 * @return Chip_Fluent_Forms_Form_Settings|null
	 */
	private function get_form_settings() {
		if ( null === $this->form_settings && class_exists( 'Chip_Fluent_Forms_Form_Settings' ) ) {
			$this->form_settings = new Chip_Fluent_Forms_Form_Settings();
		}
		return $this->form_settings;
	}

	/**
	 * Late-bind the payment processor.
	 *
	 * Hooked on plugins_loaded priority 30 (after the migration runs at 20
	 * and after FF Pro loads). The processor auto-initializes via
	 * ::get_instance() in its own include file; this method is a no-op
	 * safety net that ensures the class is loadable.
	 *
	 * @return void
	 */
	public function boot_processor() {
		if ( ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
			return;
		}
		if ( ! class_exists( 'Chip_Fluent_Forms_Purchase' ) ) {
			return;
		}
		// Chip_Fluent_Forms_Purchase auto-initializes via ::get_instance() in its own
		// include file; nothing further to do here.
	}

	/**
	 * Return the global fields schema.
	 *
	 * @inheritDoc
	 *
	 * @return array
	 */
	public function getGlobalFields() {
		$settings_page = $this->get_settings_page();
		if ( $settings_page ) {
			return $settings_page->get_fields();
		}
		return array();
	}

	/**
	 * Return the global settings values.
	 *
	 * @inheritDoc
	 *
	 * @return array
	 */
	public function getGlobalSettings() {
		return Chip_Fluent_Forms_Settings::global();
	}

	/**
	 * Push the chip method into fluentform/available_payment_methods.
	 *
	 * Kept as a fallback (also covered by BasePaymentMethod on modern Pro)
	 * so the plugin keeps working on older Pro versions that don't ship it.
	 *
	 * @param array $methods The current list of payment methods.
	 * @return array The augmented list.
	 */
	public function push_payment_method( $methods ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$title    = ! empty( $settings['payment_title'] ) ? $settings['payment_title'] : 'CHIP';

		// Per-form payment_method field schema. FF Pro's React form
		// editor only renders two templates here: inputText (type:
		// 'text') for text inputs and inputYesNoCheckbox (type:
		// 'checkbox') for yes/no toggles. The `dependency` array
		// shows/hides a field based on another field's value (see
		// Stripe's require_billing_info in
		// StripeHandler::pushPaymentMethodToForm).
		$depends_on_customize = array(
			'depends_on' => 'is_active/value',
			'value'      => 'yes',
		);

		$methods[ self::KEY ] = array(
			'title'        => $title,
			'enabled'      => 'yes',
			'method_value' => self::KEY,
			'settings'     => array(
				// Per-method display fields.
				'option_label'             => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => 'Pay with CHIP',
					/* translators: %s: payment method title (e.g. "CHIP") */
					'label'    => __( 'Method Label', 'chip-for-fluent-forms' ),
				),
				'notes'                    => array(
					'type'      => 'text',
					'template'  => 'inputText',
					'value'     => '',
					'label'     => __( 'Notes', 'chip-for-fluent-forms' ),
					/* translators: %s: payment method title */
					'help_text' => __( 'Add payment notes. You can use {inputs.<Name Attribute>} for dynamic values from form fields.', 'chip-for-fluent-forms' ),
				),
				// Per-form credential override fields.
				'is_active'                => array(
					'type'     => 'checkbox',
					'template' => 'inputYesNoCheckbox',
					'value'    => 'no',
					'label'    => __( 'Customize for this form', 'chip-for-fluent-forms' ),
				),
				'brand_id'                 => array(
					'type'       => 'text',
					'template'   => 'inputText',
					'value'      => '',
					'label'      => __( 'Brand ID', 'chip-for-fluent-forms' ),
					'help_text'  => __( 'Leave empty to use the global Brand ID.', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
				'secret_key'               => array(
					'type'       => 'text',
					'template'   => 'inputText',
					'value'      => '',
					'label'      => __( 'Secret Key', 'chip-for-fluent-forms' ),
					'help_text'  => __( 'Leave empty to use the global Secret Key.', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
				'payment_mode'             => array(
					'type'       => 'text',
					'template'   => 'inputText',
					'value'      => '',
					'label'      => __( 'Payment Mode (test or live)', 'chip-for-fluent-forms' ),
					'help_text'  => __( 'Type "test" or "live". Leave empty to use the global setting.', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
				'due_strict'               => array(
					'type'       => 'checkbox',
					'template'   => 'inputYesNoCheckbox',
					'value'      => 'no',
					'label'      => __( 'Due Strict', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
				'due_strict_timing'        => array(
					'type'       => 'text',
					'template'   => 'inputText',
					'value'      => '',
					'label'      => __( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ),
					'help_text'  => __( 'How many minutes a strict-due purchase stays open. Leave empty for global setting.', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
				'payment_method_whitelist' => array(
					'type'       => 'text',
					'template'   => 'inputText',
					'value'      => '',
					'label'      => __( 'Payment Method Whitelist', 'chip-for-fluent-forms' ),
					'help_text'  => __( 'Comma-separated method keys (e.g. fpx,cards,dnqr). Leave empty to let CHIP decide or use the global setting.', 'chip-for-fluent-forms' ),
					'dependency' => $depends_on_customize,
				),
			),
		);

		return $methods;
	}

	/**
	 * Whether the CHIP payment method is currently enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = Chip_Fluent_Forms_Settings::global();
		return isset( $settings['is_active'] ) && 'yes' === $settings['is_active'];
	}

	/**
	 * Mirror FF Pro's save payload into our canonical settings option.
	 *
	 * FF Pro's savePaymentMethodSettings() persists the settings to
	 * `fluentform_payment_settings_chip`. The purchase processor reads
	 * from `fluent_form_chip_settings`. Without this mirror, values the
	 * merchant enters through the new Payment Methods tab never reach
	 * the runtime.
	 *
	 * Filtered on `fluentform/payment_method_settings_save_chip` so it
	 * runs once per save, after FF Pro's sanitize map has been applied.
	 *
	 * @param array $settings The settings array FF Pro is about to persist.
	 * @return array Unchanged; the default save still runs in parallel.
	 */
	public function mirror_settings_to_canonical_option( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		update_option( 'fluent_form_chip_settings', $settings );
		return $settings;
	}
}
