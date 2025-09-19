<?php

namespace Airwallex\Controllers;

if (!defined('ABSPATH')) {
	exit;
}

use Airwallex\Services\LogService;
use Airwallex\Client\CardClient;
use Airwallex\Gateways\AirwallexGatewayTrait;
use Airwallex\Services\CacheService;
use Airwallex\Services\OrderService;
use Airwallex\Main;
use Airwallex\Gateways\Card;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;

class PaymentConsentController {

	use AirwallexGatewayTrait;

	protected $cardClient;
	protected $cacheService;
	protected $orderService;

	public function __construct() {
		$this->cardClient   = CardClient::getInstance();
		$this->cacheService = CacheManager::getInstance();
		$this->orderService = OrderService::getInstance();
	}

	public function syncAllConsents() {
		if ( ! is_user_logged_in() || ! current_user_can('administrator') || !function_exists('wcs_get_subscriptions') ) {
			wp_send_json_error(['message' => 'Access denied.']);
			return;
		}

		set_time_limit(0);
		$paged = 1;
		$maxPages = 1000;
		$done = [];
		while ($paged <= $maxPages) {
			$subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => 100, 'paged' => $paged]);
			if (count($subscriptions) === 0) {
				break;
			}
			$paged++;

			foreach ($subscriptions as $subscription) {
				$wpUserId = $subscription->get_user_id();
				$airwallexCustomerId = $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, true );
				if (empty($airwallexCustomerId)) continue;
				$doneKey = "$wpUserId-$airwallexCustomerId";
				if (isset($done[$doneKey])) continue;
				Card::getInstance()->syncSaveCards($airwallexCustomerId, $wpUserId);
				$done[$doneKey] = true;
			}
		}
		wp_send_json([
			'success' => true,
		]);
	}

	public function createConsentWithoutPayment() {
		check_ajax_referer('wc-airwallex-express-checkout', 'security');

		$origin               = isset($_POST['origin']) ? sanitize_url(wp_unslash($_POST['origin'])) : '';
		$redirectUrl          = isset($_POST['redirectUrl']) ? sanitize_url(wp_unslash($_POST['redirectUrl'])) : '';
		$deviceData           = isset($_POST['deviceData']) ? wc_clean(wp_unslash($_POST['deviceData'])) : '';
		$paymentMethodPayload = isset($_POST['paymentMethodObj']) ? wc_clean(wp_unslash($_POST['paymentMethodObj'])) : [];

		LogService::getInstance()->debug(__METHOD__ . " - Create payment consent without initial payment with {$paymentMethodPayload['type']}.");
		try {
			// try to detect order id from URL
			$pattern = '/\/order-received\/(\d+)\//';
			$matches = [];
			if (preg_match($pattern, $redirectUrl, $matches)) {
				$orderId = $matches[1];
			}
			if ( empty($orderId) ) {
				LogService::getInstance()->debug(__METHOD__ . ' can not locate order ID from redirect URL', array( 'url' => $redirectUrl ) );
				throw new Exception( 'Order ID cannot be found from redirect URL.' );
			}

			$order = wc_get_order( $orderId );
			if ( empty( $order ) ) {
				LogService::getInstance()->debug(__METHOD__ . ' can not find order', array( 'orderId' => $orderId ) );
				throw new Exception( 'Order not found: ' . $orderId );
			}
			
			// proceed if the order contains subscription
			if ( ! $this->orderService->containsSubscription( $order->get_id() ) ) {
				wp_send_json([
					'success' => true,
					'redirectUrl' => $redirectUrl,
				]);
			}
	
			$customerId = $this->orderService->getAirwallexCustomerId( get_current_user_id(), $this->cardClient );

			LogService::getInstance()->debug(__METHOD__ . ' - Create Payment consent for order: .' . $orderId);

			$paymentMethodType = $paymentMethodPayload['type'];
			if ( ! in_array( $paymentMethodType, ['applepay', 'googlepay'] ) ) {
				throw new Exception('Payment method type ' . $paymentMethodType . ' is not allowed.');
			}
			
			$paymentConsent  = $this->cardClient->createPaymentConsent($customerId, [
					'type' => $paymentMethodType,
					$paymentMethodType => $paymentMethodPayload[$paymentMethodType],
			]);

			LogService::getInstance()->debug(__METHOD__ . ' - Payment consent created.', $paymentConsent->getId());

			WC()->session->set( 'airwallex_payment_intent_id', $paymentConsent->getInitialPaymentIntentId() );
			$order->update_meta_data( OrderService::META_KEY_INTENT_ID, $paymentConsent->getInitialPaymentIntentId() );
			$order->save();
			WC()->session->set( 'airwallex_order', $orderId );

			$confirmationUrl = $this->get_payment_confirmation_url($orderId, $paymentConsent->getInitialPaymentIntentId());

			wp_send_json([
				'success' => true,
				'confirmation' => $paymentConsent->toArray(),
				'confirmationUrl' => $confirmationUrl,
			]);
		} catch ( Exception $e ) {
			LogService::getInstance()->error(__METHOD__ . " - Create payment const {$paymentMethodType} failed.", $e->getMessage());
			wp_send_json([
				'success' => false,
				'error' => [
					'message' => sprintf(
						/* translators: Placeholder 1: Error message */
						__('Failed to complete payment: %s' , 'airwallex-online-payments-gateway'),
						$e->getMessage()
					)
				],
			]);
		}
	}
}
