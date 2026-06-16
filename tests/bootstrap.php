<?php
/**
 * PHPUnit bootstrap for CHIP for Fluent Forms.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../vendor/wordpress/wordpress/' );
}

if ( ! defined( 'FF_CHIP_PLUGIN_PATH' ) ) {
	define( 'FF_CHIP_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'FF_CHIP_MODULE_VERSION' ) ) {
	define( 'FF_CHIP_MODULE_VERSION', 'v2.0.0' );
}

$autoload = FF_CHIP_PLUGIN_PATH . 'vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "Run composer install to install test dependencies.\n";
	exit( 1 );
}
require_once $autoload;

\WP_Mock::bootstrap();

// Stub WordPress functions used by the API but not provided by WP_Mock.
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data   Data to encode.
	 * @param int   $options Optional.
	 * @param int   $depth   Optional.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

// WordPress HTTP API stubs used by class-api.php.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = '' ) {
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
	}
}

// Load plugin files
require_once FF_CHIP_PLUGIN_PATH . 'includes/class-chip-fluent-forms-api.php';
