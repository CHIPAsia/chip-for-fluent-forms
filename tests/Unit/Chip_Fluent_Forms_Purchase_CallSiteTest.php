<?php
/**
 * Call-site guard: `due` must be produced only by the resolver.
 *
 * The behavioural test proves resolve_due_timestamp() is correct; it cannot
 * prove that the call site USES it. A mutation that puts the inline
 * time() + ( absint( ... ) * 60 ) expression back into create_purchase() left
 * every behavioural test green, because the resolver was still correct and
 * still exported - just no longer called.
 *
 * This test therefore asserts the wiring, not the behaviour.
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards the create_purchase() call site.
 */
class Chip_Fluent_Forms_Purchase_CallSiteTest extends TestCase {

	/**
	 * The purchase class source.
	 *
	 * @return string
	 */
	private function source() {
		$source = file_get_contents( FF_CHIP_PLUGIN_PATH . 'includes/class-purchase.php' );
		$this->assertNotFalse( $source, 'Could not read class-purchase.php' );

		return $source;
	}

	/**
	 * create_purchase() must build `due` through the resolver, and only add the
	 * parameter when the resolver returned a timestamp.
	 */
	public function test_create_purchase_routes_due_through_the_resolver() {
		$source = $this->source();

		$this->assertStringContainsString(
			'$due_timestamp = self::resolve_due_timestamp( $option[\'due_time\'] );',
			$source,
			'create_purchase() must derive `due` from resolve_due_timestamp().'
		);

		$this->assertStringContainsString(
			"if ( null !== \$due_timestamp ) {\n\t\t\t\$params['due'] = \$due_timestamp;\n\t\t}",
			$source,
			'The `due` parameter must only be set when the resolver returned a timestamp.'
		);
	}

	/**
	 * The exact bug expression must not exist anywhere in the file.
	 */
	public function test_the_inline_due_expression_is_gone() {
		$source = $this->source();

		$this->assertStringNotContainsString(
			"time() + ( absint( \$option['due_time'] ) * 60 )",
			$source,
			'The inline `due` expression is the bug: an empty timing yields time(), '
				. 'which CHIP rejects with 400 due_not_greater_than_now.'
		);
	}

	/**
	 * A call site must not set `due` inline as an array literal.
	 */
	public function test_no_call_site_assigns_due_inline() {
		$source = $this->source();
		$lines  = explode( "\n", $source );
		$bad    = array();

		foreach ( $lines as $number => $line ) {
			$trimmed = ltrim( $line );

			// Skip comments and docblocks.
			foreach ( array( '*', '//', '#', '/*' ) as $prefix ) {
				if ( 0 === strpos( $trimmed, $prefix ) ) {
					continue 2;
				}
			}

			// The sanctioned assignment: $params['due'] = $due_timestamp; on the
			// line after the null check on the resolver's return value.
			if ( false !== strpos( $line, '$due_timestamp' ) ) {
				continue;
			}

			if ( preg_match( "/\[?'due'\]?\s*(=>|=)/", $line ) ) {
				$bad[] = 'includes/class-purchase.php:' . ( $number + 1 ) . ': ' . $trimmed;
			}
		}

		$this->assertSame( array(), $bad, "`due` assigned outside the resolver:\n" . implode( "\n", $bad ) );
	}
}
