<?php
/**
 * Payment processor that bridges Fluent Forms Pro and the CHIP API.
 *
 * Restored from the 1.x plugin (commit 2435b25^). Extends
 * `FluentFormPro\Payments\PaymentMethods\BaseProcessor`. The
 * method signatures here are dictated by the FF Pro parent
 * class and use camelCase parameters that we cannot rename
 * without breaking the contract — see the
 * ValidVariableName exclusion in phpcs.xml.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\Notifications\EmailNotificationActions;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

/**
 * CHIP purchase handler — extends BaseProcessor.
 */
class Chip_Fluent_Forms_Purchase extends BaseProcessor {

	/**
	 * Singleton instance.
	 *
	 * @var Chip_Fluent_Forms_Purchase|null
	 */
	private static $_instance;

	/**
	 * Currencies this payment method supports.
	 *
	 * @var string[]
	 */
	private $supported_currencies = array( 'MYR' );

	/**
	 * Method identifier — used by BaseProcessor->insertRefund($data).
	 *
	 * @var string
	 */
	protected $method = 'chip';

	/**
	 * Singleton accessor.
	 *
	 * @return Chip_Fluent_Forms_Purchase
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor: register action hooks.
	 */
	public function __construct() {
		$this->add_action();
	}

	/**
	 * Register the FF Pro payment actions.
	 */
	public function add_action() {
		add_action( 'fluentform/process_payment_chip', array( $this, 'handlePaymentAction' ), 10, 6 );

		// this is redirect
		add_action( 'fluentform/payment_frameless_chip', array( $this, 'redirect' ) );

		// this is callback
		add_action( 'fluentform/ipn_endpoint_chip', array( $this, 'callback' ) );
	}

	/**
	 * Handle the form submission: insert the transaction row and
	 * dispatch to create_purchase() to call the CHIP API.
	 *
	 * Method signature is dictated by FF Pro's BaseProcessor —
	 * the camelCase parameters cannot be renamed without breaking
	 * the contract.
	 *
	 * @param int    $submissionId     FF Pro submission id.
	 * @param array  $submissionData   Submission field values.
	 * @param object $form             Fluent Forms form object.
	 * @param array  $methodSettings   Per-method settings from FF.
	 * @param bool   $hasSubscriptions Whether the form has subscriptions.
	 * @param float  $totalPayable     Total amount to charge.
	 */
	public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable ) {

		$this->validate_if_subscription( $hasSubscriptions );

		$this->setSubmissionId( $submissionId );
		$this->form  = $form;
		$submission  = $this->getSubmission();
		$amountTotal = $this->getAmountTotal();

		$this->is_form_currency_supported( strtoupper( $submission->currency ) );

		$transactionId = $this->insertTransaction(
			array(
				'payment_total'  => $amountTotal,
				'status'         => 'pending',
				'currency'       => strtoupper( $submission->currency ),
				'payment_method' => 'chip',
			)
		);

		$transaction = $this->getTransaction( $transactionId );
		$this->create_purchase( $transaction, $submission, $form, $methodSettings );
	}

	/**
	 * Build the create-purchase params, call the CHIP API, and
	 * return the redirect URL.
	 *
	 * @param object $transaction    FF Pro transaction row.
	 * @param object $submission     FF Pro submission row.
	 * @param object $form           Fluent Forms form object.
	 * @param array  $methodSettings Per-method settings from FF.
	 */
	private function create_purchase( $transaction, $submission, $form, $methodSettings ) {
		$option = $this->get_settings( $form->id );

		$success_redirect = add_query_arg(
			array(
				'fluentform_payment' => $submission->id,
				'payment_method'     => 'chip',
				'transaction_hash'   => $transaction->transaction_hash,
				'type'               => 'success',
			),
			site_url( 'index.php' )
		);

		$failure_redirect = add_query_arg(
			array(
				'fluentform_payment' => $submission->id,
				'payment_method'     => 'chip',
				'transaction_hash'   => $transaction->transaction_hash,
				'type'               => 'failed',
			),
			site_url( 'index.php' )
		);

		$success_callback = add_query_arg(
			array(
				'fluentform_payment_api_notify' => 1,
				'payment_method'                => 'chip',
				'submission_id'                 => $submission->id,
			),
			site_url( 'index.php' )
		);

		$additional_notes_array = ArrayHelper::get( $methodSettings, 'settings.notes.value', '' );
		$additional_notes       = sanitize_text_field( ShortCodeParser::parse( $additional_notes_array, $submission->id, $submission->response, $form, false, true ) );

		$params = array(
			'success_callback' => $success_callback,
			'success_redirect' => $success_redirect,
			'failure_redirect' => $failure_redirect,
			'creator_agent'    => 'FluentForms: ' . FF_CHIP_MODULE_VERSION,
			// Reference value shall be using unique.
			// 'reference'        => substr($form->title, 0, 128),
			'platform'         => 'fluentforms',
			'send_receipt'     => $option['send_rcpt'],
			'due'              => time() + ( absint( $option['due_time'] ) * 60 ),
			'brand_id'         => $option['brand_id'],
			'client'           => array(
				'email'     => PaymentHelper::getCustomerEmail( $submission, $form ),
				'full_name' => substr( PaymentHelper::getCustomerName( $submission, $form ), 0, 128 ),
			),
			'purchase'         => array(
				'timezone'   => apply_filters( 'ff_chip_purchase_timezone', $this->get_timezone() ),
				'currency'   => strtoupper( $submission->currency ),
				'due_strict' => $option['due_strict'],
				'notes'      => substr( $form->title . ' | ' . $submission->id . $additional_notes, 0, 10000 ),
				'products'   => array(
					array(
						'name'     => substr( $form->title, 0, 256 ),
						'price'    => round( $transaction->payment_total ),
						'quantity' => '1',
					),
				),
			),
		);

		if ( $option['payment_whitelist'] ) {
			$params['payment_method_whitelist'] = array();

			if ( $option['payment_method_fpx'] ) {
				$params['payment_method_whitelist'][] = 'fpx';
			}

			if ( $option['payment_method_fpxb2b1'] ) {
				$params['payment_method_whitelist'][] = 'fpx_b2b1';
			}

			if ( $option['payment_method_card'] ) {
				$params['payment_method_whitelist'][] = 'visa';
				$params['payment_method_whitelist'][] = 'maestro';
				$params['payment_method_whitelist'][] = 'mastercard';
			}

			if ( $option['payment_method_duitnow'] ) {
				$params['payment_method_whitelist'][] = 'duitnow_qr';
			}

			if ( empty( $params['payment_method_whitelist'] ) ) {
				unset( $params['payment_method_whitelist'] );
			}
		}

		$params = apply_filters( 'ff_chip_create_purchase_params', $params, $transaction, $submission, $form );

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], $option['brand_id'] );
		$payment = $chip->create_payment( $params );

		if ( ! array_key_exists( 'id', $payment ) ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form->id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission->id,
					'component'        => 'Payment',
					'status'           => 'error',
					'title'            => __( 'Failure to create purchase', 'chip-for-fluent-forms' ),
					/* translators: %s: CHIP API error response payload */
					'description'      => sprintf( __( 'User is not redirected to CHIP since failure to create purchase: %s', 'chip-for-fluent-forms' ), print_r( $payment, true ) ),
				)
			);

			wp_send_json_error(
				array(
					'message' => print_r( $payment, true ),
				),
				500
			);
		}

		do_action( 'ff_chip_after_purchase_create', $transaction, $submission, $form, $payment );

		$this->updateTransaction(
			$transaction->id,
			array(
				'payment_mode' => $payment['is_test'] ? 'test' : 'live',
				'charge_id'    => $payment['id'],
			)
		);

		$this->setMetaData( '_chip_purchase_id', $payment['id'] );

		do_action(
			'ff_log_data',
			array(
				'parent_source_id' => $form->id,
				'source_type'      => 'submission_item',
				'source_id'        => $submission->id,
				'component'        => 'Payment',
				'status'           => 'info',
				'title'            => __( 'Redirect to CHIP', 'chip-for-fluent-forms' ),
				/* translators: %s: CHIP checkout URL */
				'description'      => sprintf( __( 'User redirect to CHIP for completing the payment: %s', 'chip-for-fluent-forms' ), esc_url( $payment['checkout_url'] ) ),
			)
		);

		if ( $payment['is_test'] == true ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form->id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission->id,
					'component'        => 'Payment',
					'status'           => 'info',
					'title'            => __( 'Test mode', 'chip-for-fluent-forms' ),
					'description'      => __( 'This is test environment where payment status is simulated.', 'chip-for-fluent-forms' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'nextAction'   => 'payment',
				'actionName'   => 'normalRedirect',
				'redirect_url' => esc_url( $payment['checkout_url'] ),
				'message'      => __( 'You are redirecting to chip-in.asia to complete the purchase. Please wait while you are redirecting....', 'chip-for-fluent-forms' ),
				'result'       => array(
					'insert_id' => $submission->id,
				),
			),
			200
		);
	}

	/**
	 * Die if the form's currency isn't in our supported list.
	 *
	 * @param string $currency Currency code (e.g. 'MYR').
	 */
	private function is_form_currency_supported( $currency ) {

		if ( ! in_array( $currency, $this->supported_currencies, true ) ) {
			/* translators: %s: configured form currency code */
			wp_die( sprintf( __( 'Error! Currency not supported. The only supported currency is MYR and the current currency is %s.', 'chip-for-fluent-forms' ), esc_html( $currency ) ) );
		}
	}

	/**
	 * Build the per-form effective settings array.
	 *
	 * Reads `get_option(FF_CHIP_FSLUG)` (the codestar option) and
	 * layers per-form overrides on top of global values when
	 * `form-customize-{form_id}` is truthy.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return array Flat settings array used by create_purchase() and the callbacks.
	 */
	private function get_settings( $form_id ) {

		$options  = get_option( FF_CHIP_FSLUG );
		$postfix  = '';
		$form_cid = 'form-customize-' . $form_id;

		if ( array_key_exists( $form_cid, $options ) and $options[ $form_cid ] ) {
			$postfix = "-$form_id";
		}

		return array(
			'secret_key'             => $options[ 'secret-key' . $postfix ],
			'brand_id'               => $options[ 'brand-id' . $postfix ],
			'send_rcpt'              => empty( $options[ 'send-receipt' . $postfix ] ) ? false : $options[ 'send-receipt' . $postfix ],
			'due_strict'             => empty( $options[ 'due-strict' . $postfix ] ) ? false : $options[ 'due-strict' . $postfix ],
			'due_time'               => $options[ 'due-strict-timing' . $postfix ],
			'refund'                 => empty( $options[ 'refund' . $postfix ] ) ? false : $options[ 'refund' . $postfix ],

			'payment_whitelist'      => empty( $options[ 'payment-method-whitelist' . $postfix ] ) ? false : $options[ 'payment-method-whitelist' . $postfix ],
			'payment_method_fpx'     => empty( $options[ 'payment-method-fpx' . $postfix ] ) ? false : $options[ 'payment-method-fpx' . $postfix ],
			'payment_method_fpxb2b1' => empty( $options[ 'payment-method-fpxb2b1' . $postfix ] ) ? false : $options[ 'payment-method-fpxb2b1' . $postfix ],
			'payment_method_duitnow' => empty( $options[ 'payment-method-duitnow' . $postfix ] ) ? false : $options[ 'payment-method-duitnow' . $postfix ],
			'payment_method_card'    => empty( $options[ 'payment-method-card' . $postfix ] ) ? false : $options[ 'payment-method-card' . $postfix ],
		);
	}

	/**
	 * Resolve a valid timezone string for the CHIP API.
	 *
	 * @return string WordPress timezone string, or 'UTC' as fallback.
	 */
	private function get_timezone() {

		if ( preg_match( '/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string() ) ) {
			return wp_timezone_string();
		}

		return 'UTC';
	}

	/**
	 * Handle the redirect-back from CHIP: refetch the purchase
	 * and dispatch paid/failed handling under a MySQL lock.
	 *
	 * @param array $data Sanitized query data from FF Pro.
	 */
	public function redirect( $data ) {

		$submission_id    = absint( $data['fluentform_payment'] );
		$transaction_hash = sanitize_text_field( $data['transaction_hash'] );

		if ( $data['payment_method'] !== 'chip' ) {
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

		$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );

		if ( $transaction->id !== $transaction_by_charge_id->id ) {
			return;
		}

		if ( $transaction->status != 'paid' && $payment['status'] == 'paid' ) {
			$this->handlePaid( $submission, $transaction, $payment );
		}

		if ( $transaction->status != 'failed' && $payment['status'] != 'paid' ) {
			$this->handleFailed( $submission, $transaction, $payment );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);

		$this->handleSessionRedirectBack( $data );
	}

	// Copy-pasted from BaseProcessor with a minor tweak.
	/**
	 * Render the payment view after the user returns from CHIP.
	 *
	 * @param array $data Sanitized query data from FF Pro.
	 */
	public function handleSessionRedirectBack( $data ) {
		$submissionId = intval( $data['fluentform_payment'] );
		$this->setSubmissionId( $submissionId );

		$submission = $this->getSubmission();

		$transactionHash = sanitize_text_field( $data['transaction_hash'] );
		$transaction     = $this->getTransaction( $transactionHash, 'transaction_hash' );

		if ( ! $transaction || ! $submission ) {
			return;
		}

		$type = $transaction->status;
		$this->getForm();

		if ( $type == 'paid' ) {
			$returnData = $this->getReturnData();
		} else {
			$returnData = array(
				'insert_id' => $submission->id,
				'title'     => __( 'Payment Cancelled', 'chip-for-fluent-forms' ),
				'result'    => false,
				'error'     => __( 'Looks like you have cancelled the payment', 'chip-for-fluent-forms' ),
			);
		}

		$returnData['type']   = 'success';
		$returnData['is_new'] = false;

		$this->showPaymentView( $returnData );
	}

	/**
	 * Handle a successful payment: mark transaction paid, run
	 * FF Pro submission processing, fire post-success emails.
	 *
	 * @param object $submission         FF Pro submission row.
	 * @param object $transaction        FF Pro transaction row.
	 * @param array  $vendorTransaction  Decoded CHIP purchase payload.
	 */
	public function handlePaid( $submission, $transaction, $vendorTransaction ) {

		$this->setSubmissionId( $submission->id );

		if ( $this->getMetaData( 'is_form_action_fired' ) == 'yes' ) {
			return $this->completePaymentSubmission( false );
		}

		$status = sanitize_text_field( $vendorTransaction['status'] );

		$updateData = apply_filters(
			'ff_chip_handle_paid_data',
			array(
				'payment_note'  => maybe_serialize( $vendorTransaction ),
				'charge_id'     => sanitize_text_field( $vendorTransaction['id'] ),
				'payer_email'   => $vendorTransaction['client']['email'],
				'payment_total' => intval( $vendorTransaction['purchase']['total'] ),
			),
			$submission,
			$transaction,
			$vendorTransaction
		);

		$this->updateTransaction( $transaction->id, $updateData );
		$this->changeSubmissionPaymentStatus( $status );
		$this->changeTransactionStatus( $transaction->id, $status );
		$this->recalculatePaidTotal();
		$this->setMetaData( 'is_form_action_fired', 'yes' );

		$submission_service = new SubmissionHandlerService();
		$submission_service->processSubmissionData( $this->submissionId, $submission->response, $this->getForm() );

		Helper::setSubmissionMeta( $submission->id, '_ff_chip_on_payment_success', 'yes', $this->getForm()->id );
	}

	/**
	 * Handle a failed payment: mark transaction failed and
	 * update the payment note.
	 *
	 * @param object $submission        FF Pro submission row.
	 * @param object $transaction       FF Pro transaction row.
	 * @param array  $vendorTransaction Decoded CHIP purchase payload.
	 */
	public function handleFailed( $submission, $transaction, $vendorTransaction ) {
		$this->setSubmissionId( $submission->id );

		$status = 'failed';

		$updateData = array(
			'payment_note' => maybe_serialize( $vendorTransaction ),
		);

		$this->updateTransaction( $transaction->id, $updateData );
		$this->changeSubmissionPaymentStatus( $status );
		$this->changeTransactionStatus( $transaction->id, $status );
	}

	/**
	 * IPN entry point: dispatch to success_callback() or
	 * refund_callback() based on the query string.
	 */
	public function callback() {

		if ( ! isset( $_GET['payment_method'] ) or $_GET['payment_method'] != 'chip' ) {
			return;
		}

		if ( isset( $_GET['submission_id'] ) ) {

			$this->success_callback( absint( $_GET['submission_id'] ) );
		} else {

			$this->refund_callback();
		}
	}

	/**
	 * Handle the success IPN: refetch the purchase and dispatch
	 * paid/failed handling under a MySQL lock.
	 *
	 * @param int $submission_id FF Pro submission id from the query.
	 */
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

		$transaction = $this->getTransaction( $submission_id, 'submission_id' );

		$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );

		if ( $transaction->id !== $transaction_by_charge_id->id ) {
			return;
		}

		if ( $transaction->status != 'paid' && $payment['status'] == 'paid' ) {
			$this->handlePaid( $submission, $transaction, $payment );
		}

		if ( $transaction->status != 'failed' && $payment['status'] != 'paid' ) {
			$this->handleFailed( $submission, $transaction, $payment );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);
	}

	/**
	 * Handle the refund webhook: verify signature, dispatch refund.
	 */
	private function refund_callback() {
		$content     = file_get_contents( 'php://input' );
		$x_signature = sanitize_text_field( $_SERVER['HTTP_X_SIGNATURE'] );

		if ( empty( $content ) or ! isset( $x_signature ) ) {
			return;
		}

		$payment    = json_decode( $content, true );
		$payment_id = sanitize_text_field( $payment['related_to']['id'] );

		if ( $payment['event_type'] != 'payment.refunded' ) {
			return;
		}

		if ( is_null( $transaction   = $this->getTransaction( $payment_id, 'charge_id' ) ) ) {
			return;
		}

		$form_id       = $transaction->form_id;
		$submission_id = $transaction->submission_id;

		$options = get_option( FF_CHIP_FSLUG );
		$postfix = '';

		if ( $options[ 'form-customize-' . $form_id ] ) {
			$postfix = "-$form_id";
		}

		$option     = get_option( 'fluent_form_chip_public_key', array() );
		$public_key = $option[ 'public-key' . $postfix ] ?? '';

		if ( openssl_verify( $content, base64_decode( $x_signature ), $public_key, 'sha256WithRSAEncryption' ) != 1 ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form_id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission_id,
					'component'        => 'Payment',
					'status'           => 'info',
					'title'            => __( 'Refund', 'chip-for-fluent-forms' ),
					'description'      => __( 'Refund unable to process due to verification failure', 'chip-for-fluent-forms' ),
				)
			);

			return;
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT GET_LOCK('ff_chip_payment_$submission_id', 15);"
		);

		// get transaction once for thread safe
		$transaction = $this->getTransaction( $submission_id, 'submission_id' );

		$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );

		if ( $transaction->id !== $transaction_by_charge_id->id ) {
			return;
		}

		if ( $transaction->status != 'refunded' && $payment['status'] == 'success' && $payment['payment']['payment_type'] == 'refund' ) {
			$this->handleRefund( absint( $payment['payment']['amount'] ), $transaction->id, $submission_id, sanitize_text_field( $payment['id'] ) );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);
	}

	/**
	 * Insert a refund transaction row via FF Pro.
	 *
	 * @param int    $refund_amount Refund amount in cents.
	 * @param int    $transaction_id FF Pro transaction id.
	 * @param int    $submission_id  FF Pro submission id.
	 * @param string $refund_id       CHIP refund id.
	 */
	public function handleRefund( $refund_amount, $transaction_id, $submission_id, $refund_id ) {
		$this->setSubmissionId( $submission_id );
		$transaction = $this->getTransaction( $transaction_id );

		if ( $this->getRefund( $refund_id, 'charge_id' ) ) {
			return;
		}

		$this->refund( $refund_amount, $transaction, $this->getSubmission(), 'chip', $refund_id, 'Refunded from CHIP. ID: ' . $refund_id );
	}

	/**
	 * Reject the request with HTTP 423 if the form has subscriptions.
	 *
	 * @param bool $has_subscription Whether the form has subscriptions.
	 */
	private function validate_if_subscription( $has_subscription ) {
		if ( $has_subscription ) {
			wp_send_json(
				array(
					'errors' => __( 'Error: CHIP does not support subscriptions right now.', 'chip-for-fluent-forms' ),
				),
				423
			);
		}
	}
}

Chip_Fluent_Forms_Purchase::get_instance();
