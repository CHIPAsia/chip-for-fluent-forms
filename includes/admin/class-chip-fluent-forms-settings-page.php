<?php
/**
 * Global CHIP settings page.
 *
 * Hooked into Fluent Forms' native Payment Methods tab (via
 * fluentform/payment_methods_global_settings and the BasePaymentMethod
 * contract). The page itself is also capable of rendering standalone
 * under the Fluent Forms submenu if FF Pro is too old to render the
 * native tab.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Settings_Page — see file-level docblock above.
 *
 * Owns the field schema consumed by FF Pro's native Payment Methods tab
 * and the (optional) standalone settings page render.
 */
class Chip_Fluent_Forms_Settings_Page {

	const OPTION_GROUP = 'fluentform_chip_settings_group';

	/**
	 * Return the field schema consumed by FF Pro's native Payment Methods tab.
	 *
	 * @return array
	 */
	public function get_fields() {
		$methods = Chip_Fluent_Forms_Settings::payment_methods();
		$whitelist_options = array();
		foreach ( $methods as $key => $label ) {
			$whitelist_options[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		return array(
			array(
				'key'      => 'is_active',
				'label'    => __( 'Enable CHIP', 'chip-for-fluent-forms' ),
				'type'     => 'checkbox',
				'template' => 'inputYesNoCheckbox',
				'value'    => 'yes',
				'help'     => __( 'Globally enable or disable the CHIP payment method.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'payment_mode',
				'label' => __( 'Payment Mode', 'chip-for-fluent-forms' ),
				'type'  => 'select',
				'options' => array(
					'test' => __( 'Test', 'chip-for-fluent-forms' ),
					'live' => __( 'Live', 'chip-for-fluent-forms' ),
				),
				'help'  => __( 'Test purchases do not charge real cards and use the CHIP sandbox.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'brand_id',
				'label' => __( 'Brand ID', 'chip-for-fluent-forms' ),
				'type'  => 'text',
				'help'  => __( 'Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'secret_key',
				'label' => __( 'Secret Key', 'chip-for-fluent-forms' ),
				'type'  => 'text',
				'help'  => __( 'Secret key is used to identify your account with CHIP. You are recommended to create a dedicated secret key for each website.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'payment_title',
				'label' => __( 'Payment Title', 'chip-for-fluent-forms' ),
				'type'  => 'text',
				'value' => 'CHIP',
				'help'  => __( 'This allows you to customize the payment title shown to the user.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'send_receipt',
				'label' => __( 'Send Receipt', 'chip-for-fluent-forms' ),
				'type'  => 'checkbox',
				'template' => 'inputYesNoCheckbox',
				'value' => '0',
				'help'  => __( 'Whether to send a receipt email when the purchase is paid. The receipt email is sent by CHIP.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'due_strict',
				'label' => __( 'Due Strict', 'chip-for-fluent-forms' ),
				'type'  => 'checkbox',
				'template' => 'inputYesNoCheckbox',
				'value' => '1',
				'help'  => __( 'When on, the purchase expires after the strict-due timing instead of becoming overdue.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'due_strict_timing',
				'label' => __( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ),
				'type'  => 'number',
				'value' => '60',
				'help'  => __( 'How many minutes a strict-due purchase stays open. Defaults to 60.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'      => 'payment_method_whitelist',
				'label'    => __( 'Payment Method Whitelist', 'chip-for-fluent-forms' ),
				'type'     => 'checkbox_group',
				'options'  => $whitelist_options,
				'help'     => __( 'Pick which payment methods to allow at checkout. Leave empty to let CHIP decide.', 'chip-for-fluent-forms' ),
			),
			array(
				'key'   => 'synchronize_refund',
				'label' => __( 'Synchronize Refund', 'chip-for-fluent-forms' ),
				'type'  => 'checkbox',
				'template' => 'inputYesNoCheckbox',
				'value' => '0',
				'help'  => __( 'When on, refunds triggered on the CHIP dashboard are applied to the Fluent Forms submission.', 'chip-for-fluent-forms' ),
			),
		);
	}

	/**
	 * Render the standalone settings page (fallback when FF Pro's native
	 * Payment Methods tab is not available).
	 */
	public function render_standalone_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'chip-for-fluent-forms' ) );
		}

		$settings = Chip_Fluent_Forms_Settings::global();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CHIP for Fluent Forms', 'chip-for-fluent-forms' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'chip-for-fluent-forms' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register the settings, sections, and fields used by the standalone page.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'fluent_form_chip_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Chip_Fluent_Forms_Settings', 'sanitize_global' ),
				'default'           => Chip_Fluent_Forms_Settings::global_defaults(),
			)
		);

		add_settings_section(
			'chip_global_credentials',
			__( 'Credentials', 'chip-for-fluent-forms' ),
			function () {
				echo '<p>' . esc_html__( 'Configure your CHIP Brand ID and Secret Key.', 'chip-for-fluent-forms' ) . '</p>';
			},
			'chip-for-fluent-forms'
		);

		add_settings_field(
			'brand_id',
			__( 'Brand ID', 'chip-for-fluent-forms' ),
			array( $this, 'field_text' ),
			'chip-for-fluent-forms',
			'chip_global_credentials',
			array( 'key' => 'brand_id' )
		);

		add_settings_field(
			'secret_key',
			__( 'Secret Key', 'chip-for-fluent-forms' ),
			array( $this, 'field_text' ),
			'chip-for-fluent-forms',
			'chip_global_credentials',
			array( 'key' => 'secret_key' )
		);

		add_settings_section(
			'chip_global_misc',
			__( 'Miscellaneous', 'chip-for-fluent-forms' ),
			'__return_false',
			'chip-for-fluent-forms'
		);

		add_settings_field(
			'payment_mode',
			__( 'Payment Mode', 'chip-for-fluent-forms' ),
			array( $this, 'field_select' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array(
				'key'     => 'payment_mode',
				'options' => array(
					'test' => __( 'Test', 'chip-for-fluent-forms' ),
					'live' => __( 'Live', 'chip-for-fluent-forms' ),
				),
			)
		);

		add_settings_field(
			'payment_title',
			__( 'Payment Title', 'chip-for-fluent-forms' ),
			array( $this, 'field_text' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array( 'key' => 'payment_title' )
		);

		add_settings_field(
			'send_receipt',
			__( 'Send Receipt', 'chip-for-fluent-forms' ),
			array( $this, 'field_checkbox' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array( 'key' => 'send_receipt' )
		);

		add_settings_field(
			'due_strict',
			__( 'Due Strict', 'chip-for-fluent-forms' ),
			array( $this, 'field_checkbox' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array( 'key' => 'due_strict' )
		);

		add_settings_field(
			'due_strict_timing',
			__( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ),
			array( $this, 'field_number' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array( 'key' => 'due_strict_timing' )
		);

		add_settings_field(
			'payment_method_whitelist',
			__( 'Payment Method Whitelist', 'chip-for-fluent-forms' ),
			array( $this, 'field_checkbox_group' ),
			'chip-for-fluent-forms',
			'chip_global_misc',
			array( 'key' => 'payment_method_whitelist' )
		);

		add_settings_section(
			'chip_global_refund',
			__( 'Refund Synchronization', 'chip-for-fluent-forms' ),
			'__return_false',
			'chip-for-fluent-forms'
		);

		add_settings_field(
			'synchronize_refund',
			__( 'Synchronize Refund', 'chip-for-fluent-forms' ),
			array( $this, 'field_checkbox' ),
			'chip-for-fluent-forms',
			'chip_global_refund',
			array( 'key' => 'synchronize_refund' )
		);
	}

	/**
	 * Generic text input.
	 *
	 * @param array $args The WP Settings API field args (expects a 'key').
	 */
	public function field_text( $args ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		printf(
			'<input type="text" class="regular-text" name="fluent_form_chip_settings[%1$s]" value="%2$s" />',
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	/**
	 * Numeric input.
	 *
	 * @param array $args The WP Settings API field args (expects a 'key').
	 */
	public function field_number( $args ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		printf(
			'<input type="number" min="1" name="fluent_form_chip_settings[%1$s]" value="%2$s" />',
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	/**
	 * Select input.
	 *
	 * @param array $args The WP Settings API field args (expects 'key' and 'options').
	 */
	public function field_select( $args ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		?>
		<select name="fluent_form_chip_settings[<?php echo esc_attr( $key ); ?>]">
			<?php foreach ( $args['options'] as $opt_key => $opt_label ) : ?>
				<option value="<?php echo esc_attr( $opt_key ); ?>" <?php selected( $value, $opt_key ); ?>>
					<?php echo esc_html( $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Checkbox input.
	 *
	 * @param array $args The WP Settings API field args (expects a 'key').
	 */
	public function field_checkbox( $args ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$key      = $args['key'];
		$value    = ! empty( $settings[ $key ] );
		printf(
			'<label><input type="checkbox" name="fluent_form_chip_settings[%1$s]" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( $value, true, false ),
			esc_html__( 'Enabled', 'chip-for-fluent-forms' )
		);
	}

	/**
	 * Checkbox group for the payment method whitelist.
	 *
	 * @param array $args The WP Settings API field args (expects a 'key').
	 */
	public function field_checkbox_group( $args ) {
		$settings = Chip_Fluent_Forms_Settings::global();
		$key      = $args['key'];
		$selected = isset( $settings[ $key ] ) ? (array) $settings[ $key ] : array();

		echo '<fieldset>';
		foreach ( Chip_Fluent_Forms_Settings::payment_methods() as $method_key => $label ) {
			$is_on = ! empty( $selected[ $method_key ] );
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="fluent_form_chip_settings[%1$s][%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( $key ),
				esc_attr( $method_key ),
				checked( $is_on, true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}
}
