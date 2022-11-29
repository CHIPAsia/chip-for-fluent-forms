<?php

$slug = FF_CHIP_FSLUG;

CHIPFLUENT_Setup::createOptions( $slug, array(
  'framework_title' => sprintf( __( 'CHIP %sCash, Card & Coin Handling Integrated Platform%s', 'chip-for-fluent-forms' ), '<small>', '</small>' ),

  'menu_title'  => __( 'CHIP Settings', 'chip-for-fluent-forms' ),
  'menu_slug'   => 'chip',
  'menu_type'   => 'submenu',
  'menu_parent' => 'fluent_forms',
  'footer_text' => sprintf( __( 'CHIP for Fluent Forms %s', 'chip-for-fluent-forms' ) , FF_CHIP_MODULE_VERSION ),
  'theme'       => 'light',
) );

$credentials_global_fields = array(
  array(
    'id'    => 'secret-key',
    'type'  => 'text',
    'title' => __( 'Secret Key', 'chip-for-fluent-forms' ),
    'desc'  => __( 'Enter your Secret Key.', 'chip-for-fluent-forms' ),
    'help'  => __( 'Secret key is used to identify your account with CHIP. You are recommended to create dedicated secret key for each website.', 'chip-for-fluent-forms' ),
  ),
  array(
    'id'    => 'brand-id',
    'type'  => 'text',
    'title' => __( 'Brand ID', 'chip-for-fluent-forms' ),
    'desc'  => __( 'Enter your Brand ID.', 'chip-for-fluent-forms' ),
    'help'  => __( 'Brand ID enables you to represent your Brand suitable for the system using the same CHIP account.', 'chip-for-fluent-forms' ),
  ) );

$miscellaneous_global_fields = array(
  array(
    'id'          => 'payment_title',
    'type'        => 'text',
    'title'       => __( 'Payment Title', 'chip-for-fluent-forms' ),
    'desc'        => __( 'Enter your Payment Title. Default is <strong>CHIP</strong>', 'chip-for-fluent-forms' ),
    'help'        => __( 'This allows you to customize the payment title.', 'chip-for-fluent-forms' ),
    'placeholder' => 'CHIP',
    'default'     => 'CHIP',
  ),
  array(
    'id'    => 'send-receipt',
    'type'  => 'switcher',
    'title' => __( 'Purchase Send Receipt', 'chip-for-fluent-forms' ),
    'desc'  => __( 'Send receipt upon payment completion.', 'chip-for-fluent-forms' ),
    'help'  => __( 'Whether to send receipt email when it\'s paid. If configured, the receipt email will be send by CHIP. Default is off.', 'chip-for-fluent-forms' ),
  ),
  array(
    'id'      => 'due-strict',
    'type'    => 'switcher',
    'title'   => __( 'Due Strict', 'chip-for-fluent-forms' ),
    'desc'    => __( 'Turn this on to prevent payment after specific time.', 'chip-for-fluent-forms' ),
    'help'    => __( 'Whether to permit payments when Purchase\'s due has passed. By default those are permitted (and status will be set to overdue once due moment is passed). If this is set to true, it won\'t be possible to pay for an overdue invoice, and when due is passed the Purchase\'s status will be set to expired.', 'chip-for-fluent-forms' ),
    'default' => true,
  ),
  array(
    'id'          => 'due-strict-timing',
    'type'        => 'number',
    'after'       => 'minutes',
    'title'       => __( 'Due Strict', 'chip-for-fluent-forms' ),
    'help'        => __( 'Set due time to enforce due timing for purchases. 60 for 60 minutes. If due_strict is set while due strict timing unset, it will default to 1 hour.', 'chip-for-fluent-forms' ),
    'desc'        => __( 'Default 60 for 1 hour.', 'chip-for-fluent-forms' ),
    'default'     => '60',
    'placeholder' => '60',
    'dependency'  => array( ['due-strict', '==', 'true'] ),
    'validate'    => 'chipfluent_validate_numeric',
  ),
);

CHIPFLUENT_Setup::createSection( $slug, array(
  'id'    => 'global-configuration',
  'title' => __( 'Global Configuration', 'chip-for-fluent-forms' ),
  'icon'  => 'fa fa-home',
) );

CHIPFLUENT_Setup::createSection( $slug, array(
  'parent'      => 'global-configuration',
  'id'          => 'credentials',
  'title'       => __( 'Credentials', 'chip-for-fluent-forms' ),
  'description' => __( 'Configure your Secret Key and Brand ID.', 'chip-for-fluent-forms' ),
  'fields'      => $credentials_global_fields,
) );

CHIPFLUENT_Setup::createSection( $slug, array(
  'parent'      => 'global-configuration',
  'id'          => 'miscellaneous',
  'title'       => __( 'Miscellaneous', 'chip-for-fluent-forms' ),
  'description' => __( 'Miscellaneous settings.', 'chip-for-fluent-forms' ),
  'fields'      => $miscellaneous_global_fields,
) );

CHIPFLUENT_Setup::createSection( $slug, array(
  'id'    => 'form-configuration',
  'title' => __( 'Form Configuration', 'chip-for-fluent-forms' ),
  'icon'  => 'fa fa-gear'
));

$global_fields = array_merge( $credentials_global_fields, $miscellaneous_global_fields );

$all_forms_query = wpFluent()->table('fluentform_forms')
  ->select(['id', 'title'])
  ->orderBy('id')
  ->limit(500)
  ->get();

foreach($all_forms_query as $form) {
  $form_fields = array(
    array(
    'id'    => 'form-customize-' . $form->id,
    'type'  => 'switcher',
    'title' => sprintf( __( 'Customization', 'chip-for-fluent-forms' ) ),
    'desc'  => sprintf( __( 'Form ID: <strong>#%s</strong>. Form Title: <strong>%s</strong>', 'chip-for-fluent-forms' ), $form->id, $form->title),
    'help'  => sprintf( __( 'This to enable customization per form-basis for form: #%s', 'chip-for-fluent-forms' ), $form->id ),
  ));

  $local_gfields = $global_fields;

  for( $i=0; $i < sizeof($global_fields); $i++ ) {

    if ( $local_gfields[$i]['id'] == 'payment_title' ) {
      continue;
    }

    $dependency_array = [];
    if ( isset( $local_gfields[$i]['dependency'] ) ) {
      $local_gfields[$i]['dependency'][0][0] .= '-' . $form->id;

      $dependency_array = $local_gfields[$i]['dependency'];
    }
    $dependency_array[] = ['form-customize-' . $form->id, '==', 'true'];

    $local_gfields[$i]['id']        .= '-' . $form->id;
    $local_gfields[$i]['dependency'] = $dependency_array;

    $form_fields[] = $local_gfields[$i];
  }

  CHIPFLUENT_Setup::createSection( $slug, array(
    'parent'      => 'form-configuration',
    'id'          => 'form-id-' . $form->id,
    'title'       => sprintf( __( 'Form #%s - %s', 'chip-for-fluent-forms' ), $form->id, substr( $form->title, 0, 15 ) ),
    'description' => sprintf( __( 'Configuration for Form #%s - %s', 'chip-for-fluent-forms' ), $form->id, $form->title ),
    'fields'      => $form_fields,
  ));
}
