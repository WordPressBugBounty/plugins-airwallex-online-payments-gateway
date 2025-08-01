<?php

namespace Airwallex\Gateways;

use Airwallex\Client\GatewayClient;
use Airwallex\Main;
use Airwallex\Client\CardClient;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\Struct\Refund;
use Exception;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Services\Util;
use WC_Subscriptions_Manager;

trait AirwallexGatewayTrait {

	public static function getInstance() {
		if ( empty( static::$instance ) ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public $iconOrder = array(
		'card_visa'       => 1,
		'card_mastercard' => 2,
		'card_amex'       => 3,
		'card_jcb'        => 4,
	);

	public function get_settings_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function get_onboarding_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function sort_icons( $iconArray ) {
		uksort(
			$iconArray,
			function ( $a, $b ) {
				$orderA = isset( $this->iconOrder[ $a ] ) ? $this->iconOrder[ $a ] : 999;
				$orderB = isset( $this->iconOrder[ $b ] ) ? $this->iconOrder[ $b ] : 999;
				return $orderA - $orderB;
			}
		);
		return $iconArray;
	}

	public function is_submit_order_details() {
		return in_array( get_option( 'airwallex_submit_order_details' ), array( 'yes', 1, true, '1' ), true );
	}

	public function temporary_order_status_after_decline() {
		$temporaryOrderStatus = get_option( 'airwallex_temporary_order_status_after_decline' );
		return $temporaryOrderStatus ? $temporaryOrderStatus : 'pending';
	}

	public function is_sandbox() {
		return in_array( get_option( 'airwallex_enable_sandbox' ), array( true, 'yes' ), true );
	}

	public function isJsLoggingEnabled() {
		return in_array( get_option( 'do_js_logging' ), array( 'yes', 1, true, '1' ), true );
	}

	public function isRemoteLoggingEnabled() {
		return in_array( get_option( 'do_remote_logging' ), array( 'yes', 1, true, '1' ), true );
	}

	public function getPaymentFormTemplate() {
		return get_option( 'airwallex_payment_page_template' );
	}

	public function get_payment_url( $type ) {
		$template = get_option( 'airwallex_payment_page_template' );
		if ( 'wordpress_page' === $template ) {
			return $this->getPaymentPageUrl( $type );
		}

		return WC()->api_request_url( static::ROUTE_SLUG );
	}

	public function needs_setup() {
		return true;
	}

	public function get_payment_confirmation_url($orderId = '', $intentId = '') {
		$url = WC()->api_request_url( Main::ROUTE_SLUG_CONFIRMATION );
		if ( empty( $orderId ) ) {
			return $url;
		}
		$url .= strpos($url, '?') !== false ? '&' : '?';
		return $url . "order_id=$orderId&intent_id=$intentId";
	}

	public function init_settings() {
		parent::init_settings();
		$this->enabled = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
	}

	public function getPaymentPageUrl( $type, $fallback = '' ) {
		$pageId    = get_option( $type . '_page_id' );
		$permalink = ! empty( $pageId ) ? get_permalink( $pageId ) : '';

		if ( empty( $permalink ) ) {
			$permalink = empty( $fallback ) ? get_home_url() : $fallback;
		}

		return $permalink;
	}

	public static function getSettings() {
		return get_option(AIRWALLEX_PLUGIN_NAME . self::GATEWAY_ID . '_settings', []);
	}

	public function getPaymentMethodTypes() {
		$cacheService = new CacheService( Util::getApiKey() );
		$paymentMethodTypes = $cacheService->get( 'rawPaymentMethods' );

		if ( is_null( $paymentMethodTypes ) ) {
			$paymentMethodTypes = [];
			try {
				$apiClient = CardClient::getInstance();
				$paymentMethodTypes = $apiClient->getPaymentMethodTypes();
			} catch ( Exception $e ) {
				LogService::getInstance()->error(__METHOD__ . ' Failed to get payment method types.');
			}
			$cacheService->set( 'rawPaymentMethods', $paymentMethodTypes, 5 * MINUTE_IN_SECONDS );
		}

		return $paymentMethodTypes;
	}

	public function do_subscription_payment( $amount, $order ) {
		try {
			$subscriptionId            = $order->get_meta( '_subscription_renewal' );
			$subscription              = wcs_get_subscription( $subscriptionId );
			$originalOrderId           = $subscription->get_parent();
			$originalOrder             = wc_get_order( $originalOrderId );
			$airwallexCustomerId       = $subscription->get_meta( 'airwallex_customer_id' ) ?: $originalOrder->get_meta( 'airwallex_customer_id' );
			$airwallexPaymentConsentId = $subscription->get_meta( 'airwallex_consent_id' ) ?: $originalOrder->get_meta( 'airwallex_consent_id' );
			$cardClient                = CardClient::getInstance();
			$paymentIntent             = $cardClient->createPaymentIntent( $amount, $order->get_id(), false, $airwallexCustomerId );
			if (in_array($paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true )) {
				return;
			}
			$paymentIntentAfterCapture = $cardClient->confirmPaymentIntent( $paymentIntent->getId(), [ 'payment_consent_reference' => [ 'id' => $airwallexPaymentConsentId ] ] );

			if ( $paymentIntentAfterCapture->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
				LogService::getInstance()->debug( 'capture successful', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment capture success' );
				$order->payment_complete( $paymentIntent->getId() );
			} else {
				LogService::getInstance()->error( 'capture failed', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment failed capture' );
			}
		} catch ( Exception $e ) {
			$subscriptionId            = $order->get_meta( '_subscription_renewal' );
			$subscription              = wcs_get_subscription( $subscriptionId );
			$originalOrderId           = $subscription->get_parent();
			$originalOrder             = wc_get_order( $originalOrderId );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($originalOrder);
			LogService::getInstance()->error( 'do_subscription_payment failed', $e->getMessage() );
			RemoteLog::error( $e->getMessage(), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order           = wc_get_order( $order_id );
		$paymentIntentId = $order->get_transaction_id();
		if (empty($paymentIntentId)) {
			$paymentIntentId = $order->get_meta('_tmp_airwallex_payment_intent');
		}
		$client = GatewayClient::getInstance();
		try {
			$refund  = $client->createRefund( $paymentIntentId, $amount, $reason );
			$metaKey = $refund->getMetaKey();
			if ( ! $order->meta_exists( $metaKey ) ) {
				$order->add_order_note(
					sprintf(
						__( 'Airwallex refund initiated: %s', 'airwallex-online-payments-gateway' ),
						$refund->getId()
					)
				);
				$order->add_meta_data( $metaKey, array( 'status' => Refund::STATUS_CREATED ) );
				$order->save();
			} else {
				throw new Exception( "refund {$refund->getId()} already exist.", '1' );
			}
			LogService::getInstance()->debug( __METHOD__ . " - Order: {$order_id}, refund initiated, {$refund->getId()}" );
		} catch ( \Exception $e ) {
			LogService::getInstance()->debug( __METHOD__ . " - Order: {$order_id}, refund failed, {$e->getMessage()}" );
			return new \WP_Error( $e->getCode(), 'Refund failed, ' . $e->getMessage() );
		}

		return true;
	}

	public function getOrderFromRequest($referrer = '') {
		$orderId = 0;

		if (!empty($_GET['order_id'])) {
			$orderId = (int) $_GET['order_id'];
		}

		if (empty($orderId)) {
			$orderId = (int) WC()->session->get('airwallex_order', 0);
		}

		if (empty($orderId)) {
			$orderId = (int) WC()->session->get('order_awaiting_payment', 0);
		}

		if (empty($orderId)) {
			$errorMessage = 'Unable to retrieve a valid order ID.';
			RemoteLog::error( json_encode(['msg' => $errorMessage, '$_GET' => $_GET]), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}

		$order = wc_get_order( $orderId );
		if ( empty($order) ) {
			$errorMessage = 'Unable to retrieve a valid order.';
			RemoteLog::error( json_encode(['msg' => $errorMessage, '$_GET' => $_GET]), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}
		return $order;
	}

	public function add_subscription_payment_meta( $paymentMeta, $subscription ) {
		$subscription->read_meta_data( true );
		$paymentMeta[ $this->id ] = [
			'post_meta' => [
				'airwallex_customer_id' => [
					'value' => $subscription->get_meta( 'airwallex_customer_id', true ),
					'label' => 'Airwallex Customer ID',
				],
				'airwallex_consent_id'   => [
					'value' => $subscription->get_meta( 'airwallex_consent_id', true ),
					'label' => 'Airwallex Payment Consent ID',
				],
			],
		];

		return $paymentMeta;
	}

	public function validate_subscription_payment_meta( $paymentMethodId, $paymentMethodData ) {
		if ( $paymentMethodId === $this->id ) {
			if ( empty( $paymentMethodData['post_meta']['airwallex_customer_id']['value'] ) ) {
				throw new Exception( __('"Airwallex Customer ID" is required.', 'airwallex-online-payments-gateway') );
			}
			if ( empty( $paymentMethodData['post_meta']['airwallex_consent_id']['value'] ) ) {
				throw new Exception( __('"Airwallex Payment Consent ID" is required.', 'airwallex-online-payments-gateway') );
			}
			$paymentConsent  = (new CardClient())->getPaymentConsent(
				$paymentMethodData['post_meta']['airwallex_consent_id']['value']
			);
			if ( empty($paymentConsent->getStatus()) || $paymentConsent->getStatus() !== 'VERIFIED' ) {
				throw new Exception( __("Invalid Airwallex Payment Consent.", 'airwallex-online-payments-gateway') );
			}
			if ( $paymentConsent->getCustomerId() !== $paymentMethodData['post_meta']['airwallex_customer_id']['value'] ) {
				throw new Exception( __('The provided "Airwallex Customer ID" does not match the associated "Airwallex Payment Consent ID".', 'airwallex-online-payments-gateway') );
			}
		}
	}

	/**
	 * @param \WC_Subscription $subscription
	 * @param \WC_Order        $order
	 */
	public function update_failing_payment_method( $subscription, $order ) {
		$subscription->update_meta_data( 'airwallex_consent_id', $order->get_meta( 'airwallex_consent_id', true ) );
		$subscription->update_meta_data( 'airwallex_customer_id', $order->get_meta( 'airwallex_customer_id', true ) );
		$subscription->save();
	}
}
