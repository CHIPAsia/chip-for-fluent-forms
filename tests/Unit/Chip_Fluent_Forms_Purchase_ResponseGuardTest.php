<?php
/**
 * Class guard: every read of a CHIP API result must be guarded.
 *
 * Both defects in this branch were PATTERNS copied across call sites, not
 * isolated mistakes. A behavioural unit test can only prove that the site it
 * names is fixed; it is blind to a new call site that copies the old shape.
 * These tests read the source itself and fail when an unguarded pattern
 * reappears anywhere in the plugin.
 *
 * A read counts as guarded when, between the API call that produced the
 * variable and the read, one of these appears:
 *   - is_array( $var ) / is_usable_response( $var ) on a branch
 *   - a read through get_response_value()
 *   - a branch on array_key_exists() whose body terminates the request
 *     (wp_send_json_*, return, wp_die, exit)
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard against the unguarded-API-result pattern.
 */
class Chip_Fluent_Forms_Purchase_ResponseGuardTest extends TestCase {

	/**
	 * Variables that hold a CHIP API result in this codebase.
	 *
	 * @var string[]
	 */
	const API_VARIABLES = array( '$payment', '$webhooks' );

	/**
	 * Files that contain CHIP API call sites.
	 *
	 * @return array[]
	 */
	public static function provide_plugin_files() {
		return array(
			'purchase class' => array( 'includes/class-purchase.php' ),
			'webhook setup'  => array( 'includes/admin/class-webhook-setup.php' ),
		);
	}

	/**
	 * Whether a line is a comment or docblock, and therefore not executable.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_comment_line( $line ) {
		$trimmed = ltrim( $line );

		foreach ( array( '*', '//', '#', '/*' ) as $prefix ) {
			if ( 0 === strpos( $trimmed, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read a file's source, one line per array entry.
	 *
	 * @param string $file Relative path.
	 * @return string[] Lines.
	 */
	private function read_lines( $file ) {
		$source = file_get_contents( FF_CHIP_PLUGIN_PATH . $file );
		$this->assertNotFalse( $source, "Could not read {$file}" );

		return explode( "\n", $source );
	}

	/**
	 * Whether a read of $variable at $index is preceded by a guard.
	 *
	 * Scans back to the assignment that produced the value (the nearest line
	 * assigning the variable) and inspects the span in between.
	 *
	 * @param string[] $lines     File lines.
	 * @param int      $index     0-based index of the read.
	 * @param string   $variable  Variable being read.
	 * @return bool
	 */
	private function read_is_guarded( array $lines, $index, $variable ) {
		$quoted = preg_quote( $variable, '/' );

		// Walk back to the nearest assignment of this variable.
		$start = 0;
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( preg_match( '/' . $quoted . '\s*=\s*/', $lines[ $i ] ) ) {
				$start = $i;
				break;
			}
		}

		$span = implode( "\n", array_slice( $lines, $start, $index - $start + 1 ) );

		// Forms of guard this codebase uses.
		if ( false !== strpos( $span, "is_array( {$variable} )" ) ) {
			return true;
		}

		if ( false !== strpos( $span, "is_usable_response( {$variable} )" ) ) {
			return true;
		}

		if ( false !== strpos( $span, 'get_response_value(' ) ) {
			return true;
		}

		// A branch on the key whose body terminates the request.
		if ( preg_match( '/array_key_exists\([^)]*' . $quoted . '\s*\)/', $span )
			&& preg_match( '/\b(wp_send_json_\w+|wp_die|exit)\s*\(/', $span ) ) {
			return true;
		}

		return false;
	}

	/**
	 * No direct subscript of an API result may be reachable ungated.
	 *
	 * @dataProvider provide_plugin_files
	 *
	 * @param string $file Relative path.
	 */
	public function test_api_result_is_never_subscripted_unguarded( $file ) {
		$lines     = $this->read_lines( $file );
		$offenders = array();

		foreach ( self::API_VARIABLES as $variable ) {
			$quoted = preg_quote( $variable, '/' );

			foreach ( $lines as $index => $line ) {
				if ( $this->is_comment_line( $line ) ) {
					continue;
				}

				if ( ! preg_match( '/' . $quoted . '\[/', $line ) ) {
					continue;
				}

				// Skip lines that are part of a guarded read helper call.
				if ( false !== strpos( $line, 'get_response_value(' ) ) {
					continue;
				}

				if ( ! $this->read_is_guarded( $lines, $index, $variable ) ) {
					$offenders[] = "{$file}:" . ( $index + 1 ) . ': ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Unguarded subscript of a CHIP API result - a fatal TypeError on PHP 8 "
				. "because call() returns null for every failure shape:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * array_key_exists() must never be called directly on an API result.
	 *
	 * This is the exact expression that raised the reported fatal:
	 * array_key_exists(): Argument #2 ($array) must be of type array, null given
	 *
	 * @dataProvider provide_plugin_files
	 *
	 * @param string $file Relative path.
	 */
	public function test_array_key_exists_is_never_called_on_an_api_result( $file ) {
		$lines     = $this->read_lines( $file );
		$offenders = array();

		foreach ( self::API_VARIABLES as $variable ) {
			$quoted = preg_quote( $variable, '/' );

			foreach ( $lines as $index => $line ) {
				if ( $this->is_comment_line( $line ) ) {
					continue;
				}

				if ( ! preg_match( '/array_key_exists\([^)]*' . $quoted . '\s*\)/', $line ) ) {
					continue;
				}

				if ( ! $this->read_is_guarded( $lines, $index, $variable ) ) {
					$offenders[] = "{$file}:" . ( $index + 1 ) . ': ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"array_key_exists() directly on a CHIP API result:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * refund_callback() must reject a payload that is not a decoded array.
	 *
	 * json_decode() returns null on malformed JSON, and the original code read
	 * $payment['related_to']['id'] and $payment['event_type'] straight off
	 * that. A non-array payload is not fatal (a null offset is a warning), but
	 * it drove the refund path with garbage instead of returning.
	 */
	public function test_refund_callback_guards_a_non_array_payload() {
		$source = file_get_contents( FF_CHIP_PLUGIN_PATH . 'includes/class-purchase.php' );
		$this->assertNotFalse( $source );

		$start = strpos( $source, 'private function refund_callback()' );
		$this->assertNotFalse( $start, 'refund_callback() not found' );

		$body  = substr( $source, $start, 1500 );
		$guard = strpos( $body, 'if ( ! is_array( $payment ) ) {' );
		$read  = strpos( $body, 'self::get_response_value( $payment, \'event_type\' )' );

		$this->assertNotFalse( $guard, 'refund_callback() must bail out when json_decode() returned null.' );
		$this->assertNotFalse( $read, 'The event_type read must still exist.' );
		$this->assertLessThan(
			$read,
			$guard,
			'The non-array guard must come BEFORE any read of the decoded payload.'
		);
	}

	/**
	 * The `due` parameter must never be built inline from a raw setting.
	 *
	 * The bug shape is time() + ( absint( ... ) * 60 ) at the call site; the
	 * only sanctioned producer is resolve_due_timestamp(), which returns null
	 * when no timing is configured so the parameter can be omitted.
	 *
	 * @dataProvider provide_plugin_files
	 *
	 * @param string $file Relative path.
	 */
	public function test_due_is_never_built_inline( $file ) {
		$lines     = $this->read_lines( $file );
		$offenders = array();

		foreach ( $lines as $index => $line ) {
			if ( ! preg_match( "/'due'\s*=>/", $line ) ) {
				continue;
			}

			if ( false === strpos( $line, 'resolve_due_timestamp' ) ) {
				$offenders[] = "{$file}:" . ( $index + 1 ) . ': ' . trim( $line );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"`due` built inline instead of via resolve_due_timestamp():\n" . implode( "\n", $offenders )
		);
	}
}
