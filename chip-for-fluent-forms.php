<?php
/**
 *
 * Plugin Name: CHIP for Fluent Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-fluent-forms/
 * Description: CHIP - Digital Finance Platform
 * Version: 1.1.2
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

require_once __DIR__ . '/includes/class-chip-fluent-forms.php';

/**
 * Register the gated plugin entry point.
 *
 * Runs on `plugins_loaded` so the plugin is fully bootstrapped
 * (constants + class autoloaded + helper bootstraps included) before
 * any Fluent Forms Pro hook fires. WordPress 4.6+ handles loading
 * translations under the plugin slug automatically, so no explicit
 * `load_plugin_textdomain()` call is needed here.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'FluentFormPro\Payments\PaymentHelper' ) && ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
			return;
		}

		Chip_Fluent_Forms::get_instance();
	}
);
// phpcs:enable