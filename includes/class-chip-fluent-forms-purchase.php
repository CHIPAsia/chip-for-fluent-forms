<?php
/**
 * CHIP purchase creation, redirect handling and callback processing.
 *
 * @package CHIPForFluentForms
 */

use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\FormBuilder\Notifications\EmailNotificationActions;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;
use FluentFormPro\Payments\PaymentHelper;

/**
 * Creates CHIP purchases for Fluent Forms payment submissions and settles them
 * from the CHIP callback and refund webhook.
 */
class Chip_Fluent_Forms_Purchase extends BaseProcessor {

	/**
	 * Single instance of the class.
	 *
	 * @var Chip_Fluent_Forms_Purchase|null
	 */
	private static $_instance;

	/**
	 * DuitNow QR group. duitnow_qr is the legacy identifier, dnqr the modern
	 * one. They are interchangeable at runtime; dnqr is preferred when both are
	 * available. Exposed to the merchant as a single group checkbox (duitnow_qr).
	 *
	 * @var array
	 */
	const DUITNOW_GROUP = array( 'duitnow_qr', 'dnqr' );

	/**
	 * Shopee Pay group. razer_shopeepay is the legacy identifier, shopee_pay the
	 * modern one (whitelist-only, never returned in the /payment_methods/ schema).
	 * shopee_pay is preferred when both are available. Mirrors the WHMCS gateway.
	 *
	 * @var array
	 */
	const SHOPEE_GROUP = array( 'razer_shopeepay', 'shopee_pay' );

	/**
	 * Currencies this gateway accepts.
	 *
	 * @var string[]
	 */
	private $supported_currencies = array( 'MYR' );

	/**
	 * Payment method slug, as used by BaseProcessor::insertRefund().
	 *
	 * @var string
	 */
	protected $method = 'chip';

	/**
	 * Gets the single instance of the class.
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
	 * Read one key from a CHIP API response.
	 *
	 * Chip_Fluent_Forms_API::call() returns null for every failure shape
	 * (transport error, non-2xx, unparseable JSON, error payload), so callers
	 * cannot assume the result is an array.
	 *
	 * Only array_key_exists() is fatal here: on PHP 8 passing that null as its
	 * second argument raises a TypeError, which is not an Exception, so it
	 * escapes every catch block and the plugin has none anyway. A bare
	 * $payment['status'] on the same null is merely a warning that evaluates to
	 * null, but it silently mis-drives the paid/failed branches, so it is
	 * routed through this helper as well.
	 *
	 * @param mixed  $response API result, or null on failure.
	 * @param string $key      Key to read.
	 * @return mixed Value, or null when the response is not a usable array.
	 */
	private static function get_response_value( $response, $key ) {
		if ( ! is_array( $response ) || ! array_key_exists( $key, $response ) ) {
			return null;
		}

		return $response[ $key ];
	}

	/**
	 * Whether an API call produced a usable response.
	 *
	 * @param mixed $response API result, or null on failure.
	 * @return bool
	 */
	public static function is_usable_response( $response ) {
		return is_array( $response );
	}

	/**
	 * Build the purchase `due` timestamp.
	 *
	 * Mirrors chip-for-woocommerce's get_due_timestamp(): an empty or zero
	 * timing means "no due limit", not "due now". Returning null lets the
	 * caller omit the parameter entirely, because CHIP rejects a `due` in the
	 * past with HTTP 400 due_not_greater_than_now.
	 *
	 * @param mixed $due_time Configured timing in minutes.
	 * @return int|null Unix timestamp, or null when no due limit is configured.
	 */
	public static function resolve_due_timestamp( $due_time ) {
		if ( '' === $due_time || null === $due_time || false === $due_time ) {
			return null;
		}

		$minutes = absint( $due_time );
		if ( 0 === $minutes ) {
			return null;
		}

		return time() + ( $minutes * 60 );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->add_action();
	}

	/**
	 * Registers the CHIP hooks with Fluent Forms.
	 *
	 * @return void
	 */
	public function add_action() {
		add_action( 'fluentform/process_payment_chip', array( $this, 'handlePaymentAction' ), 10, 6 );

		// This is redirect.
		add_action( 'fluentform/payment_frameless_chip', array( $this, 'redirect' ) );

		// This is callback.
		add_action( 'fluentform/ipn_endpoint_chip', array( $this, 'callback' ) );
	}

	/**
	 * Creates the pending transaction for a submitted CHIP payment.
	 *
	 * Fluent Forms Pro fires this as the 'fluentform/process_payment_chip'
	 * callback with six positional arguments, so the parameter names and their
	 * order are part of that contract.
	 *
	 * @param int    $submissionId     Submission ID.
	 * @param array  $submissionData   Submitted form data.
	 * @param object $form             Fluent Forms form.
	 * @param array  $methodSettings   CHIP payment method settings.
	 * @param bool   $hasSubscriptions Whether the form has subscriptions.
	 * @param float  $totalPayable     Total amount payable.
	 * @return void
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
	 * Builds the CHIP create_payment request and hands the redirect URL back.
	 *
	 * @param object $transaction    Pending transaction row.
	 * @param object $submission     Fluent Forms submission.
	 * @param object $form           Fluent Forms form.
	 * @param array  $methodSettings CHIP payment method settings.
	 * @return void
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

		$params = array(
			'success_callback' => $success_callback,
			'success_redirect' => $success_redirect,
			'failure_redirect' => $failure_redirect,
			'creator_agent'    => 'FluentForms: ' . FF_CHIP_MODULE_VERSION,
			// Reference value shall be using unique.
			// 'reference' => substr( $form->title, 0, 128 ), left disabled.
			'platform'         => 'fluentforms',
			'send_receipt'     => $option['send_rcpt'],
			'brand_id'         => $option['brand_id'],
			'client'           => array(
				'email'     => PaymentHelper::getCustomerEmail( $submission, $form ),
				'full_name' => substr( PaymentHelper::getCustomerName( $submission, $form ), 0, 128 ),
			),
			'purchase'         => array(
				'timezone'   => apply_filters( 'ff_chip_purchase_timezone', $this->get_timezone() ),
				'currency'   => strtoupper( $submission->currency ),
				'due_strict' => $option['due_strict'],
				'notes'      => substr( $form->title . ' | ' . $submission->id, 0, 10000 ),
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

			if ( $option['payment_method_shopee'] ) {
				$params['payment_method_whitelist'][] = 'shopee_pay';
			}

			if ( $option['payment_method_crypto'] ) {
				$params['payment_method_whitelist'][] = 'crypto_coin';
			}

			// In-memory migration: treat any stored legacy 'razer_shopeepay' entry
			// as the modern 'shopee_pay' so existing configs keep working without
			// a DB write. The resolver's SHOPEE_GROUP still accepts both.
			$params['payment_method_whitelist'] = array_map(
				static function ( $method ) {
					return 'razer_shopeepay' === $method ? 'shopee_pay' : $method;
				},
				$params['payment_method_whitelist']
			);

			// Resolve the DuitNow QR group (duitnow_qr legacy + dnqr modern) against
			// the merchant's actual /payment_methods/ availability, prioritizing dnqr.
			// Short-circuits (no API call) when the group is not configured.
			$params['payment_method_whitelist'] = $this->resolve_duitnow_methods(
				$params['payment_method_whitelist'],
				strtoupper( $submission->currency ),
				intval( $transaction->payment_total )
			);

			if ( empty( $params['payment_method_whitelist'] ) ) {
				unset( $params['payment_method_whitelist'] );
			}
		}

		$params = apply_filters( 'ff_chip_create_purchase_params', $params, $transaction, $submission, $form );

		// Only send `due` when a timing is actually configured. An empty timing
		// means "no due limit"; sending time() there is a timestamp in the past
		// by the time CHIP reads it, and CHIP rejects the whole purchase with
		// HTTP 400 due_not_greater_than_now.
		$due_timestamp = self::resolve_due_timestamp( $option['due_time'] );
		if ( null !== $due_timestamp ) {
			$params['due'] = $due_timestamp;
		}

		$chip    = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], $option['brand_id'] );
		$payment = $chip->create_payment( $params );

		if ( ! self::is_usable_response( $payment ) || ! array_key_exists( 'id', $payment ) ) {
			do_action(
				'ff_log_data',
				array(
					'parent_source_id' => $form->id,
					'source_type'      => 'submission_item',
					'source_id'        => $submission->id,
					'component'        => 'Payment',
					'status'           => 'error',
					'title'            => __( 'Failure to create purchase', 'chip-for-fluent-forms' ),
					'description'      => sprintf(
						/* translators: %s: Response returned by the CHIP create_payment API call. */
						__( 'User is not redirected to CHIP since failure to create purchase: %s', 'chip-for-fluent-forms' ),
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Diagnostic dump of the CHIP API error payload written to the Fluent Forms payment log; intentional log content.
						print_r( $payment, true )
					),
				)
			);

			wp_send_json_success(
				array(
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Diagnostic dump of the CHIP API error payload returned to the Fluent Forms AJAX caller; existing response shape preserved.
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
				'description'      => sprintf(
					/* translators: %s: CHIP checkout URL the customer is redirected to. */
					__( 'User redirect to CHIP for completing the payment: %s', 'chip-for-fluent-forms' ),
					esc_url( $payment['checkout_url'] )
				),
			)
		);

		if ( $payment['is_test'] ) {
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
	 * Resolve the configured payment_method_whitelist against the merchant's
	 * actual /payment_methods/ response, with preferred-identifier priority for
	 * the DuitNow QR and Shopee Pay groups.
	 *
	 * Groups are resolved with a single /payment_methods/ call and a single
	 * transient, shared across both groups.
	 *
	 * Steps:
	 *   1. Short-circuit: if the whitelist intersects neither group, return unchanged.
	 *   2. Group expansion: any group member in the whitelist expands to the full group.
	 *   3. Cache key: brand + currency + amount-bucket (round to 100-sen steps).
	 *   4. Try cache. On miss, call /payment_methods/ once.
	 *   5. Fallback: return expanded whitelist unchanged if the API fails.
	 *   6. Resolve each configured group against available methods.
	 *   7. Priority: dnqr wins over duitnow_qr; shopee_pay wins over razer_shopeepay.
	 *   8. Build the final whitelist (original non-group entries + resolved groups).
	 *
	 * @param array  $whitelist Configured payment_method_whitelist.
	 * @param string $currency  Order currency code (e.g. 'MYR').
	 * @param int    $amount    Order total in sen (e.g. 12345 = RM 123.45).
	 * @return array            Final whitelist to send to CHIP.
	 */
	private function resolve_duitnow_methods( array $whitelist, string $currency, int $amount ): array {
		// 1. Short-circuit: no group configured => no API call, no group injection.
		$groups = array();
		foreach ( array( self::DUITNOW_GROUP, self::SHOPEE_GROUP ) as $group ) {
			if ( count( array_intersect( $whitelist, $group ) ) > 0 ) {
				$groups[] = $group;
			}
		}

		if ( empty( $groups ) ) {
			return $whitelist;
		}

		// 2. Group expansion: configured groups expand to their full member sets.
		$expanded = $whitelist;
		foreach ( $groups as $group ) {
			$expanded = array_merge( $expanded, $group );
		}
		$expanded = array_values( array_unique( $expanded ) );

		// 3. Cache key: brand + currency + amount-bucket (round to 100-sen steps).
		$option    = $this->get_settings( $this->form->id );
		$cache_key = 'ff_chip_pm_' . md5( $option['brand_id'] . '|' . $currency . '|' . intval( $amount / 100 ) );

		// 4. Try cache. If hit, use it. If miss, call /payment_methods/ once.
		$available = get_transient( $cache_key );
		if ( false === $available ) {
			$chip     = Chip_Fluent_Forms_API::get_instance( $option['secret_key'], $option['brand_id'] );
			$response = $chip->payment_methods( $currency, '', $amount ); // No language param.
			if ( ! is_array( $response ) || ! isset( $response['available_payment_methods'] ) ) {
				// 5. Fallback: return expanded whitelist unchanged.
				return $expanded;
			}
			$available = $response['available_payment_methods'];
			set_transient( $cache_key, $available, 30 * MINUTE_IN_SECONDS );
		}

		// 6. Resolve each configured group against the merchant's available methods.
		$resolved = array();
		foreach ( $groups as $group ) {
			$members = array_values( array_intersect( $group, $available ) );

			// 7. Priority: dnqr wins over duitnow_qr; shopee_pay wins over razer_shopeepay.
			if ( in_array( 'dnqr', $members, true ) ) {
				$members = array_values( array_diff( $members, array( 'duitnow_qr' ) ) );
			}
			if ( in_array( 'shopee_pay', $members, true ) ) {
				$members = array_values( array_diff( $members, array( 'razer_shopeepay' ) ) );
			}

			$resolved = array_merge( $resolved, $members );
		}
		$resolved = array_values( array_unique( $resolved ) );

		// 8. Build final whitelist: original entries (with group members stripped) + resolved groups.
		$all_group_members = array_merge( self::DUITNOW_GROUP, self::SHOPEE_GROUP );
		$final             = array_values( array_diff( $expanded, $all_group_members ) );
		$final             = array_merge( $final, $resolved );

		return $final;
	}

	/**
	 * Stops the request when the submission currency is not supported.
	 *
	 * @param string $currency Submission currency code.
	 * @return void
	 */
	private function is_form_currency_supported( $currency ) {

		if ( ! in_array( $currency, $this->supported_currencies, true ) ) {
			/* translators: %s: Unsupported currency code. */
			printf( esc_html__( 'Error! Currency not supported. The only supported currency is MYR and the current currency is %s.', 'chip-for-fluent-forms' ), esc_html( $currency ) );
			exit( 200 );
		}
	}

	/**
	 * Reads the CHIP settings for a form, honouring per-form overrides.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	private function get_settings( $form_id ) {

		$options  = get_option( FF_CHIP_FSLUG );
		$postfix  = '';
		$form_cid = 'form-customize-' . $form_id;

		if ( array_key_exists( $form_cid, $options ) && $options[ $form_cid ] ) {
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
			'payment_method_shopee'  => empty( $options[ 'payment-method-shopee' . $postfix ] ) ? false : $options[ 'payment-method-shopee' . $postfix ],
			'payment_method_crypto'  => empty( $options[ 'payment-method-crypto' . $postfix ] ) ? false : $options[ 'payment-method-crypto' . $postfix ],
			'payment_method_card'    => empty( $options[ 'payment-method-card' . $postfix ] ) ? false : $options[ 'payment-method-card' . $postfix ],
		);
	}

	/**
	 * Gets the site timezone, or UTC when it is not a valid CHIP identifier.
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
	 * Handles the customer coming back from the CHIP checkout page.
	 *
	 * @param array $data Fluent Forms payment redirect data.
	 * @return void
	 */
	public function redirect( $data ) {

		$submission_id    = absint( $data['fluentform_payment'] );
		$transaction_hash = sanitize_text_field( $data['transaction_hash'] );

		if ( 'chip' !== $data['payment_method'] ) {
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

		// A failed re-query must not be treated as a failed payment: leave the
		// transaction untouched and let the CHIP callback settle it. Without
		// this guard $payment['status'] on a null result is a fatal error.
		$payment_status = self::get_response_value( $payment, 'status' );
		if ( null === $payment_status ) {
			$GLOBALS['wpdb']->get_results(
				"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
			);

			$this->handleSessionRedirectBack( $data );

			return;
		}

		if ( 'paid' !== $transaction->status && 'paid' === $payment_status ) {
			$this->handlePaid( $submission, $transaction, $payment );
		}

		if ( 'failed' !== $transaction->status && 'paid' !== $payment_status ) {
			$this->handleFailed( $submission, $transaction, $payment );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);

		$this->handleSessionRedirectBack( $data );
	}

	/**
	 * Shows the payment result view to the returned customer.
	 *
	 * Copy of BaseProcessor::handleSessionRedirectBack() with a minor tweak.
	 *
	 * @param array $data Fluent Forms payment redirect data.
	 * @return void
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
	 * Marks a submission as paid and runs the post-payment flow.
	 *
	 * @param object $submission        Fluent Forms submission.
	 * @param object $transaction       Pending transaction row.
	 * @param array  $vendorTransaction CHIP payment payload.
	 * @return mixed The result of completePaymentSubmission() when the form
	 *               action had already fired, otherwise null.
	 */
	public function handlePaid( $submission, $transaction, $vendorTransaction ) {

		$this->setSubmissionId( $submission->id );

		if ( 'yes' === $this->getMetaData( 'is_form_action_fired' ) ) {
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

		$email_feeds = wpFluent()->table( 'fluentform_form_meta' )
		->where( 'form_id', $this->getForm()->id )
		->where( 'meta_key', 'notifications' )
		->get();

		if ( ! $email_feeds ) {
			return;
		}

		$form_data            = $submission->response;
		$notification_manager = new \FluentForm\App\Services\Integrations\GlobalNotificationManager( wpFluentForm() );

		$active_email_feeds = $notification_manager->getEnabledFeeds( $email_feeds, $form_data, $submission->id );

		if ( ! $active_email_feeds ) {
			return;
		}

		$after_success_email_feeds = array_filter(
			$active_email_feeds,
			function ( $feed ) {
				return 'payment_success' === ArrayHelper::get( $feed, 'settings.feed_trigger_event' );
			}
		);

		if ( ! $after_success_email_feeds || 'yes' === Helper::getSubmissionMeta( $submission->id, '_ff_chip_on_payment_success' ) ) {
			return;
		}

		$ena = new EmailNotificationActions( wpFluentForm() );

		$entry = $ena->getEntry( $submission->id );

		foreach ( $after_success_email_feeds as $feed ) {
			$processedValues = $feed['settings'];
			unset( $processedValues['conditionals'] );

			$processedValues         = ShortCodeParser::parse(
				$processedValues,
				$submission->id,
				$form_data,
				$this->getForm(),
				false,
				$feed['meta_key']
			);
			$feed['processedValues'] = $processedValues;

			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Kept as the upstream reference: the notification call is deliberately disabled for this gateway.
			// $ena->notify( $feed, $form_data, $entry, $this->getForm() );
		}

		Helper::setSubmissionMeta( $submission->id, '_ff_chip_on_payment_success', 'yes', $this->getForm()->id );
	}

	/**
	 * Marks a submission and its transaction as failed.
	 *
	 * @param object $submission        Fluent Forms submission.
	 * @param object $transaction       Pending transaction row.
	 * @param array  $vendorTransaction CHIP payment payload.
	 * @return void
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
	 * Handles the CHIP callback (IPN) endpoint request.
	 *
	 * CHIP calls this endpoint directly, so there is no form nonce to verify.
	 * A refund notification authenticates itself with the RSA signature check
	 * in refund_callback(); the reads below only route the request.
	 *
	 * @return void
	 */
	public function callback() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CHIP calls this endpoint directly; there is no nonce to send or verify.
		if ( ! isset( $_GET['payment_method'] ) || 'chip' !== $_GET['payment_method'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CHIP calls this endpoint directly; there is no nonce to send or verify.
		if ( isset( $_GET['submission_id'] ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CHIP calls this endpoint directly; there is no nonce to send or verify.
			$this->success_callback( absint( $_GET['submission_id'] ) );
		} else {

			$this->refund_callback();
		}
	}

	/**
	 * Settles a purchase from the CHIP callback for a given submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return void
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

		// A failed re-query must not be treated as a failed payment; the CHIP
		// callback is the authority on the final status. Without this guard
		// $payment['status'] on a null result is a fatal error.
		$payment_status = self::get_response_value( $payment, 'status' );
		if ( null === $payment_status ) {
			$GLOBALS['wpdb']->get_results(
				"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
			);

			return;
		}

		if ( 'paid' !== $transaction->status && 'paid' === $payment_status ) {
			$this->handlePaid( $submission, $transaction, $payment );
		}

		if ( 'failed' !== $transaction->status && 'paid' !== $payment_status ) {
			$this->handleFailed( $submission, $transaction, $payment );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);
	}

	/**
	 * Handles the signed CHIP refund webhook.
	 *
	 * The payload is authenticated by verifying the X-Signature header against
	 * the merchant's CHIP public key, not by a WordPress nonce.
	 *
	 * @return void
	 */
	private function refund_callback() {
		$content     = file_get_contents( 'php://input' );
		$x_signature = isset( $_SERVER['HTTP_X_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) )
			: '';

		if ( empty( $content ) || ! isset( $x_signature ) ) {
			return;
		}

		$payment = json_decode( $content, true );

		// json_decode() returns null on malformed JSON, which makes every read
		// below a fatal error rather than a handled no-op.
		if ( ! is_array( $payment ) ) {
			return;
		}

		$payment_id = self::get_response_value( $payment['related_to'] ?? null, 'id' );
		$payment_id = null === $payment_id ? '' : sanitize_text_field( $payment_id );

		if ( 'payment.refunded' !== self::get_response_value( $payment, 'event_type' ) ) {
			return;
		}

		$transaction = $this->getTransaction( $payment_id, 'charge_id' );
		if ( is_null( $transaction ) ) {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The X-Signature header is base64-encoded by CHIP by design; decoding it here is what the RSA signature verification in the next line requires.
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
					'description'      => __( 'Refund unable to process due to verification failure', 'chip-for-fluent-forms' ),
				)
			);

			return;
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT GET_LOCK('ff_chip_payment_$submission_id', 15);"
		);

		// Get the transaction once, for thread safety.
		$transaction = $this->getTransaction( $submission_id, 'submission_id' );

		$transaction_by_charge_id = $this->getTransaction( $payment_id, 'charge_id' );

		if ( $transaction->id !== $transaction_by_charge_id->id ) {
			return;
		}

		if ( 'refunded' !== $transaction->status && 'success' === $payment['status'] && 'refund' === $payment['payment']['payment_type'] ) {
			$this->handleRefund( absint( $payment['payment']['amount'] ), $transaction->id, $submission_id, sanitize_text_field( $payment['id'] ) );
		}

		$GLOBALS['wpdb']->get_results(
			"SELECT RELEASE_LOCK('ff_chip_payment_$submission_id');"
		);
	}

	/**
	 * Records a refund against the transaction that CHIP refunded.
	 *
	 * @param int    $refund_amount  Refunded amount.
	 * @param int    $transaction_id Transaction ID.
	 * @param int    $submission_id  Submission ID.
	 * @param string $refund_id      CHIP refund ID.
	 * @return void
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
	 * Rejects a submission that contains a subscription.
	 *
	 * @param bool $has_subscription Whether the form has subscriptions.
	 * @return void
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
