<?php 
/**
 *
 * Plugin Name: CHIP for Fluent Forms
 * Plugin URI: https://wordpress.org/plugins/chip-for-fluent-forms/
 * Description: Cash, Card and Coin Handling Integrated Platform
 * Version: 1.0.0
 * Author: Chip In Sdn Bhd
 * Author URI: http://www.chip-in.asia
 *
 * Copyright: © 2022 CHIP
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 */

if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access directly.

define('FF_CHIP_MODULE_VERSION', 'v1.0.0');

class Chip_Fluent_Forms {

  private static $_instance;

  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  public function __construct() {
    $this->define();
    $this->includes();
    $this->add_filters();
    $this->add_actions();
  }

  public function define() {
    define( 'FF_CHIP_FILE', __FILE__ );
    define( 'FF_CHIP_BASENAME', plugin_basename(FF_CHIP_FILE));
  }

  public function includes() {
    $includes_dir = plugin_dir_path( FF_CHIP_FILE ) . 'includes/';
    include $includes_dir . 'class-api.php';
    include $includes_dir . 'framework/classes/setup.class.php';

    if ( is_admin() ){
      include $includes_dir . 'admin/settings.php';
    }
  }

  public function add_filters() {
    add_filter( 'plugin_action_links_' . FF_CHIP_BASENAME, array( $this, 'setting_link' ) );
  }
  
  public function add_actions() {
      
  }

  public function setting_link($links) {
    $new_links = array(
      'settings' => sprintf(
        '<a href="%1$s">%2$s</a>', admin_url('admin.php?page=chip'), esc_html__('Settings', 'chip-for-fluent-forms')
      )
    );

    return array_merge($new_links, $links);
  }
}

add_action( 'plugins_loaded', 'load_chip_for_fluent_forms' );

function load_chip_for_fluent_forms() {

  if ( !class_exists( 'FluentFormPro\Payments\PaymentHelper' ) && !class_exists( 'FluentFormPro\Payments\PaymentMethods\BaseProcessor' ) ) {
    return;
  }
  
  Chip_Fluent_Forms::get_instance();
}
