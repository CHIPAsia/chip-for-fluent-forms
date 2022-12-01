<?php
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\Notifications\EmailNotificationActions;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

class Chip_Fluent_Forms_Purchase extends BaseProcessor {

  private static $_instance;

  private $supported_currencies = ['MYR'];
  protected $method = 'chip'; // used by BaseProcessor->insertRefund($data)

  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  public function __construct() {
    $this->add_action();
  }

  public function add_action() {
    add_action( 'fluentform_process_payment_chip', array( $this, 'handlePaymentAction' ), 10, 6 );
    
    // this is redirect
    add_action( 'fluent_payment_frameless_chip', array( $this, 'redirect' ) );

    // this is callback
    add_action( 'fluentform_ipn_endpoint_chip', array( $this, 'callback' ) );
  }

  public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable ) {
    
    $this->validate_if_subscription( $hasSubscriptions );

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

    $transaction = $this->getTransaction( $transactionId );
    $this->create_purchase( $transaction, $submission, $form, $methodSettings );
  }

  private function create_purchase( $transaction, $submission, $form, $methodSettings ) {
    $option = $this->get_settings( $form->id );

    $success_redirect = add_query_arg(array(
      'fluentform_payment' => $submission->id,
      'payment_method'     => 'chip',
      'transaction_hash'   => $transaction->transaction_hash,
      'type'               => 'success'
    ), site_url('index.php'));

    $failure_redirect = add_query_arg(array(
      'fluentform_payment' => $submission->id,
      'payment_method'     => 'chip',
      'transaction_hash'   => $transaction->transaction_hash,
      'type'               => 'failed'
    ), site_url('index.php'));

    $success_callback = add_query_arg(array(
      'fluentform_payment_api_notify' => 1,
      'payment_method'                => 'chip',
      'submission_id'                 => $submission->id
    ), site_url('index.php'));

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

    if ( !array_key_exists( 'id', $payment ) ) {
      do_action('ff_log_data', [
        'parent_source_id' => $form->id,
        'source_type'      => 'submission_item',
        'source_id'        => $submission->id,
        'component'        => 'Payment',
        'status'           => 'error',
        'title'            => __( 'Failure to create purchase', 'chip-for-fluent-forms' ),
        'description'      => sprintf( __( 'User is not redirected to CHIP since failure to create purchase: %s', 'chip-for-fluent-forms' ), print_r( $payment, true ) ),
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
      'description'      => sprintf( __( 'User redirect to CHIP for completing the payment: %s', 'chip-for-fluent-forms' ), esc_url( $payment['checkout_url'] ) ),
    ]);

    if ( $payment['is_test'] == true ) {
      do_action('ff_log_data', [
        'parent_source_id' => $form->id,
        'source_type'      => 'submission_item',
        'source_id'        => $submission->id,
        'component'        => 'Payment',
        'status'           => 'info',
        'title'            => __( 'Test mode', 'chip-for-fluent-forms' ),
        'description'      => __( 'This is test environment where payment status is simulated.', 'chip-for-fluent-forms' ),
      ]);
    }

    wp_send_json_success([
      'nextAction'   => 'payment',
      'actionName'   => 'normalRedirect',
      'redirect_url' => esc_url( $payment['checkout_url'] ),
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
      $postfix = "-$form_id";
    }

    return array(
      'secret_key' => $options['secret-key' . $postfix],
      'brand_id'   => $options['brand-id' . $postfix],
      'send_rcpt'  => empty( $options['send-receipt' . $postfix] ) ? false : $options['send-receipt' . $postfix],
      'due_strict' => empty( $options['due-strict' . $postfix] ) ? false : $options['due-strict' . $postfix],
      'due_time'   => $options['due-strict-timing' . $postfix],
      'public_key' => $options['public-key' . $postfix]
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

    $transaction = $this->getTransaction( $transaction_hash, 'transaction_hash' );

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

  // copy pasted from BaseProcessor for minor tweak
  public function handleSessionRedirectBack($data)
  {
      $submissionId = intval($data['fluentform_payment']);
      $this->setSubmissionId($submissionId);

      $submission = $this->getSubmission();

      $transactionHash = sanitize_text_field($data['transaction_hash']);
      $transaction = $this->getTransaction($transactionHash, 'transaction_hash');

      if (!$transaction || !$submission) {
          return;
      }

      $type = $transaction->status;
      $this->getForm();

      if ($type == 'paid') {
          $returnData = $this->getReturnData();
      } else {
          $returnData = [
              'insert_id' => $submission->id,
              'title'     => __('Payment Cancelled', 'chip-for-fluent-forms'),
              'result'    => false,
              'error'     => __('Looks like you have cancelled the payment', 'chip-for-fluent-forms')
          ];
      }

      $returnData['type']   = 'success';
      $returnData['is_new'] = false;

      $this->showPaymentView($returnData);
  }

  public function handlePaid( $submission, $transaction, $vendorTransaction ) {

    $this->setSubmissionId( $submission->id );

    if ( $this->getMetaData( 'is_form_action_fired' ) == 'yes' ) {
      return $this->completePaymentSubmission( false );
    }

    $status = sanitize_text_field( $vendorTransaction['status'] );

    $updateData = [
      'payment_note'  => maybe_serialize($vendorTransaction),
      'charge_id'     => sanitize_text_field($vendorTransaction['id']),
      'payment_total' => intval($vendorTransaction['purchase']['total']),
    ];

    $this->updateTransaction($transaction->id, $updateData);
    $this->changeSubmissionPaymentStatus($status);
    $this->changeTransactionStatus($transaction->id, $status);
    $this->recalculatePaidTotal();
    $this->setMetaData('is_form_action_fired', 'yes');

    $email_feeds = wpFluent()->table( 'fluentform_form_meta' )
    ->where( 'form_id', $this->getForm()->id )
    ->where( 'meta_key', 'notifications' )
    ->get();

    if ( !$email_feeds ) {
      return;
    }

    $form_data            = $submission->response;
    $notification_manager = new  \FluentForm\App\Services\Integrations\GlobalNotificationManager(wpFluentForm());

    $active_email_feeds = $notification_manager->getEnabledFeeds($email_feeds, $form_data, $submission->id);

    if (! $active_email_feeds) {
      return;
    }

    $after_success_email_feeds = array_filter($active_email_feeds, function ($feed) {
      return 'payment_success' == ArrayHelper::get($feed, 'settings.feed_trigger_event');
    });

    if (! $after_success_email_feeds || 'yes' === Helper::getSubmissionMeta($submission->id, '_ff_chip_on_payment_success')) {
      return;
    }

    $ena = new EmailNotificationActions(wpFluentForm());

    $entry = $ena->getEntry( $submission->id );

    foreach ( $after_success_email_feeds as $feed ) {
      $processedValues = $feed['settings'];
      unset($processedValues['conditionals']);

      $processedValues = ShortCodeParser::parse(
          $processedValues,
          $submission->id,
          $form_data,
          $this->getForm(),
          false,
          $feed['meta_key']
      );
      $feed['processedValues'] = $processedValues;

      $ena->notify( $feed, $form_data, $entry, $this->getForm() );
    }

    Helper::setSubmissionMeta($submission->id, '_ff_chip_on_payment_success', 'yes', $this->getForm()->id );
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

  public function callback() {

    if ( !isset($_GET['payment_method']) OR $_GET['payment_method'] != 'chip' ) {
      return;
    }

    if ( isset( $_GET['submission_id'] ) ){

      $this->success_callback( absint( $_GET['submission_id'] ) );
    } else {

      $this->refund_callback();
    }
  }

  private function success_callback( $submission_id ) {

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
      "SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
    );
  }

  private function refund_callback() {
    $content     = file_get_contents( 'php://input' );
    $x_signature = $_SERVER['HTTP_X_SIGNATURE'];

    if ( empty( $content ) OR !isset( $x_signature ) ) {
      return;
    }

    $payment    = json_decode( $content, true );
    $payment_id = sanitize_text_field( $payment['related_to']['id'] );

    if ( $payment['event_type'] != 'payment.refunded' ) {
      return;
    }

    if ( is_null( $transaction   = $this->getTransaction( $payment_id, 'charge_id') ) ) {
      return;
    }

    $form_id       = $transaction->form_id;
    $submission_id = $transaction->submission_id;

    $option     = $this->get_settings( $form_id );
    $public_key = $option['public_key'];

    if ( openssl_verify( $content,  base64_decode( $x_signature ), $public_key, 'sha256WithRSAEncryption' ) != 1) {
      do_action('ff_log_data', [
        'parent_source_id' => $form_id,
        'source_type'      => 'submission_item',
        'source_id'        => $submission_id,
        'component'        => 'Payment',
        'status'           => 'info',
        'title'            => __( 'Refund', 'chip-for-fluent-forms' ),
        'description'      => __( 'Refund unable to process due to verification failure', 'chip-for-fluent-forms' ),
      ]);

      return;
    }

    $GLOBALS['wpdb']->get_results(
      "SELECT GET_LOCK('ff_chip_payment_$submission_id', 15);"
    );

    // get transaction once for thread safe
    $transaction = $this->getTransaction( $submission_id, 'submission_id');

    if ( $transaction->id != $payment['related_to']['reference'] ) {
      return;
    }

    if ( $transaction->status != 'refunded' && $payment['status'] == 'success' && $payment['payment']['payment_type'] == 'refund' ) {
      $this->handleRefund( absint( $payment['payment']['amount'] ), $transaction->id, $submission_id, sanitize_text_field( $payment['id'] ) );
    }

    $GLOBALS['wpdb']->get_results(
      "SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
    );
  }

  public function handleRefund( $refund_amount, $transaction_id, $submission_id, $refund_id ) {
    $this->setSubmissionId( $submission_id );
    $transaction = $this->getTransaction( $transaction_id );

    if ( $this->getRefund( $refund_id, 'charge_id' ) ) {
      return;
    }

    $this->refund( $refund_amount, $transaction, $this->getSubmission(), 'chip', $refund_id, 'Refunded from CHIP. ID: ' . $refund_id );
  }

  private function validate_if_subscription( $has_subscription )
  {
    if ( $has_subscription ) {
      wp_send_json([
        'errors' => __( 'Error: CHIP does not support subscriptions right now.', 'chip-for-fluent-forms' )
      ], 423);
    }
  }
}

Chip_Fluent_Forms_Purchase::get_instance();
