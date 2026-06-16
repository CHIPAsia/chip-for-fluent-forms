<?php
/**
 * Plugin Name: CHIP for Fluent Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-fluent-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 2.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 * Requires PHP: 7.4
 * Requires at least: 6.1
 *
 * Copyright: © 2026 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package CHIPForFluentForms
 */

// phpcs:disable PSR1.Files.SideEffects.FoundNonConditionalLogic -- the plugin entry-point file intentionally combines the ABSPATH guard, the FF_CHIP_* constants, the class require, and the bootstrap action registration into one side-effect block.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FF_CHIP_MODULE_VERSION', 'v2.0.0' );

/**
 * `FF_CHIP_FILE` must point to the entry-point file (not the class
 * file) so that `plugin_dir_path( FF_CHIP_FILE )` resolves to the
 * plugin root. We set it before requiring the class so the class's
 * own `define()` method sees it and skips its own __FILE__ fallback.
 */
define( 'FF_CHIP_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-chip-fluent-forms.php';

/**
 * Register the text-domain loader and the gated plugin entry point.
 *
 * Both run on `plugins_loaded` so the plugin is fully bootstrapped
 * (constants + class autoloaded + helper bootstraps included) before
 * any Fluent Forms Pro hook fires.
 *
 * The text-domain path is derived from `FF_CHIP_FILE` (defined above)
 * rather than `FF_CHIP_BASENAME` because the latter is only set when
 * `Chip_Fluent_Forms::define()` runs, which is gated below by the
 * Fluent Forms Pro class check. Referencing it here on PHP 8 raised
 * a fatal `Undefined constant` Error before the class is instantiated.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'chip-for-fluent-forms', false, dirname( plugin_basename( FF_CHIP_FILE ) ) . '/languages/' );

		if ( ! class_exists( 'FluentFormPro\Payments\PaymentHelper' ) && ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
			return;
		}

		Chip_Fluent_Forms::get_instance();
	}
);
// phpcs:enable
