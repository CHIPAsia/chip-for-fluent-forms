<?php
/**
 * Lightweight settings framework for CHIP plugins.
 *
 * Replaces the vendored Codestar Framework, which is no longer maintained
 * upstream. Only the behaviour this plugin actually relies on is implemented:
 * one options page, parent/child sections, and the six field types in use
 * (text, number, switcher, subheading, notice, backup).
 *
 * Compatibility contract - these must never change:
 * - The option name is the slug, so `get_option( <slug> )` keeps working.
 * - The stored option is a flat map of field id => value.
 * - Defaults are persisted on first load when the option is empty.
 * - Values are sanitised with wp_kses_post()/wp_kses_post_deep() and validated
 *   through the field's `validate` callback, reverting to the previous value
 *   and recording an error when validation fails.
 * - `chip_ff_{slug}_save_before` fires with ( array $data, object $page ).
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CHIP_FF_Settings' ) ) {

	/**
	 * Static registry for options pages and their sections.
	 */
	class CHIP_FF_Settings {

		/**
		 * Registered options pages, keyed by slug.
		 *
		 * @var array
		 */
		public static $args = array();

		/**
		 * Registered sections, keyed by slug.
		 *
		 * @var array
		 */
		public static $sections = array();

		/**
		 * Page controller instances, keyed by slug.
		 *
		 * @var array
		 */
		private static $pages = array();

		/**
		 * Asset version, published by the plugin bootstrap for cache busting.
		 *
		 * @var string
		 */
		public static $version = '';

		/**
		 * Registers an options page.
		 *
		 * @param string $slug Options slug, also used as the option name.
		 * @param array  $args Page arguments.
		 * @return void
		 */
		public static function createOptions( $slug, $args = array() ) {

			self::$args[ $slug ] = wp_parse_args(
				$args,
				array(
					'framework_title' => '',
					'menu_title'      => '',
					'menu_slug'       => $slug,
					'menu_type'       => 'menu',
					'menu_parent'     => '',
					'menu_capability' => 'manage_options',
					'menu_icon'       => 'dashicons-money-alt',
					'menu_position'   => null,
					'footer_text'     => '',
					'save_defaults'   => true,
				)
			);

			if ( ! isset( self::$sections[ $slug ] ) ) {
				self::$sections[ $slug ] = array();
			}
		}

		/**
		 * Constructs every registered page.
		 *
		 * Deferred until the end of `init` because callers register their
		 * sections right after calling createOptions(). Building a page at
		 * that moment would snapshot an empty section list and silently drop
		 * every field.
		 *
		 * @return void
		 */
		public static function init_pages() {

			foreach ( array_keys( self::$args ) as $slug ) {
				self::page( $slug );
			}
		}

		/**
		 * Registers a section for an options page.
		 *
		 * Sections without a `parent` become tabs; sections with a `parent`
		 * are grouped under that tab.
		 *
		 * @param string $slug    Options slug.
		 * @param array  $section Section arguments.
		 * @return void
		 */
		public static function createSection( $slug, $section = array() ) {

			if ( ! isset( self::$sections[ $slug ] ) ) {
				self::$sections[ $slug ] = array();
			}

			self::$sections[ $slug ][] = wp_parse_args(
				$section,
				array(
					'id'          => '',
					'title'       => '',
					'icon'        => '',
					'description' => '',
					'fields'      => array(),
					'parent'      => '',
				)
			);
		}

		/**
		 * Returns the page controller for a slug, creating it on first use.
		 *
		 * @param string $slug Options slug.
		 * @return CHIP_FF_Settings_Page|null
		 */
		public static function page( $slug ) {

			if ( ! isset( self::$pages[ $slug ] ) && class_exists( 'CHIP_FF_Settings_Page' ) ) {
				self::$pages[ $slug ] = new CHIP_FF_Settings_Page( $slug );
			}

			return isset( self::$pages[ $slug ] ) ? self::$pages[ $slug ] : null;
		}
	}
}
