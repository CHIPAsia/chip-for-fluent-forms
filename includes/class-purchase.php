<?php
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

class Chip_Fluent_Forms_Purchase extends BaseProcessor {

	private static $_instance;

	private $supported_currencies = array( 'MYR' );
	protected $method             = 'chip'; // used by BaseProcessor->insertRefund($data)

	public static function get_instance() {
		if ( self::$_instance === null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		// Hook registration is deferred to init() so subclasses / unit tests
		// can override the action registration without instantiating the singleton.
	}

	/**
	 * Register all action and filter hooks.
	 *
	 * Called once from the bottom of the file (Chip_Fluent_Forms_Purchase::get_instance()).
	 * Mirrors the modern BaseProcessor::init() convention used by PayPal / Mollie.
	 */
	public function init() {
		add_action( 'fluentform/process_payment_chip', array( $this, 'handlePaymentAction' ), 10, 6 );

		// Redirect-back from the CHIP-hosted checkout.
		add_action( 'fluentform/payment_frameless_chip', array( $this, 'redirect' ) );

		// Server-to-server IPN entry point (CHIP POSTs to index.php?payment_method=chip).
		add_action( 'fluentform/ipn_endpoint_chip', array( $this, 'callback' ) );

		// Form-level validation: surface "CHIP does not support subscriptions" on the
		// form itself rather than as a 423 JSON error after submit.
		add_filter( 'fluentform/validate_payment_items_chip', array( $this, 'validateSubmittedItems' ), 10, 4 );
	}

	public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscriptions, $totalPayable ) {

		// Form-level validation is now also hooked via fluentform/validate_payment_items_chip
		// (see init()), but keep this defensive short-circuit for older FF Pro versions that
		// don't pass subscriptions through the filter chain.
		$this->validate_if_subscription( $hasSubscriptions );

		$this->setSubmissionId( $submissionId );
		$this->form  = $form;
		$submission  = $this->getSubmission();
		$amountTotal = $this->getAmountTotal();

		if ( ! $amountTotal ) {
			return false;
		}

		$this->is_form_currency_supported( strtoupper( $submission->currency ) );

		$transaction = $this->createInitialPendingTransaction( $submission, $hasSubscriptions );

		$this->create_purchase( $transaction, $submission, $form, $methodSettings );
	}

	private function create_purchase( $transaction, $submission, $form, $methodSettings ) {
		$option = $this->get_settings( $form->id );

		$ipn_domain = defined( 'FF_CHIP_IPN_DOMAIN' ) && FF_CHIP_IPN_DOMAIN
			? FF_CHIP_IPN_DOMAIN
			: site_url( 'index.php' );

		/**
		 * Filter the base domain used to build CHIP success / failure / IPN callback URLs.
		 *
		 * Useful when a site is fronted by a reverse proxy that rewrites site_url().
		 *
		 * @param string $ipn_domain  The domain to use (default: site_url('index.php')).
		 * @param object $form        The Fluent Forms form.
		 */
		$ipn_domain = apply_filters( 'ff_chip_ipn_domain', $ipn_domain, $form );

		$success_redirect = add_query_arg(
			array(
				'fluentform_payment' => $submission->id,
				'payment_method'     => 'chip',
				'transaction_hash'   => $transaction->transaction_hash,
				'type'               => 'success',
			),
			$ipn_domain
		);

		$failure_redirect = add_query_arg(
			array(
				'fluentform_payment' => $submission->id,
				'payment_method'     => 'chip',
				'transaction_hash'   => $transaction->transaction_hash,
				'type'               => 'failed',
			),
			$ipn_domain
		);

		$success_callback = add_query_arg(
			array(
				'fluentform_payment_api_notify' => 1,
				'payment_method'                => 'chip',
				'submission_id'                 => $submission->id,
			),
			$ipn_domain
		);

		$additional_notes_array = ArrayHelper::get( $methodSettings, 'settings.notes.value', '' );
		$additional_notes = sanitize_text_field( ShortCodeParser::parse( $additional_notes_array, $submission->id, $submission->response, $form, false, true ) );

		$params = array(
			'success_callback' => $success_callback,
			'success_redirect' => $success_redirect,
			'failure_redirect' => $failure_redirect,
			'creator_agent'    => 'FluentForms: ' . FF_CHIP_MODULE_VERSION,
			// reference value shall be using unique
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
			$expanded = Chip_Fluent_Forms_Settings::expand_whitelist( $option['payment_method_whitelist'] );

			if ( ! empty( $expanded ) ) {
				$params['payment_method_whitelist'] = $expanded;
			}
		}

		$params = apply_filters( 'ff_chip_create_purchase_params', $params, $transaction, $submission, $form );

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], $option['brand_id'] );
		$payment = $chip->create_payment( $params );

		if ( is_wp_error( $payment ) ) {
			$this->log_create_payment_failure( $form, $submission, $payment );

			// Mark the transaction failed so the admin can see the attempt in the log.
			$this->changeSubmissionPaymentStatus( 'failed' );
			$this->changeTransactionStatus( $transaction->id, 'failed' );

			wp_send_json_error(
				array(
					'message' => $payment->get_error_message(),
				),
				423
			);
		}

		if ( ! is_array( $payment ) || ! array_key_exists( 'id', $payment ) ) {
			$this->log_create_payment_failure( $form, $submission, $payment );

			$this->changeSubmissionPaymentStatus( 'failed' );
			$this->changeTransactionStatus( $transaction->id, 'failed' );

			wp_send_json_error(
				array(
					'message' => __( 'CHIP API returned an unexpected response (no purchase id).', 'chip-for-fluent-forms' ),
				),
				423
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

	private function is_form_currency_supported( $currency ) {

		if ( ! in_array( $currency, $this->supported_currencies ) ) {
			wp_die( sprintf( __( 'Error! Currency not supported. The only supported currency is MYR and the current currency is %s.', 'chip-for-fluent-forms' ), esc_html( $currency ) ) );
		}
	}

	/**
	 * Write a structured log entry describing a create_payment failure.
	 *
	 * Accepts either a WP_Error (preferred) or an arbitrary response payload.
	 */
	private function log_create_payment_failure( $form, $submission, $payment ) {
		if ( is_wp_error( $payment ) ) {
			$description = sprintf(
				/* translators: 1: error message, 2: error code */
				__( 'User is not redirected to CHIP because create_payment failed: %1$s (code: %2$s).', 'chip-for-fluent-forms' ),
				$payment->get_error_message(),
				$payment->get_error_code()
			);
		} else {
			$description = sprintf(
				/* translators: %s: print_r of the response */
				__( 'User is not redirected to CHIP because create_payment returned no purchase id: %s', 'chip-for-fluent-forms' ),
				print_r( $payment, true )
			);
		}

		do_action(
			'ff_log_data',
			array(
				'parent_source_id' => $form->id,
				'source_type'      => 'submission_item',
				'source_id'        => $submission->id,
				'component'        => 'Payment',
				'status'           => 'error',
				'title'            => __( 'Failure to create purchase', 'chip-for-fluent-forms' ),
				'description'      => $description,
			)
		);
	}

	private function get_settings( $form_id ) {
		$form_id  = (int) $form_id;
		$resolved = Chip_Fluent_Forms_Settings::for_form( $form_id );

		// Translate the new shape into the flat shape the rest of the class still uses.
		return array(
			'secret_key'             => $resolved['secret_key'],
			'brand_id'               => $resolved['brand_id'],
			'send_rcpt'              => $resolved['send_receipt'] ? true : false,
			'due_strict'             => $resolved['due_strict'] ? true : false,
			'due_time'               => (int) $resolved['due_strict_timing'],
			'refund'                 => $resolved['synchronize_refund'] ? true : false,

			'payment_whitelist'      => ! empty( $resolved['payment_method_whitelist'] ),
			'payment_method_fpx'     => ! empty( $resolved['payment_method_whitelist']['fpx'] ),
			'payment_method_fpxb2b1' => ! empty( $resolved['payment_method_whitelist']['fpx_b2b1'] ),
			'payment_method_duitnow' => ! empty( $resolved['payment_method_whitelist']['duitnow_qr'] ),
			'payment_method_card'    => ! empty( $resolved['payment_method_whitelist']['cards'] ),

			// New: full whitelist map for the consumer in create_purchase().
			'payment_method_whitelist' => is_array( $resolved['payment_method_whitelist'] )
				? $resolved['payment_method_whitelist']
				: array(),
		);
	}

	private function get_timezone() {

		if ( preg_match( '/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string() ) ) {
			return wp_timezone_string();
		}

		return 'UTC';
	}

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

		if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
			$this->log_vendor_lookup_failure( $submission, $payment, $payment_id );
			return;
		}

		$this->with_submission_lock( $submission_id, function () use ( $submission, $payment, $transaction_hash, $payment_id ) {

			$transaction = $this->getTransaction( $transaction_hash, 'transaction_hash' );
			if ( ! $transaction ) {
				return;
			}

			$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );
			if ( ! $transaction_by_charge_id || $transaction->id !== $transaction_by_charge_id->id ) {
				return;
			}

			if ( $transaction->status !== 'paid' && ( $payment['status'] ?? '' ) === 'paid' ) {
				$this->handlePaid( $submission, $transaction, $payment );
			}

			if ( $transaction->status !== 'failed' && ( $payment['status'] ?? '' ) !== 'paid' ) {
				$this->handleFailed( $submission, $transaction, $payment );
			}
		} );

		$this->handleSessionRedirectBack( $data );
	}

	// copy pasted from BaseProcessor for minor tweak
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

	public function handlePaid( $submission, $transaction, $vendorTransaction ) {

		$this->setSubmissionId( $submission->id );

		if ( $this->getMetaData( 'is_form_action_fired' ) == 'yes' ) {
			return $this->completePaymentSubmission( false );
		}

		$status = sanitize_text_field( $vendorTransaction['status'] );

		// Cross-verify the amount reported by CHIP against the transaction we stored.
		// A mismatch (tampered webhook, stale data, wrong submission) downgrades the
		// status to 'requires_review' so the admin can investigate.
		$reported_total = isset( $vendorTransaction['purchase']['total'] )
			? intval( $vendorTransaction['purchase']['total'] )
			: 0;

		if (
			$reported_total > 0
			&& (int) $transaction->payment_total !== $reported_total
			&& 'paid' === $status
		) {
			$status = 'requires_review';

			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $submission->form_id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission->id,
					'component'        => 'Payment',
					'status'           => 'error',
					'title'            => __( 'CHIP amount mismatch', 'chip-for-fluent-forms' ),
					'description'      => sprintf(
						/* translators: 1: expected cents, 2: reported cents */
						__( 'Expected %1$d cents but CHIP reported %2$d cents. Payment marked for review.', 'chip-for-fluent-forms' ),
						(int) $transaction->payment_total,
						$reported_total
					),
				)
			);
		}

		$updateData = apply_filters(
			'ff_chip_handle_paid_data',
			array(
				'payment_note'  => maybe_serialize( $vendorTransaction ),
				'charge_id'     => sanitize_text_field( $vendorTransaction['id'] ),
				'payer_email'   => $vendorTransaction['client']['email'],
				'payment_total' => $reported_total > 0 ? $reported_total : (int) $transaction->payment_total,
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

		/**
		 * Fires after a CHIP-backed submission has been marked as paid (or requires_review).
		 *
		 * Notification feeds, fulfillment integrations, and email automations should hook
		 * here instead of the legacy "_ff_chip_on_payment_success" submission meta flag.
		 *
		 * @param object $submission         The Fluent Forms submission row.
		 * @param object $transaction        The Fluent Forms transaction row.
		 * @param array  $vendorTransaction  The raw CHIP purchase payload.
		 */
		do_action( 'ff_chip_payment_paid_chip', $submission, $transaction, $vendorTransaction );
	}

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

	private function success_callback( $submission_id ) {

		$this->setSubmissionId( $submission_id );

		$submission = $this->getSubmission();
		$option     = $this->get_settings( $submission->form_id );
		$payment_id = $this->getMetaData( '_chip_purchase_id' );

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], '' );
		$payment = $chip->get_payment( $payment_id );

		if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
			$this->log_vendor_lookup_failure( $submission, $payment, $payment_id );
			return;
		}

		$this->with_submission_lock( $submission_id, function () use ( $submission, $payment, $payment_id ) {

			$transaction = $this->getTransaction( $submission_id, 'submission_id' );
			if ( ! $transaction ) {
				return;
			}

			$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );
			if ( ! $transaction_by_charge_id || $transaction->id !== $transaction_by_charge_id->id ) {
				return;
			}

			if ( $transaction->status !== 'paid' && ( $payment['status'] ?? '' ) === 'paid' ) {
				$this->handlePaid( $submission, $transaction, $payment );
			}

			if ( $transaction->status !== 'failed' && ( $payment['status'] ?? '' ) !== 'paid' ) {
				$this->handleFailed( $submission, $transaction, $payment );
			}
		} );
	}

	private function refund_callback() {
		$content     = file_get_contents( 'php://input' );
		$x_signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '';

		if ( empty( $content ) || '' === $x_signature ) {
			return;
		}

		$payment = json_decode( $content, true );
		if ( ! is_array( $payment ) ) {
			return;
		}

		if ( ! isset( $payment['event_type'] ) || 'payment.refunded' !== $payment['event_type'] ) {
			return;
		}

		$payment_id = isset( $payment['related_to']['id'] )
			? sanitize_text_field( $payment['related_to']['id'] )
			: '';

		$transaction = $this->getTransaction( $payment_id, 'charge_id' );
		if ( is_null( $transaction ) ) {
			return;
		}

		$form_id       = $transaction->form_id;
		$submission_id = $transaction->submission_id;

		$public_key = $this->get_webhook_public_key( $form_id );
		if ( '' === $public_key ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form_id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission_id,
					'component'        => 'Payment',
					'status'           => 'error',
					'title'            => __( 'Refund', 'chip-for-fluent-forms' ),
					'description'      => __( 'Refund unable to process because no CHIP public key is configured for this form. Re-save the CHIP settings to (re)create the webhook.', 'chip-for-fluent-forms' ),
				)
			);
			return;
		}

		if ( 1 !== openssl_verify( $content, base64_decode( $x_signature ), $public_key, 'sha256WithRSAEncryption' ) ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form_id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission_id,
					'component'        => 'Payment',
					'status'           => 'info',
					'title'            => __( 'Refund', 'chip-for-fluent-forms' ),
					'description'      => __( 'Refund unable to process due to signature verification failure', 'chip-for-fluent-forms' ),
				)
			);

			return;
		}

		$this->with_submission_lock( $submission_id, function () use ( $payment, $payment_id, $submission_id ) {

			// re-fetch for thread safety inside the lock
			$transaction           = $this->getTransaction( $submission_id, 'submission_id' );
			$transaction_by_charge = $this->getTransaction( $payment_id, 'charge_id' );

			if ( ! $transaction || ! $transaction_by_charge || $transaction->id !== $transaction_by_charge->id ) {
				return;
			}

			if (
				$transaction->status !== 'refunded'
				&& isset( $payment['status'], $payment['payment']['payment_type'] )
				&& 'success' === $payment['status']
				&& 'refund' === $payment['payment']['payment_type']
			) {
				$this->handleRefund(
					absint( $payment['payment']['amount'] ),
					$transaction->id,
					$submission_id,
					sanitize_text_field( $payment['id'] )
				);
			}
		} );
	}

	public function handleRefund( $refund_amount, $transaction_id, $submission_id, $refund_id ) {
		$this->setSubmissionId( $submission_id );
		$transaction = $this->getTransaction( $transaction_id );

		if ( ! $transaction ) {
			return;
		}

		// Idempotent: a second refund webhook for the same submission updates the
		// existing refund row instead of inserting a new one.
		$this->updateRefund(
			$refund_amount,
			$transaction,
			$this->getSubmission(),
			'chip',
			$refund_id,
			'Refunded from CHIP. ID: ' . $refund_id
		);
	}

	/**
	 * Resolve the payment mode (test/live) for the given form.
	 *
	 * Reads from the new global settings option when present (post-migration) and
	 * falls back to a per-form override. If neither is available, defaults to 'test'
	 * so the new transaction row has a non-null payment_mode (BaseProcessor::refund()
	 * copies it onto refund rows).
	 */
	public function getPaymentMode( $formId = false ) {
		$global = get_option( 'fluent_form_chip_settings', array() );
		$mode   = isset( $global['payment_mode'] ) ? $global['payment_mode'] : 'test';

		if ( $formId && class_exists( 'Chip_Fluent_Forms_Settings' ) ) {
			$form_settings = Chip_Fluent_Forms_Settings::for_form( (int) $formId );
			if ( ! empty( $form_settings['is_active'] ) && isset( $form_settings['payment_mode'] ) ) {
				$mode = $form_settings['payment_mode'];
			}
		}

		/**
		 * Filter the resolved payment mode for a given form.
		 *
		 * @param string $mode   'test' or 'live'.
		 * @param int|false $formId The form id, or false for global.
		 */
		return apply_filters( 'ff_chip_payment_mode', $mode, $formId );
	}

	/**
	 * Form-level validation filter: reject submissions that mix a CHIP onetime
	 * payment with subscriptions (CHIP does not support subscriptions).
	 *
	 * Mirrors PayPalProcessor::validateSubmittedItems / MollieProcessor::validateSubmittedItems.
	 */
	public function validateSubmittedItems( $errors, $paymentItems, $subscriptionItems, $form ) {
		$has_onetime   = false;
		$has_recurring = false;

		foreach ( $paymentItems as $item ) {
			if ( ! empty( $item['line_total'] ) ) {
				$has_onetime = true;
				break;
			}
		}

		foreach ( $subscriptionItems as $item ) {
			if ( ! empty( $item['recurring_amount'] ) ) {
				$has_recurring = true;
				break;
			}
		}

		if ( $has_onetime && $has_recurring ) {
			$errors[] = __( 'CHIP Error: CHIP does not support subscriptions and one-time payments on the same submission.', 'chip-for-fluent-forms' );
		} elseif ( $has_recurring ) {
			$errors[] = __( 'CHIP Error: CHIP does not support subscriptions right now.', 'chip-for-fluent-forms' );
		}

		return $errors;
	}

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

	/**
	 * Acquire a MySQL named lock for the given submission id, run $work, release the lock.
	 *
	 * Wraps a closure so callers don't have to remember the GET_LOCK / RELEASE_LOCK
	 * pair (or the `$GLOBALS['wpdb']` indirection). Returns whatever the closure returns.
	 *
	 * The lock prevents duplicate paid/failed/refund processing when the user's
	 * browser redirect and CHIP's server IPN arrive concurrently.
	 */
	private function with_submission_lock( $submission_id, callable $work ) {
		$GLOBALS['wpdb']->get_results(
			$GLOBALS['wpdb']->prepare( "SELECT GET_LOCK(%s, 15);", 'ff_chip_payment_' . (int) $submission_id )
		);

		try {
			return $work();
		} finally {
			$GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare( "SELECT RELEASE_LOCK(%s);", 'ff_chip_payment_' . (int) $submission_id )
			);
		}
	}

	/**
	 * Log a vendor-lookup failure (typically from get_payment).
	 */
	private function log_vendor_lookup_failure( $submission, $payment, $payment_id ) {
		$description = is_wp_error( $payment )
			? sprintf(
				/* translators: 1: error message, 2: error code */
				__( 'Could not fetch CHIP purchase %1$s: %2$s (code: %3$s).', 'chip-for-fluent-forms' ),
				$payment_id,
				$payment->get_error_message(),
				$payment->get_error_code()
			)
			: sprintf(
				/* translators: %s: payment id */
				__( 'CHIP get_payment(%s) returned a non-array response.', 'chip-for-fluent-forms' ),
				$payment_id
			);

		do_action(
			'ff_log_data',
			array(
				'parent_source_id' => $submission->form_id,
				'source_type'      => 'submission_item',
				'source_id'        => $submission->id,
				'component'        => 'Payment',
				'status'           => 'error',
				'title'            => __( 'CHIP vendor lookup failed', 'chip-for-fluent-forms' ),
				'description'      => $description,
			)
		);
	}

	/**
	 * Look up the CHIP public key used to verify refund webhook signatures.
	 *
	 * The new webhook setup class (Chip_Fluent_Forms_Webhook_Setup) stores the
	 * public key inside the new global option. For per-form setups, it is stored
	 * inside the per-form payment settings row. This helper centralizes the read.
	 *
	 * @param int $form_id
	 * @return string PEM-encoded public key, or empty string if not configured.
	 */
	private function get_webhook_public_key( $form_id ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Webhook_Setup' ) ) {
			return '';
		}

		return Chip_Fluent_Forms_Webhook_Setup::get_public_key_for_form( (int) $form_id );
	}
}

Chip_Fluent_Forms_Purchase::get_instance()->init();
