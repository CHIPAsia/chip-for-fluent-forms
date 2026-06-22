<?php
/**
 * CHIP API client.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
define( 'CHIP_FF_API_ROOT_URL', 'https://gate.chip-in.asia' ); // This is the CHIP API URL endpoint, as documented in: https://docs.chip-in.asia/api.

/**
 * Chip_Fluent_Forms_API — REST client for the CHIP payment gateway API.
 *
 * One instance per unique (secret_key, brand_id) pair. See the CHIP API
 * docs at https://docs.chip-in.asia/api.
 */
class Chip_Fluent_Forms_API {

	/**
	 * Cache of instances keyed by md5(secret_key . brand_id).
	 *
	 * @var Chip_Fluent_Forms_API[]
	 */
	private static $_instances = array();

	/**
	 * Per-form (or global) CHIP API secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Per-form (or global) CHIP brand id.
	 *
	 * @var string
	 */
	private $brand_id;

	/**
	 * Singleton accessor keyed by (secret_key, brand_id).
	 *
	 * @param string $secret_key CHIP API secret key.
	 * @param string $brand_id   CHIP brand id.
	 * @return Chip_Fluent_Forms_API
	 */
	public static function get_instance( $secret_key, $brand_id ) {
		$key = md5( $secret_key . $brand_id );
		if ( ! isset( self::$_instances[ $key ] ) ) {
			self::$_instances[ $key ] = new self( $secret_key, $brand_id );
		}

		return self::$_instances[ $key ];
	}

	/**
	 * Constructor.
	 *
	 * @param string $secret_key CHIP API secret key.
	 * @param string $brand_id   CHIP brand id.
	 * @return void
	 */
	public function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * POST /purchases/ — create a new purchase.
	 *
	 * @param array $params The create-payment payload.
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function create_payment( $params ) {
		// time() is to force fresh instead cache.
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * POST /webhooks/ — register a new webhook.
	 *
	 * @param array $params The create-webhook payload.
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function create_webhook( $params ) {
		// time() is to force fresh instead cache.
		return $this->call( 'POST', '/webhooks/?time=' . time(), $params );
	}

	/**
	 * GET /payment_methods/ — list payment methods available for a brand.
	 *
	 * @param string $currency Three-letter currency code.
	 * @param string $language Two-letter language code.
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function payment_methods( $currency, $language ) {
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
		);
	}

	/**
	 * GET /purchases/{id}/ — fetch a single purchase by id.
	 *
	 * @param string $payment_id CHIP purchase id.
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function get_payment( $payment_id ) {
		// time() is to force fresh instead cache.
		$result = $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
		return $result;
	}

	/**
	 * Convenience helper: did the purchase land in 'paid' status?
	 *
	 * @param string $payment_id CHIP purchase id.
	 * @return bool True if paid, false otherwise (including on error).
	 */
	public function was_payment_successful( $payment_id ) {
		$result = $this->get_payment( $payment_id );
		return $result && 'paid' === $result['status'];
	}

	/**
	 * GET /webhooks/ — list webhooks registered for the brand.
	 *
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function get_webhooks() {
		return $this->call( 'GET', '/webhooks/' );
	}

	/**
	 * POST /purchases/{id}/refund/ — issue a refund.
	 *
	 * @param string $payment_id CHIP purchase id.
	 * @param array  $params     Refund parameters (amount in cents, etc.).
	 * @return mixed Decoded JSON response, or WP_Error.
	 */
	public function refund_payment( $payment_id, $params = array() ) {
		return $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
	}

	/**
	 * Internal: send an HTTP request and decode the JSON response.
	 *
	 * Returns the decoded array on success, a WP_Error on HTTP / JSON /
	 * API-level error.
	 *
	 * @param string $method HTTP method (GET, POST, etc.).
	 * @param string $route  The API route (e.g. '/purchases/').
	 * @param array  $params Request body (will be JSON-encoded if non-empty).
	 * @return mixed Decoded array, or WP_Error.
	 */
	private function call( $method, $route, $params = array() ) {
		$secret_key = $this->secret_key;
		if ( ! empty( $params ) ) {
			$params = wp_json_encode( $params );
		}

		$request = $this->request(
			$method,
			sprintf( '%s/api/v1%s', CHIP_FF_API_ROOT_URL, $route ),
			$params,
			array(
				'Content-type'  => 'application/json',
				'Authorization' => 'Bearer ' . $secret_key,
			)
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		list( $response, $code ) = $request;

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'ff_chip_http_error',
				sprintf( /* translators: 1: HTTP code, 2: method, 3: route */ __( 'CHIP API responded with HTTP %1$d on %2$s %3$s.', 'chip-for-fluent-forms' ), $code, $method, $route ),
				array(
					'status' => $code,
					'body'   => $response,
				)
			);
		}

		if ( '' === trim( (string) $response ) ) {
			return new \WP_Error(
				'ff_chip_empty_response',
				sprintf( /* translators: 1: method, 2: route */ __( 'CHIP API returned an empty response on %1$s %2$s.', 'chip-for-fluent-forms' ), $method, $route )
			);
		}

		$result = json_decode( $response, true );

		if ( null === $result && JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'ff_chip_json_error',
				sprintf(
					/* translators: %s is the underlying json_last_error_msg() string */
					__( 'CHIP API returned malformed JSON: %s', 'chip-for-fluent-forms' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! empty( $result['errors'] ) ) {
			return new \WP_Error(
				'ff_chip_api_error',
				__( 'CHIP API returned errors in the response body.', 'chip-for-fluent-forms' ),
				$result
			);
		}

		return $result;
	}

	/**
	 * Internal: thin wrapper around wp_remote_request that returns
	 * [body, code] for a successful response, or a WP_Error for a
	 * transport-level failure.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $params  Request body.
	 * @param array  $headers Request headers.
	 * @return array|WP_Error
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

		if ( is_wp_error( $wp_request ) ) {
			return $wp_request;
		}

		return array(
			wp_remote_retrieve_body( $wp_request ),
			(int) wp_remote_retrieve_response_code( $wp_request ),
		);
	}
}
