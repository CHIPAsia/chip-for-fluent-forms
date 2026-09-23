<?php
/**
 * CHIP webhook registration and public key storage.
 *
 * @package CHIPForFluentForms
 */

/**
 * Registers the CHIP webhooks used for refund notifications and stores their public keys.
 */
class Chip_Fluent_Forms_Webhook_Setup {

	/**
	 * Single instance of the class.
	 *
	 * @var Chip_Fluent_Forms_Webhook_Setup|null
	 */
	private static $_instance;

	/**
	 * Webhooks fetched from CHIP, keyed by secret key.
	 *
	 * @var array
	 */
	private $results = array();

	/**
	 * Gets the single instance of the class.
	 *
	 * @return Chip_Fluent_Forms_Webhook_Setup
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'csf_fluent_form_chip_save_before', array( $this, 'setup_public_key' ), 10, 2 );
	}

	/**
	 * Stores the CHIP webhook public keys when the plugin settings are saved.
	 *
	 * Hooked to `csf_fluent_form_chip_save_before`, which passes the submitted
	 * settings and the Codestar Framework option instance positionally.
	 *
	 * @param array  $data         Submitted plugin settings.
	 * @param object $admin_option Codestar Framework option instance.
	 * @return void
	 */
	public function setup_public_key( $data, $admin_option ) {

		$this->global_public_key( $data, $admin_option );
		$this->form_public_key( $data, $admin_option );
	}

	/**
	 * Registers the global CHIP webhook and stores its public key.
	 *
	 * @param array  $data         Submitted plugin settings.
	 * @param object $admin_option Codestar Framework option instance (unused).
	 * @return void
	 */
	private function global_public_key( $data, $admin_option ) {

		if ( empty( $data['refund'] ) ) {
			return;
		}

		if ( false === $data['refund'] ) {
			return;
		}

		$chip     = Chip_Fluent_Forms_API::get_instance( $data['secret-key'], '' );
		$webhooks = $chip->get_webhooks();

		// get_webhooks() returns null when the call fails, and
		// array_key_exists() on that null is a TypeError (not an Exception).
		if ( ! is_array( $webhooks ) || ! array_key_exists( 'results', $webhooks ) ) {
			return;
		}

		$this->results = array(
			$data['secret-key'] => $webhooks,
		);

		$public_key    = '';
		$found_webhook = false;

		foreach ( $webhooks['results'] as $webhook ) {
			if ( 'CHIP for Fluent Forms' === $webhook['title'] ) {
				$public_key    = str_replace( '\n', "\n", $webhook['public_key'] );
				$found_webhook = true;
				break;
			}
		}

		if ( ! $found_webhook ) {
			$webhook = $chip->create_webhook(
				array(
					'title'      => 'CHIP for Fluent Forms',
					'all_events' => false,
					'events'     => array( 'payment.refunded' ),
					'callback'   => $this->get_callback_url(),
				)
			);

			$public_key = str_replace( '\n', "\n", $webhook['public_key'] );
		}

		if ( empty( $public_key ) ) {
			return;
		}

		$wp_option = get_option( 'fluent_form_chip_public_key', array() );

		$wp_option['public-key'] = $public_key;

		update_option( 'fluent_form_chip_public_key', $wp_option, false );
	}

	/**
	 * Registers the per-form CHIP webhook and stores its public key.
	 *
	 * @param array  $data         Submitted plugin settings.
	 * @param object $admin_option Codestar Framework option instance (unused).
	 * @return void
	 */
	private function form_public_key( $data, $admin_option ) {

		$form_ids = wpFluent()->table( 'fluentform_forms' )
		->select( 'id' )
		->orderBy( 'id' )
		->limit( 500 )
		->get();

		foreach ( $form_ids as $form ) {
			if ( $data[ 'form-customize-' . $form->id ] ) {

				if ( empty( $data[ 'refund-' . $form->id ] ) ) {
					continue;
				}

				if ( false === $data[ 'refund-' . $form->id ] ) {
					continue;
				}

				if ( array_key_exists( $data[ 'secret-key-' . $form->id ], $this->results ) ) {
					$webhooks = $this->results[ $data[ 'secret-key-' . $form->id ] ];
				} else {
					$chip     = Chip_Fluent_Forms_API::get_instance( $data['secret-key'], '' );
					$webhooks = $chip->get_webhooks();
				}

				if ( ! is_array( $webhooks ) || ! array_key_exists( 'results', $webhooks ) ) {
					continue;
				}

				$this->results = array(
					$data[ 'secret-key-' . $form->id ] => $webhooks,
				);

				$public_key    = '';
				$found_webhook = false;

				foreach ( $webhooks['results'] as $webhook ) {
					if ( 'CHIP for GiveWP' === $webhook['title'] ) {
						$public_key    = str_replace( '\n', "\n", $webhook['public_key'] );
						$found_webhook = true;
						break;
					}
				}

				if ( ! $found_webhook ) {
					$webhook = $chip->create_webhook(
						array(
							'title'      => 'CHIP for GiveWP',
							'all_events' => false,
							'events'     => array( 'payment.refunded' ),
							'callback'   => $this->get_callback_url(),
						)
					);

					$public_key = str_replace( '\n', "\n", $webhook['public_key'] );
				}

				if ( empty( $public_key ) ) {
					return;
				}

				$wp_option = get_option( 'fluent_form_chip_public_key', array() );

				$wp_option[ 'public-key-' . $form->id ] = $public_key;

				update_option( 'fluent_form_chip_public_key', $wp_option, false );
			}
		}
	}

	/**
	 * Builds the Fluent Forms payment API notify URL CHIP calls on refunds.
	 *
	 * @return string
	 */
	private function get_callback_url() {
		return add_query_arg(
			array(
				'fluentform_payment_api_notify' => 1,
				'payment_method'                => 'chip',
			),
			site_url( 'index.php' )
		);
	}
}

Chip_Fluent_Forms_Webhook_Setup::get_instance();
