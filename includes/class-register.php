<?php
use FluentForm\Framework\Helpers\ArrayHelper;

class Chip_Fluent_Forms_Register {

  private static $_instance;

  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  public function __construct() {
    add_filter( 'fluentformpro_available_payment_methods', array( $this, 'push' ) );
  }

  public function push( $methods ) {
    $options       = get_option( FF_CHIP_FSLUG ); 
    $payment_title = ArrayHelper::get($options, 'payment_title', 'CHIP');

    $methods['chip'] = [
      'title'        => $payment_title,
      'enabled'      => 'yes',
      'method_value' => 'chip',
      'settings' => [
        'option_label' => [
          'type'     => 'text',
          'template' => 'inputText',
          'value'    => 'Pay with CHIP',
          'label'    => 'Method Label'
        ],
      ]
    ];

    return $methods;
  }
}

Chip_Fluent_Forms_Register::get_instance();