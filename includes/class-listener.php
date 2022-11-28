<?php

class Chip_Fluent_Forms_Listener extends Chip_Fluent_Forms_Purchase {

  private static $_instance;

  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  public function __construct() {
    
  }
}
