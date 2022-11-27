<?php if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access directly.
/**
 *
 * Field: backup
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if ( ! class_exists( 'CHIPFLUENT_Field_backup' ) ) {
  class CHIPFLUENT_Field_backup extends CHIPFLUENT_Fields {

    public function __construct( $field, $value = '', $unique = '', $where = '', $parent = '' ) {
      parent::__construct( $field, $value, $unique, $where, $parent );
    }

    public function render() {

      $unique = $this->unique;
      $nonce  = wp_create_nonce( 'chipfluent_backup_nonce' );
      $export = add_query_arg( array( 'action' => 'chipfluent-export', 'unique' => $unique, 'nonce' => $nonce ), admin_url( 'admin-ajax.php' ) );

      echo $this->field_before();

      echo '<textarea name="chipfluent_import_data" class="chipfluent-import-data"></textarea>';
      echo '<button type="submit" class="button button-primary chipfluent-confirm chipfluent-import" data-unique="'. esc_attr( $unique ) .'" data-nonce="'. esc_attr( $nonce ) .'">'. esc_html__( 'Import', 'chipfluent' ) .'</button>';
      echo '<hr />';
      echo '<textarea readonly="readonly" class="chipfluent-export-data">'. esc_attr( json_encode( get_option( $unique ) ) ) .'</textarea>';
      echo '<a href="'. esc_url( $export ) .'" class="button button-primary chipfluent-export" target="_blank">'. esc_html__( 'Export & Download', 'chipfluent' ) .'</a>';
      echo '<hr />';
      echo '<button type="submit" name="chipfluent_transient[reset]" value="reset" class="button chipfluent-warning-primary chipfluent-confirm chipfluent-reset" data-unique="'. esc_attr( $unique ) .'" data-nonce="'. esc_attr( $nonce ) .'">'. esc_html__( 'Reset', 'chipfluent' ) .'</button>';

      echo $this->field_after();

    }

  }
}
