<?php
/**
 * Regression tests for the currency notice on the CHIP settings page.
 *
 * The notice warns that the shop currency is not supported. It is flagged
 * `hidden` while the currency is MYR. Since the bundled settings framework was
 * replaced, that flag no longer hid anything - the field stayed on screen
 * whatever the currency - so a correctly configured merchant was told their
 * currency was wrong.
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards the currency warning's visibility and the read behind it.
 */
class Chip_Fluent_Forms_CurrencyNoticeTest extends TestCase {

	/**
	 * Read a plugin file's source.
	 *
	 * @param string $file Relative path.
	 * @return string
	 */
	private function source( $file ) {
		$source = file_get_contents( FF_CHIP_PLUGIN_PATH . $file );
		$this->assertNotFalse( $source, "Could not read {$file}" );

		return $source;
	}

	/**
	 * Split a stylesheet into selector => declarations pairs.
	 *
	 * Comments are stripped first so a rule mentioned in prose cannot satisfy
	 * an assertion. At-rules are flattened, which is enough for this file.
	 *
	 * @param string $css Stylesheet.
	 * @return array Selector => declarations.
	 */
	private function rules( $css ) {
		$css   = preg_replace( '!/\*.*?\*/!s', '', $css );
		$rules = array();

		preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$selector = trim( preg_replace( '/\s+/', ' ', $match[1] ) );
			$rules[]  = array(
				'selector'     => $selector,
				'declarations' => $match[2],
			);
		}

		return $rules;
	}

	/**
	 * The `hidden` flag on a field must actually hide it.
	 *
	 * The layout rules give `.chip-ff-field` a display value with a higher
	 * specificity than WordPress' own `.hidden`, so the framework has to say it
	 * itself. Without this rule the currency notice is always visible.
	 */
	public function test_a_field_flagged_hidden_is_hidden() {
		$rules   = $this->rules( $this->source( 'includes/assets/css/chip-ff-settings.css' ) );
		$display = null;

		foreach ( $rules as $rule ) {
			if ( false === strpos( $rule['selector'], '.chip-ff-field' ) ) {
				continue;
			}

			if ( ! preg_match( '/\.hidden(?![\w-])/', $rule['selector'] ) ) {
				continue;
			}

			if ( preg_match( '/display\s*:\s*(none)\s*(!\s*important)?\s*;/', $rule['declarations'], $match ) ) {
				$display = trim( $match[0] );
			}
		}

		$this->assertNotNull(
			$display,
			'No `.chip-ff-field.hidden { display: none }` rule: a field flagged hidden is rendered visible.'
		);
	}

	/**
	 * The notice is only hidden for the currency CHIP actually supports.
	 */
	public function test_the_notice_is_hidden_only_for_myr() {
		$source = $this->source( 'includes/admin/global-settings.php' );

		$this->assertStringContainsString(
			"'MYR' === \$ff_currency ? 'hidden' : ''",
			$source,
			'The currency notice must stay visible for every currency other than MYR.'
		);
	}

	/**
	 * A missing option must not be indexed directly.
	 *
	 * `get_option()` returns false on a site whose Fluent Forms payment
	 * settings were never saved; indexing that raises a warning on PHP 8. The
	 * fallback keeps the notice visible, which is the right answer there.
	 */
	public function test_the_currency_is_read_with_a_default() {
		$source = $this->source( 'includes/admin/global-settings.php' );

		$this->assertStringContainsString(
			'is_array( $ff_module_settings )',
			$source,
			'The option must be checked before its currency key is used.'
		);

		$this->assertStringContainsString(
			": 'USD';",
			$source,
			'A site without saved payment settings must fall back to the same default Fluent Forms uses.'
		);
	}
}
