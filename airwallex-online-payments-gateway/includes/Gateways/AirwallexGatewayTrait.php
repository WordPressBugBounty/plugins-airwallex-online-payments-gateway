<?php

namespace Airwallex\Gateways;

use Airwallex\Client\GatewayClient;
use Airwallex\Main;
use Airwallex\Client\CardClient;
use Airwallex\Struct\Refund;
use Exception;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Services\Util;

trait AirwallexGatewayTrait {

	public $iconOrder = array(
		'card_visa'       => 1,
		'card_mastercard' => 2,
		'card_amex'       => 3,
		'card_jcb'        => 4,
	);

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

	public function get_payment_confirmation_url() {
		return WC()->api_request_url( Main::ROUTE_SLUG_CONFIRMATION );
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
			$paymentIntentAfterCapture = $cardClient->confirmPaymentIntent( $paymentIntent->getId(), [ 'payment_consent_reference' => [ 'id' => $airwallexPaymentConsentId ] ] );

			if ( $paymentIntentAfterCapture->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
				( new LogService() )->debug( 'capture successful', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment capture success' );
				$order->payment_complete( $paymentIntent->getId() );
			} else {
				( new LogService() )->error( 'capture failed', $paymentIntentAfterCapture->toArray() );
				$order->add_order_note( 'Airwallex payment failed capture' );
			}
		} catch ( Exception $e ) {
			( new LogService() )->error( 'do_subscription_payment failed', $e->getMessage() );
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order           = wc_get_order( $order_id );
		$paymentIntentId = $order->get_transaction_id();
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
			$this->logService->debug( __METHOD__ . " - Order: {$order_id}, refund initiated, {$refund->getId()}" );
		} catch ( \Exception $e ) {
			$this->logService->debug( __METHOD__ . " - Order: {$order_id}, refund failed, {$e->getMessage()}" );
			return new \WP_Error( $e->getCode(), 'Refund failed, ' . $e->getMessage() );
		}

		return true;
	}
}
