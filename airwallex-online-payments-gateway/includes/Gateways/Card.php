<?php

namespace Airwallex\Gateways;

use Airwallex\Client\CardClient;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\GenerateClientSecret;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\Disable as DisablePaymentConsent;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentConsent\All as AllPaymentConsents;
use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Airwallex\Services\Util;
use Airwallex\Struct\PaymentIntent;
use Error;
use Exception;
use WC_AJAX;
use WC_HTTPS;
use WC_Order;
use WC_Payment_Gateway;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WC_Subscriptions_Cart;
use WCS_Payment_Tokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Card extends WC_Payment_Gateway {

	use AirwallexGatewayTrait;
	use AirwallexSettingsTrait;

	const ROUTE_SLUG              = 'airwallex_card';
	const ROUTE_SLUG_WECHAT       = 'airwallex_wechat';
	const GATEWAY_ID              = 'airwallex_card';
	const DESCRIPTION_PLACEHOLDER = '<!-- -->';
	const TOKEN_META_KEY_CONSENT_DETAIL = 'awx_payment_consent_detail';

	protected static $instance = null;

	public $method_title = 'Airwallex - Cards';
	public $method_description;
	public $title       = 'Airwallex - Cards';
	public $description = '';
	public $icon        = AIRWALLEX_PLUGIN_URL . '/assets/images/airwallex_cc_icon.svg';
	public $id          = self::GATEWAY_ID;
	public $plugin_id;
	public $supports = array(
		'products',
		'refunds',

		'subscriptions',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
		'subscription_amount_changes',
		'subscription_date_changes',
		'multiple_subscriptions',
		'subscription_payment_method_change_admin',
		'subscription_payment_method_change',
		'subscription_payment_method_change_customer',
	);
	public $logService;

	public function __construct() {
		$this->plugin_id = AIRWALLEX_PLUGIN_NAME;
		$this->init_settings();
		$this->description = $this->get_option( 'description' ) ? $this->get_option( 'description' ) : ( $this->get_option( 'checkout_form_type' ) === 'inline' ? self::DESCRIPTION_PLACEHOLDER : '' );
		if ( Util::getClientId() && Util::getApiKey() ) {
			$this->method_description = __( 'Accept only credit and debit card payments with your Airwallex account.', 'airwallex-online-payments-gateway' );
			$this->form_fields        = $this->get_form_fields();
		}

		$this->title      = $this->get_option( 'title' ) ?: 'Card';
		$this->tabTitle   = 'Cards';
		$this->logService = LogService::getInstance();

		$this->registerHooks();

		if ($this->is_save_card_enabled()) {
			$this->supports[] = 'tokenization';
			$this->supports[] = 'add_payment_method';
		}
	}

	public function add_supported_gateways( $gateways ) {
		return array_merge(
			$gateways,
			[ self::GATEWAY_ID => FunnelKitUpsell::class ]
		);
	}

	public function enable_subscription_upsell_support( $gateways ) {
		if ( is_array( $gateways ) ) {
			$gateways[] = self::GATEWAY_ID;
		}

		return $gateways;
	}

	public function subscription_payment_information( $paymentMethodName, $subscription ) {
		$customerId = $subscription->get_customer_id();
		if ( $subscription->get_payment_method() !== $this->id || ! $customerId ) {
			return $paymentMethodName;
		}
		//add additional payment details
		return $paymentMethodName;
	}

	public function has_fields()
	{
		if ( is_account_page() || !empty($_REQUEST['change_payment_method'] ) ) {
			return true;
		}
		return parent::has_fields();
	}


	public function getCardRedirectData() {
		check_ajax_referer('wc-airwallex-get-card-redirect-data', 'security');

		$autoCapture = $this->is_capture_immediately();
		$client = CardClient::getInstance();
		$order = $this->getOrderFromRequest('Main::getApmRedirectData');
		$orderId = $order->get_id();
		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		$paymentIntent             = $client->getPaymentIntent( $paymentIntentId );
		$paymentIntentClientSecret = $paymentIntent->getClientSecret();
		$airwallexCustomerId = $paymentIntent->getCustomerId();
		$isSubscription = OrderService::getInstance()->containsSubscription( $order->get_id() );

		$airwallexElementConfiguration = [
			'intent' => [
				'id' => $paymentIntentId,
				'client_secret' => $paymentIntentClientSecret
			],
			'style' => [
				'popupWidth' => 400,
				'popupHeight' => 549,
			],
			'autoCapture' => $autoCapture,
        ] + ( $isSubscription ? [
			'mode'             => 'recurring',
			'recurringOptions' => [
				'next_triggered_by'       => 'merchant',
				'merchant_trigger_reason' => 'scheduled',
				'currency'                => $order->get_currency(),
			],
		] : [] ) + ( $airwallexCustomerId ? [ 'customer_id' => $airwallexCustomerId ] : [] );
		$airwallexRedirectElScriptData = [
			'elementType' => 'fullFeaturedCard',
			'elementOptions' => $airwallexElementConfiguration,
			'containerId' => 'airwallex-full-featured-card',
			'orderId' => $orderId,
			'paymentIntentId' => $paymentIntentId,
		];

		wp_send_json([
			'success' => true,
			'data' => $airwallexRedirectElScriptData,
		]);
	}

	public function getPaymentConsentIdsInDB($wpUserId) {
		$consentIdsInDB = [];
		$page = 1;
		$maxPages = 100;
		while ($page <= $maxPages) {
			$paymentConsentsInDB = WC_Payment_Tokens::get_tokens([
				'user_id'    => $wpUserId,
				'gateway_id' => Card::GATEWAY_ID,
				'limit'      => 1000,
				'page'       => $page,
			]);

			if (count($paymentConsentsInDB) === 0) {
				break;
			}
			$page++;

			foreach($paymentConsentsInDB as $paymentConsentInDB) {
				$consentIdsInDB[$paymentConsentInDB->get_token()] = true;
			}
		}
		return $consentIdsInDB;
	}

	public function syncSaveCards($airwallexCustomerId, $wpUserId) {
		$verifiedPaymentConsentsInCloud = (new AllPaymentConsents())->setNextTriggeredBy('')->setCustomerId($airwallexCustomerId)->get();
		$consentIdsInDB = $this->getPaymentConsentIdsInDB($wpUserId);

		foreach($verifiedPaymentConsentsInCloud as $verifiedPaymentConsentInCloud) {
			$paymentMethodTypeInCloud = $verifiedPaymentConsentInCloud->getPaymentMethod()['type'] ?? '';
			if ($paymentMethodTypeInCloud !== 'card') {
				continue;
			}
			if (empty($consentIdsInDB[$verifiedPaymentConsentInCloud->getId()])) {
				$token = new WC_Payment_Token_CC();
				$token->set_gateway_id(Card::GATEWAY_ID);
				$token->set_token($verifiedPaymentConsentInCloud->getId());
				$token->set_user_id($wpUserId);
				$token->set_card_type($this->formatCardType($verifiedPaymentConsentInCloud->getCardBrand()));
				$token->set_last4($verifiedPaymentConsentInCloud->getCardLast4());
				$token->set_expiry_month($verifiedPaymentConsentInCloud->getCardExpiryMonth());
				$token->set_expiry_year($verifiedPaymentConsentInCloud->getCardExpiryYear());
				$token->save();
				self::saveAwxPaymentConsentDetail($verifiedPaymentConsentInCloud, $token->get_id());
			}
		}
	}

	public static function saveAwxPaymentConsentDetail($paymentConsent, $tokenId) {
		$awxPaymentConsentDetail = [
			'fingerprint' => $paymentConsent->getPaymentMethod()['card']['fingerprint'] ?? '',
			'payment_method_id' => $paymentConsent->getPaymentMethod()['id'] ?? '',
			'payment_consent_id' => $paymentConsent->getId(),
			'number_type' => $paymentConsent->getPaymentMethod()['card']['number_type'] ?? '',
			OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID => $paymentConsent->getCustomerId(),
			'next_triggered_by' => $paymentConsent->getNextTriggeredBy(),
		];
		update_metadata('payment_token', $tokenId, self::TOKEN_META_KEY_CONSENT_DETAIL, $awxPaymentConsentDetail);
	}

	public function add_payment_method() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new Exception( __( 'User must be logged in.', 'airwallex-online-payments-gateway' ) );
			}

			$airwallexCustomerId = (new OrderService())->getAirwallexCustomerId( get_current_user_id(), CardClient::getInstance() );
			$this->syncSaveCards($airwallexCustomerId, get_current_user_id());

			return array(
				'result'   => 'success',
				'redirect' => wc_get_account_endpoint_url( 'payment-methods' ),
			);
		} catch ( Exception $e ) {
			wc_add_notice( sprintf(
				/* translators: Placeholder 1: Exception message. */
				__( 'Error saving payment method. Reason: %s', 'airwallex-online-payments-gateway' ),
				$e->getMessage() ), 'error' );

			return ['result' => 'error'];
		}
	}

	public function registerHooks() {
		static $isCardHooksRegistered = false;
		if ($isCardHooksRegistered) return;
		$isCardHooksRegistered = true;
		add_filter( 'woocommerce_get_customer_payment_tokens', [ Card::class, 'filterTokens' ], 8, 3 );
		add_filter( 'wc_airwallex_settings_nav_tabs', array( $this, 'adminNavTab' ), 11 );
		add_action( 'woocommerce_airwallex_settings_checkout_' . $this->id, array( $this, 'enqueueAdminScripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueScriptsForEmbeddedCard' ) );
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'deletePaymentMethod' ), 10, 2 );
		add_action( 'wp', array( $this, 'deletePaymentMethodAction' ), 1 );

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'do_subscription_payment' ), 10, 2 );
			add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'subscription_payment_information' ), 10, 2 );
			add_filter( 'woocommerce_subscription_payment_meta', [ $this, 'add_subscription_payment_meta' ], 10, 2 );
			add_action( 'woocommerce_subscription_validate_payment_meta', [ $this, 'validate_subscription_payment_meta' ], 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
			add_filter( 'wfocu_wc_get_supported_gateways', [ $this, 'add_supported_gateways' ], 10, 1 );
			add_filter( 'wfocu_subscriptions_get_supported_gateways', [ $this, 'enable_subscription_upsell_support' ] );
		}
	}

	public static function filterTokens($tokens, $user_id, $gateway_id) {
		if ( empty( $tokens ) ) {
			return [];
		}
		$airwallexCustomerIdFromDB = (new OrderService)->getAirwallexCustomerId(get_current_user_id(), CardClient::getInstance());
		foreach ( $tokens as $index => $token ) {
			if ( $token->get_gateway_id() !== Card::GATEWAY_ID ) {
				continue;
			}
			$awxPaymentConsentDetail = get_metadata( 'payment_token', $token->get_id(), self::TOKEN_META_KEY_CONSENT_DETAIL, true );
			if (!empty($awxPaymentConsentDetail) && !empty($awxPaymentConsentDetail[OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID])) {
				if ($airwallexCustomerIdFromDB !== $awxPaymentConsentDetail[OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID]) {
					$token->delete();
					unset( $tokens[ $index ] );
				}
			} else {
				try {
					$paymentConsent = CardClient::getInstance()->getPaymentConsent( $token->get_token() );
					$airwallexCustomerId = $paymentConsent->getCustomerId();

					if ($airwallexCustomerIdFromDB !== $airwallexCustomerId) {
						$token->delete();
						unset( $tokens[ $index ] );
					} else {
						self::saveAwxPaymentConsentDetail($paymentConsent, $token->get_id());
					}
				} catch ( Exception $e ) {
					LogService::getInstance()->error( 'filter tokens error: ' . $e->getMessage() );
				} catch ( Error $e) {
					LogService::getInstance()->error( 'filter tokens error: ' . $e->getMessage() );
				}
			}
		}
		return $tokens;
	}

	public function deletePaymentMethodAction() {
		global $wp;

		if ( isset( $wp->query_vars['delete-payment-method'] ) ) {
			wc_nocache_headers();

			$token_id = absint( $wp->query_vars['delete-payment-method'] );
			$token    = WC_Payment_Tokens::get( $token_id );

			if ( is_null( $token ) || get_current_user_id() !== $token->get_user_id() || ! isset( $_REQUEST['_wpnonce'] ) || false === wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), 'delete-payment-method-' . $token_id ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				wc_add_notice( __( 'Invalid payment method.', 'airwallex-online-payments-gateway' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
				exit();
			} else {
				if ($token->get_gateway_id() === Card::GATEWAY_ID && class_exists('WCS_Payment_Tokens')) {
					$subscriptions = WCS_Payment_Tokens::get_subscriptions_from_token( $token );

					if ( !empty( $subscriptions ) ) {
						wc_add_notice( __( 'That payment method cannot be deleted because it is linked to an automatic subscription.', 'airwallex-online-payments-gateway' ), 'error' );
						wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
						exit();
					}
				}
			}
		}
	}

	public function deletePaymentMethod($tokenId, $token) {
		if ($token->get_gateway_id() !== Card::GATEWAY_ID) {
			return;
		}

		(new DisablePaymentConsent())->setPaymentConsentId($token->get_token())->send();
	}

	public function getCustomerClientSecret() {
		$id = get_current_user_id();
		if (empty($id)) {
			wp_send_json([
				'success' => false,
				'error' => [
					'message' => __('User not logged in.', 'airwallex-online-payments-gateway'),
				],
			]);
			return;
		}
		$orderService = new OrderService();
		$apiClient = CardClient::getInstance();
		$customerId = $orderService->getAirwallexCustomerId($id, $apiClient);
		$secretObj = (new GenerateClientSecret())
			->setCustomerId($customerId)
			->send();

		wp_send_json([
			'success' => true,
			'customer_id' => $customerId,
			'client_secret' => $secretObj->getClientSecret(),
		]);
	}

	public function enqueueScriptsForEmbeddedCard() {
		wp_enqueue_script( 'airwallex-card-js' );
		wp_enqueue_style( 'airwallex-css' );

		$currency = get_woocommerce_currency();
		if ( isset( $_GET['pay_for_order'] ) && 'true' === $_GET['pay_for_order'] ) {
			global $wp;
			$order_id = (int) $wp->query_vars['order-pay'];
			if ($order = wc_get_order( $order_id ) ) {
				$currency = $order->get_currency();
			}
		}

		$cardScriptData = [
 			'autoCapture' => $this->is_capture_immediately() ? 'true' : 'false',
			/* translators: 1) Detail error message. */
			'getCustomerClientSecretAjaxUrl' => WC_AJAX::get_endpoint('airwallex_get_customer_client_secret'),
			'getCheckoutAjaxUrl' => WC_AJAX::get_endpoint( 'checkout' ),
			'getTokensAjaxUrl' => WC_AJAX::get_endpoint('airwallex_get_tokens'),
			'isLoggedIn' => is_user_logged_in(),
			'isAccountPage' => is_account_page(),
			'cardLogos' => $this->getCardLogos(),
			'errorMessage' => __( 'An error has occurred. Please check your payment details (%s)', 'airwallex-online-payments-gateway' ),
			'incompleteMessage' => __( 'Your credit card details are incomplete', 'airwallex-online-payments-gateway' ),
			'resourceAlreadyExistsMessage' => __( 'This payment method has already been saved. Please use a different payment method.', 'airwallex-online-payments-gateway' ),
			'CVC' => __( 'CVC', 'airwallex-online-payments-gateway' ),
			'CVCIsNotCompletedMessage' => __( 'CVC is not completed.', 'airwallex-online-payments-gateway' ),
			'isSkipCVCEnabled' => $this->is_skip_cvc_enabled(),
			'isSaveCardEnabled' => $this->is_save_card_enabled(),
			'isContainSubscription' => $this->isContainSubscription(),
			'currency' => $currency,
		];
		wp_add_inline_script( 'airwallex-card-js', 'var awxEmbeddedCardData=' . wp_json_encode($cardScriptData), 'before' );
	}

	public function enqueueScriptForRedirectCard() {
		wp_enqueue_script( 'airwallex-redirect-js' );
	}

	public function enqueueAdminScripts() {
	}

	public function getCardLogos() {
		$cacheService = new CacheService( Util::getApiKey() );
		$logos        = $cacheService->get( 'cardLogos' );
		if ( empty( $logos ) ) {
			$paymentMethodTypes = $this->getPaymentMethodTypes();
			if ( $paymentMethodTypes ) {
				$logos = array();
				foreach ( $paymentMethodTypes as $paymentMethodType ) {
					if ( 'card' === $paymentMethodType['name'] && empty( $logos ) ) {
						foreach ( $paymentMethodType['card_schemes'] as $cardType ) {
							if ( isset( $cardType['resources']['logos']['svg'] ) ) {
								$logos[ 'card_' . $cardType['name'] ] = $cardType['resources']['logos']['svg'];
							}
						}
					}
				}
				$logos = $this->sort_icons( $logos );
				$cacheService->set( 'cardLogos', $logos, 86400 );
			}
		}
		$logos['card_amex'] = AIRWALLEX_PLUGIN_URL . '/assets/images/amex_small.svg';
		return empty( $logos ) ? [] : array_reverse( $logos );
	}

	public function isContainSubscription() {
		if (class_exists('WC_Subscriptions_Cart')) {
			return WC_Subscriptions_Cart::cart_contains_subscription();
		}
		return false;
	}

	public function get_icon() {
		$return = '';
		$logos  = $this->getCardLogos();
		if ( $logos ) {
			foreach ( $logos as $logo ) {
				$return .= '<img src="' . WC_HTTPS::force_https_url( $logo ) . '" class="airwallex-card-icon" alt="' . esc_attr( $this->get_title() ) . '" />';
			}
			apply_filters( 'woocommerce_gateway_icon', $return, $this->id ); // phpcs:ignore
			return $return;
		} else {
			return parent::get_icon();
		}
	}

	public function payment_fields() {
		if ( $this->get_option( 'checkout_form_type' ) !== 'inline' && ! is_account_page() && empty($_REQUEST['change_payment_method'])) {
			parent::payment_fields();
			return;
		}
		$siteName = esc_html( get_bloginfo( 'name' ) );
		$cardInformationMessage = esc_html__( 'Card information', 'airwallex-online-payments-gateway' );
		$savePaymentMessage = esc_html__( 'Save payment information to my account for future purchases', 'airwallex-online-payments-gateway' );
		$useNewCardMessage = esc_html__( 'Use new credit card', 'airwallex-online-payments-gateway' );

		$allowChargeForFutureMessage = sprintf(
			/* translators: Placeholder 1: Opening div tag. Placeholder 2: Close div tag. */
			__(
				'%1$sBy providing your card information, you allow %2$s to charge your card for future payments in accordance with their terms.%3$s',
				'airwallex-online-payments-gateway'
			),
			'<div class="airwallex-save-tip">',
			$siteName,
			'</div>'
		);

		$isLoggedIn = is_user_logged_in();
		$isChangePaymentMethod = is_checkout_pay_page() && isset( $_REQUEST['change_payment_method'] );
		$isContainSubscription = $this->isContainSubscription() || $isChangePaymentMethod;
		$isSaveCardEnabled = $this->is_save_card_enabled();
		$saveCardsHtml = $isLoggedIn && $isSaveCardEnabled && ! is_account_page() ? '<div class="save-cards"></div>' : '';
		$newCardRadioHtml = $isLoggedIn && $isSaveCardEnabled ? sprintf(
			/* translators: Placeholder 1: Use new card message. */
			'<div class="new-card line" style="display: none;">
				<input type="radio" name="new-card" id="airwallex-new-card">
				<label for="airwallex-new-card">%s</label>
			</div>',
			$useNewCardMessage
		) : '';

		$isAllowToSave = $isLoggedIn && $isSaveCardEnabled && ! $isContainSubscription;

		$saveCheckboxHtml = ($isAllowToSave && ! is_account_page()) ? sprintf(
			/* translators: Placeholder 1: Save payment message. */
			'<div class="line save">
				<input type="checkbox" id="airwallex-save">
				<label for="airwallex-save">%s</label>
			</div>',
			$savePaymentMessage
		) : '';

		$spinnerHtml = $isLoggedIn && $isSaveCardEnabled && ! is_account_page() ? '<div class="wc-awx-checkbox-spinner" style="display: block;"></div>' : '';
		$showAirwallexContainer = $isLoggedIn && $isSaveCardEnabled && ! is_account_page() ? 'none' : 'block';
		echo wp_kses_post( '<p>' . $this->description . '</p>' );
		$managePaymentMethod = ( is_account_page() || ! empty($_REQUEST['change_payment_method']) ) ? 'manage-payment-method' : '';

		echo sprintf(
			/* translators: Placeholder 1: Change payment method class name. Placeholder 2: Airwallex container style. Placeholder 3: Save card html. Placeholder 4: New card radio html. Placeholder 5: Card information message. Placeholder 6: Save card checkbox html. */
			'<div class="airwallex-container %1$s" style="display: %2$s;">
				%3$s
				%4$s
				<div class="new-card-title">%5$s</div>
				<div id="airwallex-card"></div>
				%6$s
				<div class="awx-alert" style="display: none;"><div class="body"></div></div>
			</div>',
			$managePaymentMethod,
			$showAirwallexContainer,
			$saveCardsHtml,
			$newCardRadioHtml,
			$cardInformationMessage,
			$saveCheckboxHtml
		);

		if ( is_account_page() || ! empty($_REQUEST['change_payment_method']) ) {
			echo $allowChargeForFutureMessage;
		}
		echo $spinnerHtml;
	}

	public function get_form_fields() {
		$isEmbeddedFieldsAllowed = ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.2.0', '>=' ) );
		return apply_filters( // phpcs:ignore
			'wc_airwallex_settings', // phpcs:ignore
			array(
				'enabled'                      => array(
					'title'       => __( 'Enable/Disable', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Enable Airwallex Card Payments', 'airwallex-online-payments-gateway' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'                        => array(
					'title'       => __( 'Title', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => __( 'Card', 'airwallex-online-payments-gateway' ),
				),
				'description'                  => array(
					'title'       => __( 'Description', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the description which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => '',
				),
				'checkout_form_type'           => array(
					'title'       => __( 'Checkout form', 'airwallex-online-payments-gateway' ),
					'type'        => 'select',
					'description' => ( ! $isEmbeddedFieldsAllowed ? ' ' . __( 'Please upgrade WooCommerce to 5.2.0+ to use embedded credit card input fields', 'airwallex-online-payments-gateway' ) : '' ),
					'default'     => $isEmbeddedFieldsAllowed ? 'inline' : 'redirect',
					'options'     =>
						( $isEmbeddedFieldsAllowed ? array( 'inline' => __( 'Embedded', 'airwallex-online-payments-gateway' ) ) : array() )
						+ array( 'redirect' => __( 'On separate page', 'airwallex-online-payments-gateway' ) ),
				),
				'capture_immediately'          => array(
					'title'       => __( 'Capture immediately', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Yes', 'airwallex-online-payments-gateway' ),
					'type'        => 'checkbox',
					'description' => __( 'Choose this option if you do not want to rely on status changes for capturing the payment', 'airwallex-online-payments-gateway' ),
					'default'     => 'yes',
				),
				'capture_trigger_order_status' => array(
					'title'       => __( 'Capture status', 'airwallex-online-payments-gateway' ),
					'label'       => '',
					'type'        => 'select',
					'description' => __( 'When this status is assigned to an order, the funds will be captured', 'airwallex-online-payments-gateway' ),
					'options'     => array_merge( array( '' => '' ), wc_get_order_statuses() ),
					'default'     => '',
				),
				'save_card_enabled'            => array(
					'title'       => __( 'Enable saved cards', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Yes', 'airwallex-online-payments-gateway' ),
					'type'        => 'checkbox',
					'description' => __('This will allow users to save their card information for future purchases.', 'airwallex-online-payments-gateway'),
					'default'     => 'no',
				),
				'skip_cvc_enabled'            => array(
					'title'       => __( 'Skip CVV', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Yes', 'airwallex-online-payments-gateway' ),
					'type'        => 'checkbox',
					'description' => __('This will allow users to use their saved cards without re-entering their CVV. Some cards may still require users to enter their CVV.', 'airwallex-online-payments-gateway'),
					'default'     => 'no',
				),
			)
		);
	}

	public function change_subscription_payment_method( $order ) {
		$currentUserId = get_current_user_id();
		$userId = $order->get_user_id();
		if ($currentUserId !== $userId) {
			$message = sprintf(
				/* translators: Placeholder 1: Order user ID. Placeholder 2: Current user ID. */
				'User ID mismatch when changing payment method: Order User ID (%d) vs Current User ID (%d)',
				$userId,
				$currentUserId
			);

			$this->logService->debug($message, array('orderId' => $order->get_id()));
			throw new Exception( __( 'You are not allowed to change payment method for this order.', 'airwallex-online-payments-gateway' ) );
		}
		if (empty($_REQUEST['awx_customer_id'])) {
			throw new Exception( __( 'Customer ID is required.', 'airwallex-online-payments-gateway' ) );
		}
		if (empty($_REQUEST['awx_consent_id'])) {
			throw new Exception( __( 'Consent ID is required.', 'airwallex-online-payments-gateway' ) );
		}
		$order->update_meta_data( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, sanitize_text_field($_REQUEST['awx_consent_id']) );
		$order->update_meta_data( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, sanitize_text_field($_REQUEST['awx_customer_id']) );
		$order->save();
		return array( 'result' => 'success', 'redirect' => $order->get_view_order_url());
	}

	public function process_payment( $order_id ) {
		try {
			$order   = wc_get_order( $order_id );
			if ( empty( $order ) ) {
				$this->logService->debug( __METHOD__ . ' - can not find order', array( 'orderId' => $order_id ) );
				throw new Exception( 'Airwallex payment error: can not find order', 'airwallex-online-payments-gateway' );
			}

			if (isset($_REQUEST['is_change_payment_method']) && $_REQUEST['is_change_payment_method'] === 'true' && function_exists('wcs_is_subscription') && wcs_is_subscription($order)) {
				return $this->change_subscription_payment_method( $order );
			}

			$apiClient = CardClient::getInstance();
			$orderService        = new OrderService();
			$airwallexCustomerId = null;
			$containsSubscription = $orderService->containsSubscription( $order->get_id() );

			$tokenIdFromRequest = $_POST['token'] ?? 0;
			$paymentMethodId = '';
			if ($tokenIdFromRequest) {
				$token = WC_Payment_Tokens::get( $tokenIdFromRequest );
				if ($token->get_user_id() !== get_current_user_id()) {
					throw new Exception( __( "This token doesn't belong to the current user.", 'airwallex-online-payments-gateway' ) );
				}
				$paymentConsentDetail = get_metadata('payment_token', $token->get_id(), self::TOKEN_META_KEY_CONSENT_DETAIL, true);
				if (empty($paymentConsentDetail)) {
					$paymentConsent = $apiClient->getPaymentConsent( $token->get_token() );
					self::saveAwxPaymentConsentDetail($paymentConsent, $token->get_id());
					$paymentMethodId = $paymentConsent->getPaymentMethod()['id'];
					$airwallexCustomerId = $paymentConsent->getCustomerId();
				} else {
					$paymentMethodId = $paymentConsentDetail['payment_method_id'] ?? '';
					$airwallexCustomerId = $paymentConsentDetail[OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID] ?? '';
				}
			} else if ( $containsSubscription || is_user_logged_in() ) {
				$airwallexCustomerId = $orderService->getAirwallexCustomerId( get_current_user_id(), $apiClient );
			}
			$this->logService->debug( __METHOD__ . ' - before create intent', array( 'orderId' => $order_id ) );
			$paymentIntent = $apiClient->createPaymentIntent( $order->get_total(), $order->get_id(), $this->is_submit_order_details(), $airwallexCustomerId, 'woo_commerce_credit_card' );
			if ( in_array($paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true) ) {
				return [
					'result' => 'success',
					'redirect' => $order->get_checkout_order_received_url(),
				];
			}
			$this->logService->debug(
				__METHOD__ . ' - payment intent created ',
				array(
					'paymentIntent' => $paymentIntent->getId(),
					'session'  => array(
						'cookie' => WC()->session->get_session_cookie(),
						'data'   => WC()->session->get_session_data(),
					),
				),
				LogService::CARD_ELEMENT_TYPE
			);

			WC()->session->set( 'airwallex_order', $order_id );
			WC()->session->set( 'airwallex_payment_intent_id', $paymentIntent->getId() );
			$order->update_meta_data( OrderService::META_KEY_INTENT_ID, $paymentIntent->getId() );
			$order->save();


			$result = ['result' => 'success'];
			if ( 'redirect' === $this->get_option( 'checkout_form_type' ) && ! $tokenIdFromRequest ) {
				$redirectUrl = $this->get_payment_url( 'airwallex_payment_method_card' );
				$redirectUrl .= ( strpos( $redirectUrl, '?' ) === false ) ? '?' : '&';
				$redirectUrl .= 'order_id=' . $order_id;
				$result['redirect'] = $redirectUrl;
			} else {
				$result += [
					'paymentIntent' => $paymentIntent->getId(),
					'orderId'       => $order_id,
					'createConsent' => ! empty( $airwallexCustomerId ) && $containsSubscription,
					'customerId'    => ! empty( $airwallexCustomerId ) ? $airwallexCustomerId : '',
					'currency'      => $order->get_currency( '' ),
					'clientSecret'  => $paymentIntent->getClientSecret(),
				];
				if ($tokenIdFromRequest) {
					$result['paymentMethodId'] = $paymentMethodId;
					$result['customerId'] = $airwallexCustomerId;
					$result['tokenId'] = $tokenIdFromRequest;
				}
			}

			return $result;
		} catch ( Exception $e ) {
			$this->logService->error( __METHOD__ . ' - card payment create intent failed.', $e->getMessage() . ': ' . json_encode($e->getTrace()), LogService::CARD_ELEMENT_TYPE );
			$errorJson = json_decode($e->getMessage(), true);
			if (json_last_error() === JSON_ERROR_NONE && !empty($errorJson['data']['message'])) {
				throw new Exception(esc_html__($errorJson['data']['message'], 'airwallex-online-payments-gateway'));
			}			
			throw new Exception( esc_html__( 'Airwallex payment error', 'airwallex-online-payments-gateway' ) );
		}
	}

	/**
	 * Capture payment
	 *
	 * @param WC_Order $order
	 * @param float $amount
	 * @throws Exception
	 */
	public function capture( WC_Order $order, $amount = null ) {
		$apiClient       = CardClient::getInstance();
		$paymentIntentId = $order->get_transaction_id();
		if ( empty( $paymentIntentId ) ) {
			throw new Exception( 'No Airwallex payment intent found for this order: ' . esc_html( $order->get_id() ) );
		}
		try {
			$paymentIntent = $apiClient->getPaymentIntent( $paymentIntentId );
			if ( empty( $amount ) ) {
				$amount = $paymentIntent->getAmount();
			}
			$paymentIntentAfterCapture = $apiClient->capture( $paymentIntentId, $amount );
			if ( $paymentIntentAfterCapture->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
				$this->logService->debug( 'capture successful', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment capture success' );
			} else {
				$this->logService->error( 'capture failed', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment failed capture' );
			}
		} catch ( Exception $e ) {
			$this->logService->error( 'capture failed', $e->getMessage() );
			$order->add_order_note( 'Airwallex payment failed capture: ' . $e->getMessage() );
			throw new Exception( 'Airwallex capture error: ' . $e->getMessage() );
		}
	}

	public function is_captured( $order ) {
		$apiClient       = CardClient::getInstance();
		$paymentIntentId = $order->get_transaction_id();
		$paymentIntent   = $apiClient->getPaymentIntent( $paymentIntentId );
		if ( $paymentIntent->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get is capture immediately option
	 *
	 * @return bool
	 */
	public function is_capture_immediately() {
		return in_array( $this->get_option( 'capture_immediately' ), array( true, 'yes' ), true );
	}

	/**
	 * Get is capture immediately option
	 *
	 * @return bool
	 */
	public function is_save_card_enabled() {
		return in_array( $this->get_option( 'save_card_enabled' ), array( true, 'yes' ), true );
	}

	/**
	 * Get is capture immediately option
	 *
	 * @return bool
	 */
	public function is_skip_cvc_enabled() {
		return in_array( $this->get_option( 'skip_cvc_enabled' ), array( true, 'yes' ), true );
	}

	public function output( $attrs ) {
		if ( is_admin() || empty( WC()->session ) ) {
			$this->logService->debug( 'Update card payment shortcode.', array(), LogService::CARD_ELEMENT_TYPE );
			return;
		}

		$shortcodeAtts = shortcode_atts(
			array(
				'style' => '',
				'class' => '',
			),
			$attrs,
			'airwallex_payment_method_card'
		);

		try {
			$order = $this->getOrderFromRequest('Card::output');
			$orderId = $order->get_id();
			$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
			$apiClient                 = CardClient::getInstance();
			$paymentIntent             = $apiClient->getPaymentIntent( $paymentIntentId );
			$paymentIntentClientSecret = $paymentIntent->getClientSecret();
			$airwallexCustomerId       = $paymentIntent->getCustomerId();
			$confirmationUrl           = $this->get_payment_confirmation_url($orderId, $paymentIntentId);
			$isSandbox                 = $this->is_sandbox();
			$autoCapture = $this->is_capture_immediately();
			$orderService = new OrderService();
			$isSubscription = $orderService->containsSubscription( $orderId );

			$this->logService->debug(
				__METHOD__ . ' - Redirect to the card payment page',
				array(
					'orderId'       => $orderId,
					'paymentIntent' => $paymentIntentId,
				),
				LogService::CARD_ELEMENT_TYPE
			);

			$this->enqueueScriptForRedirectCard();

			ob_start();
			include AIRWALLEX_PLUGIN_PATH . '/html/card-payment-shortcode.php';
			return ob_get_clean();
		} catch ( Exception $e ) {
			$this->logService->error( __METHOD__ . ' - Card payment action failed', $e->getMessage(), LogService::CARD_ELEMENT_TYPE );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	public static function getMetaData() {
		$settings = self::getSettings();

		$data = [
			'enabled' => isset($settings['enabled']) ? $settings['enabled'] : 'no',
			'checkout_form_type' => isset($settings['checkout_form_type']) ? $settings['checkout_form_type'] : '',
		];

		return $data;
	}

	public static function getDescriptorSetting() {
		$settings = self::getSettings();

		/* translators: Placeholder 1: Order number. */
		return $settings['payment_descriptor'] ?? __( 'Your order %order%', 'airwallex-online-payments-gateway' );
	}

	public function getTokens() {
		$id = get_current_user_id();
		if (empty($id)) {
			wp_send_json([
				'success' => false,
				'error' => [
					'message' => __('User not logged in.', 'airwallex-online-payments-gateway'),
				],
			]);
			return;
		}

		wp_send_json([
			'success' => true,
			'tokens' => $this->savedTokens(),
			'customer_id' => (new OrderService)->getAirwallexCustomerId($id, CardClient::getInstance()),
		]);
	}

	public function savedTokens()
	{
		$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id());

		$result = [];
		/** @var WC_Payment_Token_CC $token */
		foreach ($tokens as $token) {
			if ($token->get_gateway_id() === Card::GATEWAY_ID) {
				$paymentConsentDetail = get_metadata('payment_token', $token->get_id(), self::TOKEN_META_KEY_CONSENT_DETAIL, true);
				$numberType = get_metadata('payment_token', $token->get_id(), 'number_type', true);
				if (empty($numberType)) {
					$numberType = $paymentConsentDetail['number_type'] ?? '';
				}
				$result[$token->get_id()] = [
					'id' => $token->get_id(),
					'last4' => $token->get_last4(),
					'type' => $token->get_card_type(),
					'formatted_type' => $this->formatCardType($token->get_card_type()),
					'expiry_month' => $token->get_expiry_month(),
					'expiry_year' => $token->get_expiry_year(),
					'number_type' => $numberType,
					'is_hide_cvc_element' => $this->is_skip_cvc_enabled() && in_array($numberType, ['EXTERNAL_NETWORK_TOKEN', 'AIRWALLEX_NETWORK_TOKEN'], true),
				];
			}
		}
		return $result;
	}

	public function formatCardType( $brand ) {
		$brands = array(
			'visa'       => 'Visa',
			'mastercard' => 'MasterCard',
			'diners'     => 'Diners Club',
			'discover'   => 'Discover',
			'jcb'        => 'JCB',
			'maestro'    => 'Maestro',
			'elo'        => 'Elo',
			'hypercard'  => 'HyperCard',
			'aura'       => 'Aura',
			'bcmc'       => 'BCMC',
			'union pay'   => 'Unionpay',
			'american express' => 'American Express',
		);

		return isset( $brands[ $brand ] ) ? $brands[ $brand ] : ucfirst( $brand );
	}
}
