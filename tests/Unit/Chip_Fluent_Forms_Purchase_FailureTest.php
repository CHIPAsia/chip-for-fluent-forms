<?php
/**
 * Regression tests for the two purchase-creation defects.
 *
 * 1. `due` was sent as time() when the Timing setting was empty, which CHIP
 *    rejects with HTTP 400 due_not_greater_than_now.
 * 2. Chip_Fluent_Forms_API::call() returns null for every failure shape, but
 *    the call sites read it as an array. array_key_exists() on that null is a
 *    TypeError on PHP 8 - not an Exception - and the plugin has no try/catch
 *    anywhere, so the donor got a fatal error instead of a message.
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the due-timing resolver and the API-response guard.
 */
class Chip_Fluent_Forms_Purchase_FailureTest extends TestCase {

	/**
	 * The exact bug: an unset timing produced a `due` equal to "now".
	 */
	public function test_due_timestamp_is_null_when_timing_is_empty_string() {
		$this->assertNull(
			Chip_Fluent_Forms_Purchase::resolve_due_timestamp( '' ),
			'An empty timing means "no due limit" and must not produce a timestamp.'
		);
	}

	/**
	 * The key is absent entirely on a per-form config that never saved it.
	 */
	public function test_due_timestamp_is_null_when_timing_is_null() {
		$this->assertNull( Chip_Fluent_Forms_Purchase::resolve_due_timestamp( null ) );
	}

	/**
	 * An explicit zero is the same "disabled" intent as an empty field.
	 */
	public function test_due_timestamp_is_null_when_timing_is_zero() {
		$this->assertNull( Chip_Fluent_Forms_Purchase::resolve_due_timestamp( '0' ) );
		$this->assertNull( Chip_Fluent_Forms_Purchase::resolve_due_timestamp( 0 ) );
	}

	/**
	 * A value WordPress could return for a missing option.
	 */
	public function test_due_timestamp_is_null_when_timing_is_false() {
		$this->assertNull( Chip_Fluent_Forms_Purchase::resolve_due_timestamp( false ) );
	}

	/**
	 * A configured timing must still produce a future timestamp, or the fix
	 * would silently disable the feature it is meant to preserve.
	 */
	public function test_due_timestamp_is_in_the_future_when_timing_configured() {
		$before = time();
		$due    = Chip_Fluent_Forms_Purchase::resolve_due_timestamp( '60' );

		$this->assertIsInt( $due );
		$this->assertSame( $before + 3600, $due );
		$this->assertGreaterThan( time(), $due, 'A configured due must be in the future.' );
	}

	/**
	 * The numeric string the settings form actually submits.
	 */
	public function test_due_timestamp_handles_numeric_string() {
		$this->assertSame( time() + ( 30 * 60 ), Chip_Fluent_Forms_Purchase::resolve_due_timestamp( '30' ) );
	}

	/**
	 * The exact fatal: array_key_exists() on a null API result.
	 *
	 * Without the guard this raises TypeError on PHP 8, which no catch block
	 * in this plugin would ever see.
	 */
	public function test_guard_rejects_a_null_response() {
		$this->assertFalse(
			Chip_Fluent_Forms_Purchase::is_usable_response( null ),
			'A null response is the failure shape call() returns and must be rejected.'
		);
	}

	/**
	 * Guard accepts every success shape and rejects every failure shape.
	 *
	 * @dataProvider provide_responses
	 *
	 * @param mixed $response API result.
	 * @param bool  $expected Whether it is usable.
	 */
	public function test_guard_classifies_responses( $response, $expected ) {
		$this->assertSame( $expected, Chip_Fluent_Forms_Purchase::is_usable_response( $response ) );
	}

	/**
	 * Every shape Chip_Fluent_Forms_API::call() can return.
	 *
	 * @return array[]
	 */
	public static function provide_responses() {
		return array(
			'success payload'      => array( array( 'id' => 'abc', 'status' => 'created' ), true ),
			'empty array'          => array( array(), true ),
			'null (transport err)' => array( null, false ),
			'null (non-2xx)'       => array( null, false ),
			'null (bad json)'      => array( null, false ),
			'null (errors key)'    => array( null, false ),
			'false'                => array( false, false ),
			'string'               => array( 'error', false ),
			'int'                  => array( 500, false ),
		);
	}

	/**
	 * The plugin registers its hooks even though it is loaded by the bootstrap.
	 */
	public function test_hooks_are_registered_on_load() {
		$hooks = array_column( $GLOBALS['ff_test_actions'] ?? array(), 0 );

		$this->assertContains( 'fluentform/process_payment_chip', $hooks );
		$this->assertContains( 'fluentform/payment_frameless_chip', $hooks );
		$this->assertContains( 'fluentform/ipn_endpoint_chip', $hooks );
	}
}
