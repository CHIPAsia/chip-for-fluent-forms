<?php if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access directly.
/**
 *
 * Field: icon
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
if ( ! class_exists( 'CHIPFLUENT_Field_icon' ) ) {
  class CHIPFLUENT_Field_icon extends CHIPFLUENT_Fields {

    public function __construct( $field, $value = '', $unique = '', $where = '', $parent = '' ) {
      parent::__construct( $field, $value, $unique, $where, $parent );
    }

    public function render() {

      $args = wp_parse_args( $this->field, array(
        'button_title' => esc_html__( 'Add Icon', 'chipfluent' ),
        'remove_title' => esc_html__( 'Remove Icon', 'chipfluent' ),
      ) );

      echo $this->field_before();

      $nonce  = wp_create_nonce( 'chipfluent_icon_nonce' );
      $hidden = ( empty( $this->value ) ) ? ' hidden' : '';

      echo '<div class="chipfluent-icon-select">';
      echo '<span class="chipfluent-icon-preview'. esc_attr( $hidden ) .'"><i class="'. esc_attr( $this->value ) .'"></i></span>';
      echo '<a href="#" class="button button-primary chipfluent-icon-add" data-nonce="'. esc_attr( $nonce ) .'">'. $args['button_title'] .'</a>';
      echo '<a href="#" class="button chipfluent-warning-primary chipfluent-icon-remove'. esc_attr( $hidden ) .'">'. $args['remove_title'] .'</a>';
      echo '<input type="hidden" name="'. esc_attr( $this->field_name() ) .'" value="'. esc_attr( $this->value ) .'" class="chipfluent-icon-value"'. $this->field_attributes() .' />';
      echo '</div>';

      echo $this->field_after();

    }

    public function enqueue() {
      add_action( 'admin_footer', array( 'CHIPFLUENT_Field_icon', 'add_footer_modal_icon' ) );
      add_action( 'customize_controls_print_footer_scripts', array( 'CHIPFLUENT_Field_icon', 'add_footer_modal_icon' ) );
    }

    public static function add_footer_modal_icon() {
    ?>
      <div id="chipfluent-modal-icon" class="chipfluent-modal chipfluent-modal-icon hidden">
        <div class="chipfluent-modal-table">
          <div class="chipfluent-modal-table-cell">
            <div class="chipfluent-modal-overlay"></div>
            <div class="chipfluent-modal-inner">
              <div class="chipfluent-modal-title">
                <?php esc_html_e( 'Add Icon', 'chipfluent' ); ?>
                <div class="chipfluent-modal-close chipfluent-icon-close"></div>
              </div>
              <div class="chipfluent-modal-header">
                <input type="text" placeholder="<?php esc_html_e( 'Search...', 'chipfluent' ); ?>" class="chipfluent-icon-search" />
              </div>
              <div class="chipfluent-modal-content">
                <div class="chipfluent-modal-loading"><div class="chipfluent-loading"></div></div>
                <div class="chipfluent-modal-load"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php
    }

  }
}
