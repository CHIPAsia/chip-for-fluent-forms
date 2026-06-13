<?php
/**
 * Unit tests for Chip_Fluent_Forms_API.
 *
 * @package CHIPForFluentForms
 */

namespace CHIPForFluentForms\Tests\Unit;

use Chip_Fluent_Forms_API;
use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Chip_Fluent_Forms_API
 */
class Chip_ApiTest extends TestCase {

	/**
	 * Set up WP_Mock before each test, and reset the API instance cache.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new \ReflectionClass( Chip_Fluent_Forms_API::class );
		$prop = $ref->getProperty( '_instances' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/**
	 * Tear down WP_Mock after each test.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * get_public_key returns the string body when the API responds 200 with a JSON string.
	 */
	public function test_get_public_key_returns_string_from_json_body(): void {
		$response_body = json_encode( 'simple-key-string' );

		WP_Mock::userFunction( 'wp_remote_request' )
			->once()
			->andReturn( array(
				'body'     => $response_body,
				'response' => array( 'code' => 200 ),
			) );

		WP_Mock::userFunction( 'wp_remote_retrieve_body' )
			->andReturnUsing( function ( $response ) {
				return is_array( $response ) && array_key_exists( 'body', $response ) ? $response['body'] : '';
			} );

		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )
			->andReturnUsing( function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
			} );

		WP_Mock::userFunction( 'apply_filters' )
			->with( 'ff_chip_sslverify', true )
			->andReturn( true );

		$api = Chip_Fluent_Forms_API::get_instance( 'test_secret', 'test_brand' );
		$key = $api->get_public_key();

		$this->assertIsString( $key );
		$this->assertSame( 'simple-key-string', $key );
	}
}
