<?php

namespace Airwallex\Struct;

class PaymentIntent extends AbstractBase {

	const STATUS_REQUIRES_CAPTURE         = 'REQUIRES_CAPTURE';
	const STATUS_SUCCEEDED                = 'SUCCEEDED';
	const STATUS_REQUIRES_CUSTOMER_ACTION = 'REQUIRES_CUSTOMER_ACTION';

	const PENDING_STATUSES = array(
		self::STATUS_REQUIRES_CUSTOMER_ACTION,
	);
	const SUCCESS_STATUSES = ['SUCCEEDED', 'REQUIRES_CAPTURE', 'CAPTURE_REQUIRED', 'AUTHORIZED', 'DONE'];
	const PAYMENT_METHOD_TYPE_CARD = 'CARD';
	const THREE_DS_FRICTIONLESS_MAP = [
		'Y' => 'Y - Frictionless transaction',
		'N' => 'N - Non Frictionless transaction',
	];
	const THREE_DS_AUTHENTICATED_MAP = [
		'Y' => 'Y - Authenticated',
		'N' => 'N - Not Authenticated',
		'U' => 'U - Authentication could not be performed',
		'A' => 'A - Attempted to authenticate',
		'C' => 'C - Challenge required',
		'R' => 'R - Authentication/ Account Verification Rejected',
	];
	const CARD_REFERRER_DATA_TYPE = 'woocommerce_credit_card';

	protected $id;
	protected $requestId;
	protected $amount;
	protected $currency;
	protected $order;
	protected $merchantOrderId;
	protected $customerId;
	protected $paymentConsentId;
	protected $descriptor;
	protected $metadata = [];
	protected $status;
	protected $capturedAmount;
	protected $latestPaymentAttempt;
	protected $createdAt;
	protected $updatedAt;
	protected $cancelledAt;
	protected $cancellationReason;
	protected $nextAction;
	protected $clientSecret;
	protected $supplementaryAmount;
	protected $returnUrl;
	protected $baseAmount;
	protected $baseCurrency;
	protected $customer;
	protected $availablePaymentMethodTypes;
	protected $currencySwitcher;
	protected $customerPaymentMethods;
	protected $customerPaymentConsents;

	/**
	 * Get customer ID
	 *
	 * @return mixed
	 */
	public function getCustomerId() {
		return $this->customerId;
	}

	/**
	 * Set customer ID
	 *
	 * @param mixed $customerId
	 * @return PaymentIntent
	 */
	public function setCustomerId( $customerId ) {
		$this->customerId = $customerId;
		return $this;
	}



	/**
	 * Get payment consent ID
	 *
	 * @return mixed
	 */
	public function getPaymentConsentId() {
		return $this->paymentConsentId;
	}

	/**
	 * Set payment consent ID
	 *
	 * @param mixed $paymentConsentId
	 * @return PaymentIntent
	 */
	public function setPaymentConsentId( $paymentConsentId ) {
		$this->paymentConsentId = $paymentConsentId;
		return $this;
	}

	/**
	 * Get metadata
	 *
	 * @return array
	 */
	public function getMetadata() {
		return $this->metadata;
	}

	/**
	 * Set metadata
	 *
	 * @param array $metadata
	 * @return PaymentIntent
	 */
	public function setMetadata( $metadata ) {
		$this->metadata = $metadata;
		return $this;
	}


	/**
	 * Get last payment attempt
	 *
	 * @return mixed
	 */
	public function getLatestPaymentAttempt() {
		return $this->latestPaymentAttempt;
	}

	/**
	 * Set last payment attempt
	 *
	 * @param mixed $latestPaymentAttempt
	 * @return PaymentIntent
	 */
	public function setLatestPaymentAttempt( $latestPaymentAttempt ) {
		$this->latestPaymentAttempt = $latestPaymentAttempt;
		return $this;
	}


	/**
	 * Get client secret
	 *
	 * @return mixed
	 */
	public function getClientSecret() {
		return $this->clientSecret;
	}

	/**
	 * Set client secret
	 *
	 * @param mixed $clientSecret
	 * @return PaymentIntent
	 */
	public function setClientSecret( $clientSecret ) {
		$this->clientSecret = $clientSecret;
		return $this;
	}

	/**
	 * Get payment intent ID
	 *
	 * @return mixed
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Set payment intent ID
	 *
	 * @param mixed $id
	 * @return PaymentIntent
	 */
	public function setId( $id ) {
		$this->id = $id;
		return $this;
	}

	/**
	 * Get request ID
	 *
	 * @return mixed
	 */
	public function getRequestId() {
		return $this->requestId;
	}

	/**
	 * Set request ID
	 *
	 * @param mixed $requestId
	 * @return PaymentIntent
	 */
	public function setRequestId( $requestId ) {
		$this->requestId = $requestId;
		return $this;
	}

	/**
	 * Get intent amount
	 *
	 * @return mixed
	 */
	public function getAmount() {
		return $this->amount;
	}

	/**
	 * Set intent amount
	 *
	 * @param mixed $amount
	 * @return PaymentIntent
	 */
	public function setAmount( $amount ) {
		$this->amount = $amount;
		return $this;
	}

	/**
	 * Get currency
	 *
	 * @return mixed
	 */
	public function getCurrency() {
		return $this->currency;
	}

	/**
	 * Set currency
	 *
	 * @param mixed $currency
	 * @return PaymentIntent
	 */
	public function setCurrency( $currency ) {
		$this->currency = $currency;
		return $this;
	}

	/**
	 * Get order
	 *
	 * @return mixed
	 */
	public function getOrder() {
		return $this->order;
	}

	/**
	 * Set order
	 *
	 * @param mixed $order
	 * @return PaymentIntent
	 */
	public function setOrder( $order ) {
		$this->order = $order;
		return $this;
	}

	/**
	 * Get return url
	 *
	 * @return mixed
	 */
	public function getReturnUrl() {
		return $this->returnUrl;
	}

	/**
	 * Set return url
	 *
	 * @param mixed $returnUrl
	 * @return PaymentIntent
	 */
	public function setReturnUrl( $returnUrl ) {
		$this->returnUrl = $returnUrl;
		return $this;
	}

	/**
	 * Get merchant order id
	 *
	 * @return mixed
	 */
	public function getMerchantOrderId() {
		return $this->merchantOrderId;
	}

	/**
	 * Set merchant order id
	 *
	 * @param mixed $merchantOrderId
	 * @return PaymentIntent
	 */
	public function setMerchantOrderId( $merchantOrderId ) {
		$this->merchantOrderId = $merchantOrderId;
		return $this;
	}

	/**
	 * Get supplementary amount
	 *
	 * @return mixed
	 */
	public function getSupplementaryAmount() {
		return $this->supplementaryAmount;
	}

	/**
	 * Set supplementary amount
	 *
	 * @param mixed $supplementaryAmount
	 * @return PaymentIntent
	 */
	public function setSupplementaryAmount( $supplementaryAmount ) {
		$this->supplementaryAmount = $supplementaryAmount;
		return $this;
	}

	/**
	 * Get descriptor
	 *
	 * @return mixed
	 */
	public function getDescriptor() {
		return $this->descriptor;
	}

	/**
	 * Set descriptor
	 *
	 * @param mixed $descriptor
	 * @return PaymentIntent
	 */
	public function setDescriptor( $descriptor ) {
		$this->descriptor = $descriptor;
		return $this;
	}

	/**
	 * Get status
	 *
	 * @return mixed
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * Set status
	 *
	 * @param mixed $status
	 * @return PaymentIntent
	 */
	public function setStatus( $status ) {
		$this->status = $status;
		return $this;
	}

	/**
	 * Get capture amount
	 *
	 * @return mixed
	 */
	public function getCapturedAmount() {
		return $this->capturedAmount;
	}

	/**
	 * Set capture amount
	 *
	 * @param mixed $capturedAmount
	 * @return PaymentIntent
	 */
	public function setCapturedAmount( $capturedAmount ) {
		$this->capturedAmount = $capturedAmount;
		return $this;
	}

	/**
	 * Get created time
	 *
	 * @return mixed
	 */
	public function getCreatedAt() {
		return $this->createdAt;
	}

	/**
	 * Set created time
	 *
	 * @param mixed $createdAt
	 * @return PaymentIntent
	 */
	public function setCreatedAt( $createdAt ) {
		$this->createdAt = $createdAt;
		return $this;
	}

	/**
	 * Get updated time
	 *
	 * @return mixed
	 */
	public function getUpdatedAt() {
		return $this->updatedAt;
	}

	/**
	 * Set updated time
	 *
	 * @param mixed $updatedAt
	 * @return PaymentIntent
	 */
	public function setUpdatedAt( $updatedAt ) {
		$this->updatedAt = $updatedAt;
		return $this;
	}
	/**
	 * Get cancelled at
	 * 
	 * @return mixed
	 */
	public function getCancelledAt() {
		return $this->nextAction;
	}

	/**
	 * Set cancelled at
	 * 
	 * @param mixed $cancelledAt
	 * @return PaymentIntent
	 */
	public function setCancelledAt( $cancelledAt ) {
		$this->cancelledAt = $cancelledAt;
		return $this;
	}

	/**
	 * Get cancellation reason
	 * 
	 * @return array
	 */
	public function getCancellationReason() {
		return $this->cancellationReason;
	}

	/**
	 * Set next action
	 * 
	 * @param mixed $cancellationReason
	 * @return PaymentIntent
	 */
	public function setCancellationReason( $cancellationReason ) {
		$this->cancellationReason = $cancellationReason;
		return $this;
	}

	/**
	 * Get next action
	 * 
	 * @return mixed
	 */
	public function getNextAction() {
		return $this->nextAction;
	}

	/**
	 * Set next action
	 * 
	 * @param mixed $nextAction
	 * @return PaymentIntent
	 */
	public function setNextAction( $nextAction ) {
		$this->nextAction = $nextAction;
		return $this;
	}

	/**
	 * Get base amount
	 * 
	 * @return mixed
	 */
	public function getBaseAmount() {
		return $this->baseAmount;
	}

	/**
	 * Set base amount
	 * 
	 * @param mixed $baseAmount
	 * @return PaymentIntent
	 */
	public function setBaseAmount( $baseAmount ) {
		$this->baseAmount = $baseAmount;
		return $this;
	}

	/**
	 * Get base currency
	 * 
	 * @return mixed
	 */
	public function getBaseCurrency() {
		return $this->baseCurrency;
	}

	/**
	 * Set base currency
	 * 
	 * @param mixed $baseCurrency
	 * @return PaymentIntent
	 */
	public function setBaseCurrency( $baseCurrency ) {
		$this->baseCurrency = $baseCurrency;
		return $this;
	}

	/**
	 * Get customer
	 * 
	 * @return mixed
	 */
	public function getCustomer() {
		return $this->customer;
	}

	/**
	 * Set customer
	 * 
	 * @param mixed $customer
	 * @return PaymentIntent
	 */
	public function setCustomer( $customer ) {
		$this->customer = $customer;
		return $this;
	}

	/**
	 * Get available payment method types
	 * 
	 * @return mixed
	 */
	public function getAvailablePaymentMethodTypes() {
		return $this->availablePaymentMethodTypes;
	}

	/**
	 * Set available payment method types
	 * 
	 * @param mixed $availablePaymentMethodTypes
	 * @return PaymentIntent
	 */
	public function setAvailablePaymentMethodTypes( $availablePaymentMethodTypes ) {
		$this->availablePaymentMethodTypes = $availablePaymentMethodTypes;
		return $this;
	}

	/**
	 * Get currency switcher
	 * 
	 * @return mixed
	 */
	public function getCurrencySwitcher() {
		return $this->currencySwitcher;
	}

	/**
	 * Set currency switcher
	 * 
	 * @param mixed $currencySwitcher
	 * @return PaymentIntent
	 */
	public function setCurrencySwitcher( $currencySwitcher ) {
		$this->currencySwitcher = $currencySwitcher;
		return $this;
	}

	/**
	 * Get customer payment methods
	 * 
	 * @return mixed
	 */
	public function getCustomerPaymentMethods() {
		return $this->customerPaymentMethods;
	}

	/**
	 * Set customer payment methods
	 * 
	 * @param mixed $customerPaymentMethods
	 * @return PaymentIntent
	 */
	public function setCustomerPaymentMethods( $customerPaymentMethods ) {
		$this->customerPaymentMethods = $customerPaymentMethods;
		return $this;
	}

	/**
	 * Get customer payment consents
	 * 
	 * @return mixed
	 */
	public function getCustomerPaymentConsents() {
		return $this->customerPaymentConsents;
	}

	/**
	 * Set customer payment consents
	 * 
	 * @param mixed $customerPaymentConsents
	 * @return PaymentIntent
	 */
	public function setCustomerPaymentConsents( $customerPaymentConsents ) {
		$this->customerPaymentConsents = $customerPaymentConsents;
		return $this;
	}


	/**
	 * Retrieves the payment method type for the PaymentIntent.
	 *
	 * @return string The payment method type. Returns an empty string if not available.
	 */
	public function getPaymentMethodType() {
		return empty($this->latestPaymentAttempt['payment_method']['type']) ? '' : $this->latestPaymentAttempt['payment_method']['type'];
	}

	/**
	 * Retrieves the Address verification result of the card used for the payment.
	 *
	 * @return string The formatted HTML string containing the AVS result.
	 */
	public function getCardAVSResult() {
		$additionalInfo = [
			'avs_check' => isset($this->latestPaymentAttempt['authentication_data']['avs_result']) ? $this->latestPaymentAttempt['authentication_data']['avs_result'] : '',
			'brand' => isset($this->latestPaymentAttempt['payment_method']['card']['brand']) ? $this->latestPaymentAttempt['payment_method']['card']['brand'] : '',
			'last4' => isset($this->latestPaymentAttempt['payment_method']['card']['last4']) ? $this->latestPaymentAttempt['payment_method']['card']['last4'] : '',
			'cvc_check' => isset($this->latestPaymentAttempt['authentication_data']['cvc_result']) ? $this->latestPaymentAttempt['authentication_data']['cvc_result'] : '',
		];

		$orderNote = sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
				<li>%3$s</li>
				<li>%4$s</li>
				<li>%5$s</li>
			</ul>',
			__('Address Verification Result', 'airwallex-online-payments-gateway'),
			__('AVS:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['avs_check']),
			__('Card Brand:', 'airwallex-online-payments-gateway') . ' ' . esc_html(strtoupper($additionalInfo['brand'])),
			__('Card Last Digits:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['last4']),
			__('CVC:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['cvc_check'])
		);

		return $orderNote;
	}

	/**
	 * Retrieves the 3DS authentication data of the payment.
	 *
	 * @return string The formatted HTML string containing the 3DS authentication data.
	 */
	public function getThreeDSAuthenticationData() {
		$authData = [
			'threeDS_triggered' => !empty($this->latestPaymentAttempt['authentication_data']['ds_data']['version']) ? true : false,
			"frictionless" => isset($this->latestPaymentAttempt['authentication_data']['ds_data']['frictionless']) ? $this->latestPaymentAttempt['authentication_data']['ds_data']['frictionless'] : '',
			'authenticated' => isset($this->latestPaymentAttempt['authentication_data']['ds_data']['pa_res_status']) ? $this->latestPaymentAttempt['authentication_data']['ds_data']['pa_res_status'] : '',
		];

		$orderNote = $authData['threeDS_triggered'] ? sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
				<li>%3$s</li>
				<li>%4$s</li>
			</ul>',
			__('3D Secure Data', 'airwallex-online-payments-gateway'),
			__('Triggered:', 'airwallex-online-payments-gateway') . ' ' . __('Y - Triggered', 'airwallex-online-payments-gateway'),
			__('Frictionless:', 'airwallex-online-payments-gateway') . ' ' . (isset(self::THREE_DS_FRICTIONLESS_MAP[$authData['frictionless']]) ? self::THREE_DS_FRICTIONLESS_MAP[$authData['frictionless']] : $authData['frictionless']),
			__('Authenticated:', 'airwallex-online-payments-gateway') . ' ' . (isset(self::THREE_DS_AUTHENTICATED_MAP[$authData['authenticated']]) ? self::THREE_DS_AUTHENTICATED_MAP[$authData['authenticated']] : $authData['authenticated'])
		) : sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
			</ul>',
			__('3D Secure Data', 'airwallex-online-payments-gateway'),
			__('Triggered:', 'airwallex-online-payments-gateway') . ' ' . __('N - Not Triggered', 'airwallex-online-payments-gateway')
		);

		return $orderNote;
	}
}
