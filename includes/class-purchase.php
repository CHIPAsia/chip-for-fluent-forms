<?php
/**
 * Payment processor that bridges Fluent Forms Pro and the CHIP API.
 *
 * @package CHIPForFluentForms
 */

use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

/**
 * Chip_Fluent_Forms_Purchase — see file-level docblock above.
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
	protected $method = 'chip'; // Used by BaseProcessor->insertRefund($data).

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
	 * Constructor — empty. Hook registration is deferred to init() so subclasses
	 * and unit tests can override the action registration without instantiating
	 * the singleton.
	 */
	public function __construct() {
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

	/**
	 * Hooked on fluentform/process_payment_chip. Creates a pending transaction
	 * and dispatches to create_purchase() to talk to the CHIP API.
	 *
	 * The signature is dictated by BaseProcessor::init() / FF Pro's action
	 * callback contract — do not rename the parameters.
	 *
	 * @param int     $submissionId       Fluent Forms submission id.
	 * @param array   $submissionData     Raw submission payload.
	 * @param object  $form               The Fluent Forms form object.
	 * @param array   $methodSettings     Per-method settings from FF Pro.
	 * @param bool    $hasSubscriptions   True if the form has subscription items.
	 * @param float   $totalPayable       Total payable amount.
	 * @return bool|void
	 */
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

	/**
	 * Build the CHIP create_payment payload and send it to the gateway.
	 *
	 * On success, marks the transaction with the charge_id and returns a
	 * JSON success response that the FF Pro frontend will follow (the
	 * `nextAction=payment` field). On failure, logs and returns JSON error.
	 *
	 * @param object $transaction   The pending transaction row.
	 * @param object $submission    The Fluent Forms submission row.
	 * @param object $form          The Fluent Forms form object.
	 * @param array  $methodSettings Per-method settings from FF Pro.
	 */
	private function create_purchase( $transaction, $submission, $form, $methodSettings ) {
		$option = Chip_Fluent_Forms_Settings::for_form( (int) $form->id );

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
			'platform'         => 'fluentforms',
			'send_receipt'     => ! empty( $option['send_receipt'] ),
			'due'              => time() + ( (int) $option['due_strict_timing'] * 60 ),
			'brand_id'         => $option['brand_id'],
			'client'           => array(
				'email'     => PaymentHelper::getCustomerEmail( $submission, $form ),
				'full_name' => substr( PaymentHelper::getCustomerName( $submission, $form ), 0, 128 ),
			),
			'purchase'         => array(
				'timezone'   => apply_filters( 'ff_chip_purchase_timezone', $this->get_timezone() ),
				'currency'   => strtoupper( $submission->currency ),
				'due_strict' => ! empty( $option['due_strict'] ),
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

		if ( ! empty( $option['payment_method_whitelist'] ) ) {
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

		if ( true === $payment['is_test'] ) {
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
	 * Bail with wp_die if the form's currency is not in $supported_currencies.
	 *
	 * @param string $currency Three-letter currency code (e.g. 'MYR').
	 */
	private function is_form_currency_supported( $currency ) {

		if ( ! in_array( $currency, $this->supported_currencies, true ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: the unsupported currency code */
						__( 'Error! Currency not supported. The only supported currency is MYR and the current currency is %s.', 'chip-for-fluent-forms' ),
						$currency
					)
				)
			);
		}
	}

	/**
	 * Write a structured log entry describing a create_payment failure.
	 *
	 * Accepts either a WP_Error (preferred) or an arbitrary response payload.
	 *
	 * @param object $form       The Fluent Forms form object.
	 * @param object $submission The Fluent Forms submission row.
	 * @param mixed  $payment    WP_Error or response payload from CHIP.
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
				wp_json_encode( $payment )
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

	/**
	 * Backward-compatible passthrough to Chip_Fluent_Forms_Settings::for_form().
	 *
	 * Kept for any external subclasses that may have called this directly.
	 * Returns the new schema unchanged.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return array
	 */
	private function get_settings( $form_id ) {
		// Kept for backward compatibility with any external subclasses that may
		// have called this directly. Returns the new schema from
		// Chip_Fluent_Forms_Settings::for_form() unchanged.
		return Chip_Fluent_Forms_Settings::for_form( (int) $form_id );
	}

	/**
	 * Resolve the timezone string for the create_purchase payload.
	 *
	 * Falls back to 'UTC' if wp_timezone_string() returns something that
	 * doesn't match the IANA tz database format (e.g. a UTC offset).
	 *
	 * @return string
	 */
	private function get_timezone() {

		if ( preg_match( '/^[A-z]+\/[A-z\_\/\-]+$/', wp_timezone_string() ) ) {
			return wp_timezone_string();
		}

		return 'UTC';
	}

	/**
	 * Hooked on fluentform/payment_frameless_chip.
	 *
	 * Called when the user returns to the site from the CHIP-hosted checkout
	 * (success or cancel). Re-fetches the purchase from CHIP, dispatches
	 * to handlePaid/handleFailed as appropriate, then renders the
	 * frameless payment view.
	 *
	 * @param array $data The WP query vars posted to index.php?payment_method=chip.
	 */
	public function redirect( $data ) {

		$submission_id    = absint( $data['fluentform_payment'] );
		$transaction_hash = sanitize_text_field( $data['transaction_hash'] );

		if ( $data['payment_method'] !== 'chip' ) {
			return;
		}

		$this->setSubmissionId( $submission_id );

		$submission = $this->getSubmission();
		$option     = Chip_Fluent_Forms_Settings::for_form( (int) $submission->form_id );
		$payment_id = $this->getMetaData( '_chip_purchase_id' );

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], '' );
		$payment = $chip->get_payment( $payment_id );

		if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
			$this->log_vendor_lookup_failure( $submission, $payment, $payment_id );
			return;
		}

		$this->with_submission_lock(
			$submission_id,
			function () use ( $submission, $payment, $transaction_hash, $payment_id ) {

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
			}
		);

		$this->handleSessionRedirectBack( $data );
	}

	/**
	 * Render the frameless payment view after the user returns to the site.
	 *
	 * Copy-pasted from BaseProcessor with a minor tweak: shows the
	 * "Payment Cancelled" page if the transaction isn't paid.
	 *
	 * @param array $data The WP query vars posted to index.php?payment_method=chip.
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

		if ( 'paid' === $type ) {
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
	 * Mark a submission as paid (or requires_review on amount mismatch).
	 *
	 * Idempotent: a second call returns the cached return-data via
	 * the is_form_action_fired flag instead of re-processing.
	 *
	 * @param object $submission         The Fluent Forms submission row.
	 * @param object $transaction        The Fluent Forms transaction row.
	 * @param array  $vendorTransaction  The raw CHIP purchase payload.
	 */
	public function handlePaid( $submission, $transaction, $vendorTransaction ) {

		$this->setSubmissionId( $submission->id );

		if ( 'yes' === $this->getMetaData( 'is_form_action_fired' ) ) {
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

	/**
	 * Mark a submission as failed when the user abandons or cancels.
	 *
	 * @param object $submission         The Fluent Forms submission row.
	 * @param object $transaction        The Fluent Forms transaction row.
	 * @param array  $vendorTransaction  The raw CHIP purchase payload (may be partial).
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
	 * Hooked on fluentform/ipn_endpoint_chip.
	 *
	 * Server-to-server IPN entry point. Routes to success_callback()
	 * (for the user-IPN ping) or refund_callback() (for the refund
	 * webhook) based on the query vars.
	 */
	public function callback() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server, not user-submitted
		if ( ! isset( $_GET['payment_method'] ) || 'chip' !== $_GET['payment_method'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server, not user-submitted
		if ( isset( $_GET['submission_id'] ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server, not user-submitted
			$this->success_callback( absint( $_GET['submission_id'] ) );
		} else {

			$this->refund_callback();
		}
	}

	/**
	 * Server-to-server ping from CHIP after a successful purchase.
	 *
	 * Re-fetches the purchase and dispatches to handlePaid/handleFailed.
	 * The lock ensures the redirect path (which races this) doesn't
	 * double-process the same submission.
	 *
	 * @param int $submission_id Fluent Forms submission id.
	 */
	private function success_callback( $submission_id ) {

		$this->setSubmissionId( $submission_id );

		$submission = $this->getSubmission();
		$option     = Chip_Fluent_Forms_Settings::for_form( (int) $submission->form_id );
		$payment_id = $this->getMetaData( '_chip_purchase_id' );

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], '' );
		$payment = $chip->get_payment( $payment_id );

		if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
			$this->log_vendor_lookup_failure( $submission, $payment, $payment_id );
			return;
		}

		$this->with_submission_lock(
			$submission_id,
			function () use ( $submission, $payment, $payment_id ) {

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
			}
		);
	}

	/**
	 * Refund webhook from CHIP.
	 *
	 * Reads the raw request body, verifies the X-Signature header against
	 * the configured per-form public key, and (on a valid signature) calls
	 * handleRefund() to upsert the refund transaction.
	 */
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

		$this->with_submission_lock(
			$submission_id,
			function () use ( $payment, $payment_id, $submission_id ) {

				// re-fetch for thread safety inside the lock.
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
			}
		);
	}

	/**
	 * Insert (or update, idempotently) a refund transaction.
	 *
	 * Called by refund_callback() with the parsed CHIP refund payload.
	 * Delegates to BaseProcessor::updateRefund() for the actual write.
	 *
	 * @param int    $refund_amount  Refund amount in cents.
	 * @param int    $transaction_id Fluent Forms transaction id.
	 * @param int    $submission_id  Fluent Forms submission id.
	 * @param string $refund_id      CHIP refund id (used as charge_id).
	 */
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
	 *
	 * @param int|false $formId Fluent Forms form id, or false for global.
	 * @return string 'test' or 'live'.
	 */
	public function getPaymentMode( $formId = false ) {
		$global = get_option( 'fluent_form_chip_settings', array() );
		$mode   = isset( $global['payment_mode'] ) ? $global['payment_mode'] : 'test';

		if ( $formId ) {
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
	 *
	 * @param array  $errors            The current errors list.
	 * @param array  $paymentItems      Onetime payment items.
	 * @param array  $subscriptionItems Subscription items.
	 * @param object $form              The Fluent Forms form object.
	 * @return array The (possibly amended) errors list.
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

	/**
	 * Defensive 423 response for older FF Pro versions that don't fire the
	 * fluentform/validate_payment_items_chip filter.
	 *
	 * @param bool $has_subscription True if the submission has any subscription item.
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

	/**
	 * Acquire a MySQL named lock for the given submission id, run $work, release the lock.
	 *
	 * Wraps a closure so callers don't have to remember the GET_LOCK / RELEASE_LOCK
	 * pair (or the `$GLOBALS['wpdb']` indirection). Returns whatever the closure returns.
	 *
	 * The lock prevents duplicate paid/failed/refund processing when the user's
	 * browser redirect and CHIP's server IPN arrive concurrently.
	 *
	 * @param int      $submission_id Fluent Forms submission id.
	 * @param callable $work         The closure to run while the lock is held.
	 * @return mixed Whatever $work returns.
	 */
	private function with_submission_lock( $submission_id, callable $work ) {
		$lock_name = 'ff_chip_payment_' . (int) $submission_id;
		$GLOBALS['wpdb']->get_results(
			$GLOBALS['wpdb']->prepare( 'SELECT GET_LOCK(%s, 15)', $lock_name )
		);

		try {
			return $work();
		} finally {
			$GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}

	/**
	 * Log a vendor-lookup failure (typically from get_payment).
	 *
	 * @param object $submission The Fluent Forms submission row.
	 * @param mixed  $payment    WP_Error or response payload from CHIP.
	 * @param string $payment_id The CHIP purchase id we tried to look up.
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
	 * The webhook setup class stores the public key inside the new global option.
	 * For per-form setups, it is stored inside the per-form payment settings row.
	 * This helper centralizes the read.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return string PEM-encoded public key, or empty string if not configured.
	 */
	private function get_webhook_public_key( $form_id ) {
		return Chip_Fluent_Forms_Webhook_Setup::get_public_key_for_form( (int) $form_id );
	}
}

Chip_Fluent_Forms_Purchase::get_instance()->init();
