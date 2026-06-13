<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

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
 */
class Chip_Fluent_Forms_Handler extends BasePaymentMethod {

	const KEY = 'chip';

	/** @var Chip_Fluent_Forms_Settings_Page */
	private $settings_page;

	/** @var Chip_Fluent_Forms_Form_Settings */
	private $form_settings;

	/** @var Chip_Fluent_Forms_Purchase */
	private $processor;

	public function __construct() {
		$this->key         = self::KEY;
		$this->settingsKey = 'fluent_form_chip_settings';

		// BasePaymentMethod::__construct() registers the global settings + payment
		// settings filters. We hook the remaining pieces afterwards.
		parent::__construct( self::KEY );

		$this->settings_page = new Chip_Fluent_Forms_Settings_Page();
		$this->form_settings = new Chip_Fluent_Forms_Form_Settings();

		add_action( 'plugins_loaded', array( $this, 'boot_processor' ), 30 );
	}

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
	 * @inheritDoc
	 */
	public function getGlobalFields() {
		if ( $this->settings_page ) {
			return $this->settings_page->get_fields();
		}
		return array();
	}

	/**
	 * @inheritDoc
	 */
	public function getGlobalSettings() {
		return Chip_Fluent_Forms_Settings::global();
	}

	/**
	 * Push the chip method into fluentform/available_payment_methods.
	 *
	 * Kept as a fallback (also covered by BasePaymentMethod on modern Pro)
	 * so the plugin keeps working on older Pro versions that don't ship it.
	 */
	public function push_payment_method( $methods ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$title    = ! empty( $settings['payment_title'] ) ? $settings['payment_title'] : 'CHIP';

		$methods[ self::KEY ] = array(
			'title'        => $title,
			'enabled'      => 'yes',
			'method_value' => self::KEY,
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => 'Pay with CHIP',
					'label'    => __( 'Method Label', 'chip-for-fluent-forms' ),
				),
				'notes' => array(
					'type'      => 'text',
					'template'  => 'inputText',
					'value'     => '',
					'label'     => __( 'Notes', 'chip-for-fluent-forms' ),
					'help_text' => __( 'Add payment notes. You can use {inputs.<Name Attribute>} for dynamic values from form fields.', 'chip-for-fluent-forms' ),
				),
			),
		);

		return $methods;
	}

	public function is_enabled() {
		$settings = Chip_Fluent_Forms_Settings::global();
		return isset( $settings['is_active'] ) && 'yes' === $settings['is_active'];
	}
}

/**
 * Bootstrap the handler.
 *
 * Loaded from chip-for-fluent-forms.php after all includes are present.
 */
function chip_for_fluent_forms_init_handler() {
	if ( ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BasePaymentMethod' ) ) {
		return;
	}
	if ( ! class_exists( 'Chip_Fluent_Forms_Settings' ) ) {
		return;
	}
	if ( ! class_exists( 'Chip_Fluent_Forms_Handler' ) ) {
		return;
	}

	static $instance = null;
	if ( null === $instance ) {
		$instance = new Chip_Fluent_Forms_Handler();

		// Fallback: on older Pro the parent doesn't auto-register the push filter.
		if ( ! has_filter( 'fluentform/available_payment_methods', array( $instance, 'push_payment_method' ) ) ) {
			add_filter( 'fluentform/available_payment_methods', array( $instance, 'push_payment_method' ) );
		}
	}
}
add_action( 'plugins_loaded', 'chip_for_fluent_forms_init_handler', 30 );
