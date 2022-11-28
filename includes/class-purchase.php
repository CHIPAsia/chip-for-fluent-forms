<?php
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

class Chip_Fluent_Forms_Purchase extends BaseProcessor {

  private static $_instance;

  private $supported_currencies = ['MYR'];

  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  public function __construct() {
    add_action( 'fluentform_process_payment_chip', array( $this, 'handlePaymentAction' ), 10, 6 );
    
    // this is redirect
    add_action( 'fluent_payment_frameless_chip', array( $this, 'redirect' ) );

    // this is callback
    add_action( 'fluentform_ipn_endpoint_chip', array( $this, 'callback' ) );
  }

  public function handlePaymentAction($submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable) {
    $this->setSubmissionId( $submissionId );
    $this->form = $form;
    $submission = $this->getSubmission();

    $this->is_form_currency_supported( strtoupper( $submission->currency ) );

    $transactionId = $this->insertTransaction([
      'payment_total'  => $totalPayable,
      'status'         => 'pending',
      'currency'       => strtoupper( $submission->currency ),
      'payment_method' => 'chip',
    ]);

    $transaction = $this->getTransaction($transactionId);
    $this->create_purchase( $transaction, $submission, $form, $methodSettings );
  }

  private function create_purchase( $transaction, $submission, $form, $methodSettings ) {
    $option = $this->get_settings( $form->id );

    $success_redirect = add_query_arg(array(
      'fluentform_payment' => $submission->id,
      'payment_method'     => 'chip',
      'transaction_hash'   => $transaction->transaction_hash,
      'type'               => 'success'
    ), site_url('/'));

    $failure_redirect = add_query_arg(array(
      'fluentform_payment' => $submission->id,
      'payment_method'     => 'chip',
      'transaction_hash'   => $transaction->transaction_hash,
      'type'               => 'failed'
    ), site_url('/'));

    $success_callback = add_query_arg(array(
      'fluentform_payment_api_notify' => 1,
      'payment_method'                => 'chip',
      'submission_id'                 => $submission->id
    ), site_url('/'));

    $params = array(
      'success_callback' => $success_callback,
      'success_redirect' => $success_redirect,
      'failure_redirect' => $failure_redirect,
      'creator_agent'    => 'FluentForms: ' . FF_CHIP_MODULE_VERSION,
      'reference'        => $transaction->id,
      'platform'         => 'api', // TODO: change to fluentforms
      'send_receipt'     => $option['send_rcpt'],
      'due'              => time() + ( absint( $option['due_time'] ) * 60 ),
      'brand_id'         => $option['brand_id'],
      'client'           => [
        'email'          => PaymentHelper::getCustomerEmail($submission, $form),
        'full_name'      => substr(PaymentHelper::getCustomerName($submission, $form), 0, 30),
      ],
      'purchase'         => array(
        'timezone'   => apply_filters( 'ff_chip_purchase_timezone', $this->get_timezone() ),
        'currency'   => strtoupper( $submission->currency ),
        'due_strict' => $option['due_strict'],
        'products'   => array([
          'name'     => substr($form->title, 0, 256),
          'price'    => round($transaction->payment_total),
          'quantity' => '1',
        ]),
      ),
    );

    $chip = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], $option['brand_id'] );
    $payment = $chip->create_payment($params);

    if (!array_key_exists('id', $payment)) {
      do_action('ff_log_data', [
        'parent_source_id' => $form->id,
        'source_type'      => 'submission_item',
        'source_id'        => $submission->id,
        'component'        => 'Payment',
        'status'           => 'error',
        'title'            => __( 'Failure to create purchase', 'chip-for-fluent-forms' ),
        'description'      => sprintf( __( 'User is not redirected to CHIP since failure to create purchase: %s' ), print_r( $payment, true ) ),
      ]);
      
      wp_send_json_success([
        'message' => print_r($payment, true)
      ], 500);
    }

    $this->updateTransaction($transaction->id, array(
      'payment_mode' => $payment['is_test'] ? 'test' : 'live',
      'charge_id'    => $payment['id'],
    ));

    $this->setMetaData( '_chip_purchase_id', $payment['id'] );

    do_action('ff_log_data', [
      'parent_source_id' => $form->id,
      'source_type'      => 'submission_item',
      'source_id'        => $submission->id,
      'component'        => 'Payment',
      'status'           => 'info',
      'title'            => __( 'Redirect to CHIP', 'chip-for-fluent-forms' ),
      'description'      => sprintf( __( 'User redirect to CHIP for completing the payment: %s' ), $payment['checkout_url'] ),
    ]);

    wp_send_json_success([
      'nextAction'   => 'payment',
      'actionName'   => 'normalRedirect',
      'redirect_url' => $payment['checkout_url'],
      'message'      => __('You are redirecting to chip-in.asia to complete the purchase. Please wait while you are redirecting....', 'chip-for-fluent-forms'),
      'result'       => [
        'insert_id' => $submission->id
      ]
    ], 200);
  }

  private function is_form_currency_supported( $currency ) {

    if ( !in_array( $currency, $this->supported_currencies ) ) {
      echo sprintf( __( 'Error! Currency not supported. The only supported currency is MYR and the current currency is %s.', 'chip-for-fluent-forms' ), esc_html( $currency ) );
      exit( 200 );
    }
  }

  private function get_settings( $form_id ) {
    
    $options = get_option( FF_CHIP_FSLUG );
    $postfix = '';

    if ( $options['form-customize-' . $form_id] ) {
      $postfix = $form_id;
    }

    return array(
      'secret_key' => $options['secret-key' . $postfix],
      'brand_id'   => $options['brand-id' . $postfix],
      'send_rcpt'  => $options['send-receipt' . $postfix],
      'due_strict' => $options['due-strict' . $postfix],
      'due_time'   => $options['due-strict-timing' . $postfix],
    );
  }

  private function get_timezone() {

    if (preg_match('/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string())) {
      return wp_timezone_string();
    }

    return 'UTC';
  }

  public function redirect( $data ) {

    $submission_id    = absint($data['fluentform_payment']);
    $transaction_hash = sanitize_text_field($data['transaction_hash']);

    if ( $data['payment_method'] != 'chip' ) {
      return;
    }

    $this->setSubmissionId( $submission_id );

    $submission = $this->getSubmission();
    $option     = $this->get_settings( $submission->form_id );
    $payment_id = $this->getMetaData( '_chip_purchase_id' );

    $chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], '' );
    $payment = $chip->get_payment( $payment_id );

    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('ff_chip_payment_$submission_id', 15);"
    );

    $transaction = $this->getTransaction($transaction_hash, 'transaction_hash');

    if ( $transaction->id != $payment['reference'] ) {
      return;
    }

    if ( $transaction->status != 'paid' && $payment['status'] == 'paid') {
      $this->handlePaid( $submission, $transaction, $payment );
    }

    if ( $transaction->status != 'failed' && $payment['status'] != 'paid') {
      $this->handleFailed( $submission, $transaction, $payment );
    }

    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
    );
    

    $this->handleSessionRedirectBack($data);
  }

  public function handlePaid( $submission, $transaction, $vendorTransaction ) {

    $this->setSubmissionId($submission->id);

    if ($this->getMetaData('is_form_action_fired') == 'yes') {
      return $this->completePaymentSubmission(false);
    }

    $status = $vendorTransaction['status'];

    $updateData = [
      'payment_note'  => maybe_serialize($vendorTransaction),
      'charge_id'     => sanitize_text_field($vendorTransaction['id']),
      'payment_total' => intval($vendorTransaction['purchase']['total'])
    ];

    $this->updateTransaction($transaction->id, $updateData);
    $this->changeSubmissionPaymentStatus($status);
    $this->changeTransactionStatus($transaction->id, $status);
    $this->recalculatePaidTotal();
    $this->setMetaData('is_form_action_fired', 'yes');
  }

  public function handleFailed( $submission, $transaction, $vendorTransaction ) {
    $this->setSubmissionId( $submission->id );

    $status = 'failed';

    $updateData = [
      'payment_note' => maybe_serialize($vendorTransaction),
    ];

    $this->updateTransaction($transaction->id, $updateData);
    $this->changeSubmissionPaymentStatus($status);
    $this->changeTransactionStatus($transaction->id, $status);
  }


  // public function handleRefund($refundAmount, $submission, $vendorTransaction){
  //   $this->setSubmissionId($submission->id);
  //   $transaction = $this->getLastTransaction($submission->id);
  //   $this->updateRefund($refundAmount, $transaction, $submission, $this->method);
  // }

  public function callback() {

    $submission_id = absint($_GET['submission_id']);

    if ( $_GET['payment_method'] != 'chip' ) {
      return;
    }

    $this->setSubmissionId( $submission_id );

    $submission = $this->getSubmission();
    $option     = $this->get_settings( $submission->form_id );
    $payment_id = $this->getMetaData( '_chip_purchase_id' );

    $chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], '' );
    $payment = $chip->get_payment( $payment_id );

    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('ff_chip_payment_$submission_id', 15);"
    );

    $transaction = $this->getTransaction( $submission_id, 'submission_id');

    if ( $transaction->id != $payment['reference'] ) {
      return;
    }

    if ( $transaction->status != 'paid' && $payment['status'] == 'paid') {
      $this->handlePaid( $submission, $transaction, $payment );
    }

    if ( $transaction->status != 'failed' && $payment['status'] != 'paid') {
      $this->handleFailed( $submission, $transaction, $payment );
    }

    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('gff_chip_payment_$submission_id');"
    );
  }
}

Chip_Fluent_Forms_Purchase::get_instance();