<?php

/*
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
// This is CHIP API URL Endpoint as per documented in: https://developer.chip-in.asia/api
define("FLUENT_FORMS_CHIP_ROOT_URL", "https://gate.chip-in.asia");

class Chip_Fluent_Forms_API
{
  private static $_instance;
  private $secret_key;
  private $brand_id;

  public static function get_instance( $secret_key, $brand_id ) {
    if ( self::$_instance == null ) {
      self::$_instance = new self( $secret_key, $brand_id );
    }

    return self::$_instance;
  }

  public function __construct( $secret_key, $brand_id )
  {
    $this->secret_key = $secret_key;
    $this->brand_id   = $brand_id;
  }

  public function create_payment($params)
  {
    // time() is to force fresh instead cache
    return $this->call('POST', '/purchases/?time=' . time(), $params);
  }

  public function create_webhook($params)
  {
    // time() is to force fresh instead cache
    return $this->call('POST', '/webhooks/?time=' . time(), $params);
  }

  public function payment_methods($currency, $language)
  {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
    );
  }

  public function get_payment($payment_id)
  {
    // time() is to force fresh instead cache
    $result = $this->call('GET', "/purchases/{$payment_id}/?time=" . time());
    return $result;
  }

  public function was_payment_successful($payment_id)
  {
    $result = $this->get_payment($payment_id);
    return $result && $result['status'] == 'paid';
  }

  public function get_public_key()
  {
    return $this->call('GET', '/public_key/');
  }

  public function get_webhooks()
  {
    return $this->call('GET', '/webhooks/');
  }

  public function refund_payment($payment_id, $params = [])
  {
    return $this->call('POST', "/purchases/{$payment_id}/refund/", $params);
  }

  private function call($method, $route, $params = [])
  {
    $secret_key = $this->secret_key;
    if (!empty($params)) {
      $params = json_encode($params);
    }

    $response = $this->request(
      $method,
      sprintf("%s/api/v1%s", FLUENT_FORMS_CHIP_ROOT_URL, $route),
      $params,
      [
        'Content-type'  => 'application/json',
        'Authorization' => 'Bearer ' . $secret_key,
      ]
    );

    $result = json_decode($response, true);
    if (!$result) {
        return null;
    }

    if (!empty($result['errors'])) {
      return null;
    }

    return $result;
  }

  private function request($method, $url, $params = [], $headers = [])
  {
    $wp_request = wp_remote_request( $url, array(
      'method' => $method,
      'sslverify' => apply_filters( 'ff_chip_sslverify', true),
      'headers' => $headers,
      'body' => $params,
    ));

    $response = wp_remote_retrieve_body($wp_request);

    switch ($code = wp_remote_retrieve_response_code($wp_request)) {
      case 200:
      case 201:
        break;
      default:
    }

    return $response;
  }
}
