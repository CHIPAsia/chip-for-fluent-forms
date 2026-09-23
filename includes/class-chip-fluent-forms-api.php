<?php
/**
 * CHIP API client.
 *
 * @package CHIPForFluentForms
 */

// This is the CHIP API URL endpoint as documented at https://developer.chip-in.asia/api.
define( 'FLUENT_FORMS_CHIP_ROOT_URL', 'https://gate.chip-in.asia' );

/**
 * CHIP API client class.
 */
class Chip_Fluent_Forms_API {

	/**
	 * Single instance of this class.
	 *
	 * @var Chip_Fluent_Forms_API|null
	 */
	private static $_instance;

	/**
	 * Secret key for API auth.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Brand ID.
	 *
	 * @var string
	 */
	private $brand_id;

	/**
	 * Gets the shared instance, creating it on the first call.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 * @return Chip_Fluent_Forms_API
	 */
	public static function get_instance( $secret_key, $brand_id ) {
		if ( null === self::$_instance ) {
			self::$_instance = new self( $secret_key, $brand_id );
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Secret key.
	 * @param string $brand_id   Brand ID.
	 */
	public function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Creates a payment (purchase).
	 *
	 * @param array $params Purchase params.
	 * @return array|null
	 */
	public function create_payment( $params ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * Creates a webhook.
	 *
	 * @param array $params Webhook params.
	 * @return array|null
	 */
	public function create_webhook( $params ) {
		// time() is to force fresh instead of cache.
		return $this->call( 'POST', '/webhooks/?time=' . time(), $params );
	}

	/**
	 * Gets the available payment methods.
	 *
	 * @param string $currency Currency code.
	 * @param string $language Language code.
	 * @param int    $amount   Amount in minor units (sen).
	 * @return array|null
	 */
	public function payment_methods( $currency, $language, $amount ) {
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}&amount={$amount}"
		);
	}

	/**
	 * Gets a single payment (purchase).
	 *
	 * @param string $payment_id Purchase ID.
	 * @return array|null
	 */
	public function get_payment( $payment_id ) {
		// time() is to force fresh instead of cache.
		$result = $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
		return $result;
	}

	/**
	 * Checks whether a payment has been paid.
	 *
	 * @param string $payment_id Purchase ID.
	 * @return bool
	 */
	public function was_payment_successful( $payment_id ) {
		$result = $this->get_payment( $payment_id );
		return $result && 'paid' === $result['status'];
	}

	/**
	 * Gets the public key (also validates the credentials).
	 *
	 * @return array|string|null
	 */
	public function get_public_key() {
		return $this->call( 'GET', '/public_key/' );
	}

	/**
	 * Gets the configured webhooks.
	 *
	 * @return array|null
	 */
	public function get_webhooks() {
		return $this->call( 'GET', '/webhooks/' );
	}

	/**
	 * Refunds a payment.
	 *
	 * @param string $payment_id Purchase ID.
	 * @param array  $params     Refund params.
	 * @return array|null
	 */
	public function refund_payment( $payment_id, $params = array() ) {
		return $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
	}

	/**
	 * Makes an API call.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  API route.
	 * @param array  $params Request body, encoded to JSON when not empty.
	 * @return array|null Decoded response, or null on any failure.
	 */
	private function call( $method, $route, $params = array() ) {
		$secret_key = $this->secret_key;
		if ( ! empty( $params ) ) {
			$params = wp_json_encode( $params );
		}

		$response = $this->request(
			$method,
			sprintf( '%s/api/v1%s', FLUENT_FORMS_CHIP_ROOT_URL, $route ),
			$params,
			array(
				'Content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . $secret_key,
			)
		);

		$result = json_decode( $response, true );
		if ( ! $result ) {
			return null;
		}

		if ( ! empty( $result['errors'] ) ) {
			return null;
		}

		return $result;
	}

	/**
	 * Sends an HTTP request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $params  Body.
	 * @param array  $headers Headers.
	 * @return string|null Response body, or null on transport failure.
	 */
	private function request( $method, $url, $params = array(), $headers = array() ) {
		$wp_request = wp_remote_request(
			$url,
			array(
				'method'    => $method,
				'sslverify' => apply_filters( 'ff_chip_sslverify', true ),
				'headers'   => $headers,
				'body'      => $params,
			)
		);

		$response = wp_remote_retrieve_body( $wp_request );

		// The status code is not used beyond this point, but it is still
		// retrieved so the response handling stays identical to before.
		$code = wp_remote_retrieve_response_code( $wp_request );

		switch ( $code ) {
			case 200:
			case 201:
				break;
			default:
		}

		return $response;
	}
}
