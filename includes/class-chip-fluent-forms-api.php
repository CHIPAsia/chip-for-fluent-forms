<?php

/**
 * CHIP API client.
 *
 * @package CHIPForFluentForms
 */

/*
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
// This is CHIP API URL Endpoint as per documented in: https://developer.chip-in.asia/api
define( 'FLUENT_FORMS_CHIP_ROOT_URL', 'https://gate.chip-in.asia' );

class Chip_Fluent_Forms_API {

	private static $_instances = array();
	private $secret_key;
	private $brand_id;

	public static function get_instance( $secret_key, $brand_id ) {
		$key = md5( $secret_key . $brand_id );
		if ( ! isset( self::$_instances[ $key ] ) ) {
			self::$_instances[ $key ] = new self( $secret_key, $brand_id );
		}

		return self::$_instances[ $key ];
	}

	public function __construct( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	public function create_payment( $params ) {
		// time() is to force fresh instead cache
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	public function create_webhook( $params ) {
		// time() is to force fresh instead cache
		return $this->call( 'POST', '/webhooks/?time=' . time(), $params );
	}

	public function payment_methods( $currency, $language ) {
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
		);
	}

	public function get_payment( $payment_id ) {
		// time() is to force fresh instead cache
		$result = $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
		return $result;
	}

	public function was_payment_successful( $payment_id ) {
		$result = $this->get_payment( $payment_id );
		return $result && $result['status'] == 'paid';
	}

	public function get_public_key() {
		return $this->call( 'GET', '/public_key/' );
	}

	public function get_webhooks() {
		return $this->call( 'GET', '/webhooks/' );
	}

	public function refund_payment( $payment_id, $params = array() ) {
		return $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
	}

	private function call( $method, $route, $params = array() ) {
		$secret_key = $this->secret_key;
		if ( ! empty( $params ) ) {
			$params = json_encode( $params );
		}

		$request = $this->request(
			$method,
			sprintf( '%s/api/v1%s', FLUENT_FORMS_CHIP_ROOT_URL, $route ),
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
				sprintf( __( 'CHIP API responded with HTTP %1$d on %2$s %3$s.', 'chip-for-fluent-forms' ), $code, $method, $route ),
				array( 'status' => $code, 'body' => $response )
			);
		}

		if ( '' === trim( (string) $response ) ) {
			return new \WP_Error(
				'ff_chip_empty_response',
				sprintf( __( 'CHIP API returned an empty response on %1$s %2$s.', 'chip-for-fluent-forms' ), $method, $route )
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
