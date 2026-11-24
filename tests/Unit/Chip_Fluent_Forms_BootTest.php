<?php
/**
 * Regression tests for the plugin bootstrap.
 *
 * The plugin only loads its own files once Fluent Forms' payment module is
 * available: the `init` boot callback returns early when the Fluent Forms Pro
 * classes are missing. The hooks that start the settings framework were
 * registered outside that gate, so on a site with the plugin active and the
 * payment module absent (Fluent Forms Pro not installed, deactivated, or not
 * yet loaded at `init` 1) every request died with
 * "Class CHIP_FF_Settings not found".
 *
 * @package CHIPForFluentForms
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards the boot hooks against a missing settings framework.
 */
class Chip_Fluent_Forms_BootTest extends TestCase {

	/**
	 * Loads the plugin's main file.
	 *
	 * The bootstrap already defines FF_CHIP_MODULE_VERSION, so the plugin's own
	 * define() reports a redefinition warning; @ keeps that out of the result.
	 *
	 * @return void
	 */
	private function load_plugin() {
		require_once FF_CHIP_PLUGIN_PATH . 'chip-for-fluent-forms.php';
	}

	/**
	 * The init hooks must survive the settings framework being absent.
	 */
	public function test_the_settings_hooks_do_not_fatal_without_the_framework() {
		$this->assertFalse(
			class_exists( 'CHIP_FF_Settings', false ),
			'Precondition: the settings framework must not be loaded by the test bootstrap.'
		);

		@$this->load_plugin();

		$this->assertTrue(
			function_exists( 'chip_ff_init_settings_pages' ),
			'The init hook must call a guarded wrapper instead of CHIP_FF_Settings::init_pages() directly.'
		);

		foreach ( (array) $GLOBALS['ff_test_actions'] as $hook ) {
			if ( 'init' !== $hook[0] ) {
				continue;
			}

			$this->assertNotSame(
				array( 'CHIP_FF_Settings', 'init_pages' ),
				$hook[1],
				'init must not call a class that is only loaded when the payment module is available.'
			);
		}

		// Both raised "Class CHIP_FF_Settings not found" before the guard was
		// added, which is a fatal error on a live site.
		chip_ff_init_settings_pages();
		chip_ff_set_settings_asset_version();

		$this->addToAssertionCount( 1 );
	}
}
