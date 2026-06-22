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
	 * get_instance() returns the same instance for the same (secret, brand) pair.
	 */
	public function test_get_instance_returns_singleton(): void {
		$api1 = Chip_Fluent_Forms_API::get_instance( 'same_secret', 'same_brand' );
		$api2 = Chip_Fluent_Forms_API::get_instance( 'same_secret', 'same_brand' );

		$this->assertSame( $api1, $api2 );
	}

	/**
	 * get_instance() returns different instances for different (secret, brand) pairs.
	 */
	public function test_get_instance_returns_distinct_instances_for_distinct_credentials(): void {
		$api_a = Chip_Fluent_Forms_API::get_instance( 'secret_a', 'brand_a' );
		$api_b = Chip_Fluent_Forms_API::get_instance( 'secret_b', 'brand_b' );

		$this->assertNotSame( $api_a, $api_b );
	}
}
