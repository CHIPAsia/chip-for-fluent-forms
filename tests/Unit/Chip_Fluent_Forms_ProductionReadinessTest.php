<?php
/**
 * Regression tests for the production-readiness fixes.
 *
 * Each test targets a defect that shipped and is written so that reverting the
 * fix makes it fail - the point is to keep the defect from coming back, not to
 * restate the implementation.
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards the credential handling, webhook titles and notes wiring.
 */
class Chip_Fluent_Forms_ProductionReadinessTest extends TestCase {

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
	 * The API client must keep one instance per credential pair.
	 *
	 * A single shared instance meant the first credentials used in a request
	 * were reused for every later call. With per-form credentials configured,
	 * that charges the wrong brand.
	 */
	public function test_api_client_is_keyed_by_credentials() {
		$source = $this->source( 'includes/class-chip-fluent-forms-api.php' );

		$this->assertStringContainsString(
			'private static $instances = array();',
			$source,
			'The API client must store instances in a keyed array, not one shared instance.'
		);

		$this->assertStringContainsString(
			'$key = md5( $secret_key . \'|\' . $brand_id );',
			$source,
			'The instance cache key must include both credentials.'
		);

		$this->assertStringNotContainsString(
			'private static $_instance;',
			$source,
			'A single static $_instance reuses the first credentials for every call.'
		);
	}

	/**
	 * Every get_instance() call site must pass a real brand id.
	 *
	 * Passing '' silently falls back to whatever brand the cached instance was
	 * built with, which for a per-form configuration is the wrong account.
	 */
	public function test_api_get_instance_is_never_called_with_an_empty_brand_id() {
		$files = array(
			'includes/class-chip-fluent-forms-purchase.php',
			'includes/admin/class-chip-fluent-forms-webhook-setup.php',
		);

		$offenders = array();

		foreach ( $files as $file ) {
			foreach ( explode( "\n", $this->source( $file ) ) as $number => $line ) {
				if ( false === strpos( $line, 'get_instance(' ) ) {
					continue;
				}

				if ( false === strpos( $line, 'Chip_Fluent_Forms_API::get_instance(' ) ) {
					continue;
				}

				if ( preg_match( "/get_instance\\([^)]*,\\s*''\\s*\\)/", $line ) ) {
					$offenders[] = "{$file}:" . ( $number + 1 ) . ': ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"get_instance() called with an empty brand id - the request then runs "
				. "against whichever brand the cached instance was built with:\n"
				. implode( "\n", $offenders )
		);
	}

	/**
	 * The per-form webhook must use the per-form credentials.
	 *
	 * Reading the global secret key inside the per-form loop registered the
	 * webhook on the global account while storing its key as the form's key,
	 * so refund signatures for that form could never verify.
	 */
	public function test_form_webhook_uses_the_form_secret_key() {
		$source = $this->source( 'includes/admin/class-chip-fluent-forms-webhook-setup.php' );

		$this->assertStringContainsString(
			"\$form_secret_key = empty( \$data[ 'secret-key-' . \$form->id ] )",
			$source,
			'The per-form block must read the per-form secret key with a global fallback.'
		);

		$this->assertStringNotContainsString(
			"\$chip     = Chip_Fluent_Forms_API::get_instance( \$data['secret-key'], '' );",
			$source,
			'The per-form loop must not read the global secret key.'
		);
	}

	/**
	 * The webhook this plugin registers must carry this plugin's own title.
	 *
	 * The title selects which existing webhook is reused and is also the marker
	 * this plugin looks for when storing the public key. If it does not match,
	 * the plugin never reuses its own webhook and the stored key belongs to a
	 * webhook it does not own, so refund signatures cannot verify.
	 */
	public function test_webhook_title_is_this_plugin() {
		$source = $this->source( 'includes/admin/class-chip-fluent-forms-webhook-setup.php' );

		$this->assertSame(
			4,
			substr_count( $source, "'CHIP for Fluent Forms'" ),
			'The webhook title must be used by both the global and the per-form block, twice each (lookup + create).'
		);
	}

	/**
	 * The webhook cache must merge, never replace.
	 *
	 * Assigning `$this->results = array( ... )` dropped every entry stored
	 * earlier in the same save, so a form configured after the global settings
	 * discarded the global webhook list.
	 */
	public function test_webhook_cache_merges_instead_of_replacing() {
		$source = $this->source( 'includes/admin/class-chip-fluent-forms-webhook-setup.php' );

		$this->assertStringNotContainsString(
			'$this->results = array(',
			$source,
			'Assigning $this->results replaces the whole cache instead of adding to it.'
		);

		$this->assertStringContainsString(
			'$this->results[ $form_secret_key ] = $webhooks;',
			$source
		);
	}

	/**
	 * A form whose webhook key is missing must not abort the remaining forms.
	 */
	public function test_missing_public_key_skips_only_that_form() {
		$source = $this->source( 'includes/admin/class-chip-fluent-forms-webhook-setup.php' );

		$start = strpos( $source, 'private function form_public_key(' );
		$this->assertNotFalse( $start );

		$body = substr( $source, $start );
		$end  = strpos( $body, "\n\t}\n" );
		$body = false === $end ? $body : substr( $body, 0, $end );

		$this->assertStringContainsString(
			"if ( empty( \$public_key ) ) {\n\t\t\t\tcontinue;",
			$body,
			'A form with no public key must continue, not return out of the loop.'
		);
	}

	/**
	 * The configured notes must reach the purchase, and be omitted when unset.
	 */
	public function test_notes_setting_reaches_the_purchase() {
		$source = $this->source( 'includes/class-chip-fluent-forms-purchase.php' );

		$this->assertStringContainsString(
			"ArrayHelper::get( \$methodSettings, 'settings.notes.value', '' )",
			$source,
			'The notes setting must be read from the payment method settings.'
		);

		$this->assertStringContainsString(
			'ShortCodeParser::parse( $notes_setting, $submission->id, $submission->response, $form, false, true )',
			$source,
			'Notes must be shortcode-parsed so {inputs.*} resolves to form answers.'
		);

		$this->assertStringContainsString(
			"'notes'      => substr( \$notes, 0, 10000 )",
			$source,
			'The purchase payload must use the composed notes value.'
		);
	}

	/**
	 * A failed re-query must keep the previous paid/failed behaviour.
	 *
	 * Returning early when the API re-query failed changed the outcome for
	 * every transient API failure. The status read stays guarded, but the
	 * branches below must still run.
	 */
	public function test_failed_requery_does_not_return_early() {
		$source = $this->source( 'includes/class-chip-fluent-forms-purchase.php' );

		$this->assertStringNotContainsString(
			'if ( null === $payment_status ) {',
			$source,
			'A null status must not short-circuit the paid/failed decision.'
		);

		$this->assertSame(
			2,
			substr_count( $source, "\$payment_status = self::get_response_value( \$payment, 'status' );" ),
			'Both redirect() and success_callback() must still read the status through the guard.'
		);
	}

	/**
	 * Every plugin PHP file must refuse direct access.
	 */
	public function test_plugin_files_block_direct_access() {
		$files = array(
			'chip-for-fluent-forms.php',
			'uninstall.php',
			'includes/class-chip-fluent-forms-api.php',
			'includes/class-chip-fluent-forms-purchase.php',
			'includes/class-chip-fluent-forms-register.php',
			'includes/admin/backup-settings.php',
			'includes/admin/form-settings.php',
			'includes/admin/global-settings.php',
			'includes/admin/class-chip-fluent-forms-webhook-setup.php',
		);

		$offenders = array();

		foreach ( $files as $file ) {
			if ( false === strpos( $this->source( $file ), "defined( 'ABSPATH' )" ) ) {
				$offenders[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Plugin files reachable directly over HTTP:\n" . implode( "\n", $offenders )
		);
	}
}
