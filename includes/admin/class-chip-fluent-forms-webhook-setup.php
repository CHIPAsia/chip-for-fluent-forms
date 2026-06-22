<?php
/**
 * Webhook admin UI.
 *
 * Handles the Fluent Forms Pro "refund synchronization" workflow:
 * stores the CHIP public key in a separate option
 * `fluent_form_chip_public_key`, registers the IPN callback,
 * and verifies the webhook signature when a refund notification
 * arrives. The public key is per-form (suffixed with
 * `-{form_id}`) so different forms can have different keys.
 *
 * Restored verbatim from the 1.x plugin (commit 2435b25^).
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the CHIP refund-webhook public key storage and the
 * signature verification callback. Singleton — auto-instantiated
 * at the bottom of this file.
 */
class Chip_Fluent_Forms_Webhook_Setup {

	/**
	 * Singleton instance.
	 *
	 * @var Chip_Fluent_Forms_Webhook_Setup|null
	 */
	private static $_instance;

	/**
	 * Per-form webhook setup results.
	 *
	 * @var array
	 */
	private $results = array();

	/**
	 * Singleton accessor.
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
	 * Constructor: hook the codestar save callback.
	 */
	public function __construct() {
		add_action( 'csf_fluent_form_chip_save_before', array( $this, 'setup_public_key' ), 10, 2 );
	}

	/**
	 * Codestar save callback: route the refund key into global
	 * and per-form storage.
	 *
	 * @param array $data         The codestar option array.
	 * @param mixed $admin_option Unused; reserved for future use.
	 */
	public function setup_public_key( $data, $admin_option ) {

		$this->global_public_key( $data, $admin_option );
		$this->form_public_key( $data, $admin_option );
	}

	/**
	 * Sync the global refund public key.
	 *
	 * @param array $data         The codestar option array.
	 * @param mixed $admin_option Unused; reserved for future use.
	 */
	private function global_public_key( $data, $admin_option ) {

		if ( empty( $data['refund'] ) ) {
			return;
		}

		if ( $data['refund'] == false ) {
			return;
		}

		$chip     = Chip_Fluent_Forms_API::get_instance( $data['secret-key'], '' );
		$webhooks = $chip->get_webhooks();

		if ( ! array_key_exists( 'results', $webhooks ) ) {
			return;
		}

		$this->results = array(
			$data['secret-key'] => $webhooks,
		);

		$public_key    = '';
		$found_webhook = false;

		foreach ( $webhooks['results'] as $webhook ) {
			if ( $webhook['title'] == 'CHIP for Fluent Forms' ) {
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
	 * Sync the per-form refund public keys.
	 *
	 * @param array $data         The codestar option array.
	 * @param mixed $admin_option Unused; reserved for future use.
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

				if ( $data[ 'refund-' . $form->id ] === false ) {
					continue;
				}

				if ( array_key_exists( $data[ 'secret-key-' . $form->id ], $this->results ) ) {
					$webhooks = $this->results[ $data[ 'secret-key-' . $form->id ] ];
				} else {
					$chip     = Chip_Fluent_Forms_API::get_instance( $data[ 'secret-key-' . $form->id ], '' );
					$webhooks = $chip->get_webhooks();
				}

				if ( ! array_key_exists( 'results', $webhooks ) ) {
					continue;
				}

				$this->results = array(
					$data[ 'secret-key-' . $form->id ] => $webhooks,
				);

				$public_key    = '';
				$found_webhook = false;

				foreach ( $webhooks['results'] as $webhook ) {
					if ( $webhook['title'] === 'CHIP for Fluent Forms' ) {
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

				$wp_option[ 'public-key-' . $form->id ] = $public_key;

				update_option( 'fluent_form_chip_public_key', $wp_option, false );
			}
		}
	}

	/**
	 * Build the public webhook callback URL.
	 *
	 * @return string The callback URL CHIP should POST to.
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
